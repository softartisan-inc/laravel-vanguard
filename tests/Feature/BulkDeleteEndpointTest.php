<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Erasing a page of archives in one call.
 *
 * The interest of the endpoint is entirely in what it refuses and in what it
 * admits: it is confirmed like a prune, it clears the same destinations a
 * single delete clears, and it never reports a success it did not obtain.
 */
class BulkDeleteEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    #[Test]
    public function it_refuses_a_call_that_does_not_type_the_phrase_back(): void
    {
        $a = $this->makeRecord();
        $b = $this->makeRecord();

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$a->id, $b->id],
        ])
            ->assertStatus(400)
            ->assertJson(['expected' => 'delete 2 backups']);

        $this->assertNotNull(BackupRecord::find($a->id));
        $this->assertNotNull(BackupRecord::find($b->id));
    }

    #[Test]
    public function the_phrase_names_how_many_archives_are_about_to_go(): void
    {
        $records = collect(range(1, 3))->map(fn () => $this->makeRecord());

        // The count in the phrase is the count of what will be erased, so a
        // confirmation typed for two cannot delete three.
        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => $records->pluck('id')->all(),
            'confirm' => 'delete 2 backups',
        ])->assertStatus(400);

        $this->assertSame(3, BackupRecord::count());

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => $records->pluck('id')->all(),
            'confirm' => 'delete 3 backups',
        ])->assertOk();

        $this->assertSame(0, BackupRecord::count());
    }

    #[Test]
    public function a_single_selected_row_is_asked_for_in_the_singular(): void
    {
        $record = $this->makeRecord();

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$record->id],
            'confirm' => 'delete 1 backups',
        ])->assertStatus(400)->assertJson(['expected' => 'delete 1 backup']);

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$record->id],
            'confirm' => 'delete 1 backup',
        ])->assertOk();
    }

    #[Test]
    public function the_same_row_named_twice_counts_once(): void
    {
        $record = $this->makeRecord();

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$record->id, $record->id],
            'confirm' => 'delete 1 backup',
        ])
            ->assertOk()
            ->assertJson(['deleted' => [$record->id], 'failed' => []]);
    }

    #[Test]
    public function it_clears_every_destination_the_archive_reached(): void
    {
        Storage::fake('s3');
        config([
            'vanguard.destinations.remote.disk' => 's3',
        ]);

        Storage::disk('local')->put('vanguard-backups/one.tar', 'A');
        Storage::disk('s3')->put('vanguard-backups/one.tar', 'A');

        $record = $this->makeRecord([
            'file_path' => 'vanguard-backups/one.tar',
            'remote_path' => 'vanguard-backups/one.tar',
            'destinations' => ['local', 'remote'],
        ]);

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$record->id],
            'confirm' => 'delete 1 backup',
        ])->assertOk();

        Storage::disk('local')->assertMissing('vanguard-backups/one.tar');
        Storage::disk('s3')->assertMissing('vanguard-backups/one.tar');
    }

    #[Test]
    public function a_partial_failure_is_reported_as_one(): void
    {
        $kept = $this->makeRecord();

        $response = $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$kept->id, 999999],
            'confirm' => 'delete 2 backups',
        ])->assertStatus(207);

        $this->assertSame([$kept->id], $response->json('deleted'));
        $this->assertSame(999999, $response->json('failed.0.id'));
        $this->assertStringContainsString('not found', $response->json('failed.0.error'));
        $this->assertSame('1 deleted, 1 failed.', $response->json('message'));

        // What could be deleted was deleted: a bad id in the selection does
        // not turn the whole call into a no-op the operator has to redo.
        $this->assertNull(BackupRecord::find($kept->id));
    }

    #[Test]
    public function it_records_who_erased_how_many(): void
    {
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = [$message->level, $message->message, $message->context];
        });

        $record = $this->makeRecord();

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [$record->id],
            'confirm' => 'delete 1 backup',
        ])->assertOk();

        $bulk = collect($lines)->first(fn ($l) => str_contains($l[1], 'bulk delete completed'));

        $this->assertNotNull($bulk, 'a bulk delete has to leave one line saying how many archives it erased');
        $this->assertSame('warning', $bulk[0]);
        $this->assertSame('ops@in-immo.app', $bulk[2]['actor']);
        $this->assertSame([$record->id], $bulk[2]['deleted']);

        // And the per-archive line a single delete leaves is still left, once
        // per archive: the bulk path erases nothing anonymously.
        $this->assertNotNull(collect($lines)->first(fn ($l) => str_contains($l[1], 'backup deleted')));
    }

    #[Test]
    public function it_refuses_an_empty_or_oversized_selection(): void
    {
        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => [],
            'confirm' => 'delete 0 backups',
        ])->assertStatus(422);

        $this->postJson('/vanguard/api/backups/bulk-delete', [
            'ids' => range(1, 101),
            'confirm' => 'delete 101 backups',
        ])->assertStatus(422);
    }
}
