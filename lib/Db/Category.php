<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One therapeutic category of the vademécum's vocabulary — see CONTEXT.md.
 * Editable data, not code: a clinic adds a row and it appears in every list
 * and filter the app draws. Flat by design: unlike territorio's two-level
 * taxonomy, a medication's classification is one category, nothing under it.
 *
 * No color and no glyph: those exist in territorio because a map paints with
 * them; this app draws text, and the category's label is its whole face.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 */
class Category extends Entity {
	protected $slug = '';
	protected $label = '';
}