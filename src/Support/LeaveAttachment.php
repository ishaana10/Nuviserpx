<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one file an employee may attach to a leave request (a medical certificate, say).
 *
 * Kept deliberately small because server space is limited: PDF, JPG or PNG up to 2 MB; photos are shrunk to
 * at most 1600 px and re-saved as JPEG when GD is available. Files go in uploads/leave_docs/ under random names,
 * the folder refuses direct web access, and they are only handed out by a script that checks who is asking.
 */
final class LeaveAttachment
{
    public const MAX_BYTES = 2097152;
    public const DIR = 'uploads/leave_docs/';
    private const MAX_PIXELS = 1600;

    /** The file's type from its contents ('pdf' or 'jpg' or 'png'), or null if it is not one we accept. */
    public static function kind(string $path): ?string
    {
        $head = (string)@file_get_contents($path, false, null, 0, 8);
        if (strncmp($head, '%PDF-', 5) === 0) {
            return 'pdf';
        }
        $info = @getimagesize($path);
        if ($info !== false) {
            return $info[2] === IMAGETYPE_JPEG ? 'jpg' : ($info[2] === IMAGETYPE_PNG ? 'png' : null);
        }
        return null;
    }

    /** An error message for an upload that is present but not acceptable; null when there is no file or it is fine. */
    public static function problem(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
                ? 'The file is too large. Please attach a file of 2 MB or less.' : 'The file could not be uploaded. Please try again.';
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'The file is too large. Please attach a file of 2 MB or less.';
        }
        if (!is_uploaded_file((string)$file['tmp_name']) && !defined('NV_TESTING')) {
            return 'The file could not be uploaded. Please try again.';
        }
        return self::kind((string)$file['tmp_name']) === null ? 'Please attach a PDF, JPG or PNG file.' : null;
    }

    /** @return array{path:string,name:string,size:int}|null the stored file, or null if it could not be saved */
    public static function store(array $file, int $tenantId, int $employeeId): ?array
    {
        $tmp = (string)$file['tmp_name'];
        $kind = self::kind($tmp);
        if ($kind === null) {
            return null;
        }
        $dir = base_app . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $guard = $dir . '.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# Private files: served only by the signed-in download scripts.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        $base = self::DIR . 'leave_' . $tenantId . '_' . $employeeId . '_' . bin2hex(random_bytes(8));
        $rel = $base . '.' . $kind;
        $done = false;
        if ($kind !== 'pdf' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $jpg = $base . '.jpg';
            if (self::shrink($tmp, base_app . $jpg)) {
                $rel = $jpg;
                $done = true;
            }
        }
        if (!$done) {
            $done = defined('NV_TESTING') ? @copy($tmp, base_app . $rel) : @move_uploaded_file($tmp, base_app . $rel);
        }
        if (!$done) {
            return null;
        }
        $name = preg_replace('~[^A-Za-z0-9._ -]+~', '_', basename((string)($file['name'] ?? 'attachment')));
        return ['path' => $rel, 'name' => mb_substr((string)$name, 0, 120), 'size' => (int)@filesize(base_app . $rel)];
    }

    /** Re-save a photo as a smaller JPEG (at most 1600 px, quality 75, white background for transparent PNGs). */
    private static function shrink(string $tmp, string $dest): bool
    {
        $img = @imagecreatefromstring((string)@file_get_contents($tmp));
        if (!$img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, self::MAX_PIXELS / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $flat = imagecreatetruecolor($nw, $nh);
        imagefill($flat, 0, 0, (int)imagecolorallocate($flat, 255, 255, 255));
        imagecopyresampled($flat, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return @imagejpeg($flat, $dest, 75) && is_file($dest);
    }

    /** Delete a stored file (only ever inside the leave documents folder). */
    public static function remove(?string $rel): void
    {
        $rel = (string)$rel;
        if ($rel !== '' && strpos($rel, self::DIR) === 0 && strpos($rel, '..') === false) {
            @unlink(base_app . $rel);
        }
    }

    /** Send a stored file to the browser and stop. */
    public static function stream(string $rel, string $downloadName): void
    {
        $path = base_app . $rel;
        if (strpos($rel, self::DIR) !== 0 || strpos($rel, '..') !== false || !is_file($path)) {
            http_response_code(404);
            die('File not found.');
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $type = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
        $safe = preg_replace('~[^A-Za-z0-9._ -]+~', '_', $downloadName) ?: 'attachment';
        header('Content-Type: ' . $type);
        header('Content-Disposition: inline; filename="' . $safe . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
