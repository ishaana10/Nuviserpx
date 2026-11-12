<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 8. Payroll & HR -->
                            <?php if($show_payroll): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-users"></i>
                                    <p>
                                        Payroll & HR
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=employees" class="nav-link nav-employees tree-item">
                                            <i class="nav-icon fas fa-user-tie"></i>
                                            <p>Employees</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=attendance" class="nav-link nav-attendance tree-item">
                                            <i class="nav-icon fas fa-user-clock"></i>
                                            <p>Attendance Log</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=attendance/shift_roster" class="nav-link nav-attendance_shift_roster tree-item">
                                            <i class="nav-icon fas fa-clock"></i>
                                            <p>Shift Rosters</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=leaves" class="nav-link nav-leaves tree-item">
                                            <i class="nav-icon fas fa-business-time"></i>
                                            <p>Leave Requests</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=leaves/balances" class="nav-link nav-leaves_balances tree-item">
                                            <i class="nav-icon fas fa-calendar-check"></i>
                                            <p>Leave Balances</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll" class="nav-link nav-payroll tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Payroll Processing</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/payrun_engine" class="nav-link nav-payroll_payrun_engine tree-item">
                                            <i class="nav-icon fas fa-cogs"></i>
                                            <p>Payrun Engine & Retro</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/approvals" class="nav-link nav-payroll_approvals tree-item">
                                            <i class="nav-icon fas fa-user-check text-warning"></i>
                                            <p>Payroll Approvals</p>
                                        </a>
                                    </li>
                                    <?php if ($_settings->can_access('payroll_pay')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/pay_salaries" class="nav-link nav-payroll_pay_salaries tree-item">
                                            <i class="nav-icon fas fa-money-check-alt text-success"></i>
                                            <p>Pay Salaries</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($_settings->can_access('payroll_remit')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/remittances" class="nav-link nav-payroll_remittances tree-item">
                                            <i class="nav-icon fas fa-hand-holding-usd text-success"></i>
                                            <p>Payroll Remittances</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($_settings->can_access('settings_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/rules_settings" class="nav-link nav-payroll_rules_settings tree-item">
                                            <i class="nav-icon fas fa-sliders-h text-primary"></i>
                                            <p>Payroll Rules Settings</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/tax_rules" class="nav-link nav-payroll_tax_rules tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>Payroll Tax Rules</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/salary_components" class="nav-link nav-payroll_salary_components tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Payhead Components</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/loans" class="nav-link nav-payroll_loans tree-item">
                                            <i class="nav-icon fas fa-hand-holding-usd"></i>
                                            <p>Staff Loans</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/fnpf_schedule" class="nav-link nav-payroll_fnpf_schedule tree-item">
                                            <i class="nav-icon fas fa-file-contract"></i>
                                            <p>FNPF CS3 Schedule</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/ems_export" class="nav-link nav-payroll_ems_export tree-item">
                                            <i class="nav-icon fas fa-file-csv"></i>
                                            <p>FRCS EMS Tax Export</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/sandbox" class="nav-link nav-payroll_sandbox tree-item">
                                            <i class="nav-icon fas fa-calculator text-primary"></i>
                                            <p>Payroll Test Sandbox</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=expense_reports" class="nav-link nav-expense_reports tree-item">
                                            <i class="nav-icon fas fa-receipt"></i>
                                            <p>Expense Claims</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=contracts" class="nav-link nav-contracts tree-item">
                                            <i class="nav-icon fas fa-file-contract"></i>
                                            <p>Contracts & Subscriptions</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tickets" class="nav-link nav-tickets tree-item">
                                            <i class="nav-icon fas fa-headset"></i>
                                            <p>Support Tickets</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>
