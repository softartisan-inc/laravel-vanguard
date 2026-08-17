import { ref } from 'vue'
import { useApi } from './useApi.js'
import { reconcileById } from './reconcile.js'

/**
 * What is running right now, and whether anything is stuck.
 *
 * One endpoint answers all of it — running work, what is queued behind it,
 * recent failures, the queue depth and the judgements the server makes about
 * them — so the screen cannot show a queue depth from one moment next to rows
 * from another.
 */
const operations = ref(null)
const loaded = ref(false)

// When the payload on screen was read, by the browser's clock. Elapsed times
// are counted by the server; this is only the offset used to keep them moving
// between two polls.
const fetchedAt = ref(Date.now())

export function useOperations() {
  const api = useApi()

  async function fetchOperations() {
    const data = await api.get('/operations')

    operations.value = operations.value ? merge(operations.value, data) : data
    fetchedAt.value = Date.now()
    loaded.value = true
  }

  return { operations, loaded, fetchedAt, fetchOperations }
}

/**
 * Merge a fresh payload into the one on screen.
 *
 * Every list is reconciled by id, so a row that was already there keeps its
 * object and its DOM node: a restore whose phase moves from `importing` to
 * `verifying` updates one cell instead of rebuilding the table under the
 * operator watching it.
 */
function merge(current, incoming) {
  for (const group of ['running', 'waiting', 'recent_failures']) {
    for (const kind of ['backups', 'restores']) {
      reconcileById(current[group][kind], incoming[group][kind] || [])
    }
  }

  current.queue = incoming.queue
  current.warnings = incoming.warnings
  current.generated_at = incoming.generated_at

  return current
}
