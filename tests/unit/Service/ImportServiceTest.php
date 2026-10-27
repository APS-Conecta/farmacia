<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

use OCA\Farmacia\Db\Category;
use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
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
use OCA\Farmacia\Service\CsvReader;
use OCA\Farmacia\Service\ImportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The staging's judgement: the disposition each planilla row earns, the
 * status the review triages it by, and the decisions that move a row
 * between them. The mappers are mocked because what has to be OBSERVED
 * here is the judgement itself — which rows land as what, and which
 * refusals fire before anything is written (the VocabularyServiceTest
 * split: the integration suite, ImportStagingTest, proves the other half
 * against a real database).
 */
final class ImportServiceTest extends TestCase {
	private CsvReader&MockObject $csv;
	private CategoryMapper&MockObject $categories;
	private ChannelMapper&MockObject $channels;
	private MedicationMapper&MockObject $medications;
	private MedicationGesMapper&MockObject $ges;
	private MedicationRestrictionMapper&MockObject $restrictions;
	private MedicationAbasteceMapper&MockObject $abastece;
	private ImportBatchMapper&MockObject $batches;
	private ImportRowMapper&MockObject $rows;
	private ImportService $service;

	/** @var list<ImportRow> the rows the mocked mapper swallowed */
	private array $inserted = [];

