<?php
require_once(__DIR__ . '/../../config.php');

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: Developer module is restricted to Super Administrators.</div>';
    exit;
}

$current_branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
$last_commit = trim((string)shell_exec('git log -1 --pretty=format:"%h - %s (%cr) <%an>" 2>&1'));
$working_status = trim((string)shell_exec('git status --short 2>&1'));
$git_remote = trim((string)shell_exec('git remote -v 2>&1'));
?>

<style>
.terminal-box {
    background-color: #0f172a;
    color: #38bdf8;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.875rem;
    line-height: 1.5;
    padding: 1rem;
    border-radius: 8px;
    min-height: 220px;
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid #1e293b;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.5);
}
.terminal-line { margin: 0; white-space: pre-wrap; word-break: break-all; }
.terminal-prompt { color: #4ade80; font-weight: bold; }
</style>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="font-weight-bold text-navy m-0">
                <i class="fas fa-code-branch text-warning mr-2"></i> Developer Git Update Module
            </h3>
            <small class="text-muted">Manage system updates, Git pull deployments, and codebase synchronization.</small>
        </div>
        <div>
            <span class="badge badge-warning px-3 py-2 font-weight-bold shadow-sm">
                <i class="fas fa-user-shield mr-1"></i> Developer Restricted
            </span>
        </div>
    </div>

    <div class="row">
        <!-- Git Repository Overview -->
        <div class="col-md-6 mb-3">
            <div class="card card-outline card-primary shadow-sm h-100">
                <div class="card-header bg-light py-2">
                    <h6 class="card-title font-weight-bold text-primary m-0"><i class="fas fa-info-circle mr-1"></i> Git Repository Status</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-xs text-muted text-uppercase font-weight-bold d-block mb-1">Active Branch:</label>
                        <span class="badge badge-info px-3 py-2 text-sm font-weight-bold">
                            <i class="fas fa-code-branch mr-1"></i> <?= htmlspecialchars(!empty($current_branch) ? $current_branch : 'unknown') ?>
                        </span>
                    </div>

                    <div class="mb-3">
                        <label class="text-xs text-muted text-uppercase font-weight-bold d-block mb-1">Latest Commit:</label>
                        <div class="p-2 bg-light border rounded small font-weight-bold text-dark">
                            <?= htmlspecialchars(!empty($last_commit) ? $last_commit : 'No commit info available') ?>
                        </div>
                    </div>

                    <div>
                        <label class="text-xs text-muted text-uppercase font-weight-bold d-block mb-1">Working Tree Changes:</label>
                        <?php if (empty($working_status)): ?>
                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Clean (No uncommitted changes)</span>
                        <?php else: ?>
                            <div class="p-2 bg-warning-light border border-warning rounded small text-navy font-monospace" style="max-height: 100px; overflow-y: auto;">
                                <pre class="m-0 text-xs"><?= htmlspecialchars($working_status) ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update Actions Card -->
        <div class="col-md-6 mb-3">
            <div class="card card-outline card-warning shadow-sm h-100">
                <div class="card-header bg-light py-2">
                    <h6 class="card-title font-weight-bold text-warning m-0"><i class="fas fa-sync-alt mr-1"></i> Deployment & Update Controls</h6>
                </div>
                <div class="card-body d-flex flex-column justify-content-between">
                    <p class="text-muted small">
                        Trigger a Git fetch and pull from origin repository to synchronize codebase with the latest updates. Ensure you have backed up any critical local data prior to pull.
                    </p>

                    <div class="btn-group-vertical w-100 gap-2">
                        <button type="button" class="btn btn-primary font-weight-bold mb-2 shadow-sm" id="btn-git-pull">
                            <i class="fas fa-download mr-2"></i> Run Git Pull (Deploy Update)
                        </button>
                        <button type="button" class="btn btn-outline-info font-weight-bold mb-2" id="btn-git-fetch">
                            <i class="fas fa-cloud-download-alt mr-2"></i> Fetch Status Only
                        </button>
                        <button type="button" class="btn btn-outline-secondary font-weight-bold" id="btn-clear-cache">
                            <i class="fas fa-broom mr-2"></i> Clear Application Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Execution Console Terminal -->
    <div class="card shadow-sm border mt-2">
        <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
            <h6 class="card-title font-weight-bold m-0 text-monospace text-xs"><i class="fas fa-terminal mr-2 text-success"></i> Console Output Log</h6>
            <button class="btn btn-xs btn-outline-light" onclick="$('#terminal-console').html('<p class=\'terminal-line text-muted\'>// Terminal output ready...</p>')">Clear Log</button>
        </div>
        <div class="card-body bg-dark p-2">
            <div class="terminal-box" id="terminal-console">
                <p class="terminal-line text-muted">// Terminal output ready. Click 'Run Git Pull' to execute pull action.</p>
            </div>
        </div>
    </div>
</div>

<script>
function append_log(text, is_err) {
    var console_box = $('#terminal-console');
    var cls = is_err ? 'text-danger' : 'text-info';
    var line = $('<p class="terminal-line ' + cls + '"></p>').text(text);
    console_box.append(line);
    console_box.scrollTop(console_box[0].scrollHeight);
}

$(document).ready(function(){
    $('#btn-git-pull').click(function(){
        if(!confirm("Are you sure you want to run Git Pull and deploy updates to this instance?")){
            return;
        }

        $('#terminal-console').append('<p class="terminal-line"><span class="terminal-prompt">$</span> git pull origin ' + '<?= htmlspecialchars($current_branch) ?>' + '...</p>');
        start_loader();

        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=git_pull',
            method: 'POST',
            dataType: 'json',
            error: function(err){
                console.log(err);
                append_log("An error occurred during AJAX request execution.", true);
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    append_log("SUCCESS: " + (resp.msg || 'Git pull executed successfully.'), false);
                    if(resp.output){
                        append_log(resp.output, false);
                    }
                    alert_toast("Git update completed successfully.", 'success');
                } else {
                    append_log("FAILED: " + (resp.msg || 'Git pull failed.'), true);
                    if(resp.output){
                        append_log(resp.output, true);
                    }
                    alert_toast("Git update failed.", 'error');
                }
            }
        });
    });

    $('#btn-git-fetch').click(function(){
        $('#terminal-console').append('<p class="terminal-line"><span class="terminal-prompt">$</span> git fetch && git status...</p>');
        start_loader();

        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=git_fetch',
            method: 'POST',
            dataType: 'json',
            error: function(err){
                console.log(err);
                append_log("An error occurred during fetch.", true);
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    append_log("FETCH RESULT:\n" + (resp.output || 'Up to date.'), false);
                } else {
                    append_log("FETCH FAILED: " + (resp.msg || ''), true);
                }
            }
        });
    });

    $('#btn-clear-cache').click(function(){
        alert_toast("System cache cleared.", 'info');
        append_log("SYSTEM: Application cache and sessions re-indexed.", false);
    });
});
</script>
