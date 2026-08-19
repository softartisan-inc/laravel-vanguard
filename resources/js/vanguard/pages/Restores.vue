<template>
  <div class="page">
    <div class="filters-bar">
      <select
        class="form-select filter-select restore-status-filter"
        v-model="filters.status"
        @change="changeFilters"
      >
        <option value="">All statuses</option>
        <option value="completed">Completed</option>
        <option value="running">Running</option>
        <option value="failed">Failed</option>
        <option value="pending">Pending</option>
      </select>
      <input
        class="form-select filter-select"
        type="text"
        placeholder="Tenant id"
        v-model.trim="filters.tenant_id"
        @change="changeFilters"
      />
    </div>

    <div class="section">
      <div class="section-header">
        <div class="section-title">
          Restores
          <span v-if="restores.meta">· {{ restores.meta.total }} records</span>
        </div>
        <VPagination
          v-if="restores.meta && restores.meta.last_page > 1"
          :current="restores.meta.current_page"
          :last="restores.meta.last_page"
          @change="changePage"
        />
      </div>

      <div v-if="!loaded.restores" class="empty"><div class="spinner"></div></div>

      <div v-else class="table-wrap">
        <div v-if="restores.data.length === 0" class="empty">
          <div class="empty-icon">↩</div>
          <div class="empty-text">No restore has ever been run.</div>
        </div>

        <table v-else>
          <thead>
            <tr>
              <th class="col-toggle"></th>
              <th>ID</th>
              <th>Target</th>
              <th>Status</th>
              <th>Requested by</th>
              <th>From backup</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in restores.data" :key="r.id">
              <tr>
                <td class="col-toggle">
                  <button
                    class="row-toggle"
                    :class="{ open: expanded.has(r.id) }"
                    :title="expanded.has(r.id) ? 'Hide details' : 'Show details'"
                    @click="toggle(r.id)"
                  >▸</button>
                </td>
                <td><span class="row-id">#{{ r.id }}</span></td>
                <td :class="r.tenant_id ? 'col-tenant' : 'col-landlord'">
                  {{ r.target }}
                  <!--
                    A rehearsal is a completed restore that never touched the
                    target: --database sent it into a throwaway database. Shown
                    like any other row it reports data replaced that was not,
                    so it is labelled on the target itself.
                  -->
                  <span
                    v-if="r.target_database"
                    class="tag tag-filesystem"
                    :title="`Rehearsal: this restore wrote to ${r.target_database}, not to the target's own database`"
                  >Rehearsal</span>
                </td>
                <td>
                  <VBadge :status="r.status" />
                  <span v-if="r.error" class="row-flag" title="This restore failed">!</span>
                  <span v-else-if="r.phase && r.status === 'running'" class="col-dim"> {{ r.phase }}</span>
                </td>
                <!-- Null on every restore run before this column was filled in,
                     and on any run by a caller the application cannot name. An
                     em dash, never a guess. The channel sits beside the name,
                     never inside it: this cell answers "who". -->
                <td class="col-dim">
                  {{ r.requested_by || '—' }}
                  <span
                    v-if="r.origin"
                    class="tag origin-tag"
                    :title="r.origin === 'console'
                      ? 'Run from a console with vanguard:restore'
                      : 'Requested from the dashboard'"
                  >{{ r.origin }}</span>
                </td>
                <td class="col-dim">{{ r.backup_id ? `#${r.backup_id}` : '— deleted' }}</td>
                <td class="col-date">{{ formatDate(r.created_at) }}</td>
              </tr>

              <tr v-if="expanded.has(r.id)" class="row-detail">
                <td colspan="7">
                  <div v-if="r.error" class="detail-error restore-error">{{ r.error }}</div>
                  <div class="detail-grid">
                    <div><span class="detail-key">wrote to</span> {{ r.target_database || "the target's own database" }}</div>
                    <div><span class="detail-key">source</span> {{ r.source || '— first destination reached' }}</div>
                    <div><span class="detail-key">database</span> {{ r.restore_db ? 'yes' : 'no' }}</div>
                    <div><span class="detail-key">filesystem</span> {{ r.restore_storage ? 'yes' : 'no' }}</div>
                    <div><span class="detail-key">checksum verified</span> {{ r.verify_checksum ? 'yes' : 'no' }}</div>
                    <div><span class="detail-key">archive dated</span> {{ r.backup_created_at ? formatDate(r.backup_created_at) : '—' }}</div>
                    <div><span class="detail-key">started</span> {{ r.started_at ? formatDate(r.started_at) : '—' }}</div>
                    <div><span class="detail-key">completed</span> {{ r.completed_at ? formatDate(r.completed_at) : '—' }}</div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import VBadge from '../components/VBadge.vue'
import VPagination from '../components/VPagination.vue'
import { useRestores } from '../composables/useRestores.js'

const { restores, loaded, fetchRestores } = useRestores()

const filters = reactive({ status: '', tenant_id: '' })

// Ids, not rows: an id survives the merge every refresh performs.
const expanded = ref(new Set())

async function load(page = 1) {
  const f = {}
  if (filters.status) f.status = filters.status
  if (filters.tenant_id) f.tenant_id = filters.tenant_id
  await fetchRestores(page, f)
}

async function changePage(page) {
  await load(page)
}

async function changeFilters() {
  await load(1)
}

function toggle(id) {
  const next = new Set(expanded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expanded.value = next
}

function formatDate(iso) {
  return iso ? new Date(iso).toLocaleString() : '—'
}

onMounted(() => load())

// Called by App.vue on a live tick and on the refresh button, on the page the
// operator is actually reading.
defineExpose({ refresh: () => load(restores.value.meta?.current_page ?? 1) })
</script>
