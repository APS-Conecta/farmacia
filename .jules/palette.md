## 2026-10-09 - Accessible names with visible label leading for speech input

Controls with visible text that also specify an `aria-label` must lead with or match the visible text (WCAG 2.5.3 Label in Name). Putting action prefixes like "Gestionar ..." or "Renombrar ..." before the visible text breaks speech recognition software that targets controls by their visible label. Leading with the visible text (`<label> — <action description>`) preserves speech input compatibility while providing context to screen readers.
