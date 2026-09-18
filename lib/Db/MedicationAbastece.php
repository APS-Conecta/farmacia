<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One supply channel a medication is stocked through. The channel is an id
 * into the instance's ABASTECE vocabulary — never a label here, so a channel
 * rename touches one table, not every medication that names it.
 *
 * @method int getMedicationId()
 * @method void setMedicationId(int $medicationId)
 * @method int getChannelId()
 * @method void setChannelId(int $channelId)
 */
class MedicationAbastece extends Entity {
	protected $medicationId = 0;
	protected $channelId = 0;

	public function __construct() {
		$this->addType('medicationId', 'integer');
		$this->addType('channelId', 'integer');
	}
}