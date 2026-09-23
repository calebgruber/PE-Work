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
    @unlink(DB_SQLITE_PATH . '-wal');
    @unlink(DB_SQLITE_PATH . '-shm');
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
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
settings_assert($socket !== false, 'Expected to reserve an ephemeral port for the local PHP server.', $repoRoot, $localConfig, $localBackup, null, [], [$csvPath]);
$serverAddress = stream_socket_get_name($socket, false) ?: '127.0.0.1:8099';
fclose($socket);
$port = (int) substr(strrchr($serverAddress, ':'), 1);
$baseUrl = 'http://127.0.0.1:' . $port;
$process = proc_open('php -S 127.0.0.1:' . $port . ' router.php', $descriptors, $pipes, $repoRoot);
$serverReady = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probe = @file_get_contents($baseUrl . '/settings?tab=inventory');
    if ($probe !== false) {
        $serverReady = true;
        break;
    }
    usleep(250000);
}

settings_assert($serverReady, 'Expected local PHP server to start before running import requests.', $repoRoot, $localConfig, $localBackup, $process, $pipes, [$csvPath]);

$cookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-');
$headersPath = tempnam(sys_get_temp_dir(), 'pew-headers-');
$responsePath = tempnam(sys_get_temp_dir(), 'pew-response-');
$command = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F 'action=import_inventory' -F 'inventory_csv=@%s;type=text/csv' 'http://127.0.0.1:8099/settings?tab=inventory'",
    escapeshellarg($responsePath),
    escapeshellarg($headersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($csvPath)
);
$command = str_replace('http://127.0.0.1:8099', $baseUrl, $command);
exec($command, $output, $curlStatus);

$headers = is_file($headersPath) ? file_get_contents($headersPath) : '';
$responseBody = is_file($responsePath) ? file_get_contents($responsePath) : '';
$testPaths = [$csvPath, $cookieJar, $responsePath, $headersPath];

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Import Action Item']);
$quantity = (int) $stmt->fetchColumn();

settings_assert($curlStatus === 0, 'Expected curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($headers, 'Location: /settings?tab=inventory'), 'Expected settings import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($responseBody, 'Import complete'), 'Expected redirected settings page to show the import success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$warningCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-warning-');
$warningHeadersPath = tempnam(sys_get_temp_dir(), 'pew-warning-headers-');
$warningResponsePath = tempnam(sys_get_temp_dir(), 'pew-warning-response-');
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
$warningCommand = str_replace('http://127.0.0.1:8099', $baseUrl, $warningCommand);
exec($warningCommand, $warningOutput, $warningStatus);
$warningHeaders = is_file($warningHeadersPath) ? file_get_contents($warningHeadersPath) : '';
$warningBody = is_file($warningResponsePath) ? file_get_contents($warningResponsePath) : '';

settings_assert($warningStatus === 0, 'Expected warning-path curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($warningHeaders, 'Location: /settings?tab=inventory'), 'Expected warning-path import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($warningBody, 'Choose a CSV file to import.'), 'Expected redirected settings page to show the missing-file warning.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$pasteCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-paste-');
$pasteHeadersPath = tempnam(sys_get_temp_dir(), 'pew-paste-headers-');
$pasteResponsePath = tempnam(sys_get_temp_dir(), 'pew-paste-response-');
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
$pasteCommand = str_replace('http://127.0.0.1:8099', $baseUrl, $pasteCommand);
exec($pasteCommand, $pasteOutput, $pasteStatus);
$pasteHeaders = is_file($pasteHeadersPath) ? file_get_contents($pasteHeadersPath) : '';
$pasteBody = is_file($pasteResponsePath) ? file_get_contents($pasteResponsePath) : '';
$stmt->execute(['Pasted Item']);
$pastedQuantity = (int) $stmt->fetchColumn();

