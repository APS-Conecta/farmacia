<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Controller;

use OCA\Farmacia\Db\Medication;
use PHPUnit\Framework\TestCase;

/**
 * The schema↔served-payload drift test (L2-10): openapi.json's medication
 * response names exactly the keys the route actually serves — toClient()'s
 * 18 plus the three child arrays MedicationService attaches. A rename on
 * either side breaks here, loudly, in one place — before a consumer's
 * integration does it quietly in production.
 *
 * The spec is generated from the controller's @return docblock, and the
 * served shape is read from the code that builds it, so this test holds the
 * two together without either copying the other.
 */
final class ApiContractTest extends TestCase {
	private const SERVED = [
		'id', 'codigo', 'producto', 'farmaco', 'mg', 'presentacion', 'programa',
		'trazador', 'indicacion', 'dosificacion', 'precauciones', 'observaciones',
		'riesgoColinergicoAm', 'fdaEmbarazo', 'ajusteRenal', 'categoryId',
		'retired', 'updatedAt',
		// MedicationService::withChildren()/payloadOf() attach the three
		// child lists onto toClient()'s row.
		'ges', 'restricciones', 'abastece',
	];

	/** The keys the served payload actually carries, read from the code. */
	public function testToClientPlusChildrenNamesTheServedKeys(): void {
		$medication = new Medication();
		$medication->setCodigo('P-1');
		$medication->setProducto('Paracetamol 500 mg');
		$medication->setCategoryId(1);

		$payload = $medication->toClient() + ['ges' => [], 'restricciones' => [], 'abastece' => []];

		self::assertSame(
			self::SERVED,
			array_keys($payload),
			'the served key set moved — update the @return docblock and regenerate the spec',
		);
	}

	/** The spec's 200 response for the list names the same keys, and only those. */
	public function testTheSpecNamesExactlyTheServedKeys(): void {
		$spec = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/openapi.json'),
			true,
		);
		self::assertIsArray($spec, 'openapi.json is missing or unparseable');

		$schema = $spec['paths']['/ocs/v2.php/apps/farmacia/api/v1/medicamentos']['get']['responses']['200']
			?? null;
		self::assertNotNull($schema, 'the medicamentos GET 200 response is gone from the spec');

		$json = json_encode($schema['content']['application/json']['schema']);
		self::assertIsString($json);
		preg_match_all('/"([a-zA-Z]+)":\s*\{/', $json, $matches);
		$named = array_values(array_unique($matches[1]));

		// The inline schema names every payload property; anything in SERVED
		// missing from the spec, or any spec property not served, is drift.
		self::assertSame(
			[],
			array_values(array_diff(self::SERVED, $named)),
			'the spec no longer names these served keys',
		);
		self::assertSame(
			[],
			array_values(array_diff($named, self::SERVED, ['type', 'properties', 'required', 'items', 'description', 'ocs', 'meta', 'data'])),
			'the spec names keys the route does not serve',
		);
	}
}
