<?php
/**
 * Payroll Engine for Nuvis ERPX
 *
 * Jurisdiction-neutral. Every statutory number (pension rates, income-tax
 * bands, redundancy exemption, employer levies) comes from a rules array
 * (see default_rules / normalize_rules). A tenant overrides the starting
 * preset by storing JSON under the `payroll_rules` system setting.
 * Nuvis Technologies
 */

class PayrollEngine {

    /**
     * Starting rule preset. Used when a tenant has not configured its own
     * `payroll_rules`. Bands are marginal: each band taxes only the slice of
     * annual income between the previous `up_to` and its own.
     */
    public static function default_rules() {
        $bands = function ($firstRate) {
            return [
                ['up_to' => 30000,   'rate' => $firstRate],
                ['up_to' => 50000,   'rate' => $firstRate > 0 ? 0.20 : 0.18],
                ['up_to' => 270000,  'rate' => 0.20],
                ['up_to' => 300000,  'rate' => 0.33],
                ['up_to' => 350000,  'rate' => 0.34],
                ['up_to' => 400000,  'rate' => 0.35],
                ['up_to' => 450000,  'rate' => 0.36],
                ['up_to' => 500000,  'rate' => 0.37],
                ['up_to' => 1000000, 'rate' => 0.38],
                ['up_to' => null,    'rate' => 0.39],
            ];
        };
        return [
            'pension' => ['employee_rate' => 0.08, 'employer_rate' => 0.08],
            'resident_bands' => $bands(0.0),
            'non_resident_bands' => $bands(0.20),
            'secondary_income_rate' => 0.20,
            'redundancy' => ['exempt_up_to' => 15000, 'rate' => 0.15],
            'overtime' => [
                'weekly_hours' => 45,
                'weeks_per_year' => 52,
                'tiers' => [
                    ['key' => 'ot_1_5', 'label' => 'Time and a half', 'multiplier' => 1.5],
                    ['key' => 'ot_2_0', 'label' => 'Double time', 'multiplier' => 2.0],
                ],
            ],
            'employer_levies' => [
                ['key' => 'workcare_levy', 'label' => 'Employer levy 1', 'rate' => 0.01],
                ['key' => 'training_levy', 'label' => 'Employer levy 2', 'rate' => 0.01],
            ],
        ];
    }

