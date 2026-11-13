<?php

namespace App\Services;

use App\Support\Crypto;

/**
 * Per-tenant outgoing mail: each company may store its own SMTP settings
 * (password encrypted at rest) used for its customer notifications, with the
 * system SMTP as fallback. Every send is recorded in email_log per tenant.
 */
class TenantMailService
{
    private \mysqli $conn;
    private $settings;

    public function __construct(\mysqli $conn, $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
    }

    private const FIELDS = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name'];

    /** Raw row for the tenant (password stays encrypted). */
    public function row(?int $tenantId = null): ?array
    {
        $t = $tenantId !== null ? $tenantId : (int)$this->settings->active_tenant_id();
        $q = @$this->conn->query("SELECT " . implode(',', self::FIELDS) . " FROM `tenants` WHERE id = '{$t}'");
        return ($q && $q->num_rows) ? $q->fetch_assoc() : null;
    }

    /**
     * The usable SMTP config for a tenant, or null to use the system SMTP.
     * The password is decrypted here and only here.
     */
    public function config(?int $tenantId = null): ?array
    {
        $r = $this->row($tenantId);
        if (!$r || (int)$r['smtp_enabled'] !== 1 || trim((string)$r['smtp_host']) === '' || trim((string)$r['smtp_user']) === '') {
            return null;
        }
        return [
            'host' => $r['smtp_host'],
            'port' => (int)($r['smtp_port'] ?: 587),
            'encryption' => in_array($r['smtp_encryption'], ['ssl', 'tls', 'none'], true) ? $r['smtp_encryption'] : 'tls',
            'user' => $r['smtp_user'],
            'pass' => Crypto::decrypt((string)$r['smtp_pass']),
            'from_email' => trim((string)$r['smtp_from_email']) !== '' ? $r['smtp_from_email'] : $r['smtp_user'],
            'from_name' => trim((string)$r['smtp_from_name']) !== '' ? $r['smtp_from_name'] : $this->settings->info('name'),
        ];
    }

    /** Save the active tenant's SMTP settings. A blank password keeps the stored one. */
    public function save(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $enabled = !empty($post['smtp_enabled']) ? 1 : 0;
        $host = trim((string)($post['smtp_host'] ?? ''));
        $port = (int)($post['smtp_port'] ?? 587);
        $enc = in_array(($post['smtp_encryption'] ?? 'tls'), ['ssl', 'tls', 'none'], true) ? $post['smtp_encryption'] : 'tls';
        $user = trim((string)($post['smtp_user'] ?? ''));
        $from_email = strtolower(trim((string)($post['smtp_from_email'] ?? '')));
        $from_name = trim((string)($post['smtp_from_name'] ?? ''));

        if ($enabled && ($host === '' || $user === '')) {
            return json_encode(['status' => 'failed', 'msg' => 'SMTP host and username are required to enable company email.']);
        }
        if ($from_email !== '' && !filter_var($from_email, \FILTER_VALIDATE_EMAIL)) {
            return json_encode(['status' => 'failed', 'msg' => 'The from address is not a valid email address.']);
        }

        $sets = [
            "`smtp_enabled` = '{$enabled}'",
            "`smtp_host` = '" . $this->conn->real_escape_string($host) . "'",
            "`smtp_port` = '{$port}'",
            "`smtp_encryption` = '" . $this->conn->real_escape_string($enc) . "'",
            "`smtp_user` = '" . $this->conn->real_escape_string($user) . "'",
            "`smtp_from_email` = '" . $this->conn->real_escape_string($from_email) . "'",
            "`smtp_from_name` = '" . $this->conn->real_escape_string($from_name) . "'",
        ];
        $pass = (string)($post['smtp_pass'] ?? '');
        if ($pass !== '') {
            $sets[] = "`smtp_pass` = '" . $this->conn->real_escape_string(Crypto::encrypt($pass)) . "'";
        }
        if (!$this->conn->query("UPDATE `tenants` SET " . implode(', ', $sets) . " WHERE id = '{$t}'")) {
            return \App\Support\Db::fail($this->conn, 'The email settings could not be saved.');
        }
        // Audited without any credential material.
        $this->settings->log_audit('Updated Company Email Settings', 'Settings', 'Company SMTP ' . ($enabled ? "enabled via {$host}:{$port} as {$user}" : 'disabled') . ($pass !== '' ? ' (password updated)' : '') . '.');
        return json_encode(['status' => 'success', 'msg' => $enabled ? 'Company email settings saved. Send a test email to confirm delivery.' : 'Company email disabled - the system mail settings will be used.']);
    }

    /** Send a test message to the signed-in user's address with the tenant config. */
    public function test(): string
    {
        $to = '';
        $uid = (int)$this->settings->userdata('id');
        $q = @$this->conn->query("SELECT email FROM `users` WHERE id = '{$uid}'");
        if ($q && $q->num_rows) { $to = (string)($q->fetch_assoc()['email'] ?? ''); }
        if ($to === '') {
            return json_encode(['status' => 'failed', 'msg' => 'Add an email address to your own account first (My Account), so the test has somewhere to go.']);
        }
        $ok = \Emailer::send_email($to, 'Test email - ' . ($this->settings->info('name') ?: 'Nuvis ERPX'), '<p>This is a test of your company email settings. If you are reading this, delivery works.</p>');
        return json_encode($ok
            ? ['status' => 'success', 'msg' => "Test email sent to {$to} - check the inbox (and spam folder)."]
            : ['status' => 'failed', 'msg' => 'The test email could not be sent. Check the host, port, username and password - the exact error is in the log below.']);
    }

    /** Recent email log rows for the active tenant. */
    public function recentLog(int $limit = 50): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $limit = max(1, min(200, $limit));
        $out = [];
        $q = @$this->conn->query("SELECT to_email, subject, transport, status, error, created_at FROM `email_log` WHERE tenant_id = '{$t}' ORDER BY id DESC LIMIT {$limit}");
        while ($q && $r = $q->fetch_assoc()) { $out[] = $r; }
        return $out;
    }

    /** Record one send attempt against the tenant it was made for. */
    public static function logSend(\mysqli $conn, int $tenantId, string $to, string $subject, string $transport, bool $ok, string $error = ''): void
    {
        $st = $conn->prepare("INSERT INTO `email_log` (tenant_id, to_email, subject, transport, status, error) VALUES (?,?,?,?,?,?)");
        if ($st) {
            $status = $ok ? 'sent' : 'failed';
            $err = $ok ? null : mb_substr($error, 0, 2000);
            $st->bind_param('isssss', $tenantId, $to, $subject, $transport, $status, $err);
            @$st->execute();
        }
    }
}
