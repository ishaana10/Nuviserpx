<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use Emailer;
use mysqli;
use SystemSettings;

class PaymentService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function savePayment(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $payment_no = !empty($payment_no) ? $this->conn->real_escape_string((string)$payment_no) : 'REC-' . date('Ym') . '-' . rand(1000, 9999);
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'receipt';
        $party_id = isset($party_id) ? $this->conn->real_escape_string((string)$party_id) : '0';
        $bank_account_id = !empty($bank_account_id) ? (int)$bank_account_id : null;
        $payment_date = !empty($payment_date) ? $this->conn->real_escape_string((string)$payment_date) : date('Y-m-d');
        $amount = isset($amount) ? (float)$amount : 0.0;
        $method = !empty($method) ? $this->conn->real_escape_string((string)$method) : 'cash';
        $reference = isset($reference) ? $this->conn->real_escape_string((string)$reference) : '';
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : PaymentStatus::POSTED->value;
        $fx = new FxService($this->conn, $this->settings);
        $currency = !empty($currency) ? strtoupper($this->conn->real_escape_string(trim((string)$currency))) : $fx->baseCurrency();

        // Get party details
        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
        }
        $party = $party_qry->fetch_assoc();

        // A receipt in a foreign currency settles invoices in that currency and books the exchange difference.
        if ($fx->isForeign($currency)) {
            return $this->saveForeignReceipt($post, $fx, $currency, (string)$id, (int)$tenant_id, (int)$user_id, $party);
        }
        // A company-currency receipt cannot settle a foreign-currency invoice: its balance is tracked in that currency.
        if (isset($invoice_alloc) && is_array($invoice_alloc) && $invoice_alloc) {
            $ids = implode(',', array_map('intval', array_keys($invoice_alloc)));
            $chk = $this->conn->query("SELECT invoice_no, currency FROM `invoice_list` WHERE id IN ({$ids}) AND tenant_id = '{$tenant_id}' AND currency IS NOT NULL LIMIT 1");
            if ($chk && ($fcInv = $chk->fetch_assoc())) {
                return json_encode(['status' => 'failed', 'msg' => "Invoice {$fcInv['invoice_no']} is in {$fcInv['currency']}. Record the receipt in {$fcInv['currency']} so the exchange difference is booked correctly."]);
            }
        }

        // Accounts for Journal Entry (Cash/Bank Dr, Accounts Receivable Cr)
        $cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $cash_acc_id = !empty($bank_account_id) ? $bank_account_id : (($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1);

        $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

        // Create/Update Journal
        $journal_code = \App\Services\SequenceService::journalCode($this->conn, (int)$tenant_id);
        $journal_date = $payment_date;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'payment_list', (int)$id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        $partyName = $party['name'] ?? '';
        $description = $this->conn->real_escape_string("Payment Receipt {$payment_no} from {$partyName} - Amount: \${$amount}");

        if (empty($id)) {
            $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
            $this->conn->query($j_sql);
            $journal_id = $this->conn->insert_id;

            $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$cash_acc_id}', '1', '{$amount}'),
				('{$journal_id}', '{$ar_acc_id}', '2', '{$amount}')";
            $this->conn->query($j_items);

            $bank_account_sql = $bank_account_id ? "'{$bank_account_id}'" : "NULL";

            $sql = "INSERT INTO `payment_list` SET
				`tenant_id` = '{$tenant_id}',
				`payment_no` = '{$payment_no}',
				`type` = '{$type}',
				`party_id` = '{$party_id}',
				`bank_account_id` = {$bank_account_sql},
				`payment_date` = '{$payment_date}',
				`amount` = '{$amount}',
				`method` = '{$method}',
				`reference` = '{$reference}',
				`notes` = '{$notes}',
				`status` = '{$status}',
				`currency` = '{$currency}',
				`journal_id` = '{$journal_id}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $payment_id = $this->conn->insert_id;
        } else {
            $existing = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$id}'")->fetch_assoc();
            $journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
            if ($journal_id) {
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
                $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$cash_acc_id}', '1', '{$amount}'),
					('{$journal_id}', '{$ar_acc_id}', '2', '{$amount}')";
                $this->conn->query($j_items);
            }

            $bank_account_sql = $bank_account_id ? "'{$bank_account_id}'" : "NULL";

            $sql = "UPDATE `payment_list` SET
				`payment_no` = '{$payment_no}',
				`type` = '{$type}',
				`party_id` = '{$party_id}',
				`bank_account_id` = {$bank_account_sql},
				`payment_date` = '{$payment_date}',
				`amount` = '{$amount}',
				`method` = '{$method}',
				`reference` = '{$reference}',
				`notes` = '{$notes}',
				`status` = '{$status}',
				`currency` = '{$currency}',
				`amount_fc` = NULL,
				`fx_rate` = NULL
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $payment_id = $id;
        }

        if ($save) {
            // Clear old allocations & recalculate affected invoice balances
            $old_allocs = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$payment_id}'");
            $affected_invoices = [];
            if ($old_allocs) {
                while ($oa = $old_allocs->fetch_assoc()) {
                    $affected_invoices[] = $oa['invoice_id'];
                }
            }
            $this->conn->query("DELETE FROM `payment_allocations` WHERE payment_id = '{$payment_id}'");

            // Save Payment Allocations across invoice(s)
            if (isset($invoice_alloc) && is_array($invoice_alloc)) {
                foreach ($invoice_alloc as $inv_id => $alloc_amt) {
                    $alloc_amt = (float)$alloc_amt;
                    if ($alloc_amt <= 0) continue;

                    $inv_id = (int)$inv_id;
                    $affected_invoices[] = $inv_id;

                    $alloc_sql = "INSERT INTO `payment_allocations` SET
						`payment_id` = '{$payment_id}',
						`invoice_id` = '{$inv_id}',
						`amount` = '{$alloc_amt}'";
                    $this->conn->query($alloc_sql);
                }
            }

            // Update total paid and balance for all affected invoices
            $affected_invoices = array_unique($affected_invoices);
            foreach ($affected_invoices as $inv_id) {
                $sum_alloc = $this->conn->query("SELECT SUM(amount) as total_paid FROM `payment_allocations` WHERE invoice_id = '{$inv_id}'")->fetch_assoc();
                $tot_paid = isset($sum_alloc['total_paid']) ? (float)$sum_alloc['total_paid'] : 0.0;

                $inv_data = $this->conn->query("SELECT total, credited_amount FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
                $inv_credit = isset($inv_data['credited_amount']) ? (float)$inv_data['credited_amount'] : 0.0;
                $inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0.0;

                $new_bal = CreditNoteService::outstanding($inv_total, $tot_paid, $inv_credit);
                $new_status = 'unpaid';
                if ($tot_paid + $inv_credit >= $inv_total && $inv_total > 0) {
                    $new_status = 'paid';
                } elseif ($tot_paid + $inv_credit > 0 && $tot_paid + $inv_credit < $inv_total) {
                    $new_status = 'partially_paid';
                }

                $this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");

                // Emit invoice payment-state events.
                if ($new_status === 'paid' || $new_status === 'partially_paid') {
                    $inv_row = $this->conn->query("SELECT i.invoice_no, i.total, p.name AS party_name, p.email AS party_email FROM `invoice_list` i INNER JOIN `party_list` p ON i.party_id = p.id WHERE i.id = '{$inv_id}'");
                    $inv_row = $inv_row ? $inv_row->fetch_assoc() : [];
                    $evt = $new_status === 'paid'
                        ? \App\Support\IntegrationEvents::INVOICE_PAID
                        : \App\Support\IntegrationEvents::INVOICE_PARTIALLY_PAID;
                    $this->emit($evt, [
                        'invoice_id'  => (int)$inv_id,
                        'invoice_no'  => $inv_row['invoice_no'] ?? '',
                        'party_id'    => (int)$party_id,
                        'party_name'  => $inv_row['party_name'] ?? '',
                        'party_email' => $inv_row['party_email'] ?? '',
                        'total'       => (float)($inv_row['total'] ?? 0),
                        'paid_amount' => (float)$tot_paid,
                        'balance'     => (float)$new_bal,
                        'status'      => $new_status,
                    ], (int)$tenant_id);
                }
            }

            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Payment Receipt created and allocated successfully." : " Payment details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);

            // Emit the payment-received event.
            if (empty($id)) {
                $this->emit(\App\Support\IntegrationEvents::PAYMENT_RECEIVED, [
                    'payment_id'  => (int)$payment_id,
                    'payment_no'  => $payment_no,
                    'party_id'    => (int)$party_id,
                    'party_name'  => $party['name'] ?? '',
                    'party_email' => $party['email'] ?? '',
                    'amount'      => (float)$amount,
                    'method'      => $method,
                    'payment_date'=> $payment_date,
                ], (int)$tenant_id);
            }
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving payment.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    /**
     * A receipt in a foreign currency. `amount` and the allocations keep base values (cash at the receipt-date rate,
     * receivables at the invoice's carried value), with the foreign amounts beside them. The difference is the
     * realised exchange gain or loss, posted in the same journal.
     */
    private function saveForeignReceipt(array $post, FxService $fx, string $currency, string $id, int $tenant_id, int $user_id, array $party): string
    {
        $payment_no = !empty($post['payment_no']) ? $this->conn->real_escape_string((string)$post['payment_no']) : 'REC-' . date('Ym') . '-' . rand(1000, 9999);
        $party_id = (int)($post['party_id'] ?? 0);
        $bank_account_id = !empty($post['bank_account_id']) ? (int)$post['bank_account_id'] : null;
        $payment_date = !empty($post['payment_date']) ? $this->conn->real_escape_string((string)$post['payment_date']) : date('Y-m-d');
        $amountFc = round((float)($post['amount'] ?? 0), 2);
        $method = !empty($post['method']) ? $this->conn->real_escape_string((string)$post['method']) : 'cash';
        $reference = $this->conn->real_escape_string((string)($post['reference'] ?? ''));
        $notes = $this->conn->real_escape_string((string)($post['notes'] ?? ''));
        $status = !empty($post['status']) ? $this->conn->real_escape_string((string)$post['status']) : PaymentStatus::POSTED->value;
        $fail = static fn(string $m): string => json_encode(['status' => 'failed', 'msg' => $m]);

        if ($amountFc <= 0) {
            return $fail('Enter the amount received.');
        }
        $rate = $fx->documentRate($currency, $payment_date, $post['fx_rate'] ?? null);
        if ($rate === null) {
            return $fail("No exchange rate for {$currency} on {$payment_date}. Add one under Exchange rates, or type the rate on the receipt.");
        }
        $payId = (int)$id;
        if ($lockMsg = PeriodLockService::guardDocument($this->conn, $tenant_id, $payment_date, 'payment_list', $payId)) {
            return $fail($lockMsg);
        }
        $cashBase = FxService::toBase($amountFc, $rate);

        $this->conn->begin_transaction();
        try {
            // Work out each allocation: foreign amount in, carried base value out.
            $plan = [];
            $sumFc = 0.0;
            $sumAr = 0.0;
            foreach ((array)($post['invoice_alloc'] ?? []) as $invId => $allocFc) {
                $allocFc = round((float)$allocFc, 2);
                if ($allocFc <= 0) {
                    continue;
                }
                $invId = (int)$invId;
                $q = $this->conn->prepare("SELECT invoice_no, currency, fx_rate, total, total_fc, status FROM `invoice_list` WHERE id = ? AND tenant_id = ? AND party_id = ? FOR UPDATE");
                $q->bind_param('iii', $invId, $tenant_id, $party_id);
                $q->execute();
                $inv = $q->get_result()->fetch_assoc();
                if (!$inv || $inv['status'] === 'cancelled') {
                    throw new \RuntimeException('One of the invoices could not be found for this customer.');
                }
                if (strtoupper((string)$inv['currency']) !== $currency) {
                    throw new \RuntimeException("Invoice {$inv['invoice_no']} is not in {$currency}. A receipt can only settle invoices in its own currency.");
                }
                $o = $this->conn->prepare("SELECT COALESCE(SUM(amount_fc), 0) fc, COALESCE(SUM(amount), 0) base FROM `payment_allocations` WHERE invoice_id = ? AND payment_id <> ?");
                $o->bind_param('ii', $invId, $payId);
                $o->execute();
                $other = $o->get_result()->fetch_assoc();
                $remFc = round((float)$inv['total_fc'] - (float)$other['fc'], 2);
                $remBase = round((float)$inv['total'] - (float)$other['base'], 2);
                if ($allocFc > $remFc + 0.005) {
                    throw new \RuntimeException("Invoice {$inv['invoice_no']} has only {$currency} " . number_format($remFc, 2) . ' left to pay.');
                }
                $ar = FxService::allocationBase($allocFc, $remFc, $remBase, (float)$inv['fx_rate']);
                $plan[$invId] = ['fc' => $allocFc, 'ar' => $ar];
                $sumFc += $allocFc;
                $sumAr += $ar;
            }
            if ($sumFc > $amountFc + 0.005) {
                throw new \RuntimeException('More has been allocated to invoices than was received.');
            }
            $unallocFc = round($amountFc - $sumFc, 2);
            $arTotal = round($sumAr + FxService::toBase($unallocFc, $rate), 2);

            // Accounts
            $cashQ = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
            $cashAcc = $bank_account_id ?: (($cashQ && $cashQ->num_rows > 0) ? (int)$cashQ->fetch_assoc()['id'] : 1);
            $arQ = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
            $arAcc = ($arQ && $arQ->num_rows > 0) ? (int)$arQ->fetch_assoc()['id'] : 1;
            $fxAcc = 0;
            if (round($cashBase - $arTotal, 2) != 0.0) {
                $fxAcc = $fx->realisedAccount();
                if ($fxAcc === 0) {
                    throw new \RuntimeException('The ' . FxService::REALISED_ACCOUNT . ' account could not be created.');
                }
            }
            $lines = FxService::receiptLines($cashAcc, $arAcc, $fxAcc, $cashBase, $arTotal, $currency, $rate, $amountFc);

            // Journal
            $desc = $this->conn->real_escape_string("Payment Receipt {$payment_no} from " . ($party['name'] ?? '') . " - {$currency} " . number_format($amountFc, 2) . " @ {$rate}");
            $tax = new TaxService($this->conn, $this->settings);
            $journalId = 0;
            if ($payId > 0) {
                $e = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$payId}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
                $journalId = (int)($e['journal_id'] ?? 0);
            }
            if ($journalId > 0) {
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$desc}', `journal_date` = '{$payment_date}' WHERE id = '{$journalId}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journalId}'");
            } else {
                $code = SequenceService::journalCode($this->conn, $tenant_id);
                $this->conn->query("INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$code}', '{$payment_date}', '{$desc}', '{$user_id}')");
                $journalId = (int)$this->conn->insert_id;
            }
            if (!$tax->writeLines($journalId, $lines)) {
                throw new \RuntimeException('The receipt journal could not be posted.');
            }

            // Receipt row
            $bankSql = $bank_account_id ? "'{$bank_account_id}'" : 'NULL';
            $set = "`payment_no` = '{$payment_no}', `type` = 'receipt', `party_id` = '{$party_id}', `bank_account_id` = {$bankSql},
                `payment_date` = '{$payment_date}', `amount` = '" . number_format($cashBase, 2, '.', '') . "', `amount_fc` = '" . number_format($amountFc, 4, '.', '') . "',
                `fx_rate` = '" . number_format($rate, 8, '.', '') . "', `method` = '{$method}', `reference` = '{$reference}', `notes` = '{$notes}',
                `status` = '{$status}', `currency` = '" . $this->conn->real_escape_string($currency) . "', `journal_id` = '{$journalId}'";
            $affected = [];
            if ($payId > 0) {
                $this->conn->query("UPDATE `payment_list` SET {$set} WHERE id = '{$payId}' AND tenant_id = '{$tenant_id}'");
                $old = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$payId}'");
                while ($old && ($oa = $old->fetch_assoc())) {
                    $affected[] = (int)$oa['invoice_id'];
                }
                $this->conn->query("DELETE FROM `payment_allocations` WHERE payment_id = '{$payId}'");
            } else {
                $this->conn->query("INSERT INTO `payment_list` SET `tenant_id` = '{$tenant_id}', {$set}, `created_by` = '{$user_id}'");
                $payId = (int)$this->conn->insert_id;
            }
            if ($payId <= 0 || $this->conn->errno) {
                throw new \RuntimeException('The receipt could not be saved.');
            }
            foreach ($plan as $invId => $p) {
                $this->conn->query("INSERT INTO `payment_allocations` SET `payment_id` = '{$payId}', `invoice_id` = '{$invId}', `amount` = '" . number_format($p['ar'], 2, '.', '') . "', `amount_fc` = '" . number_format($p['fc'], 4, '.', '') . "'");
                $affected[] = $invId;
            }
            foreach (array_unique($affected) as $invId) {
                $this->refreshInvoice((int)$invId);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $fail($e instanceof \RuntimeException ? $e->getMessage() : 'The receipt could not be saved.');
        }

        $gain = round($cashBase - $arTotal, 2);
        $this->settings->log_audit('Foreign-currency receipt', 'Payments', "{$payment_no}: {$currency} " . number_format($amountFc, 2) . " at {$rate} = " . number_format($cashBase, 2) . ($gain != 0.0 ? ', realised exchange ' . ($gain > 0 ? 'gain ' : 'loss ') . number_format(abs($gain), 2) : ''));
        $msg = empty($id) ? ' Payment Receipt created and allocated successfully.' : ' Payment details updated successfully.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'realised' => $gain]);
    }

    /** Recompute an invoice's paid amount (base and foreign), balance and status from its allocations. */
    private function refreshInvoice(int $invId): void
    {
        $r = $this->conn->query("SELECT COALESCE(SUM(amount), 0) base, COALESCE(SUM(amount_fc), 0) fc FROM `payment_allocations` WHERE invoice_id = '{$invId}'")->fetch_assoc();
        $d = $this->conn->query("SELECT total, credited_amount FROM `invoice_list` WHERE id = '{$invId}'")->fetch_assoc();
        $paid = (float)$r['base'];
        $credit = (float)($d['credited_amount'] ?? 0);
        $total = (float)($d['total'] ?? 0);
        $bal = CreditNoteService::outstanding($total, $paid, $credit);
        $status = 'unpaid';
        if ($paid + $credit >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($paid + $credit > 0) {
            $status = 'partially_paid';
        }
        $fc = number_format((float)$r['fc'], 4, '.', '');
        $this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$paid}', `paid_fc` = '{$fc}', `balance` = '{$bal}', `status` = '{$status}' WHERE id = '{$invId}'");
    }

    public function deletePayment(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, null, 'payment_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        // Get affected invoices before deleting
        $old_allocs = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$id}'");
        $affected_invoices = [];
        if ($old_allocs) {
            while ($oa = $old_allocs->fetch_assoc()) {
                $affected_invoices[] = $oa['invoice_id'];
            }
        }

        $pay = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!empty($pay['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$pay['journal_id']}'");
        }

        $del = $this->conn->query("DELETE FROM `payment_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            // Recalculate invoice balances
            foreach (array_unique($affected_invoices) as $inv_id) {
                $sum_alloc = $this->conn->query("SELECT SUM(amount) as total_paid FROM `payment_allocations` WHERE invoice_id = '{$inv_id}'")->fetch_assoc();
                $tot_paid = isset($sum_alloc['total_paid']) ? (float)$sum_alloc['total_paid'] : 0.0;

                $inv_data = $this->conn->query("SELECT total, credited_amount FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
                $inv_credit = isset($inv_data['credited_amount']) ? (float)$inv_data['credited_amount'] : 0.0;
                $inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0.0;

                $new_bal = CreditNoteService::outstanding($inv_total, $tot_paid, $inv_credit);
                $new_status = 'unpaid';
                if ($tot_paid + $inv_credit >= $inv_total && $inv_total > 0) {
                    $new_status = 'paid';
                } elseif ($tot_paid + $inv_credit > 0 && $tot_paid + $inv_credit < $inv_total) {
                    $new_status = 'partially_paid';
                }

                $this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");
                $this->conn->query("UPDATE `invoice_list` SET `paid_fc` = (SELECT COALESCE(SUM(amount_fc), 0) FROM `payment_allocations` WHERE invoice_id = '{$inv_id}') WHERE id = '{$inv_id}'");
            }

            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Payment receipt deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function emailPaymentReceipt(int $id, ?string $custom_email = null): string
    {
        require_once(base_app . 'classes/Emailer.php');
        $tenant_id = $this->settings->active_tenant_id();

        $pay_qry = $this->conn->query("
			SELECT p.*, pt.name AS party_name, pt.email AS party_email
			FROM `payment_list` p
			INNER JOIN `party_list` pt ON p.party_id = pt.id
			WHERE p.id = '{$id}' AND p.tenant_id = '{$tenant_id}'
		");

        if (!$pay_qry || $pay_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Payment receipt not found.']);
        }

        $payment = $pay_qry->fetch_assoc();
        $email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : ($payment['party_email'] ?? '');

        if (empty($email_to)) {
            return json_encode(['status' => 'failed', 'msg' => 'No email address found for this customer. Please specify an email address.']);
        }

        $alloc_qry = $this->conn->query("
			SELECT pa.amount AS allocated_amount, i.invoice_no, i.invoice_date, i.total AS invoice_total
			FROM `payment_allocations` pa
			INNER JOIN `invoice_list` i ON pa.invoice_id = i.id
			WHERE pa.payment_id = '{$id}'
		");
        $allocations = [];
        if ($alloc_qry) {
            while ($al = $alloc_qry->fetch_assoc()) {
                $allocations[] = $al;
            }
        }

        $sys_info = [
            'name' => $this->settings->info('name'),
            'email' => $this->settings->info('email')
        ];

        $send = Emailer::send_payment_receipt_email($email_to, $payment, $allocations, $sys_info);
        if ($send) {
            return json_encode(['status' => 'success', 'msg' => "Payment Receipt #{$payment['payment_no']} sent successfully to {$email_to}."]);
        } else {
            return json_encode(['status' => 'failed', 'msg' => 'Failed to send payment receipt email. Please check SMTP settings.']);
        }
    }

    /**
     * Fire an integration event through ModuleManager (webhooks + workflows).
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
}
