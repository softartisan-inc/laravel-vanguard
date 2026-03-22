<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;

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
     * @return int  Command::SUCCESS
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

        $this->newLine();
        $this->info('✅ Vanguard installed successfully!');
        $this->newLine();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Print actionable next steps after installation.
     */
    protected function printNextSteps(): void
    {
        $timeout = (int) config('vanguard.queue.timeout', 3600);
        $queue   = config('vanguard.queue.queue', 'vanguard');
        $conn    = config('vanguard.queue.connection') ?? 'redis';

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
        $this->line("     <comment>],</comment>");
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
        $this->line("     <comment>],</comment>");
        $this->newLine();

        $this->line('  <info>5. Environment variables</info> — add to <comment>.env</comment> as needed:');
        $this->line('     <comment>VANGUARD_QUEUE_CONNECTION=redis</comment>');
        $this->line('     <comment>VANGUARD_QUEUE_NAME=vanguard</comment>');
        $this->line('     <comment>VANGUARD_QUEUE_TIMEOUT=3600</comment>');
        $this->line('     <comment>VANGUARD_RETENTION_DAYS=30</comment>');
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
        $this->line('  📖 Documentation: https://github.com/softartisan/vanguard');
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
            'ftp'    => 'vanguard.destinations.ftp',
        ];

        foreach ($destinations as $label => $key) {
            if (! config("{$key}.enabled", false)) {
                continue;
            }

            $disk = config("{$key}.disk");

            if (empty(config("filesystems.disks.{$disk}"))) {
                $this->newLine();
                $this->warn("⚠  The {$label} destination is enabled but disk [{$disk}] is not declared in config/filesystems.php.");
                $this->warn("   Add the disk configuration before running backups (see step 4 of next steps).");
            }
        }
    }

    /**
     * Check for required system tools and warn about any that are missing.
     *
     * Verifies that tar, gzip, mysqldump, and pg_dump are available on the
     * system PATH. Missing tools do not abort the installation but will cause
     * backup operations to fail at runtime.
     */
    protected function checkSystemRequirements(): void
    {
        $this->line('Checking system requirements...');

        $tools = [
            'tar'      => 'Required for bundling backup archives.',
            'gzip'     => 'Required for compressing backup files.',
            'mysqldump' => 'Required for MySQL database backups.',
            'pg_dump'  => 'Required for PostgreSQL database backups.',
        ];

        $missing = [];

        foreach ($tools as $tool => $reason) {
            exec('which '.escapeshellarg($tool).' 2>/dev/null', $output, $code);
            $output = [];

            if ($code !== 0) {
                $missing[] = [$tool, $reason];
            }
        }

        if (empty($missing)) {
            $this->line('   <info>All system tools found.</info>');
        } else {
            foreach ($missing as [$tool, $reason]) {
                $this->warn("   [missing] {$tool} — {$reason}");
            }
            $this->newLine();
            $this->warn('Some system tools are not installed. Install them before running backups.');
        }

        $this->newLine();
    }
}
