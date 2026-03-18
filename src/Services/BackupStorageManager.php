<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupStorageManager
{
    protected string $sessionTmpDir;
    protected array  $trackedTmpFiles = [];

    /**
     * Create a unique session-scoped temporary directory for this backup run.
     *
     * The directory is created with 0700 permissions to restrict access to the
     * web server user. It is cleaned up automatically after each backup via cleanTmp().
     */
    public function __construct()
    {
        $base = config('vanguard.tmp_path', storage_path('vanguard-tmp'));
        $this->sessionTmpDir = rtrim($base, '/').'/'.uniqid('vanguard_', true);
        @mkdir($this->sessionTmpDir, 0700, true);
    }

    // ─── Temp File Management ─────────────────────────────────────

    /**
     * Return an absolute path inside the session tmp directory and register it for cleanup.
     *
     * @param  string  $filename  Relative filename (e.g. 'landlord_1_db.sql.gz')
     * @return string  Absolute path to the tmp file
     */
    public function tmpPath(string $filename): string
    {
        $path = $this->sessionTmpDir.DIRECTORY_SEPARATOR.$filename;
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
        if (is_dir($this->sessionTmpDir)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->sessionTmpDir)));
        }
        $this->trackedTmpFiles = [];
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
     * @param  array   $files  ['database' => '/tmp/...sql.gz', 'storage' => '/tmp/...tar.gz']
     * @param  string  $name   Base name for the archive
     * @return array   ['local_path' => string|null, 'remote_path' => string|null, 'size' => int, 'checksum' => string]
     */
    public function bundle(array $files, string $name): array
    {
        $bundlePath = $this->sessionTmpDir."/{$name}.tar";

        if (empty($files)) {
            // Create an empty but valid tar with a manifest
            $manifest = $this->sessionTmpDir.'/manifest.txt';
            file_put_contents($manifest, "vanguard backup — no sources configured\n");
            exec(sprintf('tar cf %s -C %s manifest.txt 2>&1', escapeshellarg($bundlePath), escapeshellarg($this->sessionTmpDir)), $out, $code);
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
                sprintf('tar cf %s -C %s %s 2>&1', escapeshellarg($bundlePath), escapeshellarg($this->sessionTmpDir), $fileArgs),
                $out, $code,
            );
        }

        if (! file_exists($bundlePath)) {
            throw new RuntimeException('[Vanguard] Failed to bundle backup: '.implode("\n", $out ?? []));
        }

        $checksum = hash_file('sha256', $bundlePath);
        $size     = filesize($bundlePath);
        $result   = ['size' => $size, 'checksum' => $checksum, 'local_path' => null, 'remote_path' => null];

        // Remote first — stream while bundlePath is still on disk.
        // Flysystem's S3 adapter automatically uses multipart upload for large streams.
        if (config('vanguard.destinations.remote.enabled', false)) {
            $remoteDisk = config('vanguard.destinations.remote.disk', 's3');
            $remotePath = config('vanguard.destinations.remote.path', 'vanguard-backups')."/{$name}.tar";
            $stream = fopen($bundlePath, 'rb');
            Storage::disk($remoteDisk)->put($remotePath, $stream);
            fclose($stream);
            $result['remote_path'] = $remotePath;
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
     * @param  string  $sourcePath   Absolute path to the file to persist (may be consumed by rename)
     * @param  string  $disk         Filesystem disk name
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
        $stream = fopen($sourcePath, 'rb');
        Storage::disk($disk)->put($storagePath, $stream);
        fclose($stream);
    }

    // ─── Download for Restore ─────────────────────────────────────

    /**
     * Download a stored backup archive into the session tmp directory.
     *
     * @param  string  $storedPath  Path on disk as recorded in the BackupRecord
     * @param  bool    $remote      Whether to read from the remote disk instead of local
     * @return string  Absolute path to the downloaded file in the tmp directory
     *
     * @throws RuntimeException If the file does not exist on the disk
     */
    public function download(string $storedPath, bool $remote = false): string
    {
        $disk = $remote
            ? config('vanguard.destinations.remote.disk', 's3')
            : config('vanguard.destinations.local.disk', 'local');

        $tempFile = $this->tmpPath(basename($storedPath));

        if (! Storage::disk($disk)->exists($storedPath)) {
            throw new RuntimeException("Backup file not found on disk [{$disk}]: {$storedPath}");
        }

        $readStream  = Storage::disk($disk)->readStream($storedPath);
        $writeStream = fopen($tempFile, 'wb');
        stream_copy_to_stream($readStream, $writeStream);
        fclose($readStream);
        fclose($writeStream);

        return $tempFile;
    }

    /**
     * Extract a bundle archive and return a map of component files.
     *
     * @param  string  $bundlePath  Absolute path to the .tar bundle
     * @return array   ['database' => '/tmp/path.sql.gz', 'storage' => '/tmp/path.tar.gz']
     *
     * @throws RuntimeException If extraction fails
     */
    public function unBundle(string $bundlePath): array
    {
        $extractDir = $this->sessionTmpDir.'/extract_'.uniqid();
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
     * @return bool    true if the checksum matches
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
     * @return int  Number of records deleted
     */
    public function pruneOldBackups(?string $tenantId = null): int
    {
        $days   = config('vanguard.retention.days', 30);
        $cutoff = now()->subDays($days);

        $query = \SoftArtisan\Vanguard\Models\BackupRecord::completed()
            ->where('created_at', '<', $cutoff);

        if ($tenantId !== null) {
            $query->forTenant($tenantId);
        }

        $records = $query->get();
        $deleted = 0;

        foreach ($records as $record) {
            try {
                $this->deleteFile($record->file_path, false);
                $this->deleteFile($record->remote_path, true);
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
     * @param  string|null  $path    Path as stored on the disk
     * @param  bool         $remote  Whether to use the remote disk
     */
    protected function deleteFile(?string $path, bool $remote): void
    {
        if (! $path) return;

        $disk = $remote
            ? config('vanguard.destinations.remote.disk', 's3')
            : config('vanguard.destinations.local.disk', 'local');

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
