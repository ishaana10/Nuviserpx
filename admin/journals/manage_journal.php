<?php
/**
 * Manual journal: create, edit, copy (?copy=ID) or reverse (?reverse=ID).
 * Saving goes through Master.php?f=save_journal → JournalService, which validates
 * and writes the journal in one transaction.
 */
require_once(__DIR__ . '/../../config.php');

use App\Services\JournalService;
use App\Services\LedgerService;

$tenant_id = (int)$_settings->active_tenant_id();

/* ---- Accounts, grouped for the picker ---------------------------------- */
$accounts_by_class = [];
$acc_stmt = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1
                            ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), code, name");
$acc_stmt->bind_param('i', $tenant_id);
$acc_stmt->execute();
foreach ($acc_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $accounts_by_class[LedgerService::classify($a['type'])['class']][] = $a;
}
$class_order = ['asset', 'liability', 'equity', 'income', 'expense'];

/* ---- Load an existing journal (edit, copy or reverse) ------------------ */
$journal = null;
$lines = [];
$mode = 'new';
$load_id = 0;
if (!empty($_GET['id'])) {
    $mode = 'edit';
    $load_id = (int)$_GET['id'];
} elseif (!empty($_GET['copy'])) {
    $mode = 'copy';
    $load_id = (int)$_GET['copy'];
} elseif (!empty($_GET['reverse'])) {
    $mode = 'reverse';
    $load_id = (int)$_GET['reverse'];
}

