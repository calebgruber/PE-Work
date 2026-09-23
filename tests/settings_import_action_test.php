<?php

$repoRoot = dirname(__DIR__);
$localConfig = $repoRoot . '/config.local.php';
$localBackup = $repoRoot . '/config.local.php.test-backup';
if (file_exists($localConfig)) {
    rename($localConfig, $localBackup);
}

define('DB_DRIVER', 'sqlite');
define('DB_SQLITE_PATH', '/tmp/pe-work-settings-test-' . uniqid('', true) . '.sqlite');

require_once $repoRoot . '/shared/config.php';
require_once $repoRoot . '/shared/db.php';
require_once $repoRoot . '/shared/app.php';

function settings_test_cleanup(string $repoRoot, string $localConfig, string $localBackup): void
{
    @unlink(DB_SQLITE_PATH);
    if (file_exists($localBackup)) {
        rename($localBackup, $localConfig);
    }
}

function settings_assert(bool $condition, string $message, string $repoRoot, string $localConfig, string $localBackup): void
{
    if (!$condition) {
        settings_test_cleanup($repoRoot, $localConfig, $localBackup);
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

run_pending_migrations();

$csvPath = tempnam(sys_get_temp_dir(), 'pew-settings-import-');
file_put_contents($csvPath, "category,name,shop_quantity,unit,default_note,description\nFixtures,Import Action Item,7,ea,Imported via settings action,Action path\n");

register_shutdown_function(function () use ($repoRoot, $localConfig, $localBackup, $csvPath): void {
    require_once $repoRoot . '/shared/db.php';

    $stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
    $stmt->execute(['Import Action Item']);
    $quantity = (int) $stmt->fetchColumn();

    settings_assert($quantity === 7, 'Expected settings import action to create the inventory item.', $repoRoot, $localConfig, $localBackup);
    settings_assert(!empty($_SESSION['flash']), 'Expected settings import action to set a flash message.', $repoRoot, $localConfig, $localBackup);

    @unlink($csvPath);
    settings_test_cleanup($repoRoot, $localConfig, $localBackup);
    echo "settings import action test passed\n";
});

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['tab'] = 'inventory';
$_POST['action'] = 'import_inventory';
$_FILES['inventory_csv'] = [
    'name' => 'inventory.csv',
    'type' => 'text/csv',
    'tmp_name' => $csvPath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($csvPath),
];

ob_start();
require $repoRoot . '/settings.php';
