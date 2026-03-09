<template>
  <div class="realtime-indicator" :class="{ connected }" :title="tooltip">
    <span class="rt-dot"></span>
    <span class="rt-label">{{ label }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  connected: { type: Boolean, default: false },
  driver:    { type: String,  default: 'sse' },
})

const label = computed(() =>
  props.connected
    ? props.driver === 'sse' ? 'Live' : 'Polling'
    : 'Offline'
)

const tooltip = computed(() =>
  props.connected
    ? props.driver === 'sse'
      ? 'Connected via Server-Sent Events — updates are instant'
      : 'Polling active — updates every few seconds'
    : 'Not connected — updates paused'
)
</script>
