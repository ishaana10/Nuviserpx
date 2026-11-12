<?php require_once('../config.php') ?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('inc/header.php') ?>
<body class="hold-transition login-page">
  <script>start_loader()</script>
  <style>
    body {
      background: linear-gradient(160deg, #f8fafc 0%, #eef4ff 55%, #e5efff 100%) !important;
      font-family: 'Inter', -apple-system, sans-serif !important;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
      position: relative;
    }
    /* soft blue glows behind the card */
    body::before, body::after {
      content: ''; position: fixed; border-radius: 50%; filter: blur(90px); opacity: .35; pointer-events: none;
    }
    body::before { width: 420px; height: 420px; background: #bfdbfe; top: -120px; right: -100px; }
    body::after { width: 360px; height: 360px; background: #c7d2fe; bottom: -120px; left: -80px; }
    .login-box-custom {
      width: 430px;
      max-width: 92%;
      position: relative;
      z-index: 1;
    }
    .login-card-custom {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 1rem;
      box-shadow: 0 20px 45px -18px rgba(15, 23, 42, 0.18), 0 2px 8px rgba(15, 23, 42, 0.05);
      overflow: hidden;
      color: #0f172a;
    }
    .brand-logo-custom {
      width: 76px;
      height: 76px;
      object-fit: contain;
      background: #ffffff;
      padding: 6px;
      border-radius: 50%;
      border: 1px solid #e2e8f0;
      box-shadow: 0 8px 18px -8px rgba(15, 23, 42, 0.25);
    }
    .view-title { color: #0f172a; }
    .form-control-custom {
      background: #f8fafc !important;
      border: 1px solid #e2e8f0 !important;
      color: #0f172a !important;
      border-radius: 0.5rem !important;
      padding: 0.72rem 1rem !important;
      height: auto !important;
    }
    .form-control-custom::placeholder { color: #94a3b8; }
    .form-control-custom:focus {
      background: #ffffff !important;
      border-color: #3b82f6 !important;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
    }
    .btn-eye {
      border: 1px solid #e2e8f0 !important;
      border-left: 0 !important;
      background: #f8fafc !important;
      color: #94a3b8 !important;
      border-radius: 0 .5rem .5rem 0 !important;
    }
    .btn-eye:hover { color: #2563eb !important; }
    .input-group .form-control-custom.has-eye { border-radius: .5rem 0 0 .5rem !important; border-right: 0 !important; }
    .btn-custom {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
      border: none !important;
      border-radius: 0.5rem !important;
      padding: 0.75rem !important;
      font-weight: 600;
      letter-spacing: 0.5px;
      transition: all 0.2s ease;
    }
    .btn-custom:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.35) !important;
    }
    .text-muted-custom {
      color: #64748b !important;
    }
    .link-custom {
      color: #2563eb !important;
      text-decoration: none;
      transition: color 0.2s;
    }
    .link-custom:hover {
      color: #1d4ed8 !important;
      text-decoration: underline;
    }
    .nv-login-version {
      position: fixed;
      right: 14px;
      bottom: 10px;
      z-index: 1;
      font-size: .72rem;
      color: #94a3b8;
      font-family: ui-monospace, 'Cascadia Code', Consolas, monospace;
    }
  </style>

  <div class="login-box-custom">
    <div class="login-card-custom p-4">
      <div class="text-center mb-4">
        <img src="<?= validate_image($_settings->info('logo')) ?>" alt="Logo" class="brand-logo-custom mb-3">
        <h3 class="font-weight-bold mb-1 view-title"><?php echo $_settings->info('name') ?></h3>
        <p class="text-muted-custom small mb-0">Enterprise Resource Planning Portal</p>
      </div>

      <!-- Login View -->
      <div id="login-view">
        <form id="login-frm" action="" method="post">
          <div class="form-group mb-3">
            <label class="small text-muted-custom mb-1">Username or Email</label>
            <div class="input-group">
              <input type="text" class="form-control form-control-custom" autofocus name="username" placeholder="Enter your username" required>
            </div>
          </div>
          <div class="form-group mb-3">
            <label class="small text-muted-custom mb-1">Password</label>
            <div class="input-group">
              <input type="password" class="form-control form-control-custom has-eye" name="password" placeholder="Enter your password" required>
              <div class="input-group-append">
                <button type="button" class="btn btn-eye" tabindex="-1" aria-label="Show password"><i class="far fa-eye"></i></button>
              </div>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-4">
            <small><a href="javascript:void(0)" id="btn-forgot-toggle" class="link-custom"><i class="fas fa-key mr-1"></i> Forgot Password?</a></small>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-custom text-white mb-3">Sign In to Dashboard</button>
        </form>
      </div>

      <!-- Forgot Password View -->
      <div id="forgot-view" style="display: none;">
        <h5 class="view-title font-weight-bold mb-2">Reset Password</h5>
        <p class="text-muted-custom small mb-3">Enter your registered email address or username to receive a 6-digit OTP verification code.</p>
        <form id="forgot-frm" action="" method="post">
          <div class="form-group mb-3">
            <label class="small text-muted-custom mb-1">Username / Email</label>
            <input type="text" class="form-control form-control-custom" name="email" placeholder="e.g. admin@nuvis.com" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-custom text-white mb-3">Send OTP Code</button>
          <div class="text-center">
            <small><a href="javascript:void(0)" class="btn-login-toggle link-custom"><i class="fas fa-arrow-left mr-1"></i> Back to Sign In</a></small>
          </div>
        </form>
      </div>

      <!-- OTP Reset Password View -->
      <div id="otp-view" style="display: none;">
        <h5 class="view-title font-weight-bold mb-2">Enter OTP & New Password</h5>
        <p class="text-muted-custom small mb-3">Check your inbox for the 6-digit OTP code.</p>
        <form id="otp-frm" action="" method="post">
          <input type="hidden" name="email" id="otp_email" value="">
          <div class="form-group mb-3">
            <label class="small text-muted-custom mb-1">6-Digit OTP Code</label>
            <input type="text" class="form-control form-control-custom text-center font-weight-bold letter-spacing-2" name="otp_code" maxlength="6" placeholder="000000" required>
          </div>
          <div class="form-group mb-3">
            <label class="small text-muted-custom mb-1">New Password</label>
            <input type="password" class="form-control form-control-custom" name="password" placeholder="New password (8+ characters)" minlength="8" maxlength="72" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-custom text-white mb-3">Reset Password</button>
          <div class="text-center">
            <small><a href="javascript:void(0)" class="btn-login-toggle link-custom"><i class="fas fa-arrow-left mr-1"></i> Back to Sign In</a></small>
          </div>
        </form>
      </div>

      <div class="text-center border-top pt-3 mt-3">
        <small class="text-muted-custom">&copy; <?php echo date('Y') ?> Nuvis ERPX &bull; A Property of <a href="https://nuvistechnologies.com.fj/ERPX" target="_blank" class="link-custom">Nuvis Technologies</a></small>
      </div>

    </div>
  </div>

  <div class="nv-login-version" title="<?= htmlspecialchars(\App\Support\AppVersion::get('full')) ?>">
    v<?= htmlspecialchars(\App\Support\AppVersion::get('version')) ?> &middot; build <?= htmlspecialchars(\App\Support\AppVersion::get('build')) ?>
  </div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
  $(document).ready(function(){
    end_loader();

    $('.btn-eye').click(function(){
      var inp = $(this).closest('.input-group').find('input');
      var show = inp.attr('type') === 'password';
      inp.attr('type', show ? 'text' : 'password');
      $(this).attr('aria-label', show ? 'Hide password' : 'Show password')
             .find('i').attr('class', show ? 'far fa-eye-slash' : 'far fa-eye');
    });

    $('#btn-forgot-toggle').click(function(){
      $('#login-view').slideUp();
      $('#forgot-view').slideDown();
    });

    $('.btn-login-toggle').click(function(){
      $('#forgot-view, #otp-view').slideUp();
      $('#login-view').slideDown();
    });

    $('#login-frm').submit(function(e){
      e.preventDefault();
      start_loader();
      $.ajax({
        url:_base_url_+'classes/Login.php?f=login',
        method:'POST',
        data:$(this).serialize(),
        dataType:'json',
        error:err=>{
          console.log(err);
          alert_toast("An error occurred",'error');
          end_loader();
        },
        success:function(resp){
          if(resp.status == 'success'){
            location.href = _base_url_ + 'admin/';
          }else if(resp.status == 'locked'){
            alert_toast(resp.msg || "Too many failed attempts. Try again later.",'error');
            end_loader();
          }else if(resp.status == 'incorrect'){
            alert_toast("Incorrect username or password.",'error');
            end_loader();
          }else{
            alert_toast("An error occurred",'error');
            end_loader();
          }
        }
      });
    });

    $('#forgot-frm').submit(function(e){
      e.preventDefault();
      start_loader();
      $.ajax({
        url:_base_url_+'classes/Login.php?f=request_otp',
        method:'POST',
        data:$(this).serialize(),
        dataType:'json',
        error:err=>{
          console.log(err);
          alert_toast("An error occurred",'error');
          end_loader();
        },
        success:function(resp){
          end_loader();
          if(resp.status == 'success'){
            alert_toast(resp.msg, 'success');
            $('#otp_email').val($('#forgot-frm [name=email]').val());
            $('#forgot-view').slideUp();
            $('#otp-view').slideDown();
          }else{
            alert_toast(resp.msg, 'error');
          }
        }
      });
    });

    $('#otp-frm').submit(function(e){
      e.preventDefault();
      start_loader();
      $.ajax({
        url:_base_url_+'classes/Login.php?f=reset_password_otp',
        method:'POST',
        data:$(this).serialize(),
        dataType:'json',
        error:err=>{
          console.log(err);
          alert_toast("An error occurred",'error');
          end_loader();
        },
        success:function(resp){
          end_loader();
          if(resp.status == 'success'){
            alert_toast(resp.msg, 'success');
            $('#otp-view').slideUp();
            $('#login-view').slideDown();
          }else{
            alert_toast(resp.msg, 'error');
          }
        }
      });
    });
  });
</script>
</body>
</html>
