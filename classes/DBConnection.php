<?php
if(!defined('DB_SERVER')){
    require_once("../initialize.php");
}
class DBConnection{

    private $host = DB_SERVER;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;
    
    public $conn;
    
    public function __construct(){

        if (!isset($this->conn)) {
            // Disable default mysqli exception throwing to handle errors gracefully
            if (function_exists('mysqli_report')) {
                mysqli_report(MYSQLI_REPORT_OFF);
            }

            // Attempt connection to host and database
            @$this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            
            if ($this->conn->connect_error) {
                // The real reason goes to the server log only. Visitors never see the host, user name or error text,
                // and a normal request never creates databases (the installer does that, once).
                error_log('[nuviserpx] Database connection failed: ' . $this->conn->connect_error);
                if (!headers_sent()) {
                    http_response_code(503);
                    header('Retry-After: 60');
                }
                die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; border:1px solid #f87171; color:#991b1b; border-radius:8px;'>
                    <h3 style='margin-top:0;'>Service temporarily unavailable</h3>
                    <p>The application could not reach its database. Please try again in a few minutes. If this continues, contact your administrator.</p>
                </div>");
            }

            // MySQL's default sql_mode rejects queries MariaDB accepts (ONLY_FULL_GROUP_BY): relax just that.
            if ($this->conn && !$this->conn->connect_error) {
                \App\Database\SqlMode::apply($this->conn);
                // Database timestamps (current_timestamp()) follow the app's
                // timezone, not the database server's, so logs and documents
                // carry local time wherever the server is hosted.
                @$this->conn->query("SET time_zone = '" . date('P') . "'");
            }

            if (getenv('AUTO_MIGRATE') === '0') {
                // Production mode: a request never changes the schema. Updates are applied by `php bin/migrate.php`
                // (bin/deploy.sh does it). One cheap query makes sure the code and the database belong together.
                $this->assertSchemaCurrent();
            } else {
                // Development and older installs: bring the schema up to date on connect, as before, but a failing
                // migration is now logged and left unrecorded instead of being marked as done.
                $check_tbl = @$this->conn->query("SHOW TABLES LIKE 'system_info'");
                if (!$check_tbl || $check_tbl->num_rows == 0) {
                    $this->auto_install_schema();
                } else {
                    (new \App\Database\LegacySchemaCatchup($this->conn))->run();
                }
                try {
                    (new \App\Database\MigrationRunner($this->conn))->run(base_app . 'database/migrations');
                } catch (\App\Database\MigrationFailed $e) {
                    // Already written to the error log by the runner; serve the request, retry on the next one.
                }
            }
        }    
        
    }

    /** Stops the request with a 503 when the database is behind the code (migrations not yet applied). */
    private function assertSchemaCurrent(): void
    {
        $dir = base_app . 'database/migrations';
        $latest = \App\Database\MigrationRunner::latestFile($dir);
        $done = (new \App\Database\MigrationRunner($this->conn))->latestApplied();
        if ($latest !== null && ($done === null || strcmp($done, $latest) < 0)) {
            error_log("[nuviserpx] Database is behind the code (applied: " . ($done ?? 'none') . ", needed: {$latest}). Run: php bin/migrate.php");
            if (!headers_sent()) {
                http_response_code(503);
                header('Retry-After: 120');
            }
            die("<div style='padding:20px; font-family:sans-serif; background:#fef3c7; border:1px solid #f59e0b; color:#92400e; border-radius:8px;'>
                <h3 style='margin-top:0;'>Update in progress</h3>
                <p>The system is being updated. Please try again in a few minutes.</p>
            </div>");
        }
    }

    /** Kept for compatibility: the implementation lives in LegacySchemaCatchup. */
    public static function account_type_sql_list(array $groups){
        return \App\Database\LegacySchemaCatchup::account_type_sql_list($groups);
    }

    public function auto_install_schema(){
        $sql_file = base_app . 'database/nuviserpx.sql';
        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            if (!empty($sql_content)) {
                if (@$this->conn->multi_query($sql_content)) {
                    do {
                        if ($result = $this->conn->store_result()) {
                            $result->free();
                        }
                    } while ($this->conn->more_results() && $this->conn->next_result());
                } else {
                    // Fallback to split execution if multi_query is restricted
                    $queries = preg_split("/;[\r\n]+/", $sql_content);
                    foreach ($queries as $query) {
                        $query = trim($query);
                        if (!empty($query)) {
                            @$this->conn->query($query);
                        }
                    }
                }
            }
        }
    }

    public function __destruct(){
        if (isset($this->conn) && $this->conn instanceof mysqli) {
            @$this->conn->close();
        }
    }
}
?>