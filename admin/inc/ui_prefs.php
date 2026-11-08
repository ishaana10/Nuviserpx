<?php
// The signed-in user's saved look and feel (see App\Services\UiPrefsService). Sets $__ui for index.php and header.php.
if (!isset($__ui)) {
    $__ui = \App\Services\UiPrefsService::DEFAULTS;
    if (isset($_SESSION['userdata']['id']) && isset($conn)) {
        $__ui = (new \App\Services\UiPrefsService($conn, (int)$_SESSION['userdata']['id']))->load();
    }
}
