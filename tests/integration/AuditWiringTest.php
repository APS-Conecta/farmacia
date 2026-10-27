<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Controller\ApiController;
use OCA\Farmacia\Controller\ImportController;
use OCA\Farmacia\Controller\MedicationController;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The audited controllers as Nextcloud's container builds them: each one
 * holds the app logger its audit line is written through (ADR-0005).
 */
final class AuditWiringTest extends TestCase {
	/** @return array<string, array{class-string}> */
	public static function controllerProvider(): array {
		return [
			'MedicationController' => [MedicationController::class],
			'ApiController' => [ApiController::class],
			'ImportController' => [ImportController::class],
		];
	}

	/** @dataProvider controllerProvider */
	public function testEachAuditedControllerIsBuiltWithALogger(string $class): void {
		self::assertInstanceOf(LoggerInterface::class, (new \ReflectionProperty($class, 'logger'))->getValue(Server::get($class)));
	}
}
