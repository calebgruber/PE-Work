<?php

return [
    'name' => '008_users_auth',
    'sqlite' => [
        static function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                display_name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                concentration TEXT NOT NULL DEFAULT "lighting",
                must_change_password INTEGER NOT NULL DEFAULT 1,
                avatar_seed TEXT DEFAULT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_by_user_id INTEGER DEFAULT NULL,
                invited_at TEXT DEFAULT NULL,
                last_login_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            )');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users(email)');
        },
    ],
    'mysql' => [
        static function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                display_name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT "user",
                concentration VARCHAR(50) NOT NULL DEFAULT "lighting",
                must_change_password TINYINT(1) NOT NULL DEFAULT 1,
                avatar_seed VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INT UNSIGNED DEFAULT NULL,
                invited_at DATETIME DEFAULT NULL,
                last_login_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY users_email_unique (email),
                KEY idx_users_created_by (created_by_user_id),
                CONSTRAINT fk_users_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        },
    ],
];
