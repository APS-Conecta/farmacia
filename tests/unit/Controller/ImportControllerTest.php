<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Controller;

use OCA\Farmacia\Controller\ImportController;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\ImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller's one job: which status code a refusal becomes, and that
 * the upload's sourcing refusals (no file, too big) fire before the service
 * is reached at all. Each mock is configured once per test — stubs answer
 * in registration order, so a second registration on the same method is
 * dead weight (territorio's controller tests state the same rule by shape).
 */
final class ImportControllerTest extends TestCase {
	private ImportService&MockObject $import;
	private ApplyService&MockObject $apply;
	private IUserSession&MockObject $session;
	private string $tmp = '';

	protected function setUp(): void {
		$this->import = $this->createMock(ImportService::class);
		$this->apply = $this->createMock(ApplyService::class);
		$this->session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');
		$this->session->method('getUser')->willReturn($user);
	}

	protected function tearDown(): void {
		if ($this->tmp !== '') {
			@unlink($this->tmp);
		}
	}

	/** A request stub whose uploaded file is a real temp file. */
	private function requestWithFile(array $overrides = []): IRequest {
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'farmacia');
		file_put_contents($this->tmp, "Código;Producto\nF-001;Paracetamol\n");

		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn(array_merge([
				'error' => UPLOAD_ERR_OK,
				'size' => 100,
				'tmp_name' => $this->tmp,
				'name' => 'planilla.csv',
		], $overrides));
		return $request;
	}

	public function testAnUploadAnswersTheReviewDocumentAndNamesTheActor(): void {
		$request = $this->requestWithFile();
		$this->import->expects(self::once())->method('stage')
			->with("Código;Producto\nF-001;Paracetamol\n", 'planilla.csv', 'tester')
			->willReturn(['batch' => ['id' => 11], 'rows' => []]);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->upload();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(11, $response->getData()['batch']['id']);
	}

	public function testAnUploadWithoutAFileIsRefusedAndTheServiceIsNeverReached(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn(null);
		$this->import->expects(self::never())->method('stage');

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('No llegó ningún archivo.', $response->getData()['message']);
	}

	public function testAnOversizedUploadIsRefusedWithoutBeingRead(): void {
		$request = $this->requestWithFile(['size' => 9 * 1024 * 1024]);
		$this->import->expects(self::never())->method('stage');

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El archivo supera los 8 MB.', $response->getData()['message']);
	}

	public function testARefusedPlanillaIsUnprocessableWithItsSentence(): void {
		$request = $this->requestWithFile();
		$this->import->method('stage')->willThrowException(new InvalidImportException('El archivo no tiene filas debajo del encabezado.'));

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El archivo no tiene filas debajo del encabezado.', $response->getData()['message']);
	}

	public function testReviewAnswersTheDocument(): void {
		$request = $this->createStub(IRequest::class);
		$this->import->method('review')->willReturn(['batch' => ['id' => 5], 'rows' => []]);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->review(5);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(5, $response->getData()['batch']['id']);
	}

	public function testReviewOfTheGoneIsNotFound(): void {
		$request = $this->createStub(IRequest::class);
		$this->import->method('review')->willThrowException(new DoesNotExistException('gone'));

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->review(404);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testADecisionAnswersTheUpdatedRow(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['categoryId' => 2]);
		$this->import->method('decide')->willReturn(['id' => 31, 'status' => 'staged']);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->decide(31);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('staged', $response->getData()['status']);
	}

	public function testADecisionsRefusalIsUnprocessableWithItsSentence(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$this->import->method('decide')->willThrowException(new InvalidImportException('La fila no espera una decisión.'));

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->decide(31);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('La fila no espera una decisión.', $response->getData()['message']);
	}

	public function testApplyAnswersTheDocument(): void {
		$request = $this->createStub(IRequest::class);
		$this->apply->method('apply')->willReturn(['batch' => ['id' => 11, 'state' => 'applied'], 'rows' => []]);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->apply(11);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('applied', $response->getData()['batch']['state']);
	}

	public function testARefusedApplyIsUnprocessableWithItsSentence(): void {
		$request = $this->createStub(IRequest::class);
		$this->apply->method('apply')->willThrowException(new InvalidImportException('2 fila(s) esperan una decisión de vocabulario antes de aplicar.'));

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->apply(11);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('2 fila(s) esperan una decisión de vocabulario antes de aplicar.', $response->getData()['message']);
	}

	public function testDiscardAnswersTheDocument(): void {
		$request = $this->createStub(IRequest::class);
		$this->apply->method('discard')->willReturn(['batch' => ['id' => 11, 'state' => 'discarded'], 'rows' => []]);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->discard(11);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('discarded', $response->getData()['batch']['state']);
	}

	public function testRevertAnswersTheRevertBatchAndNamesTheActor(): void {
		$request = $this->createStub(IRequest::class);
		$this->apply->expects(self::once())->method('revert')
			->with(11, 'tester')
			->willReturn(['id' => 21, 'revertsId' => 11, 'updated' => 3, 'skipped' => 1]);

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->revert(11);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(21, $response->getData()['id']);
	}

	public function testARevertRefusalIsUnprocessableWithItsSentence(): void {
		$request = $this->createStub(IRequest::class);
		$this->apply->method('revert')->willThrowException(new InvalidImportException('Ese lote ya está deshecho.'));

		$response = (new ImportController($request, $this->import, $this->apply, $this->session))->revert(11);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Ese lote ya está deshecho.', $response->getData()['message']);
	}
}
