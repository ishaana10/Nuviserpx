<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Quotations: proforma invoices and sales quotes kept in sales_order_list, with a validity date and versions.
 *
 * A quote is valid until a date; after that it cannot be accepted or confirmed unless it is extended or revised.
 * Revising a quote makes a new version (the same number, version + 1) and marks the old one superseded, so the
 * customer can only ever accept the current offer and the history of what was offered is kept.
 */
class QuotationService
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    public static function isQuote(string $type): bool
    {
        return in_array($type, ['proforma', 'quote'], true);
    }

    /** A quote is expired the day after its valid-until date. No date means it does not expire. */
    public static function isExpired(?string $validUntil, string $today): bool
    {
        return $validUntil !== null && $validUntil !== '' && $validUntil < $today;
    }

    public static function defaultValidUntil(string $fromDate, int $days = self::DEFAULT_VALIDITY_DAYS): string
    {
        return date('Y-m-d', strtotime($fromDate . " +{$days} days"));
    }

    /** "Q-100" for the first version, "Q-100 v2" after. */
    public static function label(string $orderNo, int $version): string
    {
        return $version > 1 ? "{$orderNo} v{$version}" : $orderNo;
    }

    /**
     * Why an order or quote cannot be accepted or confirmed now, or null. $order needs status and valid_until
     * (and type). Confirmed/converted orders are not "accepted again": that is the caller's own check.
     */
    public static function acceptProblem(array $order, string $today): ?string
    {
        $status = (string)($order['status'] ?? '');
        if ($status === 'superseded') {
            return 'This quote has been replaced by a newer version. Open the latest version to accept it.';
        }
        if ($status === 'declined') {
            return 'This quote was declined. Revise it to offer it again.';
        }
        if ($status === 'cancelled') {
            return 'This order was cancelled.';
        }
        if (!in_array($status, ['converted_to_invoice', 'confirmed'], true) && self::isExpired($order['valid_until'] ?? null, $today)) {
            return 'This quote expired on ' . $order['valid_until'] . '. Extend its validity or revise it first.';
        }
        return null;
    }

    /** Why a quote cannot be revised, or null. Expired and declined quotes can be revised: that is how they are re-offered. */
    public static function reviseProblem(array $order): ?string
    {
        if (!self::isQuote((string)($order['type'] ?? ''))) {
            return 'Only quotes and proforma invoices have versions.';
        }
        return match ((string)($order['status'] ?? '')) {
            'superseded' => 'This version has already been replaced. Revise the latest version.',
            'confirmed', 'converted_to_invoice' => 'This quote was accepted and cannot be revised.',
            'cancelled' => 'This quote was cancelled.',
            default => null,
        };
    }

    /** Why a quote cannot be declined or have its validity changed, or null. */
    public static function openProblem(array $order): ?string
    {
        if (!self::isQuote((string)($order['type'] ?? ''))) {
            return 'Only quotes and proforma invoices can be declined or extended.';
        }
        return in_array((string)($order['status'] ?? ''), ['proforma', 'draft'], true) ? null : 'This quote is no longer open.';
    }

    /* --------------------------------- actions -------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }

    public function find(int $id): ?array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT * FROM sales_order_list WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** All versions of a quote, oldest first. */
    public function history(array $order): array
    {
        $t = $this->tenantId();
        $root = (int)($order['quote_root_id'] ?? 0) ?: (int)$order['id'];
        $stmt = $this->conn->prepare("SELECT id, order_no, version, status, total, valid_until, order_date FROM sales_order_list
            WHERE tenant_id = ? AND (id = ? OR quote_root_id = ?) ORDER BY version, id");
        $stmt->bind_param('iii', $t, $root, $root);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Make a new version of a quote: copy it, add one to the version and supersede the old one. */
    public function revise(int $id): string
    {
        $t = $this->tenantId();
        if (!($q = $this->find($id))) {
            return $this->fail('Quote not found.');
        }
        if ($msg = self::reviseProblem($q)) {
            return $this->fail($msg);
        }
        $userId = (int)$this->settings->userdata('id');
        $today = date('Y-m-d');
        $valid = self::defaultValidUntil($today);
        $version = (int)$q['version'] + 1;
        $root = (int)($q['quote_root_id'] ?? 0) ?: (int)$q['id'];

        $this->conn->begin_transaction();
        try {
            // Supersede first, guarded, so two people revising the same version cannot both succeed.
            $sup = $this->conn->prepare("UPDATE sales_order_list SET status = 'superseded' WHERE id = ? AND tenant_id = ? AND status NOT IN ('superseded','confirmed','converted_to_invoice','cancelled')");
            $sup->bind_param('ii', $id, $t);
            $sup->execute();
            if ($sup->affected_rows !== 1) {
                throw new \RuntimeException('This quote was just changed by someone else. Reload and try again.');
            }
            $ins = $this->conn->prepare("INSERT INTO sales_order_list
                (tenant_id, order_no, party_id, order_date, type, warehouse_id, subtotal, tax_amount, discount, total, status, notes, created_by, valid_until, version, parent_id, quote_root_id)
                SELECT tenant_id, order_no, party_id, ?, type, warehouse_id, subtotal, tax_amount, discount, total, 'proforma', notes, ?, ?, ?, id, ?
                FROM sales_order_list WHERE id = ? AND tenant_id = ?");
            $ins->bind_param('sisiiii', $today, $userId, $valid, $version, $root, $id, $t);
            if (!$ins->execute()) {
                throw new \RuntimeException('The new version could not be created.');
            }
            $newId = (int)$this->conn->insert_id;
            $cp = $this->conn->prepare("INSERT INTO sales_order_items (sales_order_id, product_id, item_name, description, qty, unit_price, tax_rate, tax_amount, amount)
                SELECT ?, product_id, item_name, description, qty, unit_price, tax_rate, tax_amount, amount FROM sales_order_items WHERE sales_order_id = ?");
            $cp->bind_param('ii', $newId, $id);
            if (!$cp->execute()) {
                throw new \RuntimeException('The quote lines could not be copied.');
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The quote could not be revised.');
        }
        $label = self::label((string)$q['order_no'], $version);
        $this->settings->log_audit('Revised quote', 'Quotations', "{$q['order_no']} v{$q['version']} replaced by {$label}");
        $msg = "Quote revised: {$label} is now the current version, valid until {$valid}.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $newId]);
    }

    public function decline(int $id, string $reason): string
    {
        $t = $this->tenantId();
        $reason = mb_substr(trim($reason), 0, 250);
        if (!($q = $this->find($id))) {
            return $this->fail('Quote not found.');
        }
        if ($msg = self::openProblem($q)) {
            return $this->fail($msg);
        }
        if ($reason === '') {
            return $this->fail('Say why the quote was declined.');
        }
        $stmt = $this->conn->prepare("UPDATE sales_order_list SET status = 'declined', decline_reason = ?, declined_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ('proforma','draft')");
        $stmt->bind_param('sii', $reason, $id, $t);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return $this->fail('This quote is no longer open.');
        }
        $this->settings->log_audit('Declined quote', 'Quotations', "{$q['order_no']}: {$reason}");
        $this->settings->set_flashdata('success', 'Quote marked as declined.');
        return json_encode(['status' => 'success', 'msg' => 'Quote marked as declined.']);
    }

    /** Move a quote's validity date out (today or later). */
    public function extend(int $id, string $date): string
    {
        $t = $this->tenantId();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter the new valid-until date.');
        }
        if ($date < date('Y-m-d')) {
            return $this->fail('The new date cannot be in the past.');
        }
        if (!($q = $this->find($id))) {
            return $this->fail('Quote not found.');
        }
        if ($msg = self::openProblem($q)) {
            return $this->fail($msg);
        }
        $stmt = $this->conn->prepare("UPDATE sales_order_list SET valid_until = ? WHERE id = ? AND tenant_id = ? AND status IN ('proforma','draft')");
        $stmt->bind_param('sii', $date, $id, $t);
        $stmt->execute();
        $this->settings->log_audit('Extended quote', 'Quotations', "{$q['order_no']} valid until {$date}");
        $this->settings->set_flashdata('success', "Quote now valid until {$date}.");
        return json_encode(['status' => 'success', 'msg' => "Quote now valid until {$date}."]);
    }
}
