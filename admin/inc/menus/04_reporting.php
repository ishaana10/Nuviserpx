<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 4. Reporting -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-chart-line"></i>
                                    <p>
                                        Reporting
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports" class="nav-link nav-reports tree-item">
                                            <i class="nav-icon fas fa-th-large"></i>
                                            <p>All Reports Hub</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/general_ledger" class="nav-link nav-reports_general_ledger tree-item">
                                            <i class="nav-icon fas fa-book-open"></i>
                                            <p>General Ledger</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/balance_sheet" class="nav-link nav-reports_balance_sheet tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>Balance Sheet</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/income_statement" class="nav-link nav-reports_income_statement tree-item">
                                            <i class="nav-icon fas fa-chart-bar"></i>
                                            <p>Income Statement</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/cash_flow" class="nav-link nav-reports_cash_flow tree-item">
                                            <i class="nav-icon fas fa-water"></i>
                                            <p>Cash Flow</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/equity_changes" class="nav-link nav-reports_equity_changes tree-item">
                                            <i class="nav-icon fas fa-layer-group"></i>
                                            <p>Changes in Equity</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/budget_vs_actual" class="nav-link nav-reports_budget_vs_actual tree-item">
                                            <i class="nav-icon fas fa-bullseye"></i>
                                            <p>Budget vs Actual</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=budgets" class="nav-link nav-budgets tree-item">
                                            <i class="nav-icon fas fa-edit"></i>
                                            <p>Budgets</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/ar_aging" class="nav-link nav-reports_ar_aging tree-item">
                                            <i class="nav-icon fas fa-history"></i>
                                            <p>AR Aging</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/ap_aging" class="nav-link nav-reports_ap_aging tree-item">
                                            <i class="nav-icon fas fa-hourglass-half"></i>
                                            <p>AP Aging</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/inventory_valuation" class="nav-link nav-reports_inventory_valuation tree-item">
                                            <i class="nav-icon fas fa-warehouse"></i>
                                            <p>Inventory Valuation</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/reorder_report" class="nav-link nav-reports_reorder_report tree-item">
                                            <i class="nav-icon fas fa-dolly-flatbed"></i>
                                            <p>Reorder Report</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/payroll_accounting" class="nav-link nav-reports_payroll_accounting tree-item">
                                            <i class="nav-icon fas fa-calculator"></i>
                                            <p>Payroll Accounting</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
