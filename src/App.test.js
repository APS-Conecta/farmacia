// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import App from './App.vue'

/**
 * The app-root harness (#40): the real App with its real list, form and
 * sidebar, the network replaced by deferreds the test answers. Read only
 * through what a person sees — rows, the sidebar name, inputs, note cards,
 * disabled buttons. #41, #42 and #43 extend this file.
 */

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal(),
	generateUrl: (path) => path,
}))

const A = { id: 1, codigo: 'F-001', producto: 'Amoxicilina', categoryId: 1, retired: false }
const B = { id: 2, codigo: 'F-002', producto: 'Losartán', categoryId: 1, retired: false }
const C = { id: 3, codigo: 'F-003', producto: 'Clorfenamina', categoryId: 1, retired: true }

const STATE = {
	medications: [A, B],
	retired: [C],
	categories: [{ id: 1, slug: 'otros', label: 'Otros' }],
	abastece: [{ id: 1, slug: 'cesfam', label: 'CESFAM' }],
}

// The real loadState throws for a key it does not hold unless a fallback
// is given — NcAppContent reads ('core', 'apps', []) through it.
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		if (app === 'farmacia' && key in STATE) {
			return JSON.parse(JSON.stringify(STATE[key]))
		}
		if (fallback !== undefined) {
			return fallback
		}
		throw new Error(`Could not find initial state ${key} of ${app}`)
	},
}))

/** The next call of one axios verb, held open until the test answers it. */
function hold(verb) {
	let answer
	axios[verb].mockReturnValueOnce(new Promise((resolve, reject) => {
		answer = { resolve, reject }
	}))
	return {
		resolve: async (data) => {
			answer.resolve({ data })
			await flushPromises()
		},
		reject: async (exception) => {
			answer.reject(exception)
			await flushPromises()
		},
	}
}

let wrapper

beforeEach(() => {
	vi.resetAllMocks()
	// safeError logs every refusal on purpose; the test reads the form instead.
	vi.spyOn(console, 'error').mockImplementation(() => {})
	// The Nextcloud page template's skip-links host: NcContent teleports into it.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	wrapper = mount(App)
})

afterEach(() => {
	wrapper.unmount()
})

function rowNames() {
	return wrapper.findAll('.list-item-content__name').map((name) => name.text())
}

async function select(producto) {
	const row = wrapper.findAll('.list-item__anchor')
		.find((anchor) => anchor.find('.list-item-content__name').text() === producto)
	await row.trigger('click')
}

function button(text) {
	return wrapper.findAll('button').find((candidate) => candidate.text() === text)
}

async function click(text) {
	await button(text).trigger('click')
}

async function closeSidebar() {
	await wrapper.find('.app-sidebar__close').trigger('click')
}

/** The sidebar's heading, or null when no sidebar is open. */
function sidebarName() {
	const name = wrapper.find('.app-sidebar-header__mainname')
	return name.exists() ? name.text() : null
}

function field(label) {
	const element = wrapper.findAll('.input-field__label').find((candidate) => candidate.text() === label)
	return wrapper.find(`[id="${element.attributes('for')}"]`)
}

async function type(values) {
	for (const [label, value] of Object.entries(values)) {
		await field(label).setValue(value)
	}
}

function typed(values) {
	return Object.fromEntries(Object.keys(values).map((label) => [label, field(label).element.value]))
}

function guardar() {
	return wrapper.find('form button[type="submit"]')
}

async function submit() {
	await wrapper.find('form').trigger('submit')
}

/** The three ways off the open record; while a write flies, none may move the sidebar. */
const SWITCHES = [() => select('Losartán'), () => click('Agregar medicamento'), closeSidebar]

/** Once an answer has landed the hold is gone: another record opens. */
async function expectReleased() {
	await select('Losartán')
	expect(sidebarName()).toBe('Losartán')
}

const SENTENCE = 'El código «F-002» ya existe.'
const REFUSED = { response: { status: 422, data: { message: SENTENCE } } }

function errorCard() {
	const card = wrapper.find('.app-sidebar .notecard--error')
	return card.exists() ? card.text() : null
}

