<?php
/**
 * Fiji Payroll Engine for Nuvis ERPX
 * Nuvis Technologies (nuvistechnologies.com.fj)
 */

class FijiPayroll {

    public static function get_periods_per_year($frequency = 'Monthly') {
        switch (strtolower($frequency)) {
            case 'fortnightly':
                return 26;
            case 'weekly':
                return 52;
            case 'bi-weekly':
            case 'semi-monthly':
                return 24;
            case 'monthly':
            default:
                return 12;
        }
    }

    /**
     * Calculate PAYE Tax and SRT (Social Responsibility Tax)
     */
    public static function calculate_paye($taxableIncome, $frequency = 'Monthly', $isResident = true) {
        if ($taxableIncome <= 0) {
            return [
                'paye' => 0.0,
                'basic_tax' => 0.0,
                'srt' => 0.0,
                'annual_equivalent' => 0.0,
                'annual_tax' => 0.0
            ];
        }

        $periods = self::get_periods_per_year($frequency);
        $annualEquivalent = $taxableIncome * $periods;

        if (!$isResident) {
            $paye = round($taxableIncome * 0.20, 2);
            return [
                'paye' => $paye,
                'basic_tax' => $paye,
                'srt' => 0.0,
                'annual_equivalent' => $annualEquivalent,
                'annual_tax' => round($paye * $periods, 2)
            ];
        }

        $annualBreakdown = self::calculate_annual_tax_breakdown($annualEquivalent);

        $periodBasic = round($annualBreakdown['basic'] / $periods, 2);
        $periodSrt = round($annualBreakdown['srt'] / $periods, 2);
        $periodTax = round($annualBreakdown['total'] / $periods, 2);

        return [
            'paye' => $periodTax,
            'basic_tax' => $periodBasic,
            'srt' => $periodSrt,
            'annual_equivalent' => $annualEquivalent,
            'annual_tax' => $annualBreakdown['total']
        ];
    }

    /**
     * Annual Tax Breakdown for Fiji Resident
     */
    public static function calculate_annual_tax_breakdown($income) {
        if ($income <= 0) {
            return ['basic' => 0.0, 'srt' => 0.0, 'total' => 0.0];
        }

        // Basic Income Tax
        $basic = 0.0;
        if ($income <= 30000) {
            $basic = 0.0;
        } elseif ($income <= 50000) {
            $basic = ($income - 30000) * 0.18;
        } elseif ($income <= 270000) {
            $basic = 3600 + ($income - 50000) * 0.20;
        } else {
            $basic = 47600 + ($income - 270000) * 0.20;
        }

        // Social Responsibility Tax (SRT) for income above 270,000
        $srt = 0.0;
        if ($income > 270000) {
            $srt = self::calculate_srt($income);
        }

        $total = round($basic + $srt, 2);

        return [
            'basic' => round($basic, 2),
            'srt' => round($srt, 2),
            'total' => $total
        ];
    }

    /**
     * Progressive SRT calculation above FJD 270,000
     */
    protected static function calculate_srt($income) {
        $srt = 0.0;
        $remaining = $income - 270000;

        $bands = [
            [30000,  0.13], // 270k - 300k
            [50000,  0.14], // 300k - 350k
            [50000,  0.15], // 350k - 400k
            [50000,  0.16], // 400k - 450k
            [50000,  0.17], // 450k - 500k
            [500000, 0.18], // 500k - 1m
            [null,   0.19]  // 1m+
        ];

        foreach ($bands as $band) {
            $size = $band[0];
            $rate = $band[1];

            if ($remaining <= 0) break;

            if ($size === null) {
                $srt += $remaining * $rate;
                break;
            }

            $taxable = min($remaining, $size);
            $srt += $taxable * $rate;
            $remaining -= $taxable;
        }

        return $srt;
    }

