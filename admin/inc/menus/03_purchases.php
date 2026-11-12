<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 3. Purchases -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-shopping-bag"></i>
                                    <p>
                                        Purchases
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=purchases" class="nav-link nav-purchases tree-item">
                                            <i class="nav-icon fas fa-shopping-cart"></i>
                                            <p>Purchase Orders</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=purchases/vendor_bills" class="nav-link nav-purchases_vendor_bills tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Vendor Bills (A/P)</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=purchases/bill_payments" class="nav-link nav-purchases_bill_payments tree-item">
                                            <i class="nav-icon fas fa-money-check-alt"></i>
                                            <p>Supplier Payments</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=stock_adjustments" class="nav-link nav-stock_adjustments tree-item">
                                            <i class="nav-icon fas fa-cubes"></i>
                                            <p>Stock Movements</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=purchases/wht_report" class="nav-link nav-purchases_wht_report tree-item">
                                            <i class="nav-icon fas fa-percent"></i>
                                            <p>Withholding Tax</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=batches" class="nav-link nav-batches tree-item">
                                            <i class="nav-icon fas fa-hourglass-half"></i>
                                            <p>Batches &amp; Expiry</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=stock_counts" class="nav-link nav-stock_counts tree-item">
                                            <i class="nav-icon fas fa-clipboard-check"></i>
                                            <p>Stock Take</p>
                                        </a>
                                    </li>
                                    <?php if ($_settings->can_access('settings_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=price_lists" class="nav-link nav-price_lists tree-item">
                                            <i class="nav-icon fas fa-tags"></i>
                                            <p>Price Lists</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($_settings->can_access('settings_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=stock_adjustments/accounting" class="nav-link nav-stock_adjustments_accounting tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>Inventory Accounting</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=warehouses" class="nav-link nav-warehouses tree-item">
                                            <i class="nav-icon fas fa-warehouse"></i>
                                            <p>Warehouses & Locations</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
