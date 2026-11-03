<?php
require_once(base_app . 'classes/PayrollEngine.php');
$rules = PayrollEngine::load_rules($_settings);

$pct = function ($r) { return rtrim(rtrim(number_format($r * 100, 2), '0'), '.') . '%'; };

/* Turn marginal bands into display rows with the cumulative tax at each band floor. */
$bandRows = function (array $bands) {
    $rows = []; $floor = 0.0; $cum = 0.0;
    foreach ($bands as $b) {
        $rows[] = ['from' => $floor, 'to' => $b['up_to'], 'rate' => $b['rate'], 'base' => $cum];
        if ($b['up_to'] !== null) { $cum += ($b['up_to'] - $floor) * $b['rate']; $floor = $b['up_to']; }
    }
    return $rows;
};
$tables = [
    'Resident income tax bands' => $bandRows($rules['resident_bands']),
    'Non-resident income tax bands' => $bandRows($rules['non_resident_bands']),
];
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-balance-scale text-primary mr-2"></i> Payroll Tax &amp; Pension Rules</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            These are the rules payroll uses to calculate income tax, pension contributions and employer levies.
            These are the rules in force today. Rules are dated and can be changed under Payroll Rules Settings; when none are saved, the starting preset is used.
            Annual bands are marginal: each rate applies only to the slice of income inside its band.
        </div>

        <div class="row mb-4">
            <div class="col-md-3"><div class="info-box bg-light border"><div class="info-box-content">
                <span class="info-box-text text-muted font-weight-bold">Employee pension</span>
                <span class="info-box-number h4 mb-0"><?= $pct($rules['pension']['employee_rate']) ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box bg-light border"><div class="info-box-content">
                <span class="info-box-text text-muted font-weight-bold">Employer pension</span>
                <span class="info-box-number h4 mb-0"><?= $pct($rules['pension']['employer_rate']) ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box bg-light border"><div class="info-box-content">
                <span class="info-box-text text-muted font-weight-bold">Secondary income rate</span>
                <span class="info-box-number h4 mb-0"><?= $pct($rules['secondary_income_rate']) ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box bg-light border"><div class="info-box-content">
                <span class="info-box-text text-muted font-weight-bold">Redundancy</span>
                <span class="info-box-number h6 mb-0"><?= number_format($rules['redundancy']['exempt_up_to'], 2) ?> exempt, then <?= $pct($rules['redundancy']['rate']) ?></span></div></div></div>
        </div>

        <?php foreach ($tables as $title => $rows): ?>
        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-list-ol text-secondary mr-2"></i> <?= htmlspecialchars($title) ?></h5>
        <div class="table-responsive mb-4">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">Band</th>
                        <th>Annual income<?php echo $_settings->currency_tag() ?></th>
                        <th class="text-right">Marginal rate</th>
                        <th class="text-right">Tax at band start</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $b): ?>
                    <tr>
                        <td class="text-center font-weight-bold"><?= $i + 1 ?></td>
                        <td class="font-monospace"><?= number_format($b['from']) ?> <?= $b['to'] === null ? 'and above' : 'to ' . number_format($b['to']) ?></td>
                        <td class="text-right font-weight-bold"><?= $pct($b['rate']) ?></td>
                        <td class="text-right"><?= number_format($b['base'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-clock text-secondary mr-2"></i> Overtime</h5>
        <p class="text-sm">Standard week: <b><?= rtrim(rtrim(number_format($rules['overtime']['weekly_hours'], 2), '0'), '.') ?> hours</b>, <b><?= rtrim(rtrim(number_format($rules['overtime']['weeks_per_year'], 2), '0'), '.') ?> weeks</b> a year.
        <?php foreach ($rules['overtime']['tiers'] as $t): ?><br><?= htmlspecialchars($t['label']) ?>: <b><?= rtrim(rtrim(number_format($t['multiplier'], 2), '0'), '.') ?>x</b> the hourly rate<?php endforeach; ?></p>

        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-building text-secondary mr-2"></i> Employer levies (on gross pay)</h5>
        <table class="table table-bordered table-striped" style="max-width:520px">
            <thead><tr class="bg-gradient-navy text-white"><th>Levy</th><th class="text-right">Rate</th></tr></thead>
            <tbody>
            <?php foreach ($rules['employer_levies'] as $l): ?>
                <tr><td><?= htmlspecialchars($l['label']) ?></td><td class="text-right"><?= $pct($l['rate']) ?></td></tr>
            <?php endforeach; if (!$rules['employer_levies']): ?>
                <tr><td colspan="2" class="text-muted">No employer levies configured.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
