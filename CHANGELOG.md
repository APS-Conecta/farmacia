# Changelog

All notable changes to `APS-Conecta/farmacia` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `docs/manual.md`: the Spanish staff manual — every screen walked in its own words, lenses,
  ficha, retirar/restaurar, the planilla round trip, the vocabularies, the occ import.
- `docs/adr/0001`–`0004`: the planilla-is-a-projection, lens-is-an-initial-filter,
  retirar/restaurar-is-logical and vademécum-not-stock decisions, recorded.
- `THIRD-PARTY-NOTICES.md`: the licences of everything the committed bundle ships.
- `docs/threat-model.md`: what an attacker wants from the vademécum, the entry points and their
  guards; records the open-edit decision and names the direct OCS write path.
- The weekly agent roster and its rules in `AGENTS.md`.
- CI clones the private `aps-common` with a read-only deploy key.

### Changed

- **Documentation rewrite**: English README on the suite's section contract and English
  `CONTEXT.md` (Spanish headwords kept), the authority-table `docs/index.md`, and
  `<documentation>` links in `appinfo/info.xml`.
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
- The domain vocabulary example in `docs/index.md` matches `CONTEXT.md`.
