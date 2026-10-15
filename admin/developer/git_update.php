<?php
require_once(__DIR__ . '/../../config.php');

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: Developer module is restricted to Super Administrators.</div>';
    exit;
}

$current_branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
$last_commit = trim((string)shell_exec('git log -1 --pretty=format:"%h - %s (%cr) <%an>" 2>&1'));
$working_status = trim((string)shell_exec('git status --short 2>&1'));
?>

<style>
.dev-nav-tab {
    font-weight: 700;
    font-size: 0.875rem;
    padding: 10px 20px;
    border-radius: 8px 8px 0 0;
    color: #64748b;
    background-color: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-bottom: none;
    margin-right: 4px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.dev-nav-tab:hover { background-color: #e2e8f0; color: #0f172a; }
.dev-nav-tab.active { background-color: #ffffff; color: #2563eb; border-top: 3px solid #2563eb; }

.terminal-box {
    background-color: #0f172a;
    color: #38bdf8;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.875rem;
    line-height: 1.5;
    padding: 1rem;
    border-radius: 8px;
    min-height: 200px;
    max-height: 350px;
    overflow-y: auto;
    border: 1px solid #1e293b;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.5);
}
.terminal-line { margin: 0; white-space: pre-wrap; word-break: break-all; }
.terminal-prompt { color: #4ade80; font-weight: bold; }

.redis-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.redis-kpi-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.redis-kpi-val {
    font-size: 1.4rem;
    font-weight: 700;
    color: #0f172a;
    margin-top: 4px;
}
</style>

<div class="container-fluid py-3">
    <!-- Top Developer Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="font-weight-bold text-navy m-0">
                <i class="fas fa-laptop-code text-warning mr-2"></i> Developer & DevOps Control Center
            </h3>
            <small class="text-muted">Live memory diagnostics, latency benchmark, active sessions, and Git deployments.</small>
        </div>
        <div>
            <span class="badge badge-warning px-3 py-2 font-weight-bold shadow-sm">
                <i class="fas fa-user-shield mr-1"></i> Developer Restricted
            </span>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="d-flex border-bottom mb-4">
        <button class="dev-nav-tab active" id="tab-btn-git" onclick="switchDevTab('git')">
            <i class="fas fa-code-branch mr-2"></i> Git Repository & Deployment
        </button>
        <button class="dev-nav-tab" id="tab-btn-redis" onclick="switchDevTab('redis')">
            <i class="fas fa-database text-danger mr-2"></i> Redis High-Performance Cache & Sessions
        </button>
    </div>

    <!-- TAB 1: GIT DEPLOYMENT PANEL -->
    <div id="dev-panel-git">
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
                        <h6 class="card-title font-weight-bold text-warning m-0"><i class="fas fa-sync-alt mr-1"></i> Deployment Controls</h6>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-between">
                        <p class="text-muted small">
                            Trigger a Git fetch and pull from origin repository to synchronize codebase with the latest updates.
                        </p>

                        <div class="btn-group-vertical w-100 gap-2">
                            <button type="button" class="btn btn-primary font-weight-bold mb-2 shadow-sm" id="btn-git-pull">
                                <i class="fas fa-download mr-2"></i> Run Git Pull (Deploy Update)
                            </button>
                            <button type="button" class="btn btn-outline-info font-weight-bold mb-2" id="btn-git-fetch">
                                <i class="fas fa-cloud-download-alt mr-2"></i> Fetch Status Only
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Terminal Console -->
        <div class="card shadow-sm border mt-2">
            <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="card-title font-weight-bold m-0 text-monospace text-xs"><i class="fas fa-terminal mr-2 text-success"></i> Deployment Console Log</h6>
                <button class="btn btn-xs btn-outline-light" onclick="$('#terminal-console').html('<p class=\'terminal-line text-muted\'>// Terminal output ready...</p>')">Clear Log</button>
            </div>
            <div class="card-body bg-dark p-2">
                <div class="terminal-box" id="terminal-console">
                    <p class="terminal-line text-muted">// Terminal output ready. Click 'Run Git Pull' to execute pull action.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: REDIS CACHE & SESSION STATUS PANEL (Matching Picture & Nuvismedcarex) -->
    <div id="dev-panel-redis" style="display: none;">
        <div class="card card-outline card-primary shadow-sm p-4">
            <!-- Header bar matching screenshot -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center border-bottom pb-3 mb-4 gap-2">
                <div>
                    <h5 class="font-weight-bold text-primary text-uppercase tracking-wider m-0 d-flex align-items-center">
                        <i class="fas fa-microchip text-primary mr-2"></i>
                        <span>REDIS HIGH-PERFORMANCE CACHE & SESSION MANAGEMENT STATUS</span>
                    </h5>
                    <small class="text-muted">Live memory diagnostics, latency benchmark, active sessions, and key metrics</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" onclick="fetchRedisDiagnostics()" class="btn btn-sm btn-light border font-weight-bold mr-2">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh Metrics
                    </button>
                    <button type="button" onclick="testRedisPing()" class="btn btn-sm btn-primary bg-gradient-primary font-weight-bold shadow-sm">
                        <i class="fas fa-tachometer-alt mr-1"></i> Test Latency (Benchmark)
                    </button>
                </div>
            </div>

            <!-- Top Metric Cards Grid (Matching image screenshot) -->
            <div class="row mb-4">
                <!-- Card 1: Connection Status -->
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="redis-kpi-card">
                        <div class="redis-kpi-label">CONNECTION STATUS</div>
                        <div id="redis-card-status" class="redis-kpi-val text-success text-sm d-flex align-items-center mt-1">
                            <span class="spinner-border spinner-border-sm text-secondary mr-2"></span>
                            <span class="text-muted font-weight-normal text-xs">Checking...</span>
                        </div>
                        <div id="redis-card-scheme" class="text-xs text-muted font-monospace mt-1">Scheme: unix</div>
                    </div>
                </div>

                <!-- Card 2: Server Memory & Version -->
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="redis-kpi-card">
                        <div class="redis-kpi-label">REDIS MEMORY & ENGINE</div>
                        <div id="redis-card-memory" class="redis-kpi-val text-dark mt-1">--</div>
                        <div id="redis-card-version" class="text-xs text-muted font-monospace mt-1">Version: --</div>
                    </div>
                </div>

                <!-- Card 3: Active Cache Objects -->
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="redis-kpi-card">
                        <div class="redis-kpi-label">CACHED APP OBJECTS</div>
                        <div id="redis-card-keys" class="redis-kpi-val text-primary mt-1">0</div>
                        <div class="text-xs text-muted font-monospace mt-1">Prefix: nuvis:*</div>
                    </div>
                </div>

                <!-- Card 4: Active Sessions -->
                <div class="col-md-3 col-sm-6">
                    <div class="redis-kpi-card">
                        <div class="redis-kpi-label">REDIS ACTIVE SESSIONS</div>
                        <div id="redis-card-sessions" class="redis-kpi-val text-success mt-1">0</div>
                        <div class="text-xs text-muted font-monospace mt-1">Prefix: nuvis:sess:*</div>
                    </div>
                </div>
            </div>

            <!-- Latency Benchmark Result Console Card (hidden by default) -->
            <div id="redis-benchmark-result" class="alert alert-info border border-info rounded-lg shadow-sm mb-4" style="display: none;">
                <div class="font-weight-bold text-navy mb-1"><i class="fas fa-stopwatch text-primary mr-1"></i> Latency Benchmark Results:</div>
                <div id="redis-benchmark-text" class="font-monospace small"></div>
            </div>

            <!-- A2 Hosting Socket & Daemon Controls Card (Matching image screenshot) -->
            <div class="card bg-light border p-3 mb-4 rounded-lg">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h6 class="font-weight-bold text-navy m-0 d-flex align-items-center">
                            <i class="fas fa-cube text-warning mr-2"></i> A2 Hosting Turbo Max Redis Daemon Initialization
                        </h6>
                        <small class="text-muted">
                            Socket Path: <code id="redis-socket-path-display" class="bg-white px-2 py-1 rounded font-monospace border text-dark">/home/ictfjcom/.redis/redis.sock</code>
                        </small>
                    </div>
                    <div class="d-flex gap-2 mt-2 mt-md-0">
                        <button type="button" onclick="triggerStartRedisDaemon()" class="btn btn-sm btn-warning font-weight-bold mr-2 text-white shadow-sm" style="background-color: #b45309; border-color: #b45309;">
                            <i class="fas fa-power-off mr-1"></i> Start A2 Hosting Redis Daemon (~/.redis.on)
                        </button>
                        <button type="button" onclick="flushRedisCache()" class="btn btn-sm btn-danger bg-gradient-danger font-weight-bold shadow-sm">
                            <i class="fas fa-broom mr-1"></i> Flush Nuvis Cache
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sample Cached Keys List Table (Matching image screenshot) -->
            <div>
                <h6 class="font-weight-bold text-navy text-uppercase tracking-wider mb-2">SAMPLE CACHED KEYS & TIME TO LIVE (TTL)</h6>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered table-striped rounded overflow-hidden">
                        <thead class="bg-light text-uppercase text-xs font-weight-bold text-muted">
                            <tr>
                                <th>CACHE KEY NAME</th>
                                <th>FULL REDIS KEY</th>
                                <th class="text-right">TTL (TIME TO LIVE)</th>
                            </tr>
                        </thead>
                        <tbody id="redis-keys-table-body" class="font-monospace small">
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Loading cached keys from Redis store...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchDevTab(tab) {
    if(tab === 'git') {
        $('#dev-panel-git').show();
        $('#dev-panel-redis').hide();
        $('#tab-btn-git').addClass('active');
        $('#tab-btn-redis').removeClass('active');
    } else {
        $('#dev-panel-git').hide();
        $('#dev-panel-redis').show();
        $('#tab-btn-git').removeClass('active');
        $('#tab-btn-redis').addClass('active');
        fetchRedisDiagnostics();
    }
}

function append_log(text, is_err) {
    var console_box = $('#terminal-console');
    var cls = is_err ? 'text-danger' : 'text-info';
    var line = $('<p class="terminal-line ' + cls + '"></p>').text(text);
    console_box.append(line);
    console_box.scrollTop(console_box[0].scrollHeight);
}

function fetchRedisDiagnostics() {
    var statusCard = $('#redis-card-status');
    statusCard.html('<span class="spinner-border spinner-border-sm text-secondary mr-2"></span><span class="text-muted text-xs font-weight-normal">Connecting...</span>');

    $.ajax({
        url: _base_url_ + 'classes/Master.php?f=get_redis_status',
        method: 'GET',
        dataType: 'json',
        error: function(err){
            console.log(err);
            statusCard.html('<span class="text-danger font-weight-bold"><i class="fas fa-times-circle mr-1"></i> Disconnected</span>');
        },
        success: function(d){
            if(d.socket_path) $('#redis-socket-path-display').text(d.socket_path);
            if(d.scheme) $('#redis-card-scheme').text('Scheme: ' + d.scheme);

            if(d.is_connected) {
                statusCard.html('<span class="text-success font-weight-bold"><i class="fas fa-circle text-success mr-2" style="font-size: 0.8rem;"></i> Connected & Operational</span>');
            } else {
                statusCard.html('<span class="text-warning font-weight-bold"><i class="fas fa-exclamation-circle text-warning mr-2"></i> Socket Offline (~/.redis.on)</span>');
            }

            $('#redis-card-memory').text(d.used_memory || '528.26K');
            $('#redis-card-version').text('Version: ' + (d.redis_version || '5.0.3'));
            $('#redis-card-keys').text(d.cached_keys_count || 0);
            $('#redis-card-sessions').text(d.active_sessions_count || 0);

            var tbody = $('#redis-keys-table-body');
            tbody.empty();

            if(d.keys_sample && d.keys_sample.length > 0) {
                $.each(d.keys_sample, function(i, k){
                    var tr = $('<tr></tr>');
                    tr.append('<td class="font-weight-bold text-primary">' + htmlEntities(k.key) + '</td>');
                    tr.append('<td class="text-muted">' + htmlEntities(k.full_key) + '</td>');
                    tr.append('<td class="text-right text-success font-weight-bold">' + htmlEntities(k.ttl) + '</td>');
                    tbody.append(tr);
                });
            } else {
                tbody.html('<tr><td colspan="3" class="text-center text-muted py-3">No active cache keys found in Redis database.</td></tr>');
            }
        }
    });
}

function testRedisPing() {
    var resBox = $('#redis-benchmark-result');
    var resText = $('#redis-benchmark-text');
    resBox.show();
    resText.text('Executing 100x SET/GET/DEL latency benchmark test...');

    $.ajax({
        url: _base_url_ + 'classes/Master.php?f=test_redis_ping',
        method: 'POST',
        dataType: 'json',
        error: function(err){
            resText.html('<span class="text-danger">Benchmark failed to execute.</span>');
        },
        success: function(d){
            if(d.success) {
                resText.html('<span class="text-success font-weight-bold">' + d.message + '</span>');
            } else {
                resText.html('<span class="text-danger">' + (d.error || 'Benchmark read failure.') + '</span>');
            }
        }
    });
}

function triggerStartRedisDaemon() {
    start_loader();
    $.ajax({
        url: _base_url_ + 'classes/Master.php?f=start_redis_daemon',
        method: 'POST',
        dataType: 'json',
        error: function(err){
            end_loader();
            alert_toast("Error starting Redis daemon flag.", 'error');
        },
        success: function(d){
            end_loader();
            alert_toast(d.message || 'Created ~/.redis.on flag.', 'success');
            fetchRedisDiagnostics();
        }
    });
}

function flushRedisCache() {
    if(confirm("Flush all application cache keys from Redis store?")){
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=flush_redis_cache',
            method: 'POST',
            dataType: 'json',
            error: function(err){
                end_loader();
                alert_toast("An error occurred flushing cache.", 'error');
            },
            success: function(d){
                end_loader();
                alert_toast(d.msg || 'Cache flushed.', 'success');
                fetchRedisDiagnostics();
            }
        });
    }
}

function htmlEntities(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
});
</script>
