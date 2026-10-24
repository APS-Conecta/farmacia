# Third-party notices — farmacia

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

**Derived from `js/farmacia-main.js.LICENSE.txt`**, which the build emits beside the bundle (js/ is
built, not committed — the release packs it), so this summary is the copy that travels with the
tarball as AGPL §4 requires. That file is the authority: it carries an SPDX identifier and a
copyright line per block the webpack licence plugin recorded. This one is its summary — with one
addition it cannot make itself: the plugin groups the Nextcloud packages under undated blocks
that name no package, so the six runtime dependencies `package.json` declares are hand-recorded
here, by licence.

Regenerating is reading it again: run `npm run build`, then reconcile this table with
`js/farmacia-main.js.LICENSE.txt`.

## Bundled JavaScript dependencies

The six runtime dependencies `package.json` declares, plus the additional components the build
pulls in through them:

| Licence | Components |
|---|---|
| AGPL-3.0-or-later | `@nextcloud/vue` |
| GPL-3.0-or-later | `@nextcloud/axios`, `@nextcloud/initial-state`, `@nextcloud/router` |
| MIT | `vue` (the bundle carries its runtime packages, `@vue/runtime-dom` and `@vue/shared`), `vue-material-design-icons`, `escape-html`, `focus-trap`, `tabbable` |
| Apache-2.0 OR MPL-2.0 | `dompurify` (DOMPurify 3.4.15) |

One reading of that table would be wrong: **`vue` is absent from the banner as a package**
because the bundle imports its runtime packages, not the umbrella `package.json` names — the
same way epidemiologia's notices put it. And **`aps/common` is not third-party**: it is the
organisation's own subtree, vendored by `make aps-sync` and AGPL-3.0-or-later like this app, so
it needs no notice of its own.

## Copyleft components

`@nextcloud/vue` is AGPL-3.0-or-later and is compiled into `js/farmacia-main.js`; the three
Nextcloud packages under GPL-3.0-or-later are likewise linked into it. This is why this app is
AGPL-3.0-or-later and could not be licensed otherwise: the obligation is inherited from what it
links, not chosen — the org-wide record is
[gestion ADR-0010](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md).

`js/farmacia-main.js.LICENSE.txt` carries an SPDX identifier and a copyright line per component,
**not the licence texts**: MIT asks for the text itself, so what ships is short of that. The
texts are in `node_modules/<name>/LICENSE` in a development checkout, and the gap closes by
having the build concatenate them — worth doing before this app is distributed to anyone outside
the organisation.
