<?php

$repoRoot = dirname(__DIR__);
$localConfig = $repoRoot . '/config.local.php';
$localBackup = $repoRoot . '/config.local.php.test-backup-' . uniqid('', true);
$movedLocalConfig = false;
if (file_exists($localConfig)) {
    $movedLocalConfig = rename($localConfig, $localBackup);
    if (!$movedLocalConfig) {
        fwrite(STDERR, "Unable to isolate config.local.php for csv_import_test.\n");
        exit(1);
    }
}

define('DB_DRIVER', 'sqlite');
define('DB_SQLITE_PATH', '/tmp/pe-work-test-' . uniqid('', true) . '.sqlite');
define('ALLOW_SQLITE_FOR_TESTS', true);

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/app.php';

function csv_test_cleanup(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $localConfig, $localBackup, $movedLocalConfig;

    @unlink(DB_SQLITE_PATH);
    @unlink(DB_SQLITE_PATH . '-wal');
    @unlink(DB_SQLITE_PATH . '-shm');
    if ($movedLocalConfig && file_exists($localBackup)) {
        rename($localBackup, $localConfig);
    }
}

register_shutdown_function('csv_test_cleanup');

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function ensure_catalog_item(string $category, string $name, int $shopQuantity, string $unit = 'ea', string $note = '', string $description = ''): int
{
    $categoryResult = create_category($category);
    if (!$categoryResult['ok'] && !str_contains(strtolower($categoryResult['message']), 'already exists')) {
        fwrite(STDERR, 'Failed creating test category: ' . $categoryResult['message'] . PHP_EOL);
        exit(1);
    }

    $itemResult = create_inventory_item([
        'category_id' => category_id_for_name($category),
        'name' => $name,
        'shop_quantity' => $shopQuantity,
        'unit' => $unit,
        'default_note' => $note,
        'description' => $description,
    ]);
    if (!$itemResult['ok'] && !str_contains(strtolower($itemResult['message']), 'already has an item')) {
        fwrite(STDERR, 'Failed creating test item: ' . $itemResult['message'] . PHP_EOL);
        exit(1);
    }

    $stmt = db()->prepare('SELECT id FROM inventory_items WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn();
}

run_pending_migrations();
assert_true((int) db()->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() === 0, 'Expected fresh migrations to leave inventory empty.');

$orderingCategory = create_category('Ordering');
assert_true($orderingCategory['ok'] === true, 'Expected ordering category creation to succeed.');
$orderingCategoryId = category_id_for_name('Ordering');
$sortItemResult = create_inventory_item([
    'category_id' => $orderingCategoryId,
    'name' => 'Second Item',
    'sort_order' => 20,
    'shop_quantity' => 1,
    'unit' => 'ea',
]);
assert_true($sortItemResult['ok'] === true, 'Expected sorted inventory item creation to succeed.');
$spacerItemResult = create_inventory_item([
    'category_id' => $orderingCategoryId,
    'name' => 'Spacer Break',
    'sort_order' => 10,
    'shop_quantity' => 0,
    'unit' => '',
    'is_spacer' => 1,
    'description' => 'Act break',
]);
assert_true($spacerItemResult['ok'] === true, 'Expected spacer inventory item creation to succeed.');
$orderingCatalog = fetch_inventory_catalog();
$orderingItems = [];
foreach ($orderingCatalog as $category) {
    if (($category['name'] ?? '') === 'Ordering') {
        $orderingItems = $category['items'];
        break;
    }
}
assert_true(count($orderingItems) === 2, 'Expected ordering category to include both test inventory items.');
assert_true(($orderingItems[0]['name'] ?? '') === 'Spacer Break', 'Expected lower sort order item to render first.');
assert_true((int) ($orderingItems[0]['is_spacer'] ?? 0) === 1, 'Expected spacer item flag to persist.');
$orderingSpacerId = (int) ($orderingItems[0]['id'] ?? 0);

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

file_put_contents($validCsv, "category,name,shop_quantity,unit,default_note,description\nControl,Source Four,15,ea,Moved category,Updated import\n");
$moveResult = import_inventory_csv($validCsv);
assert_true($moveResult['ok'] === true, 'Expected moved-category CSV import to succeed.');
$movedCategoryStmt = db()->prepare('SELECT c.name AS category_name FROM inventory_items i LEFT JOIN inventory_categories c ON c.id = i.category_id WHERE i.name = ? LIMIT 1');
$movedCategoryStmt->execute(['Source Four']);
assert_true(($movedCategoryStmt->fetchColumn() ?? '') === 'Control', 'Expected moved-category CSV import to update the existing item category.');
assert_true((int) db()->query("SELECT COUNT(*) FROM inventory_items WHERE name = 'Source Four'")->fetchColumn() === 1, 'Expected moved-category CSV import to avoid creating duplicates.');

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
$initialRevision = find_revision($initialRevisionId);
assert_true(($initialRevision['revision_code'] ?? '') === '1.0', 'Expected initial revision code to be 1.0.');
$fixtureItemId = ensure_catalog_item('Fixtures', 'SolaFrame 3000', 12, 'ea', 'Profile moving light', 'Manual test fixture row');
$adapterItemId = ensure_catalog_item('Power', 'Stagepin to True1 Adapter', 20, 'ea', 'Adapter note', 'Manual rule pairing row');
assert_true($fixtureItemId > 0, 'Expected test inventory item to exist for revision cloning.');
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
    $orderingSpacerId => [
        'rent_quantity' => 9,
        'spare_quantity' => 0,
        'action' => 'add',
    ],
]);

