<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

use OCA\Farmacia\Service\CsvGrammar;
use PHPUnit\Framework\TestCase;

/**
 * The round-trip's one grammar, tested as itself (L2-12). CsvGrammar's
 * docblock claimed "the unit container tests them in milliseconds" — false
 * as shipped: split/join/unquote were exercised only incidentally, through
 * the reader and writer suites. Every case below is one the docblocks name.
 */
final class CsvGrammarTest extends TestCase {
	public function testSplitTrimsAndDropsEmpties(): void {
		// «GES 1||2» still means problems 1 and 2 — a hand-typed planilla's
		// stray separator is tolerance, not an error.
		self::assertSame(['1', '2'], CsvGrammar::split(' 1 || 2 '));
		self::assertSame([], CsvGrammar::split('||'));
		self::assertSame([], CsvGrammar::split(null));
		self::assertSame(['Solo receta retenida'], CsvGrammar::split('Solo receta retenida'));
	}

	public function testJoinIsTheExactInverseOfSplit(): void {
		self::assertSame('1|17', CsvGrammar::join(['1', '17']));
		self::assertSame('', CsvGrammar::join([]));
	}

	public function testUnquoteStripsOneLeadingApostropheOnlyBeforeATrigger(): void {
		// The defused cell comes back as what it was.
		self::assertSame('=SUM(A1)', CsvGrammar::unquote("'=SUM(A1)"));
		self::assertSame('+56 9 1234', CsvGrammar::unquote("'+56 9 1234"));
		self::assertSame('-colmena', CsvGrammar::unquote("'-colmena"));
	}

	/** The narrowness the docblock calls the round-trip's honesty. */
	public function testAGenuineApostropheInitialCellComesBackUntouched(): void {
		// C is not a formula trigger, so the writer never defused this cell —
		// stripping here would corrupt a value the round-trip carried fine.
		self::assertSame("'Colmena", CsvGrammar::unquote("'Colmena"));
		self::assertSame("'texto", CsvGrammar::unquote("'texto"));
	}

	public function testTheOneAmbiguityIsTheDocumentedOne(): void {
		// A cell that GENUINELY begins with «'=» is indistinguishable from a
		// defused «=» — the docblock names it, the writer refuses to touch
		// apostrophe-initial cells to keep it rare, and this pin states the
		// behaviour rather than resolving it: the apostrophe goes.
		self::assertSame('=x', CsvGrammar::unquote("'=x"));
	}
}
