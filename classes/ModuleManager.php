<?php
/**
 * Modular Event Hook & Module Registry Engine for Nuvis ERPX
 * Nuvis Technologies
 *
 * trigger_hook() is the single integration spine. When any module emits an event it:
 *   1. runs in-process callback listeners (register_hook),
 *   2. evaluates customisable workflow automations (WorkflowService),
 *   3. queues outgoing webhook deliveries (WebhookService) — drained asynchronously
 *      by cron/webhook_worker.php so the user's request is never blocked on HTTP.
 */

use App\Services\WebhookService;
use App\Services\WorkflowService;

class ModuleManager {

    private static $modules = [];
    private static $hooks = [];

    /** Register a module in the registry (shown in the developer dashboard). */
    public static function register_module($key, $name, $description = '', $version = '1.0.0') {
        self::$modules[$key] = [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'version' => $version,
            'status' => 'active'
        ];
    }

    /** Register an in-process event listener. */
    public static function register_hook($event, callable $callback) {
        if (!isset(self::$hooks[$event])) {
            self::$hooks[$event] = [];
        }
        self::$hooks[$event][] = $callback;
    }

    /**
     * Fire an event: run local listeners, evaluate workflows, queue webhooks.
     * Never throws — integration failures must not break the core transaction.
     *
     * @param string $event   canonical event name (App\Support\IntegrationEvents)
     * @param array  $payload  flat, serialisable event data
     * @param int    $tenant_id
     */
    public static function trigger_hook($event, $payload = [], $tenant_id = 1) {
        $results = [];

        // 1. In-process listeners
        if (isset(self::$hooks[$event])) {
            foreach (self::$hooks[$event] as $callback) {
                if (is_callable($callback)) {
                    try {
                        $results[] = call_user_func($callback, $payload, $tenant_id);
                    } catch (\Throwable $e) {
                        // swallow — a bad listener must not abort the event
                    }
                }
            }
        }

        $conn = self::conn();
        if ($conn instanceof \mysqli) {
            // 2. Workflow automations
            try {
                (new WorkflowService($conn))->run((string)$event, (array)$payload, (int)$tenant_id);
            } catch (\Throwable $e) {
                // ignore
            }

            // 3. Outgoing webhooks (queued, non-blocking)
            try {
                (new WebhookService($conn))->enqueue((string)$event, (array)$payload, (int)$tenant_id);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $results;
    }

    /** Resolve the active mysqli connection from the app globals. */
    private static function conn(): ?\mysqli {
        global $_settings, $conn;
        if (isset($_settings) && isset($_settings->conn) && $_settings->conn instanceof \mysqli) {
            return $_settings->conn;
        }
        if (isset($conn) && $conn instanceof \mysqli) {
            return $conn;
        }
        return null;
    }

    /** @return array registered modules list */
    public static function get_modules() {
        return self::$modules;
    }
}

// Pre-register core system modules
ModuleManager::register_module('accounting', 'Financial Accounting', 'General Ledger, Balance Sheet, P&L, AR Aging');
ModuleManager::register_module('invoicing', 'Invoicing & Sales', 'Tax Invoices, Customer Payments, Customer Profiles');
ModuleManager::register_module('inventory', 'Commercial Stock & Sales', 'Product Catalog, Stock Movements, Mobile Orders & Proforma');
ModuleManager::register_module('payroll', 'Payroll & HR', 'PAYE Tax, FNPF, Batch Processing, Payslips, Compliance Exporters');
ModuleManager::register_module('emailer', 'Email Notifications', 'SMTP & Mail Delivery Engine for Invoices, Receipts, and OTPs');
ModuleManager::register_module('integrations', 'Integrations & Automation', 'Webhooks, REST API, and the customisable Workflow engine');
