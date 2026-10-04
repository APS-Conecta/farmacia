// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ExportDialog from './ExportDialog.vue'

describe('ExportDialog', () => {
	it('uses correct feminine gender "la planilla" in explanation hint', () => {
		mount(ExportDialog, {
			attachTo: document.body,
		})
		expect(document.body.textContent).toContain('la planilla es el arsenal que la app muestra')
	})
})
