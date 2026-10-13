<?php
/**
 * Modular Event Hook & Module Registry Engine for Nuvis ERPX
 * Nuvis Technologies (nuvistechnologies.com.fj)
 */

class ModuleManager {

    private static $modules = [];
    private static $hooks = [];

    /**
     * Register a module
     */
    public static function register_module($key, $name, $description = '', $version = '1.0.0') {
        self::$modules[$key] = [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'version' => $version,
            'status' => 'active'
        ];
    }

    /**
     * Register an event listener hook
     */
    public static function register_hook($event, callable $callback) {
        if (!isset(self::$hooks[$event])) {
            self::$hooks[$event] = [];
        }
        self::$hooks[$event][] = $callback;
    }

    /**
     * Trigger an event hook and notify webhooks
     */
    public static function trigger_hook($event, $payload = [], $tenant_id = 1) {
        $results = [];

        // Execute local callback listeners
        if (isset(self::$hooks[$event])) {
            foreach (self::$hooks[$event] as $callback) {
                if (is_callable($callback)) {
                    $results[] = call_user_func($callback, $payload, $tenant_id);
                }
            }
        }

        // Trigger outgoing webhooks if database connection is available
        self::dispatch_webhooks($event, $payload, $tenant_id);

        return $results;
    }

    /**
     * Dispatch registered webhooks
     */
    private static function dispatch_webhooks($event, $payload, $tenant_id) {
        global $_settings;
        if (!isset($_settings) || !isset($_settings->conn)) return;

        $conn = $_settings->conn;
        $event_clean = $conn->real_escape_string($event);
        $tenant_id = intval($tenant_id);

        $webhooks = $conn->query("SELECT * FROM `webhooks` WHERE tenant_id = '{$tenant_id}' AND status = 1 AND event = '{$event_clean}'");
        if ($webhooks && $webhooks->num_rows > 0) {
            while ($wh = $webhooks->fetch_assoc()) {
                self::send_webhook_http($wh['target_url'], $wh['secret_token'], $event, $payload);
            }
        }
    }

    /**
     * Send HTTP POST to webhook endpoint
     */
    private static function send_webhook_http($url, $secret, $event, $payload) {
        $json_data = json_encode([
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $payload
        ]);

        $signature = hash_hmac('sha256', $json_data, $secret ?: 'nuvis');

        $headers = [
            'Content-Type: application/json',
            'X-ERPX-Event: ' . $event,
            'X-ERPX-Signature: ' . $signature,
            'User-Agent: Nuvis-ERPX-Webhook-Engine/1.0'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        @curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Get registered modules list
     */
    public static function get_modules() {
        return self::$modules;
    }
}

// Pre-register core system modules
ModuleManager::register_module('accounting', 'Financial Accounting', 'General Ledger, Balance Sheet, P&L, AR Aging');
ModuleManager::register_module('invoicing', 'Invoicing & Sales', 'Tax Invoices, Customer Payments, Customer Profiles');
ModuleManager::register_module('inventory', 'Commercial Stock & Sales', 'Product Catalog, Stock Movements, Mobile Orders & Proforma');
ModuleManager::register_module('payroll', 'Fiji Payroll & HR', 'PAYE Tax, FNPF, Batch Processing, Payslips, Compliance Exporters');
ModuleManager::register_module('emailer', 'Email Notifications', 'SMTP & Mail Delivery Engine for Invoices, Receipts, and OTPs');
ModuleManager::register_module('vms', 'VAT Monitoring System (VMS 3.0)', 'Fiji FRCS VMS Fiscalization, SDC Integration, Async Queue, Multi-tenant Audit & Fail Logs');
?>