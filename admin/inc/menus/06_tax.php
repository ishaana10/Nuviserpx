<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 6. Tax -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-percentage"></i>
                                    <p>
                                        Tax
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tax/report" class="nav-link nav-tax_report tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Sales Tax Report</p>
                                        </a>
                                    </li>
                                    <?php if ($_settings->can_access('tax_file')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tax/returns" class="nav-link nav-tax_returns tree-item">
                                            <i class="nav-icon fas fa-stamp"></i>
                                            <p>Filed VAT Returns</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tax/rates" class="nav-link nav-tax_rates tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Tax Rates & Settings</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
