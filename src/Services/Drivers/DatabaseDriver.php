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

    protected function dumpMysql(array $c, string $dest): void
    {
        $this->setMysqlPasswordEnv($c);

        $cmd = sprintf(
            'mysqldump %s %s 2>&1 | gzip > %s',
            $this->mysqlConnectionArgs($c),
            escapeshellarg($c['database']),
            escapeshellarg($dest),
        );

        $this->exec($cmd, 'mysqldump');
    }

    protected function restoreMysql(array $c, string $src): void
    {
        $this->setMysqlPasswordEnv($c);

        $cmd = sprintf(
            'gunzip -c %s | mysql %s %s 2>&1',
            escapeshellarg($src),
            $this->mysqlConnectionArgs($c),
            escapeshellarg($c['database']),
        );

        $this->exec($cmd, 'mysql restore');
    }

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

    protected function setMysqlPasswordEnv(array $c): void
    {
        if (! empty($c['password'])) {
            putenv("MYSQL_PWD={$c['password']}");
        }
    }

    // ─── PostgreSQL ───────────────────────────────────────────────

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

    protected function pgPasswordEnv(array $c): string
    {
        return ! empty($c['password'])
            ? 'PGPASSWORD='.escapeshellarg($c['password'])
            : '';
    }

    // ─── SQLite ───────────────────────────────────────────────────

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
