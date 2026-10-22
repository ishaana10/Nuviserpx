<?php
/**
 * Fiji Payroll Engine for Nuvis ERPX
 * Compliant with Income Tax Act 2015, FNPF Act 2011 (2026-2027 Budget), and ERA 2007
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
     * 2026 Integrated Tax Bracket Function
     */
    public static function tax_lookup($income, $isResident = true, $taxCode = 'P') {
        if ($income <= 0) return 0.0;

        if ($taxCode === 'S') {
            return round($income * 0.20, 2);
        }

        if (!$isResident) {
            if ($income <= 30000) return round($income * 0.20, 2);
            if ($income <= 50000) return round(6000 + ($income - 30000) * 0.20, 2);
            if ($income <= 270000) return round(10000 + ($income - 50000) * 0.20, 2);
            if ($income <= 300000) return round(54000 + ($income - 270000) * 0.33, 2);
            if ($income <= 350000) return round(63900 + ($income - 300000) * 0.34, 2);
            if ($income <= 400000) return round(80900 + ($income - 350000) * 0.35, 2);
            if ($income <= 450000) return round(98400 + ($income - 400000) * 0.36, 2);
            if ($income <= 500000) return round(116400 + ($income - 450000) * 0.37, 2);
            if ($income <= 1000000) return round(134900 + ($income - 500000) * 0.38, 2);
            return round(324900 + ($income - 1000000) * 0.39, 2);
        }

        // Resident (Code P)
        if ($income <= 30000) return 0.0;
        if ($income <= 50000) return round(($income - 30000) * 0.18, 2);
        if ($income <= 270000) return round(3600 + ($income - 50000) * 0.20, 2);
        if ($income <= 300000) return round(47600 + ($income - 270000) * 0.33, 2);
        if ($income <= 350000) return round(57500 + ($income - 300000) * 0.34, 2);
        if ($income <= 400000) return round(74500 + ($income - 350000) * 0.35, 2);
        if ($income <= 450000) return round(92000 + ($income - 400000) * 0.36, 2);
        if ($income <= 500000) return round(110000 + ($income - 450000) * 0.37, 2);
        if ($income <= 1000000) return round(128500 + ($income - 500000) * 0.38, 2);
        return round(318500 + ($income - 1000000) * 0.39, 2);
    }

    /**
     * FRCS Regulation 6 Cumulative PAYE Calculation Formula
     */
    public static function calculate_reg6_paye($input = []) {
        $P = self::get_periods_per_year($input['pay_frequency'] ?? 'Monthly');
        $n = intval($input['pay_period_n'] ?? 1);
        if ($n < 1) $n = 1;
        if ($n > $P) $n = $P;

        $gYtdPrior = floatval($input['g_ytd_prior'] ?? 0);
        $tYtdPrior = floatval($input['t_ytd_prior'] ?? 0);

        $W_n = floatval($input['regular_pay'] ?? 0);
        $O_n = floatval($input['overtime_pay'] ?? 0);
        $L_n = floatval($input['lump_sum_pay'] ?? 0);
        $R_n = floatval($input['redundancy_pay'] ?? 0);

        $isResident = isset($input['is_resident']) ? (bool)$input['is_resident'] : true;
        $taxCode = $input['tax_code'] ?? 'P';

        // 1. Regular & Overtime Income
        $G_n = $W_n + $O_n;
        $Y_est = $gYtdPrior + $G_n + ($W_n * ($P - $n));
        $Y_base = $gYtdPrior + ($W_n * ($P - $n + 1));

        $taxYbase = self::tax_lookup($Y_base, $isResident, $taxCode);
        $tBase = max(0, ($taxYbase - $tYtdPrior) / ($P - $n + 1));

        $taxYbaseOt = self::tax_lookup($Y_base + $O_n, $isResident, $taxCode);
        $tOt = max(0, $taxYbaseOt - $taxYbase);

        $tPeriod = round(max(0, $tBase + $tOt), 2);

        // 2. Lump Sum Payments (Bonuses / Leave Encashment)
        $tLump = 0.0;
        if ($L_n > 0) {
            $taxYest = self::tax_lookup($Y_est, $isResident, $taxCode);
            $taxYestLump = self::tax_lookup($Y_est + $L_n, $isResident, $taxCode);
            $tLump = round(max(0, $taxYestLump - $taxYest), 2);
        }

        // 3. Redundancy Payments ($15,000 tax free cap, 15% flat excess)
        $tRedundancy = 0.0;
        if ($R_n > 15000) {
            $tRedundancy = round(($R_n - 15000) * 0.15, 2);
        }

        $totalPaye = round($tPeriod + $tLump + $tRedundancy, 2);

        return [
            'paye_normal' => $tPeriod,
            'paye_base' => round($tBase, 2),
            'paye_ot' => round($tOt, 2),
            'paye_lump' => $tLump,
            'paye_redundancy' => $tRedundancy,
            'total_paye' => $totalPaye,
            'y_est' => $Y_est
        ];
    }

    /**
     * Main Fiji Payroll Calculation Orchestrator
     */
    public static function calculate($input = []) {
        $basic          = (float)($input['basic_salary'] ?? $input['basic'] ?? 0);
        $ot15Hours      = (float)($input['overtime_1_5_hours'] ?? 0);
        $ot20Hours      = (float)($input['overtime_2_0_hours'] ?? 0);
        $hourlyRate     = (float)($input['hourly_rate'] ?? 0);

        if ($hourlyRate <= 0 && $basic > 0) {
            $weeklyHours = (float)($input['weekly_hours'] ?? 45);
            $hourlyRate = round(($basic * self::get_periods_per_year($input['pay_frequency'] ?? 'Monthly')) / (52 * $weeklyHours), 4);
        }

        $ot15Amount     = round($ot15Hours * $hourlyRate * 1.5, 2);
        $ot20Amount     = round($ot20Hours * $hourlyRate * 2.0, 2);
        $overtime       = (float)($input['overtime'] ?? ($ot15Amount + $ot20Amount));

        $allowances     = (float)($input['allowances'] ?? 0);
        $otherEarnings  = (float)($input['other_earnings'] ?? 0);
        $lumpSum        = (float)($input['lump_sum_payments'] ?? $input['lump_sum'] ?? 0);
        $redundancy     = (float)($input['redundancy_payments'] ?? $input['redundancy'] ?? 0);
        $deductions     = (float)($input['deductions'] ?? 0);

        $familyCarePay  = (float)($input['family_care_leave_pay'] ?? 0);
        $paternityPay   = (float)($input['paternity_leave_pay'] ?? 0);

        $isResident     = isset($input['is_resident']) ? (bool)$input['is_resident'] : true;
        $frequency      = $input['pay_frequency'] ?? $input['frequency'] ?? 'Monthly';
        $taxCode        = $input['tax_code'] ?? 'P';

        // 2026-2027 Relief Rates (8% Employee + 8% Employer)
        $empFnpfRate    = (float)($input['employee_fnpf_rate'] ?? 0.08);
        $emprFnpfRate   = (float)($input['employer_fnpf_rate'] ?? 0.08);
        $emprFnpfVolRate= (float)($input['fnpf_employer_voluntary'] ?? 0.00);

        $gross = round($basic + $overtime + $allowances + $otherEarnings + $lumpSum + $redundancy, 2);
        $fnpfBase = round($basic + $overtime + $allowances + $otherEarnings + $lumpSum, 2);

        $employeeFnpf = round($fnpfBase * $empFnpfRate, 2);
        $employerFnpfMandatory = round($fnpfBase * $emprFnpfRate, 2);
        $employerFnpfVoluntaryExcess = round($fnpfBase * ($emprFnpfVolRate / 100), 2);
        $employerFnpfTotal = $employerFnpfMandatory + $employerFnpfVoluntaryExcess;

        $reg6Result = self::calculate_reg6_paye([
            'pay_frequency' => $frequency,
            'pay_period_n' => intval($input['pay_period_n'] ?? 1),
            'g_ytd_prior' => floatval($input['g_ytd_prior'] ?? 0),
            't_ytd_prior' => floatval($input['t_ytd_prior'] ?? 0),
            'regular_pay' => $basic + $allowances,
            'overtime_pay' => $overtime + $otherEarnings,
            'lump_sum_pay' => $lumpSum,
            'redundancy_pay' => $redundancy,
            'is_resident' => $isResident,
            'tax_code' => $taxCode
        ]);

        $payeAmount = $reg6Result['total_paye'];

        $workcare = round($gross * 0.01, 2);
        $fnuLevy = round($gross * 0.01, 2);

        $netSalary = round($gross - $employeeFnpf - $payeAmount - $deductions, 2);
        $employerCost = round($gross + $employerFnpfTotal + $workcare + $fnuLevy, 2);

        // 150% Corporate Tax Rebate Tracker Sum
        $rebate150Sum = round($familyCarePay + $paternityPay + $employerFnpfVoluntaryExcess, 2);

        return [
            'basic_salary'    => $basic,
            'overtime'        => $overtime,
            'overtime_1_5'    => $ot15Amount,
            'overtime_2_0'    => $ot20Amount,
            'allowances'      => $allowances,
            'other_earnings'  => $otherEarnings,
            'lump_sum_payments'=> $lumpSum,
            'redundancy_payments'=> $redundancy,
            'gross_salary'    => $gross,
            'fnpf_base'       => $fnpfBase,
            'employee_fnpf'   => $employeeFnpf,
            'employer_fnpf'   => $employerFnpfTotal,
            'fnpf_er_mandatory' => $employerFnpfMandatory,
            'fnpf_er_voluntary_excess' => $employerFnpfVoluntaryExcess,
            'taxable_income'  => $gross,
            'paye_tax'        => $payeAmount,
            'paye_normal'     => $reg6Result['paye_normal'],
            'paye_lump_sum'   => $reg6Result['paye_lump'],
            'srt_tax'         => 0.00,
            'deductions'      => $deductions,
            'net_salary'      => $netSalary,
            'workcare_levy'   => $workcare,
            'training_levy'   => $fnuLevy,
            'fnu_levy_er'     => $fnuLevy,
            'rebate_150_sum'  => $rebate150Sum,
            'employer_cost'   => $employerCost,
            'is_resident'     => $isResident,
            'tax_code'        => $taxCode,
            'pay_frequency'   => $frequency
        ];
    }
}
?>