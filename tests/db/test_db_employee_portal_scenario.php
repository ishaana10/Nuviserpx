<?php
/**
 * The employee portal from the employee's side: activation, sign-in, lock-outs, password change, payslips (own, posted
 * only), leave requests (own only, balances, overlaps, date window, cancel) and what must stay out of reach.
 */
require __DIR__ . '/_harness.php';
if (!defined('base_url')) define('base_url', 'http://localhost/');

class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($field = '') { return null; }
    public function is_super_admin() { return false; }
    public function own_employee_id() { return 0; }
    public function employee_scope($m) { return 0; }
    public function log_audit($action, $module, $details = '') {}
    public function set_flashdata($f = '', $v = '') {}
    public function can_access($p) { return false; }
    public function tenant_branding() { return ['name' => 'T']; }
    public function info($k = '') { return ''; }
    public function module_enabled($m) { return true; }
}
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
[$c, $db] = nv_fresh_db();
(new \App\Database\LegacySchemaCatchup($c))->run();
$S = new SystemSettings();
$svc = new \App\Services\EmployeePortalService($c, $S);
function ok($s) { return (json_decode($s, true)['status'] ?? '') === 'success'; }

$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Other Co')");
$c->query("INSERT INTO employee_list (id,tenant_id,employee_code,firstname,lastname,email,date_of_joining,salary,annual_salary,status,delete_flag) VALUES
 (1,1,'E1','Ana','Tui','ana@x.fj','2025-01-01',3000,36000,1,0),
 (2,1,'E2','Bob','Rabu','bob@x.fj','2025-01-01',2000,24000,1,0),
 (3,2,'E3','Cara','Other','cara@y.fj','2025-01-01',2000,24000,1,0)");
(new \App\Services\LeaveBalanceService($c, $S))->savePolicy(['leave_type' => 'casual', 'tracked' => 1, 'annual_days' => 10]);

// ---- activation
$tok = 'tok-' . bin2hex(random_bytes(8));
$h = \App\Services\EmployeePortalService::tokenHash($tok);
$c->query("INSERT INTO employee_portal_users (tenant_id,employee_id,email,invite_token_hash,invite_expires_at,status) VALUES (1,1,'ana@x.fj','$h',DATE_ADD(NOW(),INTERVAL 3 DAY),0)");
check('unknown token refused', !$svc->activate('nope', 'Strong#Pass123', '10.0.0.1')['ok']);
check('weak password refused at activation', !$svc->activate($tok, 'short', '10.0.0.1')['ok']);
check('password equal to the email name refused', !$svc->activate($tok, 'ana@x.fj', '10.0.0.1')['ok']);
$act = $svc->activate($tok, 'Strong#Pass123', '10.0.0.1');
check('activation works and signs in', $act['ok'] && $act['session']['employee_id'] === 1 && $act['session']['tenant_id'] === 1);
check('an invitation can be used only once', !$svc->activate($tok, 'Other#Pass1234', '10.0.0.1')['ok']);
$c->query("UPDATE employee_portal_users SET invite_token_hash='$h', invite_expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY), status=0 WHERE employee_id=1");
check('expired invitation refused', !$svc->activate($tok, 'Strong#Pass123', '10.0.0.1')['ok']);
$c->query("UPDATE employee_portal_users SET invite_expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE employee_id=1");
$c->query("UPDATE employee_list SET status=0 WHERE id=1");
check('invitation cannot be used for an inactive employee', !$svc->activate($tok, 'Strong#Pass123', '10.0.0.1')['ok']);
$c->query("UPDATE employee_list SET status=1 WHERE id=1");
check('activation works again once active', $svc->activate($tok, 'Strong#Pass123', '10.0.0.1')['ok']);
$pu = (int)$c->query("SELECT id FROM employee_portal_users WHERE employee_id=1")->fetch_assoc()['id'];
check('stored password is hashed', password_verify('Strong#Pass123', $c->query("SELECT password FROM employee_portal_users WHERE id=$pu")->fetch_assoc()['password']));
check('invitation token is not kept after use', $c->query("SELECT invite_token_hash h FROM employee_portal_users WHERE id=$pu")->fetch_assoc()['h'] === null);

// ---- sign in and lock-outs
check('sign in with the right password', $svc->login('ana@x.fj', 'Strong#Pass123', '10.0.0.2')['ok']);
check('user name is not case sensitive', $svc->login('ANA@x.fj', 'Strong#Pass123', '10.0.0.2')['ok']);
$bad = $svc->login('ana@x.fj', 'wrong', '10.0.0.3');
check('wrong password refused with a generic message', !$bad['ok'] && stripos($bad['msg'], 'invalid') !== false);
$unk = $svc->login('nobody@x.fj', 'wrong', '10.0.0.3');
check('unknown user gets the same message as a wrong password', $unk['msg'] === $bad['msg']);
for ($i = 0; $i < 6; $i++) { $svc->login('ana@x.fj', 'guess' . $i, '10.0.0.4'); }
check('repeated wrong passwords lock the sign-in', !$svc->login('ana@x.fj', 'Strong#Pass123', '10.0.0.4')['ok']);
$c->query("UPDATE employee_portal_users SET locked_until=NULL, failed_attempts=0 WHERE id=$pu");
$c->query("DELETE FROM auth_attempts");
check('one address cannot try many different accounts', (function () use ($svc) {
    for ($i = 0; $i < 31; $i++) { $svc->login("user$i@x.fj", 'guess', '10.9.9.9'); }
    return !$svc->login('ana@x.fj', 'Strong#Pass123', '10.9.9.9')['ok'];
})());
$c->query("DELETE FROM auth_attempts");
check('a lock on one address does not stop the employee elsewhere', $svc->login('ana@x.fj', 'Strong#Pass123', '10.0.0.77')['ok']);

// ---- password change
$chg = fn($cur, $new, $conf) => $svc->changePassword($pu, 1, 1, $cur, $new, $conf, '10.0.0.2');
check('wrong current password refused', !$chg('nope', 'Brand#New1234', 'Brand#New1234')['ok']);
check('mismatched confirmation refused', !$chg('Strong#Pass123', 'Brand#New1234', 'Different#1234')['ok']);
check('same password refused', !$chg('Strong#Pass123', 'Strong#Pass123', 'Strong#Pass123')['ok']);
check('weak new password refused', !$chg('Strong#Pass123', 'abc', 'abc')['ok']);
check('password change works', $chg('Strong#Pass123', 'Brand#New1234', 'Brand#New1234')['ok']);
check('old password stops working, new one works', !$svc->login('ana@x.fj', 'Strong#Pass123', '10.0.0.5')['ok'] && $svc->login('ana@x.fj', 'Brand#New1234', '10.0.0.5')['ok']);
check("another employee's portal id cannot be used to change a password", !$svc->changePassword($pu, 1, 2, 'Brand#New1234', 'Third#Pass1234', 'Third#Pass1234', '10.0.0.2')['ok']);
check("another company's tenant cannot be used", !$svc->changePassword($pu, 2, 1, 'Brand#New1234', 'Third#Pass1234', 'Third#Pass1234', '10.0.0.2')['ok']);

// ---- payslips: own and posted only
$c->query("INSERT INTO payroll_list (id,tenant_id,employee_id,salary_month,status,gross_salary,net_salary,pay_date) VALUES
 (10,1,1,'January 2026','posted',3000,2500,'2026-01-31'),
 (11,1,1,'February 2026','voided',3000,2500,'2026-02-28'),
 (12,1,1,'March 2026','draft',3000,2500,'2026-03-31'),
 (20,1,2,'January 2026','posted',2000,1700,'2026-01-31'),
 (30,2,3,'January 2026','posted',2000,1700,'2026-01-31')");
$mine = array_column($svc->payslips(1, 1), 'id');
check('payslip list shows only own posted payslips', $mine == [10]);
check('own payslip opens', $svc->payslip(1, 1, 10) !== null);
check("a colleague's payslip is refused", $svc->payslip(1, 1, 20) === null);
check("another company's payslip is refused", $svc->payslip(1, 1, 30) === null);
check('voided and draft payslips are refused', $svc->payslip(1, 1, 11) === null && $svc->payslip(1, 1, 12) === null);
check('viewing a payslip is logged', (int)$c->query("SELECT COUNT(*) n FROM employee_portal_logs WHERE action='payslip_viewed'")->fetch_assoc()['n'] === 1);

// ---- HR switches access off while signed in
check('active session is valid', $svc->stillActive($pu, 1, 1));
(new \App\Services\EmployeePortalService($c, new class extends SystemSettings {}))->revoke(1);
check('revoked access ends the session straight away', !$svc->stillActive($pu, 1, 1) && !$svc->login('ana@x.fj', 'Brand#New1234', '10.0.0.6')['ok']);
$c->query("UPDATE employee_portal_users SET status=1, password='" . password_hash('Brand#New1234', PASSWORD_DEFAULT) . "' WHERE id=$pu");

// ---- leave self-service
$L = new \App\Services\PortalLeaveService($c, 1, 1);
$day = function ($n) { $d = strtotime("+$n days"); while (in_array(date('N', $d), [6, 7])) $d += 86400; return date('Y-m-d', $d); };
$a = $day(14); $b = $day(15);
$apply = fn($o) => $L->apply($o + ['leave_type' => 'casual', 'start_date' => $a, 'end_date' => $b, 'reason' => 'Family']);
check('leave request submits', ok($apply([])));
$lid = (int)$c->query("SELECT id FROM leave_list ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
check('it is saved for the signed-in employee and pending', (int)$c->query("SELECT employee_id FROM leave_list WHERE id=$lid")->fetch_assoc()['employee_id'] === 1 && $c->query("SELECT status FROM leave_list WHERE id=$lid")->fetch_assoc()['status'] === 'pending');
check('overlapping request refused', !ok($apply([])));
check('employee id in the form is ignored', (function () use ($apply, $c, $day) { $r = $apply(['employee_id' => 2, 'start_date' => $day(40), 'end_date' => $day(40)]); return ok($r) && (int)$c->query("SELECT employee_id FROM leave_list ORDER BY id DESC LIMIT 1")->fetch_assoc()['employee_id'] === 1; })());
check('status in the form is ignored (always pending)', (function () use ($apply, $c, $day) { $apply(['status' => 'approved', 'start_date' => $day(50), 'end_date' => $day(50)]); return $c->query("SELECT status FROM leave_list ORDER BY id DESC LIMIT 1")->fetch_assoc()['status'] === 'pending'; })());
check('an id in the form cannot overwrite another request', (function () use ($apply, $c, $day, $lid) { $apply(['id' => $lid, 'start_date' => $day(60), 'end_date' => $day(60)]); return $c->query("SELECT start_date FROM leave_list WHERE id=$lid")->fetch_assoc()['start_date'] === $GLOBALS['a']; })());
check('leave too far back refused', !ok($apply(['start_date' => date('Y-m-d', strtotime('-200 days')), 'end_date' => date('Y-m-d', strtotime('-199 days'))])));
check('leave too far ahead refused', !ok($apply(['start_date' => date('Y-m-d', strtotime('+5 years')), 'end_date' => date('Y-m-d', strtotime('+5 years'))])));
check('weekend-only request refused', (function () use ($apply) { $d = strtotime('next saturday +14 days'); return !ok($apply(['start_date' => date('Y-m-d', $d), 'end_date' => date('Y-m-d', $d + 86400)])); })());
check('request beyond the balance refused', !ok($apply(['start_date' => $day(100), 'end_date' => date('Y-m-d', strtotime($day(100) . ' +40 days'))])));
check('half day over two dates refused', !ok($apply(['day_fraction' => 0.5, 'start_date' => $day(70), 'end_date' => $day(71)])));
check('unknown leave type refused', !ok($apply(['leave_type' => 'sabbatical', 'start_date' => $day(80), 'end_date' => $day(80)])));
check('reason is capped', (function () use ($apply, $c, $day) { $apply(['reason' => str_repeat('x', 900), 'start_date' => $day(90), 'end_date' => $day(90)]); return strlen($c->query("SELECT reason FROM leave_list ORDER BY id DESC LIMIT 1")->fetch_assoc()['reason']) <= 500; })());
// cancel
$c->query("INSERT INTO leave_list (id,tenant_id,employee_id,leave_type,start_date,end_date,status) VALUES (800,1,2,'casual','2028-05-02','2028-05-02','pending')");
check("cannot cancel a colleague's request", !ok($L->cancel(800)));
check('can cancel own pending request', ok($L->cancel($lid)));
check('cancelling twice refused', !ok($L->cancel($lid)));
$c->query("INSERT INTO leave_list (id,tenant_id,employee_id,leave_type,start_date,end_date,status) VALUES (801,1,1,'casual','2027-02-01','2027-02-01','approved')");
check('cannot cancel an approved request from the portal', !ok($L->cancel(801)));
check('history shows only own requests', (function () use ($L) { foreach ($L->history(100) as $r) { if ((int)$r['id'] === 800) return false; } return true; })());
check("details expose no pay or bank data", (function () use ($L) { $d = $L->details(); return !isset($d['salary']) && !isset($d['annual_salary']) && !isset($d['bank_account']) && !isset($d['tin']); })());
check('calendar shows only own leave', (function () use ($L) { foreach ($L->calendar()['leave'] as $r) { if (($r['s'] ?? '') === '2028-05-02') return false; } return true; })());
// attachments
check('attachment of a colleague is not reachable', $L->attachment(800) === null);

nv_done();
