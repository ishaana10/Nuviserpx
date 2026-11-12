<?php

declare(strict_types=1);

namespace App\Services;

/** Custom extra fields. Moved verbatim out of Master. */
class ExtrafieldService
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

    public function save_extrafield()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $element_type = $_POST['element_type'] ?? null;
        $field_code = $_POST['field_code'] ?? null;
        $field_label = $_POST['field_label'] ?? null;
        $field_type = $_POST['field_type'] ?? null;
        $field_options = $_POST['field_options'] ?? null;
        $is_required = $_POST['is_required'] ?? null;

        if (empty($element_type) || empty($field_code) || empty($field_label)) {
            return json_encode(['status' => 'failed', 'msg' => 'Target entity, field code, and field label are required.']);
        }

        $elem = $this->conn->real_escape_string($element_type);
        $f_code = $this->conn->real_escape_string(preg_replace('/[^a-z0-9_]/', '', strtolower($field_code)));
        $f_label = $this->conn->real_escape_string($field_label);
        $f_type = $this->conn->real_escape_string($field_type ?? 'text');
        $f_opts = $this->conn->real_escape_string($field_options ?? '');
        $req = isset($is_required) ? 1 : 0;

        $save = $this->conn->query("
            INSERT INTO `custom_extrafields`
            (`tenant_id`, `element_type`, `field_code`, `field_label`, `field_type`, `field_options`, `is_required`, `status`)
            VALUES ('{$tenant_id}', '{$elem}', '{$f_code}', '{$f_label}', '{$f_type}', '{$f_opts}', '{$req}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Added Custom Extrafield", "Custom Fields", "Target: {$element_type}, Code: {$field_code}");
            return json_encode(['status' => 'success', 'id' => $this->conn->insert_id, 'msg' => 'Custom extrafield created successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save custom extrafield. ' . $this->conn->error]);
    }

    public function delete_extrafield()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `custom_extrafields` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Custom Extrafield", "Custom Fields", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Custom extrafield deleted.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete custom extrafield.']);
    }

}
