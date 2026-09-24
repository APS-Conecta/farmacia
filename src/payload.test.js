import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { blankDraft, draftFrom, payload, FDA_OPTIONS, mergeMedication } from '../src/payload.js'

// fileURLToPath, not .pathname — territorio's twins.test.js note, still true.
// '../', not '../../': farmacia's js suite lives IN src/ (one level deep);
// territorio's twins sit two levels down, which is why theirs reads '../..'.
const ROOT = fileURLToPath(new URL('../', import.meta.url))

describe('FDA_OPTIONS (payload.js) against MedicationService::FDA_CATEGORIES', () => {
	it('is the same five letters the server refuses by', () => {
		// The twins doctrine: read the PHP, assert against what is there — a
		// sixth letter added server-side breaks this before the picker offers
		// a value the save refuses.
		const php = readFileSync(`${ROOT}lib/Service/MedicationService.php`, 'utf8')
		// [^\]] — in JavaScript a bare [^]] is the any-char class plus a
		// literal ']': the negated class needs the escaped bracket.
		const found = php.match(/FDA_CATEGORIES = \[([^\]]+)\]/)
		expect(found, 'FDA_CATEGORIES could not be read from MedicationService').not.toBeNull()

		const letters = [...found[1].matchAll(/'([ABCDX])'/g)].map((m) => m[1])
		expect(FDA_OPTIONS).toEqual(letters)
	})
})

describe('mergeMedication', () => {
	const saved = { id: 7, producto: 'Nuevo', retired: false }

	it('unshifts a new record — newest first, never invisible', () => {
		const sets = mergeMedication({ medications: [{ id: 1 }], retired: [] }, saved)
		expect(sets.medications.map((m) => m.id)).toEqual([7, 1])
	})

	it('replaces in place on edit', () => {
		const sets = mergeMedication(
			{ medications: [{ id: 1, producto: 'uno' }, { id: 7, producto: 'Viejo' }], retired: [] },
			saved,
		)
		expect(sets.medications.map((m) => m.producto)).toEqual(['uno', 'Nuevo'])
	})

	it('crosses sets on retire and takes the survivor out of the other', () => {
		const retired = { ...saved, retired: true }
		const sets = mergeMedication({ medications: [saved], retired: [{ id: 3 }] }, retired)
		expect(sets.medications).toEqual([])
		expect(sets.retired.map((m) => m.id)).toEqual([7, 3])
	})

	it('restores back the other way', () => {
		const sets = mergeMedication({ medications: [{ id: 3 }], retired: [saved] }, saved)
		expect(sets.retired).toEqual([])
		expect(sets.medications.map((m) => m.id)).toEqual([7, 3])
	})

	it('never mutates the sets it was given', () => {
		const medications = [{ id: 1 }]
		mergeMedication({ medications, retired: [] }, saved)
		expect(medications).toEqual([{ id: 1 }])
	})
})

describe('blankDraft', () => {
	it('starts as a new record with empty child sets', () => {
		const draft = blankDraft()
		expect(draft.id).toBeNull()
		expect(draft.codigo).toBe('')
		expect(draft.categoryId).toBeNull()
		expect(draft.ges).toEqual([])
		expect(draft.restricciones).toEqual([])
		expect(draft.abastece).toEqual([])
		expect(draft.trazador).toBe(false)
		expect(draft.fdaEmbarazo).toBe('')
		expect(draft.updatedAt).toBeNull()
		expect(draft.retired).toBe(false)
	})
})

describe('draftFrom', () => {
	it('normalizes nulls to empty strings and coerces flags', () => {
		const draft = draftFrom({
			id: 5,
			codigo: 'F-001',
			producto: 'Paracetamol 500 mg',
			farmaco: null,
			categoryId: 3,
			trazador: 1,
			fdaEmbarazo: 'B',
			ges: [1, 17],
			restricciones: ['Solo receta retenida'],
			abastece: [2],
		})

		expect(draft.id).toBe(5)
		expect(draft.farmaco).toBe('')
		expect(draft.trazador).toBe(true)
		expect(draft.fdaEmbarazo).toBe('B')
		expect(draft.ges).toEqual([1, 17])
	})

	it('copies the child arrays rather than sharing them', () => {
		const row = { id: 1, ges: [1, 2], restricciones: ['a'], abastece: [3] }

		const draft = draftFrom(row)
		draft.ges.push(99)
		draft.restricciones.push('b')

		expect(row.ges).toEqual([1, 2])
		expect(row.restricciones).toEqual(['a'])
	})

	it('carries retired and updatedAt for display', () => {
		const draft = draftFrom({ id: 1, retired: true, updatedAt: '2026-09-18 10:00:00' })
		expect(draft.retired).toBe(true)
		expect(draft.updatedAt).toBe('2026-09-18 10:00:00')
	})
})

describe('payload', () => {
	it('carries the editable content and the three child arrays', () => {
		const draft = {
			...blankDraft(),
			codigo: 'F-001',
			producto: 'Paracetamol',
			categoryId: 3,
			ges: [17],
			abastece: [1, 2],
		}

		const sent = payload(draft)

		expect(sent.codigo).toBe('F-001')
		expect(sent.categoryId).toBe(3)
		expect(sent.ges).toEqual([17])
		expect(sent.abastece).toEqual([1, 2])
		expect(sent.restricciones).toEqual([])
	})

	it('never sends id, updatedAt or retired', () => {
		const sent = payload(draftFrom({
			id: 9,
			retired: true,
			updatedAt: '2026-09-18 10:00:00',
			codigo: 'F-009',
			producto: 'P',
		}))

		expect(sent).not.toHaveProperty('id')
		expect(sent).not.toHaveProperty('updatedAt')
		expect(sent).not.toHaveProperty('retired')
	})
})
