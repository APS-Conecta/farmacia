<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Db;

use OCA\Farmacia\Db\Category;
use OCA\Farmacia\Db\Channel;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary entities' write path — the lesson territorio learned the hard
 * way (#74, 2026-08-17): Entity::setter marks a field updated only when the
 * new value differs from the current one, so a NOT NULL column whose only
 * guarantee was "it was initialised" could never be written back. These pin
 * exactly that mechanism, on the two entities every later slice writes through.
 */
class CategoryTest extends TestCase {
	public function testAFreshEntityHasEmptyDefaultsAndNothingToUpdate(): void {
		$category = new Category();
		self::assertSame('', $category->getSlug());
		self::assertSame('', $category->getLabel());
		self::assertSame([], $category->getUpdatedFields());
	}

	public function testSettingAFieldMarksItUpdated(): void {
		$category = new Category();
		$category->setSlug('analgesicos');
		$category->setLabel('Analgésicos');

		// The contract is field => true, not field => value: the entity records
		// WHAT changed, and QBMapper writes the property it finds — a value
		// here would be a second copy of the property that could disagree.
		self::assertSame(true, $category->getUpdatedFields()['slug']);
		self::assertSame(true, $category->getUpdatedFields()['label']);
	}

	public function testSettingTheSameValueTwiceStillMarksItOnce(): void {
		// The #74 trap: the setter returns early when the value equals the
		// current one, so "seeded default then explicit same value" writes
		// nothing. With defaults on the column, that is safe — this test is
		// the reason it stays safe.
		$category = new Category();
		$category->setSlug('otros');
		$category->setSlug('otros');
		self::assertSame(true, $category->getUpdatedFields()['slug']);
		$category->resetUpdatedFields();
		$category->setSlug('otros');
		self::assertSame([], $category->getUpdatedFields(), 'the #74 trap: setting the same value a second time must NOT re-mark it updated');
	}

	public function testChannelBehavesLikeCategory(): void {
		// The twin — every write-path rule the Category entity follows, the
		// Channel must too, or a channel added through the UI that happens to
		// match a default is silently not written back.
		$channel = new Channel();
		self::assertSame([], $channel->getUpdatedFields());
		$channel->setSlug('urgencias');
		self::assertSame(true, $channel->getUpdatedFields()['slug']);
		$channel->resetUpdatedFields();
		$channel->setSlug('urgencias');
		self::assertSame([], $channel->getUpdatedFields(), 'the twin #74 trap: Channel must match Category exactly');
	}
}