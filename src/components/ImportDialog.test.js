// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import ImportDialog from './ImportDialog.vue'

/** One staged row as the review serves it, owing what `pending` says. */
function row(id, pending) {
	return {
		id,
		lineNumber: 2,
		codigo: 'F-009',
		payload: { producto: 'Producto de prueba', categoryId: null, abasteceIds: [], pending },
		status: 'unresolved-vocabulary',
		disposition: 'create',
		notes: null,
	}
}

const CATEGORIES = [
	{ id: 1, slug: 'otros', label: 'Otros' },
	{ id: 2, slug: 'salud_mental', label: 'Salud mental' },
]

function dialog(review) {
	return mount(ImportDialog, {
		props: {
			batches: [],
			review: { batch: { id: 11, state: 'staged' }, rows: review },
			categories: CATEGORIES,
			abastece: [],
		},
		// NcDialog teleports its body out of the wrapper's tree; the
		// assertions that read the DOM go through the document below.
		attachTo: document.body,
	})
}

/** The editor's primary Resolver — the button decisionReady gates. NcDialog
 *  teleports, so the button is looked up in the document, not the wrapper. */
function resolver() {
	return [...document.querySelectorAll('button')]
		.find((button) => button.textContent.trim() === 'Resolver')
}

async function openEditor(wrapper, theRow) {
	wrapper.vm.startDecision(theRow)
	await nextTick()
	return resolver()
}

describe('ImportDialog decisionReady (L2-18)', () => {
	it('is false with no row under decision', async () => {
		const wrapper = dialog([row(1, { categoria: 'Desconocida' })])
		await nextTick()

		// With no editor open there is no gated button to read — the state
		// is the computed's, and the computed is the module's rule.
		expect(wrapper.vm.decisionReady).toBe(false)
		wrapper.unmount()
	})

	it('is disabled while a category is owed and none is picked', async () => {
		const theRow = row(1, { categoria: 'Desconocida' })
		const wrapper = dialog([theRow])
		const button = await openEditor(wrapper, theRow)

		expect(button).toBeDefined()
		expect(button.disabled).toBe(true)
		wrapper.unmount()
	})

	it('is enabled once the owed category is picked', async () => {
		const theRow = row(1, { categoria: 'Desconocida' })
		const wrapper = dialog([theRow])
		const button = await openEditor(wrapper, theRow)

		wrapper.vm.decisions[1].categoryId = 2
		await nextTick()

		expect(button.disabled).toBe(false)
		wrapper.unmount()
	})

	it('is enabled when no category is owed — none picked is the channel answer', async () => {
		const theRow = row(1, { abastece: ['SECO'] })
		const wrapper = dialog([theRow])
		const button = await openEditor(wrapper, theRow)

		expect(button.disabled).toBe(false)
		wrapper.unmount()
	})
})
