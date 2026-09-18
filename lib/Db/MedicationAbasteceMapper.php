<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MedicationAbastece> */
class MedicationAbasteceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_medication_abastece', MedicationAbastece::class);
	}

	/** @return list<int> the channel ids, in insertion order */
	public function findAllFor(int $medicationId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('channel_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$channels = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$channels[] = (int)$row['channel_id'];
		}
		$result->closeCursor();

		return $channels;
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
		$qb->select('medication_id', 'channel_id')
			->from($this->getTableName())
			->where($qb->expr()->in('medication_id', $qb->createNamedParameter($medicationIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$byMedication = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$byMedication[(int)$row['medication_id']][] = (int)$row['channel_id'];
		}
		$result->closeCursor();

		return $byMedication;
	}

	/**
	 * How many channel assignments — including those on retired medications,
	 * which keep their rows — point at one channel: the vocabulary's in-use
	 * count (VocabularyService). Counting the assignments rather than the
	 * medications is the honest unit: every row is one reference the schema
	 * will not catch (no FK), so a channel is removable only when no row
	 * anywhere points back at it.
	 */
	public function countByChannel(int $channelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** @param list<int> $channelIds */
	public function replaceFor(int $medicationId, array $channelIds): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('medication_id', $qb->createNamedParameter($medicationId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		foreach ($channelIds as $channelId) {
			$row = new MedicationAbastece();
			$row->setMedicationId($medicationId);
			$row->setChannelId($channelId);
			$this->insert($row);
		}
	}
}