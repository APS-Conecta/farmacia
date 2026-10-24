# AGENTS.md — Farmacia

The CESFAM's medication vademécum, a Nextcloud app: the formulary with its clinical-safety information (FDA pregnancy category, renal adjustment, anticholinergic risk in older adults) — no patient data, no stock.

## Working rules

- **Names:** code is English, but a domain term keeps the spelling `CONTEXT.md` gives it
  (`AbasteceController`, not `SupplyController`). Rename an existing name only inside code a change already
  touches, and never a table, column, route, controller action (an OpenAPI operation id) or API field.
  `Medication*` (classes and tables) is this repo's established code spelling of *Medicamento*: keep it.
- **Threat model:** read `docs/threat-model.md` before any security work.
- **Gate before opening a PR** — what `ci.yml` and `release.yml` run. This repo uses **npm**, not pnpm:
  `git clone --depth 1 https://github.com/APS-Conecta/aps-common.git ../aps-common` if it is missing — aps-common is
  private, so if the clone fails, skip the steps that need it and list them as not run; never edit a workflow to work around it;
  `make aps-drift`; `npm ci`; `npm run lint-js`; `npm test`; `npm run build`, then assert `js/farmacia-main.js` and its
  `.LICENSE.txt` exist (never committed — the release packs them); then
  `make test` — after `npm test`, since it copies a node:test file into `vendor/` that `npm test` would then fail on;
  `make instance-up && make smoke`, then `make instance-clean`.
  A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `npm run build`**: `js/` is build output, ignored — nothing to commit. The release
  workflow builds the bundle before packing, and CI fails a PR whose build produces no bundle.
  Never commit `*.js.map` or `node_modules/`.
- **Never edit** `package-lock.json`, `composer.lock`, the dependency lists
  in `package.json`/`composer.json`, `openapi.json` (generated from `ApiController` by `scripts/openapi.sh`;
  `make smoke` fails on drift), or the aps-common copies `src/aps/`, `vendor/aps/common/src/`, `tests/stubs/OC/Hooks/Emitter.php`.
- **Strings:** what a person reads — templates, exception messages shown to the user — is Spanish, written as literals the
  way the surrounding code does; this app uses no `t()` / `$l->t()`, so do not introduce them. Code, comments, commits and PR text English.
- **One concern per PR**, under 50 lines of hand-written source (rebuilt bundle excluded), and it contains every change
  its description claims.

## Agent skills

### Issue tracker

GitHub Issues on `APS-Conecta/farmacia`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical vocabulary, unmodified: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`,
`wontfix`. All five exist on the repo. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: [`CONTEXT.md`](CONTEXT.md) for the vocabulary, [`docs/adr/`](docs/adr/) for the
decisions. See `docs/agents/domain.md`.
