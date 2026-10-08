<template>
	<!--
		One component, two vocabularies: `which` picks the list it edits and
		the words it says, because the two are one shape — a slug, a label and
		an in-use guard — over two tables. A second component would be this
		file with strings swapped.
	-->
	<NcDialog :name="word.title" size="normal" @closing="$emit('close')">
		<!--
			A refusal belongs where the person typed, and the error comes
			down as a prop because App.vue owns every request in this app
			(the S5 doctrine): the card says the LAST write's reason, and the
			parent clears it when the next one starts.
		-->
		<NcNoteCard v-if="error !== ''" type="error">{{ error }}</NcNoteCard>

		<!--
			The add row. maxlength is the server's own limit, so the usual way
			of hitting it — a pasted paragraph — never becomes a round trip
			that ends in a refusal (territorio's CapasPanel rule).
		-->
		<div class="vocabulary-dialog__add">
			<NcTextField v-model="newLabel"
				:label="word.add"
				:maxlength="128"
				@keyup.enter="commitAdd" />
			<NcButton variant="primary"
				:disabled="newLabel.trim() === ''"
				@click="commitAdd">
				Agregar
			</NcButton>
		</div>

		<ul class="vocabulary-dialog__list">
			<li v-for="entry in entries" :key="entry.slug" class="vocabulary-dialog__row">
				<!-- Renaming is click-to-edit: the shape renaming a file in
				     Files already has. The server's own rules — the «|»
				     refusal, the slug an empty label makes — are not
				     re-implemented here; one statement of each, in
				     VocabularyService. -->
				<template v-if="editing === entry.slug">
					<NcTextField v-model="draftLabel"
					:label="word.rename"
					:maxlength="128"
					@keyup.enter="commitRename(entry)" />
					<NcButton :disabled="draftLabel.trim() === ''"
					@click="commitRename(entry)">
						Guardar
					</NcButton>
					<NcButton variant="tertiary" @click="editing = null">
						Cancelar
					</NcButton>
				</template>

				<!-- Two-step, inline, never a browser confirm(): it blocks the
				     page, and a refusal that follows the question has to be
				     readable where the question was — territorio#ADR-0016's rule, the
				     same two-step Retirar already uses in this app. -->
				<template v-else-if="confirming === entry.slug">
					<span class="vocabulary-dialog__confirm">¿Quitar «{{ entry.label }}»?</span>
					<NcButton variant="error" @click="$emit('remove', entry.slug)">
						Quitar
					</NcButton>
					<NcButton variant="tertiary" @click="confirming = null">
						Cancelar
					</NcButton>
				</template>

				<template v-else>
					<button class="vocabulary-dialog__name"
					:title="`Renombrar «${entry.label}»`"
					:aria-label="`Renombrar «${entry.label}»`"
					@click="startRename(entry)">
						{{ entry.label }}
					</button>
					<NcButton variant="tertiary"
					:aria-label="`Quitar «${entry.label}»`"
					@click="startRemove(entry)">
						Quitar
					</NcButton>
				</template>
			</li>
		</ul>

		<p v-if="entries.length === 0" class="vocabulary-dialog__empty">
			{{ word.empty }}
		</p>

		<template #actions>
			<NcButton variant="primary" @click="$emit('close')">
				Cerrar
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/** The words each vocabulary says, keyed by the `which` prop: whole phrases
 * rather than assembled fragments, because Spanish gender does not compose. */
const WORDS = {
	categories: {
		title: 'Categorías terapéuticas',
		add: 'Nueva categoría',
		rename: 'Nuevo nombre de la categoría',
		empty: 'No queda ninguna categoría terapéutica.',
	},
	abastece: {
		title: 'Canales de abastece',
		add: 'Nuevo canal',
		rename: 'Nuevo nombre del canal',
		empty: 'Todavía no hay canales: cada CESFAM define los suyos.',
	},
}

export default {
	name: 'VocabularyDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextField },

	props: {
		/** Which of the two vocabularies this instance edits. */
		which: { type: String, required: true, validator: (value) => value in WORDS },
		entries: { type: Array, required: true },
		/** Why the last write was refused, shown at the top of the body. */
		error: { type: String, default: '' },
	},

	emits: ['close', 'create', 'rename', 'remove'],

	data() {
		return {
			/** Which row has its field open, as the row's slug, or null. One
			 * key rather than a flag per kind of field: exactly one is open
			 * at a time, and two pieces of state that have to agree about
			 * that can stop agreeing (CapasPanel's `editing` doctrine). */
			editing: null,
			/** Which row is asking before it removes, as the row's slug, or
			 * null — the two-step shape Retirar already uses. Kept mutually
			 * exclusive with `editing` by hand, in startRename/startRemove. */
			confirming: null,
			draftLabel: '',
			newLabel: '',
		}
	},

	computed: {
		word() {
			return WORDS[this.which]
		},
	},

	watch: {
		// Success is observable: a rename that landed answers with the whole
		// fresh list down the `entries` prop (VocabularyService's own shape),
		// and THAT is the moment the field closes. A refusal changes nothing
		// here — the error prop shows at the top of the dialog, and the field
		// keeps what the person typed, exactly as commitAdd's own comment
		// promises for the adder: "a refusal must not cost the person their
		// text" (L2-20).
		entries() {
			if (this.editing !== null) {
				this.editing = null
			}
			if (this.newLabel !== '') {
				this.newLabel = ''
			}
		},
	},

	methods: {
		startRename(entry) {
			this.confirming = null
			this.editing = entry.slug
			this.draftLabel = entry.label
		},

		commitRename(entry) {
			const label = this.draftLabel.trim()
			// A rename to the same name is a close, not a round trip: the
			// answer would be the list the client already holds.
			if (label === '' || label === entry.label) {
				this.editing = null
				return
			}
			this.$emit('rename', { slug: entry.slug, label })
			// NOT closed here: a refusal must not cost the person their text
			// (L2-20) — the field closes when the write lands, watched below.
		},

		startRemove(entry) {
			this.editing = null
			this.confirming = entry.slug
		},

		commitAdd() {
			const label = this.newLabel.trim()
			if (label === '') {
				return
			}
			this.$emit('create', label)
			// The field keeps what was typed: a refusal must not cost the
			// person their text, and the fresh list coming back down the
			// `entries` prop is what shows the add landed.
		},
	},
}
</script>

<style scoped>
.vocabulary-dialog__add {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: end;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.vocabulary-dialog__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.vocabulary-dialog__row {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: center;
}

.vocabulary-dialog__name {
	flex: 1;
	text-align: left;
	background: none;
	border: none;
	color: inherit;
	font: inherit;
	cursor: text;
	overflow-wrap: anywhere;
}

.vocabulary-dialog__name:hover {
	text-decoration: underline;
}

.vocabulary-dialog__confirm {
	flex: 1;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.vocabulary-dialog__empty {
	color: var(--color-text-maxcontrast);
}
</style>
