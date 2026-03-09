# Vanguard — Backup Manager for Laravel

A multi-tenant backup dashboard for Laravel, built with Vue 3 + Vite and real-time updates via Server-Sent Events.

---

## Installation

```bash
composer require softartisan/vanguard
```

### 1. Publish config & run migrations

```bash
php artisan vendor:publish --tag=vanguard-config
php artisan vendor:publish --tag=vanguard-migrations
php artisan migrate
```

### 2. Build frontend assets

```bash
cd vendor/softartisan/vanguard
npm install
npm run build
cd -
php artisan vendor:publish --tag=vanguard-assets
```

Local development with hot-reload:
```bash
cd vendor/softartisan/vanguard && npm run watch
```

On deploy: re-run `npm run build` + `vendor:publish --tag=vanguard-assets` only when the package version changes.

---

## Configuration — `config/vanguard.php`

```php
'path' => env('VANGUARD_PATH', 'vanguard'),   // yourapp.com/vanguard

'realtime' => [
    'driver'       => env('VANGUARD_REALTIME_DRIVER', 'sse'),  // 'sse' | 'polling'
    'interval'     => env('VANGUARD_POLL_INTERVAL', 5),        // seconds (polling only)
    'sse_interval' => env('VANGUARD_SSE_INTERVAL', 2),         // DB check interval (SSE)
    'max_lifetime' => env('VANGUARD_SSE_LIFETIME', 120),       // auto-reconnect after Ns
],
```

### Real-time drivers

| Driver | Mechanism | Best for |
|--------|-----------|----------|
| `sse` *(default)* | One persistent HTTP connection; server pushes only on state change | Most setups — zero overhead at idle |
| `polling` | API fetch every N seconds | Proxies/hosts that block streaming |

**Nginx**: add `proxy_buffering off;` to your location block for SSE.

---

## Authentication

```php
// AppServiceProvider::boot()
use SoftArtisan\Vanguard\Facades\Vanguard;

Vanguard::auth(fn (Request $r) => $r->user()?->isAdmin());
```

---

## Multi-tenancy

```php
'tenancy' => [
    'enabled'      => true,
    'tenant_model' => \App\Models\Tenant::class,
    'tenant_key'   => 'id',
],
```

---

## Frontend architecture

```
resources/
├── css/vanguard.css
└── js/vanguard/
    ├── app.js                  ← Vue entry point
    ├── App.vue                 ← layout, navigation, realtime orchestration
    ├── composables/
    │   ├── useApi.js           ← fetch wrapper (CSRF, base URL via inject)
    │   ├── useBackups.js       ← shared state: stats, backups, tenants
    │   ├── useRealtime.js      ← SSE / polling driver (auto-fallback)
    │   └── useToast.js         ← global toast notifications
    ├── components/
    │   ├── BackupTable.vue     ← reusable table (with or without actions)
    │   ├── StatCards.vue
    │   ├── RunModal.vue
    │   ├── VBadge.vue          ← status badge (completed/running/failed/pending)
    │   ├── VPagination.vue
    │   ├── VToast.vue
    │   └── RealtimeIndicator.vue  ← Live / Polling / Offline dot in sidebar
    └── pages/
        ├── Dashboard.vue
        ├── Backups.vue         ← full list with status/type filters + pagination
        └── Tenants.vue
```

The Blade layout is a minimal shell — mounts Vue and passes config via `data-*` attributes. No inline JS, no global variables.
