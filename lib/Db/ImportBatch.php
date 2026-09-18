<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One import run, staged for review — CONTEXT.md's «Lote».
 *
 * Farmacia's batch is what territorio's never was: the review surface
 * itself. There, the batch was a post-hoc ledger whose row-level truth
 * lived in the RevisionLog; here there is no revision machinery, so the
 * batch and its rows ARE the run's truth — the rows say what the apply
 * would do, and the batch says who staged it, when, from which file.
 *
 * The lifecycle is three states: `staged` (reviewable — rows can still be
 * decided), `applied` (landed), and `discarded` (never landed). `appliedAt`
 * is what the revert's touched-since guard will read — “was this
 * medication hand-edited after the apply?” — and it stays null until the
 * apply stamps it.
 *
 * The counters are the stage-time plan, tallied by disposition; the
 * review's decisions change rows, not these numbers, and the apply will
 * rewrite them with what it actually did. `revertsId` stays null for an
 * ordinary import: it is the undo chain's provenance, and a revert is
 * itself a batch.
 *
 * @method ?string getActor()
 * @method void setActor(?string $actor)
 * @method ?\DateTime getStartedAt()
 * @method void setStartedAt(?\DateTime $startedAt)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?\DateTime getAppliedAt()
 * @method void setAppliedAt(?\DateTime $appliedAt)
 * @method string getOriginalFilename()
 * @method void setOriginalFilename(string $originalFilename)
 * @method string getFileHash()
 * @method void setFileHash(string $fileHash)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 * @method int getSkipped()
 * @method void setSkipped(int $skipped)
 * @method int getDuplicates()
 * @method void setDuplicates(int $duplicates)
 * @method int getUnsupported()
 * @method void setUnsupported(int $unsupported)
 * @method int getUnchanged()
 * @method void setUnchanged(int $unchanged)
 * @method ?int getRevertsId()
 * @method void setRevertsId(?int $revertsId)
 */
class ImportBatch extends Entity {
	public const STATE_STAGED = 'staged';
	public const STATE_APPLIED = 'applied';
	public const STATE_DISCARDED = 'discarded';

	protected $actor = null;
	// Null until the staging sets it: every write path sets it explicitly, so
	// no stored row is ever without one (the updatedAt doctrine).
	protected $startedAt = null;
	protected $state = self::STATE_STAGED;
	protected $appliedAt = null;
	protected $originalFilename = '';
	protected $fileHash = '';
	protected $created = 0;
	protected $updated = 0;
	protected $skipped = 0;
	protected $duplicates = 0;
	protected $unsupported = 0;
	protected $unchanged = 0;
	protected $revertsId = null;

	public function __construct() {
		$this->addType('startedAt', 'datetime');
		$this->addType('appliedAt', 'datetime');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
		$this->addType('skipped', 'integer');
		$this->addType('duplicates', 'integer');
		$this->addType('unsupported', 'integer');
		$this->addType('unchanged', 'integer');
		$this->addType('revertsId', 'integer');
	}

	/**
	 * What the review and the batches list show. The rows ride BESIDE this
	 * document, not inside it — the batch is the run, the rows are its
	 * content, and one query fetches each.
	 *
	 * @return array<string, mixed>
	 */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'actor' => $this->getActor(),
			'startedAt' => $this->getStartedAt()?->format('Y-m-d H:i:s'),
			'state' => $this->getState(),
			'appliedAt' => $this->getAppliedAt()?->format('Y-m-d H:i:s'),
			'originalFilename' => $this->getOriginalFilename(),
			'fileHash' => $this->getFileHash(),
			'created' => $this->getCreated(),
			'updated' => $this->getUpdated(),
			'skipped' => $this->getSkipped(),
			'duplicates' => $this->getDuplicates(),
			'unsupported' => $this->getUnsupported(),
			'unchanged' => $this->getUnchanged(),
			'revertsId' => $this->getRevertsId(),
			'isRevert' => $this->getRevertsId() !== null,
		];
	}
}
