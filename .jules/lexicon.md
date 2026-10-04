## 2026-10-04 - Fix feminine gender for domain vocabulary term planilla

When referencing domain vocabulary terms in Spanish UI components like `ExportDialog.vue`, make sure gender agreement matches the term defined in `CONTEXT.md`. `planilla` is feminine (`la planilla`), so references like "el planilla" must be fixed to "la planilla".
