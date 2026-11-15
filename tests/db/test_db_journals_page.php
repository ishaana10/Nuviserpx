<?php
// The Journals list renders the real page against a real database: capped, correct tabs, correct totals, safe inputs.
require __DIR__ . '/_harness.php';

class SystemSettings {
    public static int $type = 1; public static int $uid = 1;
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return ['id' => self::$uid, 'type' => self::$type][$k] ?? null; }
    public function can_access($k) { return false; }
}
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'Ann','Admin','ann','x'),(2,'Bob','Clerk','bob','x')");
$acc = array_column($c->query("SELECT id FROM account_list WHERE tenant_id=1 AND delete_flag=0 LIMIT 2")->fetch_all(MYSQLI_ASSOC), 'id');
$g = (int)$c->query("SELECT id FROM group_list ORDER BY id LIMIT 1")->fetch_row()[0];

// 2,500 journals, newest first by id order below: ids 1..400 are OLD drafts, then posted, a few cancelled, one unbalanced posted.
$c->query("SET autocommit=0");
$total = 2500; $rows = [];
for ($i = 1; $i <= $total; $i++) {
    $odd = [2003 => 'Canceled', 2004 => 'void', 2005 => '3', 2006 => '2', 2007 => 'pending_approval', 2008 => 'DRAFT'];
    $status = $i <= 400 ? 'draft' : (in_array($i, [2000, 2001, 2002], true) ? 'cancelled' : ($odd[$i] ?? 'posted'));
    $day = $i <= 400 ? 340 - (int)($i / 3) : 330 - (int)(($i - 400) / 8);    // drafts are older than all posted ones
    $date = date('Y-m-d', strtotime("-$day days"));
    $uid = $i % 5 === 0 ? 2 : 1;
    $rows[] = "($i,1,'J$i','$date','narration $i','$status',$uid)";
}
$c->query("INSERT INTO journal_entries (id,tenant_id,code,journal_date,description,status,user_id) VALUES " . implode(',', $rows));
$items = [];
for ($i = 1; $i <= $total; $i++) { $amt = $i === 2499 ? 100 : 50; $cr = $i === 2499 ? 90 : 50; $items[] = "($i,{$acc[0]},$g,'debit',$amt)"; $items[] = "($i,{$acc[1]},$g,'credit',$cr)"; }
$c->query("INSERT INTO journal_items (journal_id,account_id,group_id,entry_side,amount) VALUES " . implode(',', $items));
$c->query("COMMIT"); $c->query("SET autocommit=1");

$render = function (array $get) use ($c) {
    $_GET = $get; $conn = $c; $_settings = new SystemSettings();
    ob_start(); include base_app . 'admin/journals/index.php'; return ob_get_clean();
};
$rowsIn = fn($html) => substr_count($html, 'class="dropdown-item view_data"');
$tab = fn($html, $label) => preg_match('/' . preg_quote($label, '/') . '<span class="count">(\d+)<\/span>/', $html, $m) ? (int)$m[1] : -1;
$from = date('Y-m-d', strtotime('-400 days')); $to = date('Y-m-d');

$h = $render(['from' => $from, 'to' => $to]);
check('default view shows at most 2,000 journals', $rowsIn($h) === 2000);
check('the banner says how many are shown of the total', strpos($h, '2,000 of 2,500') !== false && strpos($h, 'all=1') !== false);
check('tab counts are the true totals, not the capped rows', $tab($h, 'All') === 2500 && $tab($h, 'Posted') === 2091 && $tab($h, 'Draft') === 401 && $tab($h, 'Cancelled') === 7 && $tab($h, 'Awaiting approval') === 1);
check('the page stays small at the cap (under 4 MB)', strlen($h) < 4 * 1024 * 1024);
check('rows are the newest ones (newest posted present, oldest draft absent)', strpos($h, '>J2500<') !== false && strpos($h, '>J1<') === false);

