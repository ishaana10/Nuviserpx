<?php
// Performance guard rails. Plans, not stopwatches: a query that stops using its index is what hurts in production,
// and plans do not flake on a busy CI machine. Volume is modest (24k lines); the plan shape is what is asserted.
require __DIR__ . '/_harness.php';
use App\Services\LedgerService; use App\Support\ListWindow;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'T','U','t','x')");
$acc = array_column($c->query("SELECT id FROM account_list WHERE tenant_id=1 AND delete_flag=0")->fetch_all(MYSQLI_ASSOC), 'id');
$g = (int)$c->query("SELECT id FROM group_list ORDER BY id LIMIT 1")->fetch_row()[0];
$c->query("SET autocommit=0");
for ($b = 0; $b < 12000; $b += 2000) {
    $v = []; for ($i = 0; $i < 2000; $i++) { $v[] = "(1,'J" . ($b + $i) . "','" . date('Y-m-d', strtotime('2025-01-01') + rand(0, 700) * 86400) . "','bench','posted',1)"; }
    $c->query("INSERT INTO journal_entries (tenant_id,code,journal_date,description,status,user_id) VALUES " . implode(',', $v));
    $first = $c->insert_id; $iv = [];
    for ($i = 0; $i < 2000; $i++) { $amt = rand(100, 99999) / 100; $iv[] = "(" . ($first + $i) . "," . $acc[array_rand($acc)] . ",$g,'debit',$amt)"; $iv[] = "(" . ($first + $i) . "," . $acc[array_rand($acc)] . ",$g,'credit',$amt)"; }
    $c->query("INSERT INTO journal_items (journal_id,account_id,group_id,entry_side,amount) VALUES " . implode(',', $iv));
}
$inv = []; for ($i = 0; $i < 3000; $i++) { $inv[] = "(1,'INV$i',1,'2025-06-01')"; }
$c->query("INSERT INTO party_list (id, party_code, name, tenant_id) VALUES (1,'P1','Cust',1)");
$c->query("INSERT INTO invoice_list (tenant_id,invoice_no,party_id,invoice_date) VALUES " . implode(',', $inv));
$c->query("COMMIT"); $c->query("SET autocommit=1");
$c->query("ANALYZE TABLE journal_items, journal_entries, invoice_list");

$plan = function (string $sql) use ($c) { $r = $c->query("EXPLAIN $sql"); $o = []; while ($x = $r->fetch_assoc()) { $o[$x['table']] = $x; } return $o; };

$p = $plan("SELECT j.id FROM journal_items ji INNER JOIN journal_entries j ON j.id=ji.journal_id WHERE ji.account_id={$acc[3]} AND j.tenant_id=1 AND j.journal_date BETWEEN '2025-01-01' AND '2025-12-31'");
check('account ledger reads journal_items by account, not a full scan', ($p['ji']['key'] ?? null) !== null && $p['ji']['type'] !== 'ALL');
check('account ledger finds the entry by primary key', ($p['j']['key'] ?? null) === 'PRIMARY');

$p = $plan("SELECT * FROM journal_entries WHERE tenant_id=1 AND journal_date BETWEEN '2025-01-01' AND '2025-03-31'");
check('journal list by date range uses an index', ($p['journal_entries']['key'] ?? null) !== null && $p['journal_entries']['type'] !== 'ALL');

foreach (['invoice_list', 'sales_order_list', 'payment_list', 'vendor_bill_list', 'party_list', 'account_list'] as $t) {
    $p = $plan("SELECT id FROM $t WHERE tenant_id=1 LIMIT 50");
    check("$t is looked up by tenant through an index", ($p[$t]['key'] ?? null) !== null && $p[$t]['type'] !== 'ALL');
}
$p = $plan("SELECT * FROM journal_items WHERE journal_id=1");
check('journal lines are fetched by journal_id index', ($p['journal_items']['key'] ?? null) !== null);

// Behaviour: the capped list page query and the trial balance stay correct at volume
$tb = LedgerService::trialBalance((new LedgerService($c, 1))->balances(null, '2026-12-31'));
check('trial balance of 12,000 random journals balances to the cent', $tb['balanced'] === true && $tb['debit'] > 0);
$t = microtime(true); (new LedgerService($c, 1))->balances(null, '2026-12-31'); $ms = (microtime(true) - $t) * 1000;
check(sprintf('trial balance over 24k lines is well inside a request budget (%.0f ms)', $ms), $ms < 3000);
$rows = $c->query("SELECT i.id FROM invoice_list i INNER JOIN party_list p ON i.party_id=p.id WHERE i.tenant_id=1 ORDER BY i.id DESC" . ListWindow::limitSql(['x' => 1]))->num_rows;
check('the capped invoice list returns at most 2,000 of 3,000 rows', $rows === 2000);
check('and the banner reports it', strpos(ListWindow::banner($c, 'invoice_list', 1, 'invoices', []), '2,000 of 3,000') !== false);
check('?all=1 returns everything and no banner', $c->query("SELECT i.id FROM invoice_list i WHERE i.tenant_id=1 ORDER BY i.id DESC" . ListWindow::limitSql(['all' => 1]))->num_rows === 3000 && ListWindow::banner($c, 'invoice_list', 1, 'invoices', ['all' => 1]) === '');
nv_done();
