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
| Import: `POST /import` (upload), `GET /import/batches`, `GET /import/{id}`, `POST /import/rows/{id}/decision`, `POST /import/{id}/apply`, `/discard`, `/revert` (`ImportController`) | every logged-in user | `#[NoAdminRequired]`; an 8 MiB upload cap (`ImportController.php:38`); `lib/Service/CsvReader.php`; channel ids validated (farmacia #4) |
| Export: `GET /export` (`ExportController::plain`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` — a GET download needs it (`ExportController.php:27`); formula cells are defused with aps-common's `Csv::text` (`lib/Service/CsvWriter.php:135`) and restored on import by `CsvGrammar::unquote` (`lib/Service/CsvGrammar.php:82`). Test the edges: the round trip, a cell that already starts with `'=`, every exported field |
| OCS `GET /api/v1/medicamentos`, `/medicamentos/{id}`; **`POST /medicamentos`, `PUT /medicamentos/{id}`** (`ApiController`) | every logged-in user | `#[NoAdminRequired]`; writes limited to 20 a minute per user (`lib/Controller/ApiController.php:62-64`); the `OCS-APIRequest` header is the CSRF protection (`:29-35`). **Writes change medications directly, with no review step** (`:175`, `:222`): the most direct path to goal 1. |
| CLI `occ farmacia:import` (`lib/Command/Import.php`) | server operators | shell access |

## Trust boundaries

- **Uploaded CSV planillas are untrusted:** size, encoding, row count and field content. Formula
  prefixes (`=`, `+`, `-`, `@`, tab, CR) are defused on export and restored on import (see the Export row).
- **Stored text is untrusted on output.** `src/` has no `v-html`, and Vue text binding escapes it.
- **Every logged-in user can change clinical-safety data.** Imports pass a staged review (decide, then
  apply); removals are soft (retire and restore). Direct edits, through the medication form
  (`PUT /medications/{id}`) or the OCS API, have no review step.
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens.

## Accepted risks

- **Every user who can open the app may change the formulary, clinical-safety fields included.** The
  decision is recorded in `appinfo/routes.php`: medications (`:16-17`, "the safety net is retirement,
  not permissions"), the vocabulary (`:30-36`, the in-use guard) and imports (`:44-46`, the staged
  review). A finding that "any user can edit X" restates this decision; it is not a vulnerability.

Open question for dani: should this become an ADR, and should clinical-safety fields (pregnancy
category, renal adjustment, restrictions) be excluded from it?
