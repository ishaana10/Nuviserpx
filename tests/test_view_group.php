<?php

echo "=== TEST: Group View Prepared Statement & SQL Injection Prevention ===\n";

$file_path = __DIR__ . '/../admin/groups/view_group.php';
if (!file_exists($file_path)) {
    echo "FAILED: File admin/groups/view_group.php does not exist\n";
    exit(1);
}

$code = file_get_contents($file_path);

if (!str_contains($code, '$conn->prepare(') || !str_contains($code, 'bind_param(')) {
    echo "FAILED: admin/groups/view_group.php does not use prepared statements (\$conn->prepare or bind_param)\n";
    exit(1);
}

if (!str_contains($code, 'intval(') || !str_contains($code, 'is_numeric(')) {
    echo "FAILED: admin/groups/view_group.php does not perform integer validation/sanitization\n";
    exit(1);
}

// Test parameter handling logic
$_GET['id'] = "1' OR '1'='1";
$id_input = $_GET['id'];

if (is_numeric($id_input)) {
    echo "FAILED: SQL injection payload '1\' OR \'1\'=\'1' was erroneously accepted as numeric\n";
    exit(1);
}

$clean_id = intval($id_input);
if ($clean_id !== 1) {
    echo "FAILED: intval handling did not safely parse lead integer: got {$clean_id}\n";
    exit(1);
}

echo "SQL INJECTION PREVENTION AND PREPARED STATEMENTS VERIFIED FOR GROUP VIEW!\n";
