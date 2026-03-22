import { createApp } from 'vue'
import App from './App.vue'
import '../../css/vanguard.css'

// Mount on the element injected by Blade
const el = document.getElementById('vanguard-app')

if (el) {
  const app = createApp(App, {
    // Props passed via data attributes from Blade
    basePath:      el.dataset.basePath,
    csrfToken:     el.dataset.csrfToken,
    realtimeDriver: el.dataset.realtimeDriver  || 'sse',
    pollInterval:  parseInt(el.dataset.pollInterval || '5', 10),
  })

  app.mount(el)
}
