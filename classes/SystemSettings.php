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
		$home = isset($_SESSION['userdata']['tenant_id']) ? (int)$_SESSION['userdata']['tenant_id'] : 0;

		// Tenant isolation: a non-super user is ALWAYS pinned to their own
		// tenant. Any active_tenant_id override is honoured only for super
		// administrators (the developer tier), who may operate across companies.
		$is_super = isset($_SESSION['userdata']['type']) && $_SESSION['userdata']['type'] == 1;
		if(!$is_super){
			return $home > 0 ? $home : 1;
		}

		if(isset($_SESSION['userdata']['active_tenant_id']) && (int)$_SESSION['userdata']['active_tenant_id'] > 0){
			return (int)$_SESSION['userdata']['active_tenant_id'];
		}
		return $home > 0 ? $home : 1;
	}

	function set_active_tenant($tenant_id){
		$tenant_id = (int)$tenant_id;
		if($tenant_id <= 0){
			return false;
		}
		// Only super administrators may hop between companies. A staff user is
		// pinned to the tenant their account belongs to, regardless of what
		// tenant_id is submitted — this preserves multi-tenant data isolation.
		if(!$this->is_super_admin()){
			$home_tenant = (int)$this->userdata('tenant_id');
			if($home_tenant > 0 && $tenant_id !== $home_tenant){
				return false;
			}
		}
		$_SESSION['userdata']['active_tenant_id'] = $tenant_id;
		return true;
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
				// Parse through the registry: expands legacy "<module>_manage"
				// keys and implies "view" wherever a write action is granted.
				$perms = \App\Support\Permissions::parse($role['permissions'] ?? '');
				return ['name' => $role['name'], 'permissions' => $perms];
			}
		}

		return ['name' => 'Standard Staff', 'permissions' => ['invoices_view', 'payments_view', 'products_view', 'sales_orders_view', 'payroll_view', 'leaves_view', 'leaves_create', 'attendance_view']];
	}

	/**
	 * Is a tenant module (e.g. mod_payroll) enabled for the active tenant?
	 * The developer assigns modules per tenant (tenants.modules, CSV; NULL means
	 * "not restricted yet"). A system-wide switch-off (system_info mod_*=0) also
	 * disables it. Super administrators are never blocked.
	 */
	function module_enabled($module_key){
		if($this->userdata('type') == 1) return true;
		if(!isset(\App\Support\Permissions::TENANT_MODULES[$module_key])) return true;
		if($this->info($module_key) === '0') return false;
		static $cache = [];
		$tid = (int)$this->active_tenant_id();
		if(!array_key_exists($tid, $cache)){
			$cache[$tid] = null;
			$q = @$this->conn->query("SELECT `modules` FROM `tenants` WHERE id = '{$tid}'");
			if($q && ($row = $q->fetch_assoc()) && $row['modules'] !== null){
				$cache[$tid] = \App\Support\Permissions::sanitizeTenantModules($row['modules']);
			}
		}
		return $cache[$tid] === null || in_array($module_key, $cache[$tid], true);
	}

	/** The employee profile linked to the logged-in user (0 = none). Read live so re-linking applies at once. */
	function own_employee_id(){
		static $cache = [];
		$uid = (int)$this->userdata('id');
		if($uid <= 0) return 0;
		if(!array_key_exists($uid, $cache)){
			$q = @$this->conn->query("SELECT employee_id FROM `users` WHERE id = '{$uid}'");
			$cache[$uid] = ($q && ($r = $q->fetch_assoc())) ? (int)$r['employee_id'] : 0;
		}
		return $cache[$uid];
	}

	/**
	 * Whose employee records may this user see/submit for a module?
	 * null  = everyone's (super admin or an HR/payroll manager),
	 * int   = only that employee id (0 = account isn't linked to an employee).
	 * Managers hold the module's Edit permission (Payroll: Create or Edit).
	 */
	function employee_scope($module){
		if($this->userdata('type') == 1) return null;
		$manager = $module === 'payroll'
			? ($this->can_access('payroll_create') || $this->can_access('payroll_edit'))
			: $this->can_access($module.'_edit');
		return $manager ? null : $this->own_employee_id();
	}

	function can_access($permission_key){
		$user_type = $this->userdata('type');
		if($user_type == 1) return true;

		// Modules the developer has not assigned to this tenant are off-limits.
		$tenant_module = \App\Support\Permissions::tenantModuleFor((string)$permission_key);
		if($tenant_module !== null && !$this->module_enabled($tenant_module)) return false;

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

	/* ===================================================================
	 * Access-control guards
	 *
	 * These helpers are meant to be called at the very top of a protected
	 * request handler. On failure they emit a short response and terminate
	 * the request, so a caller that returns normally is always authorised.
	 * =================================================================== */

	/** True when the current session belongs to a super administrator. */
	function is_super_admin(){
		return $this->userdata('type') == 1;
	}

	/**
	 * Emit a denial and stop the request.
	 *
	 * @param string $msg  Message shown to the user.
	 * @param string $as   'json' (AJAX endpoints) or 'page' (view includes).
	 * @param int    $code HTTP status code.
	 */
	function deny($msg = 'You are not authorised to perform this action.', $as = 'json', $code = 403){
		if(!headers_sent()){
			http_response_code($code);
		}
		if($as === 'page'){
			echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>'
				. htmlspecialchars($msg) . '</div>';
		}else{
			header('Content-Type: application/json');
			echo json_encode(['status' => 'failed', 'msg' => $msg]);
		}
		exit;
	}

	/** Require an authenticated admin (staff/super) session, else stop. */
	function require_login($as = 'json'){
		if(!$this->userdata('id') || $this->userdata('login_type') != 1){
			$this->deny('Your session has expired. Please sign in again.', $as, 401);
		}
	}

	/** Require a valid CSRF token on state-changing requests, else stop. */
	function require_csrf($as = 'json'){
		if(!$this->verify_csrf()){
			$this->deny('Security token mismatch. Please refresh the page and try again.', $as, 419);
		}
	}

	/**
	 * Require a specific permission (super admins always pass). Also enforces
	 * that the caller is logged in, so handlers need only one guard call.
	 */
	function require_permission($permission_key, $as = 'json'){
		$this->require_login($as);
		if(!$this->can_access($permission_key)){
			$this->deny("You don't have permission to perform this action.", $as, 403);
		}
	}

	/** Require that the current session is a super administrator, else stop. */
	function require_super_admin($as = 'json'){
		$this->require_login($as);
		if(!$this->is_super_admin()){
			$this->deny('This action is restricted to super administrators.', $as, 403);
		}
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
		$sysset->require_permission('settings_manage');
		$sysset->require_csrf();
		echo $sysset->update_settings_info();
		break;
	case 'send_test_email':
		$sysset->require_permission('settings_manage');
		$sysset->require_csrf();
		echo $sysset->send_test_email();
		break;
	case 'test_redis_connection':
		$sysset->require_super_admin();
		echo $sysset->test_redis_connection();
		break;
	case 'flush_redis_cache':
		$sysset->require_super_admin();
		$sysset->require_csrf();
		echo $sysset->flush_redis_cache();
		break;
	case 'switch_tenant':
		$sysset->require_login();
		$sysset->require_csrf();
		if(isset($_POST['tenant_id']) && $sysset->set_active_tenant($_POST['tenant_id'])){
			echo json_encode(['status' => 'success']);
		}else{
			echo json_encode(['status' => 'failed', 'msg' => 'You are not allowed to switch to that company.']);
		}
		break;
	default:
		// echo $sysset->index();
		break;
}
?>