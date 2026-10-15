<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * Dedicated backup and restore connections.
 *
 * On a database that isolates its rows with Row-Level Security, the
 * application's own role only sees the rows of the current tenant. A dump
 * taken through that connection is not a backup of the database: it is a
 * backup of what that role can see. The two settings below let the dump and
 * the restore run as roles of their own (BYPASSRLS, the dump one read-only).
 *
 * Left unset, both must resolve to exactly what they resolved to before the
 * settings existed — that half is the backward-compatibility guarantee.
 */
class TenancyResolverConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // database.default stays the in-memory sqlite connection the test
        // database runs on; the dedicated connections share its driver.
        config([
            'database.connections.sqlite_backup' => [
                'driver' => 'sqlite',
                'database' => '/var/lib/app.sqlite',
                'username' => 'backup_user',
            ],
            'database.connections.sqlite_restore' => [
                'driver' => 'sqlite',
                'database' => '/var/lib/app.sqlite',
                'username' => 'owner_user',
            ],
            'database.connections.mysql_other' => [
                'driver' => 'mysql',
                'host' => 'db.internal',
                'database' => 'app',
            ],
        ]);
    }

    // ─── Defaults: nothing moves ─────────────────────────────────

    #[Test]
    public function without_a_backup_connection_the_dump_uses_the_landlord_connection_unchanged(): void
    {
        config(['vanguard.database.backup_connection' => null]);

        $resolver = new TenancyResolver;

        $this->assertSame($resolver->landlordDbConfig(), $resolver->backupDbConfig());
    }

    #[Test]
    public function without_a_backup_connection_the_central_connection_setting_is_still_honoured(): void
    {
        config([
            'vanguard.database.backup_connection' => null,
            'tenancy.database.central_connection' => 'sqlite_restore',
        ]);

        $resolver = new TenancyResolver;

        $this->assertSame('owner_user', $resolver->backupDbConfig()['username']);
    }

    #[Test]
    public function an_empty_backup_connection_counts_as_unset(): void
    {
        // VANGUARD_BACKUP_CONNECTION= in a .env file yields '' rather than null.
        config(['vanguard.database.backup_connection' => '']);

        $resolver = new TenancyResolver;

        $this->assertSame($resolver->landlordDbConfig(), $resolver->backupDbConfig());
    }

    #[Test]
    public function without_a_restore_connection_the_restore_uses_the_default_connection(): void
    {
        config(['vanguard.database.restore_connection' => null]);

        $resolver = new TenancyResolver;

        $this->assertSame(config('database.connections.sqlite'), $resolver->restoreDbConfig());
        $this->assertFalse($resolver->hasDedicatedRestoreConnection());
    }

    // ─── Opt-in: the named connection is used ────────────────────

    #[Test]
    public function a_backup_connection_is_used_for_the_dump(): void
    {
        config(['vanguard.database.backup_connection' => 'sqlite_backup']);

        $resolver = new TenancyResolver;

        $this->assertSame('backup_user', $resolver->backupDbConfig()['username']);
    }

    #[Test]
    public function a_restore_connection_is_used_for_the_restore(): void
    {
        config(['vanguard.database.restore_connection' => 'sqlite_restore']);

        $resolver = new TenancyResolver;

        $this->assertSame('owner_user', $resolver->restoreDbConfig()['username']);
        $this->assertTrue($resolver->hasDedicatedRestoreConnection());
    }

    // ─── Opt-in, misconfigured: refused, never a silent fallback ─

    #[Test]
    public function an_undeclared_backup_connection_is_refused_and_named(): void
    {
        config(['vanguard.database.backup_connection' => 'sqlite_missing']);

        try {
            (new TenancyResolver)->backupDbConfig();
            $this->fail('An undeclared backup connection must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vanguard.database.backup_connection', $e->getMessage());
            $this->assertStringContainsString('sqlite_missing', $e->getMessage());
        }
    }

    #[Test]
    public function an_undeclared_restore_connection_is_refused_and_named(): void
    {
        config(['vanguard.database.restore_connection' => 'sqlite_missing']);

        try {
            (new TenancyResolver)->restoreDbConfig();
            $this->fail('An undeclared restore connection must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vanguard.database.restore_connection', $e->getMessage());
            $this->assertStringContainsString('sqlite_missing', $e->getMessage());
        }
    }

    #[Test]
    public function a_backup_connection_on_another_driver_is_refused(): void
    {
        config(['vanguard.database.backup_connection' => 'mysql_other']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/driver \\[mysql\\].*driver \\[sqlite\\]/');

        (new TenancyResolver)->backupDbConfig();
    }

    #[Test]
    public function a_restore_connection_on_another_driver_is_refused(): void
    {
        config(['vanguard.database.restore_connection' => 'mysql_other']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/driver/i');

        (new TenancyResolver)->restoreDbConfig();
    }
}
