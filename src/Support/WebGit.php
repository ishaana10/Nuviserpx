<?php
namespace App\Support;

/**
 * Switch for the developer "Git update" screen.
 *
 * Running `git pull` from a web request means a stolen super-administrator session can replace
 * the application's code. It is therefore OFF unless ALLOW_WEB_GIT=1 is set in .env, which should
 * only ever be done on a development machine. Production is updated by the deployment process.
 */
final class WebGit
{
    public static function enabled(): bool
    {
        return in_array(strtolower((string)getenv('ALLOW_WEB_GIT')), ['1', 'true', 'yes', 'on'], true);
    }

    /** Only plain branch names reach the shell. */
    public static function safeBranch(string $branch): string
    {
        return preg_match('~^[A-Za-z0-9._/-]{1,100}$~', $branch) && strpos($branch, '..') === false ? $branch : 'main';
    }
}
