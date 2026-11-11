<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;

class MigrationRunner
{
    public function __construct(private readonly mysqli $conn)
    {
    }

    public function run(string $migrationsDir): void
    {
        if (!is_dir($migrationsDir)) {
            return;
        }

        // Create migrations tracking table if missing
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL UNIQUE,
                `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $executed = [];
        $res = $this->conn->query("SELECT `migration` FROM `schema_migrations`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $executed[] = $row['migration'];
            }
        }

        $files = glob($migrationsDir . '/*.sql');
        if (!$files) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $executed, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            foreach (self::splitStatements($sql) as $statement) {
                $this->apply($statement);
            }

            $stmt = $this->conn->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $filename);
                $stmt->execute();
            }
        }

        $this->repairConditionalColumns($files, $executed);
    }

    /** Run one statement. `ADD COLUMN IF NOT EXISTS` is MariaDB-only, so it is done with a column check that works everywhere. */
    private function apply(string $statement): void
    {
        $add = self::parseConditionalAddColumn($statement);
        if ($add === null) {
            @$this->conn->query($statement);
            return;
        }
        if ($this->columnExists($add['table'], $add['column'])) {
            return;
        }
        if (@$this->conn->query($add['sql']) === false && $add['sqlNoPosition'] !== $add['sql']) {
            // AFTER names a column that is not there: add it at the end instead.
            @$this->conn->query($add['sqlNoPosition']);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $q = $this->conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if (!$q) {
            return false;
        }
        $q->bind_param('ss', $table, $column);
        $q->execute();
        return $q->get_result()->num_rows > 0;
    }

    /**
     * Once per database: on MySQL the conditional column additions in migrations that ran earlier were rejected and the
     * migration was still recorded as done. Apply them again now, skipping any column that already exists.
     */
    private function repairConditionalColumns(array $files, array $executed): void
    {
        $marker = 'repair_conditional_columns_v1';
        if (in_array($marker, $executed, true)) {
            return;
        }
        $done = $this->conn->query("SELECT 1 FROM `schema_migrations` WHERE `migration` = '{$marker}'");
        if ($done && $done->num_rows > 0) {
            return;
        }
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            foreach (self::splitStatements($sql) as $statement) {
                if (self::parseConditionalAddColumn($statement) !== null) {
                    $this->apply($statement);
                }
            }
        }
        $stmt = $this->conn->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('s', $marker);
            $stmt->execute();
        }
    }

    /**
     * Pure: for `ALTER TABLE t ADD COLUMN IF NOT EXISTS c ...` return the table, the column, the statement without the
     * IF NOT EXISTS clause, and the same without an AFTER position. Null for any other statement.
     */
    public static function parseConditionalAddColumn(string $statement): ?array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?(.*)$/is', $statement, $m)) {
            return null;
        }
        [$all, $table, $column, $rest] = $m;
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}`{$rest}";
        $noPos = preg_replace('/\s+AFTER\s+`?[A-Za-z0-9_]+`?\s*$/i', '', $sql);
        return ['table' => $table, 'column' => $column, 'sql' => $sql, 'sqlNoPosition' => $noPos];
    }

    /**
     * Pure: split a migration into statements. Comments (-- to end of line, and block comments) are dropped and a
     * semicolon inside a quoted string or a comment never ends a statement.
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $cur = '';
        $n = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $cur .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $cur .= $sql[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $cur .= $c;
            } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\t" || ($sql[$i + 2] ?? '') === "\n" || ($sql[$i + 2] ?? '') === "\r")) {
                while ($i < $n && $sql[$i] !== "\n") $i++;
                $cur .= "\n";
            } elseif ($c === '#') {
                while ($i < $n && $sql[$i] !== "\n") $i++;
                $cur .= "\n";
            } elseif ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                $cur .= ' ';
            } elseif ($c === ';') {
                if (trim($cur) !== '') $out[] = trim($cur);
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }
}
