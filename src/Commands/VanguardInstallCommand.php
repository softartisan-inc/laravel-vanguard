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

        $this->newLine();
        $this->info('✅ Vanguard installed successfully!');
        $this->newLine();
        $this->line('📋 Next steps:');
        $this->line('   1. Review <comment>config/vanguard.php</comment> and set your backup destinations.');
        $this->line('   2. Add your auth gate in <comment>AppServiceProvider::boot()</comment>:');
        $this->line('      <comment>Vanguard::auth(fn ($request) => $request->user()?->isAdmin());</comment>');
        $this->line('   3. Visit <comment>'.url(config('vanguard.path', 'vanguard')).'</comment> to access the dashboard.');
        $this->newLine();
        $this->line('⚡ <comment>Production tip:</comment> publish assets so nginx/Apache serves them directly');
        $this->line('   (avoids any PHP overhead on asset requests):');
        $this->line('   <comment>php artisan vendor:publish --tag=vanguard-assets</comment>');
        $this->newLine();
        $this->line('📖 Documentation: https://github.com/softartisan/vanguard');

        return self::SUCCESS;
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
