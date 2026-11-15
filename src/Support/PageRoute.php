<?php
namespace App\Support;

/** The ?page= value of the admin shell. Only plain folder/file names are ever turned into an include path. */
final class PageRoute
{
    public const INVALID = '__invalid__';

    /** 'sales_orders/view' style names pass; anything with dots, "..", backslashes, quotes, NULs, arrays etc. does not. */
    public static function clean($raw): string
    {
        if ($raw === null || $raw === '') {
            return 'home';
        }
        if (!is_string($raw)) {
            return self::INVALID;
        }
        $raw = trim($raw, '/');
        if ($raw === '') {
            return 'home';
        }
        return preg_match('~^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$~D', $raw) ? $raw : self::INVALID;
    }
}
