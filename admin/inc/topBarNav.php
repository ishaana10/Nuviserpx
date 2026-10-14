<style>
  .user-img{
        position: absolute;
        height: 27px;
        width: 27px;
        object-fit: cover;
        left: -7%;
        top: -12%;
  }
  .btn-rounded{
        border-radius: 50px;
  }
</style>
<!-- Navbar -->
      <nav class="main-header navbar navbar-expand navbar-light text-sm shadow-sm">
        <!-- Left navbar links -->
        <ul class="navbar-nav align-items-center">
          <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
          </li>

          <!-- Quick "Create New" (+) Dropdown Menu (Inspired by Xero / Nuvis ERP) -->
          <li class="nav-item dropdown mr-2">
            <a class="btn btn-primary btn-sm font-weight-bold dropdown-toggle shadow-sm d-flex align-items-center" href="#" id="createNewDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="border-radius: 6px; padding: 5px 12px;">
              <i class="fas fa-plus"></i> <span class="d-none d-md-inline ml-1">Create new</span>
            </a>
            <div class="dropdown-menu dropdown-menu-left shadow-lg border-0 rounded-lg p-2" aria-labelledby="createNewDropdown" style="min-width: 250px;">
              <div class="text-xs font-weight-bold text-uppercase text-muted px-3 py-1 mb-1">Create new</div>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=invoices/manage_invoice">
                <span><i class="fas fa-file-invoice text-primary mr-2" style="width: 16px;"></i> Invoice</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt I</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=invoices/manage_invoice&type=bill">
                <span><i class="fas fa-file-invoice-dollar text-danger mr-2" style="width: 16px;"></i> Bill</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt B</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=parties">
                <span><i class="fas fa-address-book text-info mr-2" style="width: 16px;"></i> Contact</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt C</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=sales_orders/manage_order">
                <span><i class="fas fa-file-signature text-warning mr-2" style="width: 16px;"></i> Quote</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt Q</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=sales_orders/manage_order&type=po">
                <span><i class="fas fa-shopping-bag text-secondary mr-2" style="width: 16px;"></i> Purchase order</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt O</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=journals/manage_journal">
                <span><i class="fas fa-book text-success mr-2" style="width: 16px;"></i> Manual journal</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt J</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=payments/manage_payment">
                <span><i class="fas fa-hand-holding-usd text-indigo mr-2" style="width: 16px;"></i> Spend / Receive money</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt S</kbd>
              </a>

              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 rounded" href="<?php echo base_url ?>admin/?page=journals/manage_journal">
                <span><i class="fas fa-exchange-alt text-teal mr-2" style="width: 16px;"></i> Transfer money</span>
                <kbd class="bg-light text-muted border text-xs px-2 py-0">Alt T</kbd>
              </a>
            </div>
          </li>

          <li class="nav-item d-none d-sm-inline-block">
            <a href="<?php echo base_url ?>" class="nav-link"><b><?php echo (!isMobileDevice()) ? $_settings->info('name'):$_settings->info('short_name'); ?> - Admin</b></a>
          </li>
        </ul>
        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto align-items-center">
          <!-- Tenant Switcher Dropdown -->
          <li class="nav-item mr-2">
            <div class="input-group input-group-sm">
              <div class="input-group-prepend">
                <span class="input-group-text bg-primary text-white border-0"><i class="fas fa-building mr-1"></i> Tenant:</span>
              </div>
              <select id="tenant-switcher" class="form-control form-control-sm border-primary" style="max-width: 220px;">
                <?php
                $active_tenant = $_settings->active_tenant_id();
                $tenants_qry = $conn->query("SELECT * FROM `tenants` WHERE delete_flag = 0 AND status = 1 ORDER BY name ASC");
                while($trow = $tenants_qry->fetch_assoc()):
                ?>
                <option value="<?php echo $trow['id'] ?>" <?php echo $active_tenant == $trow['id'] ? 'selected' : '' ?>><?php echo $trow['name'] ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          </li>
          <!-- <li class="nav-item">
            <a class="nav-link" data-widget="navbar-search" href="#" role="button">
            <i class="fas fa-search"></i>
            </a>
            <div class="navbar-search-block">
              <form class="form-inline">
                <div class="input-group input-group-sm">
                  <input class="form-control form-control-navbar" type="search" placeholder="Search" aria-label="Search">
                  <div class="input-group-append">
                    <button class="btn btn-navbar" type="submit">
                    <i class="fas fa-search"></i>
                    </button>
                    <button class="btn btn-navbar" type="button" data-widget="navbar-search">
                    <i class="fas fa-times"></i>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </li> -->
          <!-- Messages Dropdown Menu -->
          <li class="nav-item">
            <div class="btn-group nav-link">
                  <button type="button" class="btn btn-rounded badge badge-light dropdown-toggle dropdown-icon" data-toggle="dropdown">
                    <span><img src="<?php echo validate_image($_settings->userdata('avatar')) ?>" class="img-circle elevation-2 user-img" alt="User Image"></span>
                    <span class="ml-3"><?php echo ucwords($_settings->userdata('firstname').' '.$_settings->userdata('lastname')) ?></span>
                    <span class="sr-only">Toggle Dropdown</span>
                  </button>
                  <div class="dropdown-menu" role="menu">
                    <a class="dropdown-item" href="<?php echo base_url.'admin/?page=user' ?>"><span class="fa fa-user"></span> My Account</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo base_url.'/classes/Login.php?f=logout' ?>"><span class="fas fa-sign-out-alt"></span> Logout</a>
                  </div>
              </div>
          </li>
          <li class="nav-item">
            
          </li>
         <!--  <li class="nav-item">
            <a class="nav-link" data-widget="control-sidebar" data-slide="true" href="#" role="button">
            <i class="fas fa-th-large"></i>
            </a>
          </li> -->
        </ul>
      </nav>
      <!-- /.navbar -->
      <script>
        $(document).ready(function(){
          // Global Keyboard Shortcuts (Alt + Key) matching Create New dropdown
          $(document).on('keydown', function(e){
            if(e.altKey && !e.ctrlKey && !e.shiftKey){
              var key = e.key ? e.key.toLowerCase() : '';
              var baseUrl = _base_url_ + 'admin/?page=';
              switch(key){
                case 'i':
                  e.preventDefault();
                  window.location.href = baseUrl + 'invoices/manage_invoice';
                  break;
                case 'b':
                  e.preventDefault();
                  window.location.href = baseUrl + 'invoices/manage_invoice&type=bill';
                  break;
                case 'c':
                  e.preventDefault();
                  window.location.href = baseUrl + 'parties';
                  break;
                case 'q':
                  e.preventDefault();
                  window.location.href = baseUrl + 'sales_orders/manage_order';
                  break;
                case 'o':
                  e.preventDefault();
                  window.location.href = baseUrl + 'sales_orders/manage_order&type=po';
                  break;
                case 'j':
                  e.preventDefault();
                  window.location.href = baseUrl + 'journals/manage_journal';
                  break;
                case 's':
                case 'r':
                  e.preventDefault();
                  window.location.href = baseUrl + 'payments/manage_payment';
                  break;
                case 't':
                  e.preventDefault();
                  window.location.href = baseUrl + 'journals/manage_journal';
                  break;
              }
            }
          });

          $('#tenant-switcher').change(function(){
            var tenant_id = $(this).val();
            start_loader();
            $.ajax({
              url: _base_url_ + 'classes/SystemSettings.php?f=switch_tenant',
              method: 'POST',
              data: { tenant_id: tenant_id },
              dataType: 'json',
              success: function(resp){
                if(resp.status == 'success'){
                  location.reload();
                } else {
                  alert_toast('Failed to switch tenant.', 'error');
                  end_loader();
                }
              },
              error: function(err){
                console.log(err);
                alert_toast('An error occurred.', 'error');
                end_loader();
              }
            });
          });
        });
      </script>