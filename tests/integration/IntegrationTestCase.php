<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Service\ImportService;
use OCA\Farmacia\Service\MedicationService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests that run against the throwaway instance's real
 * PostgreSQL (make deps && make instance-up, then make integration). The unit
 * suite mocks the mappers; nothing but a real insert proves that camelCase
 * Entity properties land in the snake_case columns the migration created.
 *
 * The cleanup here is the one place in the repo that hard-deletes a
 * medication, and with raw SQL rather than through MedicationMapper::delete(),
 * which refuses.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var int[] medication rows this test made, to be removed afterwards */
	protected array $created = [];

	/** @var int[] channel rows this test made, to be removed afterwards */
	protected array $createdChannels = [];

	/** @var int[] category rows this test made, to be removed afterwards */
	protected array $createdCategories = [];

	/** @var int[] import batches this test staged, to be removed afterwards */
	protected array $createdBatches = [];

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);

		// Import batches this test staged, if any: the rows first (they carry
		// the batch id), then the batch. Staged rows reference no medication
		// — the payload holds the código as text — so this block needs no
		// coordination with the medication cleanup below.
		if ($this->createdBatches !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('farmacia_import_row')
				->where($qb->expr()->in('batch_id', $qb->createNamedParameter(
					$this->createdBatches, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();

			$qb = $db->getQueryBuilder();
			$qb->delete('farmacia_import')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->createdBatches, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();

			$this->createdBatches = [];
		}

		if ($this->createdChannels !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('farmacia_medication_abastece')
				->where($qb->expr()->in('channel_id', $qb->createNamedParameter($this->createdChannels, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();

			$qb = $db->getQueryBuilder();
			$qb->delete('farmacia_abastece')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdChannels, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();

			$this->createdChannels = [];
		}

		// Categories this test made, if any — same poison argument as the
		// channels above, but sharper: SeedingTest asserts EXACTLY sixteen,
		// and a leaked test category would break it on every later run.
		if ($this->createdCategories !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('farmacia_category')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->createdCategories, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();

			$this->createdCategories = [];
		}

		if ($this->created !== []) {
			foreach ([
				'farmacia_medication_ges' => 'medication_id',
				'farmacia_medication_restriction' => 'medication_id',
				'farmacia_medication_abastece' => 'medication_id',
				'farmacia_medication' => 'id',
			] as $table => $column) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($this->created, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}

			$this->created = [];
		}
	}

	final protected function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	protected function categoryIdBySlug(string $slug): int {
		foreach (Server::get(CategoryMapper::class)->findAll() as $category) {
			if ($category->getSlug() === $slug) {
				return (int)$category->getId();
			}
		}

		self::fail("the seed should have created the \"$slug\" category");
	}

	protected function medication(string $codigo, array $extra = []): int {
		$id = (int)Server::get(MedicationService::class)->create(array_merge([
			'codigo' => $codigo,
			'producto' => 'Producto de prueba',
			'categoryId' => $this->categoryIdBySlug('otros'),
		], $extra))['id'];
		$this->created[] = $id;

		return $id;
	}

	/**
	 * A planilla staged through the real service, registered for cleanup —
	 * every batch a test stages must disappear with it, or the batches list
	 * the review reads grows rows no test owns.
	 *
	 * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>}
	 */
	protected function stage(string $contents, string $filename = 'planilla.csv'): array {
		$review = Server::get(ImportService::class)->stage($contents, $filename, 'test');
		$this->createdBatches[] = (int)$review['batch']['id'];

		return $review;
	}
}