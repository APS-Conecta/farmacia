<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One staged planilla row — the review's unit.
 *
 * The payload is the incoming row as normalized JSON: the trimmed cells
 * with the multi-value ones split, plus the resolution the staging already
 * did (`categoryId`, `abasteceIds`) and the `pending` map of what the
 * review still owes. The sí/no and FDA cells stay as typed — the review
 * shows the person their own words — and the apply derives from them with
 * the same deterministic rules that validated them here.
 *
 * `disposition` is what the apply would do today (create | update |
 * unchanged | skipped | duplicate | unsupported), `status` is the review's
 * triage (staged | unresolved-vocabulary | invalid), and `notes` is the
 * sentence saying why — which vocabulary value did not match, which cell
 * would not parse, why a row will not be touched.
 *
 * `beforeValues` stays null while the batch is staged: before-images are
 * captured at apply time, when the stored values they shadow are final —
 * capturing them here would freeze a guess about a record a colleague may
 * still edit before the review lands.
 *
 * @method int getBatchId()
 * @method void setBatchId(int $batchId)
 * @method int getLineNumber()
 * @method void setLineNumber(int $lineNumber)
 * @method string getCodigo()
 * @method void setCodigo(string $codigo)
 * @method string getPayload()
 * @method void setPayload(string $payload)
 * @method string getDisposition()
 * @method void setDisposition(string $disposition)
 * @method ?string getBeforeValues()
 * @method void setBeforeValues(?string $beforeValues)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class ImportRow extends Entity {
	protected $batchId = 0;
	protected $lineNumber = 0;
	protected $codigo = '';
	protected $payload = '';
	protected $disposition = 'create';
	protected $beforeValues = null;
	protected $status = 'staged';
	protected $notes = null;

	public function __construct() {
		$this->addType('batchId', 'integer');
		$this->addType('lineNumber', 'integer');
	}

	/**
	 * What the review shows, with the JSON decoded here rather than on the
	 * client (Feature::toClient's doctrine: the server ships the document
	 * it owns).
	 *
	 * @return array<string, mixed>
	 */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'batchId' => $this->getBatchId(),
			'lineNumber' => $this->getLineNumber(),
			'codigo' => $this->getCodigo(),
			'payload' => json_decode($this->getPayload(), true),
				'disposition' => $this->getDisposition(),
			'beforeValues' => $this->getBeforeValues() === null
				? null
				: json_decode($this->getBeforeValues(), true),
			'status' => $this->getStatus(),
			'notes' => $this->getNotes(),
		];
	}
}
