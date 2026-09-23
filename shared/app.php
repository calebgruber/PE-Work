<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    if (APP_BASE_URL !== '') {
        return APP_BASE_URL;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }

    return rtrim($dir, '/');
}

function url_for(string $path = ''): string
{
    $base = app_base_url();
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    return url_for($path);
}

function current_user(): array
{
    return [
        'display_name' => 'Shop Order Admin',
        'username' => 'shop-admin',
        'role' => 'admin',
    ];
}

function nav_items(string $active = 'dashboard'): array
{
    return [
        ['icon' => 'home', 'label' => 'Dashboard', 'href' => url_for(''), 'active' => $active === 'dashboard'],
        ['icon' => 'theater_comedy', 'label' => 'Shows', 'href' => url_for('show'), 'active' => $active === 'shows'],
        ['icon' => 'settings', 'label' => 'Settings', 'href' => url_for('settings'), 'active' => $active === 'settings'],
    ];
}

function normalize_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function blank_show(): array
{
    return [
        'id' => null,
        'show_name' => '',
        'theatre_name' => '',
        'shop_name' => '',
        'ld_name' => '',
        'ld_email' => '',
        'ld_phone' => '',
        'assistant_ld_name' => '',
        'assistant_ld_email' => '',
        'assistant_ld_phone' => '',
        'production_electrician_name' => '',
        'production_electrician_email' => '',
        'production_electrician_phone' => '',
        'shop_manager_name' => '',
        'shop_manager_email' => '',
        'shop_manager_phone' => '',
        'assistant_shop_manager_name' => '',
        'assistant_shop_manager_email' => '',
        'assistant_shop_manager_phone' => '',
        'show_image_url' => '',
        'pull_date' => '',
        'return_date' => '',
        'strike_date' => '',
        'opening_date' => '',
        'closing_date' => '',
        'theatre_address' => '',
        'shop_address' => '',
        'show_notes' => '',
        'created_at' => '',
        'updated_at' => '',
    ];
}

function show_required_labels(): array
{
    return [
        'show_name' => 'Show Name',
        'theatre_name' => 'Theatre Name',
        'shop_name' => 'Shop Name',
        'ld_name' => 'LD',
        'ld_email' => 'LD Email',
        'ld_phone' => 'LD Phone',
        'assistant_ld_name' => 'Assistant LD',
        'assistant_ld_email' => 'Assistant LD Email',
        'assistant_ld_phone' => 'Assistant LD Phone',
        'production_electrician_name' => 'Production Electrician',
        'production_electrician_email' => 'Production Electrician Email',
        'production_electrician_phone' => 'Production Electrician Phone',
        'shop_manager_name' => 'Shop Manager',
        'shop_manager_email' => 'Shop Manager Email',
        'shop_manager_phone' => 'Shop Manager Phone',
        'assistant_shop_manager_name' => 'Assistant Shop Manager',
        'assistant_shop_manager_email' => 'Assistant Shop Manager Email',
        'assistant_shop_manager_phone' => 'Assistant Shop Manager Phone',
    ];
}

