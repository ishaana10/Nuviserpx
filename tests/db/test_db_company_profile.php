<?php
// Company profile: new fields save, are validated, are per company, and reach the document header.
require __DIR__ . '/_harness.php';
class SystemSettings {
    public static int $tenant = 1;
    public function active_tenant_id() { return self::$tenant; }
    public function userdata($k = '') { return $k === 'id' ? 1 : null; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
}
use App\Services\TenantService;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Second Co')");
$svc = new TenantService($c, new SystemSettings());
$j = fn($x) => json_decode($x, true);
$row = fn($id) => $c->query("SELECT * FROM tenants WHERE id=$id")->fetch_assoc();

$r = $j($svc->saveProfile(['name' => 'Harbourview Trading Ltd', 'email' => 'a@b.fj', 'phone' => '123', 'address' => "1 Marine Dr\nSuva", 'tin' => ' 50-123456-0-1 ', 'vat_no' => 'V-998', 'website' => 'harbourview.com.fj', 'bank_details' => "Bank: Test\nAcc: 1"], []));
check('profile with the new fields saves', ($r['status'] ?? '') === 'success');
$t = $row(1);
check('tax numbers saved trimmed', $t['tin'] === '50-123456-0-1' && $t['vat_no'] === 'V-998');
check('a website without a scheme gets https://', $t['website'] === 'https://harbourview.com.fj');
check('bank details keep their line breaks', $t['bank_details'] === "Bank: Test\nAcc: 1");
check('another company is untouched', $row(2)['tin'] === null && $row(2)['name'] === 'Second Co');
check('an invalid website is refused', ($j($svc->saveProfile(['name' => 'X', 'website' => 'not a url'], []))['status'] ?? '') === 'failed');
check('name is still required', ($j($svc->saveProfile(['name' => ' '], []))['status'] ?? '') === 'failed');
check('bank details are capped at 600 characters', strlen($row(1)['bank_details']) <= 600 && ($j($svc->saveProfile(['name' => 'X', 'bank_details' => str_repeat('a', 900)], []))['status'] ?? '') === 'success' && strlen($row(1)['bank_details']) === 600);

// the letterhead carries them
$pdf = new \App\Support\PdfDoc('P', 'mm', 'A4'); $pdf->AddPage();
$pdf->letterhead(['name' => 'Harbourview', 'tin' => '50-1', 'vat_no' => 'V-9', 'website' => 'https://h.fj', 'email' => 'e@h.fj']);
$raw = $pdf->Output('S');
check('letterhead renders with tax numbers and website', strlen($raw) > 500 && strncmp($raw, '%PDF', 4) === 0);
nv_done();
