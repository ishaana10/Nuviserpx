<?php

declare(strict_types=1);

namespace App\Services;

use Emailer;
use mysqli;

/**
 * Customisable workflow / automation engine.
 *
 * A workflow row stores:
 *   trigger_event  — one of App\Support\IntegrationEvents
 *   match_type     — 'all' (AND) or 'any' (OR)
 *   conditions     — JSON array of {field, operator, value}
 *   actions        — JSON array of {type, ...params}, run in order
 *
 * On each fired event, run() loads active workflows for that tenant+event, evaluates
 * conditions against the event payload, and executes the actions of every matching rule.
 * Each run is recorded in `workflow_logs`.
 *
 * Supported condition operators: equals, not_equals, greater_than, less_than,
 *   contains, starts_with, is_empty, is_not_empty, changed_to.
 * Supported action types: send_email, call_webhook, create_ticket, log.
 *
 * Action parameters support {{field}} placeholders resolved from the event payload.
 */
class WorkflowService
{
    public function __construct(private readonly mysqli $conn) {}

    /**
     * Run all matching workflows for an event.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array{workflow_id:int,matched:bool,result:string}>
     */
    public function run(string $event, array $payload, int $tenant_id): array
    {
        $event_esc = $this->conn->real_escape_string($event);
        $tenant_id = (int)$tenant_id;
        $results = [];

        $rows = $this->conn->query(
            "SELECT * FROM `workflows`
             WHERE tenant_id = '{$tenant_id}' AND status = 1 AND trigger_event = '{$event_esc}'
             ORDER BY id ASC"
        );
        if (!$rows || $rows->num_rows === 0) {
            return $results;
        }

        while ($wf = $rows->fetch_assoc()) {
            $conditions = $this->decodeJson($wf['conditions']);
            $actions = $this->decodeJson($wf['actions']);
            $matchType = ($wf['match_type'] ?? 'all') === 'any' ? 'any' : 'all';

            $matched = $this->evaluate($conditions, $payload, $matchType);

            if (!$matched) {
                $this->log((int)$wf['id'], $tenant_id, $event, false, [], 'skipped', 'Conditions not met', $payload);
                $results[] = ['workflow_id' => (int)$wf['id'], 'matched' => false, 'result' => 'skipped'];
                continue;
            }

            $ran = [];
            $failures = 0;
            foreach ($actions as $action) {
                $type = $action['type'] ?? '';
                try {
                    $ok = $this->runAction($type, $action, $payload, $tenant_id);
                    $ran[] = $type . ($ok ? ':ok' : ':fail');
                    if (!$ok) {
                        $failures++;
                    }
                } catch (\Throwable $e) {
                    $ran[] = $type . ':error';
                    $failures++;
                }
            }

            $result = $failures === 0 ? 'success' : ($failures < count($ran) ? 'partial' : 'failed');
            $this->log((int)$wf['id'], $tenant_id, $event, true, $ran, $result, 'Executed ' . count($ran) . ' action(s)', $payload);

            $this->conn->query("UPDATE `workflows` SET `run_count` = `run_count` + 1, `last_run_at` = NOW() WHERE id = '{$wf['id']}'");
            $results[] = ['workflow_id' => (int)$wf['id'], 'matched' => true, 'result' => $result];
        }

        return $results;
    }

