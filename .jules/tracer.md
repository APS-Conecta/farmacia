## 2026-10-06 - Clear adder text on vocabulary prop update

In `VocabularyDialog.vue`, when adding a category or channel, `commitAdd()` emits the label to the parent, which returns updated `entries` down the prop upon success. The `entries()` watcher handled closing the rename field (`this.editing = null`) but omitted resetting `this.newLabel`. As a result, the input field retained the previously added text after a successful add. Clearing `this.newLabel` when `entries` updates ensures the field is reset for the next entry on success, while preserving typed input on error/refusal.
