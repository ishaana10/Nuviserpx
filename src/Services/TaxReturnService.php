<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * VAT returns. compute() builds the return for a period from the books; file() records it as filed with the tax
 * authority. A filed return keeps the figures as they were filed, cannot overlap another filed return, and can only
 * be filed once the books are closed up to the end of its period, so the documents behind it can no longer change.
 * A filed return is never edited: voiding it (with a reason) frees the period to be filed again.
 */
class TaxReturnService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** Pure: the return's totals from its source lines. */
    public static function totals(array $invoices, array $bills, array $jv): array
    {
        $invNet = $invTax = $billNet = $billTax = 0.0;
        foreach ($invoices as $i) {
            $invNet += (float)$i['total'] - (float)$i['tax_amount'];
            $invTax += (float)$i['tax_amount'];
        }
        foreach ($bills as $b) {
            $billNet += (float)$b['total'] - (float)$b['tax_amount'];
            $billTax += (float)$b['tax_amount'];
        }
        $output = round($invTax + (float)$jv['output_tax'], 2);
        $input = round($billTax + (float)$jv['input_tax'], 2);
        return [
            'taxable_sales' => round($invNet + (float)$jv['output_net'], 2),
            'taxable_purchases' => round($billNet + (float)$jv['input_net'], 2),
            'output_tax' => $output,
            'input_tax' => $input,
            'net_tax' => round($output - $input, 2),
        ];
    }

    /**
     * Pure: why this return cannot be filed, as messages. $filed is the company's filed returns ([from, to] pairs),
     * $lockDate the date the books are closed up to (or null).
     */
    public static function validate(array $p, string $today, array $filed, ?string $lockDate): array
    {
        $errors = [];
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = (string)($p['period_from'] ?? '');
        $to = (string)($p['period_to'] ?? '');
        if (!preg_match($re, $from) || !strtotime($from) || !preg_match($re, $to) || !strtotime($to)) {
            return ['Enter the period the return covers.'];
        }
        if ($from > $to) {
            $errors[] = 'The period starts after it ends.';
        }
        if ($to > $today) {
            $errors[] = 'A return cannot be filed for a period that has not ended.';
        }
        foreach ($filed as [$f, $t]) {
            if ($from <= $t && $to >= $f) {
                $errors[] = "That period overlaps a return already filed for {$f} to {$t}.";
                break;
            }
        }
        if ($lockDate === null || $lockDate < $to) {
            $errors[] = 'Close the books up to ' . $to . ' before filing, so the figures cannot change afterwards.';
        }
        $filedOn = (string)($p['filed_date'] ?? '');
        if (!preg_match($re, $filedOn) || !strtotime($filedOn)) {
            $errors[] = 'Enter the date it was filed.';
        } elseif ($filedOn > $today) {
            $errors[] = 'The filing date cannot be in the future.';
        }
        return $errors;
    }

    /** The return for a period, built from the books: totals plus the documents behind them. */
    public function compute(string $from, string $to): array
    {
        $t = $this->tenantId();
        $invoices = $bills = [];
        $stmt = $this->conn->prepare("SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.discount, i.tax_amount, i.total, i.status, p.name party
            FROM invoice_list i LEFT JOIN party_list p ON p.id = i.party_id
            WHERE i.tenant_id = ? AND i.status NOT IN ('draft','cancelled') AND i.invoice_date BETWEEN ? AND ?
            ORDER BY i.invoice_date, i.id");
        if ($stmt) {
            $stmt->bind_param('iss', $t, $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            $invoices = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt = $this->conn->prepare("SELECT b.id, b.bill_no, b.bill_date, b.total, b.tax_amount, p.name party
            FROM vendor_bill_list b LEFT JOIN party_list p ON p.id = b.vendor_id
            WHERE b.tenant_id = ? AND b.status NOT IN ('draft','pending_approval','cancelled') AND b.tax_amount > 0 AND b.bill_date BETWEEN ? AND ?
            ORDER BY b.bill_date, b.id");
        if ($stmt) {
            $stmt->bind_param('iss', $t, $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            $bills = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        $ledger = new LedgerService($this->conn, $t);
        $jv = LedgerService::vatFromJournalLines($ledger->vatJournalLines($from, $to));
        return [
            'invoices' => $invoices, 'bills' => $bills, 'jv' => $jv,
            'totals' => self::totals($invoices, $bills, $jv),
            'documents' => count($invoices) + count($bills) + count($jv['output']) + count($jv['input']),
        ];
    }

    /** Filed (not voided) returns, newest period first. */
    public function returns(int $limit = 100): array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT r.*, CONCAT(u.firstname, ' ', u.lastname) filer
            FROM tax_returns r LEFT JOIN users u ON u.id = r.filed_by
            WHERE r.tenant_id = ? ORDER BY r.period_to DESC, r.id DESC LIMIT ?");
        $q->bind_param('ii', $t, $limit);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** The filed return covering any day of [from, to], or null. */
    public function filedOverlapping(string $from, string $to): ?array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT * FROM tax_returns WHERE tenant_id = ? AND status = 'filed' AND period_from <= ? AND period_to >= ? ORDER BY period_to LIMIT 1");
        $q->bind_param('iss', $t, $to, $from);
        $q->execute();
        return $q->get_result()->fetch_assoc() ?: null;
    }

    public function file(array $p): string
    {
        $t = $this->tenantId();
        $lock = "tax_return_{$t}";
        $g = $this->conn->prepare("SELECT GET_LOCK(?, 5) ok");
        $g->bind_param('s', $lock);
        $g->execute();
        if ((int)($g->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) {
            return $this->fail('Another return is being filed. Please try again.');
        }
        try {
            return $this->fileLocked($p, $t);
        } finally {
            $r = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
            $r->bind_param('s', $lock);
            $r->execute();
            $r->get_result();
        }
    }

    private function fileLocked(array $p, int $t): string
    {
        $filed = [];
        $q = $this->conn->prepare("SELECT period_from, period_to FROM tax_returns WHERE tenant_id = ? AND status = 'filed'");
        $q->bind_param('i', $t);
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $filed[] = [$r['period_from'], $r['period_to']];
        }
        $errors = self::validate($p, date('Y-m-d'), $filed, PeriodLockService::lockDate($this->conn, $t));
        if ($errors) {
            return $this->fail(implode(' ', $errors));
        }
        $from = (string)$p['period_from'];
        $to = (string)$p['period_to'];
        $c = $this->compute($from, $to);
        $tot = $c['totals'];
        $ref = mb_substr(trim((string)($p['reference'] ?? '')), 0, 100);
        $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 500);
        $date = (string)$p['filed_date'];
        $user = (int)$this->settings->userdata('id');
        $docs = (int)$c['documents'];
        $s = fn (float $v) => number_format($v, 2, '.', '');
        [$sales, $purch, $out, $in, $net] = [$s($tot['taxable_sales']), $s($tot['taxable_purchases']), $s($tot['output_tax']), $s($tot['input_tax']), $s($tot['net_tax'])];
        $ins = $this->conn->prepare("INSERT INTO tax_returns (tenant_id, period_from, period_to, taxable_sales, taxable_purchases, output_tax, input_tax, net_tax, documents, reference, note, filed_date, filed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isssssssisssi', $t, $from, $to, $sales, $purch, $out, $in, $net, $docs, $ref, $note, $date, $user);
        if (!$ins->execute()) {
            return $this->fail('The return could not be saved.');
        }
        $this->settings->log_audit('Filed VAT Return', 'Tax', "{$from} to {$to}: output {$out}, input {$in}, net {$net}" . ($ref !== '' ? ", ref {$ref}" : '') . '.');
        $word = $tot['net_tax'] >= 0 ? 'payable' : 'refundable';
        return json_encode(['status' => 'success', 'msg' => "Return for {$from} to {$to} recorded as filed (net {$net} {$word}).", 'id' => (int)$this->conn->insert_id]);
    }

    public function void(int $id, string $reason): string
    {
        $t = $this->tenantId();
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            return $this->fail('Say why this return is being voided.');
        }
        $user = (int)$this->settings->userdata('id');
        $up = $this->conn->prepare("UPDATE tax_returns SET status = 'voided', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ? AND tenant_id = ? AND status = 'filed'");
        $up->bind_param('isii', $user, $reason, $id, $t);
        $up->execute();
        if ($up->affected_rows !== 1) {
            return $this->fail('Only a filed return can be voided.');
        }
        $this->settings->log_audit('Voided VAT Return', 'Tax', "Return #{$id}: {$reason}");
        return json_encode(['status' => 'success', 'msg' => 'Return voided. The period can be filed again.']);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
