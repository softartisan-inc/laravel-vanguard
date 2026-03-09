<template>
  <Teleport to="body">
    <div class="toast-container">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="t.type"
          @click="remove(t.id)"
        >
          <span class="toast-icon">
            {{ t.type === 'success' ? '✓' : t.type === 'error' ? '✕' : 'ℹ' }}
          </span>
          {{ t.message }}
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { useToast } from '../composables/useToast.js'
const { toasts, remove } = useToast()
</script>
