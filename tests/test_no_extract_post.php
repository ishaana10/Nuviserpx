<?php
// Posted data must never become local variables: a form field named tenant_id or user_id would otherwise overwrite
// the values the controller just set from the session.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

foreach (['classes/Master.php', 'classes/Login.php', 'classes/Users.php'] as $f) {
    $tokens = token_get_all(file_get_contents("$root/$f"));
    $hits = 0;
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'extract') {
            $hits++;
        }
    }
    check("$f does not use extract()", $hits === 0);
}

// Every controller method that took the company or user from the session must not read them from the form after.
$m = file_get_contents("$root/classes/Master.php") . file_get_contents("$root/src/Services/FixedAssetService.php") . file_get_contents("$root/src/Services/ContractService.php");
check('no controller reads the company from the form', substr_count($m, "\$tenant_id = \$_POST['tenant_id']") === 0);
check('only the public proposal form reads a posted company, and the quote overrides it', strpos($m, "\$t_id = (int)\$quoteRow['tenant_id'];") !== false);
check('user_id is never read from the form', strpos($m, "\$user_id = \$_POST") === false);
exit($fail ? 1 : 0);
