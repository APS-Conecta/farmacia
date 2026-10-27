// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'
import MedicationList from './components/MedicationList.vue'

vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (path) => path }))
vi.mock('@nextcloud/initial-state', async (importOriginal) => ({ ...(await importOriginal()), loadState: () => [] }))
vi.mock('@nextcloud/axios', () => {
	const answer = async () => ({ data: [] })
	return { default: { get: answer, post: answer, put: answer, delete: answer } }
})
enableAutoUnmount(afterEach)

const KEPT = 'farmacia.view'
const DEFAULTS = { lens: 'arsenal', query: '', showRetired: false, active: ['Arsenal Farmacológico'] }
const fails = (name) => () => { throw new window.DOMException(name, name) }
const navItems = (wrapper) => wrapper.findAllComponents({ name: 'NcAppNavigationItem' })
let reload

async function mountApp() {
	const wrapper = mount(App)
	await flushPromises()
	return wrapper
}

/** What the person sees: the list's lente, search and Ver retirados, and the highlighted lente. */
function view(wrapper) {
	const { filter, showRetired } = wrapper.getComponent(MedicationList).props()
	const active = navItems(wrapper).filter((item) => item.props('active')).map((item) => item.props('name'))
	return { ...filter, showRetired, active }
}

/** Alt-tab away and back: the blur/focus pair the app reloads on. */
function returnToWindow() {
	window.dispatchEvent(new window.Event('blur'))
	window.dispatchEvent(new window.Event('focus'))
}

beforeEach(() => {
	// NcContent teleports into #skip-actions, which Nextcloud's page layout provides.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	reload = vi.fn()
	vi.stubGlobal('location', { reload })
	window.sessionStorage.clear()
})
afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('the focus-reload keeps the view (#42)', () => {
	it('keeps the lente, search and Ver retirados across the focus-reload, once', async () => {
		const first = await mountApp()
		const embarazo = navItems(first).find((item) => item.props('name') === 'Fármacos en embarazo')
		await embarazo.get('.app-navigation-entry-link').trigger('click')
		first.getComponent(MedicationList).vm.$emit('update:query', 'amoxi')
		first.getComponent(MedicationList).vm.$emit('update:show-retired', true)
		returnToWindow()
		expect(reload).toHaveBeenCalledTimes(1)
		first.unmount()

		const restored = await mountApp()
		expect(view(restored)).toEqual({ lens: 'embarazo', query: 'amoxi', showRetired: true, active: ['Fármacos en embarazo'] })
		restored.unmount()
		expect(view(await mountApp())).toEqual(DEFAULTS)
	})

	const keep = (value) => () => window.sessionStorage.setItem(KEPT, value)
	it.each([
		['nothing kept', () => {}],
		['accessor throws', () => vi.spyOn(window, 'sessionStorage', 'get').mockImplementation(fails('SecurityError'))],
		['malformed', keep('{')],
		['unknown lens', keep('{"lens":"pediatria","query":"x","showRetired":true}')],
		['wrong types', keep('{"lens":"renal","query":5,"showRetired":"yes"}')],
	])('mounts the defaults and throws nothing: %s', async (_, arrange) => {
		const blocked = arrange()
		const wrapper = await mountApp()
		expect(view(wrapper)).toEqual(DEFAULTS)
		expect(wrapper.find('.farmacia__state-failed').exists()).toBe(false)
		if (blocked) {
			expect(blocked).toHaveBeenCalled()
			blocked.mockRestore()
		}
		expect(window.sessionStorage.getItem(KEPT)).toBeNull()
	})

	it('a save that throws still reloads', async () => {
		await mountApp()
		const full = vi.spyOn(Object.getPrototypeOf(window.sessionStorage), 'setItem').mockImplementation(fails('QuotaExceededError'))
		returnToWindow()
		expect(full).toHaveBeenCalled()
		expect(reload).toHaveBeenCalledTimes(1)
	})

	it.each(['Agregar medicamento', 'Importar planilla', 'Exportar planilla', 'Categorías'])('a guarded focus neither reloads nor keeps a view: %s', async (button) => {
		const wrapper = await mountApp()
		await wrapper.findAll('button').find((b) => b.text() === button).trigger('click')
		returnToWindow()
		expect(reload).not.toHaveBeenCalled()
		expect(window.sessionStorage.getItem(KEPT)).toBeNull()
	})
})
