// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import MedicationDetail from './MedicationDetail.vue'
import { draftFrom, FDA_OPTIONS } from '../payload.js'

/**
 * The L2-16 pin, tested the way the app achieves it: the key lives on
 * App.vue's sidebar, so an ISOLATED setProps would never remount. The host
 * below carries :key="draft.id" — the same key the app's sidebar has — and
 * a refactor that drops the keyed mount goes red here, not in a person's
 * face two records later.
 */
const Host = defineComponent({
	components: { MedicationDetail },
	data() {
		return { draft: draftFrom({ id: 1, producto: 'A', retired: false }) }
	},
	render() {
		return h(MedicationDetail, {
			key: this.draft.id,
			draft: this.draft,
			categories: [],
			abastece: [],
		})
	},
})

function detailOf(wrapper) {
	return wrapper.getComponent(MedicationDetail)
}

describe('MedicationDetail inside a keyed host (L2-16)', () => {
	it('resets an armed Retirar when the record changes', async () => {
		const wrapper = mount(Host)

		// Arm the two-step confirm on record A.
		detailOf(wrapper).vm.confirmingRetire = true
		await nextTick()
		expect(detailOf(wrapper).text()).toContain('Se retira del arsenal y de la lista')

		// Select record B: the key changes, the sidebar remounts, the armed
		// confirm must not cross records.
		wrapper.vm.draft = draftFrom({ id: 2, producto: 'B', retired: false })
		await nextTick()

		expect(detailOf(wrapper).vm.confirmingRetire).toBe(false)
		expect(detailOf(wrapper).text()).not.toContain('Se retira del arsenal y de la lista')
		expect(detailOf(wrapper).text()).toContain('Guardar')
	})

	it('resets the adder text the same way', async () => {
		const wrapper = mount(Host)

		detailOf(wrapper).vm.newRestriction = 'Solo receta retenida'
		wrapper.vm.draft = draftFrom({ id: 2, producto: 'B', retired: false })
		await nextTick()

		expect(detailOf(wrapper).vm.newRestriction).toBe('')
	})

	it('renders the FDA options from payload.js (L2-18)', () => {
		const wrapper = mount(Host)

		// The import is the value's one home — the component's copy is gone,
		// and the picker serves exactly the five the server refuses by.
		expect(detailOf(wrapper).vm.FDA_OPTIONS).toEqual(FDA_OPTIONS)
		expect(FDA_OPTIONS).toEqual(['A', 'B', 'C', 'D', 'X'])
	})

	it('adds GES and restriction items on Enter key press', async () => {
		const wrapper = mount(Host)
		const detail = detailOf(wrapper)

		const textFields = detail.findAllComponents({ name: 'NcTextField' })
		const gesField = textFields.find((tf) => tf.props('label') === 'Agregar número GES')
		await gesField.find('input').setValue('80')
		await gesField.find('input').trigger('keydown.enter')

		expect(wrapper.vm.draft.ges).toContain('80')
		expect(detail.vm.newGes).toBe('')

		const restrictionField = textFields.find((tf) => tf.props('label') === 'Agregar restricción')
		await restrictionField.find('input').setValue('Solo receta retenida')
		await restrictionField.find('input').trigger('keydown.enter')

		expect(wrapper.vm.draft.restricciones).toContain('Solo receta retenida')
		expect(detail.vm.newRestriction).toBe('')
	})
})
