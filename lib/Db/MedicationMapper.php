<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use BadFunctionCallException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/** @template-extends QBMapper<Medication> */
class MedicationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'farmacia_medication', Medication::class);
	}

	/**
	 * No hard DELETE anywhere in the app — with the child tables this is more
	 * load-bearing than territorio ever needed it to be: there is no foreign
	 * key that would catch three orphaned child sets, so the refusal here is
	 * the referential guarantee the schema deliberately does not carry.
	 */
	public function delete(Entity $entity): Entity {
		throw new BadFunctionCallException(
			'Medications are never hard-deleted; retire them instead',
		);
	}

	public function insert(Entity $entity): Entity {
		$entity->setUpdatedAt(new \DateTime());

		return parent::insert($entity);
	}

	public function update(Entity $entity): Entity {
		$entity->setUpdatedAt(new \DateTime());

		return parent::update($entity);
	}

	public function retire(Medication $medication): Medication {
		$medication->setRetired(true);
		$this->update($medication);

		return $medication;
	}

	public function restore(Medication $medication): Medication {
		$medication->setRetired(false);
		$this->update($medication);

		return $medication;
	}

	/**
	 * Everything the arsenal still shows.
	 *
	 * ponytail: unpaginated, exactly as territorio's findAll. One CESFAM's
	 * vademécum is hundreds of rows; paginate when a real install makes this
	 * slow, not before.
	 *
	 * @return Medication[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/** @return Medication[] */
	public function findRetired(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('updated_at', 'DESC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Medication {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The medication a planilla row matches on: the código, which the
	 * schema keeps globally unique (farm_med_codigo_idx). The apply
	 * resolves its update rows through this read, and the revert its
	 * created ones — sound for the revert because the touched-since guard
	 * covers exactly the drift: a código someone edited after the apply
	 * moved updated_at with it, so a row the guard would still revert is a
	 * row whose código still resolves.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByCodigo(string $codigo): Medication {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('codigo', $qb->createNamedParameter($codigo)));

		return $this->findEntity($qb);
	}

	/**
	 * How many medications — live AND retired — are classified under one
	 * category: the vocabulary's in-use count (VocabularyService). The
	 * retired are included on ADR-0016's argument, which the schema makes
	 * stronger here than it was in territorio: no foreign key exists to
	 * catch the dangling reference a restore would resurrect, so the count
	 * answers "what would be orphaned" and never "what is somebody
	 * looking at".
	 */
	public function countByCategory(int $categoryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * One transaction for the service's parent+children writes.
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