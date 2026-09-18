<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

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
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\ImportService;
use OCA\Farmacia\Service\MedicationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The second phase's judgement: which rows the apply walks and how, what
 * the before-image holds, what the revert refuses, and what its guard
 * skips. The mappers and both sibling services are mocked because what has
 * to be OBSERVED here is the walk itself — which service call each row
 * earns, with exactly what input — and a mock is the only thing that can
 * assert a call never happened (the VocabularyServiceTest split: the
 * integration suite, ApplyRevertTest, proves the other half against a real
 * database).
 */
final class ApplyServiceTest extends TestCase {
	private ImportBatchMapper&MockObject $batches;
	private ImportRowMapper&MockObject $rows;
	private MedicationMapper&MockObject $medications;
	private MedicationGesMapper&MockObject $ges;
	private MedicationRestrictionMapper&MockObject $restrictions;
	private MedicationAbasteceMapper&MockObject $abastece;
	private MedicationService&MockObject $medicationService;
	private ImportService&MockObject $import;
	private ApplyService $service;

	/** The input the derivedInput stub answers — the marker the write assertions read. */
	private const INPUT = ['codigo' => 'F-001', 'producto' => 'Producto de prueba', 'categoryId' => 3];

	protected function setUp(): void {
		$this->batches = $this->createMock(ImportBatchMapper::class);
		$this->rows = $this->createMock(ImportRowMapper::class);
		$this->medications = $this->createMock(MedicationMapper::class);
		$this->ges = $this->createMock(MedicationGesMapper::class);
		$this->restrictions = $this->createMock(MedicationRestrictionMapper::class);
		$this->abastece = $this->createMock(MedicationAbasteceMapper::class);
		$this->medicationService = $this->createMock(MedicationService::class);
		$this->import = $this->createMock(ImportService::class);
		$this->service = new ApplyService(
			$this->batches, $this->rows, $this->medications,
			$this->ges, $this->restrictions, $this->abastece,
			$this->medicationService, $this->import,
		);

		// The transaction runs through, as the integration suite exercises
		// it for real; what these tests read is the walk.
		$this->batches->method('transactionally')->willReturnCallback(
			static fn (callable $write) => $write(),
		);
		$this->batches->method('insert')->willReturnCallback(
			static fn (ImportBatch $batch): ImportBatch => $batch,
		);
		$this->import->method('derivedInput')->willReturn(self::INPUT);
		$this->import->method('review')->willReturn(['batch' => ['id' => 11, 'state' => 'applied'], 'rows' => []]);
	}

	private static function batch(string $state = ImportBatch::STATE_STAGED, ?\DateTime $appliedAt = null, ?int $revertsId = null): ImportBatch {
		$batch = new ImportBatch();
		$batch->setId(11);
		$batch->setState($state);
		$batch->setAppliedAt($appliedAt);
		$batch->setRevertsId($revertsId);
		$batch->setOriginalFilename('planilla.csv');
		$batch->setFileHash(str_repeat('a', 64));
		return $batch;
	}

	/** A staged row as the review left it. */
	private static function row(string $disposition, string $codigo = 'F-001', ?string $beforeValues = null, int $line = 2): ImportRow {
		$row = new ImportRow();
		$row->setId(31);
		$row->setBatchId(11);
		$row->setLineNumber($line);
		$row->setCodigo($codigo);
		$row->setPayload((string)json_encode(['codigo' => $codigo, 'producto' => 'Producto de prueba'], JSON_UNESCAPED_UNICODE));
		$row->setDisposition($disposition);
		$row->setBeforeValues($beforeValues);
		$row->setStatus('staged');
		return $row;
	}

	/** A stored medication as findByCodigo answers it. */
	private static function stored(string $codigo, string $updatedAt = '2026-09-18 10:00:03'): Medication {
		$medication = new Medication();
		$medication->setId(5);
		$medication->setCodigo($codigo);
		$medication->setProducto('Producto de prueba');
		$medication->setCategoryId(3);
		$medication->setUpdatedAt(new \DateTime($updatedAt));
		return $medication;
	}

	private function wireRows(ImportRow ...$rows): void {
		$this->rows->method('findAllForBatch')->willReturn($rows);
	}

