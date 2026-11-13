<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IntegrationEvents;
use mysqli;
use SystemSettings;

/**
 * CRUD backend for the Webhooks + Workflow admin UI.
 * Called from Master.php (classes/Master.php?f=...).
 */
class IntegrationService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------------------------------------ Webhooks */

    /** @param array<string,mixed> $post */
    public function saveWebhook(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = (int)$this->settings->userdata('id');
        $id = !empty($post['id']) ? (int)$post['id'] : 0;

        $name = $this->conn->real_escape_string(trim((string)($post['name'] ?? '')));
        $event = trim((string)($post['event'] ?? ''));
        $url = trim((string)($post['target_url'] ?? ''));
        $secret = trim((string)($post['secret_token'] ?? ''));
        $status = isset($post['status']) ? (int)$post['status'] : 1;
        $max_retries = isset($post['max_retries']) ? max(0, min(10, (int)$post['max_retries'])) : 5;

        if (!IntegrationEvents::exists($event)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please choose a valid event to subscribe to.']);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'http') !== 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Please enter a valid target URL (http/https).']);
        }
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }

        $event_esc = $this->conn->real_escape_string($event);
        $url_esc = $this->conn->real_escape_string($url);
        $secret_esc = $this->conn->real_escape_string($secret);

        if ($id === 0) {
            $sql = "INSERT INTO `webhooks` SET
                `tenant_id` = '{$tenant_id}', `name` = '{$name}', `event` = '{$event_esc}',
                `target_url` = '{$url_esc}', `secret_token` = '{$secret_esc}', `status` = '{$status}',
                `max_retries` = '{$max_retries}', `created_by` = '{$user_id}'";
        } else {
            $sql = "UPDATE `webhooks` SET
                `name` = '{$name}', `event` = '{$event_esc}', `target_url` = '{$url_esc}',
                `secret_token` = '{$secret_esc}', `status` = '{$status}', `max_retries` = '{$max_retries}'
                WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        if ($this->conn->query($sql)) {
            $this->settings->log_audit($id === 0 ? 'Created Webhook' : 'Updated Webhook', 'Integrations', "Event: {$event}");
            $this->settings->set_flashdata('success', $id === 0 ? ' Webhook created.' : ' Webhook updated.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn, 'Could not save webhook.');
    }

    public function deleteWebhook(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if ($this->conn->query("DELETE FROM `webhooks` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")) {
            $this->settings->set_flashdata('success', ' Webhook deleted.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    public function testWebhook(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $result = (new WebhookService($this->conn))->sendTest($id, $tenant_id);
        $msg = $result['outcome'] === 'delivered'
            ? 'Test delivery succeeded (2xx response).'
            : 'Test delivery did not succeed — check the delivery log for details.';
        return json_encode(['status' => $result['status'], 'msg' => $msg, 'outcome' => $result['outcome']]);
    }

    /* ----------------------------------------------------------- Workflows */

    /** @param array<string,mixed> $post */
    public function saveWorkflow(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = (int)$this->settings->userdata('id');
        $id = !empty($post['id']) ? (int)$post['id'] : 0;

        $name = $this->conn->real_escape_string(trim((string)($post['name'] ?? '')));
        $desc = $this->conn->real_escape_string(trim((string)($post['description'] ?? '')));
        $trigger = trim((string)($post['trigger_event'] ?? ''));
        $match_type = ($post['match_type'] ?? 'all') === 'any' ? 'any' : 'all';
        $status = isset($post['status']) ? (int)$post['status'] : 1;

        if ($name === '') {
            return json_encode(['status' => 'failed', 'msg' => 'Workflow name is required.']);
        }
        if (!IntegrationEvents::exists($trigger)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please choose a valid trigger event.']);
        }

        // conditions / actions arrive as JSON strings from the builder.
        $conditions = $this->validateJsonArray($post['conditions'] ?? '[]');
        $actions = $this->validateJsonArray($post['actions'] ?? '[]');
        if ($actions === null) {
            return json_encode(['status' => 'failed', 'msg' => 'Actions are malformed.']);
        }
        if (count($actions) === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Add at least one action.']);
        }

        $cond_esc = $this->conn->real_escape_string(json_encode($conditions ?: []));
        $act_esc = $this->conn->real_escape_string(json_encode($actions));
        $trigger_esc = $this->conn->real_escape_string($trigger);

        if ($id === 0) {
            $sql = "INSERT INTO `workflows` SET
                `tenant_id` = '{$tenant_id}', `name` = '{$name}', `description` = '{$desc}',
                `trigger_event` = '{$trigger_esc}', `conditions` = '{$cond_esc}', `actions` = '{$act_esc}',
                `match_type` = '{$match_type}', `status` = '{$status}', `created_by` = '{$user_id}'";
        } else {
            $sql = "UPDATE `workflows` SET
                `name` = '{$name}', `description` = '{$desc}', `trigger_event` = '{$trigger_esc}',
                `conditions` = '{$cond_esc}', `actions` = '{$act_esc}', `match_type` = '{$match_type}', `status` = '{$status}'
                WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        if ($this->conn->query($sql)) {
            $this->settings->log_audit($id === 0 ? 'Created Workflow' : 'Updated Workflow', 'Automation', "Name: {$name}");
            $this->settings->set_flashdata('success', $id === 0 ? ' Workflow created.' : ' Workflow updated.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn, 'Could not save workflow.');
    }

    public function deleteWorkflow(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if ($this->conn->query("DELETE FROM `workflows` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")) {
            $this->settings->set_flashdata('success', ' Workflow deleted.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    public function toggleWorkflow(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $this->conn->query("UPDATE `workflows` SET `status` = IF(`status` = 1, 0, 1) WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        return json_encode(['status' => 'success']);
    }

    /**
     * Validate an incoming JSON array string.
     * @return array<int,mixed>|null null when invalid JSON
     */
    private function validateJsonArray(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
}
