<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MedicationGes> */
class MedicationGesMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_medication_ges', MedicationGes::class);
	}

	/** @return list<int> the numbers, in insertion order */
	public function findAllFor(int $medicationId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('number')
			->from($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$numbers = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$numbers[] = (int)$row['number'];
		}
		$result->closeCursor();

		return $numbers;
	}

	/**
	 * @param int[] $medicationIds
	 * @return array<int, list<int>>
	 */
	public function forIds(array $medicationIds): array {
		if ($medicationIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('medication_id', 'number')
			->from($this->getTableName())
			->where($qb->expr()->in('medication_id', $qb->createNamedParameter($medicationIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$byMedication = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$byMedication[(int)$row['medication_id']][] = (int)$row['number'];
		}
		$result->closeCursor();

		return $byMedication;
	}

	/** @param list<int> $numbers */
	public function replaceFor(int $medicationId, array $numbers): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		foreach ($numbers as $number) {
			$row = new MedicationGes();
			$row->setMedicationId($medicationId);
			$row->setNumber($number);
			$this->insert($row);
		}
	}
}