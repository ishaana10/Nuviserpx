<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;

/**
 * Applies database/migrations/*.sql in order.
 *
 * A migration is recorded as applied ONLY after every statement in it has worked. The first statement that fails for a
 * real reason stops the run, is logged with its file and SQL, and is reported to the caller (MigrationFailed), so a
 * half-applied migration can never be mistaken for a finished one. The only errors tolerated are "already there" ones
 * on repeatable DDL (table/column/index exists), because older installs got the same changes from the legacy
 * catch-up code before the migration file ran.
 *
 * MySQL has no CREATE INDEX IF NOT EXISTS or ADD COLUMN IF NOT EXISTS (MariaDB does); both are emulated with a check
 * against information_schema so the same files run on either server.
 *
 * Schema changes are DDL, which MySQL commits implicitly, so a migration cannot be rolled back as a whole. Write
 * them so they can be run again, and restore the pre-deploy backup if one fails (bin/deploy.sh takes it).
 */
class MigrationRunner
{
    private const LOCK = 'nuviserpx_migrations';

    /** @var string[] human-readable notes (changed files, etc.) from the last run/status call */
    public array $warnings = [];

    public function __construct(private readonly mysqli $conn)
    {
    }

    /**
     * Apply every pending migration.
     *
     * @return string[] names of the migrations applied by this call
     * @throws MigrationFailed on the first statement that fails for a real reason (nothing after it is run)
     */
    public function run(string $migrationsDir, int $lockWaitSeconds = 0): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        if (!$files) {
            return [];
        }

