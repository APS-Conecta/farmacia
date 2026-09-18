<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Category> */
class CategoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_category', Category::class);
	}

	/** @return Category[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('label', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * By slug, because the slug is what survives a rename (territorio's
	 * CategoryMapper::findBySlug rationale, verbatim in intent): the label
	 * moves and the id differs per install, so a client that wants to address
	 * one Category has exactly one stable name for it.
	 *
	 * @throws DoesNotExistException
	 */
	public function findBySlug(string $slug): Category {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->findEntity($qb);
	}

	/**
	 * By id — what a submitted categoryId references. There is no foreign key
	 * to make the reference honest (the schema is deliberately FK-free), so
	 * MedicationService checks every write through this read:
	 * territorio's SubcategoryMapper::find, the third and last address a
	 * Category needs (id for writes, slug for stable addressing, label for
	 * display).
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Category {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}
}