import { describe, expect, it } from 'vitest'
import { BATCH_STATE_LABELS, DISPOSITION_LABELS, counters, decisionFor, decisionReady, pendingRows, revertCounters } from '../src/import.js'

const BATCH = {
	created: 5,
	updated: 0,
	unchanged: 1,
	skipped: 0,
	duplicates: 2,
	unsupported: 0,
}

describe('counters', () => {
	it('keeps the occ sentence words and drops the zero counts', () => {
		expect(counters(BATCH)).toBe('5 por crear · 1 sin cambios · 2 duplicados')
	})

	it('reads all six when the planilla is mixed', () => {
		expect(counters({ created: 1, updated: 2, unchanged: 3, skipped: 4, duplicates: 5, unsupported: 6 }))
			.toBe('1 por crear · 2 por actualizar · 3 sin cambios · 4 omitidos por retirados · 5 duplicados · 6 con problemas')
	})

	it('is an empty line when the planilla counts nothing', () => {
		expect(counters({ created: 0, updated: 0, unchanged: 0, skipped: 0, duplicates: 0, unsupported: 0 })).toBe('')
	})
})

describe('revertCounters', () => {
	it('says how many records the undo put back and how many it left as they were', () => {
		expect(revertCounters({ updated: 37, skipped: 3 }))
			.toBe('37 deshechos · 3 sin deshacer: cambiaron después del lote o su categoría o canal ya no existe')
	})

	it('drops the left-alone count when the undo reached every record', () => {
		expect(revertCounters({ updated: 37, skipped: 0 })).toBe('37 deshechos')
	})

	it('keeps the put-back count when the undo reached none', () => {
		expect(revertCounters({ updated: 0, skipped: 3 })).toMatch(/^0 deshechos · /)
	})

	it('is never an empty line', () => {
		expect(revertCounters({ updated: 0, skipped: 0 })).toBe('0 deshechos')
	})
})

describe('pendingRows', () => {
	it('keeps only the rows that owe a decision, in planilla order', () => {
		const rows = [
			{ id: 1, status: 'staged' },
			{ id: 2, status: 'unresolved-vocabulary' },
			{ id: 3, status: 'invalid' },
			{ id: 4, status: 'unresolved-vocabulary' },
		]

		expect(pendingRows(rows).map((row) => row.id)).toEqual([2, 4])
	})
})

describe('decisionFor', () => {
	const row = (pending) => ({ payload: { pending } })

	it('sends the category when the row owes one', () => {
		expect(decisionFor(row({ categoria: 'ANTITERMICOS' }), { categoryId: 3, abasteceIds: [] }))
			.toEqual({ categoryId: 3 })
	})

	it('sends the channels when the row owes them — none picked is the sin-canal answer', () => {
		expect(decisionFor(row({ abastece: ['SECO'] }), { categoryId: null, abasteceIds: [] }))
			.toEqual({ abastece: [] })
	})

	it('sends both when the row owes both', () => {
		expect(decisionFor(row({ categoria: null, abastece: ['SECO'] }), { categoryId: 2, abasteceIds: [1, 2] }))
			.toEqual({ categoryId: 2, abastece: [1, 2] })
	})

	it('sends nothing when the row owes nothing', () => {
		expect(decisionFor(row({}), { categoryId: 2, abasteceIds: [1] })).toEqual({})
		expect(decisionFor({ payload: null }, {})).toEqual({})
	})
})

describe('decisionReady', () => {
	it('is false with no row under decision', () => {
		expect(decisionReady(null, null, false)).toBe(false)
	})

	it('is false when a category is owed and none is picked', () => {
		expect(decisionReady({}, { categoryId: null, abasteceIds: [] }, true)).toBe(false)
	})

	it('is true when a category is owed and one is picked', () => {
		expect(decisionReady({}, { categoryId: 2, abasteceIds: [] }, true)).toBe(true)
	})

	it('is true when no category is owed — none picked is the answer for channels', () => {
		expect(decisionReady({}, { categoryId: null, abasteceIds: [] }, false)).toBe(true)
	})
})

describe('the labels the backend owns', () => {
	it('cover every disposition the stage writes', () => {
		expect(Object.keys(DISPOSITION_LABELS).sort())
			.toEqual(['create', 'duplicate', 'skipped', 'unchanged', 'unsupported', 'update'])
	})

	it('cover every batch state the lifecycle writes', () => {
		expect(Object.keys(BATCH_STATE_LABELS).sort()).toEqual(['applied', 'discarded', 'staged'])
	})
})
