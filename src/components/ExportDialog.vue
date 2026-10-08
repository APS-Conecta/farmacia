<template>
	<!--
		Said before the download runs — the import dialog's own doctrine,
		mirrored: the person knows what the file carries before the browser
		opens a save box. The download itself stays App.vue's (the S5
		doctrine: App.vue owns every request), so this component only says
		what the file is and emits.
	-->
	<NcDialog name="Exportar planilla" @closing="$emit('close')">
		<div class="export-dialog">
			<p class="export-dialog__hint">
				El arsenal farmacológico completo como CSV, con todas las
				columnas, listo para abrir en Excel o LibreOffice.
			</p>
			<p class="export-dialog__hint">
				Las celdas con varios valores (GES, restricciones, canales de
				abastece) van unidas con «|». El archivo se puede reimportar: la
				revisión lo recibe fila por fila como cualquier planilla.
			</p>
			<p class="export-dialog__hint">
				Los medicamentos retirados no van: la planilla es el arsenal que
				la app muestra.
			</p>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				Cancelar
			</NcButton>
			<NcButton variant="primary" @click="$emit('download')">
				Descargar
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

export default {
	name: 'ExportDialog',

	components: { NcButton, NcDialog },

	emits: ['close', 'download'],
}
</script>

<style scoped>
.export-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.export-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
