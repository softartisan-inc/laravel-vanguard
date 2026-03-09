import { ref, onUnmounted, inject } from 'vue'

/**
 * Real-time update composable.
 *
 * Drivers:
 *   'sse'     — Server-Sent Events (default). One persistent HTTP connection,
 *               server pushes events when backup state changes. Zero overhead.
 *   'polling' — Interval-based fetch. Simpler but generates constant requests.
 *               Use as fallback when SSE is not supported or desired.
 *
 * The active driver is configured in config/vanguard.php and injected
 * into the Vue app via Blade data attributes.
 *
 * Usage:
 *   const { connected, driver, startRealtime, stopRealtime } = useRealtime(onEvent)
 *   startRealtime()
 */
export function useRealtime(onEvent) {
  const basePath     = inject('basePath')
  const csrfToken    = inject('csrfToken')
  const driverName   = inject('realtimeDriver')   // 'sse' | 'polling'
  const pollInterval = inject('pollInterval')      // seconds

  const connected = ref(false)
  const driver    = ref(driverName)

  let   _sse     = null   // EventSource instance
  let   _timer   = null   // polling interval handle

  // ── SSE Driver ────────────────────────────────────────────────

  function startSse() {
    // Pass CSRF as query param — EventSource doesn't support custom headers
    const url = `${basePath}/api/stream?_token=${encodeURIComponent(csrfToken)}`

    _sse = new EventSource(url)

    _sse.addEventListener('open', () => {
      connected.value = true
    })

    // All Vanguard events share the same channel name
    _sse.addEventListener('vanguard', (e) => {
      try {
        onEvent(JSON.parse(e.data))
      } catch {}
    })

    _sse.addEventListener('error', () => {
      connected.value = false
      // EventSource auto-reconnects — no manual retry needed
    })
  }

  function stopSse() {
    if (_sse) {
      _sse.close()
      _sse = null
      connected.value = false
    }
  }

  // ── Polling Driver ────────────────────────────────────────────

  function startPolling() {
    connected.value = true
    // Caller is responsible for what to do on each tick.
    // We just emit a generic 'poll' event so the page can refresh.
    _timer = setInterval(() => {
      onEvent({ type: 'poll' })
    }, pollInterval * 1000)
  }

  function stopPolling() {
    if (_timer) {
      clearInterval(_timer)
      _timer = null
      connected.value = false
    }
  }

  // ── Public API ────────────────────────────────────────────────

  function startRealtime() {
    if (driverName === 'sse' && typeof EventSource !== 'undefined') {
      driver.value = 'sse'
      startSse()
    } else {
      // Fallback to polling if browser doesn't support SSE
      driver.value = 'polling'
      startPolling()
    }
  }

  function stopRealtime() {
    stopSse()
    stopPolling()
  }

  // Auto-cleanup when component is destroyed
  onUnmounted(stopRealtime)

  return { connected, driver, startRealtime, stopRealtime }
}
