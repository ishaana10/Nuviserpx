<?php
require_once('../config.php');
use App\Services\EmployeePortalService;

if (isset($_SESSION['employee_portal'])) {
    header('Location: ' . base_url . 'portal/employee.php');
    exit;
}
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$invitation = null;
$invalid = false;
if ($token !== '') {
    $invitation = (new EmployeePortalService($conn, $_settings))->invitation($token);
    $invalid = $invitation === null;
}
?>
<!DOCTYPE html>
<html lang="en" class="" style="height: auto;">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition login-page bg-dark">
  <script>start_loader()</script>
  <div class="login-box" style="width: 400px;">
    <div class="card card-outline card-primary shadow">
      <div class="card-header text-center">
        <a href="./employee_login.php" class="h1"><b>Employee Portal</b></a>
        <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
      </div>
      <div class="card-body">
        <?php if ($invitation): ?>
        <p class="login-box-msg">Welcome, <?php echo htmlspecialchars($invitation['name']) ?>. Choose a password to activate your account.</p>
        <form id="activate-frm" action="" method="post">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token) ?>">
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="password" placeholder="New password (8+ characters, letters and numbers)" autocomplete="new-password" required>
          </div>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="confirm" placeholder="Confirm password" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Activate and sign in</button>
        </form>
        <?php else: ?>
        <?php if ($invalid): ?>
        <div class="alert alert-warning py-2 text-sm">This invitation link is invalid, already used or has expired. Ask HR to send a new one, or sign in below if your account is already active.</div>
        <?php endif; ?>
        <p class="login-box-msg">Sign in to view your payslips</p>
        <form id="emp-login-frm" action="" method="post">
          <div class="input-group mb-3">
            <input type="email" class="form-control" name="email" placeholder="Email" autocomplete="username" required>
          </div>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="password" placeholder="Password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Sign In</button>
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
      end_loader();
      function wire(sel, fn){
        $(sel).submit(function(e){
          e.preventDefault();
          start_loader();
          $.ajax({
            url: _base_url_ + 'classes/Master.php?f=' + fn, method: 'POST', data: $(this).serialize(), dataType: 'json',
            error: function(){ alert_toast("An error occurred", 'error'); end_loader(); },
            success: function(resp){
              if(resp.status == 'success'){ location.href = _base_url_ + 'portal/employee.php'; }
              else { alert_toast(resp.msg || "Could not sign in", 'error'); end_loader(); }
            }
          });
        });
      }
      wire('#emp-login-frm', 'employee_portal_login');
      wire('#activate-frm', 'employee_portal_activate');
    });
  </script>
</body>
</html>
