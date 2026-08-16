<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Commands\VanguardListCommand;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * `--limit` went straight into the query as a cast integer.
 *
 * A typo therefore read as 0 — "no records found" on an installation with
 * hundreds — and an absent-minded 100000 asked the database for everything.
 * Clamping is safe here in a way it would never be on `vanguard:prune`: this
 * command only reads, so a coerced value shows the wrong number of rows rather
 * than deleting the wrong number of archives.
 */
class VanguardListLimitTest extends TestCase
{
    protected function command(?string $option): VanguardListCommand
    {
        return new class($option) extends VanguardListCommand
        {
            public function __construct(protected ?string $given)
            {
                parent::__construct();
            }

            public function option($key = null): mixed
            {
                return $key === 'limit' ? $this->given : null;
            }

            public function limit(): int
            {
                return $this->resolveLimit();
            }
        };
    }

    #[Test]
    public function it_keeps_a_sensible_limit_as_given(): void
    {
        $this->assertSame(20, $this->command('20')->limit());
        $this->assertSame(1, $this->command('1')->limit());
        $this->assertSame(1000, $this->command('1000')->limit());
    }

    #[Test]
    public function it_never_asks_the_database_for_zero_rows(): void
    {
        // (int) 'abc' and (int) '0' are both 0, and a listing of nothing reads
        // as "there are no backups" — the most alarming possible lie from this
        // command.
        $this->assertSame(1, $this->command('0')->limit());
        $this->assertSame(1, $this->command('abc')->limit());
        $this->assertSame(1, $this->command('-5')->limit());
    }

    #[Test]
    public function it_refuses_to_pull_an_unbounded_number_of_rows(): void
    {
        $this->assertSame(1000, $this->command('100000')->limit());
    }

    #[Test]
    public function it_falls_back_to_the_default_when_the_option_is_absent(): void
    {
        $this->assertSame(20, $this->command(null)->limit());
    }

    #[Test]
    public function the_command_still_lists_records_with_a_mistyped_limit(): void
    {
        $this->makeRecord(['type' => 'landlord']);

        $this->artisan('vanguard:list --limit=0')
            ->doesntExpectOutputToContain('No backup records found.')
            ->assertSuccessful();
    }
}
