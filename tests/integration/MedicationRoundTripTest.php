<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCA\Farmacia\Service\MedicationService;
use OCP\Server;

/**
 * The service's write paths against the real database: the camelCase
 * properties must land in the migration's snake_case columns, the child
 * replaces must be transactional with their parent, and the refusals must be
 * refusals. Each refusal test is a reintroduce-the-bug check for its guard.
 */
final class MedicationRoundTripTest extends IntegrationTestCase {
	private MedicationService $service;

	protected function setUp(): void {
		$this->service = Server::get(MedicationService::class);
	}

	public function testCreateStoresTheMedicationAndItsChildren(): void {
		$payload = $this->service->create([
			'codigo' => 'F-100',
			'producto' => 'Paracetamol 500 mg',
			'farmaco' => 'paracetamol',
			'categoryId' => $this->categoryIdBySlug('analgesicos_antipireticos_antiinflamatorios'),
			'ges' => [1, 17],
			'restricciones' => ['Solo receta retenida'],
			'trazador' => true,
		]);
		$this->created[] = (int)$payload['id'];

		self::assertSame('F-100', $payload['codigo']);
		self::assertSame([1, 17], $payload['ges']);
		self::assertSame(['Solo receta retenida'], $payload['restricciones']);
		self::assertSame([], $payload['abastece']);
		self::assertTrue($payload['trazador']);

		$inList = null;
		foreach ($this->service->listAll() as $row) {
			if ($row['id'] === $payload['id']) { $inList = $row; }
		}
		self::assertNotNull($inList);
		self::assertSame([1, 17], $inList['ges']);
	}

	public function testUpdateReplacesAChildSetWholesale(): void {
		$id = $this->medication('F-101', ['ges' => [1, 17]]);
		$payload = $this->service->update($id, ['ges' => [3]]);
		self::assertSame([3], $payload['ges']);
	}

	public function testAnAbsentChildKeyLeavesThatSetAlone(): void {
		$id = $this->medication('F-102', ['ges' => [1, 17]]);
		$payload = $this->service->update($id, ['producto' => 'Otro nombre']);
		self::assertSame('Otro nombre', $payload['producto']);
		self::assertSame([1, 17], $payload['ges']);
	}

	public function testASaveThatChangesNothingDoesNotStampUpdatedAt(): void {
		$id = $this->medication('F-103', ['ges' => [1]]);
		$first = $this->service->update($id, ['ges' => [1]]);

		sleep(1);

		$noOp = $this->service->update($id, ['ges' => [1]]);

		self::assertSame($first['updatedAt'], $noOp['updatedAt'], 'a save that changes nothing must not move updated_at');
	}

	public function testARetiredMedicationKeepsItsChildrenOffTheArsenal(): void {
		$id = $this->medication('F-104', ['ges' => [17]]);

		$payload = $this->service->retire($id);

		self::assertTrue($payload['retired']);
		self::assertSame([17], $payload['ges'], 'retirement is not destruction: the children stay attached');

		foreach ($this->service->listAll() as $row) {
			self::assertNotSame($id, $row['id']);
		}
		$retired = $this->service->listRetired();
		self::assertContains($id, array_map(static fn ($r): int => $r['id'], $retired));
		$found = array_values(array_filter($retired, static fn ($r): bool => $r['id'] === $id))[0] ?? null;
		self::assertNotNull($found);
		self::assertSame([17], $found['ges']);
	}

	public function testRestoreBringsTheRecordBack(): void {
		$id = $this->medication('F-105');
		$this->service->retire($id);

		$payload = $this->service->restore($id);

		self::assertFalse($payload['retired']);
		$ids = array_map(static fn ($r): int => $r['id'], $this->service->listAll());
		self::assertContains($id, $ids);
	}

	public function testADuplicateCodigoIsRefusedWithTheCodigoNamed(): void {
		$this->medication('F-106');

		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('El código «F-106» ya existe.');
		$this->service->create([
			'codigo' => 'F-106',
			'producto' => 'Otro producto',
			'categoryId' => $this->categoryIdBySlug('otros'),
		]);
	}

	public function testARetiredRecordRefusesEdits(): void {
		$id = $this->medication('F-107');
		$this->service->retire($id);

		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('El registro está retirado. Restáurelo antes de editarlo.');
		$this->service->update($id, ['producto' => 'Edición invisible']);
	}

