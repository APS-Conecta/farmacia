/**
 * The arsenal filter: which medications the list shows.
 *
 * One filter object and three lenses over one dataset. A lens is an initial
 * filter — click "Fármacos en embarazo" and the list narrows to the FDA set
 * with the search cleared. The filter records the current lens and query;
 * the retired toggle is separate because it switches between two datasets
 * entirely (live arsenal vs. recovery screen), not a third narrowing.
 *
 * Every function is pure, so a component can hand its filter around without
 * worrying who else might change it. None of it ever leaves the browser.
 */

/** Nothing narrowed: the full arsenal, no search typed. */
export function emptyFilter() {
	return { lens: 'arsenal', query: '' }
}

/**
 * Accent-folded, case-down text for comparison.
 *
 * NFD splits a letter from its diacritic so \p{Diacritic} can drop it.
 * Someone looking for "paracetamol" should find "Paracetamol", and someone
 * who typed "paracetámol" but missed the accent still reaches the right row.
 */
export const fold = (value) => String(value ?? '')
	.normalize('NFD')
	.replace(/\p{Diacritic}/gu, '')
	.toLowerCase()

/**
 * Whether a medication answers to what someone typed.
 *
 * Matches producto, fármaco (DCI), and the category label. categoryLabel
 * comes from the Map built by indexCategories(); resolved before calling so
 * this function stays pure.
 *
 * ponytail: substring over folded text, no ranking. One CESFAM's vademécum is
 * hundreds of rows in memory; index when search actually feels slow.
 */
export function matches(medication, query, categoryLabel) {
	const needle = fold(query).trim()
	if (needle === '') {
		return true
	}

	return [
		medication.producto,
		medication.farmaco,
		categoryLabel,
	].some((field) => fold(field).includes(needle))
}

/**
 * The lens predicate: which medications the current lens keeps.
 *
 * - arsenal: everything
 * - embarazo: FDA set — rows where fdaEmbarazo is set (null/empty = sin dato)
 * - renal: renal-adjusted — rows where ajusteRenal is set
 */
export function lensPredicate(lens) {
	switch (lens) {
		case 'embarazo':
			return (m) => m.fdaEmbarazo !== null && m.fdaEmbarazo !== ''
		case 'renal':
			return (m) => m.ajusteRenal !== null && m.ajusteRenal !== ''
		case 'arsenal':
		default:
			return () => true
	}
}

/**
 * The one filtered set: lens predicate + search, applied in one place.
 *
 * Both narrowings live here so the list and any future counts cannot drift
 * apart — territorio's filterFeatures() doctrine, restated for a single-level
 * vocabulary.
 */
export function filterMedications(medications, filter, categoriesById) {
	const predicate = lensPredicate(filter.lens)

	return medications.filter((m) => predicate(m)
		&& matches(m, filter.query, categoriesById.get(m.categoryId)?.label))
}

/**
 * What the list shows: the live arsenal, or the retired recovery screen.
 *
 * The retired list deliberately ignores the lens — it is the recovery screen,
 * and a lens is a browsing choice someone forgot they left on. The search
 * DOES reach the retired list: a query is someone saying out loud what they
 * are looking for, and ignoring it would overrule an explicit instruction.
 */
export function listFor({ medications, retiredMedications, showRetired, filter, categoriesById }) {
	if (!showRetired) {
		return filterMedications(medications, filter, categoriesById)
	}

	return retiredMedications.filter((m) => matches(m, filter.query, categoriesById.get(m.categoryId)?.label))
}

/** A Map of category id → category object, for O(1) label lookup. */
export function indexCategories(categories) {
	return new Map(categories.map((c) => [c.id, c]))
}
