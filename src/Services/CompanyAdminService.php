<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Permissions;
use mysqli;

/**
 * Every company has its own administrator. That person is a Staff User (never the Super Administrator / developer tier)
 * holding the "Company Administrator" role of their own company: all the company's modules plus users, settings,
 * API keys, webhooks and workflows. They cannot see other companies, switch company, or open the Developer tools.
 */
class CompanyAdminService
{
    public const ROLE_NAME = 'Company Administrator';

    /** The company's "Company Administrator" role id, created if it does not exist yet. */
    public static function ensureRole(mysqli $conn, int $tenantId): int
    {
        $name = self::ROLE_NAME;
        $q = $conn->prepare("SELECT id FROM `roles` WHERE tenant_id = ? AND name = ? AND delete_flag = 0 LIMIT 1");
        $q->bind_param('is', $tenantId, $name);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) {
            return (int)$row['id'];
        }
        $perms = implode(',', Permissions::sanitize(Permissions::keys()));
        $desc = 'Runs this company: every module, users, settings, API keys, webhooks and workflows. No access to other companies or the Developer tools.';
        $i = $conn->prepare("INSERT INTO `roles` (tenant_id, name, description, permissions, status) VALUES (?, ?, ?, ?, 1)");
        $i->bind_param('isss', $tenantId, $name, $desc, $perms);
        $i->execute();
        $id = (int)$conn->insert_id;
        $i->close();
        return $id;
    }

    /** @return string|null the first problem with the administrator details, or null when they are fine */
    public static function problem(mysqli $conn, array $in): ?string
    {
        $first = trim((string)($in['admin_firstname'] ?? ''));
        $last = trim((string)($in['admin_lastname'] ?? ''));
        $email = trim((string)($in['admin_email'] ?? ''));
        $user = trim((string)($in['admin_username'] ?? ''));
        $pass = (string)($in['admin_password'] ?? '');
        if ($first === '' || $last === '' || $user === '') {
            return 'Enter the company administrator\'s first name, last name and username.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address for the company administrator.';
        }
        if ($errs = EmployeePortalService::passwordErrors($pass, $email)) {
            return 'Administrator password: ' . implode(' ', $errs);
        }
        $q = $conn->prepare("SELECT 1 FROM `users` WHERE username = ? OR email = ? LIMIT 1");
        $q->bind_param('ss', $user, $email);
        $q->execute();
        $taken = $q->get_result()->num_rows > 0;
        $q->close();
        return $taken ? 'That username or email is already used by another account.' : null;
    }

    /** Create the company's administrator. Call problem() first. Returns the new user id (0 on failure). */
    public static function create(mysqli $conn, int $tenantId, array $in): int
    {
        $roleId = self::ensureRole($conn, $tenantId);
        $first = trim((string)$in['admin_firstname']);
        $last = trim((string)$in['admin_lastname']);
        $email = trim((string)$in['admin_email']);
        $user = trim((string)$in['admin_username']);
        $hash = password_hash((string)$in['admin_password'], PASSWORD_BCRYPT);
        $i = $conn->prepare("INSERT INTO `users` (tenant_id, firstname, lastname, username, email, password, type, role_id, status) VALUES (?, ?, ?, ?, ?, ?, 2, ?, 1)");
        $i->bind_param('isssssi', $tenantId, $first, $last, $user, $email, $hash, $roleId);
        $ok = $i->execute();
        $id = $ok ? (int)$conn->insert_id : 0;
        $i->close();
        return $id;
    }
}
