<?php
ob_start();
ini_set('date.timezone','Pacific/Fiji');
date_default_timezone_set('Pacific/Fiji');
if (session_status() === PHP_SESSION_NONE) {
	ini_set('session.cookie_httponly', 1);
	ini_set('session.use_only_cookies', 1);
	ini_set('session.cookie_samesite', 'Lax');
	// On HTTPS (direct or behind a proxy) the session cookie is secure-only.
	$nv_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
	if ($nv_https) {
		ini_set('session.cookie_secure', 1);
	}
	ini_set('session.gc_maxlifetime', 14400);   // idle sessions live at most 4h server-side
	ini_set('session.cookie_lifetime', 0);      // cookie ends with the browser session
	ini_set('session.use_strict_mode', 1);      // reject uninitialised session ids
	session_name('NUVISERPXSESSID');
	session_start();
}

require_once('initialize.php');
\App\Support\Maintenance::guard();

if (!headers_sent() && php_sapi_name() !== 'cli') {
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
	// Only the directives that do not need the app's inline scripts rewritten: no plugins, framing by this site only.
	header("Content-Security-Policy: object-src 'none'; frame-ancestors 'self'");
	header('Referrer-Policy: strict-origin-when-cross-origin');
	if (!empty($nv_https)) {
		header('Strict-Transport-Security: max-age=31536000');
	}
}

// Server-side PHP errors are kept in logs/php_errors.log and shown under
// Developer > Error Logs. The folder is blocked from the web (.htaccess).
$__nv_logs = base_app . 'logs';
if (!is_dir($__nv_logs)) {
	@mkdir($__nv_logs, 0775, true);
	@file_put_contents($__nv_logs . '/.htaccess', "Require all denied\n");
	@file_put_contents($__nv_logs . '/index.html', '');
}
if (is_dir($__nv_logs) && is_writable($__nv_logs)) {
	ini_set('log_errors', '1');
	ini_set('error_log', $__nv_logs . '/php_errors.log');
	if (is_file($__nv_logs . '/php_errors.log') && (filesize($__nv_logs . '/php_errors.log') ?: 0) > 8388608) {
		@rename($__nv_logs . '/php_errors.log', $__nv_logs . '/php_errors.log.1');
	}
}

require_once('classes/DBConnection.php');
require_once('classes/SystemSettings.php');
require_once('classes/ModuleManager.php');
$db = new DBConnection;
$conn = $db->conn;

function redirect($url=''){
	if(!empty($url))
	echo '<script>location.href="'.base_url .$url.'"</script>';
}
function validate_image($file){
	if(!empty($file)){
			// exit;
        $ex = explode('?',$file);
        $file = $ex[0];
        $param =  isset($ex[1]) ? '?'.$ex[1]  : '';
		if(is_file(base_app.$file)){
			return base_url.$file.$param;
		}else{
			return base_url.'dist/img/no-image-available.png';
		}
	}else{
		return base_url.'dist/img/no-image-available.png';
	}
}
function isMobileDevice(){
    $aMobileUA = array(
        '/iphone/i' => 'iPhone', 
        '/ipod/i' => 'iPod', 
        '/ipad/i' => 'iPad', 
        '/android/i' => 'Android', 
        '/blackberry/i' => 'BlackBerry', 
        '/webos/i' => 'Mobile'
    );

    //Return true if Mobile User Agent is detected
    foreach($aMobileUA as $sMobileKey => $sMobileOS){
        if(preg_match($sMobileKey, $_SERVER['HTTP_USER_AGENT'])){
            return true;
        }
    }
    //Otherwise return false..  
    return false;
}

// Central authorisation for admin view files requested directly (modal forms,
// exports, sub-pages): the router's page gate cannot see these requests.
// 1) Every file under /admin/ needs a signed-in staff session. Without it a modal form such as
//    manage_invoice.php?id=1 would answer an anonymous request with a company's data.
//    Only the sign-in page, the session ping, the router (which shows the sign-in itself) and the
//    update page (which checks for a super administrator itself) are reachable without one.
// 2) Signed-in staff are then checked against the permission of the page they asked for.
(function () use (&$_settings) {
	if (php_sapi_name() === 'cli' || !isset($_settings)) return;
	$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
	$root = str_replace('\\', '/', rtrim(base_app, '/\\')) . '/admin/';
	if (strpos($script, $root) !== 0) return;
	$rel = substr($script, strlen($root));
	$open = ['index.php', 'login.php', 'session_ping.php', 'update_database.php'];
	$staff = isset($_SESSION['userdata']) && ($_SESSION['userdata']['login_type'] ?? 1) == 1;
	if (!$staff) {
		if (in_array($rel, $open, true)) return;
		http_response_code(401);
		while (ob_get_level() > 0) { ob_end_clean(); }
		die('<div class="alert alert-warning m-3"><i class="fas fa-lock mr-2"></i>Your session has ended. Please sign in again.</div>');
	}
	if (isset($_GET['f'])) return;
	$msg = \App\Support\Permissions::guardDirectView($rel, $_settings, $_GET);
	if ($msg !== null) {
		http_response_code(403);
		while (ob_get_level() > 0) { ob_end_clean(); }
		die('<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>' . htmlspecialchars($msg) . '</div>');
	}
})();
// Redis (A2 Hosting): started automatically, and any trouble is written to logs/php_errors.log (Developer > Error Logs).
// Does real work at most once every five minutes; never lets a problem here break a page.
if (php_sapi_name() !== 'cli') {
	try { \App\Services\RedisWatch::tick($_settings ?? null); } catch (\Throwable $e) { error_log('[Redis] watcher failed: ' . $e->getMessage()); }
}
ob_end_flush();
?>