<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * PurchaseService — Procurement module (Purchase Orders, Goods Receipts, Vendor Bills).
 *
 * Implements the standard three-way procurement flow:
 *     Purchase Order  ->  Goods Receipt (stock in)  ->  Vendor Bill (Accounts Payable)
 *
 * Vendor bills post a balanced GL journal that is the mirror of a sales invoice:
 *     Dr  Inventory / Purchases / Expense      (what you received)
 *     Cr  Accounts Payable                     (what you owe the vendor)
 *
 * Goods receipts move stock into inventory and maintain a weighted-average cost
 * on product_list.cost_price, logging each movement to inventory_logs (type 'in').
 */
class PurchaseService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ===================================================================
     *  PURCHASE ORDERS
     * =================================================================== */

    public function savePurchaseOrder(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $po_no = trim((string)($po_no ?? ''));
        if ($po_no === '' && $id !== '') {
            $po_no = (string)($this->conn->query("SELECT po_no FROM `purchase_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['po_no'] ?? '');
        }
        if ($po_no !== '' && SequenceService::numberTaken($this->conn, (int)$tenant_id, 'purchase_order_list', 'po_no', $po_no, (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => "Purchase order number {$po_no} is already used. Leave it blank to number it automatically."]);
        }
        $po_no = $po_no !== '' ? $this->conn->real_escape_string($po_no) : $this->nextNumber('purchase_order_list', 'po_no', 'PO', (int)$tenant_id);
        $vendor_id = $this->conn->real_escape_string((string)($vendor_id ?? 0));
        $order_date = !empty($order_date) ? $this->conn->real_escape_string((string)$order_date) : date('Y-m-d');
        $expected_date = !empty($expected_date) ? "'" . $this->conn->real_escape_string((string)$expected_date) . "'" : 'NULL';
        $warehouse_id = !empty($warehouse_id) ? (int)$warehouse_id : 'NULL';
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : 'draft';

        // Validate vendor
        $v = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$vendor_id}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0");
        if (!$v || $v->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected vendor not found.']);
        }
        $vendor = $v->fetch_assoc();

        if (empty($id)) {
            $sql = "INSERT INTO `purchase_order_list` SET
				`tenant_id` = '{$tenant_id}',
				`po_no` = '{$po_no}',
				`vendor_id` = '{$vendor_id}',
				`order_date` = '{$order_date}',
				`expected_date` = {$expected_date},
				`warehouse_id` = {$warehouse_id},
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $po_id = $this->conn->insert_id;
        } else {
            $sql = "UPDATE `purchase_order_list` SET
				`po_no` = '{$po_no}',
				`vendor_id` = '{$vendor_id}',
				`order_date` = '{$order_date}',
				`expected_date` = {$expected_date},
				`warehouse_id` = {$warehouse_id},
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $po_id = (int)$id;
        }

        if (!$save) {
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while saving the purchase order.', 'err' => $this->conn->error]);
        }

        // Replace line items
        $this->conn->query("DELETE FROM `purchase_order_items` WHERE purchase_order_id = '{$po_id}'");
        if (isset($item_name) && is_array($item_name)) {
            foreach ($item_name as $k => $v2) {
                $i_name = $this->conn->real_escape_string((string)$v2);
                if (empty($i_name)) continue;

                $p_id = isset($product_id[$k]) && $product_id[$k] !== '' ? (int)$product_id[$k] : 'NULL';
                $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
                $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
                $i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

                $this->conn->query("INSERT INTO `purchase_order_items` SET
					`purchase_order_id` = '{$po_id}',
					`product_id` = {$p_id},
					`item_name` = '{$i_name}',
					`description` = '{$i_desc}',
					`qty` = '{$i_qty}',
					`unit_price` = '{$i_price}',
					`tax_rate` = '{$i_tax_rate}',
					`tax_amount` = '{$i_tax_amt}',
					`amount` = '{$i_amount}'");
            }
        }

        $this->settings->set_flashdata('success', empty($id) ? ' Purchase order created successfully.' : ' Purchase order updated successfully.');
        $this->emit(
            empty($id) ? \App\Support\IntegrationEvents::PURCHASE_ORDER_CREATED : \App\Support\IntegrationEvents::PURCHASE_ORDER_UPDATED,
            [
                'purchase_order_id' => (int)$po_id,
                'po_no'             => $po_no,
                'vendor_id'         => (int)$vendor_id,
                'vendor_name'       => $vendor['name'] ?? '',
                'total'             => (float)$total,
                'status'            => $status,
                'order_date'        => $order_date,
            ],
            (int)$tenant_id
        );

        return json_encode([
            'status' => 'success',
            'msg'    => empty($id) ? ' Purchase order created successfully.' : ' Purchase order updated successfully.',
            'id'     => (int)$po_id,
        ]);
    }

    public function deletePurchaseOrder(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $po = $this->conn->query("SELECT po_no FROM `purchase_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$po || $po->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Purchase order not found.']);
        }
        $po_no = $po->fetch_assoc()['po_no'] ?? '';

        $del = $this->conn->query("DELETE FROM `purchase_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->set_flashdata('success', ' Purchase order deleted successfully.');
            $this->emit(\App\Support\IntegrationEvents::PURCHASE_ORDER_DELETED, [
                'purchase_order_id' => (int)$id,
                'po_no'             => $po_no,
            ], (int)$tenant_id);
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'error' => $this->conn->error]);
    }

    /* ===================================================================
     *  GOODS RECEIPTS  (receive stock against a PO)
     * =================================================================== */

    public function receiveGoods(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $po_id = isset($purchase_order_id) ? (int)$purchase_order_id : 0;
        $receipt_date = !empty($receipt_date) ? $this->conn->real_escape_string((string)$receipt_date) : date('Y-m-d');
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';

        $po_qry = $this->conn->query("SELECT * FROM `purchase_order_list` WHERE id = '{$po_id}' AND tenant_id = '{$tenant_id}'");
        if (!$po_qry || $po_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Purchase order not found.']);
        }
        $po = $po_qry->fetch_assoc();
        $vendor_id = (int)$po['vendor_id'];
        $warehouse_id = !empty($po['warehouse_id']) ? (int)$po['warehouse_id'] : 'NULL';

        // Nothing to receive?
        if (!isset($po_item_id) || !is_array($po_item_id)) {
            return json_encode(['status' => 'failed', 'msg' => 'No lines to receive.']);
        }

        $grn_no = $this->nextNumber('goods_receipt_list', 'grn_no', 'GRN', (int)$tenant_id);
        $this->conn->query("INSERT INTO `goods_receipt_list` SET
			`tenant_id` = '{$tenant_id}',
			`grn_no` = '{$grn_no}',
			`purchase_order_id` = '{$po_id}',
			`vendor_id` = '{$vendor_id}',
			`warehouse_id` = {$warehouse_id},
			`receipt_date` = '{$receipt_date}',
			`status` = 'received',
			`stock_adjusted` = 1,
			`notes` = '{$notes}',
			`created_by` = '{$user_id}'");
        $grn_id = $this->conn->insert_id;

        $any = false;
        foreach ($po_item_id as $k => $poi) {
            $poi_id = (int)$poi;
            $recv_qty = isset($receive_qty[$k]) ? (float)$receive_qty[$k] : 0.0;
            if ($poi_id <= 0 || $recv_qty <= 0) continue;

            $line = $this->conn->query("SELECT * FROM `purchase_order_items` WHERE id = '{$poi_id}' AND purchase_order_id = '{$po_id}'");
            if (!$line || $line->num_rows == 0) continue;
            $li = $line->fetch_assoc();

            // Cap at outstanding qty
            $outstanding = (float)$li['qty'] - (float)$li['qty_received'];
            if ($outstanding <= 0) continue;
            if ($recv_qty > $outstanding) $recv_qty = $outstanding;

            $prod_id = !empty($li['product_id']) ? (int)$li['product_id'] : 'NULL';
            $unit_cost = (float)$li['unit_price'];
            $i_name = $this->conn->real_escape_string((string)$li['item_name']);

            $this->conn->query("INSERT INTO `goods_receipt_items` SET
				`goods_receipt_id` = '{$grn_id}',
				`po_item_id` = '{$poi_id}',
				`product_id` = {$prod_id},
				`item_name` = '{$i_name}',
				`qty_received` = '{$recv_qty}',
				`unit_cost` = '{$unit_cost}'");

            // Update received qty on PO line
            $this->conn->query("UPDATE `purchase_order_items` SET `qty_received` = `qty_received` + {$recv_qty} WHERE id = '{$poi_id}'");

            // Move stock in + weighted-average cost, and log it
            if (is_int($prod_id)) {
                $wh = is_int($warehouse_id) ? (int)$warehouse_id : 0;
                $this->receiveStock((int)$prod_id, $recv_qty, $unit_cost, (int)$tenant_id, (int)$user_id, $grn_no, $wh);
            }
            $any = true;
        }

        if (!$any) {
            // Roll back the empty GRN shell
            $this->conn->query("DELETE FROM `goods_receipt_list` WHERE id = '{$grn_id}'");
            return json_encode(['status' => 'failed', 'msg' => 'No valid quantities were received.']);
        }

        // Recompute PO status from line fulfilment
        $this->refreshPoReceiptStatus($po_id, (int)$tenant_id);

        $this->settings->set_flashdata('success', " Goods receipt {$grn_no} recorded. Stock updated.");
        $this->emit(\App\Support\IntegrationEvents::GOODS_RECEIVED, [
            'goods_receipt_id'  => (int)$grn_id,
            'grn_no'            => $grn_no,
            'purchase_order_id' => (int)$po_id,
            'po_no'             => $po['po_no'] ?? '',
            'vendor_id'         => (int)$vendor_id,
            'receipt_date'      => $receipt_date,
        ], (int)$tenant_id);

        return json_encode(['status' => 'success', 'msg' => " Goods receipt {$grn_no} recorded.", 'grn_id' => (int)$grn_id]);
    }

    /**
     * Increase product stock and recompute weighted-average cost, landing the
     * received quantity in the given warehouse location ($warehouse_id, 0 = Main).
     */
    private function receiveStock(int $product_id, float $qty, float $unit_cost, int $tenant_id, int $user_id, string $reference, int $warehouse_id = 0): void
    {
        $p = $this->conn->query("SELECT stock_quantity, cost_price FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        if (!$p || $p->num_rows == 0) return;
        $prod = $p->fetch_assoc();

        $old_qty = (float)$prod['stock_quantity'];
        $old_cost = (float)$prod['cost_price'];
        $new_qty = $old_qty + $qty;

        // Weighted-average cost (AVCO). Guard divide-by-zero.
        $new_cost = $new_qty > 0
            ? round((($old_qty * $old_cost) + ($qty * $unit_cost)) / $new_qty, 4)
            : $unit_cost;

        $this->conn->query("UPDATE `product_list`
			SET `stock_quantity` = '{$new_qty}', `cost_price` = '{$new_cost}'
			WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");

        // Land the received quantity in the warehouse location. syncTotal = false
        // because the product total was already updated above.
        try {
            \App\Core\Container::getInstance()->make(\App\Services\WarehouseStockService::class)
                ->adjustLocation($product_id, $warehouse_id, $qty, false);
        } catch (\Throwable $e) {
            // location tracking must not break the receipt
        }

        $wh = max(0, $warehouse_id);
        $ref = $this->conn->real_escape_string($reference);
        $this->conn->query("INSERT INTO `inventory_logs` SET
				`warehouse_id` = '{$wh}',
			`tenant_id` = '{$tenant_id}',
			`product_id` = '{$product_id}',
			`type` = 'in',
			`qty` = '{$qty}',
			`unit_cost` = '{$unit_cost}',
			`reference` = '{$ref}',
			`notes` = 'Goods receipt from purchase order',
			`created_by` = '{$user_id}'");
    }

    private function refreshPoReceiptStatus(int $po_id, int $tenant_id): void
    {
        $agg = $this->conn->query("SELECT
				COALESCE(SUM(qty),0) AS ordered,
				COALESCE(SUM(qty_received),0) AS received
			FROM `purchase_order_items` WHERE purchase_order_id = '{$po_id}'")->fetch_assoc();

        $ordered = (float)$agg['ordered'];
        $received = (float)$agg['received'];

        // Don't downgrade a billed/cancelled PO.
        $cur = $this->conn->query("SELECT status FROM `purchase_order_list` WHERE id = '{$po_id}'")->fetch_assoc();
        if (in_array($cur['status'] ?? '', ['billed', 'cancelled'], true)) return;

        if ($received <= 0) {
            $status = 'confirmed';
        } elseif ($received < $ordered) {
            $status = 'partially_received';
        } else {
            $status = 'received';
        }
        $this->conn->query("UPDATE `purchase_order_list` SET `status` = '{$status}' WHERE id = '{$po_id}' AND tenant_id = '{$tenant_id}'");
    }

    /* ===================================================================
     *  VENDOR BILLS  (Accounts Payable + GL posting)
     * =================================================================== */

    public function saveVendorBill(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $bill_no = trim((string)($bill_no ?? ''));
        if ($bill_no === '' && $id !== '') {
            $bill_no = (string)($this->conn->query("SELECT bill_no FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['bill_no'] ?? '');
        }
        if ($bill_no !== '' && SequenceService::numberTaken($this->conn, (int)$tenant_id, 'vendor_bill_list', 'bill_no', $bill_no, (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => "Bill number {$bill_no} is already used. Leave it blank to number it automatically."]);
        }
        $bill_no = $bill_no !== '' ? $this->conn->real_escape_string($bill_no) : $this->nextNumber('vendor_bill_list', 'bill_no', 'BILL', (int)$tenant_id);
        $vendor_ref = isset($vendor_ref) ? $this->conn->real_escape_string((string)$vendor_ref) : '';
        $po_id = !empty($purchase_order_id) ? (int)$purchase_order_id : 'NULL';
        $vendor_id = $this->conn->real_escape_string((string)($vendor_id ?? 0));
        $bill_date = !empty($bill_date) ? $this->conn->real_escape_string((string)$bill_date) : date('Y-m-d');
        $due_date = !empty($due_date) ? $this->conn->real_escape_string((string)$due_date) : date('Y-m-d', strtotime('+30 days'));
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : 'unpaid';

        $v = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$vendor_id}' AND tenant_id = '{$tenant_id}'");
        if (!$v || $v->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected vendor not found.']);
        }
        $vendor = $v->fetch_assoc();

        // Preserve paid_amount on edit; recompute balance + status.
        $paid_amount = 0.0;
        if (!empty($id)) {
            $cur = $this->conn->query("SELECT paid_amount, credited_amount FROM `vendor_bill_list` WHERE id = '{$id}'");
            if ($cur && $cur->num_rows) {
                $row = $cur->fetch_assoc();
                $paid_amount = (float)$row['paid_amount'];
                $credited_amount = (float)$row['credited_amount'];
            }
        }
        $credited_amount = $credited_amount ?? 0.0;
        if (!empty($id) && $status !== 'cancelled' && $status !== 'draft' && $total + 0.005 < $paid_amount + $credited_amount) {
            return json_encode(['status' => 'failed', 'msg' => 'This bill has already had ' . number_format($paid_amount + $credited_amount, 2) . ' paid or credited, so its total cannot be lowered below that. Void the payment first.']);
        }
        // Debit notes count towards settling the bill just as payments do.
        $balance = CreditNoteService::outstanding($total, $paid_amount, $credited_amount);
        if ($status !== 'cancelled' && $status !== 'draft') {
            if ($paid_amount + $credited_amount >= $total && $total > 0) {
                $status = 'paid';
            } elseif ($paid_amount + $credited_amount > 0) {
                $status = 'partially_paid';
            } else {
                $status = 'unpaid';
            }
        }

        // Maker/checker: a bill over the company's threshold is held for approval before it reaches the ledger.
        $pendingApproval = false;
        if (!in_array($status, ['draft', 'cancelled'], true)) {
            $existingBill = null;
            if (!empty($id)) {
                $eb = $this->conn->query("SELECT status, total FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
                $existingBill = $eb ? $eb->fetch_assoc() : null;
                if ($existingBill && $existingBill['status'] === ApprovalService::PENDING) {
                    return json_encode(['status' => 'failed', 'msg' => 'This bill is waiting for approval and cannot be changed. An approver can approve it or return it to draft.']);
                }
            }
            $pol = (new ApprovalService($this->conn, $this->settings))->policy(ApprovalService::DOC_BILL);
            // An approved bill whose amount is unchanged does not need approving again.
            $unchanged = $existingBill && $existingBill['status'] !== 'draft' && abs((float)$existingBill['total'] - $total) < 0.005;
            if (!$unchanged && ApprovalService::requiresApproval($pol['enabled'], $pol['threshold'], $total)) {
                $status = ApprovalService::PENDING;
                $pendingApproval = true;
            }
        }

        // GL accounts: Dr Purchases/Inventory/Expense, Cr Accounts Payable.
        $ap_acc_id = $this->findAccount(['payable', 'liabilit'], 3, (int)$tenant_id);
        $exp_acc_id = $this->findAccount(['purchase', 'inventory', 'cost of goods', 'expense'], 5, (int)$tenant_id);
        // With inventory accounting on, the part of the bill that buys stock products is an asset (Inventory), not
        // an expense: the cost reaches the profit and loss only when the goods are sold.
        $invSvc = new InventoryAccountingService($this->conn, $this->settings);
        $invAcc = 0;
        $stockNet = 0.0;
        if ($invSvc->isEnabled()) {
            $invAcc = $invSvc->config()['inventory_account_id'];
            $stockNet = $this->stockNet($post, $invSvc);
        }

        $vendorName = $vendor['name'] ?? '';
        $description = $this->conn->real_escape_string("Vendor Bill {$bill_no} from {$vendorName} - Total: \${$total}");
        $journal_date = $bill_date;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'vendor_bill_list', (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        if (empty($id)) {
            $journal_code = $this->nextJournalCode((int)$tenant_id);
            $this->conn->query("INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`)
				VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')");
            $journal_id = $this->conn->insert_id;

            // group_id mirrors the chart-of-accounts group used in InvoiceService (expense=5-ish / liability=3).
            $this->postBillLines((int)$journal_id, (int)$ap_acc_id, (int)$exp_acc_id, (float)$total, (float)$tax_amount, $invAcc, $stockNet);

            $sql = "INSERT INTO `vendor_bill_list` SET
				`tenant_id` = '{$tenant_id}',
				`bill_no` = '{$bill_no}',
				`vendor_ref` = '{$vendor_ref}',
				`purchase_order_id` = {$po_id},
				`vendor_id` = '{$vendor_id}',
				`bill_date` = '{$bill_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`paid_amount` = '{$paid_amount}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				`journal_id` = '{$journal_id}',
				`notes` = '{$notes}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $bill_id = $this->conn->insert_id;
            if ($save && $pendingApproval) {
                $this->conn->query("UPDATE `vendor_bill_list` SET `submitted_by` = '{$user_id}' WHERE id = '{$bill_id}'");
            }
        } else {
            $existing = $this->conn->query("SELECT journal_id FROM `vendor_bill_list` WHERE id = '{$id}'")->fetch_assoc();
            $journal_id = $existing['journal_id'] ?? null;
            if ($journal_id) {
                $trail = new JournalTrail($this->conn, $this->settings);
                $trailBefore = $trail->snapshot((int)$journal_id);
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
                $this->postBillLines((int)$journal_id, (int)$ap_acc_id, (int)$exp_acc_id, (float)$total, (float)$tax_amount, $invAcc, $stockNet);
                $trail->record((int)$journal_id, 'vendor_bill_list', (int)$id, "Bill {$bill_no}", $trailBefore);
            }

            $sql = "UPDATE `vendor_bill_list` SET
				`bill_no` = '{$bill_no}',
				`vendor_ref` = '{$vendor_ref}',
				`purchase_order_id` = {$po_id},
				`vendor_id` = '{$vendor_id}',
				`bill_date` = '{$bill_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				`notes` = '{$notes}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $bill_id = (int)$id;
            if ($save && $pendingApproval) {
                $this->conn->query("UPDATE `vendor_bill_list` SET `submitted_by` = '{$user_id}' WHERE id = '{$bill_id}'");
            }
        }

        // The bill's journal follows its approval state: held out of the ledger until approved.
        if ($save && !empty($journal_id) && ($pendingApproval || $status !== 'draft')) {
            $jstatus = $pendingApproval ? ApprovalService::PENDING : 'posted';
            $this->conn->query("UPDATE `journal_entries` SET `status` = '{$jstatus}' WHERE id = '{$journal_id}' AND tenant_id = '{$tenant_id}'");
        }

        if (!$save) {
            return json_encode(['status' => 'failed', 'msg' => 'An error occurred while saving the vendor bill.', 'err' => $this->conn->error]);
        }

        // Line items
        $this->conn->query("DELETE FROM `vendor_bill_items` WHERE vendor_bill_id = '{$bill_id}'");
        if (isset($item_name) && is_array($item_name)) {
            foreach ($item_name as $k => $v2) {
                $i_name = $this->conn->real_escape_string((string)$v2);
                if (empty($i_name)) continue;
                $p_id = isset($product_id[$k]) && $product_id[$k] !== '' ? (int)$product_id[$k] : 'NULL';
                $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
                $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
                $i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

                $this->conn->query("INSERT INTO `vendor_bill_items` SET
					`vendor_bill_id` = '{$bill_id}',
					`product_id` = {$p_id},
					`item_name` = '{$i_name}',
					`description` = '{$i_desc}',
					`qty` = '{$i_qty}',
					`unit_price` = '{$i_price}',
					`tax_rate` = '{$i_tax_rate}',
					`tax_amount` = '{$i_tax_amt}',
					`amount` = '{$i_amount}'");
            }
        }

        // If bill is linked to a PO, flag the PO as billed.
        if (is_int($po_id)) {
            $this->conn->query("UPDATE `purchase_order_list` SET `status` = 'billed' WHERE id = '{$po_id}' AND tenant_id = '{$tenant_id}'");
        }

        $this->settings->set_flashdata('success', empty($id) ? ' Vendor bill created successfully.' : ' Vendor bill updated successfully.');
        $this->emit(
            empty($id) ? \App\Support\IntegrationEvents::VENDOR_BILL_CREATED : \App\Support\IntegrationEvents::VENDOR_BILL_UPDATED,
            [
                'vendor_bill_id' => (int)$bill_id,
                'bill_no'        => $bill_no,
                'vendor_id'      => (int)$vendor_id,
                'vendor_name'    => $vendorName,
                'total'          => (float)$total,
                'balance'        => (float)$balance,
                'status'         => $status,
                'bill_date'      => $bill_date,
                'due_date'       => $due_date,
            ],
            (int)$tenant_id
        );

        return json_encode([
            'status' => 'success',
            'msg'    => $pendingApproval ? ' Vendor bill saved and submitted for approval.' : (empty($id) ? ' Vendor bill created successfully.' : ' Vendor bill updated successfully.'),
            'id'     => (int)$bill_id,
        ]);
    }

    public function deleteVendorBill(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, null, 'vendor_bill_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        $bill = $this->conn->query("SELECT bill_no, journal_id FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$bill || $bill->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Vendor bill not found.']);
        }
        $b = $bill->fetch_assoc();
        $pay = $this->conn->query("SELECT 1 FROM bill_payment_allocations a INNER JOIN bill_payments p ON p.id = a.payment_id AND p.status = 'posted' WHERE a.bill_id = '{$id}' LIMIT 1");
        if ($pay && $pay->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'This bill has payments against it. Void the payments before deleting the bill.']);
        }
        if (!empty($b['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$b['journal_id']}'");
        }
        $del = $this->conn->query("DELETE FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->set_flashdata('success', ' Vendor bill deleted successfully.');
            $this->emit(\App\Support\IntegrationEvents::VENDOR_BILL_DELETED, [
                'vendor_bill_id' => (int)$id,
                'bill_no'        => $b['bill_no'] ?? '',
            ], (int)$tenant_id);
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'error' => $this->conn->error]);
    }

    /* ===================================================================
     *  Helpers
     * =================================================================== */

    /**
     * Find a GL account by name keyword(s), scoped to tenant. Falls back to $default.
     *
     * @param array<int,string> $keywords
     */
    private function findAccount(array $keywords, int $default, int $tenant_id): int
    {
        $clauses = [];
        foreach ($keywords as $kw) {
            $kw = $this->conn->real_escape_string($kw);
            $clauses[] = "`name` LIKE '%{$kw}%'";
        }
        $where = implode(' OR ', $clauses);
        $q = $this->conn->query("SELECT id FROM `account_list` WHERE ({$where}) AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        if ($q && $q->num_rows > 0) {
            return (int)$q->fetch_assoc()['id'];
        }
        return $default;
    }

    /**
     * Generate the next sequential document number for a tenant, e.g. PO-202611-0001.
     */
    /** Next PO / bill number, taken under a row lock so two people saving together never get the same one. */
    private function nextNumber(string $table, string $column, string $prefix, int $tenant_id): string
    {
        $ym = date('Ym');
        return SequenceService::documentNo($this->conn, $tenant_id, $table, $column, "{$prefix}-{$ym}-", strtolower($prefix) . "-{$ym}");
    }

    private function nextJournalCode(int $tenant_id): string
    {
        return SequenceService::journalCode($this->conn, $tenant_id);
    }

    /**
     * Fire an integration event through ModuleManager (webhooks + workflows).
     * Guarded so an integration failure never breaks the core transaction.
     *
     * @param array<string,mixed> $payload
     */
    private function emit(string $event, array $payload, int $tenant_id): void
    {
        if (class_exists('ModuleManager')) {
            try {
                \ModuleManager::trigger_hook($event, $payload, $tenant_id);
            } catch (\Throwable $e) {
                // swallow — integration layer must not surface to core
            }
        }
    }

    /** Net value of the bill lines that buy stock products (this company's products only). */
    private function stockNet(array $post, InventoryAccountingService $inv): float
    {
        $rows = [];
        foreach ((array)($post['item_name'] ?? []) as $k => $name) {
            if (empty($name)) {
                continue;
            }
            $rows[] = [
                'product_id' => isset($post['product_id'][$k]) && is_numeric($post['product_id'][$k]) ? (int)$post['product_id'][$k] : 0,
                'qty' => isset($post['item_qty'][$k]) ? (float)$post['item_qty'][$k] : 1.0,
                'unit_price' => isset($post['item_price'][$k]) ? (float)$post['item_price'][$k] : 0.0,
            ];
        }
        $known = $inv->products(array_column($rows, 'product_id'));
        $rows = array_filter($rows, fn ($r) => isset($known[$r['product_id']]));
        return InventoryAccountingService::stockNet($rows);
    }

    /** Expense Dr net (stock products to Inventory when that is on); VAT Input Dr tax; Payables Cr gross (see TaxService::postingLines). */
    private function postBillLines(int $journalId, int $apAcc, int $expAcc, float $total, float $tax, int $invAcc = 0, float $stockNet = 0.0): void
    {
        $taxSvc = new TaxService($this->conn, $this->settings);
        $taxAcc = $tax > 0 ? $taxSvc->controlAccount('input') : 0;
        $lines = TaxService::postingLines('purchase', $total, $tax, $expAcc, $taxAcc, $apAcc);
        $lines = InventoryAccountingService::splitPurchaseLines($lines, $expAcc, $invAcc, $stockNet);
        if (!$taxSvc->writeLines($journalId, $lines)) {
            // Never leave a half-posted journal: fall back to a single unsplit pair.
            $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = " . (int)$journalId);
            $taxSvc->writeLines($journalId, TaxService::postingLines('purchase', $total, 0.0, $expAcc, 0, $apAcc));
        }
    }
}
