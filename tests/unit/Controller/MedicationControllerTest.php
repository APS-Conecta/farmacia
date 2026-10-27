<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Controller;

use OCA\Farmacia\Controller\MedicationController;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCA\Farmacia\Service\MedicationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The controller holds one decision: which status code a refusal becomes.
 */
final class MedicationControllerTest extends TestCase {
	private MedicationService&MockObject $medications;
	private LoggerInterface&MockObject $logger;
	private MedicationController $controller;

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['codigo' => 'F-001', 'producto' => 'Paracetamol']);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('tester');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->medications = $this->createMock(MedicationService::class);
		$this->controller = new MedicationController($request, $this->medications, $session, $this->logger);
	}

	/** @return array<string, array{string, string, string, list<int>}> action, route, service method, route arguments */
	public static function changeProvider(): array {
		return [
			'create' => ['CREATE_MEDICATION', 'create', 'create', []],
			'update' => ['UPDATE_MEDICATION', 'update', 'update', [7]],
			'retire' => ['RETIRE_MEDICATION', 'destroy', 'retire', [7]],
			'restore' => ['RESTORE_MEDICATION', 'restore', 'restore', [7]],
		];
	}

	/** @dataProvider changeProvider */
	public function testEachChangeLeavesOneAuditWarning(string $action, string $route, string $serviceMethod, array $args): void {
		$this->medications->method($serviceMethod)->willReturn(['id' => 7, 'codigo' => 'X1']);
		$this->logger->expects($this->once())->method('warning')->with($this->matchesRegularExpression(
			'/^HIPAA_AUDIT: ' . $action . ' by actor=tester on medication_id=7 codigo=X1 from ip=\S* at \d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/',
		));

		$response = $this->controller->{$route}(...$args);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 7, 'codigo' => 'X1'], $response->getData());
	}

	public function testARefusedChangeLeavesNoAuditLine(): void {
		$this->medications->method('create')->willThrowException(new InvalidMedicationException('El medicamento necesita un código.'));
		$this->logger->expects($this->never())->method('warning');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->create()->getStatus());
	}

	public function testACreatedMedicationComesBackAssembled(): void {
		$this->medications->method('create')->willReturn(['id' => 12, 'codigo' => 'F-001', 'ges' => [1, 17]]);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(12, $response->getData()['id']);
		self::assertSame([1, 17], $response->getData()['ges']);
	}

	public function testAnUpdateReturnsTheStoredMedication(): void {
		$this->medications->method('update')->willReturn(['id' => 3, 'producto' => 'Nuevo nombre']);

		$response = $this->controller->update(3);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Nuevo nombre', $response->getData()['producto']);
	}

	public function testARefusedSubmissionIsUnprocessableNotAServerError(): void {
		$this->medications->method('create')->willThrowException(new InvalidMedicationException('El medicamento necesita un código.'));

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El medicamento necesita un código.', $response->getData()['message']);
	}

	public function testEditingSomethingThatIsGoneIsNotFound(): void {
		$this->medications->method('update')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(404)->getStatus());
	}

	public function testDestroyRetiresRatherThanDeletes(): void {
		$this->medications->expects($this->once())->method('retire')->with(5)->willReturn(['id' => 5, 'retired' => true]);

		$response = $this->controller->destroy(5);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['retired']);
	}

	public function testRetiredListsTheServicePayloads(): void {
		$this->medications->method('listRetired')->willReturn([['id' => 3, 'retired' => true]]);

		$response = $this->controller->retired();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['id' => 3, 'retired' => true]], $response->getData());
	}
}