$hd = $render(['from' => $from, 'to' => $to, 'status' => 'draft']);
check('the Draft tab shows all 401 drafts (any letter case) even though most are older than the newest 2,000 journals', $rowsIn($hd) === 401 && strpos($hd, '>J1<') !== false && strpos($hd, 'Showing the newest') === false);
$hc = $render(['from' => $from, 'to' => $to, 'status' => 'cancelled']);
check('the Cancelled tab shows every spelling of cancelled (cancelled, Canceled, void, 2, 3)', $rowsIn($hc) === 7 && strpos($hc, '>J2000<') !== false && strpos($hc, '>J2003<') !== false && strpos($hc, '>J2004<') !== false && strpos($hc, '>J2005<') !== false && strpos($hc, '>J2006<') !== false);
$hp = $render(['from' => $from, 'to' => $to, 'status' => 'pending']);
check('the Awaiting approval tab shows only the pending journal', $rowsIn($hp) === 1 && strpos($hp, '>J2007<') !== false);
$hq = $render(['from' => $from, 'to' => $to, 'status' => 'posted', 'all' => '1']);
check('the Posted tab excludes every other status', $rowsIn($hq) === 2091 && strpos($hq, '>J2003<') === false && strpos($hq, '>J2007<') === false && strpos($hq, '>J2008<') === false && strpos($hq, '>J1<') === false);
$hcap = $render(['from' => $from, 'to' => $to, 'status' => 'cancelled', 'all' => '1']);
check('?all=1 on a tab shows all of that tab', $rowsIn($hcap) === 7);

$ha = $render(['from' => $from, 'to' => $to, 'all' => '1']);
check('?all=1 shows every journal and no banner', $rowsIn($ha) === 2500 && strpos($ha, 'Showing the newest') === false);

check('an out-of-balance posted journal among those shown is flagged', strpos($h, 'out of balance') !== false && strpos($h, 'out by 10.00') !== false);
check('capped view says the figures are for the journals shown', strpos($h, 'among the journals shown') !== false && strpos($h, 'Posted (journals shown)') !== false);
check('uncapped view reports the whole period', strpos($ha, 'in this period') !== false && strpos($ha, 'Posted in period') !== false);
check('draft tab has no posted total or balance alert', strpos($hd, 'Posted in period') === false && strpos($hd, 'out of balance') === false);

// A tab with more than 2,000 rows made of several status spellings: the newest 2,000 overall, in date order.
$c->query("UPDATE journal_entries SET status = 'void' WHERE id BETWEEN 401 AND 2400");
$c->query("UPDATE journal_entries SET status = 'cancelled' WHERE id BETWEEN 2401 AND 2500");
$hbig = $render(['from' => $from, 'to' => $to, 'status' => 'cancelled']);
check('a cancelled tab over the cap shows the newest 2,000 across spellings and a banner', $rowsIn($hbig) === 2000 && strpos($hbig, 'Showing the newest 2,000 of') !== false && strpos($hbig, '>J2500<') !== false && strpos($hbig, '>J401<') === false);
preg_match_all('/data-code="(J\d+)"/', $hbig, $mm); $ids = array_unique(array_map(fn($x) => (int)substr($x, 1), $mm[1]));
$sorted = $ids; rsort($sorted);
check('and they are in newest-first order', array_values($ids) === $sorted);
$c->query("UPDATE journal_entries SET status = 'posted' WHERE id BETWEEN 401 AND 2500 AND id NOT IN (2000,2001,2002,2003,2004,2005,2006,2007,2008)");

SystemSettings::$type = 2; SystemSettings::$uid = 2;
$ho = $render(['from' => $from, 'to' => $to, 'all' => '1']);
check("a non-admin sees only their own journals (Bob's 500)", $rowsIn($ho) === 500 && $tab($ho, 'All') === 500);
SystemSettings::$type = 1; SystemSettings::$uid = 1;

$hn = $render(['from' => '2020-01-01', 'to' => '2020-01-02', 'status' => 'posted']);
check('an empty period renders with no rows and no banner', $rowsIn($hn) === 0 && strpos($hn, 'Showing the newest') === false);
$hx = $render(['from' => "1' OR 1=1 --", 'to' => "x'; DROP TABLE journal_entries; --", 'status' => "posted' OR '1'='1"]);
check('hostile from/to/status are ignored: defaults apply, tables intact', $c->query("SELECT COUNT(*) FROM journal_entries")->fetch_row()[0] == 2500 && $rowsIn($hx) <= 2000 && strpos($hx, 'DROP TABLE') === false);

// Speed at this size: the old page summed every line in the period before cutting anything.
$t = microtime(true); $render(['from' => $from, 'to' => $to]); $ms = (microtime(true) - $t) * 1000;
check(sprintf('default page renders quickly at 2,500 journals (%.0f ms)', $ms), $ms < 2500);
nv_done();
