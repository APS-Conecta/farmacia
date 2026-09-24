<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use APS\Common\Csv;

/**
 * The arsenal as a planilla — the export half of the round-trip pair.
 *
 * Territorio's writer states its CSV is lossy and one-directional; this one
 * is neither, and that is the whole difference: farmacia has no second export
 * format, so the CSV IS the machine-readable copy too, and it is written to
 * be read back by the app's own reader (CsvReader) — the headings are the
 * canonical set the reader's allow-list documents, the multi-value cells are
 * joined with the planilla's separator (CsvGrammar, the one grammar both
 * halves share, stated once in S7), and the formula defusing is the exact
 * transform CsvGrammar::unquote reverses.
 *
 * The dialect is the one a Chilean spreadsheet speaks: `;` between the cells,
 * because the comma is the decimal separator in es-CL and a comma-delimited
 * file opens in Excel as one long column — and the reader, which sniffs the
 * delimiter off the heading line, answers `;` for this file by construction.
 * A BOM in front of the UTF-8 so Excel guesses the encoding right; the reader
 * folds it off with the heading's punctuation (CsvReaderTest pins that path).
 *
 * Columns are in Spanish because the file is opened by the people who use
 * the app, in the spreadsheet on their own machine, with no key to look
 * anything up in.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class CsvWriter {
	/** @var array<string, string> column heading → the row key, in the reader's canonical order */
	private const COLUMNS = [
		'Código' => 'codigo',
		'Producto' => 'producto',
		'Fármaco (DCI)' => 'farmaco',
		'Mg' => 'mg',
		'Presentación' => 'presentacion',
		'Categoría' => 'categoriaLabel',
		'Trazador' => 'trazador',
		'GES' => 'ges',
		'Restricciones' => 'restricciones',
		'Abastece' => 'abasteceLabels',
		'Programa' => 'programa',
		'Indicación' => 'indicacion',
		'Dosificación' => 'dosificacion',
		'Precauciones' => 'precauciones',
		'Observaciones' => 'observaciones',
		'Riesgo colinérgico AM' => 'riesgoColinergicoAm',
		'FDA embarazo' => 'fdaEmbarazo',
		'Ajuste renal' => 'ajusteRenal',
	];

	/** The sí/no columns, whose cells carry the planilla's own words. */
	private const YES_NO = ['trazador', 'riesgoColinergicoAm'];

	/**
	 * The rows as one planilla.
	 *
	 * The quoting is written here rather than delegated to fputcsv: PHP
	 * before 8.4 encloses every field containing a space (its historical,
	 * non-RFC dialect), so the same code emits different bytes on 8.2 and
	 * 8.5 — and the unit container (8.2) and the integration container
	 * (8.5) both read this file. RFC 4180's own rule, stated once: a cell
	 * is quoted when it carries the delimiter, the enclosure, or a line
	 * break, and never otherwise — the reader answers it by construction.
	 *
	 * @param list<array<string, mixed>> $rows payload rows (toClient plus the
	 *        children) with categoriaLabel and abasteceLabels attached by
	 *        ExportService — the writer speaks cells, not vocabulary lookups
	 */
	public function write(array $rows): string {
		$lines = [self::line(array_keys(self::COLUMNS))];

		foreach ($rows as $row) {
			$lines[] = self::line($this->cells($row));
		}

		// A BOM, so that Excel opens «Analgésicos» rather than «AnalgÃ©sicos».
		// LibreOffice asks; Excel does not, it just guesses Latin-1.
		return Csv::BOM . implode("\n", $lines) . "\n";
	}

	/**
	 * One record as one planilla line — RFC 4180's own quoting and nothing
	 * more: the cell is enclosed when it carries the delimiter, the
	 * enclosure itself (doubled inside), or a line break.
	 *
	 * @param list<string> $cells
	 */
	private static function line(array $cells): string {
		return implode(';', array_map(
			static fn (string $cell): string => strpbrk($cell, ";\"\n\r") !== false
				? '"' . str_replace('"', '""', $cell) . '"'
				: $cell,
			$cells,
		));
	}

	/**
	 * One record as the planilla's cells — the reader's row() in reverse:
	 * the sí/no words written back, the multi-value cells joined, and
	 * anything a spreadsheet would run defused. The column order is
	 * COLUMNS' own, in one place, so the heading and the cells cannot
	 * drift apart.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function cells(array $row): array {
		$cells = [];
		foreach (self::COLUMNS as $key) {
			$value = $row[$key] ?? null;
			$cells[] = match (true) {
				in_array($key, self::YES_NO, true) => self::text($value ? 'Sí' : 'No'),
				is_array($value) => self::text(CsvGrammar::join(array_map(strval(...), $value))),
				default => self::text($value),
			};
		}

		return $cells;
	}

	/**
	 * One cell, defused — territorio's own rule, now stated once in
	 * aps-common's Csv::text (the formula-threat reasoning, the apostrophe
	 * trade, and the CsvGrammar::unquote reversal all live there).
	 */
	private static function text(mixed $value): string {
		return Csv::text($value);
	}
}
