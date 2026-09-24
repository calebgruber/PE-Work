<?php

$repoRoot = dirname(__DIR__);
$testDbPath = '/tmp/pe-work-settings-test-' . uniqid('', true) . '.sqlite';
putenv('PE_WORK_SKIP_LOCAL_CONFIG=1');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $testDbPath);
putenv('APP_BASE_URL=/');
putenv('ALLOW_SQLITE_FOR_TESTS=1');
putenv('ALLOW_LOCAL_UPLOADS_FOR_TESTS=1');

require_once $repoRoot . '/shared/config.php';
require_once $repoRoot . '/shared/db.php';
require_once $repoRoot . '/shared/app.php';

function settings_test_cleanup(string $repoRoot, $process, array $pipes, array $paths, bool $removeDb = true): void
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
    if ($removeDb) {
        @unlink(DB_SQLITE_PATH);
        @unlink(DB_SQLITE_PATH . '-wal');
        @unlink(DB_SQLITE_PATH . '-shm');
    }
}

function settings_assert(bool $condition, string $message, string $repoRoot, $process, array $pipes, array $paths): void
{
    if (!$condition) {
        settings_test_cleanup($repoRoot, $process, $pipes, $paths);
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function settings_start_server(string $repoRoot, bool $disableAuthBypass = false): array
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
        $command = sprintf(
            'PE_WORK_SKIP_LOCAL_CONFIG=1 DB_DRIVER=sqlite DB_SQLITE_PATH=%s APP_BASE_URL=/ ALLOW_SQLITE_FOR_TESTS=1 ALLOW_LOCAL_UPLOADS_FOR_TESTS=1 %s php -S 127.0.0.1:%d router.php',
            escapeshellarg($GLOBALS['testDbPath']),
            $disableAuthBypass ? 'PE_WORK_DISABLE_TEST_AUTH_BYPASS=1' : '',
            $port
        );
        $process = proc_open($command, $descriptors, $pipes, $repoRoot);
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

        settings_test_cleanup($repoRoot, $process, $pipes, []);
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
$validationNextRevisionId = create_next_revision($validationShowId);
save_revision_lines($validationRevisionId, [
    $validationFixtureId => ['rent_quantity' => 1, 'spare_quantity' => 0, 'action' => 'add'],
    $validationCableId => ['rent_quantity' => 1, 'spare_quantity' => 0, 'action' => 'add'],
]);
save_revision_lines($validationNextRevisionId, [
    $validationFixtureId => ['rent_quantity' => 2, 'spare_quantity' => 1, 'action' => 'add', 'line_note' => 'Updated for revision table'],
    $validationCableId => ['rent_quantity' => 2, 'spare_quantity' => 0, 'action' => 'exchange'],
]);
save_rule([
    'trigger_item_id' => $validationFixtureId,
    'trigger_quantity' => 1,
    'required_item_id' => $validationCableId,
    'required_quantity' => 1,
    'note' => 'Validation cable required',
]);

$bootstrapAdminResult = bootstrap_admin_user([
    'display_name' => 'Auth Admin',
    'email' => 'auth-admin@example.com',
    'password' => 'strong-password',
    'password_confirmation' => 'strong-password',
    'concentration' => 'lighting',
]);
settings_assert(($bootstrapAdminResult['ok'] ?? false) === true, 'Expected auth bootstrap admin creation to succeed.', $repoRoot, null, [], []);
$authAdmin = current_user();
$authUserInsert = db()->prepare('
    INSERT INTO users (display_name, email, password_hash, role, concentration, must_change_password, avatar_seed, is_active, created_by_user_id, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');
$restrictedPassword = 'restricted-password';
$authUserInsert->execute([
    'Restricted User',
    'restricted-user@example.com',
    password_hash($restrictedPassword, PASSWORD_DEFAULT),
    'user',
    'sound',
    'restricted-user-seed',
    (int) ($authAdmin['id'] ?? 0),
]);
$_SESSION['user_id'] = (int) ($authAdmin['id'] ?? 0);
$adminShowResult = save_show_record([
    'show_name' => 'Admin Private Show',
    'concentration' => 'lighting',
    'theatre_name' => 'Private Theatre',
    'shop_name' => 'Private Shop',
    'ld_name' => 'Private LD',
    'ld_email' => 'private-ld@example.com',
    'ld_phone' => '600-600-6000',
    'assistant_ld_name' => 'Private ALD',
    'assistant_ld_email' => 'private-ald@example.com',
    'assistant_ld_phone' => '700-700-7000',
    'production_electrician_name' => 'Private PE',
    'production_electrician_email' => 'private-pe@example.com',
    'production_electrician_phone' => '800-800-8000',
    'shop_manager_name' => 'Private SM',
    'shop_manager_email' => 'private-sm@example.com',
    'shop_manager_phone' => '900-900-9000',
    'assistant_shop_manager_name' => 'Private ASM',
    'assistant_shop_manager_email' => 'private-asm@example.com',
    'assistant_shop_manager_phone' => '100-100-1000',
]);
$adminPrivateShowId = (int) ($adminShowResult['show']['id'] ?? 0);
$adminPrivateRevisionId = $adminPrivateShowId > 0 ? create_initial_revision($adminPrivateShowId) : 0;
settings_assert($adminShowResult['errors'] === [] && $adminPrivateShowId > 0 && $adminPrivateRevisionId > 0, 'Expected admin-owned show and revision to be available for auth access tests.', $repoRoot, null, [], []);

$csvPath = tempnam(sys_get_temp_dir(), 'pew-settings-import-');
file_put_contents($csvPath, "category,name,shop_quantity,unit,default_note,description\nFixtures,Import Action Item,7,ea,Imported via settings action,Action path\n");

[$process, $pipes, $baseUrl] = settings_start_server($repoRoot);
settings_assert(is_resource($process) && is_string($baseUrl) && $baseUrl !== '', 'Expected local PHP server to start before running import requests.', $repoRoot, $process, $pipes, [$csvPath]);

$cookieJar = tempnam(sys_get_temp_dir(), 'pew-cookie-');
$inventoryPagePath = tempnam(sys_get_temp_dir(), 'pew-inventory-page-');
$layoutPagePath = tempnam(sys_get_temp_dir(), 'pew-layout-page-');
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
$testPaths = [$csvPath, $cookieJar, $inventoryPagePath, $layoutPagePath, $responsePath, $headersPath];

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Import Action Item']);
$quantity = (int) $stmt->fetchColumn();

settings_assert($inventoryPageStatus === 0 && $csrfToken !== '', 'Expected inventory page request to provide a CSRF token.', $repoRoot, $process, $pipes, $testPaths);
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($layoutPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/settings?tab=layout')
), $layoutPageOutput, $layoutPageStatus);
$layoutPageHtml = is_file($layoutPagePath) ? file_get_contents($layoutPagePath) : '';
settings_assert($curlStatus === 0, 'Expected curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($layoutPageStatus === 0, 'Expected layout settings page request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($layoutPageHtml, 'Revision Summary Layout'), 'Expected layout settings page to group revision summary controls together.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($layoutPageHtml, 'Equipment Breakdown Layout'), 'Expected layout settings page to group equipment controls together.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($layoutPageHtml, 'revision_summary_col_action'), 'Expected layout settings page to include revision summary action width controls.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($layoutPageHtml, 'revision_summary_col_previous_total'), 'Expected layout settings page to include revision summary previous-total width controls.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($layoutPageHtml, 'This minimum takes priority'), 'Expected layout settings page to explain that the configured minimum rows take priority.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($headers, 'Location: /settings?tab=inventory'), 'Expected settings import action to redirect back to the inventory tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($responseBody, 'Import complete'), 'Expected redirected settings page to show the import success message.', $repoRoot, $process, $pipes, $testPaths);

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

settings_assert($warningStatus === 0, 'Expected warning-path curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($warningHeaders, 'Location: /settings?tab=inventory'), 'Expected warning-path import action to redirect back to the inventory tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($warningBody, 'Choose a CSV file to import.'), 'Expected redirected settings page to show the missing-file warning.', $repoRoot, $process, $pipes, $testPaths);

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

settings_assert($pasteStatus === 0, 'Expected pasted import curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteHeaders, 'Location: /settings?tab=inventory'), 'Expected pasted import action to redirect back to the inventory tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($pastedQuantity === 9, 'Expected pasted import action to create the inventory item.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($pasteBody, 'Import complete'), 'Expected redirected settings page to show the pasted import success message.', $repoRoot, $process, $pipes, $testPaths);

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
$freshDb = null;
$freshDb = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$resourceSubfolderId = (int) $freshDb->query("SELECT id FROM resource_folders WHERE name = 'Drafts' AND parent_id = " . $resourceFolderId . " ORDER BY id DESC LIMIT 1")->fetchColumn();
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
$freshDb = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$resourceStmt = $freshDb->prepare('SELECT * FROM resources WHERE title = ? ORDER BY id DESC LIMIT 1');
$resourceStmt->execute(['Shop Resource']);
$resourceRow = $resourceStmt->fetch() ?: [];
$storedName = (string) ($resourceRow['stored_name'] ?? '');
if ($storedName !== '') {
    $testPaths[] = upload_dir('resources') . '/' . $storedName;
}

settings_assert($createFolderStatus === 0, 'Expected resource folder creation request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($createSubfolderStatus === 0, 'Expected resource subfolder creation request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceStatus === 0, 'Expected resource upload curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourcePageStatus === 0 && $resourceCsrfToken !== '', 'Expected resources page request to provide a CSRF token.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceFolderId > 0, 'Expected resource folder creation to persist a folder row.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceSubfolderId > 0, 'Expected resource subfolder creation to persist a folder row.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceHeaders, 'Location: /settings?tab=resources'), 'Expected resource upload action to redirect back to the resources tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($storedName !== '', 'Expected resource upload action to persist the uploaded PDF.', $repoRoot, $process, $pipes, $testPaths);
settings_assert((int) ($resourceRow['folder_id'] ?? 0) === $resourceSubfolderId, 'Expected uploaded resource to be saved into the selected subfolder.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceBody, 'Resource uploaded.'), 'Expected redirected resources page to show the upload success message.', $repoRoot, $process, $pipes, $testPaths);

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
$resourceUrl = $baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0);
$resourceExpiry = resource_access_expires_at();
$resourceHeader = 'X-Resource-Token: ' . resource_access_token($resourceRow, $resourceExpiry);
$resourceExpiryHeader = 'X-Resource-Expires: ' . $resourceExpiry;
exec(sprintf(
    "curl -fsS -H %s -H %s -o %s -D %s %s",
    escapeshellarg($resourceHeader),
    escapeshellarg($resourceExpiryHeader),
    escapeshellarg($resourceFetchPath),
    escapeshellarg($resourceFetchHeadersPath),
    escapeshellarg($resourceUrl)
), $resourceFetchOutput, $resourceFetchStatus);
exec(sprintf(
    "curl -fsS -H %s -H %s -o %s -D %s %s",
    escapeshellarg($resourceHeader),
    escapeshellarg($resourceExpiryHeader),
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
    "curl -sS -H %s -H %s -o %s -D %s %s",
    escapeshellarg('X-Resource-Token: invalid-token'),
    escapeshellarg($resourceExpiryHeader),
    escapeshellarg($resourceInvalidPath),
    escapeshellarg($resourceInvalidHeadersPath),
    escapeshellarg($baseUrl . '/resource_file?id=' . (int) ($resourceRow['id'] ?? 0))
), $resourceInvalidOutput, $resourceInvalidStatus);
$resourceFetchHeaders = is_file($resourceFetchHeadersPath) ? file_get_contents($resourceFetchHeadersPath) : '';
$resourceDownloadHeaders = is_file($resourceDownloadHeadersPath) ? file_get_contents($resourceDownloadHeadersPath) : '';
$resourceForbiddenHeaders = is_file($resourceForbiddenHeadersPath) ? file_get_contents($resourceForbiddenHeadersPath) : '';
$resourceInvalidHeaders = is_file($resourceInvalidHeadersPath) ? file_get_contents($resourceInvalidHeadersPath) : '';
$resourceFetchBody = is_file($resourceFetchPath) ? file_get_contents($resourceFetchPath) : '';
$resourceDownloadBody = is_file($resourceDownloadPath) ? file_get_contents($resourceDownloadPath) : '';

settings_assert($resourceFetchStatus === 0, 'Expected signed resource URL to be fetchable.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceFetchHeaders, 'Content-Type: application/pdf'), 'Expected signed resource URL to return a PDF response.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_starts_with($resourceFetchBody, '%PDF-'), 'Expected signed resource URL to stream the uploaded PDF.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceDownloadStatus === 0, 'Expected signed resource download URL to be fetchable.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceDownloadHeaders, 'Content-Disposition: attachment;'), 'Expected download mode to force attachment disposition.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_starts_with($resourceDownloadBody, '%PDF-'), 'Expected download mode to stream the uploaded PDF.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceForbiddenStatus === 0, 'Expected unsigned resource request to complete.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceForbiddenHeaders, '403 Forbidden'), 'Expected missing token resource request to be rejected.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($resourceInvalidStatus === 0, 'Expected invalid-token resource request to complete.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($resourceInvalidHeaders, '403 Forbidden'), 'Expected invalid token resource request to be rejected.', $repoRoot, $process, $pipes, $testPaths);

$imagePath = tempnam(sys_get_temp_dir(), 'pew-resource-image-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0mQAAAAASUVORK5CYII='));
$imageHeadersPath = tempnam(sys_get_temp_dir(), 'pew-image-resource-headers-');
$imageResponsePath = tempnam(sys_get_temp_dir(), 'pew-image-resource-response-');
$imageFetchHeadersPath = tempnam(sys_get_temp_dir(), 'pew-image-fetch-headers-');
$imageFetchPath = tempnam(sys_get_temp_dir(), 'pew-image-fetch-');
$testPaths[] = $imagePath;
$testPaths[] = $imageHeadersPath;
$testPaths[] = $imageResponsePath;
$testPaths[] = $imageFetchHeadersPath;
$testPaths[] = $imageFetchPath;
$imageUploadCommand = sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F %s -F 'action=upload_resource' -F 'resource_title=Image Resource' -F 'resource_pdf=@%s;type=image/png;filename=resource.png' %s",
    escapeshellarg($imageResponsePath),
    escapeshellarg($imageHeadersPath),
    escapeshellarg($resourceCookieJar),
    escapeshellarg($resourceCookieJar),
    escapeshellarg('csrf_token=' . $resourceCsrfToken),
    escapeshellarg('folder_id=' . $resourceSubfolderId),
    escapeshellarg($imagePath),
    escapeshellarg($baseUrl . '/settings?tab=resources')
);
exec($imageUploadCommand, $imageUploadOutput, $imageUploadStatus);
$imageHeaders = is_file($imageHeadersPath) ? file_get_contents($imageHeadersPath) : '';
$imageBody = is_file($imageResponsePath) ? file_get_contents($imageResponsePath) : '';
$resourceStmt->execute(['Image Resource']);
$imageResourceRow = $resourceStmt->fetch() ?: [];
$imageStoredName = (string) ($imageResourceRow['stored_name'] ?? '');
if ($imageStoredName !== '') {
    $testPaths[] = upload_dir('resources') . '/' . $imageStoredName;
}
$imageResourceUrl = $baseUrl . '/resource_file?id=' . (int) ($imageResourceRow['id'] ?? 0);
$imageResourceExpiry = resource_access_expires_at();
$imageResourceHeader = 'X-Resource-Token: ' . resource_access_token($imageResourceRow, $imageResourceExpiry);
$imageResourceExpiryHeader = 'X-Resource-Expires: ' . $imageResourceExpiry;
exec(sprintf(
    "curl -fsS -H %s -H %s -o %s -D %s %s",
    escapeshellarg($imageResourceHeader),
    escapeshellarg($imageResourceExpiryHeader),
    escapeshellarg($imageFetchPath),
    escapeshellarg($imageFetchHeadersPath),
    escapeshellarg($imageResourceUrl)
), $imageFetchOutput, $imageFetchStatus);
$imageFetchHeaders = is_file($imageFetchHeadersPath) ? file_get_contents($imageFetchHeadersPath) : '';
$imageFetchBody = is_file($imageFetchPath) ? file_get_contents($imageFetchPath) : '';

settings_assert($imageUploadStatus === 0, 'Expected image resource upload curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($imageHeaders, 'Location: /settings?tab=resources'), 'Expected image resource upload action to redirect back to the resources tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($imageStoredName !== '', 'Expected image resource upload action to persist the uploaded image.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($imageBody, 'Resource uploaded.'), 'Expected redirected resources page to show the image upload success message.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($imageBody, 'resource-image-preview'), 'Expected redirected resources page to render an image preview card.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($imageFetchStatus === 0, 'Expected signed image resource URL to be fetchable.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($imageFetchHeaders, 'Content-Type: image/png'), 'Expected signed image resource URL to return an image response.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(substr($imageFetchBody, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A", 'Expected signed image resource URL to stream the uploaded image.', $repoRoot, $process, $pipes, $testPaths);

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

settings_assert($badResourceStatus === 0, 'Expected invalid resource upload curl request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceHeaders, 'Location: /settings?tab=resources'), 'Expected invalid resource upload action to redirect back to the resources tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($badStoredName === false, 'Expected invalid resource upload to avoid persisting a resource row.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($badResourceBody, 'Only PDF and image resources are supported.'), 'Expected redirected resources page to show the unsupported-file warning.', $repoRoot, $process, $pipes, $testPaths);

$validationPagePath = tempnam(sys_get_temp_dir(), 'pew-validation-page-');
$validationHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-headers-');
$validationResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-response-');
$autosaveHeadersPath = tempnam(sys_get_temp_dir(), 'pew-autosave-headers-');
$autosaveResponsePath = tempnam(sys_get_temp_dir(), 'pew-autosave-response-');
$saveHeadersPath = tempnam(sys_get_temp_dir(), 'pew-save-headers-');
$saveResponsePath = tempnam(sys_get_temp_dir(), 'pew-save-response-');
$validationInvalidHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-invalid-headers-');
$validationInvalidResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-invalid-response-');
$validationPersistedHeadersPath = tempnam(sys_get_temp_dir(), 'pew-validation-persisted-headers-');
$validationPersistedResponsePath = tempnam(sys_get_temp_dir(), 'pew-validation-persisted-response-');
$revisionListPagePath = tempnam(sys_get_temp_dir(), 'pew-revision-list-page-');
$revisionEditPagePath = tempnam(sys_get_temp_dir(), 'pew-revision-edit-page-');
$testPaths[] = $validationPagePath;
$testPaths[] = $validationHeadersPath;
$testPaths[] = $validationResponsePath;
$testPaths[] = $autosaveHeadersPath;
$testPaths[] = $autosaveResponsePath;
$testPaths[] = $saveHeadersPath;
$testPaths[] = $saveResponsePath;
$testPaths[] = $validationInvalidHeadersPath;
$testPaths[] = $validationInvalidResponsePath;
$testPaths[] = $validationPersistedHeadersPath;
$testPaths[] = $validationPersistedResponsePath;
$testPaths[] = $revisionListPagePath;
$testPaths[] = $revisionEditPagePath;
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
$validationPayload = json_encode([
    (string) $validationFixtureId => ['rent_quantity' => 3, 'spare_quantity' => 4],
    (string) $validationCableId => ['rent_quantity' => 0, 'spare_quantity' => 0],
], JSON_UNESCAPED_SLASHES);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($validationResponsePath),
    escapeshellarg($validationHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=validate_revision'),
    escapeshellarg('revision_id=' . $validationRevisionId),
    escapeshellarg('revision_payload=' . $validationPayload),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $validationOutput, $validationStatus);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($autosaveResponsePath),
    escapeshellarg($autosaveHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=autosave_revision'),
    escapeshellarg('revision_id=' . $validationRevisionId),
    escapeshellarg('revision_payload=' . $validationPayload),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $autosaveOutput, $autosaveStatus);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s -H %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($saveResponsePath),
    escapeshellarg($saveHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('X-Requested-With: XMLHttpRequest'),
    escapeshellarg('csrf_token=' . $validationCsrfToken),
    escapeshellarg('action=save_revision'),
    escapeshellarg('revision_id=' . $validationRevisionId),
    escapeshellarg('revision_payload=' . $validationPayload),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=orders&revision_id=' . $validationRevisionId)
), $saveOutput, $saveStatus);
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
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($revisionListPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&tab=revisions')
), $revisionListPageOutput, $revisionListPageStatus);
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($revisionEditPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&mode=edit&tab=revisions&revision_id=' . $validationNextRevisionId)
), $revisionEditPageOutput, $revisionEditPageStatus);
$validationHeaders = is_file($validationHeadersPath) ? file_get_contents($validationHeadersPath) : '';
$validationBody = is_file($validationResponsePath) ? file_get_contents($validationResponsePath) : '';
$autosaveHeaders = is_file($autosaveHeadersPath) ? file_get_contents($autosaveHeadersPath) : '';
$autosaveBody = is_file($autosaveResponsePath) ? file_get_contents($autosaveResponsePath) : '';
$saveHeaders = is_file($saveHeadersPath) ? file_get_contents($saveHeadersPath) : '';
$saveBody = is_file($saveResponsePath) ? file_get_contents($saveResponsePath) : '';
$validationInvalidHeaders = is_file($validationInvalidHeadersPath) ? file_get_contents($validationInvalidHeadersPath) : '';
$validationInvalidBody = is_file($validationInvalidResponsePath) ? file_get_contents($validationInvalidResponsePath) : '';
$validationPersistedHeaders = is_file($validationPersistedHeadersPath) ? file_get_contents($validationPersistedHeadersPath) : '';
$validationPersistedBody = is_file($validationPersistedResponsePath) ? file_get_contents($validationPersistedResponsePath) : '';
$revisionListPageHtml = is_file($revisionListPagePath) ? file_get_contents($revisionListPagePath) : '';
$revisionEditPageHtml = is_file($revisionEditPagePath) ? file_get_contents($revisionEditPagePath) : '';

settings_assert($validationPageStatus === 0 && $validationCsrfToken !== '', 'Expected validation edit page request to provide a CSRF token.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($validationStatus === 0, 'Expected validation endpoint request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationHeaders, 'Content-Type: application/json'), 'Expected validation endpoint to return JSON.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationBody, 'exceeds shop stock'), 'Expected validation endpoint to report stock warnings.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationBody, 'Rule required:'), 'Expected validation endpoint to report rule warnings.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($autosaveStatus === 0, 'Expected autosave endpoint request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($autosaveHeaders, 'Content-Type: application/json'), 'Expected autosave endpoint to return JSON.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($autosaveBody, '"ok":true'), 'Expected autosave endpoint to confirm the save.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($autosaveBody, '"overall_total":7'), 'Expected autosave endpoint totals to reflect the current revision changes.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($saveStatus === 0, 'Expected AJAX save request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($saveHeaders, 'Content-Type: application/json'), 'Expected AJAX save request to return JSON.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($saveBody, '"message":"Order changes saved."'), 'Expected AJAX save request to confirm the save.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($validationInvalidStatus === 0, 'Expected invalid validation endpoint request to complete.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationInvalidHeaders, '404 Not Found'), 'Expected invalid validation revision lookup to return 404.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationInvalidBody, 'Revision not found for this show.'), 'Expected invalid validation revision lookup to return a JSON warning message.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($validationPersistedStatus === 0, 'Expected persisted-state validation endpoint request to complete.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationPersistedHeaders, 'Content-Type: application/json'), 'Expected persisted-state validation request to return JSON.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($validationPersistedBody, 'Rule required:'), 'Expected persisted-state validation to reflect the autosaved revision state.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($revisionListPageStatus === 0, 'Expected revisions page request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($revisionListPageHtml, 'revision-history-table'), 'Expected revisions page to render the table-based revision history.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($revisionListPageHtml, '1.0') && str_contains($revisionListPageHtml, '1.1'), 'Expected revisions page to show the initial order and later revisions together.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($revisionEditPageStatus === 0, 'Expected revision edit page request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(!str_contains($revisionEditPageHtml, 'Revision History'), 'Expected revision edit page to remove the revision history section.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(!str_contains($revisionEditPageHtml, 'Every revision for this show stays visible here'), 'Expected revision edit page to remove the old revision-trail helper copy.', $repoRoot, $process, $pipes, $testPaths);
$paperworkPagePath = tempnam(sys_get_temp_dir(), 'pew-paperwork-page-');
$paperworkHeadersPath = tempnam(sys_get_temp_dir(), 'pew-paperwork-headers-');
$paperworkResponsePath = tempnam(sys_get_temp_dir(), 'pew-paperwork-response-');
$testPaths[] = $paperworkPagePath;
$testPaths[] = $paperworkHeadersPath;
$testPaths[] = $paperworkResponsePath;
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($paperworkPagePath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&tab=paperwork')
), $paperworkPageOutput, $paperworkPageStatus);
$paperworkPageHtml = is_file($paperworkPagePath) ? file_get_contents($paperworkPagePath) : '';
preg_match('/name=\"csrf_token\" value=\"([^\"]+)\"/', $paperworkPageHtml, $paperworkTokenMatch);
$paperworkToken = html_entity_decode($paperworkTokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
exec(sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s -F %s -F 'action=save_show_layout' -F 'footer_text=Show Footer Override' -F 'cover_prepared_by_name=Show Prep Person' %s",
    escapeshellarg($paperworkResponsePath),
    escapeshellarg($paperworkHeadersPath),
    escapeshellarg($cookieJar),
    escapeshellarg($cookieJar),
    escapeshellarg('csrf_token=' . $paperworkToken),
    escapeshellarg($baseUrl . '/show?show_id=' . $validationShowId . '&tab=paperwork')
), $paperworkOutput, $paperworkStatus);
$paperworkHeaders = is_file($paperworkHeadersPath) ? file_get_contents($paperworkHeadersPath) : '';
$paperworkResponseHtml = is_file($paperworkResponsePath) ? file_get_contents($paperworkResponsePath) : '';
$paperworkDb = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$showFooterStmt = $paperworkDb->prepare('SELECT value FROM show_settings WHERE show_id = ? AND `key` = ?');
$showFooterStmt->execute([$validationShowId, 'layout.footer_text']);
$showFooterValue = (string) $showFooterStmt->fetchColumn();
$showPreparedByStmt = $paperworkDb->prepare('SELECT value FROM show_settings WHERE show_id = ? AND `key` = ?');
$showPreparedByStmt->execute([$validationShowId, 'layout.cover_prepared_by_name']);
$showPreparedByValue = (string) $showPreparedByStmt->fetchColumn();
settings_assert($paperworkPageStatus === 0 && $paperworkToken !== '', 'Expected paperwork tab request to provide a CSRF token.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($paperworkStatus === 0, 'Expected show paperwork settings save request to succeed.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($paperworkHeaders, 'Location: /show?show_id=' . $validationShowId . '&tab=paperwork'), 'Expected show paperwork settings save to redirect back to the paperwork tab.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($showFooterValue === 'Show Footer Override', 'Expected show paperwork settings to persist footer overrides per show.', $repoRoot, $process, $pipes, $testPaths);
settings_assert($showPreparedByValue === 'Show Prep Person', 'Expected show paperwork settings to persist prepared-by overrides per show.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($paperworkResponseHtml, 'Show paperwork settings saved.'), 'Expected show paperwork save flow to show a success flash.', $repoRoot, $process, $pipes, $testPaths);
settings_assert(str_contains($paperworkResponseHtml, 'Show Paperwork Settings'), 'Expected show paperwork tab to render its dedicated settings card.', $repoRoot, $process, $pipes, $testPaths);

[$authProcess, $authPipes, $authBaseUrl] = settings_start_server($repoRoot, true);
$authPaths = [
    tempnam(sys_get_temp_dir(), 'pew-auth-cookie-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-login-page-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-login-headers-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-login-response-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-show-headers-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-show-response-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-revision-headers-'),
    tempnam(sys_get_temp_dir(), 'pew-auth-revision-response-'),
];
[$authCookieJar, $authLoginPagePath, $authLoginHeadersPath, $authLoginResponsePath, $authShowHeadersPath, $authShowResponsePath, $authRevisionHeadersPath, $authRevisionResponsePath] = $authPaths;
settings_assert(is_resource($authProcess) && is_string($authBaseUrl) && $authBaseUrl !== '', 'Expected auth-focused PHP server to start.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
exec(sprintf(
    "curl -fsS -o %s -c %s -b %s %s",
    escapeshellarg($authLoginPagePath),
    escapeshellarg($authCookieJar),
    escapeshellarg($authCookieJar),
    escapeshellarg($authBaseUrl . '/login')
), $authLoginPageOutput, $authLoginPageStatus);
$authLoginPageHtml = is_file($authLoginPagePath) ? file_get_contents($authLoginPagePath) : '';
preg_match('/name=\"csrf_token\" value=\"([^\"]+)\"/', $authLoginPageHtml, $authLoginTokenMatch);
$authLoginToken = html_entity_decode($authLoginTokenMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
exec(sprintf(
    "curl -isS -o %s -D %s -L -c %s -b %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s %s",
    escapeshellarg($authLoginResponsePath),
    escapeshellarg($authLoginHeadersPath),
    escapeshellarg($authCookieJar),
    escapeshellarg($authCookieJar),
    escapeshellarg('csrf_token=' . $authLoginToken),
    escapeshellarg('return_to=/'),
    escapeshellarg('email=restricted-user@example.com'),
    escapeshellarg('password=' . $restrictedPassword),
    escapeshellarg($authBaseUrl . '/login')
), $authLoginOutput, $authLoginStatus);
settings_assert($authLoginPageStatus === 0 && $authLoginToken !== '', 'Expected auth login page to provide a CSRF token.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
settings_assert($authLoginStatus === 0, 'Expected restricted-user login request to succeed.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s %s",
    escapeshellarg($authShowResponsePath),
    escapeshellarg($authShowHeadersPath),
    escapeshellarg($authCookieJar),
    escapeshellarg($authCookieJar),
    escapeshellarg($authBaseUrl . '/show?show_id=' . $adminPrivateShowId)
), $authShowOutput, $authShowStatus);
exec(sprintf(
    "curl -isS -o %s -D %s -c %s -b %s %s",
    escapeshellarg($authRevisionResponsePath),
    escapeshellarg($authRevisionHeadersPath),
    escapeshellarg($authCookieJar),
    escapeshellarg($authCookieJar),
    escapeshellarg($authBaseUrl . '/show?show_id=' . $adminPrivateShowId . '&mode=edit&tab=revisions&revision_id=' . $adminPrivateRevisionId)
), $authRevisionOutput, $authRevisionStatus);
$authShowHeaders = is_file($authShowHeadersPath) ? file_get_contents($authShowHeadersPath) : '';
$authShowBody = is_file($authShowResponsePath) ? file_get_contents($authShowResponsePath) : '';
$authRevisionHeaders = is_file($authRevisionHeadersPath) ? file_get_contents($authRevisionHeadersPath) : '';
$authRevisionBody = is_file($authRevisionResponsePath) ? file_get_contents($authRevisionResponsePath) : '';
settings_assert($authShowStatus === 0, 'Expected unauthorized show request to complete.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
settings_assert(str_contains($authShowHeaders, '404 Not Found') && str_contains($authShowBody, 'Show not found.'), 'Expected users without ownership to receive a 404 for restricted shows.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
settings_assert($authRevisionStatus === 0, 'Expected unauthorized revision request to complete.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
settings_assert(str_contains($authRevisionHeaders, '404 Not Found') && str_contains($authRevisionBody, 'Show not found.'), 'Expected users without ownership to receive a 404 for restricted revision editors.', $repoRoot, $process, $pipes, array_merge($testPaths, $authPaths));
settings_test_cleanup($repoRoot, $authProcess, $authPipes, $authPaths, false);

settings_test_cleanup($repoRoot, $process, $pipes, $testPaths);
echo "settings import action test passed\n";
