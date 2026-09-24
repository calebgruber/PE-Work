<?php

return [
    'name' => '009_show_settings',
    'sqlite' => [
        'CREATE TABLE IF NOT EXISTS show_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            show_id INTEGER NOT NULL,
            `key` TEXT NOT NULL,
            value TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE,
            UNIQUE (show_id, `key`)
        )',
    ],
    'mysql' => [
        'CREATE TABLE IF NOT EXISTS show_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            show_id INT UNSIGNED NOT NULL,
            `key` VARCHAR(120) NOT NULL,
            value TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY show_settings_unique_key (show_id, `key`),
            KEY idx_show_settings_show (show_id),
            CONSTRAINT fk_show_settings_show FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ],
];
