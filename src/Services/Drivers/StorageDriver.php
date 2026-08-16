<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class StorageDriver
{
    /**
     * Create a .tar.gz archive from a set of filesystem paths.
     *
     * Members are stored relative to the directory they will be restored into —
     * storage_path() for everything living under it — so that extracting the
     * archive with `-C storage_path()` puts every file back exactly where it
     * was taken from. Handing tar an absolute path instead makes it strip the
     * leading slash and keep the producing machine's whole path as the member
     * name, which on restore recreates that path *under* the destination.
     *
     * Requires GNU tar — standard on Linux/macOS (production target).
     *
     * @param  array  $paths  Absolute paths to include
     * @param  array  $exclude  Absolute paths to exclude
     * @param  string  $destination  Absolute path for the output .tar.gz
     * @return string Path to created archive
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

        $roots = collect($existingPaths)
            ->map(fn ($p) => $this->splitAtBase($p))
            ->values();

        $bases = $roots->pluck('base')->unique()->values()->all();

        // An exclusion is matched by tar against the member name, so it only
        // bites once expressed in the same relative form as the members.
        $excludeArgs = collect($exclude)
            ->map(fn ($p) => '--exclude='.escapeshellarg($this->relativeToBases($p, $bases)))
            ->implode(' ');

        $pathArgs = $roots
            ->map(fn ($r) => sprintf(
                '-C %s %s',
                escapeshellarg($r['base']),
                escapeshellarg('./'.$r['relative']),
            ))
            ->implode(' ');

        $cmd = sprintf(
            'tar czf %s %s %s 2>&1',
            escapeshellarg($destination),
            $excludeArgs,
            $pathArgs,
        );

        $this->execArchive($cmd, 'tar archive');

        if (! file_exists($destination)) {
            throw new RuntimeException("Storage archive was not created: {$destination}");
        }

        return $destination;
    }

    /**
     * Extract a .tar.gz archive.
     *
     * Archives written before the layout fix carry the producing machine's
     * absolute path as their member names, so the leading components are
     * dropped for those — see legacyPrefixDepth() for how many and why.
     *
     * @param  string  $source  Absolute path to the .tar.gz file
     * @param  string  $destination  Directory to extract into
     * @param  bool  $wipe  Whether to wipe the destination first
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

        $strip = $this->legacyPrefixDepth($source, $destination);

        $cmd = $strip > 0
            ? sprintf(
                'tar xzf %s -C %s --strip-components=%d 2>&1',
                escapeshellarg($source),
                escapeshellarg($destination),
                $strip,
            )
            : sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($source), escapeshellarg($destination));

        $this->exec($cmd, 'tar extract');
    }

    /**
     * Split an absolute path into the base tar will be pointed at and the
     * member name to store under it.
     *
     * Anything inside storage_path() keeps its full depth below it, so a backup
     * root of 'app/public' is stored as 'app/public/…' and lands back on
     * storage/app/public/… . A path outside storage_path() — only reachable by
     * calling archive() directly — is stored under its own parent directory.
     *
     * @return array{base: string, relative: string}
     */
    protected function splitAtBase(string $path): array
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim(storage_path(), DIRECTORY_SEPARATOR);

        if ($root !== '' && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return ['base' => $root, 'relative' => substr($path, strlen($root) + 1)];
        }

        return ['base' => dirname($path), 'relative' => basename($path)];
    }

    /**
     * Express an absolute path relative to whichever archive base contains it.
     *
     * @param  array<string>  $bases  Base directories the archive is built from
     * @return string The path relative to its base
     */
    protected function relativeToBases(string $path, array $bases): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        usort($bases, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($bases as $base) {
            if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
                return substr($path, strlen($base) + 1);
            }
        }

        return ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * How many leading path components an archive's members carry on top of the
     * destination — 0 for anything this driver wrote itself.
     *
     * The answer is read off the archive, never off configuration or off the
     * machine that produced it: the absolute prefix of a legacy bundle differs
     * between environments (/var/www/html/storage in production, elsewhere in
     * staging or in tests), so only its *shape* can be relied upon.
     *
     * Detection, in order:
     *  1. Every member announced as "./…" means the archive was written
     *     relative to a base, i.e. by the current code. A legacy archive is the
     *     product of `tar czf <dest> /abs/path`, where tar strips the leading
     *     slash and stores "abs/path/…" — it can never start with "./". This
     *     makes the legacy branch impossible to trigger on a current archive.
     *  2. Otherwise the leading directory chain shared by every member is the
     *     absolute prefix. The component named like the destination directory
     *     is where the backup roots start, deepest match winning.
     *  3. Failing that, the deepest level whose names are the configured backup
     *     roots. Failing that too, nothing is stripped and the archive is
     *     extracted verbatim, which is what this driver has always done.
     *
     * @param  string  $source  Absolute path to the .tar.gz file
     * @param  string  $destination  Directory the archive is extracted into
     * @return int Number of components to strip
     */
    protected function legacyPrefixDepth(string $source, string $destination): int
    {
        $members = $this->members($source);

        if ($members === []) {
            return 0;
        }

        foreach ($members as $member) {
            if (str_starts_with($member, './')) {
                return 0;
            }
        }

        $segments = collect($members)
            ->map(fn ($m) => explode('/', trim($m, '/')))
            ->filter(fn ($parts) => count($parts) > 1)
            ->values();

        if ($segments->isEmpty()) {
            return 0;
        }

        $chain = $this->commonLeadingChain($segments->all());

        $destinationName = basename(rtrim($destination, DIRECTORY_SEPARATOR));

        for ($depth = count($chain); $depth > 0; $depth--) {
            if ($chain[$depth - 1] === $destinationName) {
                return $depth;
            }
        }

        $roots = $this->backupRootNames();

        for ($depth = count($chain); $depth >= 0; $depth--) {
            $names = $segments
                ->filter(fn ($parts) => count($parts) > $depth + 1)
                ->map(fn ($parts) => $parts[$depth])
                ->unique()
                ->all();

            if ($names !== [] && array_intersect($names, $roots) !== []) {
                return $depth;
            }
        }

        return 0;
    }

    /**
     * The leading directory components shared by every member, never eating the
     * last component of a member (that one is the file itself).
     *
     * @param  array<array<string>>  $segments  Member names already split on '/'
     * @return array<string>
     */
    protected function commonLeadingChain(array $segments): array
    {
        $first = $segments[0] ?? [];
        $chain = [];

        for ($i = 0; $i < count($first) - 1; $i++) {
            foreach ($segments as $parts) {
                if (count($parts) <= $i + 1 || $parts[$i] !== $first[$i]) {
                    return $chain;
                }
            }

            $chain[] = $first[$i];
        }

        return $chain;
    }

    /**
     * The first path segment of every configured backup root, e.g. 'app' for
     * both 'app' and 'app/public'.
     *
     * @return array<string>
     */
    protected function backupRootNames(): array
    {
        return collect(config('vanguard.sources.filesystem_paths', ['app']))
            ->map(fn ($p) => trim(str_replace('\\', '/', (string) $p), '/'))
            ->filter()
            ->map(fn ($p) => explode('/', $p)[0])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Member names held by a .tar.gz archive.
     *
     * A listing failure is not raised here: the extraction that follows reports
     * the real error with its own message.
     *
     * @return array<string>
     */
    protected function members(string $source): array
    {
        exec(sprintf('tar tzf %s 2>/dev/null', escapeshellarg($source)), $output, $exitCode);

        return $exitCode === 0 ? $output : [];
    }

    /**
     * Resolve the list of absolute filesystem paths to include in a backup.
     *
     * Reads from vanguard.sources.filesystem_paths (relative to storage_path())
     * and filters out paths that do not exist.
     *
     * @return array<string> Existing absolute directory paths
     */
    public function resolveBackupPaths(): array
    {
        return collect(config('vanguard.sources.filesystem_paths', ['app']))
            ->map(fn ($p) => storage_path($p))
            ->filter(fn ($p) => is_dir($p))
            ->values()
            ->all();
    }

    /**
     * Resolve the list of absolute filesystem paths to exclude from a backup.
     *
     * Reads from vanguard.sources.filesystem_exclude (relative to storage_path()).
     *
     * @return array<string> Absolute paths to exclude
     */
    public function resolveExcludePaths(): array
    {
        return collect(config('vanguard.sources.filesystem_exclude', []))
            ->map(fn ($p) => storage_path($p))
            ->all();
    }

    /**
     * Execute a shell command and throw a RuntimeException on non-zero exit.
     *
     * @param  string  $cmd  The shell command to run (must use escapeshellarg for all user data)
     * @param  string  $label  Short label used in the error message (e.g. 'tar archive')
     *
     * @throws RuntimeException
     */
    protected function exec(string $cmd, string $label): void
    {
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "[Vanguard:{$label}] Command failed (exit {$exitCode}):\n".implode("\n", $output)
            );
        }
    }

    /**
     * Execute a tar archive command, tolerating the "files differ" exit code.
     *
     * GNU tar exits 1 when a file changed while it was being read or vanished
     * mid-run, and 2 or above when it actually failed. On a live application
     * every backup races the logs, sessions and temporary uploads it archives,
     * so treating exit 1 as fatal means the backup fails at random — which is
     * exactly how a filesystem backup dies on a busy server. The archive is
     * complete apart from the members named in the warning, so it is kept and
     * the warning is logged.
     *
     * @param  string  $cmd  The shell command to run
     * @param  string  $label  Short label used in the error message
     *
     * @throws RuntimeException When tar fails for real (exit 2 or above)
     */
    protected function execArchive(string $cmd, string $label): void
    {
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            return;
        }

        if ($exitCode === 1) {
            Log::warning("[Vanguard:{$label}] Some files changed while being archived; the archive was kept.", [
                'output' => implode("\n", $output),
            ]);

            return;
        }

        throw new RuntimeException(
            "[Vanguard:{$label}] Command failed (exit {$exitCode}):\n".implode("\n", $output)
        );
    }
}