describe('a Guardar in flight holds the sidebar on its record (#40)', () => {
	it.each([
		{
			verb: 'put',
			open: () => select('Amoxicilina'),
			// The sidebar name reads the draft, so it shows what was typed.
			heading: 'Amoxicilina 500 mg',
			values: { Producto: 'Amoxicilina 500 mg ' },
			stored: { ...A, producto: 'Amoxicilina 500 mg' },
		},
		{
			verb: 'post',
			open: () => click('Agregar medicamento'),
			heading: 'Nuevo medicamento',
			values: { Código: 'F-004', Producto: 'Paracetamol ' },
			stored: { id: 4, codigo: 'F-004', producto: 'Paracetamol', categoryId: 1, retired: false },
		},
	])('a $verb answer lands on the draft it was sent for', async ({ verb, open, heading, values, stored }) => {
		await open()
		await type(values)
		const request = hold(verb)
		await submit()

		for (const attempt of SWITCHES) {
			await attempt()
			expect(sidebarName()).toBe(heading)
			expect(typed(values)).toEqual(values)
		}
		expect(guardar().attributes('disabled')).toBeDefined()

		await request.resolve(stored)

		expect(sidebarName()).toBe(stored.producto)
		expect(field('Producto').element.value).toBe(stored.producto)
		expect(rowNames()).toContain(stored.producto)
		expect(rowNames()).toContain('Losartán')
		await expectReleased()
	})
})

describe('a refused Guardar stays on the form it was sent from (#40)', () => {
	it.each([
		{ refusal: 'a 422', exception: REFUSED, sentence: SENTENCE },
		{
			refusal: 'no response',
			exception: new Error('Network Error'),
			sentence: 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.',
		},
	])('$refusal renders only on that form', async ({ exception, sentence }) => {
		await select('Amoxicilina')
		await type({ Código: 'F-002' })
		const request = hold('put')
		await submit()
		await select('Losartán')

		await request.reject(exception)

		expect(sidebarName()).toBe('Amoxicilina')
		expect(errorCard()).toBe(sentence)
		expect(field('Código').element.value).toBe('F-002')
		await expectReleased()
		expect(errorCard()).toBeNull()
	})
})

describe('a close during a Guardar (#40)', () => {
	it('keeps the sidebar on its record until the answer lands, then closes', async () => {
		await select('Amoxicilina')
		await type({ Producto: 'Amoxicilina 500 mg ' })
		const request = hold('put')
		await submit()
		await closeSidebar()

		expect(sidebarName()).toBe('Amoxicilina 500 mg')

		await request.resolve({ ...A, producto: 'Amoxicilina 500 mg' })

		expect(sidebarName()).toBe('Amoxicilina 500 mg')
		expect(field('Producto').element.value).toBe('Amoxicilina 500 mg')

		await closeSidebar()
		await flushPromises()

		expect(sidebarName()).toBeNull()
	})
})

describe('a Retirar in flight holds the sidebar on its record (#40)', () => {
	it('lands the retired record in the sidebar it was sent from', async () => {
		await select('Amoxicilina')
		await click('Retirar del arsenal')
		const request = hold('delete')
		await click('Retirar')

		for (const attempt of SWITCHES) {
			await attempt()
			expect(sidebarName()).toBe('Amoxicilina')
		}
		expect(button('Retirar').attributes('disabled')).toBeDefined()
		await submit()
		expect(axios.put).not.toHaveBeenCalled()

		await request.resolve({ ...A, retired: true })

		expect(rowNames()).not.toContain('Amoxicilina')
		expect(sidebarName()).toBe('Amoxicilina')
		expect(wrapper.find('.app-sidebar .notecard--warning').text()).toContain('Este registro está retirado')
		expect(button('Restaurar').exists()).toBe(true)

		await click('Ver retirados')

		expect(rowNames()).toContain('Amoxicilina')
	})
})

describe('a Restaurar in flight holds the sidebar on its record (#40)', () => {
	it('lands the restored record in the sidebar it was sent from', async () => {
		await click('Ver retirados')
		await select('Clorfenamina')
		const request = hold('post')
		await click('Restaurar')

		expect(button('Restaurar').attributes('disabled')).toBeDefined()
		await click('Ver activos')
		await select('Losartán')
		expect(sidebarName()).toBe('Clorfenamina')

		await request.resolve({ ...C, retired: false })

		expect(rowNames()).toContain('Clorfenamina')
		expect(sidebarName()).toBe('Clorfenamina')
		expect(guardar().text()).toBe('Guardar')
	})
})

