<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use RuntimeException;

class StorageDriver
{
    /**
     * Create a .tar.gz archive from a set of filesystem paths.
     *
     * Requires GNU tar — standard on Linux/macOS (production target).
     *
     * @param  array   $paths        Absolute paths to include
     * @param  array   $exclude      Absolute paths to exclude
     * @param  string  $destination  Absolute path for the output .tar.gz
     * @return string                Path to created archive
     */
    public function archive(array $paths, array $exclude, string $destination): string
    {
        $existingPaths = array_filter($paths, fn ($p) => is_dir($p) || is_file($p));

        if (empty($existingPaths)) {
            $this->exec(
                sprintf('tar czf %s --files-from /dev/null 2>&1', escapeshellarg($destination)),
                'tar empty',
            );
            return $destination;
        }

        $excludeArgs = collect($exclude)
            ->map(fn ($p) => '--exclude='.escapeshellarg($p))
            ->implode(' ');

        $pathArgs = collect($existingPaths)
            ->map(fn ($p) => escapeshellarg($p))
            ->implode(' ');

        $cmd = sprintf(
            'tar czf %s %s %s 2>&1',
            escapeshellarg($destination),
            $excludeArgs,
            $pathArgs,
        );

        $this->exec($cmd, 'tar archive');

        if (! file_exists($destination)) {
            throw new RuntimeException("Storage archive was not created: {$destination}");
        }

        return $destination;
    }

    /**
     * Extract a .tar.gz archive.
     *
     * @param  string  $source       Absolute path to the .tar.gz file
     * @param  string  $destination  Directory to extract into
     * @param  bool    $wipe         Whether to wipe the destination first
     */
    public function extract(string $source, string $destination, bool $wipe = false): void
    {
        if (! file_exists($source)) {
            throw new RuntimeException("Archive not found for extraction: {$source}");
        }

        if ($wipe && is_dir($destination)) {
            exec(sprintf('rm -rf %s', escapeshellarg($destination)));
        }

        @mkdir($destination, 0755, true);

        $this->exec(
            sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($source), escapeshellarg($destination)),
            'tar extract',
        );
    }

    public function resolveBackupPaths(): array
    {
        return collect(config('vanguard.sources.filesystem_paths', ['app']))
            ->map(fn ($p) => storage_path($p))
            ->filter(fn ($p) => is_dir($p))
            ->values()
            ->all();
    }

    public function resolveExcludePaths(): array
    {
        return collect(config('vanguard.sources.filesystem_exclude', []))
            ->map(fn ($p) => storage_path($p))
            ->all();
    }

    protected function exec(string $cmd, string $label): void
    {
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "[Vanguard:{$label}] Command failed (exit {$exitCode}):\n".implode("\n", $output)
            );
        }
    }
}
