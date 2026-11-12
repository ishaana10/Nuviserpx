<?php

declare(strict_types=1);

namespace App\Services;

/** FRCS VMS fiscalisation: settings, SDC ping, simulation, certificates. Moved verbatim out of Master. */
class FiscalizationService
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

    public function save_tenant_vms_settings()
    {
        $tenant_id = $this->settings->active_tenant_id();
        require_once(base_app . 'src/Services/VmsService.php');
        $vmsService = new \App\Services\VmsService($this->conn, $this->settings);

        if ($vmsService->saveTenantVmsConfig($tenant_id, $_POST)) {
            $this->settings->log_audit("Saved Tenant VMS v3.0 Settings", "VMS Integration", "Configured SDC URL & TIN for tenant #{$tenant_id}");
            return json_encode(['status' => 'success', 'msg' => 'Tenant FRCS VMS v3.0 settings saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save tenant VMS settings.']);
    }

    public function test_sdc_ping()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }

        $tenant_id = $this->settings->active_tenant_id();
        require_once(base_app . 'src/Services/VmsService.php');
        $vmsService = new \App\Services\VmsService($this->conn, $this->settings);
        $config = $vmsService->getTenantVmsConfig($tenant_id);

        $sdcUrl = $config['sdc_url'] ?? 'http://localhost:8088/api/v1/sdc';

        $ch = curl_init($sdcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode > 0 || str_contains($sdcUrl, 'localhost')) {
            return json_encode(['status' => 'success', 'msg' => "SDC Server Ping OK! Target: {$sdcUrl} (HTTP Status: " . ($httpCode ?: 200) . ")"]);
        }
        return json_encode(['status' => 'failed', 'msg' => "Could not reach SDC Server at {$sdcUrl}."]);
    }

    public function simulate_vms_fiscalization()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }

        $tenant_id = $this->settings->active_tenant_id();
        require_once(base_app . 'src/Services/VmsService.php');
        $vmsService = new \App\Services\VmsService($this->conn, $this->settings);

        $rawJson = $_POST['payload_json'] ?? '';
        $decoded = json_decode($rawJson, true);

        if (!$decoded || !isset($decoded['InvoiceNumber'])) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid VSDC Request JSON format.']);
        }

        $dummyInvoice = [
            'id' => 999,
            'invoice_no' => $decoded['InvoiceNumber'],
            'invoice_date' => date('Y-m-d'),
            'total' => $decoded['Payments'][0]['Amount'] ?? 115.00
        ];

        $dummyItems = [
            ['item_name' => $decoded['Items'][0]['Name'] ?? 'Sample Product', 'qty' => 1, 'unit_price' => 100, 'amount' => 115]
        ];

        $res = $vmsService->submitVsdcRequest($tenant_id, $dummyInvoice, $dummyItems);
        if ($res['success']) {
            return json_encode(['status' => 'success', 'msg' => 'VSDC Fiscalization Succeeded!', 'data' => $res]);
        }
        return json_encode(['status' => 'failed', 'msg' => $res['message'], 'data' => $res]);
    }

    public function upload_tenant_vsdc_cert()
    {
        $tenant_id = $this->settings->active_tenant_id();

        if (!isset($_FILES['p12_cert']) || $_FILES['p12_cert']['tmp_name'] == '') {
            return json_encode(['status' => 'failed', 'msg' => 'Please select a valid PKCS#12 certificate file (.p12 or .pfx).']);
        }

        $ext = strtolower(pathinfo($_FILES['p12_cert']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['p12', 'pfx'])) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid file type. Only .p12 or .pfx files allowed.']);
        }

        $fname = 'uploads/vms/cert_tenant_' . $tenant_id . '_' . time() . '.' . $ext;
        $dir_path = base_app . 'uploads/vms';
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0777, true);
        }

        if (move_uploaded_file($_FILES['p12_cert']['tmp_name'], base_app . $fname)) {
            $parsed = (new \App\Services\VmsService($this->conn, $this->settings))
                ->parseCertificate(base_app . $fname, $_POST['cert_pass'] ?? '');
            if (empty($parsed['success'])) {
                @unlink(base_app . $fname);
                return json_encode(['status' => 'failed', 'msg' => $parsed['message'] ?? 'Invalid certificate.']);
            }

            $c_name = $this->conn->real_escape_string($parsed['subject']);
            $c_exp = $this->conn->real_escape_string($parsed['valid_to']);
            $c_ser = $this->conn->real_escape_string($parsed['serial_number']);

            $this->conn->query("
                INSERT INTO `tenant_vsdc_certificates`
                (`tenant_id`, `cert_name`, `p12_path`, `cert_expires_at`, `serial_number`, `status`)
                VALUES ('{$tenant_id}', '{$c_name}', '{$fname}', '{$c_exp}', '{$c_ser}', 1)
            ");

            $this->settings->log_audit("Uploaded VSDC Certificate", "VMS Integration", "Uploaded PKCS#12 Certificate: {$c_name}");
            return json_encode(['status' => 'success', 'msg' => 'Certificate successfully uploaded and validated.']);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to save certificate file.']);
    }

    /* Journal Actions */

}
