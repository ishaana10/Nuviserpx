<?php

declare(strict_types=1);

namespace App\Services;

/** CSV imports, database backup and optimisation. Moved verbatim out of Master. */
class MaintenanceService
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

    public function import_csv(string $entity)
    {
        $f = $_FILES['csv'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return json_encode(['status' => 'failed', 'msg' => 'Choose a CSV file to upload.']);
        }
        if (!in_array(strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            return json_encode(['status' => 'failed', 'msg' => 'The file must be a .csv file. In Excel, use Save As and choose CSV.']);
        }
        if ((int)$f['size'] > \App\Services\ImportService::MAX_BYTES) {
            return json_encode(['status' => 'failed', 'msg' => 'The file is larger than 2 MB. Split it into smaller files.']);
        }
        @set_time_limit(300);
        $dry = !empty($_POST['dry_run']);
        $res = $this->container->make(\App\Services\ImportService::class)->run($entity, (string)file_get_contents($f['tmp_name']), (string)($_POST['mode'] ?? 'skip'), $dry);
        if ($res['fatal']) {
            return json_encode(['status' => 'failed', 'msg' => $res['fatal']]);
        }
        $c = $res['counts'];
        $msg = $dry
            ? "Checked: {$c['new']} new, {$c['update']} to update, {$c['skip']} skipped, {$c['error']} with problems. Nothing has been saved yet."
            : "Imported: {$c['created']} created, {$c['updated']} updated, {$c['skip']} skipped, " . ($c['error'] + $c['failed']) . ' not imported.';
        return json_encode(['status' => 'success', 'msg' => $msg, 'dry_run' => $dry, 'counts' => $c, 'rows' => array_slice($res['rows'], 0, 500), 'truncated' => count($res['rows']) > 500, 'unknown' => $res['unknown']]);
    }

    public function backup_db()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Super Administrator permission required.']);
        }

        $tables = [];
        $res = $this->conn->query("SHOW TABLES");
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }

        $sql_dump = "-- NuvisERPX Database Dump\n-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($tables as $tbl) {
            $res_create = $this->conn->query("SHOW CREATE TABLE `{$tbl}`");
            $row_create = $res_create->fetch_array();
            $sql_dump .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . $row_create[1] . ";\n\n";

            $res_data = $this->conn->query("SELECT * FROM `{$tbl}`");
            while ($row_data = $res_data->fetch_assoc()) {
                $cols = array_keys($row_data);
                $escaped = array_map(function ($val) {
                    if (is_null($val)) return "NULL";
                    return "'" . $this->conn->real_escape_string($val) . "'";
                }, array_values($row_data));

                $sql_dump .= "INSERT INTO `{$tbl}` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $escaped) . ");\n";
            }
            $sql_dump .= "\n";
        }

        $filename = 'backup_nuviserpx_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $sql_dump;
        exit;
    }

    public function optimize_db()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }

        $tables = [];
        $res = $this->conn->query("SHOW TABLES");
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }

        foreach ($tables as $tbl) {
            $this->conn->query("OPTIMIZE TABLE `{$tbl}`");
        }

        $this->settings->log_audit("Optimized Database", "System Settings", "Executed OPTIMIZE TABLE on " . count($tables) . " tables.");
        return json_encode(['status' => 'success', 'msg' => 'All database tables successfully optimized.']);
    }

}
