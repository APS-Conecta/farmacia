# ADR-0005 — An audited action leaves one line in nextcloud.log

- **Status:** proposed
- **Affects:** `farmacia/lib/Controller/MedicationController.php`, `farmacia/lib/Controller/ApiController.php`, `farmacia/lib/Controller/ImportController.php`

## Context

ADR-0001 keeps the batch record and a medication's own fields from being an audit log:
`updated_at` and the retired flag say that a record changed, not who changed it. The
clinical-safety fields (FDA pregnancy category, renal adjustment, anticholinergic risk in older
adults) are edited directly, in the sidebar and over OCS, with no review step in between. So who
changed what, and when, needs an answer outside the record.

## Decision

**Each audited action writes one `HIPAA_AUDIT` line to `nextcloud.log`.**

- Sidebar create, update, retire and restore (`MedicationController`), OCS create, update and
  read (`ApiController`), and import apply and revert (`ImportController`) each write one line
  through the app's `Psr\Log\LoggerInterface` at `warning`: the lowest level an instance at the
  default `loglevel` 2 keeps.
- The line carries the action, the actor's uid, the `medication_id` and código (or the
  `batch_id`), the remote IP and the UTC time.
- The logger is a required constructor dependency: the container supplies it, or the controller
  is not built.

## Not taken

- Nextcloud's admin_audit app (`CriticalActionPerformedEvent`): the suite installer does not
  enable admin_audit.
- A table: the batch record stays what ADR-0001 says it is.
- No trail: a direct edit of a clinical-safety field would leave no record of who made it.

## Consequences

- Retention is `nextcloud.log`'s: it rotates at `log_rotate_size` (100 MB by default) into a
  single `.1` file, which each rotation replaces. A sustained OCS read load can rotate older lines
  out within days; keeping them longer means log shipping, an ops decision.
- Nextcloud already stamps `user` and `remoteAddr` on every entry; the line makes them explicit
  on routine events, and the log is admin-only.
- Routine events show as warnings in the admin log view and in any alerting keyed on warnings.
