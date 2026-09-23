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
    . "define('ALLOW_LOCAL_UPLOADS_FOR_TESTS', true);\n"
);

require_once $repoRoot . '/shared/config.php';
require_once $repoRoot . '/shared/db.php';
require_once $repoRoot . '/shared/app.php';

function settings_test_cleanup(string $repoRoot, string $localConfig, string $localBackup, $process, array $pipes, array $paths): void
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

    foreach ($paths as $path) {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
    if (file_exists($localConfig)) {
        unlink($localConfig);
    }
    @unlink(DB_SQLITE_PATH);
    if (file_exists($localBackup)) {
        rename($localBackup, $localConfig);
    }
}

function settings_assert(bool $condition, string $message, string $repoRoot, string $localConfig, string $localBackup, $process, array $pipes, array $paths): void
{
    if (!$condition) {
        settings_test_cleanup($repoRoot, $localConfig, $localBackup, $process, $pipes, $paths);
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
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probe = @file_get_contents('http://127.0.0.1:8099/settings?tab=inventory');
    if ($probe !== false) {
        break;
    }
    usleep(250000);
}

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
$testPaths = [$csvPath, $cookieJar, '/tmp/pew-settings-response.txt', '/tmp/pew-settings-headers.txt'];

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Import Action Item']);
$quantity = (int) $stmt->fetchColumn();

settings_assert($curlStatus === 0, 'Expected curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($headers, 'Location: /settings?tab=inventory'), 'Expected settings import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($responseBody, 'Import complete'), 'Expected redirected settings page to show the import success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$warningCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-warning-');
$warningHeadersPath = '/tmp/pew-settings-warning-headers.txt';
$warningResponsePath = '/tmp/pew-settings-warning-response.txt';
$testPaths[] = $warningCookieJar;
$testPaths[] = $warningHeadersPath;
$testPaths[] = $warningResponsePath;
$warningCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F 'action=import_inventory' 'http://127.0.0.1:8099/settings?tab=inventory'",
    escapeshellarg($warningResponsePath),
    escapeshellarg($warningHeadersPath),
    escapeshellarg($warningCookieJar),
    escapeshellarg($warningCookieJar)
);
exec($warningCommand, $warningOutput, $warningStatus);
$warningHeaders = is_file($warningHeadersPath) ? file_get_contents($warningHeadersPath) : '';
$warningBody = is_file($warningResponsePath) ? file_get_contents($warningResponsePath) : '';

settings_assert($warningStatus === 0, 'Expected warning-path curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($warningHeaders, 'Location: /settings?tab=inventory'), 'Expected warning-path import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($warningBody, 'Choose a CSV file to import.'), 'Expected redirected settings page to show the missing-file warning.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$pasteCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-paste-');
$pasteHeadersPath = '/tmp/pew-settings-paste-headers.txt';
$pasteResponsePath = '/tmp/pew-settings-paste-response.txt';
$testPaths[] = $pasteCookieJar;
$testPaths[] = $pasteHeadersPath;
$testPaths[] = $pasteResponsePath;
$pastePayload = "category,name,shop_quantity,unit,default_note,description\nFIXTURES,Pasted Item,9,ea,.,.\n";
$pasteCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s --data-urlencode %s --data-urlencode %s 'http://127.0.0.1:8099/settings?tab=inventory'",
    escapeshellarg($pasteResponsePath),
    escapeshellarg($pasteHeadersPath),
    escapeshellarg($pasteCookieJar),
    escapeshellarg($pasteCookieJar),
    escapeshellarg('action=import_inventory'),
    escapeshellarg('inventory_csv_text=' . $pastePayload)
);
exec($pasteCommand, $pasteOutput, $pasteStatus);
$pasteHeaders = is_file($pasteHeadersPath) ? file_get_contents($pasteHeadersPath) : '';
$pasteBody = is_file($pasteResponsePath) ? file_get_contents($pasteResponsePath) : '';
$stmt->execute(['Pasted Item']);
$pastedQuantity = (int) $stmt->fetchColumn();

settings_assert($pasteStatus === 0, 'Expected pasted import curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteHeaders, 'Location: /settings?tab=inventory'), 'Expected pasted import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($pastedQuantity === 9, 'Expected pasted import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteBody, 'Import complete'), 'Expected redirected settings page to show the pasted import success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

settings_test_cleanup($repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
echo "settings import action test passed\n";
