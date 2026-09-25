<?php

return [
    'name' => '004_inventory_item_order_and_spacers',
    'sqlite' => [
        static function (PDO $pdo): void {
            if (!table_column_exists('inventory_items', 'sort_order')) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
            }
            if (!table_column_exists('inventory_items', 'is_spacer')) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN is_spacer INTEGER NOT NULL DEFAULT 0');
            }
        },
        'UPDATE inventory_items
         SET sort_order = id
         WHERE sort_order = 0',
    ],
    'mysql' => [
        static function (PDO $pdo): void {
            $sortOrderExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'inventory_items'
                  AND column_name = 'sort_order'
            ")->fetchColumn() > 0;
            if (!$sortOrderExists) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER name');
            }

            $spacerExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'inventory_items'
                  AND column_name = 'is_spacer'
            ")->fetchColumn() > 0;
            if (!$spacerExists) {
                $pdo->exec('ALTER TABLE inventory_items ADD COLUMN is_spacer TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
            }
        },
        'UPDATE inventory_items
         SET sort_order = id
         WHERE sort_order = 0',
    ],
];
