<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * The platform owner (Nuvis Technologies) billing its customer tenants. Every bill is a normal sales invoice in the
 * owner's books (receivable, sales and tax journals, statements, aged debtors, reports) and every payment a normal
 * receipt there. platform_invoices only links a customer tenant to its invoice so the tenant can read it.
 * Writing is super-administrator only; a tenant reads its own bills through admin/billing.
 */
class PlatformBillingService
{
    /** Product tags for revenue-by-product; MedcareX and FlipEx bill through the same owner books. */
    public const PRODUCTS = ['erpx' => 'Nuvis ERPX', 'medcarex' => 'Nuvis MedcareX', 'flipex' => 'Nuvis FlipEx', 'other' => 'Other'];

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

    /**
     * Make sure the billing schema exists (the same statements as migration 054, all idempotent), so a server that
     * has pulled the code but not yet run bin/migrate.php still works. Returns false if it could not be created.
     */
    public static function ensureSchema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $has = fn(string $table, string $col): bool => (bool)@$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")?->num_rows;
        if ($has('tenants', 'is_platform_owner') && $has('party_list', 'linked_tenant_id') && $has('invoice_list', 'product_code') && $has('platform_invoices', 'owner_invoice_id')) {
            return $ok = true;
        }
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/054_platform_billing_owner_books.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
        $linked = $has('platform_invoices', 'owner_invoice_id');
        foreach (array_filter(array_map('trim', explode(";\n", (string)$sql))) as $stmt) {
            if ($linked && stripos($stmt, 'DROP TABLE') === 0) {
                continue;   // never drop links that already exist; the drops are only for the first-version table
            }
            if ($linked && stripos($stmt, 'CREATE TABLE') === 0) {
                continue;
            }
            @$conn->query($stmt);
        }
        return $ok = ($has('tenants', 'is_platform_owner') && $has('party_list', 'linked_tenant_id') && $has('invoice_list', 'product_code') && $has('platform_invoices', 'owner_invoice_id'));
    }

    public function ownerId(): int
    {
        if (!self::ensureSchema($this->conn)) {
            return 0;
        }
        $r = $this->conn->query("SELECT id FROM tenants WHERE is_platform_owner = 1 AND delete_flag = 0 ORDER BY id LIMIT 1");
        return $r && ($row = $r->fetch_assoc()) ? (int)$row['id'] : 0;
    }

    /** Run $fn with the owner company as the active tenant, then put the previous one back. */
    private function asOwner(int $owner, callable $fn)
    {
        $had = array_key_exists('active_tenant_id', $_SESSION['userdata'] ?? []);
        $prev = $_SESSION['userdata']['active_tenant_id'] ?? null;
        $_SESSION['userdata']['active_tenant_id'] = $owner;
        try {
            return $fn();
        } finally {
            if ($had) {
                $_SESSION['userdata']['active_tenant_id'] = $prev;
            } else {
                unset($_SESSION['userdata']['active_tenant_id']);
            }
        }
    }

    /** The customer record for a tenant in the owner's books; created on first use, details kept in step. */
    public function customerFor(int $tenantId, int $owner): int
    {
        $t = $this->conn->query("SELECT name, email, phone, address, tin FROM tenants WHERE id = {$tenantId}")->fetch_assoc();
        $e = fn($v) => $this->conn->real_escape_string((string)$v);
        $r = $this->conn->query("SELECT id FROM party_list WHERE tenant_id = {$owner} AND linked_tenant_id = {$tenantId} AND delete_flag = 0 LIMIT 1");
        if ($r && ($row = $r->fetch_assoc())) {
            $this->conn->query("UPDATE party_list SET name = '{$e($t['name'])}', email = '{$e($t['email'])}', phone = '{$e($t['phone'])}', address = '{$e($t['address'])}', tin = '{$e($t['tin'])}' WHERE tenant_id = {$owner} AND id = " . (int)$row['id']);
            return (int)$row['id'];
        }
        $code = $e(SequenceService::documentNo($this->conn, $owner, 'party_list', 'party_code', 'CUS-', 'party_code'));
        $this->conn->query("INSERT INTO party_list (tenant_id, party_code, name, type, email, phone, tin, address, linked_tenant_id)
            VALUES ({$owner}, '{$code}', '{$e($t['name'])}', 'customer', '{$e($t['email'])}', '{$e($t['phone'])}', '{$e($t['tin'])}', '{$e($t['address'])}', {$tenantId})");
        return (int)$this->conn->insert_id;
    }

    /** @param array<string,mixed> $post tenant_id, product_code, issue_date, due_date, tax_rate, notes, description[], qty[], unit_price[] */
    public function createInvoice(array $post): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $owner = $this->ownerId();
        if ($owner <= 0) {
            return $this->fail('No company is set as the platform owner yet. Edit your own company under Tenants and tick "This company bills the other tenants".');
        }
        $tenant = (int)($post['tenant_id'] ?? 0);
        $t = $this->conn->query("SELECT id FROM tenants WHERE id = {$tenant} AND delete_flag = 0");
        if ($tenant <= 0 || !$t || $t->num_rows === 0) {
            return $this->fail('Choose a tenant for this invoice.');
        }
        if ($tenant === $owner) {
            return $this->fail('That is the company that issues the invoices. Choose a customer tenant.');
        }
        $names = $qty = $price = $rate = $desc = $zero = [];
        $rateIn = max(0.0, min(100.0, (float)($post['tax_rate'] ?? 0)));
        foreach ((array)($post['description'] ?? []) as $i => $d) {
            $d = trim((string)$d);
            $q = (float)($post['qty'][$i] ?? 1);
            if ($d === '' || $q <= 0) {
                continue;
            }
            $names[] = $d;
            $desc[] = '';
            $qty[] = $q;
            $price[] = (float)($post['unit_price'][$i] ?? 0);
            $rate[] = $rateIn;
            $zero[] = 0;
        }
        if (!$names) {
            return $this->fail('Add at least one line with a description and quantity.');
        }
        $product = isset(self::PRODUCTS[$post['product_code'] ?? '']) ? $post['product_code'] : 'erpx';
        $issue = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['issue_date'] ?? '')) ? $post['issue_date'] : date('Y-m-d');
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['due_date'] ?? '')) ? $post['due_date'] : date('Y-m-d', strtotime($issue . ' +14 days'));
        $rows = [];
        foreach ($names as $k => $_) {
            $rows[$k] = ['qty' => $qty[$k], 'price' => $price[$k], 'discount_pct' => 0, 'tax_rate' => $rate[$k]];
        }
        $calc = InvoiceService::computeTotals($rows, 'exclusive', 0.0);
        if (isset($calc['error'])) {
            return $this->fail($calc['error']);
        }
        $uid = (int)$this->settings->userdata('id');

        return $this->asOwner($owner, function () use ($owner, $tenant, $names, $desc, $qty, $price, $rate, $zero, $calc, $issue, $due, $product, $post, $uid) {
            $party = $this->customerFor($tenant, $owner);
            $no = SequenceService::documentNo($this->conn, $owner, 'invoice_list', 'invoice_no', 'INV-' . date('Ym') . '-', 'invoice-' . date('Ym'));
            $resp = json_decode((new InvoiceService($this->conn, $this->settings))->saveInvoice([
                'invoice_no' => $no, 'party_id' => $party, 'invoice_date' => $issue, 'due_date' => $due,
                'subtotal' => $calc['subtotal'], 'tax_amount' => $calc['tax'], 'discount' => 0, 'total' => $calc['total'],
                'tax_type' => 'exclusive', 'status' => 'unpaid', 'notes' => trim((string)($post['notes'] ?? '')),
                'item_name' => $names, 'item_desc' => $desc, 'item_qty' => $qty, 'item_price' => $price,
                'item_discount_pct' => $zero, 'item_tax_rate' => $rate,
            ]), true);
            if (($resp['status'] ?? '') !== 'success') {
                return $this->fail((string)($resp['msg'] ?? 'The invoice could not be saved.'));
            }
            $q = $this->conn->query("SELECT id FROM invoice_list WHERE tenant_id = {$owner} AND invoice_no = '" . $this->conn->real_escape_string($no) . "'");
            $invId = $q && ($row = $q->fetch_assoc()) ? (int)$row['id'] : 0;
            if ($invId <= 0) {
                return $this->fail('The invoice could not be found after saving.');
            }
            $this->conn->query("UPDATE invoice_list SET product_code = '{$product}' WHERE tenant_id = {$owner} AND id = {$invId}");
            $this->conn->query("INSERT INTO platform_invoices (tenant_id, owner_invoice_id, created_by) VALUES ({$tenant}, {$invId}, {$uid})");
            $this->settings->log_audit('Billed Tenant', 'Platform', "{$no} to tenant #{$tenant} ({$product}), total {$calc['total']}");
            $this->settings->set_flashdata('success', " Invoice {$no} created in the owner's sales.");
            return json_encode(['status' => 'success', 'id' => (int)$this->conn->query("SELECT id FROM platform_invoices WHERE owner_invoice_id = {$invId}")->fetch_row()[0], 'invoice_no' => $no]);
        });
    }

    /** A link row with its owner invoice, or null. */
    private function link(int $linkId): ?array
    {
        $r = $this->conn->query("SELECT l.id lid, l.tenant_id AS customer_tenant_id, i.* FROM platform_invoices l JOIN invoice_list i ON i.id = l.owner_invoice_id WHERE i.tenant_id = " . $this->ownerId() . " AND l.id = {$linkId}");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }

    /** Receipts are recorded in the owner's books against the invoice. */
    public function recordPayment(array $post): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $inv = $this->link((int)($post['invoice_id'] ?? 0));
        if (!$inv) {
            return $this->fail('Invoice not found.');
        }
        if ($inv['status'] === 'cancelled') {
            return $this->fail('A void invoice cannot take payments.');
        }
        $amount = round((float)($post['amount'] ?? 0), 2);
        $balance = round((float)$inv['total'] - (float)$inv['paid_amount'] - (float)$inv['credited_amount'], 2);
        if ($amount <= 0) {
            return $this->fail('Enter the amount received.');
        }
        if ($amount > $balance + 0.004) {
            return $this->fail('That is more than the balance of ' . number_format($balance, 2) . '.');
        }
        $bank = (int)($post['bank_account_id'] ?? 0);
        if ($bank <= 0) {
            return $this->fail('Choose the bank or cash account the money went into.');
        }
        return $this->asOwner((int)$inv['tenant_id'], function () use ($inv, $amount, $bank, $post) {
            $resp = json_decode((new PaymentService($this->conn, $this->settings))->savePayment([
                'type' => 'receipt', 'party_id' => (int)$inv['party_id'], 'bank_account_id' => $bank,
                'payment_date' => (string)($post['pay_date'] ?? date('Y-m-d')), 'amount' => $amount,
                'method' => strtolower(trim((string)($post['method'] ?? 'bank transfer'))), 'reference' => trim((string)($post['reference'] ?? '')),
                'notes' => 'Invoice ' . $inv['invoice_no'], 'status' => 'posted', 'invoice_alloc' => [(int)$inv['id'] => $amount],
            ]), true);
            if (($resp['status'] ?? '') !== 'success') {
                return $this->fail((string)($resp['msg'] ?? 'The payment could not be saved.'));
            }
            $this->settings->log_audit('Recorded Platform Payment', 'Platform', "{$inv['invoice_no']}: {$amount}");
            $left = $this->conn->query("SELECT status FROM invoice_list WHERE tenant_id = " . (int)$inv['tenant_id'] . " AND id = " . (int)$inv['id'])->fetch_row()[0];
            return json_encode(['status' => 'success', 'invoice_status' => $left]);
        });
    }

    /** Cancel an unpaid invoice in the owner's books (journal reversed, trail kept). */
    public function voidInvoice(int $linkId): string
    {
        if (!$this->guard()) {
            return $this->fail('Access denied.');
        }
        $inv = $this->link($linkId);
        if (!$inv) {
            return $this->fail('Invoice not found.');
        }
        if ((float)$inv['paid_amount'] > 0) {
            return $this->fail('This invoice has payments recorded and cannot be voided. Cancel the receipt first, or raise a credit note.');
        }
        return $this->asOwner((int)$inv['tenant_id'], function () use ($inv) {
            $resp = json_decode((new InvoiceService($this->conn, $this->settings))->saveInvoice([
                'id' => (int)$inv['id'], 'party_id' => (int)$inv['party_id'], 'status' => 'cancelled',
            ]), true);
            if (($resp['status'] ?? '') !== 'success') {
                return $this->fail((string)($resp['msg'] ?? 'The invoice could not be voided.'));
            }
            $this->settings->log_audit('Voided Platform Invoice', 'Platform', (string)$inv['invoice_no']);
            $this->settings->set_flashdata('success', ' Invoice voided.');
            return json_encode(['status' => 'success']);
        });
    }

    /**
     * Invoices read live from the owner's books, newest first; one tenant's only when $tenantId is given.
     * status is paid / partial / unpaid / void.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(int $tenantId = 0): array
    {
        $w = 'WHERE i.tenant_id = ' . $this->ownerId() . ($tenantId > 0 ? ' AND l.tenant_id = ' . $tenantId : '');
        $q = $this->conn->query("SELECT l.id, l.tenant_id, i.id AS owner_invoice_id, i.tenant_id AS owner_id, i.invoice_no, i.invoice_date AS issue_date, i.due_date,
                i.subtotal, i.tax_amount, i.total, i.paid_amount, i.credited_amount, i.notes, i.product_code,
                CASE i.status WHEN 'partially_paid' THEN 'partial' WHEN 'cancelled' THEN 'void' ELSE i.status END AS status,
                t.name AS tname, t.tenant_code, t.address AS taddr, t.tin AS ttin
            FROM platform_invoices l JOIN invoice_list i ON i.id = l.owner_invoice_id JOIN tenants t ON t.id = l.tenant_id
            {$w} ORDER BY i.invoice_date DESC, l.id DESC");
        return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Receipts applied to an owner invoice. */
    public function receipts(int $ownerInvoiceId): array
    {
        $q = $this->conn->query("SELECT p.payment_date AS pay_date, a.amount, p.method, p.reference, p.payment_no FROM payment_allocations a
            JOIN payment_list p ON p.id = a.payment_id WHERE p.tenant_id = " . $this->ownerId() . " AND a.invoice_id = {$ownerInvoiceId} AND p.status <> 'cancelled' ORDER BY p.payment_date, p.id");
        return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Owner revenue by product for the billing page: [product_code => [invoiced, paid]]. */
    public function revenueByProduct(int $owner): array
    {
        $q = $this->conn->query("SELECT COALESCE(NULLIF(product_code,''),'other') p, SUM(subtotal) s, SUM(paid_amount) paid, COUNT(*) n FROM invoice_list
            WHERE tenant_id = {$owner} AND product_code IS NOT NULL AND status <> 'cancelled' GROUP BY 1");
        return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Overdue is shown, not stored: an unpaid or part-paid invoice past its due date. */
    public static function isOverdue(array $inv): bool
    {
        return in_array($inv['status'], ['unpaid', 'partial'], true) && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
    }
}
