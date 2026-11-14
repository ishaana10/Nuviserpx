<?php
// Customer unpaid-invoice statements: pure helpers, email-all behaviour (stubbed), and wiring/security pins.
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";
require_once "$root/src/Services/LedgerService.php";
require_once "$root/src/Services/CustomerStatementService.php";
use App\Services\CustomerStatementService as S;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('valid email accepted and trimmed', S::validEmail('  a@b.com ') === 'a@b.com');
check('blank, junk and header-injection emails rejected', S::validEmail('') === null && S::validEmail('nope') === null && S::validEmail("a@b.com\r\nBcc: x@y.com") === null && S::validEmail(null) === null);
check('absurdly long email rejected', S::validEmail(str_repeat('a', 200) . '@b.com') === null);
check('real date kept, junk falls back', S::cleanDate('2026-11-14', '2000-01-01') === '2026-11-14' && S::cleanDate('14/11/2026', '2000-01-01') === '2000-01-01' && S::cleanDate(null, '2000-01-01') === '2000-01-01');
check('subject names the company and date', S::subject('Acme', '2026-11-14') === 'Statement of unpaid invoices - Acme - as at 14 Nov 2026');

// emailAll with the data and the mailer stubbed out
$cls = get_class(new class(null) extends S {
    public array $sentTo = [];
    public function __construct($x) {}
    public function statements(string $asOf, ?int $partyId = null): array {
        return ['customers' => [
            ['id' => 1, 'name' => 'Good Ltd', 'email' => 'good@x.com', 'total' => 100.0, 'invoices' => []],
            ['id' => 2, 'name' => 'No Email', 'email' => '', 'total' => 50.0, 'invoices' => []],
            ['id' => 3, 'name' => 'Bad Email', 'email' => 'not-an-email', 'total' => 5.0, 'invoices' => []],
            ['id' => 4, 'name' => 'Mail Fails', 'email' => 'fail@x.com', 'total' => 9.0, 'invoices' => []],
        ], 'totals' => []];
    }
    protected function deliver(array $c, string $to, string $asOf, string $note): bool { $this->sentTo[] = $to; return $to !== 'fail@x.com'; }
});
$svc = (new ReflectionClass($cls))->newInstanceWithoutConstructor();
$r = json_decode($svc->emailAll('2026-11-14', 'hello'), true);
check('only customers with a valid address are mailed', $svc->sentTo === ['good@x.com', 'fail@x.com']);
check('summary counts sends and names the customers it could not mail', $r['status'] === 'success' && strpos($r['msg'], '1 statement sent.') === 0 && strpos($r['msg'], 'No Email, Bad Email') !== false && strpos($r['msg'], 'Mail Fails') !== false);
$one = json_decode($svc->emailOne(1, '2026-11-14', '', 'bad'), true);
check('a bad override address is refused without sending', $one['status'] === 'failed' && count($svc->sentTo) === 2);

// wiring and security pins
$m = file_get_contents("$root/classes/Master.php");
$p = file_get_contents("$root/src/Support/Permissions.php");
$e = file_get_contents("$root/classes/Emailer.php");
$pg = file_get_contents("$root/admin/reports/customer_statements.php");
$sv = file_get_contents("$root/src/Services/CustomerStatementService.php");
check('both actions are whitelisted and one-line delegates', strpos($m, "'email_customer_statement', 'email_customer_statements_all'") !== false && strpos($m, 'CustomerStatementService::class)->emailOne(') !== false && strpos($m, 'CustomerStatementService::class)->emailAll(') !== false);
check('actions are permission-mapped; mailing everyone needs edit', strpos($p, "'email_customer_statement'      => ['invoices', 'view']") !== false && strpos($p, "'email_customer_statements_all' => ['invoices', 'edit']") !== false);
check('queries are tenant-scoped, prepared, and exclude draft/cancelled', strpos($sv, 'i.tenant_id = ?') !== false && strpos($sv, "NOT IN ('draft','cancelled')") !== false && strpos($sv, 'prepare(') !== false && strpos($sv, '->query(') === false);
check('the party join is tenant-scoped too', strpos($sv, 'p.tenant_id = i.tenant_id') !== false);
preg_match('/function send_customer_statement_email.*?\n    \}\n/s', $e, $fn);
$fn = $fn[0] ?? '';
check('email body escapes customer, invoice number, label, company, note and contact', $fn !== '' && substr_count($fn, '$h(') >= 6 && strpos($fn, "\$h(\$customer['name'])") !== false && strpos($fn, "\$h(\$i['invoice_no'])") !== false && strpos($fn, '$h($company)') !== false);
check('page shows email buttons only to users who may email', strpos($pg, "can_access('invoices_view')") !== false && strpos($pg, "can_access('invoices_edit')") !== false && strpos($pg, '$can_email_all') !== false);
check('page escapes names and emails', strpos($pg, 'htmlspecialchars($c[\'name\'])') !== false && strpos($pg, 'htmlspecialchars((string)$c[\'email\'])') !== false);
check('page uses ajax posts to Master (CSRF via global setup), not GET', strpos($pg, 'method: "POST"') !== false);
check('listed in the menu and the reports index', strpos(file_get_contents("$root/admin/inc/menus/04_reporting.php"), 'reports/customer_statements') !== false && strpos(file_get_contents("$root/admin/reports/index.php"), 'reports/customer_statements') !== false);
check('help topic mentions it', strpos(file_get_contents("$root/src/Support/HelpContent.php"), 'Customer Statements') !== false);
exit($fail ? 1 : 0);
