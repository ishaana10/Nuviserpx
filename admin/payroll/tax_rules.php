<?php
$brackets = [
    ['min' => 0, 'max' => 30000, 'rate' => 0, 'base_tax' => 0, 'desc' => 'Tax Exempt Threshold ($0 - $30,000)'],
    ['min' => 30001, 'max' => 50000, 'rate' => 18, 'base_tax' => 0, 'desc' => '18% on excess over $30,000'],
    ['min' => 50001, 'max' => 270000, 'rate' => 20, 'base_tax' => 3600, 'desc' => '$3,600 + 20% on excess over $50,000'],
    ['min' => 270001, 'max' => 300000, 'rate' => 22, 'base_tax' => 47600, 'desc' => '$47,600 + 22% on excess over $270,000'],
    ['min' => 30001, 'max' => 350000, 'rate' => 24, 'base_tax' => 54200, 'desc' => '$54,200 + 24% on excess over $300,000'],
    ['min' => 350001, 'max' => 400000, 'rate' => 29, 'base_tax' => 66200, 'desc' => '$66,200 + 29% on excess over $350,000'],
    ['min' => 400001, 'max' => 450000, 'rate' => 34, 'base_tax' => 80700, 'desc' => '$80,700 + 34% on excess over $400,000'],
    ['min' => 450001, 'max' => 500000, 'rate' => 39, 'base_tax' => 97700, 'desc' => '$97,700 + 39% on excess over $450,000'],
    ['min' => 500001, 'max' => 9999999, 'rate' => 44, 'base_tax' => 117200, 'desc' => '$117,200 + 44% on excess over $500,000']
];
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-balance-scale text-primary mr-2"></i> Fiji FRCS PAYE Statutory Tax Brackets & FNPF Regulations</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Tax Schedule</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Statutory Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-file-contract"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">FNPF Employee Contribution</span>
                        <span class="info-box-number text-success h4 mb-0">8.00%</span>
                        <small class="text-muted">Mandatory Salary Deduction</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-building"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">FNPF Employer Contribution</span>
                        <span class="info-box-number text-primary h4 mb-0">8.00%</span>
                        <small class="text-muted">Employer Superannuation</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-globe"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Non-Resident Tax Rate</span>
                        <span class="info-box-number text-warning h4 mb-0">20.00%</span>
                        <small class="text-muted">Flat Rate (No Exemption Threshold)</small>
                    </div>
                </div>
            </div>
        </div>

        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-list-ol text-secondary mr-2"></i> Fiji Primary Resident Progressive PAYE Tax Brackets</h5>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">Tier</th>
                        <th>Annual Salary Range ($ FJD)</th>
                        <th class="text-right">Marginal Tax Rate</th>
                        <th class="text-right">Base Lump Sum Tax</th>
                        <th>Tax Calculation Formula Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    foreach($brackets as $b):
                    ?>
                    <tr>
                        <td class="text-center align-middle font-weight-bold"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary font-monospace">
                            $<?= number_format($b['min']) ?> <?= $b['max'] < 9999999 ? 'to $' . number_format($b['max']) : '+' ?>
                        </td>
                        <td class="text-right align-middle font-weight-bold h6 mb-0 text-navy"><?= $b['rate'] ?>%</td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($b['base_tax'], 2) ?></td>
                        <td class="align-middle text-xs"><?= htmlspecialchars($b['desc']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
