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
    public function adjustLocation(int $product_id, int $warehouse_id, float $delta, bool $syncTotal = true): void
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        if ($product_id <= 0 || $delta == 0.0) {
            return;
        }
        $warehouse_id = max(0, $warehouse_id);

        // Upsert the location row.
        $this->conn->query("INSERT INTO `product_stock` (`tenant_id`, `product_id`, `warehouse_id`, `quantity`)
            VALUES ('{$tenant_id}', '{$product_id}', '{$warehouse_id}', '{$delta}')
            ON DUPLICATE KEY UPDATE `quantity` = `quantity` + VALUES(`quantity`)");

        if ($syncTotal) {
            $sign = $delta >= 0 ? '+' : '-';
            $abs = abs($delta);
            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` {$sign} {$abs}
                WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        }
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

        if ($product_id <= 0 || $qty <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Valid product and quantity (> 0) are required.']);
        }
        if ($from_wh === $to_wh) {
            return json_encode(['status' => 'failed', 'msg' => 'Source and destination warehouses must be different.']);
        }

        $available = $this->quantityAt($product_id, $from_wh);
        if ($qty > $available + 0.00001) {
            return json_encode(['status' => 'failed', 'msg' => 'Only ' . rtrim(rtrim(number_format($available, 2), '0'), '.') . ' in stock at the source warehouse.']);
        }

        // Move: out of source, into destination. Total unchanged -> syncTotal = false.
        $this->adjustLocation($product_id, $from_wh, -$qty, false);
        $this->adjustLocation($product_id, $to_wh, $qty, false);

        // Record the transfer and movement logs (location-aware).
        $notes_e = $this->conn->real_escape_string($notes);
        $this->conn->query("INSERT INTO `warehouse_transfers`
            (`tenant_id`, `from_warehouse_id`, `to_warehouse_id`, `product_id`, `qty`, `notes`, `created_by`)
            VALUES ('{$tenant_id}', '{$from_wh}', '{$to_wh}', '{$product_id}', '{$qty}', '{$notes_e}', '{$user_id}')");

        $ref = 'Transfer ' . $this->warehouseName($from_wh) . ' -> ' . $this->warehouseName($to_wh);
        $ref_e = $this->conn->real_escape_string($ref);
        // Log an out from source and an in to destination for the movement ledger.
        $this->conn->query("INSERT INTO `inventory_logs` (`tenant_id`, `product_id`, `warehouse_id`, `type`, `qty`, `reference`, `notes`, `created_by`)
            VALUES ('{$tenant_id}', '{$product_id}', '{$from_wh}', 'out', '{$qty}', '{$ref_e}', '{$notes_e}', '{$user_id}')");
        $this->conn->query("INSERT INTO `inventory_logs` (`tenant_id`, `product_id`, `warehouse_id`, `type`, `qty`, `reference`, `notes`, `created_by`)
            VALUES ('{$tenant_id}', '{$product_id}', '{$to_wh}', 'in', '{$qty}', '{$ref_e}', '{$notes_e}', '{$user_id}')");

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