	public function testRetiringTwiceIsRefused(): void {
		$id = $this->medication('F-108');
		$this->service->retire($id);

		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('El registro ya estaba retirado.');
		$this->service->retire($id);
	}

	public function testRestoringSomethingLiveIsRefused(): void {
		$id = $this->medication('F-109');

		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('El registro no está retirado.');
		$this->service->restore($id);
	}

	public function testAnUnknownCategoryIsRefused(): void {
		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('La categoría 999999 no existe.');
		$this->service->create([
			'codigo' => 'F-110',
			'producto' => 'Producto',
			'categoryId' => 999999,
		]);
	}

	public function testAnInvalidFdaCategoryIsRefused(): void {
		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('La categoría FDA debe ser A, B, C, D o X (o vacía, para sin dato).');
		$this->service->create([
			'codigo' => 'F-111',
			'producto' => 'Producto',
			'categoryId' => $this->categoryIdBySlug('otros'),
			'fdaEmbarazo' => 'Q',
		]);
	}

	public function testAnUnknownChannelIsRefused(): void {
		$this->expectException(InvalidMedicationException::class);
		$this->expectExceptionMessage('El canal de abastece 999 no existe.');
		$this->service->create([
			'codigo' => 'F-112',
			'producto' => 'Producto',
			'categoryId' => $this->categoryIdBySlug('otros'),
			'abastece' => [999],
		]);
	}

	public function testAGesListDedupesRatherThanRefusing(): void {
		$payload = $this->service->create([
			'codigo' => 'F-113',
			'producto' => 'Producto',
			'categoryId' => $this->categoryIdBySlug('otros'),
			'ges' => [1, 1, 2],
		]);
		$this->created[] = (int)$payload['id'];

		self::assertSame([1, 2], $payload['ges']);
	}

	public function testARealChannelAssignmentRoundTrips(): void {
		$channel = new Channel();
		$channel->setSlug('urgencias-test');
		$channel->setLabel('URGENCIAS (prueba)');
		$channel = Server::get(ChannelMapper::class)->insert($channel);
		$this->createdChannels[] = (int)$channel->getId();

		$payload = $this->service->create([
			'codigo' => 'F-114',
			'producto' => 'Producto',
			'categoryId' => $this->categoryIdBySlug('otros'),
			'abastece' => [(int)$channel->getId()],
		]);
		$this->created[] = (int)$payload['id'];

		self::assertSame([(int)$channel->getId()], $payload['abastece']);
	}

	public static function blankListProvider(): array {
		return [['ges', ''], ['ges', null], ['ges', '  '], ['restricciones', ''], ['abastece', '']];
	}
	/** @dataProvider blankListProvider */
	public function testABlankListValueClearsThatList(string $key, ?string $blank): void {
		$id = $this->medication('F-115', ['ges' => [1, 17], 'restricciones' => ['Solo receta retenida']]);
		self::assertSame([[], []], [$this->service->update($id, [$key => $blank])[$key], $this->service->read($id)[$key]]);
	}

	public static function singleValueListProvider(): array {
		return [['ges', '5'], ['ges', 5], ['restricciones', 'Solo receta retenida'], ['abastece', '3']];
	}
	/** @dataProvider singleValueListProvider */
	public function testASingleValueListIsRefusedAndNothingIsWritten(string $key, int|string $single): void {
		$id = $this->medication('F-116', ['ges' => [1, 17]]);
		try {
			$this->service->update($id, ['producto' => 'Cambiado', $key => $single]);
			self::fail("a single «{$key}» value must be refused");
		} catch (InvalidMedicationException $e) {
			self::assertStringContainsString("«{$key}[]=»", $e->getMessage());
		}
		$stored = $this->service->read($id);
		self::assertSame(['Producto de prueba', [1, 17]], [$stored['producto'], $stored['ges']]);
	}

	public function testCreateReadsBlankListsAsEmpty(): void {
		$stored = $this->service->read($this->medication('F-117', ['ges' => '', 'restricciones' => '', 'abastece' => '']));
		self::assertSame([[], [], []], [$stored['ges'], $stored['restricciones'], $stored['abastece']]);
	}
}