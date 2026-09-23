<?php

return [
    'name' => '006_resource_folders',
    'sqlite' => [
        'CREATE TABLE IF NOT EXISTS resource_folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'ALTER TABLE resources ADD COLUMN folder_id INTEGER NULL REFERENCES resource_folders(id) ON DELETE SET NULL',
    ],
    'mysql' => [
        'CREATE TABLE IF NOT EXISTS resource_folders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'ALTER TABLE resources ADD COLUMN folder_id INT UNSIGNED NULL',
        'ALTER TABLE resources ADD CONSTRAINT fk_resources_folder FOREIGN KEY (folder_id) REFERENCES resource_folders(id) ON DELETE SET NULL',
    ],
];
