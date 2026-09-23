<?php

return [
    'name' => '007_resource_folder_parents',
    'sqlite' => [
        static function (PDO $pdo): void {
            if (!table_column_exists('resource_folders', 'parent_id')) {
                $pdo->exec('ALTER TABLE resource_folders ADD COLUMN parent_id INTEGER NULL REFERENCES resource_folders(id) ON DELETE SET NULL');
            }
        },
    ],
    'mysql' => [
        static function (PDO $pdo): void {
            $columnExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND column_name = 'parent_id'
            ")->fetchColumn() > 0;
            if (!$columnExists) {
                $pdo->exec('ALTER TABLE resource_folders ADD COLUMN parent_id INT UNSIGNED NULL');
            }

            $indexExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND index_name = 'idx_resource_folders_parent_id'
            ")->fetchColumn() > 0;
            if (!$indexExists) {
                $pdo->exec('ALTER TABLE resource_folders ADD INDEX idx_resource_folders_parent_id (parent_id)');
            }

            $foreignKeyExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.referential_constraints
                WHERE constraint_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND constraint_name = 'fk_resource_folders_parent'
            ")->fetchColumn() > 0;
            if (!$foreignKeyExists) {
                $pdo->exec('ALTER TABLE resource_folders ADD CONSTRAINT fk_resource_folders_parent FOREIGN KEY (parent_id) REFERENCES resource_folders(id) ON DELETE SET NULL');
            }
        },
    ],
];
