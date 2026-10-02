# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work; the 🗡️ Breaker agent starts here.*

## Data this repository holds

- **The CESFAM's medication formulary** and its clinical-safety information: FDA pregnancy category,
  renal adjustment, anticholinergic risk in older adults, GES numbers, restrictions, supply channels
  (`abastece`), plus staged CSV imports. Tables: `farmacia_medication`, `farmacia_category`,
  `farmacia_abastece`, `farmacia_medication_abastece`, `farmacia_medication_ges`,
  `farmacia_medication_restriction`, `farmacia_import`, `farmacia_import_row` (`lib/Migration/`).
- **No patient data and no stock.** The README says "No contiene datos de pacientes; no gestiona
  stock" (`README.md:8`).

## What an attacker wants

1. **Patient harm through tampered clinical-safety data** — a changed pregnancy category, renal
   adjustment or restriction that a clinician then trusts. Integrity is this repository's crown jewel.
2. **Patient data elsewhere in the same Nextcloud.** The path is acting in a staff session: XSS
   through stored medication names or notes, or through an imported CSV, or a forged request.
3. **Silent mass changes** through the import pipeline: upload, decide, apply or revert without review.
4. **Denial of service** through oversized or malformed CSV uploads.

## Entry points

| Entry point | Who can call it | Guard |
|---|---|---|
| `GET /` (`PageController`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET) |
| Medications: `POST /medications`, `PUT/DELETE /medications/{id}`, `POST /medications/{id}/restore`, `GET /medications/retired` (`MedicationController`) | **every logged-in user** | `#[NoAdminRequired]`; CSRF on writes |
| Categories: `POST`, `PUT/DELETE /categories/{slug}`; supply channels: `POST`, `PUT/DELETE /abastece/{slug}` | every logged-in user | `#[NoAdminRequired]`; CSRF on writes |
| Import: `POST /import` (upload), `GET /import/batches`, `GET /import/{id}`, `POST /import/rows/{id}/decision`, `POST /import/{id}/apply`, `/discard`, `/revert` (`ImportController`) | every logged-in user | `#[NoAdminRequired]`; an upload size cap (`ImportController.php:35`); `lib/Service/CsvReader.php`; channel ids validated (farmacia #4) |
| Export: `GET /export` (`ExportController::plain`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` — a GET download needs it (`ExportController.php:27`); `lib/Service/CsvWriter.php` (watch for CSV formula injection) |
| OCS `GET /api/v1/medicamentos`, `/medicamentos/{id}`, `POST`, `PUT /medicamentos/{id}` (`ApiController`) | logged-in users | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| CLI `occ farmacia:import` (`lib/Command/Import.php`) | server operators | shell access |

## Trust boundaries

- **Uploaded CSV planillas are untrusted:** size, encoding, row count, field content, and formula
  prefixes (`=`, `+`, `-`, `@`) that become dangerous when the export is opened in a spreadsheet.
- **Stored text is untrusted on output.** `src/` has no `v-html`, and Vue text binding escapes it.
- **Every logged-in user can change clinical-safety data.** What protects it today is the import
  review steps (decide, then apply) and soft deletion (retire and restore), not roles.
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens.

## Accepted risks

None recorded. Open question for dani: should edits to clinical-safety fields (pregnancy category,
renal adjustment, restrictions) stay open to every logged-in user? Today they are, and no ADR records
that as a decision.
