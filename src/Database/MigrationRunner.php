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

            // Split multi-statement SQL
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn(string $stmt) => !empty($stmt)
            );

            foreach ($statements as $statement) {
                @$this->conn->query($statement);
            }

            $stmt = $this->conn->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $filename);
                $stmt->execute();
            }
        }
    }
}
