<?php
/**
 * Statement of cash flows (indirect method): profit, adjusted for non-cash items and working
 * capital (operating), then investing and financing, reconciled to the bank accounts.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$cf = LedgerService::cashFlow(nvr_ledger()->balances($from, $to));

function nvr_cf_row(string $class, string $label, float $amount): string
{
    return '<tr class="' . $class . '"><td>' . $label . '</td><td class="num' . ($amount < 0 ? ' neg' : '') . '">' . nvr_money($amount, $class !== 'acct') . '</td></tr>';
}
function nvr_cf_lines(array $lines, string $from, string $to): void
{
    foreach ($lines as $l) {
        echo nvr_cf_row('acct', nvr_gl_link($l['id'] ?? null, $from, $to, $l['name']), (float)$l['amount']);
    }
}

nvr_header([
    'page' => 'reports/cash_flow',
    'title' => 'Statement of Cash Flows',
    'icon' => 'fas fa-water',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => 'For the period ' . nvr_nice_date($from) . ' to ' . nvr_nice_date($to),
]);
$hasData = $cf['net_change'] != 0.0 || $cf['opening_cash'] != 0.0 || $cf['profit'] != 0.0;
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Operating</div><div class="v<?= $cf['operating'] < 0 ? ' neg' : '' ?>"><?= nvr_money($cf['operating'], false) ?></div><div class="s">Cash from running the business</div></div>
    <div class="nvr-stat"><div class="k">Investing</div><div class="v<?= $cf['investing'] < 0 ? ' neg' : '' ?>"><?= nvr_money($cf['investing'], false) ?></div><div class="s">Assets bought and sold</div></div>
    <div class="nvr-stat"><div class="k">Financing</div><div class="v<?= $cf['financing'] < 0 ? ' neg' : '' ?>"><?= nvr_money($cf['financing'], false) ?></div><div class="s">Loans and owner funds</div></div>
    <div class="nvr-stat"><div class="k">Net change in cash</div><div class="v<?= $cf['net_change'] < 0 ? ' neg' : '' ?>"><?= nvr_money($cf['net_change'], false) ?></div><div class="s">Closing cash <?= nvr_money($cf['closing_cash'], false) ?></div></div>
</div>

<?php if (!$hasData): ?>
    <div class="nvr-empty"><i class="fas fa-water"></i><b>No cash movement in this period</b>Check the dates, or make sure your bank accounts have the Bank account type.</div>
<?php else: ?>
<?php if (!$cf['reconciled']): ?>
    <div class="nvr-empty" style="border-color:#dc3545"><i class="fas fa-exclamation-triangle text-danger"></i><b>This statement is out by <?= number_format(abs($cf['difference']), 2) ?></b>
        It does not agree with the change in your bank accounts. Usually an account has a type that does not match how it is used
        (for example a loan typed as a current liability, or accumulated depreciation typed as an expense). Check the Chart of Accounts.</div>
<?php endif; ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th></th><th class="num"><?= date('j M y', strtotime($from)) ?> – <?= date('j M y', strtotime($to)) ?></th></tr></thead>
    <tbody>
        <tr class="sec"><td colspan="2">Operating activities</td></tr>
        <?= nvr_cf_row('acct', 'Net profit for the period', $cf['profit']) ?>
        <?php if ($cf['non_cash']): ?>
        <tr class="sec"><td colspan="2">Add back non-cash items</td></tr>
        <?php nvr_cf_lines($cf['non_cash'], $from, $to); endif; ?>
        <?php if ($cf['working_assets'] || $cf['working_liabilities']): ?>
        <tr class="sec"><td colspan="2">Changes in working capital</td></tr>
        <?php nvr_cf_lines($cf['working_assets'], $from, $to); nvr_cf_lines($cf['working_liabilities'], $from, $to); endif; ?>
        <?= nvr_cf_row('sub', 'Net cash from operating activities', $cf['operating']) ?>

        <?php if ($cf['investing_lines']): ?>
        <tr class="sec"><td colspan="2">Investing activities</td></tr>
        <?php nvr_cf_lines($cf['investing_lines'], $from, $to); endif; ?>
        <?= nvr_cf_row('sub', 'Net cash from investing activities', $cf['investing']) ?>

        <?php if ($cf['financing_lines']): ?>
        <tr class="sec"><td colspan="2">Financing activities</td></tr>
        <?php nvr_cf_lines($cf['financing_lines'], $from, $to); endif; ?>
        <?= nvr_cf_row('sub', 'Net cash from financing activities', $cf['financing']) ?>

        <?= nvr_cf_row('key', 'Net change in cash', $cf['net_change']) ?>
        <?= nvr_cf_row('acct', 'Cash at start of period', $cf['opening_cash']) ?>
        <?= nvr_cf_row('grand', 'Cash at end of period', $cf['closing_cash']) ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Indirect method: starts from profit and adjusts for depreciation and amortisation and for changes in receivables, stock, prepayments and payables. Increases in assets reduce cash; increases in liabilities and equity add to it.']);
