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
 * Editing the therapeutic categories — one of the two vocabularies
 * VocabularyService owns.
 *
 * #[NoAdminRequired] for territorio's reason, still true here: the
 * vocabulary is the clinic's own words, and whoever keeps the vademécum is
 * who notices a name is wrong. The safety net is the in-use guard, not
 * permissions.
 *
 * Thin over VocabularyService, like MedicationController over
 * MedicationService: the judgements live in the service, and the one
 * decision here is which status code a refusal becomes. Every action
 * answers with the whole served list, so the client replaces its copy
 * rather than splicing — one description of the list, never two that can
 * disagree.
 */
class CategoryController extends Controller {
	public function __construct(
		IRequest $request,
		private VocabularyService $vocabulary,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->addCategory((string)($this->request->getParam('label') ?? '')));
	}

	/**
	 * Renames; the slug survives (VocabularyService::renameCategory), so the
	 * {slug} in the URL is the same address before and after the call.
	 */
	#[NoAdminRequired]
	public function update(string $slug): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->renameCategory($slug, (string)($this->request->getParam('label') ?? '')));
	}

	/**
	 * No body and no `?force`: while any medication — retired included — is
	 * classified under the category, the service refuses, and there is
	 * nothing here for a caller to choose (ADR-0016's shape).
	 */
	#[NoAdminRequired]
	public function destroy(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->vocabulary->removeCategory($slug));
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
