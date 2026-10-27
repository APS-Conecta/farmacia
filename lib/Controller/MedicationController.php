<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use APS\Common\Refuses;
use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Service\MedicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class MedicationController extends Controller {
	use Refuses;
	public function __construct(
		IRequest $request,
		private MedicationService $medications,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(function (): array {
			$res = $this->medications->create($this->request->getParams());
			$this->logAuditEvent('CREATE_MEDICATION', $res['id'] ?? null, $res['codigo'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$res = $this->medications->update($id, $this->request->getParams());
			$this->logAuditEvent('UPDATE_MEDICATION', $id, $res['codigo'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$res = $this->medications->retire($id);
			$this->logAuditEvent('RETIRE_MEDICATION', $id, $res['codigo'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$res = $this->medications->restore($id);
			$this->logAuditEvent('RESTORE_MEDICATION', $id, $res['codigo'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function retired(): JSONResponse {
		return $this->attempt(fn (): array => $this->medications->listRetired());
	}

	private function logAuditEvent(string $action, ?int $entityId, ?string $codigo): void {
		$actor = $this->userSession->getUser()?->getUID() ?? 'system';
		$ip = $this->request->getRemoteAddress() ?? '127.0.0.1';
		$this->logger->warning(sprintf(
			'HIPAA_AUDIT: %s by actor=%s on medication_id=%s codigo=%s from ip=%s at %s',
			$action,
			$actor,
			$entityId !== null ? (string)$entityId : 'unknown',
			$codigo ?? 'none',
			$ip,
			(new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)
		), ['app' => Application::APP_ID, 'audit' => true]);
	}
}