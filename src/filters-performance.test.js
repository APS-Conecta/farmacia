import { describe, expect, it } from 'vitest'
import { filterMedications, indexCategories } from './filters.js'

const CATEGORIES = [
	{ id: 1, slug: 'analgesicos', label: 'Analgésicos' },
	{ id: 2, slug: 'antibioticos', label: 'Antibióticos' },
	{ id: 3, slug: 'salud_mental', label: 'Salud mental' },
	{ id: 4, slug: 'cardiovascular', label: 'Cardiovascular' },
]

const categoriesById = indexCategories(CATEGORIES)

function generateMedications(count = 5000) {
	const items = []
	const products = ['Paracetamol', 'Amoxicilina', 'Fluoxetina', 'Losartan', 'Ibuprofeno', 'Atenolol', 'Enalapril', 'Omeprazol']
	const fdas = ['A', 'B', 'C', 'D', 'X', null]

	for (let i = 1; i <= count; i++) {
		const baseName = products[i % products.length]
		items.push({
			id: i,
			codigo: `F-${String(i).padStart(5, '0')}`,
			producto: `${baseName} ${10 * (i % 5 + 1)} mg`,
			farmaco: baseName.toLowerCase(),
			categoryId: (i % 4) + 1,
			fdaEmbarazo: fdas[i % fdas.length],
			ajusteRenal: i % 3 === 0 ? 'Ajustar según aclaramiento de creatinina' : null,
			retired: false,
		})
	}
	return items
}

describe('filterMedications performance and behavior on 5,000 items', () => {
	const medications = generateMedications(5000)

	it('produces identical results for various queries and lenses', () => {
		const arsenalEmpty = filterMedications(medications, { lens: 'arsenal', query: '' }, categoriesById)
		expect(arsenalEmpty).toHaveLength(5000)

		const searchParacetamol = filterMedications(medications, { lens: 'arsenal', query: 'paracetamol' }, categoriesById)
		expect(searchParacetamol.length).toBeGreaterThan(0)
		expect(searchParacetamol.every((m) => m.producto.toLowerCase().includes('paracetamol'))).toBe(true)

		const searchCategoryLabel = filterMedications(medications, { lens: 'arsenal', query: 'analgésico' }, categoriesById)
		expect(searchCategoryLabel.length).toBeGreaterThan(0)

		const embarazoFilter = filterMedications(medications, { lens: 'embarazo', query: 'losartan' }, categoriesById)
		expect(embarazoFilter.every((m) => m.fdaEmbarazo !== null && m.fdaEmbarazo !== '')).toBe(true)
	})

	it('measures execution time over 5,000 items', () => {
		const start = globalThis.performance.now()
		const iterations = 50
		for (let i = 0; i < iterations; i++) {
			filterMedications(medications, { lens: 'arsenal', query: 'paracetamol' }, categoriesById)
			filterMedications(medications, { lens: 'arsenal', query: '' }, categoriesById)
			filterMedications(medications, { lens: 'embarazo', query: 'analgésico' }, categoriesById)
		}
		const elapsed = globalThis.performance.now() - start
		// Just log elapsed time so vitest output includes timing
		console.log(`[Performance Benchmark] ${iterations * 3} filtering runs over 5,000 items took ${elapsed.toFixed(2)}ms`)
		expect(elapsed).toBeGreaterThan(0)
	})
})
