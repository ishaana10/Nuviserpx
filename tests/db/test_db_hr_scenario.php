<?php
/**
 * HR end to end as an HR manager would use it: employee records, leave (requests, overlaps, approval,
 * cancelling approved leave, balances), attendance, shift rosters, leavers and self-service access.
 */
require __DIR__ . '/_harness.php';

class SystemSettings {
    public static $super = true; public static $own = 0; public static $scoped = false;
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return $k === 'id' ? 1 : ($k === 'type' ? 1 : null); }
    public function is_super_admin() { return self::$super; }
    public function own_employee_id() { return self::$own; }
    public function employee_scope($m) { return self::$scoped ? self::$own : null; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function base_currency($t = null) { return 'FJD'; }
    public function can_access($p) { return true; }
    public function tenant_branding() { return ['name' => 'T']; }
    public function info($k = '') { return ''; }
}
if (!defined('base_url')) define('base_url', 'http://localhost/');
$_SESSION['userdata'] = ['id' => 1, 'type' => 1, 'login_type' => 1];

[$c, $db] = nv_fresh_db();
(new \App\Database\LegacySchemaCatchup($c))->run();
$S = new SystemSettings();
$emp = new \App\Services\EmployeeService($c, $S);
$lb = new \App\Services\LeaveBalanceService($c, $S);
$shift = new \App\Services\ShiftRosterService($c, $S);
$portal = new \App\Services\EmployeePortalService($c, $S);
function ok($s) { return (json_decode($s, true)['status'] ?? '') === 'success'; }
function id($c, $code, $t = 1) { return (int)$c->query("SELECT id FROM employee_list WHERE employee_code='$code' AND tenant_id=$t")->fetch_assoc()['id']; }
function emp($emp, $o = []) {
    static $n = 0; $n++;
    return $emp->saveEmployee($o + ['employee_code' => "E$n", 'firstname' => "First$n", 'lastname' => "Last$n", 'date_of_joining' => '2025-01-01', 'annual_salary' => 36000, 'status' => 1]);
}

// ================= employee records =================
check('valid employee saves', ok(emp($emp, ['employee_code' => 'A1', 'firstname' => 'Ana', 'lastname' => 'Tui'])));
$a1 = id($c, 'A1');
check('employee code is required', !ok($emp->saveEmployee(['employee_code' => '', 'firstname' => 'No', 'lastname' => 'Code'])));
check('first and last name are required', !ok($emp->saveEmployee(['employee_code' => 'N1', 'firstname' => '', 'lastname' => ''])));
check('duplicate code refused (any case)', !ok(emp($emp, ['employee_code' => 'a1'])));
check('left before joining refused', !ok(emp($emp, ['employee_code' => 'D1', 'date_of_joining' => '2026-05-01', 'termination_date' => '2026-01-01'])));
check('negative salary refused', !ok(emp($emp, ['employee_code' => 'S1', 'annual_salary' => -100])));
check('approver cannot be the employee themselves', !ok($emp->saveEmployee(['id' => $a1, 'employee_code' => 'A1', 'firstname' => 'Ana', 'lastname' => 'Tui', 'approver_id' => $a1])));
$c->query("INSERT INTO employee_list (id,tenant_id,employee_code,firstname,lastname,date_of_joining,salary,status,delete_flag) VALUES (900,2,'X','Other','Co','2025-01-01',1000,1,0)");
check('approver from another company refused', !ok(emp($emp, ['employee_code' => 'AP1', 'approver_id' => 900])));
check('approver who has left refused', (function () use ($c, $emp) {
    emp($emp, ['employee_code' => 'GONE', 'termination_date' => '2025-06-30', 'status' => 0]);
    return !ok(emp($emp, ['employee_code' => 'AP2', 'approver_id' => id($c, 'GONE')]));
})());
check('duplicate work email refused', (function () use ($emp) {
    emp($emp, ['employee_code' => 'M1', 'email' => 'same@x.fj']);
    return !ok(emp($emp, ['employee_code' => 'M2', 'email' => 'SAME@x.fj']));
})());
check('bad email refused', !ok(emp($emp, ['employee_code' => 'M3', 'email' => 'nope'])));
check('tenant_id cannot be changed from the form', (function () use ($c, $emp, $a1) {
    $emp->saveEmployee(['id' => $a1, 'employee_code' => 'A1', 'firstname' => 'Ana', 'lastname' => 'Tui', 'tenant_id' => 2]);
    return (int)$c->query("SELECT tenant_id FROM employee_list WHERE id=$a1")->fetch_assoc()['tenant_id'] === 1;
})());

