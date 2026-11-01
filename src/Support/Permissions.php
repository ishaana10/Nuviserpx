<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Permissions
 *
 * Single source of truth for the system's role-based permission keys.
 *
 * Every part of the access-control stack references this registry so that the
 * role editor, the permission inspector, the server-side enforcement in
 * SystemSettings::can_access() and the stored role records all speak the same
 * vocabulary. Permission keys are stored on `roles.permissions` as a
 * comma-separated list; the super-administrator account (users.type = 1)
 * bypasses this registry entirely and is granted the wildcard "*".
 *
 * To add a new permission, add its key => label under the appropriate group.
 * No other file needs to change.
 */
final class Permissions
{
    /** Wildcard granted to super administrators only. */
    public const WILDCARD = '*';

    /**
     * Grouped registry: group label => [ permission_key => human label ].
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Invoicing & Sales' => [
                'invoices_view'       => 'View Sales Invoices & Customers',
                'invoices_manage'     => 'Create / Edit Invoices & Parties',
            ],
            'Payment Receipts' => [
                'payments_view'       => 'View Payment Receipts',
                'payments_manage'     => 'Record & Allocate Receipts',
            ],
            'Commercial Stock & Orders' => [
                'products_view'       => 'View Product Catalog & Stock',
                'products_manage'     => 'Manage Products & Stock Adjustments',
                'sales_orders_view'   => 'View On-The-Go Sales Orders',
                'sales_orders_manage' => 'Take Orders & Confirm Sales',
            ],
            'Payroll & HR' => [
                'employees_view'      => 'View Employee Profiles',
                'employees_manage'    => 'Manage Employees, Attendance & Leaves',
                'payroll_view'        => 'View Payroll Runs & Payslips',
                'payroll_manage'      => 'Process Payroll & Compliance Exports',
            ],
            'Financial Accounting & Reports' => [
                'journals_view'       => 'View Journal Entries',
                'journals_manage'     => 'Post / Manage Journal Entries',
                'reports_view'        => 'Access Financial Statements (GL, P&L, Balance Sheet)',
            ],
            'User & Access Administration' => [
                'users_manage'        => 'Create, Edit & Remove System Users',
                'roles_manage'        => 'Define Custom Roles & Permission Matrix',
                'api_keys_manage'     => 'Generate & Revoke API Keys',
                'settings_manage'     => 'Manage Company Settings & System Configuration',
            ],
        ];
    }

    /**
     * Flat list of every valid permission key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::groups() as $perms) {
            foreach ($perms as $key => $_label) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Human-readable label for a single key (falls back to the raw key).
     */
    public static function label(string $key): string
    {
        foreach (self::groups() as $perms) {
            if (isset($perms[$key])) {
                return $perms[$key];
            }
        }
        return $key;
    }

    /** Whether a key is part of the registry. */
    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Reduce a submitted set of permission keys to only valid, de-duplicated
     * registry keys. The wildcard is never accepted from user input — it is
     * reserved for the super-administrator account and granted implicitly.
     *
     * @param  mixed $submitted  Array of keys (anything else yields []).
     * @return list<string>
     */
    public static function sanitize($submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $clean = [];
        foreach ($submitted as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = trim($key);
            if ($key !== '' && $key !== self::WILDCARD && self::isValid($key) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }

    /**
     * Parse a stored permission string (comma-separated) into a list of keys.
     *
     * @return list<string>
     */
    public static function parse(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        if (trim($stored) === self::WILDCARD) {
            return [self::WILDCARD];
        }
        $keys = array_map('trim', explode(',', $stored));
        return array_values(array_filter($keys, static fn ($k) => $k !== ''));
    }
}
