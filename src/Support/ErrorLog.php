<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-side PHP errors and browser JavaScript errors, kept as files under
 * logs/ and read back by the developer Error Logs page.
 *
 * - php_errors.log is written by PHP itself (ini error_log): plain text, one
 *   entry per "[date] message" line, continuation lines belong to the entry
 *   above (stack traces).
 * - js_errors.log is written by append(): one JSON object per line.
 */
class ErrorLog
{
    private const MAX_BYTES = 8388608;   // rotate a log past 8 MB
    private const READ_BYTES = 262144;   // parse the newest 256 KB
    private const MAX_ENTRIES = 400;

    public static function dir(): string
    {
        $dir = base_app . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    public static function path(string $type): string
    {
        return self::dir() . '/' . ($type === 'js' ? 'js_errors.log' : 'php_errors.log');
    }

    /** Keep a log from growing without bound: past the cap it becomes .1 (replacing the previous .1). */
    public static function rotate(string $type): void
    {
        $path = self::path($type);
        if (is_file($path) && (filesize($path) ?: 0) > self::MAX_BYTES) {
            @rename($path, $path . '.1');
        }
    }

    /** Append a browser error as one JSON line. */
    public static function append(string $type, array $entry): void
    {
        self::rotate($type);
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line !== false) {
            @file_put_contents(self::path($type), $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public static function clear(string $type): void
    {
        @file_put_contents(self::path($type), '');
        @unlink(self::path($type) . '.1');
    }

    public static function sizeLabel(string $type): string
    {
        $b = is_file(self::path($type)) ? (filesize(self::path($type)) ?: 0) : 0;
        if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
        if ($b >= 1024) return number_format($b / 1024, 1) . ' KB';
        return $b . ' B';
    }

    /** Newest-first entries, identical messages grouped with a count. */
    public static function read(string $type): array
    {
        $raw = self::tail(self::path($type));
        if ($raw === '') {
            return [];
        }
        $entries = $type === 'js' ? self::parseJs($raw) : self::parsePhp($raw);
        $entries = array_reverse(array_slice($entries, -self::MAX_ENTRIES));

        $groups = [];
        foreach ($entries as $e) {
            $key = $e['message'];
            if (!isset($groups[$key])) {
                $e['count'] = 0;
                $groups[$key] = $e;   // first seen in newest-first order = latest occurrence
            }
            $groups[$key]['count']++;
        }
        return array_values($groups);
    }

    private static function tail(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $size = filesize($path) ?: 0;
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        if ($size > self::READ_BYTES) {
            fseek($fh, -self::READ_BYTES, SEEK_END);
            fgets($fh); // drop the partial first line
        }
        $raw = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $raw;
    }

    /** "[12-Nov-2026 14:01:02 Pacific/Fiji] PHP Warning: msg in /file.php on line 9" + continuation lines. */
    private static function parsePhp(string $raw): array
    {
        $entries = [];
        $cur = null;
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})[^\]]*\]\s*(.*)$/', $line, $m)) {
                if ($cur !== null) {
                    $entries[] = $cur;
                }
                $msg = $m[2];
                $sev = 'Error';
                if (preg_match('/PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)/i', $msg, $sm)) {
                    $sev = ucfirst(strtolower($sm[1]));
                }
                $file = '';
                $fline = 0;
                if (preg_match('/ in (\/[^ ]+\.php)(?: on line |:)(\d+)/', $msg, $fm)) {
                    $file = $fm[1];
                    $fline = (int)$fm[2];
                }
                $cur = ['time' => $m[1], 'severity' => $sev, 'message' => trim($msg), 'file' => $file, 'line' => $fline, 'detail' => ''];
            } elseif ($cur !== null && trim($line) !== '') {
                $cur['detail'] .= ($cur['detail'] === '' ? '' : "\n") . $line;
            }
        }
        if ($cur !== null) {
            $entries[] = $cur;
        }
        return $entries;
    }

    private static function parseJs(string $raw): array
    {
        $entries = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $e = json_decode($line, true);
            if (!is_array($e) || !isset($e['message'])) {
                continue;
            }
            $entries[] = [
                'time' => (string)($e['time'] ?? ''),
                'severity' => 'Error',
                'message' => (string)$e['message'],
                'file' => (string)($e['source'] ?? ''),
                'line' => (int)($e['line'] ?? 0),
                'detail' => trim((string)($e['stack'] ?? '') . "\nUser: " . ($e['user'] ?? '') . '  Page: ' . ($e['page'] ?? '') . '  Browser: ' . ($e['agent'] ?? '')),
            ];
        }
        return $entries;
    }
}
