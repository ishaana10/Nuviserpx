<?php
// Leave attachment (small, private, validated), live leave validation, calendar, payslip reuse.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nv_leave_' . bin2hex(random_bytes(4)) . '/';
mkdir($tmp, 0777, true);
define('base_app', $tmp);
define('NV_TESTING', 1);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$read = fn($f) => file_get_contents("$root/$f");
require_once "$root/src/Support/LeaveAttachment.php";
use App\Support\LeaveAttachment as A;

function upload(string $bytes, string $name, int $err = UPLOAD_ERR_OK, ?int $size = null): array {
    $p = tempnam(sys_get_temp_dir(), 'nv'); file_put_contents($p, $bytes);
    return ['name' => $name, 'tmp_name' => $p, 'size' => $size ?? strlen($bytes), 'error' => $err, 'type' => ''];
}
$pdf = upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'cert.pdf');
$im = imagecreatetruecolor(3000, 2000); imagefill($im, 0, 0, 0x3366cc); ob_start(); imagepng($im); $png = ob_get_clean();
$img = upload($png, 'photo of certificate.png');
$php = upload("<?php echo 1;", 'cert.pdf');
$gif = upload("GIF89a" . str_repeat("\0", 30), 'x.jpg');

check('a PDF is accepted', A::problem($pdf) === null && A::kind($pdf['tmp_name']) === 'pdf');
check('a PNG photo is accepted', A::problem($img) === null);
check('no file at all is fine (attachment is optional)', A::problem(null) === null && A::problem(['error' => UPLOAD_ERR_NO_FILE]) === null);
check('a PHP file renamed .pdf is refused (checked by content)', A::problem($php) !== null);
check('a GIF renamed .jpg is refused', A::problem($gif) !== null);
check('over 2 MB is refused', A::problem(upload("%PDF-" . str_repeat('x', 10), 'a.pdf', UPLOAD_ERR_OK, 2097153)) !== null);
check('PHP upload-size errors give a plain message', strpos((string)A::problem(upload('', 'a.pdf', UPLOAD_ERR_INI_SIZE)), '2 MB') !== false);

$s = A::store($pdf, 5, 42);
check('PDF stored privately under a random name', $s && strpos($s['path'], 'uploads/leave_docs/leave_5_42_') === 0 && substr($s['path'], -4) === '.pdf' && is_file($tmp . $s['path']));
check('the folder refuses direct web access', is_file($tmp . 'uploads/leave_docs/.htaccess') && strpos(file_get_contents($tmp . 'uploads/leave_docs/.htaccess'), 'Deny from all') !== false);
$si = A::store($img, 5, 42);
$dim = $si ? getimagesize($tmp . $si['path']) : [0, 0, 0];
check('a big photo is shrunk to 1600 px and saved as a JPEG', $si && substr($si['path'], -4) === '.jpg' && max($dim[0], $dim[1]) <= 1600 && $dim[2] === IMAGETYPE_JPEG);
check('the stored name is sanitised', $si && $si['name'] === 'photo of certificate.png');
A::remove($s['path']);
check('remove deletes a stored file', !is_file($tmp . $s['path']));
file_put_contents($tmp . 'secret.txt', 'x');
A::remove('uploads/leave_docs/../../secret.txt'); A::remove('secret.txt');
check('remove never touches files outside the leave folder', is_file($tmp . 'secret.txt'));

// Wiring
check('migration adds the attachment columns', preg_match('~attachment_path.*attachment_name.*attachment_size~s', $read('database/migrations/044_leave_attachment.sql')) === 1);
$svc = $read('src/Services/PortalLeaveService.php');
preg_match('~function apply\(.*?\n    }~s', $svc, $ap);
check('apply re-validates server side (balance, overlap) and the file before saving', strpos($ap[0], '$this->preview($post)') !== false && strpos($ap[0], 'LeaveAttachment::problem($file)') !== false && strpos($ap[0], 'saveLeave') > strpos($ap[0], 'problem'));
check('overlap with pending/approved leave is refused', strpos($svc, "status IN ('pending','approved') AND start_date <= ? AND end_date >= ?") !== false);
check('no working days / balance shortfall are reported by preview', strpos($svc, 'no working days') !== false && strpos($svc, '->check($this->employeeId') !== false);
check('attachment is saved only onto the employee\'s own request', strpos($svc, 'WHERE id = ? AND tenant_id = ? AND employee_id = ?') !== false);
$es = $read('src/Services/EmployeeService.php');
check('cancelling or deleting a request deletes its file (saves space)', substr_count($es, 'dropAttachment(') >= 3);
check('save returns the new id', strpos($es, "\$resp['id'] = \$leaveId;") !== false);
$m = $read('classes/Master.php');
check('preview endpoint is public-listed and whitelisted', strpos($m, 'employee_portal_leave_preview') !== false && strpos($read('src/Support/Permissions.php'), "'employee_portal_leave_preview'") !== false);
check('apply passes the uploaded file through', strpos($m, "->apply(\$_POST, \$_FILES['attachment'] ?? null)") !== false);
check('employee download is limited to own requests', strpos($read('portal/leave_doc.php'), '$leave->attachment(') !== false);
$ad = $read('admin/leaves/leave_doc.php');
check('staff download needs sign-in, same company, and HR/approver/owner', strpos($ad, "userdata('id')") !== false && strpos($ad, 'l.tenant_id = ?') !== false && strpos($ad, 'leaves_edit') !== false && strpos($ad, 'approver_id') !== false);
check('HR sees the attachment in the leave list', strpos($read('admin/leaves/index.php'), 'leave_doc.php?id=') !== false);
$ui = $read('portal/employee_leave.php');
check('My Leave has Balances, New Request, View Requests and Calendar tabs', preg_match('~Balances.*New Request.*View Requests.*Calendar~s', $ui) === 1);
$js = $read('dist/js/nuvis-portal-leave.js');
check('form blocks submit until the live check passes', strpos($js, "prop('disabled', true)") !== false && strpos($js, 'employee_portal_leave_preview') !== false && strpos($js, 'if (!ok) { return; }') !== false);
check('calendar marks pending, approved, holidays and weekends', strpos($js, "'we'") !== false && strpos($js, "'hol'") !== false && strpos($js, "'approved'") !== false && strpos($js, 'Pending') !== false);
// Payslip reuse
check('portal payslip is the same view HR uses, run as this employee only', strpos($read('portal/employee_payslips.php'), 'emp_render_payslip(') !== false
    && strpos($read('portal/_employee_layout.php'), "admin/payroll/view_payslip.php") !== false && strpos($read('portal/_employee_layout.php'), 'new \\App\\Support\\PortalSettings($conn, $tenantId, $employeeId)') !== false);
check('payslip not found for another employee\'s id (checked before rendering)', strpos($read('portal/employee_payslips.php'), '$svc->payslip($tenantId, $employeeId') !== false);
exit($fail ? 1 : 0);