settings_assert($pasteStatus === 0, 'Expected pasted import curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteHeaders, 'Location: /settings?tab=inventory'), 'Expected pasted import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($pastedQuantity === 9, 'Expected pasted import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteBody, 'Import complete'), 'Expected redirected settings page to show the pasted import success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$pdfPath = tempnam(sys_get_temp_dir(), 'pew-resource-pdf-');
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
$resourceCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-resource-');
$resourceHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-headers-');
$resourceResponsePath = tempnam(sys_get_temp_dir(), 'pew-resource-response-');
$testPaths[] = $pdfPath;
$testPaths[] = $resourceCookieJar;
$testPaths[] = $resourceHeadersPath;
$testPaths[] = $resourceResponsePath;
$resourceCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F 'action=upload_resource' -F 'resource_title=Shop Resource' -F 'resource_pdf=@%s;type=application/pdf;filename=resource.pdf' %s",
    escapeshellarg($resourceResponsePath),
    escapeshellarg($resourceHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($pdfPath),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($resourceCommand, $resourceOutput, $resourceStatus);
$resourceHeaders = is_file($resourceHeadersPath) ? file_get_contents($resourceHeadersPath) : '';
$resourceBody = is_file($resourceResponsePath) ? file_get_contents($resourceResponsePath) : '';
$freshDb = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$resourceStmt = $freshDb->prepare('SELECT stored_name FROM resources WHERE title = ? ORDER BY id DESC LIMIT 1');
$resourceStmt->execute(['Shop Resource']);
$storedName = (string) $resourceStmt->fetchColumn();
if ($storedName !== '') {
    $testPaths[] = $repoRoot . '/storage/uploads/resources/' . $storedName;
}

settings_assert($resourceStatus === 0, 'Expected resource upload curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceHeaders, 'Location: /settings?tab=resources'), 'Expected resource upload action to redirect back to the resources tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($storedName !== '', 'Expected resource upload action to persist the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceBody, 'Resource uploaded.'), 'Expected redirected resources page to show the upload success message.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

$textPath = tempnam(sys_get_temp_dir(), 'pew-resource-text-');
file_put_contents($textPath, "not a pdf");
$badResourceCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-bad-resource-');
$badResourceHeadersPath = tempnam(sys_get_temp_dir(), 'pew-bad-resource-headers-');
$badResourceResponsePath = tempnam(sys_get_temp_dir(), 'pew-bad-resource-response-');
$testPaths[] = $textPath;
$testPaths[] = $badResourceCookieJar;
$testPaths[] = $badResourceHeadersPath;
$testPaths[] = $badResourceResponsePath;
$badResourceCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F 'action=upload_resource' -F 'resource_title=Bad Resource' -F 'resource_pdf=@%s;type=text/plain;filename=resource.txt' %s",
    escapeshellarg($badResourceResponsePath),
    escapeshellarg($badResourceHeadersPath),
    escapeshellarg($badResourceCookieJar),
    escapeshellarg($badResourceCookieJar),
    escapeshellarg($textPath),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($badResourceCommand, $badResourceOutput, $badResourceStatus);
$badResourceHeaders = is_file($badResourceHeadersPath) ? file_get_contents($badResourceHeadersPath) : '';
$badResourceBody = is_file($badResourceResponsePath) ? file_get_contents($badResourceResponsePath) : '';
$resourceStmt->execute(['Bad Resource']);
$badStoredName = $resourceStmt->fetchColumn();

settings_assert($badResourceStatus === 0, 'Expected invalid resource upload curl request to succeed.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceHeaders, 'Location: /settings?tab=resources'), 'Expected invalid resource upload action to redirect back to the resources tab.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert($badStoredName === false, 'Expected invalid resource upload to avoid persisting a resource row.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceBody, 'Only PDF resources are supported.'), 'Expected redirected resources page to show the non-PDF warning.', $repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);

settings_test_cleanup($repoRoot, $localConfig, $localBackup, $process, $pipes, $testPaths);
echo "settings import action test passed\n";
