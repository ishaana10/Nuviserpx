<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Gives a company its own chart of accounts.
 *
 * Accounts are per company (every row carries tenant_id), but nothing used to create them: a new company
 * started with an empty chart and invoices fell back to hard-coded account ids that belong to someone
 * else. This seeds a standard small-business chart, including the accounts that automatic postings look
 * for by name (receivables, payables, sales, purchases, VAT, opening-balance equity).
 */
class ChartProvisioner
{
    /** [code, name, type, description] */
    public const STANDARD_CHART = [
        ['1010', 'Bank - Current Account', 'Bank', 'Main operating bank account'],
        ['1020', 'Petty Cash', 'Bank', 'Cash on hand'],
        ['1100', 'Accounts Receivable', 'Current Asset', 'Money owed by customers'],
        ['1110', 'VAT Input', 'Current Asset', 'VAT paid on purchases, reclaimable from the tax authority'],
        ['1200', 'Inventory', 'Inventory', 'Stock held for sale'],
        ['1300', 'Prepayments', 'Prepayment', 'Expenses paid in advance'],
        ['1500', 'Office Equipment', 'Fixed Asset', 'Computers, furniture and equipment at cost'],
        ['1590', 'Accumulated Depreciation', 'Fixed Asset', 'Depreciation charged to date'],
        ['2010', 'Accounts Payable', 'Current Liability', 'Money owed to suppliers'],
        ['2020', 'VAT Output', 'Current Liability', 'VAT collected on sales, payable to the tax authority'],
        ['2030', 'Payroll Liabilities', 'Current Liability', 'Wages, PAYE and pension contributions owed'],
        ['2500', 'Loans', 'Non-current Liability', 'Loans repayable after more than a year'],
        ['3010', "Owner's Capital", 'Equity', 'Capital contributed by the owners'],
        ['3020', 'Retained Earnings', 'Equity', 'Profits kept in the business'],
        ['3999', 'Opening Balance Equity', 'Equity', 'Balancing account for opening balances'],
        ['4010', 'Sales Revenue', 'Revenue', 'Income from sales'],
        ['4900', 'Other Income', 'Other Income', 'Interest and other non-trading income'],
        ['5010', 'Cost of Sales', 'Direct Costs', 'Direct cost of goods sold'],
        ['5020', 'Purchases', 'Direct Costs', 'Goods and services bought for resale or production'],
        ['5100', 'Salaries and Wages', 'Expense', 'Staff costs'],
        ['5200', 'Rent', 'Expense', 'Premises rent'],
        ['5300', 'Utilities', 'Expense', 'Electricity, water, phone and internet'],
        ['5400', 'Office Supplies', 'Expense', 'Stationery and consumables'],
        ['5900', 'Depreciation', 'Depreciation', 'Depreciation expense'],
        ['5990', 'General Expenses', 'Expense', 'Other running costs'],
    ];

    /** True if every entry is a type the chart of accounts offers and codes/names are unique. For tests. */
    public static function chartIsValid(): bool
    {
        $codes = $names = [];
        foreach (self::STANDARD_CHART as [$code, $name, $type]) {
            if (!LedgerService::isRecognisedType($type) || isset($codes[$code]) || isset($names[$name])) {
                return false;
            }
            $codes[$code] = $names[$name] = true;
        }
        return true;
    }

    /** Create the standard chart for a company that has no accounts. Returns how many accounts were added. */
    public static function provision(mysqli $conn, int $tenantId): int
    {
        $r = $conn->prepare("SELECT COUNT(*) n FROM account_list WHERE tenant_id = ? AND delete_flag = 0");
        $r->bind_param('i', $tenantId);
        $r->execute();
        if ((int)($r->get_result()->fetch_assoc()['n'] ?? 0) > 0) {
            return 0; // never touch a chart that already exists
        }
        $ins = $conn->prepare("INSERT INTO account_list (tenant_id, code, name, type, normal_balance, description, status) VALUES (?, ?, ?, ?, ?, ?, 1)");
        if (!$ins) {
            return 0;
        }
        $added = 0;
        foreach (self::STANDARD_CHART as [$code, $name, $type, $desc]) {
            $normal = LedgerService::classify($type)['normal'] === 'credit' ? 'credit' : 'debit';
            $ins->bind_param('isssss', $tenantId, $code, $name, $type, $normal, $desc);
            if ($ins->execute()) {
                $added++;
            }
        }
        return $added;
    }
}
