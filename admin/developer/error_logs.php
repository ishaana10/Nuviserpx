<?php
use App\Support\ErrorLog;

$php_entries = ErrorLog::read('php');
$js_entries = ErrorLog::read('js');

// Badge colour by severity: fatal problems red, warnings amber, noise grey.
function nv_sev_badge($sev){
    $s = strtolower($sev);
    if (strpos($s, 'fatal') !== false || strpos($s, 'parse') !== false) return 'danger';
    if ($s === 'warning') return 'warning';
    if ($s === 'notice' || $s === 'deprecated') return 'secondary';
    return 'danger';
}

function nv_log_table($entries, $kind){
    if (!$entries): ?>
        <div class="text-center text-muted py-5">
            <i class="far fa-check-circle fa-2x mb-2 d-block text-success"></i>
            No <?= $kind ?> errors recorded. Anything that goes wrong will appear here.
        </div>
    <?php return; endif; ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th style="width:9%">Severity</th>
                    <th style="width:14%">Last seen</th>
                    <th style="width:6%" class="text-center">Count</th>
                    <th>Error</th>
                    <th style="width:6%"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($entries as $e): ?>
                <tr>
                    <td><span class="badge badge-<?= nv_sev_badge($e['severity']) ?> px-2 py-1"><?= htmlspecialchars($e['severity']) ?></span></td>
                    <td class="text-xs font-monospace"><?= htmlspecialchars($e['time']) ?></td>
                    <td class="text-center font-weight-bold"><?= (int)$e['count'] ?></td>
                    <td>
                        <div class="text-sm"><?= htmlspecialchars($e['message']) ?></div>
                        <?php if(!empty($e['file'])): ?>
                        <small class="text-muted font-monospace"><?= htmlspecialchars($e['file']) ?><?= $e['line'] ? ':' . (int)$e['line'] : '' ?></small>
                        <?php endif; ?>
                        <?php if(trim($e['detail']) !== ''): ?>
                        <details class="mt-1">
                            <summary class="text-xs text-primary" style="cursor:pointer">Details / stack trace</summary>
                            <pre class="text-xs bg-light border rounded p-2 mt-1 mb-0" style="white-space:pre-wrap"><?= htmlspecialchars($e['detail']) ?></pre>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-xs btn-outline-secondary nv-copy-err" title="Copy the full error for pasting into a fix request"
                            data-err="<?= htmlspecialchars($e['severity'] . ' at ' . ($e['file'] ?: 'unknown') . ($e['line'] ? ':' . $e['line'] : '') . "\n" . $e['message'] . ($e['detail'] !== '' ? "\n" . $e['detail'] : '')) ?>">
                            <i class="far fa-copy"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php }
?>

<div class="card card-outline card-danger shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-bug text-danger mr-2"></i> PHP &amp; JavaScript Error Logs</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.location.reload()"><i class="fas fa-sync-alt mr-1"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm">
            <i class="fas fa-lightbulb mr-2"></i>
            <b>How to fix an error from here:</b> the <i>file:line</i> under each message says where it happened, the count says how often, and <i>Details</i> holds the stack trace.
            Use <i class="far fa-copy"></i> to copy the whole error and paste it to your developer (or an AI assistant) for a fix.
            Identical errors are grouped; the newest 400 entries of each log are shown.
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab_php">PHP server errors <span class="badge badge-<?= $php_entries ? 'danger' : 'success' ?> ml-1"><?= count($php_entries) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab_js">JavaScript (browser) errors <span class="badge badge-<?= $js_entries ? 'danger' : 'success' ?> ml-1"><?= count($js_entries) ?></span></a></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white">
            <div class="tab-pane fade show active" id="tab_php">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <small class="text-muted font-monospace">logs/php_errors.log &middot; <?= ErrorLog::sizeLabel('php') ?></small>
                    <button type="button" class="btn btn-xs btn-outline-danger nv-clear-log" data-type="php"><i class="fas fa-trash mr-1"></i> Clear PHP log</button>
                </div>
                <?php nv_log_table($php_entries, 'PHP'); ?>
            </div>
            <div class="tab-pane fade" id="tab_js">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <small class="text-muted font-monospace">logs/js_errors.log &middot; <?= ErrorLog::sizeLabel('js') ?></small>
                    <button type="button" class="btn btn-xs btn-outline-danger nv-clear-log" data-type="js"><i class="fas fa-trash mr-1"></i> Clear JS log</button>
                </div>
                <?php nv_log_table($js_entries, 'JavaScript'); ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('.nv-clear-log').click(function(){
        var type = $(this).data('type');
        if(!confirm('Clear the ' + type.toUpperCase() + ' error log? The entries cannot be brought back.')) return;
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=clear_error_log', { type: type }, function(resp){
            end_loader();
            if(resp && resp.status === 'success'){ location.reload(); }
            else { alert_toast('Could not clear the log.', 'error'); }
        }, 'json').fail(function(){ end_loader(); alert_toast('Could not clear the log.', 'error'); });
    });
    $(document).on('click', '.nv-copy-err', function(){
        var txt = $(this).attr('data-err'), btn = $(this);
        function done(){ btn.find('i').attr('class', 'fas fa-check text-success'); setTimeout(function(){ btn.find('i').attr('class', 'far fa-copy'); }, 1200); }
        if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(txt).then(done); }
        else { var ta = $('<textarea>').val(txt).appendTo('body'); ta[0].select(); document.execCommand('copy'); ta.remove(); done(); }
    });
});
</script>
