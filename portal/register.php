<?php
require_once('../config.php');
$token = $_GET['token'] ?? '';
$user = null;

if (!empty($token)) {
    $hash = \App\Support\CustomerPortal::tokenHash((string)$token);
    $st = $conn->prepare("SELECT u.*, p.name as party_name, p.email as party_email FROM `customer_portal_users` u JOIN `party_list` p ON u.party_id = p.id WHERE (u.invite_token = ? OR u.invite_token = ?) AND u.invite_expires_at >= NOW()");
    if ($st) {
        $tok = (string)$token;
        $st->bind_param('ss', $hash, $tok);
        $st->execute();
        $user = $st->get_result()->fetch_assoc() ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en" style="height: auto;">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition login-page bg-dark">
  <div class="login-box" style="width: 450px; max-width: 92%;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center border-0 pt-4">
        <img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="Logo" style="width:76px;height:76px;object-fit:contain;background:#fff;padding:6px;border-radius:50%;border:1px solid #e2e8f0;box-shadow:0 8px 18px -8px rgba(15,23,42,.25)" class="mb-2">
        <h1 class="h3 font-weight-bold mb-1" style="color:#0f172a">Customer Portal</h1>
        <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
      </div>
      <div class="card-body">
        <?php if (!$user): ?>
          <div class="alert alert-danger text-center">
            Invalid or expired invitation token. Please contact support or request a new invitation.
          </div>
          <p class="text-center mb-0"><a href="login.php">Back to Login</a></p>
        <?php else: ?>
          <p class="login-box-msg">Activate your account for <strong><?php echo htmlspecialchars($user['party_name']) ?></strong></p>
          <form id="portal-reg-frm" action="" method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token) ?>">

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Email Address</label>
              <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']) ?>" readonly>
            </div>

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Account</label>
              <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)$user['party_name']) ?>" readonly>
            </div>

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Password</label>
              <input type="password" class="form-control" name="password" placeholder="Set Password (8+ characters, letters and numbers)" required minlength="8">
            </div>

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Confirm Password</label>
              <input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required minlength="8">
            </div>

            <div class="row">
              <div class="col-12">
                <button type="submit" class="btn btn-primary btn-block">Activate Account</button>
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
  <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo base_url ?>dist/js/adminlte.min.js"></script>

  <script>
    $(document).ready(function(){
      $('#portal-reg-frm').submit(function(e){
        e.preventDefault();
        var pass = $('input[name="password"]').val();
        var confirm_pass = $('input[name="confirm_password"]').val();
        if(pass !== confirm_pass){
          alert_toast("Passwords do not match", 'warning');
          return false;
        }
        start_loader();
        $.ajax({
          url: _base_url_ + 'classes/Master.php?f=customer_register',
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
              alert_toast(resp.msg || "Registration failed", 'danger');
              end_loader();
            }
          }
        });
      });
    });
  </script>
</body>
</html>
