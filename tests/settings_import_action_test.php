<?php

$repoRoot = dirname(__DIR__);
$localConfig = $repoRoot . '/config.local.php';
$localBackup = $repoRoot . '/config.local.php.test-backup-' . uniqid('', true);
$movedLocalConfig = false;
if (file_exists($localConfig)) {
    $movedLocalConfig = rename($localConfig, $localBackup);
    if (!$movedLocalConfig) {
        fwrite(STDERR, "Unable to isolate config.local.php for settings_import_action_test.\n");
        exit(1);
    }
}

$testDbPath = '/tmp/pe-work-settings-test-' . uniqid('', true) . '.sqlite';
file_put_contents(
    $localConfig,
    "<?php\n"
    . "define('DB_DRIVER', 'sqlite');\n"
    . "define('DB_SQLITE_PATH', '" . addslashes($testDbPath) . "');\n"
    . "define('APP_BASE_URL', '/');\n"
    . "define('ALLOW_SQLITE_FOR_TESTS', true);\n"
    . "define('ALLOW_LOCAL_UPLOADS_FOR_TESTS', true);\n"
);

require_once $repoRoot . '/shared/config.php';
require_once $repoRoot . '/shared/db.php';
require_once $repoRoot . '/shared/app.php';

function settings_test_cleanup(string $repoRoot, string $localConfig, string $localBackup, bool $movedLocalConfig, $process, array $pipes, array $paths): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (is_resource($process)) {
        proc_terminate($process);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        proc_close($process);
    }

    foreach ($paths as $path) {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
    if (!$movedLocalConfig && file_exists($localConfig)) {
        unlink($localConfig);
    }
    @unlink(DB_SQLITE_PATH);
    @unlink(DB_SQLITE_PATH . '-wal');
    @unlink(DB_SQLITE_PATH . '-shm');
    if ($movedLocalConfig && file_exists($localBackup)) {
        rename($localBackup, $localConfig);
    }
}

