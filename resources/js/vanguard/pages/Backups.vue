<template>
  <div class="page">
    <!-- Filters -->
    <div class="filters-bar">
      <select class="form-select filter-select" v-model="filters.status" @change="changeFilters">
        <option value="">All statuses</option>
        <option value="completed">Completed</option>
        <option value="running">Running</option>
        <option value="failed">Failed</option>
        <option value="pending">Pending</option>
      </select>
      <select class="form-select filter-select" v-model="filters.type" @change="changeFilters">
        <option value="">All types</option>
        <option value="landlord">Landlord</option>
        <option value="tenant">Tenant</option>
        <option value="filesystem">Filesystem</option>
      </select>

      <!-- Only there when something is selected: an always-visible bar with a
           disabled destructive button is an invitation to click it. -->
      <div v-if="selected.size" class="bulk-bar">
        <span class="bulk-count">{{ selected.size }} selected</span>
        <button class="btn btn-ghost btn-sm" @click="clearSelection">Clear</button>
        <button class="btn btn-danger btn-sm" @click="askBulkDelete">✕ Delete selected</button>
      </div>
    </div>

    <div class="section">
      <div class="section-header">
        <div class="section-title">
          All Backups
          <span v-if="backups.meta">· {{ backups.meta.total }} records</span>
        </div>
        <VPagination
          v-if="backups.meta && backups.meta.last_page > 1"
          :current="backups.meta.current_page"
          :last="backups.meta.last_page"
          @change="changePage"
        />
      </div>

      <!--
        The spinner belongs to the first load and to nothing else. It used to
        be shown on every fetch, which meant the live channel unmounted the
        table roughly every ten seconds and built a new one: scroll position,
        open detail panels and hovered rows all died on each tick. Once the
        rows are here they stay, and each refresh patches them in place.
      -->
      <div v-if="!loaded.backups" class="empty"><div class="spinner"></div></div>
      <BackupTable
        v-else
        :records="backups.data"
        :with-actions="true"
        :selectable="true"
        :selected="selected"
        @restore="askRestore"
        @delete="askDelete"
        @select="toggleSelect"
        @select-all="selectPage"
      />
    </div>

    <RestoreModal
      v-if="restoring"
      :record="restoring"
      @close="restoring = null"
      @success="load(backups.meta?.current_page ?? 1)"
    />

    <DeleteModal
      v-if="deleting"
      :record="deleting"
      @close="deleting = null"
      @success="load(backups.meta?.current_page ?? 1)"
    />

    <BulkDeleteModal
      v-if="bulkDeleting.length"
      :records="bulkDeleting"
      @close="bulkDeleting = []"
      @done="afterBulkDelete"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import BackupTable  from '../components/BackupTable.vue'
import BulkDeleteModal from '../components/BulkDeleteModal.vue'
import DeleteModal  from '../components/DeleteModal.vue'
import RestoreModal from '../components/RestoreModal.vue'
import VPagination  from '../components/VPagination.vue'
import { useBackups } from '../composables/useBackups.js'

const { backups, loaded, fetchBackups } = useBackups()

const filters   = reactive({ status: '', type: '' })
// The record being restored, or null when the dialog is closed.
const restoring = ref(null)
// The record being deleted, likewise.
const deleting  = ref(null)
// The records the bulk dialog is holding, empty when it is closed.
const bulkDeleting = ref([])

/**
 * The selection, as ids.
 *
 * Ids rather than records, because the rows are merged in place on every live
 * tick: an id still designates the same archive after the merge, and a check
 * box the operator ticked stays ticked while the table updates underneath.
 */
const selected = ref(new Set())

async function load(page = 1) {
  const f = {}
  if (filters.status) f.status = filters.status
  if (filters.type)   f.type   = filters.type
  await fetchBackups(page, f)
}

/**
 * Changing the page or the filters shows a different set of archives, so the
 * selection is dropped: carrying an invisible selection across pages is how a
 * bulk delete erases something the operator cannot see. A live tick is not a
 * change of page and leaves it alone.
 */
async function changePage(page) {
  clearSelection()
  await load(page)
}

async function changeFilters() {
  clearSelection()
  await load(1)
}

function toggleSelect(id, checked) {
  const next = new Set(selected.value)
  checked ? next.add(id) : next.delete(id)
  selected.value = next
}

function selectPage(checked) {
  const next = new Set(selected.value)
  for (const record of backups.value.data) {
    checked ? next.add(record.id) : next.delete(record.id)
  }
  selected.value = next
}

function clearSelection() {
  selected.value = new Set()
}

/**
 * The dialog is handed the records, not the ids: it names the targets and the
 * dates that are about to disappear, and asks for a phrase carrying the count.
 */
function askBulkDelete() {
  bulkDeleting.value = backups.value.data.filter((r) => selected.value.has(r.id))
}

async function afterBulkDelete() {
  clearSelection()
  await load(backups.value?.meta?.current_page ?? 1)
}

/**
 * Deleting an archive is irreversible, so it gets the theme's own dialog rather
 * than the browser's confirm(): a native prompt says only "#41", cannot show
 * which target or which date is about to disappear, and is the one dialog a
 * browser is allowed to stop showing — after a few of them Chrome offers to
 * suppress further prompts from the page, and a suppressed confirm() returns
 * false silently, so the button would simply stop working with no sign why.
 * No name to type back, though: that guard is reserved for restore and prune.
 * DeleteModal reports the outcome.
 */
function askDelete(record) {
  deleting.value = record
}

/**
 * A restore is refused unless the operator types the target's name back, so it
 * cannot be a yes/no prompt: the dialog is where the name is typed, and its
 * button stays inert until what was typed matches. RestoreModal reports the
 * outcome — a queued restore and its id, or the server's refusal.
 */
function askRestore(record) {
  restoring.value = record
}

onMounted(() => load(1))

defineExpose({ refresh: () => load(backups.value?.meta?.current_page ?? 1) })
</script>
