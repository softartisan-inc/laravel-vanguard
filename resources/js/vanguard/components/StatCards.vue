<template>
  <div class="stats-grid">
    <div class="stat-card accent">
      <div class="stat-label">Tenants</div>
      <div class="stat-value">{{ stats.total_tenants }}</div>
      <div class="stat-sub">Active tenant databases</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Total Backups</div>
      <div class="stat-value">{{ stats.total_backups }}</div>
      <div class="stat-sub">{{ stats.total_size_human }} stored</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Running</div>
      <div class="stat-value">{{ stats.running_backups }}</div>
      <div class="stat-sub">Currently in progress</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-label">Failed (24h)</div>
      <div class="stat-value">{{ stats.failed_recent }}</div>
      <div class="stat-sub">Requires attention</div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  stats: { type: Object, required: true }
})
</script>
