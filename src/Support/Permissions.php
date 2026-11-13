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

    /**
     * Special actions that are NOT implied by Create/Edit/Delete. A role must
     * be given each one explicitly (and legacy "_manage" grants never include
     * them), so sensitive operations stay opt-in.
     */
    private const SPECIAL_PERMS = [
        'Accounting' => [
            'bill_payments_approve' => 'Approve supplier payments that are waiting for approval (never your own, unless self-approval is allowed)',
            'bills_approve'    => 'Approve vendor bills that are waiting for approval (never your own, unless self-approval is allowed)',
            'journals_approve' => 'Approve manual journals that are waiting for approval (never your own, unless self-approval is allowed)',
        ],
        'Tax' => [
            'tax_file'         => 'Record VAT returns as filed and void them (the books must be closed up to the end of the period)',
        ],
        'Payroll' => [
            'payroll_process'  => 'Process payroll runs (Batch & Single Employee Run, retro pay)',
            'payroll_approve'  => 'Approve payroll runs and payroll payments that are waiting for approval (never your own, unless self-approval is allowed)',
            'payroll_pay'      => 'Pay approved salaries from the bank (net pay)',
            'payroll_remit'    => 'Pay pension, tax and levy amounts owed from payroll',
            'payroll_export'   => 'Download compliance & bank files (bank, tax return, pension schedule)',
            'payroll_view_all' => "See every employee's payroll & payslips (otherwise only their own)",
        ],
    ];

    /** Special-action groups for the role editor. */
    public static function specialGroups(): array
    {
        return self::SPECIAL_PERMS;
    }

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
        foreach (self::SPECIAL_PERMS as $perms) {
            foreach ($perms as $k => $_l) {
                $keys[] = $k;
            }
        }
        foreach (self::navPages() as $def) {
            $keys[] = $def['key'];
        }
        $keys[] = self::PAGES_SCOPED;
        return $keys;
    }

    /**
     * Marker stored on every role saved from the role editor. Present = the
     * individual page grants are authoritative; absent = a legacy role whose
     * module grants still imply access to that module's sub-pages.
     */
    public const PAGES_SCOPED = 'pages_scoped';

    /**
     * Every individual page in the sidebar that needs its own grant, discovered
     * dynamically from admin/inc/navigation.php so a new menu entry appears in
     * the role editor automatically. Sub-pages of a module (e.g. Payroll Test
     * Sandbox, AR Aging) and the Tax pages are listed; a module's main page is
     * governed by the module's View/Create/Edit/Delete row instead.
     *
     * @return array<string,array{key:string,label:string,section:string,parent:?string}>
     *         keyed by page path (e.g. "payroll/sandbox")
     */
    public static function navPages(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $file = dirname(__DIR__, 2) . '/admin/inc/navigation.php';
        $lines = is_readable($file) ? preg_split('/\R/', (string)file_get_contents($file)) : [];
        $section = 'Other';
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $l = $lines[$i];
            if (strpos($l, 'nav-is-tree') !== false) {
                for ($j = $i + 1; $j < min($n, $i + 8); $j++) {
                    $t = trim($lines[$j]);
                    if ($t !== '' && $t[0] !== '<' && strpos($t, '<') === false) {
                        $section = html_entity_decode($t);
                        break;
                    }
                }
                continue;
            }
            if (!preg_match('/\?page=([A-Za-z0-9_\/]+)[^"]*"\s+class=/', $l, $m)) {
                continue;
            }
            $page = $m[1];
            $label = $page;
            for ($j = $i; $j < min($n, $i + 5); $j++) {
                if (preg_match('/<p>(.*?)<\/p>/', $lines[$j], $mm)) {
                    $label = trim(html_entity_decode(strip_tags($mm[1])));
                    break;
                }
            }
            $parent = self::PAGE_MAP[$page] ?? self::PAGE_MAP[explode('/', $page)[0]] ?? false;
            $root = explode('/', $page)[0];
            $isTax = $root === 'tax';
            $moduleView = is_string($parent) && preg_match('/_view$/', $parent);
            if (!($isTax || $moduleView)) {
                continue; // admin / developer / open pages keep their own gate
            }
            if (strpos($page, '/') === false && $page !== 'groups') {
                continue; // a module's main page => the module row
            }
            $cache[$page] = [
                'key'     => 'page_' . str_replace('/', '__', $page),
                'label'   => $label,
                'section' => $isTax ? 'Tax' : $section,
                'parent'  => $moduleView ? $parent : null,
            ];
        }
        return $cache;
    }

    /** Individual-page grants grouped by sidebar section, for the role editor. */
    public static function pageGroups(): array
    {
        $groups = [];
        foreach (self::navPages() as $page => $def) {
            $groups[$def['section']][$page] = $def;
        }
        return $groups;
    }

    public static function label(string $key): string
    {
        foreach (self::SPECIAL_PERMS as $perms) {
            if (isset($perms[$key])) {
                return $perms[$key];
            }
        }
        foreach (self::navPages() as $def) {
            if ($def['key'] === $key) {
                return 'View ' . $def['label'];
            }
        }
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
        // A tenant role can no longer carry the wildcard (full access is the
        // developer's alone). Old roles holding it keep full BUSINESS access —
        // every module action, special action and page — but none of the
        // administration powers (users, settings, API keys, webhooks, workflows).
        if (in_array(self::WILDCARD, array_map('trim', explode(',', $stored)), true)) {
            $all = array_values(array_filter(self::keys(), static function ($k) {
                foreach (self::ADMIN_PERMS as $perms) {
                    if (isset($perms[$k])) {
                        return false;
                    }
                }
                return true;
            }));
            return $all;
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
    public const PUBLIC_ACTIONS = ['customer_login', 'customer_register', 'save_proposal_signature', 'employee_portal_login', 'employee_portal_activate'];

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
        // Posting a journal, whichever way it is created: needs the same right as save_journal (new).
        'reverse_journal'       => ['journals', 'create'],
        'post_opening_balances' => ['journals', 'create'],
        // Budgets are a plan against the chart of accounts, so they follow its edit right.
        'save_budget'           => ['accounts', 'edit'],
        'save_exchange_rate'    => ['settings_manage'],
        'post_fx_revaluation'   => ['journals', 'create'],
        'issue_credit_note'     => ['invoices', 'view'],
        'void_credit_note'      => ['invoices', 'view'],
        'refund_credit_note'    => ['invoices', 'view'],
        'void_note_refund'      => ['invoices', 'view'],
        'run_depreciation'      => ['assets', 'edit'],
        'dispose_asset'         => ['assets', 'edit'],
        'run_auto_reversals'    => ['journals', 'create'],
        'approve_bill_payment'  => ['bill_payments_approve'],
        'reject_bill_payment'   => ['bill_payments_approve'],
        'approve_bill'          => ['bills_approve'],
        'reject_bill'           => ['bills_approve'],
        'approve_journal'       => ['journals_approve'],
        'reject_journal'        => ['journals_approve'],
        'save_approval_policy'  => ['settings_manage'],
        'save_tax_code'         => ['settings_manage'],
        'delete_tax_code'       => ['settings_manage'],
        // Closing or reopening the books changes what every other user may do: tenant-admin only.
        'set_period_lock'       => ['settings_manage'],
        'save_inventory_accounting' => ['settings_manage'],
        'save_default_accounts' => ['settings_manage'],
        'provision_chart'       => ['settings_manage'],
        'save_tenant_smtp'      => ['settings_manage'],
        'test_tenant_smtp'      => ['settings_manage'],
        'save_price_list' => ['settings_manage'],
        'delete_price_list' => ['settings_manage'],
        'save_price_list_item' => ['settings_manage'],
        'delete_price_list_item' => ['settings_manage'],
        'assign_price_list' => ['settings_manage'],
        'lookup_prices' => ['invoices', 'view'],
        'file_tax_return'       => ['tax_file'],
        'void_tax_return'       => ['tax_file'],
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
        'create_stock_count'    => ['stock', 'save'],
        'write_off_expired'     => ['stock', 'save'],
        'save_stock_counts'     => ['stock', 'save'],
        'post_stock_count'      => ['stock', 'save'],
        'cancel_stock_count'    => ['stock', 'save'],
        'transfer_stock'        => ['stock', 'edit'],
        'save_warehouse'        => ['warehouses', 'save'],
        // Sales orders
        'save_sales_order'      => ['sales_orders', 'save'],
        'delete_sales_order'    => ['sales_orders', 'delete'],
        'confirm_sales_order'   => ['sales_orders', 'edit'],
        'revise_quote'          => ['sales_orders', 'edit'],
        'decline_quote'         => ['sales_orders', 'edit'],
        'extend_quote'          => ['sales_orders', 'edit'],
        'confirm_for_delivery'  => ['sales_orders', 'edit'],
        'save_delivery_note'    => ['sales_orders', 'edit'],
        'cancel_delivery_note'  => ['sales_orders', 'edit'],
        'invoice_delivered'     => ['invoices', 'save'],
        // Purchases
        'save_purchase_order'   => ['purchases', 'save'],
        'delete_purchase_order' => ['purchases', 'delete'],
        'receive_goods'         => ['purchases', 'edit'],
        'preview_landed_cost'   => ['purchases', 'edit'],
        'post_landed_cost'      => ['purchases', 'edit'],
        'save_vendor_bill'      => ['purchases', 'save'],
        'delete_vendor_bill'    => ['purchases', 'delete'],
        'cancel_vendor_bill'    => ['purchases', 'edit'],
        'pay_vendor_bills'      => ['purchases', 'edit'],
        'void_bill_payment'     => ['purchases', 'delete'],
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
        'reject_expense_claim'  => ['expenses', 'edit'],
        // Owner-or-manager is enforced in ExpenseClaimService, so staff can withdraw their own pending claim.
        'cancel_expense_claim'  => ['expenses', 'view'],
        'void_expense_claim'    => ['expenses', 'edit'],
        'reimburse_expense_claims' => ['expenses', 'edit'],
        'reverse_expense_reimbursement' => ['expenses', 'edit'],
        'save_expense_category' => ['expenses', 'edit'],
        // Employees / attendance / leaves
        'save_employee'         => ['employees', 'save'],
        'delete_employee'       => ['employees', 'delete'],
        'invite_employee_portal' => ['employees', 'save'],
        'revoke_employee_portal' => ['employees', 'save'],
        'save_attendance'       => ['attendance', 'save'],
        'delete_attendance'     => ['attendance', 'delete'],
        'save_leave'            => ['leaves', 'save'],
        'delete_leave'          => ['leaves', 'delete'],
        // Approve/reject and cancel are gated at view-level here; the real
        // authorization (assigned approver / owner + pending) is enforced in
        // EmployeeService, because an assigned approver may only hold leaves_view.
        'update_leave_status'   => ['leaves', 'view'],
        'cancel_leave'          => ['leaves', 'view'],
        'save_leave_policy'     => ['leaves', 'edit'],
        'save_leave_adjustment' => ['leaves', 'edit'],
        'leave_balance_info'    => ['leaves', 'view'],
        'save_public_holiday'   => ['leaves', 'edit'],
        'delete_public_holiday' => ['leaves', 'edit'],
        'save_leave_entitlement' => ['leaves', 'edit'],
        'leave_payout_suggestion' => ['payroll', 'edit'],
        // Payroll
        'save_payroll_rules'    => ['settings_manage'],
        'reset_payroll_rules'   => ['settings_manage'],
        'delete_payroll_rules_version' => ['settings_manage'],
        'save_payroll'          => ['payroll', 'save'],
        'delete_payroll'        => ['payroll', 'delete'],
        'calculate_payroll_ajax'=> ['payroll', 'edit'],
        'apply_retro_pay'       => ['payroll_process'],
        'cancel_retro_pay'      => ['payroll_process'],
        'approve_payroll_run'   => ['payroll_approve'],
        'reject_payroll_run'    => ['payroll_approve'],
        'approve_payroll_remittance' => ['payroll_approve'],
        'reject_payroll_remittance'  => ['payroll_approve'],
        'pay_payroll_salaries' => ['payroll_pay'],
        'reverse_payroll_payment' => ['payroll_pay'],
        'remit_payroll_liability' => ['payroll_remit'],
        'reverse_payroll_remittance' => ['payroll_remit'],
        'save_batch_payroll'    => ['payroll_process'],
        'email_payslip'         => ['payroll', 'view'],
        'save_salary_component' => ['payroll', 'edit'],
        'delete_salary_component'=> ['payroll', 'delete'],
        'issue_staff_loan'          => ['payroll_pay'],
        'cancel_staff_loan'         => ['payroll_pay'],
        'assign_salary_component'   => ['payroll', 'edit'],
        'unassign_salary_component' => ['payroll', 'edit'],
        'save_shift_type'       => ['payroll', 'edit'],
        'save_shift_assignment' => ['payroll', 'edit'],
        // CRM
        'save_opportunity'      => ['crm', 'save'],
        'delete_opportunity'    => ['crm', 'delete'],
        'move_opportunity_stage'=> ['crm', 'edit'],
        'convert_opportunity'   => ['crm', 'edit'],
        'save_crm_activity'     => ['crm', 'save'],
        'save_crm_contact'      => ['crm', 'save'],
        'delete_crm_contact'    => ['crm', 'delete'],
        'toggle_crm_activity'   => ['crm', 'edit'],
        'delete_crm_activity'   => ['crm', 'delete'],
        // 'save_proposal_signature' is a customer-portal action — see PUBLIC_ACTIONS.
        // Tickets / contracts
        'save_ticket'           => ['tickets', 'save'],
        'reply_ticket'          => ['tickets', 'edit'],
        'save_sla_policies'     => ['settings_manage'],
        'save_contract'         => ['contracts', 'save'],
        'generate_contract_invoices' => ['invoices', 'save'],
        'import_parties'        => ['settings_manage'],
        'import_products'       => ['settings_manage'],
        'import_accounts'       => ['settings_manage'],
        'save_reminder_settings' => ['invoices', 'edit'],
        'run_payment_reminders' => ['invoices', 'edit'],
        // Settings & configuration (tenant-admin: settings_manage)
        'save_currency'         => ['settings_manage'],
        'delete_currency'       => ['settings_manage'],
        'save_module_settings'  => ['settings_manage'],
        'save_extrafield'       => ['settings_manage'],
        'delete_extrafield'     => ['settings_manage'],
        'save_tenant_vms_settings' => ['settings_manage'],
        'upload_tenant_vsdc_cert'  => ['settings_manage'],
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
        'clear_error_log'       => [self::SUPER_ONLY],
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
        // Creating a payroll run IS processing payroll.
        if ($action === 'save_payroll' && !$hasId) {
            return 'payroll_process';
        }
        // Applying for leave and amending one's own request are the same
        // self-service capability, so editing is gated at create-level too.
        // EmployeeService still restricts a self-service user to their own
        // pending request.
        if ($action === 'save_leave') {
            return 'leaves_create';
        }
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
        'user/change_password' => null, // Change own password
        'settings/modules'  => null,   // read-only module overview for the tenant
        'help'              => null,   // user guide, open to any logged-in admin
        // business modules (view permission)
        'invoices'          => 'invoices_view',
        'parties'           => 'parties_view',
        'payments'          => 'payments_view',
        'sales_orders'      => 'sales_orders_view',
        'delivery_notes'    => 'sales_orders_view',
        'products'          => 'products_view',
        'stock_adjustments' => 'stock_view',
        'stock_counts'      => 'stock_view',
        'batches'           => 'stock_view',
        'warehouses'        => 'warehouses_view',
        'purchases'         => 'purchases_view',
        'journals'          => 'journals_view',
        'accounts'          => 'accounts_view',
        'groups'            => 'accounts_view',
        'budgets'           => 'accounts_view',
        'bank_reconciliation' => 'bank_reconciliation_view',
        'assets'            => 'assets_view',
        'credit_notes'      => 'invoices_view',
        'price_lists'       => 'products_view',
        'expense_reports'   => 'expenses_view',
        'reports'           => 'reports_view',
        'employees'         => 'employees_view',
        'attendance'        => 'attendance_view',
        'leaves'            => 'leaves_view',
        'payroll'           => 'payroll_view',
        'crm'               => 'crm_view',
        'tickets'           => 'tickets_view',
        'contracts'         => 'contracts_view',
        'payment_reminders' => 'invoices_view',
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
        $nav = self::navPages();
        if (isset($nav[$page])) {
            return $nav[$page]['key'];
        }
        if (array_key_exists($page, self::PAGE_MAP)) {
            return self::PAGE_MAP[$page];
        }
        $root = explode('/', $page)[0];
        if (array_key_exists($root, self::PAGE_MAP)) {
            return self::PAGE_MAP[$root];
        }
        return false;
    }

    /**
     * Tenant-assignable modules (the developer switches these on/off per
     * tenant). Maps each module key to the permission modules it governs.
     * A tenant without the module cannot reach those pages/actions no matter
     * what a role grants.
     */
    /**
     * Tenant modules: the features a company has, assigned per tenant by the
     * developer. Every permission module is governed by exactly one entry, and
     * 'requires' names the modules it cannot run without (invoices post to the
     * ledger and sell products, expense claims belong to employees, ...).
     * Activation pulls requirements in; deactivation switches dependents off.
     */
    public const TENANT_MODULES = [
        'mod_accounting'      => ['label' => 'Accounting & Financial Reports', 'perms' => ['journals', 'accounts', 'bank_reconciliation', 'reports'], 'requires' => []],
        'mod_inventory'       => ['label' => 'Products & Inventory',           'perms' => ['products', 'stock'], 'requires' => []],
        'mod_sales'           => ['label' => 'Invoicing & Receivables',        'perms' => ['invoices', 'payments', 'parties', 'sales_orders'], 'requires' => ['mod_accounting', 'mod_inventory']],
        'mod_purchases'       => ['label' => 'Purchasing & Vendor Bills',      'perms' => ['purchases'], 'requires' => ['mod_accounting', 'mod_inventory']],
        'mod_warehouses'      => ['label' => 'Multi-Warehouse Inventory',      'perms' => ['warehouses'], 'requires' => ['mod_inventory']],
        'mod_fixed_assets'    => ['label' => 'Fixed Assets Management',        'perms' => ['assets'], 'requires' => ['mod_accounting']],
        'mod_payroll'         => ['label' => 'Payroll & HR Suite',             'perms' => ['employees', 'attendance', 'leaves', 'payroll'], 'requires' => ['mod_accounting']],
        'mod_expense_reports' => ['label' => 'Employee Expense Claims',        'perms' => ['expenses'], 'requires' => ['mod_accounting', 'mod_payroll']],
        'mod_sales_crm'       => ['label' => 'Sales & CRM Opportunities',      'perms' => ['crm'], 'requires' => ['mod_sales']],
        'mod_tickets'         => ['label' => 'Helpdesk & Support Tickets',     'perms' => ['tickets'], 'requires' => ['mod_sales']],
        'mod_contracts'       => ['label' => 'Contracts & Subscriptions',      'perms' => ['contracts'], 'requires' => ['mod_sales']],
    ];

    /** Modules a tenant module directly requires. */
    public static function moduleRequires(string $key): array
    {
        return self::TENANT_MODULES[$key]['requires'] ?? [];
    }

    /**
     * Dependency closure: the given modules plus everything they require,
     * directly or indirectly, in registry order.
     */
    public static function expandTenantModules(array $keys): array
    {
        $on = [];
        $queue = $keys;
        while ($queue) {
            $k = array_shift($queue);
            if (!isset(self::TENANT_MODULES[$k]) || isset($on[$k])) {
                continue;
            }
            $on[$k] = true;
            foreach (self::moduleRequires($k) as $req) {
                $queue[] = $req;
            }
        }
        return array_values(array_intersect(array_keys(self::TENANT_MODULES), array_keys($on)));
    }

    /** Every module that, directly or indirectly, requires $key. */
    public static function moduleDependents(string $key): array
    {
        $out = [];
        foreach (array_keys(self::TENANT_MODULES) as $k) {
            if ($k !== $key && in_array($key, self::expandTenantModules([$k]), true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** Tenant module key governing a permission key, or null if ungoverned. */
    public static function tenantModuleFor(string $permKey): ?string
    {
        if (strpos($permKey, 'page_') === 0) {
            foreach (self::navPages() as $def) {
                if ($def['key'] === $permKey) {
                    return $def['parent'] ? self::tenantModuleFor($def['parent']) : null;
                }
            }
            return null;
        }
        $mod = preg_replace('/_(view_all|view|create|edit|delete|manage|process|export)$/', '', $permKey);
        foreach (self::TENANT_MODULES as $key => $def) {
            if (in_array($mod, $def['perms'], true)) {
                return $key;
            }
        }
        return null;
    }

    /** Sanitise a submitted list of tenant module keys. */
    public static function sanitizeTenantModules($list): array
    {
        $list = is_array($list) ? $list : explode(',', (string)$list);
        $valid = array_values(array_intersect(array_keys(self::TENANT_MODULES), array_map('trim', $list)));
        // A stored assignment is always dependency-closed: a module never
        // arrives without the modules it needs.
        return self::expandTenantModules($valid);
    }

    /**
     * Permission module (e.g. "payroll") a page belongs to, or null.
     */
    public static function moduleOfPage(string $page): ?string
    {
        $page = trim($page, '/');
        $nav = self::navPages();
        $perm = isset($nav[$page]) ? ($nav[$page]['parent'] ?? null) : self::forPage($page);
        if (!is_string($perm) || $perm === self::SUPER_ONLY) {
            return null;
        }
        return preg_replace('/_(view|create|edit|delete|manage)$/', '', $perm);
    }

    /**
     * Central guard for admin view files hit directly (modal forms, exports):
     * resolves the permission from the script path exactly as the router does,
     * "manage_*" forms needing create (new) or edit (existing record).
     * Returns null when allowed, otherwise a human message.
     *
     * @param object $settings SystemSettings
     */
    public static function guardDirectView(string $relPath, $settings, array $query): ?string
    {
        $page = preg_replace('/\.php$/', '', trim($relPath, '/'));
        $page = preg_replace('/\/index$/', '', $page);
        if ($page === '' || $page === 'index' || $page === 'login' || strpos($page, 'inc/') === 0 || $page === 'inc') {
            return null;
        }
        if ($settings->is_super_admin()) {
            return null;
        }
        $perm = self::forPage($page);
        if ($perm === false || $perm === null) {
            return null; // unlisted / explicitly open
        }
        if ($perm === self::SUPER_ONLY) {
            return 'Only the developer can open this.';
        }
        if ($page === 'payroll/rules_settings' || $page === 'stock_adjustments/accounting' || $page === 'accounts/default_accounts' || $page === 'price_lists') {
            $perm = 'settings_manage';
        } elseif ($page === 'tax/returns') {
            $perm = 'tax_file';
        } elseif ($page === 'payroll/pay_salaries') {
            $perm = 'payroll_pay';
        } elseif ($page === 'payroll/remittances') {
            $perm = 'payroll_remit';
        } elseif ($page === 'payroll/manage_payroll' && empty($query['id'])) {
            $perm = 'payroll_process'; // opening the run form = processing payroll
        } elseif (preg_match('/(^|\/)(manage_|new_|add_)/', $page) && preg_match('/_view$/', $perm)) {
            $perm = preg_replace('/_view$/', !empty($query['id']) ? '_edit' : '_create', $perm);
        }
        return $settings->can_access($perm) ? null : "You don't have permission to access this.";
    }

    /**
     * CSS that hides create/edit/delete controls the user may not use on a
     * module page. Presentation only — the server rejects the action anyway.
     */
    public static function hideControlsCss(string $page, $settings): string
    {
        if ($settings->is_super_admin()) {
            return '';
        }
        $mod = self::moduleOfPage($page);
        if ($mod === null || !isset(self::modules()[$mod])) {
            return '';
        }
        $css = '';
        if (!$settings->can_access("{$mod}_create")) {
            $css .= '#create_new,.create_data,a[href*="manage_"]:not([href*="id="]):not(.dropdown-item){display:none!important}';
        }
        if (!$settings->can_access("{$mod}_edit")) {
            $css .= '.edit_data,a[href*="manage_"][href*="id="]{display:none!important}';
        }
        if (!$settings->can_access("{$mod}_delete")) {
            $css .= '.delete_data{display:none!important}';
        }
        return $css === '' ? '' : '<style>' . $css . '</style>';
    }
}
