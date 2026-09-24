<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Integration;

use OCA\Farmacia\Exception\InvalidVocabularyException;
use OCA\Farmacia\Service\MedicationService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\Server;

/**
 * The in-use guard against the real database: the one property no mock can
 * know — that the count sees a RETIRED medication, so a category or channel
 * a retired record still references is refused, and the orphan territorio#ADR-0016
 * argues against cannot be created (territorio's TaxonomyRemovalTest split
 * again: the units observe that nothing is deleted; only this can observe
 * that the count is honest).
 */
final class VocabularyGuardTest extends IntegrationTestCase {
	private VocabularyService $vocabulary;
	private MedicationService $medications;

	protected function setUp(): void {
		$this->vocabulary = Server::get(VocabularyService::class);
		$this->medications = Server::get(MedicationService::class);
	}

	private function addCategoryTracked(string $label): int {
		$id = $this->idOf($this->vocabulary->addCategory($label), $label);
		$this->createdCategories[] = $id;

		return $id;
	}

	private function addChannelTracked(string $label): int {
		$id = $this->idOf($this->vocabulary->addChannel($label), $label);
		$this->createdChannels[] = $id;

		return $id;
	}

	/** @param list<array{id: int, slug: string, label: string}> $list */
	private function idOf(array $list, string $label): int {
		foreach ($list as $word) {
			if ($word['label'] === $label) {
				return (int)$word['id'];
			}
		}

		self::fail("the just-added \"$label\" is missing from the answered list");
	}

	public function testAWordNothingUsesRemoves(): void {
		$this->addCategoryTracked('Categoría de prueba');
		$this->addChannelTracked('Canal de prueba');

		$slugs = array_map(static fn ($w) => $w['slug'], $this->vocabulary->removeCategory('categoria_de_prueba'));
		self::assertNotContains('categoria_de_prueba', $slugs);

		$slugs = array_map(static fn ($w) => $w['slug'], $this->vocabulary->removeChannel('canal_de_prueba'));
		self::assertNotContains('canal_de_prueba', $slugs);
	}

	public function testACategoryHoldingARetiredMedicationIsStillRefused(): void {
		$categoryId = $this->addCategoryTracked('Categoría ocupada');
		$medicationId = $this->medication('VG-001', ['categoryId' => $categoryId]);

		try {
			$this->vocabulary->removeCategory('categoria_ocupada');
			self::fail('a category a live medication is filed under must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('hay 1 medicamento clasificado ahí', $e->getMessage());
		}

		$this->medications->retire($medicationId);

		// The property only a real database can prove: a count that filtered
		// retired would answer 0 here, the removal would go through, and
		// restoring the medication would resurrect a dangling reference no
		// foreign key will ever catch.
		try {
			$this->vocabulary->removeCategory('categoria_ocupada');
			self::fail('a category a retired medication is filed under must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('hay 1 medicamento clasificado ahí', $e->getMessage());
			self::assertStringContainsString('incluidos los retirados', $e->getMessage());
		}
	}

	public function testAChannelHoldingARetiredMedicationIsStillRefused(): void {
		$channelId = $this->addChannelTracked('Canal ocupado');
		$medicationId = $this->medication('VG-002', ['abastece' => [$channelId]]);

		$this->medications->retire($medicationId);

		try {
			$this->vocabulary->removeChannel('canal_ocupado');
			self::fail('a channel a retired medication draws from must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('hay 1 medicamento que proviene de este canal', $e->getMessage());
			self::assertStringContainsString('incluidos los retirados', $e->getMessage());
		}
	}
}
