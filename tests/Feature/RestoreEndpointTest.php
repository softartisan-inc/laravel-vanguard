<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class RestoreEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_queues_the_restore_and_answers_202_with_its_id(): void
    {
        $backup = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);

        $response = $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => '9001',
            'source' => 'remote',
        ]);

        $response->assertStatus(202)
            ->assertJson(['status' => 'pending'])
            ->assertJsonStructure(['restore_id', 'status']);

        $restore = RestoreRecord::findOrFail($response->json('restore_id'));

        $this->assertSame('pending', $restore->status);
        $this->assertSame('tenant', $restore->type);
        $this->assertSame('9001', $restore->tenant_id);
        $this->assertSame('remote', $restore->source);
        $this->assertSame('ops@in-immo.app', $restore->requested_by);
        $this->assertTrue($restore->restore_db);
        $this->assertFalse($restore->restore_storage);
        $this->assertTrue($restore->verify_checksum);
        $this->assertSame(
            $backup->created_at->timestamp,
            $restore->backup_created_at->timestamp,
            'the archive date is copied so the history outlives the backup',
        );

        Queue::assertPushed(RunRestoreJob::class, fn ($job) => $job->restoreId === $restore->id);
    }

    #[Test]
    public function it_honours_every_option_the_cli_offers(): void
    {
        $backup = $this->makeRecord(['type' => 'landlord']);

        $id = $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
            'source' => 'ftp',
            'restore_db' => false,        // --no-db
            'restore_storage' => true,    // --restore-storage
            'verify_checksum' => false,   // --no-verify
        ])->assertStatus(202)->json('restore_id');

        $restore = RestoreRecord::findOrFail($id);

        $this->assertSame('ftp', $restore->source);
        $this->assertFalse($restore->restore_db);
        $this->assertTrue($restore->restore_storage);
        $this->assertFalse($restore->verify_checksum);
    }

    #[Test]
    public function it_restores_nothing_during_the_request_itself(): void
    {
        // The implementation this replaces called RestoreService::restore()
        // inline: a seven-minute operation inside an HTTP request, whose
        // answer any proxy timeout threw away while the server carried on.
        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');
        $this->app->instance(RestoreService::class, $service);

        $backup = $this->makeRecord(['type' => 'landlord']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", ['confirm' => 'landlord'])
            ->assertStatus(202);
    }

    #[Test]
    public function it_rejects_a_confirmation_that_does_not_name_the_target(): void
    {
        $backup = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", ['confirm' => 'landlord'])
            ->assertStatus(400)
            ->assertJson(['expected' => '9001']);

        $this->assertSame(0, RestoreRecord::count(), 'a refused call leaves no history row behind');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rejects_a_call_with_no_confirmation_at_all(): void
    {
        $backup = $this->makeRecord(['type' => 'landlord']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore")->assertStatus(400);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rejects_wipe_storage_on_presence_alone(): void
    {
        // Replace mode stays a console decision (spec §2): it destroys what
        // the backup does NOT contain. Even wipe_storage=false is refused,
        // so a caller is told the parameter is meaningless here rather than
        // being obeyed for the wrong reason.
        $backup = $this->makeRecord(['type' => 'landlord']);

        foreach ([true, false] as $value) {
            $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
                'confirm' => 'landlord',
                'wipe_storage' => $value,
            ])->assertStatus(400);
        }

        $this->assertSame(0, RestoreRecord::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_answers_404_for_a_backup_that_does_not_exist(): void
    {
        $this->postJson('/vanguard/api/backups/4242/restore', ['confirm' => 'landlord'])
            ->assertStatus(404);
    }

    #[Test]
    public function it_refuses_a_backup_that_is_not_restorable(): void
    {
        // 400, not 409: one rejection code for every business refusal beats a
        // 400 and a 409 the client has to tell apart (spec §5).
        foreach (['failed', 'running', 'pending'] as $status) {
            $backup = $this->makeRecord(['type' => 'landlord', 'status' => $status]);

            $this->postJson("/vanguard/api/backups/{$backup->id}/restore", ['confirm' => 'landlord'])
                ->assertStatus(400);
        }

        $this->assertSame(0, RestoreRecord::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rejects_an_unknown_source_as_a_shape_error(): void
    {
        $backup = $this->makeRecord(['type' => 'landlord']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
            'source' => 'dropbox',
        ])->assertStatus(422);
    }

    #[Test]
    public function it_records_the_operator_and_the_target(): void
    {
        $logger = Log::spy();

        $backup = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", ['confirm' => '9001'])
            ->assertStatus(202);

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $message === '[Vanguard] restore requested'
                && $context['actor'] === 'ops@in-immo.app'
                && $context['target'] === '9001'
                && $context['backup_id'] === $backup->id,
        );
    }
}
