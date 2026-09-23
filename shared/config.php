<?php

$localConfig = __DIR__ . '/../config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

defined('APP_NAME') || define('APP_NAME', getenv('APP_NAME') ?: 'PE Work');
defined('APP_VERSION') || define('APP_VERSION', '0.1.0');
defined('APP_TIMEZONE') || define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'America/New_York');
defined('APP_BASE_URL') || define('APP_BASE_URL', rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/'));

defined('DB_DRIVER') || define('DB_DRIVER', strtolower((string) (getenv('DB_DRIVER') ?: 'mysql')));
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'pe_work');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('DB_SQLITE_PATH') || define('DB_SQLITE_PATH', __DIR__ . '/../storage/pe-work.sqlite');

defined('SESSION_NAME') || define('SESSION_NAME', 'pe_work_session');
defined('APP_SECRET') || define('APP_SECRET', getenv('APP_SECRET') ?: hash('sha256', __DIR__ . '|' . DB_DRIVER . '|' . DB_NAME . '|' . DB_SQLITE_PATH . '|' . SESSION_NAME));
defined('ALLOW_SQLITE_FOR_TESTS') || define('ALLOW_SQLITE_FOR_TESTS', false);

date_default_timezone_set(APP_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_start();
}
