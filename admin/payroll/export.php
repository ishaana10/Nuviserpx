<?php
require_once(__DIR__ . '/../../config.php');
require_once(base_app . 'src/Services/PayrollExportService.php');

if(!$_settings->userdata('id')){
    die("Access Denied: Authentication required.");
}

if(!$_settings->can_access('payroll_export')){
    http_response_code(403);
    die("Access Denied: payroll exports require the export permission.");
}

$type = isset($_GET['type']) ? strtolower((string)$_GET['type']) : 'generic';
if (!in_array($type, ['frcs', 'fnpf'], true) && !in_array($type, \App\Services\PayrollExportService::BANKS, true)) {
    $type = 'generic';
}
$ref = isset($_GET['ref']) ? $_GET['ref'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : '';

$tenant_id = $_settings->active_tenant_id();

// With no run or month given, export the most recent month rather than every run ever.
if (empty($ref) && empty($month)) {
    $latest = $conn->query("SELECT salary_month FROM `payroll_list` WHERE tenant_id = '{$tenant_id}' AND status = 'posted' ORDER BY pay_date DESC, id DESC LIMIT 1");
    if ($latest && ($lr = $latest->fetch_assoc())) {
        $month = $lr['salary_month'];
    }
}

$where = " WHERE p.tenant_id = '{$tenant_id}' AND p.status = 'posted' ";
if (!empty($ref)) {
    $where .= " AND p.payroll_run_ref = '" . $conn->real_escape_string($ref) . "' ";
} elseif (!empty($month)) {
    $where .= " AND p.salary_month = '" . $conn->real_escape_string($month) . "' ";
}

$sql = "SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code,
               e.bank_code, e.bank_account, e.tax_code, e.is_resident,
               COALESCE(NULLIF(p.tin, ''), e.tin) AS tin,
               COALESCE(NULLIF(p.fnpf_no, ''), e.fnpf_no) AS fnpf_no
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
$employerTin = $_settings->info('employer_tin') ?: ($_settings->info('company_tin') ?: '');
$employerFnpfRef = $_settings->info('employer_fnpf_ref') ?: '';

$runRef = !empty($ref) ? $ref : (!empty($month) ? str_replace(' ', '_', $month) : date('Ymd'));
$runRef = preg_replace('/[^A-Za-z0-9_.-]/', '_', $runRef); // safe in the download filename

if ($type === 'frcs') {
    $csv = \App\Services\PayrollExportService::incomeTax($rows, $employerTin, $runRef);
    $filename = "Income_Tax_Return_{$runRef}_" . date('Ymd_His') . ".csv";
} elseif ($type === 'fnpf') {
    $csv = \App\Services\PayrollExportService::pensionSchedule($rows, $employerFnpfRef, $runRef);
    $filename = "Pension_Schedule_{$runRef}_" . date('Ymd_His') . ".csv";
} else {
    $csv = \App\Services\PayrollExportService::bank($rows, $type, $runRef);
    $filename = strtoupper($type) . "_Bank_Salary_{$runRef}_" . date('Ymd_His') . ".csv";
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
exit;
?>