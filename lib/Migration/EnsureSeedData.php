<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds the sixteen therapeutic categories an install starts with.
 *
 * A repair step, NOT a migration's `postSchemaChange`, and the distinction is
 * not academic: `MigrationService::executeStep()` skips both `preSchemaChange`
 * and `postSchemaChange` when called with `$schemaOnly`, which is exactly how
 * a first-time `occ app:enable` applies an app's schema. Seeds written there
 * run on every upgrade and on no fresh install — the case that matters most.
 *
 * Registered for both `install` and `post-migration` in `appinfo/info.xml`.
 * Guarded by "the table already has rows", so running it repeatedly is safe
 * and a clinic that renamed or removed a category does not get it silently
 * restored: the vocabulary is the clinic's data the moment it is seeded.
 *
 * Nothing seeds farmacia_abastece. Which supply channels a CESFAM stocks
 * through is instance configuration (ADR-0013): URGENCIAS and CECOSF are
 * real places in some communes and meaningless in others, so the table starts
 * empty and each clinic types its own — or imports one, which lands in the
 * review step as a decision, never as a silent new row.
 */
class EnsureSeedData implements IRepairStep {
	/**
	 * The therapeutic categories a vademécum starts with — the sixteen the
	 * domain uses, expressed as slug => label. A starting point, not a fixed
	 * list: a Category is editable data, and the known gaps (there is no
	 * ophthalmic category, and "Cremas" may want to widen into
	 * "Dermatológicos") are post-seed edits, not code changes.
	 *
	 * The slugs are pre-folded exactly the way the vocabulary slice's slugFor
	 * derives them from the labels (accents off, case down, everything else a
	 * separator) — territorio's own residual miss (slugFor('Deporte y
	 * recreación') → deporte_y_recreacion vs a seed slug deporte_recreacion
	 * that never matches) is the failure class this forecloses: import
	 * classification folds a planilla's cells with the same rule, so a label
	 * typed or imported always finds the seed's row.
	 *
	 * @var array<string, string>
	 */
	private const CATEGORIES = [
		'analgesicos_antipireticos_antiinflamatorios' => 'Analgésicos / antipiréticos / antiinflamatorios',
		'anticonceptivos_incluye_preservativos' => 'Anticonceptivos (incluye preservativos)',
		'antihistaminicos' => 'Antihistamínicos',
		'antimicrobianos' => 'Antimicrobianos',
		'antitusivos_expectorantes_mucoliticos' => 'Antitusivos / expectorantes / mucolíticos',
		'cardiovasculares_hta_dm_dlp' => 'Cardiovasculares (HTA · DM · DLP)',
		'corticoides' => 'Corticoides',
		'cremas_vaselinas_bengue_pasta_lassar' => 'Cremas (vaselinas, bengue, pasta Lassar)',
		'dental' => 'Dental',
		'digestivos' => 'Digestivos',
		'endocrinos_tiroides' => 'Endocrinos (tiroides)',
		'relajante_muscular' => 'Relajante muscular',
		'respiratorios_aerocamaras_e_inhaladores' => 'Respiratorios (aerocámaras e inhaladores)',
		'salud_mental_incluye_tto_oh' => 'Salud mental (incluye tto OH)',
		'vitaminas' => 'Vitaminas',
		'otros' => 'Otros',
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getName(): string {
		return 'Farmacia: ensure the therapeutic categories exist';
	}

	public function run(IOutput $output): void {
		$this->ensureCategories($output);
	}

	private function ensureCategories(IOutput $output): void {
		if (!$this->db->tableExists('farmacia_category') || $this->hasRows('farmacia_category')) {
			return;
		}

		foreach (self::CATEGORIES as $slug => $label) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('farmacia_category')->values([
				'slug' => $insert->createNamedParameter($slug),
				'label' => $insert->createNamedParameter($label),
			])->executeStatement();
		}

		$output->info(sprintf('Farmacia: seeded %d therapeutic categories', count(self::CATEGORIES)));
	}

	private function hasRows(string $table): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}
}