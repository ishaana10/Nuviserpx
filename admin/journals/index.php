<?php
/**
 * Journal list: posted, draft and cancelled journals with totals, source and status.
 */
use App\Services\LedgerService;

$tenant_id = (int)$_settings->active_tenant_id();
$status_tab = in_array($_GET['status'] ?? '', ['posted', 'draft', 'cancelled'], true) ? $_GET['status'] : 'all';
$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-12 months'));
$to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : date('Y-m-d');

$own_only = $_settings->userdata('type') != 1;
$uid = (int)$_settings->userdata('id');
$side = LedgerService::SIDE_SQL;

$sql = "SELECT j.id, j.code, j.journal_date, j.description, j.status, j.user_id,
               MAX(CONCAT(u.firstname, ' ', u.lastname)) author,
               COUNT(ji.id) line_count,
               COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount END), 0) debit,
               COALESCE(SUM(CASE WHEN {$side} = 2 THEN ji.amount END), 0) credit,
               (SELECT i.invoice_no FROM invoice_list i WHERE i.journal_id = j.id LIMIT 1) src_invoice,
               (SELECT p.payment_no FROM payment_list p WHERE p.journal_id = j.id LIMIT 1) src_payment,
               (SELECT pr.id FROM payroll_list pr WHERE pr.journal_id = j.id LIMIT 1) src_payroll
        FROM journal_entries j
        LEFT JOIN journal_items ji ON ji.journal_id = j.id
        LEFT JOIN group_list g ON g.id = ji.group_id
        LEFT JOIN users u ON u.id = j.user_id
        WHERE j.tenant_id = ? AND j.journal_date BETWEEN ? AND ?" . ($own_only ? " AND j.user_id = ?" : "") . "
        GROUP BY j.id
        ORDER BY j.journal_date DESC, j.id DESC";
