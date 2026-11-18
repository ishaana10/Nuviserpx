<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * The platform owner's own invoicing: invoices raised against tenants for the Nuvis products they use, and the
 * payments received. Super administrator only; tenants read their own through admin/billing.
 */
class PlatformBillingService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }

    private function guard(): bool
    {
        return (int)$this->settings->userdata('type') === 1;
    }

    public function nextNumber(): string
    {
        $year = date('Y');
        $r = $this->conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED)) m FROM platform_invoices WHERE invoice_no LIKE 'NUV-{$year}-%'");
        $n = (int)(($r ? $r->fetch_assoc()['m'] : 0) ?? 0) + 1;
        return sprintf('NUV-%s-%04d', $year, $n);
    }

    /** paid / partial / unpaid / void from the amounts; void is sticky. */
    public static function statusFor(float $total, float $paid, string $current = ''): string
    {
        if ($current === 'void') {
            return 'void';
        }
        if ($paid + 0.004 >= $total && $total > 0) {
            return 'paid';
        }
        return $paid > 0 ? 'partial' : 'unpaid';
    }

    /** @param array<string,mixed> $post tenant_id, issue_date, due_date, tax_rate, notes, description[], qty[], unit_price[] */
    public function createInvoice(array $post): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $tenant = (int)($post['tenant_id'] ?? 0);
        $t = $this->conn->query("SELECT id FROM tenants WHERE id = {$tenant} AND delete_flag = 0");
        if ($tenant <= 0 || !$t || $t->num_rows === 0) {
            return $this->fail('Choose a tenant for this invoice.');
        }
        $lines = [];
        foreach ((array)($post['description'] ?? []) as $i => $d) {
            $d = trim((string)$d);
            $q = (float)($post['qty'][$i] ?? 1);
            $p = (float)($post['unit_price'][$i] ?? 0);
            if ($d === '' || $q <= 0) {
                continue;
            }
            $lines[] = [$d, $q, $p, round($q * $p, 2)];
        }
        if (!$lines) {
            return $this->fail('Add at least one line with a description and quantity.');
        }
        $issue = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['issue_date'] ?? '')) ? $post['issue_date'] : date('Y-m-d');
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['due_date'] ?? '')) ? $post['due_date'] : date('Y-m-d', strtotime($issue . ' +14 days'));
        $rate = max(0.0, min(100.0, (float)($post['tax_rate'] ?? 0)));
        $sub = round(array_sum(array_column($lines, 3)), 2);
        $tax = round($sub * $rate / 100, 2);
        $total = round($sub + $tax, 2);
        $notes = $this->conn->real_escape_string(trim((string)($post['notes'] ?? '')));
        $uid = (int)$this->settings->userdata('id');

        $this->conn->begin_transaction();
        try {
            $no = $this->nextNumber();
            $this->conn->query("INSERT INTO platform_invoices (tenant_id, invoice_no, issue_date, due_date, subtotal, tax_rate, tax_amount, total, status, notes, created_by)
                VALUES ({$tenant}, '{$no}', '{$issue}', '{$due}', {$sub}, {$rate}, {$tax}, {$total}, 'unpaid', '{$notes}', {$uid})");
            $id = (int)$this->conn->insert_id;
            if ($id <= 0) {
                throw new \RuntimeException('insert failed');
            }
            foreach ($lines as [$d, $q, $p, $a]) {
                $d = $this->conn->real_escape_string($d);
                $this->conn->query("INSERT INTO platform_invoice_items (invoice_id, description, qty, unit_price, amount) VALUES ({$id}, '{$d}', {$q}, {$p}, {$a})");
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The invoice could not be saved.');
        }
        $this->settings->log_audit('Created Platform Invoice', 'Platform', "{$no} for tenant #{$tenant}, total {$total}");
        $this->settings->set_flashdata('success', " Invoice {$no} created.");
        return json_encode(['status' => 'success', 'id' => $id, 'invoice_no' => $no]);
    }

    public function recordPayment(array $post): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $id = (int)($post['invoice_id'] ?? 0);
        $r = $this->conn->query("SELECT * FROM platform_invoices WHERE id = {$id}");
        $inv = $r ? $r->fetch_assoc() : null;
        if (!$inv) {
            return $this->fail('Invoice not found.');
        }
        if ($inv['status'] === 'void') {
            return $this->fail('A void invoice cannot take payments.');
        }
        $amount = round((float)($post['amount'] ?? 0), 2);
        $balance = round((float)$inv['total'] - (float)$inv['paid_amount'], 2);
        if ($amount <= 0) {
            return $this->fail('Enter the amount received.');
        }
        if ($amount > $balance + 0.004) {
            return $this->fail('That is more than the balance of ' . number_format($balance, 2) . '.');
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['pay_date'] ?? '')) ? $post['pay_date'] : date('Y-m-d');
        $m = $this->conn->real_escape_string(substr(trim((string)($post['method'] ?? '')), 0, 40));
        $ref = $this->conn->real_escape_string(substr(trim((string)($post['reference'] ?? '')), 0, 100));
        $paid = round((float)$inv['paid_amount'] + $amount, 2);
        $status = self::statusFor((float)$inv['total'], $paid);
        $this->conn->begin_transaction();
        $ok = $this->conn->query("INSERT INTO platform_payments (invoice_id, tenant_id, pay_date, amount, method, reference)
                VALUES ({$id}, {$inv['tenant_id']}, '{$date}', {$amount}, '{$m}', '{$ref}')")
            && $this->conn->query("UPDATE platform_invoices SET paid_amount = {$paid}, status = '{$status}' WHERE id = {$id}");
        if (!$ok) {
            $this->conn->rollback();
            return $this->fail('The payment could not be saved.');
        }
        $this->conn->commit();
        $this->settings->log_audit('Recorded Platform Payment', 'Platform', "{$inv['invoice_no']}: {$amount}");
        $this->settings->set_flashdata('success', ' Payment recorded.');
        return json_encode(['status' => 'success', 'invoice_status' => $status]);
    }

    public function voidInvoice(int $id): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $r = $this->conn->query("SELECT invoice_no, paid_amount FROM platform_invoices WHERE id = {$id}");
        $inv = $r ? $r->fetch_assoc() : null;
        if (!$inv) {
            return $this->fail('Invoice not found.');
        }
        if ((float)$inv['paid_amount'] > 0) {
            return $this->fail('This invoice has payments recorded and cannot be voided.');
        }
        $this->conn->query("UPDATE platform_invoices SET status = 'void' WHERE id = {$id}");
        $this->settings->log_audit('Voided Platform Invoice', 'Platform', (string)$inv['invoice_no']);
        $this->settings->set_flashdata('success', ' Invoice voided.');
        return json_encode(['status' => 'success']);
    }

    /** Overdue is shown, not stored: an unpaid or part-paid invoice past its due date. */
    public static function isOverdue(array $inv): bool
    {
        return in_array($inv['status'], ['unpaid', 'partial'], true) && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
    }
}
