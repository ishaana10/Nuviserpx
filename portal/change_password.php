<?php
require_once('../config.php');
?>
<!DOCTYPE html>
<html lang="en" class="" style="height: auto;">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition login-page bg-dark">
  <style>
    /* The dark login-page theme paints text white; these cards are white, so keep their text readable. */
    .login-page .card-body, .login-page .card-body p, .login-page .login-box-msg { color: #334155; }
    .login-page .card-body a:not(.btn) { color: #2563eb; }
    .login-page .card-body .alert-danger { color: #991b1b; }
    .login-page .card-body .alert-success { color: #166534; }
    .login-page .card-body .alert-info { color: #075985; }
    .login-page .card-body .alert-warning { color: #854d0e; }
  </style>
  <script>start_loader()</script>
  <div class="login-box" style="width: 420px; max-width: 92%;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center border-0 pt-4">
        <img src="<?php echo htmlspecialchars(\App\Support\PortalBranding::logoUrl($conn)) ?>" alt="Logo" style="width:76px;height:76px;object-fit:contain;background:#fff;padding:6px;border-radius:50%;border:1px solid #e2e8f0;box-shadow:0 8px 18px -8px rgba(15,23,42,.25)" class="mb-2">
        <h1 class="h3 font-weight-bold mb-1" style="color:#0f172a">Customer Portal</h1>
        <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
      </div>
      <div class="card-body">
        <p class="login-box-msg">Change your portal password</p>
        <form id="portal-pw-frm" action="" method="post" novalidate>
          <div class="input-group mb-3">
            <input type="email" class="form-control" name="email" placeholder="Email" autocomplete="username" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
          </div>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="current" placeholder="Current password" autocomplete="current-password" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
          </div>
          <div class="input-group mb-1">
            <input type="password" class="form-control" name="password" placeholder="New password" autocomplete="new-password" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-key"></span></div></div>
          </div>
          <small class="form-text text-muted mb-3">At least 8 characters, with letters and numbers.</small>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-key"></span></div></div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Change password</button>
        </form>
        <p class="text-center mt-3 mb-0"><a href="login.php"><i class="fas fa-arrow-left mr-1"></i> Back to sign in</a></p>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
  <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo base_url ?>dist/js/adminlte.min.js"></script>
  <script>
    $(document).ready(function(){
      end_loader();
      $('#portal-pw-frm').submit(function(e){
        e.preventDefault();
        var f = $(this);
        if (f.find('[name=password]').val() !== f.find('[name=confirm_password]').val()) {
          alert_toast("The two new passwords do not match", 'warning');
          return;
        }
        start_loader();
        $.ajax({
          url: _base_url_ + 'classes/Master.php?f=customer_change_password',
          method: 'POST',
          data: f.serialize(),
          dataType: 'json',
          error: function(){ alert_toast("An error occurred", 'error'); end_loader(); },
          success: function(resp){
            end_loader();
            if (resp.status == 'success') {
              alert_toast(resp.msg, 'success');
              setTimeout(function(){ location.href = 'login.php'; }, 1800);
            } else {
              alert_toast(resp.msg || "Could not change the password", 'danger');
            }
          }
        });
      });
    });
  </script>
</body>
</html>