        $this->ensureTable();
        // One runner at a time: two web requests (or a deploy and a request) must not apply the same file together.
        if (!$this->lock($lockWaitSeconds)) {
            return [];
        }
        $applied = [];
        try {
            $executed = $this->executed();   // name => checksum|null
            $this->warnings = [];
            foreach ($files as $file) {
                $name = basename($file);
                $sum = self::checksum($file);
                if (array_key_exists($name, $executed)) {
                    $this->noteChecksum($name, $executed[$name], $sum);
                    continue;
                }
                $sql = (string)file_get_contents($file);
                foreach (self::splitStatements($sql) as $statement) {
                    $err = $this->apply($statement);
                    if ($err !== null) {
                        $e = new MigrationFailed($name, $statement, $err[0], $err[1]);
                        error_log('[nuviserpx] ' . $e->getMessage());
                        throw $e;
                    }
                }
                $this->record($name, $sum);
                $applied[] = $name;
            }
            $this->repairConditionalColumns($files);
        } finally {
            $this->unlock();
        }
        return $applied;
    }

    /**
     * Read-only view for health checks and `bin/migrate.php --status`.
     *
     * @return array{applied:string[],pending:string[],changed:string[]}
     */
    public function status(string $migrationsDir): array
    {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        $executed = [];
        if ($res = @$this->conn->query("SELECT `migration`, " . ($this->hasChecksumColumn() ? '`checksum`' : 'NULL AS `checksum`') . " FROM `schema_migrations`")) {
            while ($r = $res->fetch_row()) {
                $executed[$r[0]] = $r[1];
            }
        }
        $pending = $changed = [];
        foreach ($files as $f) {
            $n = basename($f);
            if (!array_key_exists($n, $executed)) {
                $pending[] = $n;
            } elseif ($executed[$n] !== null && !hash_equals((string)$executed[$n], self::checksum($f))) {
                $changed[] = $n;
            }
        }
        return ['applied' => array_values(array_filter(array_map('basename', $files), fn($n) => array_key_exists($n, $executed))), 'pending' => $pending, 'changed' => $changed];
    }

    /** Name of the newest migration file (cheap; used by the web request guard). */
    public static function latestFile(string $migrationsDir): ?string
    {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        if (!$files) {
            return null;
        }
        sort($files);
        return basename(end($files));
    }

    /** Newest applied migration name, or null when none/the table is missing. One cheap query. */
    public function latestApplied(): ?string
    {
        $r = @$this->conn->query("SELECT MAX(`migration`) FROM `schema_migrations` WHERE `migration` REGEXP '^[0-9]{3}_'");
        if (!$r) {
            return null;
        }
        $row = $r->fetch_row();
        return $row && $row[0] !== null ? (string)$row[0] : null;
    }

    public static function checksum(string $file): string
    {
        // Line endings must not change the checksum (Windows checkouts).
        return hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents($file)));
    }

    /**
     * Pure: is this error an expected "already done" on a statement that can safely be repeated?
     * 1050 table exists, 1060 duplicate column, 1061 duplicate key name, 1068 multiple primary key, 1091 cannot drop
     * (not there), 1826/1022 duplicate constraint, 1062 duplicate entry on a seed INSERT.
     */
    public static function isRepeatable(string $statement, int $errno): bool
    {
        $isDdl = (bool)preg_match('/^\s*(CREATE|ALTER|DROP|RENAME)\b/i', $statement);
        if ($isDdl && in_array($errno, [1050, 1060, 1061, 1068, 1091, 1826, 1022, 1432], true)) {
            return true;
        }
        return $errno === 1062 && (bool)preg_match('/^\s*INSERT\b/i', $statement);
    }

    private function ensureTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL UNIQUE,
                `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (!$this->hasChecksumColumn()) {
            $this->conn->query("ALTER TABLE `schema_migrations` ADD COLUMN `checksum` CHAR(64) DEFAULT NULL");
        }
    }

    private function hasChecksumColumn(): bool
    {
        return $this->columnExists('schema_migrations', 'checksum');
    }

    /** @return array<string,?string> */
    private function executed(): array
    {
        $out = [];
        if ($res = $this->conn->query("SELECT `migration`, `checksum` FROM `schema_migrations`")) {
            while ($r = $res->fetch_row()) {
                $out[$r[0]] = $r[1];
            }
        }
        return $out;
    }

    private function record(string $name, string $sum): void
    {
        $stmt = $this->conn->prepare("INSERT INTO `schema_migrations` (`migration`, `checksum`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `checksum` = VALUES(`checksum`)");
        $stmt->bind_param('ss', $name, $sum);
        $stmt->execute();
    }

    /** First sighting of an old row's checksum is trusted; a later difference means the file was edited after it was applied. */
    private function noteChecksum(string $name, ?string $stored, string $current): void
    {
        if ($stored === null || $stored === '') {
            $stmt = $this->conn->prepare("UPDATE `schema_migrations` SET `checksum` = ? WHERE `migration` = ?");
            $stmt->bind_param('ss', $current, $name);
            $stmt->execute();
        } elseif (!hash_equals($stored, $current)) {
            $this->warnings[] = "$name was changed after it was applied";
        }
    }

    private function lock(int $wait): bool
    {
        $r = $this->conn->query("SELECT GET_LOCK('" . self::LOCK . "', " . max(0, $wait) . ")");
        $row = $r ? $r->fetch_row() : null;
        return $row !== null && (int)$row[0] === 1;
    }

    private function unlock(): void
    {
        @$this->conn->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
    }

    /**
     * Run one statement. Returns null on success (or an acceptable repeat), else [errno, message].
     *
     * @return array{0:int,1:string}|null
     */
    private function apply(string $statement): ?array
    {
        if (($add = self::parseConditionalAddColumn($statement)) !== null) {
            if ($this->columnExists($add['table'], $add['column'])) {
                return null;
            }
            if ($this->conn->query($add['sql']) === false && $this->conn->errno === 1054 && $add['sqlNoPosition'] !== $add['sql']) {
                // AFTER names a column that is not there: add it at the end instead.
                $this->conn->query($add['sqlNoPosition']);
            }
            return $this->outcome($add['sql']);
        }
        if (($ix = self::parseConditionalCreateIndex($statement)) !== null) {
            if ($this->indexExists($ix['table'], $ix['index'])) {
                return null;
            }
            $this->conn->query($ix['sql']);
            return $this->outcome($ix['sql']);
        }
        $this->conn->query($statement);
        return $this->outcome($statement);
    }

    /** @return array{0:int,1:string}|null */
    private function outcome(string $statement): ?array
    {
        $errno = $this->conn->errno;
        if ($errno === 0) {
            return null;
        }
        return self::isRepeatable($statement, $errno) ? null : [$errno, $this->conn->error];
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

    private function indexExists(string $table, string $index): bool
    {
        $q = $this->conn->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        if (!$q) {
            return false;
        }
        $q->bind_param('ss', $table, $index);
        $q->execute();
        return $q->get_result()->num_rows > 0;
    }

    /**
     * Once per database: on MySQL the conditional column additions in migrations that ran earlier were rejected and the
     * migration was still recorded as done. Apply them again now, skipping any column that already exists.
     */
    private function repairConditionalColumns(array $files): void
    {
        $marker = 'repair_conditional_columns_v1';
        $done = $this->conn->query("SELECT 1 FROM `schema_migrations` WHERE `migration` = '{$marker}'");
        if ($done && $done->num_rows > 0) {
            return;
        }
        foreach ($files as $file) {
            foreach (self::splitStatements((string)file_get_contents($file)) as $statement) {
                if (self::parseConditionalAddColumn($statement) !== null) {
                    $this->apply($statement);
                }
            }
        }
        $stmt = $this->conn->prepare("INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES (?)");
        $stmt->bind_param('s', $marker);
        $stmt->execute();
    }

    /**
     * Pure: for `CREATE [UNIQUE] INDEX IF NOT EXISTS i ON t (...)` return table, index and the statement without the
     * IF NOT EXISTS clause (MySQL does not accept it). Null for any other statement.
     */
    public static function parseConditionalCreateIndex(string $statement): ?array
    {
        if (!preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?(.*)$/is', $statement, $m)) {
            return null;
        }
        return ['table' => $m[3], 'index' => $m[2], 'sql' => 'CREATE ' . trim($m[1]) . ($m[1] !== '' ? ' ' : '') . "INDEX `{$m[2]}` ON `{$m[3]}`{$m[4]}"];
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
