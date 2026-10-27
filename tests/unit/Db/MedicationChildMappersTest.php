<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Db;

use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationGesMapper;
use OCA\Farmacia\Db\MedicationRestrictionMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The child mappers' bulk read, forIds(): Nextcloud's QueryBuilder logs a core
 * error for an array parameter over 1000 entries and Oracle rejects the query,
 * so a longer id list is read in chunks into the same map one query would give.
 */
class MedicationChildMappersTest extends TestCase {
	/** @var list<list<int>> the id list each executed query carried */
	private array $executed = [];

	/** @return array<string, array{class-string, string, callable-string}> */
	public static function mapperProvider(): array {
		return [
			'ges' => [MedicationGesMapper::class, 'number', 'intval'],
			'restricciones' => [MedicationRestrictionMapper::class, 'restriction', 'strval'],
			'abastece' => [MedicationAbasteceMapper::class, 'channel_id', 'intval'],
		];
	}

	/** @dataProvider mapperProvider */
	public function testForIdsReadsAtMostAThousandIdsPerQuery(string $class, string $column, string $cast): void {
		$byMedication = (new $class($this->connection($column)))->forIds(range(1, 2500));

		self::assertSame([1000, 1000, 500], array_map('count', $this->executed));
		self::assertSame(range(1, 2500), array_merge(...$this->executed));
		$expected = [];
		foreach (range(1, 2500) as $id) {
			$expected[$id] = [$cast((string)$id)];
		}
		$expected[1500][] = $cast('1');
		self::assertSame($expected, $byMedication);
	}

	/** @dataProvider mapperProvider */
	public function testForIdsWithNoIdsRunsNoQuery(string $class): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], (new $class($db))->forIds([]));
	}

	/**
	 * A connection whose query builder records the id list bound when each
	 * query executes, and answers one row per id plus a later-inserted second
	 * row for medication 1500.
	 */
	private function connection(string $column): IDBConnection {
		$current = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$current) {
			if (is_array($value)) {
				$current = $value;
			}
		});
		$qb->method('setParameter')->willReturnCallback(function ($key, $value) use (&$current, $qb) {
			if (is_array($value)) {
				$current = $value;
			}
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$current, $column) {
			$this->executed[] = $current;
			$rows = array_map(fn (int $id) => ['medication_id' => (string)$id, $column => (string)$id], $current);
			if (in_array(1500, $current, true)) {
				$rows[] = ['medication_id' => '1500', $column => '1'];
			}
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			return $result;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}
}
