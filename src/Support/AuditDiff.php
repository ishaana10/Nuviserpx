<?php

declare(strict_types=1);

namespace App\Support;

/** Builds the "what changed" text for audit log entries. */
final class AuditDiff
{
    /**
     * Pure: list the fields whose value differs between $before and $after as "label: 'old' -> 'new'".
     * Numbers are compared as numbers (10 and 10.00 are the same). Fields in $masked are reported as changed without
     * their values, for things such as bank details that must not be copied into the log. Values are cut to $max characters.
     *
     * @param string[] $fields
     * @param string[] $masked
     */
    public static function changes(array $before, array $after, array $fields, array $masked = [], int $max = 60): string
    {
        $out = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $after)) {
                continue;
            }
            $old = $before[$f] ?? null;
            $new = $after[$f];
            if (self::same($old, $new)) {
                continue;
            }
            $label = str_replace('_', ' ', $f);
            if (in_array($f, $masked, true)) {
                $out[] = "{$label} changed";
                continue;
            }
            $out[] = "{$label}: '" . self::show($old, $max) . "' -> '" . self::show($new, $max) . "'";
        }
        return $out ? implode('; ', $out) : 'no field changes';
    }

    /** Pure: what changed between two comma-separated lists (for example role permissions) as "added: a, b; removed: c". */
    public static function listChanges(string $beforeCsv, string $afterCsv, int $max = 400): string
    {
        $split = fn(string $s) => array_values(array_unique(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== '')));
        $b = $split($beforeCsv);
        $a = $split($afterCsv);
        $added = array_values(array_diff($a, $b));
        $removed = array_values(array_diff($b, $a));
        $parts = [];
        if ($added) {
            $parts[] = 'added: ' . implode(', ', $added);
        }
        if ($removed) {
            $parts[] = 'removed: ' . implode(', ', $removed);
        }
        $text = $parts ? implode('; ', $parts) : 'no permission changes';
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '...' : $text;
    }

    private static function same($a, $b): bool
    {
        $a = $a === null ? '' : trim((string)$a);
        $b = $b === null ? '' : trim((string)$b);
        if ($a !== '' && $b !== '' && is_numeric($a) && is_numeric($b)) {
            return abs((float)$a - (float)$b) < 0.0000001;
        }
        return $a === $b;
    }

    private static function show($v, int $max): string
    {
        $s = $v === null ? '' : trim((string)$v);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '...' : $s;
    }
}
