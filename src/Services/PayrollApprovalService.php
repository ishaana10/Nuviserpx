<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Maker/checker for payroll. A payroll run (all employees sharing a run reference) and a payroll remittance can
 * be held for approval by a second person when the company's policy says so. Until approved the payroll's journal
 * is "pending_approval", which keeps it out of every report, and the payroll rows stay out of exports, schedules
 * and dashboards. Returning a run cancels its journals and frees its retroactive pay so it can be run again.
 */
class PayrollApprovalService
{
    public const PENDING = 'pending_approval';

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    private function policy(string $doc): array
    {
        return (new ApprovalService($this->conn, $this->settings))->policy($doc);
    }

    /** Pure: why this person may not approve, or null. */
    public static function block(array $makerIds, int $me, bool $hasPermission, bool $allowSelf): ?string
    {
        if (!$hasPermission) {
            return 'You do not have permission to approve payroll.';
        }
        if (!$allowSelf && in_array($me, array_map('intval', $makerIds), true)) {
            return 'You prepared this, so someone else has to approve it.';
        }
        return null;
    }

    /* --------------------------- creating: hold for approval --------------------------- */

    /**
     * After payroll rows are saved (inside the caller's transaction), hold them for approval if the policy needs it:
     * the total net pay of these rows is compared with the threshold. Returns true when they were held.
     */
    public function holdIfRequired(array $payrollIds): bool
    {
        $payrollIds = array_values(array_filter(array_map('intval', $payrollIds)));
        if (!$payrollIds) {
            return false;
        }
        $policy = $this->policy(ApprovalService::DOC_PAYROLL_RUN);
        $in = implode(',', $payrollIds);
        $t = $this->tenantId();
        $total = (float)($this->conn->query("SELECT COALESCE(SUM(net_salary),0) n FROM payroll_list WHERE tenant_id = {$t} AND id IN ({$in})")->fetch_assoc()['n'] ?? 0);
        if (!ApprovalService::requiresApproval($policy['enabled'], $policy['threshold'], $total)) {
            return false;
        }
        $this->conn->query("UPDATE payroll_list SET status = 'pending_approval', approved_by = NULL, approved_at = NULL, approval_note = NULL WHERE tenant_id = {$t} AND id IN ({$in}) AND status <> 'rejected'");
        $this->conn->query("UPDATE journal_entries SET status = 'pending_approval' WHERE tenant_id = {$t} AND id IN (SELECT journal_id FROM payroll_list WHERE id IN ({$in}) AND journal_id IS NOT NULL)");
        return true;
    }

    /* ------------------------------------ queues ------------------------------------ */

    public function pendingRuns(): array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT p.payroll_run_ref, MAX(p.salary_month) salary_month, MAX(p.pay_date) pay_date, COUNT(*) employees,
                SUM(p.gross_salary) gross, SUM(p.net_salary) net, MIN(p.created_by) created_by,
                MAX(CONCAT(u.firstname, ' ', u.lastname)) author
            FROM payroll_list p LEFT JOIN users u ON u.id = p.created_by
            WHERE p.tenant_id = ? AND p.status = 'pending_approval'
            GROUP BY p.payroll_run_ref ORDER BY MAX(p.pay_date), p.payroll_run_ref");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function pendingRemittances(): array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT r.*, MAX(CONCAT(u.firstname, ' ', u.lastname)) author
            FROM payroll_remittances r LEFT JOIN users u ON u.id = r.created_by
            WHERE r.tenant_id = ? AND r.status = 'pending_approval' GROUP BY r.id ORDER BY r.remit_date, r.id");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /* ---------------------------------- payroll runs ---------------------------------- */

