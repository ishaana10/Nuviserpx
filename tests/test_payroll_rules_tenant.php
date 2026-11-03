<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// Stand-in for SystemSettings: per-company settings with an installation-wide fallback.
class FakeSettings {
    public $tenant = 1; public $own = []; public $global = '';
    function payroll_rules_raw($t = null) { $t = $t ?? $this->tenant; return $this->own[$t] ?? $this->global; }
}
$mk = fn ($rate) => json_encode(['versions' => [['effective_from' => '2026-01-01', 'rules' => PayrollEngine::normalize_rules(['pension' => ['employee_rate' => 8, 'employer_rate' => $rate]])]]]);

$s = new FakeSettings();
check('no setting: preset employer rate', abs(PayrollEngine::load_rules($s, '2026-06-01')['pension']['employer_rate'] - PayrollEngine::default_rules()['pension']['employer_rate']) < 1e-9);
$s->global = $mk(0.10);
check('installation default used by a company without its own', abs(PayrollEngine::load_rules($s, '2026-06-01')['pension']['employer_rate'] - 0.10) < 1e-9);
$s->own[2] = $mk(0.12);
$s->tenant = 2;
check('company 2 has its own rate', abs(PayrollEngine::load_rules($s, '2026-06-01')['pension']['employer_rate'] - 0.12) < 1e-9);
$s->tenant = 1;
check('company 1 unaffected by company 2', abs(PayrollEngine::load_rules($s, '2026-06-01')['pension']['employer_rate'] - 0.10) < 1e-9);
$s->own[3] = json_encode(['versions' => []]);
$s->tenant = 3;
check('reset company gets the preset, not the installation default', abs(PayrollEngine::load_rules($s, '2026-06-01')['pension']['employer_rate'] - PayrollEngine::default_rules()['pension']['employer_rate']) < 1e-9);

echo $fail ? "\n$fail FAILED\n" : "\nAll per-company rules tests passed\n";
exit($fail ? 1 : 0);
