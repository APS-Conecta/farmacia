import { describe, expect, it } from 'vitest'
import { blankDraft, draftFrom, payload } from '../src/payload.js'

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
