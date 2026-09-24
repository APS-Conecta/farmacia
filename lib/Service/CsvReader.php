<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use APS\Common\Csv;
use OCA\Farmacia\Exception\InvalidImportException;

/**
 * Reads the planilla: the CSV a CESFAM's spreadsheet exports.
 *
 * The headings are the Spanish ones the people using this app already see —
 * and the ones the app's own export emits, so re-importing it is a file this
 * reader understands by construction (the round-trip pair's import half).
 * A heading matches on its folded slug (VocabularyService::slugFor), so
 * «Código», «codigo» and «CODIGO » are one column, the byte-order mark a
 * spreadsheet writes in front of UTF-8 folds out with the punctuation, and a
 * hand-typed planilla missing its accents still finds its columns.
 *
 * The reader's rows are CELLS, not judgements: values arrive trimmed and
 * with the multi-value cells split (CsvGrammar), the sí/no and FDA cells
 * stay as typed, and which Categories and Channels exist is the import's
 * business, not the reader's — territorio's rule, kept for the reason it
 * states: the reader would otherwise decide vocabulary it has no say over.
 *
 * A column the file does not carry is a KEY THE ROW DOES NOT HAVE: the
 * import leaves the stored field alone rather than clearing it, exactly as
 * a save from the sidebar does (MedicationService's absent-key rule), so a
 * planilla that refreshes three columns of an existing arsenal does not
 * wipe the other fifteen. The export always carries every column, which is
 * what keeps the round-trip whole.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class CsvReader {
	/**
	 * Folded heading slug → the payload key the rest of the import reads.
	 *
	 * An allow-list rather than "pass everything through": a spreadsheet
	 * always carries columns nobody asked for (a code, a total, a note to
	 * whoever filled it in), and refusing the file for them would make this
	 * unusable on a real planilla — but a heading that slugged onto a key
	 * the apply writes would let a stray column overwrite data, so unknowns
	 * are ignored and only these are read.
	 *
	 * Some fields accept two spellings: the canonical heading the export
	 * emits and the shorter one a hand-typed planilla is likely to carry
	 * («Fármaco» for «Fármaco (DCI)», «Riesgo colinérgico» for «Riesgo
	 * colinérgico AM»). The export's canonical set, which the round-trip
	 * turns on: Código, Producto, Fármaco (DCI), Mg, Presentación,
	 * Categoría, Trazador, GES, Restricciones, Abastece, Programa,
	 * Indicación, Dosificación, Precauciones, Observaciones, Riesgo
	 * colinérgico AM, FDA embarazo, Ajuste renal.
	 */
	private const COLUMNS = [
		'codigo' => 'codigo',
		'producto' => 'producto',
		'farmaco' => 'farmaco',
		'farmaco_dci' => 'farmaco',
		'mg' => 'mg',
		'presentacion' => 'presentacion',
		'categoria' => 'categoria',
		'categoria_terapeutica' => 'categoria',
		'trazador' => 'trazador',
		'ges' => 'ges',
		'restricciones' => 'restricciones',
		'abastece' => 'abastece',
		'programa' => 'programa',
		'indicacion' => 'indicacion',
		'dosificacion' => 'dosificacion',
		'precauciones' => 'precauciones',
		'observaciones' => 'observaciones',
		'riesgo_colinergico_am' => 'riesgoColinergicoAm',
		'riesgo_colinergico' => 'riesgoColinergicoAm',
		'fda_embarazo' => 'fdaEmbarazo',
		'ajuste_renal' => 'ajusteRenal',
		'ajuste_renal_erc' => 'ajusteRenal',
	];

	/** The cells that hold several values, joined by the planilla's separator. */
	private const MULTI_VALUE = ['ges', 'restricciones', 'abastece'];

	/** The one column the whole import turns on: rows match on the código. */
	private const MATCH_KEY = 'codigo';

	/**
	 * The planilla as rows of cells. The line is the record's row in the
	 * spreadsheet — the heading is line 1, the first data row line 2 — which
	 * is the number a person finds in the gutter, not the file's physical
	 * line (a quoted cell can hold a line break).
	 *
	 * @return list<array{line: int, cells: array<string, mixed>}>
	 * @throws InvalidImportException when the file cannot be read at all
	 */
	public function read(string $csv): array {
		$parsed = self::parsed(self::utf8($csv));
		$columns = self::columns(array_shift($parsed) ?? []);

		if ($columns === []) {
			throw new InvalidImportException('El archivo está vacío.');
		}
		if (!in_array(self::MATCH_KEY, $columns, true)) {
			throw new InvalidImportException(
				'La primera fila debe ser el encabezado y no tiene una columna «Código». '
				. 'Las columnas que se leen son: Código, Producto, Fármaco (DCI), Mg, '
				. 'Presentación, Categoría, Trazador, GES, Restricciones, Abastece, '
				. 'Programa, Indicación, Dosificación, Precauciones, Observaciones, '
				. 'Riesgo colinérgico AM, FDA embarazo y Ajuste renal. Las demás se ignoran.',
			);
		}

		$rows = [];
		foreach ($parsed as $index => $cells) {
			$row = self::row($columns, $cells, $index + 2);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The file as UTF-8 — the shared rule (aps-common's Csv::utf8; the
	 * "copied for the same file" note in the old docblock is now structural:
	 * there is one file). No BOM strip, for the reason Csv::utf8 states.
	 */
	private static function utf8(string $csv): string {
		return Csv::utf8($csv);
	}

	/**
	 * Every record of the file, parsed — the RFC 4180 stream dialect is
	 * aps-common's Csv::rows. Blank records stay in the numbering ([null]
	 * rows) — the record a spreadsheet leaves blank still occupies a row a
	 * person can see — and are dropped as rows by `row()`.
	 *
	 * @return list<list<?string>>
	 */
	private static function parsed(string $csv): array {
		return Csv::rows($csv);
	}

	/**
	 * Column index → the payload key, for the columns this reader knows.
	 *
	 * @param list<?string> $headings
	 * @return array<int, string>
	 */
	private static function columns(array $headings): array {
		$columns = [];
		foreach ($headings as $index => $heading) {
			$known = self::COLUMNS[VocabularyService::slugFor((string)$heading)] ?? null;
			if ($known !== null) {
				$columns[$index] = $known;
			}
		}

		return $columns;
	}

	/**
	 * One record as a row of cells, or null where the record holds nothing
	 * this reader can see — the blank line a spreadsheet leaves at the end,
	 * and any other residue. A row that HAS cells but no código is kept: the
	 * import stages it for review with the sentence saying what it lacks,
	 * because refusing the file for it would cost the person every other
	 * row.
	 *
	 * The values are the CELLS, unjudged: trimmed, the export's formula
	 * apostrophe taken back off (CsvGrammar::unquote), the multi-value cells
	 * split — and nothing else. Sí/no stays «Sí», the FDA letter stays as
	 * typed, and the vocabulary cells keep their labels, which is what the
	 * review shows the person.
	 *
	 * @param array<int, string> $columns
	 * @param list<?string> $cells
	 * @return ?array{line: int, cells: array<string, mixed>}
	 */
	private static function row(array $columns, array $cells, int $line): ?array {
		$values = [];
		$empty = true;
		foreach ($columns as $index => $key) {
			$cell = CsvGrammar::unquote(trim((string)($cells[$index] ?? '')));
			if ($cell !== '') {
				$empty = false;
			}
			$values[$key] = in_array($key, self::MULTI_VALUE, true)
				? CsvGrammar::split($cell)
				: $cell;
		}

		return $empty ? null : ['line' => $line, 'cells' => $values];
	}
}
