<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal">
        <div class="modal-title">Run Backup</div>

        <div class="form-group">
          <label class="form-label">Backup Type</label>
          <select class="form-select" v-model="type">
            <option value="landlord">Landlord (Central DB + Filesystem)</option>
            <option value="tenant">Specific Tenant</option>
            <option value="all-tenants">All Tenants</option>
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
import { ref } from 'vue'

// running est géré par le parent (App.vue) pour éviter await emit()
defineProps({
  running: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'run'])

const type     = ref('landlord')
const tenantId = ref('')

function submit() {
  emit('run', {
    type:     type.value,
    tenantId: type.value === 'tenant' ? tenantId.value : null,
  })
}
</script>
