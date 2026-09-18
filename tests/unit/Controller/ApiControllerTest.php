<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Controller;

use OCA\Farmacia\Controller\ApiController;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCA\Farmacia\Service\MedicationService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller's own decisions: which narrowings make sense, and which
 * status a refusal becomes — the MedicationControllerTest discipline,
 * restated for the surface whose refusals a consumer reads. The narrowing
 * fold itself is pinned here too: the private methods are exercised through
 * index(), with the service mocked to two rows and the vocabulary mocked to
 * the two categories they sit in (slugFor stays real — it is static, pure,
 * and the app's one fold).
 */
final class ApiControllerTest extends TestCase {
	private MedicationService&MockObject $medications;
	private ApiController $controller;

	/** Two rows and their two categories: the minimum a narrowing can disagree about. */
	private const ROWS = [
		['id' => 1, 'producto' => 'Paracetamol 500 mg comprimidos', 'farmaco' => 'paracetamol', 'categoryId' => 7, 'fdaEmbarazo' => 'B', 'ajusteRenal' => null],
		['id' => 2, 'producto' => 'Enalapril 10 mg comprimidos', 'farmaco' => 'enalapril', 'categoryId' => 8, 'fdaEmbarazo' => 'D', 'ajusteRenal' => 'Reducir dosis en insuficiencia renal'],
	];

	private const CATEGORIES = [
		['id' => 7, 'slug' => 'analgesicos_antiinflamatorios', 'label' => 'Analgésicos / antipiréticos / antiinflamatorios'],
		['id' => 8, 'slug' => 'cardiovasculares_hta_dm_dlp', 'label' => 'Cardiovasculares (HTA · DM · DLP)'],
	];

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->medications = $this->createMock(MedicationService::class);
		$this->medications->method('listAll')->willReturn(self::ROWS);

		$vocabulary = $this->createMock(VocabularyService::class);
		$vocabulary->method('categories')->willReturn(self::CATEGORIES);

		$this->controller = new ApiController($request, $this->medications, $vocabulary);
	}

	/** @return list<array<string, mixed>> the rows the envelope carried */
	private function answered(mixed $response): array {
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		return $response->getData();
	}

	public function testAnUnaskedQuestionReturnsTheWholeArsenal(): void {
		self::assertSame(self::ROWS, $this->answered($this->controller->index(null, null, null, null)));

		// The blank twin: a consumer building `&gestacion=${picked}` with
		// nothing picked sent '', and '' must read as not asked — epi's
		// lesson, or the empty string becomes a filter that matches nothing.
		self::assertSame(self::ROWS, $this->answered($this->controller->index('', '', '', '')));
	}

	public function testAPunctuationOnlySearchIsNotAQuestion(): void {
		// slugFor('??') folds to '' — a needle that would match everything.
		self::assertSame(self::ROWS, $this->answered($this->controller->index('??', null, null, null)));
	}

	public function testAGestationOutsideTheDomainIsRefused(): void {
		$response = $this->controller->index(null, null, 'Q', null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('La categoría FDA debe ser A, B, C, D o X.', $response->getData()['message']);
	}

	public function testARenalAjusteThatIsNotAFlagIsRefused(): void {
		$response = $this->controller->index(null, null, null, 'maybe');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('renal_ajuste', $response->getData()['message'], 'the sentence names the parameter it is about');
	}

	public function testAnUnknownCategoriaSlugIsNotFound(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->index(null, 'no-existe', null, null)->getStatus());
	}

	public function testSearchFoldsAccentsAndMatchesProductoFarmacoAndTheCategoryLabel(): void {
		// The same needle the UI folds: a missed accent still finds the row.
		self::assertSame([self::ROWS[0]], $this->answered($this->controller->index('paracetámol', null, null, null)));
		// The category label is the third field the app's own search
		// matches (filters.js's one definition, mirrored).
		self::assertSame([self::ROWS[1]], $this->answered($this->controller->index('cardiovascular', null, null, null)));
	}

	public function testCategoriaNarrowsBySlug(): void {
		self::assertSame([self::ROWS[1]], $this->answered(
			$this->controller->index(null, 'cardiovasculares_hta_dm_dlp', null, null),
		));
	}

	public function testGestacionAndRenalNarrow(): void {
		self::assertSame([self::ROWS[1]], $this->answered($this->controller->index(null, null, 'D', null)));
		self::assertSame([self::ROWS[1]], $this->answered($this->controller->index(null, null, null, '1')));

		// `0` is the explicit no — it must not read as the renal set the way
		// an undefined would in JavaScript.
		self::assertSame(self::ROWS, $this->answered($this->controller->index(null, null, null, '0')));
	}

	public function testACreatedMedicationAnswersCreated(): void {
		$this->medications->method('create')->willReturn(['id' => 12, 'codigo' => 'F-001', 'ges' => [1, 17]]);

		$created = $this->controller->create();

		self::assertSame(Http::STATUS_CREATED, $created->getStatus(), '201 is the honest answer for a resource that now exists');
		self::assertSame(12, $created->getData()['id']);
		self::assertSame([1, 17], $created->getData()['ges']);
	}

	public function testARefusedCreateIsUnprocessable(): void {
		$this->medications->method('create')
			->willThrowException(new InvalidMedicationException('El medicamento necesita un código.'));

		$refused = $this->controller->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame('El medicamento necesita un código.', $refused->getData()['message']);
	}

	public function testShowServesOneMedication(): void {
		$this->medications->method('read')->willReturn(['id' => 3, 'retired' => false]);

		$shown = $this->controller->show(3);

		self::assertSame(Http::STATUS_OK, $shown->getStatus());
		self::assertSame(3, $shown->getData()['id']);
	}

	public function testAShowThatIsGoneIsNotFound(): void {
		$this->medications->method('read')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(404)->getStatus());
	}

	public function testAnUpdateReturnsTheStoredMedication(): void {
		$this->medications->method('update')->willReturn(['id' => 3, 'producto' => 'Nuevo nombre']);

		$updated = $this->controller->update(3);

		self::assertSame(Http::STATUS_OK, $updated->getStatus());
		self::assertSame('Nuevo nombre', $updated->getData()['producto']);
	}

	public function testARefusedUpdateIsUnprocessable(): void {
		$this->medications->method('update')
			->willThrowException(new InvalidMedicationException('El registro está retirado. Restáurelo antes de editarlo.'));

		$refused = $this->controller->update(3);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame('El registro está retirado. Restáurelo antes de editarlo.', $refused->getData()['message']);
	}

	public function testAnUpdateThatIsGoneIsNotFound(): void {
		$this->medications->method('update')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(404)->getStatus());
	}
}
