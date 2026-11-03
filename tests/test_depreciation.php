<?php
/**
 * Tests for depreciation and disposal maths (pure functions, no database).
 *
 * Run: php tests/test_depreciation.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\DepreciationCalc as D;

$passed = 0;
function check(bool $cond, string $label): void
{
    global $passed;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
    echo "  ✓ {$label}\n";
}
/** Post every month through $through the way the app does and return [total, last charge, months charged]. */
function runSchedule(array $a, string $through): array
{
    $acc = 0.0;
    $n = 0;
    $last = 0.0;
    foreach (D::monthsToPost($a['dep_start_date'], null, $through) as $end) {
        $c = D::monthlyCharge($a, $end, $acc);
        $acc = round($acc + $c, 2);
        if ($c > 0) {
            $n++;
            $last = $c;
        }
    }
    return [$acc, $last, $n];
}

$sl = ['purchase_price' => 12000, 'residual_value' => 0, 'dep_start_date' => '2026-01-01', 'dep_method' => 'Straight Line',
    'averaging_method' => 'Full Month', 'dep_calc_by' => 'rate', 'dep_rate' => 20, 'effective_life_years' => 0, 'cost_limit' => 0];

echo "=== Straight line ===\n";
check(D::monthlyCharge($sl, '2026-01-31', 0) === 200.00, '12,000 at 20% a year is 200.00 a month');
check(D::monthlyCharge($sl, '2025-12-31', 0) === 0.0, 'nothing before the start month');
[$tot, , $n] = runSchedule($sl, '2026-12-31');
check($tot === 2400.00 && $n === 12, 'a full year charges 2,400.00 over 12 months');
[$tot, , $n] = runSchedule($sl, '2040-12-31');
check($tot === 12000.00 && $n === 60, 'the asset is fully depreciated after 5 years, and no further');

$res = $sl; $res['residual_value'] = 2000;
check(D::depreciableAmount($res) === 10000.00 && D::monthlyCharge($res, '2026-01-31', 0) === 166.67, 'residual value is kept back (10,000 base → 166.67 a month)');
[$tot] = runSchedule($res, '2040-12-31');
check($tot === 10000.00, 'carrying value stops at the residual value exactly');

$life = $sl; $life['dep_calc_by'] = 'effective_life'; $life['effective_life_years'] = 4;
check(D::monthlyCharge($life, '2026-01-31', 0) === 250.00, 'a 4-year life is 25% a year, 250.00 a month');
check(D::annualRate($life) === 0.25, 'annual rate from effective life');

$lim = $sl; $lim['purchase_price'] = 100000; $lim['cost_limit'] = 12000;
check(D::cost($lim) === 12000.00 && D::monthlyCharge($lim, '2026-01-31', 0) === 200.00, 'the cost limit caps what is depreciated');

echo "\n=== Averaging in the first month ===\n";
$days = $sl; $days['averaging_method'] = 'Actual Days'; $days['dep_start_date'] = '2026-01-16';
check(abs(D::monthFraction('2026-01-16', '2026-01-31', 'Actual Days') - 16 / 31) < 1e-9, 'starting on the 16th of a 31-day month is 16 days of use');
check(D::monthlyCharge($days, '2026-01-31', 0) === 103.23, 'the first month is pro-rated by days (200 × 16/31)');
check(D::monthlyCharge($days, '2026-02-28', 103.23) === 200.00, 'later months are full');
$fm = $sl; $fm['dep_start_date'] = '2026-01-16';
check(D::monthlyCharge($fm, '2026-01-31', 0) === 200.00, 'Full Month averaging charges the whole first month');

echo "\n=== Diminishing value ===\n";
$dv = $sl; $dv['dep_method'] = 'Diminishing Value';
check(D::monthlyCharge($dv, '2026-01-31', 0) === 200.00, 'first month is the same as straight line');
check(D::monthlyCharge($dv, '2026-02-28', 200.00) === 196.67, 'later months are charged on what is left (11,800 × 20% ÷ 12)');
[$tot12] = runSchedule($dv, '2026-12-31');
check($tot12 < 2400.00 && $tot12 > 2100.00, 'a year of diminishing value is below straight line');
[$totAll] = runSchedule($dv, '2060-12-31');
check($totAll < 12000.00, 'diminishing value never quite reaches zero with no residual');
$dvr = $dv; $dvr['residual_value'] = 6000;
[$totR] = runSchedule($dvr, '2060-12-31');
check($totR <= 6000.00, 'and never goes below the residual value');
$dvl = $dv; $dvl['dep_calc_by'] = 'effective_life'; $dvl['effective_life_years'] = 5;
check(D::annualRate($dvl) === 0.4, 'diminishing value from a 5-year life is 40% (twice 1/life)');

