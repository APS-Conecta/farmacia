# ADR-0004 — The arsenal is a vademécum, not a stock

- **Status:** accepted (2026-10-04) — recording date; the decision predates it, in the first migration
- **Affects:** `farmacia/lib/Migration/Version000100Date20260917000000.php`, every screen and export over `farmacia_medication`

## Context

A "pharmacy system" normally counts: units on hand, expiry dates, lots, reorder points, orders.
The CESFAM asked for none of that. What its staff needed was a reference — which medications the
center works with, what covers them (GES, programas), and above all what is *safe* about them
(FDA pregnancy category, renal adjustment, anticholinergic risk in older adults) — consultable
by the whole team and editable by it (the open-edit decision `docs/threat-model.md` records).

A stock system and a reference system disagree at the schema: stock needs quantity and expiry
columns and a writer for every movement; a reference needs neither, and half-filling them is how
a reference quietly becomes a stock system nobody maintains.

## Decision

**The arsenal is a vademécum: no quantities, no expiry dates, no lots, no orders — and the
schema says so.** `farmacia_medication`'s columns
(`farmacia/lib/Migration/Version000100Date20260917000000.php:64-108`) are the reference's own:
`codigo`, `producto`, `farmaco`, `mg`, `presentacion`, `programa`, `trazador`, `indicacion`,
`dosificacion`, `precauciones`, `observaciones`, `riesgo_colinergico_am`, `fda_embarazo`,
`ajuste_renal`, `category_id`, `retired`, `updated_at`. There is no quantity column, no expiry
column, and nothing to receive a stock movement. [`CONTEXT.md`](../../CONTEXT.md) carries the
vocabulary half: _Avoid_ «stock», «inventario», «bodega» — the words that would smuggle the
missing model back in.

## Consequences

- No expiry alarms to configure or ignore, no counts to drift from the shelf, no cycle counts to
  reconcile: the app cannot be wrong about stock because it says nothing about stock.
- The boundary is structural, not a setting: turning this app into a stock system is a migration
  with an ADR of its own, not a toggle.
- The planilla round trip (ADR-0001) stays lossless in both directions: every column the export
  writes is a column the import reads, and none of them is a count that ages between export and
  import.
- Integration with anything that does count stock (a bodega system, a purchase system) happens
  outside this app, over the OCS contract, which serves the reference and nothing else.
