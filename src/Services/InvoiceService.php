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

        // Calculate paid_amount and balance
        $paid_amount = 0.0;
        if (!empty($id)) {
            $curr_inv = $this->conn->query("SELECT paid_amount FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
            $paid_amount = isset($curr_inv['paid_amount']) ? (float)$curr_inv['paid_amount'] : 0.0;
        }
        $balance = max(0.0, $total - $paid_amount);
        if ($paid_amount >= $total && $total > 0) {
            $status = InvoiceStatus::PAID->value;
        } elseif ($paid_amount > 0 && $paid_amount < $total) {
            $status = InvoiceStatus::PARTIALLY_PAID->value;
        }

        // Accounts for Journal Entry (Accounts Receivable Dr, Sales Revenue Cr)
        $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

        $rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
        $rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

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
        $journal_date = $invoice_date;
        $partyName = $party['name'] ?? '';
        $description = $this->conn->real_escape_string("Sales Invoice {$invoice_no} to {$partyName} - Total: \${$total}");

        if (empty($id)) {
            $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
            $this->conn->query($j_sql);
            $journal_id = $this->conn->insert_id;

            $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$ar_acc_id}', '1', '{$total}'),
				('{$journal_id}', '{$rev_acc_id}', '2', '{$total}')";
            $this->conn->query($j_items);

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
				`notes` = '{$notes}',
				`journal_id` = '{$journal_id}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $invoice_id = $this->conn->insert_id;
        } else {
            $existing = $this->conn->query("SELECT journal_id FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
            $journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
            if ($journal_id) {
                $this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
                $this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
                $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$ar_acc_id}', '1', '{$total}'),
					('{$journal_id}', '{$rev_acc_id}', '2', '{$total}')";
                $this->conn->query($j_items);
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

            // Auto-enqueue for FRCS VMS Fiscalization if enabled
            try {
                $vmsService = new VmsService($this->conn, $this->settings);
                $vmsConfig = $vmsService->getConfig($tenant_id);
                if (!empty($vmsConfig['auto_fiscalize'])) {
                    $vmsService->enqueueInvoice((int)$invoice_id, $tenant_id);
                    if (!empty($vmsConfig['is_sandbox'])) {
                        $vmsService->processQueue($tenant_id);
                    }
                }
            } catch (\Throwable $t) {
                // Ignore VMS auto-enqueue error so invoice save is not interrupted
            }
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving invoice.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function deleteInvoice(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();

        $inv = $this->conn->query("SELECT journal_id FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if (!empty($inv['journal_id'])) {
            $this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$inv['journal_id']}'");
        }

        $del = $this->conn->query("DELETE FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Invoice deleted successfully.");
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
}
