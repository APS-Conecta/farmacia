<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Exception;

use APS\Common\Refusal;
use RuntimeException;

/** Thrown when what a person submitted is not a vocabulary word this app will store. */
class InvalidVocabularyException extends RuntimeException implements Refusal {
}
