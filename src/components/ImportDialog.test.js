// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, reactive } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import ImportDialog from './ImportDialog.vue'
import { BATCH_STATE_LABELS, revertCounters } from '../import.js'

enableAutoUnmount(afterEach)

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
			review: reactive({ batch: { id: 11, state: 'staged' }, rows: review }),
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

describe('ImportDialog recent batches', () => {
	it('says what an undo did on its row, and leaves the other rows as they were', async () => {
		const revert = {
			id: 13,
			originalFilename: 'arsenal.csv',
			startedAt: '2026-10-26 10:05',
			state: 'applied',
			isRevert: true,
			revertsId: 12,
			undone: false,
			updated: 37,
			skipped: 3,
		}
		const applied = {
			id: 12,
			originalFilename: 'arsenal.csv',
			startedAt: '2026-10-26 10:00',
			state: 'applied',
			isRevert: false,
			undone: true,
			updated: 40,
			skipped: 0,
		}
		const wrapper = mount(ImportDialog, {
			props: { batches: [revert, applied], review: null, categories: [], abastece: [] },
			attachTo: document.body,
		})
		await nextTick()

		const [revertRow, appliedRow] = document.querySelectorAll('.import-dialog__batch')
		expect(appliedRow.querySelector('.import-dialog__hint').textContent.replace(/\s+/g, ' ').trim())
			.toBe(`${applied.originalFilename} · ${applied.startedAt} · ${BATCH_STATE_LABELS.applied}`)
		expect(revertRow.textContent).toContain(revertCounters(revert))
		wrapper.unmount()
	})
})

/** The row as decideRow patches it back: staged, owing nothing. */
function decided(theRow) {
	return { ...theRow, status: 'staged', payload: { ...theRow.payload, pending: {} } }
}

function listed(selector = '.import-dialog__row') {
	return document.querySelectorAll(selector).length
}

function onlyPendingSwitch() {
	return document.querySelector('.import-dialog input[type="checkbox"]')
}

async function filtering(rows) {
	const wrapper = dialog(rows)
	onlyPendingSwitch().click()
	await nextTick()
	return wrapper
}

describe('ImportDialog owed-rows switch', () => {
	const owed = { categoria: 'Desconocida' }
	it('lists every row once the last owed row is decided in place', async () => {
		const wrapper = await filtering([row(1, owed), decided(row(2, {}))])
		expect(listed()).toBe(1)
		wrapper.props('review').rows.splice(0, 1, decided(row(1, owed)))
		await nextTick()
		expect(listed()).toBe(2)
	})

	it('starts a replacing review with the switch off', async () => {
		const wrapper = await filtering([row(1, owed), decided(row(2, {}))])
		await wrapper.setProps({ review: null })
		await wrapper.setProps({ review: { batch: { id: 12, state: 'staged' }, rows: [row(3, owed), decided(row(4, {}))] } })
		expect(onlyPendingSwitch().checked).toBe(false)
		expect(listed()).toBe(2)
	})

	it('keeps listing exactly the rows still owed', async () => {
		const wrapper = await filtering([row(1, owed), row(2, owed), decided(row(3, {}))])
		wrapper.props('review').rows.splice(0, 1, decided(row(1, owed)))
		await nextTick()
		expect(listed()).toBe(1)
		expect(listed('.import-dialog__row--owing')).toBe(1)
	})
})
