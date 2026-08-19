import { reactive, ref } from 'vue'
import { useApi } from './useApi.js'
import { reconcileById } from './reconcile.js'

/**
 * The restore history.
 *
 * Held outside the component, like the backups, so the rows survive a page
 * change — and merged in place on every refresh rather than replaced, so a
 * detail panel opened on a failure stays open while the list ticks.
 */
const restores = ref({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })

const loaded = reactive({ restores: false })
const pending = reactive({ restores: false })

export function useRestores() {
  const api = useApi()

  async function fetchRestores(page = 1, filters = {}) {
    pending.restores = true
    try {
      const params = new URLSearchParams({ page, per_page: 15, ...filters })
      const data = await api.get(`/restores?${params}`)

      reconcileById(restores.value.data, data.data)
      restores.value.meta = data.meta

      loaded.restores = true
    } finally {
      pending.restores = false
    }
  }

  return { restores, loaded, pending, fetchRestores }
}
