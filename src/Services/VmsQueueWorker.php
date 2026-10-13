<?php

declare(strict_types=1);

namespace App\Services;

require_once(__DIR__ . '/../../initialize.php');
require_once(__DIR__ . '/../../classes/DBConnection.php');
require_once(__DIR__ . '/../../classes/SystemSettings.php');
require_once(__DIR__ . '/../../src/Core/Container.php');
require_once(__DIR__ . '/VmsService.php');

use App\Core\Container;

class VmsQueueWorker
{
    public static function run(?int $tenant_id = null, int $limit = 20): void
    {
        global $_settings;
        if (!isset($_settings)) {
            $_settings = new \SystemSettings();
        }

        $container = Container::getInstance();
        $db = new \DBConnection();
        $container->singleton(\mysqli::class, $db->conn);
        $container->singleton(\SystemSettings::class, $_settings);

        /** @var VmsService $vmsService */
        $vmsService = $container->make(VmsService::class);

        echo "[" . date('Y-m-d H:i:s') . "] Starting VMS Queue Worker process...\n";
        $res = $vmsService->processQueue($tenant_id, $limit);

        echo "[" . date('Y-m-d H:i:s') . "] Processed: {$res['processed_count']} item(s).\n";
        if (!empty($res['details'])) {
            foreach ($res['details'] as $det) {
                echo "  - Queue #{$det['queue_id']}: Status = {$det['status']}";
                if (!empty($det['sdc_invoice_no'])) {
                    echo ", SDC No = {$det['sdc_invoice_no']}";
                }
                if (!empty($det['error'])) {
                    echo ", Error = {$det['error']}";
                }
                echo "\n";
            }
        }
        echo "[" . date('Y-m-d H:i:s') . "] VMS Queue Worker process finished.\n";
    }
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'VmsQueueWorker.php') {
    $tenant_id = isset($argv[1]) ? intval($argv[1]) : null;
    $limit = isset($argv[2]) ? intval($argv[2]) : 20;
    VmsQueueWorker::run($tenant_id, $limit);
}
