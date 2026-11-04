<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Delivery notes and partial fulfilment.
 *
 * A sales order confirmed "for delivery" is fulfilled in instalments. Each delivery note issues stock for the
 * quantities actually sent (with inventory accounting on: Dr Cost of Sales, Cr Inventory at average cost), and the
 * delivered quantities are then invoiced, one or many deliveries at a time, without issuing stock a second time.
 * A delivery that has not been invoiced can be cancelled, which puts the stock back.
 */
class DeliveryService
{
    private const EPS = 0.00005;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    /** What is still to be delivered on a line. */
    public static function remaining(float $ordered, float $delivered): float
    {
        return round(max(0.0, $ordered - $delivered), 4);
    }

    /**
     * Why these delivery quantities can't be taken, or []. $want is order line id => quantity; $lines is order line
     * id => [ordered, delivered, name]. At least one quantity must be above zero, none may exceed what is left.
     *
     * @return string[]
     */
    public static function deliveryProblems(array $want, array $lines): array
    {
        $errors = [];
        $any = false;
        foreach ($want as $lineId => $qty) {
            $qty = (float)$qty;
            if ($qty < 0) {
                $errors[] = 'Quantities cannot be negative.';
                continue;
            }
            if ($qty == 0.0) {
                continue;
            }
            if (!isset($lines[$lineId])) {
                $errors[] = 'A line you are delivering is not on this order.';
                continue;
            }
            $any = true;
            $left = self::remaining((float)$lines[$lineId]['ordered'], (float)$lines[$lineId]['delivered']);
            if ($qty > $left + self::EPS) {
                $errors[] = "{$lines[$lineId]['name']}: only " . self::fmt($left) . ' left to deliver.';
            }
        }
        if (!$any && !$errors) {
            $errors[] = 'Enter the quantity delivered on at least one line.';
        }
        return $errors;
    }

    /** 'not_delivered', 'partially_delivered' or 'delivered' across all the order's lines. */
    public static function fulfilment(array $lines): string
    {
        $ordered = $delivered = 0.0;
        $allDone = true;
        foreach ($lines as $l) {
            $o = (float)$l['ordered'];
            $d = min((float)$l['delivered'], $o);
            $ordered += $o;
            $delivered += $d;
            if ($d + self::EPS < $o) {
                $allDone = false;
            }
        }
        if ($delivered <= self::EPS) {
            return 'not_delivered';
        }
        return $allDone ? 'delivered' : 'partially_delivered';
    }

    /** Delivered but not yet invoiced, per line. */
    public static function invoiceable(float $delivered, float $invoiced): float
    {
        return round(max(0.0, $delivered - $invoiced), 4);
    }

    /**
     * The overall discount that goes on one invoice of a delivery order: the order's discount shared by the value
     * invoiced, and the exact remainder on the invoice that completes the order, so the discounts add up exactly.
     */
    public static function invoiceDiscount(float $orderDiscount, float $orderSubtotal, float $thisSubtotal, float $alreadyDiscounted, bool $completes): float
    {
        if ($orderDiscount <= 0 || $orderSubtotal <= 0) {
            return 0.0;
        }
        if ($completes) {
            return round(max(0.0, $orderDiscount - $alreadyDiscounted), 2);
        }
        return round(min($orderDiscount - $alreadyDiscounted, $orderDiscount * $thisSubtotal / $orderSubtotal), 2);
    }

    /** Why a delivery can't be cancelled, or null. */
    public static function cancelProblem(string $status, int $invoicedLines): ?string
    {
        if ($status === 'cancelled') {
            return 'This delivery note is already cancelled.';
        }
        if ($invoicedLines > 0) {
            return 'This delivery has been invoiced. Cancel or delete the invoice first.';
        }
        return null;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
    }