function settings_assert(bool $condition, string $message, string $repoRoot, string $localConfig, string $localBackup, bool $movedLocalConfig, $process, array $pipes, array $paths): void
{
    if (!$condition) {
        settings_test_cleanup($repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $paths);
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
settings_assert($socket !== false, 'Expected to reserve an ephemeral port for the local PHP server.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, null, [], [$csvPath]);
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

settings_assert($serverReady, 'Expected local PHP server to start before running import requests.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, [$csvPath]);

$cookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-');
$inventoryPagePath = tempnam(sys_get_temp_dir(), 'pew-inventory-page-');
$headersPath = tempnam(sys_get_temp_dir(), 'pew-headers-');
$responsePath = tempnam(sys_get_temp_dir(), 'pew-response-');
$inventoryPageCommand = sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($inventoryPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/settings?tab=inventory')
);
exec($inventoryPageCommand, $inventoryPageOutput, $inventoryPageStatus);
$inventoryPageHtml = is_file($inventoryPagePath) ? file_get_contents($inventoryPagePath) : '';
preg_match('/name=\"csrf_token\" value=\"([^\"]+)\"/', $inventoryPageHtml, $inventoryTokenMatch);
$csrfToken = html_entity_decode($inventoryTokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
$command = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=import_inventory' -F 'inventory_csv=@%s;type=text/csv' %s",
    escapeshellarg($responsePath),
    escapeshellarg($headersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $csrfToken),
    escapeshellarg($csvPath),
    escapeshellarg($baseUrl . '/settings?tab=inventory')
);
exec($command, $output, $curlStatus);

$headers = is_file($headersPath) ? file_get_contents($headersPath) : '';
$responseBody = is_file($responsePath) ? file_get_contents($responsePath) : '';
$testPaths = [$csvPath, $cookieJar, $inventoryPagePath, $responsePath, $headersPath];

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Import Action Item']);
$quantity = (int) $stmt->fetchColumn();

settings_assert($inventoryPageStatus === 0 && $csrfToken !== '', 'Expected inventory page request to provide a CSRF token.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($curlStatus === 0, 'Expected curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($headers, 'Location: /settings?tab=inventory'), 'Expected settings import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($responseBody, 'Import complete'), 'Expected redirected settings page to show the import success message.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$warningHeadersPath = tempnam(sys_get_temp_dir(), 'pew-warning-headers-');
$warningResponsePath = tempnam(sys_get_temp_dir(), 'pew-warning-response-');
$testPaths[] = $warningHeadersPath;
$testPaths[] = $warningResponsePath;
$warningCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=import_inventory' %s",
    escapeshellarg($warningResponsePath),
    escapeshellarg($warningHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $csrfToken),
    escapeshellarg($baseUrl . '/settings?tab=inventory')
);
exec($warningCommand, $warningOutput, $warningStatus);
$warningHeaders = is_file($warningHeadersPath) ? file_get_contents($warningHeadersPath) : '';
$warningBody = is_file($warningResponsePath) ? file_get_contents($warningResponsePath) : '';

settings_assert($warningStatus === 0, 'Expected warning-path curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($warningHeaders, 'Location: /settings?tab=inventory'), 'Expected warning-path import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($warningBody, 'Choose a CSV file to import.'), 'Expected redirected settings page to show the missing-file warning.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$pasteHeadersPath = tempnam(sys_get_temp_dir(), 'pew-paste-headers-');
$pasteResponsePath = tempnam(sys_get_temp_dir(), 'pew-paste-response-');
$testPaths[] = $pasteHeadersPath;
$testPaths[] = $pasteResponsePath;
$pastePayload = "category,name,shop_quantity,unit,default_note,description\nFIXTURES,Pasted Item,9,ea,.,.\n";
$pasteCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($pasteResponsePath),
    escapeshellarg($pasteHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $csrfToken),
    escapeshellarg('action=import_inventory'),
    escapeshellarg('inventory_csv_text=' . $pastePayload),
    escapeshellarg($baseUrl . '/settings?tab=inventory')
);
exec($pasteCommand, $pasteOutput, $pasteStatus);
$pasteHeaders = is_file($pasteHeadersPath) ? file_get_contents($pasteHeadersPath) : '';
$pasteBody = is_file($pasteResponsePath) ? file_get_contents($pasteResponsePath) : '';
$stmt->execute(['Pasted Item']);
$pastedQuantity = (int) $stmt->fetchColumn();

settings_assert($pasteStatus === 0, 'Expected pasted import curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteHeaders, 'Location: /settings?tab=inventory'), 'Expected pasted import action to redirect back to the inventory tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($pastedQuantity === 9, 'Expected pasted import action to create the inventory item.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteBody, 'Import complete'), 'Expected redirected settings page to show the pasted import success message.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$pdfPath = tempnam(sys_get_temp_dir(), 'pew-resource-pdf-');
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
$resourceCookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-resource-');
$resourcePagePath = tempnam(sys_get_temp_dir(), 'pew-resource-page-');
$resourceHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-headers-');
$resourceResponsePath = tempnam(sys_get_temp_dir(), 'pew-resource-response-');
$testPaths[] = $pdfPath;
$testPaths[] = $resourceCookieJar;
$testPaths[] = $resourcePagePath;
$testPaths[] = $resourceHeadersPath;
$testPaths[] = $resourceResponsePath;
$resourcePageCommand = sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($resourcePagePath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($resourcePageCommand, $resourcePageOutput, $resourcePageStatus);
$resourcePageHtml = is_file($resourcePagePath) ? file_get_contents($resourcePagePath) : '';
preg_match('/name=\"csrf_token\" value=\"([^\"]+)\"/', $resourcePageHtml, $resourceTokenMatch);
$resourceCsrfToken = html_entity_decode($resourceTokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
$resourceCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=upload_resource' -F 'resource_title=Shop Resource' -F 'resource_pdf=@%s;type=application/pdf;filename=resource.pdf' %s",
    escapeshellarg($resourceResponsePath),
    escapeshellarg($resourceHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
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
$resourceStmt = $freshDb->prepare('SELECT * FROM resources WHERE title = ? ORDER BY id DESC LIMIT 1');
$resourceStmt->execute(['Shop Resource']);
$resourceRow = $resourceStmt->fetch() ?: [];
$storedName = (string) ($resourceRow['stored_name'] ?? '');
if ($storedName !== '') {
    $testPaths[] = $repoRoot . '/storage/uploads/resources/' . $storedName;
}

settings_assert($resourceStatus === 0, 'Expected resource upload curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourcePageStatus === 0 && $resourceCsrfToken !== '', 'Expected resources page request to provide a CSRF token.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceHeaders, 'Location: /settings?tab=resources'), 'Expected resource upload action to redirect back to the resources tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($storedName !== '', 'Expected resource upload action to persist the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceBody, 'Resource uploaded.'), 'Expected redirected resources page to show the upload success message.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$resourceFetchHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-fetch-headers-');
$resourceFetchPath = tempnam(sys_get_temp_dir(), 'pew-resource-fetch-');
$resourceForbiddenHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-forbidden-headers-');
$resourceForbiddenPath = tempnam(sys_get_temp_dir(), 'pew-resource-forbidden-');
$testPaths[] = $resourceFetchHeadersPath;
$testPaths[] = $resourceFetchPath;
$testPaths[] = $resourceForbiddenHeadersPath;
$testPaths[] = $resourceForbiddenPath;
$resourceUrl = $baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0) . '&token=' . rawurlencode(resource_access_token($resourceRow));
exec(sprintf(
    "curl -fsS -o %s -D %s %s",
    escapeshellarg($resourceFetchPath),
    escapeshellarg($resourceFetchHeadersPath),
    escapeshellarg($resourceUrl)
), $resourceFetchOutput, $resourceFetchStatus);
exec(sprintf(
    "curl -sS -o %s -D %s %s",
    escapeshellarg($resourceForbiddenPath),
    escapeshellarg($resourceForbiddenHeadersPath),
    escapeshellarg($baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0))
), $resourceForbiddenOutput, $resourceForbiddenStatus);
$resourceFetchHeaders = is_file($resourceFetchHeadersPath) ? file_get_contents($resourceFetchHeadersPath) : '';
$resourceForbiddenHeaders = is_file($resourceForbiddenHeadersPath) ? file_get_contents($resourceForbiddenHeadersPath) : '';
$resourceFetchBody = is_file($resourceFetchPath) ? file_get_contents($resourceFetchPath) : '';

settings_assert($resourceFetchStatus === 0, 'Expected signed resource URL to be fetchable.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceFetchHeaders, 'Content-Type: application/pdf'), 'Expected signed resource URL to return a PDF response.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_starts_with($resourceFetchBody, '%PDF-'), 'Expected signed resource URL to stream the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceForbiddenStatus === 0, 'Expected unsigned resource request to complete.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceForbiddenHeaders, '403 Forbidden'), 'Expected missing token resource request to be rejected.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$textPath = tempnam(sys_get_temp_dir(), 'pew-resource-text-');
file_put_contents($textPath, "not a pdf");
$badResourceHeadersPath = tempnam(sys_get_temp_dir(), 'pew-bad-resource-headers-');
$badResourceResponsePath = tempnam(sys_get_temp_dir(), 'pew-bad-resource-response-');
$testPaths[] = $textPath;
$testPaths[] = $badResourceHeadersPath;
$testPaths[] = $badResourceResponsePath;
$badResourceCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=upload_resource' -F 'resource_title=Bad Resource' -F 'resource_pdf=@%s;type=text/plain;filename=resource.txt' %s",
    escapeshellarg($badResourceResponsePath),
    escapeshellarg($badResourceHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
    escapeshellarg($textPath),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($badResourceCommand, $badResourceOutput, $badResourceStatus);
$badResourceHeaders = is_file($badResourceHeadersPath) ? file_get_contents($badResourceHeadersPath) : '';
$badResourceBody = is_file($badResourceResponsePath) ? file_get_contents($badResourceResponsePath) : '';
$resourceStmt->execute(['Bad Resource']);
$badStoredName = $resourceStmt->fetchColumn();

settings_assert($badResourceStatus === 0, 'Expected invalid resource upload curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceHeaders, 'Location: /settings?tab=resources'), 'Expected invalid resource upload action to redirect back to the resources tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($badStoredName === false, 'Expected invalid resource upload to avoid persisting a resource row.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceBody, 'Only PDF resources are supported.'), 'Expected redirected resources page to show the non-PDF warning.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

settings_test_cleanup($repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
echo "settings import action test passed\n";
