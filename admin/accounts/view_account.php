<?php
/**
 * Account detail now lives in the general ledger (running balance, posted journals only).
 * Kept so old links keep working.
 */
$acc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<script>location.replace(<?= json_encode((defined('base_url') ? base_url : '../../') . 'admin/?page=reports/general_ledger' . ($acc_id ? '&account_id=' . $acc_id : '')) ?>);</script>
<p class="p-3">Opening the general ledger… <a href="./?page=reports/general_ledger<?= $acc_id ? '&account_id=' . $acc_id : '' ?>">Continue</a></p>
