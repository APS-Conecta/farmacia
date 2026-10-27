<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\ImportService;
use OCA\Farmacia\Service\MedicationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * The second phase against the real database: the apply must really land
 * the reviewed rows through MedicationService, the revert must really put
 * them back, and the touched-since guard must really skip what someone
 * touched in between — the three properties no mock can know. The cleanup
 * registers everything a batch lands (its medications resolve by the
 * código the apply wrote) and the revert batches themselves, so a second
 * run stages a clean arsenal again.
 */
final class ApplyRevertTest extends IntegrationTestCase {
	private ApplyService $apply;
	private ImportService $import;
	private MedicationService $medications;

	/** The committed fixture — the realistic planilla. */
	private const FIXTURE = __DIR__ . '/../fixtures/planilla.csv';

	protected function setUp(): void {
		$this->apply = Server::get(ApplyService::class);
		$this->import = Server::get(ImportService::class);
		$this->medications = Server::get(MedicationService::class);
	}

	/** A channel to decide the fixture's owed rows with, registered for cleanup. */
	private function aChannel(): int {
		$channel = new Channel();
		$channel->setSlug('urgencias-' . count($this->createdChannels));
		$channel->setLabel('URGENCIAS (prueba)');
		$channel = Server::get(ChannelMapper::class)->insert($channel);
		$this->createdChannels[] = (int)$channel->getId();

		return (int)$channel->getId();
	}

	/** The fixture staged with every owed decision resolved. */
	private function stagedFixture(): array {
		$doc = $this->stage((string)file_get_contents(self::FIXTURE), 'planilla.csv');
		$channel = $this->aChannel();

		foreach ($doc['rows'] as $row) {
			if ($row['status'] === 'unresolved-vocabulary') {
				$this->import->decide((int)$row['id'], ['abastece' => [$channel]]);
			}
		}

		return $this->import->review((int)$doc['batch']['id']);
	}

	/** Register every medication a batch landed, so tearDown removes it. */
	private function registerByCodigo(string ...$codigos): void {
		$mapper = Server::get(MedicationMapper::class);
		foreach ($codigos as $codigo) {
			$this->created[] = (int)$mapper->findByCodigo($codigo)->getId();
		}
	}

	public function testApplyLandsTheReviewAndClosesTheBatch(): void {
		$doc = $this->stagedFixture();

		$applied = $this->apply->apply((int)$doc['batch']['id']);

		self::assertSame('applied', $applied['batch']['state']);
		self::assertNotNull($applied['batch']['appliedAt']);
		self::assertSame(5, $applied['batch']['created']);

		$this->registerByCodigo('F-001', 'F-002', 'F-003', 'F-004', 'F-005');
		$landed = Server::get(MedicationMapper::class)->findByCodigo('F-001');
		self::assertSame('Paracetamol 500 mg comprimidos', $landed->getProducto());
		self::assertSame($this->categoryIdBySlug('analgesicos_antipireticos_antiinflamatorios'), $landed->getCategoryId());
		self::assertSame('B', $landed->getFdaEmbarazo());
		self::assertSame([17], Server::get(\OCA\Farmacia\Db\MedicationGesMapper::class)->findAllFor((int)$landed->getId()));

		// The creates carry no before-image: there was nothing to go back to.
		foreach ($applied['rows'] as $row) {
			self::assertNull($row['beforeValues']);
		}

		// The apply is not repeatable.
		try {
			$this->apply->apply((int)$doc['batch']['id']);
			self::fail('an applied batch cannot be applied again');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}
	}

	public function testApplyRefusesWhileADecisionIsOwed(): void {
		$doc = $this->stage((string)file_get_contents(self::FIXTURE), 'planilla.csv');

		try {
			$this->apply->apply((int)$doc['batch']['id']);
			self::fail('the fixture owes four channel decisions');
		} catch (InvalidImportException $e) {
			self::assertSame('4 fila(s) esperan una decisión de vocabulario antes de aplicar.', $e->getMessage());
		}
	}