$nextRevisionId = create_next_revision($showId);
$nextRevision = find_revision($nextRevisionId);
assert_true(($nextRevision['revision_code'] ?? '') === '1.1', 'Expected next revision code to increment to 1.1.');

$lineStmt = db()->prepare('SELECT rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date FROM revision_items WHERE revision_id = ? AND inventory_item_id = ?');
$lineStmt->execute([$nextRevisionId, $fixtureItemId]);
$clonedLine = $lineStmt->fetch() ?: [];
assert_true((int) ($clonedLine['rent_quantity'] ?? 0) === 6, 'Expected next revision to clone rent quantity.');
assert_true((int) ($clonedLine['spare_quantity'] ?? 0) === 2, 'Expected next revision to clone spare quantity.');
assert_true((int) ($clonedLine['total_quantity'] ?? 0) === 8, 'Expected next revision to clone total quantity.');
assert_true(($clonedLine['action'] ?? '') === '', 'Expected next revision to reset action markers back to blank.');
assert_true(($clonedLine['line_note'] ?? '') === 'Clone this into the next revision.', 'Expected next revision to clone notes.');
assert_true(($clonedLine['pickup_date'] ?? '') === '2026-10-01', 'Expected next revision to clone pickup date.');
assert_true(($clonedLine['return_date'] ?? '') === '2026-10-15', 'Expected next revision to clone return date.');
assert_true(export_row_action_class($nextRevision, ['is_spacer' => 0], $clonedLine) === '', 'Expected carried-forward lines in a new revision to reset to blank export styling.');
assert_true(export_row_action_class(find_revision($initialRevisionId) ?: [], ['is_spacer' => 0], ['action' => 'add']) === '', 'Expected initial revision lines to avoid revised export coloring.');
assert_true(export_row_action_class($nextRevision, ['is_spacer' => 1], ['action' => 'add']) === '', 'Expected spacer rows to avoid revised export coloring.');

