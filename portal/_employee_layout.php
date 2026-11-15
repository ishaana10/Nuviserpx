<?php
/**
 * Shared shell for the Employee Portal pages: sign-in check, side menu, top bar.
 * A page does: require '_employee_layout.php'; emp_header('Title', 'home'); ...content...; emp_footer();
 * It sets $svc (EmployeePortalService), $leave (PortalLeaveService), $tenantId, $employeeId, $h() and $m().
 */
require_once(__DIR__ . '/../config.php');

use App\Services\EmployeePortalService;
use App\Services\PortalLeaveService;

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['employee_portal']);
    header('Location: ' . base_url . 'portal/employee_login.php');
    exit;
}
$__p = $_SESSION['employee_portal'] ?? null;
$svc = new EmployeePortalService($conn, $_settings);
// Signed in, and HR has not switched the account off since.
if (!is_array($__p) || empty($__p['employee_id']) || empty($__p['id']) || !$svc->stillActive((int)$__p['id'], (int)$__p['tenant_id'], (int)$__p['employee_id'])) {
    unset($_SESSION['employee_portal']);
    header('Location: ' . base_url . 'portal/employee_login.php');
    exit;
}
// Employee and company come from the session only, never from the request.
$tenantId = (int)$__p['tenant_id'];
$employeeId = (int)$__p['employee_id'];
$leave = new PortalLeaveService($conn, $tenantId, $employeeId);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$m = fn($v) => number_format((float)$v, 2);
$me = $leave->details() ?? [];
$company = '';
$cq = $conn->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
if ($cq) { $cq->bind_param('i', $tenantId); $cq->execute(); $company = (string)($cq->get_result()->fetch_assoc()['name'] ?? ''); }
if ($company === '') { $company = (string)$_settings->info('name'); }
$leaveOn = $leave->enabled();

function emp_header(string $title, string $active): void
{
    global $h, $company, $me, $leaveOn, $_settings;
    $first = (string)($me['firstname'] ?? '');
    $full = trim($first . ' ' . (string)($me['lastname'] ?? ''));
    $initials = strtoupper(substr($first, 0, 1) . substr((string)($me['lastname'] ?? ''), 0, 1));
    $menu = [['home', 'employee.php', 'fa-home', 'Home'], ['leave', 'employee_leave.php', 'fa-calendar-check', 'My Leave'],
        ['payslips', 'employee_payslips.php', 'fa-file-invoice-dollar', 'My Payslips'], ['details', 'employee_details.php', 'fa-id-card', 'My Details']];
    ?>
<!DOCTYPE html>
<html lang="en">
<?php require_once(__DIR__ . '/../admin/inc/header.php') ?>
<body class="pp hold-transition">
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/nuvis-portal.css?v=<?php echo $h(date('Ymd')) ?>">
<header class="pp-top d-print-none">
  <button class="pp-burger" type="button" id="ppBurger" aria-label="Menu"><i class="fa fa-bars"></i></button>
  <span class="pp-brand"><?php echo $h($company) ?></span>
  <span class="pp-user"><span class="pp-avatar"><?php echo $h($initials) ?></span>
    <span class="pp-who"><b><?php echo $h($full) ?></b><small><?php echo $h($me['designation'] ?? '') ?></small></span></span>
</header>
<nav class="pp-side d-print-none" aria-label="Employee portal">
<?php foreach ($menu as [$key, $file, $icon, $label]): if ($key === 'leave' && !$leaveOn) continue; ?>
  <a href="<?php echo $h($file) ?>" class="<?php echo $key === $active ? 'active' : '' ?>"><i class="fa <?php echo $h($icon) ?>"></i> <?php echo $h($label) ?></a>
<?php endforeach; ?>
  <a href="?action=logout" class="pp-signout"><i class="fa fa-sign-out-alt"></i> Sign out</a>
</nav>
<div class="pp-backdrop" id="ppBackdrop"></div>
<main class="pp-main">
<?php
}

function emp_footer(string $extraJs = ''): void
{
    ?>
</main>
<script>
  (function () {
    var b = document.body;
    document.getElementById('ppBurger').onclick = function () { b.classList.toggle('pp-open'); };
    document.getElementById('ppBackdrop').onclick = function () { b.classList.remove('pp-open'); };
  })();
</script>
<?php echo $extraJs; ?>
</body>
</html>
<?php
}
