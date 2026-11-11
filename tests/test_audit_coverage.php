<?php
// Audit trail: diff text, the employee column allow-list, and a guard that every write method in the key services logs.
$root = dirname(__DIR__);
require_once "$root/src/Support/AuditDiff.php";
require_once "$root/src/Services/EmployeeService.php";
use App\Support\AuditDiff as D;
use App\Services\EmployeeService as E;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// ---- diff text
check('reports a changed field', D::changes(['name' => 'Acme'], ['name' => 'Acme Ltd'], ['name']) === "name: 'Acme' -> 'Acme Ltd'");
check('unchanged gives no changes', D::changes(['name' => 'A'], ['name' => 'A'], ['name']) === 'no field changes');
check('10 and 10.00 are the same', D::changes(['credit_limit' => '10.00'], ['credit_limit' => '10'], ['credit_limit']) === 'no field changes');
check('null and empty are the same', D::changes(['email' => null], ['email' => ''], ['email']) === 'no field changes');
check('fields not posted are ignored', D::changes(['a' => '1', 'b' => '2'], ['a' => '1'], ['a', 'b']) === 'no field changes');
check('underscores become spaces', strpos(D::changes(['payment_terms_days' => '7'], ['payment_terms_days' => '30'], ['payment_terms_days']), 'payment terms days:') === 0);
$m = D::changes(['bank_account' => '1234567'], ['bank_account' => '7654321'], ['bank_account'], ['bank_account']);
check('masked fields never show values', $m === 'bank account changed' && strpos($m, '1234') === false && strpos($m, '7654') === false);
check('long values are cut', strlen(D::changes(['d' => ''], ['d' => str_repeat('x', 500)], ['d'])) < 120);
check('several changes are joined', substr_count(D::changes(['a' => '1', 'b' => '1'], ['a' => '2', 'b' => '2'], ['a', 'b']), ';') === 1);
check('list changes show added and removed', D::listChanges('a,b,c', 'b,c,d') === 'added: d; removed: a');
check('list with no change says so', D::listChanges('a,b', 'b,a') === 'no permission changes');
check('list handles an empty before', D::listChanges('', 'x,y') === 'added: x, y');

// ---- employee column allow-list
$cols = ['id', 'tenant_id', 'employee_code', 'firstname', 'salary', 'delete_flag', 'status'];
$f = E::editableFields(['firstname' => 'Jo', 'salary' => '5', 'tenant_id' => 9, 'delete_flag' => 1, 'id' => 4, 'x`=1,`salary' => 1, 'nope' => 'v', 'f' => ['a']], $cols);
check('real columns pass', $f === ['firstname' => 'Jo', 'salary' => '5'] || array_keys($f) === ['firstname', 'salary']);
check('tenant and delete flag cannot be posted', !isset($f['tenant_id']) && !isset($f['delete_flag']) && !isset($f['id']));
check('injected field names are dropped', !isset($f['x`=1,`salary']) && !isset($f['nope']));
check('array values are dropped', !isset(E::editableFields(['firstname' => ['a']], $cols)['firstname']));

// ---- every write method in these services must log
$must = [
    'PartyService' => ['saveParty', 'deleteParty'],
    'InventoryService' => ['saveProduct', 'deleteProduct', 'saveStockAdjustment'],
    'PurchaseService' => ['savePurchaseOrder', 'deletePurchaseOrder', 'receiveGoods', 'saveVendorBill', 'deleteVendorBill'],
    'EmployeeService' => ['saveEmployee', 'deleteEmployee', 'saveAttendance', 'deleteAttendance', 'saveLeave', 'updateLeaveStatus', 'cancelLeave', 'deleteLeave'],
    'RoleService' => ['saveRole', 'deleteRole', 'saveApiKey', 'deleteApiKey'],
    'TenantService' => ['saveTenant', 'deleteTenant'],
];
foreach ($must as $cls => $methods) {
    $src = file_get_contents("$root/src/Services/$cls.php");
    foreach ($methods as $m) {
        $start = strpos($src, "function $m(");
        $end = $start === false ? false : strpos($src, "\n    public function ", $start + 10);
        $body = $start === false ? '' : substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
        // some saves log through a small helper in the same class
        $logs = strpos($body, 'log_audit') !== false || strpos($body, 'auditSave') !== false || strpos($body, 'auditEmployee') !== false || strpos($body, 'auditRole') !== false;
        check("$cls::$m writes an audit entry", $logs);
    }
}
check('API key secrets are not logged', strpos(file_get_contents("$root/src/Services/RoleService.php"), "log_audit(empty(\$id) ? 'Created API Key'") !== false && !preg_match('/log_audit\([^;]*api_secret/', file_get_contents("$root/src/Services/RoleService.php")));
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
