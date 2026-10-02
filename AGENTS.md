# AGENTS.md — Farmacia

The CESFAM's medication vademécum, a Nextcloud app: the formulary with its clinical-safety information (FDA pregnancy category, renal adjustment, anticholinergic risk in older adults) — no patient data, no stock.

## Scheduled Jules agents (Bolt ⚡ / Sentinel 🛡️ / Palette 🎨)

Nightly Jules presets — Sentinel (security), Bolt (performance), Palette (UX/a11y). Claude reviews every PR.

- **Journals** live only in lowercase `.jules/` (`bolt.md`, `sentinel.md`, `palette.md`) — never create `.Jules/`.
  Date each entry with today's `date +%F`, never a remembered or copied date.
- **Gate before opening a PR** — what `ci.yml` and `docs.yml` run. This repo uses **npm**, not pnpm:
  `git clone --depth 1 https://github.com/APS-Conecta/aps-common.git ../aps-common` if it is missing;
  `make aps-drift`; `npm ci`; `npm run lint-js`; `npm test`; `npm run build`, then `git diff --exit-code js/`; then
  `make test` — after `npm test`, since it copies a node:test file into `vendor/` that `npm test` would then fail on;
  `make instance-up && make smoke`, then `make instance-clean`;
  `python3 .github/repo-docs.py check . --offline`. A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `npm run build`** and commit the regenerated `js/farmacia-main.js` and its `.LICENSE.txt` in the same PR.
  Never commit `*.js.map` or `node_modules/`.
- **Never edit** `.github/repo-docs.py`, `.github/profiles/*`, `package-lock.json`, `composer.lock`, the dependency lists
  in `package.json`/`composer.json`, `js/` by hand, `openapi.json` (generated from `ApiController` by `scripts/openapi.sh`;
  `make smoke` fails on drift), or the aps-common copies `src/aps/`, `vendor/aps/common/src/`, `tests/stubs/OC/Hooks/Emitter.php`.
- **Strings:** what a person reads — templates, exception messages shown to the user — is Spanish, written as literals the
  way the surrounding code does; this app uses no `t()` / `$l->t()`, so do not introduce them. Code, comments, commits and PR text English.
- **One concern per PR**, under 50 lines of hand-written source (rebuilt bundle excluded), and it contains every change
  its description claims — a journal-only PR is invalid. Nothing worthwhile → no PR. Never repeat a change an open or
  recently closed PR already proposes.
- **PR text:** Conventional Commits title; tick the template's AI-involvement box (`ai-assisted`), leave «Human-reviewed» unticked.