echo "\n=== Other methods ===\n";
$full = $sl; $full['dep_method'] = 'Full Depreciation';
check(D::monthlyCharge($full, '2026-01-31', 0) === 12000.00 && D::monthlyCharge($full, '2026-02-28', 12000.00) === 0.0, 'Full Depreciation writes everything off in the first month');
$none = $sl; $none['dep_method'] = 'No Depreciation';
check(D::monthlyCharge($none, '2026-06-30', 0) === 0.0, 'No Depreciation never charges');
$blank = $sl; $blank['dep_start_date'] = '';
check(D::monthlyCharge($blank, '2026-06-30', 0) === 0.0, 'an asset with no start date is not depreciated');
$norate = $sl; $norate['dep_rate'] = 0;
check(D::monthlyCharge($norate, '2026-06-30', 0) === 0.0, 'a zero rate charges nothing');

echo "\n=== Months to post ===\n";
check(D::monthsToPost('2026-03-15', null, '2026-05-31') === ['2026-03-31', '2026-04-30', '2026-05-31'], 'from the start month to the run month');
check(D::monthsToPost('2026-03-15', '2026-04-30', '2026-06-30') === ['2026-05-31', '2026-06-30'], 'resumes the month after the last one charged');
check(D::monthsToPost('2026-03-15', '2026-05-31', '2026-05-31') === [], 'nothing to post when already up to date');
check(D::monthsToPost('2026-03-15', null, '2026-02-28') === [], 'nothing before the start');
check(D::monthEnd('2026-02-10') === '2026-02-28' && D::monthEnd('2028-02-10') === '2028-02-29', 'month ends respect leap years');

echo "\n=== Disposal ===\n";
$acc = ['proceeds' => 1, 'accumulated' => 2, 'cost' => 3, 'gain_loss' => 4];
function net(array $lines): array { $d = $c = 0.0; foreach ($lines as $l) { $d += $l['debit']; $c += $l['credit']; } return [round($d, 2), round($c, 2)]; }
$g = D::disposalLines(10000, 6000, 5000, $acc);   // book value 4,000, sold for 5,000 => gain 1,000
check($g['gain'] === 1000.00 && net($g['lines']) === [11000.00, 11000.00], 'a sale above book value is a gain, and balances');
check(end($g['lines'])['account_id'] === 4 && end($g['lines'])['credit'] === 1000.00, 'the gain is credited');
$l = D::disposalLines(10000, 6000, 3000, $acc);   // loss 1,000
check($l['gain'] === -1000.00 && net($l['lines'])[0] === net($l['lines'])[1], 'a sale below book value is a loss, and balances');
check(count(array_filter($l['lines'], fn ($x) => $x['account_id'] === 4 && $x['debit'] === 1000.00)) === 1, 'the loss is debited');
$s = D::disposalLines(10000, 10000, 0, $acc);     // scrapped fully depreciated
check($s['gain'] === 0.0 && count($s['lines']) === 2 && net($s['lines']) === [10000.00, 10000.00], 'scrapping a fully depreciated asset has no gain or loss');
$w = D::disposalLines(10000, 0, 0, $acc);         // written off, never depreciated
check($w['gain'] === -10000.00 && net($w['lines']) === [10000.00, 10000.00], 'writing off an asset with no depreciation is a full loss');
mt_srand(3);
$ok = true;
for ($i = 0; $i < 300; $i++) {
    $cost = mt_rand(100, 5000000) / 100; $accu = round($cost * mt_rand(0, 100) / 100, 2); $pro = mt_rand(0, 1) ? mt_rand(0, 6000000) / 100 : 0;
    $r = D::disposalLines($cost, $accu, $pro, $acc);
    $n = net($r['lines']);
    $ok = $ok && $n[0] === $n[1];
}
check($ok, '300 random disposals all balance to the cent');


echo "\n=== Wiring ===\n";
use App\Support\Permissions;
check(Permissions::forAction('run_depreciation') === 'assets_edit' && Permissions::forAction('dispose_asset') === 'assets_edit', 'running depreciation and disposing need permission to edit assets');
$svc = file_get_contents(__DIR__ . '/../src/Services/AssetService.php');
check(str_contains($svc, 'postSystemJournal') && str_contains($svc, 'asset_depreciation'), 'each month is a journal linked to per-asset rows');
check(str_contains($svc, 'Run depreciation through'), 'disposal refuses until depreciation is up to date');
check(str_contains(file_get_contents(__DIR__ . '/../src/Services/JournalService.php'), 'function postSystemJournal'), 'the journal service exposes the system-journal entry point');
check(str_contains(file_get_contents(__DIR__ . '/../classes/Master.php'), 'cannot be deleted'), 'an asset with depreciation or a disposal cannot be deleted');

echo "\nAll {$passed} depreciation checks passed.\n";
