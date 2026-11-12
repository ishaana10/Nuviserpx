<?php
// Gap 7: employee self-service portal.
$root = dirname(__DIR__);
require_once "$root/src/Services/EmployeePortalService.php";
use App\Services\EmployeePortalService as P;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('token hash is sha256 hex', P::tokenHash('abc') === hash('sha256', 'abc') && strlen(P::tokenHash('x')) === 64);
check('short password rejected', count(P::passwordErrors('a1')) > 0);
check('no digit rejected', count(P::passwordErrors('abcdefghij')) > 0);
check('no letter rejected', count(P::passwordErrors('1234567890')) > 0);
check('password equal to email rejected', count(P::passwordErrors('ab1@x.com', 'AB1@x.com')) > 0);
check('over 72 chars rejected (bcrypt limit)', count(P::passwordErrors(str_repeat('a1', 40))) > 0);
check('good password accepted', P::passwordErrors('Payslip2026') === []);
$now = strtotime('2026-11-08 10:00:00');
check('locked in the future', P::isLocked('2026-11-08 10:10:00', $now));
check('lock expired', !P::isLocked('2026-11-08 09:59:00', $now));
check('no lock set', !P::isLocked(null, $now) && !P::isLocked('', $now));
check('lock only at the 5th failure', P::lockSecondsFor(4) === 0 && P::lockSecondsFor(5) === 900);
check('email validation', P::validEmail('a@b.co') && !P::validEmail('') && !P::validEmail('nope') && !P::validEmail(str_repeat('a', 150) . '@x.com'));

$svc = file_get_contents("$root/src/Services/EmployeePortalService.php");
check('payslips limited to posted payroll', substr_count($svc, "status = 'posted'") >= 2);
check('payslip query is tenant and employee scoped', strpos($svc, 'p.tenant_id = ? AND p.employee_id = ?') !== false);
check('invite token stored hashed only', strpos($svc, 'invite_token_hash') !== false && strpos($svc, "'token' =>") === false);
check('login failure message is generic', substr_count($svc, 'Invalid email or password') === 1);

$perm = file_get_contents("$root/src/Support/Permissions.php");
check('login and activate are public', preg_match("/PUBLIC_ACTIONS = \[[^\]]*employee_portal_login[^\]]*employee_portal_activate/", $perm) === 1);
check('invite and revoke need employees save', preg_match("/'invite_employee_portal'\s*=>\s*\['employees', 'save'\]/", $perm) && preg_match("/'revoke_employee_portal'\s*=>\s*\['employees', 'save'\]/", $perm));
$m = file_get_contents("$root/classes/Master.php");
foreach (['invite_employee_portal', 'revoke_employee_portal', 'employee_portal_login', 'employee_portal_activate'] as $a) {
    check("Master routes $a", strpos($m, "'$a',") !== false);
}
check('session regenerated on sign-in', strpos($m, 'session_regenerate_id(true)') !== false);
$page = file_get_contents("$root/portal/employee.php");
check('portal takes employee from the session, not the request', strpos($page, "\$_SESSION['employee_portal']['employee_id']") !== false && !preg_match('/\$_(GET|POST|REQUEST)\[.employee/', $page));
$mig = file_get_contents("$root/database/migrations/019_employee_portal.sql");
check('migration adds email and portal tables', strpos($mig, 'ADD COLUMN `email`') !== false && strpos($mig, 'employee_portal_users') !== false && strpos($mig, 'UNIQUE KEY `portal_employee`') !== false);
check('migration comments have no semicolons', !preg_match('/^--.*;/m', $mig));
check('base SQL has employee email', preg_match('/CREATE TABLE `employee_list`.*?`email` varchar\(150\)/s', file_get_contents("$root/database/nuviserpx.sql")) === 1);
exit($fail ? 1 : 0);
