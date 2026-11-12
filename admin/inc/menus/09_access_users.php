<?php /* Sidebar section: included by navigation.php; $_settings, $conn and the $show_* flags are in scope. */ ?>
                            <!-- 8b. Access & Users (super admin or delegated permission) -->
                            <?php if($_settings->is_super_admin() || $_settings->can_access('users_manage') || $_settings->can_access('api_keys_manage')): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-users-cog"></i>
                                    <p>
                                        Access & Users
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <?php if($_settings->can_access('users_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/list" class="nav-link nav-user_list tree-item">
                                            <i class="nav-icon fas fa-users"></i>
                                            <p>Users &amp; Staff</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/manage_user" class="nav-link nav-user_manage_user tree-item">
                                            <i class="nav-icon fas fa-user-plus"></i>
                                            <p>Add New User</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if($_settings->is_super_admin()): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=roles" class="nav-link nav-roles tree-item">
                                            <i class="nav-icon fas fa-user-shield"></i>
                                            <p>User Roles &amp; Access <small class="badge badge-navy ml-1">Dev</small></p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/permissions" class="nav-link nav-user_permissions tree-item">
                                            <i class="nav-icon fas fa-table"></i>
                                            <p>Permissions Matrix <small class="badge badge-navy ml-1">Dev</small></p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if($_settings->can_access('api_keys_manage')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=api_keys" class="nav-link nav-api_keys tree-item">
                                            <i class="nav-icon fas fa-plug"></i>
                                            <p>API Keys</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php endif; ?>
