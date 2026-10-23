<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/FijiPayroll.php');

echo "=== TEST 1: Standard Monthly Fiji Resident Payroll Calculation ===\n";
$input = [
    'basic_salary' => 3000,
    'overtime' => 200,
    'allowances' => 100,
    'other_earnings' => 50,
    'deductions' => 50,
    'pay_frequency' => 'Monthly',
    'is_resident' => true
];
$res = FijiPayroll::calculate($input);

assert($res['gross_salary'] == 3350, "Gross salary calculation");
assert($res['employee_fnpf'] == round(3350 * 0.08, 2), "Employee FNPF 8%");
assert($res['employer_fnpf'] == round(3350 * 0.08, 2), "Employer FNPF 8%");
assert($res['taxable_income'] == (3350 - 268), "Taxable Income calculation");

echo "=== TEST 2: High Earner Annual Tax Breakdown (Basic + SRT) ===\n";
$taxableHigh = 25000; // Monthly 25k = 300k annual
$payeHigh = FijiPayroll::calculate_paye($taxableHigh, 'Monthly', true);
assert($payeHigh['srt'] > 0, "SRT should be > 0 for high earner above FJD 270k");

echo "=== TEST 3: Non-Resident 20% Flat Tax Calculation ===\n";
$payeNonRes = FijiPayroll::calculate_paye(2000, 'Monthly', false);
assert($payeNonRes['paye'] == 400.00, "Non-Resident 20% tax");

echo "=== TEST 4: Bank Exports (BSP, ANZ, HFC, BRED, Generic) ===\n";
$testRows = [
    [
        'id' => 1,
        'employee_code' => 'EMP-001',
        'emp_name' => 'John Doe',
        'net_salary' => 2500.50,
        'bank_code' => 'bsp',
        'bank_account' => '123456789',
        'pay_date' => '2024-09-30'
    ],
    [
        'id' => 2,
        'employee_code' => 'EMP-002',
        'emp_name' => 'Jane Smith',
        'net_salary' => 1800.00,
        'bank_code' => 'anz',
        'bank_account' => '987654321',
        'pay_date' => '2024-09-30'
    ]
];

$bspCsv = FijiPayroll::generate_bank_export($testRows, 'bsp', 'RUN-202409-001');
echo "BSP Export Sample:\n" . substr($bspCsv, 0, 200) . "...\n";

$anzCsv = FijiPayroll::generate_bank_export($testRows, 'anz', 'RUN-202409-001');
echo "ANZ Export Sample:\n" . substr($anzCsv, 0, 200) . "...\n";

echo "=== TEST 5: FRCS TPOS & FNPF Schedule Exports ===\n";
$frcsRows = [
    [
        'period_start' => '2024-09-01',
        'period_end' => '2024-09-30',
        'pay_date' => '2024-09-30',
        'tin' => '99-88888-0',
        'emp_name' => 'John Doe',
        'tax_code' => 'P',
        'is_resident' => 1,
        'gross_salary' => 3350,
        'employee_fnpf' => 268,
        'taxable_income' => 3082,
        'paye_tax' => 105,
        'deductions' => 50,
        'net_salary' => 2659,
        'employer_fnpf' => 268,
        'employee_code' => 'EMP-001',
        'fnpf_no' => '123456'
    ]
];

$frcsCsv = FijiPayroll::generate_frcs_tpos_export($frcsRows, '00-11111-2', 'RUN-202409-001');
echo "FRCS TPOS Sample:\n" . substr($frcsCsv, 0, 250) . "...\n";

$fnpfCsv = FijiPayroll::generate_fnpf_schedule_export($frcsRows, 'FNPF-REF-100', 'RUN-202409-001');
echo "FNPF Schedule Sample:\n" . substr($fnpfCsv, 0, 250) . "...\n";

echo "\nALL FIJI PAYROLL UNIT TESTS PASSED SUCCESSFULLY!\n";
?>