	public function testApplyRefusesABatchThatIsNotStaged(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime()));

		try {
			$this->service->apply(11);
			self::fail('an applied batch cannot be applied again');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}
	}

	public function testApplyRefusesWhileARowStillOwesADecision(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$owed = self::row('create');
		$owed->setStatus('unresolved-vocabulary');
		$this->wireRows($owed, clone $owed);

		try {
			$this->service->apply(11);
			self::fail('an undecided row must stop the apply');
		} catch (InvalidImportException $e) {
			self::assertSame('2 fila(s) esperan una decisión de vocabulario antes de aplicar.', $e->getMessage());
		}
	}

	public function testApplyWalksCreatesThroughTheMedicationService(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(self::row('create', 'F-009'));
		$this->medicationService->expects(self::once())->method('create')->with(self::INPUT);
		$this->medicationService->expects(self::never())->method('update');
		$this->medicationService->expects(self::never())->method('retire');
		$this->batches->expects(self::once())->method('update')->with(self::callback(
			static fn (ImportBatch $batch): bool => $batch->getState() === ImportBatch::STATE_APPLIED,
		));

		$doc = $this->service->apply(11);

		self::assertSame('applied', $doc['batch']['state'], 'the answer is the review document, re-served');
	}

	public function testApplyCapturesTheBeforeImageAndWritesThroughTheService(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(self::row('update'));
		$stored = self::stored('F-001');
		$this->medications->method('findByCodigo')->with('F-001')->willReturn($stored);
		$this->ges->method('findAllFor')->willReturn([17]);
		$this->restrictions->method('findAllFor')->willReturn(['Solo receta retenida']);
		$this->abastece->method('findAllFor')->willReturn([7]);

		$this->rows->expects(self::once())->method('update')->with(self::callback(
			static function (ImportRow $row): bool {
				$before = json_decode((string)$row->getBeforeValues(), true);
				return $before['codigo'] === 'F-001'
					&& $before['ges'] === [17]
					&& $before['restricciones'] === ['Solo receta retenida']
					&& $before['abastece'] === [7];
			},
		));
		$this->medicationService->expects(self::once())->method('update')->with(5, self::INPUT);

		$this->service->apply(11);
	}

	public function testApplyLeavesTheRowsThatNeverLandAlone(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(
			self::row('unchanged'),
			self::row('skipped'),
			self::row('duplicate'),
			self::row('unsupported'),
		);

		$this->medicationService->expects(self::never())->method('create');
		$this->medicationService->expects(self::never())->method('update');
		$this->rows->expects(self::never())->method('update');
		$this->medications->expects(self::never())->method('findByCodigo');

		$this->service->apply(11);
	}

	public function testApplyReTalliesTheCountersFromTheRowsItWalked(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(
			self::row('create', 'F-009'),
			self::row('update'),
			self::row('unchanged'),
			self::row('skipped'),
			self::row('duplicate'),
			self::row('unsupported'),
		);
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-001'));

		$this->batches->expects(self::once())->method('update')->with(self::callback(
			static function (ImportBatch $batch): bool {
				return $batch->getState() === ImportBatch::STATE_APPLIED
					&& $batch->getAppliedAt() !== null
					&& $batch->getCreated() === 1
					&& $batch->getUpdated() === 1
					&& $batch->getUnchanged() === 1
					&& $batch->getSkipped() === 1
					&& $batch->getDuplicates() === 1
					&& $batch->getUnsupported() === 1;
			},
		));

		$this->service->apply(11);
	}

	public function testApplyRefusesNamingTheLineWhenTheCodigoNoLongerResolves(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(self::row('update', 'F-404', line: 7));
		$this->medications->method('findByCodigo')->willThrowException(new DoesNotExistException('gone'));
		$this->medicationService->expects(self::never())->method('update');

		try {
			$this->service->apply(11);
			self::fail('a código that stopped resolving must refuse the apply');
		} catch (InvalidImportException $e) {
			self::assertSame('La línea 7 actualizaría el código «F-404», que ya no está en el arsenal: importe la planilla de nuevo.', $e->getMessage());
		}
	}

	public function testApplyResaysAServiceRefusalNamingTheLine(): void {
		$this->batches->method('find')->willReturn(self::batch());
		$this->wireRows(self::row('update', 'F-001', line: 3));
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-001'));
		$this->medicationService->method('update')
			->willThrowException(new \OCA\Farmacia\Exception\InvalidMedicationException('El registro está retirado. Restáurelo antes de editarlo.'));

		try {
			$this->service->apply(11);
			self::fail('a service refusal must refuse the apply naming the row');
		} catch (InvalidImportException $e) {
			self::assertSame('La línea 3 no se pudo aplicar: El registro está retirado. Restáurelo antes de editarlo.', $e->getMessage());
		}
	}

	public function testDiscardClosesABatchAndAnswersTheDocument(): void {
		$batch = self::batch();
		$this->batches->method('find')->willReturn($batch);
		$this->batches->expects(self::once())->method('update')->with(self::callback(
			static fn (ImportBatch $b): bool => $b->getState() === ImportBatch::STATE_DISCARDED,
		));

		$doc = $this->service->discard(11);

		self::assertSame('applied', $doc['batch']['state'], 'the answer is the review document the import mock serves');
	}

	public function testDiscardRefusesABatchThatIsNotStaged(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime()));

		try {
			$this->service->discard(11);
			self::fail('an applied batch cannot be discarded');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}
	}

	public function testRevertRefusesABatchThatIsNotApplied(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_STAGED));

		try {
			$this->service->revert(11, 'tester');
			self::fail('a staged batch cannot be undone');
		} catch (InvalidImportException $e) {
			self::assertSame('Solo se puede deshacer un lote aplicado.', $e->getMessage());
		}
	}

	public function testRevertRefusesARevertBatch(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime(), revertsId: 9));

		try {
			$this->service->revert(11, 'tester');
			self::fail('undoing an undo must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('Un deshacer no se puede deshacer: importe la planilla de nuevo.', $e->getMessage());
		}
	}

	public function testRevertRefusesABatchAlreadyUndone(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime()));
		$this->batches->method('isUndone')->willReturn(true);

		try {
			$this->service->revert(11, 'tester');
			self::fail('a second undo must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('Ese lote ya está deshecho.', $e->getMessage());
		}
	}

	public function testRevertRetiresWhatTheApplyCreatedAndRecordsItself(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime('2026-09-18 10:00:05')));
		$this->wireRows(self::row('create', 'F-009'));
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-009', '2026-09-18 10:00:03'));
		$this->medicationService->expects(self::once())->method('retire')->with(5);

		$this->batches->expects(self::once())->method('insert')->with(self::callback(
			static function (ImportBatch $revert): bool {
				return $revert->getRevertsId() === 11
					&& $revert->getState() === ImportBatch::STATE_APPLIED
					&& $revert->getActor() === 'tester'
					&& $revert->getOriginalFilename() === 'planilla.csv';
			},
		))->willReturnCallback(static function (ImportBatch $revert) {
			$revert->setId(21);
			return $revert;
		});

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(21, $answer['id']);
		self::assertSame(1, $answer['updated']);
		self::assertSame(0, $answer['skipped']);
		self::assertSame(11, $answer['revertsId']);
	}

	public function testRevertOverlaysTheBeforeImageOnAnUpdateRow(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime('2026-09-18 10:00:05')));
		$before = ['codigo' => 'F-001', 'producto' => 'Nombre original', 'ges' => [17], 'restricciones' => [], 'abastece' => []];
		$this->wireRows(self::row('update', 'F-001', (string)json_encode($before, JSON_UNESCAPED_UNICODE)));
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-001', '2026-09-18 10:00:03'));

		$this->medicationService->expects(self::once())->method('update')->with(5, $before);
		$this->medicationService->expects(self::never())->method('retire');

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(1, $answer['updated']);
	}

	public function testRevertSkipsAMedicationTouchedSinceTheApply(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime('2026-09-18 10:00:05')));
		$this->wireRows(self::row('create', 'F-009'));
		// 10:00:06 is past the apply: a hand edit, or a later batch's own apply.
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-009', '2026-09-18 10:00:06'));
		$this->medicationService->expects(self::never())->method('retire');

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(0, $answer['updated']);
		self::assertSame(1, $answer['skipped']);
	}

	public function testRevertSkipsAMedicationWhoseCodigoNoLongerResolves(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime()));
		$this->wireRows(self::row('create', 'F-404'));
		$this->medications->method('findByCodigo')->willThrowException(new DoesNotExistException('gone'));
		$this->medicationService->expects(self::never())->method('retire');

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(1, $answer['skipped']);
	}

	public function testRevertSkipsWhenTheInverseIsRefused(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime('2026-09-18 10:00:05')));
		$before = ['codigo' => 'F-001', 'producto' => 'Nombre original', 'categoryId' => 999];
		$this->wireRows(self::row('update', 'F-001', (string)json_encode($before, JSON_UNESCAPED_UNICODE)));
		$this->medications->method('findByCodigo')->willReturn(self::stored('F-001', '2026-09-18 10:00:03'));
		// The category the before-image names was removed since the apply.
		$this->medicationService->method('update')
			->willThrowException(new \OCA\Farmacia\Exception\InvalidMedicationException('La categoría 999 no existe.'));

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(0, $answer['updated']);
		self::assertSame(1, $answer['skipped']);
	}

	public function testRevertWalksOnlyTheRowsThatLanded(): void {
		$this->batches->method('find')->willReturn(self::batch(ImportBatch::STATE_APPLIED, new \DateTime()));
		$this->wireRows(
			self::row('unchanged'),
			self::row('skipped'),
			self::row('duplicate'),
			self::row('unsupported'),
		);
		$this->medications->expects(self::never())->method('findByCodigo');

		$answer = $this->service->revert(11, 'tester');

		self::assertSame(0, $answer['updated']);
		self::assertSame(0, $answer['skipped'], 'rows that never landed are neither reverted nor reported as skipped');
	}
}
