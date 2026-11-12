<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\CreditControlService as C;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('empty limit means none', C::cleanLimit('') === ['value' => null]);
check('a limit is rounded to cents', C::cleanLimit('5000.505')['value'] === 5000.51 || C::cleanLimit('5000.505')['value'] === 5000.5);
check('zero is a real limit (no credit)', C::cleanLimit('0') === ['value' => 0.0]);
check('a negative limit is refused', isset(C::cleanLimit('-1')['error']));
check('a non-numeric limit is refused', isset(C::cleanLimit('lots')['error']));
check('empty terms means none', C::cleanTerms('') === ['value' => null]);
check('terms are whole days', C::cleanTerms('30') === ['value' => 30] && C::cleanTerms('0') === ['value' => 0]);
check('bad terms are refused', isset(C::cleanTerms('-5')['error']) && isset(C::cleanTerms('2.5')['error']) && isset(C::cleanTerms('400')['error']) && isset(C::cleanTerms('abc')['error']));
check('due date adds the terms', C::dueDate('2026-11-04', 30) === '2026-12-04' && C::dueDate('2026-01-31', 30) === '2026-03-02' && C::dueDate('2026-11-04', 0) === '2026-11-04');

check('no limit never blocks', C::limitProblem(null, 1e9, 1e9, 'A') === null);
check('within the limit is fine, up to the exact limit', C::limitProblem(1000.0, 600.0, 400.0, 'A') === null);
$m = C::limitProblem(1000.0, 600.0, 400.01, 'Acme');
check('one cent over is blocked and says who and how much', $m !== null && str_contains($m, 'Acme') && str_contains($m, '1,000.00') && str_contains($m, '600.00'));
check('a zero limit blocks any invoice', C::limitProblem(0.0, 0.0, 10.0, 'A') !== null);
check('a zero-value invoice is never blocked', C::limitProblem(0.0, 0.0, 0.0, 'A') === null);

$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('issuing an invoice runs the credit check (not drafts or cancels)', str_contains($inv, '(new CreditControlService(') && str_contains($inv, "\$status !== 'draft'"));
check('the invoice being edited is left out of what is owed', str_contains($inv, '(int)$id, !empty($post[\'credit_override\'])'));
$ps = file_get_contents(base_app . 'src/Services/PartyService.php');
check('the party save stores limit and terms', str_contains($ps, '`credit_limit` = {$limitSql}') && substr_count($ps, 'payment_terms_days') >= 3);
check('migration chained', str_contains(file_get_contents(base_app . 'src/Database/LegacySchemaCatchup.php'), '$this->check_credit_control_migrations();'));
check('override needs settings permission', str_contains(file_get_contents(base_app . 'src/Services/CreditControlService.php'), "can_access('settings_manage')"));
check('sql migration exists', is_file(base_app . 'database/migrations/014_credit_limits_terms.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
