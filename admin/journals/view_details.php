<?php
/**
 * Journal detail (opened in the modal from the journal list and the ledger).
 */
require_once(__DIR__ . '/../../config.php');

use App\Services\JournalService;
use App\Services\LedgerService;

$tenant_id = (int)$_settings->active_tenant_id();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$journal = null;
$lines = [];
$stmt = $conn->prepare("SELECT j.*, CONCAT(u.firstname, ' ', u.lastname) author FROM journal_entries j LEFT JOIN users u ON u.id = j.user_id WHERE j.id = ? AND j.tenant_id = ?");
if ($stmt) {
    $stmt->bind_param('ii', $id, $tenant_id);
    $stmt->execute();
    $journal = $stmt->get_result()->fetch_assoc();
}
if ($journal) {
    $side = LedgerService::SIDE_SQL;
    $q = $conn->prepare("SELECT ji.*, a.code, a.name account, a.type, {$side} side
        FROM journal_items ji INNER JOIN account_list a ON a.id = ji.account_id
        LEFT JOIN group_list g ON g.id = ji.group_id
        WHERE ji.journal_id = ? AND a.tenant_id = ? ORDER BY ji.id");
    $q->bind_param('ii', $id, $tenant_id);
    $q->execute();
    $lines = $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
$status = strtolower((string)($journal['status'] ?? 'posted'));
$cancelled = in_array($status, ['cancelled', 'canceled', 'void', '2', '3'], true);
$label = $cancelled ? 'Cancelled' : ($status === 'draft' ? 'Draft' : 'Posted');
$badge = $cancelled ? 'secondary' : ($status === 'draft' ? 'warning' : 'success');
$tdr = $tcr = 0.0;
$source = null;
if ($journal) {
    $source = (new JournalService($conn, $_settings))->sourceDocument($id, $tenant_id);
}
?>
<style>
    #uni_modal .modal-footer { display: none; }
    .jv-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px 16px; margin-bottom: 16px; }
    .jv-meta .k { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 600; }
    .jv-meta .v { font-weight: 600; color: #0f172a; }
    .jv-table { width: 100%; font-size: .9rem; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .jv-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; padding: 6px 8px; border-bottom: 2px solid #e2e8f0; }
    .jv-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .jv-table .num { text-align: right; white-space: nowrap; }
    .jv-table tfoot td { font-weight: 700; border-top: 2px solid #0f172a; border-bottom: 0; }
    .jv-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
</style>
<div class="container-fluid">
<?php if (!$journal): ?>
    <div class="nv-empty"><div class="nv-empty-icon"><i class="fas fa-search"></i></div><div class="nv-empty-title">Journal not found</div></div>
<?php else: ?>
    <div class="jv-meta">
        <div><div class="k">Reference</div><div class="v"><?= htmlspecialchars($journal['code']) ?></div></div>
        <div><div class="k">Date</div><div class="v"><?= date('j M Y', strtotime($journal['journal_date'])) ?></div></div>
        <div><div class="k">Status</div><div class="v"><span class="badge badge-<?= $badge ?>"><?= $label ?></span></div></div>
        <div><div class="k">Created by</div><div class="v"><?= htmlspecialchars(trim((string)$journal['author']) ?: '—') ?></div></div>
        <?php if (!empty($journal['auto_reversing_date'])): ?>
        <div><div class="k">Reverses on</div><div class="v"><?= date('j M Y', strtotime($journal['auto_reversing_date'])) ?></div></div>
        <?php endif; ?>
        <?php if ($source): ?>
        <div><div class="k">Source</div><div class="v"><?= htmlspecialchars(ucfirst($source)) ?></div></div>
        <?php endif; ?>
    </div>
    <p class="mb-3"><?= nl2br(htmlspecialchars($journal['description'])) ?></p>
    <table class="jv-table">
        <thead><tr><th>Account</th><th>Description</th><th>Tax</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $dr = (int)$l['side'] === 1 ? (float)$l['amount'] : 0; $cr = (int)$l['side'] === 2 ? (float)$l['amount'] : 0; $tdr += $dr; $tcr += $cr; ?>
            <tr>
                <td><b><?= htmlspecialchars($l['account']) ?></b><?php if ($l['code']): ?><div class="small text-muted"><?= htmlspecialchars($l['code']) ?> · <?= htmlspecialchars($l['type'] ?? '') ?></div><?php endif; ?></td>
                <td><?= htmlspecialchars((string)($l['description'] ?? '')) ?></td>
                <td class="small text-muted"><?= htmlspecialchars((string)($l['tax_rate'] ?? '')) ?></td>
                <td class="num"><?= $dr ? number_format($dr, 2) : '' ?></td>
                <td class="num"><?= $cr ? number_format($cr, 2) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">Total<?php if (abs($tdr - $tcr) >= 0.005): ?> <span class="badge badge-danger ml-2">Out by <?= number_format(abs($tdr - $tcr), 2) ?></span><?php endif; ?></td><td class="num"><?= number_format($tdr, 2) ?></td><td class="num"><?= number_format($tcr, 2) ?></td></tr></tfoot>
    </table>
    <div class="jv-actions">
        <?php if (!$cancelled && !$source): ?>
            <a class="btn btn-default" href="./?page=journals/manage_journal&id=<?= $id ?>"><i class="fas fa-edit mr-1"></i> Edit</a>
            <button type="button" class="btn btn-default text-danger" onclick="nvCancelJournal(<?= $id ?>)"><i class="fas fa-ban mr-1"></i> Cancel journal</button>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
    </div>
<?php endif; ?>
</div>
<script>
window.nvCancelJournal = function (id) {
    Swal.fire({
        title: 'Cancel this journal?',
        text: 'It stays on record but is removed from every report. This cannot be undone.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel journal', cancelButtonText: 'Keep it',
        confirmButtonColor: '#dc2626', reverseButtons: true, focusCancel: true
    }).then(function (r) {
        if (!(r.isConfirmed || r.value)) { return; }
        $.post(_base_url_ + 'classes/Master.php?f=cancel_journal', { id: id }, null, 'json').done(function (resp) {
            if (resp && resp.status === 'success') { location.reload(); } else { alert_toast((resp && resp.msg) || 'The journal could not be cancelled.', 'error'); }
        }).fail(function () { alert_toast('The journal could not be cancelled.', 'error'); });
    });
};
</script>
