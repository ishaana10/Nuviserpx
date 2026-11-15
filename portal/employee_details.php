<?php
require_once(__DIR__ . '/_employee_layout.php');
emp_header('My Details', 'details');
$rows = [
    ['Employee code', $me['employee_code'] ?? ''], ['Name', trim(($me['firstname'] ?? '') . ' ' . ($me['lastname'] ?? ''))],
    ['Email', $me['email'] ?? ''], ['Phone', $me['phone'] ?? ''], ['Department', $me['department'] ?? ''],
    ['Position', $me['designation'] ?? ''],
    ['Date joined', !empty($me['date_of_joining']) && $me['date_of_joining'] !== '0000-00-00' ? date('j M Y', strtotime($me['date_of_joining'])) : ''],
    ['Leave approver', $me['approver_name'] ?? ''],
];
?>
<h1 class="pp-h1">My Details</h1>
<p class="pp-sub">These come from your employee record. To change anything, ask HR.</p>
<div class="pp-card"><div class="pp-card-b"><ul class="pp-list">
<?php foreach ($rows as [$k, $v]): ?><li><span><?php echo $h($k) ?></span><span class="text-right"><?php echo trim((string)$v) !== '' ? $h($v) : '&mdash;' ?></span></li><?php endforeach; ?>
</ul></div></div>
<?php emp_footer(); ?>
