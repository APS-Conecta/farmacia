<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\CsvReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading the planilla. The file this is for is the one a CESFAM's
 * spreadsheet saves: Spanish headings, `;` between the cells (the comma is
 * the decimal separator in es-CL), a byte-order mark in front of the UTF-8,
 * and the multi-value cells joined with the planilla's separator. The
 * committed fixture under tests/fixtures carries the realistic shape; the
 * mechanics are pinned against small inline files, territorio's own
 * CsvReaderTest's way.
 *
 * The rows are CELLS: which vocabulary exists is the import's business, so
 * «Sí» stays «Sí» and the labels stay their labels — what is pinned here is
 * where every cell landed, not what the import will make of it.
 */
final class CsvReaderTest extends TestCase {
	private CsvReader $reader;

	/** The committed fixture — the realistic planilla. */
	private const FIXTURE = __DIR__ . '/../../fixtures/planilla.csv';

	protected function setUp(): void {
		$this->reader = new CsvReader();
	}

	public function testTheFixtureParsesIntoRowsOfCells(): void {
		$rows = $this->reader->read((string)file_get_contents(self::FIXTURE));

		self::assertCount(5, $rows);
		$first = $rows[0];
		self::assertSame(2, $first['line'], 'the heading is line 1: the line the review names is the row the spreadsheet itself shows');
		self::assertSame('F-001', $first['cells']['codigo']);
		self::assertSame('Paracetamol 500 mg comprimidos', $first['cells']['producto']);
		self::assertSame('Analgésicos / antipiréticos / antiinflamatorios', $first['cells']['categoria'], 'the category cell keeps its label: which categories exist is the import\'s business');
		self::assertSame('No', $first['cells']['trazador']);
		self::assertSame(['17'], $first['cells']['ges'], 'the multi-value cells arrive split');
		self::assertSame(['Solo receta retenida'], $first['cells']['restricciones']);
		self::assertSame(['URGENCIAS', 'CECOSF'], $first['cells']['abastece']);
		self::assertSame('B', $first['cells']['fdaEmbarazo']);
		self::assertSame('', $first['cells']['programa'], 'an empty cell is an empty string in a carried column, not a missing key');
	}

	public function testTheQuotedCellWithADelimiterInsideStaysOneCell(): void {
		$rows = $this->reader->read((string)file_get_contents(self::FIXTURE));

		self::assertSame('1 comprimido cada 8 horas; según indicación', $rows[2]['cells']['dosificacion']);
	}

	public function testAHeadingTypedWithoutItsAccentStillMatches(): void {
		$rows = $this->reader->read("Codigo;Producto\nF-001;Paracetamol\n");

		self::assertSame('F-001', $rows[0]['cells']['codigo']);
	}

	public function testAByteOrderMarkIsNotPartOfTheFirstHeading(): void {
		$plain = $this->reader->read("Código;Producto\nF-001;Paracetamol\n");
		$marked = $this->reader->read("\u{FEFF}Código;Producto\nF-001;Paracetamol\n");

		self::assertSame($plain, $marked);
	}

	public function testTheShortAndFullSpellingsOfAHeadingAreOneColumn(): void {
		$rows = $this->reader->read("Código;Fármaco;Riesgo colinérgico\nF-001;paracetamol;No\n");

		self::assertSame('paracetamol', $rows[0]['cells']['farmaco']);
		self::assertSame('No', $rows[0]['cells']['riesgoColinergicoAm']);
	}

	public function testAnUnknownColumnIsIgnoredRatherThanRefused(): void {
		$rows = $this->reader->read("Código;Sitio web\nF-001;https://ejemplo.cl\n");

		self::assertCount(1, $rows);
		self::assertSame('F-001', $rows[0]['cells']['codigo']);
		self::assertArrayNotHasKey('sitio_web', $rows[0]['cells'], 'the allow-list, not a pass-through: a stray column cannot reach a key the apply writes');
	}

	public function testACommaDelimitedTwinReadsToTheSameRows(): void {
		$commas = $this->reader->read("Código,Producto\nF-001,Paracetamol\n");
		$semicolons = $this->reader->read("Código;Producto\nF-001;Paracetamol\n");

		self::assertSame($commas, $semicolons);
	}

	public function testALineBreakInsideAQuotedCellDoesNotEndTheRow(): void {
		$rows = $this->reader->read("Código;Dosificación\nF-001;\"1 cada 8 horas.\nCon alimentos.\"\n");

		self::assertCount(1, $rows);
		self::assertSame("1 cada 8 horas.\nCon alimentos.", $rows[0]['cells']['dosificacion']);
	}

	public function testAFileThatIsNotValidUtf8IsReadAsWindows1252(): void {
		$utf8 = "Código;Producto\nF-001;Paracetamol Ñuñoa\n";
		$latin = (string)mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

		self::assertFalse(mb_check_encoding($latin, 'UTF-8'), 'the fixture is not the case being tested');
		self::assertSame('Paracetamol Ñuñoa', $this->reader->read($latin)[0]['cells']['producto']);
	}

	public function testTheFormulaApostropheIsStrippedOnlyFromDefusedCells(): void {
		$rows = $this->reader->read("Código;Producto\nF-001;'=HYPERLINK(...)\nF-002;'Colmena\n");

		self::assertSame('=HYPERLINK(...)', $rows[0]['cells']['producto'], 'the export\'s defusing comes back off');
		self::assertSame("'Colmena", $rows[1]['cells']['producto'], 'a cell that genuinely begins with the apostrophe is not the writer\'s to touch');
	}

	public function testAStraySeparatorPieceIsDroppedNotKept(): void {
		$rows = $this->reader->read("Código;GES\nF-001;1||2;\n");

		self::assertSame(['1', '2'], $rows[0]['cells']['ges']);
	}

	public function testABlankRecordIsNotARowButKeepsItsLineNumber(): void {
		$rows = $this->reader->read("Código;Producto\nF-001;Paracetamol\n\n\nF-002;Ibuprofeno\n");

		self::assertCount(2, $rows);
		self::assertSame(5, $rows[1]['line'], 'a blank record still occupies the row the spreadsheet shows');
	}

	public function testOnlyACellTheRecordCarriesCanClearItsField(): void {
		$heading = strtok((string)file_get_contents(self::FIXTURE), "\n");
		$short = 'F-001;Paracetamol;paracetamol;500;Comprimido;Analgésicos;No;;;;;;;;sin observaciones';
		$full = 'F-002;Enalapril;enalapril;10;Comprimido;Cardiovasculares;No;;;;;;;;;;;';

		$rows = $this->reader->read($heading . "\n" . $short . "\n" . $full . "\n");

		self::assertSame('sin observaciones', $rows[0]['cells']['observaciones']);
		foreach (['riesgoColinergicoAm', 'fdaEmbarazo', 'ajusteRenal'] as $key) {
			self::assertArrayNotHasKey($key, $rows[0]['cells'], 'a cell the line ends before is a key the row does not have, not a clear');
			self::assertSame('', $rows[1]['cells'][$key], 'an empty cell the line carries is still a clear');
		}
	}

	public function testAFileWithoutACodigoColumnIsRefusedNamingTheColumns(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessageMatches('/Código/');

		$this->reader->read("Producto;Mg\nParacetamol;500\n");
	}

	public function testAnEmptyFileIsRefused(): void {
		$this->expectException(InvalidImportException::class);

		$this->reader->read('');
	}
}
