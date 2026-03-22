<template>
  <div class="page">
    <!-- Empty / disabled -->
    <div v-if="!loading && tenants.length === 0" class="empty">
      <div class="empty-icon">👥</div>
      <div class="empty-text">No tenants found or tenancy is disabled.</div>
    </div>

    <div v-else class="section">
      <div class="section-header">
        <div class="section-title">
          {{ tenants.length }} Tenant{{ tenants.length !== 1 ? 's' : '' }}
        </div>
        <button class="btn btn-ghost" style="font-size:11px" @click="backupAll">
          ▶ Backup All
        </button>
      </div>

      <div v-if="loading" class="empty"><div class="spinner"></div></div>

      <div v-else class="tenant-grid">
        <div
          v-for="t in tenants"
          :key="t.id"
          class="tenant-card"
        >
          <div class="tenant-id">{{ t.id }}</div>
          <div class="tenant-meta">
            Backups: <span>{{ t.total_backups }}</span> ·
            Schedule: <span>{{ t.schedule || 'global' }}</span>
          </div>

          <!-- Latest backup info -->
          <div v-if="t.latest_backup" class="tenant-latest">
            <VBadge :status="t.latest_backup.status" />
            <span class="tenant-latest-meta">
              {{ t.latest_backup.file_size_human }} ·
              {{ formatDate(t.latest_backup.created_at) }}
            </span>
          </div>
          <div v-else class="tenant-no-backup">No backups yet</div>

          <button class="btn btn-ghost btn-sm mt-2" @click="runTenantBackup(t.id)">
            ▶ Run Backup
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import VBadge        from '../components/VBadge.vue'
import { useBackups } from '../composables/useBackups.js'
import { useToast }   from '../composables/useToast.js'

const { tenants, loading, fetchTenants, runBackup } = useBackups()
const toast = useToast()

async function runTenantBackup(id) {
  try {
    const res = await runBackup('tenant', id)
    toast.success(res.queued ? 'Backup queued.' : 'Backup started.')
    setTimeout(fetchTenants, 1500)
  } catch (e) {
    toast.error(e.message)
  }
}

async function backupAll() {
  try {
    await runBackup('all-tenants')
    toast.success('All-tenant backup started.')
    setTimeout(fetchTenants, 1500)
  } catch (e) {
    toast.error(e.message)
  }
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString()
}

onMounted(fetchTenants)

defineExpose({ refresh: fetchTenants })
</script>
