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
$moveCategoryResult = create_category('Moved Tools');
assert_true($moveCategoryResult['ok'] === true, 'Expected moved-tools category creation to succeed.');
$movedToolsCategoryId = category_id_for_name('Moved Tools');
$pipeWrenchResult = create_inventory_item([
    'category_id' => $orderingCategoryId,
    'name' => 'Pipe Wrench',
    'sort_order' => 30,
    'shop_quantity' => 2,
    'unit' => 'ea',
    'description' => 'Tool move test',
]);
assert_true($pipeWrenchResult['ok'] === true, 'Expected pipe wrench item creation to succeed.');
$pipeWrenchId = (int) db()->query("SELECT id FROM inventory_items WHERE name = 'Pipe Wrench' ORDER BY id DESC LIMIT 1")->fetchColumn();
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
assert_true(count($orderingItems) === 3, 'Expected ordering category to include all ordering test inventory items before moving one.');
assert_true(($orderingItems[0]['name'] ?? '') === 'Spacer Break', 'Expected lower sort order item to render first.');
assert_true((int) ($orderingItems[0]['is_spacer'] ?? 0) === 1, 'Expected spacer item flag to persist.');
$orderingSpacerId = (int) ($orderingItems[0]['id'] ?? 0);
save_inventory_batch([
    $pipeWrenchId => [
        'category_id' => $movedToolsCategoryId,
        'shop_quantity' => 2,
        'unit' => 'ea',
        'default_note' => '',
        'description' => 'Tool move test',
        'sort_order' => 1,
        'is_spacer' => 0,
    ],
]);
$pipeWrenchCategoryId = (int) db()->query('SELECT category_id FROM inventory_items WHERE id = ' . $pipeWrenchId)->fetchColumn();
assert_true($pipeWrenchCategoryId === $movedToolsCategoryId, 'Expected inventory batch saves to persist category changes.');

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

file_put_contents($validCsv, "category,name,shop_quantity,unit,default_note,description\nMoved Ordering,Second Item,2,ea,,\n");
$orderingMoveResult = import_inventory_csv($validCsv);
assert_true($orderingMoveResult['ok'] === true, 'Expected category move import for ordered items to succeed.');
$orderingSpacerSortOrder = (int) db()->query("SELECT sort_order FROM inventory_items WHERE name = 'Spacer Break'")->fetchColumn();
assert_true($orderingSpacerSortOrder === 1, 'Expected moving an ordered item out of a category to normalize the remaining sort order.');

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
    'theatre_address' => '123 Theatre Way',
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
    'pull_date' => '2026-09-20',
    'return_date' => '2026-10-03',
    'strike_date' => '2026-10-04',
    'opening_date' => '2026-09-25',
    'show_notes' => "Crew note one\nCrew note two",
]);
assert_true($showResult['errors'] === [], 'Expected valid show save to succeed for revision cloning.');
$showId = (int) ($showResult['show']['id'] ?? 0);
assert_true($showId > 0, 'Expected saved show to have an id.');

$initialRevisionId = create_initial_revision($showId);
$initialRevision = find_revision($initialRevisionId);
assert_true(($initialRevision['revision_code'] ?? '') === '1.0', 'Expected initial revision code to be 1.0.');
$fixtureItemId = ensure_catalog_item('Fixtures', 'SolaFrame 3000', 12, 'ea', 'Profile moving light', 'Manual test fixture row');
$adapterItemId = ensure_catalog_item('Power', 'Stagepin to True1 Adapter', 20, 'ea', 'Adapter note', 'Manual rule pairing row');
$lateAddedItemId = ensure_catalog_item('Cable', 'Late Added Feeder', 8, 'ea', 'Late note', 'Added after initial revision exists');
assert_true($fixtureItemId > 0, 'Expected test inventory item to exist for revision cloning.');
assert_true($adapterItemId > 0, 'Expected adapter inventory item to exist for rule tests.');
assert_true($lateAddedItemId > 0, 'Expected newly added inventory item to exist for current revisions.');
$initialCatalog = catalog_for_revision($initialRevisionId);
$lateItemFound = false;
foreach ($initialCatalog as $category) {
    foreach ($category['items'] as $item) {
        if ((int) ($item['id'] ?? 0) !== $lateAddedItemId) {
            continue;
        }
        $lateItemFound = true;
        assert_true((int) ($item['line']['total_quantity'] ?? -1) === 0, 'Expected newly added inventory item to appear in existing revisions with a blank line.');
    }
}
assert_true($lateItemFound, 'Expected newly added inventory item to appear in the existing revision catalog.');

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
    $lateAddedItemId => [
        'rent_quantity' => 2,
        'spare_quantity' => 1,
        'action' => 'add',
        'line_note' => 'Late-added inventory should save into the order.',
    ],
]);
$lateLineStmt = db()->prepare('SELECT rent_quantity, spare_quantity, total_quantity, line_note FROM revision_items WHERE revision_id = ? AND inventory_item_id = ?');
$lateLineStmt->execute([$initialRevisionId, $lateAddedItemId]);
$lateSavedLine = $lateLineStmt->fetch() ?: [];
assert_true((int) ($lateSavedLine['total_quantity'] ?? 0) === 3, 'Expected newly added inventory items to save into existing shop orders.');
assert_true(($lateSavedLine['line_note'] ?? '') === 'Late-added inventory should save into the order.', 'Expected newly added inventory item notes to persist.');

