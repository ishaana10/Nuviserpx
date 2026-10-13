<?php
$tenant_id = $_settings->active_tenant_id();
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';
?>

<style>
.reports-center-container {
    max-width: 1050px;
    margin: 0 auto;
    padding: 15px 10px;
}

.reports-header {
    text-align: center;
    margin-bottom: 25px;
}

.reports-header h2 {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 15px;
    font-size: 1.8rem;
}

.reports-search-wrapper {
    position: relative;
    max-width: 550px;
    margin: 0 auto;
}

.reports-search-input {
    width: 100%;
    padding: 10px 16px 10px 42px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background-color: #ffffff;
    font-size: 0.95rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s ease;
}

.reports-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.reports-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.9rem;
}

.reports-nav-pills {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin-bottom: 25px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 15px;
}

.reports-nav-pills .nav-link {
    color: #64748b;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 8px 18px;
    border-radius: 20px;
    background-color: #f1f5f9;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
}

.reports-nav-pills .nav-link:hover {
    color: #2563eb;
    background-color: #eff6ff;
    border-color: #bfdbfe;
}

.reports-nav-pills .nav-link.active {
    color: #ffffff;
    background-color: #2563eb;
    border-color: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
}

.reports-main-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
    padding: 30px 35px;
}

.report-category {
    margin-bottom: 28px;
}

.report-category-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.report-category-title i {
    color: #64748b;
    font-size: 1rem;
}

.report-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    column-gap: 40px;
    row-gap: 10px;
}

@media (max-width: 768px) {
    .report-grid {
        grid-template-columns: 1fr;
    }
}

.report-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px dashed #e2e8f0;
    transition: background 0.15s ease;
}

.report-item:hover {
    background-color: #f8fafc;
}

.report-item-link {
    color: #2563eb;
    text-decoration: none;
    font-size: 0.92rem;
    font-weight: 500;
    transition: color 0.15s ease;
}

.report-item-link:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.report-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.report-action-btn {
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 2px 4px;
    font-size: 0.9rem;
    transition: color 0.15s ease, transform 0.15s ease;
}

.report-action-btn:hover {
    color: #3b82f6;
    transform: scale(1.1);
}

.report-action-btn.favorite.active {
    color: #f59e0b;
}

.favorites-category {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 25px;
}

.favorites-category .report-category-title {
    color: #b45309;
}

.favorites-category .report-category-title i {
    color: #f59e0b;
}

.no-results {
    text-align: center;
    padding: 40px 0;
    color: #64748b;
}
</style>

