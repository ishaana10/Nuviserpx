<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Posts depreciation and disposals for the fixed-asset register.
 *
 * Depreciation is run month by month. Each month is one journal:  Dr Depreciation  Cr Accumulated Depreciation,
 * and every asset's charge is recorded in asset_depreciation with the journal it belongs to, so the register
 * can show cost, accumulated depreciation and carrying value as at any date, and a month is never charged
 * twice (one row per asset per month). Disposal clears cost and accumulated depreciation and books the gain or loss.
 *
 * The purchase itself is not posted here: an asset's cost reaches the ledger through the bill or journal that
 * bought it. This service only deals with what happens to it afterwards.
 */
class AssetService
{
    public const EXPENSE_NAME = 'Depreciation';
    public const ACCUMULATED_NAME = 'Accumulated Depreciation';
    public const GAIN_LOSS_NAME = 'Gain/Loss on Disposal of Assets';

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /* ------------------------------- accounts -------------------------------- */

    /** The company's account for a role, found by name and created on first use. */
    private function account(string $name, string $type, string $description, ?string $like = null): int
    {
        $t = $this->tenantId();
        $pattern = $like ?? $name;
        $stmt = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND (name = ? OR name LIKE ?) ORDER BY (name = ?) DESC, id LIMIT 1");
        $stmt->bind_param('isss', $t, $name, $pattern, $name);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => $name, 'type' => $type, 'description' => $description, 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            return 0;
        }
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    }

    public function expenseAccount(): int
    {
        return $this->account(self::EXPENSE_NAME, 'Depreciation', 'Depreciation charged on fixed assets', 'Depreciation%');
    }

    public function accumulatedAccount(): int
    {
        return $this->account(self::ACCUMULATED_NAME, 'Fixed Asset', 'Depreciation charged to date on fixed assets', 'Accumulated Depreciation%');
    }

    public function gainLossAccount(): int
    {
        return $this->account(self::GAIN_LOSS_NAME, 'Other Income', 'Gains and losses when fixed assets are sold or scrapped');
    }

    /* ------------------------------- the register ------------------------------ */

    /** Registered assets with depreciation charged up to $asAt: accumulated, last month charged, carrying value. */
    public function register(string $asAt): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT a.*,
                COALESCE((SELECT SUM(d.amount) FROM asset_depreciation d WHERE d.asset_id = a.id AND d.period_end <= ?), 0) accumulated,
                (SELECT MAX(d.period_end) FROM asset_depreciation d WHERE d.asset_id = a.id) last_charged
            FROM fixed_assets a WHERE a.tenant_id = ? AND a.status IN ('registered','disposed') ORDER BY a.asset_number");
        $stmt->bind_param('si', $asAt, $t);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['cost'] = DepreciationCalc::cost($r);
            $r['accumulated'] = round((float)$r['accumulated'], 2);
            $r['carrying'] = round((float)$r['purchase_price'] - $r['accumulated'], 2);
        }
        return $rows;
    }

    public function transactionCount(int $assetId): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) n FROM asset_depreciation WHERE asset_id = ?");
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    }

    /* ------------------------------- depreciation ------------------------------ */

    /**
     * What running depreciation through $through would charge, month by month, without posting anything.
     * Returns months => [period_end, charges => [asset_id => amount], total].
     */
    public function plan(string $through): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT a.*, 
                (SELECT MAX(d.period_end) FROM asset_depreciation d WHERE d.asset_id = a.id) last_charged,
                COALESCE((SELECT SUM(d.amount) FROM asset_depreciation d WHERE d.asset_id = a.id), 0) accumulated
            FROM fixed_assets a WHERE a.tenant_id = ? AND a.status = 'registered' AND a.dep_start_date IS NOT NULL");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $acc = [];
        $months = [];
        foreach ($assets as $a) {
            $id = (int)$a['id'];
            $running = round((float)$a['accumulated'], 2);
            foreach (DepreciationCalc::monthsToPost((string)$a['dep_start_date'], $a['last_charged'], $through) as $end) {
                $c = DepreciationCalc::monthlyCharge($a, $end, $running);
                if ($c <= 0) {
                    continue;
                }
                $running = round($running + $c, 2);
                $acc[$end][$id] = $c;
            }
        }
        ksort($acc);
        foreach ($acc as $end => $charges) {
            $months[] = ['period_end' => $end, 'charges' => $charges, 'total' => round(array_sum($charges), 2)];
        }
        return $months;
    }

    /** Post depreciation through the end of the month containing $through, one journal per month, oldest first. */
    public function run(string $through): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $through) || !strtotime($through)) {
            return $this->fail('Enter a valid date.');
        }
        $through = DepreciationCalc::monthEnd($through);
        if ($through > DepreciationCalc::monthEnd(date('Y-m-d'))) {
            return $this->fail('Depreciation cannot be run for a month that has not started yet.');
        }
        $plan = $this->plan($through);
        if (!$plan) {
            return $this->fail('Nothing to depreciate: every registered asset is up to date to ' . date('j M Y', strtotime($through)) . '.');
        }
        $exp = $this->expenseAccount();
        $accum = $this->accumulatedAccount();
        if (!$exp || !$accum) {
            return $this->fail('The depreciation accounts could not be found or created.');
        }

        $t = $this->tenantId();
        $posted = 0;
        $total = 0.0;
        $journals = new JournalService($this->conn, $this->settings);
        foreach ($plan as $m) {
            $end = $m['period_end'];
            $this->conn->begin_transaction();
            try {
                $ins = $this->conn->prepare("INSERT INTO asset_depreciation (tenant_id, asset_id, period_end, amount) VALUES (?, ?, ?, ?)");
                foreach ($m['charges'] as $assetId => $amt) {
                    $a = number_format($amt, 4, '.', '');
                    $ins->bind_param('iiss', $t, $assetId, $end, $a);
                    if (!$ins->execute()) {
                        throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                    }
                }
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollback();
                return $this->stopped($posted, 'Depreciation for ' . date('F Y', strtotime($end)) . ' could not be recorded (it may already have been run).');
            }

            $lines = [
                ['account_id' => $exp, 'debit' => $m['total'], 'credit' => 0.0, 'description' => 'Depreciation charge'],
                ['account_id' => $accum, 'debit' => 0.0, 'credit' => $m['total'], 'description' => count($m['charges']) . ' asset(s)'],
            ];
            $res = json_decode($journals->postSystemJournal($end, 'Depreciation for ' . date('F Y', strtotime($end)), $lines), true);
            if (($res['status'] ?? '') !== 'success') {
                $d = $this->conn->prepare("DELETE FROM asset_depreciation WHERE tenant_id = ? AND period_end = ? AND journal_id IS NULL");
                $d->bind_param('is', $t, $end);
                $d->execute();
                return $this->stopped($posted, 'Depreciation for ' . date('F Y', strtotime($end)) . ' was not posted: ' . ($res['msg'] ?? 'unknown error'));
            }
            $jid = (int)$res['id'];
            $u = $this->conn->prepare("UPDATE asset_depreciation SET journal_id = ? WHERE tenant_id = ? AND period_end = ? AND journal_id IS NULL");
            $u->bind_param('iis', $jid, $t, $end);
            $u->execute();
            $posted++;
            $total += $m['total'];
        }
        $this->settings->log_audit('Ran depreciation', 'Fixed Assets', "{$posted} month(s) through {$through}, total " . number_format($total, 2) . '.');
        $msg = "Depreciation posted for {$posted} month" . ($posted === 1 ? '' : 's') . ' (' . number_format($total, 2) . ' in total).';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /* ---------------------------------- disposal ---------------------------------- */

    /**
     * Dispose of (sell or scrap) a registered asset. Depreciation must be up to date to the end of the month
     * before the disposal; there is no charge for the month of disposal.
     */
    public function dispose(array $post): string
    {
        $t = $this->tenantId();
        $id = (int)($post['id'] ?? 0);
        $date = trim((string)($post['disposal_date'] ?? ''));
        $proceeds = BudgetService::cleanAmount($post['proceeds'] ?? '');
        $proceedsAcc = (int)($post['proceeds_account_id'] ?? 0);
        $costAcc = (int)($post['cost_account_id'] ?? 0);

        $stmt = $this->conn->prepare("SELECT * FROM fixed_assets WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        if (!$a) {
            return $this->fail('Asset not found.');
        }
        if ($a['status'] !== 'registered') {
            return $this->fail($a['status'] === 'disposed' ? 'This asset has already been disposed of.' : 'Only registered assets can be disposed of.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter the disposal date.');
        }
        if (!empty($a['purchase_date']) && $date < $a['purchase_date']) {
            return $this->fail('An asset cannot be disposed of before it was bought (' . $a['purchase_date'] . ').');
        }
        if ($proceeds === null) {
            return $this->fail('Enter the proceeds as an amount of zero or more.');
        }
        $ledger = new LedgerService($this->conn, $t);
        $valid = $ledger->accountIds();
        if (!in_array($costAcc, $valid, true)) {
            return $this->fail('Choose the account the asset’s cost is held in.');
        }
        if ($proceeds > 0 && !in_array($proceedsAcc, $valid, true)) {
            return $this->fail('Choose the account the proceeds were paid into.');
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }

        // Depreciation up to the end of the previous month must be posted first.
        $prevEnd = (new \DateTimeImmutable(DepreciationCalc::monthStart($date)))->modify('-1 day')->format('Y-m-d');
        $owed = array_filter($this->plan($prevEnd), fn ($m) => isset($m['charges'][$id]));
        if ($owed) {
            return $this->fail('Run depreciation through ' . date('F Y', strtotime($prevEnd)) . ' first, so the asset is written down to its disposal date.');
        }

        $s = $this->conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM asset_depreciation WHERE asset_id = ?");
        $s->bind_param('i', $id);
        $s->execute();
        $accumulated = round((float)$s->get_result()->fetch_assoc()['s'], 2);
        $cost = round((float)$a['purchase_price'], 2);

        $accounts = ['proceeds' => $proceedsAcc, 'accumulated' => $this->accumulatedAccount(), 'cost' => $costAcc, 'gain_loss' => $this->gainLossAccount()];
        if (!$accounts['accumulated'] || !$accounts['gain_loss']) {
            return $this->fail('The disposal accounts could not be found or created.');
        }
        $calc = DepreciationCalc::disposalLines($cost, $accumulated, (float)$proceeds, $accounts);

        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal(
            $date, "Disposal of asset {$a['asset_number']} {$a['asset_name']}", $calc['lines']
        ), true);
        if (($res['status'] ?? '') !== 'success') {
            return $this->fail((string)($res['msg'] ?? 'The disposal journal could not be posted.'));
        }
        $jid = (int)$res['id'];
        $p = number_format((float)$proceeds, 4, '.', '');
        $u = $this->conn->prepare("UPDATE fixed_assets SET status = 'disposed', disposal_date = ?, disposal_proceeds = ?, disposal_journal_id = ? WHERE id = ? AND tenant_id = ?");
        $u->bind_param('ssiii', $date, $p, $jid, $id, $t);
        $u->execute();

        $word = $calc['gain'] >= 0 ? 'gain' : 'loss';
        $this->settings->log_audit('Disposed asset', 'Fixed Assets', "{$a['asset_number']} {$a['asset_name']} on {$date}, proceeds {$p}, {$word} " . number_format(abs($calc['gain']), 2) . '.');
        $msg = "Asset {$a['asset_number']} disposed of with a " . ($calc['gain'] == 0.0 ? 'no gain or loss' : $word . ' of ' . number_format(abs($calc['gain']), 2)) . '.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    private function stopped(int $posted, string $why): string
    {
        $note = $posted ? " The {$posted} earlier month" . ($posted === 1 ? ' was' : 's were') . ' posted.' : '';
        return $this->fail($why . $note);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
