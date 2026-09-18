<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

/**
 * The one flattening grammar the planilla speaks: how a cell holds several
 * values, and how the export's defusing comes back off.
 *
 * This is the shared half of the round-trip the design commits to: the
 * export joins a medication's GES numbers, restrictions and channels into
 * single cells, and the import splits them back out — one separator stated
 * once, consumed by both halves, so the pair cannot drift into a file the
 * other half cannot read. The vocabulary CRUD refuses labels carrying the
 * separator (VocabularyService::cleanLabel), which is what makes the split
 * sound for GES and abastece cells: a value never contains its own
 * separator. Restrictions are free text and can — the import surfaces that
 * case for review rather than splitting silently (ImportService's
 * unclean-split check).
 *
 * Static on purpose: pure text rules with no state and no OCP dependency,
 * so the unit container tests them in milliseconds.
 */
final class CsvGrammar {
	/** The planilla's multi-value separator. */
	public const SEPARATOR = '|';

	/**
	 * One cell's values, trimmed, empties dropped.
	 *
	 * The export never writes an empty piece (a medication's lists never hold
	 * one), so a stray separator is a hand-typed planilla's typo and the drop
	 * is the tolerance — a «GES 1||2» cell still means problems 1 and 2.
	 *
	 * @return list<string>
	 */
	public static function split(?string $cell): array {
		if ($cell === null) {
			return [];
		}

		$values = [];
		foreach (explode(self::SEPARATOR, $cell) as $value) {
			$value = trim($value);
			if ($value !== '') {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * The values as one cell — the export's half of the grammar.
	 *
	 * @param list<string> $values
	 */
	public static function join(array $values): string {
		return implode(self::SEPARATOR, $values);
	}

	/**
	 * The formula apostrophe the export writes, taken back off.
	 *
	 * The export defuses a cell that starts with `=`, `+`, `-`, `@`, a tab or
	 * a carriage return by prefixing the spreadsheet's own text marker — the
	 * rule the writer states for the machine that opens the file. This is its
	 * exact inverse: ONE leading apostrophe goes, and only when what follows
	 * starts with one of those same characters.
	 *
	 * The narrowness is the round-trip's honesty: a cell that genuinely
	 * begins with «'» is never touched by the writer (its first character is
	 * not a formula trigger), so «'Colmena» must come back exactly as
	 * written. The one case the bytes cannot carry is a cell that genuinely
	 * begins with «'=» — indistinguishable from a defused «=» — and the
	 * writer refuses to touch apostrophe-initial cells precisely so it stays
	 * rare.
	 */
	public static function unquote(string $cell): string {
		if ($cell === '' || $cell[0] !== "'") {
			return $cell;
		}

		$rest = substr($cell, 1);
		return $rest !== '' && str_contains("=+-@\t\r", $rest[0]) ? $rest : $cell;
	}
}
