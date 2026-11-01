<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Permissions
 *
 * Single source of truth for role-based access control.
 *
 * Every module exposes four granular actions — view / create / edit / delete —
 * as the keys "<module>_view", "<module>_create", "<module>_edit",
 * "<module>_delete". A small set of administration permissions governs cross-
 * cutting capabilities (managing users, settings, API keys, webhooks and
 * workflows).
 *
 * Tier model:
 *   - Super Administrator (users.type = 1) is the "developer": bypasses this
 *     registry entirely (wildcard "*") and is the ONLY tier allowed to define
 *     roles, manage tenants/plans and use developer tools. Those capabilities
 *     are gated with require_super_admin(), not with an assignable key.
 *   - A "Tenant Administrator" is simply a staff user whose assigned role
 *     carries the `users_manage` permission: they can add/edit users within
 *     their own tenant and assign existing roles, but cannot define roles.
 *   - All other staff get exactly what their role grants.
 *
 * Permission keys are stored on `roles.permissions` as a comma-separated list.
 * Legacy "<module>_manage" keys from earlier versions are still honoured and
 * are treated as create + edit + delete for that module.
 */
final class Permissions
{
    public const WILDCARD   = '*';
    public const SUPER_ONLY = '__super__';

    /** Actions every standard module exposes, in order. */
    public const ACTIONS = [
        'view'   => 'View',
        'create' => 'Create',
        'edit'   => 'Edit',
        'delete' => 'Delete',
    ];

    /**
     * Modules grouped by section: section => [ module_key => label ].
     * Each module generates view/create/edit/delete keys unless listed in
     * VIEW_ONLY below.
     */
    private const MODULE_GROUPS = [
        'Sales & Receivables' => [
            'invoices'     => 'Sales Invoices',
            'parties'      => 'Customers & Vendors',
            'payments'     => 'Payments & Receipts',
            'sales_orders' => 'Sales Orders',
        ],
        'Inventory' => [
            'products'     => 'Products & Catalog',
            'stock'        => 'Stock Adjustments & Transfers',
            'warehouses'   => 'Warehouses',
        ],
        'Purchasing' => [
            'purchases'    => 'Purchase Orders & Bills',
        ],
        'Accounting' => [
            'journals'     => 'Journal Entries',
            'accounts'     => 'Chart of Accounts & Banks',
            'bank_reconciliation' => 'Bank Reconciliation',
            'assets'       => 'Fixed Assets',
            'expenses'     => 'Expense Claims',
            'reports'      => 'Financial Reports',
        ],
        'Payroll & HR' => [
            'employees'    => 'Employees',
            'attendance'   => 'Attendance',
            'leaves'       => 'Leave Requests',
            'payroll'      => 'Payroll',
        ],
        'CRM & Support' => [
            'crm'          => 'CRM (Opportunities & Activities)',
            'tickets'      => 'Support Tickets',
            'contracts'    => 'Contracts',
        ],
    ];

    /** Modules that only ever expose a "view" action. */
    private const VIEW_ONLY = ['reports'];

    /** Administration permissions (assignable to tenant-admin roles). */
    private const ADMIN_PERMS = [
        'User & Access Administration' => [
            'users_manage'    => 'Add, Edit & Remove Users (Tenant Administrator)',
            'settings_manage' => 'Manage Company Settings & Configuration',
            'api_keys_manage' => 'Generate & Revoke API Keys',
            'webhooks_manage' => 'Manage Webhooks',
            'workflows_manage'=> 'Manage Automation Workflows',
        ],
    ];

    /* ===================================================================
     * Registry construction
     * =================================================================== */

