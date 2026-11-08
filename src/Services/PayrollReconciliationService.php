<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Proves payroll records and the ledger agree. For every posted payroll it rebuilds the journal the record should
 * have produced and compares it, account by account, with the lines the payroll's journal really holds. It also
 * checks each record adds up on its own (gross less pension, tax and deductions equals net pay).
 * Anything over half a cent is reported.
 */
class PayrollReconciliationService
{
    public const TOLERANCE = 0.005;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pure: the ledger lines a payroll record should have, as account name => [side, amount].
     * Employer levies come from employer cost, so levies without a payslip column are still covered.
     */
    public static function expected(array $p): array
    {
        $gross = round((float)$p['gross_salary'], 2);
        $erPen = round((float)$p['employer_fnpf'], 2);
        $levies = round((float)$p['employer_cost'] - $gross - $erPen, 2);
        $loan = round((float)($p['loan_recovery'] ?? 0), 2);
        $a = PayrollPostingService::ACCOUNTS;
        return [
            $a['wages'][0]      => ['debit', $gross],
            $a['er_pension'][0] => ['debit', $erPen],
            $a['er_levies'][0]  => ['debit', $levies],
            $a['pension'][0]    => ['credit', round((float)$p['employee_fnpf'] + $erPen, 2)],
            $a['tax'][0]        => ['credit', round((float)$p['paye_tax'] + (float)($p['srt_tax'] ?? 0), 2)],
            $a['levies'][0]     => ['credit', $levies],
            $a['other'][0]      => ['credit', round((float)$p['deductions'] - $loan, 2)],
            $a['loans'][0]      => ['credit', $loan],
            $a['net'][0]        => ['credit', round((float)$p['net_salary'], 2)],
        ];
    }

    /**
     * Pure: differences between expected and actual lines. $actual is account name => [side, amount] (summed per side).
     * An account missing from $actual counts as zero. Returns a list of [account, side, expected, actual, difference].
     */
    public static function compare(array $expected, array $actual): array
    {
        $out = [];
        foreach ($expected as $name => [$side, $amount]) {
            $got = (float)($actual[$name][$side] ?? 0);
            if (abs($got - $amount) > self::TOLERANCE) {
                $out[] = [$name, $side, $amount, round($got, 2), round($got - $amount, 2)];
            }
        }
        foreach ($actual as $name => $sides) {
            if (!isset($expected[$name]) && (abs((float)($sides['debit'] ?? 0)) > self::TOLERANCE || abs((float)($sides['credit'] ?? 0)) > self::TOLERANCE)) {
                $out[] = [$name, 'unexpected', 0.0, round((float)(($sides['debit'] ?? 0) + ($sides['credit'] ?? 0)), 2), round((float)(($sides['debit'] ?? 0) + ($sides['credit'] ?? 0)), 2)];
            }
        }
        return $out;
    }

    /** Pure: does the record add up on its own? Returns the amount it is out by (0 when it adds up). */
    public static function recordGap(array $p): float
    {
        $net = round((float)$p['gross_salary'] - (float)$p['employee_fnpf'] - (float)$p['paye_tax'] - (float)($p['srt_tax'] ?? 0) - (float)$p['deductions'], 2);
        $gap = round((float)$p['net_salary'] - $net, 2);
        return abs($gap) > self::TOLERANCE ? $gap : 0.0;
    }

    /**
     * Check the active company's posted payroll, optionally for one salary month.
     *
     * @return array{checked:int,problems:array<int,array<string,mixed>>}
     */
    public function check(?string $month = null): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $sql = "SELECT p.*, CONCAT(e.firstname, ' ', e.lastname) emp_name FROM payroll_list p
            JOIN employee_list e ON e.id = p.employee_id AND e.tenant_id = p.tenant_id
            WHERE p.tenant_id = ? AND p.status = 'posted'" . ($month !== null ? " AND p.salary_month = ?" : "") . " ORDER BY p.id";
        $q = $this->conn->prepare($sql);
        if ($month !== null) {
            $q->bind_param('is', $t, $month);
        } else {
            $q->bind_param('i', $t);
        }
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);

        $side = LedgerService::SIDE_SQL;
        $items = $this->conn->prepare("SELECT a.name, {$side} s, SUM(ji.amount) amt FROM journal_items ji
            JOIN account_list a ON a.id = ji.account_id
            LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE ji.journal_id = ? GROUP BY a.name, s");
        $problems = [];
        foreach ($rows as $p) {
            $issues = [];
            if ($gap = self::recordGap($p)) {
                $issues[] = ['record', 'record', 0.0, $gap, $gap];
            }
            if (empty($p['journal_id'])) {
                $issues[] = ['journal', 'missing', 0.0, 0.0, 0.0];
            } else {
                $jid = (int)$p['journal_id'];
                $items->bind_param('i', $jid);
                $items->execute();
                $actual = [];
                foreach ($items->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                    $actual[$r['name']][(int)$r['s'] === 1 ? 'debit' : 'credit'] = round((float)$r['amt'], 2);
                }
                $issues = array_merge($issues, self::compare(self::expected($p), $actual));
            }
            if ($issues) {
                $problems[] = ['id' => (int)$p['id'], 'ref' => $p['payroll_run_ref'], 'month' => $p['salary_month'], 'employee' => $p['emp_name'], 'issues' => $issues];
            }
        }
        return ['checked' => count($rows), 'problems' => $problems];
    }
}
