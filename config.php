<?php
ob_start();
ini_set('date.timezone','Pacific/Fiji');
date_default_timezone_set('Pacific/Fiji');
if (session_status() === PHP_SESSION_NONE) {
	ini_set('session.cookie_httponly', 1);
	ini_set('session.use_only_cookies', 1);
	ini_set('session.cookie_samesite', 'Lax');
	session_name('NUVISERPXSESSID');
	session_start();
}

require_once('initialize.php');

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
(function () use (&$_settings) {
	if (!isset($_settings) || !isset($_SESSION['userdata']) || ($_SESSION['userdata']['login_type'] ?? 1) != 1) return;
	$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
	$root = str_replace('\\', '/', rtrim(base_app, '/\\')) . '/admin/';
	if (strpos($script, $root) !== 0 || isset($_GET['f'])) return;
	$msg = \App\Support\Permissions::guardDirectView(substr($script, strlen($root)), $_settings, $_GET);
	if ($msg !== null) {
		http_response_code(403);
		while (ob_get_level() > 0) { ob_end_clean(); }
		die('<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>' . htmlspecialchars($msg) . '</div>');
	}
})();
ob_end_flush();
?>