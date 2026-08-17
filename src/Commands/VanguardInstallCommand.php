<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;

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
     * Warn about settings this version knows and the published config does not.
     *
     * vendor:publish never overwrites an existing config file, so upgrading the
     * package leaves a config from an older version in place. Every read has a
     * default, but a default of "empty" silently disables the feature — a
     * missing notifications.mail.to means no alert will ever be sent.
     */
    protected function checkPublishedConfigIsCurrent(): void
    {
        $publishedPath = config_path('vanguard.php');

        if (! file_exists($publishedPath)) {
            return;
        }

        $shipped = require __DIR__.'/../../config/vanguard.php';
        $published = require $publishedPath;

        $missing = array_diff_key(
            $this->flattenKeys($shipped),
            $this->flattenKeys($published),
        );

        if ($missing === []) {
            $this->line('   <info>Published config is up to date.</info>');

            return;
        }

        $this->newLine();
        $this->warn('   Your published config/vanguard.php predates this version and is missing:');

        foreach (array_keys($missing) as $key) {
            $this->line("     - {$key}");
        }

        $this->line('   <comment>Each falls back to a built-in default, but a setting you cannot see</comment>');
        $this->line('   <comment>is a setting you cannot rely on. Compare against</comment>');
        $this->line('   <comment>vendor/softartisan/laravel-vanguard/config/vanguard.php, or republish with</comment>');
        $this->line('   <comment>php artisan vendor:publish --tag=vanguard-config --force</comment> (overwrites yours).');
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
