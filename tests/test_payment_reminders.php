<?php
// Payment reminders and late fees: stage planning, fee maths, settings parsing and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/PaymentReminderService.php";
use App\Services\PaymentReminderService as P;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('stages are sorted and de-duplicated', P::parseStages('10, 3,20,3') === [3, 10, 20]);
check('junk and out-of-range days are dropped', P::parseStages('0, -5, abc, 400, 7') === [7]);
check('empty gives none', P::parseStages('  ') === []);
check('at most six stages', count(P::parseStages('1,2,3,4,5,6,7,8')) === 6);

check('nothing before the first stage', P::plan(2, [3, 10, 20], []) === ['send' => null, 'skip' => []]);
check('first stage is sent when reached', P::plan(3, [3, 10, 20], []) === ['send' => 3, 'skip' => []]);
check('a stage already sent is not repeated', P::plan(5, [3, 10, 20], [3]) === ['send' => null, 'skip' => []]);
check('next stage sent once reached', P::plan(10, [3, 10, 20], [3]) === ['send' => 10, 'skip' => []]);
check('only the latest reached stage is sent, earlier ones skipped', P::plan(25, [3, 10, 20], []) === ['send' => 20, 'skip' => [3, 10]]);
check('a handled skip is not skipped again', P::plan(25, [3, 10, 20], [3, 10]) === ['send' => 20, 'skip' => []]);

check('percent fee', P::lateFee(1000, 'percent', 2.5) === 25.0);
check('fixed fee', P::lateFee(1000, 'fixed', 30) === 30.0);
check('cap applies', P::lateFee(10000, 'percent', 5, 100) === 100.0);
check('fee never exceeds the balance', P::lateFee(20, 'fixed', 50) === 20.0);
check('no fee on a zero balance or zero value', P::lateFee(0, 'fixed', 10) === 0.0 && P::lateFee(100, 'percent', 0) === 0.0);
check('percent above 100 is treated as 100', P::lateFee(50, 'percent', 500) === 50.0);
check('rounds to cents', P::lateFee(33.33, 'percent', 3) === 1.0);

check('fee waits for the grace period', !P::feeDue(13, 14, false) && P::feeDue(14, 14, false));
check('fee is charged once', !P::feeDue(60, 14, true));

check('defaults are off', P::defaults()['enabled'] === 0 && P::defaults()['fee_enabled'] === 0);

// wiring
$sql = file_get_contents("$root/database/migrations/029_payment_reminders.sql");
check('a stage is unique per invoice', strpos($sql, 'UNIQUE KEY `uniq_invoice_stage` (`invoice_id`, `stage_days`)') !== false);
check('a late fee is unique per invoice', strpos($sql, 'UNIQUE KEY `uniq_late_fee_for` (`late_fee_for_id`)') !== false);
check('migration has no semicolons inside comments', substr_count($sql, ';') === substr_count(preg_replace('/^\s*--.*$/m', '', $sql), ';'));
$master = file_get_contents("$root/classes/Master.php");
$perm = file_get_contents("$root/src/Support/Permissions.php");
$svc = file_get_contents("$root/src/Services/PaymentReminderService.php");
$cron = file_get_contents("$root/cron/payment_reminders.php");
foreach (['save_reminder_settings', 'run_payment_reminders'] as $f) {
    check("Master has $f", strpos($master, "function $f(") !== false && strpos($master, "case '$f':") !== false);
    check("Permissions maps $f", strpos($perm, "'$f'") !== false);
}
check('page is permissioned', strpos($perm, "'payment_reminders' => 'invoices_view'") !== false);
check('run is locked per company', strpos($svc, 'GET_LOCK') !== false);
check('fee invoices are excluded from reminders and fees', strpos($svc, 'late_fee_for_id IS NULL') !== false);
check('cron is CLI only', strpos($cron, "PHP_SAPI !== 'cli'") !== false);
check('email template exists', strpos(file_get_contents("$root/classes/Emailer.php"), 'function send_payment_reminder_email') !== false);
check('nav link exists', strpos(file_get_contents("$root/admin/inc/navigation.php"), 'page=payment_reminders') !== false);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
