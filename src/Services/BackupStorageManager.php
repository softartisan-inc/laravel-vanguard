<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;

class BackupStorageManager
{
    protected ?string $sessionTmpDir = null;

    protected array $trackedTmpFiles = [];

    // The session directory is not opened here on purpose. This service is
    // resolved by every dashboard request and every restore, most of which
    // never write a temporary file; creating a directory in the constructor
    // left one empty 0700 directory behind per resolution, forever — 687 of
    // them had piled up on the machine this was found on. It is opened on
    // first use instead, by sessionTmpDir().

    /**
     * Return the session tmp directory, opening a fresh one when there is none.
     *
     * One instance serves several backups in a row — backupAllTenants() loops
     * over the tenants in-process, and cleanTmp() deletes the directory after
     * each one. Resolving it lazily is what lets the second tenant, and every
     * tenant after it, still have somewhere to write.
     */
    protected function sessionTmpDir(): string
    {
        return $this->sessionTmpDir ??= $this->makeSessionTmpDir();
    }

    /**
     * Create a uniquely named tmp directory under the configured base path.
     *
     * @throws RuntimeException If the directory cannot be created
     */
    protected function makeSessionTmpDir(): string
    {
        $base = config('vanguard.tmp_path', storage_path('vanguard-tmp'));

        // Random rather than time-derived: this directory holds a plaintext
        // database dump between the dump and the bundle. The mode is 0700, so
        // a guessable name is not by itself a way in — but there is no reason
        // to publish when the backup ran either.
        $dir = rtrim($base, '/').'/vanguard_'.bin2hex(random_bytes(16));

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("[Vanguard] Cannot create tmp directory: {$dir}");
        }

