<?php

return [
    'name' => '003_remove_starter_catalog',
    'sqlite' => [
        'DELETE FROM system_rules
         WHERE note = \'Each SolaFrame typically needs one stagepin to True1 adapter.\'',
        'DELETE FROM inventory_items
         WHERE name IN (\'SolaFrame 3000\', \'Stagepin to True1 Adapter\', \'Tech Table Package\', \'Workbox\', \'Genie Lift\')
           AND description IN (
             \'Starter fixture inventory row\',
             \'Adapter example for global rules\',
             \'Control package example\',
             \'Accessory with default note example\',
             \'Lift scheduling example\'
           )',
        'DELETE FROM inventory_categories
         WHERE name IN (\'Fixtures\', \'Power\', \'Control\', \'Accessories\')
           AND id NOT IN (SELECT category_id FROM inventory_items)',
    ],
    'mysql' => [
        'DELETE FROM system_rules
         WHERE note = \'Each SolaFrame typically needs one stagepin to True1 adapter.\'',
        'DELETE FROM inventory_items
         WHERE name IN (\'SolaFrame 3000\', \'Stagepin to True1 Adapter\', \'Tech Table Package\', \'Workbox\', \'Genie Lift\')
           AND description IN (
             \'Starter fixture inventory row\',
             \'Adapter example for global rules\',
             \'Control package example\',
             \'Accessory with default note example\',
             \'Lift scheduling example\'
           )',
        'DELETE FROM inventory_categories
         WHERE name IN (\'Fixtures\', \'Power\', \'Control\', \'Accessories\')
           AND id NOT IN (SELECT category_id FROM inventory_items)',
    ],
];
