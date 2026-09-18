<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Channel> */
class ChannelMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_abastece', Channel::class);
	}

	/** @return Channel[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('label', 'ASC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findBySlug(string $slug): Channel {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->findEntity($qb);
	}

	/**
	 * By id — what a submitted abastece channel id references. Same honest-reference
	 * duty as CategoryMapper::find (no FK in the schema, the service checks writes
	 * through this read), in territorio's SubcategoryMapper::find shape.
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Channel {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}
}