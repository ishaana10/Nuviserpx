<?php

declare(strict_types=1);

namespace Tests\Unit;

if (!defined('PHPUNIT_TEST_MODE')) {
    define('PHPUNIT_TEST_MODE', true);
}

require_once(__DIR__ . '/../../classes/SystemSettings.php');

use App\Services\VmsService;
use PHPUnit\Framework\TestCase;

class VmsServiceTest extends TestCase
{
    private \mysqli $conn;
    private $settings;
    private VmsService $vmsService;

    protected function setUp(): void
    {
        $this->conn = new class extends \mysqli {
            public array $queries = [];
            public array $tables = [];

            public function __construct() {}

            public function query($query, $resultmode = MYSQLI_STORE_RESULT): mixed
            {
                $this->queries[] = $query;

                if (str_contains($query, 'SELECT * FROM `vms_configs`')) {
                    if (isset($this->tables['vms_configs'])) {
                        return new DummyResult([$this->tables['vms_configs']]);
                    }
                    return new DummyResult([]);
                }

                if (str_contains($query, 'SELECT id FROM `vms_configs`')) {
                    if (isset($this->tables['vms_configs'])) {
                        return new DummyResult([['id' => $this->tables['vms_configs']['id'] ?? 1]]);
                    }
                    return new DummyResult([]);
                }

                if (str_contains($query, 'INSERT INTO `vms_configs`')) {
                    $this->tables['vms_configs'] = [
                        'id' => 1,
                        'tenant_id' => 1,
                        'sdc_url' => 'https://custom-sdc.test/api',
                        'pac_code' => 'TESTPAC123',
                        'cashier_tin' => '112233445',
                        'pos_number' => 'POS-99',
                        'cert_path' => '',
                        'cert_pass' => '',
                        'is_sandbox' => 1,
                        'auto_fiscalize' => 1,
                        'max_retries' => 3,
                        'retry_interval_sec' => 120,
                    ];
                    return true;
                }

                if (str_contains($query, 'UPDATE `vms_configs`')) {
                    return true;
                }

                if (str_contains($query, 'FROM `invoice_list`')) {
                    return new DummyResult([[
                        'id' => 101,
                        'tenant_id' => 1,
                        'invoice_no' => 'INV-1001',
                        'party_id' => 1,
                        'total' => 115.00,
                        'buyer_tin' => '555666777',
                        'party_name' => 'Test Customer'
                    ]]);
                }

                if (str_contains($query, 'FROM `invoice_items`')) {
                    return new DummyResult([[
                        'id' => 1,
                        'invoice_id' => 101,
                        'item_name' => 'Software Consulting',
                        'qty' => 1,
                        'unit_price' => 100.00,
                        'amount' => 115.00
                    ]]);
                }

                if (str_contains($query, 'INSERT INTO `vms_queues`')) {
                    return true;
                }

                if (str_contains($query, 'SELECT q.* FROM `vms_queues`')) {
                    if (isset($this->tables['queue_item_50'])) {
                        return new DummyResult([$this->tables['queue_item_50']]);
                    }
                    return new DummyResult([[
                        'id' => 10,
                        'tenant_id' => 1,
                        'invoice_id' => 101,
                        'status' => 'pending',
                        'attempts' => 0,
                        'max_attempts' => 5,
                        'next_attempt_at' => null,
                        'payload' => json_encode(['invoiceNumber' => 'INV-1001', 'items' => [['totalAmount' => 115.00]]]),
                        'response' => null,
                        'invoice_no' => 'INV-1001',
                        'invoice_total' => 115.00
                    ]]);
                }

                if (str_contains($query, 'SELECT * FROM `vms_queues` WHERE id = \'50\'')) {
                    $this->tables['queue_item_50'] = [
                        'id' => 50,
                        'tenant_id' => 1,
                        'invoice_id' => 200,
                        'status' => 'pending',
                        'attempts' => 5,
                        'max_attempts' => 5,
                        'next_attempt_at' => null,
                        'payload' => '{}',
                        'invoice_no' => 'INV-200',
                        'invoice_total' => 200.0
                    ];
                    return new DummyResult([$this->tables['queue_item_50']]);
                }

                if (str_contains($query, 'SELECT q.*, i.invoice_no')) {
                    return new DummyResult([[
                        'id' => 50,
                        'tenant_id' => 1,
                        'invoice_id' => 200,
                        'status' => 'pending',
                        'attempts' => 5,
                        'max_attempts' => 5,
                        'next_attempt_at' => null,
                        'payload' => '{}',
                        'invoice_no' => 'INV-200',
                        'invoice_total' => 200.0
                    ]]);
                }

                if (str_contains($query, 'SELECT * FROM `vms_queues`')) {
                    return new DummyResult([
                        'id' => 1,
                        'tenant_id' => 1,
                        'status' => 'pending'
                    ]);
                }

                if (str_contains($query, 'SELECT * FROM `vms_fiscal_receipts`')) {
                    return new DummyResult([[
                        'id' => 1,
                        'tenant_id' => 1,
                        'invoice_id' => 101,
                        'sdc_invoice_no' => 'SDC-V3-12345',
                        'sdc_date_time' => '2026-04-13 12:00:00',
                        'verification_url' => 'https://tap.vms.frcs.org.fj/verify/12345',
                        'verification_qr' => 'DATA:QR',
                        'mrc_code' => 'MRC-01',
                        'total_amount' => 115.00,
                        'tax_amount' => 15.00
                    ]]);
                }

                if (str_contains($query, 'SELECT * FROM `vms_logs`')) {
                    return new DummyResult([[
                        'id' => 1,
                        'tenant_id' => 1,
                        'log_type' => 'audit',
                        'action' => 'fiscalize_success',
                        'status_code' => 200,
                        'execution_time_ms' => 10,
                        'message' => 'Success',
                        'created_at' => '2026-04-13 12:00:00'
                    ]]);
                }

                return new DummyResult([]);
            }

            public function __get($name)
            {
                if ($name === 'insert_id') {
                    return 10;
                }
                return null;
            }

            public function real_escape_string($string): string
            {
                return addslashes((string)$string);
            }

        };

        $this->settings = new \SystemSettings();

        $this->vmsService = new VmsService($this->conn, $this->settings);
    }

