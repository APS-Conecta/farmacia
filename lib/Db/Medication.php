<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One medication of the arsenal — see CONTEXT.md.
 *
 * The three child relations (GES numbers, restrictions, abastece channels) are
 * NOT on this entity: territorio's doctrine, inherited wholesale, is that a
 * one-to-many read is assembled in the service (bulk IN read, grouped in PHP),
 * so toClient() stays flat and ships ids, and the child rows ride along beside
 * the payload, not inside the entity.
 *
 * @method string getCodigo()
 * @method void setCodigo(string $codigo)
 * @method string getProducto()
 * @method void setProducto(string $producto)
 * @method ?string getFarmaco()
 * @method void setFarmaco(?string $farmaco)
 * @method ?string getMg()
 * @method void setMg(?string $mg)
 * @method ?string getPresentacion()
 * @method void setPresentacion(?string $presentacion)
 * @method ?string getPrograma()
 * @method void setPrograma(?string $programa)
 * @method bool getTrazador()
 * @method void setTrazador(bool $trazador)
 * @method ?string getIndicacion()
 * @method void setIndicacion(?string $indicacion)
 * @method ?string getDosificacion()
 * @method void setDosificacion(?string $dosificacion)
 * @method ?string getPrecauciones()
 * @method void setPrecauciones(?string $precauciones)
 * @method ?string getObservaciones()
 * @method void setObservaciones(?string $observaciones)
 * @method bool getRiesgoColinergicoAm()
 * @method void setRiesgoColinergicoAm(bool $riesgoColinergicoAm)
 * @method ?string getFdaEmbarazo()
 * @method void setFdaEmbarazo(?string $fdaEmbarazo)
 * @method ?string getAjusteRenal()
 * @method void setAjusteRenal(?string $ajusteRenal)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method bool getRetired()
 * @method void setRetired(bool $retired)
 * @method ?\DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Medication extends Entity {
	protected $codigo = '';
	protected $producto = '';
	protected $farmaco = null;
	protected $mg = null;
	protected $presentacion = null;
	protected $programa = null;
	protected $trazador = false;
	protected $indicacion = null;
	protected $dosificacion = null;
	protected $precauciones = null;
	protected $observaciones = null;
	protected $riesgoColinergicoAm = false;
	protected $fdaEmbarazo = null;
	protected $ajusteRenal = null;
	protected $categoryId = 0;
	protected $retired = false;
	// Null until the mapper's first stamp — every write path sets it, so no
	// stored row is ever without one. Not initialised to a DateTime: a default
	// here would be a second clock (lesson #74's shape, one door earlier).
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('categoryId', 'integer');
		$this->addType('trazador', 'boolean');
		$this->addType('riesgoColinergicoAm', 'boolean');
		$this->addType('retired', 'boolean');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * What the browser gets. Flat, and the Category is an id, not expanded: the
	 * client already holds both vocabularies from the initial state, so
	 * expanding the label here would repeat it on every row for nothing. The
	 * child lists (ges, restricciones, abastece) are attached by the service
	 * beside this array — the entity cannot reach them (territorio's doctrine).
	 *
	 * @return array<string, mixed>
	 */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'codigo' => $this->getCodigo(),
			'producto' => $this->getProducto(),
			'farmaco' => $this->getFarmaco(),
			'mg' => $this->getMg(),
			'presentacion' => $this->getPresentacion(),
			'programa' => $this->getPrograma(),
			'trazador' => (bool)$this->getTrazador(),
			'indicacion' => $this->getIndicacion(),
			'dosificacion' => $this->getDosificacion(),
			'precauciones' => $this->getPrecauciones(),
			'observaciones' => $this->getObservaciones(),
			'riesgoColinergicoAm' => (bool)$this->getRiesgoColinergicoAm(),
			'fdaEmbarazo' => $this->getFdaEmbarazo(),
			'ajusteRenal' => $this->getAjusteRenal(),
			'categoryId' => $this->getCategoryId(),
			'retired' => (bool)$this->getRetired(),
			'updatedAt' => $this->getUpdatedAt()?->format('Y-m-d H:i:s'),
		];
	}

	public function comparable(): array {
		return [
			'codigo' => $this->getCodigo(),
			'producto' => $this->getProducto(),
			'farmaco' => $this->getFarmaco(),
			'mg' => $this->getMg(),
			'presentacion' => $this->getPresentacion(),
			'programa' => $this->getPrograma(),
			'trazador' => $this->getTrazador(),
			'indicacion' => $this->getIndicacion(),
			'dosificacion' => $this->getDosificacion(),
			'precauciones' => $this->getPrecauciones(),
			'observaciones' => $this->getObservaciones(),
			'riesgoColinergicoAm' => $this->getRiesgoColinergicoAm(),
			'fdaEmbarazo' => $this->getFdaEmbarazo(),
			'ajusteRenal' => $this->getAjusteRenal(),
			'categoryId' => $this->getCategoryId(),
		];
	}
}