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

$showResult = save_show_record([
    'show_name' => 'Revision Clone Test',
    'theatre_name' => 'Mainstage',
    'shop_name' => 'Shop',
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
]);
assert_true($showResult['errors'] === [], 'Expected valid show save to succeed for revision cloning.');
$showId = (int) ($showResult['show']['id'] ?? 0);
assert_true($showId > 0, 'Expected saved show to have an id.');

$initialRevisionId = create_initial_revision($showId);
$catalog = catalog_for_revision($initialRevisionId);
$fixtureItemId = 0;
foreach ($catalog as $category) {
    foreach ($category['items'] as $item) {
        if ($item['name'] === 'SolaFrame 3000') {
            $fixtureItemId = (int) $item['id'];
            break 2;
        }
    }
}
assert_true($fixtureItemId > 0, 'Expected seeded inventory item to exist for revision cloning.');

save_revision_lines($initialRevisionId, [
    $fixtureItemId => [
        'rent_quantity' => 6,
        'spare_quantity' => 2,
        'action' => 'add',
        'line_note' => 'Clone this into the next revision.',
        'pickup_date' => '2026-10-01',
        'return_date' => '2026-10-15',
    ],
]);

$nextRevisionId = create_next_revision($showId);
$nextRevision = find_revision($nextRevisionId);
assert_true(($nextRevision['revision_code'] ?? '') === 'Rev A', 'Expected next revision code to increment to Rev A.');

$lineStmt = db()->prepare('SELECT rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date FROM revision_items WHERE revision_id = ? AND inventory_item_id = ?');
$lineStmt->execute([$nextRevisionId, $fixtureItemId]);
$clonedLine = $lineStmt->fetch() ?: [];
assert_true((int) ($clonedLine['rent_quantity'] ?? 0) === 6, 'Expected next revision to clone rent quantity.');
assert_true((int) ($clonedLine['spare_quantity'] ?? 0) === 2, 'Expected next revision to clone spare quantity.');
assert_true((int) ($clonedLine['total_quantity'] ?? 0) === 8, 'Expected next revision to clone total quantity.');
assert_true(($clonedLine['action'] ?? '') === 'add', 'Expected next revision to clone action.');
assert_true(($clonedLine['line_note'] ?? '') === 'Clone this into the next revision.', 'Expected next revision to clone notes.');
assert_true(($clonedLine['pickup_date'] ?? '') === '2026-10-01', 'Expected next revision to clone pickup date.');
assert_true(($clonedLine['return_date'] ?? '') === '2026-10-15', 'Expected next revision to clone return date.');

$invalidCsv = tempnam(sys_get_temp_dir(), 'pew-invalid-');
file_put_contents($invalidCsv, "label,qty\nBad Item,1\n");
$invalidResult = import_inventory_csv($invalidCsv);
assert_true($invalidResult['ok'] === false, 'Expected invalid CSV import to fail.');
assert_true(str_contains($invalidResult['message'], 'unsupported headers') || str_contains($invalidResult['message'], 'category and name columns'), 'Expected schema validation message.');

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
