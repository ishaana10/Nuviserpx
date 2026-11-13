<?php

namespace App\Support;

/**
 * The in-app user guide, one topic per functional area. Each topic lists the
 * admin pages it covers (longest prefix wins), the tenant module / permission
 * that makes it relevant, an intro, and step-by-step sections with tips.
 * Content is rendered by admin/help/index.php (full guide) and by the
 * floating page helper (admin/inc/help_widget.php).
 */
class HelpContent
{
    /** @return array<string,array> all guide topics, in display order */
    public static function topics(): array
    {
        return [
        'getting_started' => [
            'title' => 'Getting Started & Dashboard', 'icon' => 'fa-home',
            'pages' => ['home'],
            'intro' => 'Your dashboard summarises the active company: cash position, receivables, payables and recent activity. Everything you see is scoped to the company shown at the top of the sidebar.',
            'sections' => [
                ['h' => 'Finding your way around', 'steps' => [
                    'The sidebar lists only the modules your company has - if something is missing, it is switched off (see the Modules & Features topic).',
                    'The top bar shows your name and avatar; click it for My Account and Logout.',
                    'Breadcrumbs under the page title always lead back to the list you came from.',
                ], 'tips' => ['Use the sidebar search-as-you-scroll: the current page is highlighted and scrolled into view.']],
                ['h' => 'First-day checklist', 'steps' => [
                    'Open My Account and confirm your email address, then click the verification link you receive.',
                    'Check the dashboard date-range picker (top right of most widgets) - figures follow it.',
                    'If numbers look empty, confirm you are in the right company (the badge at the top of the sidebar).',
                ], 'tips' => []],
            ],
        ],
        'sales' => [
            'title' => 'Invoicing & Receivables', 'icon' => 'fa-file-invoice-dollar', 'module' => 'mod_sales',
            'pages' => ['invoices', 'payments', 'parties', 'sales_orders', 'credit_notes', 'delivery_notes', 'payment_reminders', 'groups'],
            'intro' => 'Bill customers, record what they pay you, and keep customer records. Invoices post automatically to the ledger (sales revenue and accounts receivable).',
            'sections' => [
                ['h' => 'Creating an invoice', 'steps' => [
                    'Go to Invoices and click Create. Pick the customer; type in the item box to search your products and select one - price and tax fill in automatically.',
                    'Adjust quantity, price, discount or tax per line; totals update as you type.',
                    'Save. The invoice number is issued automatically and the amounts post to the ledger.',
                    'Use the row menu to print, email or edit the invoice later.',
                ], 'tips' => ['If saving reports a missing receivable account, open Accounting > Default Accounts and use "Add the standard accounts".']],
                ['h' => 'Recording a customer payment', 'steps' => [
                    'Go to Payments and click Create. Choose the customer - their open invoices appear.',
                    'Enter the amount received and allocate it against one or more invoices.',
                    'Save; a receipt can be emailed to the customer from the row menu.',
                ], 'tips' => ['Part-payments are fine - the invoice stays partly paid until fully allocated.']],
                ['h' => 'Customers & contacts', 'steps' => [
                    'Parties holds customers and their contact people, terms and balances.',
                    'Credit Notes reverse part or all of an invoice; Delivery Notes accompany goods.',
                    'Payment Reminders emails customers about overdue invoices on a schedule you set.',
                ], 'tips' => []],
            ],
        ],
        'inventory' => [
            'title' => 'Products & Inventory', 'icon' => 'fa-boxes', 'module' => 'mod_inventory',
            'pages' => ['products', 'stock_adjustments', 'stock_counts', 'price_lists'],
            'intro' => 'Your product catalogue and stock levels. Invoices and purchases draw their line items from here.',
            'sections' => [
                ['h' => 'Managing products', 'steps' => [
                    'Products lists everything you sell or buy; click Create to add one with its price, tax and accounts.',
                    'Price Lists let you keep alternative prices (e.g. wholesale vs retail).',
                ], 'tips' => []],
                ['h' => 'Keeping stock accurate', 'steps' => [
                    'Stock Adjustments corrects quantities (damage, shrinkage) with an audit trail and ledger posting.',
                    'Stock Counts walks you through a physical count and posts the differences.',
                ], 'tips' => ['The Inventory Valuation and Reorder reports (under Reports) are driven by these records.']],
            ],
        ],
        'purchases' => [
            'title' => 'Purchasing & Vendor Bills', 'icon' => 'fa-shopping-cart', 'module' => 'mod_purchases',
            'pages' => ['purchases'],
            'intro' => 'Record what you buy, the bills suppliers send you, and the payments you make against them.',
            'sections' => [
                ['h' => 'From purchase to payment', 'steps' => [
                    'Purchases records orders to suppliers.',
                    'Vendor Bills records the supplier\'s invoice; it posts to accounts payable.',
                    'Bill Payments settles bills, partially or in full.',
                    'The WHT Report summarises withholding tax on supplier payments.',
                ], 'tips' => ['The AP Aging report (under Reports) shows which bills are due and overdue.']],
            ],
        ],
        'warehouses' => [
            'title' => 'Multi-Warehouse', 'icon' => 'fa-warehouse', 'module' => 'mod_warehouses',
            'pages' => ['warehouses'],
            'intro' => 'Track stock in more than one location and move it between them.',
            'sections' => [
                ['h' => 'Working with warehouses', 'steps' => [
                    'Create each location under Warehouses.',
                    'Stock movements and counts can then be recorded per warehouse.',
                ], 'tips' => []],
            ],
        ],
        'accounting' => [
            'title' => 'Accounting', 'icon' => 'fa-book', 'module' => 'mod_accounting',
            'pages' => ['journals', 'accounts', 'bank_reconciliation', 'budgets'],
            'intro' => 'The books themselves: chart of accounts, journals, bank reconciliation and budgets. Most entries arrive automatically from invoices, bills and payroll - manual journals cover the rest.',
            'sections' => [
                ['h' => 'Chart of accounts & defaults', 'steps' => [
                    'Accounts lists your chart grouped by type (Assets, Liabilities, Equity, Income, Expenses).',
                    'Default Accounts tells the system where invoices, payments and cash post. If a role shows a warning, click "Add the standard accounts" to fill the gaps, then Save.',
                ], 'tips' => ['Every company has its own chart - check the company badge before editing.']],
                ['h' => 'Manual journals', 'steps' => [
                    'Journals > Create lets you post a balanced debit/credit entry with a memo.',
                    'Approvals (if enabled) routes journals for review before they hit the ledger.',
                    'Opening Balances seeds account balances when you start using the system mid-year.',
                ], 'tips' => []],
                ['h' => 'Bank reconciliation', 'steps' => [
                    'Pick the bank account and statement period.',
                    'Tick the ledger entries that appear on the statement; the difference works toward zero.',
                    'Finish when the difference is zero - reconciled entries are locked against accidental edits.',
                ], 'tips' => []],
            ],
        ],
        'reports' => [
            'title' => 'Financial Reports', 'icon' => 'fa-chart-line', 'module' => 'mod_accounting',
            'pages' => ['reports'],
            'intro' => 'Read-only statements built live from the ledger. Every report follows the date range you pick and can be printed or exported.',
            'sections' => [
                ['h' => 'What each report tells you', 'steps' => [
                    'Income Statement - profit or loss over a period.',
                    'Balance Sheet - what you own and owe at a date.',
                    'Cash Flow and Equity Changes - where money and ownership moved.',
                    'General Ledger - every posting, account by account.',
                    'AR / AP Aging - who owes you and whom you owe, by how long overdue.',
                    'Budget vs Actual, Inventory Valuation, Reorder and Payroll Accounting cover their own areas.',
                ], 'tips' => ['If a figure looks wrong, drill into the General Ledger for the account - every line links back to its source document.']],
            ],
        ],
        'tax' => [
            'title' => 'Tax', 'icon' => 'fa-percent',
            'pages' => ['tax'],
            'intro' => 'Tax rates used on invoices and bills, and the returns built from them.',
            'sections' => [
                ['h' => 'Rates, reports and returns', 'steps' => [
                    'Tax Rates defines the rates applied on documents (e.g. VAT).',
                    'Tax Report totals tax collected and paid over a period.',
                    'Tax Returns prepares the filing figures.',
                ], 'tips' => []],
            ],
        ],
        'fixed_assets' => [
            'title' => 'Fixed Assets', 'icon' => 'fa-industry', 'module' => 'mod_fixed_assets',
            'pages' => ['assets'],
            'intro' => 'Register long-lived assets and let the system depreciate them on schedule.',
            'sections' => [
                ['h' => 'Asset lifecycle', 'steps' => [
                    'Register the asset with cost, useful life and method.',
                    'Depreciation posts automatically each period.',
                    'Dispose or revalue from the asset row when the time comes.',
                ], 'tips' => []],
            ],
        ],
        'payroll' => [
            'title' => 'Payroll & HR', 'icon' => 'fa-users', 'module' => 'mod_payroll',
            'pages' => ['employees', 'attendance', 'leaves', 'payroll', 'batches'],
            'intro' => 'Employee records, attendance and leave, and the pay run itself - including PAYE, FNPF and the compliance exports.',
            'sections' => [
                ['h' => 'Employees, attendance & leave', 'steps' => [
                    'Employees holds each person\'s record, pay details and approver.',
                    'Attendance and Shift Roster record who worked when.',
                    'Leaves tracks requests and approvals; Balances shows entitlement left.',
                ], 'tips' => ['Link a user account to an employee (user management) so staff can request their own leave.']],
                ['h' => 'Running a pay run', 'steps' => [
                    'Open Payroll > Payrun Engine and pick the period.',
                    'Review the calculated gross, PAYE, FNPF and net per employee.',
                    'Send for approval (Payroll > Approvals) if enabled, then finalise.',
                    'Pay Salaries records the payment; payslips can be emailed to each employee.',
                    'EMS Export, FNPF Schedule and Remittances produce the compliance files.',
                ], 'tips' => ['Salary Components, Rules Settings and Tax Rules control how the engine calculates - change them before a run, not during.', 'The Sandbox lets you trial a calculation without posting anything.']],
            ],
        ],
        'expense_reports' => [
            'title' => 'Expense Claims', 'icon' => 'fa-receipt', 'module' => 'mod_expense_reports',
            'pages' => ['expense_reports'],
            'intro' => 'Employees claim back money they spent; approvers review; finance pays and the ledger follows.',
            'sections' => [
                ['h' => 'Claiming and approving', 'steps' => [
                    'Create a claim, add each expense line with its receipt, and submit.',
                    'The approver reviews and approves or rejects with a note.',
                    'Approved claims are paid and post to the expense accounts.',
                ], 'tips' => []],
            ],
        ],
        'crm' => [
            'title' => 'Sales & CRM', 'icon' => 'fa-handshake', 'module' => 'mod_sales_crm',
            'pages' => ['crm'],
            'intro' => 'Track opportunities from first contact to closed deal, with contacts and follow-ups per opportunity.',
            'sections' => [
                ['h' => 'Working the pipeline', 'steps' => [
                    'Create an opportunity with the customer, value and expected close.',
                    'Move it through the stages as the deal progresses.',
                    'Log contacts and next actions so nothing slips.',
                ], 'tips' => ['A won opportunity converts to a quote or invoice under Invoicing.']],
            ],
        ],
        'tickets' => [
            'title' => 'Helpdesk & Tickets', 'icon' => 'fa-life-ring', 'module' => 'mod_tickets',
            'pages' => ['tickets'],
            'intro' => 'Customer support requests with priorities, assignment and SLA timers.',
            'sections' => [
                ['h' => 'Handling a ticket', 'steps' => [
                    'New tickets arrive with a priority and an SLA due time.',
                    'Assign it, reply from the ticket thread, and resolve when done.',
                    'Overdue SLA tickets are flagged so they can be rescued first.',
                ], 'tips' => []],
            ],
        ],
        'contracts' => [
            'title' => 'Contracts & Subscriptions', 'icon' => 'fa-file-signature', 'module' => 'mod_contracts',
            'pages' => ['contracts'],
            'intro' => 'Recurring agreements that bill customers automatically on their schedule.',
            'sections' => [
                ['h' => 'Setting up a contract', 'steps' => [
                    'Create the contract with customer, items, value and billing frequency.',
                    'Invoices are generated on schedule; you review and send them.',
                    'End or renew the contract from its row when the term is up.',
                ], 'tips' => []],
            ],
        ],
        'account' => [
            'title' => 'My Account & Email Verification', 'icon' => 'fa-user-circle',
            'pages' => ['user'],
            'intro' => 'Your own profile: name, username, password, avatar and your registered email address.',
            'sections' => [
                ['h' => 'Keeping your account current', 'steps' => [
                    'Open My Account from your avatar (top right).',
                    'Update your details; leave Password blank unless changing it.',
                    'Enter your email address - a verification link is sent to it when it changes.',
                    'Click the link in the email; the badge on My Account turns green "Verified".',
                ], 'tips' => ['No email arriving? Check your spam folder, then use "Resend verification email".', 'The verification link is valid for 48 hours.']],
            ],
        ],
        'users_roles' => [
            'title' => 'Users, Roles & Permissions', 'icon' => 'fa-user-shield', 'perm' => 'users_manage',
            'pages' => ['user/list', 'user/manage_user', 'user/permissions', 'roles'],
            'intro' => 'Create accounts for your staff, group what they may do into roles, and see at a glance whose email is verified.',
            'sections' => [
                ['h' => 'Creating a user', 'steps' => [
                    'User Management > Add User. Fill in the name, a unique username and the person\'s email address (required - they receive a welcome email with a verification link).',
                    'Pick the privilege level and a role; optionally link an employee record.',
                    'Save. The list shows the account and its email verification badge.',
                ], 'tips' => ['An unverified badge just means the user has not clicked their link yet - they can still sign in.']],
                ['h' => 'Roles', 'steps' => [
                    'Roles bundle permissions (view/create/edit/delete per area).',
                    'Assign the role on the user; changes apply at their next page load.',
                ], 'tips' => []],
            ],
        ],
        'email_settings' => [
            'title' => 'Company Email (SMTP)', 'icon' => 'fa-envelope-open-text', 'perm' => 'settings_manage',
            'pages' => ['settings/email'],
            'intro' => 'Each company can send its customer notifications (invoices, receipts, reminders) from its own email address. When enabled, your SMTP settings are used; otherwise the system mail settings apply. Every send is recorded in the log on that page.',
            'sections' => [
                ['h' => 'Setting it up', 'steps' => [
                    'Get the SMTP details from your email provider: host, port (465 for SSL, 587 for TLS), username and password.',
                    'Enter them, set the From name and address your customers should see, and tick Enable.',
                    'Save, then click Send Test Email - it goes to your own account\'s address.',
                    'Check the log: a green "sent" row means delivery worked; a red "failed" row shows the exact server error.',
                ], 'tips' => ['Use a From address on the same domain as the SMTP account, and make sure the domain has SPF and DKIM records - otherwise receivers like Yahoo may discard the mail.', 'The password is stored encrypted and never shown again; leave the field blank to keep it.']],
            ],
        ],
        'modules' => [
            'title' => 'Modules & Features', 'icon' => 'fa-puzzle-piece',
            'pages' => ['settings/modules'],
            'intro' => 'Which parts of the system your company has. Modules are assigned per company by the system developer; this page is view-only for everyone else.',
            'sections' => [
                ['h' => 'Reading the overview', 'steps' => [
                    'Each module shows Active or Off, what it covers, and what it depends on.',
                    'If a module you need is Off, contact your provider - activating it also activates whatever it depends on.',
                ], 'tips' => ['Menus and pages of a switched-off module disappear for every user of that company.']],
            ],
        ],
        'admin_settings' => [
            'title' => 'System Administration', 'icon' => 'fa-cogs', 'super' => true,
            'pages' => ['settings', 'system_info', 'tenants', 'api_keys', 'webhooks', 'workflows', 'templates'],
            'intro' => 'Developer-tier administration: companies and their modules, system settings, security, data and automation.',
            'sections' => [
                ['h' => 'Companies & modules', 'steps' => [
                    'Tenants creates companies and assigns their modules - ticking a module pulls in its dependencies, unticking switches dependents off.',
                    'System Information holds branding, SMTP (used for all system email) and global settings.',
                ], 'tips' => []],
                ['h' => 'Housekeeping', 'steps' => [
                    'Audit Logs and Active Sessions show who did and who is doing what.',
                    'Backup, Data Import, Currencies and Exchange Rates, Email Templates, Extra Fields and Financial Settings each manage what their name says.',
                    'API Keys, Webhooks and Workflows automate against outside systems.',
                ], 'tips' => ['Test SMTP changes with "Send Test Email" before relying on verification or payslip emails.']],
            ],
        ],
        'developer' => [
            'title' => 'Developer Tools', 'icon' => 'fa-code', 'super' => true,
            'pages' => ['developer'],
            'intro' => 'Diagnostics for the developer tier: error logs, customer plans and update tooling.',
            'sections' => [
                ['h' => 'Keeping the system healthy', 'steps' => [
                    'Error Logs shows grouped PHP and JavaScript errors with counts and last-seen times.',
                    'Git Update pulls the latest release; Dashboard summarises environment health.',
                ], 'tips' => []],
            ],
        ],
        ];
    }

    /** Topics the current user/tenant should see, in order. */
    public static function visibleTopics($settings): array
    {
        $out = [];
        foreach (self::topics() as $slug => $t) {
            if (!empty($t['super']) && !$settings->is_super_admin()) continue;
            if (!empty($t['module']) && !$settings->module_enabled($t['module'])) continue;
            if (!empty($t['perm']) && !$settings->is_super_admin() && !$settings->can_access($t['perm'])) continue;
            $out[$slug] = $t;
        }
        return $out;
    }

    /** The topic covering an admin page (longest page-prefix wins), or null. */
    public static function topicForPage(string $page): ?string
    {
        $page = trim($page, '/');
        $best = null; $bestLen = -1;
        foreach (self::topics() as $slug => $t) {
            foreach ($t['pages'] as $p) {
                if ($page === $p || strpos($page, $p . '/') === 0) {
                    if (strlen($p) > $bestLen) { $best = $slug; $bestLen = strlen($p); }
                }
            }
        }
        return $best;
    }
}
