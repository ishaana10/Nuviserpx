<?php

declare(strict_types=1);

namespace App\Services;

/** Customer portal: invitations, registration, login, activity log. Moved verbatim out of Master. */
class CustomerPortalService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function log_portal_activity($tenant_id, $party_id, $portal_user_id, $action, $details = '')
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip_esc = $this->conn->real_escape_string($ip);
        $action_esc = $this->conn->real_escape_string($action);
        $details_esc = $this->conn->real_escape_string($details);
        $user_id_sql = $portal_user_id ? "'{$portal_user_id}'" : "NULL";

        $sql = "INSERT INTO `customer_portal_logs` (`tenant_id`, `party_id`, `portal_user_id`, `action`, `details`, `ip_address`)
                VALUES ('{$tenant_id}', '{$party_id}', {$user_id_sql}, '{$action_esc}', '{$details_esc}', '{$ip_esc}')";
        @$this->conn->query($sql);
    }

    public function send_customer_invitation()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $party_id  = intval($_POST['party_id'] ?? 0);

        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Customer contact not found.']);
        }

        $party = $party_qry->fetch_assoc();
        $email = trim($party['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_encode(['status' => 'failed', 'msg' => 'Customer does not have a valid email address.']);
        }

        $token = bin2hex(random_bytes(24));
        $token_hash = \App\Support\CustomerPortal::tokenHash($token);
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

        $chk = $this->conn->prepare("SELECT id FROM `customer_portal_users` WHERE tenant_id = ? AND party_id = ?");
        $chk->bind_param('ii', $tenant_id, $party_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $portal_user_id = 0;
        if ($existing) {
            $portal_user_id = (int)$existing['id'];
            // Re-inviting also signs out and disables the old password until the customer activates again.
            $st = $this->conn->prepare("UPDATE `customer_portal_users` SET `email` = ?, `invite_token` = ?, `invite_expires_at` = ?, `status` = 0 WHERE id = ?");
            $st->bind_param('sssi', $email, $token_hash, $expires, $portal_user_id);
        } else {
            $st = $this->conn->prepare("INSERT INTO `customer_portal_users` (`tenant_id`, `party_id`, `email`, `invite_token`, `invite_expires_at`, `status`) VALUES (?, ?, ?, ?, ?, 0)");
            $st->bind_param('iisss', $tenant_id, $party_id, $email, $token_hash, $expires);
        }

        if ($st->execute()) {
            if ($portal_user_id === 0) {
                $portal_user_id = (int)$this->conn->insert_id;
            }

            $invite_url = base_url . "portal/register.php?token=" . $token;
            require_once(base_app . 'classes/Emailer.php');
            $sys = [];
            $sys_qry = $this->conn->query("SELECT meta_field, meta_value FROM `system_info`");
            while ($r = $sys_qry->fetch_assoc()) $sys[$r['meta_field']] = $r['meta_value'];

            \Emailer::send_portal_invitation($email, $party['name'], $invite_url, $sys);

            $this->log_portal_activity($tenant_id, $party_id, $portal_user_id, 'invitation_sent', "Portal invitation sent to {$email}");

            return json_encode([
                'status' => 'success',
                'msg' => 'Portal invitation successfully generated & emailed to ' . $email . '.',
                'invite_url' => $invite_url
            ]);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to generate invitation token.']);
    }

    public function customer_register()
    {
        $token    = trim($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($token) || empty($password)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please fill in all required fields.']);
        }

        if ((string)($_POST['confirm_password'] ?? $password) !== $password) {
            return json_encode(['status' => 'failed', 'msg' => 'The two passwords do not match.']);
        }
        $errs = \App\Support\CustomerPortal::passwordErrors($password);
        if ($errs) {
            return json_encode(['status' => 'failed', 'msg' => $errs[0]]);
        }

        $throttle = new \App\Services\AuthThrottleService($this->conn);
        $wait = $throttle->check('customer_register', $_SERVER['REMOTE_ADDR'] ?? 'cli');
        if ($wait > 0) {
            return json_encode(['status' => 'locked', 'retry_after' => $wait,
                'msg' => 'Too many attempts. Try again in ' . \App\Services\AuthThrottleService::waitText($wait) . '.']);
        }

        // Tokens are stored hashed. Links sent before hashing was introduced are plain in the database and are still
        // accepted until they expire (7 days at most).
        $hash = \App\Support\CustomerPortal::tokenHash($token);
        $q = $this->conn->prepare("SELECT * FROM `customer_portal_users` WHERE (`invite_token` = ? OR `invite_token` = ?) AND `invite_expires_at` >= NOW() LIMIT 1");
        $q->bind_param('ss', $hash, $token);
        $q->execute();
        $user = $q->get_result()->fetch_assoc();

        if (!$user) {
            $throttle->record('customer_register', $_SERVER['REMOTE_ADDR'] ?? 'cli');
            return json_encode(['status' => 'failed', 'msg' => 'Invalid or expired invitation link.']);
        }
        $hashed_pass = password_hash($password, PASSWORD_BCRYPT);
        $uid = (int)$user['id'];
        $upd = $this->conn->prepare("UPDATE `customer_portal_users` SET `password` = ?, `status` = 1, `invite_token` = NULL, `invite_expires_at` = NULL WHERE id = ?");
        $upd->bind_param('si', $hashed_pass, $uid);
        if ($upd->execute()) {
            if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
            $_SESSION['customer_portal'] = [
                'id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'party_id' => $user['party_id'],
                'email' => $user['email']
            ];

            $this->log_portal_activity($user['tenant_id'], $user['party_id'], $user['id'], 'account_activated', "Customer registered password and activated portal account");

            return json_encode(['status' => 'success', 'msg' => 'Account successfully created and activated! Redirecting to dashboard...']);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to activate account.']);
    }

    public function customer_login()
    {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please enter your email and password.']);
        }

        $throttle = new \App\Services\AuthThrottleService($this->conn);
        $wait = $throttle->check('customer_login', $email);
        if ($wait > 0) {
            return json_encode(['status' => 'locked', 'retry_after' => $wait,
                'msg' => 'Too many failed attempts. Try again in ' . \App\Services\AuthThrottleService::waitText($wait) . '.']);
        }

        $stmt = $this->conn->prepare("SELECT * FROM `customer_portal_users` WHERE `email` = ? AND `status` = 1 LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); } // a session id planted before sign-in is useless afterwards
            $_SESSION['customer_portal'] = [
                'id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'party_id' => $user['party_id'],
                'email' => $user['email']
            ];

            $this->conn->query("UPDATE `customer_portal_users` SET `last_login` = NOW() WHERE id = '" . (int)$user['id'] . "'");
            $this->log_portal_activity($user['tenant_id'], $user['party_id'], $user['id'], 'customer_login', "Customer logged into portal");
            $throttle->clear('customer_login', $email);

            return json_encode(['status' => 'success', 'msg' => 'Login successful!']);
        }
        if (!$user) {
            // Unknown emails cost the same time as wrong passwords.
            password_verify($password, '$2y$10$4atYI/I54Zf2duldz2eoGeLt85rsTV2yvoQVc7oLbm1JwmpyG6aDK');
        }
        $throttle->record('customer_login', $email);

        return json_encode(['status' => 'failed', 'msg' => 'Invalid email address or password.']);
    }

    public function get_portal_logs()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $party_id  = intval($_POST['party_id'] ?? 0);

        $qry = $this->conn->query("SELECT * FROM `customer_portal_logs` WHERE tenant_id = '{$tenant_id}' AND party_id = '{$party_id}' ORDER BY date_created DESC LIMIT 50");
        $logs = [];
        if ($qry) {
            while ($row = $qry->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        return json_encode(['status' => 'success', 'data' => $logs]);
    }

}
