// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import App from './App.vue'
import VocabularyDialog from './components/VocabularyDialog.vue'

// Both lists use id 1: the real tables collide the same way, which is why
// a list stored under the wrong vocabulary saves the wrong ids silently.
const { STATE } = vi.hoisted(() => ({
	STATE: {
		medications: [],
		retired: [],
		categories: [{ id: 1, slug: 'analgesicos', label: 'Analgésicos' }],
		abastece: [{ id: 1, slug: 'cenabast', label: 'CENABAST' }],
	},
}))

// The fallback matters: NcAppContent reads loadState('core', 'apps', []).
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (app === 'farmacia' ? STATE[key] : fallback),
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))

const CATEGORIES_BUTTON = 'Gestionar categorías terapéuticas'
const ABASTECE_BUTTON = 'Gestionar canales de abastece'
const ADDED = { id: 2, slug: 'antibioticos', label: 'Antibióticos' }
const CATEGORIES_AFTER = [...STATE.categories, ADDED]
const ABASTECE_AFTER = [...STATE.abastece, ADDED]
const SENTENCE = 'Ya existe una categoría con ese nombre.'
const REFUSAL = { response: { status: 422, data: { message: SENTENCE } } }

let wrapper

function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

/** Nextcloud's page layout carries #skip-actions, NcContent's teleport
 *  target; NcDialog teleports too, so the app is attached to the document. */
function mountApp() {
	document.body.innerHTML = '<div id="skip-actions"></div>'
	wrapper = mount(App, { attachTo: document.body })
	return wrapper
}

/** The footer buttons are how a person opens either vocabulary. */
async function openDialog(wrapper, buttonLabel) {
	await wrapper.get(`button[aria-label="${buttonLabel}"]`).trigger('click')
}

/** The dialog remounts on every open (v-if), so it is looked up fresh. */
function dialog(wrapper) {
	return wrapper.findComponent(VocabularyDialog)
}

async function closeDialog(wrapper) {
	dialog(wrapper).vm.$emit('close')
	await nextTick()
}

/** Adds a word with the POST held open until the test answers it. */
async function heldWrite(wrapper, buttonLabel) {
	await openDialog(wrapper, buttonLabel)
	const held = deferred()
	axios.post.mockReturnValueOnce(held.promise)
	dialog(wrapper).vm.$emit('create', 'Antibióticos')
	return held
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	wrapper.unmount()
	vi.restoreAllMocks()
})

describe('App vocabulary writes', () => {
	it('a categories answer that lands after Cerrar replaces categories, not abastece', async () => {
		const app = mountApp()
		const held = await heldWrite(app, CATEGORIES_BUTTON)
		await closeDialog(app)

		held.resolve({ data: CATEGORIES_AFTER })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/farmacia/categories', { label: 'Antibióticos' })
		expect(app.vm.categories).toEqual(CATEGORIES_AFTER)
		expect(app.vm.abastece).toEqual(STATE.abastece)
	})

	it('an abastece answer that lands in the Categorías dialog replaces abastece', async () => {
		const app = mountApp()
		const held = await heldWrite(app, ABASTECE_BUTTON)
		await closeDialog(app)
		await openDialog(app, CATEGORIES_BUTTON)

		held.resolve({ data: ABASTECE_AFTER })
		await flushPromises()

		expect(app.vm.abastece).toEqual(ABASTECE_AFTER)
		expect(app.vm.categories).toEqual(STATE.categories)
		expect(dialog(app).props('entries')).toEqual(STATE.categories)
	})

	it('a categories refusal does not show in the Abastece dialog', async () => {
		const app = mountApp()
		const held = await heldWrite(app, CATEGORIES_BUTTON)
		await closeDialog(app)
		await openDialog(app, ABASTECE_BUTTON)

		held.reject(REFUSAL)
		await flushPromises()

		expect(dialog(app).props('error')).toBe('')
		expect(document.body.textContent).not.toContain(SENTENCE)
		expect(console.error).toHaveBeenCalledWith('farmacia: could not write the vocabulary (422)', expect.anything())
	})

	it('with the dialog left open, a write replaces its own list and shows its own refusal', async () => {
		const app = mountApp()
		const answered = await heldWrite(app, CATEGORIES_BUTTON)
		answered.resolve({ data: CATEGORIES_AFTER })
		await flushPromises()

		expect(app.vm.categories).toEqual(CATEGORIES_AFTER)
		expect(dialog(app).props('entries')).toEqual(CATEGORIES_AFTER)
		expect(app.vm.abastece).toEqual(STATE.abastece)

		const refused = await heldWrite(app, CATEGORIES_BUTTON)
		refused.reject(REFUSAL)
		await flushPromises()

		expect(dialog(app).props('error')).toBe(SENTENCE)
	})
})
