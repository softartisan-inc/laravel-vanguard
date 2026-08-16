<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal">
        <div class="modal-title">Restore backup #{{ record.id }}</div>

        <div class="form-group">
          <div class="form-hint form-hint-danger">
            This overwrites the live database of <strong>{{ target }}</strong> with the
            contents of a backup taken on {{ takenAt }}. There is no way back.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Type the target name to confirm</label>
          <input
            class="form-input"
            v-model="confirmation"
            type="text"
            :placeholder="target"
            autocomplete="off"
            spellcheck="false"
            autofocus
            @keyup.enter="matches && submit()"
          />
          <div class="form-hint">
            Expected: <code>{{ target }}</code>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-ghost" @click="$emit('close')">Cancel</button>
          <button class="btn btn-danger" :disabled="!matches || running" @click="submit">
            <span v-if="running" class="spinner"></span>
            <span v-else>↩ Restore</span>
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

const { restoreBackup } = useBackups()
const toast = useToast()

const confirmation = ref('')
const running      = ref(false)

/**
 * What the server will require back, verbatim: the tenant this backup belongs
 * to, or 'landlord' / 'filesystem' for the untenanted targets. Mirrored here
 * rather than discovered through a 400, so the button is inert until the
 * operator has actually typed it.
 */
const target = computed(() => props.record.tenant_id || props.record.type)

const matches = computed(() => confirmation.value === target.value)

const takenAt = computed(() =>
  props.record.created_at ? new Date(props.record.created_at).toLocaleString() : 'an unknown date',
)

async function submit() {
  if (!matches.value) return

  running.value = true
  try {
    const res = await restoreBackup(props.record.id, confirmation.value)
    // Queued, not done: the endpoint answers 202 with a pending restore id and
    // the work happens on a worker. Saying "completed" here would be a claim
    // nothing has verified.
    toast.success(`Restore queued as #${res.restore_id}. Follow it in the restore history.`)
    emit('close')
    emit('success')
  } catch (e) {
    toast.error(e.message)
  } finally {
    running.value = false
  }
}
</script>
