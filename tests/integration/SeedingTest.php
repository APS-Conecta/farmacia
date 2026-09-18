<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Migration\EnsureSeedData;
use OCP\Server;

/**
 * What the seed owes an install: sixteen therapeutic categories, an ABASTECE
 * vocabulary that stays empty, and a second run that writes nothing. The
 * counts are pinned here on purpose — editing the seed list is a decision
 * this file forces someone to make consciously, and the no-op test is the
 * reintroduce-the-bug check for the hasRows guard: delete the guard, run the
 * step twice, and this file says 32 where it said 16.
 */
final class SeedingTest extends IntegrationTestCase {
	public function testTheInstallSeededExactlySixteenCategories(): void {
		$categories = Server::get(CategoryMapper::class)->findAll();
		self::assertCount(16, $categories, 'the seed must have run at enable: exactly the sixteen therapeutic categories');
	}

	public function testTheKnownSlugsArePresent(): void {
		$slugs = array_map(static fn ($c) => $c->getSlug(), Server::get(CategoryMapper::class)->findAll());
		foreach (['analgesicos_antipireticos_antiinflamatorios', 'salud_mental_incluye_tto_oh', 'otros'] as $expected) {
			self::assertContains($expected, $slugs, "the seed should have created the \"$expected\" category");
		}
	}

	public function testNoSlugRepeats(): void {
		$slugs = array_map(static fn ($c) => $c->getSlug(), Server::get(CategoryMapper::class)->findAll());
		self::assertCount(16, array_unique($slugs), 'the unique index would refuse it, but the seed itself must never try');
	}

	/** ADR-0013: which channels exist is the clinic's to say. */
	public function testTheAbasteceVocabularyStartsEmpty(): void {
		self::assertSame([], Server::get(ChannelMapper::class)->findAll());
	}

	public function testRunningTheSeedAgainIsANoOp(): void {
		$before = count(Server::get(CategoryMapper::class)->findAll());
		Server::get(EnsureSeedData::class)->run($this->stubOutput());
		$after = count(Server::get(CategoryMapper::class)->findAll());

		self::assertSame($before, $after, 'the guard exists so a re-run never duplicates: a clinic that renamed a category does not get it restored');
	}

	private function stubOutput(): \OCP\Migration\IOutput {
		return new class implements \OCP\Migration\IOutput {
			public function info($message): void {
			}

			public function warning($message): void {
			}

			public function debug($message): void {
			}

			public function startProgress($max = 0): void {
			}

			public function advance($step = 1, $description = ''): void {
			}

			public function finishProgress(): void {
			}
		};
	}
}