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

    /** Where a deal came from, for knowing which channels win business. */
    public const LEAD_SOURCES = [
        'referral' => 'Referral', 'website' => 'Website', 'walk_in' => 'Walk-in', 'social' => 'Social media',
        'cold_call' => 'Cold call / outreach', 'event' => 'Event / trade show', 'advert' => 'Advertising', 'other' => 'Other',
    ];

    /** Why a deal was lost. */
    public const LOST_REASONS = [
        'price' => 'Price too high', 'competitor' => 'Chose a competitor', 'no_budget' => 'No budget',
        'no_decision' => 'No decision made', 'timing' => 'Bad timing', 'no_response' => 'Stopped responding',
        'requirements' => "We couldn't meet the requirements", 'other' => 'Other',
    ];

    /** Pure: a posted lead source, or null when blank or unknown. */
    public static function cleanSource($raw): ?string
    {
        $k = trim((string)$raw);
        return isset(self::LEAD_SOURCES[$k]) ? $k : null;
    }

    /** Pure: a posted lost reason, or null when blank or unknown. */
    public static function cleanLostReason($raw): ?string
    {
        $k = trim((string)$raw);
        return isset(self::LOST_REASONS[$k]) ? $k : null;
    }

    /** Pure: why a stage change can't be saved, or null. A deal can only be marked lost with a reason. */
    public static function stageProblem(string $stage, ?string $lostReason): ?string
    {
        if (!in_array($stage, self::STAGES, true)) {
            return 'Unknown stage.';
        }
        if ($stage === 'lost' && $lostReason === null) {
            return 'Choose why the deal was lost.';
        }
        return null;
    }

    /** Pure: percentage of closed deals that were won, or null when none are closed. */
    public static function winRate(int $won, int $lost): ?float
    {
        return ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : null;
    }

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
        $leadSource = self::cleanSource($post['lead_source'] ?? '');
        $lostReason = self::cleanLostReason($post['lost_reason'] ?? '');
        $contactId = (int)($post['contact_id'] ?? 0);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        if (empty($title)) {
            return json_encode(['status' => 'failed', 'msg' => 'Opportunity title is required.']);
        }

        $stage = in_array(($stage ?? ''), self::STAGES, true) ? $stage : 'qualification';
        if ($problem = self::stageProblem($stage, $lostReason)) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        if ($stage !== 'lost') {
            $lostReason = null;
        }
        if ($contactId > 0) {
            $c = $this->conn->query("SELECT 1 FROM `crm_contacts` WHERE id = '{$contactId}' AND tenant_id = '" . (int)$tenant_id . "'");
            if (!$c || $c->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'That contact was not found.']);
            }
        }
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
            `lead_source` = " . ($leadSource === null ? 'NULL' : "'{$leadSource}'") . ",
            `lost_reason` = " . ($lostReason === null ? 'NULL' : "'{$lostReason}'") . ",
            `contact_id` = " . ($contactId > 0 ? (string)$contactId : 'NULL') . ",
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
    public function moveStage(int $id, string $stage, string $lostReason = ''): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $reason = self::cleanLostReason($lostReason);
        if ($problem = self::stageProblem($stage, $reason)) {
            return json_encode(['status' => 'failed', 'msg' => $problem]);
        }
        // Keep probability sensible when hitting a terminal stage.
        $set_prob = '';
        if ($stage === 'won') {
            $set_prob = ", `probability` = 100";
        } elseif ($stage === 'lost') {
            $set_prob = ", `probability` = 0";
        }
        $set_prob .= ", `lost_reason` = " . ($stage === 'lost' ? "'{$reason}'" : 'NULL');
        $ok = $this->conn->query("UPDATE `crm_opportunities` SET `stage` = '{$stage}'{$set_prob} WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($ok) {
            $this->settings->log_audit('Moved CRM Deal', 'CRM Opportunities', "Deal #{$id} -> {$stage}" . ($stage === 'lost' ? " ({$reason})" : ''));
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
     *  Contacts (the people at a customer)
     * =================================================================== */

    /** Pure: a contact's posted fields, cleaned. Returns [fields, error]. */
    public static function cleanContact(array $post): array
    {
        $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 150);
        if ($name === '') {
            return [[], "Enter the contact's name."];
        }
        $email = trim((string)($post['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], 'That email address does not look right.'];
        }
        return [[
            'name' => $name,
            'job_title' => mb_substr(trim((string)($post['job_title'] ?? '')), 0, 100),
            'email' => mb_substr($email, 0, 150),
            'phone' => mb_substr(trim((string)($post['phone'] ?? '')), 0, 50),
            'notes' => mb_substr(trim((string)($post['notes'] ?? '')), 0, 500),
            'is_primary' => !empty($post['is_primary']) ? 1 : 0,
        ], null];
    }

    public function saveContact(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        [$f, $err] = self::cleanContact($post);
        if ($err) {
            return json_encode(['status' => 'failed', 'msg' => $err]);
        }
        $partyId = (int)($post['party_id'] ?? 0);
        if ($partyId > 0) {
            $p = $this->conn->query("SELECT 1 FROM `party_list` WHERE id = '{$partyId}' AND tenant_id = '{$t}' AND delete_flag = 0");
            if (!$p || $p->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'That customer was not found.']);
            }
        }
        $e = fn($v) => $this->conn->real_escape_string((string)$v);
        $id = (int)($post['id'] ?? 0);
        $isNew = $id <= 0;
        $set = "name = '{$e($f['name'])}', job_title = '{$e($f['job_title'])}', email = '{$e($f['email'])}', phone = '{$e($f['phone'])}',
            notes = '{$e($f['notes'])}', is_primary = '{$f['is_primary']}', party_id = " . ($partyId > 0 ? $partyId : 'NULL');
        $this->conn->begin_transaction();
        try {
            if ($isNew) {
                $ok = $this->conn->query("INSERT INTO `crm_contacts` SET tenant_id = '{$t}', {$set}");
                $id = (int)$this->conn->insert_id;
            } else {
                $ok = $this->conn->query("UPDATE `crm_contacts` SET {$set} WHERE id = '{$id}' AND tenant_id = '{$t}'");
            }
            if (!$ok) {
                throw new \RuntimeException($this->conn->error);
            }
            if ($f['is_primary'] && $partyId > 0) {
                // One primary contact per customer.
                $this->conn->query("UPDATE `crm_contacts` SET is_primary = 0 WHERE tenant_id = '{$t}' AND party_id = '{$partyId}' AND id <> '{$id}'");
            }
            $this->conn->commit();
        } catch (\Throwable $ex) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The contact could not be saved.']);
        }
        $this->settings->log_audit($isNew ? 'Created CRM Contact' : 'Updated CRM Contact', 'CRM', "Contact {$f['name']} (#{$id}).");
        return json_encode(['status' => 'success', 'id' => $id, 'msg' => 'Contact saved.']);
    }

    public function deleteContact(int $id): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $c = $this->conn->query("SELECT name FROM `crm_contacts` WHERE id = '{$id}' AND tenant_id = '{$t}'");
        if (!$c || !($row = $c->fetch_assoc())) {
            return json_encode(['status' => 'failed', 'msg' => 'Contact not found.']);
        }
        $this->conn->query("UPDATE `crm_opportunities` SET contact_id = NULL WHERE contact_id = '{$id}' AND tenant_id = '{$t}'");
        $this->conn->query("DELETE FROM `crm_contacts` WHERE id = '{$id}' AND tenant_id = '{$t}'");
        $this->settings->log_audit('Deleted CRM Contact', 'CRM', "Contact {$row['name']} (#{$id}).");
        return json_encode(['status' => 'success', 'msg' => 'Contact deleted.']);
    }

    /** @return array<int,array<string,mixed>> */
    public function contacts(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $r = @$this->conn->query("SELECT c.*, p.name party_name FROM `crm_contacts` c LEFT JOIN `party_list` p ON p.id = c.party_id
            WHERE c.tenant_id = '{$t}' ORDER BY p.name IS NULL, p.name, c.is_primary DESC, c.name");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Deals by lead source: how many, how many won and lost, win rate and value won.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sourceReport(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $r = @$this->conn->query("SELECT COALESCE(lead_source, '') src, COUNT(*) n,
                SUM(stage = 'won') won, SUM(stage = 'lost') lost, COALESCE(SUM(CASE WHEN stage = 'won' THEN amount END), 0) won_value
            FROM `crm_opportunities` WHERE tenant_id = '{$t}' GROUP BY COALESCE(lead_source, '') ORDER BY n DESC");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = ['source' => $row['src'], 'label' => self::LEAD_SOURCES[$row['src']] ?? 'Not recorded', 'deals' => (int)$row['n'],
                'won' => (int)$row['won'], 'lost' => (int)$row['lost'], 'win_rate' => self::winRate((int)$row['won'], (int)$row['lost']),
                'won_value' => round((float)$row['won_value'], 2)];
        }
        return $out;
    }

    /** @return array<int,array{reason:string,label:string,deals:int,value:float}> */
    public function lostReasonReport(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $r = @$this->conn->query("SELECT COALESCE(lost_reason, '') reason, COUNT(*) n, COALESCE(SUM(amount), 0) v
            FROM `crm_opportunities` WHERE tenant_id = '{$t}' AND stage = 'lost' GROUP BY COALESCE(lost_reason, '') ORDER BY n DESC");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = ['reason' => $row['reason'], 'label' => self::LOST_REASONS[$row['reason']] ?? 'Not recorded', 'deals' => (int)$row['n'], 'value' => round((float)$row['v'], 2)];
        }
        return $out;
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
