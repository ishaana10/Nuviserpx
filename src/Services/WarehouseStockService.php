<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * WarehouseStockService — per-warehouse stock levels.
 *
 * product_list.stock_quantity remains the authoritative TOTAL on hand; this
 * service maintains the per-location breakdown in product_stock so the total
 * always equals the sum of its locations. Warehouse id 0 is the implicit
 * "Main / Unassigned" location.
 *
 * Design rule: whenever stock lands in or leaves a specific location, call
 * adjustLocation() — it updates both product_stock and the product total in one
 * step. A pure transfer moves between two locations and leaves the total alone.
 */
class WarehouseStockService
{
    public const MAIN_WAREHOUSE_ID = 0;
    public const MAIN_WAREHOUSE_NAME = 'Main / Unassigned';

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Change the quantity of a product at one warehouse by $delta (may be negative),
     * and keep product_list.stock_quantity (the total) in step.
     *
     * $syncTotal = false when the caller already updates the product total itself
     * (e.g. legacy code paths), so we only touch the location row.
     */
    public function adjustLocation(int $product_id, int $warehouse_id, float $delta, bool $syncTotal = true, bool $batches = true): bool
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        if ($product_id <= 0 || $delta == 0.0) {
            return true;
        }
        $warehouse_id = max(0, $warehouse_id);

