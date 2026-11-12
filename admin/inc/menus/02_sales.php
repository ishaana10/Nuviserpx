<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 2. Sales -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-dollar-sign"></i>
                                    <p>
                                        Sales
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=invoices" class="nav-link nav-invoices tree-item">
                                            <i class="nav-icon fas fa-file-invoice"></i>
                                            <p>Sales Invoices</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payment_reminders" class="nav-link nav-payment_reminders tree-item">
                                            <i class="nav-icon fas fa-bell"></i>
                                            <p>Payment Reminders</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=credit_notes" class="nav-link nav-credit_notes tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Credit &amp; Debit Notes</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=sales_orders" class="nav-link nav-sales_orders tree-item">
                                            <i class="nav-icon fas fa-file-signature"></i>
                                            <p>Quotes & Orders</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=delivery_notes" class="nav-link nav-delivery_notes tree-item">
                                            <i class="nav-icon fas fa-truck"></i>
                                            <p>Delivery Notes</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=crm" class="nav-link nav-crm tree-item">
                                            <i class="nav-icon fas fa-handshake"></i>
                                            <p>CRM Opportunities</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payments" class="nav-link nav-payments tree-item">
                                            <i class="nav-icon fas fa-receipt"></i>
                                            <p>Payment Receipts</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=products" class="nav-link nav-products tree-item">
                                            <i class="nav-icon fas fa-boxes"></i>
                                            <p>Products & Services</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
