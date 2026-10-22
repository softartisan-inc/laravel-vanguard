<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Encryption\AgeEncryptor;
use SoftArtisan\Vanguard\Tests\Support\FakesAgeBinary;
use SoftArtisan\Vanguard\Tests\TestCase;

class AgeEncryptorTest extends TestCase
{
    use FakesAgeBinary;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_age_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);

        file_put_contents($this->tmpDir.'/in.tar', 'plain archive content');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    #[Test]
    public function it_is_disabled_by_default(): void
    {
        $this->assertFalse(AgeEncryptor::configuredEnabled());
        $this->assertFalse((new AgeEncryptor)->enabled());
    }

    #[Test]
    public function every_recipient_is_handed_to_age(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient('q').", \n".$this->ageRecipient('p'));

        (new AgeEncryptor)->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');

        $log = file_get_contents($this->tmpDir.'/age.log');
        $this->assertStringContainsString($this->ageRecipient('q'), $log);
        $this->assertStringContainsString($this->ageRecipient('p'), $log);
        $this->assertStringStartsWith('age-encryption.org/v1', file_get_contents($this->tmpDir.'/out.tar.age'));
    }

    #[Test]
    public function recipients_are_accepted_as_an_array_and_from_a_file(): void
    {
        file_put_contents($this->tmpDir.'/recipients.txt', "# custodian\n".$this->ageRecipient('z')."\n\n");

        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', [$this->ageRecipient('q'), 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAbc admin@example.com']);
        config()->set('vanguard.encryption.recipients_file', $this->tmpDir.'/recipients.txt');

        $age = new AgeEncryptor;
        $age->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');

        $this->assertSame(3, $age->recipientCount());
        $this->assertStringContainsString('file='.$this->tmpDir.'/recipients.txt', file_get_contents($this->tmpDir.'/age.log'));
    }

    #[Test]
    public function an_invalid_recipient_is_refused_before_age_runs(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient().',AGE-SECRET-KEY-1NOTAPUBLICKEY');

        try {
            (new AgeEncryptor)->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');
            $this->fail('An invalid recipient was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('recipient #2', $e->getMessage());
            // What was refused is never echoed back: it could be a secret
            // pasted in the wrong variable.
            $this->assertStringNotContainsString('AGE-SECRET-KEY', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmpDir.'/age.log');
        $this->assertFileDoesNotExist($this->tmpDir.'/out.tar.age');
    }

    #[Test]
    public function no_recipient_at_all_is_refused(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recipient');

        (new AgeEncryptor)->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');
    }

    #[Test]
    public function a_failing_age_throws_and_removes_the_partial_file(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir, <<<'SH'
while [ $# -gt 0 ]; do [ "$1" = -o ] && { shift; out="$1"; }; shift; done
printf 'partial' > "$out"
echo "age: error: disk full" >&2
exit 1
SH));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient());

        try {
            (new AgeEncryptor)->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');
            $this->fail('A failing age was reported as a success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exit 1', $e->getMessage());
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmpDir.'/out.tar.age');
    }

    #[Test]
    public function an_empty_output_is_a_failure_even_on_exit_zero(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir, <<<'SH'
while [ $# -gt 0 ]; do [ "$1" = -o ] && { shift; out="$1"; }; shift; done
: > "$out"
exit 0
SH));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient());

        $this->expectException(RuntimeException::class);

        try {
            (new AgeEncryptor)->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');
        } finally {
            $this->assertFileDoesNotExist($this->tmpDir.'/out.tar.age');
        }
    }

    #[Test]
    public function stderr_never_ends_up_in_the_archive(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient());

        $age = new AgeEncryptor;
        $age->encrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/out.tar.age');

        $this->assertStringNotContainsString('diagnostic on stderr', file_get_contents($this->tmpDir.'/out.tar.age'));

        $identity = $this->tmpDir.'/identity.txt';
        file_put_contents($identity, 'not a real key');
        $age->decrypt($this->tmpDir.'/out.tar.age', $identity, $this->tmpDir.'/back.tar');

        $this->assertSame('plain archive content', file_get_contents($this->tmpDir.'/back.tar'));
    }

    #[Test]
    public function a_failing_decryption_throws_and_removes_the_partial_file(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir, <<<'SH'
while [ $# -gt 0 ]; do [ "$1" = -o ] && { shift; out="$1"; }; shift; done
printf 'half' > "$out"
echo "age: error: no identity matched any of the recipients" >&2
exit 1
SH));

        $identity = $this->tmpDir.'/identity.txt';
        file_put_contents($identity, 'not a real key');

        try {
            (new AgeEncryptor)->decrypt($this->tmpDir.'/in.tar', $identity, $this->tmpDir.'/back.tar');
            $this->fail('A failing decryption was reported as a success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no identity matched', $e->getMessage());
            $this->assertStringNotContainsString('not a real key', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmpDir.'/back.tar');
    }

    #[Test]
    public function a_missing_identity_file_is_refused(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity file');

        (new AgeEncryptor)->decrypt($this->tmpDir.'/in.tar', $this->tmpDir.'/nope.txt', $this->tmpDir.'/back.tar');
    }

    #[Test]
    public function status_reports_the_binary_and_the_recipient_count(): void
    {
        config()->set('vanguard.binaries.age', $this->tmpDir.'/missing-age');
        config()->set('vanguard.encryption.recipients', $this->ageRecipient());

        $status = (new AgeEncryptor)->status();

        $this->assertFalse($status['binary_present']);
        $this->assertSame(1, $status['recipients']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('age binary');
        (new AgeEncryptor)->assertReady();
    }

    #[Test]
    public function a_driver_other_than_age_is_refused(): void
    {
        config()->set('vanguard.binaries.age', $this->fakeAgeBinary($this->tmpDir));
        config()->set('vanguard.encryption.recipients', $this->ageRecipient());
        config()->set('vanguard.encryption.driver', 'gpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('driver');

        (new AgeEncryptor)->assertReady();
    }

    #[Test]
    public function an_archive_is_recognised_as_encrypted_from_its_meta_or_its_path(): void
    {
        $plain = new BackupRecord(['file_path' => 'vanguard-backups/a.tar']);
        $byMeta = new BackupRecord(['file_path' => 'x/a.tar', 'meta' => ['encryption' => ['format' => 'age']]]);
        $byPath = new BackupRecord(['remote_path' => 'vanguard-backups/a.tar.age', 'meta' => null]);

        $this->assertFalse(AgeEncryptor::isEncryptedRecord($plain));
        $this->assertTrue(AgeEncryptor::isEncryptedRecord($byMeta));
        $this->assertTrue(AgeEncryptor::isEncryptedRecord($byPath));
    }
}
