<?php
/**
 * JSON endpoint for customisable dashboards.
 *
 *   GET  ?f=list                         dashboards + widget catalogue
 *   GET  ?f=data&w=id1,id2&range=ytd     widget data
 *   POST ?f=create   name
 *   POST ?f=save     id, [name], [range], [layout (JSON)]
 *   POST ?f=delete   id
 *   POST ?f=reorder  ids[]
 */
require_once(__DIR__ . '/../config.php');

use App\Services\DashboardService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dash_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['userdata']['id'])) {
    dash_out(['status' => 'failed', 'msg' => 'Your session has expired. Please sign in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_settings->verify_csrf()) {
    dash_out(['status' => 'failed', 'msg' => 'Security token mismatch. Refresh the page and try again.'], 419);
}

$service = new DashboardService(
    $conn,
    (int)$_settings->active_tenant_id(),
    (int)$_settings->userdata('id'),
    fn (string $perm): bool => (bool)$_settings->can_access($perm)
);
$service->yearEndMonth = max(1, min(12, (int)($_settings->info('fiscal_year_end_month') ?: 12)));
$service->ensureTable();

$action = strtolower($_GET['f'] ?? '');

switch ($action) {
    case 'list':
        dash_out([
            'status' => 'success',
            'dashboards' => $service->listDashboards(),
            'catalog' => array_values($service->catalog()),
            'ranges' => DashboardService::RANGES,
            'defaultLayout' => $service->defaultLayout(),
        ]);

    case 'data':
        $ids = array_filter(array_map('trim', explode(',', (string)($_GET['w'] ?? ''))));
        $range = (string)($_GET['range'] ?? 'this_month');
        dash_out(['status' => 'success'] + $service->widgetData(array_slice($ids, 0, 60), $range));

    case 'create':
        $layout = null;
        if (!empty($_POST['template']) && $_POST['template'] === 'default') {
            $layout = $service->defaultLayout();
        }
        $d = $service->createDashboard((string)($_POST['name'] ?? ''), $layout);
        $d ? dash_out(['status' => 'success', 'dashboard' => $d])
           : dash_out(['status' => 'failed', 'msg' => 'You have reached the maximum number of dashboards.'], 422);

    case 'save':
        $changes = [];
        if (isset($_POST['name'])) {
            $changes['name'] = (string)$_POST['name'];
        }
        if (isset($_POST['range'])) {
            $changes['range'] = (string)$_POST['range'];
        }
        if (isset($_POST['layout'])) {
            $decoded = json_decode((string)$_POST['layout'], true);
            if (!is_array($decoded)) {
                dash_out(['status' => 'failed', 'msg' => 'Invalid layout.'], 422);
            }
            $changes['layout'] = $decoded;
        }
        $d = $service->saveDashboard((int)($_POST['id'] ?? 0), $changes);
        $d ? dash_out(['status' => 'success', 'dashboard' => $d])
           : dash_out(['status' => 'failed', 'msg' => 'Dashboard not found.'], 404);

    case 'delete':
        $service->deleteDashboard((int)($_POST['id'] ?? 0))
            ? dash_out(['status' => 'success'])
            : dash_out(['status' => 'failed', 'msg' => 'You need to keep at least one dashboard.'], 422);

    case 'reorder':
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $service->reorderDashboards($ids);
        dash_out(['status' => 'success']);

    default:
        dash_out(['status' => 'failed', 'msg' => 'Unknown action.'], 400);
}