    /**
     * @param array<int,array<string,mixed>> $conditions
     * @param array<string,mixed> $payload
     */
    private function evaluate(array $conditions, array $payload, string $matchType): bool
    {
        if (empty($conditions)) {
            return true; // no conditions = always run
        }

        $passed = 0;
        foreach ($conditions as $c) {
            if ($this->evaluateOne($c, $payload)) {
                $passed++;
                if ($matchType === 'any') {
                    return true;
                }
            } elseif ($matchType === 'all') {
                return false;
            }
        }

        return $matchType === 'all' ? ($passed === count($conditions)) : ($passed > 0);
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $payload */
    private function evaluateOne(array $c, array $payload): bool
    {
        $field = $c['field'] ?? '';
        $op = $c['operator'] ?? 'equals';
        $expected = $c['value'] ?? '';
        $actual = $payload[$field] ?? null;

        switch ($op) {
            case 'equals':        return $this->looseEq($actual, $expected);
            case 'not_equals':    return !$this->looseEq($actual, $expected);
            case 'greater_than':  return is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected;
            case 'less_than':     return is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected;
            case 'contains':      return $actual !== null && stripos((string)$actual, (string)$expected) !== false;
            case 'starts_with':   return $actual !== null && stripos((string)$actual, (string)$expected) === 0;
            case 'is_empty':      return $actual === null || $actual === '' || $actual === 0 || $actual === '0';
            case 'is_not_empty':  return !($actual === null || $actual === '' || $actual === 0 || $actual === '0');
            case 'changed_to':    return $this->looseEq($actual, $expected); // payload reflects new state
            default:              return false;
        }
    }

    private function looseEq(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }
        return strcasecmp(trim((string)$a), trim((string)$b)) === 0;
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $payload
     */
    private function runAction(string $type, array $action, array $payload, int $tenant_id): bool
    {
        switch ($type) {
            case 'send_email':
                return $this->actionSendEmail($action, $payload, $tenant_id);
            case 'call_webhook':
                return $this->actionCallWebhook($action, $payload, $tenant_id);
            case 'create_ticket':
                return $this->actionCreateTicket($action, $payload, $tenant_id);
            case 'log':
                return true; // logging handled by the run log itself
            default:
                return false;
        }
    }

    /** @param array<string,mixed> $action @param array<string,mixed> $payload */
    private function actionSendEmail(array $action, array $payload, int $tenant_id): bool
    {
        require_once(base_app . 'classes/Emailer.php');
        $to = $this->resolve($action['to'] ?? '', $payload);
        if (empty($to) && !empty($payload['party_email'])) {
            $to = (string)$payload['party_email'];
        }
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $subject = $this->resolve($action['subject'] ?? 'Notification from Nuvis ERPX', $payload);
        $body = nl2br(htmlspecialchars($this->resolve($action['body'] ?? '', $payload)));
        $html = "<div style='font-family:sans-serif;font-size:14px;color:#1e293b'>{$body}</div>";
        return (bool)Emailer::send_email($to, $subject, $html);
    }

    /** @param array<string,mixed> $action @param array<string,mixed> $payload */
    private function actionCallWebhook(array $action, array $payload, int $tenant_id): bool
    {
        $url = $this->resolve($action['url'] ?? '', $payload);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        $body = json_encode(['source' => 'workflow', 'tenant_id' => $tenant_id, 'data' => $payload], JSON_UNESCAPED_SLASHES);
        $secret = (string)($action['secret'] ?? 'nuvis');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-ERPX-Signature: sha256=' . hash_hmac('sha256', (string)$body, $secret),
                'User-Agent: Nuvis-ERPX-Workflow/1.0',
            ],
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    /** @param array<string,mixed> $action @param array<string,mixed> $payload */
    private function actionCreateTicket(array $action, array $payload, int $tenant_id): bool
    {
        $subject = $this->resolve($action['subject'] ?? 'Automated ticket', $payload);
        $subject = $this->conn->real_escape_string(mb_substr($subject, 0, 240));
        $priority = in_array(($action['priority'] ?? 'medium'), ['low', 'medium', 'high', 'urgent'], true)
            ? $action['priority'] : 'medium';
        $party_id = isset($payload['party_id']) ? (int)$payload['party_id'] : 0;
        $party_sql = $party_id > 0 ? "'{$party_id}'" : 'NULL';
        $code = 'TKT-' . date('Ym') . '-' . random_int(1000, 9999);

        $ok = $this->conn->query(
            "INSERT INTO `tickets` (`tenant_id`, `ticket_code`, `subject`, `party_id`, `priority`, `status`)
             VALUES ('{$tenant_id}', '{$code}', '{$subject}', {$party_sql}, '{$priority}', 'new')"
        );
        return (bool)$ok;
    }

    /** Resolve {{field}} placeholders from payload. @param array<string,mixed> $payload */
    private function resolve(string $template, array $payload): string
    {
        return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($payload) {
            return isset($payload[$m[1]]) ? (string)$payload[$m[1]] : '';
        }, $template);
    }

    /** @return array<int,array<string,mixed>> */
    private function decodeJson(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,string> $actionsRun
     * @param array<string,mixed> $payload
     */
    private function log(int $workflow_id, int $tenant_id, string $event, bool $matched, array $actionsRun, string $result, string $message, array $payload): void
    {
        $event_esc = $this->conn->real_escape_string($event);
        $result_esc = $this->conn->real_escape_string($result);
        $msg_esc = $this->conn->real_escape_string(mb_substr($message, 0, 1000));
        $actions_esc = $this->conn->real_escape_string(implode(', ', $actionsRun));
        $ref = $payload['invoice_no'] ?? ($payload['payment_no'] ?? ($payload['invoice_id'] ?? ''));
        $ref_esc = $this->conn->real_escape_string(mb_substr((string)$ref, 0, 90));
        $matched_i = $matched ? 1 : 0;

        @$this->conn->query(
            "INSERT INTO `workflow_logs`
                (`tenant_id`, `workflow_id`, `trigger_event`, `matched`, `actions_run`, `result`, `message`, `context_ref`)
             VALUES ('{$tenant_id}', '{$workflow_id}', '{$event_esc}', '{$matched_i}', '{$actions_esc}', '{$result_esc}', '{$msg_esc}', '{$ref_esc}')"
        );
    }
}
