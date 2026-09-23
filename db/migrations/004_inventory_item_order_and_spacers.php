<?php

return [
    'name' => '004_inventory_item_order_and_spacers',
    'sqlite' => [
        'ALTER TABLE inventory_items ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE inventory_items ADD COLUMN is_spacer INTEGER NOT NULL DEFAULT 0',
        'UPDATE inventory_items
         SET sort_order = id
         WHERE sort_order = 0',
    ],
    'mysql' => [
        'ALTER TABLE inventory_items
         ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER name',
        'ALTER TABLE inventory_items
         ADD COLUMN is_spacer TINYINT(1) NOT NULL DEFAULT 0 AFTER description',
        'UPDATE inventory_items
         SET sort_order = id
         WHERE sort_order = 0',
    ],
];
