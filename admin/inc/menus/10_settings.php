<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 9. Settings (Admin only) -->
                            <?php if($_settings->userdata('type') == 1): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-cog"></i>
                                    <p>
                                        Settings
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=templates/manage_template" class="nav-link nav-templates_manage_template tree-item">
                                            <i class="fas fa-file-invoice nav-icon"></i>
                                            <p>Invoice Templates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/financial_settings" class="nav-link nav-settings_financial_settings tree-item">
                                            <i class="nav-icon fas fa-calculator"></i>
                                            <p>Financial Settings</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/security" class="nav-link nav-settings_security tree-item">
                                            <i class="nav-icon fas fa-shield-alt"></i>
                                            <p>Security & 2FA Policy</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/data_import" class="nav-link nav-settings_data_import tree-item">
                                            <i class="nav-icon fas fa-file-import"></i>
                                            <p>Import Data (CSV)</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/currencies" class="nav-link nav-settings_currencies tree-item">
                                            <i class="nav-icon fas fa-coins"></i>
                                            <p>Currencies & Rates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/exchange_rates" class="nav-link nav-settings_exchange_rates tree-item">
                                            <i class="nav-icon fas fa-exchange-alt"></i>
                                            <p>Exchange Rates & Revaluation</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/vms_settings" class="nav-link nav-settings_vms_settings tree-item">
                                            <i class="nav-icon fas fa-microchip"></i>
                                            <p>FRCS VMS v3.0 Config</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=system_info" class="nav-link nav-system_info tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>System & Branding</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=system_info/modules" class="nav-link nav-system_info_modules tree-item">
                                            <i class="nav-icon fas fa-th-large"></i>
                                            <p>Modules Manager</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/extrafields" class="nav-link nav-settings_extrafields tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Custom Extrafields</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/email_templates" class="nav-link nav-settings_email_templates tree-item">
                                            <i class="nav-icon fas fa-envelope-open-text"></i>
                                            <p>Email Templates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/audit_logs" class="nav-link nav-settings_audit_logs tree-item">
                                            <i class="nav-icon fas fa-history"></i>
                                            <p>Audit Trail Logs</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/active_sessions" class="nav-link nav-settings_active_sessions tree-item">
                                            <i class="nav-icon fas fa-users-cog"></i>
                                            <p>Active User Sessions</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/backup" class="nav-link nav-settings_backup tree-item">
                                            <i class="nav-icon fas fa-database"></i>
                                            <p>DB Backup & Storage</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tenants" class="nav-link nav-tenants tree-item">
                                            <i class="nav-icon fas fa-building"></i>
                                            <p>Tenants / Companies</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=webhooks" class="nav-link nav-webhooks tree-item">
                                            <i class="nav-icon fas fa-share-square"></i>
                                            <p>Webhooks</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=workflows" class="nav-link nav-workflows tree-item">
                                            <i class="nav-icon fas fa-bolt"></i>
                                            <p>Automation Workflows</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>
