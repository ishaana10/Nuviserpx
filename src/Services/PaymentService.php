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
        $id = $post['id'] ?? null;
        $type = $post['type'] ?? null;
        $party_id = $post['party_id'] ?? null;
        $bank_account_id = $post['bank_account_id'] ?? null;
        $payment_date = $post['payment_date'] ?? null;
        $amount = $post['amount'] ?? null;
        $method = $post['method'] ?? null;
        $reference = $post['reference'] ?? null;
        $notes = $post['notes'] ?? null;
        $status = $post['status'] ?? null;
        $currency = $post['currency'] ?? null;
        $invoice_alloc = $post['invoice_alloc'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $payment_no = '';
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
        // A company-currency receipt cannot go into a foreign-currency bank account: the account tracks its own currency.
        if (($bankCur = $fx->bankCurrency((int)($bank_account_id ?? 0))) !== null) {
            return json_encode(['status' => 'failed', 'msg' => "That bank account holds {$bankCur}. Record the receipt in {$bankCur}, or choose a company-currency account."]);
        }
        // A company-currency receipt cannot settle a foreign-currency invoice: its balance is tracked in that currency.
        if (isset($invoice_alloc) && is_array($invoice_alloc) && $invoice_alloc) {
            $ids = implode(',', array_map('intval', array_keys($invoice_alloc)));
            $chk = $this->conn->query("SELECT invoice_no, currency FROM `invoice_list` WHERE id IN ({$ids}) AND tenant_id = '{$tenant_id}' AND currency IS NOT NULL LIMIT 1");
            if ($chk && ($fcInv = $chk->fetch_assoc())) {
                return json_encode(['status' => 'failed', 'msg' => "Invoice {$fcInv['invoice_no']} is in {$fcInv['currency']}. Record the receipt in {$fcInv['currency']} so the exchange difference is booked correctly."]);
            }
        }

        // A receipt is money in from a customer; supplier payments are made from Bill Payments.
        if ($type !== 'receipt') {
            return json_encode(['status' => 'failed', 'msg' => 'Only customer receipts can be recorded here. Pay suppliers from Bill Payments.']);
        }
        if ($amount <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter the amount received.']);
        }
        $payId = (int)$id;
        if ($problem = $this->existingReceiptProblem($payId, (int)$tenant_id)) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        [$payment_no, $noProblem] = $this->receiptNumber((string)($post['payment_no'] ?? ''), $payId, (int)$tenant_id);
        if ($noProblem) {
            return json_encode(['status' => 'failed', 'msg' => $noProblem]);
        }

        // Accounts: the company's chosen defaults; a receipt is never posted to a guessed account.
        $defaults = new AccountDefaultsService($this->conn, $this->settings);
        $ar_acc_id = $defaults->resolve('receivable');
        if ($ar_acc_id <= 0) {
            return json_encode(['status' => 'failed', 'msg' => AccountDefaultsService::missing('receivable')]);
        }
        if ($bank_account_id) {
            $bk = $this->conn->query("SELECT 1 FROM `account_list` WHERE id = '" . (int)$bank_account_id . "' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
            if (!$bk || $bk->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'Choose a bank or cash account from this company.']);
            }
        }
        $cash_acc_id = $bank_account_id ?: $defaults->resolve('cash');
        if ($cash_acc_id <= 0) {
            return json_encode(['status' => 'failed', 'msg' => AccountDefaultsService::missing('cash')]);
        }

        // Allocations: only this customer's open invoices, never more than each has left or than was received.
        $alloc = [];
        foreach ((array)($post['invoice_alloc'] ?? []) as $invId => $amt) {
            $amt = round((float)$amt, 2);
            if ($amt > 0) {
                $alloc[(int)$invId] = $amt;
            }
        }
        if ($problems = self::allocationProblems($amount, $alloc, $this->allocationRoom(array_keys($alloc), $payId, (int)$tenant_id), (int)$party_id)) {
            return json_encode(['status' => 'failed', 'msg' => implode(' ', $problems)]);
        }

        $journal_date = $payment_date;
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, (int)$tenant_id, $journal_date, 'payment_list', $payId)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }
        $partyName = $party['name'] ?? '';
        $description = $this->conn->real_escape_string("Payment Receipt {$payment_no} from {$partyName} - Amount: " . number_format($amount, 2));
        // A receipt: Dr cash/bank, Cr receivable.
        $lines = [[(int)$cash_acc_id, TaxService::DEBIT, round($amount, 2)], [(int)$ar_acc_id, TaxService::CREDIT, round($amount, 2)]];
        $tax = new TaxService($this->conn, $this->settings);

        // Journal, receipt and allocations are one unit: all saved or none.
        $affected = [];
        $this->conn->begin_transaction();
        try {
            $journal_id = 0;
            $trail = null;
            $trailBefore = null;
            if ($payId > 0) {
                $journal_id = (int)($this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$payId}' AND tenant_id = '{$tenant_id}'")->fetch_assoc()['journal_id'] ?? 0);
            }
            if ($journal_id > 0) {
                $trail = new JournalTrail($this->conn, $this->settings);
                $trailBefore = $trail->snapshot($journal_id);
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}' AND tenant_id = '{$tenant_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
            } else {
                $journal_code = \App\Services\SequenceService::journalCode($this->conn, (int)$tenant_id);
                $this->conn->query("INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')");
                $journal_id = (int)$this->conn->insert_id;
            }
            if ($journal_id <= 0 || !$tax->writeLines($journal_id, $lines)) {
                throw new \RuntimeException('The receipt journal could not be posted.');
            }
            if ($trail !== null) {
                $trail->record($journal_id, 'payment_list', $payId, "Receipt {$payment_no}", $trailBefore);
            }

            $bank_account_sql = $bank_account_id ? "'" . (int)$bank_account_id . "'" : 'NULL';
            $set = "`payment_no` = '{$payment_no}', `type` = 'receipt', `party_id` = '" . (int)$party_id . "', `bank_account_id` = {$bank_account_sql},
                `payment_date` = '{$payment_date}', `amount` = '{$amount}', `method` = '{$method}', `reference` = '{$reference}', `notes` = '{$notes}',
                `status` = 'posted', `currency` = '{$currency}', `amount_fc` = NULL, `fx_rate` = NULL, `journal_id` = '{$journal_id}'";
            if ($payId > 0) {
                $ok = $this->conn->query("UPDATE `payment_list` SET {$set} WHERE id = '{$payId}' AND tenant_id = '{$tenant_id}'");
                $old = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$payId}'");
                while ($old && ($oa = $old->fetch_assoc())) {
                    $affected[] = (int)$oa['invoice_id'];
                }
                $this->conn->query("DELETE FROM `payment_allocations` WHERE payment_id = '{$payId}'");
            } else {
                $ok = $this->conn->query("INSERT INTO `payment_list` SET `tenant_id` = '{$tenant_id}', {$set}, `created_by` = '{$user_id}'");
                $payId = (int)$this->conn->insert_id;
            }
            if (!$ok || $payId <= 0) {
                throw new \RuntimeException($this->conn->error ?: 'The receipt could not be saved.');
            }
            foreach ($alloc as $invId => $amt) {
                $affected[] = $invId;
                if (!$this->conn->query("INSERT INTO `payment_allocations` SET `payment_id` = '{$payId}', `invoice_id` = '{$invId}', `amount` = '" . number_format($amt, 2, '.', '') . "'")) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            foreach (array_unique($affected) as $invId) {
                $this->refreshInvoice((int)$invId);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The receipt could not be saved. Nothing was changed.']);
        }

        // Tell integrations about invoices that are now paid or part paid, and about a new receipt.
        foreach (array_unique($affected) as $inv_id) {
            $row = $this->conn->query("SELECT i.invoice_no, i.total, i.paid_amount, i.balance, i.status, p.name AS party_name, p.email AS party_email FROM `invoice_list` i INNER JOIN `party_list` p ON i.party_id = p.id WHERE i.id = '" . (int)$inv_id . "' AND i.tenant_id = '{$tenant_id}'");
            $row = $row ? $row->fetch_assoc() : null;
            if ($row && in_array($row['status'], ['paid', 'partially_paid'], true)) {
                $this->emit($row['status'] === 'paid' ? \App\Support\IntegrationEvents::INVOICE_PAID : \App\Support\IntegrationEvents::INVOICE_PARTIALLY_PAID, [
                    'invoice_id'  => (int)$inv_id,
                    'invoice_no'  => $row['invoice_no'],
                    'party_id'    => (int)$party_id,
                    'party_name'  => $row['party_name'] ?? '',
                    'party_email' => $row['party_email'] ?? '',
                    'total'       => (float)$row['total'],
                    'paid_amount' => (float)$row['paid_amount'],
                    'balance'     => (float)$row['balance'],
                    'status'      => $row['status'],
                ], (int)$tenant_id);
            }
        }
        if (empty($id)) {
            $this->emit(\App\Support\IntegrationEvents::PAYMENT_RECEIVED, [
                'payment_id'  => (int)$payId,
                'payment_no'  => $payment_no,
                'party_id'    => (int)$party_id,
                'party_name'  => $party['name'] ?? '',
                'party_email' => $party['email'] ?? '',
                'amount'      => (float)$amount,
                'method'      => $method,
                'payment_date'=> $payment_date,
            ], (int)$tenant_id);
        }

        $resp = ['status' => 'success', 'id' => (int)$payId];
        $resp['msg'] = empty($id) ? " Payment Receipt created and allocated successfully." : " Payment details updated successfully.";
        $this->settings->set_flashdata('success', $resp['msg']);
        return json_encode($resp);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Problems with how a receipt is allocated across invoices; empty when fine. Pure.
     *
     * @param array<int,float> $alloc invoice id => amount
     * @param array<int,array{party_id:int,status:string,invoice_no:string,available:float}> $room what each invoice can still take
     * @return string[]
     */
    public static function allocationProblems(float $received, array $alloc, array $room, int $partyId): array
    {
        $errors = [];
        $sum = 0.0;
        foreach ($alloc as $invId => $amt) {
            $r = $room[$invId] ?? null;
            if ($r === null || (int)$r['party_id'] !== $partyId) {
                $errors[] = 'One of the invoices could not be found for this customer.';
                continue;
            }
            if (in_array($r['status'], ['cancelled', 'draft'], true)) {
                $errors[] = "Invoice {$r['invoice_no']} is {$r['status']} and cannot be paid.";
                continue;
            }
            if ($amt > (float)$r['available'] + 0.005) {
                $errors[] = "Invoice {$r['invoice_no']} has only " . number_format(max(0.0, (float)$r['available']), 2) . ' left to pay.';
            }
            $sum += $amt;
        }
        if ($sum > $received + 0.005) {
            $errors[] = 'More has been allocated to invoices than was received.';
        }
        return array_values(array_unique($errors));
    }

    /**
     * What each invoice can still take: total, less credit notes, less what other receipts have paid.
     *
     * @param  int[] $ids
     * @return array<int,array{party_id:int,status:string,invoice_no:string,available:float}>
     */
    private function allocationRoom(array $ids, int $excludePaymentId, int $tenantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $res = $this->conn->query("SELECT i.id, i.party_id, i.status, i.invoice_no, i.total, i.credited_amount,
                COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.invoice_id = i.id AND a.payment_id <> {$excludePaymentId}), 0) other
            FROM invoice_list i WHERE i.tenant_id = {$tenantId} AND i.id IN ({$in})");
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $out[(int)$r['id']] = [
                'party_id' => (int)$r['party_id'],
                'status' => (string)$r['status'],
                'invoice_no' => (string)$r['invoice_no'],
                'available' => round((float)$r['total'] - (float)$r['credited_amount'] - (float)$r['other'], 2),
            ];
        }
        return $out;
    }

    /** Why an existing receipt cannot be saved over (not found, or cancelled); null for a new one. */
    private function existingReceiptProblem(int $payId, int $tenantId): ?string
    {
        if ($payId <= 0) {
            return null;
        }
        $row = $this->conn->query("SELECT status FROM `payment_list` WHERE id = '{$payId}' AND tenant_id = '{$tenantId}'")->fetch_assoc();
        if (!$row) {
            return 'Receipt not found.';
        }
        return $row['status'] === 'cancelled' ? 'A cancelled receipt cannot be changed. Record a new one.' : null;
    }

    /**
     * The receipt number to use: the next in the company's series when none is given, else the one typed, which
     * must not already belong to another receipt.
     *
     * @return array{0:string,1:?string} [number, problem]
     */
    private function receiptNumber(string $given, int $payId, int $tenantId): array
    {
        $given = trim($given);
        if ($given === '') {
            return [SequenceService::documentNo($this->conn, $tenantId, 'payment_list', 'payment_no', 'REC-' . date('Ym') . '-', 'receipt-' . date('Ym')), null];
        }
        $esc = $this->conn->real_escape_string($given);
        $dup = $this->conn->query("SELECT 1 FROM `payment_list` WHERE tenant_id = '{$tenantId}' AND payment_no = '{$esc}' AND id <> '{$payId}' LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            return [$esc, "Receipt number {$given} is already used. Leave it blank to take the next number."];
        }
        return [$esc, null];
    }

    /**
     * A receipt in a foreign currency. `amount` and the allocations keep base values (cash at the receipt-date rate,
     * receivables at the invoice's carried value), with the foreign amounts beside them. The difference is the
     * realised exchange gain or loss, posted in the same journal.
     */
    private function saveForeignReceipt(array $post, FxService $fx, string $currency, string $id, int $tenant_id, int $user_id, array $party): string
    {
        $party_id = (int)($post['party_id'] ?? 0);
        $bank_account_id = !empty($post['bank_account_id']) ? (int)$post['bank_account_id'] : null;
        $payment_date = !empty($post['payment_date']) ? $this->conn->real_escape_string((string)$post['payment_date']) : date('Y-m-d');
        $amountFc = round((float)($post['amount'] ?? 0), 2);
        $method = !empty($post['method']) ? $this->conn->real_escape_string((string)$post['method']) : 'cash';
        $reference = $this->conn->real_escape_string((string)($post['reference'] ?? ''));
        $notes = $this->conn->real_escape_string((string)($post['notes'] ?? ''));
        $status = PaymentStatus::POSTED->value; // a receipt is either posted or cancelled (cancelling is its own action)
        $fail = static fn(string $m): string => json_encode(['status' => 'failed', 'msg' => $m]);

        if ($amountFc <= 0) {
            return $fail('Enter the amount received.');
        }
        $rate = $fx->documentRate($currency, $payment_date, $post['fx_rate'] ?? null);
        if ($rate === null) {
            return $fail("No exchange rate for {$currency} on {$payment_date}. Add one under Exchange rates, or type the rate on the receipt.");
        }
        $payId = (int)$id;
        if ($problem = $this->existingReceiptProblem($payId, $tenant_id)) {
            return $fail($problem);
        }
        [$payment_no, $noProblem] = $this->receiptNumber((string)($post['payment_no'] ?? ''), $payId, $tenant_id);
        if ($noProblem) {
            return $fail($noProblem);
        }
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
                $q = $this->conn->prepare("SELECT invoice_no, currency, fx_rate, total, total_fc, credited_fc, credited_amount, status FROM `invoice_list` WHERE id = ? AND tenant_id = ? AND party_id = ? FOR UPDATE");
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
                $remFc = round((float)$inv['total_fc'] - (float)$inv['credited_fc'] - (float)$other['fc'], 2);
                $remBase = round((float)$inv['total'] - (float)$inv['credited_amount'] - (float)$other['base'], 2);
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
            $defaults = new AccountDefaultsService($this->conn, $this->settings);
            $arAcc = $defaults->resolve('receivable');
            if ($arAcc <= 0) {
                throw new \RuntimeException(AccountDefaultsService::missing('receivable'));
            }
            if ($bank_account_id) {
                $bk = $this->conn->query("SELECT 1 FROM `account_list` WHERE id = '" . (int)$bank_account_id . "' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
                if (!$bk || $bk->num_rows === 0) {
                    throw new \RuntimeException('Choose a bank or cash account from this company.');
                }
            }
            $cashAcc = $bank_account_id ?: $defaults->resolve('cash');
            if ($cashAcc <= 0) {
                throw new \RuntimeException(AccountDefaultsService::missing('cash'));
            }
            $fxAcc = 0;
            if (round($cashBase - $arTotal, 2) != 0.0) {
                $fxAcc = $fx->realisedAccount();
                if ($fxAcc === 0) {
                    throw new \RuntimeException('The ' . FxService::REALISED_ACCOUNT . ' account could not be created.');
                }
            }
            $bankCur = $fx->bankCurrency((int)$cashAcc);
            if ($bankCur !== null && $bankCur !== $currency) {
                throw new \RuntimeException("That bank account holds {$bankCur}, not {$currency}. Choose an account in {$currency} or in the company currency.");
            }
            $lines = FxService::receiptLines($cashAcc, $arAcc, $fxAcc, $cashBase, $arTotal, $currency, $rate, $amountFc, $bankCur === $currency);

            // Journal
            $desc = $this->conn->real_escape_string("Payment Receipt {$payment_no} from " . ($party['name'] ?? '') . " - {$currency} " . number_format($amountFc, 2) . " @ {$rate}");
            $tax = new TaxService($this->conn, $this->settings);
            $journalId = 0;
            if ($payId > 0) {
                $e = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$payId}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
                $journalId = (int)($e['journal_id'] ?? 0);
            }
            $trail = new JournalTrail($this->conn, $this->settings);
            $trailBefore = $journalId > 0 ? $trail->snapshot($journalId) : null;
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
            if ($trailBefore !== null) {
                $trail->record($journalId, 'payment_list', $payId, "Receipt {$payment_no}", $trailBefore);
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

    /**
     * "Deleting" a receipt cancels it: its journal is reversed (both stay in the ledger), its allocations are
     * released so the invoices owe the money again, and the receipt stays on record marked cancelled.
     */
    public function deletePayment(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $pay = $this->conn->query("SELECT journal_id, status, payment_no, payment_date FROM `payment_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!$pay) {
            return json_encode(['status' => 'failed', 'msg' => 'Receipt not found.']);
        }
        if ($pay['status'] === 'cancelled') {
            return json_encode(['status' => 'success', 'msg' => ' Receipt is already cancelled.']);
        }
        if ($lockMsg = \App\Services\PeriodLockService::guardDocument($this->conn, $tenant_id, null, 'payment_list', $id)) {
            return json_encode(['status' => 'failed', 'msg' => $lockMsg]);
        }

        if (!empty($pay['journal_id'])) {
            $today = date('Y-m-d');
            $date = $today < (string)$pay['payment_date'] ? (string)$pay['payment_date'] : $today;
            $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$pay['journal_id'], $date, true), true);
            if (($res['status'] ?? '') !== 'success') {
                return json_encode(['status' => 'failed', 'msg' => 'The receipt could not be reversed: ' . ($res['msg'] ?? 'unknown error')]);
            }
        }

        $affected = [];
        $old = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$id}'");
        while ($old && ($oa = $old->fetch_assoc())) {
            $affected[] = (int)$oa['invoice_id'];
        }
        $this->conn->begin_transaction();
        try {
            if (!$this->conn->query("DELETE FROM `payment_allocations` WHERE payment_id = '{$id}'")
                || !$this->conn->query("UPDATE `payment_list` SET `status` = 'cancelled' WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")) {
                throw new \RuntimeException($this->conn->error);
            }
            foreach (array_unique($affected) as $invId) {
                $this->refreshInvoice($invId);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The receipt was reversed in the ledger but its invoices could not be updated. Please contact support before trying again.']);
        }
        $this->settings->log_audit('Cancelled receipt', 'Payments', (string)$pay['payment_no']);
        $this->settings->set_flashdata('success', ' Receipt cancelled and reversed.');
        return json_encode(['status' => 'success', 'msg' => ' Receipt cancelled and reversed.']);
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
