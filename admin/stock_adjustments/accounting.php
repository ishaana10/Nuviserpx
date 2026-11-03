<?php
/** Inventory accounting: post stock to the ledger (cost of sales and inventory) instead of units only. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\InventoryAccountingService($conn, $_settings);
$cfg = $svc->config();
$choices = $svc->accountChoices();
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();

$valuation = $svc->valuation();
$ledger = $svc->isEnabled() ? $svc->ledgerBalance() : null;
$diff = $ledger === null ? null : round($valuation - $ledger, 2);

// A sensible starting point: pick each account by its class, never by guessing at names for posting.
$suggest = function (string $class, array $names) use ($choices): int {
    foreach ($choices as $id => $a) {
        if (\App\Services\LedgerService::classify($a['type'])['class'] === $class && in_array(strtolower($a['name']), $names, true)) {
            return (int)$id;
        }
    }
    return 0;
};
$pick = [
    'inventory_account_id'  => $cfg['inventory_account_id'] ?: $suggest('asset', ['inventory', 'stock', 'stock on hand']),
    'cogs_account_id'       => $cfg['cogs_account_id'] ?: $suggest('expense', ['cost of sales', 'cost of goods sold', 'cogs']),
    'adjustment_account_id' => $cfg['adjustment_account_id'] ?: $suggest('expense', ['stock adjustments', 'inventory adjustments', 'cost of sales']),
    'offset_account_id'     => $cfg['offset_account_id'] ?: $suggest('equity', ['opening balance equity']),
];
$fields = [
    'inventory_account_id'  => ['Inventory account', 'asset', 'Where the cost of stock on hand is held (an asset).'],
    'cogs_account_id'       => ['Cost of sales account', 'expense', 'Charged with the cost of goods when they are sold.'],
    'adjustment_account_id' => ['Stock adjustments account', 'expense', 'Takes stock gains and losses from counts, write-offs and stock-ins that are not purchases.'],
    'offset_account_id'     => ['Opening stock account', 'equity', 'Balances the opening inventory posted when this is switched on, and opening stock entered on new products.'],
];
$classOf = fn ($t) => \App\Services\LedgerService::classify($t)['class'];
?>
<div class="card card-outline card-info shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-balance-scale text-info mr-2"></i> Inventory accounting</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            When this is on, the ledger follows the stock: a sale books <b>Dr Cost of Sales / Cr Inventory</b> at the product's average cost,
            supplier bills for stock products go to <b>Inventory</b> instead of Purchases, and stock gains and losses are valued.
            Selling more than is in stock is refused. When you switch it on, the stock you hold now is posted once as opening inventory.
            <br>Choose the accounts below; nothing is picked by name behind your back.
        </div>
        <?php if ($cfg['enabled']): ?>
        <div class="row mb-3">
            <div class="col-md-4"><div class="border rounded p-2"><div class="text-muted text-sm">Stock valuation<?= $tag ?></div><div class="h5 mb-0"><?= number_format($valuation, 2) ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-2"><div class="text-muted text-sm">Inventory account balance<?= $tag ?></div><div class="h5 mb-0"><?= number_format((float)$ledger, 2) ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-2 <?= abs((float)$diff) < 0.01 ? '' : 'border-warning' ?>"><div class="text-muted text-sm">Difference</div>
                <div class="h5 mb-0"><?= number_format((float)$diff, 2) ?></div>
                <small class="text-muted"><?= abs((float)$diff) < 0.01 ? 'The ledger agrees with the stock.' : 'Usually goods received but not yet billed, or a bill dated after receipt.' ?></small></div></div>
        </div>
        <?php endif; ?>
        <form id="inv-acc-form">
            <?php foreach ($fields as $name => [$label, $class, $help]): ?>
            <div class="form-group row">
                <label class="col-md-3 col-form-label"><?= $e($label) ?></label>
                <div class="col-md-5">
                    <select name="<?= $e($name) ?>" class="form-control form-control-sm">
                        <option value="">— choose —</option>
                        <?php foreach ($choices as $id => $a): if ($classOf($a['type']) !== $class) continue; ?>
                            <option value="<?= (int)$id ?>" <?= (int)$pick[$name] === (int)$id ? 'selected' : '' ?>><?= $e($a['name']) ?> (<?= $e($a['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 text-muted text-sm pt-1"><?= $e($help) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($cfg['opening_journal_id'])): ?>
            <div class="form-group row">
                <label class="col-md-3 col-form-label">Opening inventory dated</label>
                <div class="col-md-3"><input type="date" name="enabled_on" class="form-control form-control-sm" value="<?= $e(date('Y-m-d')) ?>"></div>
                <div class="col-md-6 text-muted text-sm pt-1">Stock now on hand at cost: <b><?= number_format($valuation, 2) ?></b>. The date must be in an open period.</div>
            </div>
            <?php endif; ?>
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" class="custom-control-input" id="enabled" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                <label class="custom-control-label" for="enabled">Post stock to the ledger</label>
            </div>
            <button class="btn btn-info btn-sm" type="submit"><i class="fas fa-save mr-1"></i> Save</button>
        </form>
    </div>
</div>
<script>
$('#inv-acc-form').on('submit', function(ev){
    ev.preventDefault();
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=save_inventory_accounting', $(this).serialize(), function(r){
        end_loader();
        if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 900); }
        else { alert_toast((r && r.msg) || 'Failed', 'error'); }
    }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
});
</script>
