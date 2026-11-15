<?php
// Accounting rules against a real database: balanced-only posting, period lock, reversal, tenant isolation.
require __DIR__ . '/_harness.php';

class SystemSettings {                       // stand-in for the session-backed settings object
    public static int $tenant = 1;
    public function active_tenant_id() { return self::$tenant; }
    public function userdata($k = '') { return $k === 'id' ? 1 : null; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
}
use App\Services\{JournalService, LedgerService, PeriodLockService};

[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1, 'Test', 'User', 'tester', 'x')");
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Second Co')");
$c->query("INSERT INTO account_list (tenant_id, code, name, type, normal_balance, description) VALUES (2, '9000', 'T2 Cash', 'Bank', 'debit', ''), (2, '9100', 'T2 Sales', 'Revenue', 'credit', '')");
$t1 = array_column($c->query("SELECT id FROM account_list WHERE tenant_id = 1 AND delete_flag = 0 ORDER BY id LIMIT 3")->fetch_all(MYSQLI_ASSOC), 'id');
$t2 = array_column($c->query("SELECT id FROM account_list WHERE tenant_id = 2 ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'id');
[$a, $b] = [$t1[0], $t1[1]];

$svc = new JournalService($c, new SystemSettings());
$post = fn(array $over = []) => $over + ['journal_date' => '2026-01-15', 'description' => 'test', 'status' => 'posted',
    'account_id' => [$a, $b], 'debit' => ['100.00', '0'], 'credit' => ['0', '100.00']];
$res = fn($json) => json_decode($json, true);
$jcount = fn($tenant = 1) => (int)$c->query("SELECT COUNT(*) FROM journal_entries WHERE tenant_id = $tenant")->fetch_row()[0];
$icount = fn() => (int)$c->query("SELECT COUNT(*) FROM journal_items")->fetch_row()[0];

$r = $res($svc->saveJournal($post()));
check('a balanced journal posts', ($r['status'] ?? '') === 'success' && $jcount() === 1 && $icount() === 2);
$jid = (int)$c->query("SELECT id FROM journal_entries WHERE tenant_id = 1")->fetch_row()[0];

$before = [$jcount(), $icount()];
$r = $res($svc->saveJournal($post(['credit' => ['0', '99.99']])));
check('a journal one cent out is rejected', ($r['status'] ?? '') === 'failed' && stripos($r['msg'], 'equal') !== false);
check('and writes nothing (no header, no lines)', [$jcount(), $icount()] === $before);

$r = $res($svc->saveJournal($post(['account_id' => [$a, $t2[0]]])));
check("another company's account cannot be used", ($r['status'] ?? '') === 'failed' && $jcount() === 1);

$r = $res($svc->saveJournal($post(['debit' => ['-100', '0'], 'credit' => ['0', '-100']])));
check('negative amounts are rejected', ($r['status'] ?? '') === 'failed');

$r = $res($svc->saveJournal($post(['status' => 'draft', 'credit' => ['0', '50']])));
check('an unbalanced draft may be saved but is not posted', ($r['status'] ?? '') === 'success' && $c->query("SELECT status FROM journal_entries ORDER BY id DESC LIMIT 1")->fetch_row()[0] === 'draft');
$lines = (new LedgerService($c, 1))->balances(null, '2026-12-31');
$dr = array_sum(array_column($lines, 'dr')); $cr = array_sum(array_column($lines, 'cr'));
check('drafts never reach the ledger: debits equal credits and equal 100', abs($dr - 100) < 0.001 && abs($cr - 100) < 0.001);

// Reversal
$r = $res($svc->reverseJournal($jid, '2026-01-20'));
check('a posted journal can be reversed', ($r['status'] ?? '') === 'success');
$rev = $c->query("SELECT id, journal_date FROM journal_entries WHERE reverses_id = $jid")->fetch_assoc();
check('the reversal is a separate, dated entry that mirrors the original', $rev && $rev['journal_date'] === '2026-01-20' && (int)$c->query("SELECT COUNT(*) FROM journal_items WHERE journal_id = {$rev['id']}")->fetch_row()[0] === 2);
$net = $c->query("SELECT SUM(CASE entry_side WHEN 'debit' THEN amount ELSE -amount END) FROM journal_items i JOIN journal_entries e ON e.id = i.journal_id WHERE e.tenant_id = 1 AND e.status <> 'draft' AND e.status <> 'cancelled'")->fetch_row()[0];
check('after reversal the books net to zero', abs((float)$net) < 0.00001);
$r = $res($svc->reverseJournal($jid, '2026-01-21'));
check('it cannot be reversed twice', ($r['status'] ?? '') === 'failed' && stripos($r['msg'], 'already been reversed') !== false);
$r = $res($svc->reverseJournal((int)$rev['id'], '2026-01-22'));
check('a reversal cannot itself be reversed', ($r['status'] ?? '') === 'failed');
$r = $res($svc->reverseJournal($jid + 999, '2026-01-22'));
check('an unknown journal is not found', ($r['status'] ?? '') === 'failed');

// Period lock
$lock = new PeriodLockService($c, new SystemSettings());
$lock->setLock('2026-01-31', 'January closed');
$n = $jcount();
$r = $res($svc->saveJournal($post(['journal_date' => '2026-01-25'])));
check('posting into a closed period is refused', ($r['status'] ?? '') === 'failed' && $jcount() === $n);
$r = $res($svc->saveJournal($post(['journal_date' => '2026-02-02'])));
check('posting after the lock date still works', ($r['status'] ?? '') === 'success');
$r = $res($svc->reverseJournal((int)$c->query("SELECT id FROM journal_entries WHERE tenant_id = 1 AND journal_date = '2026-02-02'")->fetch_row()[0], '2026-02-05'));
check('reversing a journal that is after the lock works', ($r['status'] ?? '') === 'success');
$r = $res($svc->saveJournal($post(['id' => $jid, 'journal_date' => '2026-02-10'])));
check('a journal in a closed period cannot be moved out of it', ($r['status'] ?? '') === 'failed');

// Tenant isolation
SystemSettings::$tenant = 2;
$svc2 = new JournalService($c, new SystemSettings());
$r = $res($svc2->saveJournal($post(['journal_date' => '2026-01-15', 'account_id' => [$t2[0], $t2[1]]])));
check("company 2's lock is independent of company 1's (the same date posts)", ($r['status'] ?? '') === 'success' && $jcount(2) === 1);
$r = $res($svc2->reverseJournal($jid, '2026-03-01'));
check("company 2 cannot reverse company 1's journal", ($r['status'] ?? '') === 'failed' && stripos($r['msg'], 'not found') !== false);
$before = $jcount();
$r = $res($svc2->saveJournal($post(['id' => $jid, 'account_id' => [$t2[0], $t2[1]], 'journal_date' => '2026-03-01'])));
check("company 2 cannot overwrite company 1's journal", ($r['status'] ?? '') === 'failed' && $jcount() === $before);
$bal2 = (new LedgerService($c, 2))->balances(null, '2026-12-31');
$ids2 = array_column($bal2, 'id');
check("company 2's ledger holds only company 2's accounts", $ids2 && !array_intersect($ids2, $t1));
SystemSettings::$tenant = 1;
$ids1 = array_column((new LedgerService($c, 1))->balances(null, '2026-12-31'), 'id');
check("company 1's ledger holds none of company 2's accounts", !array_intersect($ids1, $t2));

// Whole-book invariant
$bad = $c->query("SELECT e.id FROM journal_entries e JOIN journal_items i ON i.journal_id = e.id WHERE e.status NOT IN ('draft') GROUP BY e.id HAVING ROUND(SUM(CASE i.entry_side WHEN 'debit' THEN i.amount ELSE -i.amount END), 2) <> 0");
check('every non-draft journal in the database balances to the cent', $bad->num_rows === 0);
nv_done();
