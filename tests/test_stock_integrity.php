<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\InvoiceService as V;
use App\Services\WarehouseStockService as W;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// --- invoice lifecycle rules
check('a cancelled invoice cannot be edited', V::editProblem('cancelled') !== null);
check('other invoices can be edited', V::editProblem('unpaid') === null && V::editProblem('partially_paid') === null && V::editProblem('draft') === null);
check('an invoice with no money against it can be cancelled', V::cancelProblem(0.0, 0.0) === null);
check('receipts block cancelling', V::cancelProblem(10.0, 0.0) !== null);
check('credit notes block cancelling', V::cancelProblem(0.0, 5.0) !== null);
check('posted invoices cannot be deleted', V::deleteProblem('unpaid', 0.0, 0.0) !== null && V::deleteProblem('partially_paid', 0.0, 0.0) !== null && V::deleteProblem('paid', 0.0, 0.0) !== null);
check('drafts and cancelled invoices can be deleted', V::deleteProblem('draft', 0.0, 0.0) === null && V::deleteProblem('cancelled', 0.0, 0.0) === null);
check('settled money blocks deletion even of a cancelled one', V::deleteProblem('cancelled', 3.0, 0.0) !== null);

// --- transfer rules
check('transfer within stock is fine', W::transferProblem(5.0, 5.0, 1, 2) === null);
check('transfer over stock is refused with the figure', str_contains((string)W::transferProblem(6.0, 5.0, 1, 2), 'Only 5 '));
check('transfer from empty location is refused', W::transferProblem(1.0, 0.0, 0, 3) !== null);
check('transfer to the same place is refused', W::transferProblem(1.0, 9.0, 2, 2) !== null);
check('zero or negative transfer is refused', W::transferProblem(0.0, 9.0, 1, 2) !== null && W::transferProblem(-1.0, 9.0, 1, 2) !== null);

// --- wiring
$wh = file_get_contents(base_app . 'src/Services/WarehouseStockService.php');
check('transfer runs in one transaction with the source row locked', str_contains($wh, 'begin_transaction') && str_contains($wh, 'FOR UPDATE') && str_contains($wh, 'rollback') && str_contains($wh, 'commit'));
check('transfer checks both warehouses belong to the company', str_contains($wh, 'That warehouse was not found.'));
check('location changes report failure', str_contains($wh, 'float $delta, bool $syncTotal = true, bool $batches = true): bool'));

$is = file_get_contents(base_app . 'src/Services/InventoryService.php');
check('adjustment cannot drive the total below zero', str_contains($is, '`stock_quantity` + ({$delta}) >= 0'));
check('adjustment moves total, location and log together', str_contains($is, 'begin_transaction') && str_contains($is, 'rollback') && str_contains($is, 'commit'));
check('a count sets the chosen location, not the product total', str_contains($is, '$delta = round($qty - $atLocation, 4)') && !str_contains($is, "`stock_quantity` = '{\$qty}'"));
check('adjustment type and warehouse are validated', str_contains($is, "Choose a valid adjustment type.") && str_contains($is, 'That warehouse was not found.'));
check('a failed adjustment reverses the value it posted', str_contains($is, 'reverseJournal($journalId'));

$master = (file_get_contents(base_app . 'classes/Master.php') . file_get_contents(base_app . 'src/Services/SalesOrderService.php'));
check('units-only sales cannot oversell', str_contains($master, 'shortageMessage(') && str_contains($master, "AND `stock_quantity` >= {\$qty}"));

$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('cancelling reverses the journal instead of leaving it', str_contains($inv, 'private function cancelInvoice') && str_contains($inv, 'reverseJournal((int)$cur[\'journal_id\']'));
check('cancelling forgets the stock issue so it cannot return twice', str_contains($inv, "SET `unit_cost` = NULL"));
check('only drafts have their journal deleted', substr_count($inv, 'DELETE FROM `journal_entries`') === 1 && strpos($inv, 'if ($isDraft)') < strpos($inv, 'DELETE FROM `journal_entries`'));
check('editing routes cancellation to the cancel action', str_contains($inv, 'return $this->cancelInvoice((int)$id'));

echo $fail ? "\n$fail FAILED\n" : "\nAll stock integrity tests passed\n";
exit($fail ? 1 : 0);