        return $dir;
    }

    // ─── Temp File Management ─────────────────────────────────────

    /**
     * Return an absolute path inside the session tmp directory and register it for cleanup.
     *
     * @param  string  $filename  Relative filename (e.g. 'landlord_1_db.sql.gz')
     * @return string Absolute path to the tmp file
     */
    public function tmpPath(string $filename): string
    {
        $path = $this->sessionTmpDir().DIRECTORY_SEPARATOR.$filename;
        $this->trackedTmpFiles[] = $path;

        return $path;
    }

    /**
     * Remove the entire session tmp directory and reset the tracked file list.
     *
     * Should be called in a finally block after each backup or restore operation.
     */
    public function cleanTmp(): void
    {
        if ($this->sessionTmpDir !== null && is_dir($this->sessionTmpDir)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->sessionTmpDir)));
        }

        // Forget the directory rather than pointing at one that no longer
        // exists: the next tmpPath() call opens a fresh one.
        $this->sessionTmpDir = null;
        $this->trackedTmpFiles = [];
    }

    /**
     * Delete Vanguard session tmp directories older than $hours.
     *
     * cleanTmp() removes the current session's directory in a finally block;
     * a worker killed by a timeout or an OOM never reaches it and leaves the
     * dump behind. This is the sweep for those.
     *
     * It lives here rather than in the command because both the console and
     * the dashboard call it: the same code, so the two cannot drift apart.
     *
     * @param  int  $hours  Directories untouched for longer than this go
     * @return int Number of directories removed
     */
    public function cleanOrphanedTmp(int $hours = 6): int
    {
        $base = rtrim(config('vanguard.tmp_path', storage_path('vanguard-tmp')), '/');
        $hours = max(1, $hours);

        if (! is_dir($base)) {
            return 0;
        }

        $cutoff = time() - ($hours * 3600);
        $removed = 0;

        foreach (array_diff(scandir($base), ['.', '..']) as $entry) {
            // Only our own directories: the tmp path may be shared, and
            // anything not named vanguard_* is somebody else's.
            if (! str_starts_with($entry, 'vanguard_')) {
                continue;
            }

            $path = $base.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($path) || filemtime($path) >= $cutoff) {
                continue;
            }

            exec(sprintf('rm -rf %s', escapeshellarg($path)));
            $removed++;
        }

        return $removed;
    }

    // ─── Bundle & Persist ─────────────────────────────────────────

    /**
     * Bundle component files into a single .tar archive and persist to disk(s).
     *
     * Uses shell tar — requires Unix (Linux/macOS).
     *
     * Remote destination is written first (streaming, keeps bundlePath intact).
     * Local destination is written last and uses rename() when source and
     * destination share the same filesystem — atomic, O(1), zero data copy.
     * Falls back to a stream copy when rename() crosses filesystem boundaries.
     *
     * @param  array  $files  ['database' => '/tmp/...sql.gz', 'storage' => '/tmp/...tar.gz']
     * @param  string  $name  Base name for the archive
     * @return array ['local_path' => string|null, 'remote_path' => string|null, 'ftp_path' => string|null, 'size' => int, 'checksum' => string]
     */
    public function bundle(array $files, string $name): array
    {
        $bundlePath = $this->sessionTmpDir()."/{$name}.tar";

        if (empty($files)) {
            // Create an empty but valid tar with a manifest
            $manifest = $this->sessionTmpDir().'/manifest.txt';
            file_put_contents($manifest, "vanguard backup — no sources configured\n");
            exec(sprintf('tar cf %s -C %s manifest.txt 2>&1', escapeshellarg($bundlePath), escapeshellarg($this->sessionTmpDir())), $out, $code);
        } else {
            foreach ($files as $filePath) {
                if (! file_exists($filePath)) {
                    throw new RuntimeException("[Vanguard] Component file not found: {$filePath}");
                }
            }

            $fileArgs = collect($files)
                ->map(fn ($f) => escapeshellarg(basename($f)))
                ->implode(' ');

            exec(
                sprintf('tar cf %s -C %s %s 2>&1', escapeshellarg($bundlePath), escapeshellarg($this->sessionTmpDir()), $fileArgs),
                $out, $code,
            );
        }

        if (! file_exists($bundlePath)) {
            throw new RuntimeException('[Vanguard] Failed to bundle backup: '.implode("\n", $out ?? []));
        }

        $checksum = hash_file('sha256', $bundlePath);
        $size = filesize($bundlePath);
        $result = ['size' => $size, 'checksum' => $checksum, 'local_path' => null, 'remote_path' => null, 'ftp_path' => null];

        // Remote first — stream while bundlePath is still on disk.
        // Flysystem's S3 adapter automatically uses multipart upload for large streams.
        if (config('vanguard.destinations.remote.enabled', false)) {
            $remoteDisk = config('vanguard.destinations.remote.disk', 's3');
            $remotePath = config('vanguard.destinations.remote.path', 'vanguard-backups')."/{$name}.tar";
            $ok = $this->putStream($remoteDisk, $remotePath, $bundlePath);
            if (! $ok) {
                throw new RuntimeException("[Vanguard] Failed to write backup to remote disk [{$remoteDisk}]: {$remotePath}");
            }
            $result['remote_path'] = $remotePath;
        }

        // FTP/SFTP — stream after remote so bundlePath is still available.
        if (config('vanguard.destinations.ftp.enabled', false)) {
            $ftpDisk = config('vanguard.destinations.ftp.disk', 'ftp');
            $ftpPath = config('vanguard.destinations.ftp.path', 'vanguard-backups')."/{$name}.tar";
            $ok = $this->putStream($ftpDisk, $ftpPath, $bundlePath);
            if (! $ok) {
                throw new RuntimeException("[Vanguard] Failed to write backup to FTP disk [{$ftpDisk}]: {$ftpPath}");
            }
            $result['ftp_path'] = $ftpPath;
        }

        // Local last — attempt zero-copy rename(); fall back to stream if needed.
        if (config('vanguard.destinations.local.enabled', true)) {
            $localDisk = config('vanguard.destinations.local.disk', 'local');
            $localPath = config('vanguard.destinations.local.path', 'vanguard-backups')."/{$name}.tar";
            $this->persistToLocalDisk($bundlePath, $localDisk, $localPath);
            $result['local_path'] = $localPath;
        }

        return $result;
    }

    /**
     * Persist a file to a local Flysystem disk with the fastest available strategy.
     *
     * For the 'local' driver: attempts an atomic rename() (O(1), zero copy) when
     * source and destination are on the same filesystem. Falls back to a PHP stream
     * copy when they are not (e.g. tmp on tmpfs, storage on ext4).
     *
     * For any other driver (ftp, sftp, custom local adapters): always streams.
     *
     * @param  string  $sourcePath  Absolute path to the file to persist (may be consumed by rename)
     * @param  string  $disk  Filesystem disk name
     * @param  string  $storagePath  Destination path relative to the disk root
     */
    protected function persistToLocalDisk(string $sourcePath, string $disk, string $storagePath): void
    {
        $diskConfig = config("filesystems.disks.{$disk}", []);

        if (($diskConfig['driver'] ?? '') === 'local') {
            // Ask Flysystem for the canonical absolute destination path so that
            // the renamed file is found by subsequent Storage::disk()->exists()
            // and readStream() calls regardless of the test/runtime environment.
            $destPath = Storage::disk($disk)->path($storagePath);

            @mkdir(dirname($destPath), 0755, true);

            // rename() is atomic and O(1) on the same filesystem.
            // It returns false (EXDEV) when crossing filesystem boundaries.
            if (@rename($sourcePath, $destPath)) {
                return;
            }
        }

        // Fallback: stream copy — no full file in memory.
        $this->putStream($disk, $storagePath, $sourcePath);
    }

    /**
     * Stream a local file onto a Flysystem disk, closing the handle whatever
     * happens.
     *
     * The handle used to be closed on the line after the upload — the one line
     * that does not run when the upload throws. A Horizon worker running a
     * backup an hour against a bucket that intermittently refuses writes leaks
     * one descriptor per failure until it can no longer open a file at all.
     *
     * @param  string  $disk  Filesystem disk name
     * @param  string  $storagePath  Destination path on that disk
     * @param  string  $sourcePath  Absolute path of the local file to send
     * @return bool Whether the disk accepted the write
     *
     * @throws RuntimeException If the local file cannot be opened
     */
    protected function putStream(string $disk, string $storagePath, string $sourcePath): bool
    {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("[Vanguard] Cannot read the archive to upload: {$sourcePath}");
        }

        try {
            return Storage::disk($disk)->put($storagePath, $stream) !== false;
        } finally {
            // A Flysystem adapter may already have consumed and closed the
            // stream; closing a spent handle twice is not an error worth
            // raising over an upload that otherwise succeeded.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    // ─── Download for Restore ─────────────────────────────────────

    /**
     * Download a stored backup archive into the session tmp directory.
     *
     * @param  string  $storedPath  Path on disk as recorded in the BackupRecord
     * @param  string  $destination  Which destination to read from: 'local' | 'remote' | 'ftp'
     * @return string Absolute path to the downloaded file in the tmp directory
     *
     * @throws RuntimeException If the file does not exist on the disk
     */
    public function download(string $storedPath, string $destination = 'local'): string
    {
        $disk = match ($destination) {
            'remote' => config('vanguard.destinations.remote.disk', 's3'),
            'ftp' => config('vanguard.destinations.ftp.disk', 'ftp'),
            default => config('vanguard.destinations.local.disk', 'local'),
        };

        $tempFile = $this->tmpPath(basename($storedPath));

        if (! Storage::disk($disk)->exists($storedPath)) {
            throw new RuntimeException("Backup file not found on disk [{$disk}]: {$storedPath}");
        }

        $readStream = Storage::disk($disk)->readStream($storedPath);
        $writeStream = fopen($tempFile, 'wb');

        try {
            stream_copy_to_stream($readStream, $writeStream);
        } finally {
            // In a finally block for the same reason as the upload: a download
            // interrupted half way through must cost the archive, not a file
            // descriptor the worker never gets back.
            foreach ([$readStream, $writeStream] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        return $tempFile;
    }

    /**
     * Extract a bundle archive and return a map of component files.
     *
     * @param  string  $bundlePath  Absolute path to the .tar bundle
     * @return array ['database' => '/tmp/path.sql.gz', 'storage' => '/tmp/path.tar.gz']
     *
     * @throws RuntimeException If extraction fails
     */
    public function unBundle(string $bundlePath): array
    {
        $extractDir = $this->sessionTmpDir().'/extract_'.uniqid();
        @mkdir($extractDir, 0700, true);

        exec(
            sprintf('tar xf %s -C %s 2>&1', escapeshellarg($bundlePath), escapeshellarg($extractDir)),
            $out, $code,
        );

        if ($code !== 0) {
            throw new RuntimeException('[Vanguard] Failed to extract bundle: '.implode("\n", $out));
        }

        $components = [];

        foreach (array_diff(scandir($extractDir), ['.', '..']) as $file) {
            $full = "{$extractDir}/{$file}";

            if (str_contains($file, '_db') || str_ends_with($file, '.sql.gz')) {
                $components['database'] = $full;
            } elseif (str_contains($file, '_fs') || str_contains($file, '_storage') || str_ends_with($file, '.tar.gz')) {
                $components['storage'] = $full;
            }
        }

        return $components;
    }

    // ─── Integrity ────────────────────────────────────────────────

    /**
     * Verify the SHA-256 checksum of a file against an expected hash.
     *
     * @param  string  $filePath  Absolute path to the file to verify
     * @param  string  $expected  Expected SHA-256 hex digest
     * @return bool true if the checksum matches
     */
    public function verifyChecksum(string $filePath, string $expected): bool
    {
        return hash_file('sha256', $filePath) === $expected;
    }

    // ─── Pruning ──────────────────────────────────────────────────

    /**
     * Delete backup records and their associated files that exceed the retention policy.
     *
     * Reads the retention period from vanguard.retention.days. Files are deleted
     * from local and remote disks before the database record is removed.
     * Individual deletion failures are logged as warnings and do not halt pruning.
     *
     * @param  string|null  $tenantId  When provided, only prune records for this tenant
     * @return int Number of records deleted
     */
    public function pruneOldBackups(?string $tenantId = null): int
    {
        $days = config('vanguard.retention.days', 30);
        $cutoff = now()->subDays($days);

        $query = BackupRecord::completed()
            ->where('created_at', '<', $cutoff);

        if ($tenantId !== null) {
            $query->forTenant($tenantId);
        }

        $records = $query->get();
        $deleted = 0;

        foreach ($records as $record) {
            try {
                $this->deleteFile($record->file_path, 'local');
                $this->deleteFile($record->remote_path, 'remote');
                $this->deleteFile($record->ftp_path, 'ftp');
                $record->delete();
                $deleted++;
            } catch (\Throwable $e) {
                \Log::warning("[Vanguard] Could not prune backup #{$record->id}: ".$e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * Delete a single backup file from the appropriate disk.
     *
     * No-op when $path is null or the file does not exist on the disk.
     *
     * @param  string|null  $path  Path as stored on the disk
     * @param  string  $destination  Which destination to target: 'local' | 'remote' | 'ftp'
     */
    protected function deleteFile(?string $path, string $destination): void
    {
        if (! $path) {
            return;
        }

        $disk = match ($destination) {
            'remote' => config('vanguard.destinations.remote.disk', 's3'),
            'ftp' => config('vanguard.destinations.ftp.disk', 'ftp'),
            default => config('vanguard.destinations.local.disk', 'local'),
        };

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
