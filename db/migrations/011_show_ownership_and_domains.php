<?php

return [
    'name' => '011_show_ownership_and_domains',
    'sqlite' => [
        static function (PDO $pdo): void {
            $showColumns = array_map(
                static fn (array $column): string => (string) ($column['name'] ?? ''),
                $pdo->query('PRAGMA table_info(shows)')->fetchAll(PDO::FETCH_ASSOC)
            );
            if (!in_array('owner_user_id', $showColumns, true)) {
                $pdo->exec('ALTER TABLE shows ADD COLUMN owner_user_id INTEGER DEFAULT NULL');
            }
            if (!in_array('concentration', $showColumns, true)) {
                $pdo->exec('ALTER TABLE shows ADD COLUMN concentration TEXT NOT NULL DEFAULT "lighting"');
            }

            $categoryColumns = array_map(
                static fn (array $column): string => (string) ($column['name'] ?? ''),
                $pdo->query('PRAGMA table_info(inventory_categories)')->fetchAll(PDO::FETCH_ASSOC)
            );
            if (!in_array('concentration', $categoryColumns, true)) {
                $pdo->exec('ALTER TABLE inventory_categories ADD COLUMN concentration TEXT NOT NULL DEFAULT "lighting"');
            }

            $itemColumns = array_map(
                static fn (array $column): string => (string) ($column['name'] ?? ''),
                $pdo->query('PRAGMA table_info(inventory_items)')->fetchAll(PDO::FETCH_ASSOC)
            );
            if (!in_array('concentration', $itemColumns, true)) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN concentration TEXT NOT NULL DEFAULT "lighting"');
            }

            $pdo->exec('UPDATE shows SET concentration = COALESCE(NULLIF(TRIM(concentration), \'\'), "lighting")');
            $pdo->exec('UPDATE inventory_categories SET concentration = COALESCE(NULLIF(TRIM(concentration), \'\'), "lighting")');
            $pdo->exec('UPDATE inventory_items SET concentration = COALESCE(NULLIF(TRIM(concentration), \'\'), "lighting")');
            $pdo->exec('UPDATE inventory_items
                        SET concentration = COALESCE(
                            (SELECT concentration FROM inventory_categories c WHERE c.id = inventory_items.category_id),
                            concentration,
                            "lighting"
                        )');

            $ownerId = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
            if ($ownerId <= 0) {
                $ownerId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
            }
            if ($ownerId > 0) {
                $stmt = $pdo->prepare('UPDATE shows SET owner_user_id = ? WHERE owner_user_id IS NULL');
                $stmt->execute([$ownerId]);
            }

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shows_owner_user_id ON shows(owner_user_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shows_concentration ON shows(concentration)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inventory_categories_concentration ON inventory_categories(concentration)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inventory_items_concentration ON inventory_items(concentration)');
        },
    ],
    'mysql' => [
        static function (PDO $pdo): void {
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

            $columnExists = static function (string $table, string $column) use ($pdo, $database): bool {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $stmt->execute([$database, $table, $column]);
                return (int) $stmt->fetchColumn() > 0;
            };
            $indexExists = static function (string $table, string $index) use ($pdo, $database): bool {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
                );
                $stmt->execute([$database, $table, $index]);
                return (int) $stmt->fetchColumn() > 0;
            };

            if (!$columnExists('shows', 'owner_user_id')) {
                $pdo->exec('ALTER TABLE shows ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER id');
            }
            if (!$columnExists('shows', 'concentration')) {
                $pdo->exec('ALTER TABLE shows ADD COLUMN concentration VARCHAR(50) NOT NULL DEFAULT "lighting" AFTER owner_user_id');
            }
            if (!$columnExists('inventory_categories', 'concentration')) {
                $pdo->exec('ALTER TABLE inventory_categories ADD COLUMN concentration VARCHAR(50) NOT NULL DEFAULT "lighting" AFTER name');
            }
            if (!$columnExists('inventory_items', 'concentration')) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN concentration VARCHAR(50) NOT NULL DEFAULT "lighting" AFTER name');
            }

            $pdo->exec("UPDATE shows SET concentration = IF(TRIM(COALESCE(concentration, '')) = '', 'lighting', concentration)");
            $pdo->exec("UPDATE inventory_categories SET concentration = IF(TRIM(COALESCE(concentration, '')) = '', 'lighting', concentration)");
            $pdo->exec("UPDATE inventory_items SET concentration = IF(TRIM(COALESCE(concentration, '')) = '', 'lighting', concentration)");
            $pdo->exec('UPDATE inventory_items i
                        LEFT JOIN inventory_categories c ON c.id = i.category_id
                        SET i.concentration = COALESCE(c.concentration, i.concentration, "lighting")');

            $ownerId = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
            if ($ownerId <= 0) {
                $ownerId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
            }
            if ($ownerId > 0) {
                $stmt = $pdo->prepare('UPDATE shows SET owner_user_id = ? WHERE owner_user_id IS NULL');
                $stmt->execute([$ownerId]);
            }

            if (!$indexExists('shows', 'idx_shows_owner_user_id')) {
                $pdo->exec('ALTER TABLE shows ADD INDEX idx_shows_owner_user_id (owner_user_id)');
            }
            if (!$indexExists('shows', 'idx_shows_concentration')) {
                $pdo->exec('ALTER TABLE shows ADD INDEX idx_shows_concentration (concentration)');
            }
            if (!$indexExists('inventory_categories', 'idx_inventory_categories_concentration')) {
                $pdo->exec('ALTER TABLE inventory_categories ADD INDEX idx_inventory_categories_concentration (concentration)');
            }
            if (!$indexExists('inventory_items', 'idx_inventory_items_concentration')) {
                $pdo->exec('ALTER TABLE inventory_items ADD INDEX idx_inventory_items_concentration (concentration)');
            }
        },
    ],
];
