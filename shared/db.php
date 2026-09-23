<?php

function db_driver(): string
{
    return DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite';
}

function sqlite_runtime_allowed(): bool
{
    return ALLOW_SQLITE_FOR_TESTS === true && in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (db_driver() === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        if (!sqlite_runtime_allowed()) {
            throw new RuntimeException('SQLite is disabled for normal runtime requests. Configure MySQL in config.local.php or environment variables.');
        }
        $dir = dirname(DB_SQLITE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        try {
            $journalMode = strtolower((string) ($pdo->query('PRAGMA journal_mode = WAL')->fetchColumn() ?: ''));
            if ($journalMode !== 'wal') {
                $pdo->exec('PRAGMA journal_mode = DELETE');
            }
        } catch (Throwable $e) {
            $pdo->exec('PRAGMA journal_mode = DELETE');
        }
    }

    return $pdo;
}

function table_exists(string $table): bool
{
    try {
        if (db_driver() === 'mysql') {
            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
            );
            $stmt->execute([DB_NAME, $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = db()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function migration_has_implicit_commit_statements(array $statements): bool
{
    foreach ($statements as $statement) {
        if (is_callable($statement)) {
            return true;
        }

        $sql = strtoupper(ltrim((string) $statement));
        if ($sql === '') {
            continue;
        }

        foreach (['ALTER ', 'CREATE ', 'DROP ', 'RENAME ', 'TRUNCATE ', 'PREPARE ', 'EXECUTE ', 'DEALLOCATE '] as $prefix) {
            if (str_starts_with($sql, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

function table_column_exists(string $table, string $column): bool
{
    try {
        if (!table_exists($table)) {
            return false;
        }

        if (db_driver() === 'mysql') {
            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([DB_NAME, $table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = db()->query('PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ')');
        foreach ($stmt->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function ensure_migration_tracking_table(): void
{
    if (table_exists('schema_migrations')) {
        return;
    }

    if (db_driver() === 'mysql') {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function applied_migrations(): array
{
    if (!table_exists('schema_migrations')) {
        return [];
    }

    $rows = db()->query('SELECT name, applied_at FROM schema_migrations ORDER BY id ASC')->fetchAll();
    $results = [];

    foreach ($rows as $row) {
        $results[$row['name']] = $row['applied_at'];
    }

    return $results;
}

function migration_files(): array
{
    $files = glob(__DIR__ . '/../db/migrations/*.php') ?: [];
    sort($files, SORT_NATURAL);
    return $files;
}

function run_pending_migrations(): array
{
    ensure_migration_tracking_table();

    $logs = [];
    $applied = applied_migrations();

    foreach (migration_files() as $file) {
        $migration = require $file;
        if (!is_array($migration)) {
            $logs[] = [
                'name' => basename($file),
                'status' => 'error',
                'message' => 'Migration file must return an array.',
            ];
            break;
        }

        $name = $migration['name'] ?? basename($file, '.php');
        if (isset($applied[$name])) {
            $logs[] = [
                'name' => $name,
                'status' => 'skipped',
                'message' => 'Already applied on ' . $applied[$name],
            ];
            continue;
        }

        $statements = $migration[db_driver()] ?? $migration['all'] ?? [];
        if (!is_array($statements)) {
            $logs[] = [
                'name' => $name,
                'status' => 'error',
                'message' => 'Migration statements are invalid.',
            ];
            break;
        }

        try {
            $startedTransaction = false;
            if (!db()->inTransaction() && (db_driver() !== 'mysql' || !migration_has_implicit_commit_statements($statements))) {
                db()->beginTransaction();
                $startedTransaction = true;
            }
            foreach ($statements as $statement) {
                if (is_callable($statement)) {
                    $statement(db());
                    continue;
                }

                $sql = trim((string) $statement);
                if ($sql !== '') {
                    db()->exec($sql);
                }
            }

            $stmt = db()->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
            $stmt->execute([$name]);
            if ($startedTransaction && db()->inTransaction()) {
                db()->commit();
            }

            $logs[] = [
                'name' => $name,
                'status' => 'applied',
                'message' => 'Migration applied successfully.',
            ];
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $logs[] = [
                'name' => $name,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            break;
        }
    }

    return $logs;
}

function schema_ready(): bool
{
    return table_exists('schema_migrations') && table_exists('shows');
}

function fetch_setting(string $key, ?string $default = null): ?string
{
    if (!table_exists('app_settings')) {
        return $default;
    }

    $stmt = db()->prepare('SELECT value FROM app_settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function fetch_settings_by_prefix(string $prefix): array
{
    if (!table_exists('app_settings')) {
        return [];
    }

    $stmt = db()->prepare('SELECT `key`, value FROM app_settings WHERE `key` LIKE ? ORDER BY `key` ASC');
    $stmt->execute([$prefix . '%']);

    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['key']] = $row['value'];
    }

    return $settings;
}

function save_setting(string $key, string $value): void
{
    if (!table_exists('app_settings')) {
        return;
    }

    $existing = fetch_setting($key);
    if ($existing === null) {
        $stmt = db()->prepare('INSERT INTO app_settings (`key`, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
        return;
    }

    $stmt = db()->prepare('UPDATE app_settings SET value = ? WHERE `key` = ?');
    $stmt->execute([$value, $key]);
}
