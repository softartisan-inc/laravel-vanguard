<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal">
        <div class="modal-title">Restore backup #{{ record.id }}</div>

        <!-- An encrypted archive needs a private key, and a private key does
             not travel through a browser or a queue: the endpoint refuses it,
             so the dialog hands over the console command instead. -->
        <template v-if="record.encrypted">
          <div class="form-group">
            <div class="form-hint form-hint-danger">
              This backup is encrypted with age. It can only be restored from the
              server's console, with the private key that matches one of its recipients.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Run on the server</label>
            <div class="form-hint cli-command">
              <code>{{ cliCommand }}</code>
              <button class="btn btn-ghost btn-sm" @click="copyCommand">Copy</button>
            </div>
            <div class="form-hint">
              Add <code>--restore-storage</code> to restore files too, or use
              <code>--identity-stdin</code> to type the key at a hidden prompt.
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-ghost" @click="$emit('close')">Close</button>
          </div>
        </template>

        <template v-else>
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
        </template>
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

const cliCommand = computed(() => `php artisan vanguard:restore ${props.record.id} --identity=<file>`)

async function copyCommand() {
  try {
    await navigator.clipboard.writeText(cliCommand.value)
    toast.success('Command copied.')
  } catch {
    toast.error('Could not copy: select the command and copy it by hand.')
  }
}

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
