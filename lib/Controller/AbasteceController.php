<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Exception\InvalidVocabularyException;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Editing the ABASTECE supply channels — the second vocabulary, and a
 * deliberate mirror of CategoryController: the two vocabularies are one
 * rule over two tables, so when a judgement changes it changes in
 * VocabularyService and the two controllers stay structurally identical.
 * «Abastece» is the domain's own word (CONTEXT.md): there is no English
 * name for a thing each CESFAM defines for itself.
 */
class AbasteceController extends Controller {
	public function __construct(
		IRequest $request,
		private VocabularyService $vocabulary,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->addChannel((string)($this->request->getParam('label') ?? '')));
	}

	#[NoAdminRequired]
	public function update(string $slug): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->renameChannel($slug, (string)($this->request->getParam('label') ?? '')));
	}

	/** No body and no `?force` — see CategoryController::destroy. */
	#[NoAdminRequired]
	public function destroy(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->vocabulary->removeChannel($slug));
	}

	/**
	 * One place to turn the service's refusals into status codes.
	 *
	 * @param callable(): array<int|string, mixed> $action
	 */
	private function attempt(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (InvalidVocabularyException $e) {
			// The submission was understood and refused.
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'no existe'], Http::STATUS_NOT_FOUND);
		}
	}
}
