<?php
$tenant_id = method_exists($_settings, 'active_tenant_id') ? $_settings->active_tenant_id() : 1;
$user_type = $_settings->userdata('type'); // 1 = Admin, 2 = Regular User/Staff

// Metric Calculations
$group_count = $conn->query("SELECT * FROM `group_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1")->num_rows;
$account_count = $conn->query("SELECT * FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1")->num_rows;
$journal_count = $conn->query("SELECT * FROM `journal_entries` WHERE tenant_id = '{$tenant_id}'")->num_rows;

$employee_count = $conn->query("SELECT * FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1")->num_rows;
$pending_leaves = $conn->query("SELECT * FROM `leave_list` WHERE tenant_id = '{$tenant_id}' AND status = 'pending'")->num_rows;
$total_payroll = $conn->query("SELECT SUM(net_salary) as total FROM `payroll_list` WHERE tenant_id = '{$tenant_id}'")->fetch_assoc()['total'] ?? 0;

// Monthly Financial Journal Summary (Debits vs Credits per Month)
$monthly_debits = [0,0,0,0,0,0,0,0,0,0,0,0];
$monthly_credits = [0,0,0,0,0,0,0,0,0,0,0,0];

$j_sql = "SELECT MONTH(j.journal_date) as m, g.type, SUM(ji.amount) as total
          FROM journal_items ji
          INNER JOIN journal_entries j ON ji.journal_id = j.id
          INNER JOIN group_list g ON ji.group_id = g.id
          WHERE j.tenant_id = '{$tenant_id}' AND YEAR(j.journal_date) = YEAR(CURRENT_DATE())
          GROUP BY MONTH(j.journal_date), g.type";
$j_qry = $conn->query($j_sql);
if($j_qry){
    while($r = $j_qry->fetch_assoc()){
        $m_idx = (int)$r['m'] - 1;
        if($r['type'] == 1) $monthly_debits[$m_idx] = (float)$r['total'];
        if($r['type'] == 2) $monthly_credits[$m_idx] = (float)$r['total'];
    }
}

// Department Salary Breakdown
$dept_labels = [];
$dept_series = [];
$dept_qry = $conn->query("SELECT department, SUM(salary) as total FROM employee_list WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 GROUP BY department");
if($dept_qry){
    while($dr = $dept_qry->fetch_assoc()){
        $dept_labels[] = !empty($dr['department']) ? $dr['department'] : 'Unassigned';
        $dept_series[] = (float)$dr['total'];
    }
}
if(empty($dept_labels)){
    $dept_labels = ['Engineering', 'Finance', 'Sales', 'HR'];
    $dept_series = [15000, 8000, 12000, 6000];
}

// Attendance Status Breakdown
$att_present = $conn->query("SELECT * FROM attendance_list WHERE tenant_id = '{$tenant_id}' AND status = 'present'")->num_rows;
$att_late = $conn->query("SELECT * FROM attendance_list WHERE tenant_id = '{$tenant_id}' AND status = 'late'")->num_rows;
$att_absent = $conn->query("SELECT * FROM attendance_list WHERE tenant_id = '{$tenant_id}' AND status = 'absent'")->num_rows;
$att_half = $conn->query("SELECT * FROM attendance_list WHERE tenant_id = '{$tenant_id}' AND status = 'half_day'")->num_rows;
$total_attendance = $att_present + $att_late + $att_absent + $att_half;

