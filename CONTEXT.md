# Farmacia — glossary

Cross-repo ADR citations are `repo#ADR-NNNN` (`territorio#ADR-0016`); in-repo citations stay bare. The org-wide contracts index lives in `gestion/docs/CONTRACTS.md`.

The domain's words, fixed so the code says what the team says. The terms are Spanish because the
screens and the people who read them say them in Spanish; the code around them is English, and
its ADRs cite the terms as written here. _Avoid_ marks a term that must not appear.

**Arsenal farmacológico**: the complete catalogue of medications the CESFAM uses.
_Avoid_: stock, inventario, bodega.

**Medicamento**: one row of the arsenal: commercial product + active ingredient + presentation
+ coverage and clinical-safety data.

**Lente**: an initial view over the same dataset: Arsenal (everything), Fármacos en embarazo
(FDA-informed), Ajuste farmacológico ERC (renal adjustment informed). A lens changes the initial
filter, never the data. _Avoid_: pestaña (tab), módulo, sección clínica.

**Categoría terapéutica**: a per-instance vocabulary, seeded with 16 values at install.
_Avoid_: familia, clase ATC (it is not the ATC classification).

**Canal de abastece**: a per-instance vocabulary of where each medication comes from
(URGENCIAS, CECOSF…). It starts empty: each CESFAM defines its own. _Avoid_: proveedor, bodega.

**GES**: an AUGE/GES problem number the medication covers (0..n).

**Restricción**: a condition of use written in free text (0..n).

**Trazador**: a flag marking a medication of monitoring/epidemiological use.

**Categoría FDA**: A, B, C, D or X in pregnancy. Empty = no data (not the same as safe).

**Retirar / restaurar**: logical removal and recovery. Nothing is deleted. _Avoid_: eliminar.

**Planilla**: the CSV that goes into and out of the app. The database is the source of truth;
the CSV is a projection.

**Lote**: a reviewable and revertible import run, with its author and time.
