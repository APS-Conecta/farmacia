<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * The planilla's other door. One route, one file, no branches: there is
 * nothing to refuse — the whole live arsenal is the export, and an empty
 * arsenal is an honest headings-only planilla, not an error — and nothing
 * to confirm, because no personal data is involved (territorio's
 * plain/withContacts split and its export log exist for contacts farmacia
 * never stores; the Not-Building line keeps it that way).
 *
 * GET, not POST, on purpose: the browser can navigate to the download,
 * which makes it a plain link — no fetch, no blob handling, and no CSRF
 * token, which a GET never needed.
 */
class ExportController extends Controller {
	public function __construct(
		IRequest $request,
		private ExportService $exports,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** The whole live arsenal as a CSV the app can read back. */
	#[NoAdminRequired]
	public function plain(): DataDownloadResponse {
		$file = $this->exports->export();

		return new DataDownloadResponse($file['body'], $file['filename'], $file['mime']);
	}
}
