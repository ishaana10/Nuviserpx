<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 10. Developer Tools (Admin / Developer only) -->
                            <?php if($_settings->userdata('type') == 1): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-code text-warning"></i>
                                    <p>
                                        Developer
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/dashboard" class="nav-link nav-developer_dashboard tree-item">
                                            <i class="nav-icon fas fa-tachometer-alt text-info"></i>
                                            <p>Developer Dashboard</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/customer_plans" class="nav-link nav-developer_customer_plans tree-item">
                                            <i class="nav-icon fas fa-layer-group text-primary"></i>
                                            <p>Customer Plans</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/customer_requests" class="nav-link nav-developer_customer_requests tree-item">
                                            <i class="nav-icon fas fa-inbox text-warning"></i>
                                            <p>Customer Requests</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/portal_preview" class="nav-link nav-developer_portal_preview tree-item">
                                            <i class="nav-icon fas fa-desktop text-success"></i>
                                            <p>Customer Portal Inspector</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/vms_inspector" class="nav-link nav-developer_vms_inspector tree-item">
                                            <i class="nav-icon fas fa-microchip text-warning"></i>
                                            <p>VMS v3.0 SDC Inspector</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/git_update" class="nav-link nav-developer_git_update tree-item">
                                            <i class="nav-icon fas fa-code-branch text-info"></i>
                                            <p>Git Update Module</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/error_logs" class="nav-link nav-developer_error_logs tree-item">
                                            <i class="nav-icon fas fa-bug text-danger"></i>
                                            <p>Error Logs</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>
