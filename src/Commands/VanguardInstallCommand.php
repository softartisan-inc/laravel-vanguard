<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Vanguard;

class VanguardInstallCommand extends Command
{
    protected $signature = 'vanguard:install';

    protected $description = 'Install Vanguard — publish config and run migrations';

    /**
     * Execute the console command.
     *
     * Verifies system requirements, publishes the config and migration stubs,
     * runs migrations, then prints actionable next steps.
     *
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $this->info('🛡  Installing Vanguard Backup Manager...');
        $this->newLine();

        $this->checkSystemRequirements();

        $this->call('vendor:publish', ['--tag' => 'vanguard-config', '--force' => false]);
        $this->call('vendor:publish', ['--tag' => 'vanguard-migrations', '--force' => false]);
        $this->call('migrate', ['--force' => $this->option('no-interaction')]);

        $this->checkDestinationDisks();
        $this->checkPublishedConfigIsCurrent();

        $this->newLine();
        $this->info('✅ Vanguard installed successfully!');
        $this->newLine();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Opt-in settings whose built-in default keeps the behaviour of the
     * versions that did not have them: an older published config lacking them
     * runs exactly as before. An entry ending with a dot covers a whole section.
     *
     * Every other key keeps being reported as missing — its default may not be
     * what the installation expects (a missing notifications.mail.to means no
     * alert is ever sent).
     *
     * @var list<string>
     */
    protected const OPTIONAL_KEYS = [
        'sources.on_empty_filesystem',
        'tenancy.database_mode',
        'database.',
        'dump.pgsql_version_check',
        'binaries.age',
        'encryption.',
        'r2.',
    ];

    /**
     * Report settings this version knows and the published config does not.
     *
     * vendor:publish never overwrites an existing config file, so upgrading the
     * package leaves a config from an older version in place. Every read has a
     * default, but a default of "empty" can silently disable a feature, so
     * those keys are listed as missing. Opt-in settings whose default keeps the
     * previous behaviour (OPTIONAL_KEYS) are listed apart, as nothing to do.
     */
    protected function checkPublishedConfigIsCurrent(): void
    {
        $publishedPath = config_path('vanguard.php');

        if (! file_exists($publishedPath)) {
            return;
        }

        $shipped = require __DIR__.'/../../config/vanguard.php';
        $published = require $publishedPath;

        $absent = array_keys(array_diff_key(
            $this->flattenKeys($shipped),
            $this->flattenKeys($published),
        ));

        if ($absent === []) {
            $this->line('   <info>Published config is up to date.</info>');

            return;
        }

        $optional = array_values(array_filter($absent, fn (string $key): bool => $this->isOptionalKey($key)));
        $missing = array_values(array_diff($absent, $optional));

        $this->newLine();
        $this->line('   <comment>Your published config/vanguard.php predates this version.</comment>');

        if ($missing !== []) {
            $this->warn('   Missing settings (the built-in default may not be what you expect):');

            foreach ($missing as $key) {
                $this->line("     - {$key}");
            }

            $this->line('   <comment>Each falls back to a built-in default, but a setting you cannot see</comment>');
            $this->line('   <comment>is a setting you cannot rely on. Compare against</comment>');
            $this->line('   <comment>vendor/softartisan/laravel-vanguard/config/vanguard.php, or republish with</comment>');
            $this->line('   <comment>php artisan vendor:publish --tag=vanguard-config --force</comment> (overwrites yours).');
        }

        if ($optional !== []) {
            $this->line('   <info>New optional settings (disabled by default, nothing to do):</info>');

            foreach ($optional as $key) {
                // Laravel merges the package config one level deep only: a key
                // nested in a section the published config already has is not
                // merged, so its environment variable alone is never read.
                $note = array_key_exists(strtok($key, '.'), $published)
                    ? ' <comment>(to opt in, copy this key into your config first)</comment>'
                    : '';

                $this->line("     - {$key}{$note}");
            }

            $this->line('   Without them, Vanguard behaves exactly as before. Documented in');
            $this->line('   vendor/softartisan/laravel-vanguard/config/vanguard.php.');
        }
    }

