// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VocabularyDialog from './VocabularyDialog.vue'

const ENTRIES = [{ id: 1, slug: 'renombrable', label: 'Renombrable' }]
const RENAMED = [{ id: 1, slug: 'renombrable', label: 'Nuevo nombre' }]

function dialog(entries = ENTRIES) {
	return mount(VocabularyDialog, {
		props: {
			which: 'categories',
			entries,
			error: '',
		},
		// NcDialog teleports its body out of the wrapper's tree; the DOM
		// reads below go through the document.
		attachTo: document.body,
	})
}

/** Opens the rename field on the first row, the way a person does. NcDialog
 *  teleports, so the button is looked up in the document. */
async function openRename(wrapper) {
	const name = [...document.querySelectorAll('.vocabulary-dialog__name')][0]
	name.click()
	await nextTick()
}

describe('VocabularyDialog rename (L2-20)', () => {
	it('keeps the field open and the typed label when the write is refused', async () => {
		const wrapper = dialog()
		await openRename(wrapper)

		wrapper.vm.draftLabel = 'Corrección escritada'
		await wrapper.vm.commitRename(ENTRIES[0])

		// The parent answered nothing new (a refusal): the entries prop did
		// not change, so the watcher stays silent and the text stays.
		expect(wrapper.vm.editing).toBe('renombrable')
		expect(wrapper.vm.draftLabel).toBe('Corrección escritada')
		wrapper.unmount()
	})

	it('closes the field when the rename lands — the fresh entries prop', async () => {
		const wrapper = dialog()
		await openRename(wrapper)

		wrapper.vm.draftLabel = 'Nuevo nombre'
		await wrapper.vm.commitRename(ENTRIES[0])
		expect(wrapper.vm.editing).toBe('renombrable')

		// Success is observable: the write answers with the whole fresh
		// list down the prop, and THAT closes the field.
		await wrapper.setProps({ entries: RENAMED })
		await nextTick()

		expect(wrapper.vm.editing).toBeNull()
		wrapper.unmount()
	})

	it('still closes a rename to the same name — a close, not a round trip', async () => {
		const wrapper = dialog()
		await openRename(wrapper)

		await wrapper.vm.commitRename(ENTRIES[0])

		expect(wrapper.vm.editing).toBeNull()
		expect(wrapper.emitted('rename')).toBeUndefined()
		wrapper.unmount()
	})

	it('clears the adder text when the add lands — the fresh entries prop', async () => {
		const wrapper = dialog()
		wrapper.vm.newLabel = 'Nueva categoría'
		await wrapper.vm.commitAdd()

		expect(wrapper.vm.newLabel).toBe('Nueva categoría')
		expect(wrapper.emitted('create')).toEqual([['Nueva categoría']])

		await wrapper.setProps({ entries: [...ENTRIES, { id: 2, slug: 'nueva', label: 'Nueva categoría' }] })
		await nextTick()

		expect(wrapper.vm.newLabel).toBe('')
		wrapper.unmount()
	})
})

describe('VocabularyDialog landed writes', () => {
	// NcDialog teleports into the document: unmount here so a failing test
	// cannot leave its DOM for the next one to query.
	let wrapper
	afterEach(() => wrapper.unmount())

	it('keeps unsent adder text when a rename lands', async () => {
		wrapper = dialog()
		wrapper.vm.newLabel = 'Antihipertensivos'
		await openRename(wrapper)
		wrapper.vm.draftLabel = 'Nuevo nombre'
		await wrapper.vm.commitRename(ENTRIES[0])

		await wrapper.setProps({ entries: RENAMED })

		expect(wrapper.vm.newLabel).toBe('Antihipertensivos')
		expect(wrapper.vm.editing).toBeNull()
	})

	it('keeps an open rename and its text when an add lands', async () => {
		wrapper = dialog()
		await openRename(wrapper)
		wrapper.vm.draftLabel = 'Corrección'
		wrapper.vm.newLabel = '  X  '
		await wrapper.vm.commitAdd()
		expect(wrapper.emitted('create')).toEqual([['X']])

		await wrapper.setProps({ entries: [...ENTRIES, { id: 2, slug: 'x', label: 'X' }] })

		expect(wrapper.vm.editing).toBe('renombrable')
		expect(wrapper.vm.draftLabel).toBe('Corrección')
		expect(wrapper.vm.newLabel).toBe('')
	})

	it('keeps adder text typed after sending when the earlier add lands', async () => {
		wrapper = dialog()
		wrapper.vm.newLabel = 'A'
		await wrapper.vm.commitAdd()
		wrapper.vm.newLabel = 'B'

		await wrapper.setProps({ entries: [...ENTRIES, { id: 2, slug: 'a', label: 'A' }] })

		expect(wrapper.vm.newLabel).toBe('B')
	})

	it('shows a word added again after its removal landed unarmed', async () => {
		wrapper = dialog()
		document.querySelector('[aria-label="Quitar «Renombrable»"]').click()
		await nextTick()
		const row = document.querySelector('.vocabulary-dialog__confirm').closest('li')
		;[...row.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Quitar').click()
		expect(wrapper.emitted('remove')).toEqual([['renombrable']])

		await wrapper.setProps({ entries: [] })
		await wrapper.setProps({ entries: [{ ...ENTRIES[0] }] })

		expect(document.querySelector('.vocabulary-dialog__confirm')).toBeNull()
		expect(document.querySelector('.vocabulary-dialog__name').textContent.trim()).toBe('Renombrable')
		expect(document.querySelector('[aria-label="Quitar «Renombrable»"]')).not.toBeNull()
	})

	it('keeps Quitar armed on a row still listed when an add lands', async () => {
		wrapper = dialog()
		document.querySelector('[aria-label="Quitar «Renombrable»"]').click()
		await nextTick()
		wrapper.vm.newLabel = 'X'
		await wrapper.vm.commitAdd()

		await wrapper.setProps({ entries: [...ENTRIES, { id: 2, slug: 'x', label: 'X' }] })

		expect(wrapper.vm.confirming).toBe('renombrable')
		expect(document.querySelector('.vocabulary-dialog__confirm')).not.toBeNull()
	})
})
