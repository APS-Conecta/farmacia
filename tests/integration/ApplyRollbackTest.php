<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\ImportBatch;
use OCA\Farmacia\Db\ImportBatchMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * L2-11: apply()'s atomicity was a container observation, not a test. This
 * pins it the only way an invariant gets pinned — by breaking it on purpose
 * and watching the app hold.
 *
 * The provable Nth-row failure uses no mocks and no planted exceptions: a
 * batch is staged whose third row decides into a category that is REMOVED
 * before the apply runs. The apply reaches that row, MedicationService
 * refuses («La categoría N no existe.»), ApplyService::write re-says it naming
 * the line, and the outer transaction must roll the whole walk back to
 * staged — rows one and two included, the batch still STATE_STAGED, and the
 * arsenal untouched.
 */
class ApplyRollbackTest extends IntegrationTestCase {
	public function testARowFailingMidApplyLandsNothingAndKeepsTheBatchStaged(): void {
		// [1] Stage a three-row planilla: two clean rows plus one whose
		//     categoría cell names a label no category carries yet — the
		//     decision the review exists to carry.
		$doc = $this->stage(
			"Código;Producto;Categoría\n"
			. "FILAS-1;Producto uno;Otros\n"
			. "FILAS-2;Producto dos;Otros\n"
			. "FILAS-3;Producto tres;Prueba de reversión\n",
			'reversion.csv',
		);
		$batchId = (int)$doc['batch']['id'];

		// [2] Create the category the planilla named, and decide the third row
		//     into it — the vocabulary decision, carried by the review.
		$vocabulary = Server::get(VocabularyService::class);
		$slug = 'prueba_de_reversion';
		$added = $vocabulary->addCategory('Prueba de reversión');
		foreach ($added as $word) {
			if ($word['slug'] === $slug) {
				$this->createdCategories[] = $word['id'];
			}
		}
		$categoryId = $this->categoryIdBySlug($slug);
		$decided = null;
		foreach ($doc['rows'] as $row) {
			if ($row['status'] === 'unresolved-vocabulary') {
				$decided = Server::get(\OCA\Farmacia\Service\ImportService::class)
					->decide((int)$row['id'], ['categoryId' => $categoryId]);
			}
		}
		self::assertNotNull($decided, 'the third row should have owed its category decision');
		self::assertSame('create', $decided['disposition']);

		// [4] Remove the category — legitimately: nothing references it yet,
		//     so the in-use guard waves it through. This is the "world moved
		//     under the review" the apply's own refusals exist for.
		$vocabulary->removeCategory($slug);

		// [5] Apply. The refusal names the third row's line number, and the
		//     whole walk rolls back.
		// expectException ENDS a PHPUnit test at the throwing call — everything
		// after it would never run. The try/catch + fail() shape keeps the
		// post-conditions alive: the refusal is asserted AND the rolled-back
		// world is verified in the same test.
		try {
			Server::get(ApplyService::class)->apply($batchId);
			self::fail('the apply should have refused the row whose category was removed');
		} catch (InvalidImportException $e) {
			self::assertStringContainsString('no se pudo aplicar', $e->getMessage());
			self::assertStringContainsString('La categoría ' . $categoryId . ' no existe', $e->getMessage());
		}

		$mapper = Server::get(MedicationMapper::class);
		foreach (['FILAS-1', 'FILAS-2'] as $codigo) {
			try {
				$mapper->findByCodigo($codigo);
				self::fail("row $codigo landed despite the whole batch rolling back");
			} catch (DoesNotExistException) {
				// The one answer a rolled-back row can give.
			}
		}
		self::assertSame(
			ImportBatch::STATE_STAGED,
			Server::get(ImportBatchMapper::class)->find($batchId)->getState(),
			'apply failure must leave the batch reviewable, not half-landed',
		);
	}
}
