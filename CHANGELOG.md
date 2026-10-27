# Changelog

All notable changes to `APS-Conecta/farmacia` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `docs/adr/0001`–`0004`: the planilla-is-a-projection, lens-is-an-initial-filter,
  retirar/restaurar-is-logical and vademécum-not-stock decisions, recorded.
- `docs/adr/0005`: medication changes, the OCS create, update and single-medication read, and
  import apply/revert each leave one `HIPAA_AUDIT` line in `nextcloud.log` at warning level,
  through the app logger.
- `THIRD-PARTY-NOTICES.md`: the licences of everything the committed bundle ships.
- `docs/threat-model.md`: what an attacker wants from the vademécum, the entry points and their
  guards; records the open-edit decision and names the direct OCS write path.
- CI clones the private `aps-common` with a read-only deploy key.

### Changed

- **First signed release (0.12.0).** Releases are built and signed by the org's shared
  workflow (instalador #31), called from `.github/workflows/release.yml` pinned by commit
  SHA: a pull request builds the frontend and packs the tarball; a `v*` tag — which must
  equal `v` + the `appinfo/info.xml` version — signs `SHA256SUMS` with the app key inside
  the repo's `release` environment and publishes exactly four assets (`farmacia.tar.gz`,
  `info.xml`, `SHA256SUMS`, `SHA256SUMS.minisig`), proven against the suite's `apps.pub`.
  The tarball's contents come from `.nextcloudignore`, added with this change: the
  development tree (`src/`, `tests/`, `docs/`, `scripts/`, the agent files, the configs)
  stays out; `appinfo/`, `lib/`, `templates/`, `img/`, the built `js/`, the vendored
  `vendor/aps/common/src`, the licences and the notices travel.

  **`js/` is build output now, not a committed artifact.** The two tracked bundle files
  (including the terser `.LICENSE.txt` notice) leave git and `js/` is ignored: the
  release builds the bundle before packing, and `pack-app` refuses a committed one. CI's
  rebuild-no-drift arm goes with them — CI now fails a PR whose build produces no bundle,
  the same refusal the packer makes — and installing from a clone needs
  `npm ci && npm run build` first (README says so).

  **The vendored docs checker and canon are gone** (instalador #32, S2a): the checker, its
  profiles, the `docs-es` marker and the documentation workflow under `.github/`, the
  issue forms, the pull-request template and the code-owners file — the org defaults in
  `APS-Conecta/.github` serve the community files now. No `guard.yml`: the repo is
  public, so secret scanning and push protection are GitHub's, not a workflow's.
- The README is in Spanish on the five-section contract (`Qué es`, `Documentación`, `Estado`,
  `Inicio rápido de desarrollo`, `Licencia`), and the repository opts into the Spanish-docs
  regime (`.github/docs-es`, ADR 0006 of repo-docs); the vendored checker and profiles are
  refreshed.
- The `<documentation>` links in `appinfo/info.xml` point at the documentation site.
- **Documentation rewrite**: English README on the suite's section contract, English
  `CONTEXT.md` (Spanish headwords kept), and `<documentation>` links in `appinfo/info.xml`.
- `aps-common` is the CSV core's one home; the vocabulary slugs go through its `Text`.
- The OCS payload is typed as the 21 keys the route serves.
- The hardening pass: one refusal trait, typed doors, caps single-sourced, safe console, keyed
  sidebar.
- Canonized onto the org template CI; PHPUnit normalized to the suite's shape.
- `instance-up` restarts the web workers after `app:enable` — their APCu route and app-state
  cache outlives `occ`.
- The citation convention (`repo#ADR-NNNN`) lands in `CONTEXT.md`.

### Fixed

- Import decisions validate channel id types server-side.
- Enter adds a GES number or a restriction from the adder field; vocabulary buttons carry
  aria-labels.
- `Blob` joins the declared browser surface.
- The smoke envelope check requires status `ok`, not the word `ocs`; a failing check names what
  it actually got.
- The lock file catches up with `package.json` (stale since the 0.11.1 release).
- The vocabulary dialog clears its adder field once the new entry lands.
- The export dialog's hint says «la planilla», not «el planilla».
- After Restaurar, a medicamento's actions bar starts again at «Retirar del arsenal», not at an armed confirmation.
