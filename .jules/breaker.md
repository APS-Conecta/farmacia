# Breaker Journal

## 2026-10-05 - Tested CSV formula defusing across all text fields

Added unit test assertions in `tests/unit/Service/CsvWriterTest.php` testing formula injection triggers (`=`, `+`, `-`, `@`, `\t`, `\r`) across every text field in a medication record. Confirmed that `CsvWriter` (delegating to `APS\Common\Csv::text`) defuses formula characters with a leading apostrophe in the emitted CSV bytes.
