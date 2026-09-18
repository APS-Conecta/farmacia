<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One free-text restriction on a medication's use. Distinct rows are distinct
 * facts, which is why the schema carries no unique pair here.
 *
 * @method int getMedicationId()
 * @method void setMedicationId(int $medicationId)
 * @method string getRestriction()
 * @method void setRestriction(string $restriction)
 */
class MedicationRestriction extends Entity {
	protected $medicationId = 0;
	protected $restriction = '';

	public function __construct() {
		$this->addType('medicationId', 'integer');
	}
}