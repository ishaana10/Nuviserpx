<?php
// A draft invoice must not count in the ledger (Trial Balance / Balance Sheet / P&L); issuing it makes it count.
// Also covers migration 048, which repairs journals that older versions saved as "posted" for draft invoices.
require __DIR__ . '/_harness.php';
use App\Services\LedgerService as L;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'C1','Alpha','a@example.com','customer')");

$mkJournal = function (int $id, string $status, string $date) use ($c) {
    $c->query("INSERT INTO journal_entries (id,tenant_id,code,journal_date,description,status,user_id) VALUES ($id,1,'J$id','$date','inv','$status',1)");
    $c->query("INSERT INTO journal_items (journal_id,account_id,group_id,entry_side,amount) VALUES ($id,1,1,'debit',250),($id,2,2,'credit',250)");
};
$posted = fn() => (float)$c->query("SELECT COALESCE(SUM(ji.amount),0) FROM journal_items ji JOIN journal_entries j ON j.id=ji.journal_id WHERE ji.entry_side='debit' AND " . L::POSTED_SQL)->fetch_row()[0];

$today = date('Y-m-d');
// 1 issued invoice (posted journal), 2 draft invoice whose journal an old version saved as posted, 3 draft with a draft journal (new behaviour)
$mkJournal(1, 'posted', $today); $mkJournal(2, 'posted', $today); $mkJournal(3, 'draft', $today);
$c->query("INSERT INTO invoice_list (id,tenant_id,invoice_no,party_id,invoice_date,total,balance,status,journal_id) VALUES (1,1,'I1',1,'$today',250,250,'unpaid',1),(2,1,'I2',1,'$today',250,250,'draft',2),(3,1,'I3',1,'$today',250,250,'draft',3)");
check('before repair the old draft journal is counted (the bug)', $posted() === 500.0);

$sql = file_get_contents(__DIR__ . '/../../database/migrations/048_draft_invoice_journals.sql');
check('migration runs', $c->multi_query($sql) !== false); while ($c->more_results() && $c->next_result()) {}
$st = fn($id) => $c->query("SELECT status FROM journal_entries WHERE id=$id")->fetch_row()[0];
check('draft invoice journal repaired to draft', $st(2) === 'draft');
check('issued invoice journal untouched', $st(1) === 'posted');
check('ledger now counts only the issued invoice', $posted() === 250.0);
check('migration is safe to run twice', $c->multi_query($sql) !== false);
while ($c->more_results() && $c->next_result()) {}
check('still one counted invoice after a second run', $posted() === 250.0);

// closed period: the repair must not touch (and must not fail on) journals the lock protects
$old = date('Y-m-d', strtotime('-40 days'));
$mkJournal(4, 'posted', $old);
$c->query("INSERT INTO invoice_list (id,tenant_id,invoice_no,party_id,invoice_date,total,balance,status,journal_id) VALUES (4,1,'I4',1,'$old',250,250,'draft',4)");
$c->query("INSERT INTO period_locks (tenant_id, lock_date, note, locked_by) VALUES (1, '" . date('Y-m-d', strtotime('-30 days')) . "', 'test', 1)");
check('migration does not error with a closed period', $c->multi_query($sql) !== false);
while ($c->more_results() && $c->next_result()) {}
check('journal inside the closed period left alone', $st(4) === 'posted');

// the service itself
$svc = file_get_contents(__DIR__ . '/../../src/Services/InvoiceService.php');
check('new draft invoices save a draft journal', strpos($svc, "\$j_status = \$status === 'draft' ? 'draft' : 'posted';") !== false && strpos($svc, "`status`, `user_id`) VALUES ('{\$tenant_id}', '{\$journal_code}'") !== false);
check('issuing a draft flips its journal; cancelled/reversed keep theirs', strpos($svc, "IF(`status` IN ('draft','posted')") !== false);
nv_done();
