<?php
if(!defined('base_app')) define('base_app', str_replace('\\','/',__DIR__).'/' );

// Redirect to installer if system is not installed and user is not accessing /install/
$lock_file = __DIR__ . '/install/installed.lock';
$env_file = __DIR__ . '/.env';
$uri = $_SERVER['REQUEST_URI'] ?? '';

if ((!file_exists($lock_file) || !file_exists($env_file)) && strpos($uri, '/install/') === false && php_sapi_name() !== 'cli') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    header("Location: " . $protocol . $host . $base . "/install/index.php");
    exit;
}

// Load environment variables from .env if present
if (file_exists(__DIR__ . '/.env')) {
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($env_key, $env_val) = explode('=', $line, 2);
            $env_key = trim($env_key);
            $env_val = trim(trim($env_val), '"\'');
            if (!array_key_exists($env_key, $_SERVER) && !array_key_exists($env_key, $_ENV)) {
                putenv("{$env_key}={$env_val}");
                $_ENV[$env_key] = $env_val;
                $_SERVER[$env_key] = $env_val;
            }
        }
    }
}

$db_server   = getenv('DB_SERVER')   ?: (isset($_ENV['DB_SERVER'])   ? $_ENV['DB_SERVER']   : "localhost");
$db_username = getenv('DB_USERNAME') ?: (isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : "root");
$db_password = getenv('DB_PASSWORD') ?: (isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : "");
$db_name     = getenv('DB_NAME')     ?: (isset($_ENV['DB_NAME'])     ? $_ENV['DB_NAME']     : "nuviserpx");
$env_base_url= getenv('APP_URL')     ?: (getenv('BASE_URL') ?: (isset($_ENV['APP_URL']) ? $_ENV['APP_URL'] : (isset($_ENV['BASE_URL']) ? $_ENV['BASE_URL'] : 'http://localhost/nuviserpx/')));

// Ensure trailing slash on base_url
if (substr($env_base_url, -1) !== '/') {
    $env_base_url .= '/';
}

if(!defined('base_url')) define('base_url', $env_base_url);
if(!defined('DB_SERVER')) define('DB_SERVER', $db_server);
if(!defined('DB_USERNAME')) define('DB_USERNAME', $db_username);
if(!defined('DB_PASSWORD')) define('DB_PASSWORD', $db_password);
if(!defined('DB_NAME')) define('DB_NAME', $db_name);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}
?>