	protected function setUp(): void {
		$this->csv = $this->createMock(CsvReader::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->channels = $this->createMock(ChannelMapper::class);
		$this->medications = $this->createMock(MedicationMapper::class);
		$this->ges = $this->createMock(MedicationGesMapper::class);
		$this->restrictions = $this->createMock(MedicationRestrictionMapper::class);
		$this->abastece = $this->createMock(MedicationAbasteceMapper::class);
		$this->batches = $this->createMock(ImportBatchMapper::class);
		$this->rows = $this->createMock(ImportRowMapper::class);
		$this->service = new ImportService(
			$this->csv, $this->categories, $this->channels, $this->medications,
			$this->ges, $this->restrictions, $this->abastece, $this->batches, $this->rows,
		);

		// The mapper mocks the transaction and the inserts run through: the
		// service's atomicity contract is exercised for real in ImportStagingTest;
		// what these tests read is the judged entities themselves.
		$this->batches->method('transactionally')->willReturnCallback(
			static fn (callable $write) => $write(),
		);
		$this->batches->method('insert')->willReturnCallback(
			function (ImportBatch $batch) {
				$batch->setId(11);
				return $batch;
			},
		);
		$this->rows->method('insert')->willReturnCallback(
			function (ImportRow $row) {
				$row->setId(count($this->inserted) + 1);
				$this->inserted[] = $row;
				return $row;
			},
		);
	}

	/** The seeded categories and the channels a clinic defined, as indexes. */
	private function wire(): void {
		$this->categories->method('findAll')->willReturn([
			self::category(1, 'analgesicos_antipireticos_antiinflamatorios', 'Analgésicos / antipiréticos / antiinflamatorios'),
			self::category(2, 'salud_mental_incluye_tto_oh', 'Salud mental (incluye tto OH)'),
			self::category(3, 'otros', 'Otros'),
		]);
		$this->channels->method('findAll')->willReturn([
			self::channel(7, 'urgencias', 'URGENCIAS'),
			self::channel(8, 'cecosf', 'CECOSF'),
		]);
	}

	private static function category(int $id, string $slug, string $label): Category {
		$word = new Category();
		$word->setId($id);
		$word->setSlug($slug);
		$word->setLabel($label);
		return $word;
	}

	private static function channel(int $id, string $slug, string $label): Channel {
		$word = new Channel();
		$word->setId($id);
		$word->setSlug($slug);
		$word->setLabel($label);
		return $word;
	}

	/** A stored medication the planilla will meet, live by default. */
	private static function medication(string $codigo, bool $retired = false): Medication {
		$medication = new Medication();
		$medication->setId(1);
		$medication->setCodigo($codigo);
		$medication->setProducto('Producto de prueba');
		$medication->setCategoryId(3);
		$medication->setTrazador(false);
		$medication->setRetired($retired);
		$medication->setUpdatedAt(new \DateTime('2026-09-18 10:00:00'));
		return $medication;
	}

	/** The stored side of one medication, as storedByCodigo builds it. */
	private function wireStored(Medication $medication, array $ges = [], array $restrictions = [], array $abastece = []): void {
		$this->medications->method('findAll')->willReturn([$medication]);
		$this->medications->method('findRetired')->willReturn($medication->getRetired() ? [$medication] : []);
		$this->ges->method('forIds')->willReturn([(int)$medication->getId() => $ges]);
		$this->restrictions->method('forIds')->willReturn([(int)$medication->getId() => $restrictions]);
		$this->abastece->method('forIds')->willReturn([(int)$medication->getId() => $abastece]);
	}

	/** One planilla row, as the reader hands it over. */
	private static function readRow(int $line, array $cells): array {
		return ['line' => $line, 'cells' => $cells];
	}

	/** A full row's cells for a new medication. */
	private static function fullCells(string $codigo): array {
		return [
			'codigo' => $codigo,
			'producto' => 'Producto de prueba',
			'farmaco' => 'paracetamol',
			'mg' => '500',
			'categoria' => 'Analgésicos / antipiréticos / antiinflamatorios',
			'trazador' => 'No',
			'ges' => ['17'],
			'restricciones' => ['Solo receta retenida'],
			'abastece' => ['URGENCIAS', 'CECOSF'],
			'fdaEmbarazo' => 'B',
		];
	}

	public function testANewCodigoStagesAsACreate(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$this->csv->method('read')->willReturn([self::readRow(2, self::fullCells('F-009'))]);

		$doc = $this->service->stage('csv', 'planilla.csv', 'tester');

		self::assertSame(1, $doc['batch']['created']);
		self::assertSame('staged', $doc['batch']['state']);
		self::assertSame('tester', $doc['batch']['actor']);

		$row = $doc['rows'][0];
		self::assertSame('create', $row['disposition']);
		self::assertSame('staged', $row['status']);
		self::assertNull($row['notes']);
		self::assertSame(1, $row['payload']['categoryId'], 'the seed-folded label resolved');
		self::assertSame([7, 8], $row['payload']['abasteceIds']);
		self::assertSame([], $row['payload']['pending']);
	}

	public function testAChangedStoredMedicationStagesAsAnUpdate(): void {
		$this->wire();
		$this->wireStored(self::medication('F-001'));
		$cells = self::fullCells('F-001');
		$cells['producto'] = 'Otro nombre';
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame(1, $doc['batch']['updated']);
		self::assertSame('update', $doc['rows'][0]['disposition']);
	}

	public function testAMatchingStoredMedicationStagesAsUnchanged(): void {
		$this->wire();
		$stored = self::medication('F-001');
		$stored->setProducto('Producto de prueba');
		$stored->setFarmaco('paracetamol');
		$stored->setMg('500');
		$stored->setFdaEmbarazo('B');
		$this->wireStored($stored, [17], ['Solo receta retenida'], [7, 8]);

		$cells = self::fullCells('F-001');
		$cells['categoria'] = 'Otros';
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame(1, $doc['batch']['unchanged']);
		self::assertSame('unchanged', $doc['rows'][0]['disposition'], 'the derived row IS the stored one: a re-import of the export changes nothing');
	}

	public function testANarrowPlanillaRowLeavesTheStoredFieldsAlone(): void {
		$this->wire();
		$this->wireStored(self::medication('F-001'), [17], ['Solo receta retenida'], [7, 8]);
		// A planilla carrying only the código: everything else the stored
		// record already says, the apply must leave untouched.
		$this->csv->method('read')->willReturn([self::readRow(2, ['codigo' => 'F-001'])]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame('unchanged', $doc['rows'][0]['disposition'], 'a column the file does not carry is a field the apply does not touch');
	}

	public function testARetiredRecordIsSkippedWhateverTheRowSays(): void {
		$this->wire();
		$this->wireStored(self::medication('F-001', retired: true));
		$cells = self::fullCells('F-001');
		$cells['producto'] = 'Otro nombre';
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame(1, $doc['batch']['skipped']);
		self::assertSame('skipped', $doc['rows'][0]['disposition']);
		self::assertSame('el registro está retirado: la importación no lo toca', $doc['rows'][0]['notes']);
	}

	public function testARepeatedCodigoStagesTheSecondRowAsDuplicateNamingTheFirstLine(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$this->csv->method('read')->willReturn([
			self::readRow(2, self::fullCells('F-009')),
			self::readRow(3, self::fullCells('F-009')),
		]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame('create', $doc['rows'][0]['disposition']);
		self::assertSame('duplicate', $doc['rows'][1]['disposition']);
		self::assertSame('el código «F-009» ya apareció en la línea 2', $doc['rows'][1]['notes']);
		self::assertSame(1, $doc['batch']['created']);
		self::assertSame(1, $doc['batch']['duplicates']);
	}

	public function testABadFdaCellStagesInvalidUnsupportedWithTheSentence(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$cells = self::fullCells('F-009');
		$cells['fdaEmbarazo'] = 'Q';
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame('unsupported', $doc['rows'][0]['disposition']);
		self::assertSame('invalid', $doc['rows'][0]['status']);
		self::assertStringContainsString('la celda FDA embarazo dice «Q»', (string)$doc['rows'][0]['notes']);
		self::assertSame(1, $doc['batch']['unsupported']);
	}

	public function testAGesCellBeyondTheColumnStagesInvalidAndTheRestStillStage(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$cells = self::fullCells('F-009');
		$cells['ges'] = ['56912345678']; // a pasted phone number
		$this->csv->method('read')->willReturn([
			self::readRow(2, $cells),
			self::readRow(3, self::fullCells('F-010')),
		]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame('invalid', $doc['rows'][0]['status']);
		self::assertSame('unsupported', $doc['rows'][0]['disposition']);
		self::assertStringContainsString('la celda GES trae «56912345678»: un número de problema GES', (string)$doc['rows'][0]['notes']);
		self::assertSame('staged', $doc['rows'][1]['status']);
		self::assertSame('create', $doc['rows'][1]['disposition']);
		self::assertSame(1, $doc['batch']['unsupported']);
		self::assertSame(1, $doc['batch']['created']);
	}

	public function testAnOverLongCodigoIsStoredCutToTheColumnWidthAndStagesInvalid(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		// Multibyte, so a byte-wise cut fails; the repeat takes the duplicate branch.
		$this->csv->method('read')->willReturn([
			self::readRow(2, self::fullCells(str_repeat('Á', 200))),
			self::readRow(3, self::fullCells(str_repeat('B', 128))),
			self::readRow(4, self::fullCells(str_repeat('Á', 200))),
		]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame(str_repeat('Á', 128), $this->inserted[0]->getCodigo());
		self::assertSame(str_repeat('Á', 200), json_decode($this->inserted[0]->getPayload(), true)['codigo'], 'the full cell stays in the payload');
		self::assertSame(['unsupported', 'invalid'], [$doc['rows'][0]['disposition'], $doc['rows'][0]['status']]);
		self::assertStringContainsString('la celda Código pasa de 128 caracteres', (string)$doc['rows'][0]['notes']);
		self::assertSame(str_repeat('B', 128), $this->inserted[1]->getCodigo(), 'a código at the column width is stored unchanged');
		self::assertSame('create', $doc['rows'][1]['disposition']);
		self::assertSame(str_repeat('Á', 128), $this->inserted[2]->getCodigo());
		self::assertSame('duplicate', $doc['rows'][2]['disposition']);
		self::assertSame([1, 1], [$doc['batch']['unsupported'], $doc['batch']['created']]);
	}

	public function testAnUnknownCategoryAndChannelSurfaceAsReviewDecisions(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$cells = self::fullCells('F-009');
		$cells['categoria'] = 'Cardiovasculres'; // the typo a person makes
		$cells['abastece'] = ['URGENCIAS', 'SECO'];
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		$row = $doc['rows'][0];
		self::assertSame('create', $row['disposition'], 'provisional: what the apply would do once the review decides');
		self::assertSame('unresolved-vocabulary', $row['status']);
		self::assertSame('Cardiovasculres', $row['payload']['pending']['categoria']);
		self::assertSame(['SECO'], $row['payload']['pending']['abastece']);
		self::assertSame([7], $row['payload']['abasteceIds'], 'the known channel still resolved');
		self::assertStringContainsString('la categoría «Cardiovasculres» no existe', (string)$row['notes']);
		self::assertStringContainsString('el canal «SECO» no existe', (string)$row['notes']);
	}

	public function testACreateFromAPlanillaWithoutACategoriaColumnAsksForTheCategory(): void {
		$this->wire();
		$this->medications->method('findAll')->willReturn([]);
		$this->medications->method('findRetired')->willReturn([]);
		$cells = ['codigo' => 'F-009', 'producto' => 'Producto de prueba'];
		$this->csv->method('read')->willReturn([self::readRow(2, $cells)]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		$row = $doc['rows'][0];
		self::assertSame('unresolved-vocabulary', $row['status']);
		self::assertNull($row['payload']['pending']['categoria']);
		self::assertStringContainsString('el archivo no trae columna Categoría', (string)$row['notes']);
	}

	public function testANarrowPlanillaStagesANewCodigoInvalidAndStillUpdatesTheStoredOne(): void {
		$this->wire();
		$this->wireStored(self::medication('F-001'));
		$this->csv->method('read')->willReturn([
			self::readRow(2, ['codigo' => 'NEW-1', 'fdaEmbarazo' => 'C']),
			self::readRow(3, ['codigo' => 'F-001', 'fdaEmbarazo' => 'C']),
			self::readRow(4, ['fdaEmbarazo' => 'C']),
		]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		[$new, $stored, $blank] = $doc['rows'];
		self::assertSame(['unsupported', 'invalid'], [$new['disposition'], $new['status']], 'no review decision can supply a producto');
		self::assertStringContainsString('el archivo no trae columna Producto', (string)$new['notes']);
		self::assertSame(['update', 'staged'], [$stored['disposition'], $stored['status']]);
		self::assertSame('la fila no trae código', $blank['notes']);
		self::assertSame([0, 1, 2], [$doc['batch']['created'], $doc['batch']['updated'], $doc['batch']['unsupported']]);
	}

	public function testAnUncleanRestrictionSplitIsFlaggedNotJudgedAnUpdate(): void {
		$this->wire();
		// One stored restriction containing the separator — space-padded, the
		// way a person types it: the planilla cell splits into two on the way
		// back, and the re-split of the stored join is what still matches.
		$this->wireStored(self::medication('F-001'), [], ['solo recetas | controlar stock'], []);
		$this->csv->method('read')->willReturn([self::readRow(2, [
			'codigo' => 'F-001',
			'producto' => 'Producto de prueba',
			'restricciones' => ['solo recetas', 'controlar stock'],
		])]);

		$doc = $this->service->stage('csv', 'planilla.csv', null);

		self::assertSame('unsupported', $doc['rows'][0]['disposition']);
		self::assertSame('invalid', $doc['rows'][0]['status']);
		self::assertStringContainsString('una restricción guardada contiene «|»', (string)$doc['rows'][0]['notes']);
	}

	public function testDecideResolvesTheCategoryAndMovesTheRowToStaged(): void {
		$this->wire();
		// decide() reads ONE record now (L2-08): the código the row carries
		// resolves or it does not — F-009 is unstored, so it does not.
		$this->medications->method('findByCodigo')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no'));
		$row = $this->undecidedRow(['categoria' => null]);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());
		$this->categories->method('find')->willReturn(self::category(2, 'salud_mental_incluye_tto_oh', 'Salud mental (incluye tto OH)'));

		$updated = $this->service->decide((int)$row->getId(), ['categoryId' => 2]);

		self::assertSame('staged', $updated['status']);
		self::assertSame('create', $updated['disposition']);
		self::assertSame(2, $updated['payload']['categoryId']);
		self::assertSame([], $updated['payload']['pending']);
	}

	/** @return array<string, array{string}> the dispositions a row judged against a stored record carries */
	public static function judgedAgainstAStoredRecord(): array {
		return ['update' => ['update'], 'unchanged' => ['unchanged']];
	}

	/** @dataProvider judgedAgainstAStoredRecord */
	public function testDecideKeepsARowJudgedAgainstAVanishedRecordAnUpdate(string $prior): void {
		$this->wire();
		// The stored código was edited after the upload: it no longer resolves.
		$this->medications->method('findByCodigo')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no'));
		$row = $this->undecidedRow(['categoria' => 'Cardiovasculres']);
		$row->setDisposition($prior);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());
		$this->categories->method('find')->willReturn(self::category(2, 'salud_mental_incluye_tto_oh', 'Salud mental (incluye tto OH)'));

		$updated = $this->service->decide((int)$row->getId(), ['categoryId' => 2]);

		self::assertSame('update', $updated['disposition'], 'a create would land a second medication under the old código');
	}

	public function testDecideReDerivesACreateRowAgainstAHandMadeRecord(): void {
		$this->wire();
		// Someone created F-009 by hand after the upload: the row now meets it.
		$stored = new Medication();
		$stored->setCodigo('F-009');
		$stored->setProducto('Otro nombre');
		$stored->setRetired(false);
		$this->medications->method('findByCodigo')->willReturn($stored);
		$row = $this->undecidedRow(['categoria' => null]);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());
		$this->categories->method('find')->willReturn(self::category(2, 'salud_mental_incluye_tto_oh', 'Salud mental (incluye tto OH)'));

		$updated = $this->service->decide((int)$row->getId(), ['categoryId' => 2]);

		self::assertSame('update', $updated['disposition']);
	}

	public function testDecideCanLeaveAChannelUnassigned(): void {
		$this->wire();
		$this->medications->method('findByCodigo')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no'));
		$row = $this->undecidedRow(['abastece' => ['SECO']]);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());

		$updated = $this->service->decide((int)$row->getId(), ['abastece' => []]);

		self::assertSame('staged', $updated['status'], '«dejar sin canal» is an answer, not a pending');
		self::assertSame([], $updated['payload']['abasteceIds']);
		self::assertSame([], $updated['payload']['pending']);
	}

	public function testDecideOnAnAppliedBatchIsRefused(): void {
		$this->wire();
		$row = $this->undecidedRow(['categoria' => 'Cardiovasculres']);
		$this->rows->method('find')->willReturn($row);
		$batch = new ImportBatch();
		$batch->setId(11);
		$batch->setState(ImportBatch::STATE_APPLIED);
		$this->batches->method('find')->willReturn($batch);

		try {
			$this->service->decide((int)$row->getId(), ['categoryId' => 1]);
			self::fail('a decision on an applied batch must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}
	}

	public function testDecideOnADuplicateRowIsRefused(): void {
		$this->wire();
		$row = $this->undecidedRow([]);
		$row->setDisposition('duplicate');
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());

		try {
			$this->service->decide((int)$row->getId(), ['categoryId' => 1]);
			self::fail('a duplicate row is not waiting for a decision');
		} catch (InvalidImportException $e) {
			self::assertSame('La fila no espera una decisión.', $e->getMessage());
		}
	}

	public function testDecideRefusesAnUnknownCategoryId(): void {
		$this->wire();
		$row = $this->undecidedRow(['categoria' => 'Cardiovasculres']);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());
		$this->categories->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no'));

		try {
			$this->service->decide((int)$row->getId(), ['categoryId' => 999]);
			self::fail('an unknown category id must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('La categoría 999 no existe.', $e->getMessage());
		}
	}

	public function testDecideRefusesInvalidChannelIdType(): void {
		$this->wire();
		$row = $this->undecidedRow(['abastece' => ['SECO']]);
		$this->rows->method('find')->willReturn($row);
		$this->batches->method('find')->willReturn($this->stagedBatch());

		try {
			$this->service->decide((int)$row->getId(), ['abastece' => ['invalid']]);
			self::fail('a non-numeric channel id must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('Cada canal de abastece es un número.', $e->getMessage());
		}
	}

	public function testTheEmptyPlanillaIsRefused(): void {
		$this->csv->method('read')->willReturn([]);

		try {
			$this->service->stage('Código;Producto\n', 'planilla.csv', null);
			self::fail('a header-only file stages nothing and must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('El archivo no tiene filas debajo del encabezado.', $e->getMessage());
		}
	}

	/** A staged batch, as the review reopens it. */
	private function stagedBatch(): ImportBatch {
		$batch = new ImportBatch();
		$batch->setId(11);
		$batch->setState(ImportBatch::STATE_STAGED);
		return $batch;
	}

	/** A row as the staging left it: in a staged batch, awaiting decisions. */
	private function undecidedRow(array $pending): ImportRow {
		$payload = [
			'codigo' => 'F-009',
			'producto' => 'Producto de prueba',
			'categoryId' => null,
			'abasteceIds' => [],
			'pending' => $pending,
		];
		$row = new ImportRow();
		$row->setId(31);
		$row->setBatchId(11);
		$row->setLineNumber(2);
		$row->setCodigo('F-009');
		$row->setPayload((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
		$row->setDisposition('create');
		$row->setStatus($pending === [] ? 'staged' : 'unresolved-vocabulary');
		return $row;
	}
}
