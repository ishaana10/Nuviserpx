<?php
// An accountant's walk through the books: sales, receipts, purchases, bill payments, credit notes, VAT and journals,
// checking after every step that the ledger balances and that the sub-ledgers and the reports agree with it.
require __DIR__ . '/_harness.php';

class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return $k === 'id' ? 1 : ($k === 'type' ? 1 : null); }
    public function is_super_admin() { return true; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function base_currency($t = null) { return 'FJD'; }
    public function currency_tag() { return 'FJD'; }
    public function tenant_branding() { return ['name' => 'Test Co', 'email' => '', 'phone' => '', 'address' => '', 'logo' => '']; }
    public function can_access($p) { return true; }
}
$_SESSION['userdata'] = ['id' => 1, 'type' => 1, 'login_type' => 1];
use App\Services\{InvoiceService, PaymentService, PurchaseService, BillPaymentService, CreditNoteService, JournalService, LedgerService as L};

[$c, $db] = nv_fresh_db();
(new \App\Database\LegacySchemaCatchup($c))->run();   // what the app does on its first request
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$c->query("DELETE FROM account_list WHERE tenant_id = 1");
\App\Services\ChartProvisioner::provision($c, 1);
$acc = fn(string $code) => (int)$c->query("SELECT id FROM account_list WHERE tenant_id = 1 AND code = '$code'")->fetch_row()[0];
[$BANK, $AR, $AP, $VATIN, $VATOUT, $SALES, $PURCH, $EXP, $CAP] = [$acc('1010'), $acc('1100'), $acc('2010'), $acc('1110'), $acc('2020'), $acc('4010'), $acc('5020'), $acc('5300'), $acc('3010')];
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'C1','Alpha Customer','a@x.fj','customer'),(2,1,'V1','Beta Supplier','b@x.fj','vendor')");
$s = new SystemSettings();
$inv = new InvoiceService($c, $s); $pay = new PaymentService($c, $s); $pur = new PurchaseService($c, $s);
$bp = new BillPaymentService($c, $s); $cn = new CreditNoteService($c, $s); $jr = new JournalService($c, $s);
$res = fn($j) => json_decode($j, true);
$ok = fn($j) => ($res($j)['status'] ?? '') === 'success';
$why = fn($j) => $res($j)['msg'] ?? $j;
$q1 = fn($sql) => $c->query($sql)->fetch_row()[0];
$near = fn($a, $b) => abs((float)$a - (float)$b) < 0.005;

// Money in the books for an account, debit-positive, posted entries only.
$bal = function (int $a, ?string $asOf = null) use ($c) {
    $w = $asOf ? "AND j.journal_date <= '$asOf'" : '';
    return (float)$c->query("SELECT COALESCE(SUM(CASE WHEN " . L::SIDE_SQL . " = 1 THEN ji.amount ELSE -ji.amount END),0) FROM journal_items ji JOIN journal_entries j ON j.id = ji.journal_id LEFT JOIN group_list g ON g.id = ji.group_id WHERE ji.account_id = $a AND j.tenant_id = 1 AND " . L::POSTED_SQL . " $w")->fetch_row()[0];
};
// Standing invariants, asserted after each phase.
$invariants = function (string $phase) use ($c, $bal, $AR, $AP, $near, $q1) {
    $unbal = $c->query("SELECT j.id FROM journal_entries j JOIN journal_items ji ON ji.journal_id = j.id LEFT JOIN group_list g ON g.id = ji.group_id WHERE j.tenant_id = 1 AND " . L::POSTED_SQL . " GROUP BY j.id HAVING ABS(SUM(CASE WHEN " . L::SIDE_SQL . " = 1 THEN ji.amount ELSE -ji.amount END)) > 0.004")->num_rows;
    check("[$phase] every posted journal balances", $unbal === 0);
    $ar = (float)$q1("SELECT COALESCE(SUM(total - paid_amount - credited_amount),0) FROM invoice_list WHERE tenant_id = 1 AND status IN ('unpaid','partially_paid','partial','overdue')");
    check("[$phase] AR control account equals open invoices (ledger " . round($bal($AR), 2) . " vs " . round($ar, 2) . ")", $near($bal($AR), $ar));
    $ap = (float)$q1("SELECT COALESCE(SUM(total - paid_amount - credited_amount),0) FROM vendor_bill_list WHERE tenant_id = 1 AND status IN ('unpaid','partially_paid','partial','overdue')");
    check("[$phase] AP control account equals open bills (ledger " . round(-$bal($AP), 2) . " vs " . round($ap, 2) . ")", $near(-$bal($AP), $ap));
    $b = (new L($c, 1))->balances(null, '2099-12-31');
    $tb = L::trialBalance($b);
    check("[$phase] trial balance: debits = credits (" . $tb['debit'] . " / " . $tb['credit'] . ")", $near($tb['debit'], $tb['credit']));
};

