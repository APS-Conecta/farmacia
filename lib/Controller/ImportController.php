<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use APS\Common\Refuses;
use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The browser's door to the staged import.
 *
 * #[NoAdminRequired] for the same reason every other write here carries
 * it: whoever keeps the vademécum is who imports the planilla, and the
 * safety net is the staged review — nothing lands in the medication tables
 * until the batch is applied from it — not permissions.
 *
 * The occ command runs through the same ImportService, so the two doors
 * cannot drift (territorio's doctrine).
 */
class ImportController extends Controller {
	use Refuses;
	/**
	 * A planilla is a few hundred rows of text — well under a megabyte.
	 * The cap fails a mistaken upload immediately and cheaply, before PHP
	 * has read it all into memory (territorio's ImportController lesson).
	 */
	private const MAX_BYTES = 8 * 1024 * 1024;

	private const TOO_BIG = 'El archivo supera los 8 MB.';

	public function __construct(
		IRequest $request,
		private ImportService $import,
		private ApplyService $apply,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Stage an uploaded planilla for review. The answer is the review
	 * document — the batch with its counters, and every staged row with its
	 * disposition and pending decisions — because the review screen reads
	 * exactly what the staging concluded.
	 */
	#[NoAdminRequired]
	public function upload(): JSONResponse {
		$file = $this->request->getUploadedFile('file');

		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return $this->refuse('No llegó ningún archivo.');
		}
		if (($file['size'] ?? 0) > self::MAX_BYTES) {
			return $this->refuse(self::TOO_BIG);
		}

		$contents = file_get_contents((string)($file['tmp_name'] ?? ''));
		if ($contents === false) {
			return $this->refuse('No se pudo leer el archivo subido.');
		}

		try {
			return new JSONResponse($this->import->stage(
				$contents,
				(string)($file['name'] ?? ''),
				$this->userSession->getUser()?->getUID(),
			));
		} catch (InvalidImportException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	/** The recent runs, as the batches list shows them. */
	#[NoAdminRequired]
	public function batches(): JSONResponse {
		return new JSONResponse($this->import->batches());
	}

	/**
	 * The review document of one batch — the upload's own answer shape, so
	 * a staged batch reopened after a page reload reads the same as one
	 * just uploaded.
	 */
	#[NoAdminRequired]
	public function review(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->import->review($id));
	}

	/**
	 * Record one review decision on a row. The decision settles vocabulary —
	 * which category, which channels, or none — and never rewrites the
	 * planilla's own cells.
	 */
	#[NoAdminRequired]
	public function decide(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->import->decide($id, $this->request->getParams()));
	}

	/**
	 * Land the reviewed batch. The answer is the review document, re-served:
	 * the batch applied, its rows now carrying the before-images the revert
	 * will read.
	 */
	#[NoAdminRequired]
	public function apply(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->apply->apply($id));
	}

	/**
	 * Close a staged batch without landing anything. The rows stay — they
	 * are the record of what was refused — but decide() and apply() both
	 * refuse a batch that is no longer in review.
	 */
	#[NoAdminRequired]
	public function discard(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->apply->discard($id));
	}

	/**
	 * Take an applied batch back out — territorio's own route name, for
	 * its own reason there: the safety net is that the undo is recorded and
	 * honest, not that fewer people may reach it.
	 */
	#[NoAdminRequired]
	public function revert(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->apply->revert(
			$id,
			$this->userSession->getUser()?->getUID(),
		));
	}
}
