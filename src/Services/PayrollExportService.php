<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds the payroll download files (bank credit files, income-tax return,
 * pension schedule) from payroll_list rows joined with employee details.
 * Pure: no database access, so it is unit-testable.
 *
 * Bank layouts here are simple documented column sets, not any bank's
 * proprietary upload format; map them to the bank's template before upload.
 */
class PayrollExportService
{
    public const BANKS = ['bsp', 'anz', 'hfc', 'bred', 'generic'];

    private static function csv(array $rows): string
    {
        $fh = fopen('php://memory', 'w+');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '\\');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return (string)$out;
    }

    /** Guard against spreadsheet formula injection in free-text cells. */
    private static function text($v): string
    {
        $s = trim((string)$v);
        return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
    }

    private static function money($v): string
    {
        return number_format((float)$v, 2, '.', '');
    }

    /** Net-pay credit file. Rows for other banks are listed too, tagged by bank code. */
    public static function bank(array $rows, string $type, string $runRef): string
    {
        $type = in_array($type, self::BANKS, true) ? $type : 'generic';
        $out = [['RUN_REF', 'EMPLOYEE_CODE', 'EMPLOYEE_NAME', 'BANK', 'ACCOUNT_NUMBER', 'AMOUNT', 'PAY_DATE']];
        $total = 0.0;
        foreach ($rows as $r) {
            $bank = strtolower((string)($r['bank_code'] ?? ''));
            if ($type !== 'generic' && $bank !== $type) {
                continue;
            }
            $net = round((float)($r['net_salary'] ?? 0), 2);
            if ($net <= 0) {
                continue; // nothing to pay
            }
            $total += $net;
            $out[] = [
                self::text($runRef),
                self::text($r['employee_code'] ?? ''),
                self::text($r['emp_name'] ?? ''),
                strtoupper($bank),
                self::text($r['bank_account'] ?? ''),
                self::money($net),
                (string)($r['pay_date'] ?? ''),
            ];
        }
        $out[] = ['TOTAL', '', '', '', '', self::money($total), ''];
        return self::csv($out);
    }

    /** Income-tax return: gross, taxable income and tax withheld per employee. */
    public static function incomeTax(array $rows, string $employerTin, string $runRef): string
    {
        $out = [['EMPLOYER_TIN', 'RUN_REF', 'PERIOD_START', 'PERIOD_END', 'EMPLOYEE_TIN', 'EMPLOYEE_NAME',
            'TAX_CODE', 'RESIDENT', 'GROSS_PAY', 'TAXABLE_INCOME', 'TAX_WITHHELD']];
        $gross = $taxable = $tax = 0.0;
        foreach ($rows as $r) {
            $g = (float)($r['gross_salary'] ?? 0);
            $t = (float)($r['taxable_income'] ?? $g);
            $w = (float)($r['paye_tax'] ?? 0) + (float)($r['srt_tax'] ?? 0);
            $gross += $g; $taxable += $t; $tax += $w;
            $out[] = [
                self::text($employerTin), self::text($runRef),
                (string)($r['period_start'] ?? ''), (string)($r['period_end'] ?? ''),
                self::text($r['tin'] ?? '') ?: 'N/A', self::text($r['emp_name'] ?? ''),
                self::text($r['tax_code'] ?? 'P') ?: 'P',
                !isset($r['is_resident']) || (int)$r['is_resident'] === 1 ? 'Y' : 'N',
                self::money($g), self::money($t), self::money($w),
            ];
        }
        $out[] = ['TOTAL', '', '', '', '', '', '', '', self::money($gross), self::money($taxable), self::money($tax)];
        return self::csv($out);
    }

    /** Pension schedule: member number, pensionable wages and both contributions. */
    public static function pensionSchedule(array $rows, string $employerRef, string $runRef): string
    {
        $out = [['EMPLOYER_REF', 'RUN_REF', 'MEMBER_NO', 'EMPLOYEE_NAME', 'TIN', 'PENSIONABLE_WAGES',
            'EMPLOYEE_CONTRIBUTION', 'EMPLOYER_CONTRIBUTION', 'TOTAL_CONTRIBUTION']];
        $w = $e = $r2 = 0.0;
        foreach ($rows as $r) {
            $emp = (float)($r['employee_fnpf'] ?? 0);
            $er = (float)($r['employer_fnpf'] ?? 0);
            $wages = (float)($r['fnpf_base'] ?? $r['gross_salary'] ?? 0);
            $w += $wages; $e += $emp; $r2 += $er;
            $out[] = [
                self::text($employerRef), self::text($runRef),
                self::text($r['fnpf_no'] ?? '') ?: 'N/A', self::text($r['emp_name'] ?? ''),
                self::text($r['tin'] ?? '') ?: 'N/A',
                self::money($wages), self::money($emp), self::money($er), self::money($emp + $er),
            ];
        }
        $out[] = ['TOTAL', '', '', '', '', self::money($w), self::money($e), self::money($r2), self::money($e + $r2)];
        return self::csv($out);
    }
}
