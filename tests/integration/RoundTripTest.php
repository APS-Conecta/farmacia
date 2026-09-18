<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\CsvReader;
use OCA\Farmacia\Service\ExportService;
use OCA\Farmacia\Service\MedicationService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\Server;

/**
 * The round-trip pair's proof: the export re-imported reads unchanged, row
 * for row, against real PostgreSQL — reader, judge and writer in one pass,
 * which is the only place the promise can be tested (a unit can mock the
 * service, and then it is the mock that round-trips).
 *
 * The honest boundaries get their own tests rather than being quietly
 * excluded from the set: a stored restriction carrying the planilla's
 * separator, and a renamed vocabulary word, both resurface in the review as
 * decisions — the designed safety net, not silent data loss — and a retired
 * medication is absent from the export entirely, for the reason the service
 * states. The empty-arsenal test doubles as the leak canary: it fails if
 * any earlier integration test's rows survived its own cleanup.
 */
final class RoundTripTest extends IntegrationTestCase {
	private ExportService $exports;

	protected function setUp(): void {
		$this->exports = Server::get(ExportService::class);
	}

	public function testTheArsenalReimportedReadsUnchanged(): void {
		$channel = $this->channel('Canal de prueba');
		$this->medication('RT-001', [
			'producto' => 'Paracetamol 500 mg comprimidos',
			'farmaco' => 'paracetamol',
			'mg' => '500',
			'ges' => [1, 17],
			'restricciones' => ['Solo receta retenida'],
			'abastece' => [$channel],
			'fdaEmbarazo' => 'B',
			'trazador' => true,
		]);
		$this->medication('RT-002', [
			'producto' => 'Enalapril 10 mg comprimidos',
			'ajusteRenal' => 'Reducir dosis en insuficiencia renal',
		]);

		$file = $this->exports->export();
		self::assertSame('text/csv; charset=utf-8', $file['mime']);
		self::assertMatchesRegularExpression('/^farmacia-\d{4}-\d{2}-\d{2}\.csv$/', $file['filename']);

		$review = $this->stage($file['body'], $file['filename']);

		self::assertSame(2, $review['batch']['unchanged'], 'every exported row reads unchanged against the arsenal it came from');
		self::assertSame(
			0,
			$review['batch']['created'] + $review['batch']['updated']
				+ $review['batch']['skipped'] + $review['batch']['duplicates'] + $review['batch']['unsupported'],
		);
		foreach ($review['rows'] as $row) {
			self::assertSame('unchanged', $row['disposition']);
			self::assertSame('staged', $row['status']);
			self::assertNull($row['notes']);
		}
	}

	public function testARestrictionCarryingTheSeparatorSurfacesForReview(): void {
		$this->medication('RT-S1', ['restricciones' => ['Solo receta retenida', 'Visita | firma']]);

		$review = $this->stage($this->exports->export()['body'], 'export.csv');

		self::assertSame(1, $review['batch']['unsupported']);
		self::assertSame('invalid', $review['rows'][0]['status']);
		self::assertStringContainsString(
			'el separador de la planilla',
			(string)$review['rows'][0]['notes'],
			'the S7 judge anticipated exactly this file; the sentence names the fix, not the person re-typing the restriction',
		);
	}

	public function testARenamedCategorySurfacesAsADecisionNotSilentData(): void {
		// A word this test owns, never a seed row: renaming a seed category
		// would outlive the cleanup and poison every later run — the
		// exactly-16 lesson, one door down.
		$added = Server::get(VocabularyService::class)->addCategory('Renombrable');
		$categoryId = null;
		foreach ($added as $word) {
			if ($word['slug'] === 'renombrable') {
				$categoryId = $word['id'];
				$this->createdCategories[] = $word['id'];
			}
		}
		self::assertNotNull($categoryId, 'the word was created');

		$this->medication('RT-C1', ['producto' => 'Con categoría renombrada', 'categoryId' => $categoryId]);
		Server::get(VocabularyService::class)->renameCategory('renombrable', 'Nuevo nombre');

		$review = $this->stage($this->exports->export()['body'], 'export.csv');

		self::assertSame('unresolved-vocabulary', $review['rows'][0]['status']);
		self::assertStringContainsString(
			'«Nuevo nombre»',
			(string)$review['rows'][0]['notes'],
			'the export writes the label, the import folds it, and the kept slug no longer answers — the review settles it',
		);
	}

	public function testARetiredMedicationIsNotInTheExport(): void {
		$this->medication('RT-L1', ['producto' => 'Vivo']);
		$retired = $this->medication('RT-R1', ['producto' => 'Retirado']);
		Server::get(MedicationService::class)->retire($retired);

		$review = $this->stage($this->exports->export()['body'], 'export.csv');

		self::assertSame(
			['RT-L1'],
			array_map(static fn (array $row): string => $row['codigo'], $review['rows']),
			'the retired record left the arsenal, and the export is the arsenal',
		);
		self::assertSame(1, $review['batch']['unchanged']);
	}

	public function testAnEmptyArsenalExportsAHeadingsOnlyPlanillaTheImportRefuses(): void {
		$file = $this->exports->export();

		self::assertSame(
			[],
			(new CsvReader())->read($file['body']),
			'the export is the heading: the columns are the contract, with or without rows',
		);

		// Nothing to review, so nothing to stage — the S7 refusal, met from
		// the other direction. Also the leak canary: any row here means an
		// earlier test's cleanup failed.
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessageMatches('/no tiene filas/');
		$this->stage($file['body']);
	}

	/** A channel through the real vocabulary, registered for cleanup. */
	private function channel(string $label): int {
		foreach (Server::get(VocabularyService::class)->addChannel($label) as $word) {
			if ($word['label'] === $label) {
				$this->createdChannels[] = $word['id'];
				return $word['id'];
			}
		}

		self::fail("the channel \"$label\" was not created");
	}
}
