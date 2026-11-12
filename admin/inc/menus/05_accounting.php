<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 5. Accounting -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-university"></i>
                                    <p>
                                        Accounting
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=accounts" class="nav-link nav-accounts tree-item">
                                            <i class="nav-icon fas fa-table"></i>
                                            <p>Chart of Accounts</p>
                                        </a>
                                    </li>
                                    <?php if ($_settings->can_access('settings_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=accounts/default_accounts" class="nav-link nav-accounts_default_accounts tree-item">
                                            <i class="nav-icon fas fa-link"></i>
                                            <p>Default Accounts</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=assets" class="nav-link nav-assets tree-item">
                                            <i class="nav-icon fas fa-boxes"></i>
                                            <p>Fixed Assets</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=groups" class="nav-link nav-groups tree-item">
                                            <i class="nav-icon fas fa-th-list"></i>
                                            <p>Account Groups</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=journals" class="nav-link nav-journals tree-item">
                                            <i class="nav-icon fas fa-book"></i>
                                            <p>Manual Journals</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=journals/manage_opening_balances" class="nav-link nav-journals_manage_opening_balances tree-item">
                                            <i class="nav-icon fas fa-door-open"></i>
                                            <p>Opening Balances</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=journals/approvals" class="nav-link nav-journals_approvals tree-item">
                                            <i class="nav-icon fas fa-user-check"></i>
                                            <p>Journal Approvals</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=bank_reconciliation" class="nav-link nav-bank_reconciliation tree-item">
                                            <i class="nav-icon fas fa-university"></i>
                                            <p>Bank Reconciliation</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
