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

function settings_start_server(string $repoRoot): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', '/tmp/pe-work-settings-server.log', 'a'],
        2 => ['file', '/tmp/pe-work-settings-server.log', 'a'],
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            continue;
        }

        $serverAddress = stream_socket_get_name($socket, false) ?: '127.0.0.1:8099';
        fclose($socket);
        $port = (int) substr(strrchr($serverAddress, ':'), 1);
        $baseUrl = 'http://127.0.0.1:' . $port;
        $process = proc_open('php -S 127.0.0.1:' . $port . ' router.php', $descriptors, $pipes, $repoRoot);
        if (!is_resource($process)) {
            continue;
        }

        for ($probeAttempt = 0; $probeAttempt < 20; $probeAttempt++) {
            $probe = @file_get_contents($baseUrl . '/settings?tab=inventory');
            if ($probe !== false) {
                return [$process, $pipes, $baseUrl];
            }
            usleep(250000);
        }

        settings_test_cleanup($repoRoot, $GLOBALS['localConfig'], $GLOBALS['localBackup'], $GLOBALS['movedLocalConfig'], $process, $pipes, []);
    }

    return [null, [], null];
}

run_pending_migrations();

$showResult = save_show_record([
    'show_name' => 'Validation Show',
    'theatre_name' => 'Validation Theatre',
    'shop_name' => 'Validation Shop',
    'ld_name' => 'LD',
    'ld_email' => 'ld@example.com',
    'ld_phone' => '111-111-1111',
    'assistant_ld_name' => 'ALD',
    'assistant_ld_email' => 'ald@example.com',
    'assistant_ld_phone' => '222-222-2222',
    'production_electrician_name' => 'PE',
    'production_electrician_email' => 'pe@example.com',
    'production_electrician_phone' => '333-333-3333',
    'shop_manager_name' => 'SM',
    'shop_manager_email' => 'sm@example.com',
    'shop_manager_phone' => '444-444-4444',
    'assistant_shop_manager_name' => 'ASM',
    'assistant_shop_manager_email' => 'asm@example.com',
    'assistant_shop_manager_phone' => '555-555-5555',
], null);
$validationShowId = (int) ($showResult['show']['id'] ?? 0);
create_category('Validation Fixtures');
create_category('Validation Power');
$validationFixtureCategoryId = (int) db()->query("SELECT id FROM inventory_categories WHERE name = 'Validation Fixtures' ORDER BY id DESC LIMIT 1")->fetchColumn();
$validationPowerCategoryId = (int) db()->query("SELECT id FROM inventory_categories WHERE name = 'Validation Power' ORDER BY id DESC LIMIT 1")->fetchColumn();
create_inventory_item(['category_id' => $validationFixtureCategoryId, 'name' => 'Validation Fixture', 'shop_quantity' => 5, 'unit' => 'ea']);
create_inventory_item(['category_id' => $validationPowerCategoryId, 'name' => 'Validation Cable', 'shop_quantity' => 5, 'unit' => 'ea']);
$validationFixtureId = (int) db()->query("SELECT id FROM inventory_items WHERE name = 'Validation Fixture' ORDER BY id DESC LIMIT 1")->fetchColumn();
$validationCableId = (int) db()->query("SELECT id FROM inventory_items WHERE name = 'Validation Cable' ORDER BY id DESC LIMIT 1")->fetchColumn();
$validationRevisionId = create_initial_revision($validationShowId);
save_revision_lines($validationRevisionId, [
    $validationFixtureId => ['rent_quantity' => 1, 'spare_quantity' => 0, 'action' => 'add'],
    $validationCableId => ['rent_quantity' => 1, 'spare_quantity' => 0, 'action' => 'add'],
]);
save_rule([
    'trigger_item_id' => $validationFixtureId,
    'trigger_quantity' => 1,
    'required_item_id' => $validationCableId,
    'required_quantity' => 1,
    'note' => 'Validation cable required',
]);

$csvPath = tempnam(sys_get_temp_dir(), 'pew-settings-import-');
file_put_contents($csvPath, "category,name,shop_quantity,unit,default_note,description\nFixtures,Import Action Item,7,ea,Imported via settings action,Action path\n");

