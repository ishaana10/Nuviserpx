<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Monthly budgets for income and expense accounts, kept per financial year.
 *
 * A budget row is one account in one month. Amounts are in the account's natural sign (income as a
 * positive credit, expense as a positive debit), the same sign LedgerService::budgetVsActual() compares.
 * Budgets are plans, not postings: they never touch the ledger.
 */
class BudgetService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** The twelve month-start dates of the financial year beginning on $fyStart. */
    public static function fyMonths(string $fyStart): array
    {
        $d = new \DateTimeImmutable(substr($fyStart, 0, 7) . '-01');
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[] = $d->modify("+{$i} months")->format('Y-m-d');
        }
        return $out;
    }

    /** Month-start dates covered by a report from $from to $to. A part month counts as the whole month. */
    public static function monthsInRange(string $from, string $to): array
    {
        $d = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable($to);
        $out = [];
        while ($d <= $end && count($out) < 600) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 month');
        }
        return $out;
    }

    /** Split a yearly amount over 12 months in whole cents; the last month takes the rounding. */
    public static function spread(float $annual): array
    {
        $cents = (int)round($annual * 100);
        $each = intdiv($cents, 12);
        $out = array_fill(0, 12, $each / 100);
        $out[11] = ($cents - $each * 11) / 100;
        return $out;
    }

    /** Parse a typed amount: blank or zero means "no budget"; negatives and junk are rejected (null). */
    public static function cleanAmount(mixed $v): ?float
    {
        $s = trim(str_replace(',', '', (string)$v));
        if ($s === '') {
            return 0.0;
        }
        if (!is_numeric($s) || (float)$s < 0 || (float)$s > 999999999999.0) {
            return null;
        }
        return round((float)$s, 2);
    }

    /** Budget per account over a report window: account id => amount. */
    public static function forRange(mysqli $conn, int $tenantId, string $from, string $to): array
    {
        $months = self::monthsInRange($from, $to);
        if (!$months) {
            return [];
        }
        $first = $months[0];
        $last = end($months);
        $stmt = @$conn->prepare("SELECT account_id, SUM(amount) amt FROM budget_lines
            WHERE tenant_id = ? AND month_start BETWEEN ? AND ? GROUP BY account_id");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $tenantId, $first, $last);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['account_id']] = round((float)$r['amt'], 2);
        }
        return $out;
    }

    /** Saved grid for one financial year: account id => [month start => amount]. */
    public static function grid(mysqli $conn, int $tenantId, string $fyStart): array
    {
        $months = self::fyMonths($fyStart);
        $first = $months[0];
        $last = $months[11];
        $stmt = @$conn->prepare("SELECT account_id, month_start, amount FROM budget_lines WHERE tenant_id = ? AND month_start BETWEEN ? AND ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $tenantId, $first, $last);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['account_id']][$r['month_start']] = (float)$r['amount'];
        }
        return $out;
    }

    /**
     * Save a year's budget. $post: fy_start (Y-m-d), amount[account_id][0..11], annual[account_id] (optional).
     * An annual figure with every month blank is spread evenly over the year. Blank or zero removes the row.
     */
    public function saveBudget(array $post): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $fyStart = trim((string)($post['fy_start'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fyStart) || !strtotime($fyStart)) {
            return $this->fail('Choose a financial year.');
        }
        $months = self::fyMonths($fyStart);

        $valid = [];
        $stmt = $this->conn->prepare("SELECT id, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0");
        $stmt->bind_param('i', $tenant);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
            if (in_array(LedgerService::classify($a['type'])['class'], ['income', 'expense'], true)) {
                $valid[(int)$a['id']] = true;
            }
        }

        $rows = [];
        $errors = [];
        foreach ((array)($post['amount'] ?? []) as $accId => $cells) {
            $accId = (int)$accId;
            if (!isset($valid[$accId])) {
                continue; // not this company's, or not a profit and loss account
            }
            $vals = [];
            foreach (range(0, 11) as $i) {
                $v = self::cleanAmount($cells[$i] ?? '');
                if ($v === null) {
                    $errors[] = "Account #{$accId}, month " . ($i + 1) . ': enter a number of zero or more.';
                    $v = 0.0;
                }
                $vals[$i] = $v;
            }
            if (array_sum($vals) == 0.0 && isset($post['annual'][$accId])) {
                $annual = self::cleanAmount($post['annual'][$accId]);
                if ($annual === null) {
                    $errors[] = "Account #{$accId}: the yearly amount must be a number of zero or more.";
                } elseif ($annual > 0) {
                    $vals = self::spread($annual);
                }
            }
            $rows[$accId] = $vals;
        }
        if ($errors) {
            return $this->fail(implode(' ', array_slice($errors, 0, 3)));
        }

        $this->conn->begin_transaction();
        try {
            $userId = (int)$this->settings->userdata('id');
            $up = $this->conn->prepare("INSERT INTO budget_lines (tenant_id, account_id, month_start, amount, updated_by)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by)");
            $del = $this->conn->prepare("DELETE FROM budget_lines WHERE tenant_id = ? AND account_id = ? AND month_start = ?");
            $saved = 0;
            foreach ($rows as $accId => $vals) {
                foreach ($vals as $i => $v) {
                    $m = $months[$i];
                    if ($v > 0) {
                        $amt = number_format($v, 4, '.', '');
                        $up->bind_param('iissi', $tenant, $accId, $m, $amt, $userId);
                        if (!$up->execute()) {
                            throw new \RuntimeException($this->conn->error);
                        }
                        $saved++;
                    } else {
                        $del->bind_param('iis', $tenant, $accId, $m);
                        $del->execute();
                    }
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The budget could not be saved. Nothing was changed.');
        }

        $this->settings->log_audit('Saved budget', 'Budgets', "Financial year starting {$fyStart}: " . count($rows) . " accounts, {$saved} monthly amounts.");
        $msg = 'Budget saved.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
