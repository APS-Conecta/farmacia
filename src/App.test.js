// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import App from './App.vue'

// A full replacement: an HTTP call a test did not arrange fails loudly
// instead of reaching the real client.
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
// Partial: the @nextcloud/vue chunks import the router's other helpers.
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
// Partial: NcAppContent reads loadState('core', 'apps', []). App reads only
// array keys (medications, retired, categories, abastece).
vi.mock('@nextcloud/initial-state', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		loadState: (app, key, fallback) => (app === 'farmacia' ? [] : actual.loadState(app, key, fallback)),
	}
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
const SENTENCE = 'Ese lote ya está deshecho.'

let wrapper

function deferred() {
	let resolve
	const promise = new Promise((res) => {
		resolve = res
	})
	return { promise, resolve }
}

/** NcDialog teleports, so buttons are found in the document by their text. */
function button(text) {
	return [...document.querySelectorAll('button')]
		.find((candidate) => candidate.textContent.trim() === text)
}

async function click(text) {
	button(text).click()
	await flushPromises()
}

/** Mounts the app and opens the import on a list holding lote 12. */
async function openImport() {
	// NcContent teleports into Nextcloud's #skip-actions.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	wrapper = mount(App, { attachTo: document.body })
	axios.get.mockResolvedValueOnce({ data: [APPLIED] })
	await click('Importar planilla')
}

beforeEach(() => {
	vi.stubGlobal('location', { reload: vi.fn() })
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	wrapper.unmount()
	vi.unstubAllGlobals()
	// Reset, not clear: a test that fails before taking its queued
	// mock*Once answers must not hand them to the next test.
	vi.resetAllMocks()
	vi.restoreAllMocks()
})

describe('App lote undo', () => {
	it('keeps the dialog open on the refreshed list after an undo, and reloads on Cerrar', async () => {
		await openImport()
		axios.post.mockResolvedValueOnce({ data: REVERT })
		axios.get.mockResolvedValueOnce({ data: [REVERT, { ...APPLIED, undone: true }] })

		await click('Deshacer')

		expect(axios.post).toHaveBeenCalledWith('/apps/farmacia/import/12/revert')
		expect(window.location.reload).not.toHaveBeenCalled()
		expect(document.querySelector('.import-dialog')).not.toBeNull()
		expect(document.body.textContent).toContain('37 deshechos')
		expect(document.body.textContent).toContain('3 sin deshacer')
		expect(button('Deshacer')).toBeUndefined()

		await click('Cerrar')

		expect(window.location.reload).toHaveBeenCalledTimes(1)
	})

	it('shows a refused undo and never reloads', async () => {
		await openImport()
		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: SENTENCE } } })

		await click('Deshacer')

		expect(document.body.textContent).toContain(SENTENCE)
		expect(window.location.reload).not.toHaveBeenCalled()

		await click('Cerrar')

		expect(window.location.reload).not.toHaveBeenCalled()
	})

	it('reloads when the undo lands after Cerrar', async () => {
		await openImport()
		const held = deferred()
		axios.post.mockReturnValueOnce(held.promise)

		await click('Deshacer')
		await click('Cerrar')

		expect(window.location.reload).not.toHaveBeenCalled()

		held.resolve({ data: REVERT })
		await flushPromises()

		expect(window.location.reload).toHaveBeenCalledTimes(1)
	})
})