    /** Module key => label (flat, ordered). */
    public static function modules(): array
    {
        $out = [];
        foreach (self::MODULE_GROUPS as $mods) {
            foreach ($mods as $key => $label) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /** Actions available for a given module. */
    public static function actionsFor(string $module): array
    {
        return in_array($module, self::VIEW_ONLY, true) ? ['view' => 'View'] : self::ACTIONS;
    }

    /**
     * Grouped registry for the role editor UI.
     * Returns: section => [ 'modules' => [ module_key => [label, actions=>[action=>key]] ], ... ]
     */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (self::MODULE_GROUPS as $section => $mods) {
            foreach ($mods as $mkey => $label) {
                $actions = [];
                foreach (self::actionsFor($mkey) as $act => $_al) {
                    $actions[$act] = "{$mkey}_{$act}";
                }
                $matrix[$section][$mkey] = ['label' => $label, 'actions' => $actions];
            }
        }
        return $matrix;
    }

    /** Administration permission groups for the role editor UI. */
    public static function adminGroups(): array
    {
        return self::ADMIN_PERMS;
    }

    /** Flat list of every valid permission key (modules + admin). */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::modules() as $mkey => $_l) {
            foreach (self::actionsFor($mkey) as $act => $_al) {
                $keys[] = "{$mkey}_{$act}";
            }
        }
        foreach (self::ADMIN_PERMS as $perms) {
            foreach ($perms as $k => $_l) {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    public static function label(string $key): string
    {
        foreach (self::ADMIN_PERMS as $perms) {
            if (isset($perms[$key])) {
                return $perms[$key];
            }
        }
        if (preg_match('/^(.*)_(view|create|edit|delete)$/', $key, $m)) {
            $mod = self::modules()[$m[1]] ?? $m[1];
            return ucfirst($m[2]) . ' ' . $mod;
        }
        return $key;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Reduce submitted keys to valid, de-duplicated registry keys. The wildcard
     * and unknown keys are discarded (the wildcard is implicit for super admins
     * only).
     *
     * @param  mixed $submitted
     * @return list<string>
     */
    public static function sanitize($submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }
        $valid = self::keys();
        $clean = [];
        foreach ($submitted as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = trim($key);
            if ($key !== '' && in_array($key, $valid, true) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }

    /**
     * Parse a stored permission string into granted keys, expanding legacy
     * "<module>_manage" into create/edit/delete and ensuring that any granted
     * action implies "view" for that module.
     *
     * @return list<string>
     */
    public static function parse(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        if (trim($stored) === self::WILDCARD) {
            return [self::WILDCARD];
        }

        $raw = array_values(array_filter(array_map('trim', explode(',', $stored)), static fn ($k) => $k !== ''));
        $granted = [];
        foreach ($raw as $key) {
            // Legacy: "<module>_manage" => create + edit + delete.
            if (preg_match('/^(.*)_manage$/', $key, $m) && isset(self::modules()[$m[1]])) {
                foreach (['create', 'edit', 'delete'] as $a) {
                    $granted[] = "{$m[1]}_{$a}";
                }
                continue;
            }
            $granted[] = $key;
        }

        // Any granted action on a module implies view of that module.
        foreach ($granted as $key) {
            if (preg_match('/^(.*)_(create|edit|delete)$/', $key, $m)) {
                $granted[] = "{$m[1]}_view";
            }
        }

        return array_values(array_unique($granted));
    }

    /* ===================================================================
     * Action -> permission map (server-side dispatch enforcement)
     *
     * Each entry is [module, op] where op is one of:
     *   save   -> <module>_create when no record id, else <module>_edit
     *   create/edit/delete/view -> that exact action
     * or a literal value:
     *   a string admin key (e.g. 'settings_manage')
     *   self::SUPER_ONLY  -> super administrator (developer) only
     * Actions not present here require only an authenticated admin session.
     * Public (portal) actions are listed in PUBLIC_ACTIONS and are skipped.
     * =================================================================== */

    // Customer-portal actions that run under a customer session (login_type 2),
    // not an admin session — exempt from the admin access-control gate.
    public const PUBLIC_ACTIONS = ['customer_login', 'customer_register', 'save_proposal_signature'];

    private const ACTION_MAP = [
        // Accounting - chart of accounts, groups, banks
        'save_group'            => ['accounts', 'save'],
        'delete_group'          => ['accounts', 'delete'],
        'save_account'          => ['accounts', 'save'],
        'delete_account'        => ['accounts', 'delete'],
        'delete_accounts_bulk'  => ['accounts', 'delete'],
        'archive_accounts_bulk' => ['accounts', 'edit'],
        'change_tax_rate_bulk'  => ['accounts', 'edit'],
        'save_bank_account'     => ['accounts', 'save'],
        // Journals
        'save_journal'          => ['journals', 'save'],
        'delete_journal'        => ['journals', 'delete'],
        'cancel_journal'        => ['journals', 'edit'],
        // Invoices
        'save_invoice'          => ['invoices', 'save'],
        'delete_invoice'        => ['invoices', 'delete'],
        'email_invoice'         => ['invoices', 'view'],
        'save_invoice_template' => ['settings_manage'],
        // Parties
        'save_party'            => ['parties', 'save'],
        'delete_party'          => ['parties', 'delete'],
        'send_customer_invitation' => ['parties', 'edit'],
        'get_portal_logs'       => ['parties', 'view'],
        // Payments
        'save_payment'          => ['payments', 'save'],
        'delete_payment'        => ['payments', 'delete'],
        'email_payment_receipt' => ['payments', 'view'],
        // Products / stock / warehouses
        'save_product'          => ['products', 'save'],
        'delete_product'        => ['products', 'delete'],
        'save_stock_adjustment' => ['stock', 'save'],
        'transfer_stock'        => ['stock', 'edit'],
        'save_warehouse'        => ['warehouses', 'save'],
        // Sales orders
        'save_sales_order'      => ['sales_orders', 'save'],
        'delete_sales_order'    => ['sales_orders', 'delete'],
        'confirm_sales_order'   => ['sales_orders', 'edit'],
        // Purchases
        'save_purchase_order'   => ['purchases', 'save'],
        'delete_purchase_order' => ['purchases', 'delete'],
        'receive_goods'         => ['purchases', 'edit'],
        'save_vendor_bill'      => ['purchases', 'save'],
        'delete_vendor_bill'    => ['purchases', 'delete'],
        // Bank reconciliation
        'start_reconciliation'    => ['bank_reconciliation', 'edit'],
        'update_reconciliation'   => ['bank_reconciliation', 'edit'],
        'finalize_reconciliation' => ['bank_reconciliation', 'edit'],
        'reopen_reconciliation'   => ['bank_reconciliation', 'edit'],
        'delete_reconciliation'   => ['bank_reconciliation', 'delete'],
        'add_statement_lines'     => ['bank_reconciliation', 'edit'],
        'delete_statement_line'   => ['bank_reconciliation', 'delete'],
        'match_statement_line'    => ['bank_reconciliation', 'edit'],
        'unmatch_statement_line'  => ['bank_reconciliation', 'edit'],
        // Assets
        'save_asset'            => ['assets', 'save'],
        'delete_asset'          => ['assets', 'delete'],
        // Expenses
        'save_expense_claim'    => ['expenses', 'save'],
        'approve_expense_claim' => ['expenses', 'edit'],
        // Employees / attendance / leaves
        'save_employee'         => ['employees', 'save'],
        'delete_employee'       => ['employees', 'delete'],
        'save_attendance'       => ['attendance', 'save'],
        'delete_attendance'     => ['attendance', 'delete'],
        'save_leave'            => ['leaves', 'save'],
        'delete_leave'          => ['leaves', 'delete'],
        'update_leave_status'   => ['leaves', 'edit'],
        // Payroll
        'save_payroll'          => ['payroll', 'save'],
        'delete_payroll'        => ['payroll', 'delete'],
        'calculate_payroll_ajax'=> ['payroll', 'edit'],
        'apply_retro_pay'       => ['payroll', 'edit'],
        'email_payslip'         => ['payroll', 'view'],
        'save_salary_component' => ['payroll', 'edit'],
        'delete_salary_component'=> ['payroll', 'delete'],
        'save_shift_type'       => ['payroll', 'edit'],
        'save_shift_assignment' => ['payroll', 'edit'],
        // CRM
        'save_opportunity'      => ['crm', 'save'],
        'delete_opportunity'    => ['crm', 'delete'],
        'move_opportunity_stage'=> ['crm', 'edit'],
        'convert_opportunity'   => ['crm', 'edit'],
        'save_crm_activity'     => ['crm', 'save'],
        'toggle_crm_activity'   => ['crm', 'edit'],
        'delete_crm_activity'   => ['crm', 'delete'],
        // 'save_proposal_signature' is a customer-portal action — see PUBLIC_ACTIONS.
        // Tickets / contracts
        'save_ticket'           => ['tickets', 'save'],
        'reply_ticket'          => ['tickets', 'edit'],
        'save_contract'         => ['contracts', 'save'],
        // Settings & configuration (tenant-admin: settings_manage)
        'save_currency'         => ['settings_manage'],
        'delete_currency'       => ['settings_manage'],
        'save_module_settings'  => ['settings_manage'],
        'save_extrafield'       => ['settings_manage'],
        'delete_extrafield'     => ['settings_manage'],
        'save_tenant_vms_settings' => ['settings_manage'],
        'upload_tenant_vsdc_cert'  => ['settings_manage'],
        'run_frcs_accreditation_test' => ['settings_manage'],
        'test_sdc_ping'         => ['settings_manage'],
        'simulate_vms_fiscalization' => ['settings_manage'],
        // API keys / webhooks / workflows
        'save_api_key'          => ['api_keys_manage'],
        'delete_api_key'        => ['api_keys_manage'],
        'save_webhook'          => ['webhooks_manage'],
        'delete_webhook'        => ['webhooks_manage'],
        'test_webhook'          => ['webhooks_manage'],
        'save_workflow'         => ['workflows_manage'],
        'delete_workflow'       => ['workflows_manage'],
        'toggle_workflow'       => ['workflows_manage'],
        // User/session administration (tenant-admin: users_manage)
        'terminate_session'     => ['users_manage'],
        // Developer-only (super administrator)
        'save_role'             => [self::SUPER_ONLY],
        'delete_role'           => [self::SUPER_ONLY],
        'save_tenant'           => [self::SUPER_ONLY],
        'delete_tenant'         => [self::SUPER_ONLY],
        'save_plan'             => [self::SUPER_ONLY],
        'delete_plan'           => [self::SUPER_ONLY],
        'assign_plan'           => [self::SUPER_ONLY],
        'backup_db'             => [self::SUPER_ONLY],
        'optimize_db'           => [self::SUPER_ONLY],
        'git_pull'              => [self::SUPER_ONLY],
        'git_fetch'             => [self::SUPER_ONLY],
        'get_redis_status'      => [self::SUPER_ONLY],
        'test_redis_ping'       => [self::SUPER_ONLY],
        'start_redis_daemon'    => [self::SUPER_ONLY],
    ];

    /**
     * Resolve the permission required for a dispatch action.
     *
     * @return string|null  A permission key, self::SUPER_ONLY, or null when the
     *                       action only needs an authenticated session.
     */
    public static function forAction(string $action, bool $hasId = false): ?string
    {
        if (!isset(self::ACTION_MAP[$action])) {
            return null;
        }
        $spec = self::ACTION_MAP[$action];

        // Literal: admin key or SUPER_ONLY.
        if (count($spec) === 1) {
            return $spec[0];
        }

        [$module, $op] = $spec;
        switch ($op) {
            case 'save':   return $module . '_' . ($hasId ? 'edit' : 'create');
            case 'create': return $module . '_create';
            case 'edit':   return $module . '_edit';
            case 'delete': return $module . '_delete';
            case 'view':   return $module . '_view';
            default:       return null;
        }
    }

    /* ===================================================================
     * Page -> permission map (admin view router enforcement)
     *
     * Value is a permission key or self::SUPER_ONLY. The current page slug
     * (e.g. "user/list", "invoices") is matched first exactly, then by its
     * leading segment ("settings/x" -> "settings"). Pages not listed are open
     * to any authenticated admin session.
     * =================================================================== */
    private const PAGE_MAP = [
        // self-service / always available
        'home'              => null,
        'user/index'        => null,   // "My Account" self-profile
        // business modules (view permission)
        'invoices'          => 'invoices_view',
        'parties'           => 'parties_view',
        'payments'          => 'payments_view',
        'sales_orders'      => 'sales_orders_view',
        'products'          => 'products_view',
        'stock_adjustments' => 'stock_view',
        'warehouses'        => 'warehouses_view',
        'purchases'         => 'purchases_view',
        'journals'          => 'journals_view',
        'accounts'          => 'accounts_view',
        'bank_reconciliation' => 'bank_reconciliation_view',
        'assets'            => 'assets_view',
        'expense_reports'   => 'expenses_view',
        'reports'           => 'reports_view',
        'employees'         => 'employees_view',
        'attendance'        => 'attendance_view',
        'leaves'            => 'leaves_view',
        'payroll'           => 'payroll_view',
        'crm'               => 'crm_view',
        'tickets'           => 'tickets_view',
        'contracts'         => 'contracts_view',
        // administration
        'user/list'         => 'users_manage',
        'user/manage_user'  => 'users_manage',
        'api_keys'          => 'api_keys_manage',
        'webhooks'          => 'webhooks_manage',
        'workflows'         => 'workflows_manage',
        'settings'          => 'settings_manage',
        'system_info'       => 'settings_manage',
        'tax'               => 'settings_manage',
        'templates'         => 'settings_manage',
        // developer-only (super administrator)
        'roles'             => self::SUPER_ONLY,
        'user/permissions'  => self::SUPER_ONLY,
        'tenants'           => self::SUPER_ONLY,
        'developer'         => self::SUPER_ONLY,
    ];

    /**
     * Resolve the permission required to view an admin page.
     *
     * @return string|null|false  permission key / SUPER_ONLY, null for an
     *   explicitly-open page, or false when the page is unlisted.
     */
    public static function forPage(string $page)
    {
        $page = trim($page, '/');
        if (array_key_exists($page, self::PAGE_MAP)) {
            return self::PAGE_MAP[$page];
        }
        $root = explode('/', $page)[0];
        if (array_key_exists($root, self::PAGE_MAP)) {
            return self::PAGE_MAP[$root];
        }
        return false;
    }
}
