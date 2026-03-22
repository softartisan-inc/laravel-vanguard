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
        <tr v-for="r in records" :key="r.id">
          <td><span class="row-id">#{{ r.id }}</span></td>
          <td><span class="tag" :class="`tag-${r.type}`">{{ r.type }}</span></td>
          <td :class="r.tenant_id ? 'col-tenant' : 'col-landlord'">
            {{ r.tenant_id || '— landlord' }}
          </td>
          <td><VBadge :status="r.status" /></td>
          <td>{{ r.file_size_human || '—' }}</td>
          <td class="col-dim">{{ r.duration || '—' }}</td>
          <td class="col-date">{{ r.created_at ? formatDate(r.created_at) : '—' }}</td>
          <td v-if="withActions">
            <div class="action-row">
              <button class="btn btn-ghost btn-sm" @click="$emit('restore', r.id)">
                ↩ Restore
              </button>
              <button class="btn btn-danger btn-sm" @click="$emit('delete', r.id)">
                ✕
              </button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import VBadge from './VBadge.vue'

defineProps({
  records:     { type: Array,   default: () => [] },
  withActions: { type: Boolean, default: false },
})

defineEmits(['restore', 'delete'])

function formatDate(iso) {
  return new Date(iso).toLocaleString()
}
</script>
