<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/** @template-extends QBMapper<ImportBatch> */
class ImportBatchMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_import', ImportBatch::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): ImportBatch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The recent runs, newest first, a page of them: the list is a person's
	 * way back to a batch they staged before lunch, not an audit log.
	 *
	 * @return ImportBatch[]
	 */
	public function findRecent(int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC')->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Whether some batch was recorded undoing this one — territorio's
	 * question (its ImportBatchMapper::isUndone), asked for the same two
	 * readers: the revert refuses a batch it already reverted, and the
	 * batches list stops offering the button.
	 */
	public function isUndone(int $batchId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('reverts_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * One transaction for the batch and its rows. Staging writes the batch
	 * first so the rows can carry its id, and neither half is a run on its
	 * own: a batch with no rows is a review of nothing, and rows with no
	 * batch are staging nobody can reopen. MedicationMapper::transactionally's
	 * shape, restated for two tables that are both this import's own —
	 * territorio needed per-row commits only to coexist with its shared
	 * cursor, which farmacia does not have, so the whole stage is atomic.
	 *
	 * @template T
	 * @param callable(): T $write
	 * @return T
	 */
	public function transactionally(callable $write): mixed {
		$this->db->beginTransaction();
		try {
			$written = $write();
			$this->db->commit();

			return $written;
		} catch (Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}
}
