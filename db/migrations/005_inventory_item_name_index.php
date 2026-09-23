<?php

return [
    'name' => '005_inventory_item_name_index',
    'sqlite' => [
        'CREATE INDEX IF NOT EXISTS inventory_items_name_idx ON inventory_items(name)',
    ],
    'mysql' => [
        'CREATE INDEX inventory_items_name_idx ON inventory_items(name)',
    ],
];
