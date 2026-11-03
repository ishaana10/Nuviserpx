<?php
// Exercises the dashboards table code paths with a fake connection (no database needed). These paths used to throw
// "Cannot modify readonly property" because bind_param() was given $this->tenantId by reference.
error_reporting(E_ALL & ~E_DEPRECATED);
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\DashboardService as D;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

class FakeResult { function __construct(private array $rows) {} function fetch_all($mode = null) { return $this->rows; } function fetch_assoc() { return array_shift($this->rows); } }
class FakeStmt {
    public $insert_id = 0; public $affected_rows = 1; public $error = ''; private array $bound = [];
    function __construct(private FakeConn $db, private string $sql) {}
    function bind_param(string $types, &...$vars) { $this->bound = $vars; return true; }
    function execute() {
        $this->db->log[] = [$this->sql, $this->bound];
        if (stripos($this->sql, 'INSERT INTO dashboards') === 0) {
            [$t, $u, $name, $json, $range, $order] = $this->bound;
            $this->insert_id = $this->db->nextId++;
            $this->db->rows[$this->insert_id] = ['id' => $this->insert_id, 'tenant_id' => $t, 'user_id' => $u, 'name' => $name, 'layout' => $json, 'date_range' => $range, 'sort_order' => $order];
        }
        if (stripos($this->sql, 'UPDATE dashboards SET name') === 0) {
            [$name, $json, $range, $id] = $this->bound;
            $this->db->rows[$id]['name'] = $name; $this->db->rows[$id]['layout'] = $json; $this->db->rows[$id]['date_range'] = $range;
        }
        return true;
    }
    function get_result() {
        if (stripos($this->sql, 'COUNT(*)') !== false) return new FakeResult([['c' => count($this->db->rows)]]);
        if (stripos($this->sql, 'FROM dashboards') !== false) return new FakeResult(array_values($this->db->rows));
        return new FakeResult([]);
    }
    function close() {}
}
class FakeConn extends mysqli {
    public array $rows = []; public array $log = []; public int $nextId = 1;
    function __construct() {}
    #[\ReturnTypeWillChange] function query($sql, $mode = 0) { return true; }
    #[\ReturnTypeWillChange] function prepare($sql) { return new FakeStmt($this, $sql); }
}

$db = new FakeConn();
$svc = new D($db, 5, 9, fn ($p) => true);

$err = null;
try { $list = $svc->listDashboards(); } catch (\Throwable $e) { $err = $e; $list = []; }
check('listing on first visit does not throw' . ($err ? ' (' . $err->getMessage() . ')' : ''), $err === null);
check('a starter dashboard is returned', count($list) >= 1 && count($list[0]['layout'] ?? []) >= 5);
check('it was stored for this company and user', count($db->rows) === 1 && reset($db->rows)['tenant_id'] === 5 && reset($db->rows)['user_id'] === 9);

$err = null; $saved = null; $deleted = false;
try {
    $new = $svc->createDashboard('Payroll', []);
    $saved = $svc->saveDashboard($new['id'], ['name' => 'People', 'range' => 'ytd', 'layout' => [['uid' => 'a', 'widget' => 'note', 'size' => 'md']]]);
    $svc->reorderDashboards([$new['id']]);
    $deleted = $svc->deleteDashboard($new['id']);
} catch (\Throwable $e) { $err = $e; }
check('create / save / reorder / delete do not throw' . ($err ? ' (' . $err->getMessage() . ')' : ''), $err === null);

echo $fail ? "\n$fail FAILED\n" : "\nAll dashboard persistence tests passed\n";
exit($fail ? 1 : 0);
