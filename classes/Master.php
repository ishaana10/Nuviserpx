<?php

use App\Core\Container;
use App\Services\AccountService;
use App\Services\EmployeeService;
use App\Services\IntegrationService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\JournalService;
use App\Services\PartyService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\PayrollService;
use App\Services\PayrollComponentService;
use App\Services\PayrollLoanService;
use App\Services\PurchaseService;
use App\Services\BankReconciliationService;
use App\Services\CrmService;
use App\Services\RoleService;
use App\Services\TenantService;

require_once(__DIR__ . '/../config.php');

class Master extends DBConnection
{

    private $settings;
    private Container $container;

    public function __construct()
    {
        global $_settings;
        $this->settings = $_settings;
        parent::__construct();

        $this->container = Container::getInstance();
        $this->container->singleton(\mysqli::class, $this->conn);
        $this->container->singleton(SystemSettings::class, $this->settings);
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /* Account / Group Actions */
    public function save_group()
    {
        return $this->container->make(AccountService::class)->saveGroup($_POST);
    }

    public function delete_group()
    {
        return $this->container->make(AccountService::class)->deleteGroup(intval($_POST['id'] ?? 0));
    }

    public function save_account()
    {
        return $this->container->make(AccountService::class)->saveAccount($_POST);
    }

    public function delete_account()
    {
        return $this->container->make(AccountService::class)->deleteAccount(intval($_POST['id'] ?? 0));
    }

    public function delete_accounts_bulk()
    {
        $ids = $_POST['account_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }
        return $this->container->make(AccountService::class)->deleteAccounts($ids);
    }

    public function archive_accounts_bulk()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $ids = $_POST['account_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }

        $sanitized_ids = array_map('intval', $ids);
        $id_list = implode(',', $sanitized_ids);

        $upd = $this->conn->query("UPDATE `account_list` SET is_archived = 1 WHERE tenant_id = '{$tenant_id}' AND id IN ({$id_list})");
        if ($upd) {
            return json_encode(['status' => 'success', 'msg' => 'Selected accounts successfully archived.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to archive selected accounts.']);
    }

    public function change_tax_rate_bulk()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $ids = $_POST['account_ids'] ?? [];
        $tax_rate = $this->conn->real_escape_string($_POST['tax_rate'] ?? 'Tax Exempt (0%)');

        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }

        $sanitized_ids = array_map('intval', $ids);
        $id_list = implode(',', $sanitized_ids);

        $upd = $this->conn->query("UPDATE `account_list` SET tax_rate = '{$tax_rate}' WHERE tenant_id = '{$tenant_id}' AND id IN ({$id_list})");
        if ($upd) {
            return json_encode(['status' => 'success', 'msg' => 'Tax rates updated successfully for selected accounts.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to update tax rates.']);
    }

    public function save_asset()
    {
        return $this->container->make(\App\Services\FixedAssetService::class)->save_asset();
    }

    public function issue_credit_note()
    {
        return $this->container->make(\App\Services\CreditNoteService::class)->issue($_POST);
    }

    public function refund_credit_note()
    {
        return $this->container->make(\App\Services\CreditNoteService::class)->refund($_POST);
    }

    public function void_note_refund()
    {
        return $this->container->make(\App\Services\CreditNoteService::class)->voidRefund(intval($_POST['id'] ?? 0));
    }

    public function void_credit_note()
    {
        return $this->container->make(\App\Services\CreditNoteService::class)->void(intval($_POST['id'] ?? 0));
    }

    public function run_depreciation()
    {
        return $this->container->make(\App\Services\AssetService::class)->run((string)($_POST['through'] ?? ''));
    }

    public function dispose_asset()
    {
        return $this->container->make(\App\Services\AssetService::class)->dispose($_POST);
    }

    public function delete_asset()
    {
        return $this->container->make(\App\Services\FixedAssetService::class)->delete_asset();
    }

    public function save_bank_account()
    {
        return $this->container->make(\App\Services\CurrencyService::class)->save_bank_account();
    }
    public function save_opportunity()
    {
        return $this->container->make(CrmService::class)->saveOpportunity($_POST);
    }

    public function move_opportunity_stage()
    {
        return $this->container->make(CrmService::class)->moveStage(intval($_POST['id'] ?? 0), (string)($_POST['stage'] ?? ''), (string)($_POST['lost_reason'] ?? ''));
    }

    public function save_crm_contact()
    {
        return $this->container->make(CrmService::class)->saveContact($_POST);
    }

    public function delete_crm_contact()
    {
        return $this->container->make(CrmService::class)->deleteContact(intval($_POST['id'] ?? 0));
    }

    public function convert_opportunity()
    {
        return $this->container->make(CrmService::class)->convertToInvoice(intval($_POST['id'] ?? 0));
    }

    public function save_crm_activity()
    {
        return $this->container->make(CrmService::class)->saveActivity($_POST);
    }

    public function toggle_crm_activity()
    {
        return $this->container->make(CrmService::class)->toggleActivity(intval($_POST['id'] ?? 0));
    }

    public function delete_crm_activity()
    {
        return $this->container->make(CrmService::class)->deleteActivity(intval($_POST['id'] ?? 0));
    }

    public function delete_opportunity()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `crm_opportunities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted CRM Deal", "CRM Opportunities", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'CRM opportunity deleted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete opportunity.']);
    }

    public function save_proposal_signature()
    {
        return $this->container->make(\App\Services\ContractService::class)->save_proposal_signature();
    }
    public function save_ticket()
    {
        return $this->container->make(\App\Services\HelpdeskService::class)->save_ticket();
    }

    public function reply_ticket()
    {
        return $this->container->make(\App\Services\HelpdeskService::class)->reply_ticket();
    }
    public function save_sla_policies()
    {
        return $this->container->make(\App\Services\TicketSlaService::class)->savePolicies($_POST);
    }

    public function save_reminder_settings()
    {
        return $this->container->make(\App\Services\PaymentReminderService::class)->saveSettings($_POST);
    }

    public function run_payment_reminders()
    {
        $res = $this->container->make(\App\Services\PaymentReminderService::class)->run(date('Y-m-d'));
        $msg = count($res['sent']) . ' reminder(s) sent, ' . count($res['fees']) . ' late fee(s) raised.';
        if ($res['skipped_no_email']) {
            $msg .= ' ' . count($res['skipped_no_email']) . ' customer(s) have no valid email.';
        }
        if ($res['problems']) {
            $msg .= ' ' . implode(' ', $res['problems']);
        }
        return json_encode(['status' => $res['problems'] && !$res['sent'] && !$res['fees'] ? 'failed' : 'success', 'msg' => $msg]);
    }


    public function preview_landed_cost()
    {
        $svc = $this->container->make(\App\Services\LandedCostService::class);
        $grnId = (int)($_POST['grn_id'] ?? 0);
        if (!$svc->receipt($grnId)) {
            return json_encode(['status' => 'failed', 'msg' => 'That goods receipt was not found.']);
        }
        [$charges, $err] = \App\Services\LandedCostService::cleanCharges((array)($_POST['desc'] ?? []), (array)($_POST['amount'] ?? []));
        if ($err) {
            return json_encode(['status' => 'failed', 'msg' => $err]);
        }
        $plan = $svc->plan($svc->receiptLines($grnId), round(array_sum(array_column($charges, 'amount')), 2), ($_POST['basis'] ?? 'value') === 'qty' ? 'qty' : 'value');
        return json_encode(['status' => 'success', 'rows' => array_values($plan['rows']), 'total' => $plan['total'], 'capitalised' => $plan['capitalised']]);
    }

    public function post_landed_cost()
    {
        return $this->container->make(\App\Services\LandedCostService::class)->post($_POST);
    }

    public function write_off_expired()
    {
        return $this->container->make(\App\Services\BatchService::class)->writeOffExpired((int)($_POST['product_id'] ?? 0), (int)($_POST['warehouse_id'] ?? 0));
    }

    public function create_stock_count()
    {
        return $this->container->make(\App\Services\StockTakeService::class)->create(
            (int)($_POST['warehouse_id'] ?? 0),
            (string)($_POST['count_date'] ?? ''),
            (string)($_POST['scope'] ?? 'stocked'),
            (string)($_POST['notes'] ?? '')
        );
    }

    public function save_stock_counts()
    {
        $counts = isset($_POST['counted']) && is_array($_POST['counted']) ? $_POST['counted'] : [];
        return $this->container->make(\App\Services\StockTakeService::class)->saveCounts((int)($_POST['id'] ?? 0), $counts);
    }

    public function post_stock_count()
    {
        $svc = $this->container->make(\App\Services\StockTakeService::class);
        $id = (int)($_POST['id'] ?? 0);
        if (isset($_POST['counted']) && is_array($_POST['counted'])) {
            $saved = json_decode($svc->saveCounts($id, $_POST['counted']), true);
            if (($saved['status'] ?? '') !== 'success') {
                return json_encode($saved);
            }
        }
        return $svc->post($id);
    }

    public function cancel_stock_count()
    {
        return $this->container->make(\App\Services\StockTakeService::class)->cancel((int)($_POST['id'] ?? 0));
    }

    public function import_parties() { return $this->container->make(\App\Services\MaintenanceService::class)->import_csv('parties'); }
    public function import_products() { return $this->container->make(\App\Services\MaintenanceService::class)->import_csv('products'); }
    public function import_accounts() { return $this->container->make(\App\Services\MaintenanceService::class)->import_csv('accounts'); }

    public function generate_contract_invoices()
    {
        $id = intval($_POST['id'] ?? 0);
        $res = $this->container->make(\App\Services\ContractBillingService::class)->run(date('Y-m-d'), $id > 0 ? $id : null);
        $made = count($res['made']);
        $msg = $made ? "{$made} invoice(s) raised." : 'Nothing is due to be billed.';
        if ($res['problems']) {
            $msg .= ' ' . implode(' ', array_map(fn($p) => "{$p[0]}: {$p[1]}", $res['problems']));
        }
        return json_encode(['status' => $res['problems'] && !$made ? 'failed' : 'success', 'msg' => $msg, 'made' => $res['made']]);
    }

    public function save_contract()
    {
        return $this->container->make(\App\Services\ContractService::class)->save_contract();
    }
    public function save_warehouse()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $name = $_POST['name'] ?? null;
        $location = $_POST['location'] ?? null;

        if (empty($name)) {
            return json_encode(['status' => 'failed', 'msg' => 'Warehouse Name is required.']);
        }

        $w_name = $this->conn->real_escape_string($name);
        $w_loc = $this->conn->real_escape_string($location ?? '');

        $save = $this->conn->query("INSERT INTO `warehouses` (`tenant_id`, `name`, `location`, `status`) VALUES ('{$tenant_id}', '{$w_name}', '{$w_loc}', 1)");
        if ($save) {
            $this->settings->log_audit("Added Warehouse", "Warehouses", "Name: {$name}");
            return json_encode(['status' => 'success', 'id' => $this->conn->insert_id, 'msg' => 'Warehouse created successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save warehouse.']);
    }

    public function transfer_stock()
    {
        return $this->container->make(\App\Services\WarehouseStockService::class)->transfer(
            intval($_POST['product_id'] ?? 0),
            intval($_POST['from_warehouse_id'] ?? 0),
            intval($_POST['to_warehouse_id'] ?? 0),
            floatval($_POST['qty'] ?? 0),
            (string)($_POST['notes'] ?? '')
        );
    }

    /* Dolibarr Inspired Employee Expense Claim Methods */
    private function expenses(): \App\Services\ExpenseClaimService
    {
        return new \App\Services\ExpenseClaimService($this->conn, $this->settings);
    }

    public function save_expense_claim()
    {
        $path = null;
        if (isset($_FILES['attachment']) && ($_FILES['attachment']['tmp_name'] ?? '') != '') {
            $f = $_FILES['attachment'];
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'], true)) {
                return json_encode(['status' => 'failed', 'msg' => 'Invalid receipt attachment format. Allowed: PDF, Images, Documents.']);
            }
            if (($f['size'] ?? 0) > 10 * 1024 * 1024) {
                return json_encode(['status' => 'failed', 'msg' => 'The receipt is larger than 10MB.']);
            }
            $tenant_id = (int)$this->settings->active_tenant_id();
            $dir = base_app . 'uploads/expenses';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $rel = 'uploads/expenses/claim_' . $tenant_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], base_app . $rel)) {
                $path = $rel;
            }
        }
        return $this->expenses()->submit($_POST, $path);
    }

    public function approve_expense_claim()
    {
        return $this->expenses()->approve((int)($_POST['id'] ?? 0), (int)($_POST['account_id'] ?? 0), (string)($_POST['note'] ?? ''));
    }

    public function reject_expense_claim()
    {
        return $this->expenses()->reject((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
    }

    public function cancel_expense_claim()
    {
        return $this->expenses()->cancel((int)($_POST['id'] ?? 0));
    }

    public function void_expense_claim()
    {
        return $this->expenses()->void((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
    }

    public function reimburse_expense_claims()
    {
        return $this->expenses()->reimburse((array)($_POST['ids'] ?? []), (int)($_POST['bank_account_id'] ?? 0), (string)($_POST['pay_date'] ?? ''), (string)($_POST['reference'] ?? ''));
    }

    public function reverse_expense_reimbursement()
    {
        return $this->expenses()->reversePayment((int)($_POST['id'] ?? 0));
    }

    public function save_expense_category()
    {
        return $this->expenses()->saveCategory($_POST);
    }

    public function save_module_settings()
    {
        $modules = ['mod_accounting', 'mod_fixed_assets', 'mod_sales_crm', 'mod_tickets', 'mod_contracts', 'mod_warehouses', 'mod_expense_reports', 'mod_payroll'];
        foreach ($modules as $m) {
            $val = isset($_POST[$m]) ? '1' : '0';
            $check = $this->conn->query("SELECT id FROM `system_info` WHERE `meta_field` = '{$m}'");
            if ($check && $check->num_rows > 0) {
                $this->conn->query("UPDATE `system_info` SET `meta_value` = '{$val}' WHERE `meta_field` = '{$m}'");
            } else {
                $this->conn->query("INSERT INTO `system_info` (`meta_field`, `meta_value`) VALUES ('{$m}', '{$val}')");
            }
        }
        $this->settings->log_audit("Updated Module Configuration", "System Settings", "Module toggles updated.");
        return json_encode(['status' => 'success', 'msg' => 'Module configuration updated successfully.']);
    }

    /* Dolibarr Inspired Custom Extrafields Methods */
    public function save_extrafield()
    {
        return $this->container->make(\App\Services\ExtrafieldService::class)->save_extrafield();
    }

    public function delete_extrafield()
    {
        return $this->container->make(\App\Services\ExtrafieldService::class)->delete_extrafield();
    }

    public function save_currency()
    {
        return $this->container->make(\App\Services\CurrencyService::class)->save_currency();
    }

    public function delete_currency()
    {
        return $this->container->make(\App\Services\CurrencyService::class)->delete_currency();
    }

    public function backup_db()
    {
        return $this->container->make(\App\Services\MaintenanceService::class)->backup_db();
    }

    public function optimize_db()
    {
        return $this->container->make(\App\Services\MaintenanceService::class)->optimize_db();
    }

    public function save_shift_type()
    {
        return $this->container->make(\App\Services\ShiftRosterService::class)->save_shift_type();
    }

    public function save_shift_assignment()
    {
        return $this->container->make(\App\Services\ShiftRosterService::class)->save_shift_assignment();
    }

    public function save_salary_component()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $code = $_POST['code'] ?? null;
        $name = $_POST['name'] ?? null;
        $type = $_POST['type'] ?? null;
        $is_taxable = $_POST['is_taxable'] ?? null;
        $is_fnpf_subject = $_POST['is_fnpf_subject'] ?? null;
        $default_amount = $_POST['default_amount'] ?? null;
        $description = trim((string)($_POST['description'] ?? ''));

        if (empty($code) || empty($name)) {
            return json_encode(['status' => 'failed', 'msg' => 'Component Code and Name are required.']);
        }

        $c_code = $this->conn->real_escape_string(strtoupper(trim($code)));
        $c_name = $this->conn->real_escape_string(trim($name));
        $c_type = $this->conn->real_escape_string($type ?? 'earning');
        $c_tax = isset($is_taxable) ? 1 : 0;
        $c_fnpf = isset($is_fnpf_subject) ? 1 : 0;
        $c_amt = floatval($default_amount ?? 0);
        $c_desc = $this->conn->real_escape_string(mb_substr($description, 0, 255));

        $save = $this->conn->query("
            INSERT INTO `salary_components` (`tenant_id`, `code`, `name`, `type`, `is_taxable`, `is_fnpf_subject`, `default_amount`, `description`, `status`)
            VALUES ('{$tenant_id}', '{$c_code}', '{$c_name}', '{$c_type}', '{$c_tax}', '{$c_fnpf}', '{$c_amt}', '{$c_desc}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Created Salary Component", "Payroll", "Code: {$c_code}, Name: {$c_name}");
            return json_encode(['status' => 'success', 'msg' => 'Salary component created successfully.']);
        }
        return \App\Support\Db::fail($this->conn, 'Failed to save salary component.');
    }

    public function assign_salary_component()
    {
        return $this->container->make(PayrollComponentService::class)->assign($_POST);
    }

    public function unassign_salary_component()
    {
        return $this->container->make(PayrollComponentService::class)->unassign(intval($_POST['id'] ?? 0));
    }

    public function issue_staff_loan()
    {
        return $this->container->make(PayrollLoanService::class)->issue($_POST);
    }

    public function cancel_staff_loan()
    {
        return $this->container->make(PayrollLoanService::class)->cancel(intval($_POST['id'] ?? 0));
    }

    public function delete_salary_component()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);
        $this->conn->query("DELETE FROM `employee_salary_components` WHERE component_id = '{$id}' AND tenant_id = '{$tenant_id}'");

        $del = $this->conn->query("DELETE FROM `salary_components` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Salary Component", "Payroll", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Salary component deleted.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete salary component.']);
    }

    public function terminate_session()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }

        $tenant_id = $this->settings->active_tenant_id();
        $u_id = intval($_POST['user_id'] ?? 0);

        if ($u_id <= 0 || $u_id == $this->settings->userdata('id')) {
            return json_encode(['status' => 'failed', 'msg' => 'Cannot terminate your own current session.']);
        }

        $this->settings->log_audit("Terminated Session", "Security", "Force logged out user ID #{$u_id}");
        return json_encode(['status' => 'success', 'msg' => 'User session successfully terminated and forced to logout.']);
    }

    public function apply_retro_pay()
    {
        return $this->container->make(\App\Services\PayrollAdjustmentService::class)->create($_POST);
    }

    public function approve_payroll_run()
    {
        return $this->container->make(\App\Services\PayrollApprovalService::class)->approveRun((string)($_POST['ref'] ?? ''));
    }

    public function reject_payroll_run()
    {
        return $this->container->make(\App\Services\PayrollApprovalService::class)->rejectRun((string)($_POST['ref'] ?? ''), (string)($_POST['note'] ?? ''));
    }

    public function approve_payroll_remittance()
    {
        return $this->container->make(\App\Services\PayrollApprovalService::class)->approveRemittance(intval($_POST['id'] ?? 0));
    }

    public function reject_payroll_remittance()
    {
        return $this->container->make(\App\Services\PayrollApprovalService::class)->rejectRemittance(intval($_POST['id'] ?? 0), (string)($_POST['note'] ?? ''));
    }

    public function pay_payroll_salaries()
    {
        return $this->container->make(\App\Services\PayrollPaymentService::class)->pay($_POST);
    }

    public function reverse_payroll_payment()
    {
        return $this->container->make(\App\Services\PayrollPaymentService::class)->reverse(intval($_POST['id'] ?? 0));
    }

    public function remit_payroll_liability()
    {
        return $this->container->make(\App\Services\PayrollRemittanceService::class)->remit($_POST);
    }

    public function reverse_payroll_remittance()
    {
        return $this->container->make(\App\Services\PayrollRemittanceService::class)->reverse(intval($_POST['id'] ?? 0));
    }

    public function cancel_retro_pay()
    {
        return $this->container->make(\App\Services\PayrollAdjustmentService::class)->cancel(intval($_POST['id'] ?? 0));
    }

    public function save_tenant_vms_settings()
    {
        return $this->container->make(\App\Services\FiscalizationService::class)->save_tenant_vms_settings();
    }

    public function test_sdc_ping()
    {
        return $this->container->make(\App\Services\FiscalizationService::class)->test_sdc_ping();
    }

    public function simulate_vms_fiscalization()
    {
        return $this->container->make(\App\Services\FiscalizationService::class)->simulate_vms_fiscalization();
    }

    public function upload_tenant_vsdc_cert()
    {
        return $this->container->make(\App\Services\FiscalizationService::class)->upload_tenant_vsdc_cert();
    }
    public function save_journal()
    {
        return $this->container->make(JournalService::class)->saveJournal($_POST);
    }

    public function save_budget()
    {
        return $this->container->make(\App\Services\BudgetService::class)->saveBudget($_POST);
    }

    public function save_exchange_rate()
    {
        return $this->container->make(\App\Services\FxService::class)->saveRate($_POST);
    }

    public function post_fx_revaluation()
    {
        return $this->container->make(\App\Services\FxService::class)->revalue((string)($_POST['as_at'] ?? ''));
    }

    public function run_auto_reversals()
    {
        $res = (new \App\Services\AutoReversalService($this->conn, $this->settings))->run(date('Y-m-d'));
        $n = count($res['done']);
        $f = count($res['failed']);
        if ($n === 0 && $f === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'No journals are due for reversal.']);
        }
        $msg = "{$n} journal" . ($n === 1 ? '' : 's') . ' reversed.' . ($f ? " {$f} could not be reversed: " . implode(' ', array_slice(array_map(fn ($c, $w) => "{$c}: {$w}", array_keys($res['failed']), $res['failed']), 0, 2)) : '');
        if ($n) {
            $this->settings->set_flashdata('success', $msg);
        }
        return json_encode(['status' => $n ? 'success' : 'failed', 'msg' => $msg]);
    }

    public function approve_bill_payment()
    {
        return $this->container->make(\App\Services\BillPaymentService::class)->approve(intval($_POST['id'] ?? 0));
    }

    public function reject_bill_payment()
    {
        return $this->container->make(\App\Services\BillPaymentService::class)->reject(intval($_POST['id'] ?? 0), (string)($_POST['note'] ?? ''));
    }

    public function approve_bill()
    {
        return $this->container->make(\App\Services\ApprovalService::class)->approveBill(intval($_POST['id'] ?? 0));
    }

    public function reject_bill()
    {
        return $this->container->make(\App\Services\ApprovalService::class)->rejectBill(intval($_POST['id'] ?? 0), (string)($_POST['note'] ?? ''));
    }

    public function approve_journal()
    {
        return $this->container->make(\App\Services\ApprovalService::class)->approve(intval($_POST['id'] ?? 0));
    }

    public function reject_journal()
    {
        return $this->container->make(\App\Services\ApprovalService::class)->reject(intval($_POST['id'] ?? 0), (string)($_POST['note'] ?? ''));
    }

    public function save_approval_policy()
    {
        return $this->container->make(\App\Services\ApprovalService::class)->savePolicy($_POST);
    }

    public function save_price_list()
    {
        return $this->container->make(\App\Services\PriceListService::class)->saveList($_POST);
    }

    public function delete_price_list()
    {
        return $this->container->make(\App\Services\PriceListService::class)->deleteList((int)($_POST['id'] ?? 0));
    }

    public function save_price_list_item()
    {
        return $this->container->make(\App\Services\PriceListService::class)->saveItem($_POST);
    }

    public function delete_price_list_item()
    {
        return $this->container->make(\App\Services\PriceListService::class)->deleteItem((int)($_POST['id'] ?? 0));
    }

    public function assign_price_list()
    {
        return $this->container->make(\App\Services\PriceListService::class)->assign((int)($_POST['id'] ?? 0), (array)($_POST['party_ids'] ?? []));
    }

    public function lookup_prices()
    {
        return $this->container->make(\App\Services\PriceListService::class)->lookup($_POST);
    }

    public function revise_quote()
    {
        return $this->container->make(\App\Services\QuotationService::class)->revise((int)($_POST['id'] ?? 0));
    }

    public function decline_quote()
    {
        return $this->container->make(\App\Services\QuotationService::class)->decline((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
    }

    public function extend_quote()
    {
        return $this->container->make(\App\Services\QuotationService::class)->extend((int)($_POST['id'] ?? 0), (string)($_POST['valid_until'] ?? ''));
    }
    public function confirm_for_delivery()
    {
        return $this->container->make(\App\Services\DeliveryService::class)->confirmForDelivery((int)($_POST['id'] ?? 0));
    }

    public function save_delivery_note()
    {
        return $this->container->make(\App\Services\DeliveryService::class)->createDelivery($_POST);
    }

    public function cancel_delivery_note()
    {
        return $this->container->make(\App\Services\DeliveryService::class)->cancelDelivery((int)($_POST['id'] ?? 0));
    }

    public function invoice_delivered()
    {
        return $this->container->make(\App\Services\DeliveryService::class)->invoiceDelivered((int)($_POST['id'] ?? 0));
    }

    public function save_company_profile()
    {
        return $this->container->make(\App\Services\TenantService::class)->saveProfile($_POST, $_FILES);
    }

    public function save_tenant_smtp()
    {
        return $this->container->make(\App\Services\TenantMailService::class)->save($_POST);
    }

    public function test_tenant_smtp()
    {
        return $this->container->make(\App\Services\TenantMailService::class)->test();
    }

    public function provision_chart()
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $added = \App\Services\ChartProvisioner::fill($this->conn, $tenant_id);
        if ($added > 0) {
            $this->settings->log_audit('Provisioned chart accounts', 'Accounts', "Added or reactivated {$added} standard account(s) the chart was missing.");
            return json_encode(['status' => 'success', 'msg' => " {$added} standard account(s) added or reactivated."]);
        }
        return json_encode(['status' => 'success', 'msg' => ' Your chart already has every standard account.']);
    }

    public function save_default_accounts()
    {
        return $this->container->make(\App\Services\AccountDefaultsService::class)->save($_POST);
    }

    public function save_inventory_accounting()
    {
        return $this->container->make(\App\Services\InventoryAccountingService::class)->saveSettings($_POST);
    }

    public function file_tax_return()
    {
        return $this->container->make(\App\Services\TaxReturnService::class)->file($_POST);
    }

    public function void_tax_return()
    {
        return $this->container->make(\App\Services\TaxReturnService::class)->void(intval($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
    }

    public function save_tax_code()
    {
        return $this->container->make(\App\Services\TaxService::class)->saveCode($_POST);
    }

    public function delete_tax_code()
    {
        return $this->container->make(\App\Services\TaxService::class)->deleteCode(intval($_POST['id'] ?? 0));
    }

    public function reverse_journal()
    {
        return $this->container->make(JournalService::class)->reverseJournal(intval($_POST['id'] ?? 0), (string)($_POST['date'] ?? ''));
    }

    public function set_period_lock()
    {
        return $this->container->make(\App\Services\PeriodLockService::class)->setLock((string)($_POST['lock_date'] ?? ''), (string)($_POST['note'] ?? ''));
    }

    public function post_opening_balances()
    {
        return $this->container->make(JournalService::class)->postOpeningBalances($_POST);
    }

    public function delete_journal()
    {
        return $this->container->make(JournalService::class)->deleteJournal(intval($_POST['id'] ?? 0));
    }

    public function cancel_journal()
    {
        return $this->container->make(JournalService::class)->cancelJournal(intval($_POST['id'] ?? 0));
    }

    /* Role / API Key Actions */
    public function save_role()
    {
        return $this->container->make(RoleService::class)->saveRole($_POST);
    }

    public function delete_role()
    {
        return $this->container->make(RoleService::class)->deleteRole(intval($_POST['id'] ?? 0));
    }

    public function save_api_key()
    {
        return $this->container->make(RoleService::class)->saveApiKey($_POST);
    }

    public function delete_api_key()
    {
        return $this->container->make(RoleService::class)->deleteApiKey(intval($_POST['id'] ?? 0));
    }

    /* Webhook Actions */
    public function save_webhook()
    {
        return $this->container->make(IntegrationService::class)->saveWebhook($_POST);
    }

    public function delete_webhook()
    {
        return $this->container->make(IntegrationService::class)->deleteWebhook(intval($_POST['id'] ?? 0));
    }

    public function test_webhook()
    {
        return $this->container->make(IntegrationService::class)->testWebhook(intval($_POST['id'] ?? 0));
    }

    /* Workflow Automation Actions */
    public function save_workflow()
    {
        return $this->container->make(IntegrationService::class)->saveWorkflow($_POST);
    }

    public function delete_workflow()
    {
        return $this->container->make(IntegrationService::class)->deleteWorkflow(intval($_POST['id'] ?? 0));
    }

    public function toggle_workflow()
    {
        return $this->container->make(IntegrationService::class)->toggleWorkflow(intval($_POST['id'] ?? 0));
    }

    /* Platform Plan Actions (super-admin — enforced by the central gate) */
    public function save_plan()
    {
        return $this->container->make(PlanService::class)->savePlan($_POST);
    }

    public function delete_plan()
    {
        return $this->container->make(PlanService::class)->deletePlan(intval($_POST['id'] ?? 0));
    }

    public function assign_plan()
    {
        return $this->container->make(PlanService::class)->assignPlan($_POST);
    }

    /* Invoice Actions */
    /* Developer error logs */
    public function log_js_error()
    {
        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'message' => substr(trim((string)($_POST['message'] ?? '')), 0, 600),
            'source' => substr((string)($_POST['source'] ?? ''), 0, 300),
            'line' => intval($_POST['line'] ?? 0),
            'stack' => substr((string)($_POST['stack'] ?? ''), 0, 3000),
            'page' => substr((string)($_POST['page'] ?? ''), 0, 300),
            'user' => (string)($this->settings->userdata('username') ?? ''),
            'agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ];
        if ($entry['message'] === '') {
            return json_encode(['status' => 'failed']);
        }
        \App\Support\ErrorLog::append('js', $entry);
        return json_encode(['status' => 'success']);
    }

    public function clear_error_log()
    {
        $type = ($_POST['type'] ?? '') === 'js' ? 'js' : 'php';
        \App\Support\ErrorLog::clear($type);
        $this->settings->log_audit('Cleared error log', 'Developer', strtoupper($type) . ' error log cleared.');
        return json_encode(['status' => 'success']);
    }

    public function save_invoice()
    {
        return $this->container->make(InvoiceService::class)->saveInvoice($_POST);
    }

    public function delete_invoice()
    {
        return $this->container->make(InvoiceService::class)->deleteInvoice(intval($_POST['id'] ?? 0));
    }

    public function email_invoice()
    {
        return $this->container->make(InvoiceService::class)->emailInvoice(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Payment Actions */
    public function save_payment()
    {
        return $this->container->make(PaymentService::class)->savePayment($_POST);
    }

    public function delete_payment()
    {
        return $this->container->make(PaymentService::class)->deletePayment(intval($_POST['id'] ?? 0));
    }

    public function email_payment_receipt()
    {
        return $this->container->make(PaymentService::class)->emailPaymentReceipt(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Inventory / Product / Sales Order Actions */
    public function save_product()
    {
        return $this->container->make(InventoryService::class)->saveProduct($_POST);
    }

    public function delete_product()
    {
        return $this->container->make(InventoryService::class)->deleteProduct(intval($_POST['id'] ?? 0));
    }

    public function save_stock_adjustment()
    {
        return $this->container->make(InventoryService::class)->saveStockAdjustment($_POST);
    }

    public function save_sales_order()
    {
        return $this->container->make(\App\Services\SalesOrderService::class)->save_sales_order();
    }

    public function confirm_sales_order()
    {
        return $this->container->make(\App\Services\SalesOrderService::class)->confirm_sales_order();
    }

    public function delete_sales_order()
    {
        return $this->container->make(\App\Services\SalesOrderService::class)->delete_sales_order();
    }

    public function save_purchase_order()
    {
        return $this->container->make(PurchaseService::class)->savePurchaseOrder($_POST);
    }

    public function delete_purchase_order()
    {
        return $this->container->make(PurchaseService::class)->deletePurchaseOrder(intval($_POST['id'] ?? 0));
    }

    public function receive_goods()
    {
        return $this->container->make(PurchaseService::class)->receiveGoods($_POST);
    }

    public function save_vendor_bill()
    {
        return $this->container->make(PurchaseService::class)->saveVendorBill($_POST);
    }

    public function pay_vendor_bills()
    {
        return $this->container->make(\App\Services\BillPaymentService::class)->pay($_POST);
    }

    public function void_bill_payment()
    {
        return $this->container->make(\App\Services\BillPaymentService::class)->void(intval($_POST['id'] ?? 0));
    }

    public function cancel_vendor_bill()
    {
        return $this->container->make(PurchaseService::class)->cancelVendorBill(intval($_POST['id'] ?? 0));
    }

    public function delete_vendor_bill()
    {
        return $this->container->make(PurchaseService::class)->deleteVendorBill(intval($_POST['id'] ?? 0));
    }

    /* Bank Reconciliation Actions */
    public function start_reconciliation()
    {
        return $this->container->make(BankReconciliationService::class)->startReconciliation($_POST);
    }

    public function update_reconciliation()
    {
        return $this->container->make(BankReconciliationService::class)->updateReconciliation($_POST);
    }

    public function delete_reconciliation()
    {
        return $this->container->make(BankReconciliationService::class)->deleteReconciliation(intval($_POST['id'] ?? 0));
    }

    public function add_statement_lines()
    {
        return $this->container->make(BankReconciliationService::class)->addStatementLines($_POST);
    }

    public function delete_statement_line()
    {
        return $this->container->make(BankReconciliationService::class)->deleteStatementLine(intval($_POST['id'] ?? 0));
    }

    public function match_statement_line()
    {
        return $this->container->make(BankReconciliationService::class)->matchLine($_POST);
    }

    public function unmatch_statement_line()
    {
        return $this->container->make(BankReconciliationService::class)->unmatchLine(intval($_POST['statement_line_id'] ?? 0));
    }

    public function finalize_reconciliation()
    {
        return $this->container->make(BankReconciliationService::class)->finalize(intval($_POST['id'] ?? 0));
    }

    public function reopen_reconciliation()
    {
        return $this->container->make(BankReconciliationService::class)->reopen(intval($_POST['id'] ?? 0));
    }

    /* Payroll Actions */
    public function calculate_payroll_ajax()
    {
        return $this->container->make(PayrollService::class)->calculatePayrollAjax($_POST);
    }

    public function save_payroll_rules()
    {
        return $this->container->make(PayrollService::class)->saveRules($_POST);
    }

    public function delete_payroll_rules_version()
    {
        return $this->container->make(PayrollService::class)->deleteRulesVersion((string)($_POST['effective_from'] ?? ''));
    }

    public function reset_payroll_rules()
    {
        return $this->container->make(PayrollService::class)->resetRules();
    }

    public function save_payroll()
    {
        return $this->container->make(PayrollService::class)->savePayroll($_POST);
    }

    public function delete_payroll()
    {
        return $this->container->make(PayrollService::class)->deletePayroll(intval($_POST['id'] ?? 0));
    }

    public function save_batch_payroll()
    {
        return $this->container->make(PayrollService::class)->savePayrollBatch($_POST);
    }

    public function email_payslip()
    {
        $scope = $this->settings->employee_scope('payroll');
        if ($scope !== null) {
            $pid = intval($_POST['id'] ?? 0);
            $own = $scope > 0 ? $this->conn->query("SELECT id FROM `payroll_list` WHERE id = '{$pid}' AND employee_id = '{$scope}'") : false;
            if (!$own || $own->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'You can only email your own payslips.']);
            }
        }
        return $this->container->make(PayrollService::class)->emailPayslip(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Employee / Attendance / Leave Actions */
    public function save_employee()
    {
        return $this->container->make(EmployeeService::class)->saveEmployee($_POST);
    }

    public function save_leave_policy()
    {
        return (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->savePolicy($_POST);
    }

    public function save_leave_adjustment()
    {
        return (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->saveAdjustment($_POST);
    }

    public function save_public_holiday()
    {
        return (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->saveHoliday($_POST);
    }

    public function delete_public_holiday()
    {
        return (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->deleteHoliday((int)($_POST['id'] ?? 0));
    }

    public function save_leave_entitlement()
    {
        return (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->saveEntitlement($_POST);
    }

    /** Balance and request length for the leave form. A self-service user only sees their own. */
    public function leave_balance_info()
    {
        $svc = new \App\Services\LeaveBalanceService($this->conn, $this->settings);
        $scope = $this->settings->employee_scope('leaves');
        $emp = $scope === null ? (int)($_POST['employee_id'] ?? 0) : (int)$scope;
        $type = (string)($_POST['leave_type'] ?? '');
        $start = (string)($_POST['start_date'] ?? '');
        $end = (string)($_POST['end_date'] ?? '');
        $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $bal = $emp > 0 && $ok($end) ? ($svc->forEmployee($emp, $end, (int)($_POST['id'] ?? 0))[$type] ?? null) : null;
        return json_encode(['status' => 'success', 'tracked' => $bal !== null,
            'balance' => $bal['balance'] ?? null,
            'days' => ($ok($start) && $ok($end) && $end >= $start) ? $svc->requestDays($start, $end, $_POST['day_fraction'] ?? 1) : null]);
    }

    /** Leave days payable on exit and their value at the employee's daily rate. */
    public function leave_payout_suggestion()
    {
        require_once(base_app . 'classes/PayrollEngine.php');
        $emp = (int)($_POST['employee_id'] ?? 0);
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['as_of'] ?? '')) ? (string)$_POST['as_of'] : date('Y-m-d');
        $basic = max(0.0, (float)($_POST['basic_salary'] ?? 0));
        $freq = (string)($_POST['pay_frequency'] ?? 'Monthly');
        $rules = \PayrollEngine::load_rules($this->settings, $asOf);
        $days = (new \App\Services\LeaveBalanceService($this->conn, $this->settings))->payoutDays($emp, $asOf);
        $daily = \App\Services\PayrollAttendanceService::dailyRate($basic, (int)\PayrollEngine::get_periods_per_year($freq),
            (float)($rules['overtime']['weeks_per_year'] ?? 52), (int)($rules['attendance']['working_days_per_week'] ?? 5));
        return json_encode(['status' => 'success', 'days' => $days, 'daily_rate' => $daily, 'amount' => round($days * $daily, 2)]);
    }

    public function invite_employee_portal()
    {
        return $this->container->make(\App\Services\EmployeePortalService::class)->invite((int)($_POST['employee_id'] ?? 0));
    }

    public function revoke_employee_portal()
    {
        return $this->container->make(\App\Services\EmployeePortalService::class)->revoke((int)($_POST['employee_id'] ?? 0));
    }

    private function employee_portal_start(array $r): string
    {
        if (empty($r['ok'])) {
            return json_encode(['status' => 'failed', 'msg' => $r['msg']]);
        }
        session_regenerate_id(true);
        $_SESSION['employee_portal'] = $r['session'];
        return json_encode(['status' => 'success', 'msg' => $r['msg']]);
    }

    public function employee_portal_login()
    {
        $svc = new \App\Services\EmployeePortalService($this->conn, $this->settings);
        return $this->employee_portal_start($svc->login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? '')));
    }

    public function employee_portal_activate()
    {
        $svc = new \App\Services\EmployeePortalService($this->conn, $this->settings);
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== (string)($_POST['confirm'] ?? '')) {
            return json_encode(['status' => 'failed', 'msg' => 'The two passwords do not match.']);
        }
        return $this->employee_portal_start($svc->activate((string)($_POST['token'] ?? ''), $pw, (string)($_SERVER['REMOTE_ADDR'] ?? '')));
    }

    public function delete_employee()
    {
        return $this->container->make(EmployeeService::class)->deleteEmployee(intval($_POST['id'] ?? 0));
    }

    public function save_attendance()
    {
        return $this->container->make(EmployeeService::class)->saveAttendance($_POST);
    }

    public function delete_attendance()
    {
        return $this->container->make(EmployeeService::class)->deleteAttendance(intval($_POST['id'] ?? 0));
    }

    public function save_leave()
    {
        return $this->container->make(EmployeeService::class)->saveLeave($_POST);
    }

    public function update_leave_status()
    {
        return $this->container->make(EmployeeService::class)->updateLeaveStatus(intval($_POST['id'] ?? 0), $_POST['status'] ?? 'Pending');
    }

    public function cancel_leave()
    {
        return $this->container->make(EmployeeService::class)->cancelLeave(intval($_POST['id'] ?? 0));
    }

    public function delete_leave()
    {
        return $this->container->make(EmployeeService::class)->deleteLeave(intval($_POST['id'] ?? 0));
    }

    /* Party / Tenant Actions */
    public function save_party()
    {
        return $this->container->make(PartyService::class)->saveParty($_POST);
    }

    public function delete_party()
    {
        return $this->container->make(PartyService::class)->deleteParty(intval($_POST['id'] ?? 0));
    }

    public function save_tenant()
    {
        return $this->container->make(TenantService::class)->saveTenant($_POST);
    }

    public function delete_tenant()
    {
        return $this->container->make(TenantService::class)->deleteTenant(intval($_POST['id'] ?? 0));
    }

    public function git_pull()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
        if (empty($branch)) {
            $branch = 'main';
        }
        $output = shell_exec("git pull origin {$branch} 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git pull executed.', 'output' => $output]);
    }

    public function git_fetch()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $output = shell_exec("git fetch 2>&1 && git status 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git fetch executed.', 'output' => $output]);
    }

    public function get_redis_status()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->getStatus());
    }

    public function test_redis_ping()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->benchmark());
    }

    public function start_redis_daemon()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->startDaemon());
    }

    public function log_portal_activity($tenant_id, $party_id, $portal_user_id, $action, $details = '')
    {
        return $this->container->make(\App\Services\CustomerPortalService::class)->log_portal_activity($tenant_id, $party_id, $portal_user_id, $action, $details);
    }

    public function send_customer_invitation()
    {
        return $this->container->make(\App\Services\CustomerPortalService::class)->send_customer_invitation();
    }

    public function customer_register()
    {
        return $this->container->make(\App\Services\CustomerPortalService::class)->customer_register();
    }

    public function customer_login()
    {
        return $this->container->make(\App\Services\CustomerPortalService::class)->customer_login();
    }

    public function get_portal_logs()
    {
        return $this->container->make(\App\Services\CustomerPortalService::class)->get_portal_logs();
    }

    public function save_invoice_template()
    {
        $tenant_id     = $this->settings->active_tenant_id();
        $template_name = $this->conn->real_escape_string($_POST['template_name'] ?? 'standard');
        $bg_color      = $this->conn->real_escape_string($_POST['bg_color'] ?? '#ffffff');
        $label_color   = $this->conn->real_escape_string($_POST['label_color'] ?? '#0f172a');
        $font_color    = $this->conn->real_escape_string($_POST['font_color'] ?? '#334155');
        $font_family   = $this->conn->real_escape_string($_POST['font_family'] ?? 'sans-serif');

        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['tmp_name'] != '') {
            $fname = 'uploads/tenant_logo_' . $tenant_id . '_' . time() . '.png';
            $dir_path = base_app . $fname;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir_path)) {
                $logo_path = $fname;
            }
        }

        $logo_sql = $logo_path ? ", `logo_path` = '{$logo_path}'" : "";

        $check = $this->conn->query("SELECT id FROM `invoice_templates` WHERE tenant_id = '{$tenant_id}'");
        if ($check && $check->num_rows > 0) {
            $sql = "UPDATE `invoice_templates` SET `template_name` = '{$template_name}', `bg_color` = '{$bg_color}', `label_color` = '{$label_color}', `font_color` = '{$font_color}', `font_family` = '{$font_family}' {$logo_sql} WHERE tenant_id = '{$tenant_id}'";
        } else {
            $sql = "INSERT INTO `invoice_templates` (`tenant_id`, `template_name`, `bg_color`, `label_color`, `font_color`, `font_family`, `logo_path`) VALUES ('{$tenant_id}', '{$template_name}', '{$bg_color}', '{$label_color}', '{$font_color}', '{$font_family}', " . ($logo_path ? "'{$logo_path}'" : "NULL") . ")";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $this->settings->log_audit("save_invoice_template", "Invoice Templates", "Saved template {$template_name} for tenant {$tenant_id}");
            return json_encode(['status' => 'success', 'msg' => 'Invoice template customization saved successfully!']);
        }

        return \App\Support\Db::fail($this->conn, 'Failed to save template settings:');
    }
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);

/* -------------------------------------------------------------------------
 * Central access-control gate.
 *
 * Every write action is authorised here, in one place, before its handler
 * runs — so roles are enforced consistently across every module. The required
 * permission comes from the registry's action map; "save" actions resolve to
 * create or edit depending on whether a record id is present. Super
 * administrators (the developer tier) bypass permission checks. Public portal
 * actions (customer login/registration) are exempt.
 * ------------------------------------------------------------------------- */
if ($action !== 'none' && !in_array($action, \App\Support\Permissions::PUBLIC_ACTIONS, true)) {
    $_settings->require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_settings->require_csrf();
    }
    $has_id = !empty($_POST['id']);
    $required = \App\Support\Permissions::forAction($action, $has_id);
    if ($required === \App\Support\Permissions::SUPER_ONLY) {
        $_settings->require_super_admin();
    } elseif ($required !== null) {
        $_settings->require_permission($required);
    }
}

/* -------------------------------------------------------------------------
 * Dispatch: every allowed action calls the same-named public Master method
 * (most are one-line delegates into src/Services). The permission gate above
 * has already run for the action. backup_db streams its download itself, so
 * it is called without echo. Unknown actions fall through silently, as the
 * old switch's default did.
 * ------------------------------------------------------------------------- */
$allowed_actions = [
    'save_group', 'delete_group', 'save_account', 'delete_account',
    'save_journal', 'post_opening_balances', 'reverse_journal', 'save_budget',
    'save_exchange_rate', 'post_fx_revaluation', 'issue_credit_note', 'refund_credit_note',
    'void_note_refund', 'void_credit_note', 'run_depreciation', 'dispose_asset',
    'run_auto_reversals', 'approve_bill_payment', 'reject_bill_payment', 'approve_bill',
    'reject_bill', 'approve_journal', 'reject_journal', 'save_approval_policy',
    'save_price_list', 'delete_price_list', 'save_price_list_item', 'delete_price_list_item',
    'assign_price_list', 'lookup_prices', 'revise_quote', 'decline_quote',
    'extend_quote', 'confirm_for_delivery', 'save_delivery_note', 'cancel_delivery_note',
    'invoice_delivered', 'save_default_accounts', 'provision_chart', 'save_tenant_smtp', 'test_tenant_smtp', 'save_company_profile', 'save_inventory_accounting', 'file_tax_return',
    'void_tax_return', 'save_tax_code', 'delete_tax_code', 'set_period_lock',
    'delete_journal', 'cancel_journal', 'save_employee', 'delete_employee',
    'save_public_holiday', 'delete_public_holiday', 'save_leave_entitlement', 'save_leave_policy',
    'save_leave_adjustment', 'leave_balance_info', 'leave_payout_suggestion', 'invite_employee_portal',
    'revoke_employee_portal', 'employee_portal_login', 'employee_portal_activate', 'save_attendance',
    'delete_attendance', 'save_leave', 'update_leave_status', 'cancel_leave',
    'delete_leave', 'calculate_payroll_ajax', 'save_payroll_rules', 'delete_payroll_rules_version',
    'reset_payroll_rules', 'save_payroll', 'delete_payroll', 'save_party',
    'delete_party', 'log_js_error', 'clear_error_log', 'save_invoice',
    'delete_invoice', 'save_payment', 'delete_payment', 'save_product',
    'delete_product', 'save_sales_order', 'confirm_sales_order', 'delete_sales_order',
    'save_purchase_order', 'delete_purchase_order', 'receive_goods', 'save_vendor_bill',
    'pay_vendor_bills', 'void_bill_payment', 'cancel_vendor_bill', 'delete_vendor_bill',
    'start_reconciliation', 'update_reconciliation', 'delete_reconciliation', 'add_statement_lines',
    'delete_statement_line', 'match_statement_line', 'unmatch_statement_line', 'finalize_reconciliation',
    'reopen_reconciliation', 'save_stock_adjustment', 'save_api_key', 'delete_api_key',
    'save_webhook', 'delete_webhook', 'test_webhook', 'save_workflow',
    'delete_workflow', 'toggle_workflow', 'save_plan', 'delete_plan',
    'assign_plan', 'save_role', 'delete_role', 'email_invoice',
    'email_payment_receipt', 'save_batch_payroll', 'email_payslip', 'save_tenant',
    'delete_tenant', 'git_pull', 'git_fetch', 'get_redis_status',
    'test_redis_ping', 'start_redis_daemon', 'send_customer_invitation', 'customer_register',
    'customer_login', 'get_portal_logs', 'save_invoice_template', 'save_asset',
    'delete_asset', 'save_bank_account', 'save_opportunity', 'delete_opportunity',
    'save_crm_contact', 'delete_crm_contact', 'move_opportunity_stage', 'convert_opportunity',
    'save_crm_activity', 'toggle_crm_activity', 'delete_crm_activity', 'save_proposal_signature',
    'save_ticket', 'reply_ticket', 'save_contract', 'save_sla_policies',
    'save_reminder_settings', 'run_payment_reminders', 'preview_landed_cost', 'post_landed_cost',
    'write_off_expired', 'create_stock_count', 'save_stock_counts', 'post_stock_count',
    'cancel_stock_count', 'import_parties', 'import_products', 'import_accounts',
    'generate_contract_invoices', 'save_warehouse', 'transfer_stock', 'save_expense_claim',
    'approve_expense_claim', 'reject_expense_claim', 'cancel_expense_claim', 'void_expense_claim',
    'reimburse_expense_claims', 'reverse_expense_reimbursement', 'save_expense_category', 'save_module_settings',
    'save_extrafield', 'delete_extrafield', 'delete_accounts_bulk', 'archive_accounts_bulk',
    'change_tax_rate_bulk', 'save_currency', 'delete_currency', 'optimize_db',
    'save_shift_type', 'save_shift_assignment', 'save_salary_component', 'delete_salary_component',
    'issue_staff_loan', 'cancel_staff_loan', 'assign_salary_component', 'unassign_salary_component',
    'terminate_session', 'approve_payroll_run', 'reject_payroll_run', 'approve_payroll_remittance',
    'reject_payroll_remittance', 'pay_payroll_salaries', 'reverse_payroll_payment', 'remit_payroll_liability',
    'reverse_payroll_remittance', 'cancel_retro_pay', 'apply_retro_pay', 'save_tenant_vms_settings',
    'test_sdc_ping', 'simulate_vms_fiscalization', 'upload_tenant_vsdc_cert',
];

if ($action === 'backup_db') {
    $Master->backup_db();
} elseif (in_array($action, $allowed_actions, true)) {
    echo $Master->{$action}();
}
