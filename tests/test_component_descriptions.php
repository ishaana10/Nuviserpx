<?php
// Fixed per-employee allowances/deductions carry descriptions end to end:
// component -> assignment (note) -> payroll snapshot -> payslip.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Permissions.php';
require_once $root . '/src/Services/PayrollComponentService.php';

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// lines() carries the description through to the engine/snapshot shape
$l = \App\Services\PayrollComponentService::lines([[
    'component_id' => 5, 'code' => 'INS', 'name' => 'Fiji Care Medical Insurance',
    'type' => 'deduction', 'is_taxable' => 0, 'is_fnpf_subject' => 0,
    'amount' => '41.90', 'description' => 'Group medical cover - Policy FC-20331',
]]);
check('engine lines carry the description', $l[0]['description'] === 'Group medical cover - Policy FC-20331' && $l[0]['amount'] === 41.90);

$svc = file_get_contents($root . '/src/Services/PayrollComponentService.php');
check('assignment query merges component description with the note', strpos($svc, "CONCAT_WS(' - ', NULLIF(c.description, ''), NULLIF(a.note, ''))") !== false);
check('payroll snapshot stores and reloads the description', strpos($svc, 'is_fnpf_subject, amount, description) VALUES (?,?,?,?,?,?,?,?,?,?)') !== false && strpos($svc, 'i.is_fnpf_subject, i.description') !== false);
check('assign() accepts a note', strpos($svc, "\$post['note']") !== false && strpos($svc, 'end_date, note) VALUES') !== false);

$mig = (string)@file_get_contents($root . '/database/migrations/042_component_descriptions.sql');
check('migration + catch-up add the three description columns', substr_count($mig, 'ADD COLUMN IF NOT EXISTS') === 3 && strpos(file_get_contents($root . '/src/Database/LegacySchemaCatchup.php'), "'payroll_component_items', 'description'") !== false);

$ms = file_get_contents($root . '/classes/Master.php');
check('component save stores the description', strpos($ms, '`default_amount`, `description`, `status`') !== false);

$sc = file_get_contents($root . '/admin/payroll/salary_components.php');
check('component and assignment forms capture description/note', strpos($sc, 'name="description"') !== false && strpos($sc, 'name="note"') !== false);

$me = file_get_contents($root . '/admin/employees/manage_employee.php');
check('employee form lists and edits fixed allowances/deductions', strpos($me, 'Fixed Allowances & Deductions') !== false && strpos($me, 'assign_salary_component') !== false && strpos($me, 'emp-unassign') !== false);
check('employee form filters assignments to the employee', strpos($me, "(int)(\$a['employee_id'] ?? 0) === (int)\$id") !== false);

$ps = file_get_contents($root . '/admin/payroll/view_payslip.php');
check('payslip prints each line description (earnings and deductions)', substr_count($ps, "!empty(\$pc['description'])") === 2);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
