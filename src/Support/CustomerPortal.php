<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rules for what a signed-in portal customer may see and how portal sessions and invitations behave.
 * Kept in one place so portal/index.php, portal/view_invoice.php and the invitation service cannot drift apart.
 */
final class CustomerPortal
{
    /** Invoices a customer may see: issued ones only. Drafts and cancelled invoices are internal. */
    public const INVOICE_STATUSES = ['unpaid', 'partially_paid', 'paid'];

    /** Open invoices, i.e. those that can still carry a balance. */
    public const OPEN_STATUSES = ['unpaid', 'partially_paid'];

    /** Payments a customer may see: posted only (not drafts, not cancelled). */
    public const PAYMENT_STATUS = 'posted';

    /** SQL fragment: `status IN ('unpaid','partially_paid','paid')`. Constants only, so it is safe to inline. */
    public static function invoiceStatusSql(string $col = 'status'): string
    {
        return "`{$col}` IN ('" . implode("','", self::INVOICE_STATUSES) . "')";
    }

    public static function openStatusSql(string $col = 'status'): string
    {
        return "`{$col}` IN ('" . implode("','", self::OPEN_STATUSES) . "')";
    }

    /** Invitation tokens are stored hashed so a database leak does not hand out working activation links. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function minPasswordLength(): int
    {
        return 8;
    }

    /** @return string[] reasons the password is not acceptable (empty when fine) */
    /** How long an emailed password-reset link works, in seconds. */
    public const RESET_TTL = 3600;

    /** Pure: why a new password is refused on the reset page (mismatch or weak), or null. */
    public static function resetPasswordProblem(string $new, string $confirm): ?string
    {
        if ($new !== $confirm) {
            return 'The two passwords do not match.';
        }
        $errs = self::passwordErrors($new);
        return $errs ? $errs[0] : null;
    }

    public static function passwordErrors(string $password): array
    {
        $e = [];
        if (strlen($password) < self::minPasswordLength()) {
            $e[] = 'Password must be at least ' . self::minPasswordLength() . ' characters long.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $e[] = 'Password must contain at least one letter and one number.';
        }
        return $e;
    }

    public static function clearSession(): void
    {
        foreach (['customer_portal', 'customer_id', 'customer_name', 'customer_email', 'customer_client_id', 'customer_party_id'] as $k) {
            unset($_SESSION[$k]);
        }
    }

    /**
     * The signed-in customer, re-checked against the database on every request so that a revoked portal user
     * or a deleted customer loses access immediately instead of when the session expires.
     * Also accepts the Super Administrator "portal preview" session (admin signed in + preview flag).
     *
     * @return array{party_id:int,tenant_id:int,portal_user_id:int,preview:bool}|null
     */
    public static function context(\mysqli $conn): ?array
    {
        $p = $_SESSION['customer_portal'] ?? null;
        if (is_array($p) && !empty($p['id']) && !empty($p['party_id'])) {
            $uid = (int)$p['id'];
            $pid = (int)$p['party_id'];
            $q = $conn->prepare(
                "SELECT u.tenant_id FROM customer_portal_users u
                 INNER JOIN party_list p ON p.id = u.party_id AND p.tenant_id = u.tenant_id
                 WHERE u.id = ? AND u.party_id = ? AND u.status = 1 AND p.delete_flag = 0 LIMIT 1"
            );
            if (!$q) {
                return null;
            }
            $q->bind_param('ii', $uid, $pid);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            return $r ? ['party_id' => $pid, 'tenant_id' => (int)$r['tenant_id'], 'portal_user_id' => $uid, 'preview' => false] : null;
        }

        // Super Administrator previewing a customer's portal (admin/developer/portal_preview.php).
        $isSuper = isset($_SESSION['userdata']) && (int)($_SESSION['userdata']['type'] ?? 0) === 1;
        if ($isSuper && (int)($_SESSION['customer_id'] ?? 0) === 99999 && !empty($_SESSION['customer_client_id'])) {
            $pid = (int)$_SESSION['customer_client_id'];
            $q = $conn->prepare("SELECT tenant_id FROM party_list WHERE id = ? AND delete_flag = 0 LIMIT 1");
            if ($q) {
                $q->bind_param('i', $pid);
                $q->execute();
                $r = $q->get_result()->fetch_assoc();
                if ($r) {
                    return ['party_id' => $pid, 'tenant_id' => (int)$r['tenant_id'], 'portal_user_id' => 0, 'preview' => true];
                }
            }
        }
        return null;
    }
}
