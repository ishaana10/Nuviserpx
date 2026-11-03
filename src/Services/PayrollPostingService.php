<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Books a payroll run to the ledger.
 *
 *   Dr Salaries and Wages               gross pay
 *   Dr Employer Pension Expense         employer pension contribution
 *   Dr Employer Levies Expense          employer levies
 *       Cr Pension Payable              employee + employer pension (owed to the fund)
 *       Cr Income Tax Withheld Payable  income tax withheld (owed to the tax authority)
 *       Cr Employer Levies Payable      employer levies
 *       Cr Other Payroll Deductions Payable   other deductions taken from pay
 *       Cr Net Salaries Payable         net pay owed to employees (cleared to the bank when salaries are paid)
 *
 * Paying the salaries is a separate step (PayrollPaymentService): Dr Net Salaries Payable, Cr Bank.
 * So the ledger shows the full cost of employing people and what is still owed to each authority,
 * instead of just net pay against cash.
 */
class PayrollPostingService
{
    public const ACCOUNTS = [
        'wages'      => ['Salaries and Wages', 'Expense', 'Gross pay of employees'],
        'er_pension' => ['Employer Pension Expense', 'Expense', 'Employer contributions to the pension fund'],
        'er_levies'  => ['Employer Levies Expense', 'Expense', 'Employer payroll levies'],
        'pension'    => ['Pension Payable', 'Current Liability', 'Employee and employer pension contributions owed to the fund'],
        'tax'        => ['Income Tax Withheld Payable', 'Current Liability', 'Income tax withheld from pay, owed to the tax authority'],
        'levies'     => ['Employer Levies Payable', 'Current Liability', 'Employer payroll levies owed'],
        'net'        => ['Net Salaries Payable', 'Current Liability', 'Net pay owed to employees until it is paid'],
        'other'      => ['Other Payroll Deductions Payable', 'Current Liability', 'Deductions taken from pay and owed to others'],
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pure: the journal lines [account_id, side, amount] for one pay slip. $acc maps the ACCOUNTS keys to account ids. Zero lines are left out. Throws if the figures do not balance to the cent.
     */
    public static function lines(array $p, array $acc): array
    {
        $gross   = round((float)$p['gross'], 2);
        $empP    = round((float)$p['employee_pension'], 2);
        $erP     = round((float)$p['employer_pension'], 2);
        $tax     = round((float)$p['income_tax'], 2);
        $levies  = round((float)$p['levies'], 2);
        $other   = round((float)$p['deductions'], 2);
        $net     = round((float)$p['net'], 2);

        $debits = [
            ['wages', $gross], ['er_pension', $erP], ['er_levies', $levies],
        ];
        $credits = [
            ['pension', round($empP + $erP, 2)], ['tax', $tax], ['levies', $levies], ['other', $other], ['net', $net],
        ];
        $dr = round(array_sum(array_column($debits, 1)), 2);
        $cr = round(array_sum(array_column($credits, 1)), 2);
        if (abs($dr - $cr) >= 0.005) {
            throw new \RuntimeException("Payroll figures do not balance (debits {$dr}, credits {$cr}).");
        }
        $out = [];
        foreach ($debits as [$k, $amt]) {
            if ($amt > 0) $out[] = [(int)$acc[$k], TaxService::DEBIT, $amt];
        }
        foreach ($credits as [$k, $amt]) {
            if ($amt > 0) $out[] = [(int)$acc[$k], TaxService::CREDIT, $amt];
        }
        return $out;
    }

    /** Figures from PayrollEngine::calculate() in the shape lines() wants. */
    public static function figures(array $calc): array
    {
        return [
            'gross' => $calc['gross_salary'],
            'employee_pension' => $calc['employee_pension'] ?? $calc['employee_fnpf'],
            'employer_pension' => $calc['employer_pension'] ?? $calc['employer_fnpf'],
            'income_tax' => (float)$calc['paye_tax'] + (float)($calc['srt_tax'] ?? 0),
            'levies' => $calc['employer_levies_total'] ?? 0,
            'deductions' => $calc['deductions'],
            'net' => $calc['net_salary'],
        ];
    }

    /** Account ids for the tenant, creating missing payroll accounts. */
    public function accounts(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $ids = [];
        foreach (self::ACCOUNTS as $key => [$name, $type, $desc]) {
            $ids[$key] = $this->ensure($t, $name, $type, $desc);
        }
        return $ids;
    }

    private function ensure(int $t, string $name, string $type, string $desc): int
    {
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $find->bind_param('is', $t, $name);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => $name, 'type' => $type, 'description' => $desc, 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            throw new \RuntimeException("Could not create the {$name} account.");
        }
        $find->execute();
        $id = (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
        if ($id === 0) {
            throw new \RuntimeException("Could not create the {$name} account.");
        }
        return $id;
    }

    /** Replace a journal's items with the payroll posting for these figures. */
    public function writeJournalItems(int $journalId, array $calc): void
    {
        $lines = self::lines(self::figures($calc), $this->accounts());
        $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = " . (int)$journalId);
        if (!(new TaxService($this->conn, $this->settings))->writeLines($journalId, $lines)) {
            throw new \RuntimeException('Could not write the payroll journal lines.');
        }
    }
}
