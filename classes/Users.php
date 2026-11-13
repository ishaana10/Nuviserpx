<?php
require_once('../config.php');
require_once(__DIR__ . '/Emailer.php'); // loaded lazily elsewhere; needed here for verification emails
Class Users extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;
		parent::__construct();
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function save_users(){
		$this->settings->require_login();
		$this->settings->require_csrf();

		$self_id       = (int)$this->settings->userdata('id');
		$is_super      = $this->settings->is_super_admin();
		$active_tenant = (int)$this->settings->active_tenant_id();

		$id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$is_self = ($id > 0 && $id === $self_id);
		$is_new  = ($id === 0);

		// Authorisation: anyone may edit their OWN profile; creating a new user
		// or editing someone else requires the users_manage permission.
		if(!$is_self && !$this->settings->can_access('users_manage')){
			$this->settings->deny("You don't have permission to manage system users.");
		}

		// Load the existing record (for edits) so we can enforce tenant scope
		// and protect privileged accounts.
		$target = null;
		if(!$is_new){
			$t_qry = $this->conn->query("SELECT `id`,`type`,`tenant_id`,`email`,`email_verified_at` FROM `users` WHERE id = {$id}");
			$target = ($t_qry && $t_qry->num_rows > 0) ? $t_qry->fetch_assoc() : null;
			if($target === null){
				return 2; // nothing to update
			}
			// A non-super admin can neither touch accounts outside their tenant
			// nor modify a super-administrator account.
			if(!$is_super){
				if((int)$target['tenant_id'] !== $active_tenant){
					$this->settings->deny('That user belongs to a different company.');
				}
				if((int)$target['type'] === 1){
					$this->settings->deny('You cannot modify a super-administrator account.');
				}
			}
		}

		// Self password change: verify the current password first.
		if(isset($_POST['oldpassword'])){
			$curr_pass = $this->settings->userdata('password');
			if (!password_verify($_POST['oldpassword'], $curr_pass)) {
				return 4;
			}
		}

		// Username uniqueness (escaped; excludes the record being edited).
		$username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
		$username_esc = $this->conn->real_escape_string($username);
		$chk = $this->conn->query("SELECT id FROM `users` WHERE username = '{$username_esc}' ".($id>0? " AND id != {$id} " : ""))->num_rows;
		if($chk > 0){
			return 3;
		}

		/* ---- Registered email: required on new accounts, always validated,
		   unique, and re-verified whenever it changes. ---- */
		$email_posted = array_key_exists('email', $_POST);
		$email = $email_posted ? strtolower(trim((string)$_POST['email'])) : null;
		if($is_new && ($email === null || $email === '')){
			return 5; // a new user must register an email address
		}
		if($email !== null && $email !== ''){
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
				return 5;
			}
			$email_esc = $this->conn->real_escape_string($email);
			$e_chk = $this->conn->query("SELECT id FROM `users` WHERE `email` = '{$email_esc}' ".($id>0? " AND id != {$id} " : ""));
			if($e_chk && $e_chk->num_rows > 0){
				return 6; // email already registered to another user
			}
		}
		$old_email = strtolower(trim((string)($target['email'] ?? '')));
		$email_changed = $email_posted && $email !== '' && $email !== $old_email;

		/* ---- Decide which fields this caller is allowed to write ---- */
		// Everyone may set their basic profile fields.
		$allowed = array('firstname','middlename','lastname','username');
		if($email_posted){
			$_POST['email'] = $email;
			$allowed[] = 'email';
		}
		// Only user administrators may set privilege/assignment fields, and
		// never on their own account (prevents self-escalation / self-lockout).
		$can_admin_fields = !$is_self && ($is_super || $this->settings->can_access('users_manage'));
		if($can_admin_fields){
			$allowed = array_merge($allowed, array('type','role_id','tenant_id','employee_id','status'));
		}

		// Resolve the tenant this user will belong to.
		if($is_super && $can_admin_fields && !empty($_POST['tenant_id'])){
			$target_tenant = (int)$_POST['tenant_id'];
		}elseif(!$is_new){
			$target_tenant = (int)$target['tenant_id'];
		}else{
			$target_tenant = $active_tenant;
		}

		// Normalise privilege level: only a super admin may grant type 1.
		if($can_admin_fields){
			$req_type = isset($_POST['type']) ? (int)$_POST['type'] : 2;
			$_POST['type'] = ($is_super && $req_type === 1) ? 1 : 2;
			$_POST['tenant_id'] = $target_tenant;

			// A staff account must not set a default status implicitly to 0.
			if(!isset($_POST['status'])){
				$_POST['status'] = 1;
			}

			// Validate role_id belongs to the resolved tenant, else clear it.
			if(!empty($_POST['role_id']) && (int)$_POST['role_id'] > 0){
				$rid = (int)$_POST['role_id'];
				$r_ok = $this->conn->query("SELECT id FROM `roles` WHERE id = {$rid} AND tenant_id = {$target_tenant} AND delete_flag = 0");
				if(!$r_ok || $r_ok->num_rows === 0){ $_POST['role_id'] = 0; }
			}
			// Validate employee_id belongs to the resolved tenant, else clear it.
			if(!empty($_POST['employee_id']) && (int)$_POST['employee_id'] > 0){
				$eid = (int)$_POST['employee_id'];
				$e_ok = $this->conn->query("SELECT id FROM `employee_list` WHERE id = {$eid} AND tenant_id = {$target_tenant} AND delete_flag = 0");
				if(!$e_ok || $e_ok->num_rows === 0){ $_POST['employee_id'] = 0; }
			}
			// A super admin (type 1) has no scoped role.
			if((int)$_POST['type'] === 1){ $_POST['role_id'] = 0; }
		}

		$data = '';
		foreach($_POST as $k => $v){
			if(in_array($k, $allowed, true)){
				if(!empty($data)) $data .=" , ";
				if($k === 'email' && $v === ''){
					$data .= " `email` = NULL , `email_verified_at` = NULL , `email_verify_token` = NULL , `email_verify_expires` = NULL ";
				}elseif(in_array($k, array('role_id','employee_id'), true) && (empty($v) || $v == '0')){
					$data .= " `{$k}` = NULL ";
				}elseif(in_array($k, array('type','status','role_id','employee_id','tenant_id'), true)){
					$data .= " `{$k}` = '".(int)$v."' ";
				}else{
					$v_escaped = $this->conn->real_escape_string((string)$v);
					$data .= " `{$k}` = '{$v_escaped}' ";
				}
			}
		}

		// A new or changed address is unverified until the link in the email
		// is clicked. The token is stored hashed; only the recipient gets it.
		$verify_token = null;
		if($email_changed){
			$verify_token = bin2hex(random_bytes(32));
			$token_hash = hash('sha256', $verify_token);
			if(!empty($data)) $data .=" , ";
			$data .= " `email_verified_at` = NULL , `email_verify_token` = '{$token_hash}' , `email_verify_expires` = DATE_ADD(NOW(), INTERVAL 48 HOUR) ";
		}

		$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
		if($password !== ''){
			$password_hash = password_hash($password, PASSWORD_BCRYPT);
			if(!empty($data)) $data .=" , ";
			$data .= " `password` = '{$password_hash}' ";
		}

		if($data === ''){
			return 2; // nothing to write
		}

		if($is_new){
			// New accounts are always scoped to the resolved tenant. For an
			// administrator the allowed-fields loop above already wrote
			// tenant_id, so only add it here when it is missing — writing the
			// same column twice in an INSERT ... SET is a fatal SQL error.
			if(strpos($data, '`tenant_id`') === false){
				$data .= " , `tenant_id` = '{$target_tenant}' ";
			}
			$qry = $this->conn->query("INSERT INTO users SET {$data}");
			if($qry){
				$id = (int)$this->conn->insert_id;
				$this->settings->set_flashdata('success','User Details successfully saved.');
				$this->settings->log_audit('Create User','User Management',"Created user '{$username}' (#{$id}).");
				$resp['status'] = 1;
			}else{
				$resp['status'] = 2;
			}
		}else{
			// Tenant-scope the update for non-super admins.
			$scope = $is_super ? "" : " AND tenant_id = {$active_tenant} ";
			$qry = $this->conn->query("UPDATE users SET {$data} WHERE id = {$id} {$scope}");
			if($qry){
				$this->settings->set_flashdata('success','User Details successfully updated.');
				$this->settings->log_audit('Update User','User Management',"Updated user #{$id}.");
				if($is_self){
					// Refresh only the editable profile fields in the session.
					foreach(array('firstname','middlename','lastname','username') as $k){
						if(isset($_POST[$k])){
							$this->settings->set_userdata($k,$_POST[$k]);
						}
					}
				}
				$resp['status'] = 1;
			}else{
				$resp['status'] = 2;
			}
		}

		// Deliver the verification / welcome email now that the token is stored.
		if($verify_token !== null && isset($resp['status']) && $resp['status'] == 1){
			$verify_url = base_url . 'verify_email.php?token=' . $verify_token;
			$sys = ['name' => $this->settings->info('name') ?: 'Nuvis ERPX'];
			$name_to = trim((string)($_POST['firstname'] ?? '')) ?: $username;
			$sent = false;
			try {
				$sent = (bool)\Emailer::send_email_verification($email, $name_to, $username, $verify_url, $sys, $is_new);
			} catch (\Throwable $e) {
				error_log('[users] verification email failed: ' . $e->getMessage());
			}
			$this->settings->set_flashdata('success', $sent
				? ($is_new ? 'User saved. A welcome email with a verification link was sent to '.$email.'.' : 'Saved. A verification link was sent to '.$email.'.')
				: 'Saved, but the verification email could not be sent. Check the SMTP settings, then use "Resend verification email".');
		}

		// Persist the leave approver onto this user's linked employee record.
		// Only user administrators may set it, and only when a linked employee
		// exists. Blank = administrator approves by default (NULL).
		if($can_admin_fields && (isset($resp['status']) && $resp['status'] == 1)){
			$linked_emp = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
			if($linked_emp > 0){
				$approver = isset($_POST['approver_id']) ? (int)$_POST['approver_id'] : 0;
				// An employee cannot be their own approver, and the approver must
				// belong to the same tenant.
				if($approver > 0 && $approver !== $linked_emp){
					$a_ok = $this->conn->query("SELECT id FROM `employee_list` WHERE id = {$approver} AND tenant_id = {$target_tenant} AND delete_flag = 0");
					$approver_sql = ($a_ok && $a_ok->num_rows) ? "'{$approver}'" : "NULL";
				}else{
					$approver_sql = "NULL";
				}
				$this->conn->query("UPDATE `employee_list` SET `approver_id` = {$approver_sql} WHERE id = {$linked_emp} AND tenant_id = {$target_tenant}");
			}
		}

		if(isset($_FILES['img']) && $_FILES['img']['tmp_name'] != ''){
			$fname = 'uploads/avatar-'.$id.'.png';
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
			if(isset($uploaded_img)){
				$this->conn->query("UPDATE users set `avatar` = CONCAT('{$fname}','?v=',unix_timestamp(CURRENT_TIMESTAMP)) where id = '{$id}' ");
				if($id == $this->settings->userdata('id')){
						$this->settings->set_userdata('avatar',$fname);
				}
			}
		}
		if(isset($resp['msg']))
		$this->settings->set_flashdata('success',$resp['msg']);
		return  $resp['status'];
	}
	public function delete_users(){
		$this->settings->require_permission('users_manage');
		$this->settings->require_csrf();

		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$self_id = (int)$this->settings->userdata('id');
		$is_super = $this->settings->is_super_admin();
		$active_tenant = (int)$this->settings->active_tenant_id();

		if($id <= 0 || $id === 1 || $id === $self_id){
			return json_encode(['status' => 'failed', 'msg' => 'This account cannot be deleted.']);
		}

		$row = $this->conn->query("SELECT `avatar`,`type`,`tenant_id` FROM users WHERE id = {$id}");
		$row = ($row && $row->num_rows > 0) ? $row->fetch_assoc() : null;
		if($row === null){
			return json_encode(['status' => 'failed', 'msg' => 'User not found.']);
		}

		// A non-super admin may only delete non-privileged users in their tenant.
		if(!$is_super){
			if((int)$row['tenant_id'] !== $active_tenant || (int)$row['type'] === 1){
				return json_encode(['status' => 'failed', 'msg' => 'You are not allowed to delete that user.']);
			}
		}

		$scope = $is_super ? "" : " AND tenant_id = {$active_tenant} ";
		$qry = $this->conn->query("DELETE FROM users WHERE id = {$id} {$scope}");
		if($qry && $this->conn->affected_rows > 0){
			$avatar = explode("?", (string)$row['avatar'])[0];
			$this->settings->set_flashdata('success','User Details successfully deleted.');
			$this->settings->log_audit('Delete User','User Management',"Deleted user #{$id}.");
			if(!empty($avatar) && is_file(base_app.$avatar))
				unlink(base_app.$avatar);
			$resp['status'] = 'success';
		}else{
			$resp['status'] = 'failed';
		}
		return json_encode($resp);
	}
	private function guardClient(int $targetId){
		$this->settings->require_login();
		$this->settings->require_csrf();
		$staff = $this->settings->userdata('login_type') == 1;
		$err = \App\Support\ClientAccess::error(true, $staff, $staff && $this->settings->can_access('users_manage'), (int)$this->settings->userdata('id'), $targetId);
		if($err !== null){
			echo json_encode(['status'=>'failed','msg'=>$err]);
			exit;
		}
	}

	public function save_client(){
		$oldpassword = $_POST['oldpassword'] ?? null;
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$this->guardClient($id);
		$email = $_POST['email'] ?? null;
		$password = $_POST['password'] ?? null;
		$data = '';
		$self_edit = $this->settings->userdata('login_type') != 1;
		if($self_edit && !empty($password)){
			// A client changing their own password has to prove they know the current one.
			$curr_pass = $this->settings->userdata('password');
			if (empty($oldpassword) || !password_verify((string)$oldpassword, (string)$curr_pass)) {
				return json_encode(array("status"=>'failed',
										 "msg"=>'Old Password is Incorrect'));
			}
		}
		$email_esc = $this->conn->real_escape_string((string)($email ?? ''));
		$chk = $this->conn->query("SELECT id FROM `client_list` where email ='{$email_esc}' ".($id>0? " and id != {$id} " : ""))->num_rows;
		if($chk > 0){
			return 3;
		}
		foreach(\App\Support\ClientAccess::pick($_POST) as $k => $v){
			if(!empty($data)) $data .=" , ";
			$v_escaped = $this->conn->real_escape_string($v);
			$data .= " `{$k}` = '{$v_escaped}' ";
		}
		if(!empty($password)){
			$password_hash = password_hash($password, PASSWORD_BCRYPT);
			if(!empty($data)) $data .=" , ";
			$data .= " `password` = '{$password_hash}' ";
		}

		if(empty($data)){
			return json_encode(array("status"=>'failed',"msg"=>'There is nothing to save.'));
		}
		if(empty($id)){
			$qry = $this->conn->query("INSERT INTO client_list set {$data}");
			if($qry){
				$id = $this->conn->insert_id;
				$this->settings->set_flashdata('success','User Details successfully saved.');
				$resp['status'] = "success";
			}else{
				$resp['status'] = "failed";
				$resp['msg'] = "An error occurred while saving the data. Error: ". $this->conn->error;
			}

		}else{
			$qry = $this->conn->query("UPDATE client_list set $data where id = {$id}");
			if($qry){
				$this->settings->set_flashdata('success','User Details successfully updated.');
				if($id == $this->settings->userdata('id') && $self_edit){
					foreach(\App\Support\ClientAccess::pick($_POST) as $k => $v){
						$this->settings->set_userdata($k,$v);
					}
				}
				$resp['status'] = "success";
			}else{
				$resp['status'] = "failed";
				$resp['msg'] = "An error occurred while saving the data. Error: ". $this->conn->error;
			}
			
		}
		
		if(isset($_FILES['img']) && $_FILES['img']['tmp_name'] != ''){
			$fname = 'uploads/clients/'.$id.'.png';
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
			if(isset($uploaded_img)){
				$this->conn->query("UPDATE client_list set `avatar` = CONCAT('{$fname}','?v=',unix_timestamp(CURRENT_TIMESTAMP)) where id = '{$id}' ");
				if($id == $this->settings->userdata('id')){
						$this->settings->set_userdata('avatar',$fname);
				}
			}
		}
		
		return  json_encode($resp);
	}
	public function delete_client(){
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$this->settings->require_permission('users_manage');
		$this->settings->require_csrf();
		$avatar = $this->conn->query("SELECT avatar FROM client_list where id = {$id}")->fetch_array()['avatar'];
		$qry = $this->conn->query("DELETE FROM client_list where id = {$id}");
		if($qry){
			$avatar = explode("?",$avatar)[0];
			$this->settings->set_flashdata('success','User Details successfully deleted.');
			if(is_file(base_app.$avatar))
				unlink(base_app.$avatar);
			$resp['status'] = 'success';
		}else{
			$resp['status'] = 'failed';
		}
		return json_encode($resp);
	}
	public function verify_client(){
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$this->settings->require_permission('users_manage');
		$this->settings->require_csrf();
		$update = $this->conn->query("UPDATE `client_list` set `status` = 1 where id = {$id}");
		if($update){
			$this->settings->set_flashdata('success','client Account has verified successfully.');
			$resp['status'] = 'success';
		}else{
			$resp['status'] = 'failed';
		}
		return json_encode($resp);
	}

	/**
	 * Return the roles and employees for a tenant, for the dynamic dropdowns
	 * on the user form. Super admins may request any tenant; everyone else is
	 * locked to their own tenant.
	 */
	public function get_tenant_options(){
		$this->settings->require_permission('users_manage');

		$is_super  = $this->settings->is_super_admin();
		$tenant_id = ($is_super && !empty($_POST['tenant_id']))
			? (int)$_POST['tenant_id']
			: (int)$this->settings->active_tenant_id();

		$roles = [];
		$rq = $this->conn->query("SELECT id, name FROM `roles` WHERE tenant_id = {$tenant_id} AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
		if($rq){ while($r = $rq->fetch_assoc()){ $roles[] = ['id' => (int)$r['id'], 'name' => $r['name']]; } }

		$employees = [];
		$eq = $this->conn->query("SELECT id, employee_code, firstname, lastname FROM `employee_list` WHERE tenant_id = {$tenant_id} AND delete_flag = 0 ORDER BY firstname ASC");
		if($eq){ while($e = $eq->fetch_assoc()){ $employees[] = ['id' => (int)$e['id'], 'label' => trim($e['employee_code'].' - '.$e['firstname'].' '.$e['lastname'])]; } }

		return json_encode(['status' => 'success', 'tenant_id' => $tenant_id, 'roles' => $roles, 'employees' => $employees]);
	}

	/**
	 * Change the current user's own password.
	 * Requires the current (old) password for verification.
	 * No special permission needed — any logged-in user can change their own password.
	 */
	public function change_password(){
		$this->settings->require_login();

		$user_id = (int)$this->settings->userdata('id');
		$current = $_POST['current_password'] ?? '';
		$new     = $_POST['new_password'] ?? '';
		$confirm = $_POST['confirm_password'] ?? '';

		if(empty($current) || empty($new) || empty($confirm)){
			return json_encode(['status' => 'failed', 'msg' => 'All fields are required.']);
		}
		if($new !== $confirm){
			return json_encode(['status' => 'failed', 'msg' => 'New password and confirmation do not match.']);
		}
		if(strlen($new) < 6){
			return json_encode(['status' => 'failed', 'msg' => 'Password must be at least 6 characters.']);
		}

		// Verify current password
		$qry = $this->conn->query("SELECT `password` FROM `users` WHERE id = '{$user_id}'");
		if(!$qry || $qry->num_rows === 0){
			return json_encode(['status' => 'failed', 'msg' => 'User not found.']);
		}
		$row = $qry->fetch_assoc();
		if(!password_verify($current, $row['password']) && md5($current) !== $row['password']){
			return json_encode(['status' => 'failed', 'msg' => 'Current password is incorrect.']);
		}

		// Update password
		$hashed = password_hash($new, PASSWORD_DEFAULT);
		$update = $this->conn->query("UPDATE `users` SET `password` = '{$hashed}' WHERE id = '{$user_id}'");
		if($update){
			$this->settings->log_audit('Change Password', 'User Management', "User #{$user_id} changed their own password.");
			return json_encode(['status' => 'success', 'msg' => 'Password updated successfully.']);
		}
		return json_encode(['status' => 'failed', 'msg' => 'Failed to update password.']);
	}

	/** Activate (verify) a pending system user account. */
	/** Re-issue the email verification link (own account, or users_manage). */
	public function resend_verification(){
		$this->settings->require_login();
		$this->settings->require_csrf();
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$self_id = (int)$this->settings->userdata('id');
		if($id <= 0) $id = $self_id;
		if($id !== $self_id && !$this->settings->can_access('users_manage')){
			$this->settings->deny("You don't have permission to manage system users.");
		}
		$scope = ($this->settings->is_super_admin() || $id === $self_id) ? "" : " AND tenant_id = ".(int)$this->settings->active_tenant_id();
		$q = $this->conn->query("SELECT id, firstname, username, email, email_verified_at FROM `users` WHERE id = {$id} {$scope}");
		$u = ($q && $q->num_rows) ? $q->fetch_assoc() : null;
		if(!$u || empty($u['email'])){
			return json_encode(['status' => 'failed', 'msg' => 'This account has no email address registered.']);
		}
		if(!empty($u['email_verified_at'])){
			return json_encode(['status' => 'failed', 'msg' => 'This email address is already verified.']);
		}
		$token = bin2hex(random_bytes(32));
		$hash = hash('sha256', $token);
		$this->conn->query("UPDATE `users` SET `email_verify_token` = '{$hash}', `email_verify_expires` = DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id = ".(int)$u['id']);
		$sys = ['name' => $this->settings->info('name') ?: 'Nuvis ERPX'];
		$sent = false;
		try {
			$sent = (bool)\Emailer::send_email_verification($u['email'], $u['firstname'] ?: $u['username'], $u['username'], base_url.'verify_email.php?token='.$token, $sys, false);
		} catch (\Throwable $e) {
			error_log('[users] resend verification failed: ' . $e->getMessage());
		}
		$this->settings->log_audit('Resend Email Verification','User Management',"Verification link re-sent for user #{$u['id']}.");
		return json_encode($sent
			? ['status' => 'success', 'msg' => 'Verification email sent to '.$u['email'].'.']
			: ['status' => 'failed', 'msg' => 'The email could not be sent. Check the SMTP settings under System Settings.']);
	}

	public function verify_user(){
		$this->settings->require_permission('users_manage');
		$this->settings->require_csrf();

		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$is_super = $this->settings->is_super_admin();
		$active_tenant = (int)$this->settings->active_tenant_id();

		if($id <= 0){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid user.']);
		}

		$scope = $is_super ? "" : " AND tenant_id = {$active_tenant} ";
		$update = $this->conn->query("UPDATE `users` SET `status` = 1 WHERE id = {$id} {$scope}");
		if($update && $this->conn->affected_rows >= 0){
			$this->settings->set_flashdata('success','User account verified successfully.');
			$this->settings->log_audit('Verify User','User Management',"Verified user #{$id}.");
			$resp['status'] = 'success';
		}else{
			$resp['status'] = 'failed';
		}
		return json_encode($resp);
	}

}

$users = new users();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
switch ($action) {
	case 'save':
		echo $users->save_users();
	break;
	case 'delete':
		echo $users->delete_users();
	break;
	case 'save_client':
		echo $users->save_client();
	break;
	case 'delete_client':
		echo $users->delete_client();
	break;
	case 'verify_client':
		echo $users->verify_client();
	break;
	case 'verify_user':
		echo $users->verify_user();
	break;
	case 'resend_verification':
		echo $users->resend_verification();
	break;
	case 'change_password':
		echo $users->change_password();
	break;
	case 'get_tenant_options':
		echo $users->get_tenant_options();
	break;
	default:
		// echo $sysset->index();
		break;
}