	public function testApplyThenRevertRestoresTheArsenal(): void {
		$id = $this->medication('AR-001', ['producto' => 'Nombre original', 'ges' => [17], 'restricciones' => ['Solo receta retenida']]);

		$doc = $this->stage("Código;Producto;Categoría;GES\nAR-001;Nombre cambiado;Otros;1\nAR-002;Producto nuevo;Otros;\n", 'ar.csv');
		$applied = $this->apply->apply((int)$doc['batch']['id']);
		$this->registerByCodigo('AR-002');

		$updated = Server::get(MedicationMapper::class)->findByCodigo('AR-001');
		self::assertSame('Nombre cambiado', $updated->getProducto());

		$revert = $this->apply->revert((int)$doc['batch']['id'], 'test');
		$this->createdBatches[] = (int)$revert['id'];

		self::assertSame(2, $revert['updated']);
		self::assertSame(0, $revert['skipped']);

		$restored = Server::get(MedicationMapper::class)->find($id);
		self::assertSame('Nombre original', $restored->getProducto(), 'the before-image overlay');
		self::assertSame([17], Server::get(\OCA\Farmacia\Db\MedicationGesMapper::class)->findAllFor($id));
		self::assertSame(['Solo receta retenida'], Server::get(\OCA\Farmacia\Db\MedicationRestrictionMapper::class)->findAllFor($id));

		$created = Server::get(MedicationMapper::class)->findByCodigo('AR-002');
		self::assertTrue($created->getRetired(), 'the inverse of a create is retirement, never deletion');
		$found = array_values(array_filter(
			$this->medications->listRetired(),
			static fn (array $row): bool => $row['codigo'] === 'AR-002',
		));
		self::assertCount(1, $found, '«Ver retirados» offers it back, children attached');
		self::assertSame([], $found[0]['ges']);
	}

	public function testTheTouchedSinceGuardSkipsAHandEdit(): void {
		$doc = $this->stage("Código;Producto;Categoría\nAR-003;Producto nuevo;Otros\nAR-004;Otro producto;Otros\n", 'tocado.csv');
		$this->apply->apply((int)$doc['batch']['id']);
		$this->registerByCodigo('AR-003', 'AR-004');

		// Second precision: the guard compares DATETIME stamps, so the hand
		// edit needs a second of distance to be a different one.
		sleep(1);
		$touched = Server::get(MedicationMapper::class)->findByCodigo('AR-003');
		$this->medications->update((int)$touched->getId(), ['producto' => 'Editado a mano']);

		$revert = $this->apply->revert((int)$doc['batch']['id'], 'test');
		$this->createdBatches[] = (int)$revert['id'];

		self::assertSame(1, $revert['updated']);
		self::assertSame(1, $revert['skipped']);

		$stillTouched = Server::get(MedicationMapper::class)->findByCodigo('AR-003');
		self::assertFalse($stillTouched->getRetired(), 'the hand edit owns the record now');
		self::assertSame('Editado a mano', $stillTouched->getProducto());
		self::assertTrue(Server::get(MedicationMapper::class)->findByCodigo('AR-004')->getRetired());
	}

	public function testRevertingTwiceIsRefused(): void {
		$doc = $this->stage("Código;Producto;Categoría\nAR-005;Producto nuevo;Otros\n", 'dos.csv');
		$this->apply->apply((int)$doc['batch']['id']);
		$this->registerByCodigo('AR-005');

		$revert = $this->apply->revert((int)$doc['batch']['id'], 'test');
		$this->createdBatches[] = (int)$revert['id'];

		try {
			$this->apply->revert((int)$doc['batch']['id'], 'test');
			self::fail('the second undo must be refused');
		} catch (InvalidImportException $e) {
			self::assertSame('Ese lote ya está deshecho.', $e->getMessage());
		}
	}

