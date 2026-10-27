# ADR-0001 — The planilla is a projection, not the data

- **Status:** accepted (2026-10-04) — recording date; the decision is the shape the app shipped with, taken before this record
- **Affects:** `farmacia/lib/Service/ExportService.php`, `farmacia/lib/Service/ImportService.php`, `farmacia/lib/Service/ApplyService.php`, `farmacia/lib/Db/ImportBatch.php`, `farmacia/lib/Db/ImportRow.php`

## Context

The app has one database, and it is the arsenal. The people around a CESFAM also live in
spreadsheets: a planilla is emailed, hand-corrected in Excel, kept as the "real" list on someone's
desktop. Two designs were possible when the import/export work landed:

1. the CSV is the thing itself — importing replaces the data, and the app is a viewer; or
2. the database is the source of truth, and the CSV is a projection of it: a transport format
   that can always be regenerated and is never authoritative.

Design 1 fails quietly: two edits in two places diverge, and the newer one loses. The suite had
already paid for that shape elsewhere — territorio's export answered a *filtered* set, and a
narrowed copy quietly stopped being the record of truth.

## Decision

**The database is the source of truth; the planilla is a projection.** Concretely:

- `ExportService::export()` (`farmacia/lib/Service/ExportService.php:29`) writes the whole live
  arsenal through the one definition every consumer reads (`MedicationService::listAll`), the
  vocabulary labels the planilla speaks, and the writer's dialect. Nothing is filtered, because a
  narrowed copy would quietly stop being the vademécum. Retired records are deliberately absent —
  a re-import would only skip them anyway.
- `ImportService::stage()` writes no medication: it reads the file, resolves its words and judges
  every row (`create`, `update`, `unchanged`, `skipped`, `duplicate`, `unsupported`), storing the
  judgement in `farmacia_import` / `farmacia_import_row` with the file's hash, its actor and its
  counters (`ImportBatch`, `farmacia/lib/Db/ImportBatch.php:59`).
- `ApplyService::apply()` (`farmacia/lib/Service/ApplyService.php:63`) is the only place the
  medication tables move, in one transaction over the whole walk: a batch that fails at row 300
  rolls back to the review the person approved. `revert()` walks the same rows back, using the
  before-images captured at apply time, and is itself a batch (`reverts_id`).

## Consequences

- The export is always regenerable and always complete: losing the file costs nothing.
- A round trip is a reviewable operation, not a leap of faith — a re-imported export comes back
  `unchanged`, row by row, and the round trip is pinned by test.
- A hand-typed planilla folds the same way an exported one does: labels fold to slugs, unknown
  words surface as review decisions instead of silently matching.
- Retiring stays a local decision: an import cannot revive a retired record, and an export cannot
  carry one out.
- Nothing here is an audit log: the batch record exists so a review can be reopened and undone,
  not to answer "who changed what when" — `updated_at` and the retired flag are the record's own
  trace; who made a change is the log line of
  [ADR-0005](0005-an-audited-action-leaves-one-line-in-nextcloud-log.md).
