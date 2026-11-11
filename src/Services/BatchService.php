<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Batch / lot and expiry tracking for products that have it switched on. A batch is a quantity at one warehouse
 * with a batch number and, usually, an expiry date. Stock leaving a location uses the batch that expires first
 * (FEFO). The batches are a layer on top of the normal stock figures: they never block a sale or change the stock
 * total, so anything not in a batch (stock from before tracking was switched on) is simply shown as "not in a batch".
 */
class BatchService
{
    public const WARN_DAYS = 30;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pure: which batches a quantity comes out of, earliest expiry first (no expiry last, then oldest batch first).
     *
     * @param  array<int,array{id:int,expiry_date:?string,qty_remaining:float}> $batches
     * @return array{take:array<int,float>,short:float} batch id => quantity taken; short = not covered by any batch
     */
    public static function fefoPlan(array $batches, float $qty): array
    {
        usort($batches, function ($a, $b) {
            $ea = $a['expiry_date'] ?? null;
            $eb = $b['expiry_date'] ?? null;
            if ($ea === $eb) {
                return (int)$a['id'] <=> (int)$b['id'];
            }
            if ($ea === null) {
                return 1;
            }
            if ($eb === null) {
                return -1;
            }
            return strcmp($ea, $eb);
        });
        $need = round($qty, 4);
        $take = [];
        foreach ($batches as $b) {
            if ($need <= 0) {
                break;
            }
            $have = round((float)$b['qty_remaining'], 4);
            if ($have <= 0) {
                continue;
            }
            $t = min($have, $need);
            $take[(int)$b['id']] = $t;
            $need = round($need - $t, 4);
        }
        return ['take' => $take, 'short' => max(0.0, $need)];
    }

    /** Pure: 'none' (no expiry), 'expired', 'soon' (within $warnDays) or 'ok'. */
    public static function expiryStatus(?string $expiry, string $today, int $warnDays = self::WARN_DAYS): string
    {
        if ($expiry === null || $expiry === '' || $expiry === '0000-00-00') {
            return 'none';
        }
        if ($expiry < $today) {
            return 'expired';
        }
        $limit = date('Y-m-d', strtotime($today . " +{$warnDays} days"));
        return $expiry <= $limit ? 'soon' : 'ok';
    }

