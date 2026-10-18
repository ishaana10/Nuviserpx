<?php require_once('../config.php'); ?>
<!DOCTYPE html>
<html lang="en" class="" style="height: auto;">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition login-page bg-dark">
  <script>
    start_loader()
  </script>
  <div class="login-box" style="width: 400px;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center">
        <a href="./" class="h1"><b>Customer Portal</b></a>
        <p class="text-muted text-sm mb-0"><?php echo $_settings->info('name') ?></p>
      </div>
      <div class="card-body">
        <p class="login-box-msg">Sign in to view your invoices and statements</p>
        <form id="portal-login-frm" action="" method="post">
          <div class="input-group mb-3">
            <input type="email" class="form-control" name="email" placeholder="Email" required>
            <div class="input-group-append">
              <div class="input-group-text">
                <span class="fas fa-envelope"></span>
              </div>
            </div>
          </div>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="password" placeholder="Password" required>
            <div class="input-group-append">
              <div class="input-group-text">
                <span class="fas fa-lock"></span>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-12">
              <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
  <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo base_url ?>dist/js/adminlte.min.js"></script>

  <script>
    $(document).ready(function(){
      end_loader();
      $('#portal-login-frm').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
          url: _base_url_ + 'classes/Master.php?f=customer_login',
          method: 'POST',
          data: $(this).serialize(),
          dataType: 'json',
          error: err => {
            console.log(err);
            alert_toast("An error occurred", 'error');
            end_loader();
          },
          success: function(resp){
            if(resp.status == 'success'){
              location.href = _base_url_ + 'portal/';
            } else {
              alert_toast(resp.msg || "Invalid credentials", 'danger');
              end_loader();
            }
          }
        });
      });
    });
  </script>
</body>
</html>
