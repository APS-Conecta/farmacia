<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Service\MedicationService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private MedicationService $medications,
		private VocabularyService $vocabulary,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): TemplateResponse {
		// Two documents, not one filtered on the client. The arsenal and the
		// retired list are different datasets, and the retired list needs its
		// own source — listAll() returns only live rows (findAll's retired=false
		// guard), so filtering that for retired always yields [].
		$this->initialState->provideInitialState('medications', $this->medications->listAll());
		$this->initialState->provideInitialState('retired', $this->medications->listRetired());
		$this->initialState->provideInitialState('categories', $this->vocabulary->categories());
		$this->initialState->provideInitialState('abastece', $this->vocabulary->abastece());

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'index');
	}
}
