<?php
// Slice 2 of the UX polish: quick-jump overlay (top-bar icon + Ctrl/Cmd+K).
$root = dirname(__DIR__);
$js  = file_get_contents($root . '/dist/js/nuvis-palette.js');
$css = file_get_contents($root . '/dist/css/nuvis-ui.css');
$top = file_get_contents($root . '/admin/inc/topBarNav.php');
$ftr = file_get_contents($root . '/admin/inc/footer.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('top bar has the quick-jump button', strpos($top, 'id="nvPaletteBtn"') !== false && strpos($top, 'aria-label="Quick jump') !== false);
check('footer loads the palette script after nuvis-ui.js', strpos($ftr, 'nuvis-palette.js') !== false && strpos($ftr, 'nuvis-palette.js') > strpos($ftr, 'nuvis-ui.js'));
check('Ctrl/Cmd+K opens it', strpos($js, 'e.ctrlKey || e.metaKey') !== false && strpos($js, "'k'") !== false);
check('items come from the already-filtered sidebar and Create-new menu', strpos($js, '.main-sidebar nav a.nav-link[href]') !== false && strpos($js, '#createNewDropdown') !== false);
check('no network calls (client-side only)', !preg_match('/\$\.(ajax|get|post)|fetch\(|XMLHttpRequest/', $js));
check('titles rendered with .text() (never HTML)', substr_count($js, '.text(') >= 4 && !preg_match('/\.html\(/', $js));
check('placeholder/anchor-only links are skipped', strpos($js, "href === '#'") !== false && strpos($js, "indexOf('javascript') === 0") !== false);
check('every word must match (multi-word search)', strpos($js, 'words.every') !== false);
check('arrow keys, Enter and Escape handled', strpos($js, 'ArrowDown') !== false && strpos($js, 'ArrowUp') !== false && strpos($js, "'Enter'") !== false && strpos($js, "'Escape'") !== false);
check('empty result state', strpos($js, 'Nothing matches.') !== false);
check('results are capped', strpos($js, '.slice(0, 30)') !== false);
check('overlay styles exist and honour [hidden]', strpos($css, '.nv-palette[hidden]') !== false && strpos($css, '.nv-palette-item') !== false);
check('touch rows are at least 44px', substr_count($css, 'min-height: 44px') >= 2);
exit($fail ? 1 : 0);
