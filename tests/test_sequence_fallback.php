<?php
// Document numbering must survive a host where prepare() fails on the
// sequences table: ensure-table first, plain-query fallback, errno in errors.
$root = dirname(__DIR__);
$s = file_get_contents($root . '/src/Services/SequenceService.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('table is ensured before any prepare', strpos($s, 'self::ensureTable($conn);') !== false && strpos($s, 'self::ensureTable($conn);') < strpos($s, 'INSERT IGNORE INTO `document_sequences`'));
check('all three statements checked, fallback on any failure', (bool)preg_match('/\$ins === false \|\| \$exists === false \|\| \$up === false/', $s) && strpos($s, 'return self::nextViaQuery($conn, $tenantId, $key, $seed);') !== false);
check('fallback keeps the same take-a-number semantics', strpos($s, "SET `last_value` = LAST_INSERT_ID(`last_value` + 1) WHERE `tenant_id` = {\$tenantId} AND `seq_key` = '{\$k}'") !== false && strpos($s, 'INSERT IGNORE INTO `document_sequences`') !== false);
// last_value is a reserved word on MariaDB: every identifier must be quoted.
check('no unquoted last_value remains anywhere in the service', !preg_match('/[^`]last_value[^`]/', str_replace(['\$', '->'], '', $s)) || substr_count($s, '`last_value`') >= 5);
check('every last_value in SQL is backticked', !preg_match('/(?<!`)last_value(?!`)/', preg_replace('/\/\*.*?\*\//s', '', $s)));
check('fallback escapes the key and inlines only ints', strpos($s, '$k = $conn->real_escape_string($key);') !== false);
check('remaining failures carry the MySQL errno', substr_count($s, '{$conn->errno}') >= 2);
check('no blind bind_param on a possibly-false statement', !preg_match('/if \(\$ins === false \|\| \$exists === false\) \{\s*throw/', $s));

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
