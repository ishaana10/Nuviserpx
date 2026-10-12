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
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Product added successfully." : " Product details updated successfully.";
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
        $del = $this->conn->query("UPDATE `product_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
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
        $reference = isset($reference) ? $this->conn->real_escape_string((string)$reference) : 'Stock Adj ' . date('Ymd');
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';

        if ($product_id <= 0 || $qty <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Please select a product and enter a valid positive quantity.']);
        }

        $prod_qry = $this->conn->query("SELECT * FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        if (!$prod_qry || $prod_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected product not found.']);
        }

        if ($type === 'in') {
            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$product_id}'");
        } elseif ($type === 'out') {
            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$product_id}'");
        } elseif ($type === 'adjustment') {
            if (isset($is_absolute) && $is_absolute == 1) {
                $this->conn->query("UPDATE `product_list` SET `stock_quantity` = '{$qty}' WHERE id = '{$product_id}'");
            } else {
                $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$product_id}'");
            }
        }

        $save_log = $this->conn->query("INSERT INTO `inventory_logs` SET
			`tenant_id` = '{$tenant_id}',
			`product_id` = '{$product_id}',
			`type` = '{$type}',
			`qty` = '{$qty}',
			`unit_cost` = '{$unit_cost}',
			`reference` = '{$reference}',
			`notes` = '{$notes}',
			`created_by` = '{$user_id}'");

        if ($save_log) {
            $resp['status'] = 'success';
            $resp['msg'] = " Stock adjustment saved successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving stock adjustment.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }
}
