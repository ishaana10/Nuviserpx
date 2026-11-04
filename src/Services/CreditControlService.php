<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Credit control on customers: a credit limit (the most a customer may owe at once) and default payment terms
 * (days until an invoice is due). The limit is checked when an invoice is issued; the terms set the due date.
 */
class CreditControlService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** A limit from a form value: '' means no limit (null); otherwise zero or more. Returns [value] or ['error']. Pure. */
    public static function cleanLimit(mixed $raw): array
    {
        if (trim((string)$raw) === '') {
            return ['value' => null];
        }
        $v = BudgetService::cleanAmount($raw);
        if ($v === null || $v < 0) {
            return ['error' => 'The credit limit must be a number, zero or more (leave it empty for no limit).'];
        }
        return ['value' => round($v, 2)];
    }

    /** Payment terms in days from a form value: '' means none; otherwise a whole number 0-365. Pure. */
    public static function cleanTerms(mixed $raw): array
    {
        if (trim((string)$raw) === '') {
            return ['value' => null];
        }
        if (!preg_match('/^\d{1,3}$/', trim((string)$raw)) || (int)trim((string)$raw) > 365) {
            return ['error' => 'Payment terms are a whole number of days, 0 to 365.'];
        }
        return ['value' => (int)trim((string)$raw)];
    }

    /** The due date for an invoice date and terms in days. Pure. */
    public static function dueDate(string $invoiceDate, int $days): string
    {
        return date('Y-m-d', strtotime($invoiceDate . " +{$days} days"));
    }

    /**
     * Why an invoice can't be issued within the customer's limit, or null. $limit null/0 here means "no limit" only
     * when null; a limit of 0 means the customer may not be invoiced on credit at all. $owed is what they already owe
     * on other invoices. Pure.
     */
    public static function limitProblem(?float $limit, float $owed, float $invoiceTotal, string $customer): ?string
    {
        if ($limit === null || $invoiceTotal <= 0) {
            return null;
        }
        $after = round($owed + $invoiceTotal, 2);
        if ($after <= round($limit, 2) + 0.005) {
            return null;
        }
        return "{$customer} would owe " . number_format($after, 2) . ' after this invoice, over their credit limit of '
            . number_format($limit, 2) . ' (they owe ' . number_format($owed, 2) . ' now).';
    }

    /** What a customer owes on issued invoices, optionally leaving one out (the invoice being edited). */
    public function owed(int $partyId, int $excludeInvoiceId = 0): float
    {
        $t = (int)$this->settings->active_tenant_id();
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(GREATEST(0, total - paid_amount - credited_amount)), 0) s
            FROM invoice_list WHERE tenant_id = ? AND party_id = ? AND id <> ? AND status NOT IN ('draft','cancelled')");
        $stmt->bind_param('iii', $t, $partyId, $excludeInvoiceId);
        $stmt->execute();
        return round((float)($stmt->get_result()->fetch_assoc()['s'] ?? 0), 2);
    }

    /** The customer's limit and terms: ['credit_limit' => ?float, 'terms' => ?int]. */
    public function terms(int $partyId): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $stmt = $this->conn->prepare("SELECT credit_limit, payment_terms_days FROM party_list WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $partyId, $t);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        return [
            'credit_limit' => isset($r['credit_limit']) && $r['credit_limit'] !== null ? (float)$r['credit_limit'] : null,
            'terms' => isset($r['payment_terms_days']) && $r['payment_terms_days'] !== null ? (int)$r['payment_terms_days'] : null,
        ];
    }

    /**
     * Check an invoice against the customer's limit. Returns a message, or null when it may go ahead. Someone with
     * settings permission may override a breach deliberately.
     */
    public function check(int $partyId, string $customer, float $invoiceTotal, int $excludeInvoiceId, bool $override): ?string
    {
        $limit = $this->terms($partyId)['credit_limit'];
        if ($limit === null) {
            return null;
        }
        $msg = self::limitProblem($limit, $this->owed($partyId, $excludeInvoiceId), $invoiceTotal, $customer);
        if ($msg === null) {
            return null;
        }
        if ($override && $this->settings->can_access('settings_manage')) {
            $this->settings->log_audit('Credit limit overridden', 'Invoices', $msg);
            return null;
        }
        return $msg . ($this->settings->can_access('settings_manage') ? ' Tick "Allow over credit limit" to issue it anyway.' : ' Ask a manager to approve it or raise the limit.');
    }
}