    /**
     * Whether an absent key is an opt-in setting whose default changes nothing.
     */
    protected function isOptionalKey(string $key): bool
    {
        foreach (self::OPTIONAL_KEYS as $optional) {
            if ($key === $optional || (str_ends_with($optional, '.') && str_starts_with($key, $optional))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten a config array to dotted keys, treating lists as leaves.
     *
     * @return array<string, true>
     */
    protected function flattenKeys(array $config, string $prefix = ''): array
    {
        $keys = [];

        foreach ($config as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $keys += $this->flattenKeys($value, $dotted);

                continue;
            }

            $keys[$dotted] = true;
        }

        return $keys;
    }

    /**
     * Print actionable next steps after installation.
     */
    protected function printNextSteps(): void
    {
        $timeout = (int) config('vanguard.queue.timeout', 3600);
        $queue = config('vanguard.queue.queue', 'vanguard');
        $conn = config('vanguard.queue.connection') ?? 'redis';

        $this->line('📋 <comment>Next steps:</comment>');
        $this->newLine();

        $this->line('  <info>1. Auth gate</info> — restrict dashboard access in <comment>AppServiceProvider::boot()</comment>:');
        $this->line('     <comment>Vanguard::auth(fn ($request) => $request->user()?->isAdmin());</comment>');
        $this->newLine();

        $this->line('  <info>2. Scheduler</info> — required for automatic backups, pruning, and tmp cleanup.');
        $this->line('     Add to your server crontab:');
        $this->line('     <comment>* * * * * php '.base_path('artisan').' schedule:run >> /dev/null 2>&1</comment>');
        $this->newLine();

        $this->line('  <info>3. Queue worker / Horizon</info> — worker timeout must be ≥ VANGUARD_QUEUE_TIMEOUT.');
        $this->line("     Add the <comment>{$queue}</comment> supervisor to <comment>config/horizon.php</comment>:");
        $this->line("     <comment>'{$queue}' => [</comment>");
        $this->line("     <comment>    'connection' => '{$conn}',</comment>");
        $this->line("     <comment>    'queue'      => ['{$queue}'],</comment>");
        $this->line("     <comment>    'balance'    => 'auto',</comment>");
        $this->line("     <comment>    'processes'  => 2,</comment>");
        $this->line("     <comment>    'tries'      => 3,</comment>");
        $this->line("     <comment>    'timeout'    => {$timeout},</comment>");
        $this->line('     <comment>],</comment>');
        $this->newLine();

        $this->line('  <info>4. FTP/SFTP destination</info> — if you plan to use VANGUARD_FTP_ENABLED=true:');
        $this->line('     Install the adapter:');
        $this->line('       FTP:  <comment>composer require league/flysystem-ftp</comment>');
        $this->line('       SFTP: <comment>composer require league/flysystem-sftp-v3</comment>');
        $this->line('     Declare the disk in <comment>config/filesystems.php</comment>:');
        $this->line("     <comment>'ftp' => [</comment>");
        $this->line("     <comment>    'driver'   => 'ftp',  // or 'sftp'</comment>");
        $this->line("     <comment>    'host'     => env('FTP_HOST'),</comment>");
        $this->line("     <comment>    'username' => env('FTP_USERNAME'),</comment>");
        $this->line("     <comment>    'password' => env('FTP_PASSWORD'),</comment>");
        $this->line("     <comment>    'port'     => 21,</comment>");
        $this->line('     <comment>],</comment>');
        $this->newLine();

        $this->line('  <info>4b. Cloudflare R2</info> — S3-compatible, used as the remote destination:');
        $this->line('     <comment>composer require league/flysystem-aws-s3-v3</comment>');
        $this->line('     Vanguard declares the disk itself once these are set (no filesystems.php entry):');
        $this->line('     <comment>VANGUARD_R2_ACCOUNT_ID=</comment>');
        $this->line('     <comment>VANGUARD_R2_ACCESS_KEY_ID=</comment>');
        $this->line('     <comment>VANGUARD_R2_SECRET_ACCESS_KEY=</comment>');
        $this->line('     <comment>VANGUARD_R2_BUCKET=</comment>');
        $this->line('     <comment># VANGUARD_R2_JURISDICTION=eu   # only for an EU-jurisdiction bucket</comment>');
        $this->line('     <comment>VANGUARD_REMOTE_ENABLED=true</comment>');
        $this->line('     <comment>VANGUARD_REMOTE_DISK=vanguard-r2</comment>');
        $this->newLine();

        $this->line('  <info>5. Environment variables</info> — add to <comment>.env</comment> as needed:');
        $this->line('     <comment>VANGUARD_QUEUE_CONNECTION=redis</comment>');
        $this->line('     <comment>VANGUARD_QUEUE_NAME=vanguard</comment>');
        $this->line('     <comment>VANGUARD_QUEUE_TIMEOUT=3600</comment>');
        $this->line('     <comment>VANGUARD_RETENTION_DAYS=30</comment>');
        $this->line('     <comment># Keep a copy on the server itself (default true):</comment>');
        $this->line('     <comment>VANGUARD_LOCAL_ENABLED=true</comment>');
        $this->line('     <comment># An archive that captured no file: warn (default) or fail the backup:</comment>');
        $this->line('     <comment>VANGUARD_ON_EMPTY_FILESYSTEM=warn</comment>');
        $this->line('     <comment># Alerts — without an address a failing backup stays silent:</comment>');
        $this->line('     <comment>VANGUARD_NOTIFY_FAILURE=true</comment>');
        $this->line('     <comment>VANGUARD_NOTIFY_MAIL=ops@example.com</comment>');
        $this->line('     <comment># Dedicated dump/restore connections (names from config/database.php),</comment>');
        $this->line('     <comment># e.g. for PostgreSQL Row-Level Security — unset = application connection:</comment>');
        $this->line('     <comment>VANGUARD_BACKUP_CONNECTION=</comment>');
        $this->line('     <comment>VANGUARD_RESTORE_CONNECTION=</comment>');
        $this->line('     <comment># One database shared by all tenants (multi = one per tenant, default):</comment>');
        $this->line('     <comment>VANGUARD_TENANCY_DATABASE_MODE=multi</comment>');
        $this->line('     <comment># Encrypt archives with age to public keys (generate the key pair OFF the server):</comment>');
        $this->line('     <comment>VANGUARD_ENCRYPTION_ENABLED=false</comment>');
        $this->line('     <comment>VANGUARD_ENCRYPTION_RECIPIENTS=age1...</comment>');
        $this->line('     <comment>VANGUARD_ENCRYPTION_RECIPIENTS_FILE=</comment>');
        $this->line('     <comment>VANGUARD_AGE_BINARY=</comment>');
        $this->line('     <comment># PostgreSQL: compare pg_dump and server majors before each dump (off|warn|strict):</comment>');
        $this->line('     <comment>VANGUARD_PG_VERSION_CHECK=off</comment>');
        $this->line('     <comment>VANGUARD_PG_DUMP_BINARY=</comment>');
        $this->line('     # Remote (S3):');
        $this->line('     <comment>VANGUARD_REMOTE_ENABLED=false</comment>');
        $this->line('     <comment>VANGUARD_REMOTE_DISK=s3</comment>');
        $this->line('     <comment>VANGUARD_REMOTE_PATH=vanguard-backups</comment>');
        $this->line('     # FTP/SFTP:');
        $this->line('     <comment>VANGUARD_FTP_ENABLED=false</comment>');
        $this->line('     <comment>VANGUARD_FTP_DISK=ftp</comment>');
        $this->line('     <comment>VANGUARD_FTP_PATH=vanguard-backups</comment>');
        $this->newLine();

        $this->line('  <info>6. Assets</info> — publish so nginx/Apache serves them directly:');
        $this->line('     <comment>php artisan vendor:publish --tag=vanguard-assets</comment>');
        $this->newLine();

        $this->line('  Visit <comment>'.url(config('vanguard.path', 'vanguard')).'</comment> to access the dashboard.');
        $this->line('  📖 Documentation: https://github.com/softartisan-inc/laravel-vanguard');
        $this->newLine();
    }

    /**
     * Verify that any enabled destination references a declared Flysystem disk.
     *
     * Runs after publishing config so we read the app's actual filesystems config.
     * Prints a warning (not an error) so installation is not blocked — the user
     * may configure the disk immediately after.
     */
    protected function checkDestinationDisks(): void
    {
        $destinations = [
            'remote' => 'vanguard.destinations.remote',
            'ftp' => 'vanguard.destinations.ftp',
        ];

        foreach ($destinations as $label => $key) {
            if (! config("{$key}.enabled", false)) {
                continue;
            }

            $disk = config("{$key}.disk");

            if (empty(config("filesystems.disks.{$disk}"))) {
                $this->newLine();
                $this->warn("⚠  The {$label} destination is enabled but disk [{$disk}] is not declared in config/filesystems.php.");
                $this->warn('   Add the disk configuration before running backups (see step 4 of next steps).');

                continue;
            }

            // A disk declared from vanguard.r2 counts as declared; it still
            // needs the S3 adapter.
            if (is_string($disk) && ($problem = Vanguard::r2DiskProblem($disk)) !== null) {
                $this->newLine();
                $this->warn("⚠  {$problem}");
            }
        }
    }

    /**
     * Check for required system tools and warn about any that are missing.
     *
     * Both clients of each database are checked, not only the dump one: an
     * installation that can write an archive and not put it back is the
     * August 2026 incident, and it used to pass this check in silence because
     * only mysqldump and pg_dump were looked for.
     *
     * The lookup is the driver's own (DatabaseDriver::clientStatus), so this
     * command, the health screen and the backup itself cannot disagree about
     * whether a binary is there — including when an operator has pinned one in
     * vanguard.binaries.
     *
     * Missing tools do not abort the installation but will change, or stop,
     * what backups and restores can do at runtime.
     */
    protected function checkSystemRequirements(): void
    {
        $this->line('Checking system requirements...');

        $tools = [
            'tar' => 'Required for bundling backup archives.',
            'gzip' => 'Required for compressing backup files.',
            'mysqldump' => 'MySQL/MariaDB backups — without it they run through PHP/PDO instead, which is slower.',
            'mysql' => 'MySQL/MariaDB restores — without it they run through PHP/PDO instead, which is slower.',
            'pg_dump' => 'PostgreSQL backups — there is NO PHP fallback: without it they are impossible.',
            'psql' => 'PostgreSQL restores — there is NO PHP fallback: without it they are impossible.',
        ];

        $driver = app(DatabaseDriver::class);
        $missing = [];

        foreach ($tools as $tool => $reason) {
            $status = $driver->clientStatus($tool);

            if (! $status['present']) {
                $missing[] = [$tool, $reason, $status['path']];
            }
        }

        if (empty($missing)) {
            $this->line('   <info>All system tools found.</info>');
        } else {
            foreach ($missing as [$tool, $reason, $path]) {
                $this->warn("   [missing] {$tool} (looked for [{$path}]) — {$reason}");
            }
            $this->newLine();
            $this->warn('Some system tools are not installed. Install them before running backups.');
            $this->warn('The dashboard health screen reports the same thing, per driver, at any time.');
        }

        $this->newLine();
    }
}
