<template>
	<!--
		The review half of the two-phase import. S7's staging already read,
		resolved and judged every row — this screen is where a person reads
		that judgement and settles what it still owes. Nothing here writes a
		medication: the apply/discard/revert buttons are S9's wiring, and
		they will install in the #actions slot this dialog already carries.
	-->
	<NcDialog name="Importar planilla" size="large" @closing="$emit('close')">
		<div class="import-dialog">
			<!-- App.vue owns every request in this app (the S5 doctrine), so a
			     refusal arrives as one prop and is said once, at the top —
			     the 422-with-a-sentence discipline. -->
			<NcNoteCard v-if="error !== ''" type="error">{{ error }}</NcNoteCard>

			<!-- ======== Picking: the file, and the way back to a batch staged
			     before the page closed. ======== -->
			<template v-if="review === null">
				<!--
					Said before the upload runs, not after — territorio's import
					dialog doctrine: a planilla somebody else sent is checked
					against what the reader will read while the person can
					still choose a different file.
				-->
				<p class="import-dialog__hint">
					Un CSV con encabezado. Cada fila se revisa por separado y nada se
					escribe hasta que el lote se aplique: una fila con problemas no cuesta
					las demás.
				</p>
				<p class="import-dialog__hint">
					Las columnas que se leen son: Código, Producto, Fármaco (DCI), Mg,
					Presentación, Categoría, Trazador, GES, Restricciones, Abastece,
					Programa, Indicación, Dosificación, Precauciones, Observaciones,
					Riesgo colinérgico AM, FDA embarazo y Ajuste renal. Las demás se
					ignoran.
				</p>

				<label class="import-dialog__file">
					<span>Archivo</span>
					<input type="file"
						accept=".csv,text/csv"
						:disabled="busy"
						@change="pick">
				</label>

				<p v-if="file !== null" class="import-dialog__hint">
					{{ file.name }} · {{ Math.round(file.size / 1024) }} KB
				</p>

				<NcButton class="import-dialog__review"
					variant="primary"
					:disabled="file === null || busy"
					@click="$emit('upload', file)">
					Revisar
				</NcButton>

				<!--
					Not an audit log — a batch staged before lunch reopens here
					after the page closed (ImportBatchMapper::findRecent's own
					words), with every decision already made still in it.
				-->
				<section v-if="batches.length > 0" class="import-dialog__batches">
					<h4>Lotes recientes</h4>
					<ul>
						<li v-for="batch in batches" :key="batch.id" class="import-dialog__batch">
							<span class="import-dialog__batch-what">
								<strong>Lote #{{ batch.id }}</strong>
								<span class="import-dialog__hint">
									{{ batch.originalFilename }} · {{ batch.startedAt }} ·
									{{ BATCH_STATE_LABELS[batch.state] }}<template v-if="batch.isRevert"> · deshace el lote #{{ batch.revertsId }}</template>
								</span>
							</span>
							<NcButton v-if="batch.state === 'staged'"
								variant="tertiary"
								:disabled="busy"
								@click="$emit('open-batch', batch.id)">
								Revisar
							</NcButton>
							<!-- The undo door (territorio's ImportDialog shape):
							     an applied batch no other batch has already
							     undone. A revert batch offers nothing — undoing
							     an undo is importing the planilla again, which
							     the server's own sentence says. -->
							<NcButton v-else-if="batch.state === 'applied' && !batch.undone && !batch.isRevert"
								variant="tertiary"
								:disabled="busy"
								@click="$emit('revert', batch.id)">
								Deshacer
							</NcButton>
						</li>
					</ul>
				</section>
			</template>

			<!-- ======== Review: the staged judgement, row by row. ======== -->
			<template v-else>
				<!--
					The batch's own summary in the occ sentence's own words, so
					the two doors of one ImportService say the same thing.
				-->
				<NcNoteCard type="info" class="import-dialog__summary">
					<strong>Lote #{{ review.batch.id }} en revisión — {{ counters(review.batch) }}</strong>
					<span v-if="pending.length > 0">
						{{ pending.length }} fila(s) esperan una decisión de vocabulario.
					</span>
				</NcNoteCard>

				<p class="import-dialog__hint">
					{{ review.batch.originalFilename }} · {{ review.batch.startedAt }}
				</p>

				<!--
					A few hundred rows with four decisions must not be an eye
					hunt: the owed rows are one checkbox away.
				-->
				<NcCheckboxRadioSwitch v-if="pending.length > 0" v-model="onlyPending">
					Solo filas que esperan una decisión
				</NcCheckboxRadioSwitch>

				<ul class="import-dialog__rows">
					<li v-for="row in shownRows"
						:key="row.id"
						class="import-dialog__row"
						:class="{ 'import-dialog__row--owing': row.status !== 'staged' }">
						<p class="import-dialog__row-head">
							<strong>línea {{ row.lineNumber }}</strong>
							<span>{{ row.codigo || 'sin código' }} · {{ row.payload.producto || 'sin producto' }}</span>
							<span class="import-dialog__disposition">{{ DISPOSITION_LABELS[row.disposition] }}</span>
						</p>

						<!--
							The sentence the staging wrote: which cell would not
							parse, which word did not match, why a row will not be
							touched. The review's own words, never re-derived here.
						-->
						<p v-if="row.notes !== null" class="import-dialog__notes">{{ row.notes }}</p>

						<template v-if="row.status === 'unresolved-vocabulary'">
							<NcButton v-if="deciding !== row.id"
								variant="tertiary"
								:disabled="busy"
								@click="startDecision(row)">
								Resolver
							</NcButton>

							<div v-else class="import-dialog__decision">
								<!-- Exactly the shape MedicationDetail edits, so the
								     two ways of saying a category or a channel are
								     one shape. -->
								<NcSelect v-if="owed.categoria"
									v-model="categoryChoice"
									class="import-dialog__field"
									input-label="Categoría terapéutica"
									:options="categories"
									:clearable="false"
									label="label" />

								<NcSelect v-if="owed.abastece"
									v-model="abasteceChoice"
									class="import-dialog__field"
									input-label="Abastece"
									:options="abastece"
									multiple
									label="label" />
								<p v-if="owed.abastece" class="import-dialog__hint">
									Sin marcar ningún canal se deja el medicamento sin canal.
								</p>

								<p class="import-dialog__hint">
									Si falta la palabra, ciérrelo con «Cerrar», declárela en el
									pie del menú («Categorías» o «Abastece») y vuelva a abrir
									este lote desde «Lotes recientes».
								</p>

								<div class="import-dialog__decision-actions">
									<NcButton variant="primary"
										:disabled="busy || !decisionReady"
										@click="commitDecision(row)">
										Resolver
									</NcButton>
									<NcButton variant="tertiary" :disabled="busy" @click="deciding = null">
										Cancelar
									</NcButton>
								</div>
							</div>
						</template>
					</li>
				</ul>
			</template>
		</div>

		<!-- The review's close. Aplicar lands the batch — it stands down while
		     a decision is owed, because the server would only re-say the same
		     sentence with a count (the same check in two places is cheaper
		     than the sentence it prevents). Descartar closes the review
		     without landing anything. An applied batch offers no Aplicar:
		     its way back is Deshacer, in «Lotes recientes». -->
		<template #actions>
			<NcButton v-if="review !== null && review.batch.state === 'staged'"
				variant="tertiary"
				:disabled="busy"
				@click="$emit('discard', review.batch.id)">
				Descartar
			</NcButton>
			<NcButton v-if="review !== null && review.batch.state === 'staged'"
				variant="primary"
				:disabled="busy || pending.length > 0"
				@click="$emit('apply', review.batch.id)">
				Aplicar
			</NcButton>
			<NcButton :variant="review === null ? 'primary' : 'tertiary'" @click="$emit('close')">
				Cerrar
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import { BATCH_STATE_LABELS, DISPOSITION_LABELS, counters, decisionFor, decisionReady as ready, pendingRows } from '../import.js'

export default {
	name: 'ImportDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcSelect },

	props: {
		/** The recent runs, as `import#batches` served them. */
		batches: { type: Array, required: true },
		/** The open review document — `{ batch, rows }` — or null while picking. */
		review: { type: Object, default: null },
		categories: { type: Array, required: true },
		abastece: { type: Array, required: true },
		/** One request in flight — every input and button stands down. */
		busy: { type: Boolean, default: false },
		/** Why the last request was refused, shown at the top of the body. */
		error: { type: String, default: '' },
	},

	emits: ['close', 'upload', 'open-batch', 'decide', 'apply', 'discard', 'revert'],

	data() {
		return {
			// Options-API: imported bindings are not template bindings (S5's
			// FDA_OPTIONS doctrine) — the label maps ride data.
			DISPOSITION_LABELS,
			BATCH_STATE_LABELS,
			file: null,
			/** True shows only the rows the review still owes. */
			onlyPending: false,
			/** Which row has its editor open, as the row's id, or null — one
			 * key, never a flag per kind (CapasPanel's `editing` doctrine). */
			deciding: null,
			/** The picks of every editor opened this session, keyed by row id
			 * and seeded from what the staging already resolved: a refused
			 * decision must not cost the person their picks. */
			decisions: {},
		}
	},

	computed: {
		/** The rows the review owes, in planilla order. */
		pending() {
			return this.review === null ? [] : pendingRows(this.review.rows)
		},

		shownRows() {
			return this.onlyPending ? this.pending : this.review?.rows ?? []
		},

		/** The row under the editor, or null. */
		decisionRow() {
			if (this.review === null || this.deciding === null) {
				return null
			}
			return this.review.rows.find((row) => row.id === this.deciding) ?? null
		},

		/**
		 * Which vocabulary decisions the row under the editor is owed — the
		 * payload's own `pending` map decides what the editor shows, so the
		 * editor can never offer a decision the server would refuse.
		 */
		owed() {
			const pending = this.decisionRow?.payload?.pending ?? {}
			return {
				categoria: 'categoria' in pending,
				abastece: 'abastece' in pending,
			}
		},

		/** Object options keyed by `label`; the decision keeps the id, the
		 * select keeps the object — MedicationDetail's computed shape. */
		categoryChoice: {
			get() {
				return this.categories.find((c) => c.id === this.decisions[this.deciding]?.categoryId) ?? null
			},
			set(object) {
				this.decisions[this.deciding].categoryId = object?.id ?? null
			},
		},

		/** The channel objects the multiple select binds; ids in and out. */
		abasteceChoice: {
			get() {
				return (this.decisions[this.deciding]?.abasteceIds ?? [])
					.map((id) => this.abastece.find((c) => c.id === id))
					.filter(Boolean)
			},
			set(objects) {
				this.decisions[this.deciding].abasteceIds = objects.map((c) => c.id)
			},
		},

		/**
		 * The rule lives in import.js now (L2-18) — same semantics, tested.
		 */
		decisionReady() {
			return ready(this.decisionRow, this.decisions[this.deciding], Boolean(this.owed?.categoria))
		},
	},

	methods: {
		counters,

		pick(event) {
			this.file = event.target.files?.[0] ?? null
		},

		startDecision(row) {
			// Seeded from what the staging already resolved, once per row:
			// the picks made before a refusal survive the re-open (a refused
			// decision must not cost the person their picks), and the unknown
			// labels stay in the note's words — they are not options, they did
			// not match.
			if (this.decisions[row.id] === undefined) {
				this.decisions[row.id] = {
					categoryId: row.payload.categoryId ?? null,
					abasteceIds: [...(row.payload.abasteceIds ?? [])],
				}
			}
			this.deciding = row.id
		},

		commitDecision(row) {
			// The disabled Resolver is guarded again here: the same check in
			// two places is cheaper than the sentence it prevents.
			if (!this.decisionReady) {
				return
			}
			this.deciding = null
			this.$emit('decide', { rowId: row.id, ...decisionFor(row, this.decisions[row.id]) })
		},
	},
}
</script>

<style scoped>
.import-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.import-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.import-dialog__file {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.import-dialog__review {
	align-self: start;
}

.import-dialog__batches {
	border-top: 1px solid var(--color-border);
	padding-top: calc(var(--default-grid-baseline) * 2);
}

.import-dialog__batches h4 {
	margin: 0 0 var(--default-grid-baseline);
	font-weight: bold;
}

.import-dialog__batches ul {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.import-dialog__batch {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: center;
	justify-content: space-between;
}

.import-dialog__batch-what {
	display: flex;
	flex-direction: column;
}

.import-dialog__rows {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.import-dialog__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
}

.import-dialog__row--owing {
	border-color: var(--color-warning);
}

.import-dialog__row-head {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: baseline;
	margin: 0;
}

.import-dialog__disposition {
	margin-left: auto;
	color: var(--color-text-maxcontrast);
}

.import-dialog__notes {
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-text-maxcontrast);
}

.import-dialog__decision {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.import-dialog__field {
	max-width: 340px;
}

.import-dialog__decision-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