    /**
     * Main Fiji Payroll Calculation Orchestrator
     */
    public static function calculate($input = []) {
        $basic          = (float)($input['basic_salary'] ?? $input['basic'] ?? 0);
        $overtime       = (float)($input['overtime'] ?? 0);
        $allowances     = (float)($input['allowances'] ?? 0);
        $otherEarnings  = (float)($input['other_earnings'] ?? 0);
        $deductions     = (float)($input['deductions'] ?? 0);
        $isResident     = isset($input['is_resident']) ? (bool)$input['is_resident'] : true;
        $frequency      = $input['pay_frequency'] ?? $input['frequency'] ?? 'Monthly';

        $empFnpfRate    = (float)($input['employee_fnpf_rate'] ?? 0.08); // 8% default
        $emprFnpfRate   = (float)($input['employer_fnpf_rate'] ?? 0.08); // 8% default
        $enableWorkcare = isset($input['enable_workcare']) ? (bool)$input['enable_workcare'] : true;
        $enableTraining = isset($input['enable_training']) ? (bool)$input['enable_training'] : false;

        $gross = round($basic + $overtime + $allowances + $otherEarnings, 2);

        $fnpfBase = $gross; // FNPF eligible wage base

        $employeeFnpf = round($fnpfBase * $empFnpfRate, 2);
        $employerFnpf = round($fnpfBase * $emprFnpfRate, 2);

        // Taxable Income (Employee FNPF is tax deductible in Fiji)
        $taxableIncome = max(0, $gross - $employeeFnpf);

        $payeResult = self::calculate_paye($taxableIncome, $frequency, $isResident);
        $payeAmount = $payeResult['paye'];
        $basicTax   = $payeResult['basic_tax'];
        $srtTax     = $payeResult['srt'];

        $workcare = $enableWorkcare ? round($gross * 0.01, 2) : 0.0;
        $training = $enableTraining ? round($gross * 0.01, 2) : 0.0;

        $netSalary   = round($gross - $employeeFnpf - $payeAmount - $deductions, 2);
        $employerCost = round($gross + $employerFnpf + $workcare + $training, 2);

        return [
            'basic_salary'    => $basic,
            'overtime'        => $overtime,
            'allowances'      => $allowances,
            'other_earnings'  => $otherEarnings,
            'gross_salary'    => $gross,
            'fnpf_base'       => $fnpfBase,
            'employee_fnpf'   => $employeeFnpf,
            'employer_fnpf'   => $employerFnpf,
            'taxable_income'  => $taxableIncome,
            'paye_tax'        => $payeAmount,
            'basic_tax'       => $basicTax,
            'srt_tax'         => $srtTax,
            'deductions'      => $deductions,
            'net_salary'      => $netSalary,
            'workcare_levy'   => $workcare,
            'training_levy'   => $training,
            'employer_cost'   => $employerCost,
            'is_resident'     => $isResident,
            'pay_frequency'   => $frequency
        ];
    }

    /**
     * Generate CSV content for Bank Exports (BSP, ANZ, HFC, BRED, Generic)
     */
    public static function generate_bank_export($rows, $bankCode = 'bsp', $runRef = 'PAYROLL') {
        $bankCode = strtolower($bankCode);
        $csvRows = [];

        switch ($bankCode) {
            case 'anz':
                $csvRows[] = ['Payment Date', 'Account Number', 'Account Name', 'Amount', 'Currency', 'Particulars', 'Analysis Code', 'Reference'];
                foreach ($rows as $r) {
                    $acc = preg_replace('/\D/', '', $r['bank_account'] ?? '');
                    $name = substr(preg_replace('/[^A-Za-z0-9 \-]/', '', $r['emp_name'] ?? ''), 0, 50);
                    $payDate = !empty($r['pay_date']) ? $r['pay_date'] : date('Y-m-d');
                    $csvRows[] = [
                        $payDate,
                        $acc,
                        $name,
                        number_format($r['net_salary'], 2, '.', ''),
                        'FJD',
                        'Salary Payment',
                        'SALARY',
                        $runRef . '-' . ($r['employee_code'] ?? $r['id'])
                    ];
                }
                break;

            case 'hfc':
                $csvRows[] = ['Account Number', 'Beneficiary Name', 'Amount', 'Narration', 'Reference', 'Value Date'];
                foreach ($rows as $r) {
                    $acc = preg_replace('/\D/', '', $r['bank_account'] ?? '');
                    $name = substr(preg_replace('/[^A-Za-z0-9 \-]/', '', $r['emp_name'] ?? ''), 0, 40);
                    $payDate = !empty($r['pay_date']) ? $r['pay_date'] : date('Y-m-d');
                    $csvRows[] = [
                        $acc,
                        $name,
                        number_format($r['net_salary'], 2, '.', ''),
                        'SALARY ' . $runRef,
                        $runRef . '-' . ($r['employee_code'] ?? $r['id']),
                        $payDate
                    ];
                }
                break;

            case 'bred':
                $csvRows[] = ['Account Number', 'Account Name', 'Amount', 'Currency', 'Particulars', 'Reference'];
                foreach ($rows as $r) {
                    $acc = preg_replace('/\D/', '', $r['bank_account'] ?? '');
                    $name = substr(preg_replace('/[^A-Za-z0-9 \-]/', '', $r['emp_name'] ?? ''), 0, 40);
                    $csvRows[] = [
                        $acc,
                        $name,
                        number_format($r['net_salary'], 2, '.', ''),
                        'FJD',
                        'Salary',
                        $runRef . '-' . ($r['employee_code'] ?? $r['id'])
                    ];
                }
                break;

            case 'generic':
                $csvRows[] = ['Employee Name', 'Employee Code', 'Bank Account Number', 'Bank Code', 'Amount (FJD)', 'Particulars', 'Payroll Reference'];
                foreach ($rows as $r) {
                    $acc = preg_replace('/\D/', '', $r['bank_account'] ?? '');
                    $csvRows[] = [
                        $r['emp_name'] ?? '',
                        $r['employee_code'] ?? '',
                        $acc,
                        strtoupper($r['bank_code'] ?? 'OTHER'),
                        number_format($r['net_salary'], 2, '.', ''),
                        'Salary ' . $runRef,
                        $runRef
                    ];
                }
                break;

            case 'bsp':
            default:
                $csvRows[] = ['Account Number', 'Account Name', 'Amount', 'Particulars', 'Code', 'Reference'];
                foreach ($rows as $r) {
                    $acc = preg_replace('/\D/', '', $r['bank_account'] ?? '');
                    $name = substr(preg_replace('/[^A-Za-z0-9 \-]/', '', $r['emp_name'] ?? ''), 0, 40);
                    $csvRows[] = [
                        $acc,
                        $name,
                        number_format($r['net_salary'], 2, '.', ''),
                        'SALARY',
                        'SAL',
                        $runRef . '-' . ($r['employee_code'] ?? $r['id'])
                    ];
                }
                break;
        }

        return self::to_csv($csvRows);
    }

