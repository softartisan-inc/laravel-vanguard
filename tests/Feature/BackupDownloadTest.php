<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class BackupDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    #[Test]
    public function it_streams_the_archive_from_the_destination_that_holds_it(): void
    {
        Storage::disk('local')->put('vanguard-backups/landlord-2026-08-16.tar', 'ARCHIVE-BYTES');

        $backup = $this->makeRecord(['file_path' => 'vanguard-backups/landlord-2026-08-16.tar']);

        $response = $this->get("/vanguard/api/backups/{$backup->id}/download");

        $response->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=landlord-2026-08-16.tar');

        $this->assertSame('ARCHIVE-BYTES', $response->streamedContent());
    }

    #[Test]
    public function it_reads_the_copy_the_caller_asks_for(): void
    {
        Storage::fake('s3');
        config(['vanguard.destinations.remote.disk' => 's3']);
        Storage::disk('s3')->put('vanguard-backups/remote.tar', 'REMOTE-BYTES');
        Storage::disk('local')->put('vanguard-backups/local.tar', 'LOCAL-BYTES');

        $backup = $this->makeRecord([
            'file_path' => 'vanguard-backups/local.tar',
            'remote_path' => 'vanguard-backups/remote.tar',
        ]);

        $this->assertSame(
            'REMOTE-BYTES',
            $this->get("/vanguard/api/backups/{$backup->id}/download?source=remote")->streamedContent(),
        );
    }

    #[Test]
    public function it_falls_back_to_the_first_destination_the_backup_actually_reached(): void
    {
        // No default of 'local': on the recommended production setup local is
        // disabled and only the remote copy exists. Assuming local is what
        // made restores impossible there, and downloads would repeat it.
        Storage::fake('s3');
        config(['vanguard.destinations.remote.disk' => 's3']);
        Storage::disk('s3')->put('vanguard-backups/remote-only.tar', 'REMOTE-ONLY');

        $backup = $this->makeRecord([
            'file_path' => null,
            'remote_path' => 'vanguard-backups/remote-only.tar',
        ]);

        $this->assertSame(
            'REMOTE-ONLY',
            $this->get("/vanguard/api/backups/{$backup->id}/download")->streamedContent(),
        );
    }

    #[Test]
    public function it_answers_422_for_an_unknown_source_value_even_without_an_accept_header(): void
    {
        // Deliberately $this->get(...), not getJson(...): a browser navigating
        // straight to the download link (<a href>, window.location) sends no
        // Accept: application/json, and Laravel's validate() would otherwise
        // redirect such a request instead of answering the documented 422.
        Storage::disk('local')->put('vanguard-backups/local.tar', 'LOCAL-BYTES');

        $backup = $this->makeRecord(['file_path' => 'vanguard-backups/local.tar']);

        $this->get("/vanguard/api/backups/{$backup->id}/download?source=bogus")
            ->assertStatus(422);
    }

    #[Test]
    public function it_refuses_a_destination_the_backup_never_reached(): void
    {
        Storage::disk('local')->put('vanguard-backups/local.tar', 'LOCAL-BYTES');

        $backup = $this->makeRecord(['file_path' => 'vanguard-backups/local.tar']);

        $this->getJson("/vanguard/api/backups/{$backup->id}/download?source=ftp")->assertStatus(400);
    }

    #[Test]
    public function it_answers_404_when_the_archive_is_recorded_but_gone_from_the_disk(): void
    {
        // The record says the file is there; the bucket says otherwise. A
        // stream of nothing at all is worse than a clear answer.
        $backup = $this->makeRecord(['file_path' => 'vanguard-backups/vanished.tar']);

        $this->getJson("/vanguard/api/backups/{$backup->id}/download")->assertStatus(404);
    }

    #[Test]
    public function it_answers_404_for_a_backup_that_does_not_exist(): void
    {
        $this->getJson('/vanguard/api/backups/4242/download')->assertStatus(404);
    }

    #[Test]
    public function it_refuses_a_backup_that_reached_no_destination_at_all(): void
    {
        $backup = $this->makeRecord(['file_path' => null]);

        $this->getJson("/vanguard/api/backups/{$backup->id}/download")->assertStatus(400);
    }

    #[Test]
    public function it_records_who_took_the_archive_off_the_server(): void
    {
        // Traced because it takes every tenant's database, personal data
        // included, out of the building (spec §7).
        Storage::disk('local')->put('vanguard-backups/landlord.tar', 'X');

        $backup = $this->makeRecord([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'file_path' => 'vanguard-backups/landlord.tar',
        ]);

        $logger = Log::spy();

        $this->get("/vanguard/api/backups/{$backup->id}/download")->assertOk();

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $message === '[Vanguard] backup downloaded'
                && $context['actor'] === 'ops@in-immo.app'
                && $context['target'] === '9001'
                && $context['source'] === 'local',
        );
    }

    #[Test]
    public function it_records_who_deleted_a_backup(): void
    {
        Storage::disk('local')->put('vanguard-backups/doomed.tar', 'X');

        $backup = $this->makeRecord(['file_path' => 'vanguard-backups/doomed.tar']);

        $logger = Log::spy();

        $this->deleteJson("/vanguard/api/backups/{$backup->id}")->assertOk();

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $message === '[Vanguard] backup deleted'
                && $context['actor'] === 'ops@in-immo.app'
                && $context['backup_id'] === $backup->id,
        );
    }
}