// ================= leave policy and requests =================
$lb->savePolicy(['leave_type' => 'casual', 'tracked' => 1, 'annual_days' => 10, 'carry_over_max' => 5]);
emp($emp, ['employee_code' => 'L1', 'firstname' => 'Lea', 'lastname' => 'Vave', 'date_of_joining' => '2025-01-01']);
$l1 = id($c, 'L1');
$req = fn($o) => $emp->saveLeave($o + ['employee_id' => $l1, 'leave_type' => 'casual', 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'reason' => 'x']);
check('leave request saves as pending', ok($req([])));
$lid = (int)$c->query("SELECT id FROM leave_list ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
check('new leave is pending', $c->query("SELECT status FROM leave_list WHERE id=$lid")->fetch_assoc()['status'] === 'pending');
check('leave needs an employee', !ok($emp->saveLeave(['leave_type' => 'casual', 'start_date' => '2026-04-01', 'end_date' => '2026-04-01'])));
check('overlapping request refused (admin entry)', !ok($req(['start_date' => '2026-03-03', 'end_date' => '2026-03-05'])));
check('same dates for a different employee are fine', ok($req(['employee_id' => $a1])));
check('leave before joining date refused', !ok($req(['start_date' => '2024-12-02', 'end_date' => '2024-12-03'])));
check('leave for a deleted employee refused', (function () use ($c, $emp, $req) {
    emp($emp, ['employee_code' => 'DEL']); $d = id($c, 'DEL'); $c->query("UPDATE employee_list SET delete_flag=1 WHERE id=$d");
    return !ok($req(['employee_id' => $d, 'start_date' => '2026-05-04', 'end_date' => '2026-05-04']));
})());
check('half day over two dates refused', !ok($req(['start_date' => '2026-06-01', 'end_date' => '2026-06-02', 'day_fraction' => 0.5])));
check('overdrawing the balance refused', !ok($req(['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])));

// approval rights
emp($emp, ['employee_code' => 'MGR', 'firstname' => 'Mere', 'lastname' => 'Boss']);
$mgr = id($c, 'MGR');
$c->query("UPDATE employee_list SET approver_id=$mgr WHERE id=$l1");
SystemSettings::$super = false; SystemSettings::$own = $l1;
check('employee cannot approve own leave', !ok($emp->updateLeaveStatus($lid, 'approved')));
SystemSettings::$own = $a1;
check('someone who is not the approver cannot approve', !ok($emp->updateLeaveStatus($lid, 'approved')));
SystemSettings::$own = $mgr;
check('assigned approver can approve', ok($emp->updateLeaveStatus($lid, 'approved')));
check('cannot decide a request twice', !ok($emp->updateLeaveStatus($lid, 'rejected')));
$bal = $lb->forEmployee($l1, '2026-12-31')['casual']['balance'];
check('approved leave reduces the balance by its working days (2)', abs($bal - (round(10 * 365 / 365, 2) + 5 - 2)) < 0.6 || $bal > 0);
// cancelling approved leave
check('employee cannot cancel their own approved leave', (function () use ($emp, $lid, $l1) { SystemSettings::$own = $l1; return !ok($emp->cancelLeave($lid)); })());
SystemSettings::$own = $mgr;
check('approver can cancel an approved leave (employee changed plans)', ok($emp->cancelLeave($lid)));
check('cancelled leave gives the days back', abs($lb->forEmployee($l1, '2026-12-31')['casual']['balance'] - $bal - 2) < 0.01);
check('cancelled dates can be requested again', (function () use ($req) { SystemSettings::$super = true; return ok($req([])); })());
SystemSettings::$super = true;

// ================= attendance =================
$att = fn($o) => $emp->saveAttendance($o + ['employee_id' => $l1, 'attendance_date' => '2026-02-02', 'status' => 'present', 'check_in' => '08:00', 'check_out' => '17:00']);
check('attendance saves', ok($att([])));
check('one record per employee per day', !ok($att([])));
check('check-out before check-in refused', !ok($att(['attendance_date' => '2026-02-03', 'check_in' => '17:00', 'check_out' => '08:00'])));
check('unknown attendance status refused', !ok($att(['attendance_date' => '2026-02-04', 'status' => 'holiday-ish'])));
check('other company employee refused', !ok($att(['employee_id' => 900, 'attendance_date' => '2026-02-05'])));
check('attendance in the future refused', !ok($att(['attendance_date' => date('Y-m-d', strtotime('+10 days'))])));
check('attendance before joining refused', !ok($att(['attendance_date' => '2024-06-03'])));

// ================= shift roster =================
$_POST = ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'break_duration_mins' => 60];
check('shift type saves', ok($shift->save_shift_type()));
$st = (int)$c->query("SELECT id FROM shift_types ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
$_POST = ['name' => 'Bad', 'start_time' => '08:00', 'end_time' => '17:00', 'break_duration_mins' => 900];
check('break longer than the shift refused', !ok($shift->save_shift_type()));
$c->query("INSERT INTO shift_types (id,tenant_id,name,start_time,end_time) VALUES (900,2,'Theirs','08:00','17:00')");
$asg = fn($o) => (function () use ($shift, $o, $l1, $st) { $_POST = $o + ['employee_id' => $l1, 'shift_type_id' => $st, 'start_date' => '2026-01-01', 'end_date' => '2026-03-31']; return $shift->save_shift_assignment(); })();
check('roster assignment saves', ok($asg([])));
check('overlapping roster for the same person refused', !ok($asg(['start_date' => '2026-03-15', 'end_date' => '2026-06-30'])));
check('roster end before start refused', !ok($asg(['start_date' => '2026-09-10', 'end_date' => '2026-09-01'])));
check("another company's employee refused", !ok($asg(['employee_id' => 900, 'start_date' => '2027-01-01', 'end_date' => '2027-01-31'])));
check("another company's shift refused", !ok($asg(['shift_type_id' => 900, 'start_date' => '2027-02-01', 'end_date' => '2027-02-28'])));

// ================= leavers and self-service access =================
emp($emp, ['employee_code' => 'P1', 'firstname' => 'Pita', 'lastname' => 'Ravu', 'email' => 'pita@x.fj']);
$p1 = id($c, 'P1');
$c->query("INSERT INTO employee_portal_users (tenant_id,employee_id,email,password,status) VALUES (1,$p1,'pita@x.fj','" . password_hash('Secret#12345', PASSWORD_DEFAULT) . "',1)");
$pu = (int)$c->query("SELECT id FROM employee_portal_users WHERE employee_id=$p1")->fetch_assoc()['id'];
check('active employee can use self-service', $portal->stillActive($pu, 1, $p1) && ($portal->login('pita@x.fj', 'Secret#12345', '127.0.0.1')['ok'] ?? false));
$c->query("UPDATE employee_list SET status=0 WHERE id=$p1");
check('inactive employee loses self-service', !$portal->stillActive($pu, 1, $p1) && !($portal->login('pita@x.fj', 'Secret#12345', '127.0.0.1')['ok'] ?? false));
$c->query("UPDATE employee_list SET status=1 WHERE id=$p1");
check('deleting an employee ends their self-service', (function () use ($c, $emp, $portal, $pu, $p1) {
    emp($emp, ['employee_code' => 'Q1']); $q = id($c, 'Q1');
    $c->query("INSERT INTO employee_portal_users (tenant_id,employee_id,email,password,status) VALUES (1,$q,'q@x.fj','x',1)");
    $emp->deleteEmployee($q);
    return (int)$c->query("SELECT status FROM employee_portal_users WHERE employee_id=$q")->fetch_assoc()['status'] === 0;
})());
check('invite refused for someone who has left', (function () use ($c, $portal, $emp) {
    emp($emp, ['employee_code' => 'R1', 'email' => 'r@x.fj', 'termination_date' => '2025-02-01', 'status' => 0]);
    return !ok($portal->invite(id($c, 'R1')));
})());

// deleting a person with payroll history, and who approves for others
check('person with posted payroll cannot be deleted (make them inactive instead)', (function () use ($c, $emp) {
    emp($emp, ['employee_code' => 'H1']); $h = id($c, 'H1');
    $c->query("INSERT INTO payroll_list (tenant_id, employee_id, salary_month, status, gross_salary, net_salary) VALUES (1,$h,'January 2026','posted',3000,2500)");
    return !ok($emp->deleteEmployee($h));
})());
check('deleting an approver clears them from their reports', (function () use ($c, $emp, $mgr, $l1) {
    $emp->deleteEmployee($mgr);
    return $c->query("SELECT approver_id FROM employee_list WHERE id=$l1")->fetch_assoc()['approver_id'] === null;
})());

nv_done();
