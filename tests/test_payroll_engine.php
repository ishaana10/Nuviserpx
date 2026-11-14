<?php
// Pure tests for the jurisdiction-neutral payroll engine.
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');

$fail = 0;
function check($name, $cond) { global $fail; echo ($cond ? "PASS" : "FAIL") . " - $name\n"; if (!$cond) $fail++; }
function near($a, $b) { return abs($a - $b) < 0.005; }

// Starting preset: marginal bands reproduce the cumulative tables.
check('resident below threshold', PayrollEngine::tax_lookup(30000, true) == 0.0);
check('resident 100k', near(PayrollEngine::tax_lookup(100000, true), 13600.00));
check('resident 300k', near(PayrollEngine::tax_lookup(300000, true), 57500.00));
check('resident 1.2m', near(PayrollEngine::tax_lookup(1200000, true), 396500.00));
check('non-resident 100k', near(PayrollEngine::tax_lookup(100000, false), 20000.00));
check('non-resident 300k', near(PayrollEngine::tax_lookup(300000, false), 63900.00));
check('secondary income flat', near(PayrollEngine::tax_lookup(1000, true, 'S'), 200.00));

// Standard monthly calculation.
$r = PayrollEngine::calculate(['basic_salary' => 3000, 'overtime' => 200, 'allowances' => 100,
    'other_earnings' => 50, 'deductions' => 50, 'pay_frequency' => 'Monthly', 'is_resident' => true]);
check('gross', $r['gross_salary'] == 3350);
check('employee pension 8%', near($r['employee_pension'], 268.00));
check('employer pension 8%', near($r['employer_pension'], 268.00));
check('legacy alias equals neutral', $r['employee_fnpf'] == $r['employee_pension'] && $r['paye_tax'] == $r['income_tax']);
check('net = gross - pension - tax - deductions', near($r['net_salary'], 3350 - 268 - $r['income_tax'] - 50));
check('employer cost includes levies', near($r['employer_cost'], 3350 + 268 + $r['employer_levies_total']));
check('default preset carries no employer levies (none apply generally in Fiji)', $r['employer_levies_total'] == 0.0);

// Custom rules fully replace the preset: 5%/10% pension, one 10% band over 20k, no levies.
$rules = ['pension' => ['employee_rate' => 0.05, 'employer_rate' => 0.10],
    'resident_bands' => [['up_to' => 20000, 'rate' => 0], ['up_to' => null, 'rate' => 0.10]],
    'employer_levies' => []];
check('custom bands', near(PayrollEngine::tax_lookup(50000, true, 'P', PayrollEngine::normalize_rules($rules)), 3000.00));
$c = PayrollEngine::calculate(['basic_salary' => 1000, 'rules' => $rules]);
check('custom employee pension', near($c['employee_pension'], 50.00));
check('custom employer pension', near($c['employer_pension'], 100.00));
check('no levies', $c['employer_levies_total'] == 0.0 && $c['workcare_levy'] == 0.0);
check('employer cost custom', near($c['employer_cost'], 1100.00));

// Invalid rules fall back to the preset instead of corrupting payroll.
$bad = PayrollEngine::normalize_rules(['pension' => ['employee_rate' => 5], 'resident_bands' => [['up_to' => 10, 'rate' => 0.1]]]);
check('rate > 1 rejected', $bad['pension']['employee_rate'] == 0.08);
check('bands without open-ended top rejected', count($bad['resident_bands']) === 10);
$bad2 = PayrollEngine::normalize_rules(['resident_bands' => [['up_to' => 50, 'rate' => 0.1], ['up_to' => 40, 'rate' => 0.2], ['up_to' => null, 'rate' => 0.3]]]);
check('non-increasing bands rejected', count($bad2['resident_bands']) === 10);

