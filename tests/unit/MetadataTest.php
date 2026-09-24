<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The repo says true things about itself, and this file fails when they stop
 * being true (apps/epidemiologia/tests/unit/MetadataTest.php shape). The OCS
 * slice extends this with the openapi.json agreement and the ApiController
 * attribute checks.
 */
class MetadataTest extends TestCase {
	private const APP_DIR = __DIR__ . '/../..';

	private function infoXml(): DOMDocument {
		$doc = new DOMDocument();
		$loaded = $doc->load(self::APP_DIR . '/appinfo/info.xml');
		self::assertTrue($loaded, 'appinfo/info.xml must parse');
		return $doc;
	}

	private function text(DOMDocument $doc, string $tag): string {
		$nodes = $doc->getElementsByTagName($tag);
		self::assertGreaterThan(0, $nodes->length, "info.xml must carry <$tag>");
		return trim((string)$nodes->item(0)->textContent);
	}

	public function testEveryFileThatDeclaresAVersionDeclaresTheSameOne(): void {
		// The rule is "agree", not "exist" (epi's own words): removing a
		// redundant declaration satisfies it just as well as syncing one.
		// openapi.json joins the loop the day it exists — a published contract
		// advertising a version the app does not ship is the drift epidemiologia#ADR-0006
		// records once already.
		$expected = $this->text($this->infoXml(), 'version');

		foreach (['package.json' => 'version', 'openapi.json' => null] as $file => $key) {
			$path = self::APP_DIR . '/' . $file;
			if (!file_exists($path)) {
				continue;
			}

			$json = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$found = $key === null ? ($json['info']['version'] ?? null) : ($json[$key] ?? null);

			if ($found === null) {
				continue;
			}

			self::assertSame($expected, $found, "$file declares a version info.xml does not");
		}
	}

	/**
	 * openapi.json is a generated copy of the summary (scripts/openapi.sh
	 * stamps it), so it must match EXACTLY — run the script when it does
	 * not. epi's own lesson: the version was gated and the description was
	 * not, so a corrected info.xml kept advertising the old app to
	 * consumers with 199 green tests saying nothing.
	 */
	public function testThePublishedContractDescribesTheAppItShips(): void {
		$summary = $this->text($this->infoXml(), 'summary');
		self::assertNotSame('', $summary, 'info.xml declares no summary, so everything below would be vacuous');

		$path = self::APP_DIR . '/openapi.json';
		if (!file_exists($path)) {
			self::markTestSkipped('openapi.json ships with the OCS slice');
		}

		$spec = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(
			$summary,
			$spec['info']['description'] ?? null,
			'openapi.json describes the app differently from info.xml — it is generated, so run scripts/openapi.sh',
		);
	}

	public function testPackageDescriptionCarriesTheSummary(): void {
		$summary = $this->text($this->infoXml(), 'summary');
		$package = json_decode((string)file_get_contents(self::APP_DIR . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertStringContainsString($summary, $package['description'] ?? '', 'package.json description must carry the info.xml summary verbatim');
	}

	public function testLicenceIsTheSpdxAgplId(): void {
		self::assertSame('AGPL-3.0-or-later', $this->text($this->infoXml(), 'licence'), "the licence id must be the current SPDX form, 'agpl' is deprecated");
	}

	public function testTheDeclaredLicenceIsActuallyShipped(): void {
		$text = (string)file_get_contents(self::APP_DIR . '/LICENSE');
		self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', substr($text, 0, 200), 'info.xml declares AGPL but LICENSE must actually ship its text');
		self::assertGreaterThan(30_000, strlen($text), 'the AGPL text is long; a short file means the licence was not copied');
	}

	public function testEveryPhpFileUnderLibCarriesTheSpdxHeader(): void {
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP_DIR . '/lib', \FilesystemIterator::SKIP_DOTS));
		$checked = 0;
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$checked++;
			$head = (string)substr((string)file_get_contents($file->getPathname()), 0, 4096);
			self::assertStringContainsString('SPDX-License-Identifier: AGPL-3.0-or-later', $head, "{$file->getPathname()} must carry the SPDX header");
		}
		self::assertGreaterThan(0, $checked, 'lib/ must contain PHP files by now');
	}

	/** @return array<string, class-string> */
	private function controllersWithEntryPoints(): array {
		// The OCS surface's entry point joined at S11: its index is the
		// route a consumer integrates against, and #[NoAdminRequired] is
		// the whole of what says a non-admin may call it.
		return [
			'ApiController' => \OCA\Farmacia\Controller\ApiController::class,
			'PageController' => \OCA\Farmacia\Controller\PageController::class,
		];
	}

	public function testEntryPointsAreOpenToEveryUser(): void {
		foreach ($this->controllersWithEntryPoints() as $name => $class) {
			$method = (new ReflectionClass($class))->getMethod('index');
			$attributes = $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);
			self::assertCount(1, $attributes, "$name::index must be #[NoAdminRequired]: every user who can open the app may use it");
		}
	}

	public function testEveryControllerActionIsOpenToEveryUser(): void {
		// The Requirements say "#[NoAdminRequired] everywhere"; this is the
		// gate that keeps it said. New controllers join the scan for free —
		// a future action without the attribute becomes admin-only silently,
		// which is the whole app's premise breaking invisibly.
		$files = glob(self::APP_DIR . '/lib/Controller/*.php') ?: [];
		self::assertNotEmpty($files, 'lib/Controller must contain controllers');
		foreach ($files as $file) {
			$class = 'OCA\\Farmacia\\Controller\\' . basename($file, '.php');
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
					continue;
				}
				self::assertCount(
					1,
					$method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class),
					"{$class}::{$method->getName()} must be #[NoAdminRequired]: every user who can open the app may use it",
				);
			}
		}
	}

	public function testEveryOcsMethodCarriesARateLimit(): void {
		// The Requirements say "#[UserRateLimit] on every method" of the OCS
		// surface; this is the gate that keeps it said — the ceiling against
		// a runaway consumer is the route's own, and an attribute deleted
		// from one method is an unbounded consumer door, silently.
		$class = new ReflectionClass(\OCA\Farmacia\Controller\ApiController::class);
		$checked = 0;
		foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class->getName() || str_starts_with($method->getName(), '__')) {
				continue;
			}
			$checked++;
			self::assertCount(
					1,
					$method->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class),
					\OCA\Farmacia\Controller\ApiController::class . '::' . $method->getName() . ' must carry #[UserRateLimit]',
				);
		}
		self::assertGreaterThan(0, $checked, 'ApiController must have public methods by now');
	}
}