    /** Pure: a typed batch number, trimmed and limited, or '' when blank. */
    public static function cleanBatchNo($raw): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$raw)), 0, 60);
    }

    /** Pure: a typed expiry as Y-m-d, or null when blank or not a real date. */
    public static function cleanExpiry($raw): ?string
    {
        $t = trim((string)$raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return $t;
    }

    private function tenant(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    public function isTracked(int $productId): bool
    {
        $t = $this->tenant();
        $r = @$this->conn->query("SELECT track_batches FROM `product_list` WHERE id = '{$productId}' AND tenant_id = '{$t}'");
        return (bool)($r && ($row = $r->fetch_assoc()) && (int)$row['track_batches'] === 1);
    }

    /** Add stock to a batch, creating it if needed. A blank batch number becomes the fallback given. */
    public function receive(int $productId, int $warehouseId, float $qty, string $batchNo, ?string $expiry, float $unitCost, string $reference, string $fallbackNo): bool
    {
        if ($qty <= 0) {
            return true;
        }
        $t = $this->tenant();
        $wh = max(0, $warehouseId);
        $no = $this->conn->real_escape_string(self::cleanBatchNo($batchNo) !== '' ? self::cleanBatchNo($batchNo) : self::cleanBatchNo($fallbackNo));
        $exp = $expiry === null ? 'NULL' : "'" . $expiry . "'";
        $ref = $this->conn->real_escape_string(mb_substr($reference, 0, 100));
        return (bool)$this->conn->query("INSERT INTO `product_batches`
            (tenant_id, product_id, warehouse_id, batch_no, expiry_date, qty_remaining, unit_cost, received_on, reference)
            VALUES ('{$t}', '{$productId}', '{$wh}', '{$no}', {$exp}, '{$qty}', '{$unitCost}', CURDATE(), '{$ref}')
            ON DUPLICATE KEY UPDATE qty_remaining = qty_remaining + VALUES(qty_remaining),
                expiry_date = COALESCE(expiry_date, VALUES(expiry_date))");
    }

    /**
     * Take stock out of a location's batches, first expiry first. Does nothing for products that are not tracked.
     *
     * @return array<int,array{batch_no:string,expiry_date:?string,unit_cost:float,qty:float}> what was taken
     */
    public function consume(int $productId, int $warehouseId, float $qty): array
    {
        if ($qty <= 0 || !$this->isTracked($productId)) {
            return [];
        }
        $t = $this->tenant();
        $wh = max(0, $warehouseId);
        $r = $this->conn->query("SELECT id, batch_no, expiry_date, qty_remaining, unit_cost FROM `product_batches`
            WHERE tenant_id = '{$t}' AND product_id = '{$productId}' AND warehouse_id = '{$wh}' AND qty_remaining > 0 FOR UPDATE");
        $rows = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $rows[(int)$row['id']] = $row;
        }
        $plan = self::fefoPlan(array_map(fn($x) => ['id' => (int)$x['id'], 'expiry_date' => $x['expiry_date'], 'qty_remaining' => (float)$x['qty_remaining']], $rows), $qty);
        $taken = [];
        foreach ($plan['take'] as $id => $q) {
            $this->conn->query("UPDATE `product_batches` SET qty_remaining = GREATEST(0, qty_remaining - {$q}) WHERE id = '{$id}' AND tenant_id = '{$t}'");
            $taken[] = ['batch_no' => (string)$rows[$id]['batch_no'], 'expiry_date' => $rows[$id]['expiry_date'], 'unit_cost' => (float)$rows[$id]['unit_cost'], 'qty' => $q];
        }
        return $taken;
    }

    /** Move batches with the stock when it is transferred between warehouses. */
    public function move(int $productId, int $fromWh, int $toWh, float $qty, string $reference): bool
    {
        $ok = true;
        foreach ($this->consume($productId, $fromWh, $qty) as $b) {
            $ok = $this->receive($productId, $toWh, $b['qty'], $b['batch_no'], $b['expiry_date'], $b['unit_cost'], $reference, $b['batch_no']) && $ok;
        }
        return $ok;
    }

    /**
     * Batches with stock, for the report. $filter: 'all', 'soon' (within the warning period) or 'expired'.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listing(string $filter, int $warnDays = self::WARN_DAYS): array
    {
        $t = $this->tenant();
        $today = date('Y-m-d');
        $limit = date('Y-m-d', strtotime("+{$warnDays} days"));
        $where = '';
        if ($filter === 'expired') {
            $where = " AND b.expiry_date IS NOT NULL AND b.expiry_date < '{$today}'";
        } elseif ($filter === 'soon') {
            $where = " AND b.expiry_date IS NOT NULL AND b.expiry_date >= '{$today}' AND b.expiry_date <= '{$limit}'";
        }
        $r = $this->conn->query("SELECT b.*, p.name, p.product_code, w.name warehouse_name
            FROM `product_batches` b JOIN `product_list` p ON p.id = b.product_id
            LEFT JOIN `warehouses` w ON w.id = b.warehouse_id AND b.warehouse_id > 0
            WHERE b.tenant_id = '{$t}' AND b.qty_remaining > 0 AND p.delete_flag = 0 {$where}
            ORDER BY b.expiry_date IS NULL, b.expiry_date ASC, p.name ASC LIMIT 1000");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $row['status'] = self::expiryStatus($row['expiry_date'], $today, $warnDays);
            $out[] = $row;
        }
        return $out;
    }

    /** Quantity of a product at a location that is past its expiry date. */
    public function expiredQty(int $productId, int $warehouseId): float
    {
        $t = $this->tenant();
        $wh = max(0, $warehouseId);
        $r = $this->conn->query("SELECT COALESCE(SUM(qty_remaining), 0) q FROM `product_batches`
            WHERE tenant_id = '{$t}' AND product_id = '{$productId}' AND warehouse_id = '{$wh}'
            AND expiry_date IS NOT NULL AND expiry_date < CURDATE() AND qty_remaining > 0");
        return round((float)($r ? $r->fetch_assoc()['q'] : 0), 4);
    }

    /** Take expired stock out of a location as a stock adjustment (the earliest-expiry batches go first). */
    public function writeOffExpired(int $productId, int $warehouseId): string
    {
        $qty = $this->expiredQty($productId, $warehouseId);
        if ($qty <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'There is no expired stock there.']);
        }
        return (new InventoryService($this->conn, $this->settings))->saveStockAdjustment([
            'product_id' => $productId, 'type' => 'out', 'qty' => $qty, 'warehouse_id' => $warehouseId,
            'reference' => 'Expiry write-off ' . date('Ymd'), 'notes' => 'Expired batches written off',
        ]);
    }
}
