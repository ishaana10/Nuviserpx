<?php
require_once(__DIR__ . '/_employee_layout.php');
emp_header('Change Password', 'password');
?>
<h1 class="pp-h1">Change Password</h1>
<p class="pp-sub">Your username is <b><?php echo $h($__p['email'] ?? ($me['email'] ?? '')) ?></b>. Choose a password you don't use anywhere else.</p>
<div class="pp-card" style="max-width:520px"><div class="pp-card-b">
  <form id="pwFrm" novalidate>
    <div class="form-group"><label for="cur">Current password</label><input type="password" class="form-control" id="cur" name="current" autocomplete="current-password" required></div>
    <div class="form-group"><label for="npw">New password</label><input type="password" class="form-control" id="npw" name="password" autocomplete="new-password" required>
      <small class="form-text text-muted">At least 8 characters, with letters and numbers.</small></div>
    <div class="form-group"><label for="cpw">Confirm new password</label><input type="password" class="form-control" id="cpw" name="confirm" autocomplete="new-password" required></div>
    <button type="submit" class="btn btn-primary" id="pwBtn"><i class="fa fa-key"></i> Change password</button>
    <div class="mt-3" id="pwMsg" role="alert"></div>
  </form>
</div></div>
<?php emp_footer("<script>
\$('#pwFrm').on('submit', function(e){
  e.preventDefault();
  var \$b = \$('#pwBtn').prop('disabled', true), \$m = \$('#pwMsg').attr('class', 'mt-3').text('');
  if(\$('#npw').val() !== \$('#cpw').val()){ \$m.addClass('text-danger').text('The two new passwords do not match.'); \$b.prop('disabled', false); return; }
  \$.ajax({url: _base_url_ + 'classes/Master.php?f=employee_portal_change_password', method: 'POST', data: \$(this).serialize(), dataType: 'json',
    error: function(){ \$m.addClass('text-danger').text('Something went wrong. Please try again.'); \$b.prop('disabled', false); },
    success: function(r){
      if(r.status === 'success'){ \$m.addClass('text-success').text(r.msg); \$('#pwFrm')[0].reset(); }
      else { \$m.addClass('text-danger').text(r.msg || 'Could not change the password.'); }
      \$b.prop('disabled', false);
    }});
});
</script>"); ?>
