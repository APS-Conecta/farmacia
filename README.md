# Farmacia

## What this is

Nextcloud 34 app for APS Conecta Gestión: the CESFAM's medication vademécum — every medication the
center prescribes and dispenses, with its coverage (GES, canales de abastece) and its clinical-safety
information (categoría FDA en embarazo, ajuste renal, riesgo colinérgico en adulto mayor).
**It holds no patient data and manages no stock.** The arsenal is a reference of what the CESFAM
uses, not an inventory of what is on the shelf: nothing in it expires and nothing is counted
([ADR-0004](docs/adr/0004-the-arsenal-is-a-vademecum-not-a-stock.md)). UI in Spanish, everything
else English ([`AGENTS.md`](AGENTS.md)). AGPL-3.0-or-later.

Every logged-in user can read **and edit** the arsenal. The safety net is not permissions: it is
reversible retirement and the staged, row-by-row review every import goes through — a recorded
decision, with its reasons, in [`docs/threat-model.md`](docs/threat-model.md).

| View | What it is | Where it comes from |
| --- | --- | --- |
| **Arsenal Farmacológico** | Every live medication, searched by producto, fármaco (DCI) or categoría | This app's own tables |
| **Fármacos en embarazo** | The same arsenal, opened at the FDA-informed rows | The lens rule: FDA category set ([ADR-0002](docs/adr/0002-a-lens-changes-the-initial-filter-not-the-dataset.md)) |
| **Ajuste farmacológico ERC** | The same arsenal, opened at the renal-adjusted rows | The lens rule: ajuste renal set |
| «Ver retirados» | The recovery screen: retired records, restorable in place | The `retired` flag ([ADR-0003](docs/adr/0003-retirar-and-restaurar-are-logical-nothing-is-deleted.md)) |
| «Importar planilla» | The two-phase CSV import: staged review, decide, apply, revert | `farmacia_import` / `farmacia_import_row` ([ADR-0001](docs/adr/0001-the-planilla-is-a-projection-not-the-data.md)) |
| «Exportar planilla» | The whole live arsenal as one CSV, reimportable | The same grammar, the other direction |

The manual for staff, in Spanish and in the screens' own words, is [`docs/manual.md`](docs/manual.md).

## Status

**0.11.1, tagged 2026-09-18.** Everything above is built and shipped: the arsenal and its three
lenses, the medication form, retirar/restaurar, both vocabularies (16 therapeutic categories
seeded at install; canales de abastece empty by design — each CESFAM defines its own), the
two-phase planilla import with its review, the export, the `occ farmacia:import` door, and the
OCS contract. `main` is 25 commits past the tag — the hardening pass, the org-template CI, the
typed OCS payload, the threat model, the agent roster and this documentation rewrite — none of it
released: `gestion` bumps the vendored tarball at a milestone rather than at every tag.

Not done, deliberately: no admin section and no per-group delegation (any logged-in user can
edit — the threat model records the decision and its open question), and no GitHub release pages
for the tags, for the same reason estadistica has none.

## Quickstart

