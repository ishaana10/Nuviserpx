<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use Emailer;
use mysqli;
use SystemSettings;

class InvoiceService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveInvoice(array $post): string
    {
        $id = $post['id'] ?? null;
        $invoice_no = $post['invoice_no'] ?? null;
        $party_id = $post['party_id'] ?? null;
        $invoice_date = $post['invoice_date'] ?? null;
        $due_date = $post['due_date'] ?? null;
        $subtotal = $post['subtotal'] ?? null;
        $tax_amount = $post['tax_amount'] ?? null;
        $discount = $post['discount'] ?? null;
        $total = $post['total'] ?? null;
        $notes = $post['notes'] ?? null;
        $status = $post['status'] ?? null;
        $tax_type = $post['tax_type'] ?? null;
        $item_name = $post['item_name'] ?? null;
        $currency = $post['currency'] ?? null;
        $item_desc = $post['item_desc'] ?? null;
        $item_qty = $post['item_qty'] ?? null;
        $item_price = $post['item_price'] ?? null;
        $item_discount_pct = $post['item_discount_pct'] ?? null;
        $item_tax_rate = $post['item_tax_rate'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $invoice_no = !empty($invoice_no) ? $this->conn->real_escape_string(trim((string)$invoice_no)) : '';
        $party_id = isset($party_id) ? $this->conn->real_escape_string((string)$party_id) : '0';
        $invoice_date = !empty($invoice_date) ? $this->conn->real_escape_string((string)$invoice_date) : date('Y-m-d');
        $due_date = !empty($due_date) ? $this->conn->real_escape_string((string)$due_date) : date('Y-m-d', strtotime('+30 days'));
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : InvoiceStatus::UNPAID->value;

        // The totals are worked out from the lines here, the same way the form does, and must agree with what the
        // form sent: a line discount cannot be dropped and a total cannot be typed in by hand.
        $calc = null;
        if (isset($tax_type) && isset($item_name) && is_array($item_name)) {
            $rows = [];
            foreach ($item_name as $k => $v) {
                if (trim((string)$v) === '') continue;
                $rows[$k] = ['qty' => $item_qty[$k] ?? 1, 'price' => $item_price[$k] ?? 0, 'discount_pct' => $item_discount_pct[$k] ?? 0, 'tax_rate' => $item_tax_rate[$k] ?? 0];
            }
            $calc = self::computeTotals($rows, (string)$tax_type, $discount);
            if (isset($calc['error'])) {
                return json_encode(['status' => 'failed', 'msg' => $calc['error']]);
            }
            if (abs($calc['subtotal'] - $subtotal) > 0.02 || abs($calc['tax'] - $tax_amount) > 0.02 || abs($calc['total'] - $total) > 0.02) {
                return json_encode(['status' => 'failed', 'msg' => 'The invoice totals do not match its lines (lines come to ' . number_format($calc['total'], 2) . '). Reload the page and try again.']);
            }
            $subtotal = $calc['subtotal'];
            $tax_amount = $calc['tax'];
            $total = $calc['total'];
        }

        // Get party details
        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
        }
        $party = $party_qry->fetch_assoc();

        // Numbering: the next number in the company's series when none is typed, and never a number already used.
        if ($invoice_no === '') {
            $invoice_no = SequenceService::documentNo($this->conn, (int)$tenant_id, 'invoice_list', 'invoice_no', 'INV-' . date('Ym') . '-', 'invoice-' . date('Ym'));
        } else {
            $dup = $this->conn->query("SELECT 1 FROM `invoice_list` WHERE tenant_id = '{$tenant_id}' AND invoice_no = '{$invoice_no}' AND id <> " . (int)$id . " LIMIT 1");
            if ($dup && $dup->num_rows > 0) {
                return json_encode(['status' => 'failed', 'msg' => "Invoice number {$invoice_no} is already used. Leave it blank to take the next number."]);
            }
        }

        // Foreign currency: the form's amounts are in the invoice currency; the ledger and every total are kept in base.
        $fx = new FxService($this->conn, $this->settings);
        $docCurrency = strtoupper(trim((string)($currency ?? '')));
        $isForeign = $fx->isForeign($docCurrency);
        $docRate = null;
        $total_fc = null;
        if (!empty($id)) {
            $cur = $this->conn->query("SELECT currency, fx_rate, total_fc, paid_amount, credited_amount, status, invoice_no, journal_id, invoice_date FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
            if (!$cur) {
                return json_encode(['status' => 'failed', 'msg' => 'Invoice not found.']);
            }
            if ($problem = self::editProblem((string)$cur['status'])) {
                return json_encode(['status' => 'failed', 'msg' => $problem]);
            }
            if ($status !== InvoiceStatus::CANCELLED->value && (new DeliveryService($this->conn, $this->settings))->linkedToInvoice((int)$id)) {
                return json_encode(['status' => 'failed', 'msg' => 'This invoice was raised from delivery notes, so its lines cannot be edited. Cancel it and invoice the deliveries again.']);
            }
            // Cancelling is its own action: it reverses the invoice in the ledger and returns its stock.
            if ($status === InvoiceStatus::CANCELLED->value) {
                return $this->cancelInvoice((int)$id, (int)$tenant_id, $cur);
            }
            $wasForeign = !empty($cur['currency']);
            $hasSettlement = (float)($cur['paid_amount'] ?? 0) + (float)($cur['credited_amount'] ?? 0) > 0;
            if ($hasSettlement && ($wasForeign !== $isForeign || ($wasForeign && $docCurrency !== strtoupper((string)$cur['currency'])))) {
                return json_encode(['status' => 'failed', 'msg' => 'This invoice already has receipts or credits, so its currency cannot be changed.']);
            }
            if ($wasForeign && $hasSettlement && abs((float)$cur['total_fc'] - $total) > 0.005) {
                return json_encode(['status' => 'failed', 'msg' => 'This invoice has receipts against it, so its amount cannot be changed. Reverse the receipts first, or raise a new invoice.']);
            }
        }
        if ($isForeign) {
            $typedRate = $post['fx_rate'] ?? null;
            if (isset($cur) && !empty($cur['currency']) && $hasSettlement) {
                $typedRate = $cur['fx_rate']; // the rate is fixed once money has been settled against the invoice
            }
            $docRate = $fx->documentRate($docCurrency, $invoice_date, $typedRate);
            if ($docRate === null) {
                return json_encode(['status' => 'failed', 'msg' => "No exchange rate for {$docCurrency} on {$invoice_date}. Add one under Exchange rates, or type the rate on the invoice."]);
            }
            $total_fc = round($total, 2);
            $subtotal = FxService::toBase($subtotal, $docRate);
            $tax_amount = FxService::toBase($tax_amount, $docRate);
            $discount = FxService::toBase($discount, $docRate);
            $total = FxService::toBase($total, $docRate);
        }
        // Credit limit: an invoice that would take the customer over their limit needs a manager's say-so.
        if ($status !== 'draft' && $status !== InvoiceStatus::CANCELLED->value && $total > 0) {
            $over = (new CreditControlService($this->conn, $this->settings))->check(
                (int)$party_id, (string)($party['name'] ?? 'The customer'), (float)$total, (int)$id, !empty($post['credit_override'])
            );
            if ($over !== null) {
                return json_encode(['status' => 'failed', 'msg' => $over]);
            }
        }
        $fxSql = $isForeign
            ? "`currency` = '" . $this->conn->real_escape_string($docCurrency) . "', `fx_rate` = '" . number_format($docRate, 8, '.', '') . "', `total_fc` = '" . number_format((float)$total_fc, 4, '.', '') . "',"
            : "`currency` = NULL, `fx_rate` = NULL, `total_fc` = NULL,";

        // Calculate paid_amount and balance
        $paid_amount = 0.0;
        if (!empty($id)) {
            $curr_inv = $this->conn->query("SELECT paid_amount, credited_amount FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
            $paid_amount = isset($curr_inv['paid_amount']) ? (float)$curr_inv['paid_amount'] : 0.0;
            $credited_amount = isset($curr_inv['credited_amount']) ? (float)$curr_inv['credited_amount'] : 0.0;
        }
        $credited_amount = $credited_amount ?? 0.0;
        // Credit notes count towards settling the invoice just as payments do.
        $balance = CreditNoteService::outstanding($total, $paid_amount, $credited_amount);
        if ($paid_amount + $credited_amount >= $total && $total > 0) {
            $status = InvoiceStatus::PAID->value;
        } elseif ($paid_amount + $credited_amount > 0 && $paid_amount + $credited_amount < $total) {
            $status = InvoiceStatus::PARTIALLY_PAID->value;
        }

        // Accounts for the journal (Accounts Receivable Dr, Sales Revenue Cr): the company's chosen defaults.
        // An invoice is never posted to a guessed account.
        $defaults = new AccountDefaultsService($this->conn, $this->settings);
        $ar_acc_id = $defaults->resolve('receivable');
        $rev_acc_id = $defaults->resolve('revenue');
        if ($ar_acc_id <= 0 || $rev_acc_id <= 0) {
            return json_encode(['status' => 'failed', 'msg' => AccountDefaultsService::missing($ar_acc_id <= 0 ? 'receivable' : 'revenue')]);
        }

        // Create/Update Journal
        $journal_code = \App\Services\SequenceService::journalCode($this->conn, (int)$tenant_id);
        $journal_date = $invoice_date;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'invoice_list', (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        // Stock: lines that name a product are issued from stock at average cost (Dr Cost of Sales, Cr Inventory) when
        // the company has inventory accounting on. Everything that can fail is done before the invoice is written.
        $stockSvc = new InventoryAccountingService($this->conn, $this->settings);
        $stockLines = $this->productLines($post, $stockSvc);
        $held = ['items' => [], 'journal' => 0, 'warehouse' => max(0, (int)($post['stock_warehouse_id'] ?? 0))];
        if (!empty($id)) {
            $held = $this->issuedStock((int)$id, (int)$tenant_id);
        }
        $issued = ['ok' => true, 'msg' => '', 'journal_id' => 0, 'costs' => []];
        // An invoice raised from delivery notes does not issue stock: the deliveries already did.
        $need = $status === InvoiceStatus::CANCELLED->value || $status === 'draft' || !empty($post['stock_by_delivery'])
            ? [] : InventoryAccountingService::aggregate($stockLines);
        $returning = array_map(fn ($i) => $i['qty'], $held['items']);
        // Stock already issued by this invoice is always taken back first (even if inventory accounting has since
        // been switched off), so an edit never leaves cost of sales or stock behind.
        if ($stockSvc->isEnabled() || $held['items']) {
            if ($msg = $stockSvc->checkAvailable($need, $returning)) {
                return json_encode(['status' => 'failed', 'msg' => $msg]);
            }
            if ($held['items']) {
                $back = $stockSvc->restore($held['items'], $held['journal'], $invoice_date, $invoice_no, $held['warehouse']);
                if (!$back['ok']) {
                    return json_encode(['status' => 'failed', 'msg' => 'The stock on this invoice could not be taken back: ' . $back['msg']]);
                }
            }
            $issued = $stockSvc->issue($need, $invoice_date, $invoice_no, $held['warehouse']);
            if (!$issued['ok']) {
                return json_encode(['status' => 'failed', 'msg' => $issued['msg']]);
            }
        }
        $cogsJournalId = (int)$issued['journal_id'];
        $stockCosts = $issued['costs'];
        $partyName = $party['name'] ?? '';
        $description = $this->conn->real_escape_string("Sales Invoice {$invoice_no} to {$partyName} - Total: " . ($isForeign ? "{$docCurrency} " . number_format((float)$total_fc, 2) . " @ {$docRate}" : "\${$total}"));
        $arFx = $isForeign ? ['currency' => $docCurrency, 'fx_rate' => $docRate, 'amount_fc' => (float)$total_fc] : null;

        if (empty($id)) {
            $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
            $this->conn->query($j_sql);
            $journal_id = $this->conn->insert_id;

            $this->postLines((int)$journal_id, (int)$ar_acc_id, (int)$rev_acc_id, (float)$total, (float)$tax_amount, $arFx);

            $sql = "INSERT INTO `invoice_list` SET
				`tenant_id` = '{$tenant_id}',
				`invoice_no` = '{$invoice_no}',
				`party_id` = '{$party_id}',
				`invoice_date` = '{$invoice_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`paid_amount` = '{$paid_amount}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				{$fxSql}
				`notes` = '{$notes}',
				`journal_id` = '{$journal_id}',
				`cogs_journal_id` = " . ($cogsJournalId > 0 ? $cogsJournalId : 'NULL') . ",
				`stock_warehouse_id` = '{$held['warehouse']}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $invoice_id = $this->conn->insert_id;
        } else {
            $existing = $this->conn->query("SELECT journal_id FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
            $journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
            if ($journal_id) {
                $trail = new JournalTrail($this->conn, $this->settings);
                $trailBefore = $trail->snapshot((int)$journal_id);
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
                $this->postLines((int)$journal_id, (int)$ar_acc_id, (int)$rev_acc_id, (float)$total, (float)$tax_amount, $arFx);
                $trail->record((int)$journal_id, 'invoice_list', (int)$id, "Invoice {$invoice_no}", $trailBefore);
            }

            $sql = "UPDATE `invoice_list` SET
				`invoice_no` = '{$invoice_no}',
				`party_id` = '{$party_id}',
				`invoice_date` = '{$invoice_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`paid_amount` = '{$paid_amount}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				{$fxSql}
				`notes` = '{$notes}',
				`cogs_journal_id` = " . ($cogsJournalId > 0 ? $cogsJournalId : 'NULL') . "
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $invoice_id = $id;
        }

        if ($save) {
            // Save Invoice Line Items
            $this->conn->query("DELETE FROM `invoice_items` WHERE invoice_id = '{$invoice_id}'");
            if (isset($item_name) && is_array($item_name)) {
                foreach ($item_name as $k => $v) {
                    $i_name = $this->conn->real_escape_string((string)$v);
                    if (empty($i_name)) continue;

                    $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                    $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                    $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                    $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
                    $i_disc = isset($item_discount_pct[$k]) ? max(0.0, min(100.0, (float)$item_discount_pct[$k])) : 0.0;
                    $la = self::lineAmounts($i_qty, $i_price, $i_disc, $i_tax_rate, isset($tax_type) ? (string)$tax_type : 'exclusive');
                    $i_tax_amt = round($la['tax'], 2);
                    $i_amount = round($la['amount'], 2);

                    $i_pid = (int)($stockLines[$k]['product_id'] ?? 0);
                    $i_cost = ($i_pid > 0 && isset($stockCosts[$i_pid]) && $i_qty > 0) ? number_format((float)$stockCosts[$i_pid], 4, '.', '') : null;

                    $item_sql = "INSERT INTO `invoice_items` SET
						`invoice_id` = '{$invoice_id}',
						`product_id` = " . ($i_pid > 0 ? $i_pid : 'NULL') . ",
						`unit_cost` = " . ($i_cost !== null ? "'{$i_cost}'" : 'NULL') . ",
						`item_name` = '{$i_name}',
						`description` = '{$i_desc}',
						`qty` = '{$i_qty}',
						`unit_price` = '{$i_price}',
						`discount_pct` = '{$i_disc}',
						`tax_rate` = '{$i_tax_rate}',
						`tax_amount` = '{$i_tax_amt}',
						`amount` = '{$i_amount}'";
                    $this->conn->query($item_sql);
                }
            }

            $resp['status'] = 'success';
            $resp['id'] = (int)$invoice_id;
            $resp['msg'] = empty($id) ? " Invoice created successfully." : " Invoice updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
            $this->settings->log_audit(empty($id) ? 'Created invoice' : 'Updated invoice', 'Invoices', "{$invoice_no}: total " . number_format((float)$total, 2) . ", status {$status}.");

            // Emit integration event (webhooks + workflows).
            $event = empty($id)
                ? \App\Support\IntegrationEvents::INVOICE_CREATED
                : \App\Support\IntegrationEvents::INVOICE_UPDATED;
            $this->emit($event, [
                'invoice_id'   => (int)$invoice_id,
                'invoice_no'   => $invoice_no,
                'party_id'     => (int)$party_id,
                'party_name'   => $party['name'] ?? '',
                'party_email'  => $party['email'] ?? '',
                'subtotal'     => (float)$subtotal,
                'tax_amount'   => (float)$tax_amount,
                'discount'     => (float)$discount,
                'total'        => (float)$total,
                'paid_amount'  => (float)$paid_amount,
                'balance'      => (float)$balance,
                'status'       => $status,
                'invoice_date' => $invoice_date,
                'due_date'     => $due_date,
            ], (int)$tenant_id);

            if ($status === InvoiceStatus::CANCELLED->value) {
                $this->emit(\App\Support\IntegrationEvents::INVOICE_CANCELLED, [
                    'invoice_id' => (int)$invoice_id,
                    'invoice_no' => $invoice_no,
                    'party_id'   => (int)$party_id,
                    'party_name' => $party['name'] ?? '',
                    'total'      => (float)$total,
                ], (int)$tenant_id);
            }
        } else {
            // The invoice did not save: put back the stock just issued so the books and the shelves stay level.
            if ($need && $stockCosts && $stockSvc->isEnabled()) {
                $undo = [];
                foreach ($need as $pid => $qty) {
                    $undo[$pid] = ['qty' => $qty, 'cost' => (float)($stockCosts[$pid] ?? 0)];
                }
                $stockSvc->restore($undo, $cogsJournalId, $invoice_date, $invoice_no, $held['warehouse']);
            }
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving invoice.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    /** Why a cancelled invoice cannot be edited, or null. */
    public static function editProblem(string $status): ?string
    {
        return $status === InvoiceStatus::CANCELLED->value ? 'A cancelled invoice cannot be changed. Raise a new invoice instead.' : null;
    }

    /** Why an invoice cannot be cancelled, or null. Money settled against it must be undone first. */
    public static function cancelProblem(float $paid, float $credited): ?string
    {
        return ($paid + $credited) > 0.004
            ? 'This invoice has receipts or credit notes against it. Reverse or delete those first, then cancel it.'
            : null;
    }

    /**
     * Why an invoice cannot be deleted, or null. A posted invoice is cancelled (which reverses it and leaves the
     * trail), never deleted; only drafts and already-cancelled invoices can be removed.
     */
    public static function deleteProblem(string $status, float $paid, float $credited): ?string
    {
        if (($paid + $credited) > 0.004) {
            return 'This invoice has receipts or credit notes against it, so it cannot be deleted.';
        }
        if (!in_array($status, ['draft', InvoiceStatus::CANCELLED->value], true)) {
            return 'A posted invoice cannot be deleted. Cancel it first (which reverses it in the ledger and returns its stock), then delete the cancelled invoice if you need to.';
        }
        return null;
    }

    /**
     * Cancel an invoice: reverse its journal, take back its stock and cost of sales, and mark it cancelled with
     * nothing owing. Nothing is erased, so the ledger shows the invoice and its reversal.
     */
    private function cancelInvoice(int $id, int $tenantId, array $cur): string
    {
        if ($cur['status'] === InvoiceStatus::CANCELLED->value) {
            return json_encode(['status' => 'success', 'id' => $id, 'msg' => ' Invoice is already cancelled.']);
        }
        if ($problem = self::cancelProblem((float)$cur['paid_amount'], (float)$cur['credited_amount'])) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        $date = date('Y-m-d');
        if ($lockMsg = \App\Services\PeriodLockService::guard($this->conn, $tenantId, $date)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        if (!empty($cur['journal_id'])) {
            $jdate = (string)($this->conn->query("SELECT journal_date FROM journal_entries WHERE id = '" . (int)$cur['journal_id'] . "'")->fetch_assoc()['journal_date'] ?? '');
            $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$cur['journal_id'], $jdate !== '' && $date < $jdate ? $jdate : $date, true), true);
            if (($res['status'] ?? '') !== 'success') {
                return json_encode(['status' => 'failed', 'msg' => 'The invoice could not be reversed: ' . ($res['msg'] ?? 'unknown error')]);
            }
        }
        $warning = '';
        $held = $this->issuedStock($id, $tenantId);
        if ($held['items'] || $held['journal']) {
            $back = (new InventoryAccountingService($this->conn, $this->settings))->restore($held['items'], $held['journal'], $date, (string)$cur['invoice_no'], $held['warehouse']);
            if ($back['ok']) {
                // Stock is back: forget the issue so it can never be returned twice.
                $this->conn->query("UPDATE `invoice_items` SET `unit_cost` = NULL WHERE invoice_id = '{$id}'");
                $this->conn->query("UPDATE `invoice_list` SET `cogs_journal_id` = NULL WHERE id = '{$id}' AND tenant_id = '{$tenantId}'");
            } else {
                $warning = ' The invoice was reversed but its stock could not be returned: ' . $back['msg'];
            }
        }
        $this->conn->query("UPDATE `invoice_list` SET `status` = 'cancelled', `balance` = 0 WHERE id = '{$id}' AND tenant_id = '{$tenantId}'");
        // Goods delivered on this invoice become invoiceable again.
        (new DeliveryService($this->conn, $this->settings))->releaseInvoice($id);
        $this->settings->log_audit('Cancelled invoice', 'Invoices', (string)$cur['invoice_no']);
        $this->emit(\App\Support\IntegrationEvents::INVOICE_CANCELLED, ['invoice_id' => $id, 'invoice_no' => $cur['invoice_no'], 'total' => 0], $tenantId);
        $msg = ' Invoice cancelled and reversed.' . $warning;
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'id' => $id, 'msg' => $msg]);
    }

    /**
     * The invoice's lines as [product_id, qty] by position, keeping only products that belong to this company.
     *
     * @return array<int,array{product_id:int,qty:float}>
     */
    private function productLines(array $post, InventoryAccountingService $stock): array
    {
        $rows = [];
        $ids = [];
        foreach ((array)($post['item_name'] ?? []) as $k => $name) {
            $pid = isset($post['product_id'][$k]) && is_numeric($post['product_id'][$k]) ? (int)$post['product_id'][$k] : 0;
            $rows[$k] = ['product_id' => empty($name) ? 0 : $pid, 'qty' => isset($post['item_qty'][$k]) ? (float)$post['item_qty'][$k] : 1.0];
            if ($rows[$k]['product_id'] > 0) {
                $ids[] = $rows[$k]['product_id'];
            }
        }
        $known = $stock->products($ids);
        foreach ($rows as $k => $r) {
            if ($r['product_id'] > 0 && !isset($known[$r['product_id']])) {
                $rows[$k]['product_id'] = 0;
            }
        }
        return $rows;
    }

    /**
     * One line's figures the way the invoice form works them out. $mode is 'exclusive' (tax added), 'inclusive'
     * (tax is inside the price) or 'none'. Unrounded; callers round for display. Pure.
     *
     * @return array{sub:float,tax:float,amount:float}
     */
    public static function lineAmounts(float $qty, float $price, float $discountPct, float $taxRate, string $mode): array
    {
        $sub = $qty * $price * (1 - $discountPct / 100);
        $rate = $mode === 'none' ? 0.0 : $taxRate;
        $tax = match ($mode) {
            'inclusive' => $sub - ($sub / (1 + $rate / 100)),
            'none'      => 0.0,
            default     => $sub * ($rate / 100),
        };
        return ['sub' => $sub, 'tax' => $tax, 'amount' => $mode === 'inclusive' || $mode === 'none' ? $sub : $sub + $tax];
    }

    /**
     * Subtotal, tax and total from the lines: subtotal is after line discounts, total = subtotal (+ tax when tax is
     * added on top) - the overall discount. Returns ['error'] for impossible lines. Pure.
     *
     * @param array<int,array{qty:mixed,price:mixed,discount_pct:mixed,tax_rate:mixed}> $rows
     */
    public static function computeTotals(array $rows, string $mode, float $overallDiscount): array
    {
        if (!in_array($mode, ['exclusive', 'inclusive', 'none'], true)) {
            $mode = 'exclusive';
        }
        if ($overallDiscount < 0) {
            return ['error' => 'The discount cannot be negative.'];
        }
        $sub = $tax = 0.0;
        foreach ($rows as $r) {
            $q = (float)$r['qty'];
            $p = (float)$r['price'];
            $d = (float)$r['discount_pct'];
            $t = (float)$r['tax_rate'];
            if ($q < 0 || $p < 0) {
                return ['error' => 'Quantities and prices cannot be negative.'];
            }
            if ($d < 0 || $d > 100) {
                return ['error' => 'A line discount must be between 0% and 100%.'];
            }
            if ($t < 0 || $t > 100) {
                return ['error' => 'A tax rate must be between 0% and 100%.'];
            }
            $l = self::lineAmounts($q, $p, $d, $t, $mode);
            $sub += $l['sub'];
            $tax += $l['tax'];
        }
        $total = $sub + ($mode === 'exclusive' ? $tax : 0.0) - $overallDiscount;
        if ($total < -0.005) {
            return ['error' => 'The discount is more than the invoice comes to.'];
        }
        return ['subtotal' => round($sub, 2), 'tax' => round($tax, 2), 'total' => round($total, 2)];
    }

    /**
     * Stock this invoice has already issued: per product the quantity and the cost it left at, plus the journal.
     *
     * @return array{items:array<int,array{qty:float,cost:float}>,journal:int,warehouse:int}
     */
    private function issuedStock(int $invoiceId, int $tenantId): array
    {
        $out = ['items' => [], 'journal' => 0, 'warehouse' => 0];
        $stmt = $this->conn->prepare("SELECT COALESCE(cogs_journal_id, 0) j, stock_warehouse_id w FROM invoice_list WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $invoiceId, $tenantId);
        $stmt->execute();
        if ($h = $stmt->get_result()->fetch_assoc()) {
            $out['journal'] = (int)$h['j'];
            $out['warehouse'] = (int)$h['w'];
        }
        $it = $this->conn->prepare("SELECT product_id, qty, unit_cost FROM invoice_items WHERE invoice_id = ? AND product_id IS NOT NULL AND unit_cost IS NOT NULL");
        $it->bind_param('i', $invoiceId);
        $it->execute();
        foreach ($it->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $pid = (int)$r['product_id'];
            $cur = $out['items'][$pid] ?? ['qty' => 0.0, 'cost' => (float)$r['unit_cost']];
            $cur['qty'] = round($cur['qty'] + (float)$r['qty'], 4);
            $out['items'][$pid] = $cur;
        }
        return $out;
    }

    /**
     * Fire an integration event through ModuleManager (webhooks + workflows).
     * Guarded so a missing/loaded-out-of-order ModuleManager never breaks a save.
     *
     * @param array<string,mixed> $payload
     */
    private function emit(string $event, array $payload, int $tenant_id): void
    {
        if (class_exists('ModuleManager')) {
            try {
                \ModuleManager::trigger_hook($event, $payload, $tenant_id);
            } catch (\Throwable $e) {
                // integration failures must not surface to the core transaction
            }
        }
    }

    public function deleteInvoice(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $inv = $this->conn->query("SELECT invoice_no, journal_id, status, paid_amount, credited_amount FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!$inv) {
            return json_encode(['status' => 'failed', 'msg' => 'Invoice not found.']);
        }
        if ($problem = self::deleteProblem((string)$inv['status'], (float)$inv['paid_amount'], (float)$inv['credited_amount'])) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        $isDraft = $inv['status'] === 'draft';
        if ($isDraft) {
            // A draft never counted, but it still has a journal row and possibly stock: tidy both.
            if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, null, 'invoice_list', $id)) {
                return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
            }
            $held = $this->issuedStock($id, (int)$tenant_id);
            if ($held['items'] || $held['journal']) {
                $back = (new InventoryAccountingService($this->conn, $this->settings))->restore($held['items'], $held['journal'], date('Y-m-d'), (string)$inv['invoice_no'], $held['warehouse']);
                if (!$back['ok']) {
                    return json_encode(['status' => 'failed', 'msg' => 'This invoice issued stock that could not be taken back: ' . $back['msg']]);
                }
            }
            if (!empty($inv['journal_id'])) {
                $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$inv['journal_id']}' AND tenant_id = '{$tenant_id}'");
            }
        }
        // A cancelled invoice's journal was reversed when it was cancelled; both stay in the ledger as the record.
        (new DeliveryService($this->conn, $this->settings))->releaseInvoice($id);

        $del = $this->conn->query("DELETE FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Invoice deleted successfully.");
            $this->settings->log_audit('Deleted invoice', 'Invoices', (string)$inv['invoice_no']);
            $this->emit(\App\Support\IntegrationEvents::INVOICE_DELETED, [
                'invoice_id' => (int)$id,
                'invoice_no' => $inv['invoice_no'] ?? '',
            ], (int)$tenant_id);
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function emailInvoice(int $id, ?string $custom_email = null): string
    {
        require_once(base_app . 'classes/Emailer.php');
        $tenant_id = $this->settings->active_tenant_id();

        $inv_qry = $this->conn->query("
			SELECT i.*, p.name AS party_name, p.email AS party_email
			FROM `invoice_list` i
			INNER JOIN `party_list` p ON i.party_id = p.id
			WHERE i.id = '{$id}' AND i.tenant_id = '{$tenant_id}'
		");

        if (!$inv_qry || $inv_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Invoice not found.']);
        }

        $invoice = $inv_qry->fetch_assoc();
        $email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : ($invoice['party_email'] ?? '');

        if (empty($email_to)) {
            return json_encode(['status' => 'failed', 'msg' => 'No email address found for this customer. Please specify an email address.']);
        }

        $items_qry = $this->conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}'");
        $items = [];
        if ($items_qry) {
            while ($it = $items_qry->fetch_assoc()) {
                $items[] = $it;
            }
        }

        $sys_info = [
            'name' => $this->settings->info('name'),
            'email' => $this->settings->info('email')
        ];

        $send = Emailer::send_invoice_email($email_to, $invoice, $items, $sys_info);
        if ($send) {
            return json_encode(['status' => 'success', 'msg' => "Invoice #{$invoice['invoice_no']} sent successfully to {$email_to}."]);
        } else {
            return json_encode(['status' => 'failed', 'msg' => 'Failed to send invoice email. Please check SMTP settings.']);
        }
    }

    /** Receivable Dr gross; Revenue Cr net; VAT Output Cr tax (see TaxService::postingLines). */
    private function postLines(int $journalId, int $arAcc, int $revAcc, float $total, float $tax, ?array $arFx = null): void
    {
        $taxSvc = new TaxService($this->conn, $this->settings);
        $taxAcc = $tax > 0 ? $taxSvc->controlAccount('output') : 0;
        $lines = TaxService::postingLines('sale', $total, $tax, $revAcc, $taxAcc, $arAcc);
        if ($arFx) {
            foreach ($lines as &$ln) {
                if ($ln[0] === $arAcc && $ln[1] === TaxService::DEBIT) {
                    $ln[3] = $arFx;
                }
            }
            unset($ln);
        }
        if (!$taxSvc->writeLines($journalId, $lines)) {
            // Never leave a half-posted journal: fall back to a single unsplit pair.
            $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = " . (int)$journalId);
            $plain = TaxService::postingLines('sale', $total, 0.0, $revAcc, 0, $arAcc);
            if ($arFx) {
                $plain[0][3] = $arFx;
            }
            $taxSvc->writeLines($journalId, $plain);
        }
    }
}
