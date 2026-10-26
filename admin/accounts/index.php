<?php
use App\Services\LedgerService;

$tenant_id = (int)$_settings->active_tenant_id();

// Active tab filter
$tab = in_array($_GET['tab'] ?? 'all', ['all', 'assets', 'liabilities', 'equity', 'revenue', 'expenses', 'archive'], true) ? ($_GET['tab'] ?? 'all') : 'all';
$search = trim($_GET['s'] ?? '');
$tab_class = ['assets' => 'asset', 'liabilities' => 'liability', 'equity' => 'equity', 'revenue' => 'income', 'expenses' => 'expense'];

$sql = "SELECT * FROM `account_list` WHERE tenant_id = ? AND delete_flag = 0";
$params = [$tenant_id];
$types = 'i';
if ($search !== '') {
    $sql .= " AND (code LIKE ? OR name LIKE ? OR description LIKE ? OR type LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$sql .= " ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), code, name";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$all_accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Balances: balance-sheet accounts show the balance to date; P&L accounts the
// financial year to date. Only posted journals count.
$today = date('Y-m-d');
$fy_start = LedgerService::fyStart($today, (int)($_settings->info('fiscal_year_end_month') ?: 12));
$bal = (new LedgerService($conn, $tenant_id))->balances($fy_start, $today, true);

$tab_counts = array_fill_keys(array_keys($tab_class), 0) + ['all' => 0, 'archive' => 0];
$rows = [];
foreach ($all_accounts as $a) {
    $c = LedgerService::classify($a['type']);
    $a['class'] = $c['class'];
    $b = $bal[(int)$a['id']] ?? null;
    if ($b) {
        $a['balance'] = in_array($c['class'], ['income', 'expense'], true)
            ? LedgerService::natural($c['class'], $b['dr'], $b['cr'])
            : LedgerService::natural($c['class'], $b['open_dr'] + $b['dr'], $b['open_cr'] + $b['cr']);
    } else {
        $a['balance'] = 0.0;
    }
    $archived = !empty($a['is_archived']);
    if ($archived) {
        $tab_counts['archive']++;
    } else {
        $tab_counts['all']++;
        $tab_counts[array_search($c['class'], $tab_class, true)]++;
    }
    $show = $tab === 'archive' ? $archived : (!$archived && ($tab === 'all' || $tab_class[$tab] === $c['class']));
    if ($show) {
        $rows[] = $a;
    }
}
?>

<style>
    .coa-banner {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        position: relative;
    }
    .coa-banner .play-btn-circle {
        width: 60px;
        height: 60px;
        background: #0284c7;
        color: #ffffff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
    }
    .coa-notice {
        background: #fffbebf5;
        border: 1px solid #fde68a;
        border-radius: 8px;
        color: #92400e;
        padding: 0.75rem 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .coa-toolbar .btn-coa {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #334155;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.4rem 1rem;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    .coa-toolbar .btn-coa:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
    }
    .coa-toolbar .btn-coa-primary {
        background: #0284c7;
        color: #ffffff;
        border-color: #0284c7;
    }
    .coa-toolbar .btn-coa-primary:hover {
        background: #0369a1;
        color: #ffffff;
    }
    .nav-tabs-coa {
        border-bottom: 2px solid #e2e8f0;
    }
    .nav-tabs-coa .nav-link {
        color: #64748b;
        font-weight: 600;
        font-size: 0.875rem;
        border: none;
        padding: 0.6rem 1.2rem;
        border-bottom: 3px solid transparent;
    }
    .nav-tabs-coa .nav-link.active {
        color: #0284c7;
        background: transparent;
        border-bottom-color: #0284c7;
    }
    .coa-table th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
    }
    .coa-table td {
        vertical-align: middle;
    }
    .account-title-link {
        color: #0284c7;
        font-weight: 600;
        text-decoration: none;
    }
    .account-title-link:hover {
        text-decoration: underline;
        color: #0369a1;
    }
    .sub-desc {
        font-size: 0.8rem;
        color: #64748b;
    }
</style>