    /* --------------------------------- data ---------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }

    public function order(int $id): ?array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT * FROM sales_order_list WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** The order's lines with ordered, delivered and invoiced quantities. */
    public function lines(int $orderId): array
    {
        $stmt = $this->conn->prepare("SELECT i.*, i.qty ordered, i.delivered_qty delivered, i.invoiced_qty invoiced, i.item_name name
            FROM sales_order_items i INNER JOIN sales_order_list o ON o.id = i.sales_order_id AND o.tenant_id = ?
            WHERE i.sales_order_id = ? ORDER BY i.id");
        $t = $this->tenantId();
        $stmt->bind_param('ii', $t, $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function notesFor(int $orderId): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT d.*, (SELECT COUNT(*) FROM delivery_note_items x WHERE x.delivery_note_id = d.id AND x.invoice_id IS NOT NULL) invoiced_lines
            FROM delivery_notes d WHERE d.tenant_id = ? AND d.sales_order_id = ? ORDER BY d.id");
        $stmt->bind_param('ii', $t, $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Every delivery note, newest first, with the order number and customer. */
    public function all(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT d.*, o.order_no, p.name party_name,
                (SELECT COALESCE(SUM(x.qty), 0) FROM delivery_note_items x WHERE x.delivery_note_id = d.id) units,
                (SELECT COUNT(*) FROM delivery_note_items x WHERE x.delivery_note_id = d.id AND x.invoice_id IS NOT NULL) invoiced_lines
            FROM delivery_notes d INNER JOIN sales_order_list o ON o.id = d.sales_order_id INNER JOIN party_list p ON p.id = d.party_id
            WHERE d.tenant_id = ? ORDER BY d.id DESC");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function note(int $id): ?array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT d.*, o.order_no, p.name party_name, p.address party_address FROM delivery_notes d
            INNER JOIN sales_order_list o ON o.id = d.sales_order_id INNER JOIN party_list p ON p.id = d.party_id
            WHERE d.id = ? AND d.tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function noteItems(int $id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM delivery_note_items WHERE delivery_note_id = ? ORDER BY id");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function hasDeliveries(int $orderId): bool
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT 1 FROM delivery_notes WHERE tenant_id = ? AND sales_order_id = ? AND status = 'delivered' LIMIT 1");
        $stmt->bind_param('ii', $t, $orderId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /** True when the invoice was raised from delivery notes (it is then changed only by cancelling it). */
    public function linkedToInvoice(int $invoiceId): bool
    {
        $stmt = @$this->conn->prepare("SELECT 1 FROM delivery_note_items WHERE invoice_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /* -------------------------------- actions -------------------------------- */

    /** Confirm an open order or quote to be fulfilled by delivery notes: no invoice and no stock movement yet. */
    public function confirmForDelivery(int $orderId): string
    {
        $t = $this->tenantId();
        if (!($o = $this->order($orderId))) {
            return $this->fail('Order not found.');
        }
        if (!empty($o['invoice_id']) || (int)$o['stock_adjusted'] !== 0 || !in_array((string)$o['status'], ['proforma', 'draft', 'confirmed'], true)) {
            return $this->fail('This order has already been invoiced or closed, so it cannot be set up for delivery.');
        }
        $stmt = $this->conn->prepare("UPDATE sales_order_list SET status = 'confirmed', delivery_mode = 1 WHERE id = ? AND tenant_id = ? AND invoice_id IS NULL AND stock_adjusted = 0");
        $stmt->bind_param('ii', $orderId, $t);
        $stmt->execute();
        $this->settings->log_audit('Order set up for delivery', 'Delivery notes', (string)$o['order_no']);
        $msg = 'Order confirmed for delivery. Record each delivery as goods are sent, then invoice what has been delivered.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /**
     * Record a delivery. $post: order_id, delivery_date, warehouse_id (optional), notes, qty[order line id].
     */
    public function createDelivery(array $post): string
    {
        $t = $this->tenantId();
        $orderId = (int)($post['order_id'] ?? 0);
        $date = trim((string)($post['delivery_date'] ?? ''));
        if (!($o = $this->order($orderId))) {
            return $this->fail('Order not found.');
        }
        if ((int)$o['delivery_mode'] !== 1 || $o['status'] !== 'confirmed') {
            return $this->fail('This order is not set up for delivery.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter the delivery date.');
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }
        $wh = isset($post['warehouse_id']) && $post['warehouse_id'] !== '' ? max(0, (int)$post['warehouse_id']) : max(0, (int)($o['warehouse_id'] ?? 0));

        $lines = [];
        foreach ($this->lines($orderId) as $l) {
            $lines[(int)$l['id']] = $l;
        }
        $want = [];
        foreach ((array)($post['qty'] ?? []) as $lineId => $q) {
            $v = BudgetService::cleanAmount($q === '' ? '0' : $q);
            if ($v === null) {
                return $this->fail('Enter the delivered quantities as numbers.');
            }
            $want[(int)$lineId] = round($v, 4);
        }
        if ($errs = self::deliveryProblems($want, $lines)) {
            return $this->fail(implode(' ', $errs));
        }
        $want = array_filter($want, fn ($q) => $q > 0);

        $stock = new InventoryAccountingService($this->conn, $this->settings);
        $need = [];
        foreach ($want as $lineId => $q) {
            $pid = (int)($lines[$lineId]['product_id'] ?? 0);
            if ($pid > 0) {
                $need[$pid] = round(($need[$pid] ?? 0.0) + $q, 4);
            }
        }
        $dnNo = SequenceService::documentNo($this->conn, $t, 'delivery_notes', 'dn_no', 'DN-' . date('Ym') . '-', 'delivery-' . date('Ym'));

        // Stock first (it posts its own journal); everything after is undone if it fails.
        $costs = [];
        $journalId = 0;
        if ($need) {
            if ($stock->isEnabled()) {
                $res = $stock->issue($need, $date, $dnNo, $wh);
                if (!$res['ok']) {
                    return $this->fail($res['msg']);
                }
                $costs = $res['costs'];
                $journalId = (int)$res['journal_id'];
            } else {
                $res = $stock->issueUnits($need, $dnNo, $wh, 'Delivery ' . $dnNo);
                if (!$res['ok']) {
                    return $this->fail($res['msg']);
                }
                $costs = $res['costs'];
            }
        }
        $undo = function () use ($stock, $need, $costs, $journalId, $date, $dnNo, $wh): void {
            if ($need) {
                $items = [];
                foreach ($need as $pid => $q) {
                    $items[$pid] = ['qty' => $q, 'cost' => (float)($costs[$pid] ?? 0)];
                }
                $stock->restore($items, $journalId, $date, $dnNo, $wh);
            }
        };

        $userId = (int)$this->settings->userdata('id');
        $notes = mb_substr(trim((string)($post['notes'] ?? '')), 0, 500);
        $partyId = (int)$o['party_id'];
        $this->conn->begin_transaction();
        try {
            $ins = $this->conn->prepare("INSERT INTO delivery_notes (tenant_id, dn_no, sales_order_id, party_id, delivery_date, warehouse_id, stock_journal_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $jid = $journalId > 0 ? $journalId : null;
            $ins->bind_param('isiisiisi', $t, $dnNo, $orderId, $partyId, $date, $wh, $jid, $notes, $userId);
            if (!$ins->execute()) {
                throw new \RuntimeException('The delivery note could not be saved.');
            }
            $dnId = (int)$this->conn->insert_id;
            $li = $this->conn->prepare("INSERT INTO delivery_note_items (delivery_note_id, order_item_id, product_id, item_name, qty, unit_cost) VALUES (?, ?, ?, ?, ?, ?)");
            // Claim the quantity under a guard so two deliveries cannot both take the same remainder.
            $up = $this->conn->prepare("UPDATE sales_order_items SET delivered_qty = delivered_qty + ? WHERE id = ? AND sales_order_id = ? AND delivered_qty + ? <= qty + 0.00005");
            foreach ($want as $lineId => $q) {
                $l = $lines[$lineId];
                $pid = (int)($l['product_id'] ?? 0);
                $pidv = $pid > 0 ? $pid : null;
                $cost = $pid > 0 && isset($costs[$pid]) ? (string)$costs[$pid] : null;
                $name = (string)$l['item_name'];
                $qv = (string)$q;
                $li->bind_param('iiisss', $dnId, $lineId, $pidv, $name, $qv, $cost);
                if (!$li->execute()) {
                    throw new \RuntimeException('A delivery line could not be saved.');
                }
                $qq = $q;
                $up->bind_param('diid', $qq, $lineId, $orderId, $qq);
                $up->execute();
                if ($up->affected_rows !== 1) {
                    throw new \RuntimeException("{$name}: that quantity was just delivered by someone else. Reload and try again.");
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $undo();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The delivery could not be recorded. Nothing was changed.');
        }
        $this->settings->log_audit('Recorded delivery', 'Delivery notes', "{$dnNo} for order {$o['order_no']}");
        $msg = "Delivery note {$dnNo} recorded.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $dnId]);
    }

    /** Cancel a delivery that has not been invoiced: the stock goes back and the quantities are open to deliver again. */
    public function cancelDelivery(int $id): string
    {
        $t = $this->tenantId();
        if (!($d = $this->note($id))) {
            return $this->fail('Delivery note not found.');
        }
        $items = $this->noteItems($id);
        $invoiced = count(array_filter($items, fn ($i) => $i['invoice_id'] !== null));
        if ($msg = self::cancelProblem((string)$d['status'], $invoiced)) {
            return $this->fail($msg);
        }
        $date = date('Y-m-d');
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }

        // Mark it cancelled and free the quantities first, guarded, so it can only be cancelled once.
        $this->conn->begin_transaction();
        try {
            $c = $this->conn->prepare("UPDATE delivery_notes SET status = 'cancelled' WHERE id = ? AND tenant_id = ? AND status = 'delivered'");
            $c->bind_param('ii', $id, $t);
            $c->execute();
            if ($c->affected_rows !== 1) {
                throw new \RuntimeException('This delivery note was just cancelled.');
            }
            $dn = $this->conn->prepare("UPDATE sales_order_items SET delivered_qty = GREATEST(0, delivered_qty - ?) WHERE id = ?");
            foreach ($items as $i) {
                $q = (float)$i['qty'];
                $oi = (int)$i['order_item_id'];
                $dn->bind_param('di', $q, $oi);
                $dn->execute();
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The delivery could not be cancelled.');
        }

        $back = [];
        foreach ($items as $i) {
            $pid = (int)($i['product_id'] ?? 0);
            if ($pid > 0) {
                $back[$pid] = ['qty' => round(($back[$pid]['qty'] ?? 0.0) + (float)$i['qty'], 4), 'cost' => (float)($i['unit_cost'] ?? 0)];
            }
        }
        if ($back) {
            $r = (new InventoryAccountingService($this->conn, $this->settings))->restore($back, (int)($d['stock_journal_id'] ?? 0), $date, (string)$d['dn_no'], (int)$d['warehouse_id']);
            if (!$r['ok']) {
                // Put the note back as it was: nothing was returned to stock.
                $this->conn->begin_transaction();
                $this->conn->query("UPDATE delivery_notes SET status = 'delivered' WHERE id = " . (int)$id . " AND tenant_id = {$t}");
                foreach ($items as $i) {
                    $this->conn->query("UPDATE sales_order_items SET delivered_qty = delivered_qty + " . (float)$i['qty'] . " WHERE id = " . (int)$i['order_item_id']);
                }
                $this->conn->commit();
                return $this->fail('The stock on this delivery could not be returned, so it was not cancelled: ' . $r['msg']);
            }
        }
        $this->settings->log_audit('Cancelled delivery', 'Delivery notes', (string)$d['dn_no']);
        $this->settings->set_flashdata('success', "Delivery note {$d['dn_no']} cancelled and its stock returned.");
        return json_encode(['status' => 'success', 'msg' => "Delivery note {$d['dn_no']} cancelled."]);
    }

    /** Invoice everything delivered on the order and not yet invoiced. Stock is not issued again. */
    public function invoiceDelivered(int $orderId): string
    {
        $t = $this->tenantId();
        if (!($o = $this->order($orderId))) {
            return $this->fail('Order not found.');
        }
        if ((int)$o['delivery_mode'] !== 1) {
            return $this->fail('This order is not set up for delivery.');
        }
        $lines = $this->lines($orderId);
        $todo = [];
        $thisSub = 0.0;
        foreach ($lines as $l) {
            $q = self::invoiceable((float)$l['delivered'], (float)$l['invoiced']);
            if ($q > self::EPS) {
                $todo[(int)$l['id']] = $q;
                $thisSub += $q * (float)$l['unit_price'];
            }
        }
        if (!$todo) {
            return $this->fail('Nothing has been delivered that is not already invoiced.');
        }
        $completes = true;
        foreach ($lines as $l) {
            $after = (float)$l['invoiced'] + ($todo[(int)$l['id']] ?? 0.0);
            if ($after + self::EPS < (float)$l['ordered']) {
                $completes = false;
            }
        }
        $already = 0.0;
        $q = $this->conn->prepare("SELECT COALESCE(SUM(i.discount), 0) d FROM invoice_list i WHERE i.tenant_id = ? AND i.status <> 'cancelled' AND i.id IN
            (SELECT DISTINCT x.invoice_id FROM delivery_note_items x INNER JOIN delivery_notes n ON n.id = x.delivery_note_id WHERE n.sales_order_id = ? AND x.invoice_id IS NOT NULL)");
        $q->bind_param('ii', $t, $orderId);
        $q->execute();
        $already = (float)($q->get_result()->fetch_assoc()['d'] ?? 0);

        $discount = self::invoiceDiscount((float)$o['discount'], (float)$o['subtotal'], $thisSub, $already, $completes);
        $days = (new CreditControlService($this->conn, $this->settings))->terms((int)$o['party_id'])['terms'] ?? 30;
        $post = [
            'party_id' => (int)$o['party_id'],
            'invoice_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime("+{$days} days")),
            'status' => 'unpaid',
            'notes' => trim("From order {$o['order_no']}. " . (string)($o['notes'] ?? '')),
            'stock_by_delivery' => 1,
            'discount' => $discount,
            'item_name' => [], 'item_desc' => [], 'item_qty' => [], 'item_price' => [], 'item_tax_rate' => [], 'product_id' => [],
        ];
        $sub = $tax = 0.0;
        foreach ($lines as $l) {
            $qty = $todo[(int)$l['id']] ?? 0.0;
            if ($qty <= 0) {
                continue;
            }
            $net = $qty * (float)$l['unit_price'];
            $sub += $net;
            $tax += $net * ((float)$l['tax_rate'] / 100);
            $post['item_name'][] = $l['item_name'];
            $post['item_desc'][] = $l['description'];
            $post['item_qty'][] = $qty;
            $post['item_price'][] = $l['unit_price'];
            $post['item_tax_rate'][] = $l['tax_rate'];
            $post['product_id'][] = (int)$l['product_id'] > 0 ? $l['product_id'] : '';
        }
        $post['subtotal'] = round($sub, 2);
        $post['tax_amount'] = round($tax, 2);
        $post['total'] = round($sub + $tax - $discount, 2);

        $res = json_decode((new InvoiceService($this->conn, $this->settings))->saveInvoice($post), true);
        if (($res['status'] ?? '') !== 'success' || empty($res['id'])) {
            return $this->fail((string)($res['msg'] ?? 'The invoice could not be created.'));
        }
        $invoiceId = (int)$res['id'];

        $this->conn->begin_transaction();
        try {
            $mark = $this->conn->prepare("UPDATE delivery_note_items x INNER JOIN delivery_notes n ON n.id = x.delivery_note_id
                SET x.invoice_id = ? WHERE n.sales_order_id = ? AND n.tenant_id = ? AND n.status = 'delivered' AND x.invoice_id IS NULL");
            $mark->bind_param('iii', $invoiceId, $orderId, $t);
            $mark->execute();
            $upd = $this->conn->prepare("UPDATE sales_order_items SET invoiced_qty = invoiced_qty + ? WHERE id = ? AND sales_order_id = ?");
            foreach ($todo as $lineId => $qty) {
                $qq = $qty;
                $li = $lineId;
                $upd->bind_param('dii', $qq, $li, $orderId);
                $upd->execute();
            }
            if ($completes) {
                $done = $this->conn->prepare("UPDATE sales_order_list SET status = 'converted_to_invoice', invoice_id = ? WHERE id = ? AND tenant_id = ?");
                $done->bind_param('iii', $invoiceId, $orderId, $t);
                $done->execute();
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The invoice was raised but the deliveries could not be linked to it. Cancel invoice #' . $invoiceId . ' and try again.');
        }
        $this->settings->log_audit('Invoiced deliveries', 'Delivery notes', "order {$o['order_no']} invoiced on invoice #{$invoiceId}");
        $msg = 'Invoice raised for the delivered goods' . ($completes ? ' (the order is now fully invoiced).' : '.');
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $invoiceId]);
    }

    /**
     * An invoice raised from deliveries is being cancelled or deleted: the delivered goods become invoiceable again.
     * Harmless for any other invoice.
     */
    public function releaseInvoice(int $invoiceId): void
    {
        $t = $this->tenantId();
        $stmt = @$this->conn->prepare("SELECT n.sales_order_id, x.order_item_id, SUM(x.qty) q FROM delivery_note_items x
            INNER JOIN delivery_notes n ON n.id = x.delivery_note_id WHERE x.invoice_id = ? AND n.tenant_id = ? GROUP BY n.sales_order_id, x.order_item_id");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ii', $invoiceId, $t);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$rows) {
            return;
        }
        $upd = $this->conn->prepare("UPDATE sales_order_items SET invoiced_qty = GREATEST(0, invoiced_qty - ?) WHERE id = ?");
        foreach ($rows as $r) {
            $q = (float)$r['q'];
            $oi = (int)$r['order_item_id'];
            $upd->bind_param('di', $q, $oi);
            $upd->execute();
        }
        $un = $this->conn->prepare("UPDATE delivery_note_items SET invoice_id = NULL WHERE invoice_id = ?");
        $un->bind_param('i', $invoiceId);
        $un->execute();
        // Whichever invoice it was, the order is no longer fully invoiced.
        $re = $this->conn->prepare("UPDATE sales_order_list SET status = 'confirmed', invoice_id = NULL WHERE id = ? AND tenant_id = ? AND delivery_mode = 1 AND status = 'converted_to_invoice'");
        foreach (array_unique(array_map(fn ($r) => (int)$r['sales_order_id'], $rows)) as $oid) {
            $o = $oid;
            $re->bind_param('ii', $o, $t);
            $re->execute();
        }
    }
}
