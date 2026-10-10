<?php
require_once(__DIR__ . '/auth.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    check_permission('read_journals');

    $where = " WHERE j.tenant_id = '{$api_tenant_id}' ";
    $qry = $conn->query("SELECT * FROM `journal_entries` j {$where} ORDER BY j.id DESC LIMIT 100");
    $data = [];
    while($row = $qry->fetch_assoc()){
        $jid = $row['id'];
        $items_qry = $conn->query("SELECT ji.*, a.name as account_name, g.name as group_name, g.type as group_type FROM `journal_items` ji INNER JOIN `account_list` a ON ji.account_id = a.id INNER JOIN `group_list` g ON ji.group_id = g.id WHERE ji.journal_id = '{$jid}'");
        $row['items'] = [];
        while($it = $items_qry->fetch_assoc()){
            $row['items'][] = $it;
        }
        $data[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'count' => count($data),
        'data' => $data
    ]);
    exit;
}

if($method === 'POST'){
    check_permission('write_journals');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $journal_date = !empty($input['journal_date']) ? $conn->real_escape_string($input['journal_date']) : date('Y-m-d');
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : 'API Journal Posting';
    $code = 'JRN-'.date('Ym').'-'.rand(1000,9999);

    $sql = "INSERT INTO `journal_entries` SET
        `tenant_id` = '{$api_tenant_id}',
        `code` = '{$code}',
        `journal_date` = '{$journal_date}',
        `description` = '{$description}'";

    if($conn->query($sql)){
        $jid = $conn->insert_id;

        if(isset($input['items']) && is_array($input['items'])){
            foreach($input['items'] as $it){
                $acc_id = intval($it['account_id']);
                $group_id = intval($it['group_id']); // 1 = Debit, 2 = Credit
                $amount = (float)$it['amount'];

                $conn->query("INSERT INTO `journal_items` SET
                    `journal_id` = '{$jid}',
                    `account_id` = '{$acc_id}',
                    `group_id` = '{$group_id}',
                    `amount` = '{$amount}'");
            }
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Journal entry posted successfully via API.',
            'journal_id' => $jid,
            'journal_code' => $code
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => 'Failed to post journal entry.', 'error' => $conn->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
?>