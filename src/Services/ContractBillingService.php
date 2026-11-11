<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Raises the recurring invoices for contracts. Each billing period gets exactly one invoice: the pair
 * (contract_id, billing_period_start) is unique on invoice_list, so a repeat run or a second user cannot double-bill.
 */
class ContractBillingService
{
    public const STEP_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];
    /** Most periods raised for one contract in a single run, so a mistyped start date cannot flood the ledger. */
    public const MAX_CATCH_UP = 12;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: start of billing period number $n (0 = the start date), anchored on the start date so 31 Jan + 1 month is 28/29 Feb then 31 Mar. */
    public static function periodStart(string $start, string $frequency, int $n): string
    {
        $step = self::STEP_MONTHS[$frequency] ?? 1;
        $d = new \DateTimeImmutable($start);
        $day = (int)$d->format('j');
        $months = $step * $n;
        $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $last = (int)$first->format('t');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last))->format('Y-m-d');
    }

    /** Pure: period starts that are due on $today and not yet billed. $lastBilled is the latest billed period start, or null. */
    public static function duePeriods(string $start, string $frequency, ?string $end, ?string $lastBilled, string $today, int $max = self::MAX_CATCH_UP): array
    {
        $n = 0;
        if ($lastBilled !== null) {
            while (self::periodStart($start, $frequency, $n) <= $lastBilled) {
                $n++;
                if ($n > 2000) return [];
            }
        }
        $out = [];
        while (count($out) < $max) {
            $p = self::periodStart($start, $frequency, $n);
            if ($p > $today || ($end !== null && $end !== '' && $p > $end)) break;
            $out[] = $p;
            $n++;
        }
        return $out;
    }

    public static function periodLabel(string $periodStart, string $frequency): string
    {
        $end = (new \DateTimeImmutable(self::periodStart($periodStart, $frequency, 1)))->modify('-1 day');
        return date('d M Y', strtotime($periodStart)) . ' to ' . $end->format('d M Y');
    }

    /** Pure: the invoice form fields for one period of a contract. */
    public static function invoicePost(array $contract, string $periodStart): array
    {
        $amount = round((float)$contract['amount'], 2);
        $rate = max(0.0, min(100.0, (float)($contract['tax_rate'] ?? 0)));
        $mode = in_array($contract['tax_type'] ?? '', ['exclusive', 'inclusive', 'none'], true) ? $contract['tax_type'] : 'exclusive';
        if ($rate == 0.0) $mode = 'none';
        $calc = InvoiceService::computeTotals([['qty' => 1, 'price' => $amount, 'discount_pct' => 0, 'tax_rate' => $rate]], $mode, 0.0);
        return [
            'party_id' => (int)$contract['party_id'],
            'invoice_no' => '',
            'invoice_date' => $periodStart,
            'discount' => 0,
            'tax_type' => $mode,
            'item_name' => [$contract['title']],
            'item_desc' => [$contract['contract_no'] . ' - ' . self::periodLabel($periodStart, (string)$contract['billing_frequency'])],
            'item_qty' => [1],
            'item_price' => [$amount],
            'item_discount_pct' => [0],
            'item_tax_rate' => [$rate],
            'subtotal' => $calc['subtotal'] ?? 0,
            'tax_amount' => $calc['tax'] ?? 0,
            'total' => $calc['total'] ?? 0,
            'notes' => 'Recurring invoice for contract ' . $contract['contract_no'],
        ];
    }

    /**
     * Raise every due invoice for the company's active contracts, or for one contract.
     * Returns ['made' => [[contract_no, invoice_no, period]], 'problems' => [[contract_no, message]]].
     */
    public function run(string $today, ?int $contractId = null): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $sql = "SELECT * FROM `contracts` WHERE tenant_id = ? AND status = 'active' AND amount > 0 AND start_date <= ?";
        $types = 'is'; $args = [$t, $today];
        if ($contractId) { $sql .= " AND id = ?"; $types .= 'i'; $args[] = $contractId; }
        $stmt = $this->conn->prepare($sql . " ORDER BY id");
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $res = ['made' => [], 'problems' => []];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            $this->runOne($c, $today, $res);
        }
        if ($res['made']) {
            $this->settings->log_audit('Generated Recurring Invoices', 'Contracts', count($res['made']) . ' invoice(s) raised from contracts');
        }
        return $res;
    }

    private function runOne(array $c, string $today, array &$res): void
    {
        $lockName = 'contract_bill_' . (int)$c['tenant_id'] . '_' . (int)$c['id'];
        $got = $this->conn->query("SELECT GET_LOCK('" . $this->conn->real_escape_string($lockName) . "', 5) AS l")->fetch_assoc();
        if (empty($got['l'])) {
            $res['problems'][] = [$c['contract_no'], 'Another run is billing this contract. Try again shortly.'];
            return;
        }
        try {
            $q = $this->conn->prepare("SELECT MAX(billing_period_start) AS m FROM invoice_list WHERE tenant_id = ? AND contract_id = ?");
            $tid = (int)$c['tenant_id']; $cid = (int)$c['id'];
            $q->bind_param('ii', $tid, $cid);
            $q->execute();
            $last = $q->get_result()->fetch_assoc()['m'] ?? null;
            $periods = self::duePeriods((string)$c['start_date'], (string)$c['billing_frequency'], $c['end_date'] ?: null, $last, $today);
            $svc = new InvoiceService($this->conn, $this->settings);
            foreach ($periods as $p) {
                $out = json_decode($svc->saveInvoice(self::invoicePost($c, $p)), true);
                if (($out['status'] ?? '') !== 'success' || empty($out['id'])) {
                    $res['problems'][] = [$c['contract_no'], 'Period ' . $p . ': ' . ($out['msg'] ?? 'the invoice could not be saved.')];
                    break; // later periods wait, so they are never raised out of order
                }
                $invId = (int)$out['id'];
                $u = $this->conn->prepare("UPDATE invoice_list SET contract_id = ?, billing_period_start = ? WHERE id = ? AND tenant_id = ? AND contract_id IS NULL");
                $u->bind_param('isii', $cid, $p, $invId, $tid);
                try {
                    $ok = $u->execute();
                } catch (\mysqli_sql_exception $e) {
                    $ok = false;
                }
                if (!$ok || $u->affected_rows !== 1) {
                    // The period is already billed (unique key) or the link failed: take this invoice back out rather than bill twice.
                    $res['problems'][] = [$c['contract_no'], 'Period ' . $p . ' was already billed, so the extra invoice was cancelled.'];
                    $svc->saveInvoice(['id' => $invId, 'status' => 'cancelled', 'party_id' => (int)$c['party_id']]);
                    break;
                }
                $no = $this->conn->query("SELECT invoice_no FROM invoice_list WHERE id = {$invId}")->fetch_assoc()['invoice_no'] ?? '';
                $res['made'][] = [$c['contract_no'], $no, $p];
            }
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('" . $this->conn->real_escape_string($lockName) . "')");
        }
    }

    /** The next period to be billed for display, or null when the contract has finished or is not active. */
    public function nextDue(array $c): ?string
    {
        if (($c['status'] ?? '') !== 'active') return null;
        $q = $this->conn->prepare("SELECT MAX(billing_period_start) AS m FROM invoice_list WHERE tenant_id = ? AND contract_id = ?");
        $tid = (int)$c['tenant_id']; $cid = (int)$c['id'];
        $q->bind_param('ii', $tid, $cid);
        $q->execute();
        $last = $q->get_result()->fetch_assoc()['m'] ?? null;
        $far = '9999-12-31';
        $p = self::duePeriods((string)$c['start_date'], (string)$c['billing_frequency'], $c['end_date'] ?: null, $last, $far, 1)[0] ?? null;
        return $p;
    }
}
