<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ImportRow> */
class ImportRowMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_import_row', ImportRow::class);
	}

	/**
	 * The batch's rows in planilla order — the review reads them top to
	 * bottom, which is the order the person's spreadsheet shows them in.
	 *
	 * @return ImportRow[]
	 */
	public function findAllForBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('line_number', 'ASC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): ImportRow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}
}
