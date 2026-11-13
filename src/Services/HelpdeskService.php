<?php

declare(strict_types=1);

namespace App\Services;

/** Helpdesk tickets: create/update and staff replies, with SLA transitions. Moved verbatim out of Master. */
class HelpdeskService
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

    public function save_ticket()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $subject = $_POST['subject'] ?? null;
        $id = $_POST['id'] ?? null;
        $party_id = $_POST['party_id'] ?? null;
        $priority = $_POST['priority'] ?? null;
        $status = $_POST['status'] ?? null;
        $assigned_to = $_POST['assigned_to'] ?? null;
        $initial_message = $_POST['initial_message'] ?? null;

        if (empty($subject)) {
            return json_encode(['status' => 'failed', 'msg' => 'Ticket Subject is required.']);
        }

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $code = \App\Services\SequenceService::documentNo($this->conn, (int)$tenant_id, 'tickets', 'ticket_code', 'TCK-' . date('Ym') . '-', 'ticket-' . date('Ym'));
            $data = " `tenant_id` = '{$tenant_id}' ";
            $data .= ", `ticket_code` = '{$code}' ";
            $data .= ", `subject` = '{$this->conn->real_escape_string($subject)}' ";
            $data .= ", `party_id` = " . (!empty($party_id) ? intval($party_id) : "NULL") . " ";
            $data .= ", `priority` = '{$this->conn->real_escape_string($priority ?? 'medium')}' ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'new')}' ";
            $data .= ", `assigned_to` = " . (!empty($assigned_to) ? intval($assigned_to) : "NULL") . " ";
            $data .= ", `created_by` = '{$user_id}' ";

            $save = $this->conn->query("INSERT INTO `tickets` SET {$data}");
            if ($save) {
                $ticket_id = $this->conn->insert_id;
                if (!empty($initial_message)) {
                    $msg = $this->conn->real_escape_string($initial_message);
                    $this->conn->query("INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `message`) VALUES ('{$ticket_id}', '{$user_id}', '{$msg}')");
                }
                $this->settings->log_audit("Created Ticket", "Helpdesk", "Ticket #{$code}: {$subject}");
                return json_encode(['status' => 'success', 'id' => $ticket_id, 'msg' => 'Ticket created successfully.']);
            }
        } else {
            $data = " `subject` = '{$this->conn->real_escape_string($subject)}' ";
            $data .= ", `party_id` = " . (!empty($party_id) ? intval($party_id) : "NULL") . " ";
            $data .= ", `priority` = '{$this->conn->real_escape_string($priority ?? 'medium')}' ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'new')}' ";
            $data .= ", `assigned_to` = " . (!empty($assigned_to) ? intval($assigned_to) : "NULL") . " ";

            $newStatus = in_array((string)($status ?? 'new'), ['new', 'in_progress', 'waiting_client', 'resolved', 'closed'], true) ? (string)($status ?? 'new') : 'new';
            $this->container->make(\App\Services\TicketSlaService::class)->applyStatus($target_id, $newStatus);
            $save = $this->conn->query("UPDATE `tickets` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            if ($save) {
                return json_encode(['status' => 'success', 'id' => $target_id, 'msg' => 'Ticket updated successfully.']);
            }
        }
        return \App\Support\Db::fail($this->conn, 'Failed to save ticket.');
    }

    public function reply_ticket()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $ticket_id = $_POST['ticket_id'] ?? null;
        $message = $_POST['message'] ?? null;
        $status = $_POST['status'] ?? null;

        $t_id = intval($ticket_id ?? 0);
        if ($t_id <= 0 || empty($message)) {
            return json_encode(['status' => 'failed', 'msg' => 'Message content is required.']);
        }

        // The ticket has to belong to this company.
        $own = $this->conn->query("SELECT 1 FROM `tickets` WHERE id = '{$t_id}' AND tenant_id = '{$tenant_id}'");
        if (!$own || $own->num_rows === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Ticket not found.']);
        }
        $msg = $this->conn->real_escape_string($message);
        $save = $this->conn->query("INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `message`) VALUES ('{$t_id}', '{$user_id}', '{$msg}')");

        if ($save) {
            $sla = $this->container->make(\App\Services\TicketSlaService::class);
            $sla->markFirstResponse($t_id);
            if (!empty($status) && in_array((string)$status, ['new', 'in_progress', 'waiting_client', 'resolved', 'closed'], true)) {
                $sla->applyStatus($t_id, (string)$status);
                $st = $this->conn->real_escape_string($status);
                $this->conn->query("UPDATE `tickets` SET `status` = '{$st}' WHERE id = '{$t_id}' AND tenant_id = '{$tenant_id}'");
            }
            return json_encode(['status' => 'success', 'msg' => 'Reply posted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to post reply.']);
    }

    /* Dolibarr Inspired Contract Methods */

}
