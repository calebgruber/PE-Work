<?php

$localConfig = __DIR__ . '/../config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

function app_secret_value(): string
{
    $configured = getenv('APP_SECRET');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    $secretPath = __DIR__ . '/../storage/.app_secret';

    if (is_file($secretPath)) {
        $value = trim((string) @file_get_contents($secretPath));
        if ($value !== '') {
            return $value;
        }
    }

    try {
        $secret = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $secret = hash('sha256', uniqid((string) mt_rand(), true));
    }

    $directory = dirname($secretPath);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    if (is_dir($directory) && @file_put_contents($secretPath, $secret, LOCK_EX) !== false) {
        return $secret;
    }

    return $secret;
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
defined('RESOURCE_STORAGE_PATH') || define('RESOURCE_STORAGE_PATH', rtrim((string) (getenv('RESOURCE_STORAGE_PATH') ?: (sys_get_temp_dir() . '/pe-work-storage')), '/'));

defined('SESSION_NAME') || define('SESSION_NAME', 'pe_work_session');
defined('APP_SECRET') || define('APP_SECRET', app_secret_value());
defined('ALLOW_SQLITE_FOR_TESTS') || define('ALLOW_SQLITE_FOR_TESTS', false);

date_default_timezone_set(APP_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $httpsEnabled = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $httpsEnabled,
        'samesite' => 'Lax',
    ]);
    session_name(SESSION_NAME);
    session_start();
}
