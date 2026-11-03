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
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $invoice_no = !empty($invoice_no) ? $this->conn->real_escape_string((string)$invoice_no) : 'INV-' . date('Ym') . '-' . rand(1000, 9999);
        $party_id = isset($party_id) ? $this->conn->real_escape_string((string)$party_id) : '0';
        $invoice_date = !empty($invoice_date) ? $this->conn->real_escape_string((string)$invoice_date) : date('Y-m-d');
        $due_date = !empty($due_date) ? $this->conn->real_escape_string((string)$due_date) : date('Y-m-d', strtotime('+30 days'));
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : InvoiceStatus::UNPAID->value;

        // Get party details
        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
        }
        $party = $party_qry->fetch_assoc();

        // Foreign currency: the form's amounts are in the invoice currency; the ledger and every total are kept in base.
        $fx = new FxService($this->conn, $this->settings);
        $docCurrency = strtoupper(trim((string)($currency ?? '')));
        $isForeign = $fx->isForeign($docCurrency);
        $docRate = null;
        $total_fc = null;
        if (!empty($id)) {
            $cur = $this->conn->query("SELECT currency, fx_rate, total_fc, paid_amount, credited_amount FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
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

        // Accounts for Journal Entry (Accounts Receivable Dr, Sales Revenue Cr)
        $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

        $rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

        // Create/Update Journal
        $journal_code = \App\Services\SequenceService::journalCode($this->conn, (int)$tenant_id);
        $journal_date = $invoice_date;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'invoice_list', (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
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
				`notes` = '{$notes}'
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
                    $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
                    $i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

                    $item_sql = "INSERT INTO `invoice_items` SET
						`invoice_id` = '{$invoice_id}',
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

            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Invoice created successfully." : " Invoice updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);

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
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving invoice.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
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
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, null, 'invoice_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        $inv = $this->conn->query("SELECT invoice_no, journal_id FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!empty($inv['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$inv['journal_id']}'");
        }

        $del = $this->conn->query("DELETE FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Invoice deleted successfully.");
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
