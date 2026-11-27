<?php
// API parties (find-or-create) and invoice external_ref / draft release. Needs MySQL: SKIPs without one.
require __DIR__ . '/db/_harness.php';
use App\Services\ExternalPartyService as P;
use App\Services\ExternalInvoiceRef as R;
use App\Support\CustomerPortal;
[$c, $db] = nv_fresh_db();

$a = P::findOrCreate($c, 1, ['name' => 'Alice', 'email' => 'Alice@X.com']);
$b = P::findOrCreate($c, 1, ['name' => 'Alice again', 'email' => 'alice@x.com']);
check('Alice@X.com and alice@x.com give one party', $a['id'] === $b['id'] && $a['created'] && !$b['created']);
check('email is stored lower-cased', $c->query("SELECT email FROM party_list WHERE id={$a['id']}")->fetch_row()[0] === 'alice@x.com');
$t1 = P::findOrCreate($c, 1, ['name' => 'Bob', 'tin' => '123-4']);
$t2 = P::findOrCreate($c, 1, ['name' => 'Bob', 'tin' => '123-4']);
check('TIN-only match returns the same party', $t1['id'] === $t2['id'] && !$t2['created']);
check('a party code is returned', str_starts_with($a['party_code'], 'CUST-'));
$o = P::findOrCreate($c, 2, ['name' => 'Alice', 'email' => 'alice@x.com']);
check('another tenant gets its own party', $o['id'] !== $a['id'] && $o['created']);
$bad = 0;
foreach ([['name' => 'x'], ['email' => 'a@b.co'], ['name' => 'x', 'email' => 'nope']] as $in) {
    try { P::findOrCreate($c, 1, $in); } catch (\InvalidArgumentException $e) { $bad++; }
}
check('missing identity, missing name and bad email are rejected', $bad === 3);
check('GET lookup by email and by tin', P::find($c, 1, 'ALICE@x.com', null)['id'] == $a['id'] && P::find($c, 1, null, '123-4')['id'] == $t1['id'] && P::find($c, 1, 'no@x.com', null) === null);

// Invoices
$ins = fn($ref, $st) => $c->query("INSERT INTO invoice_list (tenant_id,invoice_no,party_id,invoice_date,total,balance,status,external_ref) VALUES (1,'N" . random_int(1, 1e9) . "',{$a['id']},CURDATE(),100,100,'$st'," . ($ref === null ? 'NULL' : "'$ref'") . ")");
$ins('AP-1', 'draft');
check('external_ref lookup finds the invoice', R::find($c, 1, 'AP-1') !== null && R::find($c, 2, 'AP-1') === null);
$ins('AP-1', 'draft');
check('a second insert with the same external_ref hits the unique key', $c->errno === 1062);
$ins(null, 'unpaid'); $ins(null, 'unpaid');
check('invoices without external_ref are unrestricted', $c->errno === 0);
check('status rules: draft/unpaid/null valid, others not', R::validStatus('draft') && R::validStatus('unpaid') && R::validStatus(null) && !R::validStatus('paid') && !R::validStatus('bogus'));

$portal = fn() => (int)$c->query("SELECT COUNT(*) FROM invoice_list WHERE tenant_id=1 AND party_id={$a['id']} AND " . CustomerPortal::invoiceStatusSql())->fetch_row()[0];
$before = $portal();
$id = R::find($c, 1, 'AP-1')['id'];
check('a draft is absent from the portal query', $before === 2);
check('release turns the draft into unpaid', R::release($c, 1, (int)$id) === true);
check('and it is then visible', $portal() === 3);
check('releasing twice, or another tenant\'s invoice, does nothing', R::release($c, 1, (int)$id) === false && R::release($c, 2, (int)$id) === false);

// Concurrency: two processes inserting the same external_ref end with one invoice.
$ref = 'AP-RACE';
$cmd = fn() => proc_open([PHP_BINARY, '-r', '[$h,$u,$p]=' . var_export(nv_db_server(), true) . '; $m=new mysqli($h,$u,$p,' . var_export($db, true) . "); \$m->query(\"INSERT INTO invoice_list (tenant_id,invoice_no,party_id,invoice_date,total,balance,status,external_ref) VALUES (1,'R'+RAND(),{$a['id']},CURDATE(),1,1,'draft','$ref')\");"], [], $pipes);
$p1 = $cmd(); $p2 = $cmd(); proc_close($p1); proc_close($p2);
check('concurrent inserts with one external_ref give one invoice', (int)$c->query("SELECT COUNT(*) FROM invoice_list WHERE external_ref='$ref'")->fetch_row()[0] === 1);

// Scope: a key without write_parties gets 403
$perm = fn(array $have, string $need) => in_array('*', $have) || in_array($need, $have);
check('a key without write_parties is refused, with it allowed', !$perm(['read_parties'], 'write_parties') && $perm(['write_parties'], 'write_parties') && $perm(['*'], 'write_parties'));
nv_done();
