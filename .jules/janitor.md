## 2026-10-08 - 🧹 Janitor: remove stale SLUG_LENGTH docblock comment in VocabularyService (2 lines)

- Stale comments noting previously deleted private constants (like `SLUG_LENGTH` in `VocabularyService.php` when the logic moved to `Text::slugFor`) should be cleaned up once the refactoring has settled.
