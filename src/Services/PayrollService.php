<?php

declare(strict_types=1);

namespace App\Services;

use Emailer;
use PayrollEngine;
use mysqli;
use SystemSettings;

class PayrollService
{
    private bool $inTransaction = false;
    private int $lastPayrollId = 0;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Salary components and unpaid-leave deduction for a payroll.
     * New payroll: the employee's active components, and unpaid days from approved leave and logged absences (or the
     * days typed in). Edit: what the payroll already paid, unless unpaid days are typed in.
     *
     * @param mixed $override typed unpaid days; blank / null means automatic
     * @return array{0:array,1:float,2:float} component lines, unpaid days, unpaid deduction
     */
    private function payInputs(int $employeeId, int $editId, string $payDate, string $from, string $to, string $frequency, float $basic, array $rules, $override): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $components = new PayrollComponentService($this->conn, $this->settings);
        $lines = $editId > 0 ? $components->forPayroll($editId)
            : array_merge($components->assignedFor($employeeId, $payDate), (new PayrollLoanService($this->conn, $this->settings))->dueFor($employeeId, $to));

        $dpw = (int)($rules['attendance']['working_days_per_week'] ?? 5);
        if ($override !== null && $override !== '' && is_numeric($override)) {
            $days = max(0.0, round((float)$override, 2));
        } elseif ($editId > 0) {
            $q = $this->conn->prepare("SELECT unpaid_days FROM payroll_list WHERE id = ? AND tenant_id = ?");
            $q->bind_param('ii', $editId, $t);
            $q->execute();
            $days = (float)($q->get_result()->fetch_assoc()['unpaid_days'] ?? 0);
        } else {
            $days = (new PayrollAttendanceService($this->conn, $this->settings))->unpaidDaysFor($employeeId, $from, $to, $dpw, (bool)($rules['attendance']['prorate_joiners'] ?? true));
        }
        $daily = PayrollAttendanceService::dailyRate($basic, (int)PayrollEngine::get_periods_per_year($frequency), (float)($rules['overtime']['weeks_per_year'] ?? 52), $dpw);
        return [$lines, $days, PayrollAttendanceService::deduction($days, $daily, $basic)];
    }

    /**
     * Run the engine; if deductions would push net pay below zero, take the shortfall off loan recoveries (last first)
     * and run it again (new payroll only). $input['components'] is updated to the lines actually used.
     */
    private function calculateCapped(array &$input, bool $cap = true): array
    {
        $calc = PayrollEngine::calculate($input);
        if ($cap && $calc['net_salary'] < 0 && (float)$calc['loan_recovery'] > 0) {
            $input['components'] = PayrollLoanService::capToShortfall((array)$input['components'], -(float)$calc['net_salary']);
            $calc = PayrollEngine::calculate($input);
        }
        return $calc;
    }

    public function calculatePayrollAjax(array $post): string
    {
        require_once(base_app . 'classes/PayrollEngine.php');
        $payDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['pay_date'] ?? '')) ? (string)$post['pay_date'] : date('Y-m-d');
        $post['rules'] = PayrollEngine::load_rules($this->settings, $payDate);
        if (!empty($post['employee_id'])) {
            [$post['pay_period_n'], $post['g_ytd_prior'], $post['t_ytd_prior']] =
                $this->ytdFor((int)$post['employee_id'], $payDate, (string)($post['pay_frequency'] ?? 'Monthly'), (int)($post['id'] ?? 0), $post['rules']);
        }
        $isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) === 1;
        $from = $isDate($post['period_start'] ?? '') ? (string)$post['period_start'] : date('Y-m-01', strtotime($payDate));
        $to = $isDate($post['period_end'] ?? '') ? (string)$post['period_end'] : date('Y-m-t', strtotime($payDate));
        if (!empty($post['employee_id'])) {
            [$post['components'], $unpaidDays, $post['unpaid_deduction']] = $this->payInputs(
                (int)$post['employee_id'], (int)($post['id'] ?? 0), $payDate, $from, $to,
                (string)($post['pay_frequency'] ?? 'Monthly'), (float)($post['basic_salary'] ?? 0), $post['rules'], $post['unpaid_days'] ?? null);
        }
        $post['leave_payout'] = (float)($post['leave_payout'] ?? 0);
        $post['redundancy_payments'] = (float)($post['redundancy_pay'] ?? 0);
        $calc = $this->calculateCapped($post);
        $calc['unpaid_days'] = $unpaidDays ?? 0.0;
        $calc['component_lines'] = $post['components'] ?? [];
        return json_encode(['status' => 'success', 'data' => $calc]);
    }

    /**
     * Cumulative-tax inputs for an employee paid on $payDate: [period number, prior gross, prior tax].
     * Prior pay is the employee's earlier payrolls in the same tax year (not rejected, not this one).
     * Opening balances brought forward from a previous system count towards the first tax year the employee
     * appears in here. Editing an earlier payroll does not recalculate the later ones.
     */
    private function ytdFor(int $employeeId, string $payDate, string $frequency, int $excludeId, array $rules): array
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $taxYear = PayrollEngine::tax_year_start($payDate, $rules['tax_year_start_month']);
        $n = PayrollEngine::period_number($frequency, $taxYear, $payDate);
        $q = $this->conn->prepare("SELECT gross_salary, retro_pay, paye_tax, srt_tax, paye_lump FROM payroll_list
            WHERE tenant_id = ? AND employee_id = ? AND status NOT IN ('rejected','voided') AND id <> ? AND pay_date >= ? AND pay_date < ?");
        $q->bind_param('iiiss', $tenant, $employeeId, $excludeId, $taxYear, $payDate);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);

        $og = $ot = 0.0;
        $f = $this->conn->prepare("SELECT MIN(pay_date) first_pay FROM payroll_list WHERE tenant_id = ? AND employee_id = ? AND status NOT IN ('rejected','voided') AND id <> ? AND pay_date IS NOT NULL");
        $f->bind_param('iii', $tenant, $employeeId, $excludeId);
        $f->execute();
        $first = $f->get_result()->fetch_assoc()['first_pay'] ?? null;
        $firstYear = PayrollEngine::tax_year_start($first ?: $payDate, $rules['tax_year_start_month']);
        if ($firstYear === $taxYear) {
            $e = $this->conn->prepare("SELECT ytd_opening_gross, ytd_opening_paye FROM employee_list WHERE id = ? AND tenant_id = ?");
            $e->bind_param('ii', $employeeId, $tenant);
            $e->execute();
            $emp = $e->get_result()->fetch_assoc() ?: [];
            $og = (float)($emp['ytd_opening_gross'] ?? 0);
            $ot = (float)($emp['ytd_opening_paye'] ?? 0);
        }
        [$g, $t] = PayrollEngine::ytd_prior($rows, $og, $ot);
        return [$n, $g, $t];
    }

    /** Save the rules that apply from `effective_from` (a new version, or replace that date's version). */
    public function saveRules(array $post): string
    {
        require_once(base_app . 'classes/PayrollEngine.php');
        $from = trim((string)($post['effective_from'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter the date these rules take effect from.']);
        }
        $rules = json_decode((string)($post['rules_json'] ?? ''), true);
        $errors = PayrollEngine::validate_rules($rules);
        if ($errors) {
            return json_encode(['status' => 'failed', 'msg' => implode(' ', array_slice($errors, 0, 4))]);
        }
        foreach ($rules['employer_levies'] as &$l) {
            $l['label'] = trim(preg_replace('/[&<>"\'\\\\\x00-\x1f]/', '', (string)$l['label']));
            $l['key'] = (string)($l['key'] ?? '');
        }
        unset($l);
        foreach ($rules['overtime']['tiers'] as &$t) {
            $t['label'] = trim(preg_replace('/[&<>"\'\\\\\x00-\x1f]/', '', (string)$t['label']));
        }
        unset($t);
        $normalized = PayrollEngine::normalize_rules($rules);
        $setting = PayrollEngine::with_version(PayrollEngine::decode_setting($this->settings->payroll_rules_raw()), $from, $normalized);
        if (!$this->putRules(json_encode($setting))) {
            return json_encode(['status' => 'failed', 'msg' => 'The payroll rules could not be saved.']);
        }
        $this->settings->log_audit('Updated Payroll Rules', 'Payroll', "Payroll rules effective {$from} saved.");
        return json_encode(['status' => 'success', 'msg' => "Payroll rules saved, effective {$from}. Runs paid before that date keep the earlier rules."]);
    }

    /** Remove one dated version of the rules. */
    public function deleteRulesVersion(string $from): string
    {
        require_once(base_app . 'classes/PayrollEngine.php');
        $setting = PayrollEngine::without_version(PayrollEngine::decode_setting($this->settings->payroll_rules_raw()), $from);
        if ($setting['versions']) {
            if (!$this->putRules(json_encode($setting))) {
                return json_encode(['status' => 'failed', 'msg' => 'The payroll rules could not be saved.']);
            }
        } else {
            return $this->resetRules();
        }
        $this->settings->log_audit('Deleted Payroll Rules Version', 'Payroll', "Payroll rules effective {$from} removed.");
        return json_encode(['status' => 'success', 'msg' => "Rules effective {$from} removed."]);
    }

    /** Remove all company rules so payroll uses the starting preset again. */
    public function resetRules(): string
    {
        // An empty company setting (not a missing row) so the installation-wide default is not picked up again.
        if (!$this->putRules(json_encode(['versions' => []]))) {
            return json_encode(['status' => 'failed', 'msg' => 'The payroll rules could not be reset.']);
        }
        $this->settings->log_audit('Reset Payroll Rules', 'Payroll', 'Payroll rules reset to the starting preset.');
        return json_encode(['status' => 'success', 'msg' => 'Payroll rules reset to the starting preset.']);
    }

    /** Save this company's rules (one row per company). */
    private function putRules(string $value): bool
    {
        $t = (int)$this->settings->active_tenant_id();
        $by = (int)$this->settings->userdata('id');
        $up = $this->conn->prepare("INSERT INTO `payroll_rule_sets` (`tenant_id`, `setting`, `updated_by`) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE `setting` = VALUES(`setting`), `updated_by` = VALUES(`updated_by`)");
        $up->bind_param('isi', $t, $value, $by);
        return $up->execute();
    }

    public function savePayroll(array $post): string
    {
        require_once(base_app . 'classes/PayrollEngine.php');
        $post += ['id' => 0];
        $payroll_run_ref = $post['payroll_run_ref'] ?? null;
        $salary_month = $post['salary_month'] ?? null;
        $pay_frequency = $post['pay_frequency'] ?? null;
        $pay_date = $post['pay_date'] ?? null;
        $period_start = $post['period_start'] ?? null;
        $period_end = $post['period_end'] ?? null;
        $employee_id = $post['employee_id'] ?? null;
        $basic_salary = $post['basic_salary'] ?? null;
        $overtime = $post['overtime'] ?? null;
        $allowances = $post['allowances'] ?? null;
        $other_earnings = $post['other_earnings'] ?? null;
        $deductions = $post['deductions'] ?? null;
        $is_resident = $post['is_resident'] ?? null;
        $leave_payout = $post['leave_payout'] ?? null;
        $redundancy_pay = $post['redundancy_pay'] ?? null;
        $id = $post['id'] ?? null;
        $unpaid_days = $post['unpaid_days'] ?? null;
        $overtime_1_5_hours = $post['overtime_1_5_hours'] ?? null;
        $overtime_2_0_hours = $post['overtime_2_0_hours'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();

        try {
            $payroll_run_ref = !empty($payroll_run_ref) ? $this->conn->real_escape_string((string)$payroll_run_ref) : (new PayrollRunRefService($this->conn))->next((int)$tenant_id);
        } catch (\RuntimeException $e) {
            return json_encode(['status' => 'failed', 'msg' => $e->getMessage()]);
        }
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
        // Final pay: leave paid out (taxed as a lump sum) and redundancy / severance (exempt up to the cap, then flat rate).
        $leave_payout = isset($leave_payout) && $leave_payout !== '' ? max(0.0, (float)$leave_payout) : 0.0;
        $redundancy_pay = isset($redundancy_pay) && $redundancy_pay !== '' ? max(0.0, (float)$redundancy_pay) : 0.0;

        if ((int)($post['id'] ?? 0) === 0 && !empty($emp_data['termination_date']) && $period_start > $emp_data['termination_date']) {
            return json_encode(['status' => 'failed', 'msg' => "{$emp_data['name']} left on {$emp_data['termination_date']}, so no pay is due for a period that starts after that."]);
        }

        // Retroactive pay: a new payroll picks up the employee's pending adjustments; an edit keeps what it already paid.
        $adjustments = new PayrollAdjustmentService($this->conn, $this->settings);
        $editId = !empty($id) ? (int)$id : 0;
        $pendingAdj = [];
        if ($editId > 0) {
            $q = $this->conn->query("SELECT retro_pay FROM `payroll_list` WHERE id = '{$editId}' AND tenant_id = '{$tenant_id}'");
            $retro_pay = $q ? (float)($q->fetch_assoc()['retro_pay'] ?? 0) : 0.0;
        } else {
            $pendingAdj = $adjustments->pending((int)$employee_id, $period_end);
            $retro_pay = PayrollAdjustmentService::total($pendingAdj);
        }

        // Year-to-date position for the cumulative tax method
        $pay_rules = PayrollEngine::load_rules($this->settings, $pay_date);
        [$ytd_n, $ytd_g, $ytd_t] = $this->ytdFor((int)$employee_id, $pay_date, $pay_frequency, $editId, $pay_rules);

        [$compLines, $unpaid_days_used, $unpaid_deduction] = $this->payInputs(
            (int)$employee_id, $editId, $pay_date, $period_start, $period_end, $pay_frequency, $basic_salary, $pay_rules, $unpaid_days ?? null);

        // Calculate Payroll
        $calcInput = [
            'lump_sum_payments' => $retro_pay,
            'basic_salary' => $basic_salary,
            'overtime' => $overtime,
            'allowances' => $allowances,
            'other_earnings' => $other_earnings,
            'deductions' => $deductions,
            'components' => $compLines,
            'leave_payout' => $leave_payout,
            'redundancy_payments' => $redundancy_pay,
            'unpaid_deduction' => $unpaid_deduction,
            'is_resident' => $is_resident,
            'pay_frequency' => $pay_frequency,
            'overtime_1_5_hours' => (float)($overtime_1_5_hours ?? 0),
            'overtime_2_0_hours' => (float)($overtime_2_0_hours ?? 0),
            'pay_period_n' => $ytd_n,
            'g_ytd_prior' => $ytd_g,
            't_ytd_prior' => $ytd_t,
            'rules' => $pay_rules
        ];
        // An edit keeps the recoveries already taken off the loans, so only a new payroll may trim them.
        $calc = $this->calculateCapped($calcInput, $editId === 0);
        $compLines = $calcInput['components'];
        $overtime = (float)$calc['overtime']; // typed amount plus hours-based tiers

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
        $deductions = $calc['deductions']; // typed deductions plus salary-component deductions
        $comp_earnings = $calc['component_earnings'];
        $comp_deductions = $calc['component_deductions'];
        $loan_recovery = $calc['loan_recovery'];
        $paye_lump = $calc['paye_lump_sum'];

        $tin = $this->conn->real_escape_string((string)($emp_data['tin'] ?? ''));
        $fnpf_no = $this->conn->real_escape_string((string)($emp_data['fnpf_no'] ?? ''));
        $tax_code = $this->conn->real_escape_string((string)($emp_data['tax_code'] ?? 'P'));
        $bank_code = $this->conn->real_escape_string((string)($emp_data['bank_code'] ?? ''));
        $bank_account = $this->conn->real_escape_string((string)($emp_data['bank_account'] ?? ''));

        $journal_code = \App\Services\SequenceService::journalCode($this->conn, (int)$tenant_id);
        $user_id = $this->settings->userdata('id');
        $journal_date = $pay_date;

        $empName = $emp_data['name'] ?? '';
        $description = $this->conn->real_escape_string("Payroll for {$empName} ({$salary_month}) - Gross: {$gross_salary}, Net: {$net_salary}");

        $id = !empty($id) ? intval($id) : 0;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'payroll_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        if ($id > 0) {
            $st = $this->conn->query("SELECT status FROM `payroll_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
            if ($st && ($sr = $st->fetch_assoc())) {
                if ($sr['status'] === 'rejected') {
                    return json_encode(['status' => 'failed', 'msg' => 'This payroll was returned by the approver and cannot be edited. Run payroll again for the employee.']);
                }
                if ($sr['status'] === 'voided') {
                    return json_encode(['status' => 'failed', 'msg' => 'This payroll was voided and cannot be edited. Run payroll again for the employee.']);
                }
            }
        }
        // One payroll per employee per salary month, whether it is a single save, an edit that moves the month, or a batch.
        $dupQ = $this->conn->query("SELECT id FROM `payroll_list` WHERE employee_id = '{$employee_id}' AND salary_month = '{$salary_month}' AND tenant_id = '{$tenant_id}' AND status NOT IN ('rejected','voided') AND id <> '{$id}' LIMIT 1");
        if ($dupQ && $dupQ->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => "{$empName} already has payroll for {$salary_month}. Edit that payroll, or void it first if it was run in error."]);
        }
        $ownTx = !$this->inTransaction;
        if ($ownTx) {
            $this->conn->begin_transaction();
        }
        try {
        $posting = new PayrollPostingService($this->conn, $this->settings);
        if (empty($id) && $pendingAdj) {
            $locked = $adjustments->pending((int)$employee_id, $period_end, true);
            if (array_column($locked, 'id') != array_column($pendingAdj, 'id') || PayrollAdjustmentService::total($locked) !== $retro_pay) {
                throw new \RuntimeException('The retroactive pay adjustments changed while processing. Please try again.');
            }
        }
        if (empty($id)) {
            $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
            $this->conn->query($j_sql);
            $journal_id = $this->conn->insert_id;
            if (!$journal_id) {
                throw new \RuntimeException('The journal could not be created.');
            }
            $posting->writeJournalItems((int)$journal_id, $calc);

            $sql = "INSERT INTO `payroll_list` SET
				`tenant_id` = '{$tenant_id}',
				`employee_id` = '{$employee_id}',
				`created_by` = '" . (int)$user_id . "',
				`journal_id` = '{$journal_id}',
				`payment_status` = 'unpaid',
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
				`retro_pay` = '{$retro_pay}',
				`unpaid_days` = '{$unpaid_days_used}',
				`unpaid_deduction` = '{$unpaid_deduction}',
				`component_earnings` = '{$comp_earnings}',
				`component_deductions` = '{$comp_deductions}',
				`loan_recovery` = '{$loan_recovery}',
				`leave_payout` = '{$leave_payout}',
				`redundancy_pay` = '{$redundancy_pay}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`paye_lump` = '{$paye_lump}',
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
            $existing = $this->conn->query("SELECT journal_id, payment_status FROM `payroll_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}' FOR UPDATE")->fetch_assoc();
            if (($existing['payment_status'] ?? 'unpaid') === 'paid') {
                throw new \RuntimeException('This payroll has already been paid. Reverse the salary payment before changing it.');
            }
            $journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
            if ($journal_id) {
                $trail = new JournalTrail($this->conn, $this->settings);
                $trailBefore = $trail->snapshot((int)$journal_id);
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $posting->writeJournalItems((int)$journal_id, $calc);
                $trail->record((int)$journal_id, 'payroll_list', (int)$id, "Payroll {$id}", $trailBefore);
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
				`unpaid_days` = '{$unpaid_days_used}',
				`unpaid_deduction` = '{$unpaid_deduction}',
				`component_earnings` = '{$comp_earnings}',
				`component_deductions` = '{$comp_deductions}',
				`loan_recovery` = '{$loan_recovery}',
				`leave_payout` = '{$leave_payout}',
				`redundancy_pay` = '{$redundancy_pay}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`paye_lump` = '{$paye_lump}',
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
        if (!$save) {
            throw new \RuntimeException('save failed');
        }
        $payrollId = empty($id) ? (int)$this->conn->insert_id : (int)$id;
        $this->lastPayrollId = $payrollId;
        if (empty($id)) {
            (new PayrollComponentService($this->conn, $this->settings))->saveItems($payrollId, $compLines);
            (new PayrollLoanService($this->conn, $this->settings))->apply($payrollId, $compLines);
        }
        if (empty($id) && $pendingAdj) {
            $adjustments->markApplied(array_column($pendingAdj, 'id'), $payrollId);
        }
        // A single payroll is held for approval here; a batch run is held as a whole once every employee is saved.
        $held = $ownTx ? (new PayrollApprovalService($this->conn, $this->settings))->holdIfRequired([$payrollId]) : false;
        if ($ownTx) {
            $this->conn->commit();
        }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $this->conn->rollback();
            }
            if ($this->inTransaction) {
                throw $e; // the batch rolls back the whole run
            }
            return json_encode(['status' => 'failed', 'msg' => 'Payroll was not saved. ' . $e->getMessage()]);
        }
        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Payroll processed and Journal Entry created successfully." : " Payroll updated successfully.";
            if (!empty($held)) {
                $resp['msg'] .= ' It is waiting for approval and will not appear in reports until approved.';
            }
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving payroll.";
            \App\Support\Db::log($this->conn);
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    /**
     * Batch payroll: one payroll record + journal per selected employee, using
     * the run details and each employee's earnings overrides. All-or-nothing:
     * any failure rolls the whole run back so a run is never half-posted.
     * Employees already paid for the same salary period are skipped, never
     * double-paid.
     *
     * @param array<string,mixed> $post
     */
    public function savePayrollBatch(array $post): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($post['employee_ids'] ?? [])))));
        if (!$ids) {
            return json_encode(['status' => 'failed', 'msg' => 'Select at least one employee to process.']);
        }

        $run = [];
        foreach (['payroll_run_ref', 'salary_month', 'pay_frequency', 'pay_date', 'period_start', 'period_end'] as $f) {
            if (isset($post[$f])) {
                $run[$f] = (string)$post[$f];
            }
        }
        if (empty($run['payroll_run_ref'])) {
            try {
                $run['payroll_run_ref'] = (new PayrollRunRefService($this->conn))->next((int)$this->settings->active_tenant_id());
            } catch (\RuntimeException $e) {
                return json_encode(['status' => 'failed', 'msg' => $e->getMessage()]);
            }
        }
        $month = $this->conn->real_escape_string((string)($run['salary_month'] ?? date('F Y')));

        $processed = 0;
        $createdIds = [];
        $heldRun = false;
        $skipped = [];
        $this->conn->begin_transaction();
        $this->inTransaction = true;
        try {
            foreach ($ids as $eid) {
                $emp = $this->conn->query("SELECT id, concat(firstname,' ',lastname) AS name FROM `employee_list` WHERE id = '{$eid}' AND tenant_id = '{$tenant_id}' AND status = 1 AND delete_flag = 0");
                if (!$emp || $emp->num_rows === 0) {
                    throw new \RuntimeException("Employee #{$eid} was not found in this company.");
                }
                $name = $emp->fetch_assoc()['name'];
                $lv = $this->conn->query("SELECT termination_date FROM `employee_list` WHERE id = '{$eid}' AND tenant_id = '{$tenant_id}'");
                $left = $lv ? ($lv->fetch_assoc()['termination_date'] ?? null) : null;
                if ($left && isset($run['period_start']) && $run['period_start'] > $left) {
                    $skipped[] = $name . ' (left ' . $left . ')';
                    continue;
                }
                $dup = $this->conn->query("SELECT id FROM `payroll_list` WHERE employee_id = '{$eid}' AND salary_month = '{$month}' AND tenant_id = '{$tenant_id}' AND status NOT IN ('rejected','voided') LIMIT 1");
                if ($dup && $dup->num_rows > 0) {
                    $skipped[] = $name;
                    continue;
                }
                $row = $run + [
                    'id'             => 0,
                    'employee_id'    => $eid,
                    'overtime'       => (float)($post['emp_overtime'][$eid] ?? 0),
                    'overtime_1_5_hours' => (float)($post['emp_ot1_hours'][$eid] ?? 0),
                    'overtime_2_0_hours' => (float)($post['emp_ot2_hours'][$eid] ?? 0),
                    'allowances'     => (float)($post['emp_allowances'][$eid] ?? 0),
                    'other_earnings' => (float)($post['emp_other_earnings'][$eid] ?? 0),
                    'deductions'     => (float)($post['emp_deductions'][$eid] ?? 0),
                    'unpaid_days'    => $post['emp_unpaid_days'][$eid] ?? '',
                ];
                if (isset($post['emp_basic'][$eid]) && $post['emp_basic'][$eid] !== '') {
                    $row['basic_salary'] = (float)$post['emp_basic'][$eid];
                }
                $res = json_decode($this->savePayroll($row), true);
                if (!is_array($res) || ($res['status'] ?? '') !== 'success') {
                    throw new \RuntimeException("Could not process {$name}" . (!empty($res['err']) ? ': ' . $res['err'] : '.'));
                }
                $processed++;
                $createdIds[] = $this->lastPayrollId;
            }
            $heldRun = (new PayrollApprovalService($this->conn, $this->settings))->holdIfRequired($createdIds);
            $this->conn->commit();
            $this->inTransaction = false;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $this->inTransaction = false;
            return json_encode(['status' => 'failed', 'msg' => 'Batch payroll was not saved. ' . $e->getMessage()]);
        }

        if ($processed === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Nothing to process — every selected employee already has payroll for ' . ($run['salary_month'] ?? 'this period') . '.']);
        }
        $msg = "Batch payroll processed for {$processed} employee" . ($processed === 1 ? '' : 's') . '.';
        if ($heldRun) {
            $msg .= ' The run is waiting for approval and will not appear in reports until approved.';
        }
        if ($skipped) {
            $msg .= ' Skipped (already paid this period, or left before it): ' . implode(', ', $skipped) . '.';
        }
        $this->settings->log_audit('Processed Batch Payroll', 'Payroll', "{$run['payroll_run_ref']}: {$processed} employees");
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'processed' => $processed, 'skipped' => $skipped]);
    }

    /**
     * Remove a payroll. Payroll that never reached the ledger (waiting for approval, or returned) is deleted with its
     * journal. Posted payroll is voided instead: its journal is reversed, never deleted, and the record stays for audit.
     * Refused once salary is paid, or once the liabilities it created have been remitted.
     */
    public function deletePayroll(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, $tenant_id, null, 'payroll_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        $q = $this->conn->prepare("SELECT id, journal_id, payment_status, status, payroll_run_ref FROM `payroll_list` WHERE id = ? AND tenant_id = ?");
        $q->bind_param('ii', $id, $tenant_id);
        $q->execute();
        $existing = $q->get_result()->fetch_assoc();
        if (!$existing) {
            return json_encode(['status' => 'failed', 'msg' => 'Payroll not found.']);
        }
        if (($existing['payment_status'] ?? 'unpaid') === 'paid') {
            return json_encode(['status' => 'failed', 'msg' => 'This payroll has already been paid. Reverse the salary payment before voiding it.']);
        }
        if (($existing['status'] ?? '') === 'voided') {
            return json_encode(['status' => 'failed', 'msg' => 'This payroll has already been voided.']);
        }
        $journalId = (int)($existing['journal_id'] ?? 0);
        $res = [];

        if (($existing['status'] ?? '') === 'posted') {
            if ($journalId > 0) {
                if ($msg = $this->remittedAgainst($journalId, $tenant_id)) {
                    return json_encode(['status' => 'failed', 'msg' => $msg]);
                }
                $jq = $this->conn->prepare("SELECT journal_date FROM journal_entries WHERE id = ? AND tenant_id = ?");
                $jq->bind_param('ii', $journalId, $tenant_id);
                $jq->execute();
                $jd = (string)($jq->get_result()->fetch_assoc()['journal_date'] ?? '');
                $date = max(date('Y-m-d'), $jd);
                $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal($journalId, $date, true), true);
                if (!is_array($res) || ($res['status'] ?? '') !== 'success') {
                    return json_encode(['status' => 'failed', 'msg' => (string)($res['msg'] ?? 'The payroll journal could not be reversed.')]);
                }
            }
            $u = $this->conn->prepare("UPDATE `payroll_list` SET status = 'voided' WHERE id = ? AND tenant_id = ? AND status = 'posted'");
            $u->bind_param('ii', $id, $tenant_id);
            if (!$u->execute() || $u->affected_rows < 1) {
                return json_encode(['status' => 'failed', 'msg' => 'The journal was reversed but the payroll could not be marked void. Contact support before retrying.']);
            }
            (new PayrollAdjustmentService($this->conn, $this->settings))->release($id);
            (new PayrollLoanService($this->conn, $this->settings))->release($id);
            $this->settings->log_audit('Voided payroll', 'Payroll', "Payroll {$id} ({$existing['payroll_run_ref']}) voided; journal {$journalId} reversed by {$res['code']}.");
            $msg = 'Payroll voided and its journal reversed (' . ($res['code'] ?? 'no journal') . ').';
            $this->settings->set_flashdata('success', $msg);
            return json_encode(['status' => 'success', 'msg' => $msg]);
        }

        // Never reached the ledger: safe to remove outright.
        if ($journalId > 0) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$journalId}' and tenant_id = '{$tenant_id}'");
        }
        (new PayrollAdjustmentService($this->conn, $this->settings))->release($id);
        (new PayrollLoanService($this->conn, $this->settings))->release($id);
        $del = $this->conn->query("DELETE FROM `payroll_list` WHERE id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted payroll', 'Payroll', "Payroll {$id} ({$existing['payroll_run_ref']}) deleted before posting.");
            $this->settings->set_flashdata('success', ' Payroll record deleted successfully.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    /** Error message if reversing this payroll journal would take a payroll liability below what is still owed. */
    private function remittedAgainst(int $journalId, int $tenant_id): ?string
    {
        $side = \App\Services\LedgerService::SIDE_SQL;
        $q = $this->conn->prepare("SELECT a.name, SUM(ji.amount) amt FROM journal_items ji
            JOIN account_list a ON a.id = ji.account_id
            LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE ji.journal_id = ? AND {$side} = 2 GROUP BY a.name");
        $q->bind_param('i', $journalId);
        $q->execute();
        $credits = [];
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $credits[$r['name']] = (float)$r['amt'];
        }
        $remit = new PayrollRemittanceService($this->conn, $this->settings);
        foreach (PayrollRemittanceService::LIABILITIES as $key => [$label, $accKey]) {
            $name = PayrollPostingService::ACCOUNTS[$accKey][0];
            if (isset($credits[$name]) && $remit->outstanding($key) + 0.005 < $credits[$name]) {
                return "{$label} from this payroll have already been remitted. Reverse the remittance before voiding the payroll.";
            }
        }
        return null;
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
        if (($payroll['status'] ?? 'posted') !== 'posted') {
            return json_encode(['status' => 'failed', 'msg' => 'This payroll is not approved yet, so the payslip cannot be sent.']);
        }
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
