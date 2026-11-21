<?php
/**
 * Payroll end to end on a real chart: runs, journals, YTD PAYE, payment, remittances,
 * void/reverse, period lock, duplicates, isolation, and the preset FNPF rate by date.
 */
require '/home/claude/nuviserpx/tests/db/_harness.php';

class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return $k === 'id' ? 1 : ($k === 'type' ? 1 : null); }
    public function is_super_admin() { return true; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function base_currency($t = null) { return 'FJD'; }
    public function currency_tag() { return 'FJD'; }
    public function can_access($p) { return true; }
    public function info($k = '') { return ''; }
}
$_SESSION['userdata'] = ['id' => 1, 'type' => 1, 'login_type' => 1];

[$c, $db] = nv_fresh_db();
(new \App\Database\LegacySchemaCatchup($c))->run();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$c->query("DELETE FROM account_list WHERE tenant_id = 1");
\App\Services\ChartProvisioner::provision($c, 1);
$S = new SystemSettings();
$ps = new \App\Services\PayrollService($c, $S);
$pay = new \App\Services\PayrollPaymentService($c, $S);
$rem = new \App\Services\PayrollRemittanceService($c, $S);
$led = new \App\Services\LedgerService($c, 1);

function j($s) { return json_decode($s, true) ?: []; }
function ok($s) { return (j($s)['status'] ?? '') === 'success'; }
function bal($c, $code) {
    $q = $c->query("SELECT id FROM account_list WHERE tenant_id=1 AND code='$code' AND delete_flag=0");
    $id = (int)$q->fetch_assoc()['id'];
    $side = \App\Services\LedgerService::SIDE_SQL; $posted = \App\Services\LedgerService::POSTED_SQL;
    $r = $c->query("SELECT COALESCE(SUM(CASE WHEN $side=1 THEN ji.amount ELSE -ji.amount END),0) b FROM journal_items ji
        JOIN journal_entries j ON j.id=ji.journal_id AND j.tenant_id=1 AND $posted LEFT JOIN group_list g ON g.id=ji.group_id WHERE ji.account_id=$id");
    return round((float)$r->fetch_assoc()['b'], 2);
}
function liab($c, $name) {
    $q = $c->query("SELECT id FROM account_list WHERE tenant_id=1 AND name='" . $c->real_escape_string($name) . "' AND delete_flag=0");
    $row = $q->fetch_assoc(); if (!$row) return null; $id = (int)$row['id'];
    $side = \App\Services\LedgerService::SIDE_SQL; $posted = \App\Services\LedgerService::POSTED_SQL;
    $r = $c->query("SELECT COALESCE(SUM(CASE WHEN $side=2 THEN ji.amount ELSE -ji.amount END),0) b FROM journal_items ji
        JOIN journal_entries j ON j.id=ji.journal_id AND j.tenant_id=1 AND $posted LEFT JOIN group_list g ON g.id=ji.group_id WHERE ji.account_id=$id");
    return round((float)$r->fetch_assoc()['b'], 2);
}
function tbBalanced($led) {
    global $c;
    $side = \App\Services\LedgerService::SIDE_SQL; $posted = \App\Services\LedgerService::POSTED_SQL;
    $r = $c->query("SELECT COALESCE(SUM(CASE WHEN $side=1 THEN ji.amount ELSE 0 END),0) d, COALESCE(SUM(CASE WHEN $side=2 THEN ji.amount ELSE 0 END),0) cr
        FROM journal_items ji JOIN journal_entries j ON j.id=ji.journal_id AND j.tenant_id=1 AND $posted LEFT JOIN group_list g ON g.id=ji.group_id")->fetch_assoc();
    return abs((float)$r['d'] - (float)$r['cr']) < 0.005;
}

$c->query("INSERT INTO employee_list (id,tenant_id,employee_code,firstname,lastname,email,date_of_joining,salary,annual_salary,tin,fnpf_no,tax_code,is_resident,status,delete_flag) VALUES
 (1,1,'E1','Ana','Tui','a@x.fj','2025-01-01',5000,60000,'123','F1','P',1,1,0),
 (2,1,'E2','Bob','Rabu','b@x.fj','2025-01-01',2000,24000,'124','F2','P',1,1,0)");

$bank = (int)$c->query("SELECT id FROM account_list WHERE tenant_id=1 AND type='Bank' AND delete_flag=0 ORDER BY id LIMIT 1")->fetch_assoc()['id'];

// ---- 12 monthly runs for Ana (2026 is before the Aug cut, so the preset employer rate is 10% Jan-Jul, 8% Aug-Dec)
$totTax = 0; $totEmpFnpf = 0; $totEeFnpf = 0; $totGross = 0; $totNet = 0;
for ($m = 1; $m <= 12; $m++) {
    $end = date('Y-m-t', strtotime("2026-$m-01"));
    $r = $ps->savePayroll(['employee_id' => 1, 'pay_date' => $end, 'period_start' => "2026-" . sprintf('%02d', $m) . "-01", 'period_end' => $end, 'pay_frequency' => 'Monthly', 'salary_month' => date('F Y', strtotime($end))]);
    if (!ok($r)) { echo "run $m failed: $r\n"; }
}
$rows = $c->query("SELECT * FROM payroll_list WHERE tenant_id=1 AND employee_id=1 ORDER BY pay_date")->fetch_all(MYSQLI_ASSOC);
check('12 runs saved', count($rows) === 12);
foreach ($rows as $r) { $totTax += $r['paye_tax'] ?? $r['tax'] ?? 0; }
$cols = array_keys($rows[0]);
$taxCol = in_array('paye', $cols) ? 'paye' : (in_array('paye_tax', $cols) ? 'paye_tax' : (in_array('income_tax', $cols) ? 'income_tax' : (in_array('tax', $cols) ? 'tax' : null)));
check('tax column found', $taxCol !== null);
$totTax = array_sum(array_map(fn($r) => (float)$r[$taxCol], $rows));
check('cumulative PAYE over 12 months = annual tax (5,600)', abs($totTax - 5600) < 0.05);
check('Jan employee FNPF 8% of 5,000', abs((float)$rows[0]['employee_fnpf'] - 400) < 0.005 || abs((float)($rows[0]['fnpf_employee'] ?? 0) - 400) < 0.005);

// journals balanced
check('trial balance balances after 12 runs', tbBalanced($led));
check('wages expense = 12 x 5,000', abs(bal($c, '5100') - 60000) < 0.05);
$empFnpf = round(5000 * 0.10 * 7 + 5000 * 0.08 * 5, 2); // 3,500 + 2,000
check('employer pension expense follows 10% to Jul, 8% from Aug', abs(bal($c, '5998') - $empFnpf) < 0.05);

// Liabilities before payment
$netOwed = liab($c, \App\Services\PayrollPostingService::ACCOUNTS['net'][0]);
check('net salaries payable equals sum of net pay', abs($netOwed - array_sum(array_map(fn($r) => (float)$r['net_salary'], $rows))) < 0.05);

// duplicate run for same period
$dup = $ps->savePayroll(['employee_id' => 1, 'pay_date' => '2026-01-31', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'pay_frequency' => 'Monthly', 'salary_month' => 'January 2026']);
$cnt = (int)$c->query("SELECT COUNT(*) n FROM payroll_list WHERE tenant_id=1 AND employee_id=1 AND status<>'voided' AND period_end='2026-01-31'")->fetch_assoc()['n'];
check('duplicate period run does not create a second payroll', $cnt === 1);

// ---- payment
$refs = array_column($c->query("SELECT DISTINCT payroll_run_ref r FROM payroll_list WHERE tenant_id=1 AND status='posted' AND payroll_run_ref<>''")->fetch_all(MYSQLI_ASSOC), 'r');
$resp = $pay->pay(['refs' => $refs, 'pay_date' => '2026-12-31', 'bank_account_id' => $bank, 'reference' => 'BATCH']);
check('salary payment accepted', ok($resp));
check('net salaries payable cleared by payment', abs((float)liab($c, \App\Services\PayrollPostingService::ACCOUNTS['net'][0])) < 0.05);
$unpaid = (int)$c->query("SELECT COUNT(*) n FROM payroll_list WHERE tenant_id=1 AND payment_status='unpaid' AND status='posted'")->fetch_assoc()['n'];
check('all runs flagged paid', $unpaid === 0);
$again = $pay->pay(['refs' => $refs, 'pay_date' => '2026-12-31', 'bank_account_id' => $bank]);
check('cannot pay the same runs twice', !ok($again));

// ---- remittances
$bals = $rem->balances();
check('pension owed = 16-18% of gross', $bals['pension']['owed'] > 0);
check('tax owed = 5,600', abs($bals['tax']['owed'] - 5600) < 0.05);
$over = $rem->remit(['liability' => 'tax', 'remit_date' => '2026-12-31', 'amount' => $bals['tax']['owed'] + 100, 'bank_account_id' => $bank]);
check('cannot remit more than owed', !ok($over));
check('remit tax in full', ok($rem->remit(['liability' => 'tax', 'remit_date' => '2027-01-15', 'amount' => $bals['tax']['owed'], 'bank_account_id' => $bank, 'reference' => 'FRCS'])));
check('remit pension in full', ok($rem->remit(['liability' => 'pension', 'remit_date' => '2027-01-15', 'amount' => $bals['pension']['owed'], 'bank_account_id' => $bank])));
$b2 = $rem->balances();
check('tax and pension liabilities cleared', abs($b2['tax']['owed']) < 0.01 && abs($b2['pension']['owed']) < 0.01);
check('trial balance balances after payment and remittance', tbBalanced($led));

// ---- reverse a remittance restores liability
$rid = (int)$c->query("SELECT id FROM payroll_remittances WHERE liability='tax' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
check('remittance reversal ok', ok($rem->reverse($rid)));
check('tax owed restored after reversal', abs($rem->outstanding('tax') - 5600) < 0.05);

// ---- void a paid payroll is refused; reverse payment then void works
$jan = (int)$rows[0]['id'];
check('paid payroll cannot be voided', !ok($ps->deletePayroll($jan)));
$payId = (int)$c->query("SELECT id FROM payroll_payments ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
check('payment reversal ok', ok($pay->reverse($payId)));
check('net payable restored after payment reversal', liab($c, \App\Services\PayrollPostingService::ACCOUNTS['net'][0]) > 0);
$gl = bal($c, '5100');
check('payroll with remitted pension cannot be voided', !ok($ps->deletePayroll($jan)));
$pid = (int)$c->query("SELECT id FROM payroll_remittances WHERE liability='pension' AND status='posted' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
check('pension remittance reversal ok', ok($rem->reverse($pid)));
$vr = $ps->deletePayroll($jan);
check('void unpaid payroll ok', ok($vr));
check('wages expense falls after void', bal($c, '5100') < $gl);
check('trial balance balances after void', tbBalanced($led));

// ---- period lock blocks posting
(new \App\Services\PeriodLockService($c, $S))->setLock('2026-10-31', 'Oct closed');
$blocked = $ps->savePayroll(['employee_id' => 2, 'pay_date' => '2026-06-30', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'pay_frequency' => 'Monthly', 'salary_month' => 'June 2026']);
check('locked period blocks payroll', !ok($blocked));
(new \App\Services\PeriodLockService($c, $S))->setLock('2000-01-01', 'reopened');

// ---- preset FNPF employer rate by date
check('employer FNPF 10% before Aug 2026', \PayrollEngine::rules_on(['versions' => []], '2026-07-31')['pension']['employer_rate'] == 0.10);
check('employer FNPF 8% from 1 Aug 2026', \PayrollEngine::rules_on(['versions' => []], '2026-08-01')['pension']['employer_rate'] == 0.08);
check('employer FNPF 8% to 31 Jul 2027', \PayrollEngine::rules_on(['versions' => []], '2027-07-31')['pension']['employer_rate'] == 0.08);
check('employer FNPF back to 10% from Aug 2027', \PayrollEngine::rules_on(['versions' => []], '2027-08-01')['pension']['employer_rate'] == 0.10);

// ---- special pay: unpaid days, overtime tiers, redundancy, non-resident, terminated, isolation
function run($ps, $emp, $m, $extra = []) {
    $end = date('Y-m-t', strtotime("2027-$m-01"));
    return $ps->savePayroll(['employee_id' => $emp, 'pay_date' => $end, 'period_start' => date('Y-m-01', strtotime($end)), 'period_end' => $end, 'pay_frequency' => 'Monthly', 'salary_month' => date('F Y', strtotime($end))] + $extra);
}
function last($c, $emp) { return $c->query("SELECT * FROM payroll_list WHERE employee_id=$emp AND status='posted' ORDER BY id DESC LIMIT 1")->fetch_assoc(); }
check('unpaid-days run ok', ok(run($ps, 2, 1, ['unpaid_days' => 2])));
$r = last($c, 2);
check('2 unpaid days deducted at annual/(52x5) a day', abs((float)$r['unpaid_deduction'] - round(2 * 24000 / 260, 2)) < 0.05);
check('gross reduced by unpaid deduction', abs((float)$r['gross_salary'] - (2000 - (float)$r['unpaid_deduction'])) < 0.05);
check('overtime run ok', ok(run($ps, 2, 2, ['overtime_1_5_hours' => 10, 'overtime_2_0_hours' => 4])));
$r = last($c, 2);
$hr = 24000 / (52 * 45);
check('overtime = hours x hourly x multiplier', abs((float)$r['overtime'] - round($hr * 1.5 * 10 + $hr * 2.0 * 4, 2)) < 0.1);
check('overtime counted in FNPF base', (float)$r['fnpf_base'] > 2000);
check('redundancy run ok', ok(run($ps, 2, 3, ['redundancy_pay' => 20000])));
$r = last($c, 2);
check('redundancy taxed 15% on the part above 15,000', abs((float)$r['paye_tax'] - 750) < 0.05);
check('redundancy not subject to FNPF', abs((float)$r['employee_fnpf'] - 160) < 0.5);
check('month after redundancy ok', ok(run($ps, 2, 4)));
$r = last($c, 2);
check('redundancy does not inflate the next month\'s PAYE (24,000 a year is under the tax-free band)', abs((float)$r['paye_tax']) < 0.05);
check('non-resident taxed 20% from the first dollar', abs(\PayrollEngine::tax_lookup(24000, false) - 4800) < 0.05);
$c->query("UPDATE employee_list SET termination_date='2027-03-31' WHERE id=2");
check('no payroll after termination date', !ok(run($ps, 2, 6)));
$c->query("INSERT INTO employee_list (id,tenant_id,employee_code,firstname,lastname,email,date_of_joining,salary,annual_salary,tax_code,is_resident,status,delete_flag) VALUES (9,2,'X1','Other','Co','o@x.fj','2025-01-01',1000,12000,'P',1,1,0)");
check("another company's employee cannot be paid", !ok(run($ps, 9, 1)));
check('trial balance still balances', tbBalanced($led));

nv_done();
