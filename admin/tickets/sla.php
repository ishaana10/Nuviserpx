<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\TicketSlaService;

if (!$_settings->can_access('settings_manage')) {
    echo '<div class="alert alert-danger">You do not have access to service targets.</div>';
    return;
}
$svc = new TicketSlaService($conn, $_settings);
$pol = $svc->policies();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-stopwatch text-primary"></i> Ticket Service Targets</h3>
        <div class="card-tools"><a href="./?page=tickets" class="btn btn-sm btn-default btn-flat">Back to tickets</a></div>
    </div>
    <div class="card-body">
        <p class="text-sm text-muted">How quickly each priority of ticket should get a first staff reply and be resolved, in hours (calendar time, not working hours). The resolution clock pauses while a ticket is <b>Waiting Client</b> and stops when it is resolved or closed; reopening starts it again.</p>
        <form id="sla-form">
            <table class="table table-sm" style="max-width:560px">
                <thead><tr><th>Priority</th><th>First reply within (hours)</th><th>Resolved within (hours)</th></tr></thead>
                <tbody>
                <?php foreach (TicketSlaService::PRIORITIES as $p): ?>
                    <tr><td class="text-capitalize font-weight-bold"><?= $h($p) ?></td>
                        <td><input type="number" step="0.25" min="0.25" max="720" name="response[<?= $p ?>]" class="form-control form-control-sm" value="<?= $h($fmt($pol[$p][0])) ?>" required></td>
                        <td><input type="number" step="0.25" min="0.25" max="720" name="resolve[<?= $p ?>]" class="form-control form-control-sm" value="<?= $h($fmt($pol[$p][1])) ?>" required></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button class="btn btn-sm btn-primary btn-flat"><i class="fas fa-save"></i> Save targets</button>
        </form>
    </div>
</div>
<script>
$('#sla-form').submit(function(e){
    e.preventDefault(); start_loader();
    $.post(_base_url_+'classes/Master.php?f=save_sla_policies', $(this).serialize(), null, 'json')
        .fail(function(){ alert_toast('An error occurred.','error'); end_loader(); })
        .done(function(r){ alert_toast(r.msg, r.status==='success'?'success':'error'); end_loader(); });
});
</script>
