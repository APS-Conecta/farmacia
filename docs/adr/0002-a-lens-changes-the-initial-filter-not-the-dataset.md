# ADR-0002 — A lens changes the initial filter, not the dataset

- **Status:** accepted (2026-10-04) — recording date; the decision predates it, in `src/filters.js` since the first front-end slices
- **Affects:** `farmacia/src/filters.js`, `farmacia/src/App.vue`, every count or narrowing the list shows

## Context

The same arsenal is read by three audiences: everyone («Arsenal Farmacológico»), whoever is
counseling pregnancy («Fármacos en embarazo», the FDA-informed rows), and whoever is adjusting
doses for chronic kidney disease («Ajuste farmacológico ERC», the renal-adjusted rows). The
tempting shape is three datasets — three screens, three queries, three counts — which is also
three places for the same medication to disagree with itself.

## Decision

**One dataset, three initial filters.** The three lenses are navigation items over one arsenal:

- `farmacia/src/filters.js:4-8` states the rule in its own docblock: a lens is an initial filter.
  `lensPredicate()` (`farmacia/src/filters.js:44`) is a pure predicate per lens — `arsenal`
  keeps everything, `embarazo` keeps rows with an FDA category set, `renal` keeps rows with a
  renal adjustment set — and `filterMedications()` applies lens + search in one place so the list
  and any future count cannot drift apart.
- `openLens()` (`farmacia/src/App.vue:313-319`) resets the whole filter object to
  `{ lens: id, query: '' }`: choosing a lens is a fresh start, not a narrowing stacked on a
  forgotten search.
- The retired screen deliberately ignores the lens (`listFor`, `farmacia/src/filters.js:78`):
  it is a recovery screen, and a highlighted lens there would advertise a narrowing that never
  happened. The search does reach it — a query is someone saying what they are looking for.

## Consequences

- A lens can never hide data: switching back to «Arsenal Farmacológico» is proof, not a
  coincidence of implementation.
- «Fármacos en embarazo» shows rows whose FDA category is *informed* — empty means sin dato, not
  safe — which is the same sentence the medication form's hint says; one rule, two surfaces.
- A new lens is one predicate and one navigation entry, not a new screen.
- Everything stays in the browser: the filter never leaves it, and the server-side OCS narrowing
  (`?gestacion=`, `?renal_ajuste=`) re-derives the same predicates for consumers who ask over
  the wire.
