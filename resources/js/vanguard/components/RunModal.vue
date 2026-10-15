<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal">
        <div class="modal-title">Run Backup</div>

        <div class="form-group">
          <label class="form-label">Backup Type</label>
          <select class="form-select" v-model="type">
            <option value="landlord">Landlord (Central DB + Filesystem)</option>
            <option value="tenant">Specific Tenant{{ filesOnly ? ' (files only)' : '' }}</option>
            <option value="all-tenants">All Tenants{{ filesOnly ? ' (files only)' : '' }}</option>
            <option value="filesystem">Filesystem Only</option>
          </select>
        </div>

        <div class="form-group" v-if="type === 'tenant'">
          <label class="form-label">Tenant ID</label>
          <input
            class="form-input"
            v-model="tenantId"
            type="text"
            placeholder="tenant-uuid-or-key"
            autofocus
          />
        </div>

        <p v-if="filesOnly && (type === 'tenant' || type === 'all-tenants')" class="form-hint">
          Single-database mode: a tenant backup holds the tenant's files only.
          The shared database is backed up by the landlord backup.
        </p>

        <div class="modal-footer">
          <button class="btn btn-ghost" @click="$emit('close')">Cancel</button>
          <button class="btn btn-primary" :disabled="running" @click="submit">
            <span v-if="running" class="spinner"></span>
            <span v-else>▶ Run</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, inject } from 'vue'
import { useBackups } from '../composables/useBackups.js'
import { useToast }   from '../composables/useToast.js'

const emit = defineEmits(['close', 'success'])

const { runBackup } = useBackups()
const toast = useToast()

const type     = ref('landlord')
const tenantId = ref('')
const running  = ref(false)

// One database shared by every tenant: tenant backups carry files only.
const filesOnly = inject('databaseMode', 'multi') === 'single'

async function submit() {
  running.value = true
  try {
    const res = await runBackup(type.value, type.value === 'tenant' ? tenantId.value : null)
    toast.success(res.queued ? 'Backup queued.' : 'Backup started.')
    emit('close')
    emit('success')
  } catch (e) {
    toast.error(e.message)
  } finally {
    running.value = false
  }
}
</script>
