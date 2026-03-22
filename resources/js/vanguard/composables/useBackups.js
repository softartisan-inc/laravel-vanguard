import { ref } from 'vue'
import { useApi } from './useApi.js'

/**
 * Shared backup data & actions.
 * Kept outside the component so data survives page navigation.
 */
const stats   = ref(null)
const backups = ref({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
const tenants = ref([])
const loading = ref(false)

export function useBackups() {
  const api = useApi()

  async function fetchStats() {
    loading.value = true
    try {
      stats.value = await api.get('/stats')
    } finally {
      loading.value = false
    }
  }

  async function fetchBackups(page = 1, filters = {}) {
    loading.value = true
    try {
      const params = new URLSearchParams({ page, per_page: 15, ...filters })
      backups.value = await api.get(`/backups?${params}`)
    } finally {
      loading.value = false
    }
  }

  async function fetchTenants() {
    loading.value = true
    try {
      const data   = await api.get('/tenants')
      tenants.value = data.tenants || []
    } finally {
      loading.value = false
    }
  }

  async function runBackup(type, tenantId = null) {
    const body = { type }
    if (tenantId) body.tenant_id = tenantId
    return api.post('/backups/run', body)
  }

  async function deleteBackup(id) {
    await api.delete(`/backups/${id}`)
  }

  async function restoreBackup(id, options = {}) {
    return api.post(`/backups/${id}/restore`, {
      verify_checksum: true,
      restore_db:      true,
      restore_storage: false,
      ...options,
    })
  }

  return {
    stats, backups, tenants, loading,
    fetchStats, fetchBackups, fetchTenants,
    runBackup, deleteBackup, restoreBackup,
  }
}