    public function testGetAndSaveConfig(): void
    {
        $config = $this->vmsService->getConfig(1);
        $this->assertEquals(1, $config['tenant_id']);
        $this->assertEquals(1, $config['is_sandbox']);

        $saveRes = json_decode($this->vmsService->saveConfig([
            'sdc_url' => 'https://custom-sdc.test/api',
            'pac_code' => 'TESTPAC123',
            'cashier_tin' => '112233445',
            'pos_number' => 'POS-99',
            'is_sandbox' => 1,
            'auto_fiscalize' => 1,
            'max_retries' => 3,
            'retry_interval_sec' => 120,
        ], 1), true);

        $this->assertEquals('success', $saveRes['status']);

        $updated = $this->vmsService->getConfig(1);
        $this->assertEquals('https://custom-sdc.test/api', $updated['sdc_url']);
        $this->assertEquals('TESTPAC123', $updated['pac_code']);
        $this->assertEquals(3, (int)$updated['max_retries']);
    }

    public function testBuildPayloadEnqueueAndProcessQueue(): void
    {
        $payload = $this->vmsService->buildInvoicePayload(101, 1);
        $this->assertEquals('INV-1001', $payload['invoiceNumber']);
        $this->assertEquals('555666777', $payload['buyerTIN']);
        $this->assertCount(1, $payload['items']);

        $enqueueRes = $this->vmsService->enqueueInvoice(101, 1);
        $this->assertEquals('success', $enqueueRes['status']);

        $processRes = $this->vmsService->processQueue(1, 10);
        $this->assertEquals('success', $processRes['status']);
        $this->assertEquals(1, $processRes['processed_count']);

        $receipt = $this->vmsService->getReceiptByInvoice(101, 1);
        $this->assertNotNull($receipt);
        $this->assertEquals('SDC-V3-12345', $receipt['sdc_invoice_no']);
        $this->assertEquals(115.00, (float)$receipt['total_amount']);

        $logs = $this->vmsService->getLogs(1, 'audit');
        $this->assertNotEmpty($logs);
    }

    public function testRetryFailedQueueItem(): void
    {
        $retryRes = $this->vmsService->retryFailedQueue(50, 1);
        $this->assertEquals('success', $retryRes['status']);

        $q = $this->vmsService->getQueue(1, 'pending');
        $this->assertNotEmpty($q);
        $this->assertEquals(50, $q[0]['id']);
    }
}

class DummyResult
{
    public int $num_rows;
    private array $rows;
    private int $pointer = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc(): ?array
    {
        if ($this->pointer < count($this->rows)) {
            return $this->rows[$this->pointer++];
        }
        return null;
    }
}
