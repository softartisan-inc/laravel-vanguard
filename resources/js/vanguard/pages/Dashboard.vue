<template>
  <div class="page">
    <!-- Loading -->
    <div v-if="loading && !stats" class="empty">
      <div class="spinner"></div>
    </div>

    <template v-else-if="stats">
      <StatCards :stats="stats" />

      <div class="section">
        <div class="section-header">
          <div class="section-title">Recent Backups</div>
        </div>
        <BackupTable :records="stats.recent_backups" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import StatCards  from '../components/StatCards.vue'
import BackupTable from '../components/BackupTable.vue'
import { useBackups } from '../composables/useBackups.js'

const { stats, loading, fetchStats } = useBackups()

onMounted(fetchStats)

// Called by App.vue when a realtime event arrives
defineExpose({ refresh: fetchStats })
</script>