// Redundancy exemption is rule-driven.
$red = PayrollEngine::calculate_cumulative_tax(['regular_pay' => 0, 'redundancy_pay' => 20000]);
check('redundancy excess taxed', near($red['paye_redundancy'], 750.00));
$red2 = PayrollEngine::calculate_cumulative_tax(['regular_pay' => 0, 'redundancy_pay' => 20000],
    PayrollEngine::normalize_rules(['redundancy' => ['exempt_up_to' => 5000, 'rate' => 0.10]]));
check('custom redundancy rule', near($red2['paye_redundancy'], 1500.00));


// Overtime is rule-driven: hourly rate = basic * periods / (weeks * weekly hours).
$o = PayrollEngine::calculate(['basic_salary' => 4500, 'overtime_1_5_hours' => 4, 'overtime_2_0_hours' => 2, 'pay_frequency' => 'Monthly']);
$hr = 4500 * 12 / (52 * 45);
check('default hourly rate', near($o['overtime_hourly_rate'], round($hr, 4)));
check('time and a half', near($o['overtime_1_5'], round(4 * round($hr, 4) * 1.5, 2)));
check('double time', near($o['overtime_2_0'], round(2 * round($hr, 4) * 2.0, 2)));
check('overtime total in gross', near($o['gross_salary'], 4500 + $o['overtime_1_5'] + $o['overtime_2_0']));
$cfg = ['overtime' => ['weekly_hours' => 40, 'weeks_per_year' => 52, 'tiers' => [
    ['label' => 'Tier A', 'multiplier' => 1.25], ['label' => 'Tier B', 'multiplier' => 2.5]]]];
$o2 = PayrollEngine::calculate(['basic_salary' => 4160, 'overtime_1_5_hours' => 8, 'overtime_2_0_hours' => 4, 'pay_frequency' => 'Monthly', 'rules' => $cfg]);
check('custom hourly rate (4160*12/2080 = 24)', near($o2['overtime_hourly_rate'], 24.0));
check('custom tier 1 multiplier', near($o2['overtime_1_5'], 8 * 24 * 1.25));
check('custom tier 2 multiplier', near($o2['overtime_2_0'], 4 * 24 * 2.5));
$o3 = PayrollEngine::calculate(['basic_salary' => 4160, 'overtime' => 100, 'overtime_1_5_hours' => 8, 'pay_frequency' => 'Monthly', 'rules' => $cfg]);
check('typed overtime amount adds to hours', near($o3['overtime'], 100 + 8 * 24 * 1.25));
$bad = PayrollEngine::default_rules(); $bad['overtime']['tiers'][0]['multiplier'] = 0.5;
check('multiplier below 1 rejected', count(PayrollEngine::validate_rules($bad)) === 1);
$bad = PayrollEngine::default_rules(); $bad['overtime']['weekly_hours'] = 0;
check('zero weekly hours rejected', count(PayrollEngine::validate_rules($bad)) === 1);

// Dated rule versions: the employer pension rate changes from 1 Jan 2027, earlier runs keep 8%.
$r26 = PayrollEngine::default_rules();
$r27 = PayrollEngine::default_rules(); $r27['pension']['employer_rate'] = 0.09;
$setting = PayrollEngine::with_version(PayrollEngine::with_version(['versions' => []], '2026-01-01', $r26), '2027-01-01', $r27);
check('versions sorted', array_column($setting['versions'], 'effective_from') === ['2026-01-01', '2027-01-01']);
check('2026 run uses 8%', PayrollEngine::rules_on($setting, '2026-12-31')['pension']['employer_rate'] == 0.08);
check('2027 run uses 9%', PayrollEngine::rules_on($setting, '2027-01-01')['pension']['employer_rate'] == 0.09);
check('before first version uses earliest', PayrollEngine::rules_on($setting, '2025-06-01')['pension']['employer_rate'] == 0.08);
$p = PayrollEngine::calculate(['basic_salary' => 1000, 'rules' => PayrollEngine::rules_on($setting, '2027-03-31')]);
check('2027 employer pension 9% in calculation', near($p['employer_pension'], 90.00));
check('replace a version', PayrollEngine::rules_on(PayrollEngine::with_version($setting, '2027-01-01', $r26), '2027-02-01')['pension']['employer_rate'] == 0.08);
check('remove a version', count(PayrollEngine::without_version($setting, '2027-01-01')['versions']) === 1);
$json = json_encode($setting);
check('setting round-trips through JSON', PayrollEngine::decode_setting($json) == $setting);
$legacy = PayrollEngine::decode_setting(json_encode(['pension' => ['employee_rate' => 0.05, 'employer_rate' => 0.06]]));
check('legacy single-object setting still works', PayrollEngine::rules_on($legacy, '2026-05-05')['pension']['employer_rate'] == 0.06);
check('empty setting gives preset', PayrollEngine::rules_on(PayrollEngine::decode_setting(''), '2026-01-01')['pension']['employer_rate'] == 0.08);

