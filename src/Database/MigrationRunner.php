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
                @$this->conn->query($statement);
            }

            $stmt = $this->conn->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $filename);
                $stmt->execute();
            }
        }
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
