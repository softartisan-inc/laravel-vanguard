<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use DeflateContext;
use RuntimeException;

/**
 * The single place that knows how a database dump reaches the disk.
 *
 * Deliberately NOT gzopen()/gzwrite()/gzclose(): zlib buffers internally and
 * reports the number of bytes it accepted, not the number that reached the
 * disk, so a full disk goes unnoticed and yields a truncated archive that is
 * then recorded as a successful backup. Compressing in memory with
 * deflate_init()/deflate_add() and writing through a plain, checked handle
 * makes a failed write observable — every dump path goes through here so the
 * check cannot be forgotten on one of them.
 */
class GzipDumpWriter
{
    /** @var resource|null Open write handle, null once closed, aborted or discarded. */
    private $handle;

    /**
     * @param  resource  $handle
     */
    private function __construct(
        $handle,
        private DeflateContext $deflate,
        private string $dest,
        private string $label,
    ) {
        $this->handle = $handle;
    }

    /**
     * Open a destination for a gzipped dump.
     *
     * @param  string  $dest   Absolute destination path (.sql.gz)
     * @param  string  $label  Short label used in error messages (e.g. 'mysqldump')
     *
     * @throws RuntimeException When the destination cannot be opened
     */
    public static function open(string $dest, string $label): self
    {
        $handle = @fopen($dest, 'wb');

        if ($handle === false) {
            throw new RuntimeException("[Vanguard:{$label}] Cannot open dump destination: {$dest}");
        }

        return new self($handle, deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]), $dest, $label);
    }

    /**
     * Compress and write a chunk of the dump.
     *
     * On failure the partial file is removed so no corrupt backup survives.
     *
     * @throws RuntimeException When the destination stops accepting data
     */
    public function write(string $data): void
    {
        if ($data === '' || $this->handle === null) {
            return;
        }

        if (! $this->writeAll(deflate_add($this->deflate, $data, ZLIB_NO_FLUSH))) {
            $this->discard();

            throw $this->writeFailure();
        }
    }

    /**
     * Flush the gzip trailer and close the file.
     *
     * The trailer carries the CRC and the length; without it the archive is
     * unreadable, so this last write matters as much as the rest.
     *
     * @throws RuntimeException When the trailer or the final flush cannot be written
     */
    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $tailWritten = $this->writeAll(deflate_add($this->deflate, '', ZLIB_FINISH));
        $closed      = fclose($this->handle);
        $this->handle = null;

        if (! $tailWritten || $closed === false) {
            @unlink($this->dest);

            throw $this->writeFailure();
        }
    }

    /**
     * Close the handle without touching the file on disk.
     *
     * Used when the failure comes from elsewhere (a PDO error, for instance)
     * and the caller's own error handling decides what happens next.
     */
    public function abort(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Close the handle and remove the partial file, so no corrupt backup survives.
     */
    public function discard(): void
    {
        $this->abort();

        @unlink($this->dest);
    }

    /**
     * Write a whole string to the stream, tolerating short writes.
     *
     * fwrite() may legitimately write less than asked for; only a false return
     * or a stalled write means the destination stopped accepting data.
     *
     * @return bool  true when every byte was written
     */
    private function writeAll(string $data): bool
    {
        $length  = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($this->handle, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                return false;
            }

            $written += $bytes;
        }

        return true;
    }

    private function writeFailure(): RuntimeException
    {
        return new RuntimeException(
            "[Vanguard:{$this->label}] Could not write the whole dump to {$this->dest} (disk full?)."
        );
    }
}
