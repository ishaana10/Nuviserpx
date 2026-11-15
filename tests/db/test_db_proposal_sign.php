<?php
// Public proposal signing: only the right token signs, only once, and only a real PNG signature is stored.
require __DIR__ . '/_harness.php';
class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function log_audit($a, $m, $d = '') {}
}
use App\Support\ProposalLink;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'C1','Alpha','a@example.com','customer')");
$tok = ProposalLink::newToken(); $tok2 = ProposalLink::newToken();
$c->query("INSERT INTO sales_order_list (id,tenant_id,order_no,party_id,order_date,type,status,sign_token) VALUES (1,1,'Q-1',1,CURDATE(),'proforma','proforma','$tok'),(2,1,'Q-2',1,CURDATE(),'proforma','proforma','$tok2'),(3,1,'Q-3',1,CURDATE(),'proforma','proforma','$tok2')");
$png = 'data:image/png;base64,' . base64_encode(random_bytes(3000));
$svc = new \App\Services\ContractService($c, new SystemSettings());
$sign = function (array $p) use ($svc) { $_POST = $p; return json_decode($svc->save_proposal_signature(), true)['status'] ?? '?'; };
$base = ['sales_order_id' => 1, 'sign_token' => $tok, 'signer_name' => 'Jo Customer', 'signer_email' => 'jo@example.com', 'signature_data' => $png];
$sigs = fn() => (int)$c->query("SELECT COUNT(*) FROM proposal_signatures")->fetch_row()[0];

check('wrong token refused', $sign(['sign_token' => str_repeat('a', 64)] + $base) === 'failed' && $sigs() === 0);
check('token of another proposal refused', $sign(['sign_token' => $tok2] + $base) === 'failed');
check('missing token refused', $sign(['sign_token' => ''] + $base) === 'failed');
foreach ([
    'html in signature' => '"><script>alert(1)</script>',
    'attribute breakout' => 'data:image/png;base64,AAAA" onerror="alert(1)',
    'javascript url' => 'javascript:alert(1)',
    'svg data url' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
    'oversized' => 'data:image/png;base64,' . str_repeat('A', 450000),
] as $name => $bad) {
    check("$name refused", $sign(['signature_data' => $bad] + $base) === 'failed');
}
check('invalid email refused', $sign(['signer_email' => 'not-an-email'] + $base) === 'failed');
check('nothing stored by any refused attempt', $sigs() === 0 && $c->query("SELECT status FROM sales_order_list WHERE id=1")->fetch_row()[0] === 'proforma');

check('valid signature accepted', $sign($base) === 'success');
check('quote confirmed', $c->query("SELECT status FROM sales_order_list WHERE id=1")->fetch_row()[0] === 'confirmed');
check('exactly one signature stored, as sent', $sigs() === 1 && $c->query("SELECT signature_data FROM proposal_signatures")->fetch_row()[0] === $png);
check('cannot be signed twice', $sign($base) === 'failed' && $sigs() === 1);
$c->query("UPDATE sales_order_list SET valid_until = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id=2");
check('expired quote refused', $sign(['sales_order_id' => 2, 'sign_token' => $tok2] + $base) === 'failed');
$c->query("UPDATE sales_order_list SET status='cancelled' WHERE id=3");
check('cancelled quote refused', $sign(['sales_order_id' => 3, 'sign_token' => $tok2] + $base) === 'failed');

check('only PNG data URLs validate', ProposalLink::validSignatureImage($png) && !ProposalLink::validSignatureImage('data:image/jpeg;base64,AAAA') && !ProposalLink::validSignatureImage(''));
check('page escapes and validates the stored image', strpos(file_get_contents(__DIR__ . '/../../portal/proposal_sign.php'), 'validSignatureImage((string)$existing_sig') !== false);
nv_done();
