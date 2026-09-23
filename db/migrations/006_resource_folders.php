<?php

return [
    'name' => '006_resource_folders',
    'sqlite' => [
        'CREATE TABLE IF NOT EXISTS resource_folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        static function (PDO $pdo): void {
            if (!table_column_exists('resources', 'folder_id')) {
                $pdo->exec('ALTER TABLE resources ADD COLUMN folder_id INTEGER NULL REFERENCES resource_folders(id) ON DELETE SET NULL');
            }
        },
    ],
    'mysql' => [
        'CREATE TABLE IF NOT EXISTS resource_folders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        static function (PDO $pdo): void {
            $columnExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'resources'
                  AND column_name = 'folder_id'
            ")->fetchColumn() > 0;
            if (!$columnExists) {
                $pdo->exec('ALTER TABLE resources ADD COLUMN folder_id INT UNSIGNED NULL');
            }

            $foreignKeyExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.referential_constraints
                WHERE constraint_schema = DATABASE()
                  AND table_name = 'resources'
                  AND constraint_name = 'fk_resources_folder'
            ")->fetchColumn() > 0;
            if (!$foreignKeyExists) {
                $pdo->exec('ALTER TABLE resources ADD CONSTRAINT fk_resources_folder FOREIGN KEY (folder_id) REFERENCES resource_folders(id) ON DELETE SET NULL');
            }
        },
    ],
];