On a machine with Docker, beside a checkout of [`aps-common`](https://github.com/APS-Conecta/aps-common)
(`../aps-common`). This stands up a throwaway Nextcloud 34 with PostgreSQL 18 on port 8090; it is
not the gestion stack.

1. **Start the instance** (in this repo): `make instance-up`. It enables the app — which runs the
   migration and the seed's install repair step — restarts the web workers (see *Development*),
   and ends with `Instance ready at http://localhost:8090 (admin / admin)`.
2. **Check the wiring**: `make smoke`. It proves the OCS route answers 412 without the
   `OCS-APIRequest` header and 200 with it, that a write goes through with no CSRF token, the
   400/404 refusals, the icon, the `.htaccess` holes, the committed bundle, and that
   `openapi.json` regenerates without drift. It ends with `All good.`
3. **Open `http://localhost:8090/apps/farmacia`** as `admin` / `admin`. The list says «Todavía no
   hay medicamentos», which is right: import a planilla («Importar planilla») or add the first
   medication by hand («Agregar medicamento»).

`make instance-clean` removes the instance and its database.

## How it works

- **One write path.** Every door — the sidebar's «Guardar», the OCS API, an applied batch — goes
  through `MedicationService`, so no two doors can disagree about what a record is.
- **The planilla is a projection** ([ADR-0001](docs/adr/0001-the-planilla-is-a-projection-not-the-data.md)).
  The database is the source of truth; the export is the whole live arsenal through one
  definition, and an import stages every row for review before anything lands.
- **Nothing is deleted** ([ADR-0003](docs/adr/0003-retirar-and-restaurar-are-logical-nothing-is-deleted.md)).
  Retiring is a flag; restoring is one button; an import never revives a retired record.
- **The staged review.** Staging reads and judges each row (`create`, `update`, `sin cambios`,
  `omitido`, `duplicado`, `con problemas`) without writing; a row whose words the vocabulary does
  not know waits for a decision instead of silently matching; apply lands the batch in one
  transaction — a failure rolls back to the review the person approved; revert walks the rows
  back and refuses to discard a record someone edited after the apply.
- **Words fold, ids never travel.** The planilla carries labels that fold to slugs
  (aps-common's `Text`); the OCS contract carries ids. A word renamed after a planilla was
  written resurfaces as a review decision, not a silent match.
- **Uploads are capped at 8 MiB**; formula-cell prefixes are defused on export and restored on
  import, so the CSV opens in Excel without executing anything.

## For other apps

OCS, with `OCS-APIRequest: true` — **not optional**, Nextcloud answers `412` without it — under
`/ocs/v2.php/apps/farmacia/api/v1/medicamentos`: `GET` the arsenal (narrowed server-side by
`?search=`, `?categoria=<slug>`, `?gestacion=A–X`, `?renal_ajuste=1`), `GET` one medication,
`POST` a new one, `PUT` an update. Rate limits: 120 reads and 20 writes a minute per user — the
bulk path is the planilla import, which has its own staged review, and a script hammering writes
has missed the door built for it.

**The write routes change medications directly, with no review step** — the most direct path to
clinical-safety data there is; [`docs/threat-model.md`](docs/threat-model.md) records why that
is accepted. A key a medication does not have is absent, never null. What cannot be understood
is refused (400/404), never answered with a confidently empty list.

The contract is [`openapi.json`](openapi.json), generated by `make openapi` from the controller,
never by hand; gestion's [`docs/CONTRACTS.md`](https://github.com/APS-Conecta/gestion/blob/main/docs/CONTRACTS.md)
lists it.

## Development

- `make test`: the PHPUnit unit suite in a bare PHP container, no Nextcloud.
- `npm test` and `npm run lint-js`: Vitest and ESLint over `src/`.
- `make instance-up && make smoke`: the wiring against the throwaway instance (the quickstart's
  steps 1–2).
- The integration suite runs inside that instance against its real PostgreSQL — the Makefile's
  `integration` target (needs `make deps` and `make instance-up` first; no CI job runs it).
- `make aps-drift`: the vendored `aps-common` copies are current (`make aps-sync` refreshes
  them).
- `npm run build`, then commit `js/`: the bundle is committed, and CI fails when it differs from
  `src/`.

CI (`.github/workflows/ci.yml`) runs the aps-common drift check, lint, the unit suites, the
bundle check, `make test`, and `make smoke` on a cold instance. One trap already paid for:
**restart the web workers after `occ app:enable`** — they cache the routes and the app state in
APCu for an hour, and every route answers 404 until they restart; `make instance-up` does it for
you.

## Installation

A clinic gets Farmacia through gestion, not from the app store: gestion's provisioning unpacks
the tarball it vendors under `provisioning/apps/farmacia/`, pinned by its `VENDOR` file, and an
install needs no network for the app itself — the org-wide decision is
[gestion ADR-0003](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0003-this-stack-ships-a-custom-app.md).
Nextcloud 34, PHP 8.2–8.5 (`appinfo/info.xml`); the dev harness runs PostgreSQL 18.

To develop, clone this repo into the gestion checkout and let provisioning enable it (both
commands run from the gestion checkout):

    git clone https://github.com/APS-Conecta/farmacia.git apps/farmacia
    make fix-mount-perms && make seed

With the directory present, provisioning leaves the live clone alone instead of unpacking the
tarball over it.

## Configuration

**Nothing to configure.** The app reads no configuration at all — no `IConfig` anywhere in
`lib/`, no app-config keys, no admin section, nothing in `config.php`. The instance's own data
is its two vocabularies («Categorías», «Abastece»), edited in the app itself; and access is not
a setting either: every logged-in user can read and edit, a recorded decision
([`docs/threat-model.md`](docs/threat-model.md)) whose safety nets are retirement and the staged
import review. The one door outside the browser is the occ command
([the occ reference](https://docs.nextcloud.com/server/34/admin_manual/occ.html)):

    occ farmacia:import <archivo.csv> --actor=<name> [--apply]

## Licence

AGPL-3.0-or-later — see [`LICENSE`](LICENSE). Not a free choice: the bundles under `js/` compile
`@nextcloud/vue` (AGPL-3.0-or-later) and Nextcloud packages under GPL-3.0-or-later, so the
copyleft is inherited from what it links —
[gestion ADR-0010](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md)
records the organisation-wide decision. Because AGPL §13 covers network use, staff who use this
app over the intranet are owed its source on request.

Third-party components and their notices: [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).