if ($load_id) {
    $stmt = $conn->prepare("SELECT j.*, CONCAT(u.firstname, ' ', u.lastname) author
                            FROM journal_entries j LEFT JOIN users u ON u.id = j.user_id
                            WHERE j.id = ? AND j.tenant_id = ?");
    $stmt->bind_param('ii', $load_id, $tenant_id);
    $stmt->execute();
    $journal = $stmt->get_result()->fetch_assoc() ?: null;

    if ($journal) {
        $side = LedgerService::SIDE_SQL;
        $q = $conn->prepare("SELECT ji.account_id, ji.amount, ji.description, ji.tax_rate, {$side} side
                             FROM journal_items ji LEFT JOIN group_list g ON g.id = ji.group_id
                             WHERE ji.journal_id = ? ORDER BY ji.id");
        $q->bind_param('i', $load_id);
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $isDebit = (int)$r['side'] === 1;
            if ($mode === 'reverse') {
                $isDebit = !$isDebit;
            }
            $lines[] = [
                'account_id' => (int)$r['account_id'],
                'description' => (string)($r['description'] ?? ''),
                'tax_rate' => (string)($r['tax_rate'] ?? 'No Tax (0%)'),
                'debit' => $isDebit ? (float)$r['amount'] : null,
                'credit' => $isDebit ? null : (float)$r['amount'],
            ];
        }
    } elseif ($mode === 'edit') {
        echo '<div class="alert alert-warning m-3">Journal not found. <a href="./?page=journals">Back to journals</a></div>';
        return;
    }
}

$status = strtolower((string)($journal['status'] ?? ''));
$is_cancelled = $mode === 'edit' && in_array($status, ['cancelled', 'canceled', 'void', '2', '3'], true);
$source = null;
if ($mode === 'edit' && $journal) {
    $source = (new JournalService($conn, $_settings))->sourceDocument($load_id, $tenant_id);
}
$read_only = $is_cancelled || $source !== null;

$narration = (string)($journal['description'] ?? '');
$date = $mode === 'edit' && $journal ? (string)$journal['journal_date'] : date('Y-m-d');
$reverse_date = $mode === 'edit' ? (string)($journal['auto_reversing_date'] ?? '') : '';
if ($mode === 'reverse' && $journal) {
    $narration = 'Reversal of ' . $journal['code'] . ($narration !== '' ? ' — ' . $narration : '');
}
$tax_mode = (string)($journal['tax_mode'] ?? 'No Tax');
$default_narration = $journal ? (int)($journal['default_narration'] ?? 1) : 1;
$show_cash_basis = $journal ? (int)($journal['show_cash_basis'] ?? 1) : 1;

while (count($lines) < 2) {
    $lines[] = ['account_id' => 0, 'description' => '', 'tax_rate' => 'No Tax (0%)', 'debit' => null, 'credit' => null];
}

$tax_rates = ['No Tax (0%)', 'VAT on Income (15%)', 'VAT on Expenses (15%)', 'Tax Exempt (0%)'];

$status_label = match (true) {
    $mode !== 'edit' => 'New',
    $is_cancelled => 'Cancelled',
    $status === 'draft' => 'Draft',
    default => 'Posted',
};
$status_tone = ['New' => 'secondary', 'Cancelled' => 'dark', 'Draft' => 'warning', 'Posted' => 'success'][$status_label];
$title = match ($mode) {
    'edit' => $read_only ? 'Journal ' . $journal['code'] : 'Edit journal ' . $journal['code'],
    'copy' => 'Copy of ' . ($journal['code'] ?? 'journal'),
    'reverse' => 'Reverse ' . ($journal['code'] ?? 'journal'),
    default => 'New manual journal',
};

function mj_account_options(array $by_class, array $order, int $selected): string
{
    $h = '<option value=""></option>';
    foreach ($order as $cls) {
        if (empty($by_class[$cls])) {
            continue;
        }
        $h .= '<optgroup label="' . LedgerService::CLASS_LABELS[$cls] . '">';
        foreach ($by_class[$cls] as $a) {
            $label = trim(($a['code'] !== null && $a['code'] !== '' ? $a['code'] . ' · ' : '') . $a['name']);
            $h .= '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === $selected ? ' selected' : '') . ' data-type="' . htmlspecialchars((string)$a['type']) . '">' . htmlspecialchars($label) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

function mj_amount(?float $v): string
{
    return $v === null || $v == 0.0 ? '' : number_format($v, 2, '.', '');
}
?>
<style>
.mj { max-width: 1180px; margin: 0 auto; padding: 4px 2px 32px; }
.mj-top { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 10px; margin-bottom: 16px; }
.mj-back { font-size: .84rem; color: #64748b; }
.mj-back:hover { color: #2563eb; text-decoration: none; }
.mj-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 2px 0 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mj-pill { font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: 3px 10px; border-radius: 999px; }
.mj-pill.secondary { background: #f1f5f9; color: #475569; }
.mj-pill.warning { background: #fffbeb; color: #b45309; box-shadow: inset 0 0 0 1px #fde68a; }
.mj-pill.success { background: #ecfdf3; color: #15803d; box-shadow: inset 0 0 0 1px #bbf7d0; }
.mj-pill.dark { background: #1e293b; color: #f8fafc; }
.mj-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px rgba(15,23,42,.04); margin-bottom: 16px; }
.mj-card-body { padding: 20px 22px; }
.mj-banner { display: flex; gap: 12px; align-items: flex-start; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: .88rem; }
.mj-banner.info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; }
.mj-banner.lock { background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; }
.mj-banner i { margin-top: 2px; }
.mj-opts { display: flex; flex-wrap: wrap; gap: 6px 22px; align-items: center; }
.mj-opts .custom-control-label { font-size: .85rem; color: #475569; font-weight: 400 !important; }

.mj-lines { width: 100%; border-collapse: separate; border-spacing: 0; }
.mj-lines th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 600; padding: 10px 8px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.mj-lines th:first-child { border-top-left-radius: 10px; }
.mj-lines th:last-child { border-top-right-radius: 10px; }
.mj-lines td { padding: 6px 4px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.mj-lines td.n { width: 34px; text-align: center; color: #94a3b8; font-size: .8rem; padding-top: 14px; }
.mj-lines .form-control, .mj-lines .custom-select { height: 38px; font-size: .9rem; }
.mj-lines .amt { text-align: right; font-variant-numeric: tabular-nums; }
.mj-lines .amt::-webkit-outer-spin-button, .mj-lines .amt::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.mj-lines .amt { -moz-appearance: textfield; }
.mj-lines .select2-container .select2-selection--single { height: 38px !important; border-radius: 7px !important; border-color: #d5dde8 !important; }
.mj-lines .select2-container .select2-selection__rendered { line-height: 36px !important; font-size: .9rem; }
.mj-lines .select2-container .select2-selection__arrow { height: 36px !important; }
.mj-lines tr.has-error .select2-selection, .mj-lines tr.has-error .form-control.err { border-color: #dc2626 !important; }
.mj-del { width: 34px; height: 38px; border: 0; background: transparent; color: #cbd5e1; border-radius: 7px; }
.mj-del:hover { background: #fef2f2; color: #dc2626; }
.mj-lines tfoot td { border-bottom: 0; padding-top: 12px; }
.mj-total-row td { font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
.mj-total-row .tot { text-align: right; padding: 10px 12px; background: #f8fafc; border-radius: 8px; }
.mj-diff { display: inline-flex; align-items: center; gap: 8px; font-size: .86rem; font-weight: 600; padding: 6px 12px; border-radius: 8px; }
.mj-diff.ok { background: #ecfdf3; color: #15803d; }
.mj-diff.bad { background: #fef2f2; color: #b91c1c; }
.mj-diff.idle { background: #f1f5f9; color: #64748b; }
.mj-errors { display: none; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: .88rem; }
.mj-errors ul { margin: 6px 0 0; padding-left: 18px; }
.mj-actions { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; align-items: center; padding: 12px 22px; background: rgba(255,255,255,.96); border-top: 1px solid #e2e8f0; border-radius: 0 0 12px 12px; }
.mj-actions .hint { margin-right: auto; font-size: .8rem; color: #94a3b8; }
.mj-actions .btn { height: 38px; display: inline-flex; align-items: center; gap: 6px; }
.mj-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; font-size: .85rem; }
.mj-meta .k { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; font-weight: 600; }
.mj-meta .v { color: #1e293b; font-weight: 500; }
@media (max-width: 767.98px) {
  .mj-lines thead { display: none; }
  .mj-lines, .mj-lines tbody, .mj-lines tr, .mj-lines td { display: block; width: 100%; }
  .mj-lines tbody tr { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px; margin-bottom: 10px; position: relative; }
  .mj-lines td { border: 0; padding: 4px; }
  .mj-lines td.n { display: none; }
  .mj-lines td[data-label]::before { content: attr(data-label); display: block; font-size: .7rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; margin-bottom: 2px; }
  .mj-lines td.x { position: absolute; top: 4px; right: 4px; width: auto; }
  .mj-lines tfoot tr { display: flex; flex-wrap: wrap; gap: 6px; }
}
</style>

<div class="mj">
    <div class="mj-top">
        <div>
            <a href="./?page=journals" class="mj-back"><i class="fas fa-arrow-left mr-1"></i> Manual journals</a>
            <h1 class="mj-title"><?= htmlspecialchars($title) ?> <span class="mj-pill <?= $status_tone ?>"><?= $status_label ?></span></h1>
        </div>
        <?php if ($mode === 'edit' && $journal): ?>
        <div class="d-flex" style="gap:8px">
            <a class="btn btn-default btn-sm" href="./?page=journals/manage_journal&copy=<?= $load_id ?>"><i class="far fa-clone mr-1"></i> Copy</a>
            <?php if (!$is_cancelled && !$source && $status !== 'draft'): ?>
            <a class="btn btn-default btn-sm" href="./?page=journals/manage_journal&reverse=<?= $load_id ?>"><i class="fas fa-exchange-alt mr-1"></i> Reverse</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($source): ?>
        <div class="mj-banner lock"><i class="fas fa-lock"></i><div>This journal was created automatically by <b><?= htmlspecialchars($source) ?></b>. To change it, edit or cancel that document — the ledger will follow.</div></div>
    <?php elseif ($is_cancelled): ?>
        <div class="mj-banner lock"><i class="fas fa-ban"></i><div>This journal is cancelled and no longer affects your reports. Use <b>Copy</b> to start a new one from it.</div></div>
    <?php elseif ($mode === 'reverse'): ?>
        <div class="mj-banner info"><i class="fas fa-exchange-alt"></i><div>Debits and credits from <b><?= htmlspecialchars($journal['code'] ?? '') ?></b> have been swapped. Pick the date the reversal should take effect, then post.</div></div>
    <?php elseif ($mode === 'new'): ?>
        <div class="mj-banner info"><i class="fas fa-info-circle"></i><div>Manual journals adjust your ledger directly. Each line needs an account and either a debit or a credit, and the totals must match before you can post.</div></div>
    <?php endif; ?>

    <div class="mj-errors" id="mj-errors" role="alert"></div>

    <form id="journal-form" autocomplete="off" novalidate>
        <input type="hidden" name="id" value="<?= $mode === 'edit' ? $load_id : '' ?>">
        <input type="hidden" name="status" id="journal_status" value="<?= $status === 'draft' ? 'draft' : 'posted' ?>">

        <fieldset <?= $read_only ? 'disabled' : '' ?> style="min-width:0">
        <div class="mj-card">
            <div class="mj-card-body">
                <div class="row">
                    <div class="col-lg-6 form-group">
                        <label for="mj-narration">Narration</label>
                        <input type="text" id="mj-narration" name="description" class="form-control" maxlength="500" required
                               placeholder="e.g. Accrue October electricity" value="<?= htmlspecialchars($narration) ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3 form-group">
                        <label for="mj-date">Date</label>
                        <input type="date" id="mj-date" name="journal_date" class="form-control" required value="<?= htmlspecialchars($date) ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3 form-group">
                        <label for="mj-reverse">Auto-reverse on <span class="text-muted font-weight-normal">(optional)</span></label>
                        <input type="date" id="mj-reverse" name="auto_reversing_date" class="form-control" value="<?= htmlspecialchars($reverse_date) ?>">
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap:12px">
                    <div class="mj-opts">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="default_narration" name="default_narration" value="1" <?= $default_narration ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="default_narration">Use narration as line description</label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="show_cash_basis" name="show_cash_basis" value="1" <?= $show_cash_basis ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="show_cash_basis">Show on cash-basis reports</label>
                        </div>
                    </div>
                    <div class="d-flex align-items-center" style="gap:8px">
                        <label for="mj-taxmode" class="mb-0 text-muted" style="font-size:.85rem">Amounts are</label>
                        <select id="mj-taxmode" name="tax_mode" class="custom-select custom-select-sm" style="width:auto">
                            <?php foreach (['No Tax', 'Tax Inclusive', 'Tax Exclusive'] as $tm): ?>
                                <option <?= $tax_mode === $tm ? 'selected' : '' ?>><?= $tm ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="px-3 pb-2" style="overflow-x:auto">
                <table class="mj-lines" id="mj-lines">
                    <thead>
                        <tr>
                            <th></th>
                            <th style="min-width:240px">Account</th>
                            <th style="min-width:200px">Description</th>
                            <th style="min-width:160px">Tax rate</th>
                            <th class="text-right" style="width:140px">Debit</th>
                            <th class="text-right" style="width:140px">Credit</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr class="mj-line">
                            <td class="n"></td>
                            <td data-label="Account"><select name="account_id[]" class="form-control mj-account" data-placeholder="Choose an account"><?= mj_account_options($accounts_by_class, $class_order, (int)$l['account_id']) ?></select></td>
                            <td data-label="Description"><input type="text" name="line_description[]" class="form-control mj-desc" maxlength="500" value="<?= htmlspecialchars($l['description']) ?>"></td>
                            <td data-label="Tax rate"><select name="tax_rate[]" class="custom-select" data-no-select2>
                                <?php foreach ($tax_rates as $tr): ?><option <?= $l['tax_rate'] === $tr ? 'selected' : '' ?>><?= $tr ?></option><?php endforeach; ?>
                            </select></td>
                            <td data-label="Debit"><input type="number" step="0.01" min="0" inputmode="decimal" name="debit[]" class="form-control amt mj-dr" placeholder="0.00" value="<?= mj_amount($l['debit']) ?>"></td>
                            <td data-label="Credit"><input type="number" step="0.01" min="0" inputmode="decimal" name="credit[]" class="form-control amt mj-cr" placeholder="0.00" value="<?= mj_amount($l['credit']) ?>"></td>
                            <td class="x"><button type="button" class="mj-del" title="Remove line" aria-label="Remove line"><i class="fas fa-times"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td colspan="3">
                                <button type="button" class="btn btn-default btn-sm" id="mj-add"><i class="fas fa-plus mr-1"></i> Add line</button>
                                <button type="button" class="btn btn-default btn-sm" id="mj-balance" title="Put the difference on an empty line"><i class="fas fa-balance-scale mr-1"></i> Balance</button>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                        <tr class="mj-total-row">
                            <td></td>
                            <td colspan="3" class="text-right align-middle"><span class="mj-diff idle" id="mj-diff"><i class="fas fa-equals"></i> Enter amounts</span></td>
                            <td><div class="tot" id="mj-tdr">0.00</div></td>
                            <td><div class="tot" id="mj-tcr">0.00</div></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!$read_only): ?>
            <div class="mj-actions">
                <span class="hint d-none d-md-inline"><kbd>Enter</kbd> in the last amount adds a line</span>
                <a href="./?page=journals" class="btn btn-default">Cancel</a>
                <button type="button" class="btn btn-default" id="btn-save-draft"><i class="far fa-save"></i> Save draft</button>
                <button type="button" class="btn btn-primary" id="btn-post"><i class="fas fa-check"></i> <?= $mode === 'edit' && $status !== 'draft' ? 'Save & post' : 'Post' ?></button>
            </div>
            <?php endif; ?>
        </div>
        </fieldset>
    </form>

    <?php if ($mode === 'edit' && $journal): ?>
    <div class="mj-card">
        <div class="mj-card-body">
            <h6 class="font-weight-bold mb-3" style="font-size:.9rem"><i class="fas fa-history text-muted mr-1"></i> History</h6>
            <div class="mj-meta">
                <div><div class="k">Reference</div><div class="v"><?= htmlspecialchars($journal['code']) ?></div></div>
                <div><div class="k">Created</div><div class="v"><?= date('j M Y, g:i a', strtotime($journal['date_created'])) ?><?= !empty(trim((string)$journal['author'])) ? ' by ' . htmlspecialchars($journal['author']) : '' ?></div></div>
                <div><div class="k">Last changed</div><div class="v"><?= !empty($journal['date_updated']) ? date('j M Y, g:i a', strtotime($journal['date_updated'])) : '—' ?></div></div>
                <div><div class="k">Source</div><div class="v"><?= $source ? htmlspecialchars(ucfirst($source)) : 'Manual journal' ?></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<template id="mj-line-tpl">
    <tr class="mj-line">
        <td class="n"></td>
        <td data-label="Account"><select name="account_id[]" class="form-control mj-account" data-placeholder="Choose an account"><?= mj_account_options($accounts_by_class, $class_order, 0) ?></select></td>
        <td data-label="Description"><input type="text" name="line_description[]" class="form-control mj-desc" maxlength="500"></td>
        <td data-label="Tax rate"><select name="tax_rate[]" class="custom-select" data-no-select2>
            <?php foreach ($tax_rates as $tr): ?><option><?= $tr ?></option><?php endforeach; ?>
        </select></td>
        <td data-label="Debit"><input type="number" step="0.01" min="0" inputmode="decimal" name="debit[]" class="form-control amt mj-dr" placeholder="0.00"></td>
        <td data-label="Credit"><input type="number" step="0.01" min="0" inputmode="decimal" name="credit[]" class="form-control amt mj-cr" placeholder="0.00"></td>
        <td class="x"><button type="button" class="mj-del" title="Remove line" aria-label="Remove line"><i class="fas fa-times"></i></button></td>
    </tr>
</template>

<script>
(function () {
    var readOnly = <?= $read_only ? 'true' : 'false' ?>;
    var $body = $('#mj-lines tbody');
    var fmt = function (n) { return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var cents = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : Math.round(n * 100); };

    function initRow($tr) {
        if ($.fn.select2) {
            $tr.find('.mj-account').select2({ width: '100%', placeholder: 'Choose an account' });
        }
    }

    function renumber() {
        $body.children('tr').each(function (i) { $(this).find('td.n').text(i + 1); });
        $body.find('.mj-del').prop('disabled', $body.children('tr').length <= 2);
    }

    function totals() {
        var dr = 0, cr = 0, used = 0;
        $body.children('tr').each(function () {
            var d = cents($(this).find('.mj-dr').val()), c = cents($(this).find('.mj-cr').val());
            dr += d; cr += c;
            if (d || c) { used++; }
        });
        $('#mj-tdr').text(fmt(dr / 100));
        $('#mj-tcr').text(fmt(cr / 100));
        var $d = $('#mj-diff').removeClass('ok bad idle');
        if (!dr && !cr) {
            $d.addClass('idle').html('<i class="fas fa-equals"></i> Enter amounts');
        } else if (dr === cr && used >= 2) {
            $d.addClass('ok').html('<i class="fas fa-check-circle"></i> Balanced');
        } else if (dr === cr) {
            $d.addClass('bad').html('<i class="fas fa-exclamation-circle"></i> Needs at least two lines');
        } else {
            $d.addClass('bad').html('<i class="fas fa-exclamation-triangle"></i> Out by ' + fmt(Math.abs(dr - cr) / 100) +
                (dr > cr ? ' — credits are short' : ' — debits are short'));
        }
        $('#mj-balance').prop('disabled', dr === cr);
        return { dr: dr, cr: cr, used: used };
    }

    function addLine(focus) {
        var $tr = $($('#mj-line-tpl').html());
        if ($('#default_narration').is(':checked')) { $tr.find('.mj-desc').val($('#mj-narration').val()).data('auto', true); }
        $body.append($tr);
        initRow($tr);
        renumber();
        if (focus) { $tr.find('.mj-account').select2 ? $tr.find('.mj-account').select2('open') : $tr.find('.mj-account').focus(); }
        return $tr;
    }

    function showErrors(list) {
        var $e = $('#mj-errors');
        if (!list || !list.length) { $e.hide().empty(); return; }
        $e.html('<b><i class="fas fa-exclamation-triangle mr-1"></i> Please fix the following:</b><ul></ul>');
        $.each(list, function (_, m) { $e.find('ul').append($('<li>').text(m)); });
        $e.show();
        $e[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function markRowErrors() {
        $body.children('tr').removeClass('has-error').find('.err').removeClass('err');
        $body.children('tr').each(function () {
            var $tr = $(this);
            var acc = $tr.find('.mj-account').val();
            var d = cents($tr.find('.mj-dr').val()), c = cents($tr.find('.mj-cr').val());
            if ((d || c) && !acc) { $tr.addClass('has-error'); }
            if (acc && !d && !c) { $tr.addClass('has-error'); $tr.find('.mj-dr, .mj-cr').addClass('err'); }
        });
    }

    function save(status) {
        if (readOnly) { return; }
        $('#journal_status').val(status);
        var errs = [];
        if (!$.trim($('#mj-narration').val())) { errs.push('Enter a narration.'); $('#mj-narration').addClass('is-invalid'); }
        if (!$('#mj-date').val()) { errs.push('Enter the journal date.'); $('#mj-date').addClass('is-invalid'); }
        var rev = $('#mj-reverse').val();
        if (rev && rev <= $('#mj-date').val()) { errs.push('The auto-reverse date must be after the journal date.'); $('#mj-reverse').addClass('is-invalid'); }
        markRowErrors();
        if ($body.children('tr.has-error').length) { errs.push('Each line with an amount needs an account, and each account needs an amount.'); }
        var t = totals();
        if (status === 'posted') {
            if (t.used < 2) { errs.push('A journal needs at least two lines.'); }
            if (t.dr !== t.cr) { errs.push('Debits and credits must be equal before posting — save it as a draft if you need to finish later.'); }
        }
        if (errs.length) { showErrors(errs); return; }
        showErrors(null);

        var $btn = status === 'draft' ? $('#btn-save-draft') : $('#btn-post');
        window.NuvisUI && NuvisUI.setSaving($btn, true);
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=save_journal',
            data: new FormData($('#journal-form')[0]),
            cache: false, contentType: false, processData: false,
            method: 'POST', dataType: 'json'
        }).done(function (resp) {
            if (resp && resp.status === 'success') {
                $('#journal-form').data('nvSaved', true);
                location.href = './?page=journals';
                return;
            }
            showErrors(resp && resp.errors ? resp.errors : [resp && resp.msg ? resp.msg : 'The journal could not be saved.']);
        }).fail(function () {
            showErrors(['The server could not be reached. Your journal has not been saved.']);
        }).always(function () {
            window.NuvisUI && NuvisUI.setSaving($btn, false);
        });
    }

    $(function () {
        $body.children('tr').each(function () { initRow($(this)); });
        renumber();
        totals();
        if (readOnly) { $body.find('.mj-del').hide(); $('#mj-add, #mj-balance').hide(); return; }

        $('#mj-add').on('click', function () { addLine(true); });
        $body.on('click', '.mj-del', function () {
            if ($body.children('tr').length <= 2) { return; }
            $(this).closest('tr').remove();
            renumber(); totals();
        });
        $body.on('input', '.mj-dr, .mj-cr', function () {
            var $tr = $(this).closest('tr');
            if ($(this).val() !== '') { $tr.find($(this).hasClass('mj-dr') ? '.mj-cr' : '.mj-dr').val(''); }
            $tr.removeClass('has-error').find('.err').removeClass('err');
            totals();
        });
        $body.on('change', '.mj-account', function () { $(this).closest('tr').removeClass('has-error'); });
        $body.on('blur', '.amt', function () {
            var v = $(this).val();
            if (v !== '' && !isNaN(parseFloat(v))) { $(this).val((Math.round(parseFloat(v) * 100) / 100).toFixed(2)); }
        });
        $body.on('keydown', '.amt', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if ($(this).closest('tr').is(':last-child')) { addLine(true); }
                else { $(this).closest('tr').next().find('.mj-dr').focus(); }
            }
        });
        $body.on('input', '.mj-desc', function () { $(this).data('auto', false); });

        $('#mj-narration').on('input', function () {
            $(this).removeClass('is-invalid');
            if (!$('#default_narration').is(':checked')) { return; }
            var v = $(this).val();
            $body.find('.mj-desc').each(function () {
                if ($(this).val() === '' || $(this).data('auto')) { $(this).val(v).data('auto', true); }
            });
        });
        $('#mj-date, #mj-reverse').on('change', function () { $(this).removeClass('is-invalid'); });

        $('#mj-balance').on('click', function () {
            var t = totals();
            var diff = t.dr - t.cr;
            if (!diff) { return; }
            var $target = null;
            $body.children('tr').each(function () {
                if (!$target && !cents($(this).find('.mj-dr').val()) && !cents($(this).find('.mj-cr').val())) { $target = $(this); }
            });
            if (!$target) { $target = addLine(false); }
            $target.find(diff > 0 ? '.mj-cr' : '.mj-dr').val((Math.abs(diff) / 100).toFixed(2));
            $target.find(diff > 0 ? '.mj-dr' : '.mj-cr').val('');
            totals();
            if (!$target.find('.mj-account').val()) { $target.find('.mj-account').select2('open'); }
        });

        $('#btn-save-draft').on('click', function () { save('draft'); });
        $('#btn-post').on('click', function () { save('posted'); });
        $('#journal-form').on('submit', function (e) { e.preventDefault(); save('posted'); });
    });
})();
</script>
