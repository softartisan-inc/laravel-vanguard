<template>
  <div class="pagination">
    <button class="page-btn" :disabled="current <= 1" @click="$emit('change', current - 1)">←</button>
    <button class="page-btn active">{{ current }} / {{ last }}</button>
    <button class="page-btn" :disabled="current >= last" @click="$emit('change', current + 1)">→</button>
  </div>
</template>

<script setup>
defineProps({
  current: { type: Number, required: true },
  last:    { type: Number, required: true },
})
defineEmits(['change'])
</script>
