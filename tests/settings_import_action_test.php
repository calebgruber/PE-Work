<?php

$repoRoot = dirname(__DIR__);
$localConfig = $repoRoot . '/config.local.php';
$localBackup = $repoRoot . '/config.local.php.test-backup';
if (file_exists($localConfig)) {
    rename($localConfig, $localBackup);
}

$testDbPath = '/tmp/pe-work-settings-test-' . uniqid('', true) . '.sqlite';
file_put_contents(
    $localConfig,
    "<?php\n"
    . "define('DB_DRIVER', 'sqlite');\n"
    . "define('DB_SQLITE_PATH', '" . addslashes($testDbPath) . "');\n"
    . "define('APP_BASE_URL', '/');\n"
);

require_once $repoRoot . '/shared/config.php';
require_once $repoRoot . '/shared/db.php';
require_once $repoRoot . '/shared/app.php';

function settings_test_cleanup(string $repoRoot, string $localConfig, string $localBackup, $process, array $pipes, string $csvPath): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    @unlink($csvPath);
    if (file_exists($localConfig)) {
        unlink($localConfig);
    }
    @unlink(DB_SQLITE_PATH);
    if (file_exists($localBackup)) {
        rename($localBackup, $localConfig);
    }
}

function settings_assert(bool $condition, string $message, string $repoRoot, string $localConfig, string $localBackup, $process, array $pipes, string $csvPath): void
{
    if (!$condition) {
        settings_test_cleanup($repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

run_pending_migrations();

$csvPath = tempnam(sys_get_temp_dir(), 'pew-settings-import-');
file_put_contents($csvPath, "category,name,shop_quantity,unit,default_note,description\nFixtures,Import Action Item,7,ea,Imported via settings action,Action path\n");

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', '/tmp/pe-work-settings-server.log', 'a'],
    2 => ['file', '/tmp/pe-work-settings-server.log', 'a'],
];
$process = proc_open('php -S 127.0.0.1:8099 router.php', $descriptors, $pipes, $repoRoot);
usleep(800000);

$cookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-');
$command = sprintf(
    "curl -isS -o /tmp/pew-settings-response.txt -D /tmp/pew-settings-headers.txt -L -c %s -b %s -F 'action=import_inventory' -F 'inventory_csv=@%s;type=text/csv' 'http://127.0.0.1:8099/settings?tab=inventory'",
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($csvPath)
);
exec($command, $output, $curlStatus);

$headers = is_file('/tmp/pew-settings-headers.txt') ? file_get_contents('/tmp/pew-settings-headers.txt') : '';
$responseBody = is_file('/tmp/pew-settings-response.txt') ? file_get_contents('/tmp/pew-settings-response.txt') : '';

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Import Action Item']);
$quantity = (int) $stmt->fetchColumn();

settings_assert($curlStatus === 0, 'Expected curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);
settings_assert(str_contains($headers, 'Location: /settings?tab=inventory'), 'Expected settings import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);
settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);
settings_assert(str_contains($responseBody, 'Import complete'), 'Expected redirected settings page to show the import success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);

@unlink($cookieJar);
@unlink('/tmp/pew-settings-response.txt');
@unlink('/tmp/pew-settings-headers.txt');

settings_test_cleanup($repoRoot, $localConfig, $localBackup, $process, $pipes, $csvPath);
echo "settings import action test passed\n";
