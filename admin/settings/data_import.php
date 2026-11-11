<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\ImportService;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$templates = [];
foreach (ImportService::ENTITIES as $k => $def) {
    [$head, $sample] = ImportService::template($k);
    $templates[$k] = ['head' => $head, 'sample' => $sample];
}
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-file-import text-primary mr-2"></i> Import Data from CSV</h3></div>
    <div class="card-body">
        <form id="import-frm" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-3 form-group">
                    <label class="text-sm">What are you importing?</label>
                    <select name="entity" id="entity" class="form-control form-control-sm">
                        <?php foreach (ImportService::ENTITIES as $k => $def): ?><option value="<?= $h($k) ?>"><?= $h($def['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label class="text-sm">If a record already exists</label>
                    <select name="mode" class="form-control form-control-sm">
                        <option value="skip">Leave it unchanged</option>
                        <option value="update">Update it (filled-in cells only)</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="text-sm">CSV file (up to 2 MB, <?= ImportService::MAX_ROWS ?> rows)</label>
                    <input type="file" name="csv" id="csv" accept=".csv,.txt" class="form-control form-control-sm p-1">
                </div>
                <div class="col-md-2 form-group d-flex align-items-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-flat btn-block" id="btn-template"><i class="fas fa-download mr-1"></i> Template</button>
                </div>
            </div>
            <div class="mb-2">
                <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn-preview"><i class="fas fa-search mr-1"></i> Check file</button>
                <button type="button" class="btn btn-sm btn-success btn-flat" id="btn-import" disabled><i class="fas fa-check mr-1"></i> Import rows that passed</button>
                <small class="text-muted ml-2" id="hint">Check the file first. Nothing is saved until you import.</small>
            </div>
            <p class="text-xs text-muted mb-0">The first row must be the column headings. Download the template for the exact headings. Customers and suppliers without a code are numbered automatically. For products, the stock quantity on a new product is posted as opening stock.</p>
        </form>
        <div id="result" class="mt-3"></div>
    </div>
</div>
<script>
$(function(){
    var templates = <?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var checkedOk = false;
    function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
    function csvCell(v){ v = String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
    $('#btn-template').click(function(){
        var t = templates[$('#entity').val()];
        var text = [t.head, t.sample].map(function(r){ return r.map(csvCell).join(','); }).join('\r\n') + '\r\n';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + text], {type: 'text/csv'}));
        a.download = $('#entity').val() + '_template.csv';
        document.body.appendChild(a); a.click(); a.remove();
    });
    $('#entity, #csv, [name=mode]').on('change', function(){ checkedOk = false; $('#btn-import').prop('disabled', true); });
    function send(dry){
        if (!$('#csv')[0].files.length) { alert_toast('Choose a CSV file first.', 'warning'); return; }
        var fd = new FormData($('#import-frm')[0]);
        fd.append('dry_run', dry ? 1 : 0);
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=import_' + $('#entity').val(),
            method: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            error: function(){ alert_toast('An error occurred.', 'error'); end_loader(); },
            success: function(r){
                end_loader();
                if (r.status !== 'success') { $('#result').html('<div class="alert alert-danger">' + esc(r.msg) + '</div>'); checkedOk = false; $('#btn-import').prop('disabled', true); return; }
                var c = r.counts, html = '<div class="alert alert-' + (c.error || c.failed ? 'warning' : 'success') + ' py-2">' + esc(r.msg) + '</div>';
                if (r.unknown && r.unknown.length) html += '<p class="text-xs text-muted">Columns not used: ' + r.unknown.map(esc).join(', ') + '</p>';
                html += '<div class="table-responsive" style="max-height:420px;overflow:auto"><table class="table table-sm table-striped"><thead><tr><th>Row</th><th>Record</th><th>Result</th><th>Notes</th></tr></thead><tbody>';
                var badge = {'new':'info','update':'primary','skip':'secondary','error':'danger','created':'success','updated':'success','failed':'danger'};
                r.rows.forEach(function(x){ html += '<tr><td>' + x.line + '</td><td>' + esc(x.label) + '</td><td><span class="badge badge-' + (badge[x.status] || 'secondary') + '">' + esc(x.status) + '</span></td><td class="text-xs">' + x.messages.map(esc).join('; ') + '</td></tr>'; });
                html += '</tbody></table></div>' + (r.truncated ? '<p class="text-xs text-muted">Showing the first 500 rows.</p>' : '');
                $('#result').html(html);
                if (dry) { checkedOk = (c['new'] + c['update']) > 0; $('#btn-import').prop('disabled', !checkedOk); }
                else { checkedOk = false; $('#btn-import').prop('disabled', true); }
            }
        });
    }
    $('#btn-preview').click(function(){ send(true); });
    $('#btn-import').click(function(){ if (checkedOk && confirm('Import the rows that passed? Rows with problems are skipped.')) send(false); });
});
</script>
