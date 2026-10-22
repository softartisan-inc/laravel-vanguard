<?php

namespace SoftArtisan\Vanguard\Services\Encryption;

use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;

/**
 * Opt-in encryption of backup archives with age (https://age-encryption.org).
 *
 * Archives are encrypted to one or more PUBLIC keys configured in
 * vanguard.encryption; decryption takes a private key (an "identity") handed
 * over at restore time. This class never stores, copies, logs or echoes an
 * identity, and never reads one from the configuration.
 *
 * age is run through proc_open with an argv array — no shell — so the exit
 * code checked is age's own and its stderr never reaches the output file. On
 * any failure the partial output is removed and an exception is thrown: an
 * archive that is not fully encrypted must never be mistaken for one that is.
 */
class AgeEncryptor
{
    public const FORMAT = 'age';

    public const EXTENSION = '.age';

    /**
     * Whether new archives are to be encrypted. False by default.
     */
    public static function configuredEnabled(): bool
    {
        return filter_var(config('vanguard.encryption.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether a stored backup is an age-encrypted archive.
     *
     * Read from the record's meta first, then from the stored paths, so that
     * an archive whose meta was lost or edited is still recognised by what
     * it actually is on disk. Independent of the current configuration:
     * turning encryption off does not make an encrypted archive plaintext.
     */
    public static function isEncryptedRecord(BackupRecord $record): bool
    {
        $meta = is_array($record->meta) ? $record->meta : [];

        if (($meta['encryption']['format'] ?? null) === self::FORMAT) {
            return true;
        }

        foreach ([$record->file_path, $record->remote_path, $record->ftp_path] as $path) {
            if (is_string($path) && str_ends_with($path, self::EXTENSION)) {
                return true;
            }
        }

        return false;
    }

    public function enabled(): bool
    {
        return self::configuredEnabled();
    }

    /**
     * The inline recipients, validated.
     *
     * @return list<string>
     *
     * @throws RuntimeException When an entry is not a public key
     */
    public function recipients(): array
    {
        $configured = config('vanguard.encryption.recipients', '');

        $entries = is_array($configured)
            ? $configured
            : preg_split('/[,\r\n]+/', (string) $configured);

        $recipients = [];

        foreach (array_values(array_filter(array_map(fn ($e) => trim((string) $e), $entries), fn ($e) => $e !== '')) as $i => $entry) {
            if (! $this->isRecipient($entry)) {
                // The entry itself is not repeated: an invalid "recipient" is
                // most likely a private key pasted into the wrong variable.
                throw new RuntimeException(sprintf(
                    '[Vanguard] vanguard.encryption.recipients: recipient #%d is not an age (age1...) or SSH (ssh-ed25519 / ssh-rsa) public key.',
                    $i + 1,
                ));
            }

            $recipients[] = $entry;
        }

        return $recipients;
    }

    /**
     * The configured recipients file, or null.
     */
    public function recipientsFile(): ?string
    {
        $file = config('vanguard.encryption.recipients_file');

        return is_string($file) && trim($file) !== '' ? trim($file) : null;
    }

    /**
     * How many recipients an archive is encrypted to: inline plus file.
     */
    public function recipientCount(): int
    {
        $count = count($this->recipients());

        if (($file = $this->recipientsFile()) !== null && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * The age binary: vanguard.binaries.age, else a common location, else PATH.
     */
    public function binary(): string
    {
        $configured = config('vanguard.binaries.age');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        foreach (['/usr/bin/age', '/usr/local/bin/age', '/opt/homebrew/bin/age'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'age';
    }

    public function binaryAvailable(): bool
    {
        $binary = $this->binary();

        if (str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        exec('command -v '.escapeshellarg($binary).' 2>/dev/null', $out, $code);

        return $code === 0 && ! empty($out[0]);
    }

    /**
     * What the health screen shows. Never a key, public or private.
     *
     * @return array{driver: string, binary: string, binary_present: bool, recipients: int, recipients_file: bool, error: string|null}
     */
    public function status(): array
    {
        $error = null;

        try {
            $recipients = $this->recipientCount();
        } catch (RuntimeException $e) {
            $recipients = 0;
            $error = $e->getMessage();
        }

        return [
            'driver' => (string) config('vanguard.encryption.driver', self::FORMAT),
            'binary' => $this->binary(),
            'binary_present' => $this->binaryAvailable(),
            'recipients' => $recipients,
            'recipients_file' => $this->recipientsFile() !== null,
            'error' => $error,
        ];
    }

    /**
     * Refuse to start a backup that could not be encrypted.
     *
     * Called before anything is dumped, so a misconfiguration costs nothing
     * but a clear message — and never a plaintext archive.
     *
     * @throws RuntimeException
     */
    public function assertReady(): void
    {
        $driver = config('vanguard.encryption.driver', self::FORMAT);

        if ($driver !== self::FORMAT) {
            throw new RuntimeException(sprintf(
                '[Vanguard] vanguard.encryption.driver must be "%s"; "%s" is not supported. Refusing to write an unencrypted archive.',
                self::FORMAT,
                is_scalar($driver) ? (string) $driver : gettype($driver),
            ));
        }

        if (! $this->binaryAvailable()) {
            throw new RuntimeException(sprintf(
                '[Vanguard] Archive encryption is enabled but the age binary [%s] was not found. '
                .'Install age or set VANGUARD_AGE_BINARY. Refusing to write an unencrypted archive.',
                $this->binary(),
            ));
        }

        $file = $this->recipientsFile();

        if ($file !== null && ! is_readable($file)) {
            throw new RuntimeException("[Vanguard] vanguard.encryption.recipients_file is not readable: {$file}");
        }

        if ($this->recipientCount() === 0) {
            throw new RuntimeException(
                '[Vanguard] Archive encryption is enabled but no recipient is configured: set VANGUARD_ENCRYPTION_RECIPIENTS '
                .'(age1... public keys) or VANGUARD_ENCRYPTION_RECIPIENTS_FILE. Refusing to write an unencrypted archive.'
            );
        }
    }

    /**
     * Encrypt $in to $out for every configured recipient.
     *
     * @throws RuntimeException On any failure; $out is removed
     */
    public function encrypt(string $in, string $out): void
    {
        $args = [];

        foreach ($this->recipients() as $recipient) {
            array_push($args, '-r', $recipient);
        }

        if (($file = $this->recipientsFile()) !== null) {
            array_push($args, '-R', $file);
        }

        if ($args === []) {
            throw new RuntimeException('[Vanguard] Cannot encrypt the archive: no recipient is configured.');
        }

        $this->run(array_merge([$this->binary(), '-e'], $args, ['-o', $out, $in]), $out, 'encryption');
    }

    /**
     * Decrypt $in to $out with the identity (private key) file given.
     *
     * The identity is read by age itself; its path is passed, never its
     * content. An identity protected by a passphrase makes age prompt on a
     * terminal, so it only works from an interactive console.
     *
     * @throws RuntimeException On any failure; $out is removed
     */
    public function decrypt(string $in, string $identityFile, string $out): void
    {
        if (! is_file($identityFile) || ! is_readable($identityFile)) {
            throw new RuntimeException('[Vanguard] The age identity file given for the restore cannot be read.');
        }

        $this->run([$this->binary(), '-d', '-i', $identityFile, '-o', $out, $in], $out, 'decryption');
    }

    /**
     * Run age and check everything a successful run must leave behind.
     *
     * @param  list<string>  $command
     */
    protected function run(array $command, string $out, string $label): void
    {
        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("[Vanguard] Could not start age for the archive {$label}.");
        }

        // age writes to -o, not stdout; both pipes are drained so neither can
        // fill up and block it.
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        clearstatcache(true, $out);

        if ($code !== 0 || ! is_file($out) || filesize($out) === 0) {
            if (is_file($out)) {
                @unlink($out);
            }

            throw new RuntimeException(sprintf(
                '[Vanguard] age %s failed (exit %d)%s',
                $label,
                $code,
                trim($stderr) !== '' ? ': '.mb_substr(trim($stderr), 0, 2000) : ($code === 0 ? ': no output was written' : ''),
            ));
        }
    }

    protected function isRecipient(string $entry): bool
    {
        return preg_match('/^age1[0-9a-z]+$/', $entry) === 1
            || preg_match('#^ssh-(ed25519|rsa) [A-Za-z0-9+/=]+( .*)?$#', $entry) === 1;
    }
}
