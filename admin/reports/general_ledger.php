<?php
/**
 * General ledger.
 *  - All accounts: opening, debits, credits and closing per account (click to drill in).
 *  - One account: every transaction with a running balance.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['this_month'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['this_month'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$account_id = isset($_GET['account_id']) && ctype_digit((string)$_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$hide_empty = !isset($_GET['all']) || $_GET['all'] !== '1';

$ledger = nvr_ledger();

// Account picker
$acc_opts = '<option value="">All accounts (summary)</option>';
$stmt = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), code, name");
$tid = (int)$_settings->active_tenant_id();
if ($stmt) {
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $res = $stmt->get_result();
    $groups = [];
    foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $a) {
        $groups[LedgerService::CLASS_LABELS[LedgerService::classify($a['type'])['class']]][] = $a;
    }
    foreach (['Assets', 'Liabilities', 'Equity', 'Income', 'Expenses'] as $g) {
        if (empty($groups[$g])) { continue; }
        $acc_opts .= '<optgroup label="' . $g . '">';
        foreach ($groups[$g] as $a) {
            $acc_opts .= '<option value="' . (int)$a['id'] . '"' . ($account_id === (int)$a['id'] ? ' selected' : '') . '>'
                . htmlspecialchars(trim(($a['code'] ? $a['code'] . ' · ' : '') . $a['name'])) . '</option>';
        }
        $acc_opts .= '</optgroup>';
    }
}
$extra = '<div class="nvr-field nvr-wide"><label for="nvr-acc">Account</label><select id="nvr-acc" name="account_id" class="custom-select nvr-select2">' . $acc_opts . '</select></div>';
if (!$account_id) {
    $extra .= '<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="all" value="1"' . ($hide_empty ? '' : ' checked') . '> Include accounts without movement</label></div>';
}

$detail = $account_id ? $ledger->accountTransactions($account_id, $from, $to) : null;
$title = $detail ? 'Account Transactions' : 'General Ledger';

nvr_header([
    'page' => 'reports/general_ledger',
    'title' => $title,
    'icon' => 'fas fa-book-open',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'extra' => $extra,
    'subtitle' => ($detail ? trim(($detail['account']['code'] ? $detail['account']['code'] . ' · ' : '') . $detail['account']['name']) . ' · ' : '')
        . nvr_nice_date($from) . ' – ' . nvr_nice_date($to),
]);

if ($detail):
    $a = $detail['account'];
    ?>
    <div class="nvr-summary">
        <div class="nvr-stat"><div class="k">Opening balance</div><div class="v"><?= nvr_money($detail['opening'], false) ?></div><div class="s"><?= nvr_nice_date($from) ?></div></div>
        <div class="nvr-stat"><div class="k">Debits</div><div class="v"><?= nvr_money($detail['debit'], false) ?></div><div class="s"><?= count($detail['lines']) ?> transaction<?= count($detail['lines']) === 1 ? '' : 's' ?></div></div>
        <div class="nvr-stat"><div class="k">Credits</div><div class="v"><?= nvr_money($detail['credit'], false) ?></div></div>
        <div class="nvr-stat"><div class="k">Closing balance</div><div class="v<?= $detail['closing'] < 0 ? ' neg' : '' ?>"><?= nvr_money($detail['closing'], false) ?></div><div class="s"><?= htmlspecialchars($a['type'] ?: 'Unclassified') ?> · normal <?= $a['normal'] ?? (in_array($a['class'], ['asset', 'expense'], true) ? 'debit' : 'credit') ?> balance</div></div>
    </div>
    <div class="nvr-scroll">
    <table class="nvr-table">
        <thead>
            <tr><th>Date</th><th>Journal</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            <tr class="open"><td colspan="5">Opening balance</td><td class="num"><?= nvr_money($detail['opening'], false) ?></td></tr>
            <?php foreach ($detail['lines'] as $l): ?>
            <tr class="acct">
                <td style="white-space:nowrap"><?= date('j M Y', strtotime($l['date'])) ?></td>
                <td style="white-space:nowrap"><a class="nvr-drill" href="javascript:void(0)" onclick="uni_modal('Journal <?= htmlspecialchars($l['code'], ENT_QUOTES) ?>','journals/view_details.php?id=<?= $l['journal_id'] ?>','modal-lg')"><?= htmlspecialchars($l['code']) ?></a></td>
                <td><?= htmlspecialchars($l['description']) ?></td>
                <td class="num"><?= $l['debit'] ? nvr_money($l['debit']) : '' ?></td>
                <td class="num"><?= $l['credit'] ? nvr_money($l['credit']) : '' ?></td>
                <td class="num<?= $l['balance'] < 0 ? ' neg' : '' ?>"><?= nvr_money($l['balance'], false) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$detail['lines']): ?>
            <tr><td colspan="6" class="text-center muted" style="padding:24px">No transactions in this period.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="grand"><td colspan="3">Closing balance</td><td class="num"><?= nvr_money($detail['debit'], false) ?></td><td class="num"><?= nvr_money($detail['credit'], false) ?></td><td class="num"><?= nvr_money($detail['closing'], false) ?></td></tr>
        </tfoot>
    </table>
    </div>
    <?php
    nvr_footer(['Balances are shown in the account\'s natural sign: positive means a normal balance, brackets mean the account has swung the other way.']);
else:
    $rows = $ledger->balances($from, $to, !$hide_empty);
    if ($hide_empty) {
        $rows = array_filter($rows, fn ($r) => $r['dr'] != 0.0 || $r['cr'] != 0.0);
    }
    usort($rows, fn ($x, $y) => array_search($x['class'], ['asset', 'liability', 'equity', 'income', 'expense']) <=> array_search($y['class'], ['asset', 'liability', 'equity', 'income', 'expense']));
    $tdr = array_sum(array_column($rows, 'dr'));
    $tcr = array_sum(array_column($rows, 'cr'));
    ?>
    <?php if (!$rows): ?>
        <div class="nvr-empty"><i class="fas fa-book-open"></i><b>No transactions in this period</b>Try a wider date range or tick “Include accounts without movement”.</div>
    <?php else: ?>
    <div class="nvr-checks"><?= nvr_check(abs($tdr - $tcr) < 0.005, $tdr - $tcr, 'Period debits equal credits', 'Period out of balance') ?></div>
    <div class="nvr-scroll">
    <table class="nvr-table">
        <thead>
            <tr><th>Account</th><th>Code</th><th class="num">Opening</th><th class="num">Debits</th><th class="num">Credits</th><th class="num">Closing</th></tr>
        </thead>
        <tbody>
        <?php $cls = null; foreach ($rows as $r):
            $open = LedgerService::natural($r['class'], $r['open_dr'], $r['open_cr']);
            $close = LedgerService::natural($r['class'], $r['open_dr'] + $r['dr'], $r['open_cr'] + $r['cr']);
            if ($r['class'] !== $cls): $cls = $r['class']; ?>
                <tr class="sec"><td colspan="6"><?= LedgerService::CLASS_LABELS[$cls] ?></td></tr>
            <?php endif; ?>
            <tr class="acct">
                <td><?= nvr_gl_link($r['id'], $from, $to, $r['name']) ?></td>
                <td class="code"><?= htmlspecialchars($r['code']) ?></td>
                <td class="num"><?= nvr_money($open) ?></td>
                <td class="num"><?= nvr_money($r['dr']) ?></td>
                <td class="num"><?= nvr_money($r['cr']) ?></td>
                <td class="num<?= $close < 0 ? ' neg' : '' ?>"><?= nvr_money($close) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="grand"><td colspan="3">Total movement</td><td class="num"><?= nvr_money($tdr, false) ?></td><td class="num"><?= nvr_money($tcr, false) ?></td><td></td></tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
    <?php
    nvr_footer(['Choose an account above, or click one in the list, to see each transaction with a running balance.']);
endif;
