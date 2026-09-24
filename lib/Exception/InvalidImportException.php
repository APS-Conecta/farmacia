<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Exception;

use APS\Common\Refusal;
use RuntimeException;

/** Thrown when an import is refused with a sentence a person reads, rather than a stack trace. */
class InvalidImportException extends RuntimeException implements Refusal {
}
