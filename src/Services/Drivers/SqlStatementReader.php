<?php

namespace SoftArtisan\Vanguard\Services\Drivers;

use Generator;
use RuntimeException;

/**
 * Cuts a SQL dump into statements, without ever holding the whole dump.
 *
 * The mysql client does this splitting for the CLI restore path; when the
 * client is missing, the PDO path has to do it itself, and explode(';') is not
 * a substitute. A semicolon lives inside string values, inside comments, and
 * inside trigger and routine bodies, and a cut in the wrong place does not
 * announce itself: the two halves either raise a syntax error halfway through
 * a restore, or — worse — execute as something else.
 *
 * What it is built to read: the archives this package writes.
 *   - Its own PDO dump (dumpMysqlViaPdo): '--' header comments, SET
 *     FOREIGN_KEY_CHECKS, DROP/CREATE TABLE straight from SHOW CREATE TABLE
 *     (multi-line, backquoted identifiers, DEFAULT and COMMENT strings that may
 *     contain quotes and semicolons), and multi-row INSERTs whose values are
 *     escaped by PDO::quote() — that is, backslash escapes.
 *   - mysqldump output, since the same shelf of archives can hold files written
 *     on a host that had the binary: '/*!… *\/' versioned comments, LOCK/UNLOCK
 *     TABLES, and — because --routines --triggers is in the default dump
 *     options — DELIMITER ;; blocks whose bodies are full of semicolons.
 *
 * So it tracks: single- and double-quoted strings (backslash escapes and
 * doubled quotes), backquoted identifiers, '#' and '-- ' line comments,
 * block comments, and the DELIMITER directive. Versioned comments are kept
 * verbatim in the statement and handed to the server, which is the only thing
 * that knows whether its version should run them.
 *
 * Everything is scanned with strcspn()/strpos() over a sliding buffer rather
 * than character by character: a landlord dump is gigabytes, and per-byte PHP
 * would turn a restore into an afternoon.
 */
final class SqlStatementReader
{
    private const CODE = 0;

    private const SINGLE_QUOTE = 1;

    private const DOUBLE_QUOTE = 2;

    private const BACKQUOTE = 3;

    private const LINE_COMMENT = 4;

    private const BLOCK_COMMENT = 5;

    /** Bytes pulled from the gzip stream per read. */
    public const CHUNK_BYTES = 262144;

    /** Unemitted text: the statement being built, plus whatever follows it in the last chunk. */
    private string $buffer = '';

    /** Where the current statement starts inside the buffer. */
    private int $start = 0;

    /** How far the scanner has got inside the buffer. */
    private int $offset = 0;

    private int $state = self::CODE;

    private string $delimiter = ';';

    /** Whether anything executable has been seen since the last statement was emitted. */
    private bool $hasCode = false;

    /**
     * Split a stream of chunks into statements, delimiters stripped.
     *
     * @param  iterable<string>  $chunks  Raw SQL, in any chunking
     * @return Generator<int, string>
     */
    public function read(iterable $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            // Compact first: the buffer only ever needs the current statement
            // and what follows it, so everything already emitted is dropped
            // here rather than on every statement, which would copy the tail
            // of the buffer once per INSERT.
            if ($this->start > 0) {
                $this->buffer = substr($this->buffer, $this->start);
                $this->offset -= $this->start;
                $this->start = 0;
            }

            $this->buffer .= $chunk;

            yield from $this->consume(false);
        }

        yield from $this->consume(true);