$nextRevisionId = create_next_revision($showId);
$nextRevision = find_revision($nextRevisionId);
assert_true(($nextRevision['revision_code'] ?? '') === '1.1', 'Expected next revision code to increment to 1.1.');
db()->prepare('UPDATE show_revisions SET revision_date = ? WHERE id = ?')->execute(['2026-09-01', $initialRevisionId]);
db()->prepare('UPDATE show_revisions SET revision_date = ? WHERE id = ?')->execute(['2026-09-15', $nextRevisionId]);
save_export_layout([
    'header_text' => 'Production Electrician Shop Order',
    'organization_text' => '',
    'footer_text' => 'Prepared in PE Work',
    'export_notes' => "Default note one\nDefault note two",
    'show_page_numbers' => '1',
    'show_revision_summary' => '1',
    'cover_title_revision_spacing' => '0.73',
    'cover_footer_logo_url' => 'images/footer-logo.png',
    'equipment_table_width' => '100',
    'equipment_min_rows_per_page' => '0',
    'equipment_max_rows_per_page' => '0',
    'equipment_zebra_gray' => '#BBBBBB',
    'equipment_row_padding' => '0.016',
    'equipment_header_row_padding' => '0.028',
    'equipment_category_row_padding' => '0.036',
    'equipment_category_gap' => '0.222',
    'equipment_header_fill' => '#ABCDEF',
    'equipment_category_fill' => '#FEDCBA',
    'equipment_font_size' => '7.35',
    'equipment_line_height' => '1.1',
    'equipment_col_item' => '45',
    'equipment_col_description' => '23',
    'equipment_col_used' => '5',
    'equipment_col_spare' => '5',
    'equipment_col_total' => '6',
    'equipment_col_notes' => '12',
]);

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
        'spare_quantity' => 2,
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
    $lateAddedItemId => [
        'rent_quantity' => 3,
        'spare_quantity' => 1,
        'action' => '',
        'line_note' => 'Changed without an explicit action.',
    ],
]);
$bulkRevisionLines = [];
for ($bulkIndex = 1; $bulkIndex <= 72; $bulkIndex++) {
    $bulkItemId = ensure_catalog_item('Fixtures', 'Paged Fixture ' . $bulkIndex, 5, 'ea', '', 'Paged export test item');
    $bulkRevisionLines[$bulkItemId] = [
        'rent_quantity' => 1,
        'spare_quantity' => 0,
        'action' => 'add',
    ];
}
save_revision_lines($nextRevisionId, $bulkRevisionLines);
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
assert_true(str_contains($exportHtml, 'Pull 10/02/26'), 'Expected equipment breakdown notes to include item-specific pull dates in mm/dd/yy format.');
assert_true(str_contains($exportHtml, 'Return 10/16/26'), 'Expected equipment breakdown notes to include item-specific return dates in mm/dd/yy format.');
assert_true(str_contains($exportHtml, 'Latest revision should clone from here.'), 'Expected order or revision line notes to print on the breakdown paperwork.');
assert_true(str_contains($exportHtml, '<div class="cover-title-fallback">Revision Clone Test</div>'), 'Expected the cover page to fall back to the show title when no show image is configured.');
assert_true(str_contains($exportHtml, '<p class="cover-venue-name">Mainstage</p>'), 'Expected the cover page to show the theatre name on its own line.');
assert_true(str_contains($exportHtml, '<p class="cover-venue-address">123 Theatre Way</p>'), 'Expected the cover page to show the theatre address on a separate line.');
assert_true(str_contains($exportHtml, 'LIGHTING SHOP ORDER'), 'Expected the cover page to label the paperwork as a lighting shop order.');
assert_true(str_contains($exportHtml, '&gt;&gt; REVISION 1.1 - 09/15/2026 &lt;&lt;'), 'Expected the cover page to highlight the current revision with arrows and a mm/dd/yyyy date.');
assert_true(str_contains($exportHtml, 'INITIAL ORDER - 09/01/2026'), 'Expected the cover page to list past revision dates in mm/dd/yyyy format.');
assert_true(str_contains($exportHtml, '<p class="details-page-heading">CREW &amp; NOTES</p>'), 'Expected the second paperwork page to contain the crew and notes section.');
assert_true(str_contains($exportHtml, '09/20/2026'), 'Expected show schedule dates to use mm/dd/yyyy formatting.');
assert_true(str_contains($exportHtml, 'margin-bottom: 0.730in;'), 'Expected the cover title-to-revision spacing to use the saved layout setting.');
assert_true(str_contains($exportHtml, 'margin-top: 0.9in;'), 'Expected notes to have much more space above them.');
assert_true(str_contains($exportHtml, 'line-height: 1.55;'), 'Expected the cover page to add more vertical space between lines.');
assert_true(str_contains($exportHtml, 'cover-footer-logo') && str_contains($exportHtml, 'footer-logo.png'), 'Expected the cover page footer to support a centered personal logo.');
assert_true(str_contains($exportHtml, '<p class="page-heading">REVISION SUMMARY</p>'), 'Expected revision summary heading without the revision code.');
assert_true(str_contains($exportHtml, '<p class="page-heading">EQUIPMENT BREAKDOWN</p>'), 'Expected equipment breakdown heading without the revision code.');
assert_true(str_contains($exportHtml, 'Only lines with changed counts or explicit revision actions are listed here.'), 'Expected revision summary copy to explain the changed-lines filter.');
assert_true((bool) preg_match('/<p class="page-heading">REVISION SUMMARY<\/p>.*?<td class="col-total">TOTAL<\/td>.*?<td class="col-action">ACTION<\/td>.*?<td class="col-notes">NOTES<\/td>/s', $exportHtml), 'Expected revision summary to use total, action, and notes columns.');
assert_true((bool) preg_match('/<p class="page-heading">REVISION SUMMARY<\/p>.*?<td class="item-cell">SolaFrame 3000<\/td>.*?<td class="description-cell">Manual test fixture row<\/td>.*?<span class="delta delta-positive">\(\+1\)<\/span>.*?<td>EXCHANGE<\/td>.*?Latest revision should clone from here\./s', $exportHtml), 'Expected revision summary to show description, total deltas, explicit action, and notes in separate columns.');
assert_true((bool) preg_match('/<p class="page-heading">REVISION SUMMARY<\/p>.*?<td class="item-cell">Late Added Feeder<\/td>.*?<td>CHANGE<\/td>.*?Changed without an explicit action\./s', $exportHtml), 'Expected revision summary rows without an explicit action to display CHANGE.');
assert_true((bool) preg_match('/<p class="page-heading">REVISION SUMMARY<\/p>.*?<tr class="category-header-row">\s*<td colspan="6">Fixtures<\/td>.*?<tr class="category-column-header-row">\s*<td class="col-line">LINE<\/td>/s', $exportHtml), 'Expected revision summary to include category headers followed by repeated table headers.');
assert_true(str_contains($exportHtml, 'table.word-table.revision-summary-table'), 'Expected the revision summary table to have its own centered table styling.');
assert_true(str_contains($exportHtml, '.delta-positive { color: #000; }'), 'Expected export delta styling to stay black.');
assert_true((bool) preg_match('/>\s*9\s*<span class="delta delta-positive">\(\+1\)<\/span>/', $exportHtml), 'Expected equipment breakdown totals to show total-quantity deltas in black text.');
assert_true(str_contains($exportHtml, 'size: Letter portrait;'), 'Expected export stylesheet to force letter-size pages.');
assert_true(export_row_style(0, $nextRevision, ['is_spacer' => 0], ['action' => ''], '#BBBBBB') === 'background:#BBBBBB;', 'Expected export zebra striping to use the configured gray.');
assert_true(!str_contains($exportHtml, 'Manager Contact'), 'Expected export cover to remove the extra shop info box above the show title.');
assert_true(substr_count($exportHtml, '<p class="page-heading">EQUIPMENT BREAKDOWN</p>') >= 3, 'Expected long equipment breakdowns to spill onto as many additional pages as needed.');
assert_true(str_contains($exportHtml, 'Paged Fixture 72'), 'Expected the export to include later line items instead of stopping early.');
assert_true(!str_contains($exportHtml, 'Adapter note'), 'Expected admin inventory default notes to stay off paperwork exports.');
assert_true((bool) preg_match('/<p class="page-heading">EQUIPMENT BREAKDOWN<\/p>.*?<tr class="category-header-row">\s*<td colspan="7">Fixtures<\/td>.*?<tr class="category-column-header-row">\s*<td class="col-line">LINE<\/td>/s', $exportHtml), 'Expected equipment breakdown to include category header rows followed by repeated table headers.');
assert_true(str_contains($exportHtml, '<tr class="category-gap-row"><td colspan="7"></td></tr>'), 'Expected export tables to include spacing rows between categories.');
assert_true(!str_contains($exportHtml, 'page-header-bar'), 'Expected export pages to remove the old per-page top header block.');
assert_true(str_contains($exportHtml, 'background: #ABCDEF;'), 'Expected export header rows to use the saved header color.');
assert_true(str_contains($exportHtml, 'background: #FEDCBA;'), 'Expected export category rows to use the saved category color.');
assert_true(str_contains($exportHtml, 'padding-top: 0.028in;'), 'Expected export header rows to use the saved header row height.');
assert_true(str_contains($exportHtml, 'padding-top: 0.036in;'), 'Expected export category rows to use the saved category row height.');
assert_true(str_contains($exportHtml, 'padding: 0.222in 0 0;'), 'Expected category spacing above each section to use the saved layout setting.');
$syntheticLayout = export_layout_settings();
$syntheticLayout['layout.equipment_min_rows_per_page'] = '4';
$syntheticLayout['layout.equipment_max_rows_per_page'] = '2';
$syntheticRows = [];
for ($syntheticIndex = 0; $syntheticIndex < 5; $syntheticIndex++) {
    $syntheticRows[] = [
        'category' => 'Fixtures',
        'item' => ['name' => 'Synthetic Item ' . $syntheticIndex, 'default_note' => ''],
        'line' => ['line_note' => '', 'pickup_date' => null, 'return_date' => null],
    ];
}
$syntheticPages = export_equipment_pages($syntheticRows, $syntheticLayout);
assert_true(count($syntheticPages) === 3, 'Expected configured max rows per page to cap equipment pagination.');
assert_true(count($syntheticPages[0]) === 2 && count($syntheticPages[1]) === 2 && count($syntheticPages[2]) === 1, 'Expected synthetic page chunking to preserve row limits.');
$pageResetStyles = [];
foreach ($syntheticPages as $pageRows) {
    foreach ($pageRows as $pageRowIndex => $pageRow) {
        $pageResetStyles[] = export_row_style($pageRowIndex, $nextRevision, ['is_spacer' => 0], ['action' => ''], '#BBBBBB');
    }
}
assert_true($pageResetStyles === ['background:#BBBBBB;', 'background:#FFFFFF;', 'background:#BBBBBB;', 'background:#FFFFFF;', 'background:#BBBBBB;'], 'Expected each equipment page to restart row striping with gray then white.');
$syntheticMinOnlyLayout = export_layout_settings();
$syntheticMinOnlyLayout['layout.equipment_min_rows_per_page'] = '4';
$syntheticMinOnlyLayout['layout.equipment_max_rows_per_page'] = '0';
$tallSyntheticRows = [];
for ($syntheticIndex = 0; $syntheticIndex < 5; $syntheticIndex++) {
    $tallSyntheticRows[] = [
        'category' => 'Very Long Category Label ' . str_repeat('Alpha ', 30),
        'item' => ['name' => 'Tall Synthetic Item ' . $syntheticIndex, 'default_note' => ''],
        'line' => ['line_note' => str_repeat('This is a very long export note to force wrapping. ', 20), 'pickup_date' => null, 'return_date' => null],
    ];
}
$syntheticAutoPages = export_equipment_pages($tallSyntheticRows, export_layout_settings());
$syntheticMinPages = export_equipment_pages($tallSyntheticRows, $syntheticMinOnlyLayout);
assert_true(count($syntheticAutoPages[0]) < 4, 'Expected automatic pagination to break tall rows before four items.');
assert_true(count($syntheticMinPages[0]) === 4 && count($syntheticMinPages[1]) === 1, 'Expected configured minimum rows per page to keep at least the minimum rows together when possible.');

