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
        $id = $post['id'] ?? null;
        $po_no = $post['po_no'] ?? null;
        $vendor_id = $post['vendor_id'] ?? null;
        $order_date = $post['order_date'] ?? null;
        $expected_date = $post['expected_date'] ?? null;
        $warehouse_id = $post['warehouse_id'] ?? null;
        $subtotal = $post['subtotal'] ?? null;
        $tax_amount = $post['tax_amount'] ?? null;
        $discount = $post['discount'] ?? null;
        $total = $post['total'] ?? null;
        $notes = $post['notes'] ?? null;
        $status = $post['status'] ?? null;
        $item_name = $post['item_name'] ?? null;
        $product_id = $post['product_id'] ?? null;
        $item_desc = $post['item_desc'] ?? null;
        $item_qty = $post['item_qty'] ?? null;
        $item_price = $post['item_price'] ?? null;
        $item_tax_rate = $post['item_tax_rate'] ?? null;
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
            return \App\Support\Db::fail($this->conn, 'An error occurred while saving the purchase order.');
        }

        // Replace line items
        $this->conn->query("DELETE FROM `purchase_order_items` WHERE purchase_order_id = '{$po_id}'");
        if (isset($item_name) && is_array($item_name)) {
            foreach ($item_name as $k => $v2) {
                $i_name = $this->conn->real_escape_string((string)$v2);
                if (empty($i_name)) continue;

                $p_id = $this->ownProductId($product_id[$k] ?? null, (int)$tenant_id);
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
        $this->settings->log_audit(empty($id) ? 'Created Purchase Order' : 'Updated Purchase Order', 'Purchasing', "PO {$po_no} (#{$po_id}) for " . ($vendor['name'] ?? "vendor #{$vendor_id}") . ", total {$total}, status {$status}.");
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

    /** The purchase order as a PDF for the signed-in company (name and bytes), or null if it is not theirs. */
    public function pdf(int $id): ?array
    {
        $t = (int)$this->settings->active_tenant_id();
        $stmt = $this->conn->prepare("SELECT po.*, p.name vendor_name, p.party_code vendor_code, p.email vendor_email, p.phone vendor_phone, p.address vendor_address
            FROM `purchase_order_list` po INNER JOIN `party_list` p ON p.id = po.vendor_id AND p.tenant_id = po.tenant_id
            WHERE po.id = ? AND po.tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();
        if (!$po) {
            return null;
        }
        $items = $this->conn->prepare("SELECT * FROM `purchase_order_items` WHERE purchase_order_id = ? ORDER BY id");
        $items->bind_param('i', $id);
        $items->execute();
        try {
            return ['name' => \App\Support\PurchaseOrderPdf::filename((string)$po['po_no']),
                'data' => \App\Support\PurchaseOrderPdf::render($po, $items->get_result()->fetch_all(MYSQLI_ASSOC), $this->settings->tenant_branding(), (string)$this->settings->base_currency($t))];
        } catch (\Throwable $e) {
            error_log('[purchase-order-pdf] ' . $e->getMessage());
            return null;
        }
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
            $this->settings->log_audit('Deleted Purchase Order', 'Purchasing', "Deleted PO {$po_no} (#{$id}).");
            $this->emit(\App\Support\IntegrationEvents::PURCHASE_ORDER_DELETED, [
                'purchase_order_id' => (int)$id,
                'po_no'             => $po_no,
            ], (int)$tenant_id);
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    /* ===================================================================
     *  GOODS RECEIPTS  (receive stock against a PO)
     * =================================================================== */

    public function receiveGoods(array $post): string
    {
        $purchase_order_id = $post['purchase_order_id'] ?? null;
        $receipt_date = $post['receipt_date'] ?? null;
        $notes = $post['notes'] ?? null;
        $po_item_id = $post['po_item_id'] ?? null;
        $receive_qty = $post['receive_qty'] ?? null;
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
                $batches = new BatchService($this->conn, $this->settings);
                if ($batches->isTracked((int)$prod_id)) {
                    $batches->receive((int)$prod_id, $wh, $recv_qty, (string)($post['batch_no'][$k] ?? ''), BatchService::cleanExpiry($post['expiry_date'][$k] ?? ''), $unit_cost, $grn_no, $grn_no);
                }
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
        $this->settings->log_audit('Received Goods', 'Purchasing', "Goods receipt {$grn_no} (#{$grn_id}) against PO " . ($po['po_no'] ?? "#{$po_id}") . ', received ' . $receipt_date . '.');
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
        $p = $this->conn->query("SELECT stock_quantity, cost_price, is_tracked FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
        if (!$p || $p->num_rows == 0) return;
        $prod = $p->fetch_assoc();
        if ((int)($prod['is_tracked'] ?? 1) === 0) return; // a service: nothing to put on a shelf

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
        $id = $post['id'] ?? null;
        $bill_no = $post['bill_no'] ?? null;
        $vendor_ref = $post['vendor_ref'] ?? null;
        $purchase_order_id = $post['purchase_order_id'] ?? null;
        $vendor_id = $post['vendor_id'] ?? null;
        $bill_date = $post['bill_date'] ?? null;
        $due_date = $post['due_date'] ?? null;
        $subtotal = $post['subtotal'] ?? null;
        $tax_amount = $post['tax_amount'] ?? null;
        $discount = $post['discount'] ?? null;
        $total = $post['total'] ?? null;
        $notes = $post['notes'] ?? null;
        $status = $post['status'] ?? null;
        $item_name = $post['item_name'] ?? null;
        $product_id = $post['product_id'] ?? null;
        $item_desc = $post['item_desc'] ?? null;
        $item_qty = $post['item_qty'] ?? null;
        $item_price = $post['item_price'] ?? null;
        $item_tax_rate = $post['item_tax_rate'] ?? null;
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
        if (is_int($po_id) && !$this->conn->query("SELECT 1 FROM `purchase_order_list` WHERE id = {$po_id} AND tenant_id = " . (int)$tenant_id)->num_rows) {
            $po_id = 'NULL'; // another company's order (or none): never link a bill to it
        }
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
        // Start from known values; they are filled from the saved bill below.
        $paid_amount = 0.0;
        $credited_amount = 0.0;
        $oldStatus = '';
        if (!empty($id)) {
            $cur = $this->conn->query("SELECT paid_amount, credited_amount, status FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
            if ($cur && $cur->num_rows) {
                $row = $cur->fetch_assoc();
                $paid_amount = (float)$row['paid_amount'];
                $credited_amount = (float)$row['credited_amount'];
                $oldStatus = (string)$row['status'];
            }
        }
        if (!empty($id) && $oldStatus === 'cancelled') {
            return json_encode(['status' => 'failed', 'msg' => 'This bill is cancelled and cannot be changed. Enter a new bill if it needs redoing.']);
        }
        $isCancelling = !empty($id) && $status === 'cancelled';
        if ($isCancelling && ($problem = self::cancelProblem($paid_amount, $credited_amount))) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        if (!empty($id) && $status !== 'cancelled' && $status !== 'draft' && $total + 0.005 < $paid_amount + $credited_amount) {
            return json_encode(['status' => 'failed', 'msg' => 'This bill has already had ' . number_format($paid_amount + $credited_amount, 2) . ' paid or credited, so its total cannot be lowered below that. Void the payment first.']);
        }
        // Debit notes count towards settling the bill just as payments do.
        $balance = $status === 'cancelled' ? 0.0 : CreditNoteService::outstanding($total, $paid_amount, $credited_amount);
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
        // Chosen by account TYPE first (a bill is never debited to an asset such as Inventory because of its name),
        // and only from this company's own chart.
        $ap_acc_id = $this->accountOfClass('liability', ['payable', 'creditor'], (int)$tenant_id);
        $exp_acc_id = $this->accountOfClass('expense', ['purchase', 'cost of goods', 'general'], (int)$tenant_id);
        if ($ap_acc_id === 0 || $exp_acc_id === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Your chart of accounts needs an Accounts Payable (liability) account and a Purchases or expense account before bills can be posted.']);
        }
        // With inventory accounting on, the part of the bill that buys stock products is an asset (Inventory), not
        // an expense: the cost reaches the profit and loss only when the goods are sold.
        $invSvc = new InventoryAccountingService($this->conn, $this->settings);
        $invAcc = 0;
        $stockNet = 0.0;
        if ($invSvc->isEnabled()) {
            $invAcc = $invSvc->config()['inventory_account_id'];
            $stockNet = $this->stockNet($post, $invSvc);
        }

        // Stock this bill brings in (inventory accounting on, bill posted, no purchase order): worked out now so a change that
        // would take back stock already sold is refused before anything is booked.
        $stockLines = [];
        foreach ((array)($item_name ?? []) as $k => $nm) {
            if (trim((string)$nm) === '') {
                continue;
            }
            $stockLines[] = ['product_id' => isset($product_id[$k]) && is_numeric($product_id[$k]) ? (int)$product_id[$k] : 0,
                'qty' => isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0, 'unit_price' => isset($item_price[$k]) ? (float)$item_price[$k] : 0.0];
        }
        $stockWanted = $this->stockWanted($stockLines, $status, is_int($po_id), (int)$tenant_id);
        if (!empty($id) && ($problem = $this->stockTakeBackProblem($this->stockReceived((int)$id, (int)$tenant_id), $stockWanted, (int)$tenant_id))) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
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
            if ($isCancelling && $journal_id) {
                // Cancelling keeps the bill and its journal as the record and undoes the ledger effect with a reversal.
                $js = $this->conn->query("SELECT status FROM `journal_entries` WHERE id = '{$journal_id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['status'] ?? '';
                if ($js === 'posted') {
                    $rev = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$journal_id, date('Y-m-d'), true), true);
                    if (($rev['status'] ?? '') !== 'success') {
                        return json_encode(['status' => 'failed', 'msg' => 'The bill could not be cancelled: ' . ($rev['msg'] ?? 'its journal could not be reversed.')]);
                    }
                }
            } elseif ($journal_id) {
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
        if ($save && !empty($journal_id) && $status !== 'cancelled' && ($pendingApproval || $status !== 'draft')) {
            $jstatus = $pendingApproval ? ApprovalService::PENDING : 'posted';
            $this->conn->query("UPDATE `journal_entries` SET `status` = '{$jstatus}' WHERE id = '{$journal_id}' AND tenant_id = '{$tenant_id}'");
        }

        if (!$save) {
            return \App\Support\Db::fail($this->conn, 'An error occurred while saving the vendor bill.');
        }

        // Line items
        $this->conn->query("DELETE FROM `vendor_bill_items` WHERE vendor_bill_id = '{$bill_id}'");
        if (isset($item_name) && is_array($item_name)) {
            foreach ($item_name as $k => $v2) {
                $i_name = $this->conn->real_escape_string((string)$v2);
                if (empty($i_name)) continue;
                $p_id = $this->ownProductId($product_id[$k] ?? null, (int)$tenant_id);
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

        $this->applyBillStock((int)$bill_id, $stockWanted, 'Bill ' . $bill_no, (int)$tenant_id, (int)$user_id);

        // If bill is linked to a PO, flag the PO as billed.
        if (is_int($po_id)) {
            $this->conn->query("UPDATE `purchase_order_list` SET `status` = 'billed' WHERE id = '{$po_id}' AND tenant_id = '{$tenant_id}'");
        }

        $this->settings->set_flashdata('success', empty($id) ? ' Vendor bill created successfully.' : ' Vendor bill updated successfully.');
        $this->settings->log_audit(empty($id) ? 'Created Vendor Bill' : ($isCancelling ? 'Cancelled Vendor Bill' : 'Updated Vendor Bill'), 'Purchasing', "Bill {$bill_no} for {$vendorName}, total {$total}" . ($isCancelling ? ', ledger entry reversed.' : '.'));
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

    /** Pure: why a bill cannot be cancelled, or null. A bill with payments or debit notes must have those undone first. */
    public static function cancelProblem(float $paid, float $credited): ?string
    {
        if (($paid + $credited) > 0.004) {
            return 'This bill has payments or debit notes against it, so it cannot be cancelled. Void those first.';
        }
        return null;
    }

    /** Pure: why a bill cannot be deleted, or null. Only a draft or an already-cancelled bill can go. */
    public static function deleteProblem(string $status, float $paid, float $credited): ?string
    {
        if (($paid + $credited) > 0.004) {
            return 'This bill has payments or debit notes against it, so it cannot be deleted. Void those first.';
        }
        if (!in_array($status, ['draft', 'cancelled'], true)) {
            return 'A posted bill cannot be deleted. Cancel it first, which reverses it in the ledger, then delete the cancelled bill if you need to.';
        }
        return null;
    }

    /** Cancel a posted bill: reverse its journal and mark it cancelled with nothing owing. Nothing is erased. */
    public function cancelVendorBill(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $q = $this->conn->query("SELECT bill_no, journal_id, status, total, paid_amount, credited_amount FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        $b = $q ? $q->fetch_assoc() : null;
        if (!$b) {
            return json_encode(['status' => 'failed', 'msg' => 'Vendor bill not found.']);
        }
        if ($b['status'] === 'cancelled') {
            return json_encode(['status' => 'success', 'msg' => ' Bill is already cancelled.']);
        }
        if ($problem = self::cancelProblem((float)$b['paid_amount'], (float)$b['credited_amount'])) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, $tenant_id, null, 'vendor_bill_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        if ($problem = $this->stockTakeBackProblem($this->stockReceived((int)$id, (int)$tenant_id), [], (int)$tenant_id)) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        $reversed = false;
        if (!empty($b['journal_id'])) {
            $js = $this->conn->query("SELECT status FROM `journal_entries` WHERE id = '{$b['journal_id']}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['status'] ?? '';
            if ($js === 'posted') {
                $rev = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$b['journal_id'], date('Y-m-d'), true), true);
                if (($rev['status'] ?? '') !== 'success') {
                    return json_encode(['status' => 'failed', 'msg' => 'The bill could not be cancelled: ' . ($rev['msg'] ?? 'its journal could not be reversed.')]);
                }
                $reversed = true;
            }
        }
        $old = $this->conn->real_escape_string((string)$b['status']);
        $ok = $this->conn->query("UPDATE `vendor_bill_list` SET `status` = 'cancelled', `balance` = 0 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}' AND `status` = '{$old}'");
        if (!$ok || $this->conn->affected_rows !== 1) {
            return json_encode(['status' => 'failed', 'msg' => 'The bill changed while it was being cancelled. Reload and check it.']);
        }
        $this->applyBillStock((int)$id, [], 'Bill ' . $b['bill_no'], (int)$tenant_id, (int)$this->settings->userdata('id'));
        $this->settings->log_audit('Cancelled Vendor Bill', 'Purchasing', "Bill {$b['bill_no']} (#{$id}), total {$b['total']}" . ($reversed ? ', ledger entry reversed.' : '.'));
        $this->settings->set_flashdata('success', ' Vendor bill cancelled and its ledger entry reversed.');
        return json_encode(['status' => 'success', 'msg' => ' Vendor bill cancelled.']);
    }

    public function deleteVendorBill(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, null, 'vendor_bill_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        $bill = $this->conn->query("SELECT bill_no, journal_id, status, paid_amount, credited_amount FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$bill || $bill->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Vendor bill not found.']);
        }
        $b = $bill->fetch_assoc();
        $pay = $this->conn->query("SELECT 1 FROM bill_payment_allocations a INNER JOIN bill_payments p ON p.id = a.payment_id AND p.status = 'posted' WHERE a.bill_id = '{$id}' LIMIT 1");
        if ($pay && $pay->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'This bill has payments against it. Void the payments before deleting the bill.']);
        }
        if ($problem = self::deleteProblem((string)$b['status'], (float)$b['paid_amount'], (float)$b['credited_amount'])) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        // Only a draft can get here still holding a journal row, and a draft never reached the ledger. A cancelled
        // bill keeps its journal and the reversal made when it was cancelled, as the record.
        if ($b['status'] === 'draft' && !empty($b['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$b['journal_id']}' AND tenant_id = '{$tenant_id}' AND status <> 'posted'");
        }
        $del = $this->conn->query("DELETE FROM `vendor_bill_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->set_flashdata('success', ' Vendor bill deleted successfully.');
            $this->settings->log_audit('Deleted Vendor Bill', 'Purchasing', "Deleted bill " . ($b['bill_no'] ?? "#{$id}") . " (#{$id})" . ($b['status'] === 'draft' ? ', a draft' : ', cancelled, its ledger entries kept') . '.');
            $this->emit(\App\Support\IntegrationEvents::VENDOR_BILL_DELETED, [
                'vendor_bill_id' => (int)$id,
                'bill_no'        => $b['bill_no'] ?? '',
            ], (int)$tenant_id);
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    /* ===================================================================
     *  Helpers
     * =================================================================== */

    /**
     * Find a GL account by name keyword(s), scoped to tenant. Falls back to $default.
     *
     * @param array<int,string> $keywords
     */
    /**
     * The company's account of a class (liability / expense), taken from its own chart by what the account IS (its type),
     * never by its name alone: a bill must not land in Inventory because the name matched. Among several, the first name hint
     * that matches wins, then the lowest code. 0 when the chart has none.
     */
    private function accountOfClass(string $class, array $nameHints, int $tenant_id): int
    {
        $q = $this->conn->query("SELECT id, code, name, type FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY code, id");
        $best = 0;
        $bestRank = -1;
        foreach ($q ? $q->fetch_all(MYSQLI_ASSOC) : [] as $a) {
            if (LedgerService::classify((string)$a['type'])['class'] !== $class) {
                continue;
            }
            $rank = 0;
            foreach ($nameHints as $i => $h) {
                if (stripos((string)$a['name'], $h) !== false) {
                    $rank = count($nameHints) - $i;
                    break;
                }
            }
            if ($rank > $bestRank) {
                $best = (int)$a['id'];
                $bestRank = $rank;
            }
        }
        return $best;
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
    /**
     * The catalogue product a line points at, or 'NULL' for a one-off line. The item combo posts a plain id for a
     * catalogue item and "custom:Name" for a typed one; an id that is not this company's product is dropped too,
     * so a line can never reference another tenant's product.
     */
    private function ownProductId($raw, int $tenantId)
    {
        if (!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw <= 0) {
            return 'NULL';
        }
        $id = (int)$raw;
        $r = $this->conn->query("SELECT 1 FROM `product_list` WHERE id = {$id} AND tenant_id = " . (int)$tenantId . " AND delete_flag = 0 LIMIT 1");
        return ($r && $r->num_rows) ? $id : 'NULL';
    }

    /**
     * The stock a bill brings in, per product: [product_id => ['qty','cost']]. Only when inventory accounting is on, the bill is
     * posted (not draft, waiting for approval or cancelled) and is not tied to a purchase order (a goods receipt brings those in).
     * $lines: [product_id, qty, unit_price] rows; services and unknown products are dropped.
     */
    private function stockWanted(array $lines, string $status, bool $hasPo, int $tenantId): array
    {
        $inv = new InventoryAccountingService($this->conn, $this->settings);
        if ($hasPo || !$inv->isEnabled() || !in_array($status, ['unpaid', 'partially_paid', 'paid'], true)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(fn ($l) => (int)$l['product_id'], $lines))));
        $known = $inv->products($ids);
        $services = $inv->untrackedIds($ids);
        $out = [];
        foreach ($lines as $l) {
            $pid = (int)$l['product_id'];
            if ($pid <= 0 || !isset($known[$pid]) || in_array($pid, $services, true) || (float)$l['qty'] <= 0) {
                continue;
            }
            $out[$pid]['qty'] = ($out[$pid]['qty'] ?? 0.0) + (float)$l['qty'];
            $out[$pid]['value'] = ($out[$pid]['value'] ?? 0.0) + (float)$l['qty'] * (float)$l['unit_price'];
        }
        foreach ($out as $pid => $o) {
            $out[$pid] = ['qty' => round($o['qty'], 4), 'cost' => $o['qty'] > 0 ? round($o['value'] / $o['qty'], 4) : 0.0];
        }
        return $out;
    }

    /** What this bill has already put on the shelf: [product_id => ['qty','cost']]. */
    private function stockReceived(int $billId, int $tenantId): array
    {
        $out = [];
        $q = $this->conn->query("SELECT product_id, qty, unit_cost FROM `vendor_bill_stock` WHERE bill_id = " . (int)$billId . " AND tenant_id = " . (int)$tenantId);
        foreach ($q ? $q->fetch_all(MYSQLI_ASSOC) : [] as $r) {
            $out[(int)$r['product_id']] = ['qty' => (float)$r['qty'], 'cost' => (float)$r['unit_cost']];
        }
        return $out;
    }

    /** Why the stock this bill brought in cannot be taken back (some of it already sold or moved), or null. */
    private function stockTakeBackProblem(array $received, array $wanted, int $tenantId): ?string
    {
        foreach ($received as $pid => $r) {
            $drop = round($r['qty'] - ($wanted[$pid]['qty'] ?? 0.0), 4);
            if ($drop <= 0) {
                continue;
            }
            $pidI = (int)$pid; $tidI = (int)$tenantId;
            $p = $this->conn->query("SELECT name, stock_quantity FROM `product_list` WHERE id = {$pidI} AND tenant_id = {$tidI}")->fetch_assoc();
            if ($p && (float)$p['stock_quantity'] + 0.00001 < $drop) {
                return "This bill brought in {$r['qty']} of \"{$p['name']}\" but only " . (float)$p['stock_quantity'] . ' are on hand now, so it cannot be reduced or cancelled. Some of that stock has been sold or moved.';
            }
        }
        return null;
    }

    /** Bring the shelf in line with $wanted: receive what is new, take back what was reduced. Records what the bill holds. */
    private function applyBillStock(int $billId, array $wanted, string $reference, int $tenantId, int $userId): void
    {
        $received = $this->stockReceived($billId, $tenantId);
        foreach ($wanted + $received as $pid => $_) {
            $have = $received[$pid] ?? ['qty' => 0.0, 'cost' => 0.0];
            $want = $wanted[$pid] ?? ['qty' => 0.0, 'cost' => 0.0];
            $dq = round($want['qty'] - $have['qty'], 4);
            if ($dq > 0) {
                $this->receiveStock((int)$pid, $dq, $want['cost'], $tenantId, $userId, $reference, 0);
            } elseif ($dq < 0) {
                $this->unreceiveStock((int)$pid, -$dq, $have['cost'], $tenantId, $userId, $reference);
            }
        }
        $this->conn->query("DELETE FROM `vendor_bill_stock` WHERE bill_id = " . (int)$billId . " AND tenant_id = " . (int)$tenantId);
        foreach ($wanted as $pid => $w) {
            $this->conn->query("INSERT INTO `vendor_bill_stock` (tenant_id, bill_id, product_id, qty, unit_cost) VALUES ("
                . (int)$tenantId . ', ' . (int)$billId . ', ' . (int)$pid . ", '{$w['qty']}', '{$w['cost']}')");
        }
    }

    /** The exact inverse of receiveStock(): quantity down, weighted-average cost put back as it was before that receipt. */
    private function unreceiveStock(int $productId, float $qty, float $unitCost, int $tenantId, int $userId, string $reference): void
    {
        $pidI = (int)$productId; $tidI = (int)$tenantId;
        $p = $this->conn->query("SELECT stock_quantity, cost_price FROM `product_list` WHERE id = {$pidI} AND tenant_id = {$tidI}")->fetch_assoc();
        if (!$p) {
            return;
        }
        $oldQty = (float)$p['stock_quantity'];
        $oldCost = (float)$p['cost_price'];
        $newQty = max(0.0, $oldQty - $qty);
        $newCost = $newQty > 0 ? max(0.0, round(($oldQty * $oldCost - $qty * $unitCost) / $newQty, 4)) : $oldCost;
        $this->conn->query("UPDATE `product_list` SET `stock_quantity` = '{$newQty}', `cost_price` = '{$newCost}' WHERE id = {$pidI} AND tenant_id = {$tidI}");
        try {
            \App\Core\Container::getInstance()->make(\App\Services\WarehouseStockService::class)->adjustLocation($productId, 0, -$qty, false);
        } catch (\Throwable $e) {
            // location tracking must not break the correction
        }
        $ref = $this->conn->real_escape_string($reference);
        $this->conn->query("INSERT INTO `inventory_logs` SET `warehouse_id` = '0', `tenant_id` = '{$tenantId}', `product_id` = '{$productId}', `type` = 'out', `qty` = '{$qty}',
            `unit_cost` = '{$unitCost}', `reference` = '{$ref}', `notes` = 'Vendor bill reduced or cancelled', `created_by` = '{$userId}'");
    }

    /**
     * Put a bill's stock on the shelf once it has been approved (a bill waiting for approval has not reached the books or the shelf).
     * Called by the approval step. Returns null, or why it could not.
     */
    public function receiveStockForApprovedBill(int $billId): ?string
    {
        $t = (int)$this->settings->active_tenant_id();
        $b = $this->conn->query("SELECT bill_no, status, purchase_order_id FROM `vendor_bill_list` WHERE id = " . (int)$billId . " AND tenant_id = {$t}")->fetch_assoc();
        if (!$b) {
            return 'Bill not found.';
        }
        $lines = [];
        $q = $this->conn->query("SELECT product_id, qty, unit_price FROM `vendor_bill_items` WHERE vendor_bill_id = " . (int)$billId);
        foreach ($q ? $q->fetch_all(MYSQLI_ASSOC) : [] as $r) {
            $lines[] = ['product_id' => (int)$r['product_id'], 'qty' => (float)$r['qty'], 'unit_price' => (float)$r['unit_price']];
        }
        $wanted = $this->stockWanted($lines, (string)$b['status'], !empty($b['purchase_order_id']), $t);
        $this->applyBillStock($billId, $wanted, 'Bill ' . $b['bill_no'], $t, (int)$this->settings->userdata('id'));
        return null;
    }

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
        $services = $inv->untrackedIds(array_column($rows, 'product_id'));
        $rows = array_filter($rows, fn ($r) => isset($known[$r['product_id']]) && !in_array($r['product_id'], $services, true));
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
