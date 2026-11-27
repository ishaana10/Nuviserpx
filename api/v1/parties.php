<?php
/**
 * Parties (customers) endpoint — Nuvis ERPX API v1
 *
 *   GET  /api/v1/parties.php?email=a@b.c  or  ?tin=123   look up a customer (read_parties)
 *   POST /api/v1/parties.php                             find-or-create by lower-cased email, else TIN (write_parties)
 *        {"name":"…","email":"…","tin":"…","phone":"…","address":"…"} -> {id, party_code, created}
 */

require_once(__DIR__ . '/auth.php');

use App\Services\ExternalPartyService;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    check_permission('read_parties');
    $row = ExternalPartyService::find($conn, (int)$api_tenant_id, $_GET['email'] ?? null, $_GET['tin'] ?? null);
    if (!$row) api_respond(404, ['status' => 'not_found', 'message' => 'Party not found.']);
    api_respond(200, ['status' => 'success', 'data' => [
        'id' => (int)$row['id'], 'party_code' => $row['party_code'], 'name' => $row['name'],
        'email' => $row['email'], 'tin' => $row['tin'], 'phone' => $row['phone'],
    ]]);
}

if ($method === 'POST') {
    check_permission('write_parties');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    try {
        $res = ExternalPartyService::findOrCreate($conn, (int)$api_tenant_id, $input);
    } catch (\InvalidArgumentException $e) {
        api_respond(400, ['status' => 'failed', 'message' => $e->getMessage()]);
    } catch (\Throwable $e) {
        api_respond(500, ['status' => 'failed', 'message' => 'Failed to save party.']);
    }
    api_respond($res['created'] ? 201 : 200, ['status' => 'success'] + $res);
}

api_respond(405, ['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
