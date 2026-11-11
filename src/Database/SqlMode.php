<?php

declare(strict_types=1);

namespace App\Database;

/**
 * The app was written against MariaDB, whose default sql_mode does not include ONLY_FULL_GROUP_BY. MySQL 5.7 and 8 turn
 * it on by default, which rejects any report query that selects a column it does not group by and leaves the page blank.
 */
final class SqlMode
{
    /** Pure: the given sql_mode without ONLY_FULL_GROUP_BY (everything else, including strict mode, is kept). */
    public static function withoutFullGroupBy(string $mode): string
    {
        $parts = array_filter(array_map('trim', explode(',', $mode)), fn($p) => $p !== '' && strcasecmp($p, 'ONLY_FULL_GROUP_BY') !== 0);
        return implode(',', $parts);
    }

    public static function apply(\mysqli $conn): void
    {
        $res = @$conn->query("SELECT @@SESSION.sql_mode AS m");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row || stripos((string)$row['m'], 'ONLY_FULL_GROUP_BY') === false) {
            return;
        }
        $new = $conn->real_escape_string(self::withoutFullGroupBy((string)$row['m']));
        @$conn->query("SET SESSION sql_mode = '{$new}'");
    }
}
