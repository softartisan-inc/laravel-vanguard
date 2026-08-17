import { computed, reactive, ref } from 'vue'
import { useApi } from './useApi.js'
import { reconcileById, reconcileObject } from './reconcile.js'

/**
 * Shared backup data & actions.
 * Kept outside the component so data survives page navigation.
 */
const stats = ref(null)
const backups = ref({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
const tenants = ref([])

/**
 * Whether a collection has ever arrived, and whether a fetch is in flight.
 *
 * Two flags rather than one, because the interface owes them different
 * answers. A spinner belongs to the first load only: on every refresh after
 * that the rows are already on screen, and swapping them for a spinner is what
 * made the whole window blink every ten seconds — losing the scroll position,
 * the open error and the half-typed filter with it. `pending` is there for a
 * discreet indicator, never for a v-if that unmounts the table.
 */
const loaded = reactive({ stats: false, backups: false, tenants: false })
const pending = reactive({ stats: false, backups: false, tenants: false })

// Kept for callers that only want to know whether anything is in flight.
const loading = computed(() => pending.stats || pending.backups || pending.tenants)

export function useBackups() {
  const api = useApi()

  async function fetchStats() {
    pending.stats = true
    try {
      const data = await api.get('/stats')

      // Merged, not replaced: the dashboard's recent list is a table the
      // operator may have a row expanded in.
      stats.value = stats.value
        ? reconcileObject(stats.value, data, ['recent_backups'])
        : data

      loaded.stats = true
    } finally {
      pending.stats = false
    }
  }

  async function fetchBackups(page = 1, filters = {}) {
    pending.backups = true
    try {
      const params = new URLSearchParams({ page, per_page: 15, ...filters })
      const data = await api.get(`/backups?${params}`)

      reconcileById(backups.value.data, data.data)
      backups.value.meta = data.meta

      loaded.backups = true
    } finally {
      pending.backups = false
    }
  }

  async function fetchTenants() {
    pending.tenants = true
    try {
      const data = await api.get('/tenants')

      reconcileById(tenants.value, data.tenants || [])

      loaded.tenants = true
    } finally {
      pending.tenants = false
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

  /**
   * Queue a restore.
   *
   * `confirm` is not optional: the endpoint refuses the call with a 400 unless
   * it repeats the target's name exactly — the tenant id, or 'landlord' /
   * 'filesystem' for the untenanted targets. It is an API rule, not an
   * interface courtesy, so it is a required argument here rather than one more
   * key in an options bag.
   *
   * Answers 202 with { restore_id, status: 'pending' }. Nothing has been
   * restored when this resolves.
   */
  async function restoreBackup(id, confirm, options = {}) {
    return api.post(`/backups/${id}/restore`, {
      confirm,
      verify_checksum: true,
      restore_db:      true,
      restore_storage: false,
      ...options,
    })
  }

  return {
    stats, backups, tenants, loading, loaded, pending,
    fetchStats, fetchBackups, fetchTenants,
    runBackup, deleteBackup, restoreBackup,
  }
}
