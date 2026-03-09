<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupStorageManager
{
    protected string $sessionTmpDir;
    protected array  $trackedTmpFiles = [];

    public function __construct()
    {
        $base = config('vanguard.tmp_path', storage_path('vanguard-tmp'));
        $this->sessionTmpDir = rtrim($base, '/').'/'.uniqid('vanguard_', true);
        @mkdir($this->sessionTmpDir, 0700, true);
    }

    // ─── Temp File Management ─────────────────────────────────────

    public function tmpPath(string $filename): string
    {
        $path = $this->sessionTmpDir.DIRECTORY_SEPARATOR.$filename;
        $this->trackedTmpFiles[] = $path;
        return $path;
    }

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

        if (config('vanguard.destinations.local.enabled', true)) {
            $localDisk = config('vanguard.destinations.local.disk', 'local');
            $localPath = config('vanguard.destinations.local.path', 'vanguard-backups')."/{$name}.tar";
            Storage::disk($localDisk)->put($localPath, file_get_contents($bundlePath));
            $result['local_path'] = $localPath;
        }

        if (config('vanguard.destinations.remote.enabled', false)) {
            $remoteDisk = config('vanguard.destinations.remote.disk', 's3');
            $remotePath = config('vanguard.destinations.remote.path', 'vanguard-backups')."/{$name}.tar";
            Storage::disk($remoteDisk)->put($remotePath, file_get_contents($bundlePath));
            $result['remote_path'] = $remotePath;
        }

        return $result;
    }

    // ─── Download for Restore ─────────────────────────────────────

    public function download(string $storedPath, bool $remote = false): string
    {
        $disk = $remote
            ? config('vanguard.destinations.remote.disk', 's3')
            : config('vanguard.destinations.local.disk', 'local');

        $tempFile = $this->tmpPath(basename($storedPath));

        if (! Storage::disk($disk)->exists($storedPath)) {
            throw new RuntimeException("Backup file not found on disk [{$disk}]: {$storedPath}");
        }

        file_put_contents($tempFile, Storage::disk($disk)->get($storedPath));
        return $tempFile;
    }

    /**
     * Extract a bundle archive and return a map of component files.
     *
     * @return array ['database' => '/tmp/path.sql.gz', 'storage' => '/tmp/path.tar.gz']
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

    public function verifyChecksum(string $filePath, string $expected): bool
    {
        return hash_file('sha256', $filePath) === $expected;
    }

    // ─── Pruning ──────────────────────────────────────────────────

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
