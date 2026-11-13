<?php

declare(strict_types=1);

namespace App\Services;

/** Sales orders: save, confirm (stock + invoice conversion), delete. Moved verbatim out of Master. */
class SalesOrderService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;
    private string $confirm_error = '';

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function save_sales_order()
    {
        $id = $_POST['id'] ?? null;
        $order_no = $_POST['order_no'] ?? null;
        $party_id = $_POST['party_id'] ?? null;
        $order_date = $_POST['order_date'] ?? null;
        $type = $_POST['type'] ?? null;
        $warehouse_id = $_POST['warehouse_id'] ?? null;
        $subtotal = $_POST['subtotal'] ?? null;
        $tax_amount = $_POST['tax_amount'] ?? null;
        $discount = $_POST['discount'] ?? null;
        $total = $_POST['total'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $status = $_POST['status'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;
        $item_name = $_POST['item_name'] ?? null;
        $product_id = $_POST['product_id'] ?? null;
        $item_desc = $_POST['item_desc'] ?? null;
        $item_qty = $_POST['item_qty'] ?? null;
        $item_price = $_POST['item_price'] ?? null;
        $item_tax_rate = $_POST['item_tax_rate'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';
        if ($id !== '' && $this->container->make(\App\Services\DeliveryService::class)->hasDeliveries((int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => 'Deliveries have been recorded against this order, so it can no longer be edited. Cancel the deliveries first.']);
        }

        $order_no = trim((string)($order_no ?? ''));
        if ($order_no === '' && $id !== '') {
            $order_no = (string)($this->conn->query("SELECT order_no FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['order_no'] ?? '');
        }
        if ($order_no !== '' && \App\Services\SequenceService::numberTaken($this->conn, (int)$tenant_id, 'sales_order_list', 'order_no', $order_no, (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => "Order number {$order_no} is already used. Leave it blank to number it automatically."]);
        }
        if ($order_no === '') {
            $order_no = \App\Services\SequenceService::documentNo($this->conn, (int)$tenant_id, 'sales_order_list', 'order_no', 'SO-' . date('Ym') . '-', 'so-' . date('Ym'));
        }
        $order_no = $this->conn->real_escape_string($order_no);
        $party_id = $this->conn->real_escape_string((string)($party_id ?? 0));
        $order_date = !empty($order_date) ? $this->conn->real_escape_string((string)$order_date) : date('Y-m-d');
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'proforma';
        $warehouse_id = isset($warehouse_id) ? max(0, (int)$warehouse_id) : 0;
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : 'proforma';

        // Quotes: a validity date (30 days by default), and a quote that has been replaced, declined or accepted is not edited in place.
        $valid_until = isset($valid_until) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$valid_until) ? (string)$valid_until : '';
        if (\App\Services\QuotationService::isQuote($type) && $valid_until === '') {
            $valid_until = \App\Services\QuotationService::defaultValidUntil($order_date);
        }
        $validSql = $valid_until === '' ? 'NULL' : "'" . $this->conn->real_escape_string($valid_until) . "'";
        if (!empty($id)) {
            $existing = $this->conn->query("SELECT status, type, valid_until FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
            if (!$existing) {
                return json_encode(['status' => 'failed', 'msg' => 'Order not found.']);
            }
            if (in_array($existing['status'], ['superseded', 'declined', 'converted_to_invoice'], true)) {
                return json_encode(['status' => 'failed', 'msg' => 'This ' . str_replace('_', ' ', $existing['status']) . ' order can no longer be edited.' . ($existing['status'] === 'converted_to_invoice' ? '' : ' Revise the quote to change it.')]);
            }
        }
        if (in_array($status, ['confirmed', 'converted_to_invoice'], true)
            && ($problem = \App\Services\QuotationService::acceptProblem(['status' => 'proforma', 'valid_until' => $valid_until === '' ? null : $valid_until], date('Y-m-d')))) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }

        // Confirming an order books its invoice and stock movement, so the order date must fall in an open period.
        if (in_array($status, ['confirmed', 'converted_to_invoice'], true)
            && ($lockMsg = \App\Services\PeriodLockService::guard($this->conn, (int)$tenant_id, $order_date))) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        if (empty($id)) {
            $sql = "INSERT INTO `sales_order_list` SET
				`tenant_id` = '{$tenant_id}',
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`warehouse_id` = '{$warehouse_id}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`valid_until` = {$validSql},
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $sales_order_id = $this->conn->insert_id;
        } else {
            $sql = "UPDATE `sales_order_list` SET
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`warehouse_id` = '{$warehouse_id}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`valid_until` = {$validSql}
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $sales_order_id = $id;
        }

        if ($save) {
            $this->conn->query("DELETE FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            if (isset($item_name) && is_array($item_name)) {
                foreach ($item_name as $k => $v) {
                    $i_name = $this->conn->real_escape_string((string)$v);
                    if (empty($i_name)) continue;

                    $p_id = isset($product_id[$k]) && is_numeric($product_id[$k]) ? (int)$product_id[$k] : 'NULL';
                    $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                    $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                    $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                    $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
                    $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
                    $i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

                    $item_sql = "INSERT INTO `sales_order_items` SET
						`sales_order_id` = '{$sales_order_id}',
						`product_id` = {$p_id},
						`item_name` = '{$i_name}',
						`description` = '{$i_desc}',
						`qty` = '{$i_qty}',
						`unit_price` = '{$i_price}',
						`tax_rate` = '{$i_tax_rate}',
						`tax_amount` = '{$i_tax_amt}',
						`amount` = '{$i_amount}'";
                    $this->conn->query($item_sql);
                }
            }

            if ($status === 'confirmed' || $status === 'converted_to_invoice') {
                if (!$this->confirm_sales_order_internal($sales_order_id)) {
                    // The order is saved but could not be confirmed (for example not enough stock): say so rather than pretend.
                    return json_encode(['status' => 'failed', 'msg' => 'The order was saved but not confirmed. ' . ($this->confirm_error !== '' ? $this->confirm_error : 'Please try again.')]);
                }
            }

            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Sales Order / Proforma Invoice created successfully." : " Order details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving sales order.";
            \App\Support\Db::log($this->conn);
        }

        return json_encode($resp);
    }

    public function confirm_sales_order_internal($sales_order_id)
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $this->confirm_error = '';

        $order_qry = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$sales_order_id}' AND tenant_id = '{$tenant_id}'");
        if (!$order_qry || $order_qry->num_rows == 0) return false;

        $order = $order_qry->fetch_assoc();
        if ($order['stock_adjusted'] != 0) {
            return true; // already confirmed
        }
        if (!empty($order['delivery_mode'])) {
            $this->confirm_error = 'This order is fulfilled by delivery notes. Record each delivery, then invoice what was delivered.';
            return false;
        }

        // A superseded, declined, cancelled or expired quote cannot be confirmed.
        if ($problem = \App\Services\QuotationService::acceptProblem($order, date('Y-m-d'))) {
            $this->confirm_error = $problem;
            return false;
        }

        // Confirming books the invoice and the stock movement, so it must fall in an open period.
        if ($lockMsg = \App\Services\PeriodLockService::guard($this->conn, (int)$tenant_id, (string)$order['order_date'])) {
            $this->confirm_error = $lockMsg;
            return false;
        }
        $wh = isset($order['warehouse_id']) ? max(0, (int)$order['warehouse_id']) : 0;
        $inventoryOn = $this->container->make(\App\Services\InventoryAccountingService::class)->isEnabled();

        // The invoice is raised through InvoiceService, exactly as if it had been typed in: VAT is split out to the
        // output account, the period lock and numbering apply, integrations fire, and (with inventory accounting
        // on) it issues the stock and books the cost of sales. It goes first so a refusal leaves nothing half done.
        // Units can never go below zero, with or without inventory accounting (which checks again when it issues).
        $need = [];
        $needRows = $this->conn->query("SELECT product_id, qty FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
        while ($needRows && ($nr = $needRows->fetch_assoc())) {
            $need[] = $nr;
        }
        $shortage = $this->container->make(\App\Services\InventoryAccountingService::class)
            ->shortageMessage(\App\Services\InventoryAccountingService::aggregate($need));
        if ($shortage !== null) {
            $this->confirm_error = $shortage;
            return false;
        }

        $invoice_id = (int)($order['invoice_id'] ?? 0);
        $hadInvoice = $invoice_id !== 0;
        if (!$hadInvoice) {
            $post = [
                'invoice_no'   => '',   // numbered from the invoice series, never derived from the order number
                'party_id'     => (int)$order['party_id'],
                'invoice_date' => (string)$order['order_date'],
                'due_date'     => date('Y-m-d', strtotime($order['order_date'] . ' +30 days')),
                'subtotal'     => (float)$order['subtotal'],
                'tax_amount'   => (float)$order['tax_amount'],
                'discount'     => (float)$order['discount'],
                'total'        => (float)$order['total'],
                'notes'        => (string)$order['notes'],
                'status'       => 'unpaid',
                'stock_warehouse_id' => $wh,
                'item_name' => [], 'item_desc' => [], 'item_qty' => [], 'item_price' => [], 'item_tax_rate' => [], 'product_id' => [],
            ];
            $lines = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}' ORDER BY id");
            while ($lines && ($ln = $lines->fetch_assoc())) {
                $post['item_name'][] = $ln['item_name'];
                $post['item_desc'][] = $ln['description'];
                $post['item_qty'][] = $ln['qty'];
                $post['item_price'][] = $ln['unit_price'];
                $post['item_tax_rate'][] = $ln['tax_rate'];
                $post['product_id'][] = (int)$ln['product_id'] > 0 ? $ln['product_id'] : '';
            }
            $res = json_decode($this->container->make(\App\Services\InvoiceService::class)->saveInvoice($post), true);
            if (($res['status'] ?? '') !== 'success' || empty($res['id'])) {
                $this->confirm_error = (string)($res['msg'] ?? 'The invoice could not be created.');
                return false;
            }
            $invoice_id = (int)$res['id'];
        }

        // Without inventory accounting the long-standing units-only deduction runs (the invoice does not move stock).
        if (!$inventoryOn || $hadInvoice) {
            $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            while ($items && ($item = $items->fetch_assoc())) {
                $pid = (int)$item['product_id'];
                $qty = (float)$item['qty'];
                if ($pid <= 0 || $qty <= 0) continue;

                $prod = $this->conn->query("SELECT cost_price FROM `product_list` WHERE id = '{$pid}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
                $cost = isset($prod['cost_price']) ? (float)$prod['cost_price'] : 0.0;
                $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$pid}' AND tenant_id = '{$tenant_id}' AND `stock_quantity` >= {$qty}");
                if ($this->conn->affected_rows !== 1) {
                    continue; // someone else took the stock since the check above; never go below zero
                }

                // Draw the sold quantity down from the order's warehouse location.
                // syncTotal = false because the product total was just decremented above.
                try {
                    $this->container->make(\App\Services\WarehouseStockService::class)->adjustLocation($pid, $wh, -$qty, false);
                } catch (\Throwable $e) {
                    // location tracking must not block the sale
                }

                $orderNoEsc = $this->conn->real_escape_string((string)$order['order_no']);
                $this->conn->query("INSERT INTO `inventory_logs` SET
					`tenant_id` = '{$tenant_id}',
					`product_id` = '{$pid}',
					`warehouse_id` = '{$wh}',
					`type` = 'out',
					`qty` = '{$qty}',
					`unit_cost` = '{$cost}',
					`reference` = '{$orderNoEsc}',
					`notes` = 'Auto stock deduction on sales order confirmation',
					`created_by` = '{$user_id}'");
            }
        }

        $this->conn->query("UPDATE `sales_order_list` SET `stock_adjusted` = 1, `invoice_id` = '{$invoice_id}', `status` = 'converted_to_invoice' WHERE id = '{$sales_order_id}' AND tenant_id = '{$tenant_id}'");
        return true;
    }

    public function confirm_sales_order()
    {
        $id = $_POST['id'] ?? null;
        $id = $this->conn->real_escape_string((string)$id);
        if ($this->confirm_sales_order_internal($id)) {
            $resp['status'] = 'success';
            $resp['msg'] = " Sale confirmed, auto stock adjusted, and converted to Sales Invoice successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = $this->confirm_error !== '' ? $this->confirm_error : "An error occurred while confirming order.";
        }
        return json_encode($resp);
    }

    public function delete_sales_order()
    {
        $id = $_POST['id'] ?? null;
        $id = $this->conn->real_escape_string((string)$id);
        $tenant_id = $this->settings->active_tenant_id();

        $order = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if ($order && $this->container->make(\App\Services\DeliveryService::class)->hasDeliveries((int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => 'Deliveries have been recorded against this order. Cancel the deliveries (and any invoice raised from them) before deleting it.']);
        }
        if ($order && !empty($order['invoice_id'])) {
            // The invoice's cost of sales and stock are tied to it: cancel or delete the invoice instead.
            $iss = $this->conn->query("SELECT 1 FROM `invoice_items` WHERE invoice_id = '" . (int)$order['invoice_id'] . "' AND unit_cost IS NOT NULL LIMIT 1");
            if ($iss && $iss->num_rows > 0) {
                return json_encode(['status' => 'failed', 'msg' => 'This order has been invoiced and its stock issued. Delete or cancel the invoice instead, which also puts the stock back.']);
            }
        }
        if ($order) {
            if ($order['stock_adjusted'] == 1) {
                $wh = isset($order['warehouse_id']) ? max(0, (int)$order['warehouse_id']) : 0;
                $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}'");
                if ($items) {
                    while ($it = $items->fetch_assoc()) {
                        $pid = (int)$it['product_id'];
                        $qty = (float)$it['qty'];
                        if ($pid > 0 && $qty > 0) {
                            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$pid}'");
                            // Return the stock to the location it was drawn from.
                            try {
                                $this->container->make(\App\Services\WarehouseStockService::class)
                                    ->adjustLocation($pid, $wh, $qty, false);
                            } catch (\Throwable $e) {
                                // location tracking must not block the delete
                            }
                        }
                    }
                }
            }
            $this->conn->query("DELETE FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Order deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "Order not found.";
        }
        return json_encode($resp);
    }

    /* Procurement Actions (Purchase Orders, Goods Receipts, Vendor Bills) */
}
