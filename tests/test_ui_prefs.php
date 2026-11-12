<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\UiPrefsService as U;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('empty input gives the defaults', U::clean([]) === U::DEFAULTS && U::clean(null) === U::DEFAULTS);
$p = U::clean(['mode' => 'dark', 'accent' => '#E11D48', 'sidebar' => 'accent', 'density' => 'compact', 'text' => 'large', 'collapsed' => '1', 'pins' => ['invoices', 'reports_pnl']]);
check('valid choices are kept', $p['mode'] === 'dark' && $p['accent'] === '#e11d48' && $p['sidebar'] === 'accent' && $p['density'] === 'compact' && $p['text'] === 'large' && $p['collapsed'] === true && $p['pins'] === ['invoices', 'reports_pnl']);
check('unknown values fall back', U::clean(['mode' => 'neon', 'sidebar' => 'x', 'density' => 'tiny', 'text' => 'huge'])['mode'] === 'light');
check('an accent name maps to its colour', U::clean(['accent' => 'teal'])['accent'] === U::ACCENTS['teal']);
check('a bad colour cannot reach the CSS', U::clean(['accent' => 'red;}body{display:none'])['accent'] === U::DEFAULTS['accent'] && U::clean(['accent' => '#12345'])['accent'] === U::DEFAULTS['accent']);
check('pins reject markup, duplicates and blanks', U::clean(['pins' => ['a', 'a', '<b>', '', 'x y', 'ok_1']])['pins'] === ['a', 'ok_1']);
check('pins are capped', count(U::clean(['pins' => array_map(fn($i) => "p$i", range(1, 30))])['pins']) === U::MAX_PINS);
check('unknown keys are dropped', array_keys(U::clean(['evil' => 1])) === array_keys(U::DEFAULTS));

check('shade darkens and lightens', U::shade('#808080', -50) === '#404040' && U::shade('#000000', 100) === '#ffffff' && U::shade('#2563eb', 0) === '#2563eb');
check('white text on a dark accent, dark text on a light one', U::contrastText('#2563eb') === '#ffffff' && U::contrastText('#fde68a') === '#0f172a');
$css = U::cssVars(U::clean(['accent' => '#e11d48']));
check('css vars carry the accent and rgb', str_contains($css, '--nv-accent:#e11d48') && str_contains($css, '--nv-accent-rgb:225,29,72') && str_starts_with($css, ':root{'));
check('html attributes only hold whitelisted words', preg_match('/^data-nv-mode="dark" data-nv-eff="dark" data-nv-sidebar="light" data-nv-density="spacious" data-nv-text="small"$/', U::htmlAttrs(U::clean(['mode' => 'dark', 'sidebar' => 'light', 'density' => 'spacious', 'text' => 'small']))) === 1);
check('auto mode starts light until the browser decides', str_contains(U::htmlAttrs(U::clean(['mode' => 'auto'])), 'data-nv-eff="light"'));

// wiring
$r = fn($f) => file_get_contents(base_app . $f);
check('endpoint checks session, csrf and saves through the service', str_contains($r('classes/UiPrefs.php'), 'verify_csrf') && str_contains($r('classes/UiPrefs.php'), '$_SESSION[\'userdata\'][\'id\']') && str_contains($r('classes/UiPrefs.php'), '->save('));
check('migration file and boot-time check create the table', str_contains($r('database/migrations/017_user_ui_prefs.sql'), 'user_ui_prefs') && str_contains($r('src/Database/LegacySchemaCatchup.php'), 'check_ui_pref_migrations();'));
check('index renders saved attributes on <html>', str_contains($r('admin/index.php'), 'UiPrefsService::htmlAttrs'));
check('header loads theme css after the others and primes NUVIS_UI', str_contains($r('admin/inc/header.php'), 'nuvis-theme.css') && str_contains($r('admin/inc/header.php'), 'window.NUVIS_UI'));
check('footer loads the script and the panel', str_contains($r('admin/inc/footer.php'), 'nuvis-theme.js') && str_contains($r('admin/inc/footer.php'), 'personalise.php'));
check('top bar has a Personalise button', str_contains($r('admin/inc/topBarNav.php'), 'data-nv-open="personalise"'));
check('side menu has search and a pinned block, and no hard-coded colours', str_contains($r('admin/inc/navigation.php'), 'nvSideSearchInput') && str_contains($r('admin/inc/navigation.php'), 'nvPinned') && !str_contains($r('admin/inc/navigation.php'), '#0f172a'));
check('theme css covers dark mode and the three menu styles', str_contains($r('dist/css/nuvis-theme.css'), 'data-nv-eff="dark"') && str_contains($r('dist/css/nuvis-theme.css'), 'data-nv-sidebar="accent"') && str_contains($r('dist/css/nuvis-theme.css'), 'data-nv-sidebar="light"'));
check('dashboard follows the app colour scheme', str_contains($r('dist/js/nuvis-dashboard.js'), 'nuvis:theme'));
exit($fail ? 1 : 0);
