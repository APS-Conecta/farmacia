<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One ABASTECE supply channel: where a medication comes from (URGENCIAS,
 * CECOSF, the instance's own naming — CONTEXT.md). The twin of Category in
 * every way but one: it starts EMPTY, because which channels exist is
 * instance configuration (ADR-0013), never seed data.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 */
class Channel extends Entity {
	protected $slug = '';
	protected $label = '';
}