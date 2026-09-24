/**
 * The review's own words and its own arithmetic.
 *
 * Everything here is what the import review screen needs to read a staged
 * document (S7's ImportService answer) without re-deriving any of its
 * judgements: the Spanish a disposition or a batch state reads as, the
 * counters line in the occ sentence's own words, which rows still owe a
 * decision, and exactly which keys one row's decision sends.
 *
 * Pure, like filters.js and payload.js: the component hands rows around
 * without worrying who else might change them, and the tests pin the
 * vocabulary the backend owns — a disposition or a state the labels do not
 * cover fails the suite, not the screen.
 */

/** What the apply would do today, in the words the occ command prints. */
export const DISPOSITION_LABELS = {
	create: 'crear',
	update: 'actualizar',
	unchanged: 'sin cambios',
	skipped: 'omitido',
	duplicate: 'duplicado',
	unsupported: 'con problemas',
}

/** A batch's lifecycle, for the recent-runs list. */
export const BATCH_STATE_LABELS = {
	staged: 'en revisión',
	applied: 'aplicado',
	discarded: 'descartado',
}

/**
 * The batch's counters as one line, zero counts dropped — a 400-row
 * all-create planilla reads «400 por crear», not five zeroes beside it.
 * The words are the occ sentence's own, so the two doors of one
 * ImportService say the same thing.
 *
 * @param {object} batch ImportBatch::toClient()
 * @returns {string}
 */
export function counters(batch) {
	const parts = [
		[batch.created, 'por crear'],
		[batch.updated, 'por actualizar'],
		[batch.unchanged, 'sin cambios'],
		[batch.skipped, 'omitidos por retirados'],
		[batch.duplicates, 'duplicados'],
		[batch.unsupported, 'con problemas'],
	]

	return parts
		.filter(([count]) => count > 0)
		.map(([count, words]) => `${count} ${words}`)
		.join(' · ')
}

/**
 * The rows the review still owes a decision on, in planilla order. The
 * stage's own status decides — never a re-derivation of the payload here.
 *
 * @param {Array} rows ImportRow::toClient() list
 * @returns {Array}
 */
export function pendingRows(rows) {
	return rows.filter((row) => row.status === 'unresolved-vocabulary')
}

/**
 * The decision payload for one row: exactly the keys its `pending` map
 * owes, never more. A key the row does not owe would let the decision
 * rewrite a cell the planilla already settled, and a decision with no key
 * is the server's «La decisión no dice qué resolver.» — both stay out by
 * construction, not by a disabled button.
 *
 * @param {object} row ImportRow::toClient()
 * @param {object} choice the editor's picks: { categoryId, abasteceIds }
 * @returns {object} the POST body: { categoryId? , abastece? }
 */
export function decisionFor(row, choice) {
	const pending = row.payload?.pending ?? {}
	const decision = {}

	if ('categoria' in pending) {
		decision.categoryId = choice.categoryId
	}
	if ('abastece' in pending) {
		decision.abastece = choice.abasteceIds
	}

	return decision
}

/**
 * Whether a row's decision can be committed (L2-18 — ImportDialog's
 * computed, moved so the server semantics it embeds are testable):
 * a category decision is only ready when a category is picked — the
 * server refuses an unpicked one («La categoría tiene que ser un número.»,
 * the type guard this same phase lands at the decision door), and an
 * unpicked one read as id 0 was the sentence this rule exists to keep
 * away from the person. The channel decision is always ready: none picked
 * IS the «dejar sin canal» answer, a legal decision.
 *
 * @param {?object} row the row being decided, or null
 * @param {?{categoryId: ?number, abasteceIds: number[]}} decision the
 *        seeded picks for that row
 * @param {boolean} owedCategoria whether the row owes a category decision
 * @return {boolean}
 */
export function decisionReady(row, decision, owedCategoria) {
	if (row === null) {
		return false
	}
	return !owedCategoria || (decision?.categoryId ?? null) !== null
}
