<?php
// PAYE must not vanish when a run starts mid-year with no YTD history:
// FRCS period tables annualise the period's pay (threshold $30,000, then
// 18%/20%; SRT folded into PAYE since 1 Jan 2024).
$root = dirname(__DIR__);
require_once $root . '/classes/PayrollEngine.php';

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// 36,000/yr fortnightly, first run in November, nothing recorded yet
$r = PayrollEngine::calculate_cumulative_tax(['pay_frequency' => 'Fortnightly', 'pay_period_n' => 23, 'regular_pay' => 1384.62, 'is_resident' => true]);
check('fortnightly 36k/yr with no history withholds ~41.54, not zero', abs($r['total_paye'] - 41.54) < 0.05);

$r = PayrollEngine::calculate_cumulative_tax(['pay_frequency' => 'Monthly', 'pay_period_n' => 11, 'regular_pay' => 3000, 'is_resident' => true]);
check('monthly 36k/yr with no history withholds 90.00', abs($r['total_paye'] - 90.00) < 0.01);

// Below the threshold stays untaxed
$r = PayrollEngine::calculate_cumulative_tax(['pay_frequency' => 'Fortnightly', 'pay_period_n' => 23, 'regular_pay' => 1000, 'is_resident' => true]);
check('26k/yr stays under the 30k threshold: zero PAYE', $r['total_paye'] == 0.0);

// With real YTD history the cumulative method is unchanged
$r = PayrollEngine::calculate_cumulative_tax(['pay_frequency' => 'Fortnightly', 'pay_period_n' => 23, 'regular_pay' => 1384.62, 'g_ytd_prior' => 30461.64, 't_ytd_prior' => 913.85, 'is_resident' => true]);
check('cumulative self-correction still applies when history exists', abs($r['total_paye'] - 41.54) < 0.5);

// Non-residents are taxed from the first dollar
$r = PayrollEngine::calculate_cumulative_tax(['pay_frequency' => 'Fortnightly', 'pay_period_n' => 23, 'regular_pay' => 1000, 'is_resident' => false]);
check('non-resident pays from the first dollar', $r['total_paye'] > 0);

// The guard itself
$s = file_get_contents($root . '/classes/PayrollEngine.php');
check('engine annualises when no YTD history exists', strpos($s, 'if ($gYtdPrior <= 0 && $tYtdPrior <= 0)') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
