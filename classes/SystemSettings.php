<?php
if(!class_exists('DBConnection')){
	require_once(__DIR__ . '/../config.php');
	require_once(__DIR__ . '/DBConnection.php');
}
class SystemSettings extends DBConnection{
	public function __construct(){
		parent::__construct();
	}
	function check_connection(){
		return($this->conn);
	}
	function load_system_info(){
		$sql = "SELECT * FROM system_info";
		$qry = @$this->conn->query($sql);
		if(!$qry){
			// If system_info table is missing, trigger auto-installation of database schema
			$this->auto_install_schema();
			$qry = @$this->conn->query($sql);
		}
		if($qry){
			while($row = $qry->fetch_assoc()){
				$_SESSION['system_info'][$row['meta_field']] = $row['meta_value'];
			}
		}
	}
	function update_system_info(){
		$sql = "SELECT * FROM system_info";
		$qry = $this->conn->query($sql);
			while($row = $qry->fetch_assoc()){
				if(isset($_SESSION['system_info'][$row['meta_field']]))unset($_SESSION['system_info'][$row['meta_field']]);
				$_SESSION['system_info'][$row['meta_field']] = $row['meta_value'];
			}
		return true;
	}
	function update_settings_info(){
		$data = "";
		foreach ($_POST as $key => $value) {
			if(!in_array($key,array("content")))
			if(isset($_SESSION['system_info'][$key])){
				$value = str_replace("'", "&apos;", $value);
				$qry = $this->conn->query("UPDATE system_info set meta_value = '{$value}' where meta_field = '{$key}' ");
			}else{
				$qry = $this->conn->query("INSERT into system_info set meta_value = '{$value}', meta_field = '{$key}' ");
			}
		}
		if(isset($_POST['content']))
		foreach($_POST['content'] as $k => $v){
			file_put_contents("../{$k}.html",$v);

		}
		
		if(isset($_FILES['img']) && $_FILES['img']['tmp_name'] != ''){
			$fname = 'uploads/logo-'.(time()).'.png';
			$dir_path =base_app. $fname;
			$upload = $_FILES['img']['tmp_name'];
			$type = mime_content_type($upload);
			$allowed = array('image/png','image/jpeg');
			if(!in_array($type,$allowed)){
				$resp['msg'].=" But Image failed to upload due to invalid file type.";
			}else{
				$new_height = 200; 
				$new_width = 200; 
		
				list($width, $height) = getimagesize($upload);
				$t_image = imagecreatetruecolor($new_width, $new_height);
				imagealphablending( $t_image, false );
				imagesavealpha( $t_image, true );
				$gdImg = ($type == 'image/png')? imagecreatefrompng($upload) : imagecreatefromjpeg($upload);
				imagecopyresampled($t_image, $gdImg, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
				if($gdImg){
						if(is_file($dir_path))
						unlink($dir_path);
						$uploaded_img = imagepng($t_image,$dir_path);
						imagedestroy($gdImg);
						imagedestroy($t_image);
				}else{
				$resp['msg'].=" But Image failed to upload due to unkown reason.";
				}
			}
			if(isset($uploaded_img) && $uploaded_img == true){
				if(isset($_SESSION['system_info']['logo'])){
					$qry = $this->conn->query("UPDATE system_info set meta_value = '{$fname}' where meta_field = 'logo' ");
					if(is_file(base_app.$_SESSION['system_info']['logo'])) unlink(base_app.$_SESSION['system_info']['logo']);
				}else{
					$qry = $this->conn->query("INSERT into system_info set meta_value = '{$fname}',meta_field = 'logo' ");
				}
				unset($uploaded_img);
			}
		}
		if(isset($_FILES['cover']) && $_FILES['cover']['tmp_name'] != ''){
			$fname = 'uploads/cover-'.time().'.png';
			$dir_path =base_app. $fname;
			$upload = $_FILES['cover']['tmp_name'];
			$type = mime_content_type($upload);
			$allowed = array('image/png','image/jpeg');
			if(!in_array($type,$allowed)){
				$resp['msg'].=" But Image failed to upload due to invalid file type.";
			}else{
				$new_height = 720; 
				$new_width = 1280; 
		
				list($width, $height) = getimagesize($upload);
				$t_image = imagecreatetruecolor($new_width, $new_height);
				$gdImg = ($type == 'image/png')? imagecreatefrompng($upload) : imagecreatefromjpeg($upload);
				imagecopyresampled($t_image, $gdImg, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
				if($gdImg){
						if(is_file($dir_path))
						unlink($dir_path);
						$uploaded_img = imagepng($t_image,$dir_path);
						imagedestroy($gdImg);
						imagedestroy($t_image);
				}else{
				$resp['msg'].=" But Image failed to upload due to unkown reason.";
				}
			}
			if(isset($uploaded_img) && $uploaded_img == true){
				if(isset($_SESSION['system_info']['cover'])){
					$qry = $this->conn->query("UPDATE system_info set meta_value = '{$fname}' where meta_field = 'cover' ");
					if(is_file(base_app.$_SESSION['system_info']['cover'])) unlink(base_app.$_SESSION['system_info']['cover']);
				}else{
					$qry = $this->conn->query("INSERT into system_info set meta_value = '{$fname}',meta_field = 'cover' ");
				}
				unset($uploaded_img);
			}
		}
		
		$update = $this->update_system_info();
		$flash = $this->set_flashdata('success','System Info Successfully Updated.');
		if($update && $flash){
			return true;
		}
	}
	function set_userdata($field='',$value=''){
		if(!empty($field) && !empty($value)){
			$_SESSION['userdata'][$field]= $value;
		}
	}
	function userdata($field = ''){
		if(!empty($field)){
			if(isset($_SESSION['userdata'][$field]))
				return $_SESSION['userdata'][$field];
			else
				return null;
		}else{
			return false;
		}
	}

	function active_tenant_id(){
		if(isset($_SESSION['userdata']['active_tenant_id']) && $_SESSION['userdata']['active_tenant_id'] > 0){
			return (int)$_SESSION['userdata']['active_tenant_id'];
		}
		if(isset($_SESSION['userdata']['tenant_id']) && $_SESSION['userdata']['tenant_id'] > 0){
			return (int)$_SESSION['userdata']['tenant_id'];
		}
		return 1;
	}

	function set_active_tenant($tenant_id){
		if($tenant_id > 0){
			$_SESSION['userdata']['active_tenant_id'] = (int)$tenant_id;
			return true;
		}
		return false;
	}
	function set_flashdata($flash='',$value=''){
		if(!empty($flash) && !empty($value)){
			$_SESSION['flashdata'][$flash]= $value;
		return true;
		}
	}
	function chk_flashdata($flash = ''){
		if(isset($_SESSION['flashdata'][$flash])){
			return true;
		}else{
			return false;
		}
	}
	function flashdata($flash = ''){
		if(!empty($flash)){
			$_tmp = isset($_SESSION['flashdata'][$flash]) ? $_SESSION['flashdata'][$flash] : null;
			if(isset($_SESSION['flashdata'][$flash])){
				unset($_SESSION['flashdata'][$flash]);
			}
			return $_tmp;
		}else{
			return false;
		}
	}
	function sess_des(){
		if(isset($_SESSION['userdata'])){
				unset($_SESSION['userdata']);
			return true;
		}
			return true;
	}
	function info($field=''){
		if(!empty($field)){
			if(isset($_SESSION['system_info'][$field]))
				return $_SESSION['system_info'][$field];
			else
				return false;
		}else{
			return false;
		}
	}
	function set_info($field='',$value=''){
		if(!empty($field) && !empty($value)){
			$_SESSION['system_info'][$field] = $value;
		}
	}

	function get_user_role(){
		$role_id = $this->userdata('role_id');
		$user_type = $this->userdata('type');

		if($user_type == 1){
			return ['name' => 'Super Administrator', 'permissions' => ['*']];
		}

		if($role_id > 0){
			$tenant_id = $this->active_tenant_id();
			$qry = $this->conn->query("SELECT * FROM `roles` WHERE id = '{$role_id}' AND tenant_id = '{$tenant_id}' AND status = 1 AND delete_flag = 0");
			if($qry && $qry->num_rows > 0){
				$role = $qry->fetch_assoc();
				$perms = !empty($role['permissions']) ? explode(',', $role['permissions']) : [];
				return ['name' => $role['name'], 'permissions' => $perms];
			}
		}

		return ['name' => 'Standard Staff', 'permissions' => ['journals_view', 'invoices_view', 'payments_view', 'products_view', 'sales_orders_view', 'employees_view', 'payroll_view', 'reports_view']];
	}

	function can_access($permission_key){
		$user_type = $this->userdata('type');
		if($user_type == 1) return true;

		$role = $this->get_user_role();
		$perms = $role['permissions'];

		if(in_array('*', $perms) || in_array($permission_key, $perms)){
			return true;
		}

		return false;
	}

	function csrf_token(){
		if(empty($_SESSION['csrf_token'])){
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['csrf_token'];
	}

	function verify_csrf(){
		if($_SERVER['REQUEST_METHOD'] === 'POST'){
			$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
			if(empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)){
				return false;
			}
		}
		return true;
	}

	function send_test_email(){
		require_once(__DIR__ . '/Emailer.php');
		$to = isset($_POST['test_email']) ? trim($_POST['test_email']) : '';
		if(empty($to)){
			return json_encode(['status' => 'failed', 'msg' => 'Please enter a test email address.']);
		}

		$body = "
		<h3>Nuvis ERPX Email Test</h3>
		<p>Congratulations! Your email notification settings are working properly.</p>
		<p><b>SMTP Host:</b> " . htmlspecialchars($this->info('smtp_host')) . "<br>
		<b>SMTP Port:</b> " . htmlspecialchars($this->info('smtp_port')) . "</p>";

		$send = Emailer::send_email($to, "Nuvis ERPX - Test Email", $body);
		if($send){
			return json_encode(['status' => 'success', 'msg' => "Test email successfully sent to {$to}."]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => "Failed to send test email. Please check your email configuration."]);
		}
	}

	function test_redis_connection(){
		require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
		$redis = new \App\Services\RedisCacheService($this);
		$ping = $redis->ping();
		if($ping['success']){
			return json_encode(['status' => 'success', 'msg' => $ping['message']]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => $ping['message']]);
		}
	}

