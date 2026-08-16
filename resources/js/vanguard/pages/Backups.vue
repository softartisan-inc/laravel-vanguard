<template>
  <div class="page">
    <!-- Filters -->
    <div class="filters-bar">
      <select class="form-select filter-select" v-model="filters.status" @change="load(1)">
        <option value="">All statuses</option>
        <option value="completed">Completed</option>
        <option value="running">Running</option>
        <option value="failed">Failed</option>
        <option value="pending">Pending</option>
      </select>
      <select class="form-select filter-select" v-model="filters.type" @change="load(1)">
        <option value="">All types</option>
        <option value="landlord">Landlord</option>
        <option value="tenant">Tenant</option>
        <option value="filesystem">Filesystem</option>
      </select>
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
          @change="load"
        />
      </div>

      <div v-if="loading" class="empty"><div class="spinner"></div></div>
      <BackupTable
        v-else
        :records="backups.data"
        :with-actions="true"
        @restore="askRestore"
        @delete="confirmDelete"
      />
    </div>

    <RestoreModal
      v-if="restoring"
      :record="restoring"
      @close="restoring = null"
      @success="load(backups.meta?.current_page ?? 1)"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import BackupTable  from '../components/BackupTable.vue'
import RestoreModal from '../components/RestoreModal.vue'
import VPagination  from '../components/VPagination.vue'
import { useBackups } from '../composables/useBackups.js'
import { useToast }   from '../composables/useToast.js'

const { backups, loading, fetchBackups, deleteBackup } = useBackups()
const toast = useToast()

const filters   = reactive({ status: '', type: '' })
// The record being restored, or null when the dialog is closed.
const restoring = ref(null)

async function load(page = 1) {
  const f = {}
  if (filters.status) f.status = filters.status
  if (filters.type)   f.type   = filters.type
  await fetchBackups(page, f)
}

async function confirmDelete(id) {
  if (!confirm(`Delete backup #${id}? This will remove the archive file.`)) return
  try {
    await deleteBackup(id)
    toast.success('Backup deleted.')
    load(backups.value?.meta?.current_page ?? 1)
  } catch (e) {
    toast.error(e.message)
  }
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
