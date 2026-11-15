<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_name('NUVISERPXSESSID');
    session_start();
}
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
    $link = "https"; 
else
    $link = "http"; 
$link .= "://"; 
$link .= $_SERVER['HTTP_HOST']; 
$link .= $_SERVER['REQUEST_URI'];
// The employee and customer portals (portal/*.php) have their own sign-in and sessions. This check is for staff
// pages only; running it there sent every portal visitor to the staff login (and bounced a signed-in admin away
// from employee_login.php, because that name contains "login.php").
$nv_path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~/portal/(?:[A-Za-z0-9_]+\.php)?$~', $nv_path)) {
    return;
}
if(!isset($_SESSION['userdata']) && !strpos($link, 'login.php') && !strpos($link, 'register.php')){
	redirect('admin/login.php');
}
if(isset($_SESSION['userdata']) && strpos($link, 'login.php')){
	redirect('admin/index.php');
}
$module = array('','admin','faculty','student');
if(isset($_SESSION['userdata']) && (strpos($link, 'index.php') || strpos($link, 'admin/')) && $_SESSION['userdata']['login_type'] !=  1){
	echo "<script>alert('Access Denied!');location.replace('".base_url.$module[$_SESSION['userdata']['login_type']]."');</script>";
    exit;
}
