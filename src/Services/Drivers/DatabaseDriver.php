<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use RuntimeException;

class DatabaseDriver
{
    /**
     * Fallback mysqldump options, used when the published config predates the
     * 'dump' section and therefore has no 'mysql_options' key.
     */
    protected const DEFAULT_MYSQL_DUMP_OPTIONS = '--single-transaction --quick --routines --triggers --no-tablespaces';

    /** Maximum number of rows grouped into a single INSERT by the PDO fallback. */
    protected const PDO_INSERT_MAX_ROWS = 100;

    /** Maximum byte size of the values of a single INSERT built by the PDO fallback. */
    protected const PDO_INSERT_MAX_BYTES = 1048576; // 1 MB

    /**
     * Dump a database to a gzipped SQL file.
     *
     * @param  string  $driver  'mysql'|'mariadb'|'pgsql'|'sqlite'
     * @param  array  $config  Laravel DB connection config
     * @param  string  $destination  Absolute path for output (.sql.gz)
     * @return string Path to the created dump file
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
            'pgsql' => $this->dumpPgsql($config, $destination),
            'sqlite' => $this->dumpSqlite($config, $destination),
            default => throw new RuntimeException("Unsupported DB driver: [{$driver}]"),
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
     * @param  array  $config  Laravel DB connection config
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
            'pgsql' => $this->restorePgsql($config, $source),
            'sqlite' => $this->restoreSqlite($config, $source),
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
     * @param  array  $c  Laravel MySQL connection config
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
     * mysqldump is started directly (no shell pipeline), so the exit code we
     * check is mysqldump's own and not gzip's. Its stdout is compressed on the
     * fly, its stderr is kept aside and only ever surfaces in the exception —
     * never inside the archive.
     *
     * The password travels in a 0600 defaults file, removed in the finally
     * block whatever the dump did — see writeMysqlDefaultsFile().
     */
    protected function dumpMysqlViaCli(array $c, string $dest, string $binary): void
    {
        $defaultsFile = $this->writeMysqlDefaultsFile($c);

        try {
            $this->runProcessToGzip($this->mysqlDumpCommand($binary, $c, $defaultsFile), $dest, 'mysqldump');
        } finally {
            $this->removeMysqlDefaultsFile($defaultsFile);
        }
    }

    /**
     * Build the mysqldump command as an argument list (no shell involved).
     *
     * The password is intentionally absent from the arguments: --password= is
     * readable by every user of the machine through ps. It is handed over in a
     * defaults file instead, and that option has to come first — mysqldump
     * refuses it anywhere else ("unknown variable 'defaults-extra-file=…'") and
     * then dies on the next option it would otherwise have understood.
     *
     * @param  string  $binary  Resolved mysqldump path or bare name
     * @param  array  $c  Laravel MySQL connection config
     * @param  string|null  $defaultsFile  Path to the temporary credential file, if any
     * @return array<int, string>
     */
    protected function mysqlDumpCommand(string $binary, array $c, ?string $defaultsFile = null): array
    {
        $command = [$binary];

        if ($defaultsFile !== null) {
            $command[] = '--defaults-extra-file='.$defaultsFile;
        }

        array_push(
            $command,
            '-h', (string) ($c['host'] ?? '127.0.0.1'),
            '-P', (string) ($c['port'] ?? 3306),
            '-u', (string) ($c['username'] ?? 'root'),
        );

        if (! empty($c['unix_socket'])) {
            $command[] = '--socket='.$c['unix_socket'];
        }

        foreach ($this->mysqlDumpOptions() as $option) {
            $command[] = $option;
        }

        $command[] = (string) $c['database'];

        return $command;
    }

    /**
     * Resolve the configured mysqldump options.
     *
     * Accepts either a space-separated string or an array in
     * config('vanguard.dump.mysql_options').
     *
     * @return array<int, string>
     */
    protected function mysqlDumpOptions(): array
    {
        $configured = config('vanguard.dump.mysql_options', self::DEFAULT_MYSQL_DUMP_OPTIONS)
            ?? self::DEFAULT_MYSQL_DUMP_OPTIONS;

        if (is_string($configured)) {
            $configured = preg_split('/\s+/', trim($configured)) ?: [];
        }

        return array_values(array_filter(
            array_map(fn ($option) => trim((string) $option), (array) $configured),
            fn (string $option) => $option !== '',
        ));
    }

    /**
     * Run a dump command and stream its stdout into a gzip file.
     *
     * stdout and stderr are read from separate pipes, so an error message can
     * never end up inside the archive. The exit code is the command's own —
     * there is no shell pipeline to mask it. On failure the partial
     * destination file is removed so no corrupt backup survives.
     *
     * @param  array<int, string>  $command  Argument list, binary first
     * @param  string  $dest  Absolute destination path (.sql.gz)
     * @param  string  $label  Short label used in error messages
     *
     * @throws RuntimeException When the process cannot start, the destination
     *                          stops accepting data, or the command exits non-zero
     */
    protected function runProcessToGzip(array $command, string $dest, string $label): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $writer = GzipDumpWriter::open($dest, $label);

