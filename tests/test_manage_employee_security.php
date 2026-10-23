<?php
// Unit test for manage_employee.php SQL injection fix verification

$file_content = file_get_contents(__DIR__ . '/../admin/employees/manage_employee.php');

if (strpos($file_content, '$id_clean = intval($_GET[\'id\']);') !== false &&
    strpos($file_content, 'where id = \'{$id_clean}\'') !== false) {
    echo "VERIFICATION SUCCESS: admin/employees/manage_employee.php safely casts \$_GET['id'] to intval().\n";
    exit(0);
} else {
    echo "VERIFICATION FAILED: admin/employees/manage_employee.php does not contain proper intval() sanitization.\n";
    exit(1);
}
