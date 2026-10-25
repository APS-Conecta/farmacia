<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's whole schema in one step: the two vocabularies, the medication
 * record with its three child tables, and the two staging tables the import
 * reads and writes. Farmacia is greenfield — there is no earlier shape to
 * evolve, so one changeSchema beats a migration chain nothing would ever walk.
 *
 * Cross-reference columns are plain BIGINT with named indexes and no foreign
 * keys, on the territorio convention: an app must run on MySQL, SQLite and
 * PostgreSQL alike (the suite runs PostgreSQL, but a lab app graduates to a
 * tarball any instance installs), and the referential guarantees live in the
 * service layer instead — write-time reference checks, in-use vocabulary
 * guards, and a delete() that refuses. What a foreign key would have caught
 * silently becomes a sentence a person can read.
 *
 * Every NOT NULL column carries a default unless the write path always sets
 * it explicitly (lesson #74, territorio 2026-08-17): Entity::setter skips
 * marking a field whose value equals the current one, so a NOT NULL column
 * whose only guarantee was "the seed writes 0" could never be written back.
 *
 * Reserved words: `codigo` (not `key`), `before_values` (not `before`),
 * `line_number` (not `row_number`) — MySQL reserves all three originals, and
 * territorio met this class of problem first with `limit`.
 */
class Version000100Date20260917000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('farmacia_category')) {
			$table = $schema->createTable('farmacia_category');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'farm_cat_slug_idx');
		}

		// The ABASTECE vocabulary: identical shape to the categories, and
		// deliberately never seeded — which channels exist is instance
		// configuration (instalador#ADR-0009), so each clinic defines its own.
		if (!$schema->hasTable('farmacia_abastece')) {
			$table = $schema->createTable('farmacia_abastece');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'farm_abast_slug_idx');
		}

		if (!$schema->hasTable('farmacia_medication')) {
			$table = $schema->createTable('farmacia_medication');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// The file identity, unique per instance: imports match on it, the
			// CSV round-trip regroups on it, and a planilla's duplicate rows
			// collapse against it.
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('producto', Types::STRING, ['notnull' => true, 'length' => 255]);
			// DCI / principio activo. Null is meaningful: searchable identity
			// that some planillas never filled in.
			$table->addColumn('farmaco', Types::STRING, ['notnull' => false, 'length' => 255]);
			// Strength as typed ("500", "500/1000") — not a number: no
			// arithmetic ever runs over it, and a fraction would lie.
			$table->addColumn('mg', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('presentacion', Types::STRING, ['notnull' => false, 'length' => 255]);
			// Null = sin programa.
			$table->addColumn('programa', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('trazador', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('indicacion', Types::TEXT, ['notnull' => false]);
			$table->addColumn('dosificacion', Types::TEXT, ['notnull' => false]);
			$table->addColumn('precauciones', Types::TEXT, ['notnull' => false]);
			$table->addColumn('observaciones', Types::TEXT, ['notnull' => false]);
			// Anticholinergic risk in older adults: a flag the consult needs at
			// a glance, not a grade anyone distinguishes at that moment.
			$table->addColumn('riesgo_colinergico_am', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// A | B | C | D | X — the FDA pregnancy categories. Null = sin dato:
			// a missing category is not a safe one, and the pregnancy lens
			// shows exactly the rows where it is set.
			$table->addColumn('fda_embarazo', Types::STRING, ['notnull' => false, 'length' => 1]);
			$table->addColumn('ajuste_renal', Types::TEXT, ['notnull' => false]);
			$table->addColumn('category_id', Types::BIGINT, ['notnull' => true]);
			// The same spec territorio's retirement arrived with: a NULL would
			// match neither the live read (retired = false) nor the retired
			// read (retired = true), hiding the record from both lists.
			$table->addColumn('retired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// Stamped by every write path; never defaulted. The import's
			// touched-since guard compares it against a batch's applied_at,
			// and the retired list orders by it.
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['codigo'], 'farm_med_codigo_idx');
			$table->addIndex(['category_id'], 'farm_med_cat_idx');
			$table->addIndex(['retired'], 'farm_med_retired_idx');
		}

		// One GES problem number per row: a medication covers a problem at
		// most once, and the pair index is what makes that true.
		if (!$schema->hasTable('farmacia_medication_ges')) {
			$table = $schema->createTable('farmacia_medication_ges');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('medication_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('number', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['medication_id'], 'farm_ges_med_idx');
			$table->addUniqueIndex(['medication_id', 'number'], 'farm_ges_pair_idx');
		}

		// Free-text restrictions: distinct rows are distinct facts, so no
		// unique constraint. Named `restriction` — TEXT is not reserved, so
		// this is a choice, not an escape: a column named `text` reads like a
		// placeholder for whatever the table is about, and this holds one
		// specific thing.
		if (!$schema->hasTable('farmacia_medication_restriction')) {
			$table = $schema->createTable('farmacia_medication_restriction');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('medication_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('restriction', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['medication_id'], 'farm_rest_med_idx');
		}

		// A channel either stocks the medication or does not: a duplicate row
		// would carry no information, so the pair is unique.
		if (!$schema->hasTable('farmacia_medication_abastece')) {
			$table = $schema->createTable('farmacia_medication_abastece');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('medication_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('channel_id', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['medication_id', 'channel_id'], 'farm_abast_pair_idx');
		}

		// One import batch: staged, then applied or discarded. Provenance is
		// the row — who, when, from which file — and the counters describe
		// what the apply did. No revision columns: farmacia has no cursor.
		if (!$schema->hasTable('farmacia_import')) {
			$table = $schema->createTable('farmacia_import');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// Null where nobody was behind it: an occ run names itself --actor.
			$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('started_at', Types::DATETIME, ['notnull' => true]);
			// staged | applied | discarded — the staging lifecycle. A batch
			// that has been applied can still be reverted; discarded means it
			// never landed.
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'staged']);
			// Null while the batch is still staged; the touched-since guard
			// reads it ("was this medication hand-edited after the apply?").
			$table->addColumn('applied_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('original_filename', Types::STRING, ['notnull' => true, 'length' => 255]);
			// SHA-256 hex: what the review screen shows, and what "the same
			// file" means when the same person uploads it twice.
			$table->addColumn('file_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('skipped', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('duplicates', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('unsupported', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('unchanged', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// The undo chain: a revert is itself a batch, pointing at the one
			// it undid. Null for an ordinary import.
			$table->addColumn('reverts_id', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['reverts_id'], 'farm_import_reverts_idx');
		}

		// One staged row: the review surface. Three renames this table owes
		// the reserved-word precedent territorio met with `limit`
		// (Feature.php's PROPERTY_OF): `codigo` (not `key`: reserved on
		// MySQL), `before_values` (not `before`: a trigger keyword there), and
		// `line_number` (not `row_number`: ROW_NUMBER became reserved on
		// MySQL 8.0.2). The Medication and ImportBatch entities that read
		// these tables arrive with their own slices; the schema exists first
		// so the app enables against one migration, not a chain.
		if (!$schema->hasTable('farmacia_import_row')) {
			$table = $schema->createTable('farmacia_import_row');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('batch_id', Types::BIGINT, ['notnull' => true]);
			// The file line, so review errors can name the row a person can
			// find in the planilla.
			$table->addColumn('line_number', Types::INTEGER, ['notnull' => true]);
			// The código this row matches on — the same uniqueness the
			// medication table enforces.
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 128]);
			// The incoming row as normalized JSON: every field, the multi-value
			// cells split, the vocabulary decisions not yet made.
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			// create | update | unchanged | skipped | duplicate | unsupported —
			// what the apply would do, decided at stage time.
			$table->addColumn('disposition', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'create']);
			// The stored row's values before the apply overwrites them, so a
			// revert can overlay them back. Null for creates.
			$table->addColumn('before_values', Types::TEXT, ['notnull' => false]);
			// staged | unresolved-vocabulary | invalid — the review's triage.
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 24, 'default' => 'staged']);
			// The sentence saying why: which vocabulary value did not match,
			// which cell would not parse.
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['batch_id'], 'farm_row_batch_idx');
			$table->addUniqueIndex(['batch_id', 'line_number'], 'farm_row_pair_idx');
		}

		return $schema;
	}
}