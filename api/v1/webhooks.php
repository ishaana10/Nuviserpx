<?php
/**
 * Webhooks REST endpoint — Nuvis ERPX API v1
 *
 * Lets a connected application manage its own webhook subscriptions.
 *
 *   GET    /api/v1/webhooks.php            list this tenant's webhooks + available events
 *   POST   /api/v1/webhooks.php            subscribe  { event, target_url, name?, secret_token? }
 *   DELETE /api/v1/webhooks.php?id=123     unsubscribe
 *
 * Requires the `manage_webhooks` (or `*`) permission on the API key.
 */

require_once(__DIR__ . '/auth.php');

use App\Support\IntegrationEvents;

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET') {
    check_permission('manage_webhooks');
    $rows = $conn->query("SELECT id, name, event, target_url, status, last_status, last_triggered_at, failure_count, date_created
        FROM `webhooks` WHERE tenant_id = '{$api_tenant_id}' ORDER BY id DESC");
    $data = [];
    if ($rows) { while ($r = $rows->fetch_assoc()) { $data[] = $r; } }

    $events = [];
    foreach (IntegrationEvents::catalog() as $key => $meta) {
        $events[] = ['event' => $key, 'label' => $meta['label'], 'group' => $meta['group'], 'fields' => $meta['fields']];
    }

    api_respond(200, ['status' => 'success', 'count' => count($data), 'data' => $data, 'available_events' => $events]);
}

if ($method === 'POST') {
    check_permission('manage_webhooks');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $event = isset($input['event']) ? trim((string)$input['event']) : '';
    $url = isset($input['target_url']) ? trim((string)$input['target_url']) : '';
    $name = isset($input['name']) ? $conn->real_escape_string((string)$input['name']) : 'API Subscription';
    $secret = !empty($input['secret_token']) ? $conn->real_escape_string((string)$input['secret_token']) : bin2hex(random_bytes(16));

    if (!IntegrationEvents::exists($event)) {
        api_respond(422, ['status' => 'failed', 'message' => 'Unknown event. See available_events from GET.', 'event' => $event]);
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        api_respond(422, ['status' => 'failed', 'message' => 'A valid target_url (https) is required.']);
    }

    $event_esc = $conn->real_escape_string($event);
    $url_esc = $conn->real_escape_string($url);
    $conn->query("INSERT INTO `webhooks` SET
        `tenant_id` = '{$api_tenant_id}', `name` = '{$name}', `event` = '{$event_esc}',
        `target_url` = '{$url_esc}', `secret_token` = '{$secret}', `status` = 1");

    if ($conn->error) api_respond(500, ['status' => 'failed', 'message' => 'Could not create subscription.', 'error' => $conn->error]);

    api_respond(201, [
        'status' => 'success',
        'message' => 'Webhook subscription created.',
        'webhook_id' => (int)$conn->insert_id,
        'secret_token' => $secret,
        'signature_scheme' => 'HMAC-SHA256 over raw body, header X-ERPX-Signature: sha256=<hex>',
    ]);
}

if ($method === 'DELETE') {
    check_permission('manage_webhooks');
    if ($id <= 0) api_respond(400, ['status' => 'failed', 'message' => 'A webhook id is required.']);
    $conn->query("DELETE FROM `webhooks` WHERE id = '{$id}' AND tenant_id = '{$api_tenant_id}'");
    if ($conn->affected_rows === 0) api_respond(404, ['status' => 'not_found', 'message' => 'Subscription not found.']);
    api_respond(200, ['status' => 'success', 'message' => 'Webhook subscription removed.']);
}

api_respond(405, ['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST, DELETE']);
