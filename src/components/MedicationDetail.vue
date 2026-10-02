<template>
	<form class="medication-detail" @submit.prevent="$emit('save')">
		<!--
			Saving and retiring, first in the form and stuck there (territorio's
			issue #35 lesson): a form this tall pushes its last control below the
			fold on a real screen, and «Retirar» was reported as not existing at
			all when that happened to it.

			Inside the form on purpose: Guardar stays a real `type="submit"`,
			which is the thing that was broken for eight territorio slices.
			`type`, not `native-type` — in @nextcloud/vue 9 `type` IS the native
			button type; `nativeType` was the v8 prop and fell through inert.

			Hidden for a retired record: the server refuses edits on one, and a
			Guardar that always ends in the 422 saying so is worse than no
			Guardar. Restaurar lives in the NcNoteCard above this component.
		-->
		<div v-if="!retired" class="medication-detail__actions">
			<NcButton variant="primary" :disabled="saving" type="submit">
				{{ saving ? 'Guardando…' : 'Guardar' }}
			</NcButton>

			<!-- Two-step, inline. Never a browser confirm(): it blocks the page,
			     and there is nothing irreversible to warn about — retiring keeps
			     the record and its children, and it can be restored. -->
			<template v-if="draft.id !== null">
				<NcButton v-if="!confirmingRetire"
					variant="tertiary"
					@click="confirmingRetire = true">
					Retirar del arsenal
				</NcButton>

				<template v-else>
					<p class="medication-detail__hint medication-detail__confirm">
						Se retira del arsenal y de la lista. No se borra: conserva sus
						datos y se puede restaurar.
					</p>
					<NcButton variant="error" @click="$emit('retire')">
						Retirar
					</NcButton>
					<NcButton variant="tertiary" @click="confirmingRetire = false">
						Cancelar
					</NcButton>
				</template>
			</template>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<fieldset class="medication-detail__section">
			<legend>Identidad</legend>

			<NcTextField v-model="draft.codigo" class="medication-detail__field" label="Código" required />
			<NcTextField v-model="draft.producto" class="medication-detail__field" label="Producto" required />
			<NcTextField v-model="draft.farmaco" class="medication-detail__field" label="Fármaco (DCI)" />
			<NcTextField v-model="draft.mg" class="medication-detail__field" label="Mg" />
			<NcTextField v-model="draft.presentacion" class="medication-detail__field" label="Presentación" />

			<!-- Object options ({id, label}) keyed by `label`; the draft keeps the
			     id, and the select keeps the object — mapped through a computed. -->
			<NcSelect v-model="category"
				class="medication-detail__field"
				input-label="Categoría terapéutica"
				:options="categories"
				:clearable="false"
				label="label" />
		</fieldset>

		<fieldset class="medication-detail__section">
			<legend>Cobertura</legend>

			<NcSelect v-model="abasteceSelection"
				class="medication-detail__field"
				input-label="Abastece"
				:options="abastece"
				multiple
				label="label" />

			<NcTextField v-model="draft.programa" class="medication-detail__field" label="Programa" />

			<!-- No `name` attr: NcCheckboxRadioSwitch throws on mount when a
		     NAMED checkbox's model is not an array (group contract) — a
		     standalone flag is not a data set, and the names only ever bought
		     a console error on every sidebar open (surfaced by the L2-21
		     mount tests). -->
			<NcCheckboxRadioSwitch :model-value="draft.trazador"
				@update:model-value="draft.trazador = $event">
				Trazador (uso monitorio / epidemiológico)
			</NcCheckboxRadioSwitch>

			<!--
				GES: add/remove-row list, BoundaryPicker's shape. The row is the
				value typed, kept as typed — the server is the single judge of
				what a valid número GES is, its refusal names the value, and its
				dedupe comes back with the saved payload.
			-->
			<div class="medication-detail__list">
				<p class="medication-detail__list-title">GES</p>
				<div v-for="(ges, index) in draft.ges" :key="index" class="medication-detail__row">
					<span>{{ ges }}</span>
					<NcButton variant="tertiary"
						:aria-label="`Quitar el GES ${ges}`"
						@click="draft.ges.splice(index, 1)">
						Quitar
					</NcButton>
				</div>
				<div class="medication-detail__adder">
					<NcTextField v-model="newGes"
						label="Agregar número GES"
						inputmode="numeric"
						@keydown.enter.prevent="addGes" />
					<NcButton variant="tertiary" :disabled="newGes.trim() === ''" @click="addGes">
						Agregar
					</NcButton>
				</div>
				<p v-if="draft.ges.length === 0" class="medication-detail__hint">
					Los números de problema AUGE/GES que este medicamento cubre.
				</p>
			</div>

			<div class="medication-detail__list">
				<p class="medication-detail__list-title">Restricciones</p>
				<div v-for="(restriction, index) in draft.restricciones" :key="index" class="medication-detail__row">
					<span>{{ restriction }}</span>
					<NcButton variant="tertiary"
						:aria-label="`Quitar «${restriction}»`"
						@click="draft.restricciones.splice(index, 1)">
						Quitar
					</NcButton>
				</div>
				<div class="medication-detail__adder">
					<NcTextField v-model="newRestriction"
						label="Agregar restricción"
						@keydown.enter.prevent="addRestriction" />
					<NcButton variant="tertiary" :disabled="newRestriction.trim() === ''" @click="addRestriction">
						Agregar
					</NcButton>
				</div>
				<p v-if="draft.restricciones.length === 0" class="medication-detail__hint">
					Condiciones de uso escritas en texto libre.
				</p>
			</div>
		</fieldset>

		<fieldset class="medication-detail__section">
			<legend>Clínica</legend>

			<NcTextArea v-model="draft.indicacion" class="medication-detail__field" label="Indicación" />
			<NcTextArea v-model="draft.dosificacion" class="medication-detail__field" label="Dosificación" />
			<NcTextArea v-model="draft.precauciones" class="medication-detail__field" label="Precauciones" />
			<NcTextArea v-model="draft.observaciones" class="medication-detail__field" label="Observaciones" />
		</fieldset>

		<fieldset class="medication-detail__section">
			<legend>Seguridad</legend>

			<NcCheckboxRadioSwitch :model-value="draft.riesgoColinergicoAm"
				@update:model-value="draft.riesgoColinergicoAm = $event">
				Riesgo colinérgico en adulto mayor
			</NcCheckboxRadioSwitch>

			<!-- Primitive options: NcSelect binds the letter itself, and clearing
			     it yields null — mapped to '' (sin dato), never to a safe-sounding
			     category the record does not have. -->
			<NcSelect v-model="fda"
				class="medication-detail__field"
				input-label="Categoría FDA en embarazo"
				:options="FDA_OPTIONS" />
			<p class="medication-detail__hint">
				Vacío = sin dato (no lo mismo que segura).
			</p>

			<NcTextArea v-model="draft.ajusteRenal" class="medication-detail__field" label="Ajuste renal (ERC)" />
		</fieldset>

		<fieldset class="medication-detail__section">
			<legend>Estado</legend>

			<!-- The one fact this section holds: when the record was last
			     written. Retirement has no representation here — it is an
			     action with its own surface (the NcNoteCard above and the
			     two-step Retirar), not a field on the form. -->
			<p class="medication-detail__hint">
				{{ draft.updatedAt ? `Actualizado: ${draft.updatedAt}` : 'Sin guardar todavía.' }}
			</p>
		</fieldset>
	</form>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { FDA_OPTIONS } from '../payload.js'

export default {
	name: 'MedicationDetail',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		// Owned by App.vue and mutated in place: the sidebar edits one record
		// at a time, and copying it back and forth would only add a second
		// place for the two to disagree (FeatureDetail's rationale).
		draft: { type: Object, required: true },
		categories: { type: Array, required: true },
		abastece: { type: Array, required: true },
		retired: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},

	emits: ['save', 'retire'],

	data() {
		return {
			FDA_OPTIONS,
			// The text a row-being-added is typed into; cleared on add. Never
			// part of the draft: an empty adder is not an empty restriction.
			newGes: '',
			newRestriction: '',
			confirmingRetire: false,
		}
	},

	computed: {
		/** {id, label} object for the selected category; null when none. */
		category: {
			get() {
				return this.categories.find((c) => c.id === this.draft.categoryId) ?? null
			},
			set(object) {
				this.draft.categoryId = object?.id ?? null
			},
		},

		/** The channel objects NcSelect multiple binds; ids in and out of the draft. */
		abasteceSelection: {
			get() {
				return this.draft.abastece
					.map((id) => this.abastece.find((c) => c.id === id))
					.filter(Boolean)
			},
			set(objects) {
				this.draft.abastece = objects.map((c) => c.id)
			},
		},

		/** FDA letter or null — NcSelect clears to null, the draft keeps ''. */
		fda: {
			get() {
				return this.draft.fdaEmbarazo || null
			},
			set(letter) {
				this.draft.fdaEmbarazo = letter ?? ''
			},
		},
	},

	methods: {
		addGes() {
			const value = this.newGes.trim()
			if (value === '') {
				return
			}
			this.draft.ges.push(value)
			this.newGes = ''
		},

		addRestriction() {
			const value = this.newRestriction.trim()
			if (value === '') {
				return
			}
			this.draft.restricciones.push(value)
			this.newRestriction = ''
		},
	},
}
</script>

<style scoped>
/* Sticky, bounded by this form (issue #35's measurement): saving stays reachable
   on a form whose height is the section list's. */
.medication-detail__actions {
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 2) 0;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.medication-detail__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3) 0;
	border: none;
	border-bottom: 1px solid var(--color-border);
	margin: 0;
}

.medication-detail__section legend {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	padding: 0;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.medication-detail__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	margin: 0;
}

.medication-detail__confirm {
	flex-basis: 100%;
}

.medication-detail__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.medication-detail__list-title {
	font-weight: bold;
	margin: 0;
}

.medication-detail__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.medication-detail__adder {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: flex-start;
}
</style>
