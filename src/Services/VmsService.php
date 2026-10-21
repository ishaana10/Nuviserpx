<?php

namespace App\Services;

use mysqli;
use SystemSettings;

class VmsService
{
    private mysqli $db;
    private SystemSettings $settings;

    public function __construct(mysqli $db, SystemSettings $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * Retrieve per-tenant VMS v3.0 configuration
     */
    public function getTenantVmsConfig(int $tenantId): array
    {
        $qry = $this->db->query("SELECT * FROM `tenant_vms_config` WHERE tenant_id = '{$tenantId}'");
        if ($qry && $qry->num_rows > 0) {
            return $qry->fetch_assoc();
        }

        // Return default system fallback config
        return [
            'tenant_id' => $tenantId,
            'sdc_url' => $this->settings->info('vms_url') ?: 'http://localhost:8088/api/v1/sdc',
            'tin' => $this->settings->info('vms_tin') ?: '50-00000-0-0',
            'pos_operator_id' => 'OPERATOR_01',
            'pac_code' => '',
            'default_tax_group' => 'A',
            'mode' => $this->settings->info('vms_status') == '1' ? 'production' : ($this->settings->info('vms_status') == '2' ? 'sandbox' : 'disabled'),
            'status' => 1
        ];
    }

    /**
     * Save or update per-tenant VMS configuration
     */
    public function saveTenantVmsConfig(int $tenantId, array $data): bool
    {
        $sdcUrl = $this->db->real_escape_string($data['sdc_url'] ?? 'http://localhost:8088/api/v1/sdc');
        $tin = $this->db->real_escape_string($data['tin'] ?? '50-00000-0-0');
        $posOperator = $this->db->real_escape_string($data['pos_operator_id'] ?? 'OPERATOR_01');
        $pacCode = $this->db->real_escape_string($data['pac_code'] ?? '');
        $taxGroup = $this->db->real_escape_string($data['default_tax_group'] ?? 'A');
        $mode = $this->db->real_escape_string($data['mode'] ?? 'sandbox');

        $chk = $this->db->query("SELECT id FROM `tenant_vms_config` WHERE tenant_id = '{$tenantId}'");
        if ($chk && $chk->num_rows > 0) {
            $sql = "UPDATE `tenant_vms_config` SET
                    `sdc_url` = '{$sdcUrl}',
                    `tin` = '{$tin}',
                    `pos_operator_id` = '{$posOperator}',
                    `pac_code` = '{$pacCode}',
                    `default_tax_group` = '{$taxGroup}',
                    `mode` = '{$mode}'
                    WHERE tenant_id = '{$tenantId}'";
        } else {
            $sql = "INSERT INTO `tenant_vms_config`
                    (`tenant_id`, `sdc_url`, `tin`, `pos_operator_id`, `pac_code`, `default_tax_group`, `mode`, `status`)
                    VALUES ('{$tenantId}', '{$sdcUrl}', '{$tin}', '{$posOperator}', '{$pacCode}', '{$taxGroup}', '{$mode}', 1)";
        }

        return (bool)$this->db->query($sql);
    }

    /**
     * Build VSDC v3.0 Request Payload for Sales Invoice Fiscalization
     */
    public function buildVsdcPayload(array $invoice, array $items, array $config): array
    {
        $vsdcItems = [];
        foreach ($items as $item) {
            $vsdcItems[] = [
                'GTIN' => 'N/A',
                'Name' => $item['item_name'] ?? 'Item',
                'Quantity' => floatval($item['qty'] ?? 1),
                'UnitPrice' => floatval($item['unit_price'] ?? 0),
                'TotalAmount' => floatval($item['amount'] ?? 0),
                'Labels' => [$config['default_tax_group'] ?? 'A']
            ];
        }

        return [
            'InvoiceType' => 1, // 1 = Normal Sales Invoice
            'TransactionType' => 1, // 1 = Sale
            'TIN' => $config['tin'] ?? '50-00000-0-0',
            'OperatorID' => $config['pos_operator_id'] ?? 'OPERATOR_01',
            'InvoiceNumber' => $invoice['invoice_no'] ?? 'INV-001',
            'InvoiceDate' => date('Y-m-d\TH:i:s\Z', strtotime($invoice['invoice_date'] ?? date('Y-m-d'))),
            'Items' => $vsdcItems,
            'Payments' => [
                [
                    'PaymentType' => 0, // 0 = Cash / Wire / Card
                    'Amount' => floatval($invoice['total'] ?? 0)
                ]
            ]
        ];
    }

    /**
     * Submit VSDC v3.0 Fiscalization Request to FRCS SDC Server
     */
    public function submitVsdcRequest(int $tenantId, array $invoice, array $items): array
    {
        $config = $this->getTenantVmsConfig($tenantId);
        if ($config['mode'] === 'disabled') {
            return [
                'success' => false,
                'message' => 'FRCS VMS Integration is currently disabled for this tenant.'
            ];
        }

        $payload = $this->buildVsdcPayload($invoice, $items, $config);
        $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT);

        // Simulation for Sandbox or Local environments
        if ($config['mode'] === 'sandbox' || str_contains($config['sdc_url'], 'localhost')) {
            $mrc = 'MRC-' . sprintf('%06d', rand(1, 999999));
            $sdcInvoiceNo = $config['tin'] . '-SDC-' . rand(100000, 999999);
            $verificationUrl = 'https://vms.frcs.org.fj/verify/' . $sdcInvoiceNo;

            $response = [
                'FiscalInvoiceNumber' => $sdcInvoiceNo,
                'VerificationUrl' => $verificationUrl,
                'VerificationQRCode' => 'data:image/svg+xml;utf8,<svg>QRCodeMock</svg>',
                'Mrc' => $mrc,
                'InternalData' => 'VSDC_v3_SIGNED_OK',
                'Signature' => bin2hex(random_bytes(16))
            ];

            $this->logVmsTransaction($tenantId, intval($invoice['id'] ?? 0), $invoice['invoice_no'], $sdcInvoiceNo, $mrc, $verificationUrl, $jsonPayload, json_encode($response), 'success');

            return [
                'success' => true,
                'sdc_invoice_number' => $sdcInvoiceNo,
                'mrc' => $mrc,
                'verification_url' => $verificationUrl,
                'response' => $response
            ];
        }

        // Live cURL HTTP POST execution to SDC Server
        $ch = curl_init($config['sdc_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'PAC-Code: ' . ($config['pac_code'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->logVmsTransaction($tenantId, intval($invoice['id'] ?? 0), $invoice['invoice_no'], '', '', '', $jsonPayload, '', 'failed', $err);
            return [
                'success' => false,
                'message' => 'SDC Server Connection Failed: ' . $err
            ];
        }

        $decoded = json_decode($res, true);
        if (isset($decoded['FiscalInvoiceNumber'])) {
            $this->logVmsTransaction($tenantId, intval($invoice['id'] ?? 0), $invoice['invoice_no'], $decoded['FiscalInvoiceNumber'], $decoded['Mrc'] ?? '', $decoded['VerificationUrl'] ?? '', $jsonPayload, $res, 'success');
            return [
                'success' => true,
                'sdc_invoice_number' => $decoded['FiscalInvoiceNumber'],
                'mrc' => $decoded['Mrc'] ?? '',
                'verification_url' => $decoded['VerificationUrl'] ?? '',
                'response' => $decoded
            ];
        }

        $this->logVmsTransaction($tenantId, intval($invoice['id'] ?? 0), $invoice['invoice_no'], '', '', '', $jsonPayload, $res, 'failed', 'Invalid SDC Response format.');
        return [
            'success' => false,
            'message' => 'VSDC Fiscalization Failed: ' . $res
        ];
    }

    /**
     * Record VMS fiscal transaction log
     */
    private function logVmsTransaction(int $tenantId, int $invoiceId, string $invoiceNo, string $sdcNo, string $mrc, string $url, string $reqJson, string $resJson, string $status, string $errMsg = ''): void
    {
        $invNoEsc = $this->db->real_escape_string($invoiceNo);
        $sdcNoEsc = $this->db->real_escape_string($sdcNo);
        $mrcEsc = $this->db->real_escape_string($mrc);
        $urlEsc = $this->db->real_escape_string($url);
        $reqEsc = $this->db->real_escape_string($reqJson);
        $resEsc = $this->db->real_escape_string($resJson);
        $errEsc = $this->db->real_escape_string($errMsg);

        $this->db->query("
            INSERT INTO `vms_logs`
            (`tenant_id`, `invoice_id`, `invoice_no`, `sdc_invoice_no`, `mrc`, `verification_url`, `request_json`, `response_json`, `status`, `error_msg`)
            VALUES ('{$tenantId}', '{$invoiceId}', '{$invNoEsc}', '{$sdcNoEsc}', '{$mrcEsc}', '{$urlEsc}', '{$reqEsc}', '{$resEsc}', '{$status}', '{$errEsc}')
        ");
    }
}
