<?php
// Customer portal against a real database: invitation, activation, login, session re-check.
require __DIR__ . '/_harness.php';
define('base_url', 'http://portal.test/');
class SystemSettings {
    public function active_tenant_id() { return 1; }
}
use App\Support\CustomerPortal as P;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'C1','Alpha Ltd','alpha@example.com','customer'),(2,1,'C2','O''Brien & Co','o\\'brien@example.com','customer'),(3,1,'C3','No Email','','customer')");
$svc = new \App\Services\CustomerPortalService($c, new SystemSettings());
$post = function (array $p) { $_POST = $p; };
$call = function (string $m, array $p) use ($svc, $post) { $post($p); return json_decode($svc->$m(), true); };
$row = fn($pid) => $c->query("SELECT * FROM customer_portal_users WHERE party_id=$pid")->fetch_assoc();

// --- invitation
$r = $call('send_customer_invitation', ['party_id' => 1]);
check('invitation succeeds', ($r['status'] ?? '') === 'success');
check('invite url points at register.php', strpos($r['invite_url'] ?? '', 'portal/register.php?token=') !== false);
$tok1 = substr($r['invite_url'], strpos($r['invite_url'], 'token=') + 6);
check('token is stored hashed, not plain', $row(1)['invite_token'] === hash('sha256', $tok1) && $row(1)['invite_token'] !== $tok1);
check('customer without email is refused', ($call('send_customer_invitation', ['party_id' => 3])['status'] ?? '') === 'failed');
$r2 = $call('send_customer_invitation', ['party_id' => 2]);
check('email with an apostrophe is stored safely (no SQL breakage)', ($r2['status'] ?? '') === 'success' && $row(2)['email'] === "o'brien@example.com");
$inj = $c->query("SELECT COUNT(*) FROM party_list")->fetch_row()[0];
check('party table intact', (int)$inj === 3);

// --- activation
check('weak password refused', ($call('customer_register', ['token' => $tok1, 'password' => 'short1', 'confirm_password' => 'short1'])['status'] ?? '') === 'failed');
check('mismatched confirmation refused', ($call('customer_register', ['token' => $tok1, 'password' => 'Passw0rd1', 'confirm_password' => 'Passw0rd2'])['status'] ?? '') === 'failed');
check('wrong token refused', ($call('customer_register', ['token' => 'nope', 'password' => 'Passw0rd1', 'confirm_password' => 'Passw0rd1'])['status'] ?? '') === 'failed');
check('user still inactive after failures', (int)$row(1)['status'] === 0);
$_SESSION = [];
$ok = $call('customer_register', ['token' => $tok1, 'password' => 'Passw0rd1', 'confirm_password' => 'Passw0rd1']);
check('activation succeeds', ($ok['status'] ?? '') === 'success');
check('user active, token cleared', (int)$row(1)['status'] === 1 && $row(1)['invite_token'] === null);
check('password stored as a bcrypt hash', password_verify('Passw0rd1', $row(1)['password']));
check('token cannot be reused', ($call('customer_register', ['token' => $tok1, 'password' => 'Hacker123x', 'confirm_password' => 'Hacker123x'])['status'] ?? '') === 'failed');
check('activation signs the customer in', (int)($_SESSION['customer_portal']['party_id'] ?? 0) === 1);

// legacy plain-text token (sent before hashing) still activates; expired does not
$c->query("UPDATE customer_portal_users SET invite_token='legacyplain123', invite_expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY), status=0 WHERE party_id=2");
check('legacy plain token accepted', ($call('customer_register', ['token' => 'legacyplain123', 'password' => 'Passw0rd1', 'confirm_password' => 'Passw0rd1'])['status'] ?? '') === 'success');
$call('send_customer_invitation', ['party_id' => 2]);
$c->query("UPDATE customer_portal_users SET invite_expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE party_id=2");
$expiredTok = 'expiredtok';
$c->query("UPDATE customer_portal_users SET invite_token='" . hash('sha256', $expiredTok) . "' WHERE party_id=2");
check('expired token refused', ($call('customer_register', ['token' => $expiredTok, 'password' => 'Passw0rd1', 'confirm_password' => 'Passw0rd1'])['status'] ?? '') === 'failed');

// --- login
$_SESSION = [];
check('login with right password', ($call('customer_login', ['email' => 'alpha@example.com', 'password' => 'Passw0rd1'])['status'] ?? '') === 'success');
check('login with wrong password', ($call('customer_login', ['email' => 'alpha@example.com', 'password' => 'wrong'])['status'] ?? '') === 'failed');

// --- session re-check
$_SESSION = ['customer_portal' => ['id' => (int)$row(1)['id'], 'tenant_id' => 1, 'party_id' => 1, 'email' => 'alpha@example.com']];
check('active customer has a context', (P::context($c)['party_id'] ?? 0) === 1);
$c->query("UPDATE customer_portal_users SET status=0 WHERE party_id=1");
check('revoked portal user loses access at once', P::context($c) === null);
$c->query("UPDATE customer_portal_users SET status=1 WHERE party_id=1");
$c->query("UPDATE party_list SET delete_flag=1 WHERE id=1");
check('deleted customer loses access at once', P::context($c) === null);
$c->query("UPDATE party_list SET delete_flag=0 WHERE id=1");
$_SESSION['customer_portal']['party_id'] = 2;
check('session cannot be pointed at another customer', P::context($c) === null);
$_SESSION = ['customer_id' => 99999, 'customer_client_id' => 1];
check('preview flag alone (no admin session) gives nothing', P::context($c) === null);
$_SESSION['userdata'] = ['type' => 1];
check('super admin preview works', (P::context($c)['preview'] ?? false) === true);
$_SESSION['userdata'] = ['type' => 2];
check('non-super admin preview refused', P::context($c) === null);
// --- forgot / reset password
$c->query("DELETE FROM auth_attempts");
$sendCap = $c->query("SELECT 1"); // (email sending itself is not exercised here; only the data effects)
$fp = fn(string $e) => $call('customer_forgot_password', ['email' => $e]);
$reply = $fp('alpha@example.com');
check('forgot: known email gets the generic reply', ($reply['status'] ?? '') === 'success' && ($reply['msg'] ?? '') === \App\Services\CustomerPortalService::FORGOT_REPLY);
check('forgot: unknown email gets the identical reply (no account enumeration)', $fp('ghost@example.com') === $reply);
check('forgot: malformed email is asked again', ($fp('not-an-email')['status'] ?? '') === 'failed');
$u = $row(1);
check('forgot: a hashed token with a ~1 hour expiry is stored', strlen((string)$u['invite_token']) === 64 && strtotime($u['invite_expires_at']) > time() + 3000 && strtotime($u['invite_expires_at']) <= time() + 3700);
check('forgot: an account that never activated gets no reset token', $fp("o'brien@example.com") === $reply && $row(2)['invite_token'] !== null /* its invitation token, untouched */ && (int)$row(2)['status'] === 0);
// Make a token we know (the real one only exists in the emailed link).
$tok = bin2hex(random_bytes(24));
$c->query("UPDATE customer_portal_users SET invite_token='" . hash('sha256', $tok) . "', invite_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=" . (int)$u['id']);
$rs = fn(array $p) => $call('customer_reset_password', array_merge(['token' => $tok, 'password' => 'Reset12345x', 'confirm_password' => 'Reset12345x'], $p));
check('reset: mismatch refused', ($rs(['confirm_password' => 'Different12'])['status'] ?? '') === 'failed');
check('reset: weak password refused', ($rs(['password' => 'short1', 'confirm_password' => 'short1'])['status'] ?? '') === 'failed');
check('reset: wrong token refused', ($rs(['token' => 'nope'])['status'] ?? '') === 'failed');
check('reset: nothing changed by refusals', password_verify('Passw0rd1', $row(1)['password']));
$_SESSION = [];
check('reset: succeeds with the emailed token', ($rs([])['status'] ?? '') === 'success');
check('reset: new password works, old one does not', password_verify('Reset12345x', $row(1)['password']) && !password_verify('Passw0rd1', $row(1)['password']));
check('reset: token is single-use', ($rs(['password' => 'Another3Pass', 'confirm_password' => 'Another3Pass'])['status'] ?? '') === 'failed' && $row(1)['invite_token'] === null);
check('reset: does not sign anyone in', empty($_SESSION['customer_portal']));
check('reset: signing in with the new password works', ($call('customer_login', ['email' => 'alpha@example.com', 'password' => 'Reset12345x'])['status'] ?? '') === 'success');
$c->query("UPDATE customer_portal_users SET invite_token='" . hash('sha256', $tok) . "', invite_expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=" . (int)$u['id']);
check('reset: an expired token is refused', ($rs(['password' => 'Another3Pass', 'confirm_password' => 'Another3Pass'])['status'] ?? '') === 'failed');
$c->query("UPDATE customer_portal_users SET invite_token='" . hash('sha256', $tok) . "', invite_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR), status=0 WHERE id=" . (int)$u['id']);
check('reset: a deactivated (re-invited) account cannot use a reset token', ($rs(['password' => 'Another3Pass', 'confirm_password' => 'Another3Pass'])['status'] ?? '') === 'failed');
check('forgot/reset are logged', (int)$c->query("SELECT COUNT(*) FROM customer_portal_logs WHERE action IN ('password_reset_requested','password_reset')")->fetch_row()[0] >= 2);
$c->query("DELETE FROM auth_attempts");
for ($i = 0; $i < 3; $i++) { $fp('alpha@example.com'); }
check('forgot: repeated requests for one email are rate limited', ($fp('alpha@example.com')['status'] ?? '') === 'locked');
for ($i = 0; $i < 6; $i++) { $rs(['token' => 'bad' . $i]); }
check('reset: repeated bad tokens are locked out', ($rs(['token' => 'bad9'])['status'] ?? '') === 'locked');
nv_done();
