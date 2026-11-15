<?php
namespace App\Support;

/** Secret token that lets a customer open and sign one specific proposal, and nothing else. */
final class ProposalLink
{
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Constant-time match. An empty or malformed stored/supplied token never matches. */
    public static function matches(?string $stored, ?string $given): bool
    {
        $stored = (string)$stored;
        $given = (string)$given;
        if (!preg_match('/^[a-f0-9]{64}$/', $stored) || !preg_match('/^[a-f0-9]{64}$/', $given)) {
            return false;
        }
        return hash_equals($stored, $given);
    }
}