[$process, $pipes, $baseUrl] = settings_start_server($repoRoot);
settings_assert(is_resource($process) && is_string($baseUrl) && $baseUrl !== '', 'Expected local PHP server to start before running import requests.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, [$csvPath]);

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
$createFolderHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-folder-headers-');
$createFolderResponsePath = tempnam(sys_get_temp_dir(), 'pew-resource-folder-response-');
$testPaths[] = $createFolderHeadersPath;
$testPaths[] = $createFolderResponsePath;
$createFolderCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=create_resource_folder' -F 'folder_name=Manuals' %s",
    escapeshellarg($createFolderResponsePath),
    escapeshellarg($createFolderHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($createFolderCommand, $createFolderOutput, $createFolderStatus);
$freshDb = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$resourceFolderId = (int) $freshDb->query("SELECT id FROM resource_folders WHERE name = 'Manuals' ORDER BY id DESC LIMIT 1")->fetchColumn();
$createSubfolderHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-subfolder-headers-');
$createSubfolderResponsePath = tempnam(sys_get_temp_dir(), 'pew-resource-subfolder-response-');
$testPaths[] = $createSubfolderHeadersPath;
$testPaths[] = $createSubfolderResponsePath;
$createSubfolderCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=create_resource_folder' -F 'folder_name=Drafts' -F %s %s",
    escapeshellarg($createSubfolderResponsePath),
    escapeshellarg($createSubfolderHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
    escapeshellarg('parent_folder_id=' . $resourceFolderId),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($createSubfolderCommand, $createSubfolderOutput, $createSubfolderStatus);
$resourceSubfolderId = (int) $freshDb->query("SELECT id FROM resource_folders WHERE name = 'Drafts' ORDER BY id DESC LIMIT 1")->fetchColumn();
$resourceCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F %s -F 'action=upload_resource' -F 'resource_title=Shop Resource' -F 'resource_pdf=@%s;type=application/pdf;filename=resource.pdf' %s",
    escapeshellarg($resourceResponsePath),
    escapeshellarg($resourceHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
    escapeshellarg('folder_id=' . $resourceSubfolderId),
    escapeshellarg($pdfPath),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($resourceCommand, $resourceOutput, $resourceStatus);
$resourceHeaders = is_file($resourceHeadersPath) ? file_get_contents($resourceHeadersPath) : '';
$resourceBody = is_file($resourceResponsePath) ? file_get_contents($resourceResponsePath) : '';
$resourceStmt = $freshDb->prepare('SELECT * FROM resources WHERE title = ? ORDER BY id DESC LIMIT 1');
$resourceStmt->execute(['Shop Resource']);
$resourceRow = $resourceStmt->fetch() ?: [];
$storedName = (string) ($resourceRow['stored_name'] ?? '');
if ($storedName !== '') {
    $testPaths[] = upload_dir('resources') . '/' . $storedName;
}

settings_assert($createFolderStatus === 0, 'Expected resource folder creation request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($createSubfolderStatus === 0, 'Expected resource subfolder creation request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceStatus === 0, 'Expected resource upload curl request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourcePageStatus === 0 && $resourceCsrfToken !== '', 'Expected resources page request to provide a CSRF token.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceFolderId > 0, 'Expected resource folder creation to persist a folder row.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceSubfolderId > 0, 'Expected resource subfolder creation to persist a folder row.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceHeaders, 'Location: /settings?tab=resources'), 'Expected resource upload action to redirect back to the resources tab.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($storedName !== '', 'Expected resource upload action to persist the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert((int) ($resourceRow['folder_id'] ?? 0) === $resourceSubfolderId, 'Expected uploaded resource to be saved into the selected subfolder.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceBody, 'Resource uploaded.'), 'Expected redirected resources page to show the upload success message.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

$resourceFetchHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-fetch-headers-');
$resourceFetchPath = tempnam(sys_get_temp_dir(), 'pew-resource-fetch-');
$resourceDownloadHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-download-headers-');
$resourceDownloadPath = tempnam(sys_get_temp_dir(), 'pew-resource-download-');
$resourceForbiddenHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-forbidden-headers-');
$resourceForbiddenPath = tempnam(sys_get_temp_dir(), 'pew-resource-forbidden-');
$resourceInvalidHeadersPath = tempnam(sys_get_temp_dir(), 'pew-resource-invalid-headers-');
$resourceInvalidPath = tempnam(sys_get_temp_dir(), 'pew-resource-invalid-');
$testPaths[] = $resourceFetchHeadersPath;
$testPaths[] = $resourceFetchPath;
$testPaths[] = $resourceDownloadHeadersPath;
$testPaths[] = $resourceDownloadPath;
$testPaths[] = $resourceForbiddenHeadersPath;
$testPaths[] = $resourceForbiddenPath;
$testPaths[] = $resourceInvalidHeadersPath;
$testPaths[] = $resourceInvalidPath;
$resourceUrl = $baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0) . '&token=' . rawurlencode(resource_access_token($resourceRow));
exec(sprintf(
    "curl -fsS -o %s -D %s %s",
    escapeshellarg($resourceFetchPath),
    escapeshellarg($resourceFetchHeadersPath),
    escapeshellarg($resourceUrl)
), $resourceFetchOutput, $resourceFetchStatus);
exec(sprintf(
    "curl -fsS -o %s -D %s %s",
    escapeshellarg($resourceDownloadPath),
    escapeshellarg($resourceDownloadHeadersPath),
    escapeshellarg($resourceUrl . '&download=1')
), $resourceDownloadOutput, $resourceDownloadStatus);
exec(sprintf(
    "curl -sS -o %s -D %s %s",
    escapeshellarg($resourceForbiddenPath),
    escapeshellarg($resourceForbiddenHeadersPath),
    escapeshellarg($baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0))
), $resourceForbiddenOutput, $resourceForbiddenStatus);
exec(sprintf(
    "curl -sS -o %s -D %s %s",
    escapeshellarg($resourceInvalidPath),
    escapeshellarg($resourceInvalidHeadersPath),
    escapeshellarg($baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0) . '&token=invalid-token')
), $resourceInvalidOutput, $resourceInvalidStatus);
$resourceFetchHeaders = is_file($resourceFetchHeadersPath) ? file_get_contents($resourceFetchHeadersPath) : '';
$resourceDownloadHeaders = is_file($resourceDownloadHeadersPath) ? file_get_contents($resourceDownloadHeadersPath) : '';
$resourceForbiddenHeaders = is_file($resourceForbiddenHeadersPath) ? file_get_contents($resourceForbiddenHeadersPath) : '';
$resourceInvalidHeaders = is_file($resourceInvalidHeadersPath) ? file_get_contents($resourceInvalidHeadersPath) : '';
$resourceFetchBody = is_file($resourceFetchPath) ? file_get_contents($resourceFetchPath) : '';
$resourceDownloadBody = is_file($resourceDownloadPath) ? file_get_contents($resourceDownloadPath) : '';

settings_assert($resourceFetchStatus === 0, 'Expected signed resource URL to be fetchable.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceFetchHeaders, 'Content-Type: application/pdf'), 'Expected signed resource URL to return a PDF response.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_starts_with($resourceFetchBody, '%PDF-'), 'Expected signed resource URL to stream the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceDownloadStatus === 0, 'Expected signed resource download URL to be fetchable.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceDownloadHeaders, 'Content-Disposition: attachment;'), 'Expected download mode to force attachment disposition.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_starts_with($resourceDownloadBody, '%PDF-'), 'Expected download mode to stream the uploaded PDF.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceForbiddenStatus === 0, 'Expected unsigned resource request to complete.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceForbiddenHeaders, '403 Forbidden'), 'Expected missing token resource request to be rejected.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($resourceInvalidStatus === 0, 'Expected invalid-token resource request to complete.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceInvalidHeaders, '403 Forbidden'), 'Expected invalid token resource request to be rejected.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);

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

$validationPagePath = tempnam(sys_get_temp_dir(), 'pew-validation-page-');
$validationHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-headers-');
$validationResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-response-');
$validationInvalidHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-invalid-headers-');
$validationInvalidResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-invalid-response-');
$validationPersistedHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-persisted-headers-');
$validationPersistedResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-persisted-response-');
$testPaths[] = $validationPagePath;
$testPaths[] = $validationHeadersPath;
$testPaths[] = $validationResponsePath;
$testPaths[] = $validationInvalidHeadersPath;
$testPaths[] = $validationInvalidResponsePath;
$testPaths[] = $validationPersistedHeadersPath;
$testPaths[] = $validationPersistedResponsePath;
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($validationPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $validationPageOutput, $validationPageStatus);
$validationPageHtml = is_file($validationPagePath) ? file_get_contents($validationPagePath) : '';
preg_match('/name=\"csrf_token\" value=\"([^\"]+)\"/', $validationPageHtml, $validationTokenMatch);
$validationCsrfToken = html_entity_decode($validationTokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($validationResponsePath),
    escapeshellarg($validationHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=validate_revision'),
    escapeshellarg('revision_id=' . $validationRevisionId),
    escapeshellarg('items[' . $validationFixtureId . '][rent_quantity]=3'),
    escapeshellarg('items[' . $validationFixtureId . '][spare_quantity]=4'),
    escapeshellarg('items[' . $validationCableId . '][rent_quantity]=0'),
    escapeshellarg('items[' . $validationCableId . '][spare_quantity]=0'),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $validationOutput, $validationStatus);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($validationInvalidResponsePath),
    escapeshellarg($validationInvalidHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=validate_revision'),
    escapeshellarg('revision_id=999999'),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $validationInvalidOutput, $validationInvalidStatus);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($validationPersistedResponsePath),
    escapeshellarg($validationPersistedHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=validate_revision'),
    escapeshellarg('revision_id=' . $validationRevisionId),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $validationPersistedOutput, $validationPersistedStatus);
$validationHeaders = is_file($validationHeadersPath) ? file_get_contents($validationHeadersPath) : '';
$validationBody = is_file($validationResponsePath) ? file_get_contents($validationResponsePath) : '';
$validationInvalidHeaders = is_file($validationInvalidHeadersPath) ? file_get_contents($validationInvalidHeadersPath) : '';
$validationInvalidBody = is_file($validationInvalidResponsePath) ? file_get_contents($validationInvalidResponsePath) : '';
$validationPersistedHeaders = is_file($validationPersistedHeadersPath) ? file_get_contents($validationPersistedHeadersPath) : '';
$validationPersistedBody = is_file($validationPersistedResponsePath) ? file_get_contents($validationPersistedResponsePath) : '';

settings_assert($validationPageStatus === 0 && $validationCsrfToken !== '', 'Expected validation edit page request to provide a CSRF token.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($validationStatus === 0, 'Expected validation endpoint request to succeed.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationHeaders, 'Content-Type: application/json'), 'Expected validation endpoint to return JSON.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationBody, 'exceeds shop stock'), 'Expected validation endpoint to report stock warnings.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationBody, 'Rule required:'), 'Expected validation endpoint to report rule warnings.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($validationInvalidStatus === 0, 'Expected invalid validation endpoint request to complete.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationInvalidHeaders, '404 Not Found'), 'Expected invalid validation revision lookup to return 404.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationInvalidBody, 'Revision not found for this show.'), 'Expected invalid validation revision lookup to return a JSON warning message.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert($validationPersistedStatus === 0, 'Expected persisted-state validation endpoint request to complete.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(str_contains($validationPersistedHeaders, 'Content-Type: application/json'), 'Expected persisted-state validation request to return JSON.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_assert(!str_contains($validationPersistedBody, 'Rule required:'), 'Expected omitted-items validation to preserve the saved revision state.', $repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
settings_test_cleanup($repoRoot, $localConfig, $localBackup, $movedLocalConfig, $process, $pipes, $testPaths);
echo "settings import action test passed\n";