	function flush_redis_cache(){
		require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
		$redis = new \App\Services\RedisCacheService($this);
		if($redis->flush()){
			return json_encode(['status' => 'success', 'msg' => 'Redis cache database successfully flushed.']);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Failed to flush Redis cache or Redis is disconnected.']);
		}
	}

	function log_audit($action, $module, $details = ''){
		$tenant_id = $this->active_tenant_id();
		$user_id = $this->userdata('id') ? intval($this->userdata('id')) : "NULL";
		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

		$action_esc = $this->conn->real_escape_string($action);
		$module_esc = $this->conn->real_escape_string($module);
		$details_esc = $this->conn->real_escape_string($details);
		$ip_esc = $this->conn->real_escape_string($ip);

		$sql = "INSERT INTO `audit_logs` (`tenant_id`, `user_id`, `action`, `module`, `details`, `ip_address`)
		        VALUES ('{$tenant_id}', {$user_id}, '{$action_esc}', '{$module_esc}', '{$details_esc}', '{$ip_esc}')";
		@$this->conn->query($sql);
	}
}
$_settings = new SystemSettings();
$_settings->load_system_info();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$sysset = new SystemSettings();
switch ($action) {
	case 'update_settings':
		echo $sysset->update_settings_info();
		break;
	case 'send_test_email':
		echo $sysset->send_test_email();
		break;
	case 'test_redis_connection':
		echo $sysset->test_redis_connection();
		break;
	case 'flush_redis_cache':
		echo $sysset->flush_redis_cache();
		break;
	case 'switch_tenant':
		if(isset($_POST['tenant_id'])){
			$sysset->set_active_tenant($_POST['tenant_id']);
			echo json_encode(['status' => 'success']);
		}
		break;
	default:
		// echo $sysset->index();
		break;
}
?>