<?php

declare(strict_types=1);

namespace App\Support;

/** Rules for who may change a client record and which fields a client profile may set. */
final class ClientAccess
{
    /** Fields a client profile may set. Anything else posted (status, type, avatar, ...) is ignored. */
    public const FIELDS = ['firstname', 'middlename', 'lastname', 'gender', 'contact', 'email', 'address'];

    /**
     * A signed-in staff user with users_manage may change any client; a signed-in client may change only their own.
     * Returns an error message, or null when allowed.
     */
    public static function error(bool $signedIn, bool $isStaff, bool $canManage, int $sessionId, int $targetId): ?string
    {
        if (!$signedIn) {
            return 'Your session has expired. Please sign in again.';
        }
        if ($isStaff) {
            return $canManage ? null : "You don't have permission to perform this action.";
        }
        return ($targetId > 0 && $targetId === $sessionId) ? null : "You don't have permission to perform this action.";
    }

    /**
     * Pure: the posted fields that may be saved, as column => value.
     *
     * @return array<string,string>
     */
    public static function pick(array $post): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            if (array_key_exists($k, $post)) {
                $out[$k] = (string)$post[$k];
            }
        }
        return $out;
    }
}
