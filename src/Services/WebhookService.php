<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Outgoing webhook engine for Nuvis ERPX.
 *
 * Responsibilities:
 *  - enqueue(): match an event to active subscriptions and queue one delivery per subscription.
 *  - drainQueue(): pick up pending/retryable deliveries and attempt HTTP POST (cron worker).
 *  - attempt(): deliver a single row, applying exponential backoff + attempt accounting.
 *
 * Deliveries are signed with HMAC-SHA256 over the raw JSON body and sent with:
 *   X-ERPX-Event, X-ERPX-Signature, X-ERPX-Delivery headers.
 *
 * Delivery is asynchronous by default (queue + cron). If $inlineFallback is true and no
 * worker has drained the row quickly, callers may also invoke attempt() inline — but the
 * default path never blocks the user's request beyond the INSERT.
 */
class WebhookService
{
    /** Backoff schedule in seconds, indexed by attempt number (1-based). */
    private const BACKOFF = [0, 60, 300, 900, 3600, 21600]; // immediate, 1m, 5m, 15m, 1h, 6h

    public function __construct(private readonly mysqli $conn) {}

    /**
     * Queue deliveries for every active webhook subscribed to $event in $tenant_id.
     *
     * @param array<string,mixed> $payload
     * @return int number of deliveries queued
     */
    public function enqueue(string $event, array $payload, int $tenant_id): int
    {
        $event_esc = $this->conn->real_escape_string($event);
        $tenant_id = (int)$tenant_id;

        $hooks = $this->conn->query(
            "SELECT * FROM `webhooks` WHERE tenant_id = '{$tenant_id}' AND status = 1 AND event = '{$event_esc}'"
        );
        if (!$hooks || $hooks->num_rows === 0) {
            return 0;
        }

        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'tenant_id' => $tenant_id,
            'data'      => $payload,
        ], JSON_UNESCAPED_SLASHES);

        $queued = 0;
        while ($wh = $hooks->fetch_assoc()) {
            $secret = $wh['secret_token'] ?: 'nuvis';
            $signature = hash_hmac('sha256', (string)$body, $secret);
            $max_retries = isset($wh['max_retries']) ? (int)$wh['max_retries'] : 5;

            $stmt = $this->conn->prepare(
                "INSERT INTO `webhook_deliveries`
                    (`tenant_id`, `webhook_id`, `event`, `target_url`, `payload`, `signature`, `status`, `max_retries`, `next_attempt_at`)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
            );
            if ($stmt) {
                $wid = (int)$wh['id'];
                $url = $wh['target_url'];
                $stmt->bind_param('iissssi', $tenant_id, $wid, $event, $url, $body, $signature, $max_retries);
                if ($stmt->execute()) {
                    $queued++;
                }
                $stmt->close();
            }
        }

        return $queued;
    }

    /**
     * Drain the delivery queue. Intended to be called by cron/webhook_worker.php.
     *
     * @return array{processed:int, delivered:int, failed:int, retrying:int}
     */
    public function drainQueue(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stats = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'retrying' => 0];

        $rows = $this->conn->query(
            "SELECT * FROM `webhook_deliveries`
             WHERE `status` IN ('pending')
               AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())
             ORDER BY `id` ASC
             LIMIT {$limit}"
        );
        if (!$rows || $rows->num_rows === 0) {
            return $stats;
        }

        while ($row = $rows->fetch_assoc()) {
            $outcome = $this->attempt($row);
            $stats['processed']++;
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Attempt a single delivery row (as an associative array from webhook_deliveries).
     *
     * @param array<string,mixed> $row
     * @return string one of: delivered | failed | retrying
     */
    public function attempt(array $row): string
    {
        $id = (int)$row['id'];
        $attempts = (int)$row['attempts'] + 1;
        $max_retries = (int)($row['max_retries'] ?? 5);

        // Claim the row so parallel workers don't double-send.
        $this->conn->query("UPDATE `webhook_deliveries` SET `status` = 'delivering', `attempts` = '{$attempts}' WHERE id = '{$id}' AND status = 'pending'");
        if ($this->conn->affected_rows === 0) {
            return 'retrying'; // another worker claimed it
        }

        [$code, $respBody, $error] = $this->post(
            (string)$row['target_url'],
            (string)$row['payload'],
            (string)$row['event'],
            (string)$row['signature'],
            $id
        );

        $ok = ($code >= 200 && $code < 300);
        $respBody_esc = $this->conn->real_escape_string(mb_substr((string)$respBody, 0, 2000));
        $error_esc = $this->conn->real_escape_string(mb_substr((string)$error, 0, 1000));
        $code_val = $code > 0 ? (string)$code : 'NULL';

        if ($ok) {
            $this->conn->query(
                "UPDATE `webhook_deliveries` SET
                    `status` = 'delivered',
                    `response_code` = {$code_val},
                    `response_body` = '{$respBody_esc}',
                    `error` = NULL,
                    `delivered_at` = NOW()
                 WHERE id = '{$id}'"
            );
            $this->touchWebhook((int)$row['webhook_id'], 'delivered', false);
            return 'delivered';
        }

        // Failure: either schedule a retry or mark permanently failed.
        if ($attempts <= $max_retries) {
            $delay = self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)];
            $this->conn->query(
                "UPDATE `webhook_deliveries` SET
                    `status` = 'pending',
                    `response_code` = {$code_val},
                    `response_body` = '{$respBody_esc}',
                    `error` = '{$error_esc}',
                    `next_attempt_at` = DATE_ADD(NOW(), INTERVAL {$delay} SECOND)
                 WHERE id = '{$id}'"
            );
            $this->touchWebhook((int)$row['webhook_id'], 'retrying', true);
            return 'retrying';
        }

        $this->conn->query(
            "UPDATE `webhook_deliveries` SET
                `status` = 'failed',
                `response_code` = {$code_val},
                `response_body` = '{$respBody_esc}',
                `error` = '{$error_esc}'
             WHERE id = '{$id}'"
        );
        $this->touchWebhook((int)$row['webhook_id'], 'failed', true);
        return 'failed';
    }

    /**
     * Queue a delivery and attempt it immediately once (used by the "Test" button and
     * by synchronous callers). Returns the attempt outcome + the delivery id.
     *
     * @param array<string,mixed> $payload
     * @return array{status:string, delivery_id:int, outcome:string}
     */
    public function sendTest(int $webhook_id, int $tenant_id): array
    {
        $webhook_id = (int)$webhook_id;
        $tenant_id = (int)$tenant_id;
        $wh = $this->conn->query("SELECT * FROM `webhooks` WHERE id = '{$webhook_id}' AND tenant_id = '{$tenant_id}'");
        if (!$wh || $wh->num_rows === 0) {
            return ['status' => 'failed', 'delivery_id' => 0, 'outcome' => 'failed'];
        }
        $wh = $wh->fetch_assoc();

        $body = json_encode([
            'event'     => $wh['event'],
            'timestamp' => date('c'),
            'tenant_id' => $tenant_id,
            'test'      => true,
            'data'      => ['message' => 'This is a test delivery from Nuvis ERPX.'],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', (string)$body, $wh['secret_token'] ?: 'nuvis');

        $stmt = $this->conn->prepare(
            "INSERT INTO `webhook_deliveries`
                (`tenant_id`, `webhook_id`, `event`, `target_url`, `payload`, `signature`, `status`, `max_retries`, `next_attempt_at`)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, NOW())"
        );
        $event = $wh['event'];
        $url = $wh['target_url'];
        $stmt->bind_param('iissss', $tenant_id, $webhook_id, $event, $url, $body, $signature);
        $stmt->execute();
        $delivery_id = (int)$this->conn->insert_id;
        $stmt->close();

        $row = $this->conn->query("SELECT * FROM `webhook_deliveries` WHERE id = '{$delivery_id}'")->fetch_assoc();
        $outcome = $this->attempt($row);

        return [
            'status'      => $outcome === 'delivered' ? 'success' : 'failed',
            'delivery_id' => $delivery_id,
            'outcome'     => $outcome,
        ];
    }

    /**
     * Perform the HTTP POST.
     *
     * @return array{0:int,1:string,2:string} [http_code, response_body, error]
     */
    private function post(string $url, string $body, string $event, string $signature, int $deliveryId): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'cURL extension not available'];
        }
        $headers = [
            'Content-Type: application/json',
            'X-ERPX-Event: ' . $event,
            'X-ERPX-Signature: sha256=' . $signature,
            'X-ERPX-Delivery: ' . $deliveryId,
            'User-Agent: Nuvis-ERPX-Webhook-Engine/2.0',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $resp === false ? curl_error($ch) : '';
        curl_close($ch);

        return [$code, is_string($resp) ? $resp : '', $err];
    }

    /** Update the parent webhook's health counters. */
    private function touchWebhook(int $webhook_id, string $status, bool $isFailure): void
    {
        if ($webhook_id <= 0) {
            return;
        }
        $status_esc = $this->conn->real_escape_string($status);
        $fail = $isFailure ? '`failure_count` = `failure_count` + 1,' : '`failure_count` = 0,';
        $this->conn->query(
            "UPDATE `webhooks` SET
                {$fail}
                `last_status` = '{$status_esc}',
                `last_triggered_at` = NOW()
             WHERE id = '{$webhook_id}'"
        );
    }
}
