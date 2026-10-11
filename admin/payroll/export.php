<?php
require_once('../../config.php');
require_once(base_app . 'classes/FijiPayroll.php');

if(!$_settings->userdata('id')){
    die("Access Denied: Authentication required.");
}

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'generic';
$ref = isset($_GET['ref']) ? $_GET['ref'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : '';

$tenant_id = $_settings->active_tenant_id();

$where = " WHERE p.tenant_id = '{$tenant_id}' ";
if (!empty($ref)) {
    $where .= " AND p.payroll_run_ref = '" . $conn->real_escape_string($ref) . "' ";
} elseif (!empty($month)) {
    $where .= " AND p.salary_month = '" . $conn->real_escape_string($month) . "' ";
}

$sql = "SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code
        FROM `payroll_list` p
        INNER JOIN `employee_list` e ON p.employee_id = e.id
        {$where}
        ORDER BY e.firstname ASC";

$qry = $conn->query($sql);
$rows = [];
while ($row = $qry->fetch_assoc()) {
    $rows[] = $row;
}

if (empty($rows)) {
    die("No payroll records found for export.");
}

// Get Employer TIN and FNPF Ref from system settings or active tenant
$employerTin = $_settings->info('employer_tin') ?: '00-00000-0';
$employerFnpfRef = $_settings->info('employer_fnpf_ref') ?: 'FNPF-0000';

$runRef = !empty($ref) ? $ref : (!empty($month) ? str_replace(' ', '_', $month) : date('Ymd'));

if ($type === 'frcs') {
    $csv = FijiPayroll::generate_frcs_tpos_export($rows, $employerTin, $runRef);
    $filename = "FRCS_TPOS_PAYE_{$runRef}_" . date('Ymd_His') . ".csv";
} elseif ($type === 'fnpf') {
    $csv = FijiPayroll::generate_fnpf_schedule_export($rows, $employerFnpfRef, $runRef);
    $filename = "FNPF_Schedule_{$runRef}_" . date('Ymd_His') . ".csv";
} else {
    $csv = FijiPayroll::generate_bank_export($rows, $type, $runRef);
    $filename = strtoupper($type) . "_Bank_Salary_{$runRef}_" . date('Ymd_His') . ".csv";
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
exit;
?>