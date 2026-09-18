<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

use OCA\Farmacia\Service\CsvReader;
use OCA\Farmacia\Service\CsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * The export half of the round-trip pair. The reader is S7's and locked, so
 * the writer is tested THROUGH it: most tests write a planilla and read it
 * back with the app's own reader, which pins the pair the design commits to
 * (a writer test with its own fixture parser would pass against a file the
 * import cannot read).
 *
 * The one thing read-back CANNOT prove gets a bytes-level test: the reader
 * strips the formula marker back off, so a file that was never defused
 * reads back exactly the same — and it is the FILE the spreadsheet
 * evaluates, not the reader's opinion of it.
 */
final class CsvWriterTest extends TestCase {
	private CsvWriter $writer;

	/** A full payload row, as ExportService hands the writer one. */
	private const ROW = [
		'id' => 1,
		'codigo' => 'F-001',
		'producto' => 'Paracetamol 500 mg comprimidos',
		'farmaco' => 'paracetamol',
		'mg' => '500',
		'presentacion' => 'Comprimido',
		'programa' => null,
		'trazador' => true,
		'indicacion' => 'Dolor y fiebre',
		'dosificacion' => '1 comprimido cada 6 a 8 horas',
		'precauciones' => 'máximo 4 comprimidos al día',
		'observaciones' => null,
		'riesgoColinergicoAm' => false,
		'fdaEmbarazo' => 'B',
		'ajusteRenal' => null,
		'categoryId' => 3,
		'retired' => false,
		'ges' => [1, 17],
		'restricciones' => ['Solo receta retenida'],
		'abastece' => [2, 5],
		'categoriaLabel' => 'Analgésicos / antipiréticos / antiinflamatorios',
		'abasteceLabels' => ['URGENCIAS', 'CECOSF'],
	];

	/** The canonical heading, in the reader's own documented order. */
	private const HEADING = 'Código;Producto;Fármaco (DCI);Mg;Presentación;Categoría;Trazador;GES;Restricciones;Abastece;Programa;Indicación;Dosificación;Precauciones;Observaciones;Riesgo colinérgico AM;FDA embarazo;Ajuste renal';

	protected function setUp(): void {
		$this->writer = new CsvWriter();
	}

	public function testTheHeadingIsTheCanonicalSetOnItsOwnLine(): void {
		$csv = $this->writer->write([]);

		self::assertStringStartsWith("\u{FEFF}", $csv, 'the BOM is what makes Excel guess UTF-8');
		self::assertSame(
			self::HEADING,
			strstr(substr($csv, strlen("\u{FEFF}")), "\n", true),
			'an empty arsenal is an honest headings-only planilla, not an error',
		);
	}

	public function testAReadRowRoundTripsThroughTheReader(): void {
		$cells = (new CsvReader())->read($this->writer->write([self::ROW]))[0]['cells'];

		self::assertSame('F-001', $cells['codigo']);
		self::assertSame('Paracetamol 500 mg comprimidos', $cells['producto']);
		self::assertSame('Analgésicos / antipiréticos / antiinflamatorios', $cells['categoria']);
		self::assertSame('Sí', $cells['trazador'], 'the sí/no cells carry the planilla\'s own words');
		self::assertSame('No', $cells['riesgoColinergicoAm']);
		self::assertSame(['1', '17'], $cells['ges'], 'the multi-value cells are joined with the planilla\'s separator and split back');
		self::assertSame(['Solo receta retenida'], $cells['restricciones']);
		self::assertSame(['URGENCIAS', 'CECOSF'], $cells['abastece']);
		self::assertSame('B', $cells['fdaEmbarazo']);
		self::assertSame('', $cells['programa'], 'a null field is an empty cell in a carried column, never a missing one');
	}

	public function testEveryFormulaTriggerCarriesTheTextMarkerInTheFile(): void {
		// The round-trip cannot prove this one: unquote strips the marker
		// back off, so an undefused file reads back exactly the same. The
		// marker must be asserted in the bytes, per trigger — the set
		// CsvGrammar::unquote reverses, no more.
		$triggers = ['=SUM(A1:A2)', '+56 9 1234 5678', '-1 día', '@deporte', "\tcon tab", "\rcon retorno"];

		foreach ($triggers as $trigger) {
			$record = $this->recordOf(self::ROW, 'producto', $trigger);

			self::assertStringContainsString(
				"'" . $trigger,
				$record,
				'the marker must be in the bytes: the spreadsheet runs the file, not the reader\'s tolerance',
			);
		}
	}

	public function testAnApostropheInitialCellIsWrittenExactlyAsStored(): void {
		$record = $this->recordOf(self::ROW, 'producto', "'Colmena");
		$cells = (new CsvReader())->read($this->writer->write([array_merge(self::ROW, ['producto' => "'Colmena"])]))[0]['cells'];

		self::assertStringNotContainsString("''Colmena", $record, 'the writer never touches an apostrophe-initial cell: its first character is not a formula trigger');
		self::assertSame("'Colmena", $cells['producto'], 'and it reads back exactly as stored');
	}

	public function testASemicolonInsideAValueStaysOneCell(): void {
		$row = array_merge(self::ROW, ['dosificacion' => '1 comprimido cada 8 horas; según indicación']);

		self::assertSame(
			'1 comprimido cada 8 horas; según indicación',
			(new CsvReader())->read($this->writer->write([$row]))[0]['cells']['dosificacion'],
			'unquoted, the delimiter would shift every column after it — fputcsv quotes it, fgetcsv reads it back',
		);
	}

	public function testALineBreakInsideAValueStaysOneCell(): void {
		$row = array_merge(self::ROW, ['dosificacion' => "1 cada 8 horas.\nCon alimentos."]);

		self::assertSame(
			"1 cada 8 horas.\nCon alimentos.",
			(new CsvReader())->read($this->writer->write([$row]))[0]['cells']['dosificacion'],
		);
	}

	/**
	 * The data record of a one-row planilla with one cell overridden — the
	 * file's bytes, BOM off, line by line (no quoted newlines in these
	 * rows, so a plain split is the honest parse for the assertion).
	 *
	 * @param array<string, mixed> $row
	 */
	private function recordOf(array $row, string $key, string $value): string {
		$row[$key] = $value;
		$csv = $this->writer->write([$row]);

		return explode("\n", substr($csv, strlen("\u{FEFF}")))[1];
	}
}
