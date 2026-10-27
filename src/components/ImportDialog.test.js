// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
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

function dialog(rows) {
	return mount(ImportDialog, {
		props: {
			batches: [],
			review: rows === null ? null : { batch: { id: 11, state: 'staged' }, rows },
			categories: CATEGORIES,
			abastece: [],
		},
		// NcDialog teleports its body out of the wrapper's tree; the
		// assertions that read the DOM go through the document below.
		attachTo: document.body,
	})
}

/** The button labelled `label` — the editor's primary Resolver that
 *  decisionReady gates, or the picking half's Revisar. NcDialog teleports,
 *  so the button is looked up in the document, not the wrapper. */
function findButton(label) {
	return [...document.querySelectorAll('button')]
		.find((button) => button.textContent.trim() === label)
}

/** A planilla of `kb` KB; every pick shares one name, as a same-path re-pick does. */
function planilla(kb) {
	return new window.File(['x'.repeat(kb * 1024)], 'planilla.csv')
}

/** Makes the file input hold `file` and fires `type` on it. */
async function choose(file, type = 'change') {
	const input = document.querySelector('input[type="file"]')
	Object.defineProperty(input, 'files', { value: [file], configurable: true })
	input.dispatchEvent(new window.Event(type))
	await nextTick()
}

/** The picked file's name and size hint, or undefined when none is shown. */
function fileHint() {
	return [...document.querySelectorAll('.import-dialog__hint')]
		.find((hint) => hint.textContent.includes(' KB'))?.textContent.trim()
}

async function openEditor(wrapper, theRow) {
	wrapper.vm.startDecision(theRow)
	await nextTick()
	return findButton('Resolver')
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

describe('ImportDialog picked file', () => {
	let wrapper
	beforeEach(() => {
		wrapper = dialog(null)
	})
	afterEach(() => wrapper.unmount())

	it('uploads the file a same-path re-pick reported as cancel', async () => {
		const edited = planilla(3)
		await choose(planilla(1))
		await choose(edited, 'cancel')
		findButton('Revisar').click()

		expect(wrapper.emitted('upload')[0][0]).toBe(edited)
		expect(fileHint()).toBe('planilla.csv · 3 KB')
	})

	it('starts with no file after Descartar', async () => {
		await choose(planilla(1))
		await wrapper.setProps({ review: { batch: { id: 11, state: 'staged' }, rows: [] } })
		await wrapper.setProps({ review: null })

		expect(fileHint()).toBeUndefined()
		expect(findButton('Revisar').disabled).toBe(true)
	})

	it('keeps the pick through a refusal, and a new pick replaces it', async () => {
		const first = planilla(1)
		const second = planilla(2)
		await choose(first)
		await wrapper.setProps({ error: 'El archivo no tiene filas debajo del encabezado.' })
		findButton('Revisar').click()
		await choose(second)
		findButton('Revisar').click()

		const [[sentFirst], [sentSecond]] = wrapper.emitted('upload')
		expect(sentFirst).toBe(first)
		expect(sentSecond).toBe(second)
		expect(fileHint()).toBe('planilla.csv · 2 KB')
	})
})
