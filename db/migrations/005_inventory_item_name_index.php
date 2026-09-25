<?php

return [
    'name' => '005_inventory_item_name_index',
    'sqlite' => [
        'CREATE INDEX IF NOT EXISTS inventory_items_name_idx ON inventory_items(name)',
    ],
    'mysql' => [
        "SET @inventory_items_name_idx_exists := (
            SELECT COUNT(1)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'inventory_items'
              AND index_name = 'inventory_items_name_idx'
        )",
        "SET @inventory_items_name_idx_sql := IF(
            @inventory_items_name_idx_exists = 0,
            'CREATE INDEX inventory_items_name_idx ON inventory_items(name)',
            'SELECT 1'
        )",
        'PREPARE inventory_items_name_idx_stmt FROM @inventory_items_name_idx_sql',
        'EXECUTE inventory_items_name_idx_stmt',
        'DEALLOCATE PREPARE inventory_items_name_idx_stmt',
    ],
];
