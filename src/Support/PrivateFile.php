<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Business documents (expense receipts, asset files) that must never be reachable by a guessed URL.
 *
 * Saved under uploads/<folder>/ with a random name, in a folder that refuses direct web access, after the
 * file's real contents have been checked against its extension. They are handed out only by
 * admin/attachments/file.php, which checks the signed-in user, their company and their permission.
 */
final class PrivateFile
{
    public const MAX_BYTES = 10485760;

    /** extension => content family */
    private const FAMILY = [
        'pdf' => 'pdf', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png',
        'doc' => 'ole', 'xls' => 'ole', 'docx' => 'zip', 'xlsx' => 'zip', 'zip' => 'zip',
        'txt' => 'text', 'csv' => 'text',
    ];
    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/plain; charset=utf-8',
    ];

    /** True when the file's first bytes really are what its extension claims. */
    public static function contentMatches(string $path, string $ext): bool
    {
        $family = self::FAMILY[$ext] ?? null;
        if ($family === null || !is_file($path)) {
            return false;
        }
        $head = (string)@file_get_contents($path, false, null, 0, 8192);
        switch ($family) {
            case 'pdf':
                return strncmp($head, '%PDF-', 5) === 0;
            case 'jpeg':
                return strncmp($head, "\xFF\xD8\xFF", 3) === 0;
            case 'png':
                return strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0;
            case 'ole':
                return strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
            case 'zip':
                return strncmp($head, "PK\x03\x04", 4) === 0;
            default: // text: no binary bytes and no PHP/HTML tags
                return strpos($head, "\0") === false && stripos($head, '<?php') === false && stripos($head, '<script') === false;
        }
    }

    /** @return string|null an error message, or null when there is no file or it is acceptable */
    public static function problem(?array $file, array $allowedExts): ?string
    {
        if ($file === null || (string)($file['tmp_name'] ?? '') === '') {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'The file could not be uploaded. Please try again.';
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'The file is larger than 10 MB.';
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true) || !isset(self::FAMILY[$ext])) {
            return 'This file type is not allowed.';
        }
        if (!defined('NV_TESTING') && !is_uploaded_file((string)$file['tmp_name'])) {
            return 'The file could not be uploaded. Please try again.';
        }
        return self::contentMatches((string)$file['tmp_name'], $ext) ? null : 'The file contents do not match its type.';
    }

    /** Store an already-checked upload. @return string|null path relative to the app root, e.g. uploads/expenses/x.pdf */
    public static function store(array $file, string $folder, string $prefix, int $tenantId): ?string
    {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $dir = base_app . 'uploads/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# Private files: served only by admin/attachments/file.php after a permission check.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        $rel = 'uploads/' . $folder . '/' . $prefix . '_' . $tenantId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $ok = defined('NV_TESTING') ? @copy((string)$file['tmp_name'], base_app . $rel) : @move_uploaded_file((string)$file['tmp_name'], base_app . $rel);
        return $ok ? $rel : null;
    }

    /** Send a stored file as a download and stop. Never rendered in the browser, so a stored HTML file cannot run. */
    public static function stream(string $rel, array $allowedFolders): void
    {
        $ok = false;
        foreach ($allowedFolders as $f) {
            $ok = $ok || strpos($rel, 'uploads/' . $f . '/') === 0;
        }
        $path = base_app . $rel;
        if (!$ok || strpos($rel, '..') !== false || !is_file($path)) {
            http_response_code(404);
            die('File not found.');
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        header('Content-Type: ' . (self::MIME[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . preg_replace('~[^A-Za-z0-9._-]+~', '_', basename($rel)) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
