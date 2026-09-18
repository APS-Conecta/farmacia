/**
 * The draft contract between the sidebar and the server — territorio's
 * blankDraft/draftFrom/payload trio, restated for a record whose editable
 * content includes three child arrays.
 *
 * The draft is owned by App.vue and mutated in place by the sidebar: one
 * record is edited at a time, and copying it back and forth would only add a
 * second place for the two to disagree.
 *
 * `retired` and `updatedAt` ride the draft for display only — retirement is
 * its own guarded action (never part of a save) and the server stamps
 * updated_at itself. payload() strips both; a save that cannot change them
 * must not pretend to.
 */

/** A new record: nothing typed, nothing chosen, empty child sets. */
export function blankDraft() {
	return {
		id: null,
		codigo: '',
		producto: '',
		farmaco: '',
		mg: '',
		presentacion: '',
		categoryId: null,
		programa: '',
		trazador: false,
		ges: [],
		restricciones: [],
		abastece: [],
		indicacion: '',
		dosificacion: '',
		precauciones: '',
		observaciones: '',
		riesgoColinergicoAm: false,
		fdaEmbarazo: '',
		ajusteRenal: '',
		// Display-only: stamped by the server on every write.
		updatedAt: null,
		// Display-only: changed only through Retirar / Restaurar, never a save.
		retired: false,
	}
}

/**
 * A stored row, as the form edits it: nulls become empty strings (an input
 * wants '' to type into, never null), flags are coerced to booleans, and the
 * three child arrays are COPIED — a draft that shared them with the list's
 * row would push every "Quitar" straight into the rendered list.
 */
export function draftFrom(medication) {
	return {
		id: medication.id,
		codigo: medication.codigo ?? '',
		producto: medication.producto ?? '',
		farmaco: medication.farmaco ?? '',
		mg: medication.mg ?? '',
		presentacion: medication.presentacion ?? '',
		categoryId: medication.categoryId ?? null,
		programa: medication.programa ?? '',
		trazador: Boolean(medication.trazador),
		ges: [...(medication.ges ?? [])],
		restricciones: [...(medication.restricciones ?? [])],
		abastece: [...(medication.abastece ?? [])],
		indicacion: medication.indicacion ?? '',
		dosificacion: medication.dosificacion ?? '',
		precauciones: medication.precauciones ?? '',
		observaciones: medication.observaciones ?? '',
		riesgoColinergicoAm: Boolean(medication.riesgoColinergicoAm),
		fdaEmbarazo: medication.fdaEmbarazo ?? '',
		ajusteRenal: medication.ajusteRenal ?? '',
		updatedAt: medication.updatedAt ?? null,
		retired: Boolean(medication.retired),
	}
}

/**
 * What Guardar posts. The whole editable record, always: the create contract
 * is "a create states the WHOLE record" and the update's no-op check compares
 * what was sent against what is stored, so a partial draft would read as
 * "leave the rest alone" rather than "clear the rest".
 *
 * The server is the single judge of every value: it trims, it validates, it
 * dedupes, and its Spanish refusals come back with a 422 the sidebar shows
 * verbatim. Nothing here duplicates that.
 */
export function payload(draft) {
	// id rides the URL; updatedAt is stamped by the server; retirement is its
	// own guarded action. Everything else is the record's editable content.
	const { id, updatedAt, retired, ...fields } = draft

	return fields
}
