<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use APS\Common\Refuses;
use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Controller;
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
	use Refuses;
	public function __construct(
		IRequest $request,
		private VocabularyService $vocabulary,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->addChannel(self::label($this->request->getParam('label'))));
	}

	#[NoAdminRequired]
	public function update(string $slug): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->vocabulary->renameChannel($slug, self::label($this->request->getParam('label'))));
	}

	/** No body and no `?force` — see CategoryController::destroy. */
	#[NoAdminRequired]
	public function destroy(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->vocabulary->removeChannel($slug));
	}

	/** See CategoryController::label — the two controllers stay structurally
	 * identical, this included (L2-03). */
	private static function label(mixed $value): string {
		return is_string($value) ? $value : '';
	}
}
