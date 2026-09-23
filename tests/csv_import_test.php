<?php

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

$invalidCsv = tempnam(sys_get_temp_dir(), 'pew-invalid-');
file_put_contents($invalidCsv, "label,qty\nBad Item,1\n");
$invalidResult = import_inventory_csv($invalidCsv);
assert_true($invalidResult['ok'] === false, 'Expected invalid CSV import to fail.');
assert_true(str_contains($invalidResult['message'], 'category and name columns'), 'Expected missing-header message.');

@unlink($validCsv);
@unlink($invalidCsv);
@unlink(DB_SQLITE_PATH);

echo "csv import tests passed\n";
