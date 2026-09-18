<template>
	<NcContent app-name="farmacia">
		<NcAppNavigation :aria-label="'Farmacia'">
			<NcAppNavigationList>
				<NcAppNavigationItem v-for="lens in lenses"
					:key="lens.id"
					:name="lens.name"
					:active="filter.lens === lens.id"
					@click="openLens(lens.id)" />
			</NcAppNavigationList>

			<!-- The affordances, in the navigation footer because the list column
			     (MedicationList) is another slice's file and already locked. The
			     planilla import leads: it is the bulk path (S4's own words); a
			     medication the planilla lacks is typed by hand; the two
			     vocabularies are the words every other screen reads. -->
			<template #footer>
				<NcButton class="farmacia-nav__action" variant="secondary" @click="openImport">
					Importar planilla
				</NcButton>
				<NcButton class="farmacia-nav__action" variant="secondary" @click="openExport">
					Exportar planilla
				</NcButton>
				<NcButton class="farmacia-nav__action" variant="secondary" @click="newDraft">
					Agregar medicamento
				</NcButton>
				<NcButton class="farmacia-nav__action"
					variant="tertiary"
					aria-label="Gestionar categorías terapéuticas"
					@click="openVocabulary('categories')">
					Categorías
				</NcButton>
				<NcButton class="farmacia-nav__action"
					variant="tertiary"
					aria-label="Gestionar canales de abastece"
					@click="openVocabulary('abastece')">
					Abastece
				</NcButton>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<MedicationList
				:medications="medications"
				:retired-medications="retired"
				:categories="categories"
				:filter="filter"
				:show-retired="showRetired"
				@update:query="filter.query = $event"
				@update:show-retired="showRetired = $event"
				@select="openDraft" />
		</NcAppContent>

		<!-- The sectioned sidebar card (the checkpoint's mockup #2): one record,
		     five sections, Guardar a real submit. -->
		<NcAppSidebar v-if="draft !== null"
			:name="draft.id === null ? 'Nuevo medicamento' : draft.producto || 'Sin nombre'"
			@close="closeDraft">
			<!-- The recovery surface: a retired record opens read-only (the form
			     hides its actions), and Restaurar is the one thing to do with it.
			     Territory's NcNoteCard shape. -->
			<NcNoteCard v-if="selectedIsRetired" type="warning">
				Este registro está retirado: no aparece en el arsenal.
				<NcButton variant="primary" @click="restore">
					Restaurar
				</NcButton>
			</NcNoteCard>

			<MedicationDetail
				:draft="draft"
				:categories="categories"
				:abastece="abastece"
				:saving="saving"
				:error="error"
				:retired="selectedIsRetired"
				@save="save"
				@retire="retire" />
		</NcAppSidebar>

		<!-- The import review and the vocabulary editors (S6's dialog, mounted
		     at last). App.vue owns every request (the S5 doctrine): both are
		     props-in, events-out. -->
		<ImportDialog v-if="importing"
			:batches="importBatches"
			:review="importDocument"
			:categories="categories"
			:abastece="abastece"
			:busy="importBusy"
			:error="importError"
			@close="closeImport"
			@upload="uploadPlanilla"
			@open-batch="reopenBatch"
			@decide="decideRow"
			@apply="applyBatch"
			@discard="discardBatch"
			@revert="revertBatch" />

		<!-- The export's said-before screen (S10): what the file carries,
		     said before the browser opens a save box — the import dialog's
		     own doctrine, mirrored. App.vue owns the download too (the S5
		     doctrine: every request is App.vue's; the navigation included). -->
		<ExportDialog v-if="exporting"
			@close="closeExport"
			@download="downloadExport" />

		<VocabularyDialog v-if="vocabulary !== null"
			:which="vocabulary"
			:entries="vocabulary === 'categories' ? categories : abastece"
			:error="vocabularyError"
			@close="closeVocabulary"
			@create="createWord"
			@rename="renameWord"
			@remove="removeWord" />
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import ImportDialog from './components/ImportDialog.vue'
import ExportDialog from './components/ExportDialog.vue'
import MedicationDetail from './components/MedicationDetail.vue'
import MedicationList from './components/MedicationList.vue'
import VocabularyDialog from './components/VocabularyDialog.vue'
import { emptyFilter } from './filters.js'
import { blankDraft, draftFrom, payload } from './payload.js'

const LENSES = [
	{ id: 'arsenal', name: 'Arsenal Farmacológico' },
	{ id: 'embarazo', name: 'Fármacos en embarazo' },
	{ id: 'renal', name: 'Ajuste farmacológico ERC' },
]

export default {
	name: 'App',

	components: {
		ExportDialog,
		ImportDialog,
		MedicationDetail,
		MedicationList,
		VocabularyDialog,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationList,
		NcAppSidebar,
		NcButton,
		NcContent,
		NcNoteCard,
	},

	data() {
		return {
			lenses: LENSES,
			medications: loadState('farmacia', 'medications'),
			retired: loadState('farmacia', 'retired'),
			categories: loadState('farmacia', 'categories'),
			abastece: loadState('farmacia', 'abastece'),
			filter: emptyFilter(),
			showRetired: false,
			// The draft IS the selection: one sidebar, one record, no
			// selectedId to keep in step with it.
			draft: null,
			saving: false,
			error: '',
			// The import review: one flag for the dialog, one document for
			// the review half of it (null while picking), one list for the
			// recent runs the picker reopens.
			importing: false,
			importDocument: null,
			importBatches: [],
			importBusy: false,
			importError: '',
			// The export's said-before screen: one flag, nothing to hold.
			exporting: false,
			// The vocabulary editor: 'categories' | 'abastece' | null.
			vocabulary: null,
			vocabularyError: '',
			// Whether the window has left since mount — the focus-reload's
			// latch (see onWindowFocus).
			blurred: false,
		}
	},

	computed: {
		selectedIsRetired() {
			return this.draft?.retired ?? false
		},
	},

	mounted() {
		// The no-poll decision: a tab that comes back reloads rather than
		// polls — zero new routes, the initial state re-runs. The blur/focus
		// pair rather than a bare focus listener, because browsers fire
		// focus once at load: a bare listener would read that as a return
		// and reload the app on every open, forever. Only a window that has
		// blurred is one that left.
		window.addEventListener('blur', this.onWindowBlur)
		window.addEventListener('focus', this.onWindowFocus)
	},

	beforeUnmount() {
		window.removeEventListener('blur', this.onWindowBlur)
		window.removeEventListener('focus', this.onWindowFocus)
	},

	methods: {
		openLens(id) {
			this.filter = { lens: id, query: '' }
		},

		newDraft() {
			this.draft = blankDraft()
			this.error = ''
		},

		/** A row is in exactly one of the two sets; the draft opens from either. */
		openDraft(id) {
			const row = this.medications.find((m) => m.id === id)
				?? this.retired.find((m) => m.id === id)

			if (row === undefined) {
				return
			}
			this.draft = draftFrom(row)
			this.error = ''
		},

		closeDraft() {
			this.draft = null
			this.error = ''
		},

		/**
		 * One saved payload, into the set it belongs in and out of the other:
		 * a record changed `retired` by definition crossed sets (Guardar never
		 * sends it, but Retirar and Restaurar both answer with the row they
		 * moved). Unshifted when new — the list reads newest-first, and a
		 * record that saved into invisibility would read as data loss.
		 */
		mergeMedication(data) {
			const into = data.retired ? 'retired' : 'medications'
			const outOf = data.retired ? 'medications' : 'retired'

			const index = this[into].findIndex((m) => m.id === data.id)
			if (index === -1) {
				this[into].unshift(data)
			} else {
				this[into].splice(index, 1, data)
			}
			this[outOf] = this[outOf].filter((m) => m.id !== data.id)
		},

		async save() {
			this.saving = true
			this.error = ''

			try {
				const isNew = this.draft.id === null
				const { data } = isNew
					? await axios.post(generateUrl('/apps/farmacia/medications'), payload(this.draft))
					: await axios.put(
						generateUrl(`/apps/farmacia/medications/${this.draft.id}`),
						payload(this.draft),
					)

				this.mergeMedication(data)
				// The draft rebuilds from the server's answer, so what the form
				// shows after Guardar is what was stored — deduped GES list,
				// trimmed texts, stamped updated_at — not what was typed.
				this.draft = draftFrom(data)
			} catch (exception) {
				// 422 carries a message naming what to fix; anything else does not.
				this.error = exception.response?.data?.message
					?? 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not save the medication', exception)
			} finally {
				this.saving = false
			}
		},

		async retire() {
			this.error = ''

			try {
				const { data } = await axios.delete(
					generateUrl(`/apps/farmacia/medications/${this.draft.id}`),
				)

				this.mergeMedication(data)
				// The sidebar stays open on the retired record: the person sees
				// what retiring did, and Restaurar is right there.
				this.draft = draftFrom(data)
			} catch (exception) {
				this.error = exception.response?.data?.message
					?? 'No se pudo retirar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not retire the medication', exception)
			}
		},

		async restore() {
			this.error = ''

			try {
				const { data } = await axios.post(
					generateUrl(`/apps/farmacia/medications/${this.draft.id}/restore`),
				)

				this.mergeMedication(data)
				this.draft = draftFrom(data)
			} catch (exception) {
				this.error = exception.response?.data?.message
					?? 'No se pudo restaurar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not restore the medication', exception)
			}
		},

		openImport() {
			this.importing = true
			this.importError = ''
			this.importDocument = null
			this.loadBatches()
		},

		closeImport() {
			this.importing = false
			this.importDocument = null
			this.importError = ''
		},

		/** The recent runs — a batch staged before the page closed reopens. */
		async loadBatches() {
			try {
				const { data } = await axios.get(generateUrl('/apps/farmacia/import/batches'))
				this.importBatches = data
			} catch (exception) {
				this.importError = 'No se pudo leer la lista de lotes. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not read the import batches', exception)
			}
		},

		/**
		 * Stage the picked planilla. The answer IS the review document — the
		 * batch with its counters and every judged row — so the dialog
		 * switches to its review half on the same request.
		 */
		async uploadPlanilla(file) {
			this.importBusy = true
			this.importError = ''

			try {
				const upload = new FormData()
				upload.append('file', file)

				const { data } = await axios.post(generateUrl('/apps/farmacia/import'), upload)

				this.importDocument = data
				this.importBatches.unshift(data.batch)
			} catch (exception) {
				this.importError = exception.response?.data?.message
					?? 'No se pudo leer el archivo. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not stage the planilla', exception)
			} finally {
				this.importBusy = false
			}
		},

		/** Reopen a staged batch — the upload's own answer shape, re-served. */
		async reopenBatch(id) {
			this.importBusy = true
			this.importError = ''

			try {
				const { data } = await axios.get(generateUrl(`/apps/farmacia/import/${id}`))
				this.importDocument = data
			} catch (exception) {
				this.importError = exception.response?.status === 404
					? 'El lote ya no existe.'
					: (exception.response?.data?.message
						?? 'No se pudo abrir el lote. Revise la conexión e inténtelo de nuevo.')
				console.error('farmacia: could not reopen the import batch', exception)
			} finally {
				this.importBusy = false
			}
		},

		/**
		 * One review decision, one row. The answer is the row as the review
		 * now shows it — its disposition re-derived and its note shrunk to
		 * what is still owed — and it replaces the row in the open document.
		 */
		async decideRow({ rowId, ...decision }) {
			this.importBusy = true
			this.importError = ''

			try {
				const { data } = await axios.post(
					generateUrl(`/apps/farmacia/import/rows/${rowId}/decision`),
					decision,
				)

				// The dialog may have closed while the request flew (Cerrar is
				// never disabled): the decision landed, there is simply no review
				// open to show it in.
				if (this.importDocument === null) {
					return
				}

				const rows = this.importDocument.rows
				const index = rows.findIndex((row) => row.id === data.id)
				if (index !== -1) {
					rows.splice(index, 1, data)
				}
			} catch (exception) {
				this.importError = exception.response?.data?.message
					?? 'No se pudo guardar la decisión. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not record the review decision', exception)
			} finally {
				this.importBusy = false
			}
		},

		/**
		 * Land the reviewed batch. The answer is ignored on purpose: the
		 * arsenal changed under the app, and the doctrine's own tool for
		 * that is the reload — nothing merged, nothing diffed, the fresh
		 * initial state IS the report of what the apply did.
		 */
		async applyBatch(id) {
			this.importBusy = true
			this.importError = ''

			try {
				await axios.post(generateUrl(`/apps/farmacia/import/${id}/apply`))
				window.location.reload()
			} catch (exception) {
				this.importError = exception.response?.data?.message
				?? 'No se pudo aplicar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not apply the import batch', exception)
			} finally {
				this.importBusy = false
			}
		},

		/**
		 * A closed review: nothing landed, so nothing reloads — the list of
		 * runs is the only thing that changed.
		 */
		async discardBatch(id) {
			this.importBusy = true
			this.importError = ''

			try {
				await axios.post(generateUrl(`/apps/farmacia/import/${id}/discard`))
				this.importDocument = null
				await this.loadBatches()
			} catch (exception) {
				this.importError = exception.response?.data?.message
				?? 'No se pudo descartar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not discard the import batch', exception)
			} finally {
				this.importBusy = false
			}
		},

		/** The undo moved the arsenal like an apply, so it reloads like one. */
		async revertBatch(id) {
			this.importBusy = true
			this.importError = ''

			try {
				await axios.post(generateUrl(`/apps/farmacia/import/${id}/revert`))
				window.location.reload()
			} catch (exception) {
				this.importError = exception.response?.data?.message
				?? 'No se pudo deshacer. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not revert the import batch', exception)
			} finally {
				this.importBusy = false
			}
		},

		openExport() {
			this.exporting = true
		},

		closeExport() {
			this.exporting = false
		},

		/**
		 * The download is a navigation, not an axios request: the browser
		 * saves what DataDownloadResponse serves and the page never leaves —
		 * which is also why there is no response to wait for: the dialog
		 * closes here, at the click.
		 */
		downloadExport() {
			this.exporting = false
			window.location = generateUrl('/apps/farmacia/export')
		},

		openVocabulary(which) {
			this.vocabulary = which
			this.vocabularyError = ''
		},

		closeVocabulary() {
			this.vocabulary = null
			this.vocabularyError = ''
		},

		/**
		 * Both vocabularies answer every write with the whole served list
		 * (VocabularyService's own shape), so the client replaces its copy
		 * — one description of the list, never two that can disagree. The
		 * fresh list coming back down the `entries` prop is what shows the
		 * write landed (the S6 dialog's own words).
		 */
		createWord(label) {
			this.writeWord(() => axios.post(generateUrl(this.wordUrl()), { label }))
		},

		renameWord({ slug, label }) {
			this.writeWord(() => axios.put(generateUrl(`${this.wordUrl()}/${slug}`), { label }))
		},

		removeWord(slug) {
			this.writeWord(() => axios.delete(generateUrl(`${this.wordUrl()}/${slug}`)))
		},

		wordUrl() {
			return this.vocabulary === 'categories'
				? '/apps/farmacia/categories'
				: '/apps/farmacia/abastece'
		},

		async writeWord(request) {
			this.vocabularyError = ''

			try {
				const { data } = await request()
				if (this.vocabulary === 'categories') {
					this.categories = data
				} else {
					this.abastece = data
				}
			} catch (exception) {
				this.vocabularyError = exception.response?.data?.message
					?? 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.'
				console.error('farmacia: could not write the vocabulary', exception)
			}
		},

		onWindowBlur() {
			this.blurred = true
		},

		/**
		 * The whole cross-tab sync: reload on return, the research's own
		 * decision — «hard reload + draft guard, zero new routes». Nothing
		 * is merged, nothing is diffed; the colleague's save is simply there
		 * when the page re-runs its initial state.
		 *
		 * Guarded by everything the person may be holding: an open draft
		 * (the research's guard — a half-typed record must not be replaced
		 * from under anyone), the import review (its decisions are made one
		 * row at a time, and the OS file picker itself blurs the window —
		 * an unguarded reload would eat the picked planilla mid-flow), the
		 * export dialog (a Descargar click in flight), and the vocabulary
		 * editor (unsent text).
		 */
		onWindowFocus() {
			if (!this.blurred || this.draft !== null || this.importing || this.exporting || this.vocabulary !== null) {
				return
			}
			window.location.reload()
		},
	},
}
</script>

<style scoped>
.farmacia-nav__action {
	width: calc(100% - var(--default-grid-baseline) * 8);
	margin: calc(var(--default-grid-baseline) * 1);
}
</style>
