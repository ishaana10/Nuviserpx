<?php
// Users register an email address; new accounts get a welcome email with a
// verification link, the state is visible, and changed addresses re-verify.
$root = dirname(__DIR__);
$us = file_get_contents($root . '/classes/Users.php');
$em = file_get_contents($root . '/classes/Emailer.php');
$ve = file_get_contents($root . '/verify_email.php');
$ac = file_get_contents($root . '/admin/user/index.php');
$mu = file_get_contents($root . '/admin/user/manage_user.php');
$ul = file_get_contents($root . '/admin/user/list.php');
$mig = @file_get_contents($root . '/database/migrations/038_user_email_verification.sql');
$lc = file_get_contents($root . '/src/Database/LegacySchemaCatchup.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// Schema
check('migration adds email + verification columns', $mig && substr_count($mig, 'ADD COLUMN IF NOT EXISTS') === 4 && strpos($mig, '`email_verified_at`') !== false);
check('runtime catch-up covers the same columns', strpos($lc, "'email_verify_expires'") !== false);

// Save flow
check('a new user must register a valid email', strpos($us, 'return 5; // a new user must register an email address') !== false && strpos($us, 'FILTER_VALIDATE_EMAIL') !== false);
check('email addresses are unique across users', strpos($us, 'return 6; // email already registered to another user') !== false);
check('a changed address is re-verified: token stored hashed with expiry', strpos($us, "hash('sha256', \$verify_token)") !== false && strpos($us, 'DATE_ADD(NOW(), INTERVAL 48 HOUR)') !== false && strpos($us, '`email_verified_at` = NULL') !== false);
check('token is random and never stored in clear', strpos($us, 'random_bytes(32)') !== false && !preg_match('/email_verify_token`?\s*=\s*\'\{?\$verify_token/', $us));
check('welcome/verification email is sent after a successful save', strpos($us, 'send_email_verification($email') !== false && strpos($us, "verify_email.php?token=") !== false);
check('a send failure is reported, not swallowed silently', strpos($us, 'could not be sent') !== false);
check('resend endpoint exists with permission + csrf checks', strpos($us, 'function resend_verification') !== false && preg_match('/function resend_verification\(\)\{\s*\$this->settings->require_login\(\);\s*\$this->settings->require_csrf\(\);/', $us) && strpos($us, "case 'resend_verification':") !== false);

// Emailer
check('Emailer has the verification template with welcome wording', strpos($em, 'function send_email_verification') !== false && strpos($em, 'An account has been created for you') !== false && strpos($em, 'valid for 48 hours') !== false);

// Public endpoint
check('verify_email.php validates token format and uses prepared statements', strpos($ve, "preg_match('/^[a-f0-9]{64}\$/'") !== false && strpos($ve, '$conn->prepare') !== false);
check('verify_email.php enforces expiry and clears the token', strpos($ve, "'expired'") !== false && strpos($ve, '`email_verify_token` = NULL') !== false);
check('verification is audited', strpos($ve, "log_audit('Email Verified'") !== false);

// Visibility
check('My Account shows the email with its verification state', strpos($ac, 'name="email"') !== false && strpos($ac, 'email-verify-state') !== false && strpos($ac, 'Not verified') !== false);
check('My Account offers resend when unverified', strpos($ac, 'resend-verification') !== false && strpos($ac, 'f=resend_verification') !== false);
check('create-user form requires the email address', preg_match('/name="email".*required/', $mu) === 1);
check('user list shows the email verification badge', strpos($ul, 'Email verified') !== false && strpos($ul, 'Email not verified') !== false);
check('duplicate/invalid email errors are explained in both forms', strpos($ac, "code == '6'") !== false && strpos($mu, "code == '6'") !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
