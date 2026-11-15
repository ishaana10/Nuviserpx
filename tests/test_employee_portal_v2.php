<?php
// Employee Portal v2: self-service leave, side menu pages, decision email. Behaviour where it runs without a DB, pins elsewhere.
$root = dirname(__DIR__);
define('base_app', $root . '/');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$read = fn($f) => file_get_contents("$root/$f");

// A staff session that happens to share the browser must not leak into the portal.
class SystemSettings {
    function userdata($f = '') { return $_SESSION['userdata'][$f] ?? null; }
    function is_super_admin() { return true; }
    function can_access($k) { return true; }
    function active_tenant_id() { return 99; }
    function own_employee_id() { return 77; }
    function employee_scope($m) { return null; }
    function set_flashdata($a = '', $b = '') { return true; }
    function log_audit($a, $b, $c = '') {}
}
require_once "$root/src/Support/PortalSettings.php";
$_SESSION['userdata'] = ['id' => 1, 'type' => 1, 'tenant_id' => 99];
$mysqli = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
$ps = new App\Support\PortalSettings($mysqli, 5, 42);
check('company is the portal session company, not the staff session', $ps->active_tenant_id() === 5);
check('employee is the portal employee for scope and ownership', $ps->employee_scope('leaves') === 42 && $ps->own_employee_id() === 42);
check('never an admin, no staff permissions, no staff user data', $ps->is_super_admin() === false && $ps->can_access('leaves_edit') === false && $ps->userdata('id') === null && $ps->userdata('type') === null);

// Wiring
$m = $read('classes/Master.php');
check('leave endpoints are whitelisted and public (they check the portal session themselves)',
    substr_count($m, 'employee_portal_leave_apply') >= 2 && strpos($read('src/Support/Permissions.php'), "'employee_portal_leave_cancel'") !== false);
preg_match('~function employee_portal_leave_apply.*?\n    }~s', $m, $a);
preg_match('~function employee_portal_leave_cancel.*?\n    }~s', $m, $c);
check('apply/cancel take company and employee from the session identity only',
    strpos($a[0] ?? '', 'employee_portal_identity()') !== false && strpos($c[0] ?? '', 'employee_portal_identity()') !== false
    && strpos($a[0] ?? '', "_POST['employee_id']") === false && strpos($a[0] ?? '', "_POST['tenant") === false && strpos($c[0] ?? '', "_POST['employee") === false);
check('identity also requires the portal account to still be active', strpos($m, 'stillActive(') !== false);
$svc = $read('src/Services/PortalLeaveService.php');
preg_match('~function apply\(.*?\n    }~s', $svc, $ap);
check('apply whitelists fields and never passes an id or status', preg_match('~\$clean = \[(.*?)\];~s', $ap[0] ?? '', $cl) && strpos($cl[1], "'leave_type' =>") !== false && strpos($cl[1], "'status'") === false && strpos($cl[1], "'id'") === false && strpos($cl[1], 'employee') === false);
check('history/details queries are scoped to company and employee', substr_count($svc, 'tenant_id = ?') >= 2 && strpos($svc, 'e.tenant_id = ?') !== false);
check('leave is hidden when the company has no Payroll & HR module', strpos($svc, "module_enabled('mod_payroll')") !== false && strpos($read('portal/employee_leave.php'), '!$leaveOn') !== false);

$lay = $read('portal/_employee_layout.php');
check('every page signs the employee in via the shared layout (and checks access is still on)', strpos($lay, 'stillActive(') !== false);
foreach (['employee.php', 'employee_leave.php', 'employee_payslips.php', 'employee_details.php'] as $f) {
    check("$f uses the shared layout", strpos($read("portal/$f"), "_employee_layout.php") !== false);
}
check('side menu has Home, My Leave, My Payslips, My Details', strpos($lay, "'My Leave'") !== false && strpos($lay, "'My Payslips'") !== false && strpos($lay, "'My Details'") !== false && strpos($lay, "'Home'") !== false);
check('old payslip links still work', strpos($read('portal/employee.php'), 'employee_payslips.php?id=') !== false);
preg_match('~function details\(.*?\n    }~s', $svc, $dt);
check('My Details shows no pay, tax or bank fields', ($dt[0] ?? '') !== '' && !preg_match('~salary|annual_gross|\btin\b|fnpf|bank~i', ($dt[0] ?? '') . $read('portal/employee_details.php')));
check('only pending requests can be cancelled in the UI', strpos($read('portal/employee_leave.php'), "=== 'pending'") !== false);

// Email on decision
$em = $read('classes/Emailer.php');
check('decision email exists for approved and rejected', strpos($em, 'function send_leave_decision_email') !== false && strpos($em, 'approved') !== false);
$es = $read('src/Services/EmployeeService.php');
check('approve/reject emails the employee, best effort', strpos($es, '$this->emailLeaveDecision($leave, $status)') !== false && preg_match('~function emailLeaveDecision.*?catch \(\\\\Throwable~s', $es) === 1);
check('email goes only to a valid address, company-scoped lookup', strpos($es, 'FILTER_VALIDATE_EMAIL') !== false && strpos($es, 'FROM employee_list WHERE id = ? AND tenant_id = ?') !== false);
// bind_param needs references; readonly/promoted properties cannot be passed that way (a fatal error, HTTP 500).
$bad = [];
foreach (['src/Services/PortalLeaveService.php', 'src/Support/PortalSettings.php', 'src/Services/EmployeePortalService.php'] as $f) {
    if (preg_match('~bind_param\([^;]*\$this->~', $read($f))) { $bad[] = $f; }
}
check('no bind_param call binds a property directly', $bad === []);
exit($fail ? 1 : 0);
