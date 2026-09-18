<template>
	<NcAppContentList>
		<div class="medication-list__header">
			<span class="medication-list__count">
				{{ displayed.length }} {{ displayed.length === 1 ? 'medicamento' : 'medicamentos' }}
			</span>
			<NcButton variant="tertiary"
				:pressed="showRetired"
				@click="$emit('update:show-retired', !showRetired)">
				{{ showRetired ? 'Ver activos' : 'Ver retirados' }}
			</NcButton>
		</div>

		<!--
			type="search", not a plain text field: the browser draws its own clear
			button and handles Escape, two behaviours not written here — territorio's
			FeatureList comment, copied because the reason is not territorio's alone.
		-->
		<div class="medication-list__search">
			<NcTextField :model-value="filter.query"
				type="search"
				label="Buscar"
				:show-trailing-button="filter.query !== ''"
				trailing-button-label="Limpiar la búsqueda"
				@update:model-value="$emit('update:query', $event)"
				@trailing-button-click="$emit('update:query', '')" />
		</div>

		<!-- Three empty states: search with no results, genuinely empty arsenal,
		     empty retired list. "Nothing matched" is a different fact from "there
		     is nothing here" — territorio's FeatureList lesson. -->
		<NcEmptyContent v-if="displayed.length === 0 && filter.query !== ''"
			:name="`Nada coincide con «${filter.query}»`"
			description="Pruebe con menos palabras, o limpie la búsqueda.">
			<template #icon>
				<MagnifyIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="displayed.length === 0 && !showRetired"
			name="Todavía no hay medicamentos"
			description="Importe la planilla del CESFAM o agregue el primer medicamento.">
			<template #icon>
				<PillIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="displayed.length === 0"
			name="No hay medicamentos retirados"
			description="Lo que se retira del arsenal aparece aquí y se puede restaurar.">
			<template #icon>
				<PillIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcListItem v-for="m in displayed"
			:key="m.id"
			:name="m.producto"
			:force-display-actions="false"
			@click="$emit('select', m.id)">
			<template #subname>
				{{ categoryLabel(m) }}
				<span v-if="m.fdaEmbarazo"> · FDA {{ m.fdaEmbarazo }}</span>
				<span v-if="m.retired" class="medication-list__retired-badge"> · Retirado</span>
			</template>
			<template #icon>
				<span class="medication-list__swatch" aria-hidden="true">
					<PillIcon :size="16" />
				</span>
			</template>
		</NcListItem>
	</NcAppContentList>
</template>

<script>
import NcAppContentList from '@nextcloud/vue/components/NcAppContentList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PillIcon from 'vue-material-design-icons/Pill.vue'

import { indexCategories, listFor } from '../filters.js'

export default {
	name: 'MedicationList',

	components: { NcAppContentList, NcButton, NcEmptyContent, NcListItem, NcTextField, MagnifyIcon, PillIcon },

	props: {
		medications: { type: Array, required: true },
		retiredMedications: { type: Array, required: true },
		categories: { type: Array, required: true },
		filter: { type: Object, required: true },
		showRetired: { type: Boolean, required: true },
	},

	emits: ['select', 'update:query', 'update:show-retired'],

	computed: {
		categoriesById() {
			return indexCategories(this.categories)
		},

		displayed() {
			return listFor({
				medications: this.medications,
				retiredMedications: this.retiredMedications,
				showRetired: this.showRetired,
				filter: this.filter,
				categoriesById: this.categoriesById,
			})
		},
	},

	methods: {
		categoryLabel(medication) {
			return this.categoriesById.get(medication.categoryId)?.label ?? 'Sin categoría'
		},
	},
}
</script>

<style scoped>
.medication-list__header {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.medication-list__count {
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.medication-list__search {
	padding: calc(var(--default-grid-baseline) * 2);
}

.medication-list__swatch {
	color: var(--color-primary-element);
}

.medication-list__retired-badge {
	color: var(--color-warning);
}
</style>