        // Upsert the location row.
        $ok = (bool)$this->conn->query("INSERT INTO `product_stock` (`tenant_id`, `product_id`, `warehouse_id`, `quantity`)
            VALUES ('{$tenant_id}', '{$product_id}', '{$warehouse_id}', '{$delta}')
            ON DUPLICATE KEY UPDATE `quantity` = `quantity` + VALUES(`quantity`)");

        if ($ok && $syncTotal) {
            $sign = $delta >= 0 ? '+' : '-';
            $abs = abs($delta);
            $ok = (bool)$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` {$sign} {$abs}
                WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        }
        if ($ok && $batches && $delta < 0) {
            // Stock leaving a batch-tracked product comes off the batch that expires first.
            (new BatchService($this->conn, $this->settings))->consume($product_id, $warehouse_id, -$delta);
        }
        return $ok;
    }

    /** Why a transfer cannot go ahead, or null. Pure, so the rule can be tested without a database. */
    public static function transferProblem(float $qty, float $available, int $from, int $to): ?string
    {
        if ($qty <= 0) {
            return 'Valid product and quantity (> 0) are required.';
        }
        if ($from === $to) {
            return 'Source and destination warehouses must be different.';
        }
        if ($qty > $available + 0.00001) {
            return 'Only ' . rtrim(rtrim(number_format(max(0.0, $available), 2, '.', ''), '0'), '.') . ' in stock at the source warehouse.';
        }
        return null;
    }

    /**
     * Set a location's quantity to an absolute figure, adjusting the product total
     * by the difference. Used when reconciling a location to a counted value.
     */
    public function setLocation(int $product_id, int $warehouse_id, float $qty): void
    {
        $current = $this->quantityAt($product_id, $warehouse_id);
        $this->adjustLocation($product_id, $warehouse_id, round($qty - $current, 4), true);
    }

    /**
     * Move stock from one warehouse to another. The product total is unchanged.
     * Returns a JSON result for the controller.
     */
    public function transfer(int $product_id, int $from_wh, int $to_wh, float $qty, string $notes = ''): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $user_id = (int)$this->settings->userdata('id');
        $from_wh = max(0, $from_wh);
        $to_wh = max(0, $to_wh);

        if ($product_id <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Valid product and quantity (> 0) are required.']);
        }
        // Both ends must be this company's warehouses (0 is the implicit Main location).
        foreach ([$from_wh, $to_wh] as $w) {
            if ($w !== self::MAIN_WAREHOUSE_ID) {
                $chk = $this->conn->query("SELECT 1 FROM `warehouses` WHERE id = '{$w}' AND tenant_id = '{$tenant_id}' LIMIT 1");
                if (!$chk || $chk->num_rows === 0) {
                    return json_encode(['status' => 'failed', 'msg' => 'That warehouse was not found.']);
                }
            }
        }
        $prod = $this->conn->query("SELECT 1 FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}' LIMIT 1");
        if (!$prod || $prod->num_rows === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Product not found.']);
        }

        $notes_e = $this->conn->real_escape_string($notes);
        $ref = 'Transfer ' . $this->warehouseName($from_wh) . ' -> ' . $this->warehouseName($to_wh);
        $ref_e = $this->conn->real_escape_string($ref);

        // One transaction: the source row is locked while availability is checked, so two transfers cannot both
        // take the same units, and a failure part-way leaves nothing moved.
        $this->conn->begin_transaction();
        try {
            $lock = $this->conn->query("SELECT quantity FROM `product_stock`
                WHERE product_id = '{$product_id}' AND warehouse_id = '{$from_wh}' AND tenant_id = '{$tenant_id}' FOR UPDATE");
            $available = ($lock && ($row = $lock->fetch_assoc())) ? round((float)$row['quantity'], 2) : 0.0;
            if ($problem = self::transferProblem($qty, $available, $from_wh, $to_wh)) {
                $this->conn->rollback();
                return json_encode(['status' => 'failed', 'msg' => $problem]);
            }

            // Move: out of source, into destination. Total unchanged -> syncTotal = false.
            $ok = $this->adjustLocation($product_id, $from_wh, -$qty, false, false)
                && $this->adjustLocation($product_id, $to_wh, $qty, false, false)
                && (new BatchService($this->conn, $this->settings))->move($product_id, $from_wh, $to_wh, $qty, $ref)
                && $this->conn->query("INSERT INTO `warehouse_transfers`
                    (`tenant_id`, `from_warehouse_id`, `to_warehouse_id`, `product_id`, `qty`, `notes`, `created_by`)
                    VALUES ('{$tenant_id}', '{$from_wh}', '{$to_wh}', '{$product_id}', '{$qty}', '{$notes_e}', '{$user_id}')")
                // An out from the source and an in to the destination for the movement ledger.
                && $this->conn->query("INSERT INTO `inventory_logs` (`tenant_id`, `product_id`, `warehouse_id`, `type`, `qty`, `reference`, `notes`, `created_by`)
                    VALUES ('{$tenant_id}', '{$product_id}', '{$from_wh}', 'out', '{$qty}', '{$ref_e}', '{$notes_e}', '{$user_id}')")
                && $this->conn->query("INSERT INTO `inventory_logs` (`tenant_id`, `product_id`, `warehouse_id`, `type`, `qty`, `reference`, `notes`, `created_by`)
                    VALUES ('{$tenant_id}', '{$product_id}', '{$to_wh}', 'in', '{$qty}', '{$ref_e}', '{$notes_e}', '{$user_id}')");
            if (!$ok) {
                throw new \RuntimeException(\App\Support\Db::oops($this->conn));
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The transfer could not be completed. Nothing was moved.']);
        }

        $this->settings->log_audit('Transferred Stock', 'Warehouses', "Product #{$product_id}, Qty {$qty}, {$this->warehouseName($from_wh)} -> {$this->warehouseName($to_wh)}");
        return json_encode(['status' => 'success', 'msg' => 'Stock transferred successfully.']);
    }

    /* ===================================================================
     *  Read helpers
     * =================================================================== */

    /** Quantity of a product at one warehouse (0 if no row). */
    public function quantityAt(int $product_id, int $warehouse_id): float
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $warehouse_id = max(0, $warehouse_id);
        $res = $this->conn->query("SELECT quantity FROM `product_stock`
            WHERE product_id = '{$product_id}' AND warehouse_id = '{$warehouse_id}' AND tenant_id = '{$tenant_id}'");
        if ($res && $res->num_rows > 0) {
            return round((float)$res->fetch_assoc()['quantity'], 2);
        }
        return 0.0;
    }

    /**
     * Per-location quantities for a product, keyed by warehouse_id.
     *
     * @return array<int,float>
     */
    public function stockForProduct(int $product_id): array
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $out = [];
        $res = $this->conn->query("SELECT warehouse_id, quantity FROM `product_stock`
            WHERE product_id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $out[(int)$r['warehouse_id']] = round((float)$r['quantity'], 2);
            }
        }
        return $out;
    }

    /**
     * Warehouses for the tenant, including the implicit Main (0), each with the
     * number of products stocked and total units held.
     *
     * @return array<int,array{id:int,name:string,location:?string,skus:int,units:float}>
     */
    public function warehousesWithTotals(): array
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $list = [self::MAIN_WAREHOUSE_ID => ['id' => self::MAIN_WAREHOUSE_ID, 'name' => self::MAIN_WAREHOUSE_NAME, 'location' => null, 'skus' => 0, 'units' => 0.0]];

        $wh = $this->conn->query("SELECT id, name, location FROM `warehouses` WHERE tenant_id = '{$tenant_id}' AND status = 1 ORDER BY name ASC");
        if ($wh) {
            while ($w = $wh->fetch_assoc()) {
                $list[(int)$w['id']] = ['id' => (int)$w['id'], 'name' => (string)$w['name'], 'location' => $w['location'], 'skus' => 0, 'units' => 0.0];
            }
        }

        $agg = $this->conn->query("SELECT warehouse_id, COUNT(*) skus, COALESCE(SUM(quantity),0) units
            FROM `product_stock` WHERE tenant_id = '{$tenant_id}' AND quantity <> 0 GROUP BY warehouse_id");
        if ($agg) {
            while ($a = $agg->fetch_assoc()) {
                $wid = (int)$a['warehouse_id'];
                if (isset($list[$wid])) {
                    $list[$wid]['skus'] = (int)$a['skus'];
                    $list[$wid]['units'] = round((float)$a['units'], 2);
                }
            }
        }
        return $list;
    }

    public function warehouseName(int $warehouse_id): string
    {
        if ($warehouse_id === self::MAIN_WAREHOUSE_ID) {
            return self::MAIN_WAREHOUSE_NAME;
        }
        $tenant_id = (int)$this->settings->active_tenant_id();
        $res = $this->conn->query("SELECT name FROM `warehouses` WHERE id = '{$warehouse_id}' AND tenant_id = '{$tenant_id}'");
        if ($res && $res->num_rows > 0) {
            return (string)$res->fetch_assoc()['name'];
        }
        return 'Warehouse #' . $warehouse_id;
    }
}
