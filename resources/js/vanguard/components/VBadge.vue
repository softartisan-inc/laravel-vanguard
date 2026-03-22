<template>
  <span class="badge" :class="`badge-${status}`">{{ status }}</span>
</template>

<script setup>
defineProps({
  status: { type: String, required: true }
})
</script>
