<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;

/**
 * Builds a complete schema on an empty database, the same way every time, or fails loudly.
 *
 * Order: base dump -> legacy catch-up -> migrations -> check that the tables the application cannot run without exist.
 * (Before this existed the installer ran the dump with multi_query, which silently stops at the first error, and the
 * rest of the schema only appeared bit by bit as later requests ran the catch-up code.)
 */
final class SchemaInstaller
{
    /** Tables whose absence means the install is unusable. */
    public const REQUIRED_TABLES = [
        'users', 'tenants', 'system_info', 'account_list', 'group_list', 'journal_entries', 'journal_items',
        'party_list', 'product_list', 'invoice_list', 'invoice_items', 'payment_list', 'sales_order_list',
        'employee_list', 'payroll_list', 'leave_list', 'attendance_list', 'schema_migrations', 'audit_logs', 'document_sequences',
    ];

    /**
     * @return array{statements:int,migrations:int}
     * @throws \RuntimeException with the failing statement or the missing tables
     */
    public static function install(mysqli $conn, string $dumpFile, string $migrationsDir): array
    {
        if (!is_file($dumpFile)) {
            throw new \RuntimeException('Schema file not found: ' . basename($dumpFile));
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        // The dump creates tables first and adds foreign keys last; checks are off while it loads.
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $n = 0;
        foreach (MigrationRunner::splitStatements((string)file_get_contents($dumpFile)) as $st) {
            $conn->query($st);
            $n++;
            if ($conn->errno !== 0 && !MigrationRunner::isRepeatable($st, $conn->errno)) {
                $e = $conn->error;
                $no = $conn->errno;
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                throw new \RuntimeException("Base schema failed (MySQL error {$no}: {$e}) at: " . mb_substr((string)preg_replace('/\s+/', ' ', $st), 0, 200));
            }
        }
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');

        (new LegacySchemaCatchup($conn))->run();
        $applied = (new MigrationRunner($conn))->run($migrationsDir);

        $missing = self::missingTables($conn, self::REQUIRED_TABLES);
        if ($missing) {
            throw new \RuntimeException('The install is incomplete. Missing tables: ' . implode(', ', $missing));
        }
        return ['statements' => $n, 'migrations' => count($applied)];
    }

    /** @param string[] $tables @return string[] those not present in the current database */
    public static function missingTables(mysqli $conn, array $tables): array
    {
        $have = [];
        if ($r = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")) {
            while ($row = $r->fetch_row()) {
                $have[strtolower((string)$row[0])] = true;
            }
        }
        return array_values(array_filter($tables, fn($t) => !isset($have[strtolower($t)])));
    }
}
