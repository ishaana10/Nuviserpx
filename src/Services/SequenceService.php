<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Document numbers that cannot repeat.
 *
 * The old way was "try 00001, 00002, ... until a free one" which is slow on a busy ledger and lets two
 * people saving at the same moment take the same number. Here each company has a counter row per series
 * (journals are one series per month). Taking the next number is a single UPDATE on that row, so MySQL's
 * row lock makes concurrent callers queue; inside a transaction, a rollback also gives the number back.
 */
class SequenceService
{
    /** "202611-00042" from a month prefix and a number. */
    public static function format(string $prefix, int $n): string
    {
        return $prefix . sprintf('%05d', $n);
    }

    /** The numeric part of a code made by format(), or 0 for anything else. */
    public static function parse(string $prefix, string $code): int
    {
        if (strncmp($code, $prefix, strlen($prefix)) !== 0) {
            return 0;
        }
        $tail = substr($code, strlen($prefix));
        return ctype_digit($tail) ? (int)$tail : 0;
    }

    /** Next journal code for the company in the current month, e.g. 202611-00042. */
    public static function journalCode(mysqli $conn, int $tenantId, ?string $month = null): string
    {
        $prefix = ($month ?? date('Ym')) . '-';
        $key = 'journal-' . rtrim($prefix, '-');
        $n = self::next($conn, $tenantId, $key, function () use ($conn, $tenantId, $prefix): int {
            $like = $prefix . '%';
            $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) n FROM journal_entries WHERE tenant_id = ? AND code LIKE ?");
            $from = strlen($prefix) + 1;
            $stmt->bind_param('iis', $from, $tenantId, $like);
            $stmt->execute();
            return (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        });
        $code = self::format($prefix, $n);

        // A hand-typed or imported code may already hold this number: step past it rather than collide.
        $chk = $conn->prepare("SELECT 1 FROM journal_entries WHERE tenant_id = ? AND code = ? LIMIT 1");
        for ($i = 0; $i < 50; $i++) {
            $chk->bind_param('is', $tenantId, $code);
            $chk->execute();
            if (!$chk->get_result()->num_rows) {
                break;
            }
            $n = self::next($conn, $tenantId, $key, fn () => 0);
            $code = self::format($prefix, $n);
        }
        return $code;
    }

    /**
     * Next number for a sales document in a month, e.g. "INV-202611-0042": continues from the highest number
     * already used with that prefix, takes it under the row lock, and steps past any number already in use.
     * $table and $column are fixed names chosen by the caller, never user input.
     */
    public static function documentNo(mysqli $conn, int $tenantId, string $table, string $column, string $prefix, string $key): string
    {
        $n = self::next($conn, $tenantId, $key, function () use ($conn, $tenantId, $table, $column, $prefix): int {
            $like = $prefix . '%';
            $from = strlen($prefix) + 1;
            $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(`{$column}`, ?) AS UNSIGNED)) n FROM `{$table}` WHERE tenant_id = ? AND `{$column}` LIKE ?");
            $stmt->bind_param('iis', $from, $tenantId, $like);
            $stmt->execute();
            return (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        });
        $no = self::formatDoc($prefix, $n);
        $chk = $conn->prepare("SELECT 1 FROM `{$table}` WHERE tenant_id = ? AND `{$column}` = ? LIMIT 1");
        for ($i = 0; $i < 50; $i++) {
            $chk->bind_param('is', $tenantId, $no);
            $chk->execute();
            if (!$chk->get_result()->num_rows) {
                break;
            }
            $no = self::formatDoc($prefix, self::next($conn, $tenantId, $key, fn () => 0));
        }
        return $no;
    }

    /** True when another document in the company already uses this number. $table and $column are fixed names from the caller. */
    public static function numberTaken(mysqli $conn, int $tenantId, string $table, string $column, string $number, int $exceptId = 0): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM `{$table}` WHERE tenant_id = ? AND `{$column}` = ? AND id <> ? LIMIT 1");
        $stmt->bind_param('isi', $tenantId, $number, $exceptId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /** "INV-202611-0042" from a prefix and a number (at least four digits). */
    public static function formatDoc(string $prefix, int $n): string
    {
        return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Take the next number in a series. $seed gives the highest number already in use, asked for only the
     * first time the series is seen, so existing documents are continued rather than overlapped.
     */
    public static function next(mysqli $conn, int $tenantId, string $key, callable $seed): int
    {
        self::ensureTable($conn);
        $ins = $conn->prepare("INSERT IGNORE INTO document_sequences (tenant_id, seq_key, last_value) VALUES (?, ?, ?)");
        $exists = $conn->prepare("SELECT 1 FROM document_sequences WHERE tenant_id = ? AND seq_key = ?");
        $up = $conn->prepare("UPDATE document_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE tenant_id = ? AND seq_key = ?");
        if ($ins === false || $exists === false || $up === false) {
            // Prepared statements are failing on this table (seen on one host
            // with an empty error string): the plain-query path does the same
            // job, and reports errno and error if even that cannot run.
            return self::nextViaQuery($conn, $tenantId, $key, $seed);
        }
        $exists->bind_param('is', $tenantId, $key);
        $exists->execute();
        if (!$exists->get_result()->num_rows) {
            $start = (int)$seed();
            $ins->bind_param('isi', $tenantId, $key, $start);
            $ins->execute();
        }
        $up->bind_param('is', $tenantId, $key);
        $up->execute();
        return (int)$conn->insert_id;
    }

    /** Create the sequences table if it is missing; errors are reported by whatever runs next. */
    private static function ensureTable(mysqli $conn): void
    {
        $r = @$conn->query("SELECT 1 FROM document_sequences LIMIT 1");
        if ($r !== false) {
            return;
        }
        @$conn->query("CREATE TABLE IF NOT EXISTS `document_sequences` (
            `tenant_id` int(30) NOT NULL,
            `seq_key` varchar(60) NOT NULL,
            `last_value` bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY (`tenant_id`, `seq_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** The same take-a-number, in plain escaped SQL. $key is internal, never user input. */
    private static function nextViaQuery(mysqli $conn, int $tenantId, string $key, callable $seed): int
    {
        $k = $conn->real_escape_string($key);
        $r = $conn->query("SELECT 1 FROM document_sequences WHERE tenant_id = {$tenantId} AND seq_key = '{$k}' LIMIT 1");
        if ($r === false) {
            throw new \RuntimeException("The document_sequences table is unavailable ([{$conn->errno}] {$conn->error}). Run the repair migration or create the table, then try again.");
        }
        if (!$r->num_rows) {
            $start = (int)$seed();
            $conn->query("INSERT IGNORE INTO document_sequences (tenant_id, seq_key, last_value) VALUES ({$tenantId}, '{$k}', {$start})");
        }
        if ($conn->query("UPDATE document_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE tenant_id = {$tenantId} AND seq_key = '{$k}'") === false) {
            throw new \RuntimeException("The document sequence could not be advanced ([{$conn->errno}] {$conn->error}).");
        }
        return (int)$conn->insert_id;
    }
}