<div class="reports-center-container">
    <div class="reports-header">
        <h2>Reports Center</h2>
        <div class="reports-search-wrapper">
            <i class="fas fa-search reports-search-icon"></i>
            <input type="text" id="reportSearch" class="reports-search-input" placeholder="Search reports across all categories..." autocomplete="off">
        </div>
    </div>

    <!-- Category Tabs Navigation -->
    <ul class="nav reports-nav-pills" id="reportTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'all' ? 'active' : '' ?>" id="tab-all" data-toggle="tab" href="#content-all" role="tab" data-tab="all"><i class="fas fa-th-large mr-1"></i> All Reports</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'sales' ? 'active' : '' ?>" id="tab-sales" data-toggle="tab" href="#content-sales" role="tab" data-tab="sales"><i class="fas fa-shopping-cart mr-1"></i> Sales & AR</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'purchases' ? 'active' : '' ?>" id="tab-purchases" data-toggle="tab" href="#content-purchases" role="tab" data-tab="purchases"><i class="fas fa-wallet mr-1"></i> Purchases & Expenses</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'financial' ? 'active' : '' ?>" id="tab-financial" data-toggle="tab" href="#content-financial" role="tab" data-tab="financial"><i class="fas fa-chart-line mr-1"></i> Financials & Taxes</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'payroll' ? 'active' : '' ?>" id="tab-payroll" data-toggle="tab" href="#content-payroll" role="tab" data-tab="payroll"><i class="fas fa-users mr-1"></i> Payroll & HR</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'activity' ? 'active' : '' ?>" id="tab-activity" data-toggle="tab" href="#content-activity" role="tab" data-tab="activity"><i class="fas fa-history mr-1"></i> Activity & Audit</a>
        </li>
    </ul>

    <!-- Active Tenant Scope & Access Level Banner -->
    <div class="alert alert-light border shadow-sm mb-4 d-flex justify-content-between align-items-center rounded-lg">
        <div>
            <i class="fas fa-building text-primary mr-1"></i> Active Tenant Scope: <strong><?= htmlspecialchars($_settings->info('name') ?: 'Active Tenant') ?></strong> (Tenant ID: <?= $tenant_id ?>)
        </div>
        <span class="badge badge-primary px-3 py-1 font-weight-normal"><i class="fas fa-user-shield mr-1"></i> Access Level: <?= htmlspecialchars(method_exists($_settings, 'get_user_role') ? ($_settings->get_user_role()['name'] ?? 'User') : ($_settings->userdata('type') == 1 ? 'Administrator' : 'Staff User')) ?></span>
    </div>

    <div class="reports-main-card">
        <!-- Favorites Section (Populated via JS) -->
        <div id="favoritesSection" class="report-category favorites-category d-none">
            <div class="report-category-title">
                <i class="fas fa-star"></i> Pinned Favorites
            </div>
            <div id="favoritesGrid" class="report-grid"></div>
        </div>

        <div class="tab-content" id="reportTabsContent">

            <!-- ALL REPORTS TAB -->
            <div class="tab-pane fade <?= $active_tab == 'all' ? 'show active' : '' ?>" id="content-all" role="tabpanel">
                <!-- Sales Category -->
                <div class="report-category" data-category="sales">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Sales & Orders
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="sales_customer" data-title="Sales by Customer" data-url="./?page=invoices">
                            <a href="./?page=invoices" class="report-item-link">Sales by Customer</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="sales_customer" data-title="Sales by Customer" data-page="invoices"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="sales_customer"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="sales_item" data-title="Sales by Item" data-url="./?page=products">
                            <a href="./?page=products" class="report-item-link">Sales by Item</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="sales_item" data-title="Sales by Item" data-page="products"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="sales_item"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="sales_orders" data-title="Sales Order Summary" data-url="./?page=sales_orders">
                            <a href="./?page=sales_orders" class="report-item-link">Sales Order Summary</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="sales_orders" data-title="Sales Order Summary" data-page="sales_orders"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="sales_orders"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Receivables Category -->
                <div class="report-category" data-category="sales">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Accounts Receivable (AR)
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="ar_aging_summary" data-title="AR Aging Summary" data-url="./?page=reports/ar_aging">
                            <a href="./?page=reports/ar_aging" class="report-item-link">AR Aging Summary</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="ar_aging" data-title="AR Aging Summary" data-page="reports/ar_aging"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="ar_aging_summary"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="invoice_details" data-title="Invoice Details" data-url="./?page=invoices">
                            <a href="./?page=invoices" class="report-item-link">Invoice Details</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="invoice_details" data-title="Invoice Details" data-page="invoices"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="invoice_details"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="customer_balance_summary" data-title="Customer Balance Summary" data-url="./?page=parties">
                            <a href="./?page=parties" class="report-item-link">Customer Balance Summary</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="customer_balance" data-title="Customer Balance Summary" data-page="parties"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="customer_balance_summary"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="payments_received" data-title="Payments Received" data-url="./?page=payments">
                            <a href="./?page=payments" class="report-item-link">Payments Received</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="payments_received" data-title="Payments Received" data-page="payments"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="payments_received"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purchases & Expenses Category -->
                <div class="report-category" data-category="purchases">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Purchases and Expenses
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="expense_details" data-title="Expense Details" data-url="./?page=journals">
                            <a href="./?page=journals" class="report-item-link">Expense Details</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="expense_details" data-title="Expense Details" data-page="journals"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="expense_details"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="expenses_by_category" data-title="Expenses by Category" data-url="./?page=reports/income_statement">
                            <a href="./?page=reports/income_statement" class="report-item-link">Expenses by Category</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="expenses_by_category" data-title="Expenses by Category" data-page="reports/income_statement"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="expenses_by_category"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Statements Category -->
                <div class="report-category" data-category="financial">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Financial Statements & Taxes
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="general_ledger" data-title="General Ledger" data-url="./?page=reports/general_ledger">
                            <a href="./?page=reports/general_ledger" class="report-item-link">General Ledger</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="general_ledger" data-title="General Ledger" data-page="reports/general_ledger"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="general_ledger"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="balance_sheet" data-title="Balance Sheet" data-url="./?page=reports/balance_sheet">
                            <a href="./?page=reports/balance_sheet" class="report-item-link">Balance Sheet</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="balance_sheet" data-title="Balance Sheet" data-page="reports/balance_sheet"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="balance_sheet"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="income_statement" data-title="Income Statement (P&L)" data-url="./?page=reports/income_statement">
                            <a href="./?page=reports/income_statement" class="report-item-link">Income Statement (P&L)</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="income_statement" data-title="Income Statement (P&L)" data-page="reports/income_statement"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="income_statement"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="trial_balance" data-title="Trial Balance" data-url="./?page=reports/trial_balance">
                            <a href="./?page=reports/trial_balance" class="report-item-link">Trial Balance</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="trial_balance" data-title="Trial Balance" data-page="reports/trial_balance"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="trial_balance"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="working_trial_balance" data-title="Working Trial Balance" data-url="./?page=reports/working_trial_balance">
                            <a href="./?page=reports/working_trial_balance" class="report-item-link">Working Trial Balance</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="working_trial_balance" data-title="Working Trial Balance" data-page="reports/working_trial_balance"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="working_trial_balance"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payroll and HR Category -->
                <div class="report-category" data-category="payroll">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Payroll and HR
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="payroll_accounting" data-title="Payroll Accounting Report" data-url="./?page=reports/payroll_accounting">
                            <a href="./?page=reports/payroll_accounting" class="report-item-link">Payroll Accounting Report</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="payroll_accounting" data-title="Payroll Accounting Report" data-page="reports/payroll_accounting"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="payroll_accounting"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="attendance_details" data-title="Timesheet & Attendance Details" data-url="./?page=attendance">
                            <a href="./?page=attendance" class="report-item-link">Timesheet & Attendance Details</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="attendance_details" data-title="Timesheet & Attendance Details" data-page="attendance"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="attendance_details"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity & Audit Category -->
                <div class="report-category" data-category="activity">
                    <div class="report-category-title">
                        <i class="far fa-folder"></i> Activity
                    </div>
                    <div class="report-grid">
                        <div class="report-item" data-id="activity_logs" data-title="Activity & Audit Logs" data-url="./?page=journals">
                            <a href="./?page=journals" class="report-item-link">Activity & Audit Logs</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="activity_logs" data-title="Activity & Audit Logs" data-page="journals"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="activity_logs"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                        <div class="report-item" data-id="portal_activities" data-title="Portal Activities" data-url="./?page=user/list">
                            <a href="./?page=user/list" class="report-item-link">Portal Activities</a>
                            <div class="report-actions">
                                <button class="report-action-btn customize-btn" title="Customize Parameters" data-report="portal_activities" data-title="Portal Activities" data-page="user/list"><i class="fas fa-sliders-h"></i></button>
                                <button class="report-action-btn favorite" title="Pin to Favorites" data-id="portal_activities"><i class="far fa-star"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SALES & AR TAB -->
            <div class="tab-pane fade <?= $active_tab == 'sales' ? 'show active' : '' ?>" id="content-sales" role="tabpanel">
                <div id="salesTabContainer"></div>
            </div>

            <!-- PURCHASES & EXPENSES TAB -->
            <div class="tab-pane fade <?= $active_tab == 'purchases' ? 'show active' : '' ?>" id="content-purchases" role="tabpanel">
                <div id="purchasesTabContainer"></div>
            </div>

            <!-- FINANCIALS & TAXES TAB -->
            <div class="tab-pane fade <?= $active_tab == 'financial' ? 'show active' : '' ?>" id="content-financial" role="tabpanel">
                <div id="financialTabContainer"></div>
            </div>

            <!-- PAYROLL & HR TAB -->
            <div class="tab-pane fade <?= $active_tab == 'payroll' ? 'show active' : '' ?>" id="content-payroll" role="tabpanel">
                <div id="payrollTabContainer"></div>
            </div>

            <!-- ACTIVITY & AUDIT TAB -->
            <div class="tab-pane fade <?= $active_tab == 'activity' ? 'show active' : '' ?>" id="content-activity" role="tabpanel">
                <div id="activityTabContainer"></div>
            </div>

        </div>

        <div id="noResults" class="no-results d-none">
            <i class="fas fa-search fa-2x mb-2 text-muted"></i>
            <p class="m-0">No matching reports found.</p>
        </div>
    </div>