    /**
     * Generate FRCS TPOS PAYE Export CSV
     */
    public static function generate_frcs_tpos_export($rows, $employerTin = '', $runRef = 'PAYROLL') {
        $csvRows = [];
        $csvRows[] = [
            'Employer TIN',
            'Payroll Period Start',
            'Payroll Period End',
            'Pay Date',
            'Employee TIN',
            'Employee Name',
            'Tax Code',
            'Resident (Y/N)',
            'Gross Earnings',
            'FNPF Deducted (Employee)',
            'Taxable Income',
            'PAYE Deducted',
            'Other Deductions',
            'Net Pay',
            'Employer FNPF',
            'Employee Number',
            'FNPF Number'
        ];

        foreach ($rows as $r) {
            $csvRows[] = [
                $employerTin,
                $r['period_start'] ?? '',
                $r['period_end'] ?? '',
                $r['pay_date'] ?? date('Y-m-d'),
                $r['tin'] ?? '',
                $r['emp_name'] ?? '',
                $r['tax_code'] ?? 'P',
                (!isset($r['is_resident']) || $r['is_resident']) ? 'Y' : 'N',
                number_format($r['gross_salary'] ?? 0, 2, '.', ''),
                number_format($r['employee_fnpf'] ?? 0, 2, '.', ''),
                number_format($r['taxable_income'] ?? 0, 2, '.', ''),
                number_format($r['paye_tax'] ?? 0, 2, '.', ''),
                number_format($r['deductions'] ?? 0, 2, '.', ''),
                number_format($r['net_salary'] ?? 0, 2, '.', ''),
                number_format($r['employer_fnpf'] ?? 0, 2, '.', ''),
                $r['employee_code'] ?? '',
                $r['fnpf_no'] ?? ''
            ];
        }

        return self::to_csv($csvRows);
    }

    /**
     * Generate FNPF Schedule Export CSV
     */
    public static function generate_fnpf_schedule_export($rows, $employerFnpfRef = '', $runRef = 'PAYROLL') {
        $csvRows = [];
        $csvRows[] = [
            'Employer FNPF Ref',
            'Salary Period',
            'Employee Code',
            'Employee Name',
            'FNPF Number',
            'TIN',
            'Gross Wages',
            'FNPF Wage Base',
            'Employee Contribution (8%)',
            'Employer Contribution (8%)',
            'Total FNPF Contribution (16%)'
        ];

        foreach ($rows as $r) {
            $empFnpf = (float)($r['employee_fnpf'] ?? 0);
            $emprFnpf = (float)($r['employer_fnpf'] ?? 0);
            $totalFnpf = $empFnpf + $emprFnpf;

            $csvRows[] = [
                $employerFnpfRef,
                $r['salary_month'] ?? '',
                $r['employee_code'] ?? '',
                $r['emp_name'] ?? '',
                $r['fnpf_no'] ?? '',
                $r['tin'] ?? '',
                number_format($r['gross_salary'] ?? 0, 2, '.', ''),
                number_format($r['fnpf_base'] ?? 0, 2, '.', ''),
                number_format($empFnpf, 2, '.', ''),
                number_format($emprFnpf, 2, '.', ''),
                number_format($totalFnpf, 2, '.', '')
            ];
        }

        return self::to_csv($csvRows);
    }

    /**
     * Helper to output CSV string
     */
    protected static function to_csv(array $rows) {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
?>