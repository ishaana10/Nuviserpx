<?php
/** Default accounts: where invoices, receipts and credit notes post. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\AccountDefaultsService($conn, $_settings);
$accounts = $svc->accounts();
$chosen = $svc->chosen();
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$classOf = fn ($t) => \App\Services\LedgerService::classify($t)['class'];
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-link text-primary mr-2"></i> Default accounts</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            Invoices, customer receipts and credit notes post to these accounts. Where nothing has been chosen yet, the first account
            of the right kind whose name fits is used (shown selected). If there is none, the document is refused rather than posted to the wrong account.
        </div>
        <?php
            // A role with no fitting account at all cannot be configured: offer
            // to add the standard accounts the chart is missing.
            $nv_missing = [];
            foreach (\App\Services\AccountDefaultsService::ROLES as $nv_role => $nv_meta) {
                if ($svc->resolve($nv_role) <= 0) { $nv_missing[] = $nv_meta['label']; }
            }
            if ($nv_missing):
        ?>
        <div class="alert alert-warning text-sm d-flex align-items-center justify-content-between">
            <div><i class="fas fa-exclamation-triangle mr-2"></i>Your chart has no account to use for: <b><?= $e(implode(', ', $nv_missing)) ?></b>.</div>
            <button type="button" class="btn btn-sm btn-warning font-weight-bold ml-3 text-nowrap" id="btn-provision-chart">
                <i class="fas fa-magic mr-1"></i> Add the standard accounts
            </button>
        </div>
        <?php endif; ?>
        <form id="defaults-form">
            <?php foreach (\App\Services\AccountDefaultsService::ROLES as $role => $meta):
                $current = $svc->resolve($role); ?>
            <div class="form-group row">
                <label class="col-md-3 col-form-label"><?= $e($meta['label']) ?></label>
                <div class="col-md-5">
                    <select name="<?= $e($role) ?>" class="form-control form-control-sm">
                        <option value="">— choose —</option>
                        <?php foreach ($accounts as $id => $a): if ($classOf($a['type']) !== $meta['class']) continue; ?>
                            <option value="<?= (int)$id ?>" <?= $current === (int)$id ? 'selected' : '' ?>><?= $e($a['name']) ?> (<?= $e($a['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 text-muted text-sm pt-1"><?= $e($meta['help']) ?><?= empty($chosen[$role]) ? ' <i>(not saved yet)</i>' : '' ?></div>
            </div>
            <?php endforeach; ?>
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save mr-1"></i> Save</button>
        </form>
    </div>
</div>
<script>
$('#btn-provision-chart').on('click', function(){
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=provision_chart', {}, function(r){
        end_loader();
        alert_toast(r.msg || 'Done.', r.status === 'success' ? 'success' : 'error');
        if (r.status === 'success') { setTimeout(function(){ location.reload(); }, 800); }
    }, 'json').fail(function(){ end_loader(); alert_toast('Could not add the accounts.', 'error'); });
});

$('#defaults-form').on('submit', function(ev){
    ev.preventDefault();
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=save_default_accounts', $(this).serialize(), function(r){
        end_loader();
        if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 800); }
        else { alert_toast((r && r.msg) || 'Failed', 'error'); }
    }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
});
</script>
