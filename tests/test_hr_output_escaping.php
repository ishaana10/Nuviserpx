<?php
/** Free text typed by staff or by employees (leave reasons, names, codes) must never be printed raw on HR pages. */
$root = dirname(__DIR__);
$raw = [
    'admin/employees/manage_employee.php' => ['$employee_code : \'\'', '$firstname : \'\'', '$lastname : \'\'', '$phone : \'\'', '$tin : \'\'', '$fnpf_no : \'\'', '$bank_account : \'\'', '$department : \'\'', '$designation : \'\''],
    'admin/employees/index.php' => ["echo \$row['employee_code'] ?>", "echo \$row['name'] ?>", "echo \$row['department'] ?>", "echo \$row['designation'] ?>"],
    'admin/leaves/index.php' => ["echo \$row['emp_name'] ?>", "echo \$row['employee_code'] ?>", "echo \$row['reason'] ?>"],
    'admin/leaves/manage_leave.php' => ['$reason : \'\'', "\$row['employee_code'] . ' - ' . \$row['name'] ?>"],
    'admin/attendance/index.php' => ["echo \$row['emp_name'] ?>", "echo \$row['employee_code'] ?>"],
    'admin/attendance/manage_attendance.php' => ["\$row['employee_code'] . ' - ' . \$row['name'] ?>"],
];
$fail = 0;
foreach ($raw as $file => $bad) {
    $s = file_get_contents("$root/$file");
    foreach ($bad as $b) {
        $hit = strpos($s, $b) !== false;
        echo ($hit ? 'FAIL' : 'PASS') . " - $file does not print " . trim($b) . " unescaped\n";
        $fail += $hit;
    }
}
exit($fail ? 1 : 0);
