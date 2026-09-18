<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Db;

use OCA\Farmacia\Db\Medication;
use PHPUnit\Framework\TestCase;

/**
 * The entity's write path and payload contract — the same suite shape
 * CategoryTest pins, over the one entity every service write goes through.
 */
class MedicationTest extends TestCase {
	public function testAFreshEntityHasEmptyDefaultsAndNothingToUpdate(): void {
		$medication = new Medication();
		self::assertSame('', $medication->getCodigo());
		self::assertSame('', $medication->getProducto());
		self::assertNull($medication->getFarmaco());
		self::assertSame(0, $medication->getCategoryId());
		self::assertFalse($medication->getRetired());
		self::assertNull($medication->getUpdatedAt());
		self::assertSame([], $medication->getUpdatedFields());
	}

	public function testSettingAFieldMarksItUpdated(): void {
		$medication = new Medication();
		$medication->setCodigo('F-001');
		$medication->setProducto('Paracetamol 500 mg');
		$medication->setTrazador(true);

		self::assertSame(true, $medication->getUpdatedFields()['codigo']);
		self::assertSame(true, $medication->getUpdatedFields()['producto']);
		self::assertSame(true, $medication->getUpdatedFields()['trazador']);
	}

	public function testTheSetterTrapOnBooleans(): void {
		$medication = new Medication();
		$medication->setRetired(false);

		self::assertSame([], $medication->getUpdatedFields());
	}

	public function testToClientIsFlatWithCastsAndBareIds(): void {
		$medication = new Medication();
		$medication->setId(7);
		$medication->setCodigo('F-001');
		$medication->setProducto('Paracetamol 500 mg');
		$medication->setCategoryId(3);
		$medication->setTrazador(true);
		$medication->setUpdatedAt(new \DateTime('2026-09-17 12:00:00'));

		$client = $medication->toClient();

		self::assertSame(7, $client['id']);
		self::assertSame('F-001', $client['codigo']);
		self::assertSame(3, $client['categoryId']);
		self::assertTrue($client['trazador']);
		self::assertFalse($client['riesgoColinergicoAm']);
		self::assertNull($client['fdaEmbarazo']);
		self::assertSame('2026-09-17 12:00:00', $client['updatedAt']);
		self::assertArrayNotHasKey('ges', $client);
	}

	public function testComparableCarriesTheEditableContentOnly(): void {
		$medication = new Medication();
		$medication->setId(7);
		$medication->setRetired(true);
		$medication->setUpdatedAt(new \DateTime());

		self::assertArrayNotHasKey('id', $medication->comparable());
		self::assertArrayNotHasKey('retired', $medication->comparable());
		self::assertArrayNotHasKey('updatedAt', $medication->comparable());
		self::assertArrayHasKey('codigo', $medication->comparable());
	}
}