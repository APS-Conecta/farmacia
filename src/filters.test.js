import { describe, expect, it } from 'vitest'
import { emptyFilter, filterMedications, fold, indexCategories, lensPredicate, listFor, matches } from '../src/filters.js'

const CATEGORIES = [
	{ id: 1, slug: 'analgesicos', label: 'Analgésicos' },
	{ id: 3, slug: 'salud_mental', label: 'Salud mental' },
]

const byId = indexCategories(CATEGORIES)

const MEDICATIONS = [
	{ id: 1, codigo: 'F-001', producto: 'Paracetamol 500 mg', farmaco: 'paracetamol',
		categoryId: 1, fdaEmbarazo: 'B', ajusteRenal: null, retired: false },
	{ id: 2, codigo: 'F-002', producto: 'Fluoxetina 20 mg', farmaco: 'fluoxetina',
		categoryId: 3, fdaEmbarazo: null, ajusteRenal: 'Reducir dosis al 50%', retired: false },
	{ id: 3, codigo: 'F-003', producto: 'Diazepam 10 mg', farmaco: null,
		categoryId: 3, fdaEmbarazo: 'D', ajusteRenal: null, retired: true },
]

describe('fold', () => {
	it('lower-cases', () => {
		expect(fold('Paracetamol')).toBe('paracetamol')
	})

	it('drops accents', () => {
		expect(fold('Analgésicos')).toBe('analgesicos')
	})

	it('handles null and undefined', () => {
		expect(fold(null)).toBe('')
		expect(fold(undefined)).toBe('')
	})
})

describe('matches', () => {
	it('matches on producto', () => {
		expect(matches(MEDICATIONS[0], 'paracetamol', null)).toBe(true)
	})

	it('matches on farmaco', () => {
		expect(matches(MEDICATIONS[0], 'paracetamol', null)).toBe(true)
	})

	it('matches on category label', () => {
		expect(matches(MEDICATIONS[0], 'analgésico', 'Analgésicos')).toBe(true)
	})

	it('returns true for empty query', () => {
		expect(matches(MEDICATIONS[0], '', null)).toBe(true)
	})

	it('is accent-insensitive', () => {
		expect(matches(MEDICATIONS[1], 'fluoxetina', null)).toBe(true)
	})
})

describe('emptyFilter', () => {
	it('is the browsing default: the whole arsenal, nothing typed', () => {
		expect(emptyFilter()).toEqual({ lens: 'arsenal', query: '' })
	})
})

describe('filterMedications', () => {
	it('composes the lens predicate and the search over its own fixtures', () => {
		// The lens semantics through the composition the App actually calls
		// (L2-21): embarazo keeps the fdaEmbarazo set, renal keeps ajusteRenal,
		// and the query narrows inside what the lens kept.
		const embarazo = filterMedications(MEDICATIONS, { lens: 'embarazo', query: '' }, byId)
		expect(embarazo.map((m) => m.codigo)).toEqual(['F-001', 'F-003'])

		const renal = filterMedications(MEDICATIONS, { lens: 'renal', query: '' }, byId)
		expect(renal.map((m) => m.codigo)).toEqual(['F-002'])

		const both = filterMedications(MEDICATIONS, { lens: 'embarazo', query: 'paracet' }, byId)
		expect(both.map((m) => m.codigo)).toEqual(['F-001'])
	})
})

describe('lensPredicate', () => {
	it('arsenal keeps everything', () => {
		const p = lensPredicate('arsenal')
		expect(MEDICATIONS.every(p)).toBe(true)
	})

	it('embarazo keeps only FDA set', () => {
		const p = lensPredicate('embarazo')
		expect(p(MEDICATIONS[0])).toBe(true)   // B
		expect(p(MEDICATIONS[1])).toBe(false)  // null
		expect(p(MEDICATIONS[2])).toBe(true)   // D
	})

	it('renal keeps only renal-adjusted', () => {
		const p = lensPredicate('renal')
		expect(p(MEDICATIONS[0])).toBe(false)  // null
		expect(p(MEDICATIONS[1])).toBe(true)   // "Reducir dosis..."
		expect(p(MEDICATIONS[2])).toBe(false)  // null
	})
})

describe('listFor', () => {
	it('retired list ignores lens but honors query', () => {
		const f = { lens: 'embarazo', query: 'diazepam' }
		const result = listFor({
			medications: MEDICATIONS,
			retiredMedications: [MEDICATIONS[2]],
			showRetired: true,
			filter: f,
			categoriesById: byId,
		})
		expect(result).toHaveLength(1)
		expect(result[0].codigo).toBe('F-003')
	})

	it('retired list returns empty when query does not match', () => {
		const result = listFor({
			medications: MEDICATIONS,
			retiredMedications: [MEDICATIONS[2]],
			showRetired: true,
			filter: { lens: 'arsenal', query: 'zzz' },
			categoriesById: byId,
		})
		expect(result).toHaveLength(0)
	})
})
