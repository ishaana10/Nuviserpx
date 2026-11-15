<?php
require_once('../config.php');
$token = (string)($_GET['token'] ?? '');
$valid = false;
if ($token !== '') {
    $hash = \App\Support\CustomerPortal::tokenHash($token);
    $st = $conn->prepare("SELECT id FROM `customer_portal_users` WHERE `invite_token` = ? AND `invite_expires_at` >= NOW() AND `status` = 1 LIMIT 1");
    if ($st) {
        $st->bind_param('s', $hash);
        $st->execute();
        $valid = (bool)$st->get_result()->fetch_assoc();
    }
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
  <script>start_loader()</script>
  <div class="login-box" style="width: 420px; max-width: 92%;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center border-0 pt-4">
        <img src="<?php echo htmlspecialchars(\App\Support\PortalBranding::logoUrl($conn)) ?>" alt="Logo" style="width:76px;height:76px;object-fit:contain;background:#fff;padding:6px;border-radius:50%;border:1px solid #e2e8f0;box-shadow:0 8px 18px -8px rgba(15,23,42,.25)" class="mb-2">
        <h1 class="h3 font-weight-bold mb-1" style="color:#0f172a">Customer Portal</h1>
        <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
      </div>
      <div class="card-body">
      <?php if (!$valid): ?>
        <div class="alert alert-danger text-center">This reset link is invalid or has expired.</div>
        <p class="text-center mb-0"><a href="forgot_password.php">Request a new link</a></p>
      <?php else: ?>
        <p class="login-box-msg">Choose a new password</p>
        <form id="portal-reset-frm" action="" method="post" novalidate>
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token) ?>">
          <div class="input-group mb-1">
            <input type="password" class="form-control" name="password" placeholder="New password" autocomplete="new-password" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
          </div>
          <small class="form-text text-muted mb-3">At least 8 characters, with letters and numbers.</small>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password" required>
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Change password</button>
        </form>
        <p class="text-center mt-3 mb-0"><a href="login.php">Back to sign in</a></p>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
  <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo base_url ?>dist/js/adminlte.min.js"></script>
  <script>
    $(document).ready(function(){
      end_loader();
      $('#portal-reset-frm').submit(function(e){
        e.preventDefault();
        var f = $(this);
        if (f.find('[name=password]').val() !== f.find('[name=confirm_password]').val()) {
          alert_toast("The two passwords do not match", 'warning');
          return;
        }
        start_loader();
        $.ajax({
          url: _base_url_ + 'classes/Master.php?f=customer_reset_password',
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