// Tax year and pay-period numbering
check('tax year start (Jan)', PayrollEngine::tax_year_start('2026-11-30', 1) === '2026-01-01');
check('tax year start (Jul, before)', PayrollEngine::tax_year_start('2026-03-15', 7) === '2025-07-01');
check('tax year start (Jul, after)', PayrollEngine::tax_year_start('2026-08-15', 7) === '2026-07-01');
check('monthly period number', PayrollEngine::period_number('Monthly', '2026-01-01', '2026-06-30') === 6);
check('monthly period over year start (Jul)', PayrollEngine::period_number('Monthly', '2025-07-01', '2026-03-31') === 9);
check('weekly period number', PayrollEngine::period_number('Weekly', '2026-01-01', '2026-01-08') === 2);
check('fortnightly period number', PayrollEngine::period_number('Fortnightly', '2026-01-01', '2026-01-15') === 2);
check('semi-monthly period number', PayrollEngine::period_number('Semi-monthly', '2026-01-01', '2026-02-20') === 4);
check('period number never above periods per year', PayrollEngine::period_number('Monthly', '2026-01-01', '2028-01-01') === 12);

// Prior-year-to-date excludes back pay and its tax
[$pg, $pt] = PayrollEngine::ytd_prior([
    ['gross_salary' => 3000, 'retro_pay' => 0, 'paye_tax' => 100, 'srt_tax' => 0, 'paye_lump' => 0],
    ['gross_salary' => 3500, 'retro_pay' => 500, 'paye_tax' => 160, 'srt_tax' => 0, 'paye_lump' => 60],
], 1000, 50);
check('prior gross excludes back pay, includes opening', $pg === 7000.0);
check('prior tax excludes lump tax, includes opening', $pt === 250.0);

// A steady salary taxed cumulatively gives the same tax each month once prior pay is supplied.
$monthly = 3000.0;
$tax1 = PayrollEngine::calculate(['basic_salary' => $monthly, 'pay_period_n' => 1])['income_tax'];
$prevG = 0.0; $prevT = 0.0; $same = true;
for ($n = 1; $n <= 12; $n++) {
    $c = PayrollEngine::calculate(['basic_salary' => $monthly, 'pay_period_n' => $n, 'g_ytd_prior' => $prevG, 't_ytd_prior' => $prevT]);
    if (abs($c['income_tax'] - $tax1) > 0.02) $same = false;
    $prevG += $c['gross_salary']; $prevT += $c['income_tax'];
}
check('steady salary: same tax every month with year-to-date inputs', $same);
$annual = PayrollEngine::tax_lookup($monthly * 12, true);
check('steady salary: year total equals annual tax', abs($prevT - $annual) < 0.2);
// Without any YTD history, a mid-year run annualises the period's pay
// (FRCS period tables) instead of projecting only the remaining periods.
$noPrior = PayrollEngine::calculate(['basic_salary' => $monthly, 'pay_period_n' => 7])['income_tax'];
check('mid-year with no history withholds the annualised (steady) tax', abs($noPrior - $tax1) < 0.02);
check('rule: tax year start month validated', count(PayrollEngine::validate_rules(['tax_year_start_month' => 13] + PayrollEngine::default_rules())) === 1);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll engine tests passed\n";
exit($fail ? 1 : 0);