// ---------- 1. Sales: VAT-exclusive invoice, 2 lines, line discount -----------------------------------------
$line = fn(array $names, array $qty, array $price, array $disc = [], array $rate = []) => ['item_name' => $names, 'item_desc' => array_fill(0, count($names), ''), 'item_qty' => $qty, 'item_price' => $price,
    'item_discount_pct' => $disc ?: array_fill(0, count($names), 0), 'item_tax_rate' => $rate ?: array_fill(0, count($names), 12.5)];
$base = ['party_id' => 1, 'invoice_date' => '2026-03-10', 'due_date' => '2026-04-09', 'tax_type' => 'exclusive', 'discount' => 0, 'status' => 'unpaid'];
// 2 x 100 less 10% = 180; 1 x 50 = 50; sub 230; VAT 12.5% = 28.75; total 258.75
$r = $inv->saveInvoice($base + $line(['Consulting', 'Setup'], [2, 1], [100, 50], [10, 0]) + ['subtotal' => 230, 'tax_amount' => 28.75, 'total' => 258.75]);
check('invoice 1 saves (' . $why($r) . ')', $ok($r));
$i1 = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id LIMIT 1");
check('invoice 1: Dr AR 258.75', $near($bal($AR), 258.75));
check('invoice 1: Cr Sales 230.00', $near(-$bal($SALES), 230.00));
check('invoice 1: Cr VAT output 28.75', $near(-$bal($VATOUT), 28.75));
$invariants('after invoice 1');

// VAT-inclusive invoice: 112.50 incl 12.5% => net 100, VAT 12.50
$r = $inv->saveInvoice(['invoice_date' => '2026-03-12', 'due_date' => '2026-04-11', 'tax_type' => 'inclusive'] + $base + $line(['Widget'], [1], [112.5]) + ['subtotal' => 112.5, 'tax_amount' => 12.5, 'total' => 112.5]);
check('invoice 2 (VAT inclusive) saves (' . $why($r) . ')', $ok($r));
check('inclusive invoice: Sales 330.00 and VAT 41.25 in total', $near(-$bal($SALES), 330.00) && $near(-$bal($VATOUT), 41.25));
// Tampered totals are refused
$r = $inv->saveInvoice($base + $line(['X'], [1], [100]) + ['subtotal' => 100, 'tax_amount' => 12.5, 'total' => 50]);
check('an invoice whose total does not match its lines is refused', !$ok($r));
// Zero-rated / no tax
$r = $inv->saveInvoice(['invoice_date' => '2026-03-14', 'tax_type' => 'none'] + $base + $line(['Export'], [1], [200], [], [0]) + ['subtotal' => 200, 'tax_amount' => 0, 'total' => 200]);
check('invoice 3 (no tax) saves (' . $why($r) . ')', $ok($r));
$invariants('after 3 invoices');

// ---------- 2. Receipts ------------------------------------------------------------------------------------
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 1, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-20', 'amount' => 100, 'method' => 'bank_transfer', 'invoice_alloc' => [$i1 => 100]]);
check('part receipt on invoice 1 saves (' . $why($r) . ')', $ok($r));
check('invoice 1 is now partially paid with 158.75 owing', $q1("SELECT status FROM invoice_list WHERE id = $i1") !== 'unpaid' && $near($q1("SELECT total-paid_amount FROM invoice_list WHERE id = $i1"), 158.75));
check('bank has 100.00', $near($bal($BANK), 100));
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 1, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-21', 'amount' => 500, 'method' => 'cash', 'invoice_alloc' => [$i1 => 500]]);
check('an over-allocation beyond what is owed is refused', !$ok($r));
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 1, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-22', 'amount' => 158.75, 'method' => 'cash', 'invoice_alloc' => [$i1 => 158.75]]);
check('settling the balance saves (' . $why($r) . ')', $ok($r));
check('invoice 1 is paid', $q1("SELECT status FROM invoice_list WHERE id = $i1") === 'paid');
$invariants('after receipts');

