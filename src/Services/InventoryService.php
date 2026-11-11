<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class InventoryService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveProduct(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $product_code = isset($product_code) ? $this->conn->real_escape_string((string)$product_code) : '';
        $name = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $category = isset($category) ? $this->conn->real_escape_string((string)$category) : '';
        $unit = !empty($unit) ? $this->conn->real_escape_string((string)$unit) : 'pcs';
        $cost_price = isset($cost_price) ? (float)$cost_price : 0.0;
        $selling_price = isset($selling_price) ? (float)$selling_price : 0.0;
        $stock_quantity = isset($stock_quantity) ? (float)$stock_quantity : 0.0;
        $reorder_level = isset($reorder_level) ? (float)$reorder_level : 10.0;
        $description = isset($description) ? $this->conn->real_escape_string((string)$description) : '';
        $status = isset($status) ? (int)$status : 1;

        $check = $this->conn->query("SELECT * FROM `product_list` WHERE `product_code` = '{$product_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Product Code already exists.']);
        }

        $before = !empty($id) ? ($this->conn->query("SELECT * FROM `product_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: []) : [];
        if (empty($id)) {
            $sql = "INSERT INTO `product_list` SET
				`tenant_id` = '{$tenant_id}',
				`product_code` = '{$product_code}',
				`name` = '{$name}',
				`category` = '{$category}',
				`unit` = '{$unit}',
				`cost_price` = '{$cost_price}',
				`selling_price` = '{$selling_price}',
				`stock_quantity` = '{$stock_quantity}',
				`reorder_level` = '{$reorder_level}',
				`description` = '{$description}',
				`status` = '{$status}'";
            $save = $this->conn->query($sql);
            $product_id = $this->conn->insert_id;

            if ($save && $stock_quantity > 0 && ($openMsg = (new InventoryAccountingService($this->conn, $this->settings))->postOpeningStock((int)$product_id, $stock_quantity, $cost_price, $product_code))) {
                $openingWarning = ' The opening stock could not be posted to the ledger: ' . $openMsg;
            }
            if ($save && $stock_quantity > 0) {
                // Log initial stock entry
                $userId = $this->settings->userdata('id');
                $this->conn->query("INSERT INTO `inventory_logs` SET
					`tenant_id` = '{$tenant_id}',
					`product_id` = '{$product_id}',
					`type` = 'in',
					`qty` = '{$stock_quantity}',
					`unit_cost` = '{$cost_price}',
					`reference` = 'Initial Stock',
					`notes` = 'Initial opening stock entry',
					`created_by` = '{$userId}'");
            }
        } else {
            $sql = "UPDATE `product_list` SET
				`product_code` = '{$product_code}',
				`name` = '{$name}',
				`category` = '{$category}',
				`unit` = '{$unit}',
				`cost_price` = '{$cost_price}',
				`selling_price` = '{$selling_price}',
				`reorder_level` = '{$reorder_level}',
				`description` = '{$description}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
        }

        if ($save) {
            $name_raw = (string)($post['name'] ?? '');
            if (empty($id)) {
                $this->settings->log_audit('Created Product', 'Inventory', "Created product '{$name_raw}' (#{$product_id}), opening stock {$stock_quantity} at cost {$cost_price}.");
            } else {
                $diff = \App\Support\AuditDiff::changes($before, $post, ['product_code', 'name', 'category', 'unit', 'cost_price', 'selling_price', 'reorder_level', 'description', 'status']);
                $this->settings->log_audit('Updated Product', 'Inventory', "Updated '{$name_raw}' (#{$id}): {$diff}.");
            }
            $resp['status'] = 'success';
            $resp['msg'] = (empty($id) ? " Product added successfully." : " Product details updated successfully.") . ($openingWarning ?? '');
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving product.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function deleteProduct(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $was = $this->conn->query("SELECT name, product_code, stock_quantity FROM `product_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("UPDATE `product_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Product', 'Inventory', "Deleted '{$was['name']}' ({$was['product_code']}), #{$id}, with {$was['stock_quantity']} in stock.");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Product deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function saveStockAdjustment(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $product_id = isset($product_id) ? (int)$product_id : 0;
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'in';
        $qty = isset($qty) ? (float)$qty : 0.0;
        $unit_cost = isset($unit_cost) ? (float)$unit_cost : 0.0;
        $warehouse_id = isset($warehouse_id) ? max(0, (int)$warehouse_id) : 0;
        $reference = isset($reference) ? $this->conn->real_escape_string((string)$reference) : 'Stock Adj ' . date('Ymd');
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';

        $absolute = $type === 'adjustment' && isset($is_absolute) && $is_absolute == 1;
        if (!in_array($type, ['in', 'out', 'adjustment'], true)) {
            return json_encode(['status' => 'failed', 'msg' => 'Choose a valid adjustment type.']);
        }
        if ($product_id <= 0 || $qty < 0 || ($qty == 0 && !$absolute)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please select a product and enter a valid positive quantity.']);
        }

        $prod_qry = $this->conn->query("SELECT * FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        if (!$prod_qry || $prod_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected product not found.']);
        }
        if ($warehouse_id > 0) {
            $wh = $this->conn->query("SELECT 1 FROM `warehouses` WHERE id = '{$warehouse_id}' AND tenant_id = '{$tenant_id}' LIMIT 1");
            if (!$wh || $wh->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'That warehouse was not found.']);
            }
        }

        // The change is always to one location (the chosen warehouse); the product total moves by the same amount, so
        // the total stays equal to the sum of its locations. A count sets that location to the counted figure.
        $stockSvc = new WarehouseStockService($this->conn, $this->settings);
        $atLocation = $stockSvc->quantityAt($product_id, $warehouse_id);
        if ($type === 'out') {
            $delta = -$qty;
        } elseif ($absolute) {
            $delta = round($qty - $atLocation, 4);
        } else {
            $delta = $qty;
        }
        if ($delta == 0.0) {
            return json_encode(['status' => 'failed', 'msg' => 'That is already the quantity recorded there, so there is nothing to change.']);
        }
        if ($delta < 0 && -$delta > $atLocation + 0.00001) {
            return json_encode(['status' => 'failed', 'msg' => 'Only ' . rtrim(rtrim(number_format($atLocation, 2, '.', ''), '0'), '.') . ' in stock at that warehouse, so ' . rtrim(rtrim(number_format(-$delta, 2, '.', ''), '0'), '.') . ' cannot be taken out.']);
        }

        // With inventory accounting on, the change in stock value is posted to the ledger first (and refused if the
        // period is closed or the stock is not there), then the quantities are applied below.
        $accounting = new InventoryAccountingService($this->conn, $this->settings);
        $journalId = 0;
        if ($accounting->isEnabled()) {
            $valued = $accounting->valueChange($product_id, $delta, $delta > 0 && $unit_cost > 0 ? $unit_cost : null, date('Y-m-d'), $reference);
            if (!$valued['ok']) {
                return json_encode(['status' => 'failed', 'msg' => $valued['msg']]);
            }
            $journalId = $valued['journal_id'];
        }

        // Total, location and movement log change together or not at all. The total can never be driven below zero.
        $logType = $type;
        $this->conn->begin_transaction();
        try {
            $ok = (bool)$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + ({$delta})
                WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}' AND `stock_quantity` + ({$delta}) >= 0");
            if (!$ok || $this->conn->affected_rows !== 1) {
                throw new \RuntimeException('Stock changed while this was being saved.');
            }
            if (!$stockSvc->adjustLocation($product_id, $warehouse_id, $delta, false)) {
                throw new \RuntimeException($this->conn->error);
            }
            if ($absolute) {
                $logType = 'adjustment';
            }
            $saved = $this->conn->query("INSERT INTO `inventory_logs` SET
				`tenant_id` = '{$tenant_id}',
				`product_id` = '{$product_id}',
				`warehouse_id` = '{$warehouse_id}',
				`type` = '{$logType}',
				`qty` = '{$qty}',
				`unit_cost` = '{$unit_cost}',
				`reference` = '{$reference}',
				`notes` = '{$notes}',
				" . ($journalId > 0 ? "`journal_id` = '{$journalId}'," : '') . "
				`created_by` = '{$user_id}'");
            if (!$saved) {
                throw new \RuntimeException($this->conn->error);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            if ($journalId > 0) {
                // Do not leave a stock value in the ledger for a movement that did not happen.
                (new JournalService($this->conn, $this->settings))->reverseJournal($journalId, date('Y-m-d'), true);
            }
            return json_encode(['status' => 'failed', 'msg' => 'The stock adjustment could not be saved. Nothing was changed.']);
        }

        $this->settings->log_audit('Stock Adjustment', 'Inventory', "Product #{$product_id}: " . ($absolute ? "counted {$qty}" : "{$type} {$qty}") . " (change {$delta}) at warehouse #{$warehouse_id}, was {$atLocation}, ref '" . trim((string)($post['reference'] ?? '')) . "'" . ($journalId > 0 ? ", journal #{$journalId}" : '') . '.');
        $resp['status'] = 'success';
        $resp['msg'] = " Stock adjustment saved successfully.";
        $this->settings->set_flashdata('success', $resp['msg']);
        return json_encode($resp);
    }
}
