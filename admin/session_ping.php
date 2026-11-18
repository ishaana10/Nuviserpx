<?php
// Is the staff session still signed in? Read-only: the session is opened and closed straight away, so asking
// never extends it (a ping every minute would otherwise keep an abandoned tab signed in forever).
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 14400);
session_name('NUVISERPXSESSID');
session_start(['read_and_close' => true]);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => !empty($_SESSION['userdata'])]);
