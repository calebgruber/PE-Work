<?php

return [
    'name' => '010_resource_folder_sibling_uniqueness',
    'sqlite' => [
        static function (PDO $pdo): void {
            $indexExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM sqlite_master
                WHERE type = 'index'
                  AND name = 'resource_folders_parent_name_unique'
            ")->fetchColumn() > 0;
            if ($indexExists) {
                return;
            }

            $supportsParents = table_column_exists('resource_folders', 'parent_id');
            $parentSelect = $supportsParents ? 'parent_id' : 'NULL';

            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('
                CREATE TABLE resource_folders_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    parent_id INTEGER NULL REFERENCES resource_folders_new(id) ON DELETE SET NULL,
                    UNIQUE (parent_id, name)
                )
            ');
            $rows = $pdo->query("
                SELECT id, name, created_at, {$parentSelect} AS parent_id
                FROM resource_folders
                ORDER BY COALESCE(parent_id, 0), LOWER(name), id
            ")->fetchAll(PDO::FETCH_ASSOC);
            $insert = $pdo->prepare('
                INSERT INTO resource_folders_new (id, name, created_at, parent_id)
                VALUES (?, ?, ?, ?)
            ');
            $seen = [];
            foreach ($rows as $row) {
                $parentKey = array_key_exists('parent_id', $row) && $row['parent_id'] !== null ? (string) $row['parent_id'] : 'root';
                $baseName = (string) ($row['name'] ?? 'Folder');
                $candidate = $baseName;
                $suffix = 2;
                while (isset($seen[$parentKey . '|' . strtolower($candidate)])) {
                    $candidate = $baseName . ' (' . $suffix . ')';
                    $suffix++;
                }
                $seen[$parentKey . '|' . strtolower($candidate)] = true;
                $insert->execute([
                    (int) $row['id'],
                    $candidate,
                    $row['created_at'],
                    $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                ]);
            }
            $pdo->exec('DROP TABLE resource_folders');
            $pdo->exec('ALTER TABLE resource_folders_new RENAME TO resource_folders');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resource_folders_parent_id ON resource_folders(parent_id)');
            $pdo->exec('PRAGMA foreign_keys = ON');
        },
    ],
    'mysql' => [
        static function (PDO $pdo): void {
            $singleColumnUniqueIndexes = $pdo->query("
                SELECT index_name
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                GROUP BY index_name
                HAVING SUM(CASE WHEN non_unique = 0 THEN 1 ELSE 0 END) > 0
                   AND COUNT(*) = 1
                   AND MAX(column_name = 'name') = 1
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($singleColumnUniqueIndexes as $indexName) {
                $pdo->exec('ALTER TABLE resource_folders DROP INDEX `' . str_replace('`', '``', (string) $indexName) . '`');
            }

            $generatedColumnExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND column_name = 'parent_scope_key'
            ")->fetchColumn() > 0;
            if (!$generatedColumnExists) {
                $pdo->exec('ALTER TABLE resource_folders ADD COLUMN parent_scope_key INT UNSIGNED AS (COALESCE(parent_id, 0)) STORED');
            }

            $uniqueIndexExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND index_name = 'resource_folders_parent_name_unique'
            ")->fetchColumn() > 0;
            if ($uniqueIndexExists) {
                $pdo->exec('ALTER TABLE resource_folders DROP INDEX resource_folders_parent_name_unique');
            }

            $duplicates = $pdo->query("
                SELECT parent_scope_key, name, GROUP_CONCAT(id ORDER BY id ASC) AS ids
                FROM resource_folders
                GROUP BY parent_scope_key, name
                HAVING COUNT(*) > 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            $rename = $pdo->prepare('UPDATE resource_folders SET name = ? WHERE id = ?');
            foreach ($duplicates as $duplicate) {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($duplicate['ids'] ?? '')))));
                $baseName = (string) ($duplicate['name'] ?? 'Folder');
                $used = [$baseName => true];
                foreach ($ids as $index => $id) {
                    if ($index === 0) {
                        continue;
                    }
                    $suffix = $index + 1;
                    $candidate = $baseName . ' (' . $suffix . ')';
                    while (isset($used[$candidate])) {
                        $suffix++;
                        $candidate = $baseName . ' (' . $suffix . ')';
                    }
                    $used[$candidate] = true;
                    $rename->execute([$candidate, $id]);
                }
            }

            $uniqueIndexExists = (int) $pdo->query("
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'resource_folders'
                  AND index_name = 'resource_folders_parent_name_unique'
            ")->fetchColumn() > 0;
            if (!$uniqueIndexExists) {
                $pdo->exec('ALTER TABLE resource_folders ADD UNIQUE INDEX resource_folders_parent_name_unique (parent_scope_key, name)');
            }
        },
    ],
];
