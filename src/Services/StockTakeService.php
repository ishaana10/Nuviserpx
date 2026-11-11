<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Physical stock counts. A count snapshots what the system says is at one warehouse, the team enters what they
 * actually counted (over days, saving as they go), and posting turns each difference into an ordinary stock
 * adjustment. The adjustment is the difference against the snapshot, not "set to the counted figure", so stock
 * that moved while counting (a sale, a receipt) is kept rather than overwritten.
 */
class StockTakeService
{
    public const MAX_LINES = 2000;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings,
        private readonly ?InventoryService $inventory = null
    ) {}

    /** Pure: counted minus system, to 4 places. */
    public static function variance(float $counted, float $system): float
    {
        return round($counted - $system, 4);
    }

    /** Pure: what posting does with a line. 'skip' = not counted, 'none' = matches, else 'in' or 'out'. */
    public static function lineAction(?float $counted, float $system): string
    {
        if ($counted === null) {
            return 'skip';
        }
        $v = self::variance($counted, $system);
        return $v == 0.0 ? 'none' : ($v > 0 ? 'in' : 'out');
    }

    /**
     * Pure: totals for a count sheet. Each line needs system_qty, counted_qty (or null) and unit_cost.
     *
     * @return array{lines:int,counted:int,uncounted:int,over:int,short:int,value:float}
     */
    public static function summary(array $lines): array
    {
        $s = ['lines' => 0, 'counted' => 0, 'uncounted' => 0, 'over' => 0, 'short' => 0, 'value' => 0.0];
        foreach ($lines as $l) {
            $s['lines']++;
            $c = $l['counted_qty'] ?? null;
            if ($c === null || $c === '') {
                $s['uncounted']++;
                continue;
            }
            $s['counted']++;
            $v = self::variance((float)$c, (float)$l['system_qty']);
            if ($v > 0) {
                $s['over']++;
            } elseif ($v < 0) {
                $s['short']++;
            }
            $s['value'] += $v * (float)($l['unit_cost'] ?? 0);
        }
        $s['value'] = round($s['value'], 2);
        return $s;
    }

    /** Pure: a typed count cell. '' means not counted yet (null); anything else must be a number of 0 or more. */
    public static function parseCount($raw): ?float
    {
        $t = trim(str_replace(',', '', (string)$raw));
        if ($t === '') {
            return null;
        }
        if (!is_numeric($t) || (float)$t < 0) {
            return -1.0;
        }
        return round((float)$t, 4);
    }

    private function tenant(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $t = $this->tenant();
        $r = $this->conn->query("SELECT * FROM `stock_counts` WHERE id = '{$id}' AND tenant_id = '{$t}'");
        return ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function lines(int $id): array
    {
        $t = $this->tenant();
        $r = $this->conn->query("SELECT l.*, p.name, p.product_code FROM `stock_count_lines` l
            LEFT JOIN `product_list` p ON p.id = l.product_id
            WHERE l.count_id = '{$id}' AND l.tenant_id = '{$t}' ORDER BY p.name ASC");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }

    /** Start a count. $scope 'stocked' = products with stock recorded there; 'all' = every active product. */
    public function create(int $warehouseId, string $date, string $scope, string $notes): string
    {
        $t = $this->tenant();
        $warehouseId = max(0, $warehouseId);
        if ($warehouseId > 0) {
            $w = $this->conn->query("SELECT 1 FROM `warehouses` WHERE id = '{$warehouseId}' AND tenant_id = '{$t}' LIMIT 1");
            if (!$w || !$w->num_rows) {
                return json_encode(['status' => 'failed', 'msg' => 'That warehouse was not found.']);
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $stock = new WarehouseStockService($this->conn, $this->settings);
        $sql = "SELECT p.id, p.cost_price FROM `product_list` p WHERE p.tenant_id = '{$t}' AND p.delete_flag = 0";
        if ($scope !== 'all') {
            $sql .= " AND EXISTS (SELECT 1 FROM `product_stock` s WHERE s.product_id = p.id AND s.tenant_id = '{$t}'
                AND s.warehouse_id = '{$warehouseId}' AND s.quantity <> 0)";
        }
        $r = $this->conn->query($sql . ' ORDER BY p.name ASC LIMIT ' . (self::MAX_LINES + 1));
        $products = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $products[] = $row;
        }
        if (!$products) {
            return json_encode(['status' => 'failed', 'msg' => 'There are no products to count there. Choose "all products" to count items with no stock recorded.']);
        }
        if (count($products) > self::MAX_LINES) {
            return json_encode(['status' => 'failed', 'msg' => 'That is more than ' . self::MAX_LINES . ' products. Count a smaller location or split the count.']);
        }

        $no = SequenceService::documentNo($this->conn, $t, 'stock_counts', 'count_no', 'CNT-', 'stock_count');
        $user = (int)$this->settings->userdata('id');
        $notesE = $this->conn->real_escape_string(mb_substr(trim($notes), 0, 500));
        $this->conn->begin_transaction();
        try {
            if (!$this->conn->query("INSERT INTO `stock_counts` SET tenant_id = '{$t}', count_no = '" . $this->conn->real_escape_string($no) . "',
                warehouse_id = '{$warehouseId}', count_date = '{$date}', notes = '{$notesE}', created_by = '{$user}'")) {
                throw new \RuntimeException($this->conn->error);
            }
            $id = (int)$this->conn->insert_id;
            foreach ($products as $p) {
                $pid = (int)$p['id'];
                $sys = $stock->quantityAt($pid, $warehouseId);
                $cost = (float)$p['cost_price'];
                if (!$this->conn->query("INSERT INTO `stock_count_lines` SET count_id = '{$id}', tenant_id = '{$t}', product_id = '{$pid}',
                    system_qty = '{$sys}', unit_cost = '{$cost}'")) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The count could not be started. Nothing was saved.']);
        }
        $this->settings->log_audit('Started Stock Count', 'Inventory', "Count {$no} (#{$id}) at warehouse #{$warehouseId}, " . count($products) . ' products.');
        return json_encode(['status' => 'success', 'id' => $id, 'msg' => "Count {$no} started."]);
    }

    /** Save typed counts for a draft. $counts is product_id => typed value. Lines already posted are left alone. */
    public function saveCounts(int $id, array $counts): string
    {
        $t = $this->tenant();
        $c = $this->find($id);
        if (!$c || $c['status'] !== 'draft') {
            return json_encode(['status' => 'failed', 'msg' => 'Only an open count can be changed.']);
        }
        $bad = [];
        $updates = [];
        foreach ($counts as $pid => $raw) {
            $v = self::parseCount($raw);
            if ($v !== null && $v < 0) {
                $bad[] = (int)$pid;
                continue;
            }
            $updates[(int)$pid] = $v;
        }
        if ($bad) {
            return json_encode(['status' => 'failed', 'msg' => 'Counts must be numbers of 0 or more. Nothing was saved.']);
        }
        foreach ($updates as $pid => $v) {
            $val = $v === null ? 'NULL' : "'" . $v . "'";
            $this->conn->query("UPDATE `stock_count_lines` SET counted_qty = {$val}, line_status = 'pending', result_note = NULL
                WHERE count_id = '{$id}' AND tenant_id = '{$t}' AND product_id = '{$pid}' AND line_status <> 'posted'");
        }
        return json_encode(['status' => 'success', 'msg' => 'Counts saved.']);
    }

    /**
     * Post the differences. Safe to run again: lines already posted are never repeated, and a line that failed
     * (for example the stock was no longer there to take out) is retried.
     */
    public function post(int $id): string
    {
        $t = $this->tenant();
        $lockName = "stockcount_{$t}_{$id}";
        $got = $this->conn->query("SELECT GET_LOCK('" . $this->conn->real_escape_string($lockName) . "', 5) AS l")->fetch_assoc();
        if ((int)($got['l'] ?? 0) !== 1) {
            return json_encode(['status' => 'failed', 'msg' => 'This count is being posted by someone else. Try again in a moment.']);
        }
        try {
            $c = $this->find($id);
            if (!$c || $c['status'] !== 'draft') {
                return json_encode(['status' => 'failed', 'msg' => 'Only an open count can be posted.']);
            }
            $lines = $this->lines($id);
            if (self::summary($lines)['counted'] === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'Enter at least one counted quantity first.']);
            }
            $inv = $this->inventory ?? new InventoryService($this->conn, $this->settings);
            $adjusted = 0;
            $matched = 0;
            $failed = [];
            foreach ($lines as $l) {
                if ($l['line_status'] === 'posted' || $l['counted_qty'] === null) {
                    continue;
                }
                $lid = (int)$l['id'];
                $action = self::lineAction((float)$l['counted_qty'], (float)$l['system_qty']);
                if ($action === 'none') {
                    $this->conn->query("UPDATE `stock_count_lines` SET line_status = 'posted', result_note = 'Matched' WHERE id = '{$lid}'");
                    $matched++;
                    continue;
                }
                $qty = abs(self::variance((float)$l['counted_qty'], (float)$l['system_qty']));
                $res = json_decode($inv->saveStockAdjustment([
                    'product_id' => (int)$l['product_id'],
                    'type' => $action,
                    'qty' => $qty,
                    'unit_cost' => (float)$l['unit_cost'],
                    'warehouse_id' => (int)$c['warehouse_id'],
                    'reference' => 'Count ' . $c['count_no'],
                    'notes' => 'Stock count ' . $c['count_no'] . ': system ' . (float)$l['system_qty'] . ', counted ' . (float)$l['counted_qty'],
                ]), true);
                if (($res['status'] ?? '') === 'success') {
                    $this->conn->query("UPDATE `stock_count_lines` SET line_status = 'posted', result_note = '" . ($action === 'in' ? 'Added' : 'Removed') . " {$qty}' WHERE id = '{$lid}'");
                    $adjusted++;
                } else {
                    $note = $this->conn->real_escape_string(mb_substr((string)($res['msg'] ?? 'Could not be adjusted.'), 0, 250));
                    $this->conn->query("UPDATE `stock_count_lines` SET line_status = 'failed', result_note = '{$note}' WHERE id = '{$lid}'");
                    $failed[] = (string)($l['name'] ?? $l['product_id']);
                }
            }
            if (!$failed) {
                $user = (int)$this->settings->userdata('id');
                $this->conn->query("UPDATE `stock_counts` SET status = 'posted', posted_by = '{$user}', posted_at = NOW()
                    WHERE id = '{$id}' AND tenant_id = '{$t}' AND status = 'draft'");
            }
            $this->settings->log_audit('Posted Stock Count', 'Inventory', "Count {$c['count_no']} (#{$id}): {$adjusted} adjusted, {$matched} matched, " . count($failed) . ' failed.');
            if ($failed) {
                return json_encode(['status' => 'failed', 'msg' => "{$adjusted} adjusted, but " . count($failed) . ' could not be: ' . implode(', ', array_slice($failed, 0, 5)) . '. Fix the cause or change the count, then post again; finished lines will not repeat.']);
            }
            return json_encode(['status' => 'success', 'msg' => "Count posted: {$adjusted} adjusted, {$matched} matched."]);
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('" . $this->conn->real_escape_string($lockName) . "')");
        }
    }

    /** Cancel an open count that has not adjusted anything. */
    public function cancel(int $id): string
    {
        $t = $this->tenant();
        $c = $this->find($id);
        if (!$c || $c['status'] !== 'draft') {
            return json_encode(['status' => 'failed', 'msg' => 'Only an open count can be cancelled.']);
        }
        $p = $this->conn->query("SELECT COUNT(*) n FROM `stock_count_lines` WHERE count_id = '{$id}' AND tenant_id = '{$t}' AND line_status = 'posted' AND result_note <> 'Matched'");
        if ($p && (int)$p->fetch_assoc()['n'] > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Some differences are already posted, so this count cannot be cancelled. Post the rest to finish it.']);
        }
        $this->conn->query("UPDATE `stock_counts` SET status = 'cancelled' WHERE id = '{$id}' AND tenant_id = '{$t}' AND status = 'draft'");
        $this->settings->log_audit('Cancelled Stock Count', 'Inventory', "Count {$c['count_no']} (#{$id}) cancelled.");
        return json_encode(['status' => 'success', 'msg' => 'Count cancelled.']);
    }
}