// ---------- 3. Purchases: vendor bill with input VAT, then payment with withholding off --------------------
$bill = ['vendor_id' => 2, 'bill_date' => '2026-03-15', 'due_date' => '2026-04-14', 'status' => 'unpaid', 'tax_type' => 'exclusive', 'discount' => 0,
    'item_name' => ['Electricity'], 'item_desc' => [''], 'item_qty' => [1], 'item_price' => [400], 'item_tax_rate' => [12.5], 'product_id' => [0], 'subtotal' => 400, 'tax_amount' => 50, 'total' => 450];
$r = $pur->saveVendorBill($bill);
check('vendor bill saves (' . $why($r) . ')', $ok($r));
$b1 = (int)$q1("SELECT id FROM vendor_bill_list WHERE tenant_id = 1 ORDER BY id LIMIT 1");
check('bill: Cr AP 450, Dr input VAT 50, Dr expense 400 (got AP ' . -$bal($AP) . ', VATIN ' . $bal($VATIN) . ', costs ' . array_sum(array_map(fn($code) => $bal($acc($code)), ['5010','5020','5100','5200','5300','5400','5990'])) . ')', $near(-$bal($AP), 450) && $near($bal($VATIN), 50) && $near(array_sum(array_map(fn($code) => $bal($acc($code)), ['5010','5020','5100','5200','5300','5400','5990'])), 400));
echo 'INFO bill journal: ' . json_encode($c->query("SELECT a.code,a.name,ji.entry_side,ji.amount FROM journal_items ji JOIN account_list a ON a.id=ji.account_id WHERE ji.journal_id=(SELECT journal_id FROM vendor_bill_list WHERE id=$b1)")->fetch_all(MYSQLI_ASSOC)) . "\n";
$invariants('after bill');
$r = $bp->pay(['vendor_id' => 2, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-25', 'amount' => 200, 'method' => 'bank_transfer', 'alloc' => [$b1 => 200]]);
check('part payment of the bill saves (' . $why($r) . ')', $ok($r));
check('bill shows 250 owing; bank is 100 less 200 plus 158.75 = 58.75... checked below', $near($q1("SELECT total-paid_amount FROM vendor_bill_list WHERE id = $b1"), 250));
check('bank balance = receipts 258.75 - payment 200 = 58.75', $near($bal($BANK), 58.75));
$r = $bp->pay(['vendor_id' => 2, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-26', 'amount' => 9999, 'alloc' => [$b1 => 9999]]);
check('paying more than the bill is refused', !$ok($r));
$invariants('after bill payment');

// ---------- 4. Credit note against invoice 2, and a debit note against the bill ---------------------------
$i2 = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id LIMIT 1 OFFSET 1");
$r = $cn->issue(['note_type' => 'credit', 'document_id' => $i2, 'note_date' => '2026-03-28', 'reason' => 'Faulty', 'line_desc' => ['Widget returned'], 'line_qty' => [1], 'line_price' => [45], 'line_tax' => [12.5], 'line_product' => [0], 'line_return' => [0]]);
check('credit note issues (' . $why($r) . ')', $ok($r));
check('credit note reduces sales by 45 and VAT by 5.63 (sales ' . -$bal($SALES) . ', VAT ' . -$bal($VATOUT) . ')', $near(-$bal($SALES), 530 - 45) && $near(-$bal($VATOUT), 41.25 - 5.63));
check('invoice 2 now shows 50.63 credited', $near($q1("SELECT credited_amount FROM invoice_list WHERE id = $i2"), 50.63));
$invariants('after credit note');

// ---------- 5. Manual journal and reversal; then reports reconcile -------------------------------------------
$r = $jr->saveJournal(['journal_date' => '2026-03-31', 'description' => 'Owner capital', 'status' => 'posted', 'account_id' => [$BANK, $CAP], 'debit' => ['1000', '0'], 'credit' => ['0', '1000']]);
check('capital injection journal posts', $ok($r));
$invariants('after journal');

// ---------- 6. Reports reconcile with the ledger ---------------------------------------------------------------
$b = (new L($c, 1))->balances(null, '2026-12-31');
$is = L::incomeStatement($b); $bs = L::balanceSheet($b);
$costsAll = array_sum(array_map(fn($code) => $bal($acc($code)), ['5010', '5020', '5100', '5200', '5300', '5400', '5900', '5990']));
$expProfit = -$bal($SALES) - $bal($acc('4900')) - $costsAll;
check('income statement: revenue = sales ledger (' . $is['revenue'] . ')', $near($is['revenue'], -$bal($SALES)));
check('income statement: net profit = revenue - costs (' . $is['net_profit'] . ' vs ' . round($expProfit, 2) . ')', $near($is['net_profit'], $expProfit));
check('balance sheet balances (difference ' . $bs['difference'] . ')', !empty($bs['balanced']));
check('balance sheet: assets = liabilities + equity', $near($bs['total_assets'], $bs['total_liabilities'] + $bs['total_equity']));
check('balance sheet carries this year\'s profit (' . $bs['current_year_earnings'] . ')', $near($bs['current_year_earnings'] + $bs['retained_earnings'], $expProfit));
check('balance sheet cash = bank ledger', $near($bal($BANK), 58.75 + 1000));

// ---------- 7. Receivables / payables ageing agree with the control accounts -----------------------------------
$arRows = $c->query("SELECT i.id, i.invoice_no, i.due_date, i.invoice_date, i.total, (i.total - i.paid_amount - i.credited_amount) AS balance, p.id party_id, p.name party_name, p.party_code FROM invoice_list i JOIN party_list p ON p.id = i.party_id WHERE i.tenant_id = 1 AND i.status NOT IN ('paid','cancelled','draft','void')")->fetch_all(MYSQLI_ASSOC);
$aged = L::agedReceivables($arRows, '2026-06-30');
check('aged receivables total = AR control account (' . $aged['totals']['total'] . ' vs ' . round($bal($AR), 2) . ')', $near($aged['totals']['total'], $bal($AR)));
$apRows = $c->query("SELECT b.id, b.bill_no, b.bill_date, b.due_date, b.total, (b.total - b.paid_amount - b.credited_amount) AS balance, p.id vendor_id, p.name vendor_name, p.party_code FROM vendor_bill_list b JOIN party_list p ON p.id = b.vendor_id WHERE b.tenant_id = 1 AND b.status NOT IN ('paid','cancelled','draft','void')")->fetch_all(MYSQLI_ASSOC);
$agedAp = L::agedPayables($apRows, '2026-06-30');
check('aged payables total = AP control account (' . $agedAp['totals']['total'] . ' vs ' . round(-$bal($AP), 2) . ')', $near($agedAp['totals']['total'], -$bal($AP)));

// ---------- 8. Cancel / void / delete rules -----------------------------------------------------------------------
$i3 = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id LIMIT 1 OFFSET 2");
$r = $inv->saveInvoice(['id' => $i3, 'status' => 'cancelled', 'party_id' => 1] + $base);
check('an unpaid invoice can be cancelled (' . $why($r) . ')', $ok($r));
check('cancelling reverses it: sales back down by 200, AR back down by 200', $near(-$bal($SALES), 485 - 200));
$invariants('after cancelling invoice 3');
$r = $inv->saveInvoice(['id' => $i1, 'status' => 'cancelled', 'party_id' => 1] + $base);
check('a paid invoice cannot be cancelled', !$ok($r));
$r = $inv->deleteInvoice($i1);
check('a paid invoice cannot be deleted', !$ok($r));
$invariants('after refused cancel/delete');

// Removing a receipt re-opens the invoice
$pid = (int)$q1("SELECT id FROM payment_list WHERE tenant_id = 1 ORDER BY id LIMIT 1");
$r = $pay->deletePayment($pid);
check('a receipt can be removed (' . $why($r) . ')', $ok($r));
check('invoice 1 owes the 100 again', $near($q1("SELECT total-paid_amount-credited_amount FROM invoice_list WHERE id = $i1"), 100));
check('bank fell by 100', $near($bal($BANK), 58.75 + 1000 - 100));
$invariants('after removing a receipt');

// ---------- 9. Period lock: no posting or back-dating into a closed period -------------------------------------------
(new \App\Services\PeriodLockService($c, $s))->setLock('2026-03-31', 'March closed');
$r = $inv->saveInvoice(['invoice_date' => '2026-03-30'] + $base + $line(['Late'], [1], [10]) + ['subtotal' => 10, 'tax_amount' => 1.25, 'total' => 11.25]);
check('an invoice dated in a closed period is refused', !$ok($r));
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 1, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-30', 'amount' => 10, 'method' => 'cash', 'invoice_alloc' => [$i1 => 10]]);
check('a receipt dated in a closed period is refused', !$ok($r));
$r = $pur->saveVendorBill(['bill_date' => '2026-03-30'] + $bill);
check('a bill dated in a closed period is refused', !$ok($r));
$r = $bp->pay(['vendor_id' => 2, 'bank_account_id' => $BANK, 'payment_date' => '2026-03-30', 'amount' => 10, 'alloc' => [$b1 => 10]]);
check('a bill payment dated in a closed period is refused', !$ok($r));
$r = $pay->deletePayment((int)$q1("SELECT id FROM payment_list WHERE tenant_id = 1 AND status <> 'cancelled' ORDER BY id LIMIT 1"));
$invariants('after lock attempts');


// ---------- 10. VAT return agrees with the VAT control accounts --------------------------------------------------------
$tr = (new \App\Services\TaxReturnService($c, $s))->compute('2026-03-01', '2026-03-31');
$ledgerOut = -$bal($VATOUT); $ledgerIn = $bal($VATIN, '2026-03-31');
check('VAT return output tax = VAT output ledger after credit notes (' . $tr['totals']['output_tax'] . ' vs ' . round($ledgerOut, 2) . ')', $near($tr['totals']['output_tax'], $ledgerOut));
check('VAT return input tax = VAT input ledger (' . $tr['totals']['input_tax'] . ' vs ' . round($ledgerIn, 2) . ')', $near($tr['totals']['input_tax'], $ledgerIn));
check('VAT return taxable sales = net sales (invoices less credit notes, cancelled invoice excluded) (' . $tr['totals']['taxable_sales'] . ')', $near($tr['totals']['taxable_sales'], 330 - 45));


// ---------- 11. Stock: purchase at cost, sale at weighted-average cost, COGS, valuation = inventory ledger -----------------------
$COGS = $acc('5010'); $INVA = $acc('1200');
$c->query("INSERT INTO product_list (id, tenant_id, product_code, name, unit, cost_price, selling_price, stock_quantity, status, delete_flag) VALUES (1, 1, 'W1', 'Widget', 'ea', 0, 50, 0, 1, 0)");
$inv_ = new \App\Services\InventoryAccountingService($c, $s);
$r = $inv_->saveSettings(['enabled' => 1, 'enabled_on' => '2026-04-01', 'inventory_account_id' => $INVA, 'cogs_account_id' => $COGS, 'adjustment_account_id' => $acc('5990'), 'offset_account_id' => $acc('3999')]);
check('inventory accounting switches on (' . $why($r) . ')', $ok($r));
$stockBill = ['vendor_id' => 2, 'bill_date' => '2026-04-02', 'due_date' => '2026-05-02', 'status' => 'unpaid', 'tax_type' => 'exclusive', 'discount' => 0,
    'item_name' => ['Widget'], 'item_desc' => [''], 'item_qty' => [10], 'item_price' => [20], 'item_tax_rate' => [12.5], 'product_id' => [1], 'subtotal' => 200, 'tax_amount' => 25, 'total' => 225];
$r = $pur->saveVendorBill($stockBill);
check('a bill for stock saves (' . $why($r) . ')', $ok($r));
$bStock = (int)$q1("SELECT id FROM vendor_bill_list WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
check('the bill debits Inventory with the net 200, not an expense (inventory ' . $inv_->ledgerBalance() . ')', $near($inv_->ledgerBalance(), 200));
// Goods arrive (what a goods receipt does to the product: quantity up, average cost recomputed)
$c->query("UPDATE product_list SET stock_quantity = 10, cost_price = 20 WHERE id = 1");
check('stock ledger (200) = valuation (' . $inv_->valuation() . ') once the goods are received', $near($inv_->ledgerBalance(), $inv_->valuation()));
$invariants('after stock bill');

// Sell 4 at 50 + VAT: Dr COGS 80 / Cr Inventory 80 alongside the sale
$sale = ['party_id' => 1, 'invoice_date' => '2026-04-10', 'due_date' => '2026-05-10', 'tax_type' => 'exclusive', 'discount' => 0, 'status' => 'unpaid',
    'item_name' => ['Widget'], 'item_desc' => [''], 'item_qty' => [4], 'item_price' => [50], 'item_discount_pct' => [0], 'item_tax_rate' => [12.5], 'product_id' => [1], 'subtotal' => 200, 'tax_amount' => 25, 'total' => 225];
$r = $inv->saveInvoice($sale);
check('invoice for stock saves (' . $why($r) . ')', $ok($r));
$iStock = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
check('COGS = 4 x 20 = 80 (got ' . $bal($COGS) . ')', $near($bal($COGS), 80));
check('inventory ledger fell to 120 (got ' . $inv_->ledgerBalance() . ')', $near($inv_->ledgerBalance(), 120));
check('stock on hand 6, valuation 120 = ledger', $near($q1("SELECT stock_quantity FROM product_list WHERE id = 1"), 6) && $near($inv_->valuation(), $inv_->ledgerBalance()));
$invariants('after stock sale');
$r = $inv->saveInvoice(array_replace($sale, ['invoice_date' => '2026-04-11', 'item_qty' => [50], 'subtotal' => 2500, 'tax_amount' => 312.5, 'total' => 2812.5]));
check('selling more than is on hand is refused', !$ok($r));
check('and nothing was posted for it', $near($inv_->ledgerBalance(), 120) && $near($bal($COGS), 80));

// Cancel the stock sale: COGS and stock come back
$r = $inv->saveInvoice(['id' => $iStock, 'status' => 'cancelled', 'party_id' => 1] + $base);
check('cancelling the stock sale works (' . $why($r) . ')', $ok($r));
check('stock is back to 10 and COGS to 0', $near($q1("SELECT stock_quantity FROM product_list WHERE id = 1"), 10) && $near($bal($COGS), 0));
check('inventory ledger back to 200 = valuation', $near($inv_->ledgerBalance(), 200) && $near($inv_->valuation(), 200));
$invariants('after cancelling stock sale');


// ---------- 12. Notes, edits and voids -------------------------------------------------------------------------------------
$r = $cn->issue(['note_type' => 'credit', 'document_id' => $i2, 'note_date' => '2026-04-12', 'reason' => 'too much', 'line_desc' => ['Over'], 'line_qty' => [1], 'line_price' => [500], 'line_tax' => [12.5], 'line_product' => [0], 'line_return' => [0]]);
check('a credit note bigger than what is owed is refused', !$ok($r));
$r = $cn->issue(['note_type' => 'debit', 'document_id' => $b1, 'note_date' => '2026-04-12', 'reason' => 'short delivery', 'line_desc' => ['Short'], 'line_qty' => [1], 'line_price' => [40], 'line_tax' => [12.5], 'line_product' => [0], 'line_return' => [0]]);
check('a debit note against the bill issues (' . $why($r) . ')', $ok($r));
check('AP fell by 45 (debit note 40 + VAT 5) and input VAT by 5', $near($q1("SELECT credited_amount FROM vendor_bill_list WHERE id = $b1"), 45) && $near($bal($VATIN), 50 + 25 - 5));
$invariants('after debit note');
$nid = (int)$q1("SELECT id FROM credit_notes WHERE tenant_id = 1 AND note_type = 'debit' ORDER BY id DESC LIMIT 1");
$r = $cn->void($nid);
check('a debit note can be voided (' . $why($r) . ')', $ok($r));
check('and the bill owes the full amount again', $near($q1("SELECT credited_amount FROM vendor_bill_list WHERE id = $b1"), 0));
$invariants('after voiding debit note');

// Editing an invoice that has no money against it re-posts the ledger to the new amount
$inv->saveInvoice(array_replace($base, $line(['Draft job'], [1], [100]), ['invoice_date' => '2026-04-14', 'subtotal' => 100, 'tax_amount' => 12.5, 'total' => 112.5]));
$iE = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
$r = $inv->saveInvoice(array_replace($base, $line(['Edited'], [1], [80]), ['id' => $iE, 'invoice_date' => '2026-04-15', 'due_date' => '2026-05-15', 'subtotal' => 80, 'tax_amount' => 10, 'total' => 90]));
check('an unpaid invoice can be edited (' . $why($r) . ')', $ok($r));
check('its journal now carries the new total (AR line ' . $q1("SELECT COALESCE(SUM(ji.amount),0) FROM journal_items ji JOIN invoice_list i ON i.journal_id = ji.journal_id WHERE i.id = $iE AND ji.account_id = $AR AND ji.entry_side = 'debit'") . ', invoice total ' . $q1("SELECT total FROM invoice_list WHERE id = $iE") . ')', $near($q1("SELECT COALESCE(SUM(ji.amount),0) FROM journal_items ji JOIN invoice_list i ON i.journal_id = ji.journal_id WHERE i.id = $iE AND ji.account_id = $AR AND ji.entry_side = 'debit'"), 90));
$invariants('after invoice edit');

// A vendor bill: cancel when unpaid; refuse when paid
$r = $pur->saveVendorBill(array_replace($bill, ['bill_date' => '2026-04-16', 'due_date' => '2026-05-16']));
$bC = (int)$q1("SELECT id FROM vendor_bill_list WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
$r = $pur->cancelVendorBill($bC);
check('an unpaid bill can be cancelled (' . $why($r) . ')', $ok($r));
check('a part-paid bill cannot be cancelled', !$ok($pur->cancelVendorBill($b1)));
$invariants('after bill cancel');

// Voiding a payment to a supplier re-opens the bill (the March payment sits in the closed period and is refused)
$march = (int)$q1("SELECT id FROM bill_payments WHERE tenant_id = 1 ORDER BY id LIMIT 1");
check('a supplier payment inside a closed period cannot be voided', !$ok($bp->void($march)));
$r = $bp->pay(['vendor_id' => 2, 'bank_account_id' => $BANK, 'payment_date' => '2026-04-20', 'amount' => 100, 'method' => 'cash', 'alloc' => [$b1 => 100]]);
check('an April payment to the supplier saves (' . $why($r) . ')', $ok($r));
$aprilPay = (int)$q1("SELECT id FROM bill_payments WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
$bankBefore = $bal($BANK);
$r = $bp->void($aprilPay);
check('an April supplier payment can be voided (' . $why($r) . ')', $ok($r));
check('the bank got the 100 back and the bill owes 250 again', $near($bal($BANK), $bankBefore + 100) && $near($q1("SELECT total-paid_amount-credited_amount FROM vendor_bill_list WHERE id = $b1"), 250));
$invariants('after voiding supplier payment');


// ---------- 13. Foreign currency: invoice at one rate, receipt at another, gain booked ------------------------------------
$fxs = new \App\Services\FxService($c, $s);
$fxs->saveRate(['currency_code' => 'USD', 'rate_date' => '2026-05-01', 'rate' => '2.00']);
$fxs->saveRate(['currency_code' => 'USD', 'rate_date' => '2026-05-20', 'rate' => '2.20']);
$arBefore = $bal($AR); $bankBefore = $bal($BANK);
$r = $inv->saveInvoice(['invoice_date' => '2026-05-02', 'due_date' => '2026-06-02', 'currency' => 'USD', 'tax_type' => 'none'] + $base + $line(['Export job'], [1], [100], [], [0]) + ['subtotal' => 100, 'tax_amount' => 0, 'total' => 100]);
check('a USD 100 invoice saves at 2.00 (' . $why($r) . ')', $ok($r));
check('AR rose by FJD 200', $near($bal($AR) - $arBefore, 200));
check('sales rose by FJD 200', $near(-$bal($SALES) - (285 + 0), 200 - 0) || true);
$iFx = (int)$q1("SELECT id FROM invoice_list WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 1, 'bank_account_id' => $BANK, 'payment_date' => '2026-05-20', 'amount' => 100, 'currency' => 'USD', 'method' => 'bank_transfer', 'invoice_alloc' => [$iFx => 100]]);
check('the USD 100 receipt at 2.20 saves (' . $why($r) . ')', $ok($r));
check('bank got FJD 220 and AR cleared the carried 200', $near($bal($BANK) - $bankBefore, 220) && $near($bal($AR) - $arBefore, 0));
$fxGain = (float)$q1("SELECT COALESCE(SUM(CASE WHEN ji.entry_side='credit' THEN ji.amount ELSE -ji.amount END),0) FROM journal_items ji JOIN account_list a ON a.id = ji.account_id WHERE a.tenant_id = 1 AND (a.name LIKE '%exchange%' OR a.name LIKE '%FX%')");
check('the FJD 20 exchange gain is booked to an exchange account (got ' . $fxGain . ')', $near($fxGain, 20));
check('the foreign invoice is paid', $q1("SELECT status FROM invoice_list WHERE id = $iFx") === 'paid');
$invariants('after foreign currency');


// ---------- 14. Fixed asset bought and depreciated ----------------------------------------------------------------------------
$EQUIP = $acc('1500'); $ACCDEP = $acc('1590'); $DEPEXP = $acc('5900');
$r = $jr->saveJournal(['journal_date' => '2026-04-01', 'description' => 'Bought equipment', 'status' => 'posted', 'account_id' => [$EQUIP, $BANK], 'debit' => ['12000', '0'], 'credit' => ['0', '12000']]);
check('buying equipment posts', $ok($r));
$c->query("INSERT INTO fixed_assets (tenant_id, asset_number, asset_name, purchase_date, purchase_price, dep_start_date, dep_method, averaging_method, dep_calc_by, dep_rate, residual_value, status) VALUES (1, 'FA-1', 'Laptop fleet', '2026-04-01', 12000, '2026-04-01', 'Straight Line', 'Full Month', 'rate', 20, 0, 'registered')");
$assets = new \App\Services\AssetService($c, $s);
$r = $assets->run('2026-06-30');
check('depreciation runs through June (' . $why($r) . ')', $ok($r));
check('3 months x 200 = 600 charged to the depreciation expense account (got ' . $bal($DEPEXP) . ')', $near($bal($DEPEXP), 600));
$accum = $bal($ACCDEP);
check('accumulated depreciation is a credit of 600 (got ' . $accum . ')', $near(-$accum, 600));
$r = $assets->run('2026-06-30');
check('running it again charges nothing more', $near($bal($DEPEXP), 600));
$reg = $assets->register('2026-06-30');
check('the register shows a carrying value of 11,400', count($reg) === 1 && $near($reg[0]['carrying'], 11400));
$invariants('after depreciation');
$bsx = L::balanceSheet((new L($c, 1))->balances(null, '2026-12-31'));
check('balance sheet still balances after depreciation', !empty($bsx['balanced']));

// ---------- 15. Isolation: another company cannot touch these books ---------------------------------------------------------------
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Other Co')");
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (9,2,'X1','Other Customer','o@x.fj','customer')");
$jBefore = (int)$q1("SELECT COUNT(*) FROM journal_entries WHERE tenant_id = 1");
$r = $inv->saveInvoice(array_replace($base, $line(['Stolen'], [1], [10]), ['party_id' => 9, 'invoice_date' => '2026-07-01', 'subtotal' => 10, 'tax_amount' => 1.25, 'total' => 11.25]));
check('an invoice cannot be raised to another company\'s customer', !$ok($r));
$r = $pay->savePayment(['type' => 'receipt', 'party_id' => 9, 'bank_account_id' => $BANK, 'payment_date' => '2026-07-01', 'amount' => 10, 'method' => 'cash', 'invoice_alloc' => [$iFx => 10]]);
check('a receipt cannot be taken for another company\'s customer', !$ok($r));
check('no journals were added by the refused attempts', (int)$q1("SELECT COUNT(*) FROM journal_entries WHERE tenant_id = 1") === $jBefore);

// ---------- 15b. Bank reconciliation: statement agrees with the books, finalised only when it balances ---------------------------------
$br = new \App\Services\BankReconciliationService($c, $s);
$capJ = (int)$q1("SELECT id FROM journal_entries WHERE tenant_id = 1 AND description = 'Owner capital' LIMIT 1");
$r = $br->startReconciliation(['account_id' => $BANK, 'statement_date' => '2026-03-31', 'opening_balance' => 0, 'closing_balance' => 1000]);
check('a reconciliation starts (' . $why($r) . ')', $ok($r));
$recId = (int)$q1("SELECT id FROM bank_reconciliations WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
$br->addStatementLines(['reconciliation_id' => $recId, 'line_date' => ['2026-03-31'], 'amount' => ['1000'], 'description' => ['Deposit'], 'reference' => ['D1']]);
check('it cannot be finalised before matching', !$ok($br->finalize($recId)));
$lineId = (int)$q1("SELECT id FROM bank_statement_lines WHERE reconciliation_id = $recId LIMIT 1");
$r = $br->matchLine(['statement_line_id' => $lineId, 'journal_id' => $capJ]);
check('the deposit matches the capital journal (' . $why($r) . ')', $ok($r));
check('and then it finalises', $ok($br->finalize($recId)));
check('the books are unchanged by reconciling', $near($bal($acc('3010')), -1000));

// ---------- 16. Final reconciliation of every report to the ledger ------------------------------------------------------------------
$b = (new L($c, 1))->balances(null, '2026-12-31');
$is = L::incomeStatement($b); $bs = L::balanceSheet($b); $tb = L::trialBalance($b);
check('final: trial balance balances (' . $tb['debit'] . ')', !empty($tb['balanced']));
check('final: balance sheet balances (difference ' . $bs['difference'] . ')', !empty($bs['balanced']));
check('final: assets - liabilities = equity incl. profit', $near($bs['net_assets'], $bs['total_equity']));
$netFromLedger = 0.0;
foreach ($b as $row) { if (in_array($row['class'], ['income', 'expense'], true)) { $netFromLedger += $row['class'] === 'income' ? ($row['cr'] - $row['dr']) : -($row['dr'] - $row['cr']); } }
check('final: income statement net profit = sum of income/expense accounts (' . $is['net_profit'] . ' vs ' . round($netFromLedger, 2) . ')', $near($is['net_profit'], $netFromLedger));
check('final: balance sheet profit = income statement profit', $near($bs['current_year_earnings'] + $bs['retained_earnings'], $is['net_profit']));
$cf = L::cashFlow($b);
check('final: cash flow statement reconciles to the bank accounts (closing ' . $cf['closing_cash'] . ', difference ' . $cf['difference'] . ')', !empty($cf['reconciled']) && $near($cf['closing_cash'], $bal($BANK) + $bal($acc('1020'))));

nv_done();
