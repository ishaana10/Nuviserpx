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
        $currency = !empty($currency) ? $this->conn->real_escape_string((string)$currency) : 'FJD';

        // Get party details
        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
        }
        $party = $party_qry->fetch_assoc();

        // Accounts for Journal Entry (Cash/Bank Dr, Accounts Receivable Cr)
        $cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $cash_acc_id = !empty($bank_account_id) ? $bank_account_id : (($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1);

        $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

        // Create/Update Journal
        $prefix = date("Ym-");
        $code = sprintf("%'.05d", 1);
        while (true) {
            $check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
            if ($check > 0) {
                $code = sprintf("%'.05d", ceil((float)$code) + 1);
            } else {
                break;
            }
        }
        $journal_code = $prefix . $code;
        $journal_date = $payment_date;
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
				`currency` = '{$currency}'
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

                $inv_data = $this->conn->query("SELECT total FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
                $inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0.0;

                $new_bal = max(0.0, $inv_total - $tot_paid);
                $new_status = 'unpaid';
                if ($tot_paid >= $inv_total && $inv_total > 0) {
                    $new_status = 'paid';
                } elseif ($tot_paid > 0 && $tot_paid < $inv_total) {
                    $new_status = 'partially_paid';
                }

                $this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");
            }

            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Payment Receipt created and allocated successfully." : " Payment details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving payment.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function deletePayment(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();

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

                $inv_data = $this->conn->query("SELECT total FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
                $inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0.0;

                $new_bal = max(0.0, $inv_total - $tot_paid);
                $new_status = 'unpaid';
                if ($tot_paid >= $inv_total && $inv_total > 0) {
                    $new_status = 'paid';
                } elseif ($tot_paid > 0 && $tot_paid < $inv_total) {
                    $new_status = 'partially_paid';
                }

                $this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");
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
}
