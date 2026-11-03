<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
require_once(base_app . 'src/Services/PayrollExportService.php');
use App\Services\PayrollExportService as X;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
function parse($csv) { return array_map('str_getcsv', array_filter(explode("\n", $csv), 'strlen')); }

$rows = [
  ['employee_code'=>'E1','emp_name'=>'John Doe','net_salary'=>2500.50,'bank_code'=>'bsp','bank_account'=>'111','pay_date'=>'2026-10-31',
   'tin'=>'99-1','fnpf_no'=>'M1','tax_code'=>'P','is_resident'=>1,'gross_salary'=>3350,'taxable_income'=>3350,'paye_tax'=>105,'srt_tax'=>0,
   'employee_fnpf'=>268,'employer_fnpf'=>268,'fnpf_base'=>3350,'period_start'=>'2026-10-01','period_end'=>'2026-10-31'],
  ['employee_code'=>'E2','emp_name'=>'=HYPERLINK("x")','net_salary'=>1800,'bank_code'=>'anz','bank_account'=>'222','pay_date'=>'2026-10-31',
   'gross_salary'=>2000,'paye_tax'=>0,'employee_fnpf'=>160,'employer_fnpf'=>160],
  ['employee_code'=>'E3','emp_name'=>'Zero Net','net_salary'=>0,'bank_code'=>'bsp','bank_account'=>'333','pay_date'=>'2026-10-31'],
];

$bsp = parse(X::bank($rows, 'bsp', 'RUN-1'));
check('bsp: header + 1 payable row + total', count($bsp) === 3);
check('bsp excludes other banks and zero net', $bsp[1][1] === 'E1');
check('bsp total', $bsp[2][5] === '2500.50');
$gen = parse(X::bank($rows, 'generic', 'RUN-1'));
check('generic includes every payable row', count($gen) === 4 && end($gen)[5] === '4300.50');
check('unknown bank falls back to generic', count(parse(X::bank($rows, 'nope', 'R'))) === 4);
check('formula injection neutralised', $gen[2][2] === "'=HYPERLINK(\"x\")");

$tax = parse(X::incomeTax($rows, '50-1', 'RUN-1'));
check('tax return rows', count($tax) === 5);
check('tax withheld', $tax[1][10] === '105.00');
check('missing TIN shows N/A', $tax[2][4] === 'N/A');
check('tax total', end($tax)[10] === '105.00' && end($tax)[8] === '5350.00');

$pen = parse(X::pensionSchedule($rows, 'ER-9', 'RUN-1'));
check('pension total contribution', end($pen)[8] === '856.00');
check('pension row total', $pen[1][8] === '536.00');

// Rule validation
$ok = PayrollEngine::default_rules();
check('default rules valid', PayrollEngine::validate_rules($ok) === []);
$bad = $ok; $bad['pension']['employee_rate'] = 1.5;
check('rate over 100% rejected', count(PayrollEngine::validate_rules($bad)) === 1);
$bad = $ok; $bad['resident_bands'][3]['up_to'] = 100;
check('non-increasing band rejected', count(PayrollEngine::validate_rules($bad)) >= 1);
$bad = $ok; $bad['resident_bands'][count($bad['resident_bands']) - 1]['up_to'] = 5e6;
check('bounded last band rejected', count(PayrollEngine::validate_rules($bad)) >= 1);
$bad = $ok; $bad['employer_levies'][1]['key'] = 'workcare_levy';
check('duplicate payslip field rejected', count(PayrollEngine::validate_rules($bad)) === 1);
$bad = $ok; $bad['employer_levies'][0]['label'] = ' ';
check('blank levy name rejected', count(PayrollEngine::validate_rules($bad)) === 1);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll export tests passed\n";
exit($fail ? 1 : 0);
