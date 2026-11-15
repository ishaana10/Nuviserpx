<?php
// in_array($_GET['k'] ?? 'x', [..'x'..]) ? $_GET['k'] : ...  raises "Undefined array key" when ?k= is missing,
// because the default passes the check but the true-branch has no default. Found on a live page (expense_reports).
$root = dirname(__DIR__);
$f = 0; function check($n, $c) { global $f; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $f++; }
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $p = $file->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/tests/') !== false || strpos($p, '/libs/') !== false || strpos($p, '/plugins/') !== false) continue;
    $src = file_get_contents($p);
    if (preg_match_all('/in_array\(\$_(GET|POST|REQUEST)\[\'(\w+)\'\]\s*\?\?\s*([\'"][^\'"]*[\'"])\s*,\s*\[([^\]]*)\]\s*,\s*true\)\s*\?\s*\$_\1\[\'\2\'\](?!\s*\?\?)/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $default = trim($x[3], '\'"');
            $list = array_map(fn($v) => trim(trim($v), '\'"'), explode(',', $x[4]));
            if (in_array($default, $list, true)) { $bad[] = substr($p, strlen($root) + 1) . " ({$x[2]})"; }
        }
    }
}
check('no page reads a request key without a default after defaulting it into an allowed list' . ($bad ? ': ' . implode(', ', $bad) : ''), !$bad);
exit($f ? 1 : 0);