$thirdRevisionId = create_next_revision($showId);
$thirdRevision = find_revision($thirdRevisionId);
assert_true(($thirdRevision['revision_code'] ?? '') === '1.2', 'Expected second follow-up revision code to increment to 1.2.');
$lineStmt->execute([$thirdRevisionId, $fixtureItemId]);
$thirdLine = $lineStmt->fetch() ?: [];
assert_true((int) ($thirdLine['rent_quantity'] ?? 0) === 7, 'Expected later revisions to clone rent quantity from the most recent revision.');
assert_true((int) ($thirdLine['spare_quantity'] ?? 0) === 2, 'Expected later revisions to clone spare quantity from the most recent revision.');
assert_true(($thirdLine['action'] ?? '') === '', 'Expected later revisions to reset the latest action marker back to blank.');
assert_true(($thirdLine['line_note'] ?? '') === 'Latest revision should clone from here.', 'Expected later revisions to clone the latest note.');

$deleteRevisionResult = delete_show_revision($thirdRevisionId);
assert_true($deleteRevisionResult['ok'] === true, 'Expected deleting a saved revision to succeed.');
assert_true(find_revision($thirdRevisionId) === null, 'Expected deleted revision to be removed.');
assert_true((int) db()->query('SELECT COUNT(*) FROM revision_items WHERE revision_id = ' . (int) $thirdRevisionId)->fetchColumn() === 0, 'Expected deleting a revision to remove related revision lines.');
assert_true(delete_show_revision($initialRevisionId)['ok'] === false, 'Expected initial revision deletion to be blocked.');

