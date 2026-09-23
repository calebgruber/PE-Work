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

$excelCsv = tempnam(sys_get_temp_dir(), 'pew-excel-');
file_put_contents($excelCsv, "\xEF\xBB\xBFcategory,name,shop_quantity,unit,default_note,description\nFIXTURES,HES Solaframe Theatre,12,ea,,\nFIXTURES,GLP Impression S350 Wash,10,ea,,\nFIXTURES,GLP Impression Wash One,6,ea,,\nFIXTURES,GLP Impression Spot One,6,ea,,\n");
$excelResult = import_inventory_csv($excelCsv);
assert_true($excelResult['ok'] === true, 'Expected Excel-style CSV import with BOM and uppercase categories to succeed.');

$stmt->execute(['HES Solaframe Theatre']);
assert_true((int) $stmt->fetchColumn() === 12, 'Expected Excel-style CSV import to store HES Solaframe Theatre quantity.');
$stmt->execute(['GLP Impression S350 Wash']);
assert_true((int) $stmt->fetchColumn() === 10, 'Expected Excel-style CSV import to store GLP Impression S350 Wash quantity.');

$fixtureCategoryCount = (int) db()->query("SELECT COUNT(*) FROM inventory_categories WHERE LOWER(name) = 'fixtures'")->fetchColumn();
assert_true($fixtureCategoryCount === 1, 'Expected uppercase spreadsheet categories to reuse the existing Fixtures category.');

$utf16Csv = tempnam(sys_get_temp_dir(), 'pew-utf16-');
$utf16Contents = "category,name,shop_quantity,unit,default_note,description\r\nFIXTURES,HES Solaframe Theatre,12,ea,.,.\r\n";
$utf16Encoded = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE//IGNORE', $utf16Contents);
file_put_contents($utf16Csv, $utf16Encoded);
$utf16Result = import_inventory_csv($utf16Csv);
assert_true($utf16Result['ok'] === true, 'Expected UTF-16 Excel-style CSV import to succeed.');

$utf16Stmt = db()->prepare('SELECT unit, default_note, description FROM inventory_items WHERE name = ?');
$utf16Stmt->execute(['HES Solaframe Theatre']);
$utf16Item = $utf16Stmt->fetch() ?: [];
assert_true(($utf16Item['unit'] ?? '') === 'ea', 'Expected UTF-16 Excel-style CSV import to preserve unit values.');
assert_true(($utf16Item['default_note'] ?? '') === '', 'Expected dot placeholders to import as blank notes.');
assert_true(($utf16Item['description'] ?? '') === '', 'Expected dot placeholders to import as blank descriptions.');

$pastedCsv = "category,name,shop_quantity,unit,default_note,description\nFIXTURES,HES Solaframe Theatre,12,ea,.,.\nFIXTURES,GLP Impression S350 Wash,10,ea,.,.\n";
$pastedResult = import_inventory_csv_text($pastedCsv);
assert_true($pastedResult['ok'] === true, 'Expected pasted CSV rows to import successfully.');

$quotedPaste = "category,name,shop_quantity,unit,default_note,description\nAccessories,\"Workbox, Large\",2,ea,\"Contains gels, tape\",.\n";
$quotedPasteResult = import_inventory_csv_text($quotedPaste);
assert_true($quotedPasteResult['ok'] === true, 'Expected pasted quoted CSV rows to import successfully.');
$stmt->execute(['Workbox, Large']);
assert_true((int) $stmt->fetchColumn() === 2, 'Expected pasted quoted CSV row to store the item quantity.');

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
$adapterItemId = 0;
foreach ($catalog as $category) {
    foreach ($category['items'] as $item) {
        if ($item['name'] === 'SolaFrame 3000') {
            $fixtureItemId = (int) $item['id'];
        }
        if ($item['name'] === 'Stagepin to True1 Adapter') {
            $adapterItemId = (int) $item['id'];
        }
    }
}
assert_true($fixtureItemId > 0, 'Expected seeded inventory item to exist for revision cloning.');
assert_true($adapterItemId > 0, 'Expected adapter inventory item to exist for rule tests.');

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

$createRuleResult = save_rule([
    'trigger_item_id' => $fixtureItemId,
    'trigger_quantity' => 2,
    'required_item_id' => $adapterItemId,
    'required_quantity' => 3,
    'note' => 'Initial rule note',
]);
assert_true($createRuleResult['ok'] === true, 'Expected rule creation to succeed.');
$ruleId = (int) db()->query('SELECT id FROM system_rules ORDER BY id DESC LIMIT 1')->fetchColumn();
assert_true($ruleId > 0, 'Expected created rule id.');

$updateRuleResult = save_rule([
    'rule_id' => $ruleId,
    'trigger_item_id' => $fixtureItemId,
    'trigger_quantity' => 4,
    'required_item_id' => $adapterItemId,
    'required_quantity' => 5,
    'note' => 'Updated rule note',
]);
assert_true($updateRuleResult['ok'] === true, 'Expected rule update to succeed.');
$ruleStmt = db()->prepare('SELECT trigger_quantity, required_quantity, note FROM system_rules WHERE id = ?');
$ruleStmt->execute([$ruleId]);
$updatedRule = $ruleStmt->fetch() ?: [];
assert_true((int) ($updatedRule['trigger_quantity'] ?? 0) === 4, 'Expected updated rule trigger quantity.');
assert_true((int) ($updatedRule['required_quantity'] ?? 0) === 5, 'Expected updated rule required quantity.');
assert_true(($updatedRule['note'] ?? '') === 'Updated rule note', 'Expected updated rule note.');

$deleteRuleResult = delete_rule($ruleId);
assert_true($deleteRuleResult['ok'] === true, 'Expected rule delete to succeed.');
$ruleStmt->execute([$ruleId]);
assert_true($ruleStmt->fetch() === false, 'Expected deleted rule to be removed from storage.');

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
@unlink($excelCsv);
@unlink($utf16Csv);
@unlink($emptyCsv);
@unlink(DB_SQLITE_PATH);
if (file_exists($localBackup)) {
    rename($localBackup, $localConfig);
}

echo "csv import tests passed\n";
