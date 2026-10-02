<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use DateTime;
use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Db\ImportBatch;
use OCA\Farmacia\Db\ImportBatchMapper;
use OCA\Farmacia\Db\ImportRow;
use OCA\Farmacia\Db\ImportRowMapper;
use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationGesMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Db\MedicationRestrictionMapper;
use OCA\Farmacia\Exception\InvalidImportException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Stages a planilla for review — and nothing else.
 *
 * The two-phase doctrine this service exists for: the upload reads,
 * resolves and JUDGES every row, and writes its findings to the staging
 * tables, but the medication tables are untouched until the batch is
 * applied from the review. Territorio's importer, which this mirrors in
 * mechanics, applies the moment its validation passes because its safety
 * net is a revision log; farmacia has no revision machinery, so the staged
 * rows ARE the safety net — the review reads them, the decisions land on
 * them, and the apply will walk exactly them.
 *
 * Judgement is per row, never per file: a bad cell stages the row as
 * invalid with the sentence naming it, an unknown vocabulary value stages
 * it as unresolved-vocabulary with the label in the note, and one bad row
 * never costs the person the other four hundred — territorio's
 * all-or-nothing validation reads nothing on a planilla, where the review
 * screen is precisely the place to sort a mixed file out.
 *
 * The dispositions, decided at stage time — what the apply would do today:
 * create (no stored medication carries the código), update (one does, and
 * the row would change it), unchanged (one does, and the derived row
 * matches it), skipped (one does, and it is RETIRED — retirement is a
 * local decision an import must not undo, territorio#ADR-0003's argument
 * restated), duplicate (the planilla repeats a código; the first row owns
 * it and the note names its line), unsupported (the row itself will not
 * parse as a medication).
 *
 * Two doors, one service: the browser upload and `occ farmacia:import`
 * both end here, so the two cannot drift (territorio's ImportController
 * doctrine).
 */
class ImportService {
	/** The FDA pregnancy categories, as MedicationService states them. */
	private const FDA_CATEGORIES = ['A', 'B', 'C', 'D', 'X'];

	public function __construct(
		private CsvReader $csv,
		private CategoryMapper $categories,
		private ChannelMapper $channels,
		private MedicationMapper $medications,
		private MedicationGesMapper $ges,
		private MedicationRestrictionMapper $restrictions,
		private MedicationAbasteceMapper $abastece,
		private ImportBatchMapper $batches,
		private ImportRowMapper $rows,
	) {
	}

	/**
	 * Read a planilla and stage it for review: the batch and its rows land
	 * in the staging tables inside one transaction, the medication tables
	 * stay untouched, and the answer is the review document — the same
	 * shape `review()` serves, so the review screen opens a fresh upload
	 * and a reopened batch through one document.
	 *
	 * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>}
	 * @throws InvalidImportException when the file itself cannot be read
	 */
	public function stage(string $contents, string $filename, ?string $actor): array {
		$read = $this->csv->read($contents);
		if ($read === []) {
			throw new InvalidImportException('El archivo no tiene filas debajo del encabezado.');
		}

		$categories = $this->categoryIndex();
		$channels = $this->channelIndex();
		$stored = $this->storedByCodigo();

		$counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'duplicate' => 0, 'unsupported' => 0];
		$firstLine = [];
		$entities = [];

		foreach ($read as $readRow) {
			$line = $readRow['line'];
			$codigo = trim((string)($readRow['cells']['codigo'] ?? ''));

			if ($codigo !== '' && isset($firstLine[$codigo])) {
				// The planilla repeats a código. The FIRST row owns it — the
				// planilla's first word is the one a person reads first — and
				// the repeat is staged for the review to see, never silently
				// collapsed: territorio collapsed it and counted it, which is
				// honest, but here the row is reviewable surface and the
				// person may want the second one's cells instead.
				$judged = [
					'payload' => $readRow['cells'],
					'disposition' => 'duplicate',
					'status' => 'staged',
					'notes' => sprintf('el código «%s» ya apareció en la línea %d', $codigo, $firstLine[$codigo]),
				];
			} else {
				$judged = $this->judge(
					$readRow['cells'],
					$codigo === '' ? null : ($stored[$codigo] ?? null),
					$categories,
					$channels,
				);
				if ($codigo !== '') {
					$firstLine[$codigo] = $line;
				}
			}
			$counts[$judged['disposition']]++;

			$entity = new ImportRow();
			$entity->setLineNumber($line);
			$entity->setCodigo($codigo);
			$entity->setPayload((string)json_encode($judged['payload'], JSON_UNESCAPED_UNICODE));
			$entity->setDisposition($judged['disposition']);
			$entity->setStatus($judged['status']);
			$entity->setNotes($judged['notes']);
			$entities[] = $entity;
		}

