<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

class RunRestoreJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_takes_a_restore_from_pending_to_completed(): void
    {
        Event::fake([RestoreStarted::class, RestoreCompleted::class, RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnTrue();
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame('completed', $restore->fresh()->status);
        $this->assertNotNull($restore->fresh()->completed_at);

        Event::assertDispatched(RestoreStarted::class);
        Event::assertDispatched(RestoreCompleted::class);
        Event::assertNotDispatched(RestoreFailed::class);
    }

    #[Test]
    public function it_writes_the_exact_error_of_a_failed_restore(): void
    {
        // The HTTP layer answers "check server logs"; the row must not.
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andThrow(new RuntimeException('Checksum mismatch for backup #7.'));
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $restore = $restore->fresh();

        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('Checksum mismatch', $restore->error);

        Event::assertDispatched(RestoreFailed::class);
    }

    #[Test]
    public function it_records_the_phases_the_service_announces(): void
    {
        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $seenInDatabase = [];

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andReturnUsing(function ($record, array $options) use ($restore, &$seenInDatabase) {
                foreach (['downloading', 'verifying', 'restoring database'] as $phase) {
                    $options['on_phase']($phase);
                    // Read it back: the point is that the phase is persisted while
                    // the restore runs, which is what the dashboard will poll.
                    $seenInDatabase[] = $restore->fresh()->phase;
                }

                return true;
            });
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame(['downloading', 'verifying', 'restoring database'], $seenInDatabase);
        $this->assertSame('completed', $restore->fresh()->status);
        $this->assertNull($restore->fresh()->phase, 'a finished restore is in no phase');
    }

    #[Test]
    public function it_forwards_the_options_recorded_on_the_row(): void
    {
        $backup = $this->makeRecord();
        $restore = $this->makeRestore([
            'backup_id' => $backup->id,
            'status' => 'pending',
            'source' => 'remote',
            'restore_db' => true,
            'restore_storage' => true,
            'verify_checksum' => false,
        ]);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->withArgs(function ($record, array $options) {
                return $options['source'] === 'remote'
                    && $options['restore_storage'] === true
                    && $options['verify_checksum'] === false
                    && ($options['wipe_storage'] ?? false) === false;
            })
            ->andReturnTrue();
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame('completed', $restore->fresh()->status);
    }

    #[Test]
    public function it_does_nothing_when_the_row_is_gone(): void
    {
        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');

        (new RunRestoreJob(999999))->handle($service);

        $this->assertTrue(true, 'a deleted restore row must not crash the worker');
    }
}