// Green before #40 too: a guard against writeMedication drifting from what
// each write did on its own.
describe('with no switching, the Medicamento writes behave as before (#40)', () => {
	it('Guardar rebuilds the form from the stored record', async () => {
		await select('Amoxicilina')
		await type({ Producto: 'Amoxicilina 500 mg ' })
		const request = hold('put')
		await submit()
		await request.resolve({ ...A, producto: 'Amoxicilina 500 mg' })

		expect(field('Producto').element.value).toBe('Amoxicilina 500 mg')
		expect(guardar().attributes('disabled')).toBeUndefined()
		await expectReleased()
	})

	it('a refusal shows on the form', async () => {
		await select('Amoxicilina')
		const request = hold('put')
		await submit()
		await request.reject(REFUSED)

		expect(errorCard()).toBe(SENTENCE)
		expect(guardar().attributes('disabled')).toBeUndefined()
		await expectReleased()
	})

	it('Retirar keeps the sidebar open on the retired record', async () => {
		await select('Amoxicilina')
		await click('Retirar del arsenal')
		const request = hold('delete')
		await click('Retirar')
		await request.resolve({ ...A, retired: true })

		expect(sidebarName()).toBe('Amoxicilina')
		expect(button('Restaurar').attributes('disabled')).toBeUndefined()
		await expectReleased()
	})
})

/** A batch as ImportService::batches() serves it. */
function batch(fields) {
	return {
		actor: 'admin',
		startedAt: '2026-10-26 10:00:00',
		appliedAt: '2026-10-26 10:01:00',
		originalFilename: 'arsenal.csv',
		fileHash: 'abc',
		created: 0,
		updated: 0,
		skipped: 0,
		duplicates: 0,
		unsupported: 0,
		unchanged: 0,
		revertsId: null,
		isRevert: false,
		undone: false,
		state: 'applied',
		...fields,
	}
}

const APPLIED = batch({ id: 12, updated: 40 })
const REVERT = batch({ id: 13, isRevert: true, revertsId: 12, updated: 37, skipped: 3 })
const ALREADY_UNDONE = 'Ese lote ya está deshecho.'

/** NcDialog teleports out of the wrapper's tree, so its buttons are found in the document. */
function dialogButton(text) {
	return [...document.querySelectorAll('button')]
		.find((candidate) => candidate.textContent.trim() === text)
}

async function clickInDialog(text) {
	dialogButton(text).click()
	await flushPromises()
}

/** Opens the import on a list holding lote 12. */
async function openImport() {
	axios.get.mockResolvedValueOnce({ data: [APPLIED] })
	await click('Importar planilla')
	await flushPromises()
}

describe('App lote undo (#43)', () => {
	beforeEach(() => {
		vi.stubGlobal('location', { reload: vi.fn() })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('keeps the dialog open on the refreshed list after an undo, and reloads on Cerrar', async () => {
		await openImport()
		axios.post.mockResolvedValueOnce({ data: REVERT })
		axios.get.mockResolvedValueOnce({ data: [REVERT, { ...APPLIED, undone: true }] })

		await clickInDialog('Deshacer')

		expect(axios.post).toHaveBeenCalledWith('/apps/farmacia/import/12/revert')
		expect(window.location.reload).not.toHaveBeenCalled()
		expect(document.querySelector('.import-dialog')).not.toBeNull()
		expect(document.body.textContent).toContain('37 deshechos')
		expect(document.body.textContent).toContain('3 sin deshacer')
		expect(dialogButton('Deshacer')).toBeUndefined()

		await clickInDialog('Cerrar')

		expect(window.location.reload).toHaveBeenCalledTimes(1)
	})

	it('shows a refused undo and never reloads', async () => {
		await openImport()
		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: ALREADY_UNDONE } } })

		await clickInDialog('Deshacer')

		expect(document.body.textContent).toContain(ALREADY_UNDONE)
		expect(window.location.reload).not.toHaveBeenCalled()

		await clickInDialog('Cerrar')

		expect(window.location.reload).not.toHaveBeenCalled()
	})

	it('reloads when the undo lands after Cerrar', async () => {
		await openImport()
		const request = hold('post')

		await clickInDialog('Deshacer')
		await clickInDialog('Cerrar')

		expect(window.location.reload).not.toHaveBeenCalled()

		await request.resolve(REVERT)

		expect(window.location.reload).toHaveBeenCalledTimes(1)
	})
})
