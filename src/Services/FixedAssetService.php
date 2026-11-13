<?php

declare(strict_types=1);

namespace App\Services;

/** Fixed assets: create/update and delete. Moved verbatim out of Master. */
class FixedAssetService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function save_asset()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $asset_name = $_POST['asset_name'] ?? null;
        $asset_number = $_POST['asset_number'] ?? null;
        $id = $_POST['id'] ?? null;
        $purchase_date = $_POST['purchase_date'] ?? null;
        $purchase_price = $_POST['purchase_price'] ?? null;
        $asset_type = $_POST['asset_type'] ?? null;
        $warranty_expiry = $_POST['warranty_expiry'] ?? null;
        $serial_number = $_POST['serial_number'] ?? null;
        $description = $_POST['description'] ?? null;
        $dep_start_date = $_POST['dep_start_date'] ?? null;
        $dep_method = $_POST['dep_method'] ?? null;
        $averaging_method = $_POST['averaging_method'] ?? null;
        $dep_calc_by = $_POST['dep_calc_by'] ?? null;
        $dep_rate = $_POST['dep_rate'] ?? null;
        $effective_life_years = $_POST['effective_life_years'] ?? null;
        $cost_limit = $_POST['cost_limit'] ?? null;
        $residual_value = $_POST['residual_value'] ?? null;
        $status = $_POST['status'] ?? null;

        if (empty($asset_name) || empty($asset_number)) {
            return json_encode(['status' => 'failed', 'msg' => 'Asset Name and Asset Number are required.']);
        }

        // Check unique asset number within tenant
        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id > 0) {
            $cur = $this->conn->query("SELECT status FROM `fixed_assets` WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            if ($cur && ($cr = $cur->fetch_assoc()) && $cr['status'] === 'disposed') {
                return json_encode(['status' => 'failed', 'msg' => 'A disposed asset cannot be changed.']);
            }
        }
        $chk_sql = "SELECT id FROM `fixed_assets` WHERE `tenant_id` = '{$tenant_id}' AND `asset_number` = '{$this->conn->real_escape_string($asset_number)}' ";
        if ($target_id > 0) {
            $chk_sql .= " AND id != '{$target_id}' ";
        }
        $check = $this->conn->query($chk_sql);
        if ($check && $check->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Asset Number already exists for this tenant.']);
        }

        $data = " `tenant_id` = '{$tenant_id}' ";
        $data .= ", `asset_number` = '{$this->conn->real_escape_string($asset_number)}' ";
        $data .= ", `asset_name` = '{$this->conn->real_escape_string($asset_name)}' ";
        $data .= ", `purchase_date` = " . (!empty($purchase_date) ? "'{$this->conn->real_escape_string($purchase_date)}'" : "NULL") . " ";
        $data .= ", `purchase_price` = '" . (!empty($purchase_price) ? floatval($purchase_price) : 0.00) . "' ";
        $data .= ", `asset_type` = '{$this->conn->real_escape_string($asset_type ?? '')}' ";
        $data .= ", `warranty_expiry` = " . (!empty($warranty_expiry) ? "'{$this->conn->real_escape_string($warranty_expiry)}'" : "NULL") . " ";
        $data .= ", `serial_number` = '{$this->conn->real_escape_string($serial_number ?? '')}' ";
        $data .= ", `description` = '{$this->conn->real_escape_string($description ?? '')}' ";
        $data .= ", `dep_start_date` = " . (!empty($dep_start_date) ? "'{$this->conn->real_escape_string($dep_start_date)}'" : "NULL") . " ";
        $data .= ", `dep_method` = '{$this->conn->real_escape_string($dep_method ?? '')}' ";
        $data .= ", `averaging_method` = '{$this->conn->real_escape_string($averaging_method ?? '')}' ";
        $data .= ", `dep_calc_by` = '{$this->conn->real_escape_string($dep_calc_by ?? 'rate')}' ";
        $data .= ", `dep_rate` = '" . (!empty($dep_rate) ? floatval($dep_rate) : 0.00) . "' ";
        $data .= ", `effective_life_years` = '" . (!empty($effective_life_years) ? floatval($effective_life_years) : 0.00) . "' ";
        $data .= ", `cost_limit` = '" . (!empty($cost_limit) ? floatval($cost_limit) : 0.00) . "' ";
        $data .= ", `residual_value` = '" . (!empty($residual_value) ? floatval($residual_value) : 0.00) . "' ";
        $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'draft')}' ";
        $data .= ", `created_by` = '{$user_id}' ";

        if (isset($_FILES['attachment']) && $_FILES['attachment']['tmp_name'] != '') {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'zip', 'txt'];
            if (!in_array($ext, $allowed_exts)) {
                return json_encode(['status' => 'failed', 'msg' => 'Invalid file attachment format. Allowed types: PDF, Images, Documents, Spreadsheets.']);
            }
            $fname = 'uploads/assets/asset_' . $tenant_id . '_' . time() . '.' . $ext;
            $dir_path = base_app . 'uploads/assets';
            if (!is_dir($dir_path)) {
                mkdir($dir_path, 0777, true);
            }
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], base_app . $fname)) {
                $data .= ", `attachment_path` = '{$fname}' ";
            }
        }

        if ($target_id <= 0) {
            $save = $this->conn->query("INSERT INTO `fixed_assets` SET {$data}");
            $asset_id = $this->conn->insert_id;
        } else {
            $save = $this->conn->query("UPDATE `fixed_assets` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            $asset_id = $target_id;
        }

        if ($save) {
            $this->settings->log_audit(empty($id) ? "Created Asset" : "Updated Asset", "Fixed Assets", "Asset #{$asset_number}: {$asset_name}");
            return json_encode(['status' => 'success', 'id' => $asset_id, 'msg' => 'Fixed asset saved successfully.']);
        }
        return \App\Support\Db::fail($this->conn, 'Failed to save fixed asset.');
    }

    public function delete_asset()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        // An asset with depreciation or a disposal in the ledger stays on record.
        $hist = $this->conn->query("SELECT (SELECT COUNT(*) FROM asset_depreciation WHERE asset_id = '{$id}') d, (SELECT status FROM fixed_assets WHERE id = '{$id}' AND tenant_id = '{$tenant_id}') s");
        $h = $hist ? $hist->fetch_assoc() : null;
        if ($h && ((int)$h['d'] > 0 || $h['s'] === 'disposed')) {
            return json_encode(['status' => 'failed', 'msg' => 'This asset has depreciation or a disposal posted to the ledger, so it cannot be deleted.']);
        }

        $del = $this->conn->query("DELETE FROM `fixed_assets` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Asset", "Fixed Assets", "Asset ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Fixed asset deleted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete fixed asset.']);
    }

}