save_revision_lines($nextRevisionId, [
    $fixtureItemId => [
        'rent_quantity' => 7,
        'spare_quantity' => 1,
        'action' => 'exchange',
        'line_note' => 'Latest revision should clone from here.',
        'pickup_date' => '2026-10-02',
        'return_date' => '2026-10-16',
    ],
    $orderingSpacerId => [
        'rent_quantity' => 4,
        'spare_quantity' => 0,
        'action' => 'add',
    ],
]);
$previousGet = $_GET;
$_GET = [
    'show_id' => $showId,
    'revision_id' => $nextRevisionId,
    'type' => 'order',
];
ob_start();
require $repoRoot . '/export.php';
$exportHtml = ob_get_clean();
$_GET = $previousGet;
assert_true(!str_contains($exportHtml, 'Spacer Break'), 'Expected spacer rows to stay out of final paperwork.');
assert_true(!str_contains($exportHtml, 'col-summary-notes'), 'Expected revision summary export to omit the notes column.');
assert_true(str_contains($exportHtml, 'Pull 2026-10-02'), 'Expected equipment breakdown notes to include item-specific pull dates.');
assert_true(str_contains($exportHtml, 'Return 2026-10-16'), 'Expected equipment breakdown notes to include item-specific return dates.');
assert_true(str_contains($exportHtml, '<p class="page-heading">REVISION SUMMARY</p>'), 'Expected revision summary heading without the revision code.');
assert_true(str_contains($exportHtml, '<p class="page-heading">EQUIPMENT BREAKDOWN</p>'), 'Expected equipment breakdown heading without the revision code.');
assert_true(str_contains($exportHtml, '.delta-positive { color: #000; }'), 'Expected export delta styling to stay black.');
assert_true(str_contains($exportHtml, 'background:#CCCCCC;'), 'Expected export zebra striping to use the darker gray.');

$thirdRevisionId = create_next_revision($showId);
$thirdRevision = find_revision($thirdRevisionId);
assert_true(($thirdRevision['revision_code'] ?? '') === '1.2', 'Expected second follow-up revision code to increment to 1.2.');
$lineStmt->execute([$thirdRevisionId, $fixtureItemId]);
$thirdLine = $lineStmt->fetch() ?: [];
assert_true((int) ($thirdLine['rent_quantity'] ?? 0) === 7, 'Expected later revisions to clone rent quantity from the most recent revision.');
assert_true((int) ($thirdLine['spare_quantity'] ?? 0) === 1, 'Expected later revisions to clone spare quantity from the most recent revision.');
assert_true(($thirdLine['action'] ?? '') === '', 'Expected later revisions to reset the latest action marker back to blank.');
assert_true(($thirdLine['line_note'] ?? '') === 'Latest revision should clone from here.', 'Expected later revisions to clone the latest note.');

$uploadFailure = store_resource_upload([
    'error' => UPLOAD_ERR_CANT_WRITE,
    'tmp_name' => '',
    'name' => 'broken.pdf',
]);
assert_true($uploadFailure['ok'] === false, 'Expected failed PHP upload errors to be rejected.');
assert_true(($uploadFailure['message'] ?? '') === 'Choose a PDF file to upload.', 'Expected failed PHP upload errors to surface the missing upload warning.');

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

$blockingWarnings = revision_validation_warnings([
    $fixtureItemId => [
        'rent_quantity' => 6,
        'spare_quantity' => 7,
        'action' => 'add',
    ],
    $adapterItemId => [
        'rent_quantity' => 3,
        'spare_quantity' => 0,
        'action' => 'add',
    ],
]);
assert_true(count($blockingWarnings) === 2, 'Expected stock and rule warnings to block invalid revision saves.');
assert_true($blockingWarnings[0]['type'] === 'stock', 'Expected stock warning to be reported first.');
assert_true($blockingWarnings[1]['type'] === 'rule', 'Expected missing required rule item warning to be reported.');

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

$layoutDefaults = export_layout_settings();
assert_true(array_key_exists('layout.organization_text', $layoutDefaults), 'Expected export layout defaults to include organization text.');
assert_true(array_key_exists('layout.export_notes', $layoutDefaults), 'Expected export layout defaults to include export notes.');
save_export_layout([
    'header_text' => 'Custom Header',
    'organization_text' => 'Top Right Copy',
    'footer_text' => 'Custom Footer',
    'export_notes' => "One\nTwo",
    'show_page_numbers' => '1',
    'show_revision_summary' => '1',
]);
$savedLayout = export_layout_settings();
assert_true(($savedLayout['layout.organization_text'] ?? '') === 'Top Right Copy', 'Expected organization text to persist in export layout settings.');
assert_true(($savedLayout['layout.export_notes'] ?? '') === "One\nTwo", 'Expected export notes to persist in export layout settings.');

