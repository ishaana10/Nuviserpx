<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\CrmService;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tenant_id = (int)$_settings->active_tenant_id();
$svc = new CrmService($conn, $_settings);
$contacts = $svc->contacts();
$parties = [];
$q = $conn->query("SELECT id, name FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
while ($q && ($p = $q->fetch_assoc())) { $parties[] = $p; }
$can_save = $_settings->can_access('crm_save');
$can_delete = $_settings->can_access('crm_delete');
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-address-book text-primary"></i> CRM Contacts</h3>
        <div class="card-tools"><a href="./?page=crm" class="btn btn-sm btn-default btn-flat">Back to pipeline</a></div>
    </div>
    <div class="card-body">
        <?php if ($can_save): ?>
        <form id="contact-form" class="callout border-primary rounded-0 mb-3">
            <input type="hidden" name="id" id="c_id" value="0">
            <div class="row">
                <div class="col-md-3 form-group"><label class="text-sm">Name *</label><input name="name" id="c_name" class="form-control form-control-sm" maxlength="150" required></div>
                <div class="col-md-3 form-group"><label class="text-sm">Customer</label>
                    <select name="party_id" id="c_party" class="form-control form-control-sm"><option value="0">None</option>
                        <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2 form-group"><label class="text-sm">Job title</label><input name="job_title" id="c_job" class="form-control form-control-sm" maxlength="100"></div>
                <div class="col-md-2 form-group"><label class="text-sm">Email</label><input type="email" name="email" id="c_email" class="form-control form-control-sm" maxlength="150"></div>
                <div class="col-md-2 form-group"><label class="text-sm">Phone</label><input name="phone" id="c_phone" class="form-control form-control-sm" maxlength="50"></div>
            </div>
            <div class="row">
                <div class="col-md-8 form-group"><label class="text-sm">Notes</label><input name="notes" id="c_notes" class="form-control form-control-sm" maxlength="500"></div>
                <div class="col-md-2 form-group d-flex align-items-end"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="c_primary" name="is_primary" value="1"><label class="custom-control-label text-sm" for="c_primary">Primary contact</label></div></div>
                <div class="col-md-2 form-group d-flex align-items-end"><button class="btn btn-primary btn-sm btn-flat btn-block"><i class="fas fa-save"></i> Save</button></div>
            </div>
        </form>
        <?php endif; ?>
        <table class="table table-sm table-striped">
            <thead><tr><th>Name</th><th>Customer</th><th>Job title</th><th>Email</th><th>Phone</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contacts as $c): ?>
                <tr>
                    <td><?= $h($c['name']) ?> <?= (int)$c['is_primary'] ? '<span class="badge badge-info">Primary</span>' : '' ?></td>
                    <td><?= $h($c['party_name']) ?></td><td><?= $h($c['job_title']) ?></td>
                    <td><?php if ($c['email']): ?><a href="mailto:<?= $h($c['email']) ?>"><?= $h($c['email']) ?></a><?php endif; ?></td>
                    <td><?= $h($c['phone']) ?></td>
                    <td class="text-right text-nowrap">
                        <?php if ($can_save): ?><button class="btn btn-xs btn-flat btn-outline-primary edit-c" data-c='<?= $h(json_encode($c)) ?>'>Edit</button><?php endif; ?>
                        <?php if ($can_delete): ?><button class="btn btn-xs btn-flat btn-outline-danger del-c" data-id="<?= (int)$c['id'] ?>">Delete</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; if (!$contacts): ?><tr><td colspan="6" class="text-center text-muted">No contacts yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$(function(){
    $('#contact-form').submit(function(e){
        e.preventDefault(); start_loader();
        $.post(_base_url_+'classes/Master.php?f=save_crm_contact', $(this).serialize(), null, 'json')
            .fail(function(){ alert_toast('An error occurred.','error'); end_loader(); })
            .done(function(r){ if (r.status==='success') { location.reload(); } else { alert_toast(r.msg,'error'); end_loader(); } });
    });
    $('.edit-c').click(function(){
        var c = $(this).data('c');
        $('#c_id').val(c.id); $('#c_name').val(c.name); $('#c_party').val(c.party_id || 0); $('#c_job').val(c.job_title);
        $('#c_email').val(c.email); $('#c_phone').val(c.phone); $('#c_notes').val(c.notes); $('#c_primary').prop('checked', c.is_primary == 1);
        $('html,body').animate({scrollTop: 0}, 200);
    });
    $('.del-c').click(function(){
        if (!confirm('Delete this contact? Deals linked to them keep working without a contact.')) return;
        start_loader();
        $.post(_base_url_+'classes/Master.php?f=delete_crm_contact', {id: $(this).data('id')}, null, 'json')
            .done(function(r){ if (r.status==='success') { location.reload(); } else { alert_toast(r.msg,'error'); end_loader(); } })
            .fail(function(){ alert_toast('An error occurred.','error'); end_loader(); });
    });
});
</script>
