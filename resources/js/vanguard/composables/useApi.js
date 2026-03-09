import { inject } from 'vue'

/**
 * Centralized API client.
 * BASE_PATH and CSRF_TOKEN are provided via app-level provide() in App.vue.
 */
export function useApi() {
  const basePath  = inject('basePath')
  const csrfToken = inject('csrfToken')

  async function request(path, options = {}) {
    const url = `${basePath}/api${path}`

    const res = await fetch(url, {
      ...options,
      headers: {
        'Content-Type':     'application/json',
        'Accept':           'application/json',
        'X-CSRF-TOKEN':     csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {}),
      },
    })

    // Non-2xx → parse error body and throw
    if (!res.ok) {
      let msg = `HTTP ${res.status}`
      try {
        const body = await res.json()
        msg = body.error || body.message || msg
      } catch {}
      throw new Error(msg)
    }

    return res.json()
  }

  return {
    get:    (path)              => request(path),
    post:   (path, data = {})  => request(path, { method: 'POST',   body: JSON.stringify(data) }),
    delete: (path)              => request(path, { method: 'DELETE' }),
  }
}
