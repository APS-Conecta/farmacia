<?php
declare(strict_types=1);
/** The SPA host: everything renders inside this one mount point. The
 * fallback line lives where a failed mount can show it — replaced by the
 * app on a clean boot, left standing when data() cannot read its initial
 * state (App.vue's readState card handles the app-side half; this is the
 * never-mounted half, which had nothing at all to say for itself).
 */
?>
<div id="farmacia">Si este mensaje no desaparece, la aplicación no pudo cargar: avise al administrador.</div>
