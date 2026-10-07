# Bolt Journal

## 2026-10-07 - Pre-fold query and fast-path empty search in filterMedications

Pre-folding the query string once per filter call and short-circuiting empty query strings avoids repeated calls to `fold()` (Unicode normalization and diacritic regex replacement) across 5,000 items in memory on every keystroke. 150 filtering iterations over 5,000 items dropped from 870.76ms to 586.67ms (~33% overall speedup, and empty search evaluations drop near zero).
