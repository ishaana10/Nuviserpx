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
  <style>
    .ep-box { width: 400px; max-width: 94%; }
    .ep-card { background: #fff; color: #0f172a; border-radius: 1rem; box-shadow: 0 20px 45px -18px rgba(0,0,0,.45); padding: 1.6rem 1.5rem 1.2rem; }
    .ep-logo { width: 76px; height: 76px; object-fit: contain; background: #fff; padding: 6px; border-radius: 50%; border: 1px solid #e2e8f0; box-shadow: 0 8px 18px -8px rgba(15,23,42,.25); }
    .ep-title { color: #0f172a; font-weight: 700; font-size: 1.5rem; margin: .7rem 0 .1rem; }
    .ep-company { color: #64748b; font-size: .9rem; margin: 0; }
    .ep-card .form-control { min-height: 46px; background: #f8fafc; border-color: #e2e8f0; color: #0f172a; }
    .ep-card .btn-primary { min-height: 46px; font-weight: 600; }
    .ep-back { display: block; text-align: center; margin-top: 1rem; font-size: .9rem; color: #2563eb; }
    .ep-back:hover { text-decoration: underline; }
  </style>
  <script>start_loader()</script>
  <div class="login-box ep-box">
    <div class="ep-card">
      <div class="text-center mb-3">
        <img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="Logo" class="ep-logo">
        <h1 class="ep-title">Employee Portal</h1>
        <p class="ep-company"><?php echo htmlspecialchars((string)$_settings->info('name')) ?></p>
      </div>
      <div>
        <?php if ($invitation): ?>
        <p class="login-box-msg">Welcome, <?php echo htmlspecialchars($invitation['name']) ?>. Choose a password to activate your account.</p>
        <div class="alert alert-info py-2 text-sm">Your username for signing in is your email address:<br><strong><?php echo htmlspecialchars((string)$invitation['email']) ?></strong></div>
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
        <p class="text-muted text-sm text-center">Your username is the email address HR has on file for you.</p>
        <form id="emp-login-frm" action="" method="post">
          <div class="input-group mb-3">
            <input type="email" class="form-control" name="email" placeholder="Username (your email address)" autocomplete="username" required>
          </div>
          <div class="input-group mb-3">
            <input type="password" class="form-control" name="password" placeholder="Password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>
        <?php endif; ?>
      </div>
      <a class="ep-back" href="<?php echo base_url ?>admin/login.php">&larr; Back to main portal</a>
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
