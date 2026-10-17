<?php

require_once __DIR__ . '/../initialize.php';
require_once __DIR__ . '/../src/Support/AppVersion.php';
require_once __DIR__ . '/../classes/AppVersion.php';

use App\Support\AppVersion as SupportAppVersion;

echo "=== TEST 1: AppVersion Load and Keys ===\n";
$data = SupportAppVersion::load();
if (empty($data['version']) || empty($data['build']) || empty($data['commit']) || empty($data['full'])) {
    echo "FAILED: Missing required version keys\n";
    exit(1);
}
echo "SupportAppVersion full: " . $data['full'] . "\n";

echo "=== TEST 2: AppVersion Global Proxy Class ===\n";
$fullStr = AppVersion::get('full');
if (empty($fullStr)) {
    echo "FAILED: AppVersion::get('full') returned empty\n";
    exit(1);
}
echo "AppVersion::get('full'): " . $fullStr . "\n";

echo "ALL APP VERSION TESTS PASSED!\n";
