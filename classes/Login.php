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
	private function throttle(){
		return new \App\Services\AuthThrottleService($this->conn);
	}
	public function login(){
		$username = trim((string)($_POST['username'] ?? ''));
		$password = (string)($_POST['password'] ?? '');
		$throttle = $this->throttle();
		$wait = $throttle->check('login', $username);
		if($wait > 0){
			return json_encode(array('status'=>'locked', 'retry_after'=>$wait,
				'msg'=>'Too many failed attempts. Try again in ' . \App\Services\AuthThrottleService::waitText($wait) . '.'));
		}
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
				$throttle->clear('login', $username);

				// Update last_login timestamp
				$ll = $this->conn->prepare("UPDATE `users` SET `last_login` = NOW() WHERE id = ?");
				$ll->bind_param('i', $res['id']);
				$ll->execute();

				// Record successful login in audit trail
				$this->settings->log_audit("User Login", "Authentication", "User '{$res['username']}' logged in successfully.");

				return json_encode(array('status'=>'success'));
			}
		}

		if(!empty($username)){
			$this->settings->log_audit("Failed Login Attempt", "Authentication", "Failed login attempt for username: " . htmlspecialchars($username));
		}
		// Unknown usernames cost the same time as wrong passwords, and both count towards the limit.
		if($qry->num_rows == 0){
			password_verify($password, '$2y$10$4atYI/I54Zf2duldz2eoGeLt85rsTV2yvoQVc7oLbm1JwmpyG6aDK');
		}
		$throttle->record('login', $username);

		return json_encode(array('status'=>'incorrect'));
	}
	public function logout(){
		$u_name = $this->settings->userdata('username') ?: 'User';
		$this->settings->log_audit("User Logout", "Authentication", "User '{$u_name}' logged out.");
		if($this->settings->sess_des()){
			redirect('admin/login.php');
		}
	}
	function client_login(){
		$email = $_POST['email'] ?? null;
		$password = $_POST['password'] ?? null;
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

	/** Password reset step 1. The answer is the same whether or not the account exists. */
	public function request_otp(){
		$username = trim((string)($_POST['email'] ?? ''));
		$generic = ['status' => 'success', 'msg' => 'If an account exists for that address, a 6-digit code has been sent to it. It is valid for 15 minutes.'];
		if($username === ''){
			return json_encode(['status' => 'failed', 'msg' => 'Enter your username / email.']);
		}
		$throttle = $this->throttle();
		$wait = $throttle->check('otp_request', $username);
		if($wait > 0){
			return json_encode(['status' => 'failed', 'msg' => 'Too many requests. Try again in ' . \App\Services\AuthThrottleService::waitText($wait) . '.']);
		}
		$throttle->record('otp_request', $username);

		$stmt = $this->conn->prepare("SELECT id, firstname, username, status FROM `users` WHERE `username` = ? LIMIT 1");
		$stmt->bind_param('s', $username);
		$stmt->execute();
		$user = $stmt->get_result()->fetch_assoc();
		if(!$user || (int)$user['status'] !== 1 || !filter_var($user['username'], FILTER_VALIDATE_EMAIL)){
			return json_encode($generic);
		}

		$user_id = (int)$user['id'];
		$otp_code = sprintf("%06d", random_int(0, 999999));
		$otp_hash = hash('sha256', $otp_code);
		$expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));
		$to = $user['username'];

		$inv = $this->conn->prepare("UPDATE `otp_tokens` SET `status` = 1 WHERE `user_id` = ?");
		$inv->bind_param('i', $user_id);
		$inv->execute();
		$ins = $this->conn->prepare("INSERT INTO `otp_tokens` (`user_id`, `email`, `otp_code`, `expires_at`, `status`) VALUES (?, ?, ?, ?, 0)");
		$ins->bind_param('isss', $user_id, $to, $otp_hash, $expires_at);
		if($ins->execute()){
			require_once(__DIR__ . '/Emailer.php');
			Emailer::send_auth_otp($to, $user['firstname'], $otp_code);
			$this->settings->log_audit("Password Reset Requested", "Authentication", "Reset code requested for '" . htmlspecialchars($to) . "'.");
		}
		return json_encode($generic);
	}

	/** Password reset step 2: the username, the emailed code and a new password. Five wrong codes kill the code. */
	public function reset_password_otp(){
		$username = trim((string)($_POST['email'] ?? ''));
		$otp_code = trim((string)($_POST['otp_code'] ?? ''));
		$password = (string)($_POST['password'] ?? '');
		$bad = ['status' => 'failed', 'msg' => 'Invalid or expired code.'];

		if(strlen($password) < 8 || strlen($password) > 72){
			return json_encode(['status' => 'failed', 'msg' => 'Choose a password of 8 to 72 characters.']);
		}
		$throttle = $this->throttle();
		$wait = $throttle->check('otp_verify', $username);
		if($wait > 0){
			return json_encode(['status' => 'failed', 'msg' => 'Too many attempts. Try again in ' . \App\Services\AuthThrottleService::waitText($wait) . '.']);
		}

		$stmt = $this->conn->prepare("SELECT id FROM `users` WHERE `username` = ? AND `status` = 1 LIMIT 1");
		$stmt->bind_param('s', $username);
		$stmt->execute();
		$user = $stmt->get_result()->fetch_assoc();
		$token = null;
		if($user){
			$uid = (int)$user['id'];
			$tq = $this->conn->prepare("SELECT id, otp_code, attempts FROM `otp_tokens` WHERE `user_id` = ? AND `status` = 0 AND `expires_at` >= NOW() ORDER BY id DESC LIMIT 1");
			$tq->bind_param('i', $uid);
			$tq->execute();
			$token = $tq->get_result()->fetch_assoc();
		}
		if(!$user || !$token || !preg_match('/^\d{6}$/', $otp_code) || !hash_equals((string)$token['otp_code'], hash('sha256', $otp_code))){
			$throttle->record('otp_verify', $username);
			if($token){
				$tid = (int)$token['id'];
				// Fifth wrong guess: the code is dead and a new one has to be requested.
				$ta = $this->conn->prepare("UPDATE `otp_tokens` SET `attempts` = `attempts` + 1, `status` = IF(`attempts` >= 5, 1, `status`) WHERE `id` = ?");
				$ta->bind_param('i', $tid);
				$ta->execute();
			}
			return json_encode($bad);
		}

		$new_password = password_hash($password, PASSWORD_BCRYPT);
		$up = $this->conn->prepare("UPDATE `users` SET `password` = ? WHERE `id` = ?");
		$up->bind_param('si', $new_password, $uid);
		$up->execute();
		$done = $this->conn->prepare("UPDATE `otp_tokens` SET `status` = 1 WHERE `user_id` = ?");
		$done->bind_param('i', $uid);
		$done->execute();
		$throttle->clear('otp_verify', $username);
		$throttle->clear('login', $username);
		$this->settings->log_audit("Password Reset", "Authentication", "Password reset by emailed code for '" . htmlspecialchars($username) . "'.");
		return json_encode(['status' => 'success', 'msg' => 'Password reset successfully! You can now sign in with your new password.']);
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

