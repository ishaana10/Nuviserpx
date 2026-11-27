<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/** Idempotency and draft rules for invoices raised through the API. */
final class ExternalInvoiceRef
{
    public const CREATE_STATUSES = ['draft', 'unpaid'];

    public static function validStatus(?string $s): bool
    {
        return $s === null || in_array($s, self::CREATE_STATUSES, true);
    }

    public static function clean(mixed $ref): ?string
    {
        $r = trim((string)($ref ?? ''));
        return $r === '' ? null : substr($r, 0, 100);
    }

    public static function find(mysqli $c, int $tenant, string $ref): ?array
    {
        $e = $c->real_escape_string($ref);
        $r = $c->query("SELECT id, invoice_no, status FROM `invoice_list` WHERE tenant_id='{$tenant}' AND external_ref='{$e}' LIMIT 1");
        return ($r && ($row = $r->fetch_assoc())) ? $row : null;
    }

    /** Draft to unpaid only; returns false when the invoice is not a draft of this tenant. */
    public static function release(mysqli $c, int $tenant, int $id): bool
    {
        $c->query("UPDATE `invoice_list` SET status='unpaid' WHERE id='{$id}' AND tenant_id='{$tenant}' AND status='draft'");
        return $c->affected_rows === 1;
    }
}
