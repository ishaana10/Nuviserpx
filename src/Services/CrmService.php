<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use mysqli;
use SystemSettings;

/**
 * CrmService — sales pipeline: opportunities, stage moves, follow-up activities,
 * and conversion of a won deal into an invoice.
 *
 * Stages: qualification -> proposal -> negotiation -> won | lost.
 */
class CrmService
{
    public const STAGES = ['qualification', 'proposal', 'negotiation', 'won', 'lost'];

    /** Default win probability suggested per stage (used when none is set). */
    public const STAGE_PROBABILITY = [
        'qualification' => 20,
        'proposal'      => 50,
        'negotiation'   => 75,
        'won'           => 100,
        'lost'          => 0,
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ===================================================================
     *  Opportunities
     * =================================================================== */

    public function saveOpportunity(array $post): string
    {
        $title = $post['title'] ?? null;
        $stage = $post['stage'] ?? null;
        $party_id = $post['party_id'] ?? null;
        $amount = $post['amount'] ?? null;
        $probability = $post['probability'] ?? null;
        $expected_close_date = $post['expected_close_date'] ?? null;
        $notes = $post['notes'] ?? null;
        $id = $post['id'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        if (empty($title)) {
            return json_encode(['status' => 'failed', 'msg' => 'Opportunity title is required.']);
        }

        $stage = in_array(($stage ?? ''), self::STAGES, true) ? $stage : 'qualification';
        $title_e = $this->conn->real_escape_string((string)$title);
        $party = !empty($party_id) ? (int)$party_id : 'NULL';
        $amount = !empty($amount) ? (float)$amount : 0.00;
        $probability = isset($probability) && $probability !== '' ? (int)$probability : self::STAGE_PROBABILITY[$stage];
        $probability = max(0, min(100, $probability));
        $close = !empty($expected_close_date) ? "'" . $this->conn->real_escape_string((string)$expected_close_date) . "'" : 'NULL';
        $notes_e = $this->conn->real_escape_string((string)($notes ?? ''));

        $data = "`tenant_id` = '{$tenant_id}',
            `title` = '{$title_e}',
            `party_id` = {$party},
            `stage` = '{$stage}',
            `amount` = '{$amount}',
            `probability` = '{$probability}',
            `expected_close_date` = {$close},
            `notes` = '{$notes_e}'";

        $target_id = !empty($id) ? (int)$id : 0;
        if ($target_id <= 0) {
            $save = $this->conn->query("INSERT INTO `crm_opportunities` SET {$data}, `created_by` = '{$user_id}'");
            $opp_id = $this->conn->insert_id;
        } else {
            $save = $this->conn->query("UPDATE `crm_opportunities` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            $opp_id = $target_id;
        }

        if (!$save) {
            return json_encode(['status' => 'failed', 'msg' => 'Failed to save opportunity. ' . $this->conn->error]);
        }
        $this->settings->log_audit($target_id ? 'Updated CRM Deal' : 'Created CRM Deal', 'CRM Opportunities', "Title: {$title}");
        return json_encode(['status' => 'success', 'id' => (int)$opp_id, 'msg' => 'Opportunity saved successfully.']);
    }

    /** Move an opportunity to a new stage (drag-and-drop on the board). */
    public function moveStage(int $id, string $stage): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if (!in_array($stage, self::STAGES, true)) {
            return json_encode(['status' => 'failed', 'msg' => 'Unknown stage.']);
        }
        // Keep probability sensible when hitting a terminal stage.
        $set_prob = '';
        if ($stage === 'won') {
            $set_prob = ", `probability` = 100";
        } elseif ($stage === 'lost') {
            $set_prob = ", `probability` = 0";
        }
        $ok = $this->conn->query("UPDATE `crm_opportunities` SET `stage` = '{$stage}'{$set_prob} WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($ok) {
            $this->settings->log_audit('Moved CRM Deal', 'CRM Opportunities', "Deal #{$id} -> {$stage}");
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => $this->conn->error]);
    }

    public function deleteOpportunity(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        // CASCADE removes activities.
        $del = $this->conn->query("DELETE FROM `crm_opportunities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->set_flashdata('success', ' Opportunity deleted.');
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'error' => $this->conn->error]);
    }

