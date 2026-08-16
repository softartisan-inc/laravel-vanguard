<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class MaintenanceEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');
    }

    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    // ─── Prune ───────────────────────────────────────────────────

    #[Test]
    public function it_prunes_backups_older_than_the_configured_retention(): void
    {
        config(['vanguard.retention.days' => 30]);

        $old = $this->makeRecord(['status' => 'completed', 'file_path' => null, 'created_at' => now()->subDays(60)]);
        $recent = $this->makeRecord(['status' => 'completed', 'file_path' => null, 'created_at' => now()->subDay()]);

        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups'])
            ->assertOk()
            ->assertJson(['deleted' => 1, 'days' => 30]);

        $this->assertNull(BackupRecord::find($old->id));
        $this->assertNotNull(BackupRecord::find($recent->id));
    }

    #[Test]
    public function it_honours_days_zero_rather_than_treating_it_as_absent(): void
    {
        // Parity with `vanguard:prune --days=0`, which means prune everything.
        // Read as a falsy value it used to be dropped and the configured
        // retention applied instead — the opposite of what was typed.
        config(['vanguard.retention.days' => 30]);

        $this->makeRecord(['status' => 'completed', 'file_path' => null, 'created_at' => now()->subMinute()]);

        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'days' => 0])
            ->assertOk()
            ->assertJson(['deleted' => 1, 'days' => 0]);
    }

    #[Test]
    public function it_prunes_only_the_named_tenant(): void
    {
        config(['vanguard.retention.days' => 30]);

        $mine = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001', 'status' => 'completed', 'file_path' => null, 'created_at' => now()->subDays(60)]);
        $theirs = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9002', 'status' => 'completed', 'file_path' => null, 'created_at' => now()->subDays(60)]);

        $this->postJson('/vanguard/api/prune', ['confirm' => '9001', 'tenant_id' => '9001'])
            ->assertOk()
            ->assertJson(['deleted' => 1]);

        $this->assertNull(BackupRecord::find($mine->id));
        $this->assertNotNull(BackupRecord::find($theirs->id));
    }

    #[Test]
    public function it_refuses_to_prune_without_the_typed_confirmation(): void
    {
        config(['vanguard.retention.days' => 30]);

        $old = $this->makeRecord(['status' => 'completed', 'file_path' => null, 'created_at' => now()->subDays(60)]);

        $this->postJson('/vanguard/api/prune')->assertStatus(400);
        $this->postJson('/vanguard/api/prune', ['confirm' => 'yes'])->assertStatus(400);

        $this->assertNotNull(BackupRecord::find($old->id), 'nothing may be deleted by an unconfirmed call');
    }

    #[Test]
    public function it_requires_the_confirmation_to_name_the_tenant_being_pruned(): void
    {
        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'tenant_id' => '9001'])
            ->assertStatus(400)
            ->assertJson(['expected' => '9001']);
    }

    #[Test]
    public function it_rejects_a_days_value_that_is_not_a_whole_number(): void
    {
        // (int) 'abc' is 0, and 0 means prune everything: the CLI guards this
        // with ctype_digit, the endpoint with a shape rule.
        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'days' => 'abc'])
            ->assertStatus(422);

        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'days' => -1])
            ->assertStatus(422);
    }

    #[Test]
    public function it_records_who_pruned_what(): void
    {
        $logger = Log::spy();

        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'days' => 7])->assertOk();

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $message === '[Vanguard] prune requested'
                && $context['actor'] === 'ops@in-immo.app'
                && $context['target'] === 'all-backups'
                && $context['days'] === 7,
        );
    }

    // ─── Tmp cleanup ─────────────────────────────────────────────

    #[Test]
    public function it_removes_orphaned_tmp_directories_older_than_the_cutoff(): void
    {
        $base = config('vanguard.tmp_path');
        @mkdir($base, 0700, true);

        $stale = $base.'/vanguard_stale';
        $fresh = $base.'/vanguard_fresh';
        $foreign = $base.'/not_ours';

        foreach ([$stale, $fresh, $foreign] as $dir) {
            @mkdir($dir, 0700, true);
        }

        touch($stale, time() - (10 * 3600));
        touch($foreign, time() - (10 * 3600));

        $this->postJson('/vanguard/api/cleanup-tmp', ['confirm' => 'tmp', 'hours' => 6])
            ->assertOk()
            ->assertJson(['removed' => 1, 'hours' => 6]);

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($foreign, 'only vanguard_* directories are ours to delete');
    }

    #[Test]
    public function it_refuses_the_cleanup_without_the_typed_confirmation(): void
    {
        $this->postJson('/vanguard/api/cleanup-tmp')->assertStatus(400)
            ->assertJson(['expected' => 'tmp']);
    }

    #[Test]
    public function it_records_who_cleaned_the_tmp_directories(): void
    {
        $logger = Log::spy();

        $this->postJson('/vanguard/api/cleanup-tmp', ['confirm' => 'tmp'])->assertOk();

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $message === '[Vanguard] tmp cleanup requested'
                && $context['actor'] === 'ops@in-immo.app'
                && $context['hours'] === 6,
        );
    }
}
