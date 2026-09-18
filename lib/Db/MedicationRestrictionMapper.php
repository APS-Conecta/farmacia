<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MedicationRestriction> */
class MedicationRestrictionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_medication_restriction', MedicationRestriction::class);
	}

	/** @return list<string> the restrictions, in insertion order */
	public function findAllFor(int $medicationId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('restriction')
			->from($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$restrictions = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$restrictions[] = (string)$row['restriction'];
		}
		$result->closeCursor();

		return $restrictions;
	}

	/**
	 * @param int[] $medicationIds
	 * @return array<int, list<string>>
	 */
	public function forIds(array $medicationIds): array {
		if ($medicationIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('medication_id', 'restriction')
			->from($this->getTableName())
			->where($qb->expr()->in('medication_id', $qb->createNamedParameter($medicationIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$byMedication = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$byMedication[(int)$row['medication_id']][] = (string)$row['restriction'];
		}
		$result->closeCursor();

		return $byMedication;
	}

	/** @param list<string> $restrictions */
	public function replaceFor(int $medicationId, array $restrictions): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		foreach ($restrictions as $restriction) {
			$row = new MedicationRestriction();
			$row->setMedicationId($medicationId);
			$row->setRestriction($restriction);
			$this->insert($row);
		}
	}
}