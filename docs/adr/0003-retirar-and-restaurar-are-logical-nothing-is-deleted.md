# ADR-0003 — Retirar and restaurar are logical; nothing is deleted

- **Status:** accepted (2026-10-04) — recording date; the decision predates it, in the schema and the controller since the first slices
- **Affects:** `farmacia/lib/Controller/MedicationController.php`, `farmacia/lib/Service/MedicationService.php`, `farmacia/lib/Db/Medication.php`, the import's skip rule

## Context

A formulary changes: a medication leaves the arsenal when the center stops using it, and comes
back when it starts again — with its whole clinical record (indication, dosification,
precauciones, FDA category, renal adjustment, GES numbers, restrictions). A hard delete is the
wrong shape twice over: it destroys a record whose recovery is a foreseeable event, and it makes
every "remove" irreversible in a domain where the only constant is that decisions get revisited.

The surface still needs the verb REST: the browser's list actions and the route table read
naturally with it.

## Decision

**Retiring sets a flag; nothing is ever deleted.**

- `DELETE /medications/{id}` maps to `MedicationController::destroy()`
  (`farmacia/lib/Controller/MedicationController.php:37-39`), which calls
  `MedicationService::retire()` (`farmacia/lib/Service/MedicationService.php:160`) — never a
  mapper's `delete`. The `retired` column is a boolean on the record itself
  (`farmacia/lib/Db/Medication.php:71`, typed at `:81`), and the children — GES, restrictions,
  channels — stay exactly as they are.
- `POST /medications/{id}/restore` (`farmacia/lib/Controller/MedicationController.php:42-44`,
  `MedicationService::restore()` at `farmacia/lib/Service/MedicationService.php:169`) clears the
  flag; the record returns with everything it had.
- The import honours it: a planilla row for a retired record is judged `skipped` with the
  sentence «el registro está retirado: la importación no lo toca» — an import must not undo a
  local retirement. And the revert of an applied batch retires what that batch created, never
  deletes it, so «Ver retirados» offers everything back
  (`farmacia/lib/Service/ApplyService.php:39-52`).

## Consequences

- The live lists filter on the flag; «Ver retirados» is the recovery screen, and restoring is
  self-service — no administrator, no backup restore.
- The export is the live arsenal only (ADR-0001); retired records stay reachable in the app,
  which is where recovering them belongs.
- History is never lost by ordinary use: the worst a mistake costs is one «Restaurar».
- The route name (`destroy`) is the one dishonest word in the file, kept because the HTTP verb
  and the route table are public contract; the code behind it and this record say what it does.