</div>

<!-- Modal for Report Parameter Customization -->
<div class="modal fade" id="reportCustomizeModal" tabindex="-1" role="dialog" aria-labelledby="reportCustomizeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content rounded-0">
      <div class="modal-header bg-navy text-white rounded-0">
        <h5 class="modal-title font-weight-bold" id="reportCustomizeModalLabel"><i class="fas fa-sliders-h"></i> Customize Report</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="customizeReportForm" action="" method="GET">
        <input type="hidden" name="page" id="customPageInput" value="">
        <div class="modal-body">
            <p class="text-navy font-weight-bold mb-3" id="customReportTitleName"></p>
            <div class="form-group">
                <label for="customFromDate" class="control-label font-weight-bold">From Date</label>
                <input type="date" id="customFromDate" name="from" class="form-control form-control-sm rounded-0" value="<?= date('Y-m-01') ?>">
            </div>
            <div class="form-group">
                <label for="customToDate" class="control-label font-weight-bold">To Date</label>
                <input type="date" id="customToDate" name="to" class="form-control form-control-sm rounded-0" value="<?= date('Y-m-t') ?>">
            </div>
            <div class="form-group">
                <label for="customAsOfDate" class="control-label font-weight-bold">As Of Date (For Aging / Balance Sheets)</label>
                <input type="date" id="customAsOfDate" name="as_of" class="form-control form-control-sm rounded-0" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-navy bg-gradient-navy btn-flat btn-sm"><i class="fas fa-play"></i> Run Custom Report</button>
          <button type="button" class="btn btn-secondary btn-flat btn-sm" data-dismiss="modal">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
    var favoriteStorageKey = 'nuvis_report_favorites';

    function getFavorites() {
        var favs = localStorage.getItem(favoriteStorageKey);
        return favs ? JSON.parse(favs) : [];
    }

    function setFavorites(favs) {
        localStorage.setItem(favoriteStorageKey, JSON.stringify(favs));
    }

    function renderFavorites() {
        var favs = getFavorites();
        var $favSection = $('#favoritesSection');
        var $favGrid = $('#favoritesGrid');
        $favGrid.empty();

        if (favs.length === 0) {
            $favSection.addClass('d-none');
            $('.report-action-btn.favorite').removeClass('active').html('<i class="far fa-star"></i>');
            return;
        }

        $favSection.removeClass('d-none');
        favs.forEach(function(id) {
            var $originalItem = $('.report-item[data-id="' + id + '"]');
            if ($originalItem.length > 0) {
                $originalItem.find('.favorite').addClass('active').html('<i class="fas fa-star"></i>');
                var $clone = $originalItem.clone(true);
                $favGrid.append($clone);
            }
        });
    }

    function populateTabContainers() {
        $('#salesTabContainer').html($('#content-all .report-category[data-category="sales"]').clone(true));
        $('#purchasesTabContainer').html($('#content-all .report-category[data-category="purchases"]').clone(true));
        $('#financialTabContainer').html($('#content-all .report-category[data-category="financial"]').clone(true));
        $('#payrollTabContainer').html($('#content-all .report-category[data-category="payroll"]').clone(true));
        $('#activityTabContainer').html($('#content-all .report-category[data-category="activity"]').clone(true));
    }

    // Toggle favorite logic
    $(document).on('click', '.report-action-btn.favorite', function(e){
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).data('id');
        var favs = getFavorites();
        var index = favs.indexOf(id);

        if (index > -1) {
            favs.splice(index, 1);
        } else {
            favs.push(id);
        }

        setFavorites(favs);
        $('.report-action-btn.favorite').removeClass('active').html('<i class="far fa-star"></i>');
        renderFavorites();
    });

    // Real-time search filter across all tabs
    $('#reportSearch').on('keyup input', function(){
        var val = $(this).val().toLowerCase().trim();
        var matchCount = 0;

        if(val !== '') {
            $('#tab-all').tab('show');
        }

        $('.report-category').each(function(){
            if ($(this).attr('id') === 'favoritesSection') return;

            var categoryHasMatch = false;
            $(this).find('.report-item').each(function(){
                var title = $(this).data('title').toLowerCase();
                if (title.indexOf(val) > -1) {
                    $(this).removeClass('d-none');
                    categoryHasMatch = true;
                    matchCount++;
                } else {
                    $(this).addClass('d-none');
                }
            });

            if (categoryHasMatch) {
                $(this).removeClass('d-none');
            } else {
                $(this).addClass('d-none');
            }
        });

        if (matchCount === 0 && val !== '') {
            $('#noResults').removeClass('d-none');
        } else {
            $('#noResults').addClass('d-none');
        }
    });

    // Open parameter customization modal
    $(document).on('click', '.customize-btn', function(e){
        e.preventDefault();
        var page = $(this).data('page');
        var title = $(this).data('title');

        $('#customPageInput').val(page);
        $('#customReportTitleName').text('Parameters for: ' + title);
        $('#reportCustomizeModal').modal('show');
    });

    populateTabContainers();
    renderFavorites();
});
</script>
