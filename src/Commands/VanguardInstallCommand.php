<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;

class VanguardInstallCommand extends Command
{
    protected $signature = 'vanguard:install';
    protected $description = 'Install Vanguard — publish config and run migrations';

    public function handle(): int
    {
        $this->info('🛡  Installing Vanguard Backup Manager...');
        $this->newLine();

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
        $this->line('📖 Documentation: https://github.com/softartisan/vanguard');

        return self::SUCCESS;
    }
}
