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
    |
    | 'database_mode' (runtime) — how tenants map to databases:
    |   'multi'  (default) one database per tenant: every tenant backup dumps
    |            the tenant's own database (and its files when asked).
    |   'single' one database shared by every tenant, e.g. isolated by
    |            PostgreSQL Row-Level Security. The shared database is dumped
    |            once, by the landlord backup (see 'database' below for a
    |            dedicated connection), and a tenant backup holds the tenant's
    |            files only — storage_path() as stancl/tenancy's
    |            FilesystemTenancyBootstrapper sets it for that tenant. No
    |            tenant database connection is ever read. Restoring a tenant
    |            backup restores its files; restoring one tenant's rows out of
    |            the shared database is not covered.
    | Any other value is reported at boot and refused by tenant backups.
    */
    'tenancy' => [
        'enabled' => env('VANGUARD_TENANCY_ENABLED', true),
        'tenant_model' => env('VANGUARD_TENANT_MODEL', Tenant::class),
        'database_mode' => env('VANGUARD_TENANCY_DATABASE_MODE', 'multi'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dedicated Backup / Restore Connections
    |--------------------------------------------------------------------------
    | Names of connections declared in config/database.php. Both are read at
    | runtime. Leave them null to keep the default behaviour: the landlord dump
    | runs through the central connection (tenancy.database.central_connection,
    | else database.default) and the landlord restore writes through
    | database.default.
    |
    | Set them when the application's own database role cannot see — or
    | write — every row. On PostgreSQL with Row-Level Security, a dump taken
    | as the application role holds only what that role is allowed to see:
    |
    |   'backup_connection'  — a role with BYPASSRLS, ideally read-only
    |                          (ALTER ROLE ... SET default_transaction_read_only = on).
    |   'restore_connection' — the owner of the tables, with BYPASSRLS: under
    |                          FORCE ROW LEVEL SECURITY the owner is filtered too.
    |
    | A named connection that is not declared, or whose driver differs from
    | the connection it replaces, fails the backup/restore loudly — there is
    | no silent fallback to the application connection.
    |
    | Only the landlord (global) dump and restore move. In a multi-database
    | tenancy setup, per-tenant dumps keep using the tenant connection.
    */
    'database' => [
        'backup_connection' => env('VANGUARD_BACKUP_CONNECTION', null),
        'restore_connection' => env('VANGUARD_RESTORE_CONNECTION', null),
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
    |
    | 'pgsql_version_check' (opt-in, read at runtime) — pg_dump cannot dump a
    | server of a newer major version (a pg_dump 16 against a PostgreSQL 18
    | server fails), and it only says so once the dump has started. With
    | this check, the major version of the resolved pg_dump binary is
    | compared with the server's (SHOW server_version_num, on the connection
    | the dump uses) before anything is dumped:
    |
    |   'off'    (default) no check: no extra process, no extra connection —
    |            the behaviour of every version before this setting existed.
    |   'warn'   a mismatch is logged and the dump runs anyway.
    |   'strict' a mismatch refuses the backup before the dump starts, with a
    |            message naming both versions and VANGUARD_PG_DUMP_BINARY.
    |
    | A version that cannot be read (binary missing, server unreachable) is
    | logged and never blocks: unknown is not a proven mismatch. Point
    | 'binaries.pg_dump' below at the right client, e.g.
    | /usr/lib/postgresql/18/bin/pg_dump on Debian/Ubuntu.
    */
    'dump' => [
        'mysql_options' => env(
            'VANGUARD_MYSQL_DUMP_OPTIONS',
            '--single-transaction --quick --routines --triggers --no-tablespaces',
        ),
        'pgsql_version_check' => env('VANGUARD_PG_VERSION_CHECK', 'off'),
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
    | A configured path is always used as is, even when nothing is there
    | (the health screen then reports it missing). Auto-detection does not
    | look into /usr/lib/postgresql/<major>/bin: when several PostgreSQL
    | clients are installed, pin the one matching your server here.
    |
    | Examples:
    |   VANGUARD_MYSQLDUMP_BINARY=/usr/bin/mysqldump
    |   VANGUARD_MYSQL_BINARY=/usr/bin/mysql
    |   VANGUARD_PG_DUMP_BINARY=/usr/lib/postgresql/18/bin/pg_dump
    |   VANGUARD_PSQL_BINARY=/usr/lib/postgresql/18/bin/psql
    |   VANGUARD_AGE_BINARY=/usr/bin/age
    */
    'binaries' => [
        'mysqldump' => env('VANGUARD_MYSQLDUMP_BINARY', null),
        'mysql' => env('VANGUARD_MYSQL_BINARY', null),
        'pg_dump' => env('VANGUARD_PG_DUMP_BINARY', null),
        'psql' => env('VANGUARD_PSQL_BINARY', null),
        // Only used when 'encryption' below is enabled.
        'age' => env('VANGUARD_AGE_BINARY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive Encryption (opt-in)
    |--------------------------------------------------------------------------
    | Every key is read at runtime. Disabled by default: archives are plain
    | .tar bundles, exactly as before this section existed.
    |
    | Enabled, each archive is encrypted with age (https://age-encryption.org)
    | to one or more PUBLIC keys before it leaves the machine, and stored as
    | {name}.tar.age on every destination. The private key is never part of
    | this configuration: generate it off the server (age-keygen on an admin
    | workstation), keep it there, and put only the public key here. It is
    | handed to `php artisan vanguard:restore {id} --identity=<file>` (or
    | --identity-stdin) at restore time and never stored by Vanguard.
    |
    | 'enabled'         — true to encrypt every new archive.
    | 'driver'          — 'age', the only supported value.
    | 'recipients'      — age public keys (age1...) or SSH public keys
    |                     (ssh-ed25519 ..., ssh-rsa ...), separated by commas
    |                     or new lines; an array is accepted too. Several
    |                     recipients = several keys able to decrypt (key
    |                     rotation, a second custodian).
    | 'recipients_file' — path to a file of recipients, one per line (age -R).
    |
    | When enabled, a backup with no usable recipient, or without the age
    | binary (see 'binaries.age'), fails before anything is dumped. It never
    | falls back to a plaintext archive. Encrypted backups cannot be restored
    | from the dashboard or the HTTP API: a private key must not travel over
    | HTTP or through the queue.
    */
    'encryption' => [
        'enabled' => env('VANGUARD_ENCRYPTION_ENABLED', false),
        'driver' => 'age',
        'recipients' => env('VANGUARD_ENCRYPTION_RECIPIENTS', ''),
        'recipients_file' => env('VANGUARD_ENCRYPTION_RECIPIENTS_FILE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare R2 (opt-in)
    |--------------------------------------------------------------------------
    | Every key is read at runtime. R2 is S3-compatible, so it is used through
    | the existing 'remote' destination; this section only declares the disk.
    |
    | When 'bucket' and either 'account_id' or 'endpoint' are set, Vanguard
    | declares an S3 disk named 'disk' (default 'vanguard-r2'): region auto,
    | path-style endpoint https://<account_id>.r2.cloudflarestorage.com, no
    | ACL/visibility, checksums only when required. A disk of that name already
    | declared in config/filesystems.php is never overwritten, and no other
    | disk (the 's3' one included) is ever touched. Left empty — the default —
    | nothing is declared.
    |
    | Requires league/flysystem-aws-s3-v3. Then point the remote destination at it:
    |   VANGUARD_REMOTE_ENABLED=true
    |   VANGUARD_REMOTE_DISK=vanguard-r2
    |
    | 'jurisdiction' — null (default), 'eu' or 'fedramp': the bucket's data
    |                  location jurisdiction, which has its own endpoint
    |                  (https://<account_id>.eu.r2.cloudflarestorage.com).
    | 'endpoint'     — explicit endpoint, overriding the one built from the
    |                  account id (e.g. a local S3-compatible server in tests).
    |
    | Prefer declaring the disk yourself? Declare it in config/filesystems.php
    | under the same name (it then wins), or build it from these settings in a
    | service provider with SoftArtisan\Vanguard\Vanguard::r2DiskConfig().
    */
    'r2' => [
        'disk' => env('VANGUARD_R2_DISK', 'vanguard-r2'),
        'account_id' => env('VANGUARD_R2_ACCOUNT_ID'),
        'access_key_id' => env('VANGUARD_R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('VANGUARD_R2_SECRET_ACCESS_KEY'),
        'bucket' => env('VANGUARD_R2_BUCKET'),
        'jurisdiction' => env('VANGUARD_R2_JURISDICTION', null),
        'endpoint' => env('VANGUARD_R2_ENDPOINT', null),
    ],

];