    private function runRows(string $ref): array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT id, journal_id, pay_date, created_by, net_salary FROM payroll_list WHERE tenant_id = ? AND payroll_run_ref = ? AND status = 'pending_approval'");
        $q->bind_param('is', $t, $ref);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function approveRun(string $ref): string
    {
        $t = $this->tenantId();
        $rows = $this->runRows($ref);
        if (!$rows) {
            return $this->fail('That payroll run is not waiting for approval.');
        }
        $me = (int)$this->settings->userdata('id');
        $policy = $this->policy(ApprovalService::DOC_PAYROLL_RUN);
        if ($msg = self::block(array_column($rows, 'created_by'), $me, $this->settings->can_access('payroll_approve'), $policy['allow_self'])) {
            return $this->fail($msg);
        }
        foreach (array_unique(array_column($rows, 'pay_date')) as $d) {
            if ($msg = PeriodLockService::guard($this->conn, $t, (string)$d)) {
                return $this->fail($msg);
            }
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $jids = array_values(array_filter(array_map('intval', array_column($rows, 'journal_id'))));
        $this->conn->begin_transaction();
        try {
            if ($jids) {
                $side = LedgerService::SIDE_SQL;
                $in = implode(',', $jids);
                $bad = $this->conn->query("SELECT ji.journal_id FROM journal_items ji LEFT JOIN group_list g ON g.id = ji.group_id WHERE ji.journal_id IN ({$in})
                    GROUP BY ji.journal_id HAVING ABS(SUM(CASE WHEN {$side} = 1 THEN ji.amount ELSE 0 END) - SUM(CASE WHEN {$side} = 2 THEN ji.amount ELSE 0 END)) >= 0.005");
                if ($bad && $bad->num_rows > 0) {
                    throw new \RuntimeException('A payroll journal does not balance, so the run cannot be approved. Return it and run payroll again.');
                }
            }
            $in = implode(',', $ids);
            $this->conn->query("UPDATE payroll_list SET status = 'posted', approved_by = {$me}, approved_at = NOW(), approval_note = NULL WHERE tenant_id = {$t} AND id IN ({$in}) AND status = 'pending_approval'");
            if ($this->conn->affected_rows !== count($ids)) {
                throw new \RuntimeException('That payroll run was already dealt with by someone else.');
            }
            if ($jids) {
                $this->conn->query("UPDATE journal_entries SET status = 'posted' WHERE tenant_id = {$t} AND id IN (" . implode(',', $jids) . ")");
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payroll run could not be approved.');
        }
        $this->settings->log_audit('Approved payroll run', 'Approvals', "{$ref}: " . count($ids) . ' employees.');
        return json_encode(['status' => 'success', 'msg' => "Payroll run {$ref} approved and posted."]);
    }

    public function rejectRun(string $ref, string $note): string
    {
        $t = $this->tenantId();
        $rows = $this->runRows($ref);
        if (!$rows) {
            return $this->fail('That payroll run is not waiting for approval.');
        }
        if (!$this->settings->can_access('payroll_approve')) {
            return $this->fail('You do not have permission to review payroll.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return $this->fail('Say why the payroll run is being returned.');
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $jids = array_values(array_filter(array_map('intval', array_column($rows, 'journal_id'))));
        $in = implode(',', $ids);
        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE payroll_list SET status = 'rejected', approval_note = ? WHERE tenant_id = ? AND id IN ({$in}) AND status = 'pending_approval'");
            $up->bind_param('si', $note, $t);
            $up->execute();
            if ($up->affected_rows !== count($ids)) {
                throw new \RuntimeException('That payroll run was already dealt with by someone else.');
            }
            if ($jids) {
                $this->conn->query("UPDATE journal_entries SET status = 'cancelled' WHERE tenant_id = {$t} AND id IN (" . implode(',', $jids) . ")");
            }
            // Retroactive pay that was in these payslips becomes payable again
            $adj = new PayrollAdjustmentService($this->conn, $this->settings);
            $loans = new PayrollLoanService($this->conn, $this->settings);
            foreach ($ids as $pid) {
                $adj->release($pid);
                $loans->release($pid);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payroll run could not be returned.');
        }
        $this->settings->log_audit('Returned payroll run', 'Approvals', "{$ref} returned: {$note}");
        return json_encode(['status' => 'success', 'msg' => "Payroll run {$ref} returned. Run payroll again once it is corrected."]);
    }

    /* ------------------------------------ remittances ------------------------------------ */

    private function pendingRemittance(int $id, int $t): ?array
    {
        $q = $this->conn->prepare("SELECT * FROM payroll_remittances WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
        $q->bind_param('ii', $id, $t);
        $q->execute();
        return $q->get_result()->fetch_assoc() ?: null;
    }

    public function approveRemittance(int $id): string
    {
        $t = $this->tenantId();
        $r = $this->pendingRemittance($id, $t);
        if (!$r) {
            return $this->fail('That payment is not waiting for approval.');
        }
        $me = (int)$this->settings->userdata('id');
        $policy = $this->policy(ApprovalService::DOC_PAYROLL_REMITTANCE);
        if ($msg = self::block([(int)$r['created_by']], $me, $this->settings->can_access('payroll_approve'), $policy['allow_self'])) {
            return $this->fail($msg);
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, (string)$r['remit_date'])) {
            return $this->fail($msg);
        }
        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE payroll_remittances SET status = 'posted', approved_by = ?, approved_at = NOW(), approval_note = NULL WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
            $up->bind_param('iii', $me, $id, $t);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('That payment was already dealt with by someone else.');
            }
            if (!empty($r['journal_id'])) {
                $this->conn->query("UPDATE journal_entries SET status = 'posted' WHERE id = " . (int)$r['journal_id'] . " AND tenant_id = {$t}");
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be approved.');
        }
        $this->settings->log_audit('Approved payroll remittance', 'Approvals', "#{$id} for " . number_format((float)$r['amount'], 2) . '.');
        return json_encode(['status' => 'success', 'msg' => 'Payment approved and posted.']);
    }

    public function rejectRemittance(int $id, string $note): string
    {
        $t = $this->tenantId();
        $r = $this->pendingRemittance($id, $t);
        if (!$r) {
            return $this->fail('That payment is not waiting for approval.');
        }
        if (!$this->settings->can_access('payroll_approve')) {
            return $this->fail('You do not have permission to review payroll.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return $this->fail('Say why the payment is being returned.');
        }
        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE payroll_remittances SET status = 'rejected', approval_note = ? WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
            $up->bind_param('sii', $note, $id, $t);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('That payment was already dealt with by someone else.');
            }
            if (!empty($r['journal_id'])) {
                $this->conn->query("UPDATE journal_entries SET status = 'cancelled' WHERE id = " . (int)$r['journal_id'] . " AND tenant_id = {$t}");
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be returned.');
        }
        $this->settings->log_audit('Returned payroll remittance', 'Approvals', "#{$id} returned: {$note}");
        return json_encode(['status' => 'success', 'msg' => 'Payment returned.']);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
