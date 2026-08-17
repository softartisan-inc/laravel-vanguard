<template>
  <div class="table-wrap">
    <!-- Empty state -->
    <div v-if="!records || records.length === 0" class="empty">
      <div class="empty-icon">🗄</div>
      <div class="empty-text">No backups found.</div>
    </div>

    <table v-else>
      <thead>
        <tr>
          <th class="col-toggle"></th>
          <th>ID</th>
          <th>Type</th>
          <th>Tenant</th>
          <th>Status</th>
          <th>Size</th>
          <th>Duration</th>
          <th>Date</th>
          <th v-if="withActions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="r in records" :key="r.id">
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
            <td><span class="tag" :class="`tag-${r.type}`">{{ r.type }}</span></td>
            <td :class="r.tenant_id ? 'col-tenant' : 'col-landlord'">
              {{ r.tenant_id || '— landlord' }}
            </td>
            <td>
              <VBadge :status="r.status" />
              <span v-if="r.error" class="row-flag" title="This backup failed">!</span>
              <span
                v-else-if="r.filesystem_empty"
                class="row-flag"
                title="This backup archived no file at all"
              >∅</span>
            </td>
            <td>{{ r.file_size_human || '—' }}</td>
            <td class="col-dim">{{ r.duration || '—' }}</td>
            <td class="col-date">{{ r.created_at ? formatDate(r.created_at) : '—' }}</td>
            <td v-if="withActions">
              <div class="action-row">
                <!-- The whole record, not just the id: the restore dialog has
                     to know which target the operator must type back, and the
                     delete dialog names the target and the date so the operator
                     confirms an archive rather than a number. -->
                <button class="btn btn-ghost btn-sm" @click="$emit('restore', r)">
                  ↩ Restore
                </button>
                <button class="btn btn-danger btn-sm" @click="$emit('delete', r)">
                  ✕
                </button>
              </div>
            </td>
          </tr>

          <!--
            The detail panel. Its open/closed state lives here, keyed by record
            id, and the rows are merged in place on every refresh rather than
            replaced — so a panel opened on a failure stays open, and its text
            follows the record, across as many live ticks as the operator needs
            to read it.
          -->
          <tr v-if="expanded.has(r.id)" class="row-detail">
            <td :colspan="withActions ? 9 : 8">
              <div v-if="r.error" class="detail-error">{{ r.error }}</div>
              <div v-else-if="r.filesystem_empty" class="detail-error">
                This backup archived no file at all: the filesystem paths it was
                pointed at held nothing.
              </div>
              <div class="detail-grid">
                <div><span class="detail-key">destinations</span> {{ destinations(r) }}</div>
                <div><span class="detail-key">checksum</span> {{ r.checksum || '—' }}</div>
                <div><span class="detail-key">started</span> {{ r.started_at ? formatDate(r.started_at) : '—' }}</div>
                <div><span class="detail-key">completed</span> {{ r.completed_at ? formatDate(r.completed_at) : '—' }}</div>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import VBadge from './VBadge.vue'

defineProps({
  records:     { type: Array,   default: () => [] },
  withActions: { type: Boolean, default: false },
})

defineEmits(['restore', 'delete'])

// Ids, not rows: an id survives the record being merged, re-ordered or paged
// away and back.
const expanded = ref(new Set())

function toggle(id) {
  const next = new Set(expanded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expanded.value = next
}

function destinations(r) {
  const list = Array.isArray(r.destinations) ? r.destinations : []

  return list.length ? list.join(', ') : '—'
}

function formatDate(iso) {
  return new Date(iso).toLocaleString()
}
</script>