<div class="card card-outline card-primary shadow-sm border-0">
    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
        <small class="text-muted text-uppercase font-weight-bold">Settings &gt;</small>
        <h2 class="font-weight-bold text-navy m-0">Chart of accounts</h2>
    </div>

    <div class="card-body px-4">
        <!-- Educational Banner Box -->
        <div class="coa-banner d-flex align-items-center justify-content-between" id="help-banner">
            <div class="d-flex align-items-center">
                <div class="play-btn-circle mr-3">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div>
                    <h5 class="font-weight-bold mb-1 text-dark">
                        Categorise every transaction in NuvisERPX with our full <a href="#" class="text-primary">Chart of accounts</a>
                    </h5>
                    <p class="mb-0 text-muted">
                        Financial reports draw on each account to show how your business is performing.
                        <a href="https://nuvistechnologies.com.fj" target="_blank" class="text-primary font-weight-bold ml-1">Learn more</a> about customizing the chart of accounts.
                    </p>
                </div>
            </div>
            <button type="button" class="close text-muted" onclick="$('#help-banner').slideUp();" aria-label="Close">
                <small class="text-xs text-uppercase mr-1">Hide Help</small>&times;
            </button>
        </div>

        <!-- Action Toolbar -->
        <div class="coa-toolbar d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-coa btn-coa-primary" id="btn_add_account">
                    <i class="fas fa-plus mr-1"></i> Add Account
                </button>
                <button type="button" class="btn btn-coa btn-coa-primary" id="btn_add_bank">
                    <i class="fas fa-university mr-1"></i> Add Bank Account
                </button>
                <a href="./?page=reports/trial_balance" class="btn btn-coa">
                    <i class="fas fa-balance-scale text-primary mr-1"></i> Trial balance
                </a>
                <button type="button" class="btn btn-coa" id="btn_export_coa">
                    <i class="fas fa-file-export text-success mr-1"></i> Export CSV
                </button>
            </div>
        </div>

        <!-- Filter Tabs -->
        <ul class="nav nav-tabs nav-tabs-coa mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'all' ? 'active' : '' ?>" href="./?page=accounts&tab=all">All Accounts <span class="badge badge-light border ml-1"><?= $tab_counts['all'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'assets' ? 'active' : '' ?>" href="./?page=accounts&tab=assets">Assets <span class="badge badge-light border ml-1"><?= $tab_counts['assets'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'liabilities' ? 'active' : '' ?>" href="./?page=accounts&tab=liabilities">Liabilities <span class="badge badge-light border ml-1"><?= $tab_counts['liabilities'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'equity' ? 'active' : '' ?>" href="./?page=accounts&tab=equity">Equity <span class="badge badge-light border ml-1"><?= $tab_counts['equity'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'expenses' ? 'active' : '' ?>" href="./?page=accounts&tab=expenses">Expenses <span class="badge badge-light border ml-1"><?= $tab_counts['expenses'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'revenue' ? 'active' : '' ?>" href="./?page=accounts&tab=revenue">Income <span class="badge badge-light border ml-1"><?= $tab_counts['revenue'] ?></span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab == 'archive' ? 'active' : '' ?>" href="./?page=accounts&tab=archive">Archive <span class="badge badge-light border ml-1"><?= $tab_counts['archive'] ?></span></a>
            </li>
        </ul>

        <!-- Search & Bulk Action Controls -->
        <div class="row align-items-center mb-3">
            <div class="col-md-6 mb-2 mb-md-0 d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn_bulk_delete" disabled>Delete</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn_bulk_archive" disabled>Archive</button>
                <button type="button" class="btn btn-sm btn-outline-info" id="btn_bulk_tax" disabled>Change Tax Rate</button>
                <span class="text-muted small ml-2" id="selected_count_label">No accounts selected</span>
            </div>
            <div class="col-md-6 text-md-right">
                <form action="" method="GET" class="d-inline-flex">
                    <input type="hidden" name="page" value="accounts">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <input type="text" name="s" class="form-control form-control-sm rounded-0 mr-1" placeholder="Search accounts..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-sm btn-navy btn-flat">Search</button>
                </form>
            </div>
        </div>

        <!-- Accounts Table -->
        <form id="bulk-form">
            <div class="table-responsive">
                <table class="table table-hover coa-table">
                    <thead>
                        <tr>
                            <th width="30" class="text-center">
                                <input type="checkbox" id="check_all">
                            </th>
                            <th width="100">Code <i class="fas fa-sort-up ml-1 text-primary"></i></th>
                            <th>Name</th>
                            <th width="140">Type</th>
                            <th width="180">Tax Rate</th>
                            <th width="140" class="text-right" title="Balance to date for assets, liabilities and equity; financial-year-to-date for income and expenses">Balance / YTD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="account_ids[]" class="row-checkbox" value="<?= $row['id'] ?>">
                                    </td>
                                    <td>
                                        <strong class="text-dark"><?= htmlspecialchars($row['code'] ?: ('ACC-' . $row['id'])) ?></strong>
                                    </td>
                                    <td>
                                        <a href="javascript:void(0)" class="account-title-link edit_account" data-id="<?= $row['id'] ?>">
                                            <?= htmlspecialchars($row['name']) ?>
                                        </a>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="sub-desc"><?= htmlspecialchars($row['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-light border px-2 py-1 font-weight-normal"><?= htmlspecialchars($row['type'] ?: 'Expense') ?></span>
                                        <div class="sub-desc"><?= LedgerService::CLASS_LABELS[$row['class']] ?></div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($row['tax_rate'] ?: 'Tax Exempt (0%)') ?></small>
                                    </td>
                                    <td class="text-right font-weight-bold <?= $row['balance'] < 0 ? 'text-danger' : 'text-dark' ?>">
                                        <a class="text-reset" href="./?page=reports/general_ledger&account_id=<?= (int)$row['id'] ?>&from=<?= $fy_start ?>&to=<?= $today ?>" title="View transactions"><?= $row['balance'] == 0 ? '<span class="text-muted font-weight-normal">–</span>' : number_format($row['balance'], 2) ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fas fa-folder-open fa-2x mb-2 d-block"></i> No accounts found matching the criteria.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#btn_export_coa').click(function(){
        var rows = [['Code', 'Name', 'Type', 'Class', 'Tax rate', 'Balance / YTD']];
        $('.coa-table tbody tr').each(function(){
            var $t = $(this).children('td');
            if ($t.length < 6) { return; }
            rows.push([
                $t.eq(1).text().trim(),
                $t.eq(2).find('a').first().text().trim(),
                $t.eq(3).find('.badge').text().trim(),
                $t.eq(3).find('.sub-desc').text().trim(),
                $t.eq(4).text().trim(),
                $t.eq(5).text().trim().replace(/,/g, '').replace('–', '0')
            ]);
        });
        var csv = rows.map(function(r){ return r.map(function(v){ return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8'}));
        a.download = 'chart-of-accounts.csv';
        document.body.appendChild(a); a.click(); a.remove();
    });

    $('#btn_add_account').click(function(){
        uni_modal("Add Account", "accounts/manage_account.php", "mid-large");
    });

    $('#btn_add_bank').click(function(){
        uni_modal("Add Bank Account", "accounts/add_bank_account.php", "mid-large");
    });

    $('.edit_account').click(function(){
        uni_modal("Update Account Details", "accounts/manage_account.php?id=" + $(this).data('id'), "mid-large");
    });

    $('#check_all').change(function(){
        $('.row-checkbox').prop('checked', $(this).is(':checked'));
        updateBulkState();
    });

    $('.row-checkbox').change(function(){
        updateBulkState();
    });

    function updateBulkState() {
        var count = $('.row-checkbox:checked').length;
        if (count > 0) {
            $('#btn_bulk_delete, #btn_bulk_archive, #btn_bulk_tax').prop('disabled', false);
            $('#selected_count_label').text(count + ' account(s) selected');
        } else {
            $('#btn_bulk_delete, #btn_bulk_archive, #btn_bulk_tax').prop('disabled', true);
            $('#selected_count_label').text('No accounts selected');
        }
    }

    $('#btn_bulk_delete').click(function(){
        if (confirm("Are you sure you want to delete the selected accounts permanently?")) {
            submitBulk('delete_accounts_bulk');
        }
    });

    $('#btn_bulk_archive').click(function(){
        submitBulk('archive_accounts_bulk');
    });

    $('#btn_bulk_tax').click(function(){
        var rate = prompt("Enter new default tax rate (e.g. Tax on Sales (0%), VAT on Sales (15%)):", "Tax Exempt (0%)");
        if (rate) {
            submitBulk('change_tax_rate_bulk', { tax_rate: rate });
        }
    });

    function submitBulk(action, extraData) {
        start_loader();
        var data = $('#bulk-form').serialize();
        if (extraData) {
            data += '&' + $.param(extraData);
        }
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=" + action,
            method: "POST",
            data: data,
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", 'error');
                end_loader();
            },
            success: function(resp){
                end_loader();
                if (resp.status == 'success') {
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to complete bulk action.", 'error');
                }
            }
        });
    }
});
</script>