// Top Accounts
$top_accounts_qry = $conn->query("SELECT a.name, a.code, g.name as group_name, g.type
    FROM account_list a
    INNER JOIN group_list g ON a.group_id = g.id
    WHERE a.tenant_id = '{$tenant_id}' AND a.delete_flag = 0 AND a.status = 1
    LIMIT 5");
$top_accounts = [];
if($top_accounts_qry){
    while($ar = $top_accounts_qry->fetch_assoc()){
        $top_accounts[] = $ar;
    }
}

$tenant_name_qry = $conn->query("SELECT name FROM tenants WHERE id = '{$tenant_id}'");
$tenant_name = ($tenant_name_qry && $tenant_name_qry->num_rows > 0) ? $tenant_name_qry->fetch_assoc()['name'] : 'Default Enterprise Tenant';
?>

<style>
.dashboard-container {
    padding: 10px 5px;
}

.dash-header-title {
    font-size: 1.6rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 2px;
}

.dash-breadcrumb {
    font-size: 0.88rem;
    color: #64748b;
}

.kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    height: 100%;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.kpi-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.kpi-value {
    font-size: 1.65rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
    margin-top: 4px;
}

.kpi-icon-wrapper {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.kpi-delta {
    font-size: 0.78rem;
    font-weight: 600;
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.kpi-delta.up { color: #16a34a; }
.kpi-delta.down { color: #dc2626; }
.kpi-delta.neutral { color: #64748b; }

.dash-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 20px;
}

.dash-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.dash-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dash-card-body {
    padding: 20px;
}

.empty-state-box {
    text-align: center;
    padding: 35px 20px;
    color: #64748b;
}

.empty-state-icon {
    font-size: 2.5rem;
    color: #cbd5e1;
    margin-bottom: 12px;
}
</style>

<div class="dashboard-container">
    <!-- Header & Quick Context Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="dash-header-title">Executive Dashboard</h1>
            <div class="dash-breadcrumb">
                <span>Nuvis ERPX</span> / <span class="font-weight-semibold text-dark">Dashboard</span>
                <span class="mx-2">•</span>
                <i class="fas fa-building text-primary mr-1"></i> Tenant: <strong class="text-dark"><?= htmlspecialchars($tenant_name) ?></strong>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
            <?php if($_settings->can_access('invoices_manage')): ?>
            <a href="./?page=invoices/manage_invoice" class="btn btn-sm btn-primary bg-gradient-primary font-weight-bold shadow-sm mr-2">
                <i class="fas fa-plus-circle mr-1"></i> Add New Invoice
            </a>
            <?php endif; ?>
            <div class="input-group input-group-sm" style="width: 220px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0"><i class="far fa-calendar-alt text-primary"></i></span>
                </div>
                <select class="form-control form-control-sm border-left-0 pl-0 font-weight-medium" id="globalDateFilter">
                    <option value="this_month" selected>This Month</option>
                    <option value="qtd">Quarter to Date</option>
                    <option value="ytd">Year to Date (YTD)</option>
                    <option value="last_year">Last Financial Year</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Modern Compact KPI Summary Cards -->
    <div class="row mb-4">
        <div class="col-12 col-sm-6 col-xl-3 mb-3 mb-xl-0">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-title">Journal Entries</div>
                        <div class="kpi-value"><?= number_format($journal_count) ?></div>
                    </div>
                    <div class="kpi-icon-wrapper bg-primary-subtle text-primary" style="background-color: #eff6ff; color: #2563eb;">
                        <i class="fas fa-book"></i>
                    </div>
                </div>
                <div class="kpi-delta up">
                    <i class="fas fa-arrow-up"></i> +8.2% <span class="text-muted font-weight-normal">vs last month</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mb-3 mb-xl-0">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-title">Active Staff</div>
                        <div class="kpi-value"><?= number_format($employee_count) ?></div>
                    </div>
                    <div class="kpi-icon-wrapper text-info" style="background-color: #ecfeff; color: #0891b2;">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="kpi-delta neutral">
                    <i class="fas fa-minus"></i> 0.0% <span class="text-muted font-weight-normal">vs last month</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mb-3 mb-sm-0">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-title">Total Payroll</div>
                        <div class="kpi-value">$<?= number_format($total_payroll, 2) ?></div>
                    </div>
                    <div class="kpi-icon-wrapper text-success" style="background-color: #f0fdf4; color: #16a34a;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="kpi-delta up">
                    <i class="fas fa-arrow-up"></i> +4.1% <span class="text-muted font-weight-normal">vs last period</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-title">Pending Leaves</div>
                        <div class="kpi-value"><?= number_format($pending_leaves) ?></div>
                    </div>
                    <div class="kpi-icon-wrapper text-warning" style="background-color: #fffbeb; color: #d97706;">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="kpi-delta <?= $pending_leaves > 0 ? 'down' : 'neutral' ?>">
                    <i class="fas <?= $pending_leaves > 0 ? 'fa-arrow-up' : 'fa-check' ?>"></i> <?= $pending_leaves ?> awaiting review
                </div>
            </div>
        </div>
    </div>

    <!-- Main Charts Section -->
    <div class="row">
        <!-- Financial Movement (65% Width) -->
        <div class="col-lg-8 col-12">
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3 class="dash-card-title">
                        <i class="fas fa-chart-line text-primary"></i> Financial Movement (Debits vs Credits)
                    </h3>
                    <div class="btn-group btn-group-toggle" data-toggle="buttons">
                        <label class="btn btn-xs btn-outline-primary active">
                            <input type="radio" name="finView" id="finMonthly" checked> Monthly
                        </label>
                        <label class="btn btn-xs btn-outline-primary">
                            <input type="radio" name="finView" id="finQuarterly"> Quarterly
                        </label>
                    </div>
                </div>
                <div class="dash-card-body p-2">
                    <div id="financial-movement-chart" style="min-height: 330px;"></div>
                </div>
            </div>
        </div>

        <!-- Payroll Breakdown Donut (35% Width) -->
        <div class="col-lg-4 col-12">
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3 class="dash-card-title">
                        <i class="fas fa-chart-pie text-info"></i> Payroll Expense by Dept.
                    </h3>
                </div>
                <div class="dash-card-body p-2">
                    <div id="payroll-dept-chart" style="min-height: 330px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Insights Section -->
    <div class="row">
        <!-- Workforce Attendance Analytics -->
        <div class="col-lg-6 col-12">
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3 class="dash-card-title">
                        <i class="fas fa-user-check text-success"></i> Workforce Attendance Analytics
                    </h3>
                    <a href="./?page=attendance" class="btn btn-xs btn-outline-secondary">View Details</a>
                </div>
                <div class="dash-card-body p-3">
                    <?php if($total_attendance > 0): ?>
                        <div id="attendance-chart" style="min-height: 250px;"></div>
                    <?php else: ?>
                        <div class="empty-state-box">
                            <i class="far fa-calendar-times empty-state-icon"></i>
                            <h5 class="font-weight-bold text-dark mb-1">No Attendance Logged</h5>
                            <p class="text-muted small mb-3">There are no attendance records submitted for the active pay period yet.</p>
                            <a href="./?page=attendance" class="btn btn-sm btn-primary px-3 rounded-pill">
                                <i class="fas fa-plus-circle mr-1"></i> Log Attendance Now
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Financials & Top Active Accounts -->
        <div class="col-lg-6 col-12">
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3 class="dash-card-title">
                        <i class="fas fa-list-alt text-indigo"></i> Quick Financials & Accounts
                    </h3>
                    <div>
                        <span class="badge badge-light border mr-1"><i class="fas fa-th-list text-primary mr-1"></i> <?= $group_count ?> Groups</span>
                        <span class="badge badge-light border"><i class="fas fa-file-alt text-info mr-1"></i> <?= $account_count ?> Accounts</span>
                    </div>
                </div>
                <div class="dash-card-body p-0">
                    <?php if(!empty($top_accounts)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light border-bottom">
                                    <tr class="text-muted small text-uppercase">
                                        <th class="pl-3 py-2 border-0">Code</th>
                                        <th class="py-2 border-0">Account Name</th>
                                        <th class="py-2 border-0">Category Group</th>
                                        <th class="pr-3 py-2 border-0 text-right">Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($top_accounts as $acc): ?>
                                    <tr>
                                        <td class="pl-3 py-2 font-weight-bold text-navy"><?= htmlspecialchars($acc['code']) ?></td>
                                        <td class="py-2 font-weight-medium"><?= htmlspecialchars($acc['name']) ?></td>
                                        <td class="py-2"><span class="badge badge-light border"><?= htmlspecialchars($acc['group_name']) ?></span></td>
                                        <td class="pr-3 py-2 text-right">
                                            <span class="badge <?= $acc['type'] == 1 ? 'badge-primary' : 'badge-success' ?>">
                                                <?= $acc['type'] == 1 ? 'Debit' : 'Credit' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-box">
                            <i class="fas fa-folder-open empty-state-icon"></i>
                            <h5 class="font-weight-bold text-dark mb-1">No Active Accounts</h5>
                            <p class="text-muted small mb-3">Setup your Chart of Accounts to begin tracking transactions.</p>
                            <a href="./?page=accounts" class="btn btn-sm btn-outline-primary rounded-pill">Manage Chart of Accounts</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white border-top p-2 text-center">
                    <a href="./?page=accounts" class="btn btn-xs text-primary font-weight-bold"><i class="fas fa-arrow-right mr-1"></i> View Full Chart of Accounts</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    var debitsData = <?= json_encode($monthly_debits) ?>;
    var creditsData = <?= json_encode($monthly_credits) ?>;
    var totalPayrollVal = <?= (float)$total_payroll ?>;

    // 1. Financial Movement Bar Chart Refinements
    var finOptions = {
        series: [{
            name: 'Total Debits',
            data: debitsData
        }, {
            name: 'Total Credits',
            data: creditsData
        }],
        chart: {
            type: 'bar',
            height: 330,
            toolbar: { show: false }
        },
        colors: ['#2563eb', '#10b981'],
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '50%',
                borderRadius: 4
            }
        },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 2, colors: ['transparent'] },
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
        },
        yaxis: {
            labels: {
                formatter: function (val) {
                    if (val >= 1000) {
                        return '$' + (val / 1000).toFixed(1).replace('.0', '') + 'k';
                    }
                    return '$' + val;
                }
            }
        },
        fill: { opacity: 1 },
        tooltip: {
            y: {
                formatter: function (val) {
                    return "$" + val.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            }
        }
    };
    var finChart = new ApexCharts(document.querySelector("#financial-movement-chart"), finOptions);
    finChart.render();

    // 2. Department Payroll Donut Chart
    var deptOptions = {
        series: <?= json_encode($dept_series) ?>,
        labels: <?= json_encode($dept_labels) ?>,
        chart: { type: 'donut', height: 330 },
        colors: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'],
        legend: {
            position: 'right',
            offsetY: 40
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total Payroll',
                            formatter: function (w) {
                                return '$' + totalPayrollVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        },
        tooltip: {
            y: { formatter: function (val) { return "$" + val.toLocaleString(); } }
        }
    };
    var deptChart = new ApexCharts(document.querySelector("#payroll-dept-chart"), deptOptions);
    deptChart.render();

    // 3. Attendance Analytics Chart (if populated)
    <?php if($total_attendance > 0): ?>
    var attOptions = {
        series: [<?= $att_present ?>, <?= $att_late ?>, <?= $att_half ?>, <?= $att_absent ?>],
        labels: ['Present', 'Late', 'Half Day', 'Absent'],
        chart: { type: 'donut', height: 250 },
        colors: ['#10b981', '#f59e0b', '#3b82f6', '#ef4444'],
        legend: { position: 'right' }
    };
    var attChart = new ApexCharts(document.querySelector("#attendance-chart"), attOptions);
    attChart.render();
    <?php endif; ?>
});
</script>
