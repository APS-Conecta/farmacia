<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use DateTime;
use OCA\Farmacia\Db\ImportBatch;
use OCA\Farmacia\Db\ImportBatchMapper;
use OCA\Farmacia\Db\ImportRow;
use OCA\Farmacia\Db\ImportRowMapper;
use OCA\Farmacia\Db\Medication;
use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationGesMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Db\MedicationRestrictionMapper;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The second phase of the two-phase import: landing a reviewed batch, and
 * taking it back out again.
 *
 * Staging (ImportService) is where nothing is written; this service is where
 * the medication tables finally move. Every row lands through
 * MedicationService — the one write path — because the stage's prediction
 * was derived against it (ImportService::derivedInput's own contract): a
 * row judged unchanged must apply as a no-op, or the apply would stamp
 * updated_at on a record nobody touched, and a later revert would skip it
 * as hand-edited.
 *
 * One transaction over the whole walk, where territorio commits each row
 * alone: there, the shared RevisionLog cursor had to see every write
 * serialised; farmacia has no cursor, so a batch that fails at row 300
 * rolls back to the review the person approved — all of it, not 299 rows
 * of it. The MedicationService create and update calls inside each open
 * their own transaction (MedicationMapper::transactionally); on the pinned
 * stack (Nextcloud 34, DBAL 3 with savepoints off) a nested begin/commit is
 * a no-op and a nested rollback marks the connection rollback-only, so the
 * outer rollBack() still wins — verified in the container against the same
 * Nextcloud 34 the suite pins (OC\DB\ConnectionAdapter over DBAL 3's
 * Connection:beginTransaction/commit/rollBack), the way the OCS header was.
 *
 * The revert is the inverse walk over the same rows (territorio's
 * RevertService, minus its RevisionLog): what the apply created is retired
 * — never deleted, so «Ver retirados» offers it back — and what it updated
 * is overlaid with the before-image captured at apply time. Nothing is
 * undone blindly: a medication touched since the apply (updated_at past
 * the batch's applied_at — a hand edit, or a later batch's own apply) is
 * left exactly as it is and counted, because silently discarding someone's
 * work is the failure a revert exists to prevent. The revert is itself a
 * batch (reverts_id), so the undo is recorded where every run is; undoing
 * an undo is refused with the honest alternative — import the planilla
 * again.
 */
class ApplyService {
	public function __construct(
		private ImportBatchMapper $batches,
		private ImportRowMapper $rows,
		private MedicationMapper $medications,
		private MedicationGesMapper $ges,
		private MedicationRestrictionMapper $restrictions,
		private MedicationAbasteceMapper $abastece,
		private MedicationService $medicationService,
		private ImportService $import,
	) {
	}

	/**
	 * Land a reviewed batch: every create and update row writes through
	 * MedicationService inside one transaction, each update row carries its
	 * before-image from now on, and the batch closes as applied with its
	 * counters re-tallied from the rows as they were walked — the stage-time
	 * plan is stale the moment a review decision re-derived a row.
	 *
	 * Rows the review triaged as invalid (unsupported) never land, and do not
	 * stop the others: one bad row costing the person the other four hundred
	 * is what the staged review exists to prevent.
	 *
	 * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>} the review document, re-served: the batch applied, its rows with their before-images
	 * @throws DoesNotExistException when there is no such batch
	 * @throws InvalidImportException when the batch is not staged, a row still owes a decision, or the world moved under a row
	 */
	public function apply(int $batchId): array {
		$batch = $this->batches->find($batchId);

		if ($batch->getState() !== ImportBatch::STATE_STAGED) {
			throw new InvalidImportException('El lote ya no está en revisión.');
		}

		$rows = $this->rows->findAllForBatch($batchId);

		$undecided = count(array_filter(
			$rows,
			static fn (ImportRow $row): bool => $row->getStatus() === 'unresolved-vocabulary',
		));
		if ($undecided > 0) {
			throw new InvalidImportException(sprintf(
				'%d fila(s) esperan una decisión de vocabulario antes de aplicar.',
				$undecided,
			));
		}

		return $this->batches->transactionally(function () use ($batch, $rows, $batchId): array {
			$counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'duplicate' => 0, 'unsupported' => 0];

			foreach ($rows as $row) {
				$counts[$row->getDisposition()]++;

				if ($row->getDisposition() === 'create') {
					// No before-image: there is nothing stored to go back to —
					// the row's inverse is the retirement the revert makes.
					$this->write($row, fn (): array
						=> $this->medicationService->create($this->import->derivedInput($this->payloadOf($row))));
				} elseif ($row->getDisposition() === 'update') {
					$stored = $this->resolve($row);
					$row->setBeforeValues((string)json_encode($this->beforeImage($stored), JSON_UNESCAPED_UNICODE));
					$this->rows->update($row);
					$this->write($row, fn (): array => $this->medicationService->update(
						(int)$stored->getId(),
						$this->import->derivedInput($this->payloadOf($row)),
					));
				}
			}

			$batch->setCreated($counts['create']);
			$batch->setUpdated($counts['update']);
			$batch->setUnchanged($counts['unchanged']);
			$batch->setSkipped($counts['skipped']);
			$batch->setDuplicates($counts['duplicate']);
			$batch->setUnsupported($counts['unsupported']);
			$batch->setState(ImportBatch::STATE_APPLIED);
			$batch->setAppliedAt(new DateTime());
			$this->batches->update($batch);

			return $this->import->review($batchId);
		});
	}

	/**
	 * Close a staged batch without landing anything. The rows stay — they are
	 * the record of what was refused — but every door that could still move
	 * them is shut: decide() and apply() both read the state and refuse a
	 * batch that is no longer in review.
	 *
	 * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>}
	 * @throws DoesNotExistException when there is no such batch
	 * @throws InvalidImportException when the batch is not staged
	 */
	public function discard(int $batchId): array {
		$batch = $this->batches->find($batchId);

		if ($batch->getState() !== ImportBatch::STATE_STAGED) {
			throw new InvalidImportException('El lote ya no está en revisión.');
		}

		$batch->setState(ImportBatch::STATE_DISCARDED);
		$this->batches->update($batch);

		return $this->import->review($batchId);
	}

	/**
	 * Take an applied batch back out. The answer is the revert's own batch —
	 * recorded, with its counters — because the undo is itself part of the
	 * provenance the batches list shows.
	 *
	 * @return array<string, mixed> the revert batch, as the list shows it
	 * @throws DoesNotExistException when there is no such batch
	 * @throws InvalidImportException when the batch is not applied, is itself a revert, or is already undone
	 */
	public function revert(int $batchId, ?string $actor): array {
		$original = $this->batches->find($batchId);

		if ($original->getState() !== ImportBatch::STATE_APPLIED) {
			throw new InvalidImportException('Solo se puede deshacer un lote aplicado.');
		}
		// A revert batch carries no rows of its own, so undoing it would walk
		// nothing; the planilla — still wherever the person keeps it — is the
		// honest way back.
		if ($original->getRevertsId() !== null) {
			throw new InvalidImportException('Un deshacer no se puede deshacer: importe la planilla de nuevo.');
		}
		if ($this->batches->isUndone($batchId)) {
			throw new InvalidImportException('Ese lote ya está deshecho.');
		}

		return $this->batches->transactionally(function () use ($original, $actor): array {
			$revert = new ImportBatch();
			$revert->setActor($actor);
			$revert->setStartedAt(new DateTime());
			// Born applied: there is nothing to review in an undo, so there
			// is no review to skip.
			$revert->setState(ImportBatch::STATE_APPLIED);
			$revert->setAppliedAt(new DateTime());
			// The provenance of what it undoes: the same file, named by the
			// run it belongs to.
			$revert->setOriginalFilename($original->getOriginalFilename());
			$revert->setFileHash($original->getFileHash());
			$revert->setRevertsId((int)$original->getId());
			$revert = $this->batches->insert($revert);

			$updated = 0;
			$skipped = 0;

			foreach ($this->rows->findAllForBatch((int)$original->getId()) as $row) {
				if (!in_array($row->getDisposition(), ['create', 'update'], true)) {
					// Nothing to take back: unchanged, skipped, duplicate and
					// unsupported rows never landed.
					continue;
				}

				try {
					$undone = $this->undoRow($original, $row);
				} catch (DoesNotExistException|InvalidMedicationException) {
					// One record this revert cannot put back — a category or
					// channel removed since the apply, say. Left as it is and
					// counted, rather than aborting: a half-undone batch is
					// worse than one that says what it could not reach.
					$undone = false;
				}

				$undone ? $updated++ : $skipped++;
			}

			$revert->setUpdated($updated);
			$revert->setSkipped($skipped);
			$this->batches->update($revert);

			return $revert->toClient();
		});
	}

	/**
	 * One row's inverse, guarded: false where the record is no longer this
	 * batch's to touch.
	 *
	 * @throws DoesNotExistException when the código no longer resolves
	 * @throws InvalidMedicationException when the service refuses the inverse
	 */
	private function undoRow(ImportBatch $original, ImportRow $row): bool {
		$medication = $this->medications->findByCodigo($row->getCodigo());

		// The touched-since guard. updated_at is stamped by every write path
		// (MedicationMapper), so anything past the batch's applied_at is a
		// hand edit or a later batch's apply — either way this revert is no
		// longer the last word on the record, and leaves it alone.
		//
		// ponytail: second precision, the DATETIME column's own. An edit
		// landing in the same second as the apply reads as untouched; the
		// ceiling is known and accepted (the no-op stamp test sleeps a
		// second for the same reason), and closing it means a write clock
		// with more resolution than the one the retired list orders by.
		if ($medication->getUpdatedAt() > $original->getAppliedAt()) {
			return false;
		}

		if ($row->getDisposition() === 'create') {
			// The inverse of a create is retirement, never deletion: the
			// record keeps its data and «Ver retirados» offers it back. A
			// medication retired by hand trips the guard above — the same
			// stamp is what retirement moves.
			$this->medicationService->retire((int)$medication->getId());

			return true;
		}

		// The inverse of an update is the before-image, whole: every field
		// and all three child lists, so the overlay is a restore rather than
		// a merge. A category or channel the apply moved off was guarded by
		// the vocabulary's in-use count while it was referenced, but may be
		// gone since — the service's refusal is the catch above.
		$this->medicationService->update(
			(int)$medication->getId(),
			json_decode((string)$row->getBeforeValues(), true) ?? [],
		);

		return true;
	}

	/**
	 * One row's write, with its refusal re-said naming the row it belongs
	 * to: a 422 that says only «El registro está retirado.» does not say
	 * which of four hundred rows it means.
	 *
	 */
	private function write(ImportRow $row, callable $action): void {
		try {
			$action();
		} catch (InvalidMedicationException $e) {
			throw new InvalidImportException(sprintf(
				'La línea %d no se pudo aplicar: %s',
				$row->getLineNumber(),
				$e->getMessage(),
			), 0, $e);
		}
	}

	/**
	 * The stored medication an update row is about to overwrite. A refusal,
	 * not a skip: the batch is one transaction, so a código that stopped
	 * resolving costs the whole apply nothing — the honest answer to a
	 * world that moved under the review.
	 *
	 * @throws InvalidImportException when the código no longer resolves
	 */
	private function resolve(ImportRow $row): Medication {
		try {
			return $this->medications->findByCodigo($row->getCodigo());
		} catch (DoesNotExistException $e) {
			throw new InvalidImportException(sprintf(
				'La línea %d actualizaría el código «%s», que ya no está en el arsenal: importe la planilla de nuevo.',
				$row->getLineNumber(),
				$row->getCodigo(),
			), 0, $e);
		}
	}

	/**
	 * The stored row as it stands immediately before the apply overwrites
	 * it — comparable fields plus the three child lists, read under the
	 * same transaction that is about to write, so a colleague's save in
	 * between lands in the image rather than under it.
	 *
	 * @return array<string, mixed> the MedicationService input that restores this state
	 */
	private function beforeImage(Medication $stored): array {
		$id = (int)$stored->getId();

		return $stored->comparable() + [
			'ges' => $this->ges->findAllFor($id),
			'restricciones' => $this->restrictions->findAllFor($id),
			'abastece' => $this->abastece->findAllFor($id),
		];
	}

	/** @return array<string, mixed> */
	private function payloadOf(ImportRow $row): array {
		return json_decode((string)$row->getPayload(), true) ?? [];
	}
}
