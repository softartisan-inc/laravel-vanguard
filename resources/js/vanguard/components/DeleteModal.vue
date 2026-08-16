<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal">
        <div class="modal-title">Delete backup #{{ record.id }}</div>

        <div class="form-group">
          <div class="form-hint form-hint-danger">
            This removes the archive of <strong>{{ target }}</strong> taken on {{ takenAt }},
            on every destination it reached, and its row in the catalogue.
            It cannot be undone, and it is one fewer backup to restore from.
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-ghost" @click="$emit('close')">Cancel</button>
          <button class="btn btn-danger" :disabled="running" @click="submit">
            <span v-if="running" class="spinner"></span>
            <span v-else>✕ Delete</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useBackups } from '../composables/useBackups.js'
import { useToast }   from '../composables/useToast.js'

const props = defineProps({
  record: { type: Object, required: true },
})

const emit = defineEmits(['close', 'success'])

const { deleteBackup } = useBackups()
const toast = useToast()

const running = ref(false)

/** Which target this archive belongs to, said the way the restore dialog says it. */
const target = computed(() => props.record.tenant_id || props.record.type)

const takenAt = computed(() =>
  props.record.created_at ? new Date(props.record.created_at).toLocaleString() : 'an unknown date',
)

/**
 * No name to type back, deliberately. That guard belongs to restore and prune,
 * which overwrite or erase data the operator still has; deleting one archive
 * destroys only that archive, and asking for a typed confirmation everywhere is
 * how operators learn to type through confirmations without reading them.
 */
async function submit() {
  running.value = true
  try {
    await deleteBackup(props.record.id)
    toast.success('Backup deleted.')
    emit('close')
    emit('success')
  } catch (e) {
    toast.error(e.message)
  } finally {
    running.value = false
  }
}
</script>
