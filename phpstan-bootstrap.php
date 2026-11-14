<?php
// Constants that config.php / initialize.php define at runtime, declared here
// so static analysis can resolve them. Not loaded by the application.
if (!defined('base_app'))  define('base_app', __DIR__ . '/');
if (!defined('base_url'))  define('base_url', '/');
