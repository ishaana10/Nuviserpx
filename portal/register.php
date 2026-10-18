<?php
require_once('../config.php');
$token = $_GET['token'] ?? '';
$user = null;

if (!empty($token)) {
    $qry = $conn->query("SELECT u.*, p.name as party_name, p.email as party_email FROM `customer_portal_users` u JOIN `client_list` p ON u.client_id = p.id WHERE u.invitation_token = '{$conn->real_escape_string($token)}'");
    if ($qry->num_rows > 0) {
        $user = $qry->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en" style="height: auto;">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition login-page bg-dark">
  <div class="login-box" style="width: 450px;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center">
        <a href="./" class="h1"><b>Customer Portal</b></a>
        <p class="text-muted text-sm mb-0"><?php echo $_settings->info('name') ?></p>
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
              <label class="text-muted text-xs">Your Full Name</label>
              <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($user['name'] ?: $user['party_name']) ?>" required>
            </div>

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Password</label>
              <input type="password" class="form-control" name="password" placeholder="Set Password" required minlength="6">
            </div>

            <div class="form-group mb-3">
              <label class="text-muted text-xs">Confirm Password</label>
              <input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required minlength="6">
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
