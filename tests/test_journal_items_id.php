<?php
// Every ji.id reference in the code must be backed by the migration that adds
// the journal_items primary key.
$root = dirname(__DIR__);
$mig = file_get_contents($root . '/database/migrations/035_journal_items_id.sql');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('migration adds an auto-increment primary key to journal_items', (bool)preg_match('/ALTER TABLE `journal_items` ADD COLUMN IF NOT EXISTS `id` BIGINT\(20\) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST/', $mig));

// The runner's conditional handling must recognise this statement, so an
// install that already has the column skips it instead of failing.
require_once $root . '/src/Database/SqlMode.php';
$src = file_get_contents($root . '/src/Database/MigrationRunner.php');
check('runner understands ADD COLUMN IF NOT EXISTS', strpos($src, 'ADD COLUMN IF NOT EXISTS') !== false);

// References that needed the column (regression list).
$refs = [
    'src/Services/DashboardService.php' => 'ji.id IS NULL',
    'src/Services/LedgerService.php' => 'ji.id',
    'admin/journals/index.php' => 'COUNT(ji.id)',
    'admin/journals/view_details.php' => 'ORDER BY ji.id',
    'admin/journals/manage_journal.php' => 'ORDER BY ji.id',
];
foreach ($refs as $f => $needle) {
    check("$f still relies on ji.id (now valid)", strpos(file_get_contents($root . '/' . $f), $needle) !== false);
}

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
