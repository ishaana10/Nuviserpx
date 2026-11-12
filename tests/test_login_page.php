<?php
// Login page: light design and version in the bottom-right corner.
$root = dirname(__DIR__);
$p = file_get_contents($root . '/admin/login.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('dark body background gone', strpos($p, 'bg-dark') === false && strpos($p, '#0f172a 0%, #1e293b 100%') === false);
check('light gradient background in place', strpos($p, '#f8fafc 0%, #eef4ff 55%') !== false);
check('card is white with light border', strpos($p, 'background: #ffffff;') !== false && strpos($p, 'border: 1px solid #e2e8f0;') !== false);
check('headings use dark text, no text-white left', strpos($p, 'text-white font-weight-bold') === false && substr_count($p, 'view-title') >= 4);
check('version pinned bottom right', strpos($p, 'nv-login-version') !== false && strpos($p, 'position: fixed;') !== false && strpos($p, "AppVersion::get('version')") !== false && strpos($p, "AppVersion::get('build')") !== false);
check('version output is escaped', substr_count($p, 'htmlspecialchars(\App\Support\AppVersion::get(') === 3);
check('password show/hide toggle wired', strpos($p, 'btn-eye') !== false && strpos($p, "inp.attr('type', show ? 'text' : 'password')") !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
