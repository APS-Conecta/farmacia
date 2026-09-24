// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
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
})
