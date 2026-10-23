<?php
require_once '../config.php';
class Login extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;

		parent::__construct();
		ini_set('display_error', 1);
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function index(){
		echo "<h1>Access Denied</h1> <a href='".base_url."'>Go Back.</a>";
	}
	public function login(){
		extract($_POST);
		$stmt = $this->conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
		$stmt->bind_param('s', $username);
		$stmt->execute();
		$qry = $stmt->get_result();

		if($qry->num_rows > 0){
			$res = $qry->fetch_array();
			if($res['status'] != 1){
				return json_encode(array('status'=>'notverified'));
			}

			$db_pass = $res['password'];
			$authenticated = false;

			if (password_verify($password, $db_pass)) {
				$authenticated = true;
				if (password_needs_rehash($db_pass, PASSWORD_BCRYPT)) {
					$new_hash = password_hash($password, PASSWORD_BCRYPT);
					$stmt_u = $this->conn->prepare("UPDATE `users` SET `password` = ? WHERE id = ?");
					$stmt_u->bind_param('si', $new_hash, $res['id']);
					$stmt_u->execute();
				}
			}

			if ($authenticated) {
				session_regenerate_id(true);

				foreach($res as $k => $v){
					if(!is_numeric($k) && $k != 'password'){
						$this->settings->set_userdata($k, $v);
					}
				}
				$this->settings->set_userdata('login_type', 1);

				// Update last_login timestamp
				$this->conn->query("UPDATE `users` SET `last_login` = NOW() WHERE id = '{$res['id']}'");

				// Record successful login in audit trail
				$this->settings->log_audit("User Login", "Authentication", "User '{$res['username']}' logged in successfully.");

				return json_encode(array('status'=>'success'));
			}
		}

		if(!empty($username)){
			$this->settings->log_audit("Failed Login Attempt", "Authentication", "Failed login attempt for username: " . htmlspecialchars($username));
		}

		return json_encode(array('status'=>'incorrect', 'error'=>$this->conn->error));
	}
	public function logout(){
		$u_name = $this->settings->userdata('username') ?: 'User';
		$this->settings->log_audit("User Logout", "Authentication", "User '{$u_name}' logged out.");
		if($this->settings->sess_des()){
			redirect('admin/login.php');
		}
	}
	function client_login(){
		extract($_POST);
		$stmt = $this->conn->prepare("SELECT *,concat(lastname,', ',firstname,' ',middlename) as fullname from client_list where email = ? LIMIT 1");
		$stmt->bind_param('s',$email);
		$stmt->execute();
		$qry = $stmt->get_result();
		if($this->conn->error){
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while fetching data. Error:". $this->conn->error;
		}else{
			if($qry->num_rows > 0){
				$res = $qry->fetch_array();
				if (password_verify($password, $res['password'])) {
					if($res['status'] == 1){
						foreach($res as $k => $v){
							$this->settings->set_userdata($k,$v);
						}
						$this->settings->set_userdata('login_type',2);
						$resp['status'] = 'success';
					}else{
						$resp['status'] = 'failed';
						$resp['msg'] = "Your Account has been blocked. Contact the management.";
					}
				}else{
					$resp['status'] = 'failed';
					$resp['msg'] = "Invalid email or password.";
				}
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "Invalid email or password.";
			}
		}
		return json_encode($resp);
	}
	public function client_logout(){
		if($this->settings->sess_des()){
			redirect('./');
		}
	}

	public function request_otp(){
		extract($_POST);
		$email = $this->conn->real_escape_string($email);
		$qry = $this->conn->query("SELECT * FROM `users` WHERE `username` = '{$email}' LIMIT 1");

		if(!$qry || $qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'No user account found with that username / email.']);
		}

		$user = $qry->fetch_assoc();
		$user_id = $user['id'];
		$otp_code = sprintf("%06d", rand(100000, 999999));
		$expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));

		$this->conn->query("UPDATE `otp_tokens` SET `status` = 1 WHERE `user_id` = '{$user_id}'");
		$ins = $this->conn->query("INSERT INTO `otp_tokens` (`user_id`, `email`, `otp_code`, `expires_at`, `status`) VALUES ('{$user_id}', '{$email}', '{$otp_code}', '{$expires_at}', 0)");

		if($ins){
			require_once(__DIR__ . '/Emailer.php');
			Emailer::send_auth_otp($email, $user['firstname'], $otp_code);

			return json_encode([
				'status' => 'success',
				'msg' => 'An OTP code has been sent to your email address.',
				'user_id' => $user_id
			]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Failed to generate OTP code.']);
		}
	}

	public function reset_password_otp(){
		extract($_POST);
		$user_id = $this->conn->real_escape_string($user_id);
		$otp_code = $this->conn->real_escape_string($otp_code);
		$new_password = password_hash($password, PASSWORD_BCRYPT);

		$qry = $this->conn->query("SELECT * FROM `otp_tokens` WHERE `user_id` = '{$user_id}' AND `otp_code` = '{$otp_code}' AND `status` = 0 AND `expires_at` >= NOW() ORDER BY id DESC LIMIT 1");

		if($qry && $qry->num_rows > 0){
			$token = $qry->fetch_assoc();
			$this->conn->query("UPDATE `users` SET `password` = '{$new_password}' WHERE `id` = '{$user_id}'");
			$this->conn->query("UPDATE `otp_tokens` SET `status` = 1 WHERE `id` = '{$token['id']}'");

			return json_encode(['status' => 'success', 'msg' => 'Password reset successfully! You can now log in with your new password.']);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Invalid or expired OTP code.']);
		}
	}
}
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$auth = new Login();
switch ($action) {
	case 'login':
		echo $auth->login();
		break;
	case 'logout':
		echo $auth->logout();
		break;
	case 'client_login':
		echo $auth->client_login();
		break;
	case 'client_logout':
		echo $auth->client_logout();
		break;
	case 'request_otp':
		echo $auth->request_otp();
		break;
	case 'reset_password_otp':
		echo $auth->reset_password_otp();
		break;
	default:
		echo $auth->index();
		break;
}

