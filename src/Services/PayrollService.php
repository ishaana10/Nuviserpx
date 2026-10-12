<?php

declare(strict_types=1);

namespace App\Services;

use Emailer;
use FijiPayroll;
use mysqli;
use SystemSettings;

class PayrollService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function calculatePayrollAjax(array $post): string
    {
        require_once(base_app . 'classes/FijiPayroll.php');
        $calc = FijiPayroll::calculate($post);
        return json_encode(['status' => 'success', 'data' => $calc]);
    }

    public function savePayroll(array $post): string
    {
        require_once(base_app . 'classes/FijiPayroll.php');
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();

        $payroll_run_ref = !empty($payroll_run_ref) ? $this->conn->real_escape_string((string)$payroll_run_ref) : 'RUN-' . date('Ym') . '-' . rand(100, 999);
        $salary_month = !empty($salary_month) ? $this->conn->real_escape_string((string)$salary_month) : date('F Y');
        $pay_frequency = !empty($pay_frequency) ? $this->conn->real_escape_string((string)$pay_frequency) : 'Monthly';
        $pay_date = !empty($pay_date) ? $this->conn->real_escape_string((string)$pay_date) : date('Y-m-d');
        $period_start = !empty($period_start) ? $this->conn->real_escape_string((string)$period_start) : date('Y-m-01');
        $period_end = !empty($period_end) ? $this->conn->real_escape_string((string)$period_end) : date('Y-m-t');

        $employee_id = isset($employee_id) ? $this->conn->real_escape_string((string)$employee_id) : '0';

        // Get Employee details
        $emp_qry = $this->conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` WHERE id = '{$employee_id}' and tenant_id = '{$tenant_id}'");
        if (!$emp_qry || $emp_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Employee not found.']);
        }
        $emp_data = $emp_qry->fetch_assoc();

        $basic_salary = isset($basic_salary) ? (float)$basic_salary : (float)($emp_data['salary'] ?? 0);
        $overtime = isset($overtime) ? (float)$overtime : 0.0;
        $allowances = isset($allowances) ? (float)$allowances : 0.0;
        $other_earnings = isset($other_earnings) ? (float)$other_earnings : 0.0;
        $deductions = isset($deductions) ? (float)$deductions : 0.0;
        $is_resident = isset($is_resident) ? (int)$is_resident : (int)($emp_data['is_resident'] ?? 1);

        // Calculate Fiji Payroll
        $calc = FijiPayroll::calculate([
            'basic_salary' => $basic_salary,
            'overtime' => $overtime,
            'allowances' => $allowances,
            'other_earnings' => $other_earnings,
            'deductions' => $deductions,
            'is_resident' => $is_resident,
            'pay_frequency' => $pay_frequency
        ]);

        $gross_salary = $calc['gross_salary'];
        $fnpf_base = $calc['fnpf_base'];
        $employee_fnpf = $calc['employee_fnpf'];
        $employer_fnpf = $calc['employer_fnpf'];
        $taxable_income = $calc['taxable_income'];
        $paye_tax = $calc['paye_tax'];
        $srt_tax = $calc['srt_tax'];
        $net_salary = $calc['net_salary'];
        $workcare_levy = $calc['workcare_levy'];
        $training_levy = $calc['training_levy'];
        $employer_cost = $calc['employer_cost'];

        $tin = $this->conn->real_escape_string((string)($emp_data['tin'] ?? ''));
        $fnpf_no = $this->conn->real_escape_string((string)($emp_data['fnpf_no'] ?? ''));
        $tax_code = $this->conn->real_escape_string((string)($emp_data['tax_code'] ?? 'P'));
        $bank_code = $this->conn->real_escape_string((string)($emp_data['bank_code'] ?? ''));
        $bank_account = $this->conn->real_escape_string((string)($emp_data['bank_account'] ?? ''));

        // Accounts for Journal Entry
        $salary_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%salary%' OR `name` LIKE '%expense%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $salary_acc_id = ($salary_acc && $salary_acc->num_rows > 0) ? $salary_acc->fetch_assoc()['id'] : 1;

        $cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $cash_acc_id = ($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1;

        $prefix = date("Ym-");
        $code = sprintf("%'.05d", 1);
        while (true) {
            $check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
            if ($check > 0) {
                $code = sprintf("%'.05d", ceil((float)$code) + 1);
            } else {
                break;
            }
        }
        $journal_code = $prefix . $code;
        $user_id = $this->settings->userdata('id');
        $journal_date = $pay_date;

        $empName = $emp_data['name'] ?? '';
        $description = $this->conn->real_escape_string("Fiji Payroll Disbursement for {$empName} ({$salary_month}) - Gross: \${$gross_salary}, Net: \${$net_salary}");

        $id = !empty($id) ? intval($id) : 0;
        if (empty($id)) {
            $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
            $this->conn->query($j_sql);
            $journal_id = $this->conn->insert_id;

            $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$salary_acc_id}', '1', '{$net_salary}'),
				('{$journal_id}', '{$cash_acc_id}', '2', '{$net_salary}')";
            $this->conn->query($j_items);

            $sql = "INSERT INTO `payroll_list` SET
				`tenant_id` = '{$tenant_id}',
				`employee_id` = '{$employee_id}',
				`journal_id` = '{$journal_id}',
				`payroll_run_ref` = '{$payroll_run_ref}',
				`salary_month` = '{$salary_month}',
				`pay_frequency` = '{$pay_frequency}',
				`pay_date` = '{$pay_date}',
				`period_start` = '{$period_start}',
				`period_end` = '{$period_end}',
				`basic_salary` = '{$basic_salary}',
				`overtime` = '{$overtime}',
				`allowances` = '{$allowances}',
				`other_earnings` = '{$other_earnings}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`srt_tax` = '{$srt_tax}',
				`deductions` = '{$deductions}',
				`net_salary` = '{$net_salary}',
				`workcare_levy` = '{$workcare_levy}',
				`training_levy` = '{$training_levy}',
				`employer_cost` = '{$employer_cost}',
				`tin` = '{$tin}',
				`fnpf_no` = '{$fnpf_no}',
				`tax_code` = '{$tax_code}',
				`is_resident` = '{$is_resident}',
				`bank_code` = '{$bank_code}',
				`bank_account` = '{$bank_account}'";
        } else {
            $existing = $this->conn->query("SELECT journal_id FROM `payroll_list` WHERE id = '{$id}'")->fetch_assoc();
            $journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
            if ($journal_id) {
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
                $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$salary_acc_id}', '1', '{$net_salary}'),
					('{$journal_id}', '{$cash_acc_id}', '2', '{$net_salary}')";
                $this->conn->query($j_items);
            }

            $sql = "UPDATE `payroll_list` SET
				`employee_id` = '{$employee_id}',
				`payroll_run_ref` = '{$payroll_run_ref}',
				`salary_month` = '{$salary_month}',
				`pay_frequency` = '{$pay_frequency}',
				`pay_date` = '{$pay_date}',
				`period_start` = '{$period_start}',
				`period_end` = '{$period_end}',
				`basic_salary` = '{$basic_salary}',
				`overtime` = '{$overtime}',
				`allowances` = '{$allowances}',
				`other_earnings` = '{$other_earnings}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`srt_tax` = '{$srt_tax}',
				`deductions` = '{$deductions}',
				`net_salary` = '{$net_salary}',
				`workcare_levy` = '{$workcare_levy}',
				`training_levy` = '{$training_levy}',
				`employer_cost` = '{$employer_cost}',
				`tin` = '{$tin}',
				`fnpf_no` = '{$fnpf_no}',
				`tax_code` = '{$tax_code}',
				`is_resident` = '{$is_resident}',
				`bank_code` = '{$bank_code}',
				`bank_account` = '{$bank_account}'
				WHERE id = '{$id}'";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Fiji Payroll processed and Journal Entry created successfully." : " Payroll updated successfully.";
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving payroll.";
            $resp['err'] = $this->conn->error;
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deletePayroll(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $existing = $this->conn->query("SELECT journal_id FROM `payroll_list` WHERE id = '{$id}' and tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!empty($existing['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$existing['journal_id']}' and tenant_id = '{$tenant_id}'");
        }
        $del = $this->conn->query("DELETE FROM `payroll_list` WHERE id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Payroll record deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function emailPayslip(int $id, ?string $custom_email = null): string
    {
        require_once(base_app . 'classes/Emailer.php');
        $tenant_id = $this->settings->active_tenant_id();

        $pay_qry = $this->conn->query("
			SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.phone
			FROM `payroll_list` p
			INNER JOIN `employee_list` e ON p.employee_id = e.id
			WHERE p.id = '{$id}' AND p.tenant_id = '{$tenant_id}'
		");

        if (!$pay_qry || $pay_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Payroll record not found.']);
        }

        $payroll = $pay_qry->fetch_assoc();
        $email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : '';

        if (empty($email_to)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please enter the employee email address.']);
        }

        $sys_info = [
            'name' => $this->settings->info('name'),
            'email' => $this->settings->info('email')
        ];

        $send = Emailer::send_payslip_email($email_to, $payroll, $sys_info);
        if ($send) {
            return json_encode(['status' => 'success', 'msg' => "Payslip for {$payroll['emp_name']} ({$payroll['salary_month']}) sent successfully to {$email_to}."]);
        } else {
            return json_encode(['status' => 'failed', 'msg' => 'Failed to send payslip email. Please check SMTP settings.']);
        }
    }
}
