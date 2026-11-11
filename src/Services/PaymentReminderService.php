<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Chases unpaid invoices. Each company picks the days after the due date to remind on (for example 3, 10 and 20) and,
 * optionally, a late fee raised once as its own invoice. A reminder stage is sent once per invoice, and a late fee is
 * charged once per invoice: both are enforced by unique keys, not just by checks.
 */
class PaymentReminderService
{
    public const MAX_STAGES = 6;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: "10, 3,20,3" becomes [3, 10, 20]. Only whole days from 1 to 365, at most MAX_STAGES. */
    public static function parseStages(string $text): array
    {
        $days = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (ctype_digit($p) && (int)$p >= 1 && (int)$p <= 365) {
                $days[(int)$p] = (int)$p;
            }
        }
        sort($days);
        return array_slice(array_values($days), 0, self::MAX_STAGES);
    }

    /**
     * Pure: what to do for an invoice that is $daysOverdue days late. Only the latest stage that has been reached is sent;
     * earlier stages that were never sent (for example reminders were just switched on) are marked skipped so the
     * customer is not sent several emails at once.
     * @return array{send: ?int, skip: int[]}
     */
    public static function plan(int $daysOverdue, array $stages, array $alreadyHandled): array
    {
        $reached = array_values(array_filter($stages, fn($s) => $s <= $daysOverdue && !in_array($s, $alreadyHandled, true)));
        if (!$reached) {
            return ['send' => null, 'skip' => []];
        }
        $send = max($reached);
        return ['send' => $send, 'skip' => array_values(array_filter($reached, fn($s) => $s !== $send))];
    }

    /** Pure: the late fee on a balance. A percentage or a fixed amount, never above the cap when one is set, never more than the balance. */
    public static function lateFee(float $balance, string $type, float $value, float $cap = 0.0): float
    {
        if ($balance <= 0 || $value <= 0) {
            return 0.0;
        }
        $fee = $type === 'fixed' ? $value : $balance * min($value, 100.0) / 100.0;
        if ($cap > 0) {
            $fee = min($fee, $cap);
        }
        return round(min($fee, $balance), 2);
    }

    public static function feeDue(int $daysOverdue, int $graceDays, bool $alreadyCharged): bool
    {
        return !$alreadyCharged && $daysOverdue >= max(1, $graceDays);
    }

    public static function defaults(): array
    {
        return ['enabled' => 0, 'stages' => '3,10,20', 'fee_enabled' => 0, 'fee_type' => 'percent', 'fee_value' => 0.0, 'fee_grace_days' => 14, 'fee_cap' => 0.0, 'note' => ''];
    }

    public function getSettings(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT * FROM `reminder_settings` WHERE tenant_id = ?");
        $q->bind_param('i', $t);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        return $row ? array_merge(self::defaults(), $row) : self::defaults();
    }

    public function saveSettings(array $p): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $stages = self::parseStages((string)($p['stages'] ?? ''));
        $enabled = !empty($p['enabled']) ? 1 : 0;
        if ($enabled && !$stages) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter at least one reminder day, for example 3, 10, 20.']);
        }
        $feeEnabled = !empty($p['fee_enabled']) ? 1 : 0;
        $type = ($p['fee_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = round((float)($p['fee_value'] ?? 0), 2);
        $cap = round((float)($p['fee_cap'] ?? 0), 2);
        $grace = (int)($p['fee_grace_days'] ?? 14);
        if ($feeEnabled && ($value <= 0 || ($type === 'percent' && $value > 100))) {
            return json_encode(['status' => 'failed', 'msg' => $type === 'percent' ? 'Late fee must be between 0 and 100 percent.' : 'Enter the late fee amount.']);
        }
        if ($grace < 1 || $grace > 365 || $cap < 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Check the late fee days and cap.']);
        }
        $stageText = implode(',', $stages) ?: '3,10,20';
        $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 500);
        $q = $this->conn->prepare("INSERT INTO `reminder_settings` (tenant_id, enabled, stages, fee_enabled, fee_type, fee_value, fee_grace_days, fee_cap, note)
            VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), stages = VALUES(stages), fee_enabled = VALUES(fee_enabled),
            fee_type = VALUES(fee_type), fee_value = VALUES(fee_value), fee_grace_days = VALUES(fee_grace_days), fee_cap = VALUES(fee_cap), note = VALUES(note)");
        $q->bind_param('iisisdids', $t, $enabled, $stageText, $feeEnabled, $type, $value, $grace, $cap, $note);
        if (!$q->execute()) {
            return json_encode(['status' => 'failed', 'msg' => 'Could not save the settings.']);
        }
        $this->settings->log_audit('Updated Payment Reminder Settings', 'Invoices', "Reminders " . ($enabled ? "on ({$stageText} days)" : 'off') . ', late fee ' . ($feeEnabled ? "{$value} {$type}" : 'off'));
        return json_encode(['status' => 'success', 'msg' => 'Reminder settings saved.']);
    }

    /**
     * Send the reminders and raise the late fees that are due today for this company.
     * @return array{sent: array, skipped_no_email: array, fees: array, problems: array}
     */
    public function run(string $today): array
    {
        $res = ['sent' => [], 'skipped_no_email' => [], 'fees' => [], 'problems' => []];
        $cfg = $this->getSettings();
        $stages = self::parseStages((string)$cfg['stages']);
        $remind = (int)$cfg['enabled'] === 1 && $stages;
        $fees = (int)$cfg['fee_enabled'] === 1 && (float)$cfg['fee_value'] > 0;
        if (!$remind && !$fees) {
            return $res;
        }
        $t = (int)$this->settings->active_tenant_id();
        $lock = 'payment_reminders_' . $t;
        $got = $this->conn->query("SELECT GET_LOCK('" . $this->conn->real_escape_string($lock) . "', 5) AS l")->fetch_assoc();
        if (empty($got['l'])) {
            $res['problems'][] = 'Another reminder run is in progress.';
            return $res;
        }
        try {
            $q = $this->conn->prepare("SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.balance, i.total, i.party_id, i.late_fee_invoice_id,
                    p.name AS party_name, p.email AS party_email, DATEDIFF(?, i.due_date) AS days_overdue
                FROM `invoice_list` i INNER JOIN `party_list` p ON p.id = i.party_id
                WHERE i.tenant_id = ? AND i.status IN ('unpaid','partially_paid') AND i.balance > 0 AND i.due_date IS NOT NULL
                  AND i.due_date < ? AND i.late_fee_for_id IS NULL ORDER BY i.due_date, i.id");
            $q->bind_param('sis', $today, $t, $today);
            $q->execute();
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $inv) {
                if ($remind) {
                    $this->remindOne($inv, $stages, $cfg, $res);
                }
                if ($fees && self::feeDue((int)$inv['days_overdue'], (int)$cfg['fee_grace_days'], $inv['late_fee_invoice_id'] !== null)) {
                    $this->feeOne($inv, $cfg, $today, $res);
                }
            }
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('" . $this->conn->real_escape_string($lock) . "')");
        }
        if ($res['sent'] || $res['fees']) {
            $this->settings->log_audit('Ran Payment Reminders', 'Invoices', count($res['sent']) . ' reminder(s) sent, ' . count($res['fees']) . ' late fee(s) raised');
        }
        return $res;
    }

    private function remindOne(array $inv, array $stages, array $cfg, array &$res): void
    {
        $t = (int)$this->settings->active_tenant_id();
        $iid = (int)$inv['id'];
        $h = $this->conn->prepare("SELECT stage_days FROM invoice_reminders WHERE invoice_id = ?");
        $h->bind_param('i', $iid);
        $h->execute();
        $handled = array_map('intval', array_column($h->get_result()->fetch_all(MYSQLI_ASSOC), 'stage_days'));
        $plan = self::plan((int)$inv['days_overdue'], $stages, $handled);
        $days = (int)$inv['days_overdue'];
        $bal = (float)$inv['balance'];
        foreach ($plan['skip'] as $s) {
            $this->logStage($t, $iid, $s, 'skipped', null, $days, $bal);
        }
        if ($plan['send'] === null) {
            return;
        }
        $email = trim((string)$inv['party_email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $res['skipped_no_email'][] = [$inv['invoice_no'], $inv['party_name']];
            return;
        }
        require_once(base_app . 'classes/Emailer.php');
        $sys = ['name' => $this->settings->info('name'), 'email' => $this->settings->info('email'), 'currency' => $this->settings->currency_tag()];
        $ok = \Emailer::send_payment_reminder_email($email, $inv, $days, $sys, (string)($cfg['note'] ?? ''));
        if (!$ok) {
            $res['problems'][] = "{$inv['invoice_no']}: the email to {$email} could not be sent. Check the SMTP settings.";
            return;
        }
        $this->logStage($t, $iid, $plan['send'], 'sent', $email, $days, $bal);
        $res['sent'][] = [$inv['invoice_no'], $inv['party_name'], $plan['send']];
    }

    private function logStage(int $t, int $iid, int $stage, string $status, ?string $email, int $days, float $bal): void
    {
        $q = $this->conn->prepare("INSERT IGNORE INTO invoice_reminders (tenant_id, invoice_id, stage_days, status, sent_to, days_overdue, balance) VALUES (?,?,?,?,?,?,?)");
        $q->bind_param('iiissid', $t, $iid, $stage, $status, $email, $days, $bal);
        $q->execute();
    }

    private function feeOne(array $inv, array $cfg, string $today, array &$res): void
    {
        $t = (int)$this->settings->active_tenant_id();
        $fee = self::lateFee((float)$inv['balance'], (string)$cfg['fee_type'], (float)$cfg['fee_value'], (float)$cfg['fee_cap']);
        if ($fee <= 0) {
            return;
        }
        $oid = (int)$inv['id'];
        $svc = new InvoiceService($this->conn, $this->settings);
        $post = [
            'party_id' => (int)$inv['party_id'], 'invoice_no' => '', 'invoice_date' => $today, 'discount' => 0, 'tax_type' => 'none',
            'item_name' => ['Late payment fee'],
            'item_desc' => ["Late fee on invoice {$inv['invoice_no']}, {$inv['days_overdue']} days overdue"],
            'item_qty' => [1], 'item_price' => [$fee], 'item_discount_pct' => [0], 'item_tax_rate' => [0],
            'subtotal' => $fee, 'tax_amount' => 0, 'total' => $fee,
            'notes' => "Late payment fee for invoice {$inv['invoice_no']}",
        ];
        $out = json_decode($svc->saveInvoice($post), true);
        if (($out['status'] ?? '') !== 'success' || empty($out['id'])) {
            $res['problems'][] = "{$inv['invoice_no']}: late fee not raised. " . ($out['msg'] ?? '');
            return;
        }
        $fid = (int)$out['id'];
        $linked = false;
        try {
            $u = $this->conn->prepare("UPDATE invoice_list SET late_fee_for_id = ? WHERE id = ? AND tenant_id = ? AND late_fee_for_id IS NULL");
            $u->bind_param('iii', $oid, $fid, $t);
            $linked = $u->execute() && $u->affected_rows === 1;
        } catch (\mysqli_sql_exception $e) {
            $linked = false;
        }
        if (!$linked) {
            // A fee already exists for this invoice (the unique key refused the link): take the extra one back out.
            $svc->saveInvoice(['id' => $fid, 'status' => 'cancelled', 'party_id' => (int)$inv['party_id']]);
            $res['problems'][] = "{$inv['invoice_no']}: a late fee was already charged.";
            return;
        }
        $m = $this->conn->prepare("UPDATE invoice_list SET late_fee_invoice_id = ? WHERE id = ? AND tenant_id = ?");
        $m->bind_param('iii', $fid, $oid, $t);
        $m->execute();
        $no = $this->conn->query("SELECT invoice_no FROM invoice_list WHERE id = {$fid}")->fetch_assoc()['invoice_no'] ?? '';
        $res['fees'][] = [$inv['invoice_no'], $no, $fee];
    }

    public function recent(int $limit = 50): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $limit = max(1, min(200, $limit));
        $q = $this->conn->prepare("SELECT r.*, i.invoice_no, p.name AS party_name FROM invoice_reminders r
            LEFT JOIN invoice_list i ON i.id = r.invoice_id LEFT JOIN party_list p ON p.id = i.party_id
            WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT {$limit}");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
