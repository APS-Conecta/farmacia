<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One GES problem number a medication covers. An id of its own because the
 * child set is replaceable as a whole (the service deletes and reinserts), not
 * addressable one row at a time.
 *
 * @method int getMedicationId()
 * @method void setMedicationId(int $medicationId)
 * @method int getNumber()
 * @method void setNumber(int $number)
 */
class MedicationGes extends Entity {
	protected $medicationId = 0;
	protected $number = 0;

	public function __construct() {
		$this->addType('medicationId', 'integer');
		$this->addType('number', 'integer');
	}
}