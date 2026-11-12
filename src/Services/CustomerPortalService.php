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
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

        $chk = $this->conn->query("SELECT id FROM `customer_portal_users` WHERE tenant_id = '{$tenant_id}' AND party_id = '{$party_id}'");
        if ($chk && $chk->num_rows > 0) {
            $portal_user = $chk->fetch_assoc();
            $portal_user_id = $portal_user['id'];
            $sql = "UPDATE `customer_portal_users` SET `email` = '{$email}', `invite_token` = '{$token}', `invite_expires_at` = '{$expires}', `status` = 0 WHERE id = '{$portal_user_id}'";
        } else {
            $sql = "INSERT INTO `customer_portal_users` (`tenant_id`, `party_id`, `email`, `invite_token`, `invite_expires_at`, `status`) VALUES ('{$tenant_id}', '{$party_id}', '{$email}', '{$token}', '{$expires}', 0)";
        }

        if ($this->conn->query($sql)) {
            if (empty($portal_user_id)) {
                $portal_user_id = $this->conn->insert_id;
            }

            $invite_url = base_url . "portal/login.php?token=" . $token;
            require_once(__DIR__ . '/Emailer.php');
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

        if (strlen($password) < 6) {
            return json_encode(['status' => 'failed', 'msg' => 'Password must be at least 6 characters long.']);
        }

        $token_esc = $this->conn->real_escape_string($token);
        $qry = $this->conn->query("SELECT * FROM `customer_portal_users` WHERE `invite_token` = '{$token_esc}' AND `invite_expires_at` >= NOW() LIMIT 1");

        if (!$qry || $qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid or expired invitation link.']);
        }

        $user = $qry->fetch_assoc();
        $hashed_pass = password_hash($password, PASSWORD_BCRYPT);
        $pass_esc = $this->conn->real_escape_string($hashed_pass);

        $upd = "UPDATE `customer_portal_users` SET `password` = '{$pass_esc}', `status` = 1, `invite_token` = NULL, `invite_expires_at` = NULL WHERE id = '{$user['id']}'";
        if ($this->conn->query($upd)) {
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

        $email_esc = $this->conn->real_escape_string($email);
        $qry = $this->conn->query("SELECT * FROM `customer_portal_users` WHERE `email` = '{$email_esc}' AND `status` = 1 LIMIT 1");

        if ($qry && $qry->num_rows > 0) {
            $user = $qry->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['customer_portal'] = [
                    'id' => $user['id'],
                    'tenant_id' => $user['tenant_id'],
                    'party_id' => $user['party_id'],
                    'email' => $user['email']
                ];

                $this->conn->query("UPDATE `customer_portal_users` SET `last_login` = NOW() WHERE id = '{$user['id']}'");
                $this->log_portal_activity($user['tenant_id'], $user['party_id'], $user['id'], 'customer_login', "Customer logged into portal");

                return json_encode(['status' => 'success', 'msg' => 'Login successful!']);
            }
        }

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