$resourceFolderResult = create_resource_folder('Manuals');
assert_true($resourceFolderResult['ok'] === true, 'Expected resource folder creation to succeed.');
$resourceFolderId = (int) db()->query("SELECT id FROM resource_folders WHERE name = 'Manuals'")->fetchColumn();
assert_true($resourceFolderId > 0, 'Expected resource folder id.');
$resourceSubfolderResult = create_resource_folder('Drafts', $resourceFolderId);
assert_true($resourceSubfolderResult['ok'] === true, 'Expected resource subfolder creation to succeed.');
$resourceSubfolderId = (int) db()->query("SELECT id FROM resource_folders WHERE name = 'Drafts'")->fetchColumn();
assert_true($resourceSubfolderId > 0, 'Expected resource subfolder id.');
$resourceFolders = fetch_resource_folders();
$draftsFolder = null;
foreach ($resourceFolders as $folderRow) {
    if ((int) ($folderRow['id'] ?? 0) === $resourceSubfolderId) {
        $draftsFolder = $folderRow;
        break;
    }
}
assert_true(($draftsFolder['full_path'] ?? '') === 'Manuals / Drafts', 'Expected resource subfolders to report their full path.');
db()->prepare(
    'INSERT INTO resources (title, original_name, stored_name, mime_type, file_size, folder_id)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute(['Console Cheat Sheet', 'console.pdf', '20260923000000-abcdefabcdef.pdf', 'application/pdf', 1234, $resourceSubfolderId]);
$resourceId = (int) db()->lastInsertId();
$folderResources = fetch_resources($resourceSubfolderId);
assert_true(count($folderResources) === 1, 'Expected folder-filtered resources to include the inserted PDF.');
assert_true(($folderResources[0]['folder_name'] ?? '') === 'Drafts', 'Expected fetched resource rows to include the immediate folder name.');
assert_true(($folderResources[0]['folder_path'] ?? '') === 'Manuals / Drafts', 'Expected fetched resources to include full folder paths.');
assert_true(delete_resource_folder($resourceFolderId)['ok'] === false, 'Expected parent folder deletion to be blocked while subfolders exist.');
assert_true(move_resource_to_folder($resourceId, null)['ok'] === true, 'Expected moving a resource back to the root library to succeed.');
assert_true(delete_resource_folder($resourceSubfolderId)['ok'] === true, 'Expected deleting an empty resource subfolder to succeed.');
assert_true(delete_resource_folder($resourceFolderId)['ok'] === true, 'Expected deleting an empty resource folder to succeed.');

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
assert_true(save_rule(['trigger_quantity' => 1, 'required_quantity' => 1])['ok'] === false, 'Expected missing rule item ids to be rejected.');
assert_true(save_rule([
    'trigger_item_id' => 999999,
    'trigger_quantity' => 1,
    'required_item_id' => $adapterItemId,
    'required_quantity' => 1,
])['ok'] === false, 'Expected nonexistent trigger items to be rejected.');
assert_true(save_rule([
    'trigger_item_id' => $fixtureItemId,
    'trigger_quantity' => 1,
    'required_item_id' => 999999,
    'required_quantity' => 1,
])['ok'] === false, 'Expected nonexistent required items to be rejected.');
$deactivateStmt = db()->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = ?');
$deactivateStmt->execute([$adapterItemId]);
assert_true(save_rule([
    'trigger_item_id' => $fixtureItemId,
    'trigger_quantity' => 1,
    'required_item_id' => $adapterItemId,
    'required_quantity' => 1,
])['ok'] === false, 'Expected inactive required items to be rejected.');
$reactivateStmt = db()->prepare('UPDATE inventory_items SET is_active = 1 WHERE id = ?');
$reactivateStmt->execute([$adapterItemId]);

$deleteRuleResult = delete_rule($ruleId);
assert_true($deleteRuleResult['ok'] === true, 'Expected rule delete to succeed.');
$ruleStmt->execute([$ruleId]);
assert_true($ruleStmt->fetch() === false, 'Expected deleted rule to be removed from storage.');

$layoutDefaults = export_layout_settings();
assert_true(array_key_exists('layout.organization_text', $layoutDefaults), 'Expected export layout defaults to include organization text.');
assert_true(array_key_exists('layout.export_notes', $layoutDefaults), 'Expected export layout defaults to include export notes.');
assert_true(array_key_exists('layout.equipment_table_width', $layoutDefaults), 'Expected export layout defaults to include equipment table sizing.');
assert_true(array_key_exists('layout.equipment_min_rows_per_page', $layoutDefaults), 'Expected export layout defaults to include equipment min rows per page.');
assert_true(array_key_exists('layout.equipment_max_rows_per_page', $layoutDefaults), 'Expected export layout defaults to include equipment max rows per page.');
assert_true(array_key_exists('layout.equipment_zebra_gray', $layoutDefaults), 'Expected export layout defaults to include equipment zebra gray.');
assert_true(array_key_exists('layout.equipment_line_height', $layoutDefaults), 'Expected export layout defaults to include equipment line height.');
save_export_layout([
    'header_text' => 'Custom Header',
    'organization_text' => 'Top Right Copy',
    'footer_text' => 'Custom Footer',
    'export_notes' => "One\nTwo",
    'show_page_numbers' => '1',
    'show_revision_summary' => '0',
    'equipment_table_width' => '96',
    'equipment_min_rows_per_page' => '4',
    'equipment_max_rows_per_page' => '12',
    'equipment_zebra_gray' => '#BBBBBB',
    'equipment_row_padding' => '0.02',
    'equipment_font_size' => '7.8',
    'equipment_line_height' => '1.3',
    'equipment_col_item' => '48',
    'equipment_col_description' => '22',
    'equipment_col_used' => '5',
    'equipment_col_spare' => '5',
    'equipment_col_total' => '6',
    'equipment_col_notes' => '10',
]);
$savedLayout = export_layout_settings();
assert_true(($savedLayout['layout.organization_text'] ?? '') === 'Top Right Copy', 'Expected organization text to persist in export layout settings.');
assert_true(($savedLayout['layout.export_notes'] ?? '') === "One\nTwo", 'Expected export notes to persist in export layout settings.');
assert_true(($savedLayout['layout.equipment_table_width'] ?? '') === '96', 'Expected equipment table width to persist in export layout settings.');
assert_true(($savedLayout['layout.equipment_min_rows_per_page'] ?? '') === '4', 'Expected equipment min rows per page to persist in export layout settings.');
assert_true(($savedLayout['layout.equipment_max_rows_per_page'] ?? '') === '12', 'Expected equipment max rows per page to persist in export layout settings.');
assert_true(($savedLayout['layout.equipment_zebra_gray'] ?? '') === '#BBBBBB', 'Expected equipment zebra gray to persist in export layout settings.');
assert_true(($savedLayout['layout.equipment_line_height'] ?? '') === '1.3', 'Expected equipment line height to persist in export layout settings.');

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
