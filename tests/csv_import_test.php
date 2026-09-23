<?php

$repoRoot = dirname(__DIR__);
$localConfig = $repoRoot . '/config.local.php';
$localBackup = $repoRoot . '/config.local.php.test-backup';
if (file_exists($localConfig)) {
    rename($localConfig, $localBackup);
}

define('DB_DRIVER', 'sqlite');
define('DB_SQLITE_PATH', '/tmp/pe-work-test-' . uniqid('', true) . '.sqlite');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/app.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

run_pending_migrations();

$validCsv = tempnam(sys_get_temp_dir(), 'pew-valid-');
file_put_contents($validCsv, "category,name,shop_quantity,unit,default_note,description\nFixtures,Source Four,10,ea,Ellipsoidal,Test import\n");
$validResult = import_inventory_csv($validCsv);
assert_true($validResult['ok'] === true, 'Expected valid CSV import to succeed.');
assert_true(str_contains($validResult['message'], 'created'), 'Expected valid CSV import to report created rows.');

$stmt = db()->prepare('SELECT shop_quantity FROM inventory_items WHERE name = ?');
$stmt->execute(['Source Four']);
assert_true((int) $stmt->fetchColumn() === 10, 'Expected imported item quantity to be stored.');

file_put_contents($validCsv, "category,name,shop_quantity,unit,default_note,description\nFixtures,Source Four,12,ea,Updated note,Updated import\n");
$updateResult = import_inventory_csv($validCsv);
assert_true($updateResult['ok'] === true, 'Expected second valid CSV import to succeed.');
$stmt->execute(['Source Four']);
assert_true((int) $stmt->fetchColumn() === 12, 'Expected repeated import to update the existing item.');

$dynamicCsv = tempnam(sys_get_temp_dir(), 'pew-dynamic-');
file_put_contents($dynamicCsv, "category,name,shop_quantity,unit,default_note,description\nPracticals,Lamp Cart,3,ea,Practical carts,Dynamic category import\n");
$dynamicResult = import_inventory_csv($dynamicCsv);
assert_true($dynamicResult['ok'] === true, 'Expected dynamic category import to succeed.');
$stmt->execute(['Lamp Cart']);
assert_true((int) $stmt->fetchColumn() === 3, 'Expected dynamic-category item to be inserted.');

file_put_contents($dynamicCsv, "category,name,shop_quantity,unit,default_note,description\nPracticals,Lamp Cart,4,ea,Practical carts,Dynamic category reimport\n");
$dynamicUpdateResult = import_inventory_csv($dynamicCsv);
assert_true($dynamicUpdateResult['ok'] === true, 'Expected dynamic category reimport to succeed.');
$stmt->execute(['Lamp Cart']);
assert_true((int) $stmt->fetchColumn() === 4, 'Expected dynamic-category item to update on reimport.');

$invalidCsv = tempnam(sys_get_temp_dir(), 'pew-invalid-');
file_put_contents($invalidCsv, "label,qty\nBad Item,1\n");
$invalidResult = import_inventory_csv($invalidCsv);
assert_true($invalidResult['ok'] === false, 'Expected invalid CSV import to fail.');
assert_true(str_contains($invalidResult['message'], 'category and name columns'), 'Expected missing-header message.');

$emptyCsv = tempnam(sys_get_temp_dir(), 'pew-empty-');
file_put_contents($emptyCsv, '');
$emptyResult = import_inventory_csv($emptyCsv);
assert_true($emptyResult['ok'] === false, 'Expected empty CSV import to fail.');
assert_true(str_contains($emptyResult['message'], 'empty'), 'Expected empty CSV message.');

$missingPathResult = import_inventory_csv('/tmp/does-not-exist-' . uniqid('', true) . '.csv');
assert_true($missingPathResult['ok'] === false, 'Expected unreadable CSV import to fail.');
assert_true(str_contains($missingPathResult['message'], 'Unable to read'), 'Expected unreadable-file message.');

@unlink($validCsv);
@unlink($invalidCsv);
@unlink($dynamicCsv);
@unlink($emptyCsv);
@unlink(DB_SQLITE_PATH);
if (file_exists($localBackup)) {
    rename($localBackup, $localConfig);
}

echo "csv import tests passed\n";