$deleteItemResult = delete_inventory_item($adapterItemId);
assert_true($deleteItemResult['ok'] === true, 'Expected inventory delete to hard-delete the row.');
$stmt->execute(['Stagepin to True1 Adapter']);
assert_true($stmt->fetch() === false, 'Expected deleted inventory item to be removed from storage.');
assert_true((int) db()->query('SELECT COUNT(*) FROM revision_items WHERE inventory_item_id = ' . (int) $adapterItemId)->fetchColumn() === 0, 'Expected deleted inventory item to remove related revision lines.');

$fixtureMetaStmt = db()->prepare('SELECT category_id, sort_order FROM inventory_items WHERE id = ?');
$fixtureMetaStmt->execute([$fixtureItemId]);
$fixtureMeta = $fixtureMetaStmt->fetch() ?: ['category_id' => 0, 'sort_order' => 0];
$originalFixtureSortOrder = (int) ($fixtureMeta['sort_order'] ?? 0);
$fixtureCategoryId = (int) ($fixtureMeta['category_id'] ?? 0);

$spacerAboveResult = create_spacer_near_inventory_item($fixtureItemId, 'above');
assert_true($spacerAboveResult['ok'] === true, 'Expected spacer-above action to succeed.');
$spacerLookup = db()->prepare('SELECT is_spacer FROM inventory_items WHERE category_id = ? AND sort_order = ? ORDER BY id DESC LIMIT 1');
$spacerLookup->execute([$fixtureCategoryId, $originalFixtureSortOrder]);
$spacerAbove = $spacerLookup->fetch() ?: [];
assert_true(($spacerAbove['is_spacer'] ?? 0) == 1, 'Expected spacer-above action to create a spacer row.');

$fixtureMetaStmt->execute([$fixtureItemId]);
$fixtureMetaAfterAbove = $fixtureMetaStmt->fetch() ?: ['sort_order' => 0];
$fixtureSortOrderAfterAbove = (int) ($fixtureMetaAfterAbove['sort_order'] ?? 0);
$spacerBelowResult = create_spacer_near_inventory_item($fixtureItemId, 'below');
assert_true($spacerBelowResult['ok'] === true, 'Expected spacer-below action to succeed.');
$spacerLookup->execute([$fixtureCategoryId, $fixtureSortOrderAfterAbove + 1]);
$spacerBelow = $spacerLookup->fetch() ?: [];
assert_true(($spacerBelow['is_spacer'] ?? 0) == 1, 'Expected spacer-below action to create a spacer row directly after the item.');

$clearCatalogItemId = ensure_catalog_item('Accessories', 'Cable Crate', 8, 'ea');
$clearInventoryResult = clear_inventory_items();
assert_true($clearInventoryResult['ok'] === true, 'Expected clear inventory action to succeed.');
assert_true((int) db()->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() === 0, 'Expected clear inventory action to remove all items.');
assert_true((int) db()->query('SELECT COUNT(*) FROM inventory_categories')->fetchColumn() === 0, 'Expected clear inventory action to remove all categories.');
assert_true((int) db()->query('SELECT COUNT(*) FROM system_rules')->fetchColumn() === 0, 'Expected clear inventory action to cascade-delete related rules.');
assert_true((int) db()->query('SELECT COUNT(*) FROM revision_items')->fetchColumn() === 0, 'Expected clear inventory action to remove related revision lines.');
assert_true($clearCatalogItemId > 0, 'Expected clear-inventory test item creation to succeed before clearing.');

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

$emptyPasteResult = import_inventory_csv_text('');
assert_true($emptyPasteResult['ok'] === false, 'Expected empty pasted CSV import to fail.');
assert_true(($emptyPasteResult['message'] ?? '') === 'Paste CSV rows to import.', 'Expected empty pasted CSV warning message.');

$missingPathResult = import_inventory_csv('/tmp/does-not-exist-' . uniqid('', true) . '.csv');
assert_true($missingPathResult['ok'] === false, 'Expected unreadable CSV import to fail.');
assert_true(str_contains($missingPathResult['message'], 'Unable to read'), 'Expected unreadable-file message.');

@unlink($validCsv);
@unlink($invalidCsv);
@unlink($dynamicCsv);
@unlink($excelCsv);
@unlink($utf16Csv);
@unlink($emptyCsv);
csv_test_cleanup();

echo "csv import tests passed\n";
