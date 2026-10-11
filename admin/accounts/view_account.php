<?php
require_once('../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $acc_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT a.*, g.name as group_name, g.type as group_type FROM `account_list` a LEFT JOIN `group_list` g ON a.group_id = g.id WHERE a.id = '{$acc_id_clean}' AND a.tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>

<div class="container-fluid">
    <div class="card p-3 mb-3 bg-light border">
        <div class="row align-items-center">
            <div class="col-8">
                <h5 class="font-weight-bold text-navy m-0"><i class="fas fa-file-alt"></i> <?= isset($name) ? $name : '' ?></h5>
                <small class="text-muted"><b>Group:</b> <?= isset($group_name) ? $group_name : 'General Account' ?></small>
            </div>
            <div class="col-4 text-right">
                <?php
                $status = isset($status) ? $status : 1;
                echo $status == 1 ? '<span class="badge badge-success px-3 py-1 rounded-pill">Active</span>' : '<span class="badge badge-danger px-3 py-1 rounded-pill">Inactive</span>';
                ?>
            </div>
        </div>
        <?php if(!empty($description)): ?>
            <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars($description) ?></p>
        <?php endif; ?>
    </div>

    <h6 class="font-weight-bold text-navy mb-2"><i class="fas fa-list"></i> Account Transaction Ledger</h6>

    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
        <table class="table table-sm table-bordered table-striped">
            <thead class="bg-navy text-white text-center">
                <tr>
                    <th width="15%">Date</th>
                    <th width="20%">Journal Ref</th>
                    <th>Description</th>
                    <th width="15%">Debit (FJD)</th>
                    <th width="15%">Credit (FJD)</th>
                    <th width="18%">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $running_bal = 0;
                $tot_debit = 0;
                $tot_credit = 0;

                if(isset($id)):
                    $items_qry = $conn->query("SELECT j.journal_date, j.code, j.description as journal_desc, ji.amount, g.type as entry_type
                        FROM `journal_items` ji
                        INNER JOIN `journal_entries` j ON ji.journal_id = j.id
                        INNER JOIN `group_list` g ON ji.group_id = g.id
                        WHERE ji.account_id = '{$id}' AND j.tenant_id = '{$tenant_id}'
                        ORDER BY date(j.journal_date) ASC, j.id ASC");

                    if($items_qry->num_rows > 0):
                        while($item = $items_qry->fetch_assoc()):
                            $debit = ($item['entry_type'] == 1) ? (float)$item['amount'] : 0;
                            $credit = ($item['entry_type'] == 2) ? (float)$item['amount'] : 0;
                            $tot_debit += $debit;
                            $tot_credit += $credit;

                            if(isset($group_type) && $group_type == 2){
                                $running_bal += ($credit - $debit);
                            } else {
                                $running_bal += ($debit - $credit);
                            }
                ?>
                <tr>
                    <td class="text-center"><?= date('d M Y', strtotime($item['journal_date'])) ?></td>
                    <td><b><?= $item['code'] ?></b></td>
                    <td><small><?= htmlspecialchars($item['journal_desc']) ?></small></td>
                    <td class="text-right"><?= $debit > 0 ? number_format($debit, 2) : '—' ?></td>
                    <td class="text-right"><?= $credit > 0 ? number_format($credit, 2) : '—' ?></td>
                    <td class="text-right font-weight-bold">$<?= number_format($running_bal, 2) ?></td>
                </tr>
                <?php
                        endwhile;
                    else:
                ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">No journal postings recorded for this account.</td>
                </tr>
                <?php
                    endif;
                endif;
                ?>
            </tbody>
            <tfoot class="bg-light font-weight-bold">
                <tr>
                    <td colspan="3" class="text-right">Ending Account Totals:</td>
                    <td class="text-right text-primary">$<?= number_format($tot_debit, 2) ?></td>
                    <td class="text-right text-success">$<?= number_format($tot_credit, 2) ?></td>
                    <td class="text-right text-navy h6 mb-0">$<?= number_format($running_bal, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="text-right mt-3">
        <a href="./?page=reports/general_ledger&account_id=<?= isset($id) ? $id : '' ?>" class="btn btn-sm btn-flat btn-primary"><i class="fas fa-file-alt"></i> Full General Ledger</a>
        <button class="btn btn-sm btn-flat btn-secondary" type="button" data-dismiss="modal"><i class="fa fa-times"></i> Close</button>
    </div>
</div>