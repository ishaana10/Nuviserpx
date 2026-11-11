<?php
// Batch / expiry tracking: FEFO planning, expiry status, input cleaning and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/BatchService.php";
use App\Services\BatchService as B;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$b = [
    ['id' => 1, 'expiry_date' => '2027-06-01', 'qty_remaining' => 10],
    ['id' => 2, 'expiry_date' => '2027-01-15', 'qty_remaining' => 5],
    ['id' => 3, 'expiry_date' => null, 'qty_remaining' => 20],
    ['id' => 4, 'expiry_date' => '2027-01-15', 'qty_remaining' => 3],
];
$p = B::fefoPlan($b, 7);
check('earliest expiry first, then older batch', $p['take'] === [2 => 5.0, 4 => 2.0] && $p['short'] === 0.0);
$p = B::fefoPlan($b, 40);
check('no expiry is used last', array_keys($p['take']) === [2, 4, 1, 3] && $p['take'][3] === 20.0 && $p['short'] === 2.0);
check('quantity beyond all batches is reported short, not an error', B::fefoPlan($b, 100)['short'] === 62.0);
check('empty batches are skipped', B::fefoPlan([['id' => 1, 'expiry_date' => '2027-01-01', 'qty_remaining' => 0]], 3)['take'] === []);
check('nothing to take', B::fefoPlan($b, 0)['take'] === []);
check('fractions stay exact', B::fefoPlan([['id' => 1, 'expiry_date' => null, 'qty_remaining' => 0.3]], 0.1 + 0.2)['short'] === 0.0);

check('past date is expired', B::expiryStatus('2026-11-11', '2026-11-12') === 'expired');
check('today is not yet expired', B::expiryStatus('2026-11-12', '2026-11-12') === 'soon');
check('within the warning period', B::expiryStatus('2026-12-12', '2026-11-12') === 'soon');
check('beyond the warning period', B::expiryStatus('2026-12-13', '2026-11-12') === 'ok');
check('no expiry', B::expiryStatus(null, '2026-11-12') === 'none' && B::expiryStatus('', '2026-11-12') === 'none');

check('batch number trimmed and squashed', B::cleanBatchNo("  LOT   42 \n") === 'LOT 42');
check('batch number length limited', mb_strlen(B::cleanBatchNo(str_repeat('x', 100))) === 60);
check('real date accepted', B::cleanExpiry('2027-02-28') === '2027-02-28');
check('impossible date rejected', B::cleanExpiry('2027-02-30') === null);
check('wrong format rejected', B::cleanExpiry('28/02/2027') === null && B::cleanExpiry('') === null);

$wh = file_get_contents("$root/src/Services/WarehouseStockService.php");
check('stock leaving a location uses FEFO', strpos($wh, '->consume($product_id, $warehouse_id, -$delta)') !== false);
check('transfers move batches instead of consuming twice', strpos($wh, '->move($product_id, $from_wh, $to_wh') !== false && strpos($wh, '-$qty, false, false)') !== false);
$pu = file_get_contents("$root/src/Services/PurchaseService.php");
check('goods receipts create batches', strpos($pu, 'BatchService::cleanExpiry') !== false);
$in = file_get_contents("$root/src/Services/InventoryService.php");
check('stock-in adjustments create batches inside the transaction', strpos($in, 'BatchService::cleanExpiry') !== false && strpos($in, 'BatchService::cleanExpiry') < strpos($in, '$this->conn->commit();', strpos($in, 'BatchService::cleanExpiry')));
check('product tracking flag is saved', substr_count($in, 'track_batches') >= 3);
$mig = file_get_contents("$root/database/migrations/032_batches_expiry.sql");
check('one row per batch per location', strpos($mig, 'uniq_batch') !== false && strpos($mig, 'track_batches') !== false);
$perm = file_get_contents("$root/src/Support/Permissions.php");
check('write-off needs stock save', preg_match("/'write_off_expired'\s*=>\s*\['stock', 'save'\]/", $perm) === 1);
check('batches page needs stock view', preg_match("/'batches'\s*=>\s*'stock_view'/", $perm) === 1);
check('write-off is routed', strpos(file_get_contents("$root/classes/Master.php"), "case 'write_off_expired':") !== false);
exit($fail ? 1 : 0);
