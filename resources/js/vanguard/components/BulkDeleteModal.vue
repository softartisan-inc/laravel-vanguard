<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="$emit('close')">
      <div class="modal modal-wide">
        <div class="modal-title">Delete {{ records.length }} backup{{ records.length === 1 ? '' : 's' }}</div>

        <div class="form-group">
          <div class="form-hint form-hint-danger">
            This removes {{ records.length }} archive{{ records.length === 1 ? '' : 's' }} from every
            destination they reached, and their rows in the catalogue. It cannot be undone.
          </div>
        </div>

        <!-- What, not just how many. A count alone is a number to click past;
             the operator has to see which targets and which dates leave. -->
        <div class="form-group">
          <label class="form-label">What is about to go</label>
          <div class="bulk-summary">
            <div v-for="line in summary" :key="line.target" class="bulk-summary-row">
              <span class="bulk-summary-target">{{ line.target }}</span>
              <span class="bulk-summary-count">{{ line.count }} archive{{ line.count === 1 ? '' : 's' }}</span>
              <span class="bulk-summary-range">{{ line.range }}</span>
            </div>
          </div>
          <div class="form-hint">Ids: {{ ids.join(', ') }}</div>
        </div>

        <!--
          Typed, unlike a single delete. Deleting one archive destroys one
          archive; this destroys a page of them, and the number is the part an
          operator gets wrong — so the number is what has to be typed back. The
          server refuses the call on the same phrase, so a curl without it is
          refused exactly as this button is.
        -->
        <div class="form-group">
          <label class="form-label">Type the phrase to confirm</label>
          <input
            class="form-input"
            v-model="confirmation"
            type="text"
            :placeholder="phrase"
            autocomplete="off"
            spellcheck="false"
            autofocus
            @keyup.enter="matches && submit()"
          />
          <div class="form-hint">
            Expected: <code>{{ phrase }}</code>
          </div>
        </div>

        <!-- The honest report of a partial outcome, kept on screen rather than
             flashed as a toast: these lines are what the operator has to act
             on next. -->
        <div v-if="failures.length" class="form-group">
          <label class="form-label">Not deleted</label>
          <div class="bulk-failures">
            <div v-for="f in failures" :key="f.id" class="bulk-failure">
              <span class="bulk-failure-id">#{{ f.id }}</span> {{ f.error }}
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-ghost" @click="$emit('close')">
            {{ failures.length ? 'Close' : 'Cancel' }}
          </button>
          <button
            v-if="!failures.length"
            class="btn btn-danger"
            :disabled="!matches || running"
            @click="submit"
          >
            <span v-if="running" class="spinner"></span>
            <span v-else>✕ Delete {{ records.length }}</span>
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
  // The records themselves, so the dialog can name targets and dates rather
  // than a list of numbers.
  records: { type: Array, required: true },
})

const emit = defineEmits(['close', 'done'])

const { bulkDeleteBackups, bulkDeletePhrase } = useBackups()
const toast = useToast()

const confirmation = ref('')
const running      = ref(false)
const failures     = ref([])

const ids = computed(() => props.records.map((r) => r.id))

const phrase = computed(() => bulkDeletePhrase(props.records.length))

const matches = computed(() => confirmation.value === phrase.value)

/** One line per target: what leaves, how much of it, and over which dates. */
const summary = computed(() => {
  const groups = new Map()

  for (const record of props.records) {
    const target = record.tenant_id || record.type
    const at = record.created_at ? new Date(record.created_at) : null
    const group = groups.get(target) || { target, count: 0, from: at, to: at }

    group.count++
    if (at) {
      if (!group.from || at < group.from) group.from = at
      if (!group.to   || at > group.to)   group.to = at
    }
    groups.set(target, group)
  }

  return [...groups.values()].map((g) => ({
    target: g.target,
    count:  g.count,
    range:  g.from
      ? (g.from.getTime() === g.to.getTime()
        ? g.from.toLocaleString()
        : `${g.from.toLocaleDateString()} → ${g.to.toLocaleDateString()}`)
      : '—',
  }))
})

async function submit() {
  if (!matches.value) return

  running.value = true
  try {
    const res = await bulkDeleteBackups(ids.value, confirmation.value)
    const deleted = res.deleted || []
    const failed  = res.failed  || []

    // Never a blanket success: what went and what did not are both said, and
    // the dialog stays open on the failures so their reasons can be read.
    if (failed.length === 0) {
      toast.success(`${deleted.length} backup${deleted.length === 1 ? '' : 's'} deleted.`)
      emit('done', { deleted, failed })
      emit('close')
    } else {
      failures.value = failed
      toast.error(`${deleted.length} deleted, ${failed.length} failed.`)
      emit('done', { deleted, failed })
    }
  } catch (e) {
    toast.error(e.message)
  } finally {
    running.value = false
  }
}
</script>
