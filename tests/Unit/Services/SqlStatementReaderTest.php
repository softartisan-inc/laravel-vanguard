<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\SqlStatementReader;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The splitter the PDO restore replays a dump with.
 *
 * Every case here is a way a naive explode(';') corrupts a database without
 * failing: it cuts a statement in half inside a string literal, inside a
 * comment, or inside a trigger body, and the halves either error out or — far
 * worse — execute as something else.
 */
class SqlStatementReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_reader_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function split(string $sql, int $chunkSize = 8192): array
    {
        return iterator_to_array(
            (new SqlStatementReader)->read(str_split($sql, $chunkSize)),
            false,
        );
    }

    #[Test]
    public function it_splits_plain_statements(): void
    {
        $statements = $this->split("SELECT 1;\nSELECT 2;\n");

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    #[Test]
    public function a_semicolon_inside_a_string_literal_does_not_end_the_statement(): void
    {
        $sql = "INSERT INTO `t` VALUES ('a;b','c;d');\nSELECT 2;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertSame("INSERT INTO `t` VALUES ('a;b','c;d')", $statements[0]);
    }

    #[Test]
    public function a_backslash_escaped_quote_does_not_close_the_string(): void
    {
        // What $pdo->quote() produces for  it's; a value
        $sql = "INSERT INTO `t` VALUES ('it\\'s; a value');\nSELECT 2;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("it\\'s; a value", $statements[0]);
    }

    #[Test]
    public function a_doubled_quote_does_not_close_the_string(): void
    {
        $sql = "INSERT INTO `t` VALUES ('it''s; fine');\nSELECT 2;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("it''s; fine", $statements[0]);
    }

    #[Test]
    public function a_trailing_backslash_before_the_closing_quote_is_not_an_escape(): void
    {
        // The value is a single backslash: '\\' — the second backslash is the
        // escaped one, so the quote that follows really does close the string.
        $sql = "INSERT INTO `t` VALUES ('\\\\');\nSELECT 2;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('SELECT 2', $statements[1]);
    }

    #[Test]
    public function semicolons_inside_comments_do_not_end_the_statement(): void
    {
        $sql = "-- a comment; with a semicolon\n"
            ."# another; one\n"
            ."/* block; comment */\n"
            ."SELECT 1;\n";

        $statements = $this->split($sql);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('SELECT 1', $statements[0]);
    }

    #[Test]
    public function a_double_dash_without_whitespace_is_not_a_comment(): void
    {
        // MySQL requires whitespace after -- ; 5--1 is arithmetic.
        $statements = $this->split("SELECT 5--1;\nSELECT 2;\n");

        $this->assertSame(['SELECT 5--1', 'SELECT 2'], $statements);
    }

    #[Test]
    public function a_semicolon_inside_a_backquoted_identifier_does_not_end_the_statement(): void
    {
        $sql = "CREATE TABLE `weird;name` (`id` int);\nSELECT 2;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE `weird;name` (`id` int)', $statements[0]);
    }

    #[Test]
    public function it_honours_delimiter_blocks(): void
    {
        // Exactly the shape mysqldump --triggers writes.
        $sql = "DELIMITER ;;\n"
            ."/*!50003 CREATE TRIGGER `t_bi` BEFORE INSERT ON `t` FOR EACH ROW BEGIN\n"
            ."  SET NEW.a = 1;\n"
            ."  SET NEW.b = 2;\n"
            ."END */;;\n"
            ."DELIMITER ;\n"
            ."SELECT 1;\n";

        $statements = $this->split($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('CREATE TRIGGER', $statements[0]);
        $this->assertStringContainsString('SET NEW.b = 2;', $statements[0]);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    #[Test]
    public function a_versioned_comment_is_kept_verbatim_so_the_server_decides(): void
    {
        $sql = "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";

        $statements = $this->split($sql);

        $this->assertCount(1, $statements);
        $this->assertSame("/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */", $statements[0]);
    }

    #[Test]
    public function a_statement_without_a_trailing_delimiter_is_still_returned(): void
    {
        $statements = $this->split("SELECT 1;\nSELECT 2");

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    #[Test]
    public function trailing_comments_alone_do_not_produce_an_empty_statement(): void
    {
        // mysqldump ends its output with "-- Dump completed on ...". Sent to
        // the server on its own that is error 1065, "Query was empty".
        $statements = $this->split("SELECT 1;\n\n-- Dump completed on 2026-08-16 10:00:00\n");

        $this->assertSame(['SELECT 1'], $statements);
    }

    #[Test]
    public function empty_statements_between_delimiters_are_skipped(): void
    {
        $statements = $this->split("SELECT 1;;;\n;\nSELECT 2;\n");

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    #[Test]
    public function the_split_is_identical_whatever_the_chunk_boundaries_are(): void
    {
        $sql = "-- header; comment\n"
            ."/*!40101 SET NAMES utf8mb4 */;\n"
            ."DELIMITER ;;\n"
            ."CREATE TRIGGER `x` BEGIN SET a = 1; END;;\n"
            ."DELIMITER ;\n"
            ."INSERT INTO `t` VALUES ('a;b','it\\'s'),(NULL,'/* not a comment */');\n"
            ."/* multi\nline; comment */\n"
            ."SELECT 5--1;\n";

        $reference = $this->split($sql, 8192);

        // One byte at a time: every state transition now straddles a chunk.
        $this->assertSame($reference, $this->split($sql, 1), 'byte-by-byte feeding must not change the split');
        $this->assertSame($reference, $this->split($sql, 3));
        $this->assertSame($reference, $this->split($sql, 7));
        $this->assertSame($reference, $this->split($sql, 64));
    }

    // ─── Reading from a gzipped dump ─────────────────────────────

    #[Test]
    public function it_reads_statements_from_a_gzipped_dump(): void
    {
        $path = $this->tmpDir.'/dump.sql.gz';
        file_put_contents($path, gzencode("SELECT 1;\nSELECT 2;\n"));

        $statements = iterator_to_array(
            (new SqlStatementReader)->read(SqlStatementReader::gzipChunks($path)),
            false,
        );

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    #[Test]
    public function it_refuses_a_dump_it_cannot_open(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array(SqlStatementReader::gzipChunks($this->tmpDir.'/absent.sql.gz'), false);
    }

    #[Test]
    public function it_streams_a_large_dump_instead_of_slurping_it(): void
    {
        // A landlord dump is gigabytes; file_get_contents() on one takes the
        // process down. 32 MB uncompressed here is enough to make the
        // difference between streaming and slurping obvious in peak memory.
        $path = $this->tmpDir.'/large.sql.gz';
        $handle = gzopen($path, 'wb1');
        $row = str_repeat('x', 900);

        for ($i = 0; $i < 32000; $i++) {
            gzwrite($handle, "INSERT INTO `t` VALUES ({$i},'{$row}');\n");
        }
        gzclose($handle);

        $this->assertGreaterThan(28 * 1024 * 1024, 32000 * 940, 'the fixture must be large enough to matter');

        gc_collect_cycles();
        $before = memory_get_usage(true);
        $peak = $before;
        $count = 0;

        foreach ((new SqlStatementReader)->read(SqlStatementReader::gzipChunks($path)) as $statement) {
            $count++;
            $peak = max($peak, memory_get_usage(true));
            unset($statement);
        }

        $this->assertSame(32000, $count);
        $this->assertLessThan(
            8 * 1024 * 1024,
            $peak - $before,
            'the reader must hold a window of the dump, not the whole of it',
        );
    }
}
