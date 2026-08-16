<?php

use App\Models\Tenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Path & Domain
    |--------------------------------------------------------------------------
    | Customize where the Vanguard dashboard is accessible.
    | Set 'domain' to null to use your app's default domain.
    |
    | You can also configure these programmatically in AppServiceProvider:
    |   Vanguard::path('admin/backups')->domain('tools.acme.com');
    */
    'path' => env('VANGUARD_PATH', 'vanguard'),
    'domain' => env('VANGUARD_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Middleware applied to all Vanguard dashboard routes.
    | Add your own auth middleware here, or use Vanguard::auth() for
    | a programmatic gate callback (recommended).
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    | Enable stancl/tenancy v3 integration. When enabled, Vanguard will
    | discover tenants and allow per-tenant backup operations.
    |
    | 'tenant_model' must implement Stancl\Tenancy\Contracts\Tenant
    */
    'tenancy' => [
        'enabled' => env('VANGUARD_TENANCY_ENABLED', true),
        'tenant_model' => env('VANGUARD_TENANT_MODEL', Tenant::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Sources
    |--------------------------------------------------------------------------
    | Define what gets backed up. Each source can be toggled independently.
    */
    'sources' => [
        'landlord_database' => true,   // Central (landlord) database
        'tenant_databases' => true,   // All tenant databases
        'filesystem' => true,   // Application filesystem (storage/)
        'filesystem_paths' => [       // Paths relative to storage_path()
            'app',
        ],
        'filesystem_exclude' => [      // Paths to exclude
            'app/public/tmp',
            'logs',
            'framework',
        ],

        /*
        | What to do when a backup was asked for the filesystem and not one of
        | the paths above exists — the tenant whose storage root has no 'app'
        | directory, the entry configured for a sibling installation. The
        | archive is then a valid, tiny, empty tarball, and it used to be
        | reported as a success.
        |
        | 'warn' (default) logs the tenant, the configured paths and the
        |        resolved storage root, tells the console, and marks the backup
        |        record so the dashboard and the API can say the archive
        |        carries no file. The backup still completes: an installation
        |        that genuinely keeps nothing under storage/app is legitimate.
        | 'fail' refuses the backup instead. Set it on an installation where an
        |        empty filesystem archive can only mean a misconfiguration.
        */
        'on_empty_filesystem' => env('VANGUARD_ON_EMPTY_FILESYSTEM', 'warn'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Destinations
    |--------------------------------------------------------------------------
    | 'local'  — stores backups on the server disk.
    | 'remote' — stores backups on a configured Laravel filesystem disk (e.g. s3).
    | 'ftp'    — stores backups on an FTP or SFTP disk (any Flysystem FTP/SFTP adapter).
    |
    | Multiple destinations can be enabled simultaneously. The backup file is
    | streamed to each enabled destination in the order: remote → ftp → local.
    |
    | For FTP/SFTP, configure a disk in config/filesystems.php using the
    | league/flysystem-ftp or league/flysystem-sftp-v3 adapter, then set
    | VANGUARD_FTP_DISK to that disk name.
    */
    'destinations' => [
        'local' => [
            // Env-driven so a deployment that keeps no backup on the server
            // itself can say so without publishing this file.
            'enabled' => env('VANGUARD_LOCAL_ENABLED', true),
            'disk' => env('VANGUARD_LOCAL_DISK', 'local'),
            'path' => env('VANGUARD_LOCAL_PATH', 'vanguard-backups'),
        ],
        'remote' => [
            'enabled' => env('VANGUARD_REMOTE_ENABLED', false),
            'disk' => env('VANGUARD_REMOTE_DISK', 's3'),
            'path' => env('VANGUARD_REMOTE_PATH', 'vanguard-backups'),
        ],
        'ftp' => [
            'enabled' => env('VANGUARD_FTP_ENABLED', false),
            'disk' => env('VANGUARD_FTP_DISK', 'ftp'),
            'path' => env('VANGUARD_FTP_PATH', 'vanguard-backups'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    | Global backup schedule. Per-tenant overrides can be stored in the
    | tenants table via the 'vanguard_schedule' column (optional).
    |
    | Supported frequencies: 'hourly', 'daily', 'weekly', 'monthly', 'custom'
    | For 'custom', set a valid cron expression in 'cron'.
    */
    'schedule' => [
        'enabled' => env('VANGUARD_SCHEDULE_ENABLED', true),
        'frequency' => env('VANGUARD_SCHEDULE_FREQUENCY', 'daily'),
        'cron' => env('VANGUARD_SCHEDULE_CRON', '0 2 * * *'),  // 2:00 AM daily
        'timezone' => env('APP_TIMEZONE', 'UTC'),
        'landlord' => true,   // Schedule landlord backup
        'tenants' => true,   // Schedule all tenant backups
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    | Automatically prune backups older than the configured number of days.
    */
    'retention' => [
        'enabled' => true,
        'days' => env('VANGUARD_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Notify when a backup fails or succeeds.
    | Add any notification channel supported by Laravel.
    */
    'notifications' => [
        'on_success' => env('VANGUARD_NOTIFY_SUCCESS', false),
        'on_failure' => env('VANGUARD_NOTIFY_FAILURE', true),
        'mail' => [
            'enabled' => true,
            'to' => env('VANGUARD_NOTIFY_MAIL', null),
        ],
        'slack' => [
            'enabled' => false,
            'webhook_url' => env('VANGUARD_SLACK_WEBHOOK', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    | Where Vanguard writes dump files during the backup process.
    | Must be writable by the web server user.
    */
    'tmp_path' => env('VANGUARD_TMP_PATH', storage_path('vanguard-tmp')),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Dispatch backup jobs to a queue instead of running synchronously.
    |
    | IMPORTANT — worker timeout: your queue worker (Horizon or artisan queue:work)
    | must have a timeout >= VANGUARD_QUEUE_TIMEOUT, otherwise the worker will kill
    | long-running backups before they complete.
    |
    | Horizon example (config/horizon.php):
    |   'vanguard' => [
    |       'connection' => env('VANGUARD_QUEUE_CONNECTION', 'redis'),
    |       'queue'      => [env('VANGUARD_QUEUE_NAME', 'vanguard')],
    |       'balance'    => 'auto',
    |       'processes'  => 2,
    |       'tries'      => 3,
    |       'timeout'    => env('VANGUARD_QUEUE_TIMEOUT', 3600),
    |   ],
    */
    'queue' => [
        'enabled' => env('VANGUARD_QUEUE_ENABLED', true),
        'connection' => env('VANGUARD_QUEUE_CONNECTION', null),
        'queue' => env('VANGUARD_QUEUE_NAME', 'vanguard'),
        'timeout' => env('VANGUARD_QUEUE_TIMEOUT', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Controls how many requests each authenticated user can make per minute
    | to the Vanguard API. Set a value to 0 to disable the limit entirely.
    |
    | 'run'     — POST /api/backups/run     (triggers a backup)
    | 'restore' — POST /api/backups/{id}/restore
    | 'api'     — all other API endpoints (stats, list, delete…)
    */
    'rate_limits' => [
        'run' => env('VANGUARD_RATE_LIMIT_RUN', 5),
        'restore' => env('VANGUARD_RATE_LIMIT_RESTORE', 3),
        'api' => env('VANGUARD_RATE_LIMIT_API', 60),
        // Its own bucket, deliberately. A named limiter is keyed by name and
        // user, not by route, so sharing 'run' meant five loads of the health
        // page locked out the backup trigger, the download — and the health
        // page itself, the one screen that reports breakage. Twelve is ample
        // for a probe that writes to every destination on each call.
        'health' => env('VANGUARD_RATE_LIMIT_HEALTH', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime Updates
    |--------------------------------------------------------------------------
    | Controls how the dashboard receives live updates.
    |
    | driver:
    |   'polling' — Interval-based fetch (default). Compatible with all server
    |               setups including php artisan serve and single-process servers.
    |   'sse'     — Server-Sent Events. One persistent connection, server pushes
    |               updates only when backup state changes. Zero overhead when
    |               nothing is happening. Requires a multi-process server
    |               (nginx + php-fpm, Octane, etc.) — incompatible with
    |               php artisan serve (single-process).
    |
    | interval:     Polling interval in seconds (only used when driver = 'polling')
    | sse_interval: How often the SSE endpoint checks the DB for changes (seconds)
    | max_lifetime: Max SSE connection lifetime before client auto-reconnects (seconds)
    */
    'realtime' => [
        'driver' => env('VANGUARD_REALTIME_DRIVER', 'polling'),
        'interval' => env('VANGUARD_POLL_INTERVAL', 5),
        'sse_interval' => env('VANGUARD_SSE_INTERVAL', 2),
        'max_lifetime' => env('VANGUARD_SSE_LIFETIME', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dump Options
    |--------------------------------------------------------------------------
    | Options passed to mysqldump on every MySQL/MariaDB dump.
    |
    | The defaults keep the dump consistent without locking the tables
    | (--single-transaction), stream rows instead of buffering them (--quick),
    | and include stored procedures and triggers (--routines --triggers).
    | --no-tablespaces avoids the PROCESS privilege requirement of MySQL 8.
    |
    | --events is intentionally left out: it requires the EVENT privilege,
    | which managed and restricted database accounts often lack, and the dump
    | would fail outright. Add it here if your account has that privilege.
    |
    | Accepts a space-separated string or an array of options.
    */
    'dump' => [
        'mysql_options' => env(
            'VANGUARD_MYSQL_DUMP_OPTIONS',
            '--single-transaction --quick --routines --triggers --no-tablespaces',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Binary Paths
    |--------------------------------------------------------------------------
    | Absolute paths to the CLI tools used during backup and restore.
    | Leave null to let Vanguard auto-detect from common system locations
    | (/usr/bin, /usr/local/bin, etc.).
    |
    | Set explicitly when the binary is not in the PATH of your web/queue
    | process (common in Docker, shared hosting, or custom server setups).
    |
    | Examples:
    |   VANGUARD_MYSQLDUMP_BINARY=/usr/bin/mysqldump
    |   VANGUARD_MYSQL_BINARY=/usr/bin/mysql
    |   VANGUARD_PG_DUMP_BINARY=/usr/bin/pg_dump
    |   VANGUARD_PSQL_BINARY=/usr/bin/psql
    */
    'binaries' => [
        'mysqldump' => env('VANGUARD_MYSQLDUMP_BINARY', null),
        'mysql' => env('VANGUARD_MYSQL_BINARY', null),
        'pg_dump' => env('VANGUARD_PG_DUMP_BINARY', null),
        'psql' => env('VANGUARD_PSQL_BINARY', null),
    ],

];