function save_show_record(array $input, ?int $showId = null): array
{
    $show = blank_show();
    foreach ($show as $field => $value) {
        if (array_key_exists($field, $input)) {
            $show[$field] = trim((string) $input[$field]);
        }
    }

    foreach (['pull_date', 'return_date', 'strike_date', 'opening_date', 'closing_date'] as $dateField) {
        $show[$dateField] = normalize_date($show[$dateField]);
    }

    $errors = [];
    foreach (show_required_labels() as $field => $label) {
        if ($show[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }

    if ($errors) {
        return ['show' => $show, 'errors' => $errors];
    }

    if ($showId) {
        $show['id'] = $showId;
        $stmt = db()->prepare(
            'UPDATE shows SET
                show_name = ?, theatre_name = ?, shop_name = ?,
                ld_name = ?, ld_email = ?, ld_phone = ?,
                assistant_ld_name = ?, assistant_ld_email = ?, assistant_ld_phone = ?,
                production_electrician_name = ?, production_electrician_email = ?, production_electrician_phone = ?,
                shop_manager_name = ?, shop_manager_email = ?, shop_manager_phone = ?,
                assistant_shop_manager_name = ?, assistant_shop_manager_email = ?, assistant_shop_manager_phone = ?,
                show_image_url = ?, pull_date = ?, return_date = ?, strike_date = ?, opening_date = ?, closing_date = ?,
                theatre_address = ?, shop_address = ?, show_notes = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([
            $show['show_name'], $show['theatre_name'], $show['shop_name'],
            $show['ld_name'], $show['ld_email'], $show['ld_phone'],
            $show['assistant_ld_name'], $show['assistant_ld_email'], $show['assistant_ld_phone'],
            $show['production_electrician_name'], $show['production_electrician_email'], $show['production_electrician_phone'],
            $show['shop_manager_name'], $show['shop_manager_email'], $show['shop_manager_phone'],
            $show['assistant_shop_manager_name'], $show['assistant_shop_manager_email'], $show['assistant_shop_manager_phone'],
            $show['show_image_url'], $show['pull_date'], $show['return_date'], $show['strike_date'], $show['opening_date'], $show['closing_date'],
            $show['theatre_address'], $show['shop_address'], $show['show_notes'],
            $showId,
        ]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO shows (
                show_name, theatre_name, shop_name,
                ld_name, ld_email, ld_phone,
                assistant_ld_name, assistant_ld_email, assistant_ld_phone,
                production_electrician_name, production_electrician_email, production_electrician_phone,
                shop_manager_name, shop_manager_email, shop_manager_phone,
                assistant_shop_manager_name, assistant_shop_manager_email, assistant_shop_manager_phone,
                show_image_url, pull_date, return_date, strike_date, opening_date, closing_date,
                theatre_address, shop_address, show_notes
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $show['show_name'], $show['theatre_name'], $show['shop_name'],
            $show['ld_name'], $show['ld_email'], $show['ld_phone'],
            $show['assistant_ld_name'], $show['assistant_ld_email'], $show['assistant_ld_phone'],
            $show['production_electrician_name'], $show['production_electrician_email'], $show['production_electrician_phone'],
            $show['shop_manager_name'], $show['shop_manager_email'], $show['shop_manager_phone'],
            $show['assistant_shop_manager_name'], $show['assistant_shop_manager_email'], $show['assistant_shop_manager_phone'],
            $show['show_image_url'], $show['pull_date'], $show['return_date'], $show['strike_date'], $show['opening_date'], $show['closing_date'],
            $show['theatre_address'], $show['shop_address'], $show['show_notes'],
        ]);
        $show['id'] = (int) db()->lastInsertId();
    }

    return ['show' => find_show((int) $show['id']), 'errors' => []];
}

function list_shows(): array
{
    if (!schema_ready()) {
        return [];
    }

    return db()->query(
        'SELECT
            s.*,
            (SELECT revision_code FROM show_revisions sr WHERE sr.show_id = s.id ORDER BY revision_index DESC LIMIT 1) AS latest_revision_code,
            (SELECT revision_date FROM show_revisions sr WHERE sr.show_id = s.id ORDER BY revision_index DESC LIMIT 1) AS latest_revision_date,
            (SELECT COUNT(*) FROM show_revisions sr WHERE sr.show_id = s.id) AS revision_count
         FROM shows s
         ORDER BY s.updated_at DESC, s.show_name ASC'
    )->fetchAll();
}

function find_show(int $showId): ?array
{
    $stmt = db()->prepare('SELECT * FROM shows WHERE id = ?');
    $stmt->execute([$showId]);
    $show = $stmt->fetch();
    return $show ?: null;
}

function dashboard_stats(): array
{
    if (!schema_ready()) {
        return ['shows' => 0, 'items' => 0, 'revisions' => 0, 'rules' => 0];
    }

    return [
        'shows' => (int) db()->query('SELECT COUNT(*) FROM shows')->fetchColumn(),
        'items' => (int) db()->query('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1')->fetchColumn(),
        'revisions' => (int) db()->query('SELECT COUNT(*) FROM show_revisions')->fetchColumn(),
        'rules' => (int) db()->query('SELECT COUNT(*) FROM system_rules')->fetchColumn(),
    ];
}

function fetch_categories(): array
{
    return table_exists('inventory_categories')
        ? db()->query('SELECT * FROM inventory_categories ORDER BY sort_order ASC, name ASC')->fetchAll()
        : [];
}

function fetch_inventory_catalog(): array
{
    $categories = fetch_categories();
    $items = table_exists('inventory_items')
        ? db()->query(
            'SELECT i.*, c.name AS category_name
             FROM inventory_items i
             LEFT JOIN inventory_categories c ON c.id = i.category_id
             WHERE i.is_active = 1
             ORDER BY COALESCE(c.sort_order, 9999), COALESCE(c.name, "Uncategorized"), i.name'
        )->fetchAll()
        : [];

    $byCategory = [];
    foreach ($categories as $category) {
        $category['items'] = [];
        $byCategory[$category['id']] = $category;
    }

    foreach ($items as $item) {
        $categoryId = $item['category_id'] ?: 0;
        if (!isset($byCategory[$categoryId])) {
            $byCategory[$categoryId] = [
                'id' => 0,
                'name' => 'Uncategorized',
                'sort_order' => 9999,
                'items' => [],
            ];
        }
        $byCategory[$categoryId]['items'][] = $item;
    }

    return array_values($byCategory);
}

function find_latest_revision(int $showId): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? ORDER BY revision_index DESC LIMIT 1');
    $stmt->execute([$showId]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function list_revisions(int $showId): array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? ORDER BY revision_index DESC');
    $stmt->execute([$showId]);
    return $stmt->fetchAll();
}

function find_revision(int $revisionId): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE id = ?');
    $stmt->execute([$revisionId]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function revision_alpha(int $index): string
{
    $value = '';
    $number = $index;

    while ($number >= 0) {
        $value = chr(($number % 26) + 65) . $value;
        $number = intdiv($number, 26) - 1;
    }

    return $value;
}

function create_initial_revision(int $showId): int
{
    $existing = find_latest_revision($showId);
    if ($existing) {
        return (int) $existing['id'];
    }

    $stmt = db()->prepare(
        'INSERT INTO show_revisions (show_id, revision_code, revision_index, revision_date, is_initial, summary_note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$showId, 'Initial', 0, date('Y-m-d'), 1, 'Initial shop order']);
    $revisionId = (int) db()->lastInsertId();
    seed_revision_items($revisionId);
    return $revisionId;
}

function create_next_revision(int $showId): int
{
    $latest = find_latest_revision($showId);
    if (!$latest) {
        return create_initial_revision($showId);
    }

    $nextIndex = (int) $latest['revision_index'] + 1;
    $code = 'Rev ' . revision_alpha($nextIndex - 1);

    $stmt = db()->prepare(
        'INSERT INTO show_revisions (show_id, revision_code, revision_index, revision_date, is_initial, summary_note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$showId, $code, $nextIndex, date('Y-m-d'), 0, 'Revision created from ' . $latest['revision_code']]);
    $revisionId = (int) db()->lastInsertId();
    seed_revision_items($revisionId, (int) $latest['id']);
    return $revisionId;
}

function seed_revision_items(int $revisionId, ?int $sourceRevisionId = null): void
{
    if ($sourceRevisionId) {
        $rows = db()->prepare(
            'SELECT inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
             FROM revision_items WHERE revision_id = ? ORDER BY inventory_item_id ASC'
        );
        $rows->execute([$sourceRevisionId]);
        $items = $rows->fetchAll();
    } else {
        $items = [];
        foreach (fetch_inventory_catalog() as $category) {
            foreach ($category['items'] as $item) {
                $items[] = [
                    'inventory_item_id' => $item['id'],
                    'rent_quantity' => 0,
                    'spare_quantity' => 0,
                    'total_quantity' => 0,
                    'action' => '',
                    'line_note' => '',
                    'pickup_date' => null,
                    'return_date' => null,
                ];
            }
        }
    }

    $insert = db()->prepare(
        'INSERT INTO revision_items (
            revision_id, inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $insert->execute([
            $revisionId,
            $item['inventory_item_id'],
            (int) ($item['rent_quantity'] ?? 0),
            (int) ($item['spare_quantity'] ?? 0),
            (int) ($item['total_quantity'] ?? 0),
            (string) ($item['action'] ?? ''),
            (string) ($item['line_note'] ?? ''),
            $item['pickup_date'] ?: null,
            $item['return_date'] ?: null,
        ]);
    }
}

function catalog_for_revision(?int $revisionId): array
{
    $catalog = fetch_inventory_catalog();
    $lineItems = [];

    if ($revisionId) {
        $stmt = db()->prepare('SELECT * FROM revision_items WHERE revision_id = ?');
        $stmt->execute([$revisionId]);
        foreach ($stmt->fetchAll() as $line) {
            $lineItems[(int) $line['inventory_item_id']] = $line;
        }
    }

    foreach ($catalog as &$category) {
        foreach ($category['items'] as &$item) {
            $line = $lineItems[(int) $item['id']] ?? [
                'rent_quantity' => 0,
                'spare_quantity' => 0,
                'total_quantity' => 0,
                'action' => '',
                'line_note' => '',
                'pickup_date' => null,
                'return_date' => null,
            ];
            $item['line'] = $line;
        }
        unset($item);
    }
    unset($category);

    return $catalog;
}

function save_revision_lines(int $revisionId, array $items): void
{
    $lookup = db()->prepare('SELECT id FROM revision_items WHERE revision_id = ? AND inventory_item_id = ?');
    $update = db()->prepare(
        'UPDATE revision_items
         SET rent_quantity = ?, spare_quantity = ?, total_quantity = ?, action = ?, line_note = ?, pickup_date = ?, return_date = ?, updated_at = CURRENT_TIMESTAMP
         WHERE revision_id = ? AND inventory_item_id = ?'
    );
    $insert = db()->prepare(
        'INSERT INTO revision_items (
            revision_id, inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $itemId => $row) {
        $rent = max(0, (int) ($row['rent_quantity'] ?? 0));
        $spares = max(0, (int) ($row['spare_quantity'] ?? 0));
        $total = $rent + $spares;
        $action = (string) ($row['action'] ?? '');
        if (!in_array($action, ['', 'add', 'return', 'exchange', 'note'], true)) {
            $action = '';
        }

        $lineNote = trim((string) ($row['line_note'] ?? ''));
        $pickupDate = normalize_date($row['pickup_date'] ?? '');
        $returnDate = normalize_date($row['return_date'] ?? '');

        $lookup->execute([$revisionId, $itemId]);
        if ($lookup->fetchColumn()) {
            $update->execute([$rent, $spares, $total, $action, $lineNote, $pickupDate, $returnDate, $revisionId, $itemId]);
        } else {
            $insert->execute([$revisionId, $itemId, $rent, $spares, $total, $action, $lineNote, $pickupDate, $returnDate]);
        }
    }
}

function revision_totals(int $revisionId): array
{
    $stmt = db()->prepare('SELECT SUM(rent_quantity) AS rent_total, SUM(spare_quantity) AS spare_total, SUM(total_quantity) AS overall_total FROM revision_items WHERE revision_id = ?');
    $stmt->execute([$revisionId]);
    $totals = $stmt->fetch() ?: [];
    return [
        'rent_total' => (int) ($totals['rent_total'] ?? 0),
        'spare_total' => (int) ($totals['spare_total'] ?? 0),
        'overall_total' => (int) ($totals['overall_total'] ?? 0),
    ];
}

function fetch_rules(): array
{
    if (!table_exists('system_rules')) {
        return [];
    }

    return db()->query(
        'SELECT
            r.*,
            ti.name AS trigger_item_name,
            ri.name AS required_item_name
         FROM system_rules r
         LEFT JOIN inventory_items ti ON ti.id = r.trigger_item_id
         LEFT JOIN inventory_items ri ON ri.id = r.required_item_id
         ORDER BY r.created_at DESC, r.id DESC'
    )->fetchAll();
}

function rule_suggestions(int $revisionId): array
{
    $rules = fetch_rules();
    if (!$rules) {
        return [];
    }

    $stmt = db()->prepare('SELECT inventory_item_id, rent_quantity FROM revision_items WHERE revision_id = ?');
    $stmt->execute([$revisionId]);
    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $totals[(int) $row['inventory_item_id']] = (int) $row['rent_quantity'];
    }

    $suggestions = [];
    foreach ($rules as $rule) {
        $triggerId = (int) $rule['trigger_item_id'];
        $requiredId = (int) $rule['required_item_id'];
        $triggerQty = max(1, (int) $rule['trigger_quantity']);
        $requiredQty = max(1, (int) $rule['required_quantity']);
        $currentTrigger = $totals[$triggerId] ?? 0;

        if ($currentTrigger < $triggerQty) {
            continue;
        }

        $multiplier = (int) ceil($currentTrigger / $triggerQty);
        $recommended = $multiplier * $requiredQty;
        $currentRequired = $totals[$requiredId] ?? 0;

        $suggestions[] = [
            'rule' => $rule,
            'recommended_quantity' => $recommended,
            'current_quantity' => $currentRequired,
            'is_short' => $currentRequired < $recommended,
        ];
    }

    return $suggestions;
}

function save_inventory_batch(array $items): void
{
    $stmt = db()->prepare(
        'UPDATE inventory_items
         SET shop_quantity = ?, unit = ?, default_note = ?, description = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );

    foreach ($items as $itemId => $row) {
        $stmt->execute([
            max(0, (int) ($row['shop_quantity'] ?? 0)),
            trim((string) ($row['unit'] ?? '')),
            trim((string) ($row['default_note'] ?? '')),
            trim((string) ($row['description'] ?? '')),
            $itemId,
        ]);
    }
}

function create_category(string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM inventory_categories WHERE name = ?');
    $stmt->execute([$name]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $sortOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM inventory_categories')->fetchColumn();
    $insert = db()->prepare('INSERT INTO inventory_categories (name, sort_order) VALUES (?, ?)');
    $insert->execute([$name, $sortOrder]);
}

function create_inventory_item(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $categoryId = (int) ($input['category_id'] ?? 0);

    if ($name === '' || $categoryId <= 0) {
        return ['ok' => false, 'message' => 'Choose a category and enter an item name.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO inventory_items (category_id, name, shop_quantity, unit, default_note, description)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $categoryId,
        $name,
        max(0, (int) ($input['shop_quantity'] ?? 0)),
        trim((string) ($input['unit'] ?? '')),
        trim((string) ($input['default_note'] ?? '')),
        trim((string) ($input['description'] ?? '')),
    ]);

    return ['ok' => true, 'message' => 'Item added.'];
}

function category_id_for_name(string $name): int
{
    $trimmed = trim($name);
    $stmt = db()->prepare('SELECT id FROM inventory_categories WHERE name = ?');
    $stmt->execute([$trimmed]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    create_category($trimmed);
    $stmt->execute([$trimmed]);
    return (int) $stmt->fetchColumn();
}

function import_inventory_csv(string $tmpPath): array
{
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) {
        return ['ok' => false, 'message' => 'Unable to read uploaded CSV.'];
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['ok' => false, 'message' => 'CSV is empty.'];
    }

    $headerMap = [];
    foreach ($header as $index => $column) {
        $headerMap[strtolower(trim((string) $column))] = $index;
    }

    if (!isset($headerMap['category'], $headerMap['name'])) {
        fclose($handle);
        return ['ok' => false, 'message' => 'CSV must include category and name columns.'];
    }

    $created = 0;
    $updated = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $category = trim((string) ($row[$headerMap['category']] ?? ''));
        $name = trim((string) ($row[$headerMap['name']] ?? ''));
        if ($category === '' || $name === '') {
            continue;
        }

        $categoryId = category_id_for_name($category);
        $shopQuantity = max(0, (int) ($row[$headerMap['shop_quantity']] ?? 0));
        $unit = trim((string) ($row[$headerMap['unit']] ?? ''));
        $defaultNote = trim((string) ($row[$headerMap['default_note']] ?? ''));
        $description = trim((string) ($row[$headerMap['description']] ?? ''));

        $lookup = db()->prepare('SELECT id FROM inventory_items WHERE category_id = ? AND name = ?');
        $lookup->execute([$categoryId, $name]);
        $itemId = $lookup->fetchColumn();

        if ($itemId) {
            $stmt = db()->prepare(
                'UPDATE inventory_items
                 SET shop_quantity = ?, unit = ?, default_note = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $stmt->execute([$shopQuantity, $unit, $defaultNote, $description, $itemId]);
            $updated++;
        } else {
            $stmt = db()->prepare(
                'INSERT INTO inventory_items (category_id, name, shop_quantity, unit, default_note, description)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$categoryId, $name, $shopQuantity, $unit, $defaultNote, $description]);
            $created++;
        }
    }

    fclose($handle);

    return ['ok' => true, 'message' => sprintf('Import complete: %d created, %d updated.', $created, $updated)];
}

function save_rule(array $input): array
{
    $triggerItem = (int) ($input['trigger_item_id'] ?? 0);
    $requiredItem = (int) ($input['required_item_id'] ?? 0);
    $triggerQuantity = max(1, (int) ($input['trigger_quantity'] ?? 1));
    $requiredQuantity = max(1, (int) ($input['required_quantity'] ?? 1));

    if ($triggerItem <= 0 || $requiredItem <= 0) {
        return ['ok' => false, 'message' => 'Choose both the trigger item and the required item.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO system_rules (trigger_item_id, trigger_quantity, required_item_id, required_quantity, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $triggerItem,
        $triggerQuantity,
        $requiredItem,
        $requiredQuantity,
        trim((string) ($input['note'] ?? '')),
    ]);

    return ['ok' => true, 'message' => 'Rule saved.'];
}

function export_layout_settings(): array
{
    $defaults = [
        'layout.header_text' => 'Production Electrician Shop Order',
        'layout.footer_text' => 'Prepared in PE Work',
        'layout.show_image' => '1',
        'layout.show_page_numbers' => '1',
        'layout.show_revision_summary' => '1',
    ];

    $settings = [];
    foreach ($defaults as $key => $default) {
        $settings[$key] = fetch_setting($key, $default) ?? $default;
    }

    return $settings;
}

function save_export_layout(array $input): void
{
    save_setting('layout.header_text', trim((string) ($input['header_text'] ?? 'Production Electrician Shop Order')));
    save_setting('layout.footer_text', trim((string) ($input['footer_text'] ?? 'Prepared in PE Work')));
    save_setting('layout.show_image', !empty($input['show_image']) ? '1' : '0');
    save_setting('layout.show_page_numbers', !empty($input['show_page_numbers']) ? '1' : '0');
    save_setting('layout.show_revision_summary', !empty($input['show_revision_summary']) ? '1' : '0');
}

function action_badge(string $action): string
{
    return match ($action) {
        'add' => ui_badge('Add', 'success'),
        'return' => ui_badge('Return', 'danger'),
        'exchange' => ui_badge('Exchange', 'warning'),
        'note' => ui_badge('See Notes', 'info'),
        default => ui_badge('No Change', 'neutral'),
    };
}