        // A dump may end on a statement with no trailing delimiter.
        if ($this->hasCode) {
            $tail = trim(substr($this->buffer, $this->start));

            if ($tail !== '') {
                yield $tail;
            }
        }
    }

    /**
     * Open a gzipped dump and yield it in chunks.
     *
     * @return Generator<int, string>
     *
     * @throws RuntimeException When the archive cannot be opened or read
     */
    public static function gzipChunks(string $path, int $size = self::CHUNK_BYTES): Generator
    {
        // zlib reads a file that is not gzipped straight through, so an archive
        // holding something else entirely — an error page, a truncated
        // transfer, the wrong file — would be replayed as SQL statement by
        // statement into a live database. gunzip, which the client path pipes
        // through, refuses it outright; so does this.
        $magic = @file_get_contents($path, false, null, 0, 2);

        if ($magic === false) {
            throw new RuntimeException("[Vanguard] Cannot open the dump archive: {$path}");
        }

        if ($magic !== "\x1f\x8b") {
            throw new RuntimeException("[Vanguard] The dump archive is not a gzip stream: {$path}");
        }

        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("[Vanguard] Cannot open the dump archive: {$path}");
        }

        try {
            while (! gzeof($handle)) {
                $chunk = @gzread($handle, $size);

                if ($chunk === false) {
                    throw new RuntimeException("[Vanguard] The dump archive could not be read (truncated or corrupt): {$path}");
                }

                if ($chunk === '') {
                    break;
                }

                yield $chunk;
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Scan as far as the buffer allows, emitting every complete statement.
     *
     * Returns as soon as a decision would need bytes that have not arrived yet,
     * unless this is the final pass — then what is there is all there will be.
     *
     * @return Generator<int, string>
     */
    private function consume(bool $final): Generator
    {
        $length = strlen($this->buffer);

        while (true) {
            switch ($this->state) {
                case self::CODE:
                    // At the start of a statement, and only there: drop leading
                    // whitespace and look for a DELIMITER directive. It is a
                    // client instruction, not SQL — the server would reject it.
                    if ($this->offset === $this->start) {
                        $whitespace = strspn($this->buffer, " \t\r\n", $this->start);

                        if ($whitespace > 0) {
                            $this->start += $whitespace;
                            $this->offset = $this->start;
                        }

                        if ($this->start + 10 > $length && ! $final) {
                            return;
                        }

                        if (strncasecmp(substr($this->buffer, $this->start, 9), 'DELIMITER', 9) === 0
                            && in_array($this->buffer[$this->start + 9] ?? '', [' ', "\t"], true)) {
                            $eol = strpos($this->buffer, "\n", $this->start);

                            if ($eol === false) {
                                if (! $final) {
                                    return;
                                }

                                $eol = $length;
                            }

                            $token = trim(substr($this->buffer, $this->start + 9, $eol - $this->start - 9));

                            if ($token !== '') {
                                $this->delimiter = $token;
                            }

                            $this->start = min($eol + 1, $length);
                            $this->offset = $this->start;

                            continue 2;
                        }
                    }

                    $span = strcspn($this->buffer, "'\"`#-/".$this->delimiter[0], $this->offset);

                    if ($span > 0 && ! $this->hasCode
                        && strspn($this->buffer, " \t\r\n", $this->offset, $span) !== $span) {
                        $this->hasCode = true;
                    }

                    $position = $this->offset + $span;

                    if ($position >= $length) {
                        $this->offset = $position;

                        return;
                    }

                    $character = $this->buffer[$position];

                    if ($character === "'" || $character === '"' || $character === '`') {
                        $this->state = match ($character) {
                            "'" => self::SINGLE_QUOTE,
                            '"' => self::DOUBLE_QUOTE,
                            default => self::BACKQUOTE,
                        };
                        $this->hasCode = true;
                        $this->offset = $position + 1;

                        continue 2;
                    }

                    if ($character === '#') {
                        $this->state = self::LINE_COMMENT;
                        $this->offset = $position + 1;

                        continue 2;
                    }

                    if ($character === '-') {
                        // MySQL only starts a comment on '--' followed by
                        // whitespace or end of line; 5--1 is arithmetic.
                        if ($position + 3 > $length && ! $final) {
                            $this->offset = $position;

                            return;
                        }

                        $next = $this->buffer[$position + 2] ?? "\n";

                        if (substr($this->buffer, $position, 2) === '--'
                            && in_array($next, [' ', "\t", "\r", "\n"], true)) {
                            $this->state = self::LINE_COMMENT;
                            $this->offset = $position + 2;
                        } else {
                            $this->hasCode = true;
                            $this->offset = $position + 1;
                        }

                        continue 2;
                    }

                    if ($character === '/') {
                        // Three bytes, not two: '/*!' is decided on the third,
                        // and reading it before it has arrived would silently
                        // demote a versioned comment to an ordinary one.
                        if ($position + 3 > $length && ! $final) {
                            $this->offset = $position;

                            return;
                        }

                        if (($this->buffer[$position + 1] ?? '') === '*') {
                            // '/*!' is a versioned comment: the server executes
                            // what is inside it, so it counts as code even
                            // though its semicolons must not split anything.
                            if (($this->buffer[$position + 2] ?? '') === '!') {
                                $this->hasCode = true;
                            }

                            $this->state = self::BLOCK_COMMENT;
                            $this->offset = $position + 2;
                        } else {
                            $this->hasCode = true;
                            $this->offset = $position + 1;
                        }

                        continue 2;
                    }

                    // Candidate delimiter — only its first character matched.
                    $delimiterLength = strlen($this->delimiter);

                    if ($position + $delimiterLength > $length) {
                        if (! $final) {
                            $this->offset = $position;

                            return;
                        }

                        $this->hasCode = true;
                        $this->offset = $position + 1;

                        continue 2;
                    }

                    if (substr_compare($this->buffer, $this->delimiter, $position, $delimiterLength) !== 0) {
                        $this->hasCode = true;
                        $this->offset = $position + 1;

                        continue 2;
                    }

                    $statement = substr($this->buffer, $this->start, $position - $this->start);
                    $this->start = $position + $delimiterLength;
                    $this->offset = $this->start;

                    // Nothing executable in it — a run of delimiters, or the
                    // '-- Dump completed on …' line mysqldump signs off with.
                    // Sent on its own that is error 1065, "Query was empty".
                    if (! $this->hasCode) {
                        continue 2;
                    }

                    $this->hasCode = false;

                    yield trim($statement);

                    continue 2;

                case self::SINGLE_QUOTE:
                case self::DOUBLE_QUOTE:
                    $quote = $this->state === self::SINGLE_QUOTE ? "'" : '"';
                    $position = $this->offset + strcspn($this->buffer, $quote.'\\', $this->offset);

                    if ($position >= $length) {
                        $this->offset = $position;

                        return;
                    }

                    if ($this->buffer[$position] === '\\') {
                        if ($position + 2 > $length) {
                            if (! $final) {
                                $this->offset = $position;

                                return;
                            }

                            $this->offset = $length;

                            return;
                        }

                        $this->offset = $position + 2;

                        continue 2;
                    }

                    // A doubled quote needs no special case: this one closes
                    // the string and the next one opens it again, which splits
                    // the same way.
                    $this->state = self::CODE;
                    $this->offset = $position + 1;

                    continue 2;

                case self::BACKQUOTE:
                    // No backslash escapes inside an identifier: MySQL doubles
                    // the backquote, which closes and reopens as above.
                    $position = strpos($this->buffer, '`', $this->offset);

                    if ($position === false) {
                        $this->offset = $length;

                        return;
                    }

                    $this->state = self::CODE;
                    $this->offset = $position + 1;

                    continue 2;

                case self::LINE_COMMENT:
                    $position = strpos($this->buffer, "\n", $this->offset);

                    if ($position === false) {
                        $this->offset = $length;

                        return;
                    }

                    $this->state = self::CODE;
                    $this->offset = $position + 1;

                    continue 2;

                default:
                    $position = strpos($this->buffer, '*/', $this->offset);

                    if ($position === false) {
                        // Stop one byte short: the '*' of a '*/' straddling
                        // this chunk and the next must still be seen.
                        $this->offset = max($this->offset, $length - 1);

                        return;
                    }

                    $this->state = self::CODE;
                    $this->offset = $position + 2;

                    continue 2;
            }
        }
    }
}
