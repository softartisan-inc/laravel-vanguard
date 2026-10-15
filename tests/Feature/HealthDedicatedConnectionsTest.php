<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The health screen checks the clients of the connections a backup and a
 * restore really go through. With dedicated connections configured, those
 * are the dedicated ones; the screen names them, and never their credentials.
 */
class HealthDedicatedConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);

        config([
            'database.connections.vanguard_backup_pg' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'app',
                'username' => 'backup_user',
                'password' => 'backup-secret-value',
            ],
            'database.connections.vanguard_restore_pg' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'app',
                'username' => 'owner_user',
                'password' => 'owner-secret-value',
            ],
        ]);
    }

    #[Test]
    public function the_dedicated_connections_are_part_of_the_client_check(): void
    {
        config([
            'vanguard.database.backup_connection' => 'vanguard_backup_pg',
            'vanguard.database.restore_connection' => 'vanguard_restore_pg',
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();
        $row = collect($response->json('database_clients'))->firstWhere('driver', 'pgsql');

        $this->assertNotNull($row, 'the driver of the dedicated connections must be checked');
        $this->assertContains('vanguard_backup_pg', $row['connections']);
        $this->assertContains('vanguard_restore_pg', $row['connections']);
    }

    #[Test]
    public function the_payload_names_the_dedicated_connections_and_nothing_secret(): void
    {
        config(['vanguard.database.backup_connection' => 'vanguard_backup_pg']);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $response->assertJsonPath('connections.backup', 'vanguard_backup_pg');
        $response->assertJsonPath('connections.restore', null);

        $payload = $response->getContent();
        $this->assertStringNotContainsString('backup-secret-value', $payload);
        $this->assertStringNotContainsString('backup_user', $payload);
    }

    #[Test]
    public function without_dedicated_connections_the_payload_is_unchanged(): void
    {
        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertArrayNotHasKey('connections', $response->json());
        $this->assertNull(collect($response->json('database_clients'))->firstWhere('driver', 'pgsql'));
    }
}
