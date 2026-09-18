<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCA\Farmacia\Service\MedicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MedicationController extends Controller {
	public function __construct(
		IRequest $request,
		private MedicationService $medications,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->create($this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->update($id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->retire($id));
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->restore($id));
	}

	#[NoAdminRequired]
	public function retired(): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->listRetired());
	}

	private function attempt(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (InvalidMedicationException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'no existe'], Http::STATUS_NOT_FOUND);
		}
	}
}