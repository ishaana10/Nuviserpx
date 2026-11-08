<?php
/**
 * JSON endpoint for a user's look and feel.
 *
 *   GET  ?f=get
 *   POST ?f=save    any of mode, accent, sidebar, density, text, collapsed, pins[]
 *   POST ?f=reset
 */
ini_set('display_errors', '0');
ob_start();
require_once(__DIR__ . '/../config.php');

use App\Services\UiPrefsService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function prefs_out(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

set_exception_handler(function (\Throwable $e): void {
    error_log('UiPrefs error: ' . $e->getMessage());
    prefs_out(['status' => 'failed', 'msg' => 'Your display settings could not be saved. Please try again.'], 500);
});

if (!isset($_SESSION['userdata']['id'])) {
    prefs_out(['status' => 'failed', 'msg' => 'Your session has expired. Please sign in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_settings->verify_csrf()) {
    prefs_out(['status' => 'failed', 'msg' => 'Security token mismatch. Refresh the page and try again.'], 419);
}

$svc = new UiPrefsService($conn, (int)$_settings->userdata('id'));

switch (strtolower($_GET['f'] ?? '')) {
    case 'get':
        prefs_out(['status' => 'success', 'prefs' => $svc->load()]);
    case 'save':
        $allowed = array_intersect_key($_POST, array_flip(['mode', 'accent', 'sidebar', 'density', 'text', 'collapsed', 'pins']));
        prefs_out(['status' => 'success', 'prefs' => $svc->save($allowed)]);
    case 'reset':
        prefs_out(['status' => 'success', 'prefs' => $svc->reset()]);
    default:
        prefs_out(['status' => 'failed', 'msg' => 'Unknown action.'], 400);
}