        $process = @proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            $writer->discard();

            throw new RuntimeException("[Vanguard:{$label}] Unable to start the process.");
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);

                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        unset($open[array_search($stream, $open, true)]);
                        fclose($stream);
                    }

                    continue;
                }

                if ($stream === $pipes[1]) {
                    try {
                        $writer->write($chunk);
                    } catch (RuntimeException $e) {
                        proc_close($process);

                        throw $e;
                    }
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }

        try {
            $writer->close();
        } catch (RuntimeException $e) {
            proc_close($process);

            throw $e;
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $writer->discard();

            throw new RuntimeException(
                "[Vanguard:{$label}] Command failed (exit {$exitCode}):\n".trim($stderr)
            );
        }
    }

    /**
     * Dump MySQL via PDO — no binary required.
     *
     * Exports schema (CREATE TABLE) and data (INSERT) for every table.
     * Output is written directly to a gzip stream, avoiding large temp files.
     *
     * @param  array  $c  Laravel MySQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     *
     * @throws RuntimeException On PDO errors, or when the destination stops accepting data
     */
    protected function dumpMysqlViaPdo(array $c, string $dest): void
    {
        $pdo = $this->createMysqlPdo($c);

        $writer = GzipDumpWriter::open($dest, 'mysqldump-pdo');

        try {
            $db = $c['database'];

            $writer->write("-- Vanguard MySQL dump (PDO fallback)\n");
            $writer->write("-- Database: {$db}\n");
            $writer->write('-- Generated: '.now()->toIso8601String()."\n\n");
            $writer->write("SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            // Read every schema up front: while the connection is unbuffered no
            // other query may run until the open result set is fully consumed.
            $schemas = [];
            foreach ($tables as $table) {
                $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $schemas[$table] = $create['Create Table'];
            }

            // PDO MySQL buffers results by default, so "SELECT * FROM table"
            // would pull the whole table into PHP memory before the first row
            // is written. Unbuffered mode makes the read a real stream.
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            try {
                foreach ($tables as $table) {
                    $writer->write("DROP TABLE IF EXISTS `{$table}`;\n");
                    $writer->write($schemas[$table].";\n\n");

                    $this->writeTableDataViaPdo($pdo, $writer, $table);
                }
            } finally {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

            $writer->write("SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) {
            // The write failures already removed their partial file; anything
            // else (a PDO error) only needs the handle released, exactly as the
            // previous gzclose() in a finally block did.
            $writer->abort();

            throw $e;
        }

        $writer->close();
    }

    /**
     * Open the PDO connection used by the MySQL fallback dump.
     *
     * @param  array  $c  Laravel MySQL connection config
     */
    protected function createMysqlPdo(array $c): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $c['host'] ?? '127.0.0.1',
            $c['port'] ?? 3306,
            $c['database'],
            $c['charset'] ?? 'utf8mb4',
        );

        return new \PDO($dsn, $c['username'] ?? 'root', $c['password'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Stream one table's rows into the gzip handle as multi-row INSERTs.
     *
     * Rows are grouped into batches so the dump stays small and replays much
     * faster than one statement per row.
     *
     * @param  \PDO  $pdo  Connection, already switched to unbuffered reads
     * @param  GzipDumpWriter  $writer  Open dump writer
     * @param  string  $table  Table name
     */
    protected function writeTableDataViaPdo(\PDO $pdo, GzipDumpWriter $writer, string $table): void
    {
        $statement = $pdo->query("SELECT * FROM `{$table}`");

        $batch = [];
        $batchBytes = 0;
        $wroteAny = false;

        foreach ($statement as $row) {
            $values = array_map(
                fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                $row,
            );

            $tuple = '('.implode(',', $values).')';
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);

            if (count($batch) >= self::PDO_INSERT_MAX_ROWS || $batchBytes >= self::PDO_INSERT_MAX_BYTES) {
                $writer->write("INSERT INTO `{$table}` VALUES ".implode(',', $batch).";\n");
                $batch = [];
                $batchBytes = 0;
                $wroteAny = true;
            }
        }

        if ($batch !== []) {
            $writer->write("INSERT INTO `{$table}` VALUES ".implode(',', $batch).";\n");
            $wroteAny = true;
        }

        $statement->closeCursor();

        if ($wroteAny) {
            $writer->write("\n");
        }
    }

    /**
     * Restore a MySQL/MariaDB database from a gzipped SQL dump.
     *
     * The password travels in a 0600 defaults file, removed in the finally
     * block whatever the restore did — see writeMysqlDefaultsFile().
     *
     * @param  array  $c  Laravel MySQL connection config
     * @param  string  $src  Absolute path to the .sql.gz dump file
     */
    protected function restoreMysql(array $c, string $src): void
    {
        $defaultsFile = $this->writeMysqlDefaultsFile($c);

        try {
            $cmd = sprintf(
                'gunzip -c %s | %s %s %s 2>&1',
                escapeshellarg($src),
                escapeshellcmd($this->resolveBinary('mysql')),
                $this->mysqlConnectionArgs($c, $defaultsFile),
                escapeshellarg($c['database']),
            );

            $this->exec($cmd, 'mysql restore');
        } finally {
            $this->removeMysqlDefaultsFile($defaultsFile);
        }
    }

    /**
     * Build the common MySQL connection arguments string (host, port, user, socket, flags).
     *
     * The password is intentionally omitted here: it reaches the client through
     * the defaults file, whose option leads the list because the client rejects
     * --defaults-extra-file in any other position.
     *
     * @param  array  $c  Laravel MySQL connection config
     * @param  string|null  $defaultsFile  Path to the temporary credential file, if any
     * @return string Shell-safe argument string
     */
    protected function mysqlConnectionArgs(array $c, ?string $defaultsFile = null): string
    {
        $args = $defaultsFile !== null
            ? '--defaults-extra-file='.escapeshellarg($defaultsFile).' '
            : '';

        $args .= sprintf(
            '-h %s -P %s -u %s',
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($c['port'] ?? 3306)),
            escapeshellarg($c['username'] ?? 'root'),
        );

        if (! empty($c['unix_socket'])) {
            $args .= ' --socket='.escapeshellarg($c['unix_socket']);
        }

        // Connection only. Dump flags belong to mysqlDumpOptions(): the mysql
        // client rejects --single-transaction, lock-tables and set-gtid-purged
        // as unknown options and exits before reading a single statement, so
        // adding them here made every CLI restore fail.
        return $args;
    }

    /**
     * Write the password into a temporary defaults file the client will read.
     *
     * The credential used to travel in MYSQL_PWD. The environment of a running
     * process is world-readable to anything running as the same user — a second
     * PHP-FPM pool, a stray shell, any compromised dependency — through
     * /proc/<pid>/environ, for as long as the dump lasts; and a fatal signal,
     * which skips every finally block, left it set for every child started
     * afterwards. A file can be made unreadable to everyone else, which an
     * environment variable cannot.
     *
     * The mode is set before the secret is written, not after: tempnam() already
     * creates the file 0600 on the platforms we support, but a window in which
     * the credential sits in a readable file is exactly the defect being
     * removed, so it is closed explicitly rather than assumed shut.
     *
     * Returns null when the connection has no password, so no file is created
     * and no --defaults-extra-file option is passed at all.
     *
     * @param  array  $c  Laravel MySQL connection config
     * @return string|null Absolute path to the file, or null when there is no password
     *
     * @throws RuntimeException When the file cannot be created or written
     */
    protected function writeMysqlDefaultsFile(array $c): ?string
    {
        if (empty($c['password'])) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'vanguard-mysql-');

        if ($path === false) {
            throw new RuntimeException('[Vanguard:mysql] Unable to create the temporary credentials file.');
        }

        if (! chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException('[Vanguard:mysql] Unable to restrict the temporary credentials file.');
        }

        $contents = "[client]\npassword=".$this->quoteMysqlOptionValue((string) $c['password'])."\n";

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw new RuntimeException('[Vanguard:mysql] Unable to write the temporary credentials file.');
        }

        return $path;
    }

    /**
     * Quote a value for the ini-style option file mysql/mysqldump parse.
     *
     * Unquoted, that syntax eats passwords: a # starts a comment, and leading
     * and trailing spaces are stripped. Both produce the same failure — the
     * client authenticates with a truncated password and the backup dies with
     * "Access denied", which reads exactly like a wrong password and has cost
     * whole nights of backups elsewhere.
     *
     * So the value is always wrapped in double quotes, which makes #, spaces
     * and single quotes ordinary data. Inside those quotes the parser
     * recognises backslash escapes, so the backslash itself and the closing
     * quote character — the only two that could end or alter the value — are
     * escaped. Everything else is passed through byte for byte.
     *
     * @param  string  $value  The raw password
     * @return string The quoted, escaped value, quotes included
     */
    protected function quoteMysqlOptionValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Delete the temporary defaults file.
     *
     * Always called from a finally block: a dump that throws must not leave the
     * credential on disk, and the tmp directory is the last place it should be
     * found by whoever goes looking after an incident.
     *
     * @param  string|null  $path  The file to remove, or null when none was written
     */
    protected function removeMysqlDefaultsFile(?string $path): void
    {
        if ($path !== null && file_exists($path)) {
            @unlink($path);
        }
    }

    // ─── PostgreSQL ───────────────────────────────────────────────

    /**
     * Dump a PostgreSQL database to a gzipped SQL file via pg_dump.
     *
     * The password is passed via the PGPASSWORD environment variable prefix.
     *
     * @param  array  $c  Laravel PostgreSQL connection config
     * @param  string  $dest  Absolute destination path (.sql.gz)
     */
    protected function dumpPgsql(array $c, string $dest): void
    {
        // Started directly, not through a shell pipeline: piping into gzip
        // meant the exit code checked was gzip's, so a pg_dump that died
        // halfway still produced a "successful" archive — with its error
        // message written inside it by 2>&1. Same defect the MySQL path had.
        $command = [
            $this->resolveBinary('pg_dump'),
            '--format=plain',
            '--no-acl',
            '--no-owner',
            '-h', (string) ($c['host'] ?? '127.0.0.1'),
            '-p', (string) ($c['port'] ?? 5432),
            '-U', (string) $c['username'],
            (string) $c['database'],
        ];

        $this->setPgPasswordEnv($c);

        try {
            $this->runProcessToGzip($command, $dest, 'pg_dump');
        } finally {
            $this->clearPgPasswordEnv();
        }
    }

    /**
     * Restore a PostgreSQL database from a gzipped SQL dump via psql.
     *
     * The password is passed via the PGPASSWORD environment variable prefix.
     *
     * @param  array  $c  Laravel PostgreSQL connection config
     * @param  string  $src  Absolute path to the .sql.gz dump file
     */
    protected function restorePgsql(array $c, string $src): void
    {
        // The password goes into the process environment, not in front of the
        // command: in `PGPASSWORD=x gunzip -c file | psql`, the shell gives the
        // variable to gunzip only, so psql prompted for a password and the
        // restore died with "password authentication failed".
        $this->setPgPasswordEnv($c);

        try {
            $cmd = sprintf(
                'gunzip -c %s | %s -h %s -p %s -U %s -d %s 2>&1',
                escapeshellarg($src),
                escapeshellcmd($this->resolveBinary('psql')),
                escapeshellarg($c['host'] ?? '127.0.0.1'),
                escapeshellarg((string) ($c['port'] ?? 5432)),
                escapeshellarg($c['username']),
                escapeshellarg($c['database']),
            );

            $this->exec($cmd, 'psql restore');
        } finally {
            $this->clearPgPasswordEnv();
        }
    }

    /**
     * Build the PGPASSWORD=... environment variable prefix for a PostgreSQL command.
     *
     * Returns an empty string when no password is set so the prefix can be
     * safely prepended to any command without causing syntax errors.
     *
     * @param  array  $c  Laravel PostgreSQL connection config
     * @return string e.g. "PGPASSWORD='secret'" or ""
     */
    protected function pgPasswordEnv(array $c): string
    {
        return ! empty($c['password'])
            ? 'PGPASSWORD='.escapeshellarg($c['password'])
            : '';
    }

    /**
     * Put PGPASSWORD in the process environment for a PostgreSQL command.
     *
     * Both pg_dump and psql read it from the environment they inherit, which
     * is what makes it work across a pipeline and keeps the credential out of
     * the process list.
     */
    protected function setPgPasswordEnv(array $c): void
    {
        if (! empty($c['password'])) {
            putenv("PGPASSWORD={$c['password']}");
        }
    }

    /**
     * Remove PGPASSWORD from the process environment.
     *
     * Always called in a finally block so the credential does not persist
     * across subsequent jobs in a long-running worker.
     */
    protected function clearPgPasswordEnv(): void
    {
        putenv('PGPASSWORD');
    }

    // ─── SQLite ───────────────────────────────────────────────────

    /**
     * Dump a SQLite database to a gzipped file.
     *
     * For in-memory databases (used in tests), the schema is exported via PDO
     * and written directly to a gzip stream. For file-based databases, gzip
     * compresses the file directly for speed.
     *
     * @param  array  $c  Laravel SQLite connection config
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
            foreach ($pdo->query('SELECT sql FROM sqlite_master WHERE sql IS NOT NULL') as $row) {
                $sql .= $row['sql'].";\n";
            }
            // The writer cleans up its own partial file when a write fails.
            $writer = GzipDumpWriter::open($dest, 'sqlite');
            $writer->write($sql ?: "-- empty database\n");
            $writer->close();

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
     * @param  array  $c  Laravel SQLite connection config
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
     * @param  string  $cmd  The shell command to run (must use escapeshellarg for all user data)
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
     * @return string Absolute path or bare name
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
