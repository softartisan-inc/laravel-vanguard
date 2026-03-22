<template>
  <div id="app">
    <!-- Sidebar -->
    <aside id="sidebar">
      <div class="sidebar-logo">
        <div class="logo-mark">Van<span>guard</span></div>
        <div class="logo-sub">by SoftArtisan</div>
      </div>

      <nav class="sidebar-nav">
        <div
          v-for="item in navItems"
          :key="item.page"
          class="nav-item"
          :class="{ active: currentPage === item.page }"
          @click="navigate(item.page)"
        >
          <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" v-html="item.icon" />
          {{ item.label }}
        </div>
      </nav>

      <div class="sidebar-footer">
        <RealtimeIndicator :connected="rtConnected" :driver="rtDriver" />
      </div>
    </aside>

    <!-- Main -->
    <div id="main">
      <!-- Topbar -->
      <div class="topbar">
        <div class="topbar-title">{{ pageTitle }}</div>
        <div class="topbar-actions">
          <!-- Realtime toggle -->
          <button
            class="btn btn-ghost"
            :title="rtConnected ? 'Pause live updates' : 'Resume live updates'"
            @click="toggleRealtime"
          >
            {{ rtConnected ? '⏸ Pause' : '▶ Resume' }}
          </button>

          <button class="btn btn-ghost" @click="refresh" :disabled="loading">
            <span :class="{ spinning: loading }">↻</span> Refresh
          </button>

          <button class="btn btn-primary" @click="showRunModal = true">
            + Run Backup
          </button>
        </div>
      </div>

      <!-- Page content -->
      <div id="content">
        <component
          :is="currentComponent"
          :key="currentPage"
          ref="pageRef"
        />
      </div>
    </div>

    <!-- Run modal -->
    <RunModal
      v-if="showRunModal"
      :running="modalRunning"
      @close="showRunModal = false"
      @run="handleRun"
    />

    <!-- Global toasts -->
    <VToast />
  </div>
</template>

<script setup>
import { ref, computed, provide, onMounted, shallowRef } from 'vue'

import Dashboard  from './pages/Dashboard.vue'
import Backups    from './pages/Backups.vue'
import Tenants    from './pages/Tenants.vue'
import RunModal   from './components/RunModal.vue'
import VToast     from './components/VToast.vue'
import RealtimeIndicator from './components/RealtimeIndicator.vue'

import { useRealtime } from './composables/useRealtime.js'
import { useBackups }  from './composables/useBackups.js'
import { useToast }    from './composables/useToast.js'

// ── Props from Blade data attributes ─────────────────────────
const props = defineProps({
  basePath:       { type: String, required: true },
  csrfToken:      { type: String, required: true },
  realtimeDriver: { type: String, default: 'polling' },
  pollInterval:   { type: Number, default: 5 },
})

// Provide config to all child composables via inject()
provide('basePath',       props.basePath)
provide('csrfToken',      props.csrfToken)
provide('realtimeDriver', props.realtimeDriver)
provide('pollInterval',   props.pollInterval)

// ── Navigation ────────────────────────────────────────────────
const pages = {
  dashboard: { label: 'Dashboard',   component: Dashboard, icon: '<path d="M2 10a8 8 0 1116 0A8 8 0 012 10zm8-3a1 1 0 00-1 1v3a1 1 0 001 1h2a1 1 0 100-2H10V8a1 1 0 00-1-1z"/>' },
  backups:   { label: 'All Backups', component: Backups,   icon: '<path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>' },
  tenants:   { label: 'Tenants',     component: Tenants,   icon: '<path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zm5 6a2 2 0 10-4 0 2 2 0 004 0zm-9 0a2 2 0 10-4 0 2 2 0 004 0z"/>' },
}

const navItems      = Object.entries(pages).map(([page, p]) => ({ page, ...p }))
const currentPage   = ref('dashboard')
const currentComponent = shallowRef(Dashboard)
const pageTitle     = computed(() => pages[currentPage.value]?.label || '')
const pageRef       = ref(null)

function navigate(page) {
  currentPage.value      = page
  currentComponent.value = pages[page].component
}

// ── Refresh current page ──────────────────────────────────────
const { loading } = useBackups()

function refresh() {
  pageRef.value?.refresh?.()
}

// ── Run backup modal ──────────────────────────────────────────
const showRunModal = ref(false)
const modalRunning  = ref(false)
const toast = useToast()
const { runBackup } = useBackups()

async function handleRun({ type, tenantId }) {
  modalRunning.value = true
  try {
    const res = await runBackup(type, tenantId)
    toast.success(res.queued ? 'Backup queued.' : 'Backup started.')
    showRunModal.value = false
    setTimeout(refresh, 1500)
  } catch (e) {
    toast.error(e.message)
  } finally {
    modalRunning.value = false
  }
}

// ── Realtime ──────────────────────────────────────────────────
const { connected: rtConnected, driver: rtDriver, startRealtime, stopRealtime } = useRealtime(onRealtimeEvent)

function onRealtimeEvent(event) {
  // On any backup state change or poll tick → refresh current page
  if (event.type === 'backup.updated' || event.type === 'backup.completed'
    || event.type === 'backup.failed'  || event.type === 'poll') {
    refresh()
  }
}

function toggleRealtime() {
  rtConnected.value ? stopRealtime() : startRealtime()
}

onMounted(startRealtime)
</script>
