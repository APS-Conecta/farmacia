<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ImportService;
use OCA\Farmacia\Service\MedicationService;
use OCP\Server;

/**
 * Staging against the real database: the entities' camelCase properties
 * must land in the migration's snake_case columns (the IntegrationTestCase
 * doctrine — invisible until data is lost), the fixture's category labels
 * must resolve against the seed's pre-folded slugs (EnsureSeedData's
 * promise, pinned here), and staging must leave the medication tables
 * untouched, which is the whole point of the two-phase design.
 */
final class ImportStagingTest extends IntegrationTestCase {
	private ImportService $import;

	/** The committed fixture — the realistic planilla. */
	private const FIXTURE = __DIR__ . '/../fixtures/planilla.csv';

	protected function setUp(): void {
		$this->import = Server::get(ImportService::class);
	}

	private function fixtureContents(): string {
		return (string)file_get_contents(self::FIXTURE);
	}

	public function testStagingWritesTheBatchAndItsRowsAndTouchesNoMedication(): void {
		$before = count(Server::get(MedicationService::class)->listAll());
		$contents = $this->fixtureContents();

		$doc = $this->stage($contents, 'planilla.csv');

		$batch = $doc['batch'];
		self::assertSame('staged', $batch['state']);
		self::assertSame(5, $batch['created']);
		self::assertSame(hash('sha256', $contents), $batch['fileHash']);
		self::assertSame('planilla.csv', $batch['originalFilename']);
		self::assertSame('test', $batch['actor']);
		self::assertNull($batch['appliedAt']);

		self::assertCount(5, $doc['rows']);
		$first = $doc['rows'][0];
		self::assertSame(2, $first['lineNumber'], 'the heading is line 1: what the review names is the spreadsheet\'s own row');
		self::assertSame('F-001', $first['codigo']);
		self::assertSame('create', $first['disposition']);
		self::assertSame(
			$this->categoryIdBySlug('analgesicos_antipireticos_antiinflamatorios'),
			$first['payload']['categoryId'],
			'the fixture\'s category label resolved against the seed\'s pre-folded slugs — EnsureSeedData\'s promise',
		);
		self::assertSame('unresolved-vocabulary', $first['status'], 'ABASTECE starts empty: the channels are the review\'s decisions');
		self::assertSame(['URGENCIAS', 'CECOSF'], $first['payload']['pending']['abastece']);
		self::assertNull($first['beforeValues'], 'before-images are captured at apply time, never at stage');

		self::assertSame($before, count(Server::get(MedicationService::class)->listAll()), 'staging is the whole point: no medication row was written');
	}

	public function testDecideMovesAResolvedRowToStaged(): void {
		$doc = $this->stage($this->fixtureContents());
		$row = $doc['rows'][0];

		// The channel the review declares — the vocabulary starts empty, so
		// the reviewer's own word is what resolves the pending decision.
		$channel = new Channel();
		$channel->setSlug('urgencias');
		$channel->setLabel('URGENCIAS');
		$channel = Server::get(ChannelMapper::class)->insert($channel);
		$this->createdChannels[] = (int)$channel->getId();

		$updated = $this->import->decide((int)$row['id'], ['abastece' => [(int)$channel->getId()]]);

		self::assertSame('staged', $updated['status']);
		self::assertSame([(int)$channel->getId()], $updated['payload']['abasteceIds']);
		self::assertArrayNotHasKey('abastece', $updated['payload']['pending']);
	}

	public function testTheDispositionMatrixAgainstRealRows(): void {
		$this->medication('IM-001', ['ges' => [1]]);
		$this->medication('IM-002');
		$retired = $this->medication('IM-003');
		Server::get(MedicationService::class)->retire($retired);

		$csv = "Código;Producto;Categoría;FDA embarazo\n"
			. "IM-001;Producto cambiado;Otros;\n"
			. "IM-002;Producto de prueba;Otros;\n"
			. "IM-003;Producto de prueba;Otros;\n"
			. "IM-004;Producto nuevo;Otros;Q\n"
			. "IM-001;Producto cambiado de nuevo;Otros;\n"
			. "IM-005;Producto nuevo;Otros;\n";

		$doc = $this->stage($csv, 'matriz.csv');

		$byCodigo = [];
		foreach ($doc['rows'] as $row) {
			$byCodigo[$row['codigo']][] = $row;
		}

		self::assertSame('update', $byCodigo['IM-001'][0]['disposition']);
		self::assertSame('duplicate', $byCodigo['IM-001'][1]['disposition']);
		self::assertSame('unchanged', $byCodigo['IM-002'][0]['disposition']);
		self::assertSame('skipped', $byCodigo['IM-003'][0]['disposition']);
		self::assertSame('invalid', $byCodigo['IM-004'][0]['status']);
		self::assertSame('unsupported', $byCodigo['IM-004'][0]['disposition']);
		self::assertSame('create', $byCodigo['IM-005'][0]['disposition']);

		self::assertSame(1, $doc['batch']['created']);
		self::assertSame(1, $doc['batch']['updated']);
		self::assertSame(1, $doc['batch']['unchanged']);
		self::assertSame(1, $doc['batch']['skipped']);
		self::assertSame(1, $doc['batch']['duplicates']);
		self::assertSame(1, $doc['batch']['unsupported']);
	}

	public function testAnOverLongCodigoStagesInvalidAndTheOtherRowsStillStage(): void {
		$doc = $this->stage("Código;Producto;Categoría\n"
			. str_repeat('X', 200) . ";Producto nuevo;Otros\n"
			. "IM-101;Producto nuevo;Otros\n"
			. "IM-102;Producto nuevo;Otros\n", 'larga.csv');

		self::assertSame([2, 1], [$doc['batch']['created'], $doc['batch']['unsupported']]);
		$byLine = array_column($this->import->review((int)$doc['batch']['id'])['rows'], null, 'lineNumber');
		self::assertSame(128, mb_strlen($byLine[2]['codigo']), 'the staging column holds the cut código');
		self::assertSame(str_repeat('X', 200), $byLine[2]['payload']['codigo'], 'the full cell stays in the payload');
		self::assertStringContainsString('pasa de 128 caracteres', (string)$byLine[2]['notes']);
		foreach ([3 => 'IM-101', 4 => 'IM-102'] as $line => $codigo) {
			self::assertSame([$codigo, 'create', 'staged'], [$byLine[$line]['codigo'], $byLine[$line]['disposition'], $byLine[$line]['status']]);
		}

		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('La fila no espera una decisión.');
		$this->import->decide((int)$byLine[2]['id'], ['categoryId' => $this->categoryIdBySlug('otros')]);
	}

	public function testReviewReopensTheDocumentAndBatchesListsIt(): void {
		$doc = $this->stage("Código;Producto\nIM-009;Algo\n", 'reabrir.csv');

		$again = $this->import->review((int)$doc['batch']['id']);

		self::assertSame($doc['batch']['id'], $again['batch']['id']);
		self::assertCount(1, $again['rows']);
		self::assertSame('IM-009', $again['rows'][0]['codigo']);

		$list = $this->import->batches();
		self::assertContains($doc['batch']['id'], array_map(static fn ($b): int => (int)$b['id'], $list));
	}
}