    /* ===================================================================
     *  Activities (follow-ups)
     * =================================================================== */

    public function saveActivity(array $post): string
    {
        $opportunity_id = $post['opportunity_id'] ?? null;
        $type = $post['type'] ?? null;
        $subject = $post['subject'] ?? null;
        $due_date = $post['due_date'] ?? null;
        $notes = $post['notes'] ?? null;
        $id = $post['id'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $opp_id = isset($opportunity_id) ? (int)$opportunity_id : 0;
        $type = in_array(($type ?? ''), ['call', 'meeting', 'email', 'task', 'note'], true) ? $type : 'task';
        $subject = $this->conn->real_escape_string((string)($subject ?? ''));
        if ($subject === '') {
            return json_encode(['status' => 'failed', 'msg' => 'A subject is required.']);
        }
        $due = !empty($due_date) ? "'" . $this->conn->real_escape_string((string)$due_date) . "'" : 'NULL';
        $notes_e = $this->conn->real_escape_string((string)($notes ?? ''));

        $opp = $this->conn->query("SELECT id FROM `crm_opportunities` WHERE id = '{$opp_id}' AND tenant_id = '{$tenant_id}'");
        if (!$opp || $opp->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Opportunity not found.']);
        }

        $id = !empty($id) ? (int)$id : 0;
        if ($id > 0) {
            $ok = $this->conn->query("UPDATE `crm_activities` SET `type` = '{$type}', `subject` = '{$subject}', `due_date` = {$due}, `notes` = '{$notes_e}' WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        } else {
            $ok = $this->conn->query("INSERT INTO `crm_activities` SET
                `tenant_id` = '{$tenant_id}',
                `opportunity_id` = '{$opp_id}',
                `type` = '{$type}',
                `subject` = '{$subject}',
                `due_date` = {$due},
                `notes` = '{$notes_e}',
                `created_by` = '{$user_id}'");
        }
        if ($ok) {
            return json_encode(['status' => 'success', 'msg' => 'Activity saved.']);
        }
        return json_encode(['status' => 'failed', 'msg' => $this->conn->error]);
    }

    public function toggleActivity(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $a = $this->conn->query("SELECT is_done FROM `crm_activities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$a || $a->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Activity not found.']);
        }
        $now_done = (int)$a->fetch_assoc()['is_done'] === 1;
        if ($now_done) {
            $this->conn->query("UPDATE `crm_activities` SET `is_done` = 0, `done_at` = NULL WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        } else {
            $this->conn->query("UPDATE `crm_activities` SET `is_done` = 1, `done_at` = NOW() WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        }
        return json_encode(['status' => 'success', 'is_done' => $now_done ? 0 : 1]);
    }

    public function deleteActivity(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("DELETE FROM `crm_activities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        return json_encode(['status' => $del ? 'success' : 'failed']);
    }

    /* ===================================================================
     *  Convert a won deal to an invoice
     * =================================================================== */

    public function convertToInvoice(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $opp = $this->conn->query("SELECT * FROM `crm_opportunities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$opp || $opp->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Opportunity not found.']);
        }
        $opp = $opp->fetch_assoc();

        if (empty($opp['party_id'])) {
            return json_encode(['status' => 'failed', 'msg' => 'Add a customer to this deal before converting it to an invoice.']);
        }
        if (!empty($opp['invoice_id'])) {
            return json_encode(['status' => 'failed', 'msg' => 'This deal has already been converted to invoice #' . (int)$opp['invoice_id'] . '.']);
        }
        if ((float)$opp['amount'] <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Set a deal value before converting it to an invoice.']);
        }

        // Build the invoice payload for InvoiceService (single line = the deal).
        $amount = (float)$opp['amount'];
        $payload = [
            'party_id'        => (int)$opp['party_id'],
            'invoice_date'    => date('Y-m-d'),
            'due_date'        => date('Y-m-d', strtotime('+30 days')),
            'subtotal'        => $amount,
            'tax_amount'      => 0.0,
            'discount'        => 0.0,
            'total'           => $amount,
            'notes'           => 'Converted from CRM opportunity: ' . $opp['title'],
            'item_name'       => [$opp['title']],
            'item_desc'       => [$opp['notes'] ?? ''],
            'item_qty'        => [1],
            'item_price'      => [$amount],
            'item_tax_rate'   => [0],
        ];

        $invoiceJson = Container::getInstance()->make(InvoiceService::class)->saveInvoice($payload);
        $invoiceResp = json_decode($invoiceJson, true);
        if (!is_array($invoiceResp) || ($invoiceResp['status'] ?? '') !== 'success') {
            return json_encode(['status' => 'failed', 'msg' => 'Could not create the invoice. ' . ($invoiceResp['msg'] ?? '')]);
        }

        // Find the invoice just created for this party (most recent) to link it back.
        $inv = $this->conn->query("SELECT id, invoice_no FROM `invoice_list` WHERE tenant_id = '{$tenant_id}' AND party_id = '" . (int)$opp['party_id'] . "' ORDER BY id DESC LIMIT 1");
        $inv_row = ($inv && $inv->num_rows) ? $inv->fetch_assoc() : null;
        if ($inv_row) {
            $inv_id = (int)$inv_row['id'];
            $this->conn->query("UPDATE `crm_opportunities` SET `invoice_id` = '{$inv_id}', `stage` = 'won', `probability` = 100 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        }

        $this->settings->set_flashdata('success', ' Deal converted to invoice ' . ($inv_row['invoice_no'] ?? '') . '.');
        return json_encode(['status' => 'success', 'invoice_id' => $inv_row ? (int)$inv_row['id'] : null, 'msg' => 'Converted to invoice.']);
    }

    /* ===================================================================
     *  Reporting helpers
     * =================================================================== */

    /**
     * Pipeline totals by stage with weighted (probability-adjusted) value.
     *
     * @return array<string,mixed>
     */
    public function pipelineSummary(): array
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $byStage = [];
        foreach (self::STAGES as $s) {
            $byStage[$s] = ['count' => 0, 'value' => 0.0, 'weighted' => 0.0];
        }
        $res = $this->conn->query("SELECT stage, COUNT(*) n, COALESCE(SUM(amount),0) v,
                COALESCE(SUM(amount * probability / 100),0) w
            FROM `crm_opportunities` WHERE tenant_id = '{$tenant_id}' GROUP BY stage");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $s = $r['stage'];
                if (!isset($byStage[$s])) {
                    continue;
                }
                $byStage[$s] = ['count' => (int)$r['n'], 'value' => round((float)$r['v'], 2), 'weighted' => round((float)$r['w'], 2)];
            }
        }

        // "Open" = everything except won/lost.
        $open_value = $open_weighted = 0.0;
        $open_count = 0;
        foreach (['qualification', 'proposal', 'negotiation'] as $s) {
            $open_value += $byStage[$s]['value'];
            $open_weighted += $byStage[$s]['weighted'];
            $open_count += $byStage[$s]['count'];
        }

        $won = $byStage['won'];
        $lost = $byStage['lost'];
        $closed = $won['count'] + $lost['count'];
        $win_rate = $closed > 0 ? round($won['count'] / $closed * 100, 1) : null;

        return [
            'by_stage'      => $byStage,
            'open_count'    => $open_count,
            'open_value'    => round($open_value, 2),
            'open_weighted' => round($open_weighted, 2),
            'won_value'     => $won['value'],
            'won_count'     => $won['count'],
            'lost_count'    => $lost['count'],
            'win_rate'      => $win_rate,
        ];
    }
}
