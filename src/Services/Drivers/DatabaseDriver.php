<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use RuntimeException;

class DatabaseDriver
{
    /**
     * Dump a database to a gzipped SQL file.
     *
     * @param  string  $driver       'mysql'|'mariadb'|'pgsql'|'sqlite'
     * @param  array   $config       Laravel DB connection config
     * @param  string  $destination  Absolute path for output (.sql.gz)
     * @return string                Path to the created dump file
     *
     * @throws RuntimeException For unsupported drivers or if the dump file is empty/missing
     */
    public function dump(string $driver, array $config, string $destination): string
    {
        // Normalize extension
        if (! str_ends_with($destination, '.sql.gz')) {
            $destination = rtrim($destination, '.gz');
            $destination = str_ends_with($destination, '.sql')
                ? $destination.'.gz'
                : $destination.'.sql.gz';
        }

        match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($config, $destination),
            'pgsql'            => $this->dumpPgsql($config, $destination),
            'sqlite'           => $this->dumpSqlite($config, $destination),
            default            => throw new RuntimeException("Unsupported DB driver: [{$driver}]"),
        };

        if (! file_exists($destination) || filesize($destination) === 0) {
            throw new RuntimeException("Dump file was not created or is empty: {$destination}");
        }

        return $destination;
    }

    /**
     * Restore a .sql.gz dump into a database.
     *
     * @param  string  $driver  'mysql'|'mariadb'|'pgsql'|'sqlite'
     * @param  array   $config  Laravel DB connection config
     * @param  string  $source  Absolute path to the .sql.gz dump file
     *
     * @throws RuntimeException For unsupported drivers or missing source file
     */
    public function restore(string $driver, array $config, string $source): void
    {
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'])) {
            throw new RuntimeException("Unsupported DB driver: [{$driver}]");
        }

        if (! file_exists($source)) {
            throw new RuntimeException("Restore file not found: {$source}");
        }

        match ($driver) {
            'mysql', 'mariadb' => $this->restoreMysql($config, $source),
            'pgsql'            => $this->restorePgsql($config, $source),
            'sqlite'           => $this->restoreSqlite($config, $source),
        };
    }

    // ─── MySQL / MariaDB ─────────────────────────────────────────

    /**
     * Dump a MySQL/MariaDB database to a gzipped SQL file.
     *
     * Prefers the mysqldump CLI when available (faster, handles edge cases).
     * Falls back to a PHP/PDO-based dump when mysqldump is not installed,
     * which is common in Docker or minimal server environments.
     *
     * @param  array   $c     Laravel MySQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     */
    protected function dumpMysql(array $c, string $dest): void
    {
        $binary = $this->resolveBinary('mysqldump');

        if ($this->binaryAvailable($binary)) {
            $this->dumpMysqlViaCli($c, $dest, $binary);
        } else {
            $this->dumpMysqlViaPdo($c, $dest);
        }
    }

    /**
     * Dump MySQL via the mysqldump CLI binary.
     *
     * Uses MYSQL_PWD environment variable to pass the password securely.
     */
    protected function dumpMysqlViaCli(array $c, string $dest, string $binary): void
    {
        $this->setMysqlPasswordEnv($c);

        try {
            $cmd = sprintf(
                '%s %s %s 2>&1 | gzip > %s',
                escapeshellcmd($binary),
                $this->mysqlConnectionArgs($c),
                escapeshellarg($c['database']),
                escapeshellarg($dest),
            );

            $this->exec($cmd, 'mysqldump');
        } finally {
            $this->clearMysqlPasswordEnv();
        }
    }

    /**
     * Dump MySQL via PDO — no binary required.
     *
     * Exports schema (CREATE TABLE) and data (INSERT) for every table.
     * Output is written directly to a gzip stream, avoiding large temp files.
     *
     * @param  array   $c     Laravel MySQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     *
     * @throws RuntimeException On PDO or gzip errors
     */
    protected function dumpMysqlViaPdo(array $c, string $dest): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $c['host']     ?? '127.0.0.1',
            $c['port']     ?? 3306,
            $c['database'],
            $c['charset']  ?? 'utf8mb4',
        );

        $pdo = new \PDO($dsn, $c['username'] ?? 'root', $c['password'] ?? '', [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $gz = @gzopen($dest, 'wb9');
        if ($gz === false) {
            throw new RuntimeException("Cannot open gzip destination: {$dest}");
        }

        try {
            $db = $c['database'];

            gzwrite($gz, "-- Vanguard MySQL dump (PDO fallback)\n");
            gzwrite($gz, "-- Database: {$db}\n");
            gzwrite($gz, "-- Generated: ".now()->toIso8601String()."\n\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Schema
                $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $create['Create Table'].";\n\n");

                // Data — stream row by row to limit memory usage
                $rows = $pdo->query("SELECT * FROM `{$table}`");
                $count = 0;

                foreach ($rows as $row) {
                    $values = array_map(
                        fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        $row,
                    );

                    gzwrite($gz, "INSERT INTO `{$table}` VALUES (".implode(',', $values).");\n");
                    $count++;

                    // Flush every 500 rows to avoid large write buffers
                    if ($count % 500 === 0) {
                        gzwrite($gz, "\n");
                    }
                }

                if ($count > 0) {
                    gzwrite($gz, "\n");
                }
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Restore a MySQL/MariaDB database from a gzipped SQL dump.
     *
     * Uses MYSQL_PWD environment variable to pass the password securely.
     *
     * @param  array   $c    Laravel MySQL connection config
     * @param  string  $src  Absolute path to the .sql.gz dump file
     */
    protected function restoreMysql(array $c, string $src): void
    {
        $this->setMysqlPasswordEnv($c);

        try {
            $cmd = sprintf(
                'gunzip -c %s | %s %s %s 2>&1',
                escapeshellarg($src),
                escapeshellcmd($this->resolveBinary('mysql')),
                $this->mysqlConnectionArgs($c),
                escapeshellarg($c['database']),
            );

            $this->exec($cmd, 'mysql restore');
        } finally {
            $this->clearMysqlPasswordEnv();
        }
    }

    /**
     * Build the common MySQL connection arguments string (host, port, user, socket, flags).
     *
     * The password is intentionally omitted here and passed via the MYSQL_PWD
     * environment variable to avoid exposing it in the process list.
     *
     * @param  array  $c  Laravel MySQL connection config
     * @return string     Shell-safe argument string
     */
    protected function mysqlConnectionArgs(array $c): string
    {
        $args = sprintf(
            '-h %s -P %s -u %s',
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($c['port'] ?? 3306)),
            escapeshellarg($c['username'] ?? 'root'),
        );

        if (! empty($c['unix_socket'])) {
            $args .= ' --socket='.escapeshellarg($c['unix_socket']);
        }

        // Safe dump flags
        $args .= ' --single-transaction --quick --lock-tables=false';

        // Suppress GTID warning if not using GTID replication
        $args .= ' --set-gtid-purged=OFF';

        return $args;
    }

    /**
     * Set the MYSQL_PWD environment variable for the current process.
     *
     * This avoids passing the password on the command line where it would be
     * visible in the system process list. Always call clearMysqlPasswordEnv()
     * in a finally block after the command completes.
     *
     * @param  array  $c  Laravel MySQL connection config
     */
    protected function setMysqlPasswordEnv(array $c): void
    {
        if (! empty($c['password'])) {
            putenv("MYSQL_PWD={$c['password']}");
        }
    }

    /**
     * Remove MYSQL_PWD from the process environment.
     *
     * Called in a finally block after dumpMysql/restoreMysql so that the
     * credential does not persist in the process env across subsequent jobs.
     */
    protected function clearMysqlPasswordEnv(): void
    {
        putenv('MYSQL_PWD');
    }

    // ─── PostgreSQL ───────────────────────────────────────────────

    /**
     * Dump a PostgreSQL database to a gzipped SQL file via pg_dump.
     *
     * The password is passed via the PGPASSWORD environment variable prefix.
     *
     * @param  array   $c     Laravel PostgreSQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     */
    protected function dumpPgsql(array $c, string $dest): void
    {
        $cmd = sprintf(
            '%s %s --format=plain --no-acl --no-owner -h %s -p %s -U %s %s 2>&1 | gzip > %s',
            $this->pgPasswordEnv($c),
            escapeshellcmd($this->resolveBinary('pg_dump')),
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($c['port'] ?? 5432)),
            escapeshellarg($c['username']),
            escapeshellarg($c['database']),
            escapeshellarg($dest),
        );

        $this->exec($cmd, 'pg_dump');
    }

    /**
     * Restore a PostgreSQL database from a gzipped SQL dump via psql.
     *
     * The password is passed via the PGPASSWORD environment variable prefix.
     *
     * @param  array   $c    Laravel PostgreSQL connection config
     * @param  string  $src  Absolute path to the .sql.gz dump file
     */
    protected function restorePgsql(array $c, string $src): void
    {
        $cmd = sprintf(
            '%s gunzip -c %s | %s -h %s -p %s -U %s -d %s 2>&1',
            $this->pgPasswordEnv($c),
            escapeshellarg($src),
            escapeshellcmd($this->resolveBinary('psql')),
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($c['port'] ?? 5432)),
            escapeshellarg($c['username']),
            escapeshellarg($c['database']),
        );

        $this->exec($cmd, 'psql restore');
    }

    /**
     * Build the PGPASSWORD=... environment variable prefix for a PostgreSQL command.
     *
     * Returns an empty string when no password is set so the prefix can be
     * safely prepended to any command without causing syntax errors.
     *
     * @param  array  $c  Laravel PostgreSQL connection config
     * @return string     e.g. "PGPASSWORD='secret'" or ""
     */
    protected function pgPasswordEnv(array $c): string
    {
        return ! empty($c['password'])
            ? 'PGPASSWORD='.escapeshellarg($c['password'])
            : '';
    }

    // ─── SQLite ───────────────────────────────────────────────────

    /**
     * Dump a SQLite database to a gzipped file.
     *
     * For in-memory databases (used in tests), the schema is exported via PDO
     * and written directly to a gzip stream. For file-based databases, gzip
     * compresses the file directly for speed.
     *
     * @param  array   $c     Laravel SQLite connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     *
     * @throws RuntimeException If the database file does not exist
     */
    protected function dumpSqlite(array $c, string $dest): void
    {
        $src = $c['database'];

        // Special case: in-memory SQLite (used in tests) — export via PDO
        if ($src === ':memory:') {
            $pdo = new \PDO("sqlite:{$src}");
            $sql = '';
            foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL") as $row) {
                $sql .= $row['sql'].";\n";
            }
            $gz = gzopen($dest, 'wb9');
            gzwrite($gz, $sql ?: "-- empty database\n");
            gzclose($gz);
            return;
        }

        if (! file_exists($src)) {
            throw new RuntimeException("SQLite database file not found: {$src}");
        }

        // On Unix: use gzip for speed and streaming
        $this->exec(
            sprintf('gzip -c %s > %s 2>&1', escapeshellarg($src), escapeshellarg($dest)),
            'sqlite gzip',
        );
    }

    /**
     * Restore a SQLite database from a gzipped dump.
     *
     * No-op for in-memory databases as there is nothing meaningful to restore to.
     *
     * @param  array   $c    Laravel SQLite connection config
     * @param  string  $src  Absolute path to the gzipped SQLite file
     */
    protected function restoreSqlite(array $c, string $src): void
    {
        $target = $c['database'];

        if ($target === ':memory:') {
            return; // Nothing meaningful to restore to in-memory DB
        }

        $this->exec(
            sprintf('gunzip -c %s > %s 2>&1', escapeshellarg($src), escapeshellarg($target)),
            'sqlite restore',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Execute a shell command and throw a RuntimeException on non-zero exit.
     *
     * @param  string  $cmd    The shell command to run (must use escapeshellarg for all user data)
     * @param  string  $label  Short label used in the error message (e.g. 'mysqldump')
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
     * Check whether a binary is actually executable on this system.
     *
     * @param  string  $binary  Resolved binary path or bare name
     * @return bool
     */
    protected function binaryAvailable(string $binary): bool
    {
        // Absolute path — check directly
        if (str_starts_with($binary, '/')) {
            return file_exists($binary) && is_executable($binary);
        }

        // Bare name — probe via `which` (POSIX) or `where` (Windows)
        exec('which '.escapeshellarg($binary).' 2>/dev/null', $out, $code);

        return $code === 0 && ! empty($out[0]);
    }

    /**
     * Resolve the absolute path for a CLI binary.
     *
     * Resolution order:
     *   1. Explicit path from config('vanguard.binaries.<name>')
     *   2. Auto-detection from common system locations
     *   3. Fall back to the bare binary name (relies on PATH)
     *
     * @param  string  $binary  Binary name: 'mysqldump', 'mysql', 'pg_dump', 'psql'
     * @return string           Absolute path or bare name
     */
    protected function resolveBinary(string $binary): string
    {
        // 1. Explicit config override
        $configured = config("vanguard.binaries.{$binary}");
        if ($configured && file_exists($configured) && is_executable($configured)) {
            return $configured;
        }

        // 2. Auto-detect from common locations
        $commonPaths = [
            "/usr/bin/{$binary}",
            "/usr/local/bin/{$binary}",
            "/usr/mysql/bin/{$binary}",
            "/opt/homebrew/bin/{$binary}",  // macOS Apple Silicon
            "/usr/local/mysql/bin/{$binary}",
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // 3. Bare name — relies on the process PATH
        return $binary;
    }
}
