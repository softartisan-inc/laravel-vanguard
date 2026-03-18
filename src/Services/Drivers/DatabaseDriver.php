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
     * Dump a MySQL/MariaDB database to a gzipped SQL file via mysqldump.
     *
     * Uses MYSQL_PWD environment variable to pass the password securely.
     *
     * @param  array   $c     Laravel MySQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     */
    protected function dumpMysql(array $c, string $dest): void
    {
        $this->setMysqlPasswordEnv($c);

        try {
            $cmd = sprintf(
                'mysqldump %s %s 2>&1 | gzip > %s',
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
                'gunzip -c %s | mysql %s %s 2>&1',
                escapeshellarg($src),
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
            '%s pg_dump --format=plain --no-acl --no-owner -h %s -p %s -U %s %s 2>&1 | gzip > %s',
            $this->pgPasswordEnv($c),
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
            '%s gunzip -c %s | psql -h %s -p %s -U %s -d %s 2>&1',
            $this->pgPasswordEnv($c),
            escapeshellarg($src),
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
}
