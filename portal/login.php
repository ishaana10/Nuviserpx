<?php
require_once('../config.php');
// Invitations sent before the link was corrected pointed here; hand them on to the activation page.
if (!empty($_GET['token']) && is_string($_GET['token'])) {
    header('Location: register.php?token=' . rawurlencode($_GET['token']), true, 302);
    exit;
}
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
  <script>
    start_loader()
  </script>
  <div class="login-box" style="width: 400px; max-width: 92%;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center border-0 pt-4">
        <img src="<?php echo htmlspecialchars(\App\Support\PortalBranding::logoUrl($conn)) ?>" alt="Logo" style="width:76px;height:76px;object-fit:contain;background:#fff;padding:6px;border-radius:50%;border:1px solid #e2e8f0;box-shadow:0 8px 18px -8px rgba(15,23,42,.25)" class="mb-2">
        <h1 class="h3 font-weight-bold mb-1" style="color:#0f172a">Customer Portal</h1>
        <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
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
        <p class="text-center mt-3 mb-0"><a href="change_password.php"><i class="fas fa-key mr-1"></i> Change password</a></p>
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
