<?php

declare(strict_types=1);

namespace App\Services;

/** Contracts and proposal signatures. Moved verbatim out of Master. */
class ContractService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function save_contract()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $title = $_POST['title'] ?? null;
        $party_id = $_POST['party_id'] ?? null;
        $amount = $_POST['amount'] ?? null;
        $id = $_POST['id'] ?? null;
        $billing_frequency = $_POST['billing_frequency'] ?? null;
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? null;
        $status = $_POST['status'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $tax_rate = $_POST['tax_rate'] ?? null;
        $tax_type = $_POST['tax_type'] ?? null;

        if (empty($title) || empty($party_id) || empty($amount)) {
            return json_encode(['status' => 'failed', 'msg' => 'Title, Customer, and Amount are required.']);
        }

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $c_no = \App\Services\SequenceService::documentNo($this->conn, (int)$tenant_id, 'contracts', 'contract_no', 'CTR-' . date('Ym') . '-', 'contract-' . date('Ym'));
            $data = " `tenant_id` = '{$tenant_id}' ";
            $data .= ", `contract_no` = '{$c_no}' ";
            $data .= ", `title` = '{$this->conn->real_escape_string($title)}' ";
            $data .= ", `party_id` = '" . intval($party_id) . "' ";
            $data .= ", `billing_frequency` = '{$this->conn->real_escape_string($billing_frequency ?? 'monthly')}' ";
            $data .= ", `amount` = '" . floatval($amount) . "' ";
            $data .= ", `start_date` = '{$this->conn->real_escape_string($start_date)}' ";
            $data .= ", `end_date` = " . (!empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL") . " ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'active')}' ";
            $data .= ", `notes` = '{$this->conn->real_escape_string($notes ?? '')}' ";
            $data .= ", `tax_rate` = '" . max(0, min(100, floatval($tax_rate ?? 0))) . "' ";
            $data .= ", `tax_type` = '" . (in_array($tax_type ?? '', ['exclusive', 'inclusive'], true) ? $tax_type : 'exclusive') . "' ";

            $save = $this->conn->query("INSERT INTO `contracts` SET {$data}");
            if ($save) {
                $contract_id = $this->conn->insert_id;
                $this->settings->log_audit("Created Contract", "Contracts", "Contract #{$c_no}: {$title}");
                return json_encode(['status' => 'success', 'id' => $contract_id, 'msg' => 'Contract created successfully.']);
            }
        } else {
            $data = " `title` = '{$this->conn->real_escape_string($title)}' ";
            $data .= ", `party_id` = '" . intval($party_id) . "' ";
            $data .= ", `billing_frequency` = '{$this->conn->real_escape_string($billing_frequency ?? 'monthly')}' ";
            $data .= ", `amount` = '" . floatval($amount) . "' ";
            $data .= ", `start_date` = '{$this->conn->real_escape_string($start_date)}' ";
            $data .= ", `end_date` = " . (!empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL") . " ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'active')}' ";
            $data .= ", `notes` = '{$this->conn->real_escape_string($notes ?? '')}' ";
            $data .= ", `tax_rate` = '" . max(0, min(100, floatval($tax_rate ?? 0))) . "' ";
            $data .= ", `tax_type` = '" . (in_array($tax_type ?? '', ['exclusive', 'inclusive'], true) ? $tax_type : 'exclusive') . "' ";

            $save = $this->conn->query("UPDATE `contracts` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            if ($save) {
                return json_encode(['status' => 'success', 'id' => $target_id, 'msg' => 'Contract updated successfully.']);
            }
        }
        return \App\Support\Db::fail($this->conn, 'Failed to save contract.');
    }

    /* Dolibarr Inspired Warehouse Methods */

    public function save_proposal_signature()
    {
        $tenant_id = $_POST['tenant_id'] ?? null;
        $sales_order_id = $_POST['sales_order_id'] ?? null;
        $signer_name = $_POST['signer_name'] ?? null;
        $signer_email = $_POST['signer_email'] ?? null;
        $signature_data = $_POST['signature_data'] ?? null;
        $t_id = !empty($tenant_id) ? intval($tenant_id) : $this->settings->active_tenant_id();
        $so_id = intval($sales_order_id ?? 0);

        if ($so_id <= 0 || empty($signer_name) || empty($signer_email) || empty($signature_data)) {
            return json_encode(['status' => 'failed', 'msg' => 'Signer Name, Email, and Signature drawing are required.']);
        }

        // The quote itself decides the company, and only an open, current, unexpired quote can be accepted.
        $quoteRow = $this->conn->query("SELECT tenant_id, status, type, valid_until FROM `sales_order_list` WHERE id = '{$so_id}'")->fetch_assoc();
        if (!$quoteRow || !\App\Services\QuotationService::isQuote((string)$quoteRow['type'])) {
            return json_encode(['status' => 'failed', 'msg' => 'This proposal could not be found.']);
        }
        $t_id = (int)$quoteRow['tenant_id'];
        if ($quoteRow['status'] === 'confirmed' || $quoteRow['status'] === 'converted_to_invoice') {
            return json_encode(['status' => 'failed', 'msg' => 'This proposal has already been accepted.']);
        }
        if ($problem = \App\Services\QuotationService::acceptProblem($quoteRow, date('Y-m-d'))) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $s_name = $this->conn->real_escape_string($signer_name);
        $s_email = $this->conn->real_escape_string($signer_email);
        $sig_data = $this->conn->real_escape_string($signature_data);

        $save = $this->conn->query("
            INSERT INTO `proposal_signatures`
            (`tenant_id`, `sales_order_id`, `signer_name`, `signer_email`, `signature_data`, `ip_address`, `status`)
            VALUES ('{$t_id}', '{$so_id}', '{$s_name}', '{$s_email}', '{$sig_data}', '{$ip}', 'accepted')
        ");

        if ($save) {
            // Update sales order status to confirmed
            $this->conn->query("UPDATE `sales_order_list` SET `status` = 'confirmed' WHERE id = '{$so_id}'");
            $this->settings->log_audit("Signed Commercial Proposal", "Customer Portal", "Quote ID #{$so_id} signed by {$signer_name}");
            return json_encode(['status' => 'success', 'msg' => 'Proposal signed and confirmed successfully.']);
        }
        return \App\Support\Db::fail($this->conn, 'Failed to save digital signature.');
    }

    /* Dolibarr Inspired Helpdesk Ticket Methods */

}
