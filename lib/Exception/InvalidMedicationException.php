<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Exception;

use APS\Common\Refusal;
use RuntimeException;

/** Thrown when what a person submitted is not a Medication this app will store. */
class InvalidMedicationException extends RuntimeException implements Refusal {
}