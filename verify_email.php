<?php
// Public email-verification endpoint: the link from the verification email
// lands here. Looks the token up by its hash, marks the address verified.
require_once(__DIR__ . '/config.php');

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$state = 'invalid';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT id, email, email_verified_at, email_verify_expires FROM `users` WHERE `email_verify_token` = ?");
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if ($u) {
            if (!empty($u['email_verified_at'])) {
                $state = 'already';
            } elseif (strtotime((string)$u['email_verify_expires']) < time()) {
                $state = 'expired';
            } else {
                $up = $conn->prepare("UPDATE `users` SET `email_verified_at` = NOW(), `email_verify_token` = NULL, `email_verify_expires` = NULL WHERE id = ?");
                $up->bind_param('i', $u['id']);
                if ($up->execute()) {
                    $state = 'verified';
                    $_settings->log_audit('Email Verified', 'User Management', "User #{$u['id']} verified email {$u['email']}.");
                }
            }
        }
    }
}

$messages = [
    'verified' => ['check-circle', 'text-success', 'Email verified', 'Thank you — your email address is now verified. You can close this page and sign in.'],
    'already'  => ['check-circle', 'text-success', 'Already verified', 'This email address was already verified. You can close this page and sign in.'],
    'expired'  => ['clock',        'text-warning', 'Link expired',    'This verification link has expired. Sign in and use "Resend verification email" on My Account to get a fresh one.'],
    'invalid'  => ['times-circle', 'text-danger',  'Invalid link',    'This verification link is not valid. Check that the full URL from the email was used.'],
];
[$icon, $cls, $title, $text] = $messages[$state];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Verification | <?php echo htmlspecialchars($_settings->info('name') ?: 'Nuvis ERPX') ?></title>
    <link rel="stylesheet" href="<?php echo base_url ?>plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo base_url ?>dist/css/adminlte.min.css">
</head>
<body class="hold-transition" style="background:#f4f6f9;display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="card shadow-sm rounded-0 text-center" style="max-width:420px;width:100%;">
        <div class="card-body p-5">
            <i class="fas fa-<?php echo $icon ?> <?php echo $cls ?> mb-3" style="font-size:3rem;"></i>
            <h4 class="mb-3"><?php echo $title ?></h4>
            <p class="text-muted"><?php echo $text ?></p>
            <a href="<?php echo base_url ?>admin/login.php" class="btn btn-primary rounded-0 mt-2">Go to sign in</a>
        </div>
    </div>
</body>
</html>
