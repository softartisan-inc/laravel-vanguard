<template>
  <div class="page">
    <!-- First load only, like everywhere else: a poll patches what is here. -->
    <div v-if="!loaded" class="empty"><div class="spinner"></div></div>

    <template v-else-if="operations">
      <!-- What the server concluded, before anything it merely counted. -->
      <div v-if="operations.warnings.length" class="ops-warnings">
        <div
          v-for="w in operations.warnings"
          :key="w.code"
          class="ops-warning"
          :class="`ops-warning-${w.level}`"
        >
          <div class="ops-warning-message">{{ w.message }}</div>
          <div v-if="w.rows && w.rows.length" class="ops-warning-rows">
            <span v-for="row in w.rows" :key="`${row.kind}-${row.id}`" class="ops-warning-row">
              {{ row.kind }} #{{ row.id }} · {{ duration(row.waiting_seconds ?? row.elapsed_seconds) }}
            </span>
          </div>
        </div>
      </div>

      <div class="stats-grid">
        <div class="stat-card warn">
          <div class="stat-label">Running</div>
          <div class="stat-value">{{ running.length }}</div>
          <div class="stat-sub">Backups and restores in progress</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-label">Waiting</div>
          <div class="stat-value">{{ waiting.length }}</div>
          <div class="stat-sub">Rows queued, not yet started</div>
        </div>
        <div class="stat-card accent">
          <div class="stat-label">Queue depth</div>
          <!-- Never a 0 for a queue that could not be read: unknown says so. -->
          <div class="stat-value">{{ operations.queue.pending ?? '?' }}</div>
          <div class="stat-sub">
            {{ operations.queue.pending === null
              ? 'Unreadable — ' + (operations.queue.reason || 'unknown reason')
              : `Jobs on [${operations.queue.queue}]` }}
          </div>
        </div>
        <div class="stat-card danger">
          <div class="stat-label">Failed (24h)</div>
          <div class="stat-value">{{ failures.length }}</div>
          <div class="stat-sub">Backups and restores</div>
        </div>
      </div>

      <!-- Running -->
      <div class="section">
        <div class="section-header">
          <div class="section-title">Running now</div>
          <div class="section-note">as of {{ formatTime(operations.generated_at) }}</div>
        </div>
        <div class="table-wrap">
          <div v-if="!running.length" class="empty">
            <div class="empty-icon">🌙</div>
            <div class="empty-text">Nothing is running.</div>
          </div>
          <table v-else>
            <thead>
              <tr>
                <th>Job</th>
                <th>Target</th>
                <th>Phase</th>
                <th>Elapsed</th>
                <th>Started</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in running" :key="`${row.kind}-${row.id}`">
                <td><span class="tag" :class="`tag-${row.kind}`">{{ row.kind }}</span> <span class="row-id">#{{ row.id }}</span></td>
                <td :class="row.tenant_id ? 'col-tenant' : 'col-landlord'">
                  {{ row.target }}
                  <!-- A rehearsal writes to a throwaway database. Unlabelled,
                       this line reads as the target being overwritten. -->
                  <span
                    v-if="row.target_database"
                    class="tag tag-filesystem"
                    :title="`Rehearsal: writing to ${row.target_database}, not to the target's own database`"
                  >Rehearsal</span>
                </td>
                <!-- A restore holds one status for minutes while moving
                     through five phases; without this the screen looks hung. -->
                <td>{{ row.phase || '—' }}</td>
                <td class="ops-elapsed">{{ duration(live(row.elapsed_seconds)) }}</td>
                <td class="col-date">{{ row.started_at ? formatTime(row.started_at) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Waiting -->
      <div class="section">
        <div class="section-header">
          <div class="section-title">Queued behind it</div>
        </div>
        <div class="table-wrap">
          <div v-if="!waiting.length" class="empty">
            <div class="empty-text">Nothing is waiting.</div>
          </div>
          <table v-else>
            <thead>
              <tr>
                <th>Job</th>
                <th>Target</th>
                <th>Waiting</th>
                <th>Requested</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in waiting" :key="`${row.kind}-${row.id}`" :class="{ 'row-stale': stale(row) }">
                <td><span class="tag" :class="`tag-${row.kind}`">{{ row.kind }}</span> <span class="row-id">#{{ row.id }}</span></td>
                <td :class="row.tenant_id ? 'col-tenant' : 'col-landlord'">
                  {{ row.target }}
                  <!-- A rehearsal writes to a throwaway database. Unlabelled,
                       this line reads as the target being overwritten. -->
                  <span
                    v-if="row.target_database"
                    class="tag tag-filesystem"
                    :title="`Rehearsal: writing to ${row.target_database}, not to the target's own database`"
                  >Rehearsal</span>
                </td>
                <td class="ops-elapsed">{{ duration(live(row.waiting_seconds)) }}</td>
                <td class="col-date">{{ row.created_at ? formatTime(row.created_at) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent failures -->
      <div class="section">
        <div class="section-header">
          <div class="section-title">Failed in the last 24 hours</div>
        </div>
        <div class="table-wrap">
          <div v-if="!failures.length" class="empty">
            <div class="empty-text">Nothing failed in the last 24 hours.</div>
          </div>
          <table v-else>
            <thead>
              <tr>
                <th>Job</th>
                <th>Target</th>
                <th>When</th>
                <th>Error</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in failures" :key="`${row.kind}-${row.id}`">
                <td><span class="tag" :class="`tag-${row.kind}`">{{ row.kind }}</span> <span class="row-id">#{{ row.id }}</span></td>
                <td :class="row.tenant_id ? 'col-tenant' : 'col-landlord'">
                  {{ row.target }}
                  <!-- A rehearsal writes to a throwaway database. Unlabelled,
                       this line reads as the target being overwritten. -->
                  <span
                    v-if="row.target_database"
                    class="tag tag-filesystem"
                    :title="`Rehearsal: writing to ${row.target_database}, not to the target's own database`"
                  >Rehearsal</span>
                </td>
                <td class="col-date">{{ formatTime(row.completed_at || row.created_at) }}</td>
                <!-- The exact message, not a redaction: it is what the
                     operator acts on next. -->
                <td class="ops-error">{{ row.error || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useOperations } from '../composables/useOperations.js'

const { operations, loaded, fetchedAt, fetchOperations } = useOperations()

// The browser's clock, once a second, used only as an offset on the elapsed
// times the server computed. A workstation four minutes off still shows the
// right duration; only the ticking between two polls comes from here.
const now = ref(Date.now())
let ticker = null
let poller = null

const offset = computed(() => Math.max(0, Math.floor((now.value - fetchedAt.value) / 1000)))

function live(seconds) {
  return seconds === null || seconds === undefined ? null : seconds + offset.value
}

const running = computed(() => [
  ...(operations.value?.running.restores || []),
  ...(operations.value?.running.backups || []),
])

const waiting = computed(() => [
  ...(operations.value?.waiting.restores || []),
  ...(operations.value?.waiting.backups || []),
])

const failures = computed(() => [
  ...(operations.value?.recent_failures.restores || []),
  ...(operations.value?.recent_failures.backups || []),
])

/** Waiting longer than the grace the server applies before it says so. */
function stale(row) {
  return live(row.waiting_seconds) >= 120
}

function duration(seconds) {
  if (seconds === null || seconds === undefined) return '—'

  const s = Math.max(0, seconds)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)

  if (h) return `${h}h ${m}m`
  if (m) return `${m}m ${s % 60}s`

  return `${s}s`
}

function formatTime(iso) {
  return iso ? new Date(iso).toLocaleTimeString() : '—'
}

onMounted(() => {
  fetchOperations()

  ticker = setInterval(() => { now.value = Date.now() }, 1000)

  // Its own poll, on top of the live channel. The channel only fires when a
  // backup or a restore changes, and the state this screen is for — a queue
  // nothing consumes, a worker that was killed — is precisely the state in
  // which nothing changes and no event is ever sent.
  poller = setInterval(fetchOperations, 5000)
})

onUnmounted(() => {
  clearInterval(ticker)
  clearInterval(poller)
})

defineExpose({ refresh: fetchOperations })
</script>