		$batch = new ImportBatch();
		$batch->setActor($actor);
		$batch->setStartedAt(new DateTime());
		$batch->setState(ImportBatch::STATE_STAGED);
		$batch->setOriginalFilename(mb_substr(trim($filename), 0, 255));
		$batch->setFileHash(hash('sha256', $contents));
		$batch->setCreated($counts['create']);
		$batch->setUpdated($counts['update']);
		$batch->setUnchanged($counts['unchanged']);
		$batch->setSkipped($counts['skipped']);
		$batch->setDuplicates($counts['duplicate']);
		$batch->setUnsupported($counts['unsupported']);

		return $this->batches->transactionally(function () use ($batch, $entities): array {
			$batch = $this->batches->insert($batch);

			foreach ($entities as $entity) {
				$entity->setBatchId((int)$batch->getId());
				$this->rows->insert($entity);
			}

			return [
				'batch' => $batch->toClient(),
				'rows' => array_map(static fn (ImportRow $r): array => $r->toClient(), $entities),
			];
		});
	}

	/**
	 * The review document of one batch: its own state and counters, and its
	 * rows in planilla order. A batch staged before a page reload reopens
	 * through here exactly as the upload answered it.
	 *
	 * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>}
	 * @throws DoesNotExistException when there is no such batch
	 */
	public function review(int $batchId): array {
		$batch = $this->batches->find($batchId);

		return [
			'batch' => $batch->toClient(),
			'rows' => array_map(static fn (ImportRow $r): array => $r->toClient(), $this->rows->findAllForBatch($batchId)),
		];
	}

	/**
	 * The recent runs, as the batches list shows them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function batches(): array {
		return array_map(
			fn (ImportBatch $b): array => $b->toClient() + [
				// The undo's own flag, territorio's recentBatches() shape: the
				// list stops offering Deshacer on a batch a later batch undid.
				'undone' => $this->batches->isUndone((int)$b->getId()),
			],
			$this->batches->findRecent(),
		);
	}

	/**
	 * Record one review decision on a staged row.
	 *
	 * What a decision can settle is vocabulary and nothing else: the
	 * category a row names (or lacks, on a new record from a planilla with
	 * no Categoría column) and the channels its labels resolve to —
	 * including none of them, which is the «dejar sin canal» answer the
	 * review offers. The cells themselves are the planilla's words and are
	 * never rewritten here: a wrong cell is fixed in the spreadsheet and
	 * imported again, which is cheaper than an editor that becomes a second
	 * spreadsheet.
	 *
	 * @param array<string, mixed> $input `categoryId` and/or `abastece` (a list of channel ids)
	 * @return array<string, mixed> the updated row, as the review shows it
	 * @throws DoesNotExistException when the row or its batch is gone
	 * @throws InvalidImportException when the row is not waiting for this decision
	 */
	public function decide(int $rowId, array $input): array {
		$row = $this->rows->find($rowId);
		$batch = $this->batches->find($row->getBatchId());

		if ($batch->getState() !== ImportBatch::STATE_STAGED) {
			throw new InvalidImportException('El lote ya no está en revisión.');
		}
		if (in_array($row->getDisposition(), ['duplicate', 'skipped', 'unsupported'], true)) {
			throw new InvalidImportException('La fila no espera una decisión.');
		}

		$payload = json_decode((string)$row->getPayload(), true) ?? [];
		$decided = false;

		if (array_key_exists('categoryId', $input)) {
			// The same L2-03 guard MedicationService::apply carries — the
			// decision door is a write door too.
			$given = $input['categoryId'] ?? null;
			$categoryId = is_int($given) || (is_string($given) && ctype_digit($given)) ? (int)$given : null;
			if ($categoryId === null) {
				throw new InvalidImportException('La categoría tiene que ser un número.');
			}
			try {
				$this->categories->find($categoryId);
			} catch (DoesNotExistException $e) {
				throw new InvalidImportException(sprintf('La categoría %d no existe.', $categoryId), 0, $e);
			}
			$payload['categoryId'] = $categoryId;
			unset($payload['pending']['categoria']);
			$decided = true;
		}

		if (array_key_exists('abastece', $input) && is_array($input['abastece'])) {
			$ids = [];
			foreach ($input['abastece'] as $rawId) {
				$channelId = is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)) ? (int)$rawId : null;
				if ($channelId === null || $channelId < 1) {
					throw new InvalidImportException('Cada canal de abastece es un número.');
				}
				try {
					$this->channels->find($channelId);
				} catch (DoesNotExistException $e) {
					throw new InvalidImportException(sprintf('El canal de abastece %d no existe.', $channelId), 0, $e);
				}
				$ids[] = $channelId;
			}
			$payload['abasteceIds'] = array_values(array_unique($ids));
			unset($payload['pending']['abastece']);
			$decided = true;
		}

		if (!$decided) {
			throw new InvalidImportException('La decisión no dice qué resolver.');
		}

		// The decision settles vocabulary, never cells: the validation at
		// stage time still holds, and what moves is the triage (nothing
		// pending) and the disposition, re-derived against the record as it
		// stands NOW — a medication retired between the upload and the
		// decision reads skipped here, not at the apply.
		$pending = $payload['pending'] ?? [];
		// ONE record, not the vademécum (L2-08): a 400-row planilla with 30
		// vocabulary decisions re-read every medication — live, retired and
		// three bulk child lists — 30 times, against the app's own zero-JOIN
		// stage-time budget. The single-record shape is ApplyService::
		// beforeImage's: find by the código, read the three children.
		$stored = null;
		if ($row->getCodigo() !== '') {
			try {
				$medication = $this->medications->findByCodigo($row->getCodigo());
				$stored = [
					'retired' => (bool)$medication->getRetired(),
					'comparable' => $medication->comparable(),
					'ges' => $this->ges->findAllFor((int)$medication->getId()),
					'restricciones' => $this->restrictions->findAllFor((int)$medication->getId()),
					'abastece' => $this->abastece->findAllFor((int)$medication->getId()),
				];
			} catch (DoesNotExistException) {
					// A código that stopped resolving between stage and decision reads
					// as create here — the apply's own resolve() is the door that
					// refuses a world that moved, and re-deriving a disposition is not
					// that door.
				}
		}

		if ($stored === null) {
			$disposition = 'create';
		} elseif ($stored['retired']) {
			$disposition = 'skipped';
		} else {
			$disposition = $this->differs($stored, $this->derivedInput($payload))
				? 'update'
				: 'unchanged';
		}

		$row->setPayload((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
		$row->setDisposition($disposition);
		$row->setStatus($pending === [] ? 'staged' : 'unresolved-vocabulary');
		$row->setNotes($disposition === 'skipped'
			? 'el registro está retirado: la importación no lo toca'
			: $this->pendingNotes($pending));

		$this->rows->update($row);

		return $row->toClient();
	}

	/**
	 * One planilla row's judgement: the payload with its resolution
	 * written in, and what the review should do with the row.
	 *
	 * @param array<string, mixed> $cells the reader's row
	 * @param ?array<string, mixed> $stored the stored medication carrying this código, if any
	 * @param array<string, int> $categories folded slug → category id
	 * @param array<string, int> $channels folded slug → channel id
	 * @return array{payload: array<string, mixed>, disposition: string, status: string, notes: ?string}
	 */
	private function judge(array $cells, ?array $stored, array $categories, array $channels): array {
		$payload = $cells;
		$problems = [];
		$pending = [];

		$codigo = trim((string)($payload['codigo'] ?? ''));
		if ($codigo === '') {
			$problems[] = 'la fila no trae código';
		}

		// A producto cell that is empty in a column the file carries is a
		// CLEAR, and a clear of the one name a medication cannot lose is
		// refused downstream (MedicationService's own sentence) — better
		// said here, where the person still has the planilla open.
		if (array_key_exists('producto', $payload)) {
			$producto = trim((string)$payload['producto']);
			if ($producto === '') {
				$problems[] = 'la fila no trae producto';
			}
		}

		// The sí/no cells, folded with the repo's own rule so «Sí», «SI» and
		// «si» are one answer and anything else is a sentence, not a guess.
		foreach (['trazador' => 'Trazador', 'riesgoColinergicoAm' => 'Riesgo colinérgico AM'] as $key => $column) {
			if (!array_key_exists($key, $payload)) {
				continue;
			}
			$folded = VocabularyService::slugFor((string)$payload[$key]);
			if ($folded !== '' && $folded !== 'si' && $folded !== 'no') {
				$problems[] = sprintf('la celda %s dice «%s»: escriba Sí o No', $column, (string)$payload[$key]);
			}
		}

		if (array_key_exists('fdaEmbarazo', $payload)) {
			$fda = strtoupper(trim((string)$payload['fdaEmbarazo']));
			if ($fda !== '' && !in_array($fda, self::FDA_CATEGORIES, true)) {
				$problems[] = sprintf('la celda FDA embarazo dice «%s»: A, B, C, D, X o vacía, para sin dato', (string)$payload['fdaEmbarazo']);
			}
		}

		if (array_key_exists('ges', $payload)) {
			foreach ($payload['ges'] as $number) {
				if (!ctype_digit((string)$number) || (int)$number < 1) {
					$problems[] = sprintf('la celda GES trae «%s»: un número de problema GES', (string)$number);
				}
			}
		}

		foreach (MedicationService::CELL_CAPS as $key => [$max, $column]) {
			if (array_key_exists($key, $payload) && mb_strlen(trim((string)$payload[$key])) > $max) {
				$problems[] = sprintf('la celda %s pasa de %d caracteres', $column, $max);
			}
		}

		// Vocabulary: the labels fold with the same rule the seed's slugs
		// were pre-folded with (EnsureSeedData's promise), so a label typed
		// — or imported — always finds the row it names.
		$categoryId = null;
		if (array_key_exists('categoria', $payload)) {
			$slug = VocabularyService::slugFor((string)$payload['categoria']);
			$categoryId = $slug === '' ? null : ($categories[$slug] ?? null);
			if ($categoryId === null) {
				$pending['categoria'] = (string)$payload['categoria'];
			}
		} elseif ($stored === null) {
			// A new record from a planilla with no Categoría column cannot be
			// created without one: the review says which, and that is a
			// decision this batch can carry.
			$pending['categoria'] = null;
		}

		$abasteceIds = [];
		$unknownChannels = [];
		if (array_key_exists('abastece', $payload)) {
			foreach ($payload['abastece'] as $label) {
				$slug = VocabularyService::slugFor((string)$label);
				$match = $slug === '' ? null : ($channels[$slug] ?? null);
				if ($match !== null) {
					$abasteceIds[] = $match;
				} else {
					$unknownChannels[] = (string)$label;
				}
			}
			if ($unknownChannels !== []) {
				$pending['abastece'] = $unknownChannels;
			}
		}

		// The one case a stored restriction cannot round-trip: the planilla's
		// separator inside a free-text value splits into two restrictions on
		// the way back, and applying that would silently rewrite the stored
		// record — so the row is flagged for the person to fix in the app,
		// where the restriction is editable, and import again. Detected by
		// re-splitting the stored values' own join: only a stored separator
		// can make the two lists disagree while their joined text still
		// reads the same.
		if ($stored !== null && array_key_exists('restricciones', $payload)) {
			$incoming = $this->derivedRestrictions($payload['restricciones']);
			if ($incoming !== $stored['restricciones']
				&& CsvGrammar::split(CsvGrammar::join($stored['restricciones'])) === $incoming) {
				$problems[] = 'una restricción guardada contiene «|», el separador de la planilla: corríjala en el medicamento y vuelva a importar';
			}
		}

		$payload['categoryId'] = $categoryId;
		$payload['abasteceIds'] = $abasteceIds;
		$payload['pending'] = $pending;

		if ($problems !== []) {
			return [
				'payload' => $payload,
				'disposition' => 'unsupported',
				'status' => 'invalid',
				'notes' => implode('; ', $problems),
			];
		}

		if ($stored === null) {
			$disposition = 'create';
		} elseif ($stored['retired']) {
			$disposition = 'skipped';
		} elseif ($pending !== []) {
			// Provisional: the row would change the record once the pending
			// decisions land — the exact disposition is re-derived at each
			// decision, and the apply only ever walks a resolved row.
			$disposition = 'update';
		} else {
			$disposition = $this->differs($stored, $this->derivedInput($payload)) ? 'update' : 'unchanged';
		}

		$status = ($disposition === 'skipped' || $pending === []) ? 'staged' : 'unresolved-vocabulary';

		return [
			'payload' => $payload,
			'disposition' => $disposition,
			'status' => $status,
			'notes' => $disposition === 'skipped'
				? 'el registro está retirado: la importación no lo toca'
				: $this->pendingNotes($pending),
		];
	}

	/**
	 * The sentences that say what the review still owes, recomposed from the
	 * pending map so a decision shrinks the note to what is left.
	 *
	 * @param array<string, mixed> $pending
	 */
	private function pendingNotes(array $pending): ?string {
		$notes = [];

		if (array_key_exists('categoria', $pending)) {
			$notes[] = $pending['categoria'] !== null
				? sprintf('la categoría «%s» no existe: declárela en la revisión', (string)$pending['categoria'])
				: 'la fila es nueva y el archivo no trae columna Categoría: declárela en la revisión';
		}

		if (array_key_exists('abastece', $pending)) {
			$labels = array_map(static fn (string $label): string => '«' . $label . '»', $pending['abastece']);
			$notes[] = count($labels) === 1
				? sprintf('el canal %s no existe: declárelo en la revisión', $labels[0])
				: sprintf('los canales %s no existen: declárelos en la revisión', implode(' y ', $labels));
		}

		return $notes === [] ? null : implode('; ', $notes);
	}

	/** @return array<string, int> folded slug → category id */
	private function categoryIndex(): array {
		$index = [];
		foreach ($this->categories->findAll() as $category) {
			$index[$category->getSlug()] = (int)$category->getId();
		}

		return $index;
	}

	/** @return array<string, int> folded slug → channel id */
	private function channelIndex(): array {
		$index = [];
		foreach ($this->channels->findAll() as $channel) {
			$index[$channel->getSlug()] = (int)$channel->getId();
		}

		return $index;
	}

	/**
	 * Every stored medication, live and retired, keyed by the código the
	 * planilla matches on — with its comparable fields and its three child
	 * lists beside it. The bulk `IN` reads are the zero-JOIN doctrine
	 * (TaxonomyService::tree's fold, restated): one clinic's vademécum is
	 * hundreds of rows, read once per stage.
	 *
	 * @return array<string, array{retired: bool, comparable: array<string, mixed>, ges: list<int>, restricciones: list<string>, abastece: list<int>}>
	 */
	private function storedByCodigo(): array {
		$all = [...$this->medications->findAll(), ...$this->medications->findRetired()];
		$ids = array_map(static fn ($m): int => (int)$m->getId(), $all);

		$ges = $this->ges->forIds($ids);
		$restrictions = $this->restrictions->forIds($ids);
		$abastece = $this->abastece->forIds($ids);

		$byCodigo = [];
		foreach ($all as $medication) {
			$id = (int)$medication->getId();
			$byCodigo[$medication->getCodigo()] = [
				'retired' => (bool)$medication->getRetired(),
				'comparable' => $medication->comparable(),
				'ges' => $ges[$id] ?? [],
				'restricciones' => $restrictions[$id] ?? [],
				'abastece' => $abastece[$id] ?? [],
			];
		}

		return $byCodigo;
	}

	/**
	 * Whether applying this input would change anything a person can see —
	 * territorio's `differs()`, restated for a record whose comparable set
	 * carries three child lists. Only the keys the planilla carries are
	 * compared: a column the file does not have is a field the apply leaves
	 * alone, and comparing it against the stored value would flip every row
	 * of a narrow planilla into an update that changed nothing.
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $input
	 */
	private function differs(array $stored, array $input): bool {
		$storedSide = $stored['comparable'];
		foreach (['ges', 'restricciones', 'abastece'] as $child) {
			if (array_key_exists($child, $input)) {
				$storedSide[$child] = $stored[$child];
			}
		}

		// Sorted because the two are built in different orders and `!==` on
		// arrays is order-sensitive; strict so a stored '' never reads as null.
		$storedSide = array_intersect_key($storedSide, $input);
		ksort($storedSide);
		ksort($input);

		return $storedSide !== $input;
	}

	/**
	 * The MedicationService input a validated payload derives to — the same
	 * normalizations the service itself applies (trim, blank becomes null,
	 * deduped lists), stated here so the stage's prediction IS the apply's
	 * write: a row judged unchanged must apply as a no-op, or the apply
	 * would stamp updated_at on a record nobody touched. Only the keys the
	 * planilla carries are derived.
	 *
	 * Public because ApplyService is the other half of that contract: the
	 * derivation is stated once, and the phase that writes reads the same
	 * one the phase that judged predicted with.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function derivedInput(array $payload): array {
		$input = [];

		if (array_key_exists('codigo', $payload)) {
			$input['codigo'] = trim((string)$payload['codigo']);
		}
		if (array_key_exists('producto', $payload)) {
			$input['producto'] = trim((string)$payload['producto']);
		}

		foreach (['farmaco', 'mg', 'presentacion', 'programa', 'indicacion', 'dosificacion', 'precauciones', 'observaciones', 'ajusteRenal'] as $key) {
			if (array_key_exists($key, $payload)) {
				$value = trim((string)$payload[$key]);
				$input[$key] = $value === '' ? null : $value;
			}
		}

		if (array_key_exists('trazador', $payload)) {
			$input['trazador'] = VocabularyService::slugFor((string)$payload['trazador']) === 'si';
		}
		if (array_key_exists('riesgoColinergicoAm', $payload)) {
			$input['riesgoColinergicoAm'] = VocabularyService::slugFor((string)$payload['riesgoColinergicoAm']) === 'si';
		}
		if (array_key_exists('fdaEmbarazo', $payload)) {
			$input['fdaEmbarazo'] = strtoupper(trim((string)$payload['fdaEmbarazo'])) ?: null;
		}
		if (array_key_exists('ges', $payload)) {
			$input['ges'] = $this->derivedGes($payload['ges']);
		}
		if (array_key_exists('restricciones', $payload)) {
			$input['restricciones'] = $this->derivedRestrictions($payload['restricciones']);
		}
		if (array_key_exists('abastece', $payload)) {
			$input['abastece'] = array_values(array_unique($payload['abasteceIds'] ?? []));
		}
		if (($payload['categoryId'] ?? null) !== null) {
			$input['categoryId'] = (int)$payload['categoryId'];
		}

		return $input;
	}

	/**
	 * The MedicationService::gesList twin — a validated row's numbers, so
	 * the comparison predicts the write.
	 *
	 * @param list<mixed> $values
	 * @return list<int>
	 */
	private function derivedGes(array $values): array {
		$numbers = [];
		foreach ($values as $value) {
			if (ctype_digit((string)$value) && (int)$value >= 1) {
				$numbers[] = (int)$value;
			}
		}

		return array_values(array_unique($numbers));
	}

	/**
	 * The MedicationService::restrictionList twin — trim, drop empties,
	 * dedupe.
	 *
	 * @param list<mixed> $values
	 * @return list<string>
	 */
	private function derivedRestrictions(array $values): array {
		$restrictions = [];
		foreach ($values as $value) {
			$value = trim((string)$value);
			if ($value !== '') {
				$restrictions[] = $value;
			}
		}

		return array_values(array_unique($restrictions));
	}
}