$stmt = $conn->prepare($sql);
$rows = [];
if ($stmt) {
    $own_only ? $stmt->bind_param('issi', $tenant_id, $from, $to, $uid) : $stmt->bind_param('iss', $tenant_id, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$norm = function ($s): string {
    $s = strtolower((string)$s);
    if (in_array($s, ['cancelled', 'canceled', 'void', '2', '3'], true)) { return 'cancelled'; }
    return $s === 'draft' ? 'draft' : 'posted';
};
$counts = ['all' => count($rows), 'posted' => 0, 'draft' => 0, 'cancelled' => 0];
$posted_total = 0.0;
foreach ($rows as $r) {
    $st = $norm($r['status']);
    $counts[$st]++;
    if ($st === 'posted') { $posted_total += (float)$r['debit']; }
}
// Only posted journals affect the ledger; an unbalanced draft is just unfinished.
$unbalanced = count(array_filter($rows, fn ($r) => $norm($r['status']) === 'posted' && abs((float)$r['debit'] - (float)$r['credit']) >= 0.005));
if ($status_tab !== 'all') {
    $rows = array_values(array_filter($rows, fn ($r) => $norm($r['status']) === $status_tab));
}
$qs = fn (array $over) => './?' . http_build_query(array_merge(['page' => 'journals', 'from' => $from, 'to' => $to, 'status' => $status_tab], $over));
?>
<style>
    .jl-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e2e8f0; margin-bottom: 14px; flex-wrap: wrap; }
    .jl-tabs a { padding: 8px 14px; font-size: .88rem; font-weight: 500; color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .jl-tabs a.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
    .jl-tabs a:hover { text-decoration: none; color: #0f172a; }
    .jl-tabs .count { font-size: .72rem; background: #f1f5f9; color: #475569; border-radius: 999px; padding: 1px 7px; margin-left: 4px; }
    .jl-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 14px; }
    .jl-filters label { font-size: .72rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; display: block; }
    .jl-filters .form-control { height: 36px; }
    .jl-note { font-size: .8rem; color: #64748b; }
    .jl-src { font-size: .7rem; font-weight: 600; color: #4f46e5; background: #eef2ff; border-radius: 999px; padding: 1px 8px; margin-left: 6px; white-space: nowrap; }
    tr.jl-cancelled td { color: #94a3b8; }
    tr.jl-cancelled td .jl-narr { text-decoration: line-through; }
</style>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book text-primary"></i> Manual Journals</h3>
        <div class="card-tools">
            <a class="btn btn-default btn-sm" href="./?page=reports/general_ledger"><i class="fas fa-book-open mr-1"></i> General ledger</a>
            <a class="btn btn-primary btn-sm" href="./?page=journals/manage_journal"><i class="fas fa-plus mr-1"></i> New journal</a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($unbalanced): ?>
        <div class="d-flex align-items-center mb-3" style="gap:10px;padding:10px 14px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:.88rem"><i class="fas fa-exclamation-triangle"></i> <span><b><?= $unbalanced ?> posted journal<?= $unbalanced === 1 ? ' is' : 's are' ?> out of balance</b> in this period, so your trial balance won’t agree. Open <?= $unbalanced === 1 ? 'it' : 'them' ?> to correct the lines.</span></div>
        <?php endif; ?>
        <div class="jl-tabs">
            <?php foreach (['all' => 'All', 'posted' => 'Posted', 'draft' => 'Draft', 'cancelled' => 'Cancelled'] as $k => $lbl): ?>
                <a href="<?= htmlspecialchars($qs(['status' => $k])) ?>" class="<?= $status_tab === $k ? 'active' : '' ?>"><?= $lbl ?><span class="count"><?= $counts[$k] ?></span></a>
            <?php endforeach; ?>
        </div>
        <form class="jl-filters" method="get" action="./">
            <input type="hidden" name="page" value="journals">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_tab) ?>">
            <div><label for="jl-from">From</label><input type="date" id="jl-from" name="from" value="<?= $from ?>" class="form-control form-control-sm"></div>
            <div><label for="jl-to">To</label><input type="date" id="jl-to" name="to" value="<?= $to ?>" class="form-control form-control-sm"></div>
            <div><button class="btn btn-default" style="height:36px"><i class="fas fa-filter mr-1"></i> Apply</button></div>
            <div class="ml-auto jl-note">Posted in period: <b><?= number_format($posted_total, 2) ?></b></div>
        </form>
        <table class="table table-hover" id="journal-list">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Narration</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $st = $norm($r['status']);
                $src = $r['src_invoice'] ? 'Invoice ' . $r['src_invoice'] : ($r['src_payment'] ? 'Payment ' . $r['src_payment'] : ($r['src_payroll'] ? 'Payroll' : ''));
                $out = $st !== 'cancelled' && abs((float)$r['debit'] - (float)$r['credit']) >= 0.005;
            ?>
                <tr class="<?= $st === 'cancelled' ? 'jl-cancelled' : '' ?>">
                    <td data-order="<?= $r['journal_date'] ?>" style="white-space:nowrap"><?= date('j M Y', strtotime($r['journal_date'])) ?></td>
                    <td style="white-space:nowrap"><a href="javascript:void(0)" class="view_data font-weight-bold" data-id="<?= (int)$r['id'] ?>" data-code="<?= htmlspecialchars($r['code']) ?>"><?= htmlspecialchars($r['code']) ?></a></td>
                    <td>
                        <span class="jl-narr"><?= htmlspecialchars(mb_strimwidth((string)$r['description'], 0, 90, '…')) ?></span>
                        <?php if ($src): ?><span class="jl-src"><?= htmlspecialchars($src) ?></span><?php endif; ?>
                        <div class="small text-muted"><?= (int)$r['line_count'] ?> line<?= (int)$r['line_count'] === 1 ? '' : 's' ?><?= $out ? ' · <span class="text-danger font-weight-bold">out by ' . number_format(abs((float)$r['debit'] - (float)$r['credit']), 2) . '</span>' : '' ?></div>
                    </td>
                    <td class="text-right" data-order="<?= (float)$r['debit'] ?>"><?= number_format((float)$r['debit'], 2) ?></td>
                    <td class="text-right" data-order="<?= (float)$r['credit'] ?>"><?= number_format((float)$r['credit'], 2) ?></td>
                    <td><span class="badge badge-<?= $st === 'posted' ? 'success' : ($st === 'draft' ? 'warning' : 'secondary') ?>"><?= ucfirst($st) ?></span></td>
                    <td><?= htmlspecialchars(trim((string)$r['author']) ?: '—') ?></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">Action</button>
                        <div class="dropdown-menu" role="menu">
                            <a class="dropdown-item view_data" href="javascript:void(0)" data-id="<?= (int)$r['id'] ?>" data-code="<?= htmlspecialchars($r['code']) ?>"><span class="fa fa-eye text-info"></span> View</a>
                            <?php if ($st !== 'cancelled' && !$src): ?>
                            <a class="dropdown-item" href="./?page=journals/manage_journal&id=<?= (int)$r['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
                            <a class="dropdown-item cancel_data" href="javascript:void(0)" data-id="<?= (int)$r['id'] ?>"><span class="fa fa-ban text-warning"></span> Cancel</a>
                            <?php endif; ?>
                            <a class="dropdown-item" href="./?page=journals/manage_journal&copy=<?= (int)$r['id'] ?>"><span class="far fa-clone text-secondary"></span> Copy</a>
                            <?php if ($st === 'posted' && !$src): ?>
                            <a class="dropdown-item" href="./?page=journals/manage_journal&reverse=<?= (int)$r['id'] ?>"><span class="fas fa-exchange-alt text-secondary"></span> Reverse</a>
                            <?php endif; ?>
                            <?php if (!$src): ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?= (int)$r['id'] ?>" data-code="<?= htmlspecialchars($r['code']) ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$(function () {
    $('#journal-list').on('click', '.view_data', function () {
        uni_modal('<i class="fas fa-book"></i> Journal ' + $('<i>').text($(this).data('code')).html(), 'journals/view_details.php?id=' + $(this).data('id'), 'modal-lg');
    });
    $('#journal-list').on('click', '.cancel_data', function () {
        _conf('Cancel this journal? It stays on record but is removed from every report.', 'nv_cancel_journal', [$(this).data('id')]);
    });
    $('#journal-list').on('click', '.delete_data', function () {
        _conf('Permanently delete journal <b>' + $('<i>').text($(this).data('code')).html() + '</b>? Consider cancelling it instead to keep an audit trail.', 'nv_delete_journal', [$(this).data('id')]);
    });
    $('#journal-list').dataTable({ order: [[0, 'desc']], columnDefs: [{ orderable: false, targets: [7] }] });
});
function nv_journal_action(action, id) {
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=' + action, { id: id }, null, 'json').done(function (resp) {
        if (resp && resp.status === 'success') { location.reload(); return; }
        alert_toast((resp && resp.msg) || 'Something went wrong.', 'error');
        end_loader();
        $('#confirm_modal').modal('hide');
    }).fail(function () { alert_toast('Something went wrong.', 'error'); end_loader(); });
}
function nv_delete_journal(id) { nv_journal_action('delete_journal', id); }
function nv_cancel_journal(id) { nv_journal_action('cancel_journal', id); }
</script>
