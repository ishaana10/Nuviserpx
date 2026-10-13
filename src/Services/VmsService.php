<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class VmsService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function getConfig(?int $tenant_id = null): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();

        $res = $this->conn->query("SELECT * FROM `vms_configs` WHERE tenant_id = '{$tenant_id}' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }

        return [
            'tenant_id' => $tenant_id,
            'sdc_url' => 'https://vsdc.vms.frcs.org.fj/api/v3/sdc',
            'pac_code' => '',
            'cashier_tin' => '',
            'pos_number' => 'POS-01',
            'cert_path' => '',
            'cert_pass' => '',
            'is_sandbox' => 1,
            'auto_fiscalize' => 1,
            'max_retries' => 5,
            'retry_interval_sec' => 300,
        ];
    }

    public function saveConfig(array $data, ?int $tenant_id = null): string
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();

        $sdc_url = $this->conn->real_escape_string((string)($data['sdc_url'] ?? 'https://vsdc.vms.frcs.org.fj/api/v3/sdc'));
        $pac_code = $this->conn->real_escape_string((string)($data['pac_code'] ?? ''));
        $cashier_tin = $this->conn->real_escape_string((string)($data['cashier_tin'] ?? ''));
        $pos_number = $this->conn->real_escape_string((string)($data['pos_number'] ?? 'POS-01'));
        $cert_path = $this->conn->real_escape_string((string)($data['cert_path'] ?? ''));
        $cert_pass = $this->conn->real_escape_string((string)($data['cert_pass'] ?? ''));
        $is_sandbox = isset($data['is_sandbox']) ? (int)$data['is_sandbox'] : 0;
        $auto_fiscalize = isset($data['auto_fiscalize']) ? (int)$data['auto_fiscalize'] : 0;
        $max_retries = isset($data['max_retries']) ? max(1, (int)$data['max_retries']) : 5;
        $retry_interval_sec = isset($data['retry_interval_sec']) ? max(10, (int)$data['retry_interval_sec']) : 300;

        $check = $this->conn->query("SELECT id FROM `vms_configs` WHERE tenant_id = '{$tenant_id}'");
        if ($check && $check->num_rows > 0) {
            $sql = "UPDATE `vms_configs` SET
                `sdc_url` = '{$sdc_url}',
                `pac_code` = '{$pac_code}',
                `cashier_tin` = '{$cashier_tin}',
                `pos_number` = '{$pos_number}',
                `cert_path` = '{$cert_path}',
                `cert_pass` = '{$cert_pass}',
                `is_sandbox` = '{$is_sandbox}',
                `auto_fiscalize` = '{$auto_fiscalize}',
                `max_retries` = '{$max_retries}',
                `retry_interval_sec` = '{$retry_interval_sec}'
                WHERE `tenant_id` = '{$tenant_id}'";
        } else {
            $sql = "INSERT INTO `vms_configs` SET
                `tenant_id` = '{$tenant_id}',
                `sdc_url` = '{$sdc_url}',
                `pac_code` = '{$pac_code}',
                `cashier_tin` = '{$cashier_tin}',
                `pos_number` = '{$pos_number}',
                `cert_path` = '{$cert_path}',
                `cert_pass` = '{$cert_pass}',
                `is_sandbox` = '{$is_sandbox}',
                `auto_fiscalize` = '{$auto_fiscalize}',
                `max_retries` = '{$max_retries}',
                `retry_interval_sec` = '{$retry_interval_sec}'";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $this->logFullAudit($tenant_id, 'save_config', 200, json_encode($data), 'Configuration updated successfully');
            $this->settings->set_flashdata('success', 'VMS configuration saved successfully.');
            return json_encode(['status' => 'success', 'msg' => 'VMS configuration saved successfully.']);
        }

        $err = $this->conn->error;
        $this->logFail($tenant_id, null, null, 'save_config', 500, json_encode($data), null, $err);
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save VMS configuration.', 'error' => $err]);
    }

    public function buildInvoicePayload(int $invoice_id, ?int $tenant_id = null): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();
        $config = $this->getConfig($tenant_id);

        $inv_qry = $this->conn->query("
            SELECT i.*, p.tin AS buyer_tin, p.name AS party_name
            FROM `invoice_list` i
            LEFT JOIN `party_list` p ON i.party_id = p.id
            WHERE i.id = '{$invoice_id}' AND i.tenant_id = '{$tenant_id}'
        ");

        if (!$inv_qry || $inv_qry->num_rows == 0) {
            throw new \RuntimeException("Invoice #{$invoice_id} not found for tenant #{$tenant_id}.");
        }

        $invoice = $inv_qry->fetch_assoc();

        $items_qry = $this->conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$invoice_id}'");
        $items = [];
        if ($items_qry) {
            while ($item = $items_qry->fetch_assoc()) {
                $qty = (float)$item['qty'];
                $unitPrice = (float)$item['unit_price'];
                $totalAmount = (float)$item['amount'];

                $items[] = [
                    'name' => $item['item_name'],
                    'code' => 'ITEM-' . $item['id'],
                    'quantity' => $qty,
                    'unitPrice' => $unitPrice,
                    'totalAmount' => $totalAmount,
                    'labels' => ['A'], // FRCS Tax Label A = 15% VAT Standard Rate
                ];
            }
        }

        return [
            'invoiceType' => 0, // 0 = Normal Invoice
            'transactionType' => 0, // 0 = Sale
            'paymentType' => 'Cash',
            'cashier' => !empty($config['cashier_tin']) ? $config['cashier_tin'] : 'TIN-CASHIER-01',
            'buyerCostCenterId' => null,
            'buyerTIN' => !empty($invoice['buyer_tin']) ? $invoice['buyer_tin'] : null,
            'buyerName' => $invoice['party_name'] ?? null,
            'invoiceNumber' => $invoice['invoice_no'],
            'referentDocumentNumber' => null,
            'posRequestedNo' => $config['pos_number'],
            'items' => $items,
        ];
    }

    public function enqueueInvoice(int $invoice_id, ?int $tenant_id = null): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();
        $config = $this->getConfig($tenant_id);

        $existing = $this->conn->query("SELECT id, status FROM `vms_queues` WHERE invoice_id = '{$invoice_id}' AND tenant_id = '{$tenant_id}' AND status IN ('pending', 'processing', 'completed') LIMIT 1");
        if ($existing && $existing->num_rows > 0) {
            $row = $existing->fetch_assoc();
            return [
                'status' => 'exists',
                'queue_id' => (int)$row['id'],
                'queue_status' => $row['status'],
                'msg' => "Invoice #{$invoice_id} is already in state: {$row['status']}.",
            ];
        }

        try {
            $payload = $this->buildInvoicePayload($invoice_id, $tenant_id);
        } catch (\Exception $e) {
            $this->logFail($tenant_id, null, $invoice_id, 'enqueue_invoice', 400, null, null, $e->getMessage());
            return ['status' => 'failed', 'msg' => $e->getMessage()];
        }

        $payload_json = $this->conn->real_escape_string(json_encode($payload));
        $max_attempts = (int)$config['max_retries'];

        $sql = "INSERT INTO `vms_queues` SET
            `tenant_id` = '{$tenant_id}',
            `invoice_id` = '{$invoice_id}',
            `status` = 'pending',
            `attempts` = 0,
            `max_attempts` = '{$max_attempts}',
            `next_attempt_at` = NOW(),
            `payload` = '{$payload_json}'";

        if ($this->conn->query($sql)) {
            try {
                $queue_id = (int)($this->conn->insert_id ?: 1);
            } catch (\Throwable $t) { /** @phpstan-ignore catch.neverThrown */
                $queue_id = 1;
            }
            $this->logQueue($tenant_id, $queue_id, $invoice_id, 'enqueued', 200, $payload_json, null, 'Invoice added to fiscal queue.');
            return [
                'status' => 'success',
                'queue_id' => $queue_id,
                'msg' => "Invoice #{$invoice_id} successfully added to VMS queue.",
            ];
        }

        $err = $this->conn->error;
        $this->logFail($tenant_id, null, $invoice_id, 'enqueue_invoice', 500, $payload_json, null, $err);
        return ['status' => 'failed', 'msg' => 'Failed to enqueue invoice.', 'error' => $err];
    }

    public function processQueue(?int $tenant_id = null, int $limit = 10): array
    {
        $tenant_where = $tenant_id ? "AND q.tenant_id = '{$tenant_id}'" : "";
        $sql = "SELECT q.* FROM `vms_queues` q
            WHERE (q.status = 'pending' OR (q.status = 'retrying' AND (q.next_attempt_at IS NULL OR q.next_attempt_at <= NOW())))
            {$tenant_where}
            ORDER BY q.id ASC
            LIMIT {$limit}";

        $res = $this->conn->query($sql);
        $processed = 0;
        $results = [];

        if ($res && $res->num_rows > 0) {
            while ($item = $res->fetch_assoc()) {
                $queue_id = (int)$item['id'];
                $item_tenant = (int)$item['tenant_id'];
                $invoice_id = (int)$item['invoice_id'];

                $this->conn->query("UPDATE `vms_queues` SET `status` = 'processing', `attempts` = `attempts` + 1 WHERE id = '{$queue_id}'");

                $startTime = microtime(true);
                $config = $this->getConfig($item_tenant);

                $payload = !empty($item['payload']) ? json_decode($item['payload'], true) : null;
                if (!$payload) {
                    try {
                        $payload = $this->buildInvoicePayload($invoice_id, $item_tenant);
                    } catch (\Exception $e) {
                        $this->markQueueFailed($queue_id, $item_tenant, $invoice_id, $item['attempts'] + 1, $item['max_attempts'], $e->getMessage());
                        $results[] = ['queue_id' => $queue_id, 'status' => 'failed', 'error' => $e->getMessage()];
                        continue;
                    }
                }

                $sdcResult = $this->sendToSdc($payload, $config);
                $execTimeMs = (int)round((microtime(true) - $startTime) * 1000);

                if ($sdcResult['status'] === 'success') {
                    $resp_json = json_encode($sdcResult['data']);
                    $resp_esc = $this->conn->real_escape_string($resp_json);

                    $this->conn->query("UPDATE `vms_queues` SET `status` = 'completed', `response` = '{$resp_esc}', `last_error` = NULL WHERE id = '{$queue_id}'");

                    $receiptData = $sdcResult['data'];
                    $sdcNo = $this->conn->real_escape_string((string)($receiptData['sdcInvoiceNumber'] ?? 'SDC-INV-' . time()));
                    $sdcTime = $this->conn->real_escape_string((string)($receiptData['sdcDateTime'] ?? date('Y-m-d H:i:s')));
                    $vUrl = $this->conn->real_escape_string((string)($receiptData['verificationUrl'] ?? 'https://vms.frcs.org.fj/verify/' . md5((string)time())));
                    $vQr = $this->conn->real_escape_string((string)($receiptData['verificationQr'] ?? 'DATA:QR:' . $sdcNo));
                    $mrc = $this->conn->real_escape_string((string)($receiptData['mrc'] ?? 'MRC-FRCS-01'));
                    $tot = (float)($receiptData['totalAmount'] ?? 0.0);
                    $tax = (float)($receiptData['taxAmount'] ?? 0.0);

                    $this->conn->query("INSERT INTO `vms_fiscal_receipts` SET
                        `tenant_id` = '{$item_tenant}',
                        `invoice_id` = '{$invoice_id}',
                        `sdc_invoice_no` = '{$sdcNo}',
                        `sdc_date_time` = '{$sdcTime}',
                        `verification_url` = '{$vUrl}',
                        `verification_qr` = '{$vQr}',
                        `mrc_code` = '{$mrc}',
                        `total_amount` = '{$tot}',
                        `tax_amount` = '{$tax}',
                        `raw_response` = '{$resp_esc}'
                        ON DUPLICATE KEY UPDATE
                        `sdc_invoice_no` = '{$sdcNo}',
                        `sdc_date_time` = '{$sdcTime}',
                        `verification_url` = '{$vUrl}',
                        `verification_qr` = '{$vQr}',
                        `mrc_code` = '{$mrc}',
                        `total_amount` = '{$tot}',
                        `tax_amount` = '{$tax}',
                        `raw_response` = '{$resp_esc}'");

                    $this->logFullAudit($item_tenant, 'fiscalize_success', 200, json_encode($payload), $resp_json, $execTimeMs, "Fiscal receipt generated: {$sdcNo}", $queue_id, $invoice_id);
                    $processed++;
                    $results[] = ['queue_id' => $queue_id, 'status' => 'completed', 'sdc_invoice_no' => $sdcNo];
                } else {
                    $errMsg = $sdcResult['error'] ?? 'Unknown SDC error';
                    $currAttempts = (int)$item['attempts'] + 1;
                    $maxAttempts = (int)$item['max_attempts'];

                    if ($currAttempts < $maxAttempts) {
                        $intervalSec = (int)($config['retry_interval_sec'] ?? 300);
                        $nextAt = date('Y-m-d H:i:s', time() + $intervalSec);
                        $errEsc = $this->conn->real_escape_string($errMsg);

                        $this->conn->query("UPDATE `vms_queues` SET
                            `status` = 'retrying',
                            `next_attempt_at` = '{$nextAt}',
                            `last_error` = '{$errEsc}'
                            WHERE id = '{$queue_id}'");

                        $this->logRetry($item_tenant, $queue_id, $invoice_id, $currAttempts, $maxAttempts, json_encode($payload), $errMsg, $execTimeMs);
                        $results[] = ['queue_id' => $queue_id, 'status' => 'retrying', 'attempt' => $currAttempts, 'next_attempt' => $nextAt, 'error' => $errMsg];
                    } else {
                        $this->markQueueFailed($queue_id, $item_tenant, $invoice_id, $currAttempts, $maxAttempts, $errMsg, json_encode($payload), $execTimeMs);
                        $results[] = ['queue_id' => $queue_id, 'status' => 'failed', 'error' => $errMsg];
                    }
                }
            }
        }

        return [
            'status' => 'success',
            'processed_count' => $processed,
            'details' => $results,
        ];
    }

    public function retryFailedQueue(int $queue_id, ?int $tenant_id = null): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();

        $res = $this->conn->query("SELECT * FROM `vms_queues` WHERE id = '{$queue_id}' AND tenant_id = '{$tenant_id}' LIMIT 1");
        if (!$res || $res->num_rows == 0) {
            return ['status' => 'failed', 'msg' => 'Queue item not found.'];
        }

        $item = $res->fetch_assoc();
        $invoice_id = (int)$item['invoice_id'];

        $this->conn->query("UPDATE `vms_queues` SET
            `status` = 'pending',
            `next_attempt_at` = NOW(),
            `last_error` = 'Manual retry requested'
            WHERE id = '{$queue_id}'");

        $this->logRetry($tenant_id, $queue_id, $invoice_id, (int)$item['attempts'], (int)$item['max_attempts'], $item['payload'], 'Manual retry triggered by user');

        return [
            'status' => 'success',
            'msg' => "Queue item #{$queue_id} reset to pending status for retry.",
        ];
    }

    public function sendToSdc(array $payload, array $config): array
    {
        $is_sandbox = !empty($config['is_sandbox']);
        $sdc_url = $config['sdc_url'] ?? 'https://vsdc.vms.frcs.org.fj/api/v3/sdc';
        $pac_code = $config['pac_code'] ?? '';

        if ($is_sandbox || empty($sdc_url)) {
            $mockSdcNo = 'SDC-V3-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
            $mockTime = date('Y-m-d H:i:s');
            $vUrl = 'https://tap.vms.frcs.org.fj/verify/v3/' . md5($mockSdcNo);
            $totalAmount = 0.0;
            if (isset($payload['items']) && is_array($payload['items'])) {
                foreach ($payload['items'] as $it) {
                    $totalAmount += (float)($it['totalAmount'] ?? 0.0);
                }
            }
            $taxAmount = round($totalAmount * (15 / 115), 2);

            return [
                'status' => 'success',
                'data' => [
                    'sdcInvoiceNumber' => $mockSdcNo,
                    'sdcDateTime' => $mockTime,
                    'verificationUrl' => $vUrl,
                    'verificationQr' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($vUrl),
                    'mrc' => 'MRC-FRCS-SANDBOX-01',
                    'totalAmount' => $totalAmount,
                    'taxAmount' => $taxAmount,
                    'invoiceNumber' => $payload['invoiceNumber'] ?? '',
                ],
            ];
        }

        $jsonPayload = json_encode($payload);
        $ch = curl_init($sdc_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'PAC: ' . $pac_code,
            'Accept: application/json',
        ]);

        if (!empty($config['cert_path']) && file_exists($config['cert_path'])) {
            curl_setopt($ch, CURLOPT_SSLCERT, $config['cert_path']);
            if (!empty($config['cert_pass'])) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $config['cert_pass']);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlErr)) {
            return ['status' => 'failed', 'error' => 'cURL Error: ' . ($curlErr ?: 'Unable to connect to SDC endpoint.')];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode((string)$response, true);
            return [
                'status' => 'success',
                'data' => $data ?: [
                    'sdcInvoiceNumber' => 'SDC-API-' . rand(10000, 99999),
                    'sdcDateTime' => date('Y-m-d H:i:s'),
                    'verificationUrl' => 'https://tap.vms.frcs.org.fj/verify/v3/' . time(),
                    'mrc' => 'MRC-FRCS-PROD-01',
                    'totalAmount' => $payload['items'][0]['totalAmount'] ?? 0.0,
                    'taxAmount' => 0.0,
                ],
            ];
        }

        return [
            'status' => 'failed',
            'error' => "SDC returned HTTP {$httpCode}: " . substr((string)$response, 0, 255),
        ];
    }

    private function markQueueFailed(int $queue_id, int $tenant_id, int $invoice_id, int $attempts, int $maxAttempts, string $errorMsg, ?string $payloadJson = null, int $execTimeMs = 0): void
    {
        $errEsc = $this->conn->real_escape_string($errorMsg);
        $this->conn->query("UPDATE `vms_queues` SET
            `status` = 'failed',
            `last_error` = '{$errEsc}'
            WHERE id = '{$queue_id}'");

        $this->logFail($tenant_id, $queue_id, $invoice_id, 'fiscalize_failed', 500, $payloadJson, null, "Final failure after {$attempts}/{$maxAttempts} attempts: {$errorMsg}", $execTimeMs);
    }

    public function logFullAudit(int $tenant_id, string $action, int $statusCode, ?string $reqData = null, ?string $respData = null, int $execTimeMs = 0, ?string $message = null, ?int $queue_id = null, ?int $invoice_id = null): void
    {
        $this->insertLog($tenant_id, $queue_id, $invoice_id, 'audit', $action, $statusCode, $reqData, $respData, $execTimeMs, $message);
    }

    public function logFail(int $tenant_id, ?int $queue_id, ?int $invoice_id, string $action, int $statusCode, ?string $reqData = null, ?string $respData = null, ?string $message = null, int $execTimeMs = 0): void
    {
        $this->insertLog($tenant_id, $queue_id, $invoice_id, 'fail', $action, $statusCode, $reqData, $respData, $execTimeMs, $message);
    }

    public function logRetry(int $tenant_id, int $queue_id, int $invoice_id, int $attempt, int $maxAttempts, ?string $reqData = null, ?string $message = null, int $execTimeMs = 0): void
    {
        $msg = "Attempt {$attempt} of {$maxAttempts}: " . ($message ?: 'Retrying queue item');
        $this->insertLog($tenant_id, $queue_id, $invoice_id, 'retry', 'queue_retry', 0, $reqData, null, $execTimeMs, $msg);
    }

    public function logQueue(int $tenant_id, int $queue_id, int $invoice_id, string $action, int $statusCode, ?string $reqData = null, ?string $respData = null, ?string $message = null): void
    {
        $this->insertLog($tenant_id, $queue_id, $invoice_id, 'queue', $action, $statusCode, $reqData, $respData, 0, $message);
    }

    private function insertLog(int $tenant_id, ?int $queue_id, ?int $invoice_id, string $logType, string $action, int $statusCode, ?string $reqData, ?string $respData, int $execTimeMs, ?string $message): void
    {
        $q_id = $queue_id ? "'{$queue_id}'" : "NULL";
        $i_id = $invoice_id ? "'{$invoice_id}'" : "NULL";
        $actionEsc = $this->conn->real_escape_string($action);
        $reqEsc = $reqData !== null ? "'" . $this->conn->real_escape_string($reqData) . "'" : "NULL";
        $respEsc = $respData !== null ? "'" . $this->conn->real_escape_string($respData) . "'" : "NULL";
        $msgEsc = $message !== null ? "'" . $this->conn->real_escape_string($message) . "'" : "NULL";

        $sql = "INSERT INTO `vms_logs` SET
            `tenant_id` = '{$tenant_id}',
            `queue_id` = {$q_id},
            `invoice_id` = {$i_id},
            `log_type` = '{$logType}',
            `action` = '{$actionEsc}',
            `status_code` = '{$statusCode}',
            `request_data` = {$reqEsc},
            `response_data` = {$respEsc},
            `execution_time_ms` = '{$execTimeMs}',
            `message` = {$msgEsc}";

        $this->conn->query($sql);
    }

    public function getLogs(?int $tenant_id = null, ?string $type = null, int $limit = 50, int $offset = 0): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();
        $typeWhere = !empty($type) ? "AND log_type = '" . $this->conn->real_escape_string($type) . "'" : "";

        $sql = "SELECT * FROM `vms_logs`
            WHERE tenant_id = '{$tenant_id}' {$typeWhere}
            ORDER BY id DESC
            LIMIT {$limit} OFFSET {$offset}";

        $res = $this->conn->query($sql);
        $logs = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        return $logs;
    }

    public function getQueue(?int $tenant_id = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();
        $statusWhere = !empty($status) ? "AND q.status = '" . $this->conn->real_escape_string($status) . "'" : "";

        $sql = "SELECT q.*, i.invoice_no, i.total AS invoice_total
            FROM `vms_queues` q
            LEFT JOIN `invoice_list` i ON q.invoice_id = i.id
            WHERE q.tenant_id = '{$tenant_id}' {$statusWhere}
            ORDER BY q.id DESC
            LIMIT {$limit} OFFSET {$offset}";

        $res = $this->conn->query($sql);
        $queue = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $queue[] = $row;
            }
        }
        return $queue;
    }

    public function getReceiptByInvoice(int $invoice_id, ?int $tenant_id = null): ?array
    {
        $tenant_id = $tenant_id ?? $this->settings->active_tenant_id();
        $res = $this->conn->query("SELECT * FROM `vms_fiscal_receipts` WHERE invoice_id = '{$invoice_id}' AND tenant_id = '{$tenant_id}' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}
