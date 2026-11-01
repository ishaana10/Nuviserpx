<?php
/**
 * Sales pipeline: open deals by stage with probability-weighted value, plus
 * win rate and closed-won totals. A live snapshot of the CRM funnel.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\CrmService;

$svc = new CrmService($conn, $_settings);
$pipe = $svc->pipelineSummary();
$by = $pipe['by_stage'];

$stage_labels = [
    'qualification' => 'Qualification',
    'proposal'      => 'Proposal Sent',
    'negotiation'   => 'Negotiation',
    'won'           => 'Closed Won',
    'lost'          => 'Closed Lost',
];
$open_stages = ['qualification', 'proposal', 'negotiation'];

nvr_header([
    'page' => 'reports/sales_pipeline',
    'title' => 'Sales Pipeline',
    'icon' => 'fas fa-funnel-dollar',
    'mode' => 'live',
    'subtitle' => 'Open deals by stage, as they stand today',
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Open pipeline</div><div class="v"><?= nvr_money($pipe['open_value'], false) ?></div><div class="s"><?= (int)$pipe['open_count'] ?> open deal<?= $pipe['open_count'] === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Weighted forecast</div><div class="v"><?= nvr_money($pipe['open_weighted'], false) ?></div><div class="s">probability-adjusted</div></div>
    <div class="nvr-stat"><div class="k">Closed won</div><div class="v"><?= nvr_money($pipe['won_value'], false) ?></div><div class="s"><?= (int)$pipe['won_count'] ?> deal<?= $pipe['won_count'] === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Win rate</div><div class="v"><?= $pipe['win_rate'] === null ? '–' : number_format($pipe['win_rate'], 0) . '%' ?></div><div class="s"><?= (int)$pipe['won_count'] ?> won / <?= (int)$pipe['lost_count'] ?> lost</div></div>
</div>

<?php
$open_total = $pipe['open_value'];
?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Stage</th><th class="num">Deals</th><th class="num">Value</th><th class="num">Weighted value</th><th>Share of open pipeline</th></tr></thead>
    <tbody>
    <?php foreach ($open_stages as $s): $row = $by[$s]; $share = $open_total > 0 ? $row['value'] / $open_total * 100 : 0; ?>
        <tr class="acct">
            <td><b><?= $stage_labels[$s] ?></b></td>
            <td class="num"><?= (int)$row['count'] ?></td>
            <td class="num"><?= nvr_money($row['value']) ?></td>
            <td class="num"><?= nvr_money($row['weighted']) ?></td>
            <td><div style="height:8px;background:#eef2f7;border-radius:999px;max-width:320px"><span style="display:block;height:100%;width:<?= round($share, 1) ?>%;background:#2563eb;border-radius:inherit"></span></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="grand"><td>Open pipeline</td><td class="num"><?= (int)$pipe['open_count'] ?></td><td class="num"><?= nvr_money($pipe['open_value'], false) ?></td><td class="num"><?= nvr_money($pipe['open_weighted'], false) ?></td><td></td></tr>
    </tfoot>
</table>
</div>

<div class="nvr-scroll" style="margin-top:18px">
<table class="nvr-table">
    <thead><tr><th>Closed</th><th class="num">Deals</th><th class="num">Value</th></tr></thead>
    <tbody>
        <tr class="acct"><td><b>Won</b></td><td class="num"><?= (int)$by['won']['count'] ?></td><td class="num"><?= nvr_money($by['won']['value']) ?></td></tr>
        <tr class="acct"><td><b>Lost</b></td><td class="num"><?= (int)$by['lost']['count'] ?></td><td class="num"><?= nvr_money($by['lost']['value']) ?></td></tr>
    </tbody>
</table>
</div>
<?php
nvr_footer(['Weighted value is each open deal\'s amount multiplied by its win probability. Win rate is won deals as a share of all closed (won + lost) deals. A live snapshot — open the <a href="./?page=crm">pipeline board</a> to work the deals.'], false);
