<?php
// A brand-new company must start with a properly typed chart that invoicing can use; a live company's chart is never touched.
require __DIR__ . '/_harness.php';
use App\Services\ChartProvisioner as C;
use App\Services\AccountDefaultsService as D;
[$c, $db] = nv_fresh_db();
$types = fn() => array_column($c->query("SELECT type, COUNT(*) n FROM account_list WHERE delete_flag=0 GROUP BY type")->fetch_all(MYSQLI_ASSOC), 'n', 'type');
$before = $types();
check('dump seed is the untyped demo chart (the problem)', ($before['Expense'] ?? 0) >= 40 && !isset($before['Current Asset']) && !isset($before['Revenue']));

// a company with a journal is never touched
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
check('test journal inserted', $c->query("INSERT INTO journal_entries (id,tenant_id,code,journal_date,description,status,user_id) VALUES (1,1,'J1',CURDATE(),'x','posted',1)") === true);
check('refuses when the books hold a journal', C::replaceDemoSeed($c, 1) === -1 && $types() === $before);
$c->query("DELETE FROM journal_entries");

$n = C::replaceDemoSeed($c, 1);
$after = $types();
check('replaces the demo chart', $n > 0);
check('no demo Expense-typed Cash/Receivable/Payable left', (int)$c->query("SELECT COUNT(*) FROM account_list WHERE delete_flag=0 AND type='Expense' AND name IN ('Cash','Accounts Receivable','Accounts Payable','Interest Receivable')")->fetch_row()[0] === 0);
foreach (['Bank', 'Current Asset', 'Current Liability', 'Equity', 'Revenue', 'Expense'] as $t) { check("chart has $t accounts", ($after[$t] ?? 0) > 0); }
check('Opening Balance Equity kept', (int)$c->query("SELECT COUNT(*) FROM account_list WHERE name='Opening Balance Equity' AND delete_flag=0")->fetch_row()[0] === 1);
check('codes are unique', (int)$c->query("SELECT COUNT(*) - COUNT(DISTINCT code) FROM account_list WHERE delete_flag=0")->fetch_row()[0] === 0);

$accounts = (function () use ($c) { return $c->query("SELECT id, name, type, code FROM account_list WHERE delete_flag=0 AND tenant_id=1")->fetch_all(MYSQLI_ASSOC); })();
foreach (['receivable', 'revenue', 'cash'] as $role) { check("default account for $role is found automatically", D::detect($accounts, $role) > 0); }

check('second run changes nothing (no loop, no duplicates)', ($n2 = C::replaceDemoSeed($c, 1)) > 0 && $types() === $after);
$c->query("INSERT INTO account_defaults (tenant_id, role, account_id) VALUES (1,'cash',(SELECT id FROM account_list WHERE type='Bank' LIMIT 1))");
check('refuses once defaults exist', C::replaceDemoSeed($c, 1) === -1);
check('installer calls it', strpos(file_get_contents(__DIR__ . '/../../install/process.php'), 'ChartProvisioner::replaceDemoSeed') !== false);
nv_done();
