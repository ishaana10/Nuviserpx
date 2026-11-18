<?php
/** Shared helpers for the platform billing pages. */
function nv_pb_badge(array $inv): string
{
    $overdue = \App\Services\PlatformBillingService::isOverdue($inv);
    $map = ['paid' => 'success', 'partial' => 'warning', 'unpaid' => 'secondary', 'void' => 'dark'];
    $label = $overdue ? 'Overdue' : ucfirst((string)$inv['status']);
    $cls = $overdue ? 'danger' : ($map[$inv['status']] ?? 'secondary');
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}
function nv_pb_money($v, $cur = null): string
{
    global $_settings; return htmlspecialchars((string)($cur ?: $_settings->base_currency())) . ' ' . number_format((float)$v, 2);
}