    /**
     * Merge a partial / decoded-JSON rules array over the defaults and
     * validate it. Invalid sections fall back to the default for that section.
     */
    public static function normalize_rules($custom = null) {
        $rules = self::default_rules();
        if (!is_array($custom)) return $rules;

        $rate = function ($v) { return is_numeric($v) && $v >= 0 && $v <= 1; };

        if (isset($custom['pension']) && is_array($custom['pension'])) {
            foreach (['employee_rate', 'employer_rate'] as $k) {
                if (isset($custom['pension'][$k]) && $rate($custom['pension'][$k])) {
                    $rules['pension'][$k] = (float)$custom['pension'][$k];
                }
            }
        }
        foreach (['resident_bands', 'non_resident_bands'] as $k) {
            if (!empty($custom[$k]) && is_array($custom[$k])) {
                $bands = []; $prev = 0.0; $ok = true;
                $n = count($custom[$k]);
                foreach (array_values($custom[$k]) as $i => $b) {
                    $upTo = $b['up_to'] ?? null;
                    $last = ($i === $n - 1);
                    if (!isset($b['rate']) || !$rate($b['rate'])) { $ok = false; break; }
                    if ($last) {
                        if ($upTo !== null) { $ok = false; break; }
                    } else {
                        if (!is_numeric($upTo) || (float)$upTo <= $prev) { $ok = false; break; }
                        $prev = (float)$upTo;
                    }
                    $bands[] = ['up_to' => $last ? null : (float)$upTo, 'rate' => (float)$b['rate']];
                }
                if ($ok) $rules[$k] = $bands;
            }
        }
        if (isset($custom['secondary_income_rate']) && $rate($custom['secondary_income_rate'])) {
            $rules['secondary_income_rate'] = (float)$custom['secondary_income_rate'];
        }
        if (isset($custom['redundancy']) && is_array($custom['redundancy'])) {
            if (isset($custom['redundancy']['exempt_up_to']) && is_numeric($custom['redundancy']['exempt_up_to']) && $custom['redundancy']['exempt_up_to'] >= 0) {
                $rules['redundancy']['exempt_up_to'] = (float)$custom['redundancy']['exempt_up_to'];
            }
            if (isset($custom['redundancy']['rate']) && $rate($custom['redundancy']['rate'])) {
                $rules['redundancy']['rate'] = (float)$custom['redundancy']['rate'];
            }
        }
        if (isset($custom['overtime']) && is_array($custom['overtime'])) {
            $o = $custom['overtime'];
            if (isset($o['weekly_hours']) && is_numeric($o['weekly_hours']) && $o['weekly_hours'] > 0 && $o['weekly_hours'] <= 168) {
                $rules['overtime']['weekly_hours'] = (float)$o['weekly_hours'];
            }
            if (isset($o['weeks_per_year']) && is_numeric($o['weeks_per_year']) && $o['weeks_per_year'] >= 1 && $o['weeks_per_year'] <= 53) {
                $rules['overtime']['weeks_per_year'] = (float)$o['weeks_per_year'];
            }
            foreach (array_values((array)($o['tiers'] ?? [])) as $i => $t) {
                if ($i > 1 || !is_array($t)) break;
                if (isset($t['multiplier']) && is_numeric($t['multiplier']) && $t['multiplier'] >= 1 && $t['multiplier'] <= 10) {
                    $rules['overtime']['tiers'][$i]['multiplier'] = (float)$t['multiplier'];
                }
                $label = trim((string)($t['label'] ?? ''));
                if ($label !== '') $rules['overtime']['tiers'][$i]['label'] = $label;
            }
        }
        if (isset($custom['employer_levies']) && is_array($custom['employer_levies'])) {
            $levies = [];
            foreach ($custom['employer_levies'] as $l) {
                if (!is_array($l) || !isset($l['rate']) || !$rate($l['rate'])) continue;
                $levies[] = [
                    'key' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($l['key'] ?? ''))),
                    'label' => (string)($l['label'] ?? 'Employer levy'),
                    'rate' => (float)$l['rate'],
                ];
            }
            $rules['employer_levies'] = $levies;
        }
        return $rules;
    }

    /**
     * Strict validation for the settings screen. Returns a list of error
     * messages (empty = valid). normalize_rules() stays lenient for reads.
     */
    public static function validate_rules($r) {
        $err = [];
        if (!is_array($r)) return ['Rules must be an object.'];
        $rate = function ($v) { return is_numeric($v) && $v >= 0 && $v <= 1; };

        foreach (['employee_rate' => 'Employee pension rate', 'employer_rate' => 'Employer pension rate'] as $k => $lbl) {
            if (!isset($r['pension'][$k]) || !$rate($r['pension'][$k])) $err[] = "$lbl must be between 0% and 100%.";
        }
        if (!isset($r['secondary_income_rate']) || !$rate($r['secondary_income_rate'])) {
            $err[] = 'Secondary income rate must be between 0% and 100%.';
        }
        foreach (['resident_bands' => 'Resident', 'non_resident_bands' => 'Non-resident'] as $k => $lbl) {
            $bands = $r[$k] ?? null;
            if (!is_array($bands) || !$bands) { $err[] = "$lbl bands: add at least one band."; continue; }
            $bands = array_values($bands); $prev = 0.0; $n = count($bands);
            foreach ($bands as $i => $b) {
                $row = $i + 1;
                if (!isset($b['rate']) || !$rate($b['rate'])) { $err[] = "$lbl band $row: rate must be between 0% and 100%."; }
                $up = $b['up_to'] ?? null;
                if ($i === $n - 1) {
                    if ($up !== null && $up !== '') $err[] = "$lbl bands: the last band must have no upper limit.";
                } elseif (!is_numeric($up) || (float)$up <= $prev) {
                    $err[] = "$lbl band $row: upper limit must be a number above the previous band.";
                } else {
                    $prev = (float)$up;
                }
            }
        }
        $red = $r['redundancy'] ?? [];
        if (!isset($red['exempt_up_to']) || !is_numeric($red['exempt_up_to']) || $red['exempt_up_to'] < 0) $err[] = 'Redundancy exemption must be zero or more.';
        if (!isset($red['rate']) || !$rate($red['rate'])) $err[] = 'Redundancy rate must be between 0% and 100%.';

        $o = $r['overtime'] ?? [];
        if (!isset($o['weekly_hours']) || !is_numeric($o['weekly_hours']) || $o['weekly_hours'] <= 0 || $o['weekly_hours'] > 168) $err[] = 'Standard weekly hours must be between 1 and 168.';
        if (!isset($o['weeks_per_year']) || !is_numeric($o['weeks_per_year']) || $o['weeks_per_year'] < 1 || $o['weeks_per_year'] > 53) $err[] = 'Weeks per year must be between 1 and 53.';
        $tiers = array_values((array)($o['tiers'] ?? []));
        if (count($tiers) !== 2) $err[] = 'Overtime needs two rate tiers.';
        foreach ($tiers as $i => $t) {
            $row = $i + 1;
            if (trim((string)($t['label'] ?? '')) === '') $err[] = "Overtime tier $row: enter a name.";
            if (!isset($t['multiplier']) || !is_numeric($t['multiplier']) || $t['multiplier'] < 1 || $t['multiplier'] > 10) $err[] = "Overtime tier $row: multiplier must be between 1 and 10.";
        }

        $seen = [];
        foreach (($r['employer_levies'] ?? []) as $i => $l) {
            $row = $i + 1;
            if (!is_array($l) || trim((string)($l['label'] ?? '')) === '') { $err[] = "Levy $row: enter a name."; continue; }
            if (!isset($l['rate']) || !$rate($l['rate'])) $err[] = "Levy $row: rate must be between 0% and 100%.";
            $key = (string)($l['key'] ?? '');
            if ($key !== '') {
                if (!in_array($key, ['workcare_levy', 'training_levy'], true)) $err[] = "Levy $row: unknown payslip field.";
                elseif (isset($seen[$key])) $err[] = "Levy $row: payslip field already used by another levy.";
                $seen[$key] = true;
            }
        }
        return $err;
    }

    /**
     * The stored setting is either one rules object (legacy) or
     * {"versions":[{"effective_from":"Y-m-d","rules":{...}}, ...]} so rates can
     * change from a given date (e.g. each year) without touching past payroll.
     */
    public static function decode_setting($raw) {
        $decoded = $raw ? json_decode(html_entity_decode((string)$raw, ENT_QUOTES), true) : null;
        if (!is_array($decoded)) return ['versions' => []];
        if (isset($decoded['versions']) && is_array($decoded['versions'])) {
            $v = [];
            foreach ($decoded['versions'] as $x) {
                if (is_array($x) && !empty($x['effective_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x['effective_from']) && is_array($x['rules'] ?? null)) {
                    $v[(string)$x['effective_from']] = $x['rules'];
                }
            }
            ksort($v);
            return ['versions' => array_map(function ($d, $r) { return ['effective_from' => $d, 'rules' => $r]; }, array_keys($v), array_values($v))];
        }
        // legacy single object: applies from the beginning
        return ['versions' => [['effective_from' => '0001-01-01', 'rules' => $decoded]]];
    }

    /** Rules in force on $asAt (Y-m-d). Before the first version, the earliest version applies. */
    public static function rules_on($setting, $asAt = null) {
        $asAt = ($asAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$asAt)) ? (string)$asAt : date('Y-m-d');
        $versions = $setting['versions'] ?? [];
        if (!$versions) return self::default_rules();
        $pick = $versions[0];
        foreach ($versions as $v) {
            if ($v['effective_from'] <= $asAt) $pick = $v;
        }
        return self::normalize_rules($pick['rules']);
    }

    /** Add or replace the version starting on $from; returns the new setting structure. */
    public static function with_version($setting, $from, $rules) {
        $v = [];
        foreach (($setting['versions'] ?? []) as $x) $v[$x['effective_from']] = $x['rules'];
        $v[$from] = $rules;
        ksort($v);
        return ['versions' => array_map(function ($d, $r) { return ['effective_from' => $d, 'rules' => $r]; }, array_keys($v), array_values($v))];
    }

    public static function without_version($setting, $from) {
        $out = [];
        foreach (($setting['versions'] ?? []) as $x) if ($x['effective_from'] !== $from) $out[] = $x;
        return ['versions' => $out];
    }

    /** Load the company's rules in force on $asAt (default today), or the preset. */
    public static function load_rules($settings = null, $asAt = null) {
        $raw = ($settings && method_exists($settings, 'info')) ? $settings->info('payroll_rules') : '';
        return self::rules_on(self::decode_setting($raw), $asAt);
    }

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
     * Progressive annual income tax from marginal bands.
     */
    public static function tax_lookup($income, $isResident = true, $taxCode = 'P', $rules = null) {
        if ($income <= 0) return 0.0;
        $rules = $rules ?: self::default_rules();

        if ($taxCode === 'S') {
            return round($income * $rules['secondary_income_rate'], 2);
        }

        $bands = $isResident ? $rules['resident_bands'] : $rules['non_resident_bands'];
        $tax = 0.0; $prev = 0.0;
        foreach ($bands as $b) {
            $top = $b['up_to'];
            if ($top === null || $income <= $top) {
                $tax += ($income - $prev) * $b['rate'];
                break;
            }
            $tax += ($top - $prev) * $b['rate'];
            $prev = $top;
        }
        return round($tax, 2);
    }

    /**
     * Cumulative (year-to-date) withholding: spreads the projected annual tax
     * over the remaining periods so under/over-withholding self-corrects.
     */
    public static function calculate_cumulative_tax($input = [], $rules = null) {
        $rules = $rules ?: self::default_rules();
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

        $taxYbase = self::tax_lookup($Y_base, $isResident, $taxCode, $rules);
        $tBase = max(0, ($taxYbase - $tYtdPrior) / ($P - $n + 1));

        $taxYbaseOt = self::tax_lookup($Y_base + $O_n, $isResident, $taxCode, $rules);
        $tOt = max(0, $taxYbaseOt - $taxYbase);

        $tPeriod = round(max(0, $tBase + $tOt), 2);

        // 2. Lump Sum Payments (Bonuses / Leave Encashment)
        $tLump = 0.0;
        if ($L_n > 0) {
            $taxYest = self::tax_lookup($Y_est, $isResident, $taxCode, $rules);
            $taxYestLump = self::tax_lookup($Y_est + $L_n, $isResident, $taxCode, $rules);
            $tLump = round(max(0, $taxYestLump - $taxYest), 2);
        }

        // 3. Redundancy payments: exempt up to a cap, flat rate on the excess
        $tRedundancy = 0.0;
        if ($R_n > $rules['redundancy']['exempt_up_to']) {
            $tRedundancy = round(($R_n - $rules['redundancy']['exempt_up_to']) * $rules['redundancy']['rate'], 2);
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
     * Main Payroll Calculation Orchestrator.
     * Pass `rules` (see normalize_rules) to override the starting preset.
     *
     * Output keys keep their historical names (they map to stored columns);
     * the neutral aliases pension_base / employee_pension / employer_pension /
     * income_tax carry the same values.
     */
    public static function calculate($input = []) {
        $rules = self::normalize_rules($input['rules'] ?? null);

        $basic          = (float)($input['basic_salary'] ?? $input['basic'] ?? 0);
        $ot = $rules['overtime'];
        $ot15Hours      = (float)($input['overtime_1_5_hours'] ?? 0);   // tier 1 hours
        $ot20Hours      = (float)($input['overtime_2_0_hours'] ?? 0);   // tier 2 hours
        $hourlyRate     = (float)($input['hourly_rate'] ?? 0);

        if ($hourlyRate <= 0 && $basic > 0) {
            $weeklyHours = (float)($input['weekly_hours'] ?? $ot['weekly_hours']);
            if ($weeklyHours <= 0) $weeklyHours = (float)$ot['weekly_hours'];
            $hourlyRate = round(($basic * self::get_periods_per_year($input['pay_frequency'] ?? 'Monthly')) / ($ot['weeks_per_year'] * $weeklyHours), 4);
        }

        $ot15Amount     = round($ot15Hours * $hourlyRate * $ot['tiers'][0]['multiplier'], 2);
        $ot20Amount     = round($ot20Hours * $hourlyRate * $ot['tiers'][1]['multiplier'], 2);
        // A typed overtime amount and the hours-based tiers add together.
        $overtime       = round((float)($input['overtime'] ?? 0) + $ot15Amount + $ot20Amount, 2);

        $allowances     = (float)($input['allowances'] ?? 0);
        $otherEarnings  = (float)($input['other_earnings'] ?? 0);
        $lumpSum        = (float)($input['lump_sum_payments'] ?? $input['lump_sum'] ?? 0);
        $redundancy     = (float)($input['redundancy_payments'] ?? $input['redundancy'] ?? 0);
        $deductions     = (float)($input['deductions'] ?? 0);

        $isResident     = isset($input['is_resident']) ? (bool)$input['is_resident'] : true;
        $frequency      = $input['pay_frequency'] ?? $input['frequency'] ?? 'Monthly';
        $taxCode        = $input['tax_code'] ?? 'P';

        // Pension rates: per-employee override, else the rule set.
        $empRate   = (float)($input['employee_fnpf_rate'] ?? $rules['pension']['employee_rate']);
        $emprRate  = (float)($input['employer_fnpf_rate'] ?? $rules['pension']['employer_rate']);
        // Additional employer pension, entered as a percentage (e.g. 2 = 2%).
        $emprExtraPct = (float)($input['fnpf_employer_voluntary'] ?? 0.00);

        $gross = round($basic + $overtime + $allowances + $otherEarnings + $lumpSum + $redundancy, 2);
        $pensionBase = round($basic + $overtime + $allowances + $otherEarnings + $lumpSum, 2);

        $employeePension = round($pensionBase * $empRate, 2);
        $employerMandatory = round($pensionBase * $emprRate, 2);
        $employerExtra = round($pensionBase * ($emprExtraPct / 100), 2);
        $employerPensionTotal = round($employerMandatory + $employerExtra, 2);

        $tax = self::calculate_cumulative_tax([
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
        ], $rules);

        $incomeTax = $tax['total_paye'];

        // Employer levies (on gross). Named keys feed the stored columns.
        $levyTotal = 0.0;
        $levyOut = ['workcare_levy' => 0.00, 'training_levy' => 0.00];
        $levyLines = [];
        foreach ($rules['employer_levies'] as $l) {
            $amt = round($gross * $l['rate'], 2);
            $levyTotal += $amt;
            $levyLines[] = ['label' => $l['label'], 'rate' => $l['rate'], 'amount' => $amt];
            if ($l['key'] !== '' && array_key_exists($l['key'], $levyOut)) {
                $levyOut[$l['key']] = $amt;
            }
        }
        $levyTotal = round($levyTotal, 2);

        $netSalary = round($gross - $employeePension - $incomeTax - $deductions, 2);
        $employerCost = round($gross + $employerPensionTotal + $levyTotal, 2);

        return [
            'basic_salary'    => $basic,
            'overtime'        => $overtime,
            'overtime_1_5'    => $ot15Amount,
            'overtime_2_0'    => $ot20Amount,
            'overtime_hourly_rate' => $hourlyRate,
            'allowances'      => $allowances,
            'other_earnings'  => $otherEarnings,
            'lump_sum_payments'=> $lumpSum,
            'redundancy_payments'=> $redundancy,
            'gross_salary'    => $gross,
            'fnpf_base'       => $pensionBase,
            'pension_base'    => $pensionBase,
            'employee_fnpf'   => $employeePension,
            'employee_pension'=> $employeePension,
            'employer_fnpf'   => $employerPensionTotal,
            'employer_pension'=> $employerPensionTotal,
            'fnpf_er_mandatory' => $employerMandatory,
            'fnpf_er_voluntary_excess' => $employerExtra,
            'taxable_income'  => $gross,
            'paye_tax'        => $incomeTax,
            'income_tax'      => $incomeTax,
            'paye_normal'     => $tax['paye_normal'],
            'paye_lump_sum'   => $tax['paye_lump'],
            'srt_tax'         => 0.00,
            'deductions'      => $deductions,
            'net_salary'      => $netSalary,
            'workcare_levy'   => $levyOut['workcare_levy'],
            'training_levy'   => $levyOut['training_levy'],
            'fnu_levy_er'     => $levyOut['training_levy'],
            'employer_levies' => $levyLines,
            'employer_levies_total' => $levyTotal,
            'employer_cost'   => $employerCost,
            'is_resident'     => $isResident,
            'tax_code'        => $taxCode,
            'pay_frequency'   => $frequency
        ];
    }
}