	public function testDiscardClosesTheBatchAndShutsItsDoors(): void {
		$doc = $this->stage("Código;Producto;Categoría\nAR-006;Producto;Otros\n", 'cerrar.csv');

		$discarded = $this->apply->discard((int)$doc['batch']['id']);

		self::assertSame('discarded', $discarded['batch']['state']);

		try {
			$this->apply->apply((int)$doc['batch']['id']);
			self::fail('a discarded batch cannot be applied');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}

		$row = $discarded['rows'][0];
		try {
			$this->import->decide((int)$row['id'], ['categoryId' => $this->categoryIdBySlug('otros')]);
			self::fail('a discarded batch no longer takes decisions');
		} catch (InvalidImportException $e) {
			self::assertSame('El lote ya no está en revisión.', $e->getMessage());
		}
	}

	public function testAnUnchangedRowAppliesWithoutStamping(): void {
		$id = $this->medication('AR-007', ['producto' => 'Producto de prueba']);
		$before = Server::get(MedicationMapper::class)->find($id)->getUpdatedAt()->format('Y-m-d H:i:s');

		$doc = $this->stage("Código;Producto;Categoría\nAR-007;Producto de prueba;Otros\n", 'igual.csv');
		self::assertSame('unchanged', $doc['rows'][0]['disposition'], 'the fixture must judge the row unchanged for this test to mean anything');

		$this->apply->apply((int)$doc['batch']['id']);

		$after = Server::get(MedicationMapper::class)->find($id)->getUpdatedAt()->format('Y-m-d H:i:s');
		self::assertSame($before, $after, 'a row judged unchanged must apply as a no-op: the stage\'s prediction IS the apply\'s write');
	}

	public function testANarrowPlanillaAppliesItsUpdatesAndNeverCreatesTheNewCodigo(): void {
		$this->medication('AR-009');

		$doc = $this->stage("Código;FDA embarazo\nAR-009;C\nAR-010;C\n", 'estrecha.csv');
		$rows = array_column($doc['rows'], null, 'codigo');
		self::assertSame(['unsupported', 'invalid'], [$rows['AR-010']['disposition'], $rows['AR-010']['status']], 'a new código the apply cannot create must stage invalid, or it refuses the whole lote');
		self::assertStringContainsString('la fila no trae producto', (string)$rows['AR-010']['notes']);

		$applied = $this->apply->apply((int)$doc['batch']['id']);

		self::assertSame('applied', $applied['batch']['state']);
		self::assertSame([1, 0, 1], [$applied['batch']['updated'], $applied['batch']['created'], $applied['batch']['unsupported']]);
		$mapper = Server::get(MedicationMapper::class);
		self::assertSame('C', $mapper->findByCodigo('AR-009')->getFdaEmbarazo());
		$this->expectException(DoesNotExistException::class);
		$mapper->findByCodigo('AR-010');
	}

	public function testTheRevertBatchIsListedAndMarksTheOriginalUndone(): void {
		$doc = $this->stage("Código;Producto;Categoría\nAR-008;Producto nuevo;Otros\n", 'lista.csv');
		$this->apply->apply((int)$doc['batch']['id']);
		$this->registerByCodigo('AR-008');

		$revert = $this->apply->revert((int)$doc['batch']['id'], 'test');
		$this->createdBatches[] = (int)$revert['id'];

		$batches = $this->import->batches();
		$byId = [];
		foreach ($batches as $batch) {
			$byId[(int)$batch['id']] = $batch;
		}

		self::assertArrayHasKey((int)$revert['id'], $byId);
		self::assertTrue($byId[(int)$revert['id']]['isRevert']);
		self::assertSame((int)$doc['batch']['id'], $byId[(int)$revert['id']]['revertsId']);
		self::assertTrue($byId[(int)$doc['batch']['id']]['undone'], 'the list stops offering Deshacer on the original');
	}
}
