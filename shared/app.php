<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_display_name(): string
{
    $value = fetch_setting('app.name', APP_NAME);
    $value = trim((string) $value);
    return $value !== '' ? $value : APP_NAME;
}

function app_logo_svg_path(): string
{
    return sanitize_local_asset_path(fetch_setting('app.logo_svg_path', '')) ?? '';
}

function app_logo_markup(string $class = 'app-logo-image', string $alt = ''): string
{
    $path = app_logo_svg_path();
    if ($path === '') {
        return '';
    }

    return '<img src="' . h(asset_url($path)) . '" alt="' . h($alt) . '" class="' . h($class) . '">';
}

function fetch_show_settings_by_prefix(int $showId, string $prefix): array
{
    if ($showId <= 0 || !table_exists('show_settings')) {
        return [];
    }

    $stmt = db()->prepare('SELECT `key`, value FROM show_settings WHERE show_id = ? AND `key` LIKE ? ORDER BY `key` ASC');
    $stmt->execute([$showId, $prefix . '%']);

    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['key']] = $row['value'];
    }

    return $settings;
}

function save_show_setting(int $showId, string $key, string $value): void
{
    if ($showId <= 0 || !table_exists('show_settings')) {
        return;
    }

    $stmt = db()->prepare('SELECT id FROM show_settings WHERE show_id = ? AND `key` = ? LIMIT 1');
    $stmt->execute([$showId, $key]);
    $existingId = $stmt->fetchColumn();
    if ($existingId === false) {
        $stmt = db()->prepare('INSERT INTO show_settings (show_id, `key`, value) VALUES (?, ?, ?)');
        $stmt->execute([$showId, $key, $value]);
        return;
    }

    $stmt = db()->prepare('UPDATE show_settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$value, (int) $existingId]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = upload_random_suffix() . upload_random_suffix();
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    return $sessionToken !== '' && is_string($token) && hash_equals($sessionToken, $token);
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function app_base_url(): string
{
    if (APP_BASE_URL !== '') {
        return APP_BASE_URL === '/' ? '' : rtrim(APP_BASE_URL, '/');
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = trim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }

    return '/' . $dir;
}

function url_for(string $path = ''): string
{
    $base = app_base_url();
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    $url = url_for($path);
    $localPath = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($localPath)) {
        $version = @filemtime($localPath);
        if ($version) {
            return $url . '?v=' . rawurlencode((string) $version);
        }
    }

    return $url;
}

function test_auth_bypass_enabled(): bool
{
    $disabled = getenv('PE_WORK_DISABLE_TEST_AUTH_BYPASS');
    return ALLOW_SQLITE_FOR_TESTS && in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true) && $disabled !== '1';
}

function auth_tables_ready(): bool
{
    return schema_ready() && table_exists('users');
}

function user_roles(): array
{
    return [
        'admin' => 'Admin',
        'user' => 'User',
    ];
}

function user_concentrations(): array
{
    return [
        'lighting' => 'Lighting',
        'sound' => 'Sound',
    ];
}

function normalize_user_email(?string $value): string
{
    return strtolower(trim((string) $value));
}

function user_avatar_url(array $user): string
{
    $seed = trim((string) ($user['avatar_seed'] ?? ''));
    if ($seed === '') {
        $seed = trim((string) ($user['email'] ?? ''));
    }
    if ($seed === '') {
        $seed = trim((string) ($user['display_name'] ?? 'user'));
    }

    return 'https://api.dicebear.com/9.x/thumbs/svg?seed=' . rawurlencode($seed);
}

function user_initials(array $user): string
{
    $name = trim((string) ($user['display_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['email'] ?? 'U'));
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'U';
}

function role_label(?string $role): string
{
    $roles = user_roles();
    return $roles[$role ?? ''] ?? 'User';
}

function concentration_label(?string $concentration): string
{
    $concentrations = user_concentrations();
    return $concentrations[$concentration ?? ''] ?? 'Lighting';
}

function valid_domain_concentration(?string $concentration): string
{
    $value = strtolower(trim((string) $concentration));
    return array_key_exists($value, user_concentrations()) ? $value : 'lighting';
}

function show_owner_options(): array
{
    return auth_tables_ready() ? list_users() : [];
}

function show_owner_id_for_user(?array $user = null): ?int
{
    $user = $user ?? current_user();
    $userId = (int) ($user['id'] ?? 0);
    return $userId > 0 ? $userId : null;
}

function can_access_show(array $show, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$show) {
        return false;
    }
    if (is_admin($user)) {
        return true;
    }

    $ownerId = (int) ($show['owner_user_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);

    return $ownerId > 0 && $userId > 0 && $ownerId === $userId;
}

function show_concentration(?array $show): string
{
    return valid_domain_concentration($show['concentration'] ?? 'lighting');
}

function shows_support_owner_scope(): bool
{
    return table_column_exists('shows', 'owner_user_id');
}

function shows_support_concentration(): bool
{
    return table_column_exists('shows', 'concentration');
}

function user_count(): int
{
    if (!auth_tables_ready()) {
        return 0;
    }

    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function user_bootstrap_required(): bool
{
    return auth_tables_ready() && user_count() === 0;
}

function find_user_by_id(int $userId): ?array
{
    if (!auth_tables_ready() || $userId <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function find_user_by_email(string $email): ?array
{
    if (!auth_tables_ready()) {
        return null;
    }

    $normalized = normalize_user_email($email);
    if ($normalized === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$normalized]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function current_user(): array
{
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId > 0) {
        $sessionUser = find_user_by_id($sessionUserId);
        if ($sessionUser) {
            $sessionUser['avatar_url'] = user_avatar_url($sessionUser);
            return $sessionUser;
        }

        unset($_SESSION['user_id']);
    }

    if (test_auth_bypass_enabled()) {
        return [
            'id' => 0,
            'display_name' => 'Test Admin',
            'email' => 'test-admin@example.com',
            'role' => 'admin',
            'concentration' => 'lighting',
            'must_change_password' => 0,
            'avatar_seed' => 'test-admin',
            'avatar_url' => user_avatar_url(['avatar_seed' => 'test-admin']),
            'is_test_user' => 1,
        ];
    }

    return [];
}

function is_logged_in(): bool
{
    return !empty(current_user());
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return ($user['role'] ?? '') === 'admin';
}

function auth_password_change_required(?array $user = null): bool
{
    $user = $user ?? current_user();
    return (int) ($user['must_change_password'] ?? 0) === 1;
}

function current_request_path_with_query(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? url_for(''));
    if ($uri === '') {
        return url_for('');
    }

    return $uri;
}

function app_site_url(): string
{
    if (APP_SITE_URL !== '') {
        return APP_SITE_URL;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ? 'https'
        : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host . app_base_url();
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function require_login(array $options = []): void
{
    if (!schema_ready()) {
        redirect(url_for('setup'));
    }

    if (test_auth_bypass_enabled()) {
        return;
    }

    if (user_bootstrap_required()) {
        redirect(url_for('setup'));
    }

    if (!is_logged_in()) {
        $loginUrl = url_for('login');
        $returnTo = current_request_path_with_query();
        if ($returnTo !== '') {
            $loginUrl .= '?return_to=' . rawurlencode($returnTo);
        }
        redirect($loginUrl);
    }

    if (auth_password_change_required() && empty($options['allow_password_change'])) {
        if (function_exists('flash')) {
            flash('warning', 'Change your temporary password before using the rest of the system.');
        }
        redirect(url_for('profile?force_password=1'));
    }
}

function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = (int) ($user['id'] ?? 0);
    session_regenerate_id(true);
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

function authenticate_user(string $email, string $password): array
{
    if (!auth_tables_ready()) {
        return ['ok' => false, 'message' => 'Run setup before signing in.'];
    }

    if (user_bootstrap_required()) {
        return ['ok' => false, 'message' => 'Create the first admin account in setup before signing in.'];
    }

    $user = find_user_by_email($email);
    if (!$user || !(int) ($user['is_active'] ?? 0)) {
        return ['ok' => false, 'message' => 'Invalid email or password.'];
    }

    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        return ['ok' => false, 'message' => 'Invalid email or password.'];
    }

    $stmt = db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $user = find_user_by_id((int) $user['id']) ?? $user;
    login_user($user);

    return ['ok' => true, 'user' => $user];
}

function random_password(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';
    for ($index = 0; $index < $length; $index++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

function valid_user_role(string $role): string
{
    $roles = user_roles();
    return array_key_exists($role, $roles) ? $role : 'user';
}

function valid_user_concentration(string $concentration): string
{
    return valid_domain_concentration($concentration);
}

function validate_password_rules(string $password): ?string
{
    return strlen($password) < 10 ? 'Password must be at least 10 characters.' : null;
}

function send_invite_email(array $user, string $temporaryPassword): array
{
    $siteUrl = app_site_url();
    $loginUrl = $siteUrl !== '' ? rtrim($siteUrl, '/') . url_for('login') : url_for('login');
    $brandName = app_display_name();
    $subject = $brandName . ' account invite';
    $bodyLines = [
        'You have been invited to ' . $brandName . '.',
        '',
        'Sign in here: ' . $loginUrl,
        'Email: ' . (string) ($user['email'] ?? ''),
        'Temporary password: ' . $temporaryPassword,
        '',
        'You must change your password the first time you sign in.',
    ];
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if (APP_EMAIL_FROM_ADDRESS !== '') {
        $fromName = APP_EMAIL_FROM_NAME !== '' ? APP_EMAIL_FROM_NAME : $brandName;
        $headers[] = 'From: ' . sprintf('%s <%s>', str_replace(["\r", "\n"], '', $fromName), str_replace(["\r", "\n"], '', APP_EMAIL_FROM_ADDRESS));
    }

    $sent = @mail((string) ($user['email'] ?? ''), $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
    if (!$sent) {
        return ['ok' => false, 'message' => 'User saved, but the invite email could not be sent. Configure mail delivery first.'];
    }

    return ['ok' => true, 'message' => 'Invite email sent.'];
}

function bootstrap_admin_user(array $input): array
{
    if (!auth_tables_ready()) {
        return ['ok' => false, 'message' => 'Run migrations before creating the first admin.'];
    }
    if (user_count() > 0) {
        return ['ok' => false, 'message' => 'An admin account already exists.'];
    }

    $displayName = trim((string) ($input['display_name'] ?? ''));
    $email = normalize_user_email($input['email'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
    $concentration = valid_user_concentration((string) ($input['concentration'] ?? 'lighting'));

    if ($displayName === '' || $email === '') {
        return ['ok' => false, 'message' => 'Name and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }
    if ($password !== $passwordConfirmation) {
        return ['ok' => false, 'message' => 'Password confirmation does not match.'];
    }
    $passwordError = validate_password_rules($password);
    if ($passwordError !== null) {
        return ['ok' => false, 'message' => $passwordError];
    }

    $stmt = db()->prepare('
        INSERT INTO users (display_name, email, password_hash, role, concentration, must_change_password, avatar_seed, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        $displayName,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        'admin',
        $concentration,
        bin2hex(random_bytes(8)),
    ]);

    $user = find_user_by_id((int) db()->lastInsertId());
    if (!$user) {
        return ['ok' => false, 'message' => 'The admin account was created, but could not be loaded.'];
    }

    login_user($user);
    return ['ok' => true, 'user' => $user];
}

function create_user_invite(array $input, array $actor): array
{
    if (!is_admin($actor)) {
        return ['ok' => false, 'message' => 'Only admins can create users.'];
    }

    $displayName = trim((string) ($input['display_name'] ?? ''));
    $email = normalize_user_email($input['email'] ?? '');
    $role = valid_user_role((string) ($input['role'] ?? 'user'));
    $concentration = valid_user_concentration((string) ($input['concentration'] ?? 'lighting'));

    if ($displayName === '' || $email === '') {
        return ['ok' => false, 'message' => 'Name and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }
    if (find_user_by_email($email)) {
        return ['ok' => false, 'message' => 'That email already has an account.'];
    }

    $temporaryPassword = random_password();

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('
            INSERT INTO users (display_name, email, password_hash, role, concentration, must_change_password, avatar_seed, is_active, created_by_user_id, invited_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $displayName,
            $email,
            password_hash($temporaryPassword, PASSWORD_DEFAULT),
            $role,
            $concentration,
            bin2hex(random_bytes(8)),
            (int) ($actor['id'] ?? 0) ?: null,
        ]);

        $user = find_user_by_id((int) db()->lastInsertId());
        if (!$user) {
            throw new RuntimeException('Unable to load the new user.');
        }

        $mailResult = send_invite_email($user, $temporaryPassword);
        if (!$mailResult['ok']) {
            throw new RuntimeException($mailResult['message']);
        }

        db()->commit();
        return ['ok' => true, 'message' => 'User created and invite email sent.', 'user' => $user];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function update_user_profile(array $user, array $input): array
{
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $concentration = valid_user_concentration((string) ($input['concentration'] ?? ($user['concentration'] ?? 'lighting')));
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');
    $confirmPassword = (string) ($input['confirm_password'] ?? '');

    if ($displayName === '') {
        return ['ok' => false, 'message' => 'Name is required.'];
    }

    $passwordHash = (string) ($user['password_hash'] ?? '');
    $mustChangePassword = auth_password_change_required($user);
    $passwordChanged = false;

    if ($newPassword !== '' || $confirmPassword !== '' || $mustChangePassword) {
        if (!$mustChangePassword && !password_verify($currentPassword, $passwordHash)) {
            return ['ok' => false, 'message' => 'Current password is incorrect.'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['ok' => false, 'message' => 'New password confirmation does not match.'];
        }
        $passwordError = validate_password_rules($newPassword);
        if ($passwordError !== null) {
            return ['ok' => false, 'message' => $passwordError];
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $mustChangePassword = false;
        $passwordChanged = true;
    }

    $stmt = db()->prepare('
        UPDATE users
           SET display_name = ?, concentration = ?, password_hash = ?, must_change_password = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?
    ');
    $stmt->execute([
        $displayName,
        $concentration,
        $passwordHash,
        $mustChangePassword ? 1 : 0,
        (int) $user['id'],
    ]);

    $updated = find_user_by_id((int) $user['id']) ?? $user;
    login_user($updated);

    return [
        'ok' => true,
        'message' => $passwordChanged ? 'Profile updated and password changed.' : 'Profile updated.',
        'user' => $updated,
    ];
}

function list_users(): array
{
    if (!auth_tables_ready()) {
        return [];
    }

    return db()->query('SELECT * FROM users ORDER BY created_at DESC, display_name ASC')->fetchAll();
}

function nav_items(string $active = 'dashboard'): array
{
    $user = current_user();
    $items = [
        ['icon' => 'home', 'label' => 'Dashboard', 'href' => url_for(''), 'active' => $active === 'dashboard'],
        ['icon' => 'theater_comedy', 'label' => 'Shows', 'href' => url_for('show'), 'active' => $active === 'shows'],
        ['icon' => 'folder', 'label' => 'Resources', 'href' => url_for('settings?tab=resources'), 'active' => $active === 'resources'],
    ];

    if (is_admin($user)) {
        $items[] = ['icon' => 'group', 'label' => 'Users', 'href' => url_for('users'), 'active' => $active === 'users'];
        $items[] = ['icon' => 'settings', 'label' => 'Settings', 'href' => url_for('settings'), 'active' => $active === 'settings'];
    }

    return $items;
}

function normalize_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function sanitize_local_asset_path(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (str_starts_with($value, '//') || str_contains($value, '..')) {
        return null;
    }

    $parts = parse_url($value);
    if ($parts === false || !empty($parts['scheme']) || !empty($parts['host'])) {
        return null;
    }

    return ltrim($value, '/');
}

function normalize_csv_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = str_replace("\0", '', $value);
    $value = strtolower(trim($value));

    return str_replace([' ', '-'], '_', $value);
}

function normalize_csv_value(?string $value): string
{
    $value = str_replace("\0", '', trim((string) $value));

    return $value === '.' ? '' : $value;
}

function csv_stream_handle(string $tmpPath)
{
    $contents = @file_get_contents($tmpPath);
    if ($contents === false) {
        return false;
    }
    return csv_normalized_handle($contents);
}

function csv_string_handle(string $contents)
{
    return csv_normalized_handle($contents);
}

function csv_normalized_handle(string $contents)
{
    $contents = normalize_csv_contents($contents);
    $handle = fopen('php://temp', 'r+b');
    if (!$handle) {
        return false;
    }

    fwrite($handle, $contents);
    rewind($handle);
    return $handle;
}

function normalize_csv_contents(string $contents): string
{
    if (str_starts_with($contents, "\xFF\xFE")) {
        $converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($contents, 2));
        if ($converted !== false) {
            $contents = $converted;
        }
    } elseif (str_starts_with($contents, "\xFE\xFF")) {
        $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($contents, 2));
        if ($converted !== false) {
            $contents = $converted;
        }
    } elseif (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    } elseif (str_contains($contents, "\x00")) {
        $utf16Le = @iconv('UTF-16LE', 'UTF-8//IGNORE', $contents);
        $utf16Be = @iconv('UTF-16BE', 'UTF-8//IGNORE', $contents);
        if ($utf16Le !== false && preg_match('/[A-Za-z0-9]/', $utf16Le)) {
            $contents = $utf16Le;
        } elseif ($utf16Be !== false && preg_match('/[A-Za-z0-9]/', $utf16Be)) {
            $contents = $utf16Be;
        }
    }

    return str_replace(["\r\n", "\r"], "\n", $contents);
}

function csv_stream_from_handle($handle)
{
    $contents = stream_get_contents($handle);
    if ($contents === false) {
        fclose($handle);
        return false;
    }
    fclose($handle);

    $contents = normalize_csv_contents($contents);
    $normalizedHandle = fopen('php://temp', 'r+b');
    if (!$normalizedHandle) {
        return false;
    }

    fwrite($normalizedHandle, $contents);
    rewind($normalizedHandle);

    return $normalizedHandle;
}

function detect_csv_delimiter(array $headerRow): string
{
    $candidates = [',', ';', "\t"];
    $bestDelimiter = ',';
    $bestCount = -1;

    foreach ($candidates as $delimiter) {
        $count = count(str_getcsv($headerRow[0] ?? '', $delimiter));
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }

    return $bestDelimiter;
}

function import_inventory_csv_from_handle($handle): array
{
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        return ['ok' => false, 'message' => 'CSV is empty.'];
    }

    $delimiter = detect_csv_delimiter([$firstLine]);
    rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['ok' => false, 'message' => 'CSV is empty.'];
    }

    $headerMap = [];
    $allowedHeaders = ['category', 'name', 'shop_quantity', 'unit', 'default_note', 'description', 'concentration', 'domain'];
    foreach ($header as $index => $column) {
        $normalized = normalize_csv_header((string) $column);
        if ($normalized === '') {
            continue;
        }
        $headerMap[$normalized] = $index;
        if (!in_array($normalized, $allowedHeaders, true)) {
            fclose($handle);
            return ['ok' => false, 'message' => 'CSV includes unsupported headers. Use only: ' . implode(', ', $allowedHeaders) . '.'];
        }
    }

    if (!isset($headerMap['category'], $headerMap['name'])) {
        fclose($handle);
        return ['ok' => false, 'message' => 'CSV must include category and name columns.'];
    }

    $created = 0;
    $updated = 0;
    $lookupWithCategory = db()->prepare('SELECT * FROM inventory_items WHERE category_id = ? AND name = ? ORDER BY is_active DESC, id ASC LIMIT 1');
    $lookupByName = db()->prepare('SELECT * FROM inventory_items WHERE name = ? ORDER BY is_active DESC, id ASC LIMIT 1');
    $supportsSortOrder = table_column_exists('inventory_items', 'sort_order');
    $supportsSpacer = table_column_exists('inventory_items', 'is_spacer');
    $updateFields = ['name = ?', 'category_id = ?'];
    if ($supportsSortOrder) {
        $updateFields[] = 'sort_order = ?';
    }
    if (table_column_exists('inventory_items', 'concentration')) {
        $updateFields[] = 'concentration = ?';
    }
    $updateFields[] = 'shop_quantity = ?';
    $updateFields[] = 'unit = ?';
    $updateFields[] = 'default_note = ?';
    $updateFields[] = 'description = ?';
    if ($supportsSpacer) {
        $updateFields[] = 'is_spacer = ?';
    }
    $updateFields[] = 'is_active = 1';
    $updateFields[] = 'updated_at = CURRENT_TIMESTAMP';
    $updateItem = db()->prepare(
        'UPDATE inventory_items
         SET ' . implode(', ', $updateFields) . '
         WHERE id = ?'
    );
    $insertColumns = ['category_id', 'name'];
    if ($supportsSortOrder) {
        $insertColumns[] = 'sort_order';
    }
    if (table_column_exists('inventory_items', 'concentration')) {
        $insertColumns[] = 'concentration';
    }
    array_push($insertColumns, 'shop_quantity', 'unit', 'default_note', 'description');
    if ($supportsSpacer) {
        $insertColumns[] = 'is_spacer';
    }
    $insertPlaceholders = array_fill(0, count($insertColumns), '?');
    $insertItem = db()->prepare(
        'INSERT INTO inventory_items (' . implode(', ', $insertColumns) . ')
         VALUES (' . implode(', ', $insertPlaceholders) . ')'
    );
    $sourceCategoriesToNormalize = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $category = normalize_csv_value($row[$headerMap['category']] ?? '');
        $name = normalize_csv_value($row[$headerMap['name']] ?? '');
        if ($category === '' || $name === '') {
            continue;
        }

        $rowConcentration = normalize_csv_value($row[$headerMap['concentration'] ?? $headerMap['domain'] ?? -1] ?? '');
        $rowConcentration = valid_domain_concentration($rowConcentration !== '' ? $rowConcentration : 'lighting');
        $categoryId = category_id_for_name($category, $rowConcentration);
        if ($categoryId <= 0) {
            fclose($handle);
            return ['ok' => false, 'message' => 'Unable to resolve category "' . $category . '" while importing inventory.'];
        }
        $shopQuantity = max(0, (int) ($row[$headerMap['shop_quantity']] ?? 0));
        $unit = normalize_csv_value($row[$headerMap['unit']] ?? '');
        $defaultNote = normalize_csv_value($row[$headerMap['default_note']] ?? '');
        $description = normalize_csv_value($row[$headerMap['description']] ?? '');

        if ($categoryId > 0) {
            $lookupWithCategory->execute([$categoryId, $name]);
            $existing = $lookupWithCategory->fetch();
        } else {
            $existing = false;
        }
        if (!$existing) {
            $lookupByName->execute([$name]);
            $existing = $lookupByName->fetch();
        }
        $itemId = $existing['id'] ?? null;

        if ($itemId) {
            $updateParams = [$name, $categoryId];
            if ($supportsSortOrder) {
                $currentCategoryId = (int) ($existing['category_id'] ?? 0);
                $updateParams[] = $currentCategoryId === $categoryId
                    ? max(0, (int) ($existing['sort_order'] ?? 0))
                    : next_inventory_item_sort_order($categoryId);
                if ($currentCategoryId > 0 && $currentCategoryId !== $categoryId) {
                    $sourceCategoriesToNormalize[$currentCategoryId] = true;
                }
            }
            if (table_column_exists('inventory_items', 'concentration')) {
                $updateParams[] = $rowConcentration;
            }
            $updateParams = array_merge($updateParams, [$shopQuantity, $unit, $defaultNote, $description]);
            if ($supportsSpacer) {
                $updateParams[] = 0;
            }
            $updateParams[] = $itemId;
            $updateItem->execute($updateParams);
            $updated++;
        } else {
            $insertParams = [$categoryId, $name];
            if ($supportsSortOrder) {
                $insertParams[] = next_inventory_item_sort_order($categoryId);
            }
            if (table_column_exists('inventory_items', 'concentration')) {
                $insertParams[] = $rowConcentration;
            }
            $insertParams = array_merge($insertParams, [$shopQuantity, $unit, $defaultNote, $description]);
            if ($supportsSpacer) {
                $insertParams[] = 0;
            }
            $insertItem->execute($insertParams);
            $created++;
        }
    }

    fclose($handle);

    foreach (array_keys($sourceCategoriesToNormalize) as $sourceCategoryId) {
        normalize_inventory_category_sort_order((int) $sourceCategoryId);
    }

    return ['ok' => true, 'message' => sprintf('Import complete: %d created, %d updated.', $created, $updated)];
}

function blank_show(): array
{
    $user = current_user();
    return [
        'id' => null,
        'owner_user_id' => show_owner_id_for_user($user),
        'concentration' => valid_domain_concentration($user['concentration'] ?? 'lighting'),
        'show_name' => '',
        'theatre_name' => '',
        'shop_name' => '',
        'ld_name' => '',
        'ld_email' => '',
        'ld_phone' => '',
        'assistant_ld_name' => '',
        'assistant_ld_email' => '',
        'assistant_ld_phone' => '',
        'production_electrician_name' => '',
        'production_electrician_email' => '',
        'production_electrician_phone' => '',
        'shop_manager_name' => '',
        'shop_manager_email' => '',
        'shop_manager_phone' => '',
        'assistant_shop_manager_name' => '',
        'assistant_shop_manager_email' => '',
        'assistant_shop_manager_phone' => '',
        'show_image_url' => '',
        'pull_date' => '',
        'return_date' => '',
        'strike_date' => '',
        'opening_date' => '',
        'closing_date' => '',
        'theatre_address' => '',
        'shop_address' => '',
        'show_notes' => '',
        'created_at' => '',
        'updated_at' => '',
    ];
}

function show_required_labels(): array
{
    return [
        'concentration' => 'Domain',
        'show_name' => 'Show Name',
        'theatre_name' => 'Theatre Name',
        'shop_name' => 'Shop Name',
        'ld_name' => 'LD',
        'ld_email' => 'LD Email',
        'ld_phone' => 'LD Phone',
        'assistant_ld_name' => 'Assistant LD',
        'assistant_ld_email' => 'Assistant LD Email',
        'assistant_ld_phone' => 'Assistant LD Phone',
        'production_electrician_name' => 'Production Electrician',
        'production_electrician_email' => 'Production Electrician Email',
        'production_electrician_phone' => 'Production Electrician Phone',
        'shop_manager_name' => 'Shop Manager',
        'shop_manager_email' => 'Shop Manager Email',
        'shop_manager_phone' => 'Shop Manager Phone',
        'assistant_shop_manager_name' => 'Assistant Shop Manager',
        'assistant_shop_manager_email' => 'Assistant Shop Manager Email',
        'assistant_shop_manager_phone' => 'Assistant Shop Manager Phone',
    ];
}

function save_show_record(array $input, ?int $showId = null): array
{
    $show = blank_show();
    $existingShow = $showId ? find_show($showId) : null;
    $supportsOwnerScope = shows_support_owner_scope();
    $supportsConcentration = shows_support_concentration();
    if ($showId && !$existingShow) {
        return ['show' => $show, 'errors' => ['Show not found.']];
    }
    foreach ($show as $field => $value) {
        if (array_key_exists($field, $input)) {
            $show[$field] = trim((string) $input[$field]);
        }
    }
    $show['concentration'] = $supportsConcentration
        ? valid_domain_concentration($show['concentration'] ?? 'lighting')
        : 'lighting';

    $currentUser = current_user();
    if ($supportsOwnerScope && is_admin($currentUser) && auth_tables_ready()) {
        $requestedOwnerId = (int) ($input['owner_user_id'] ?? ($existingShow['owner_user_id'] ?? 0));
        $owner = $requestedOwnerId > 0 ? find_user_by_id($requestedOwnerId) : null;
        $show['owner_user_id'] = $owner ? (int) $owner['id'] : show_owner_id_for_user($currentUser);
    } elseif ($supportsOwnerScope && $existingShow) {
        $show['owner_user_id'] = (int) ($existingShow['owner_user_id'] ?? 0) ?: show_owner_id_for_user($currentUser);
    } elseif ($supportsOwnerScope) {
        $show['owner_user_id'] = show_owner_id_for_user($currentUser);
    } else {
        $show['owner_user_id'] = null;
    }

    foreach (['pull_date', 'return_date', 'strike_date', 'opening_date', 'closing_date'] as $dateField) {
        $show[$dateField] = normalize_date($show[$dateField]);
    }
    $show['show_image_url'] = sanitize_local_asset_path($show['show_image_url']);

    $errors = [];
    foreach (show_required_labels() as $field => $label) {
        if ($show[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }
    if (!empty($input['show_image_url']) && $show['show_image_url'] === null) {
        $errors[] = 'Show image must be an app-relative path, not an external URL.';
    }
    if ($supportsOwnerScope && (int) ($show['owner_user_id'] ?? 0) <= 0 && user_count() > 0) {
        $errors[] = 'Choose who owns this show.';
    }

    if ($errors) {
        return ['show' => $show, 'errors' => $errors];
    }

    if ($showId) {
        $show['id'] = $showId;
        $updateFields = [];
        $updateParams = [];
        if ($supportsOwnerScope) {
            $updateFields[] = 'owner_user_id = ?';
            $updateParams[] = $show['owner_user_id'];
        }
        if ($supportsConcentration) {
            $updateFields[] = 'concentration = ?';
            $updateParams[] = $show['concentration'];
        }
        $updateFields = array_merge($updateFields, [
            'show_name = ?', 'theatre_name = ?', 'shop_name = ?',
            'ld_name = ?', 'ld_email = ?', 'ld_phone = ?',
            'assistant_ld_name = ?', 'assistant_ld_email = ?', 'assistant_ld_phone = ?',
            'production_electrician_name = ?', 'production_electrician_email = ?', 'production_electrician_phone = ?',
            'shop_manager_name = ?', 'shop_manager_email = ?', 'shop_manager_phone = ?',
            'assistant_shop_manager_name = ?', 'assistant_shop_manager_email = ?', 'assistant_shop_manager_phone = ?',
            'show_image_url = ?', 'pull_date = ?', 'return_date = ?', 'strike_date = ?', 'opening_date = ?', 'closing_date = ?',
            'theatre_address = ?', 'shop_address = ?', 'show_notes = ?', 'updated_at = CURRENT_TIMESTAMP',
        ]);
        $stmt = db()->prepare('UPDATE shows SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
        $stmt->execute(array_merge($updateParams, [
            $show['show_name'], $show['theatre_name'], $show['shop_name'],
            $show['ld_name'], $show['ld_email'], $show['ld_phone'],
            $show['assistant_ld_name'], $show['assistant_ld_email'], $show['assistant_ld_phone'],
            $show['production_electrician_name'], $show['production_electrician_email'], $show['production_electrician_phone'],
            $show['shop_manager_name'], $show['shop_manager_email'], $show['shop_manager_phone'],
            $show['assistant_shop_manager_name'], $show['assistant_shop_manager_email'], $show['assistant_shop_manager_phone'],
            $show['show_image_url'], $show['pull_date'], $show['return_date'], $show['strike_date'], $show['opening_date'], $show['closing_date'],
            $show['theatre_address'], $show['shop_address'], $show['show_notes'],
            $showId,
        ]));
    } else {
        $columns = [];
        $placeholders = [];
        $insertParams = [];
        if ($supportsOwnerScope) {
            $columns[] = 'owner_user_id';
            $placeholders[] = '?';
            $insertParams[] = $show['owner_user_id'];
        }
        if ($supportsConcentration) {
            $columns[] = 'concentration';
            $placeholders[] = '?';
            $insertParams[] = $show['concentration'];
        }
        $columns = array_merge($columns, [
            'show_name', 'theatre_name', 'shop_name',
            'ld_name', 'ld_email', 'ld_phone',
            'assistant_ld_name', 'assistant_ld_email', 'assistant_ld_phone',
            'production_electrician_name', 'production_electrician_email', 'production_electrician_phone',
            'shop_manager_name', 'shop_manager_email', 'shop_manager_phone',
            'assistant_shop_manager_name', 'assistant_shop_manager_email', 'assistant_shop_manager_phone',
            'show_image_url', 'pull_date', 'return_date', 'strike_date', 'opening_date', 'closing_date',
            'theatre_address', 'shop_address', 'show_notes',
        ]);
        $placeholders = array_merge($placeholders, array_fill(0, 27, '?'));
        $stmt = db()->prepare(
            'INSERT INTO shows (' . implode(', ', $columns) . ')
             VALUES (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute(array_merge($insertParams, [
            $show['show_name'], $show['theatre_name'], $show['shop_name'],
            $show['ld_name'], $show['ld_email'], $show['ld_phone'],
            $show['assistant_ld_name'], $show['assistant_ld_email'], $show['assistant_ld_phone'],
            $show['production_electrician_name'], $show['production_electrician_email'], $show['production_electrician_phone'],
            $show['shop_manager_name'], $show['shop_manager_email'], $show['shop_manager_phone'],
            $show['assistant_shop_manager_name'], $show['assistant_shop_manager_email'], $show['assistant_shop_manager_phone'],
            $show['show_image_url'], $show['pull_date'], $show['return_date'], $show['strike_date'], $show['opening_date'], $show['closing_date'],
            $show['theatre_address'], $show['shop_address'], $show['show_notes'],
        ]));
        $show['id'] = (int) db()->lastInsertId();
    }

    return ['show' => find_show((int) $show['id']), 'errors' => []];
}

function list_shows(): array
{
    if (!schema_ready()) {
        return [];
    }
    $user = current_user();
    $supportsOwnerScope = shows_support_owner_scope();
    $supportsConcentration = shows_support_concentration();
    $ownerFilter = $supportsOwnerScope && !is_admin($user) ? show_owner_id_for_user($user) : null;
    $ownerJoin = $supportsOwnerScope ? 'LEFT JOIN users u ON u.id = s.owner_user_id' : 'LEFT JOIN users u ON 1 = 0';
    $concentrationSelect = $supportsConcentration ? 's.concentration' : '"lighting" AS concentration';
    $sql = 'SELECT
            s.*,
            ' . $concentrationSelect . ',
            u.display_name AS owner_display_name,
            u.email AS owner_email,
            (SELECT revision_code FROM show_revisions sr WHERE sr.show_id = s.id ORDER BY revision_index DESC LIMIT 1) AS latest_revision_code,
            (SELECT revision_date FROM show_revisions sr WHERE sr.show_id = s.id ORDER BY revision_index DESC LIMIT 1) AS latest_revision_date,
            (SELECT COUNT(*) FROM show_revisions sr WHERE sr.show_id = s.id) AS revision_count
         FROM shows s
         ' . $ownerJoin;
    $params = [];
    if ($ownerFilter !== null) {
        $sql .= ' WHERE s.owner_user_id = ?';
        $params[] = $ownerFilter;
    }
    $sql .= ' ORDER BY s.updated_at DESC, s.show_name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function list_shows_grouped_by_owner(): array
{
    $groups = [];
    foreach (list_users() as $user) {
        $groups[(int) $user['id']] = [
            'user' => $user,
            'shows' => [],
        ];
    }

    foreach (list_shows() as $show) {
        $ownerId = (int) ($show['owner_user_id'] ?? 0);
        if (!isset($groups[$ownerId])) {
            $groups[$ownerId] = [
                'user' => [
                    'id' => 0,
                    'display_name' => 'Unassigned',
                    'email' => '',
                    'concentration' => $show['concentration'] ?? 'lighting',
                ],
                'shows' => [],
            ];
        }
        $groups[$ownerId]['shows'][] = $show;
    }

    return array_values(array_filter($groups, static fn (array $group): bool => !empty($group['shows'])));
}

function find_show_unrestricted(int $showId): ?array
{
    $supportsOwnerScope = shows_support_owner_scope();
    $supportsConcentration = shows_support_concentration();
    $ownerJoin = $supportsOwnerScope ? 'LEFT JOIN users u ON u.id = s.owner_user_id' : 'LEFT JOIN users u ON 1 = 0';
    $concentrationSelect = $supportsConcentration ? 's.concentration' : '"lighting" AS concentration';
    $stmt = db()->prepare(
        'SELECT s.*, ' . $concentrationSelect . ', u.display_name AS owner_display_name, u.email AS owner_email
         FROM shows s
         ' . $ownerJoin . '
         WHERE s.id = ?'
    );
    $stmt->execute([$showId]);
    $show = $stmt->fetch();
    return $show;
}

function find_show(int $showId): ?array
{
    $show = find_show_unrestricted($showId);
    if (!$show || !can_access_show($show)) {
        return null;
    }
    return $show;
}

function dashboard_stats(): array
{
    if (!schema_ready()) {
        return ['shows' => 0, 'items' => 0, 'revisions' => 0, 'rules' => 0];
    }
    $user = current_user();
    $supportsOwnerScope = shows_support_owner_scope();
    $ownerFilter = $supportsOwnerScope && !is_admin($user) ? show_owner_id_for_user($user) : null;
    if ($ownerFilter !== null) {
        $showCountStmt = db()->prepare('SELECT COUNT(*) FROM shows WHERE owner_user_id = ?');
        $showCountStmt->execute([$ownerFilter]);
        $revisionCountStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM show_revisions sr
             INNER JOIN shows s ON s.id = sr.show_id
             WHERE s.owner_user_id = ?'
        );
        $revisionCountStmt->execute([$ownerFilter]);
        $showCount = (int) $showCountStmt->fetchColumn();
        $revisionCount = (int) $revisionCountStmt->fetchColumn();
    } else {
        $showCount = (int) db()->query('SELECT COUNT(*) FROM shows')->fetchColumn();
        $revisionCount = (int) db()->query('SELECT COUNT(*) FROM show_revisions')->fetchColumn();
    }
    return [
        'shows' => $showCount,
        'items' => (int) db()->query('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1')->fetchColumn(),
        'revisions' => $revisionCount,
        'rules' => (int) db()->query('SELECT COUNT(*) FROM system_rules')->fetchColumn(),
    ];
}

function fetch_categories(?string $concentration = null): array
{
    if (!table_exists('inventory_categories')) {
        return [];
    }

    $supportsConcentration = table_column_exists('inventory_categories', 'concentration');
    $sql = 'SELECT * FROM inventory_categories';
    $params = [];
    if ($supportsConcentration) {
        if ($concentration !== null) {
            $sql .= ' WHERE concentration = ?';
            $params[] = valid_domain_concentration($concentration);
        }
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$supportsConcentration) {
        foreach ($rows as &$row) {
            $row['concentration'] = 'lighting';
        }
        unset($row);
    }

    return $rows;
}

function fetch_inventory_catalog(?string $concentration = null): array
{
    $normalizedConcentration = $concentration !== null ? valid_domain_concentration($concentration) : null;
    $categories = fetch_categories($normalizedConcentration);
    $itemOrderSql = table_column_exists('inventory_items', 'sort_order') ? 'COALESCE(i.sort_order, 0), ' : '';
    $extraSelect = [];
    $supportsCategoryConcentration = table_column_exists('inventory_categories', 'concentration');
    $supportsItemConcentration = table_column_exists('inventory_items', 'concentration');
    if (!table_column_exists('inventory_items', 'sort_order')) {
        $extraSelect[] = '0 AS sort_order';
    }
    if (!table_column_exists('inventory_items', 'is_spacer')) {
        $extraSelect[] = '0 AS is_spacer';
    }
    if (!$supportsItemConcentration) {
        $extraSelect[] = ($supportsCategoryConcentration ? 'COALESCE(c.concentration, "lighting")' : '"lighting"') . ' AS concentration';
    }
    $selectSuffix = $extraSelect ? ', ' . implode(', ', $extraSelect) : '';
    if (!table_exists('inventory_items')) {
        return [];
    }
    $categoryConcentrationExpr = $supportsCategoryConcentration
        ? ($supportsItemConcentration ? 'COALESCE(c.concentration, i.concentration, "lighting")' : 'COALESCE(c.concentration, "lighting")')
        : ($supportsItemConcentration ? 'COALESCE(i.concentration, "lighting")' : '"lighting"');
    $filterConcentrationExpr = $supportsItemConcentration
        ? ($supportsCategoryConcentration ? 'COALESCE(i.concentration, c.concentration, "lighting")' : 'COALESCE(i.concentration, "lighting")')
        : ($supportsCategoryConcentration ? 'COALESCE(c.concentration, "lighting")' : '"lighting"');
    $sql = 'SELECT i.*' . $selectSuffix . ', c.name AS category_name, ' . $categoryConcentrationExpr . ' AS category_concentration
             FROM inventory_items i
             LEFT JOIN inventory_categories c ON c.id = i.category_id
             WHERE i.is_active = 1';
    $params = [];
    if ($normalizedConcentration !== null) {
        $sql .= ' AND ' . $filterConcentrationExpr . ' = ?';
        $params[] = $normalizedConcentration;
    }
    $sql .= ' ORDER BY COALESCE(c.sort_order, 9999), COALESCE(c.name, "Uncategorized"), ' . $itemOrderSql . ' i.name, i.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $byCategory = [];
    foreach ($categories as $category) {
        $category['items'] = [];
        $byCategory[$category['id']] = $category;
    }

    foreach ($items as $item) {
        $categoryId = $item['category_id'] ?: 0;
        if (!isset($byCategory[$categoryId])) {
            $byCategory[$categoryId] = [
                'id' => 0,
                'name' => 'Uncategorized',
                'sort_order' => 9999,
                'concentration' => $item['concentration'] ?? 'lighting',
                'items' => [],
            ];
        }
        $byCategory[$categoryId]['items'][] = $item;
    }

    return array_values($byCategory);
}

function find_latest_revision(int $showId): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? ORDER BY revision_index DESC LIMIT 1');
    $stmt->execute([$showId]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function find_initial_revision(int $showId): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? AND is_initial = 1 ORDER BY revision_index ASC LIMIT 1');
    $stmt->execute([$showId]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function list_revisions(int $showId): array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? ORDER BY revision_index DESC');
    $stmt->execute([$showId]);
    return $stmt->fetchAll();
}

function delete_show_revision(int $revisionId): array
{
    $revision = find_revision($revisionId);
    if (!$revision) {
        return ['ok' => false, 'message' => 'Revision not found.'];
    }
    if (!empty($revision['is_initial'])) {
        return ['ok' => false, 'message' => 'Delete later revisions only. Keep the initial order as the base record.'];
    }

    $stmt = db()->prepare('DELETE FROM show_revisions WHERE id = ?');
    $stmt->execute([$revisionId]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'Revision not found.'];
    }

    return ['ok' => true, 'message' => 'Revision deleted.'];
}

function find_revision(int $revisionId): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE id = ?');
    $stmt->execute([$revisionId]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function find_revision_by_identity(int $showId, string $revisionCode, int $revisionIndex): ?array
{
    $stmt = db()->prepare('SELECT * FROM show_revisions WHERE show_id = ? AND revision_code = ? AND revision_index = ? LIMIT 1');
    $stmt->execute([$showId, $revisionCode, $revisionIndex]);
    $revision = $stmt->fetch();
    return $revision ?: null;
}

function revision_code_for_index(int $index): string
{
    return '1.' . $index;
}

function revision_display_code(array $revision): string
{
    return revision_code_for_index((int) ($revision['revision_index'] ?? 0));
}

function create_initial_revision(int $showId): int
{
    $pdo = db();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO show_revisions (show_id, revision_code, revision_index, revision_date, is_initial, summary_note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$showId, revision_code_for_index(0), 0, date('Y-m-d'), 1, 'Initial shop order']);
        $revisionId = (int) $pdo->lastInsertId();
        seed_revision_items($revisionId);
        $pdo->commit();
        return $revisionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_unique_constraint_violation($e)) {
            $existing = find_initial_revision($showId);
            if ($existing) {
                return (int) $existing['id'];
            }
        }
        throw $e;
    }
}

function create_next_revision(int $showId): int
{
    $pdo = db();
    $attempts = 0;

    while ($attempts < 3) {
        $attempts++;
        try {
            $pdo->beginTransaction();
            $latest = find_latest_revision($showId);
            if (!$latest || ((int) ($latest['is_initial'] ?? 0) !== 1 && !find_initial_revision($showId))) {
                throw new RuntimeException('Create the initial order before adding revisions.');
            }

            $nextIndex = (int) $latest['revision_index'] + 1;
            $code = revision_code_for_index($nextIndex);

            $stmt = $pdo->prepare(
                'INSERT INTO show_revisions (show_id, revision_code, revision_index, revision_date, is_initial, summary_note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$showId, $code, $nextIndex, date('Y-m-d'), 0, 'Revision created from ' . $latest['revision_code']]);
            $revisionId = (int) $pdo->lastInsertId();
            seed_revision_items($revisionId, (int) $latest['id'], true);
            $pdo->commit();
            return $revisionId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_unique_constraint_violation($e) && $attempts < 3) {
                continue;
            }
            throw $e;
        }
    }

    throw new RuntimeException('Unable to create the next revision right now. Please try again.');
}

function seed_revision_items(int $revisionId, ?int $sourceRevisionId = null, bool $resetRevisionMarkers = false): void
{
    if ($sourceRevisionId) {
        $rows = db()->prepare(
            'SELECT inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
             FROM revision_items WHERE revision_id = ? ORDER BY inventory_item_id ASC'
        );
        $rows->execute([$sourceRevisionId]);
        $items = $rows->fetchAll();
    } else {
        $items = [];
    }

    $insert = db()->prepare(
        'INSERT INTO revision_items (
            revision_id, inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $insert->execute([
            $revisionId,
            $item['inventory_item_id'],
            (int) ($item['rent_quantity'] ?? 0),
            (int) ($item['spare_quantity'] ?? 0),
            (int) ($item['total_quantity'] ?? 0),
            $resetRevisionMarkers ? '' : (string) ($item['action'] ?? ''),
            (string) ($item['line_note'] ?? ''),
            $item['pickup_date'] ?: null,
            $item['return_date'] ?: null,
        ]);
    }
}

function normalize_revision_line_input(array $row): array
{
    $rent = max(0, (int) ($row['rent_quantity'] ?? 0));
    $spares = max(0, (int) ($row['spare_quantity'] ?? 0));
    $action = (string) ($row['action'] ?? '');
    if (!in_array($action, ['', 'add', 'return', 'exchange', 'note'], true)) {
        $action = '';
    }

    return [
        'rent_quantity' => $rent,
        'spare_quantity' => $spares,
        'total_quantity' => $rent + $spares,
        'action' => $action,
        'line_note' => trim((string) ($row['line_note'] ?? '')),
        'pickup_date' => normalize_date($row['pickup_date'] ?? ''),
        'return_date' => normalize_date($row['return_date'] ?? ''),
    ];
}

function normalize_revision_lines_input(array $items): array
{
    $normalized = [];
    foreach ($items as $itemId => $row) {
        $normalized[(int) $itemId] = normalize_revision_line_input(is_array($row) ? $row : []);
    }
    return $normalized;
    return $normalized;
}
function revision_request_items(array $input): array
{
    $payload = $input['revision_payload'] ?? null;
    if (is_string($payload) && trim($payload) !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return is_array($input['items'] ?? null) ? $input['items'] : [];
}

function revision_input_snapshot(int $revisionId, array $overrides = []): array
{
    $snapshot = [];
    if ($revisionId > 0) {
        $stmt = db()->prepare('SELECT * FROM revision_items WHERE revision_id = ?');
        $stmt->execute([$revisionId]);
        foreach ($stmt->fetchAll() as $row) {
            $snapshot[(int) $row['inventory_item_id']] = normalize_revision_line_input($row);
        }
    }

    foreach (normalize_revision_lines_input($overrides) as $itemId => $row) {
        $snapshot[(int) $itemId] = $row;
    }

    return $snapshot;
}

function revision_validation_warnings(array $items, ?string $concentration = null): array
{
    $warnings = [];
    $normalized = normalize_revision_lines_input($items);
    $supportsSpacer = table_column_exists('inventory_items', 'is_spacer');
    $supportsConcentration = table_column_exists('inventory_items', 'concentration');
    $sql = 'SELECT id, name, shop_quantity' . ($supportsSpacer ? ', is_spacer' : ', 0 AS is_spacer')
        . ($supportsConcentration ? ', concentration' : ', "lighting" AS concentration') . '
         FROM inventory_items
         WHERE is_active = 1';
    $params = [];
    if ($concentration !== null && $supportsConcentration) {
        $sql .= ' AND concentration = ?';
        $params[] = valid_domain_concentration($concentration);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $inventory = $stmt->fetchAll();

    $rentByItem = [];
    foreach ($inventory as $item) {
        $itemId = (int) $item['id'];
        $line = $normalized[$itemId] ?? normalize_revision_line_input([]);
        $rentByItem[$itemId] = (int) $line['rent_quantity'];

        if (!empty($item['is_spacer'])) {
            continue;
        }

        $total = (int) $line['total_quantity'];
        $shopQuantity = (int) ($item['shop_quantity'] ?? 0);
        if ($total > $shopQuantity) {
            $warnings[] = [
                'type' => 'stock',
                'item_id' => $itemId,
                'message' => $item['name'] . ' exceeds shop stock (' . $total . ' requested, ' . $shopQuantity . ' available).',
            ];
        }
    }

    foreach (fetch_rules($concentration) as $rule) {
        $triggerQty = max(1, (int) ($rule['trigger_quantity'] ?? 0));
        $requiredQty = max(1, (int) ($rule['required_quantity'] ?? 0));
        $triggerCurrent = $rentByItem[(int) ($rule['trigger_item_id'] ?? 0)] ?? 0;
        $requiredCurrent = $rentByItem[(int) ($rule['required_item_id'] ?? 0)] ?? 0;
        if ($triggerCurrent < $triggerQty) {
            continue;
        }

        $recommended = (int) ceil($triggerCurrent / $triggerQty) * $requiredQty;
        if ($requiredCurrent >= $recommended) {
            continue;
        }

        $message = 'Rule required: ' . $triggerCurrent . ' ' . $rule['trigger_item_name'] . ' rented means at least ' . $recommended . ' ' . $rule['required_item_name'] . '.';
        if (!empty($rule['note'])) {
            $message .= ' ' . trim((string) $rule['note']);
        }

        $warnings[] = [
            'type' => 'rule',
            'item_id' => (int) ($rule['required_item_id'] ?? 0),
            'message' => $message,
        ];
    }

    return $warnings;
}

function revision_concentration(int $revisionId): string
{
    $expr = shows_support_concentration() ? 'COALESCE(s.concentration, "lighting")' : '"lighting"';
    $stmt = db()->prepare(
        'SELECT ' . $expr . '
         FROM show_revisions sr
         INNER JOIN shows s ON s.id = sr.show_id
         WHERE sr.id = ?'
    );
    $stmt->execute([$revisionId]);
    return valid_domain_concentration((string) ($stmt->fetchColumn() ?: 'lighting'));
}

function catalog_for_revision(?int $revisionId, array $overrides = []): array
{
    $catalog = fetch_inventory_catalog($revisionId ? revision_concentration($revisionId) : null);
    $lineItems = [];
    $overrideLines = normalize_revision_lines_input($overrides);

    if ($revisionId) {
        $stmt = db()->prepare('SELECT * FROM revision_items WHERE revision_id = ?');
        $stmt->execute([$revisionId]);
        foreach ($stmt->fetchAll() as $line) {
            $lineItems[(int) $line['inventory_item_id']] = $line;
        }
    }

    foreach ($catalog as &$category) {
        foreach ($category['items'] as &$item) {
            $line = $lineItems[(int) $item['id']] ?? [
                'rent_quantity' => 0,
                'spare_quantity' => 0,
                'total_quantity' => 0,
                'action' => '',
                'line_note' => '',
                'pickup_date' => null,
                'return_date' => null,
            ];
            if (isset($overrideLines[(int) $item['id']])) {
                $line = array_merge($line, $overrideLines[(int) $item['id']]);
            }
            $item['line'] = $line;
        }
        unset($item);
    }
    unset($category);

    return $catalog;
}

function save_revision_lines(int $revisionId, array $items): void
{
    $allowedConcentration = $revisionId > 0 ? revision_concentration($revisionId) : null;
    $allowedIds = [];
    $sql = 'SELECT id FROM inventory_items WHERE is_active = 1';
    $params = [];
    if ($allowedConcentration !== null && table_column_exists('inventory_items', 'concentration')) {
        $sql .= ' AND concentration = ?';
        $params[] = $allowedConcentration;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $allowedIds[(int) $row['id']] = true;
    }

    $lookup = db()->prepare('SELECT id FROM revision_items WHERE revision_id = ? AND inventory_item_id = ?');
    $update = db()->prepare(
        'UPDATE revision_items
         SET rent_quantity = ?, spare_quantity = ?, total_quantity = ?, action = ?, line_note = ?, pickup_date = ?, return_date = ?, updated_at = CURRENT_TIMESTAMP
         WHERE revision_id = ? AND inventory_item_id = ?'
    );
    $insert = db()->prepare(
        'INSERT INTO revision_items (
            revision_id, inventory_item_id, rent_quantity, spare_quantity, total_quantity, action, line_note, pickup_date, return_date
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $itemId => $row) {
        $itemId = (int) $itemId;
        if (!isset($allowedIds[$itemId])) {
            continue;
        }

        $line = normalize_revision_line_input(is_array($row) ? $row : []);
        $rent = (int) $line['rent_quantity'];
        $spares = (int) $line['spare_quantity'];
        $total = (int) $line['total_quantity'];
        $action = (string) $line['action'];
        $lineNote = (string) $line['line_note'];
        $pickupDate = $line['pickup_date'];
        $returnDate = $line['return_date'];

        $lookup->execute([$revisionId, $itemId]);
        if ($lookup->fetchColumn()) {
            $update->execute([$rent, $spares, $total, $action, $lineNote, $pickupDate, $returnDate, $revisionId, $itemId]);
        } else {
            $insert->execute([$revisionId, $itemId, $rent, $spares, $total, $action, $lineNote, $pickupDate, $returnDate]);
        }
    }
}

function revision_totals(int $revisionId): array
{
    $stmt = db()->prepare('SELECT SUM(rent_quantity) AS rent_total, SUM(spare_quantity) AS spare_total, SUM(total_quantity) AS overall_total FROM revision_items WHERE revision_id = ?');
    $stmt->execute([$revisionId]);
    $totals = $stmt->fetch() ?: [];
    return [
        'rent_total' => (int) ($totals['rent_total'] ?? 0),
        'spare_total' => (int) ($totals['spare_total'] ?? 0),
        'overall_total' => (int) ($totals['overall_total'] ?? 0),
    ];
}

function fetch_rules(?string $concentration = null): array
{
    if (!table_exists('system_rules')) {
        return [];
    }
    $supportsCategoryConcentration = table_column_exists('inventory_categories', 'concentration');
    $supportsItemConcentration = table_column_exists('inventory_items', 'concentration');
    $ruleConcentrationSql = $supportsItemConcentration
        ? 'COALESCE(ti.concentration, ri.concentration, "lighting")'
        : ($supportsCategoryConcentration
            ? 'COALESCE(tc.concentration, rc.concentration, "lighting")'
            : '"lighting"');
    $sql = 'SELECT
            r.*,
            ti.name AS trigger_item_name,
            ri.name AS required_item_name,
            ' . $ruleConcentrationSql . ' AS concentration
         FROM system_rules r
         LEFT JOIN inventory_items ti ON ti.id = r.trigger_item_id
         LEFT JOIN inventory_items ri ON ri.id = r.required_item_id
         LEFT JOIN inventory_categories tc ON tc.id = ti.category_id
         LEFT JOIN inventory_categories rc ON rc.id = ri.category_id';
    $params = [];
    if ($concentration !== null) {
        $sql .= ' WHERE ' . $ruleConcentrationSql . ' = ?';
        $params[] = valid_domain_concentration($concentration);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rule_suggestions(int $revisionId): array
{
    $rules = fetch_rules(revision_concentration($revisionId));
    if (!$rules) {
        return [];
    }

    $stmt = db()->prepare('SELECT inventory_item_id, rent_quantity FROM revision_items WHERE revision_id = ?');
    $stmt->execute([$revisionId]);
    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $totals[(int) $row['inventory_item_id']] = (int) $row['rent_quantity'];
    }

    $suggestions = [];
    foreach ($rules as $rule) {
        $triggerId = (int) $rule['trigger_item_id'];
        $requiredId = (int) $rule['required_item_id'];
        $triggerQty = max(1, (int) $rule['trigger_quantity']);
        $requiredQty = max(1, (int) $rule['required_quantity']);
        $currentTrigger = $totals[$triggerId] ?? 0;

        if ($currentTrigger < $triggerQty) {
            continue;
        }

        $multiplier = (int) ceil($currentTrigger / $triggerQty);
        $recommended = $multiplier * $requiredQty;
        $currentRequired = $totals[$requiredId] ?? 0;

        if ($currentRequired < $recommended) {
            $suggestions[] = [
                'rule' => $rule,
                'recommended_quantity' => $recommended,
                'current_quantity' => $currentRequired,
                'is_short' => true,
            ];
        }
    }

    return $suggestions;
}

function save_inventory_batch(array $items): void
{
    $allowedItems = [];
    foreach (db()->query('SELECT id, category_id FROM inventory_items WHERE is_active = 1')->fetchAll() as $row) {
        $allowedItems[(int) $row['id']] = (int) ($row['category_id'] ?? 0);
    }

    $supportsSortOrder = table_column_exists('inventory_items', 'sort_order');
    $supportsSpacer = table_column_exists('inventory_items', 'is_spacer');
    $supportsConcentration = table_column_exists('inventory_items', 'concentration');
    $categoryConcentrations = [];
    if ($supportsConcentration && table_exists('inventory_categories')) {
        foreach (db()->query('SELECT id, concentration FROM inventory_categories')->fetchAll() as $categoryRow) {
            $categoryConcentrations[(int) $categoryRow['id']] = valid_domain_concentration((string) ($categoryRow['concentration'] ?? 'lighting'));
        }
    }
    $updateFields = 'category_id = ?';
    if ($supportsConcentration) {
        $updateFields .= ', concentration = ?';
    }
    $updateFields .= ', shop_quantity = ?, unit = ?, default_note = ?, description = ?';
    if ($supportsSortOrder) {
        $updateFields .= ', sort_order = ?';
    }
    if ($supportsSpacer) {
        $updateFields .= ', is_spacer = ?';
    }
    $updateFields .= ', updated_at = CURRENT_TIMESTAMP';
    $stmt = db()->prepare('UPDATE inventory_items SET ' . $updateFields . ' WHERE id = ?');
    $touchedCategories = [];

    foreach ($items as $itemId => $row) {
        $itemId = (int) $itemId;
        if (!isset($allowedItems[$itemId])) {
            continue;
        }

        $existingCategoryId = $allowedItems[$itemId];
        $categoryId = isset($row['category_id']) ? max(0, (int) $row['category_id']) : $existingCategoryId;
        if ($categoryId <= 0) {
            $categoryId = $existingCategoryId;
        }
        $itemConcentration = $supportsConcentration ? ($categoryConcentrations[$categoryId] ?? 'lighting') : null;
        $params = [
            $categoryId,
        ];
        if ($supportsConcentration) {
            $params[] = $itemConcentration;
        }
        $params = array_merge($params, [
            max(0, (int) ($row['shop_quantity'] ?? 0)),
            trim((string) ($row['unit'] ?? '')),
            trim((string) ($row['default_note'] ?? '')),
            trim((string) ($row['description'] ?? '')),
        ]);
        if ($supportsSortOrder) {
            $params[] = max(0, (int) ($row['sort_order'] ?? 0));
        }
        if ($supportsSpacer) {
            $params[] = !empty($row['is_spacer']) ? 1 : 0;
        }
        $params[] = $itemId;
        $stmt->execute($params);
        $touchedCategories[$existingCategoryId] = true;
        $touchedCategories[$categoryId] = true;
    }

    if ($supportsSortOrder) {
        foreach (array_keys($touchedCategories) as $categoryId) {
            normalize_inventory_category_sort_order((int) $categoryId);
        }
    }
}

function next_inventory_item_sort_order(?int $categoryId): int
{
    if (!table_column_exists('inventory_items', 'sort_order')) {
        return 0;
    }

    if ($categoryId && $categoryId > 0) {
        $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM inventory_items WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        return (int) $stmt->fetchColumn();
    }

    return (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM inventory_items')->fetchColumn();
}

function normalize_inventory_category_sort_order(int $categoryId): void
{
    if ($categoryId <= 0 || !table_column_exists('inventory_items', 'sort_order')) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id
         FROM inventory_items
         WHERE category_id = ? AND is_active = 1
         ORDER BY COALESCE(sort_order, 0) ASC, name ASC, id ASC'
    );
    $stmt->execute([$categoryId]);
    $update = db()->prepare('UPDATE inventory_items SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $sortOrder = 1;
    foreach ($stmt->fetchAll() as $row) {
        $update->execute([$sortOrder, (int) $row['id']]);
        $sortOrder++;
    }
}

function unique_inventory_item_name(int $categoryId, string $baseName): string
{
    $baseName = trim($baseName) !== '' ? trim($baseName) : 'Spacer';
    $name = $baseName;
    $suffix = 2;
    $stmt = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE category_id = ? AND name = ? AND is_active = 1');

    while (true) {
        $stmt->execute([$categoryId, $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $name;
        }
        $name = $baseName . ' ' . $suffix;
        $suffix++;
    }
}

function shift_inventory_item_sort_orders(int $categoryId, int $minimumSortOrder): void
{
    if (!table_column_exists('inventory_items', 'sort_order')) {
        return;
    }

    $stmt = db()->prepare(
        'UPDATE inventory_items
         SET sort_order = sort_order + 1, updated_at = CURRENT_TIMESTAMP
         WHERE category_id = ? AND COALESCE(sort_order, 0) >= ?'
    );
    $stmt->execute([$categoryId, $minimumSortOrder]);
}

function create_category(string $name, ?string $concentration = null): array
{
    $name = trim($name);
    $concentration = valid_domain_concentration($concentration);
    if ($name === '') {
        return ['ok' => false, 'message' => 'Category name is required.'];
    }

    $supportsConcentration = table_column_exists('inventory_categories', 'concentration');
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM inventory_categories WHERE LOWER(name) = LOWER(?)'
        . ($supportsConcentration ? ' AND concentration = ?' : '')
    );
    $stmt->execute($supportsConcentration ? [$name, $concentration] : [$name]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'That category already exists in this domain.'];
    }

    $pdo = db();
    if ($supportsConcentration) {
        $insert = $pdo->prepare(
            'INSERT INTO inventory_categories (name, concentration, sort_order)
             SELECT ?, ?, COALESCE(MAX(sort_order), 0) + 1
             FROM inventory_categories
             WHERE concentration = ?'
        );
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO inventory_categories (name, sort_order)
             SELECT ?, COALESCE(MAX(sort_order), 0) + 1
             FROM inventory_categories'
        );
    }

    try {
        $pdo->beginTransaction();
        $insert->execute($supportsConcentration ? [$name, $concentration, $concentration] : [$name]);
        $pdo->commit();
        return ['ok' => true, 'message' => 'Category added.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_unique_constraint_violation($e)) {
            return ['ok' => false, 'message' => 'That category already exists in this domain.'];
        }
        throw $e;
    }
}

function update_category(array $input): array
{
    $categoryId = (int) ($input['category_id'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    $sortOrder = max(0, (int) ($input['sort_order'] ?? 0));
    $supportsConcentration = table_column_exists('inventory_categories', 'concentration');
    $concentration = valid_domain_concentration((string) ($input['concentration'] ?? 'lighting'));

    if ($categoryId <= 0 || $name === '') {
        return ['ok' => false, 'message' => 'Category name is required.'];
    }

    $lookup = db()->prepare('SELECT id FROM inventory_categories WHERE id = ?');
    $lookup->execute([$categoryId]);
    if (!$lookup->fetchColumn()) {
        return ['ok' => false, 'message' => 'Category not found.'];
    }

    $dup = db()->prepare(
        'SELECT COUNT(*) FROM inventory_categories WHERE LOWER(name) = LOWER(?) AND id != ?'
        . ($supportsConcentration ? ' AND concentration = ?' : '')
    );
    $dup->execute($supportsConcentration ? [$name, $categoryId, $concentration] : [$name, $categoryId]);
    if ((int) $dup->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Another category already uses that name in this domain.'];
    }

    if ($supportsConcentration) {
        $stmt = db()->prepare('UPDATE inventory_categories SET name = ?, concentration = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$name, $concentration, $sortOrder, $categoryId]);
        if (table_column_exists('inventory_items', 'concentration')) {
            $syncItems = db()->prepare('UPDATE inventory_items SET concentration = ?, updated_at = CURRENT_TIMESTAMP WHERE category_id = ?');
            $syncItems->execute([$concentration, $categoryId]);
        }
    } else {
        $stmt = db()->prepare('UPDATE inventory_categories SET name = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$name, $sortOrder, $categoryId]);
    }
    return ['ok' => true, 'message' => 'Category updated.'];
}

function delete_category(int $categoryId): array
{
    if ($categoryId <= 0) {
        return ['ok' => false, 'message' => 'Category not found.'];
    }

    $count = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE category_id = ? AND is_active = 1');
    $count->execute([$categoryId]);
    if ((int) $count->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Remove or reassign the inventory in this category before deleting it.'];
    }

    $stmt = db()->prepare('DELETE FROM inventory_categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    return ['ok' => true, 'message' => 'Category removed.'];
}

function create_inventory_item(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $categoryId = (int) ($input['category_id'] ?? 0);
    $sortOrderInput = trim((string) ($input['sort_order'] ?? ''));
    $sortOrder = $sortOrderInput === '' ? next_inventory_item_sort_order($categoryId) : max(0, (int) $sortOrderInput);
    $isSpacer = !empty($input['is_spacer']) ? 1 : 0;
    $supportsSortOrder = table_column_exists('inventory_items', 'sort_order');
    $supportsSpacer = table_column_exists('inventory_items', 'is_spacer');

    if ($name === '' || $categoryId <= 0) {
        return ['ok' => false, 'message' => 'Choose a category and enter an item name.'];
    }

    $categoryLookup = db()->prepare('SELECT COUNT(*) FROM inventory_categories WHERE id = ?');
    $categoryLookup->execute([$categoryId]);
    if ((int) $categoryLookup->fetchColumn() !== 1) {
        return ['ok' => false, 'message' => 'Choose a valid category.'];
    }
    $categoryInfo = db()->prepare('SELECT concentration FROM inventory_categories WHERE id = ?');
    $categoryInfo->execute([$categoryId]);
    $itemConcentration = valid_domain_concentration((string) ($categoryInfo->fetchColumn() ?: 'lighting'));

    $columns = ['category_id', 'name'];
    $placeholders = ['?', '?'];
    $params = [$categoryId, $name];
    if (table_column_exists('inventory_items', 'concentration')) {
        $columns[] = 'concentration';
        $placeholders[] = '?';
        $params[] = $itemConcentration;
    }
    if ($supportsSortOrder) {
        $columns[] = 'sort_order';
        $placeholders[] = '?';
        $params[] = $sortOrder;
    }
    $columns[] = 'shop_quantity';
    $placeholders[] = '?';
    $params[] = max(0, (int) ($input['shop_quantity'] ?? 0));
    $columns[] = 'unit';
    $placeholders[] = '?';
    $params[] = trim((string) ($input['unit'] ?? ''));
    $columns[] = 'default_note';
    $placeholders[] = '?';
    $params[] = trim((string) ($input['default_note'] ?? ''));
    $columns[] = 'description';
    $placeholders[] = '?';
    $params[] = trim((string) ($input['description'] ?? ''));
    if ($supportsSpacer) {
        $columns[] = 'is_spacer';
        $placeholders[] = '?';
        $params[] = $isSpacer;
    }
    $stmt = db()->prepare(
        'INSERT INTO inventory_items (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );
    $duplicate = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE category_id = ? AND name = ? AND is_active = 1');
    $duplicate->execute([$categoryId, $name]);
    if ((int) $duplicate->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'That category already has an item with this name.'];
    }

    $stmt->execute($params);

    return ['ok' => true, 'message' => 'Item added.'];
}

function create_spacer_near_inventory_item(int $itemId, string $position): array
{
    if (!table_column_exists('inventory_items', 'sort_order') || !table_column_exists('inventory_items', 'is_spacer')) {
        return ['ok' => false, 'message' => 'Run the latest migrations before inserting spacer rows.'];
    }

    if (!in_array($position, ['above', 'below'], true)) {
        return ['ok' => false, 'message' => 'Choose where to place the spacer.'];
    }

    $stmt = db()->prepare('SELECT id, category_id, sort_order FROM inventory_items WHERE id = ? AND is_active = 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        return ['ok' => false, 'message' => 'Inventory item not found.'];
    }

    $categoryId = (int) $item['category_id'];
    $baseSortOrder = (int) ($item['sort_order'] ?? 0);
    $insertSortOrder = $position === 'above' ? $baseSortOrder : $baseSortOrder + 1;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        shift_inventory_item_sort_orders($categoryId, $insertSortOrder);
        $name = unique_inventory_item_name($categoryId, 'Spacer');
        $insert = $pdo->prepare(
            'INSERT INTO inventory_items (
                category_id, name, sort_order, shop_quantity, unit, default_note, description, is_spacer
             ) VALUES (?, ?, ?, 0, ?, ?, ?, 1)'
        );
        $insert->execute([$categoryId, $name, $insertSortOrder, '', '', '']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'Spacer added ' . $position . ' this item.'];
}

function category_id_for_name(string $name, ?string $concentration = null): int
{
    $trimmed = trim($name);
    $supportsConcentration = table_column_exists('inventory_categories', 'concentration');
    $normalizedConcentration = valid_domain_concentration($concentration);
    $stmt = db()->prepare(
        'SELECT id FROM inventory_categories WHERE LOWER(name) = LOWER(?)'
        . ($supportsConcentration ? ' AND concentration = ?' : '')
    );
    $stmt->execute($supportsConcentration ? [$trimmed, $normalizedConcentration] : [$trimmed]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    try {
        $result = create_category($trimmed, $normalizedConcentration);
        if (!$result['ok'] && !str_contains(strtolower($result['message']), 'already exists')) {
            return 0;
        }
    } catch (Throwable $e) {
        if (!is_unique_constraint_violation($e)) {
            throw $e;
        }
    }
    $stmt->execute($supportsConcentration ? [$trimmed, $normalizedConcentration] : [$trimmed]);
    return (int) $stmt->fetchColumn();
}

function import_inventory_csv(string $tmpPath): array
{
    $handle = csv_stream_handle($tmpPath);
    if (!$handle) {
        return ['ok' => false, 'message' => 'Unable to read the CSV source.'];
    }

    return import_inventory_csv_from_handle($handle);
}

function import_inventory_csv_text(string $csvText): array
{
    $csvText = trim($csvText);
    if ($csvText === '') {
        return ['ok' => false, 'message' => 'Paste CSV rows to import.'];
    }

    $handle = csv_string_handle($csvText);
    if (!$handle) {
        return ['ok' => false, 'message' => 'Paste CSV rows to import.'];
    }

    return import_inventory_csv_from_handle($handle);
}

function save_rule(array $input): array
{
    $ruleId = (int) ($input['rule_id'] ?? 0);
    $triggerItem = (int) ($input['trigger_item_id'] ?? 0);
    $requiredItem = (int) ($input['required_item_id'] ?? 0);
    $triggerQuantity = max(1, (int) ($input['trigger_quantity'] ?? 1));
    $requiredQuantity = max(1, (int) ($input['required_quantity'] ?? 1));

    if ($triggerItem <= 0 || $requiredItem <= 0) {
        return ['ok' => false, 'message' => 'Choose both the trigger item and the required item.'];
    }

    $supportsConcentration = table_column_exists('inventory_items', 'concentration');
    $lookup = db()->prepare(
        'SELECT id' . ($supportsConcentration ? ', concentration' : ', "lighting" AS concentration') . '
         FROM inventory_items
         WHERE id = ? AND is_active = 1'
    );
    $lookup->execute([$triggerItem]);
    $triggerRow = $lookup->fetch() ?: null;
    if (!$triggerRow) {
        return ['ok' => false, 'message' => 'Choose a valid trigger item.'];
    }
    $lookup->execute([$requiredItem]);
    $requiredRow = $lookup->fetch() ?: null;
    if (!$requiredRow) {
        return ['ok' => false, 'message' => 'Choose a valid suggested item.'];
    }
    if (valid_domain_concentration($triggerRow['concentration'] ?? 'lighting') !== valid_domain_concentration($requiredRow['concentration'] ?? 'lighting')) {
        return ['ok' => false, 'message' => 'Rules must use trigger and suggested items from the same domain.'];
    }

    $note = trim((string) ($input['note'] ?? ''));

    if ($ruleId > 0) {
        $ruleLookup = db()->prepare('SELECT COUNT(*) FROM system_rules WHERE id = ?');
        $ruleLookup->execute([$ruleId]);
        if ((int) $ruleLookup->fetchColumn() !== 1) {
            return ['ok' => false, 'message' => 'Rule not found.'];
        }

        $stmt = db()->prepare(
            'UPDATE system_rules
             SET trigger_item_id = ?, trigger_quantity = ?, required_item_id = ?, required_quantity = ?, note = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $triggerItem,
            $triggerQuantity,
            $requiredItem,
            $requiredQuantity,
            $note,
            $ruleId,
        ]);

        return ['ok' => true, 'message' => 'Rule updated.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO system_rules (trigger_item_id, trigger_quantity, required_item_id, required_quantity, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $triggerItem,
        $triggerQuantity,
        $requiredItem,
        $requiredQuantity,
        $note,
    ]);

    return ['ok' => true, 'message' => 'Rule saved.'];
}

function delete_rule(int $ruleId): array
{
    if ($ruleId <= 0) {
        return ['ok' => false, 'message' => 'Rule not found.'];
    }

    $stmt = db()->prepare('DELETE FROM system_rules WHERE id = ?');
    $stmt->execute([$ruleId]);

    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'Rule not found.'];
    }

    return ['ok' => true, 'message' => 'Rule removed.'];
}

function delete_inventory_item(int $itemId): array
{
    if ($itemId <= 0) {
        return ['ok' => false, 'message' => 'Inventory item not found.'];
    }

    $lookup = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = ?');
    $lookup->execute([$itemId]);
    if ((int) $lookup->fetchColumn() !== 1) {
        return ['ok' => false, 'message' => 'Inventory item not found.'];
    }

    if (table_exists('revision_items')) {
        $cleanup = db()->prepare('DELETE FROM revision_items WHERE inventory_item_id = ?');
        $cleanup->execute([$itemId]);
    }

    $stmt = db()->prepare('DELETE FROM inventory_items WHERE id = ?');
    $stmt->execute([$itemId]);
    return ['ok' => true, 'message' => 'Inventory item removed.'];
}

function clear_inventory_items(): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (table_exists('revision_items')) {
            $pdo->exec('DELETE FROM revision_items');
        }
        if (table_exists('system_rules')) {
            $pdo->exec('DELETE FROM system_rules');
        }
        $pdo->exec('DELETE FROM inventory_items');
        if (table_exists('inventory_categories')) {
            $pdo->exec('DELETE FROM inventory_categories');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'All inventory items removed.'];
}

function pdf_signature_is_valid(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $header = (string) fread($handle, 1024);
    if (!preg_match('/^%PDF-\d\.\d/', $header)) {
        fclose($handle);
        return false;
    }

    $size = filesize($path);
    if ($size === false || $size < 32) {
        fclose($handle);
        return false;
    }

    $tailLength = min(2048, $size);
    fseek($handle, -$tailLength, SEEK_END);
    $tail = (string) fread($handle, $tailLength);
    fclose($handle);

    return str_contains($tail, '%%EOF');
}

function is_allowed_pdf_mime_type(string $mimeType): bool
{
    return in_array($mimeType, ['application/pdf', 'application/x-pdf'], true);
}

function is_allowed_image_mime_type(string $mimeType): bool
{
    return in_array($mimeType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
}

function resource_extension_mime_map(): array
{
    return [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];
}

function detected_upload_mime_type(string $path): string
{
    if ($path === '' || !is_file($path) || !function_exists('finfo_open')) {
        return '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return '';
    }

    $mimeType = (string) finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mimeType;
}

function resource_file_is_valid(string $path, string $mimeType, string $extension): bool
{
    $extension = strtolower($extension);
    if ($extension === 'pdf' || is_allowed_pdf_mime_type($mimeType)) {
        return pdf_signature_is_valid($path) && ($mimeType === '' || is_allowed_pdf_mime_type($mimeType));
    }

    if ($extension !== '' && !array_key_exists($extension, resource_extension_mime_map()) && !is_allowed_image_mime_type($mimeType)) {
        return false;
    }

    $imageInfo = @getimagesize($path);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return false;
    }

    $imageMime = strtolower((string) ($imageInfo['mime'] ?? ''));
    if ($imageMime !== '' && !is_allowed_image_mime_type($imageMime)) {
        return false;
    }

    return $mimeType === '' || is_allowed_image_mime_type($mimeType) || $imageMime !== '';
}

function upload_root_dir(): string
{
    $path = RESOURCE_STORAGE_PATH;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

function upload_dir(string $subdir = ''): string
{
    $path = upload_root_dir() . '/uploads';
    if ($subdir !== '') {
        $safeSubdir = trim($subdir, '/');
        if (!preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $safeSubdir)) {
            throw new InvalidArgumentException('Invalid upload directory.');
        }
        $path .= '/' . $safeSubdir;
    }
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

function is_trusted_uploaded_file(string $tmpPath): bool
{
    if ($tmpPath === '') {
        return false;
    }

    if (is_uploaded_file($tmpPath)) {
        return true;
    }

    $allowLocalTestUpload = defined('ALLOW_LOCAL_UPLOADS_FOR_TESTS')
        && ALLOW_LOCAL_UPLOADS_FOR_TESTS
        && in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && is_file($tmpPath);

    return $allowLocalTestUpload;
}

function normalize_resource_folder_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    $name = str_replace('\\', '/', $name);
    $name = trim($name, '/');
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '../') || str_contains($name, '/..')) {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
}

function resource_folder_parenting_supported(): bool
{
    return table_exists('resource_folders') && table_column_exists('resource_folders', 'parent_id');
}

function resource_folder_exists(int $folderId): bool
{
    if ($folderId <= 0 || !table_exists('resource_folders')) {
        return false;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE id = ?');
    $stmt->execute([$folderId]);
    return (int) $stmt->fetchColumn() === 1;
}

function fetch_resource_folders(): array
{
    if (!table_exists('resource_folders')) {
        return [];
    }

    $supportsParents = resource_folder_parenting_supported();
    $rows = db()->query(
        'SELECT rf.*, COUNT(r.id) AS resource_count
         FROM resource_folders rf
         LEFT JOIN resources r ON r.folder_id = rf.id
         GROUP BY rf.id, rf.name' . ($supportsParents ? ', rf.parent_id' : '') . ', rf.created_at
         ORDER BY LOWER(rf.name) ASC, rf.id ASC'
    )->fetchAll();

    if (!$supportsParents) {
        foreach ($rows as &$row) {
            $row['depth'] = 0;
            $row['full_path'] = $row['name'];
        }
        unset($row);
        return $rows;
    }

    $byId = [];
    foreach ($rows as $row) {
        $row['parent_id'] = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $row['children'] = [];
        $byId[(int) $row['id']] = $row;
    }

    $roots = [];
    foreach (array_keys($byId) as $folderId) {
        $parentId = $byId[$folderId]['parent_id'];
        if ($parentId && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = $folderId;
        } else {
            $roots[] = $folderId;
        }
    }

    usort($roots, static fn (int $a, int $b): int => strcasecmp((string) $byId[$a]['name'], (string) $byId[$b]['name']));
    foreach ($byId as &$row) {
        usort($row['children'], static fn (int $a, int $b): int => strcasecmp((string) $byId[$a]['name'], (string) $byId[$b]['name']));
    }
    unset($row);

    $ordered = [];
    $appendFolder = static function (int $folderId, int $depth, string $prefix) use (&$appendFolder, &$ordered, $byId): void {
        $row = $byId[$folderId];
        $row['depth'] = $depth;
        $row['full_path'] = $prefix === '' ? $row['name'] : ($prefix . ' / ' . $row['name']);
        unset($row['children']);
        $ordered[] = $row;
        foreach ($byId[$folderId]['children'] as $childId) {
            $appendFolder($childId, $depth + 1, $row['full_path']);
        }
    };

    foreach ($roots as $rootId) {
        $appendFolder($rootId, 0, '');
    }

    return $ordered;
}

function create_resource_folder(string $name, ?int $parentId = null): array
{
    if (!table_exists('resource_folders')) {
        return ['ok' => false, 'message' => 'Run the latest migrations before creating folders.'];
    }

    $name = normalize_resource_folder_name($name);
    if ($name === '') {
        return ['ok' => false, 'message' => 'Enter a folder name.'];
    }

    if ($parentId !== null && $parentId > 0) {
        if (!resource_folder_parenting_supported()) {
            return ['ok' => false, 'message' => 'Run the latest resource folder migration before creating subfolders.'];
        }
        if (!resource_folder_exists($parentId)) {
            return ['ok' => false, 'message' => 'Choose a valid parent folder.'];
        }
    } else {
        $parentId = null;
    }

    if (resource_folder_parenting_supported()) {
        $duplicateStmt = $parentId === null
            ? db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE parent_id IS NULL AND name = ?')
            : db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE parent_id = ? AND name = ?');
        $duplicateStmt->execute($parentId === null ? [$name] : [$parentId, $name]);
    } else {
        $duplicateStmt = db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE name = ?');
        $duplicateStmt->execute([$name]);
    }
    if ((int) $duplicateStmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'That folder already exists.'];
    }

    $stmt = resource_folder_parenting_supported()
        ? db()->prepare('INSERT INTO resource_folders (name, parent_id) VALUES (?, ?)')
        : db()->prepare('INSERT INTO resource_folders (name) VALUES (?)');
    try {
        $stmt->execute(resource_folder_parenting_supported() ? [$name, $parentId] : [$name]);
    } catch (Throwable $e) {
        if (is_unique_constraint_violation($e)) {
            return ['ok' => false, 'message' => 'That folder already exists.'];
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'Folder created.'];
}

function delete_resource_folder(int $folderId): array
{
    if (!table_exists('resource_folders')) {
        return ['ok' => false, 'message' => 'Resource folders are not available yet.'];
    }

    $resourceCountStmt = db()->prepare('SELECT COUNT(*) FROM resources WHERE folder_id = ?');
    $resourceCountStmt->execute([$folderId]);
    if ((int) $resourceCountStmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Move or remove the PDFs in this folder before deleting it.'];
    }

    if (resource_folder_parenting_supported()) {
        $childCountStmt = db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE parent_id = ?');
        $childCountStmt->execute([$folderId]);
        if ((int) $childCountStmt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Delete or move the subfolders in this folder before removing it.'];
        }
    }

    $stmt = db()->prepare('DELETE FROM resource_folders WHERE id = ?');
    $stmt->execute([$folderId]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'Folder not found.'];
    }

    return ['ok' => true, 'message' => 'Folder removed.'];
}

function fetch_resources(?int $folderId = null): array
{
    if (!table_exists('resources')) {
        return [];
    }

    $supportsFolders = table_column_exists('resources', 'folder_id') && table_exists('resource_folders');
    $select = 'SELECT r.*';
    $join = '';
    if ($supportsFolders) {
        $select .= ', rf.name AS folder_name';
        $join = ' LEFT JOIN resource_folders rf ON rf.id = r.folder_id';
    }

    if ($folderId !== null && $supportsFolders) {
        $stmt = db()->prepare($select . ' FROM resources r' . $join . ' WHERE r.folder_id = ? ORDER BY r.created_at DESC, r.id DESC');
        $stmt->execute([$folderId]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = db()->query($select . ' FROM resources r' . $join . ' ORDER BY r.created_at DESC, r.id DESC')->fetchAll();
    }

    if ($supportsFolders) {
        $folderPaths = [];
        foreach (fetch_resource_folders() as $folder) {
            $folderPaths[(int) $folder['id']] = $folder['full_path'] ?? $folder['name'];
        }
        foreach ($rows as &$row) {
            $folderIdValue = (int) ($row['folder_id'] ?? 0);
            $row['folder_path'] = $folderPaths[$folderIdValue] ?? ($row['folder_name'] ?? '');
        }
        unset($row);
    }

    return $rows;
}

function store_resource_upload(array $file, string $title = '', ?int $folderId = null): array
{
    if (!table_exists('resources')) {
        return ['ok' => false, 'message' => 'Run migrations before uploading resources.'];
    }

    if ($folderId !== null && $folderId > 0) {
        if (!table_column_exists('resources', 'folder_id') || !table_exists('resource_folders')) {
            return ['ok' => false, 'message' => 'Run the resource folder migration before uploading into a folder.'];
        }
        $folderStmt = db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE id = ?');
        $folderStmt->execute([$folderId]);
        if ((int) $folderStmt->fetchColumn() !== 1) {
            return ['ok' => false, 'message' => 'Choose a valid folder.'];
        }
    } else {
        $folderId = null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'Choose a PDF or image file to upload.'];
    }

    $isUploadedFile = is_trusted_uploaded_file((string) $file['tmp_name']);
    if (!$isUploadedFile) {
        return ['ok' => false, 'message' => 'Choose a valid uploaded resource file.'];
    }
    $allowLocalTestUpload = !is_uploaded_file((string) $file['tmp_name']) && $isUploadedFile;

    $originalName = (string) ($file['name'] ?? 'resource.pdf');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeType = detected_upload_mime_type((string) $file['tmp_name']);
    if (!resource_file_is_valid((string) $file['tmp_name'], $mimeType, $extension)) {
        return ['ok' => false, 'message' => 'Only PDF and image resources are supported.'];
    }

    $normalizedMimeType = $mimeType;
    if ($normalizedMimeType === '') {
        $mimeOptions = resource_extension_mime_map()[$extension] ?? [];
        $normalizedMimeType = $mimeOptions[0] ?? 'application/octet-stream';
    }

    $storedName = date('YmdHis') . '-' . upload_random_suffix() . '.' . $extension;
    $destination = upload_dir('resources') . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $tmpPath = (string) $file['tmp_name'];
        $moved = false;
        if ($allowLocalTestUpload && is_file($tmpPath)) {
            $moved = @rename($tmpPath, $destination);
            if (!$moved && @copy($tmpPath, $destination)) {
                $moved = true;
                @unlink($tmpPath);
            }
        }
        if (!$moved) {
            return ['ok' => false, 'message' => 'Unable to store the uploaded resource.'];
        }
    }

    $resourceTitle = trim($title) !== '' ? trim($title) : pathinfo($originalName, PATHINFO_FILENAME);
    $supportsFolders = table_column_exists('resources', 'folder_id');
    $stmt = db()->prepare(
        'INSERT INTO resources (title, original_name, stored_name, mime_type, file_size' . ($supportsFolders ? ', folder_id' : '') . ')
         VALUES (?, ?, ?, ?, ?' . ($supportsFolders ? ', ?' : '') . ')'
    );
    try {
        $params = [
            $resourceTitle,
            $originalName,
            $storedName,
            $normalizedMimeType,
            max(0, (int) ($file['size'] ?? filesize($destination))),
        ];
        if ($supportsFolders) {
            $params[] = $folderId;
        }
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (is_file($destination)) {
            @unlink($destination);
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'Resource uploaded.'];
}

function move_resource_to_folder(int $resourceId, ?int $folderId): array
{
    $resource = find_resource($resourceId);
    if (!$resource) {
        return ['ok' => false, 'message' => 'Resource not found.'];
    }
    if (!table_column_exists('resources', 'folder_id')) {
        return ['ok' => false, 'message' => 'Run the latest migrations before moving resources.'];
    }

    $folderId = $folderId !== null && $folderId > 0 ? $folderId : null;
    if ($folderId !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM resource_folders WHERE id = ?');
        $stmt->execute([$folderId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['ok' => false, 'message' => 'Choose a valid folder.'];
        }
    }

    $stmt = db()->prepare('UPDATE resources SET folder_id = ? WHERE id = ?');
    $stmt->execute([$folderId, $resourceId]);
    return ['ok' => true, 'message' => 'Resource location updated.'];
}

function find_resource(int $resourceId): ?array
{
    if (!table_exists('resources')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM resources WHERE id = ?');
    $stmt->execute([$resourceId]);
    $resource = $stmt->fetch();
    return $resource ?: null;
}

function resource_path(array $resource): string
{
    $storedName = (string) ($resource['stored_name'] ?? '');
    if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}\.(pdf|png|jpe?g|gif|webp)$/i', $storedName)) {
        throw new RuntimeException('Invalid resource path.');
    }

    $currentPath = upload_dir('resources') . '/' . $storedName;
    if (is_file($currentPath)) {
        return $currentPath;
    }

    $legacyPath = (__DIR__ . '/../storage/uploads/resources/' . $storedName);
    if (is_file($legacyPath)) {
        $destinationDir = upload_dir('resources');
        $destinationPath = $destinationDir . '/' . $storedName;
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0775, true);
        }
        if (!@rename($legacyPath, $destinationPath) && (!@copy($legacyPath, $destinationPath) || !@unlink($legacyPath))) {
            throw new RuntimeException('Unable to migrate resource to private storage.');
        }
        return $destinationPath;
    }

    return $currentPath;
}

function resource_access_expires_at(int $ttlSeconds = 900): int
{
    return time() + max(60, $ttlSeconds);
}

function resource_access_token(array $resource, int $expiresAt): string
{
    return hash_hmac('sha256', (string) $resource['id'] . '|' . (string) $resource['stored_name'] . '|' . $expiresAt, APP_SECRET);
}

function is_unique_constraint_violation(Throwable $e): bool
{
    if (!$e instanceof \PDOException) {
        return false;
    }

    $sqlState = (string) ($e->getCode() ?? '');
    if ($sqlState === '23000' || $sqlState === '23505') {
        return true;
    }

    return str_contains(strtolower($e->getMessage()), 'unique');
}

function upload_random_suffix(): string
{
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            // Fall back below.
        }
    }

    return substr(sha1(uniqid((string) mt_rand(), true)), 0, 12);
}

function delete_resource(int $resourceId): array
{
    $resource = find_resource($resourceId);
    if (!$resource) {
        return ['ok' => false, 'message' => 'Resource not found.'];
    }

    try {
        $path = resource_path($resource);
    } catch (RuntimeException $e) {
        $path = '';
    }
    if (is_file($path)) {
        @unlink($path);
    }

    $stmt = db()->prepare('DELETE FROM resources WHERE id = ?');
    $stmt->execute([$resourceId]);
    return ['ok' => true, 'message' => 'Resource removed.'];
}

function branding_settings_defaults(): array
{
    return [
        'app.name' => APP_NAME,
        'app.logo_svg_path' => '',
    ];
}

function branding_settings(): array
{
    return array_merge(branding_settings_defaults(), fetch_settings_by_prefix('app.'));
}

function save_branding_settings(array $input): void
{
    $name = trim((string) ($input['app_name'] ?? APP_NAME));
    $logoPath = sanitize_local_asset_path((string) ($input['app_logo_svg_path'] ?? '')) ?? '';

    save_setting('app.name', $name !== '' ? $name : APP_NAME);
    save_setting('app.logo_svg_path', $logoPath);
}

function export_layout_defaults(): array
{
    return [
        'layout.header_text' => 'Production Electrician Shop Order',
        'layout.organization_text' => '',
        'layout.footer_text' => 'Prepared in Backline',
        'layout.export_notes' => "Unless otherwise noted, all units to come with lamp, c-clamp, safety cable and black color frame.\nAll hardware, perishables, cable lengths and power distribution requirements as per electrician.\nAbsolutely no substitutions without written permission of Designer.\nAny revisions or substitutions must be fully disclosed.\nShop assumes responsibility for any additional materials that are required on site due to rental shop oversight or error.\nAll PAR cans to have interior protective screening.\nColor scrolls to be made and loaded by shop. A list of required colors will be provided.",
        'layout.show_image' => '1',
        'layout.cover_show_title' => '1',
        'layout.show_page_numbers' => '1',
        'layout.show_revision_summary' => '1',
        'layout.cover_title_revision_spacing' => '0.52',
        'layout.cover_notes_spacing' => '0.9',
        'layout.cover_footer_logo_url' => '',
        'layout.cover_prepared_by_name' => '',
        'layout.equipment_table_width' => '100',
        'layout.equipment_min_rows_per_page' => '0',
        'layout.equipment_max_rows_per_page' => '0',
        'layout.revision_summary_table_width' => '100',
        'layout.revision_summary_min_rows_per_page' => '0',
        'layout.revision_summary_max_rows_per_page' => '0',
        'layout.revision_summary_col_line' => '4',
        'layout.revision_summary_col_item' => '45',
        'layout.revision_summary_col_description' => '23',
        'layout.revision_summary_col_previous_total' => '6',
        'layout.revision_summary_col_total' => '6',
        'layout.revision_summary_col_action' => '10',
        'layout.revision_summary_col_notes' => '12',
        'layout.equipment_zebra_gray' => '#CCCCCC',
        'layout.equipment_row_padding' => '0.016',
        'layout.equipment_header_row_padding' => '0.22',
        'layout.equipment_category_row_padding' => '0.26',
        'layout.equipment_category_gap' => '0.08',
        'layout.equipment_header_fill' => '#F3F4F6',
        'layout.equipment_category_fill' => '#E5E7EB',
        'layout.equipment_font_size' => '7.35',
        'layout.equipment_line_height' => '1.1',
        'layout.equipment_col_line' => '4',
        'layout.equipment_col_item' => '45',
        'layout.equipment_col_description' => '23',
        'layout.equipment_col_used' => '5',
        'layout.equipment_col_spare' => '5',
        'layout.equipment_col_total' => '6',
        'layout.equipment_col_notes' => '12',
        'layout.equipment_font_line' => '6.9',
        'layout.equipment_font_item' => '7.35',
        'layout.equipment_font_description' => '7.35',
        'layout.equipment_font_used' => '7.35',
        'layout.equipment_font_spare' => '7.35',
        'layout.equipment_font_total' => '7.35',
        'layout.equipment_font_action' => '7.35',
        'layout.equipment_font_notes' => '7.35',
    ];
}

function show_export_layout_override_keys(): array
{
    return [
        'layout.header_text',
        'layout.organization_text',
        'layout.footer_text',
        'layout.export_notes',
        'layout.show_image',
        'layout.cover_show_title',
        'layout.show_page_numbers',
        'layout.show_revision_summary',
        'layout.cover_title_revision_spacing',
        'layout.cover_notes_spacing',
        'layout.cover_footer_logo_url',
        'layout.cover_prepared_by_name',
    ];
}

function show_export_layout_checkbox_input_names(): array
{
    return [
        'show_image',
        'cover_show_title',
        'show_page_numbers',
        'show_revision_summary',
    ];
}

function export_layout_settings(?int $showId = null): array
{
    $defaults = export_layout_defaults();
    $settings = array_merge($defaults, fetch_settings_by_prefix('layout.'));

    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $settings) || $settings[$key] === null) {
            $settings[$key] = $default;
        }
    }

    if ($showId !== null && $showId > 0) {
        $showOverrides = fetch_show_settings_by_prefix($showId, 'layout.');
        foreach (show_export_layout_override_keys() as $key) {
            if (array_key_exists($key, $showOverrides)) {
                $settings[$key] = (string) $showOverrides[$key];
            }
        }
    }

    return $settings;
}

function export_layout_number(array $input, string $key, float|string $default, ?float $min = null, ?float $max = null, int $precision = 3): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($value === '') {
        return (string) $default;
    }
    if (!is_numeric($value)) {
        return (string) $default;
    }
    if ($min === null && $max === null) {
        return $value;
    }
    $number = (float) $value;
    if ($min !== null && $number < $min) {
        $number = $min;
    }
    if ($max !== null && $number > $max) {
        $number = $max;
    }
    $formatted = number_format($number, $precision, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? (string) $default : $formatted;
}

function export_layout_color(array $input, string $key, string $default): string
{
    $value = strtoupper(trim((string) ($input[$key] ?? $default)));
    if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
        return $default;
    }

    return $value;
}

function export_layout_sanitized_values(array $input): array
{
    return [
        'layout.header_text' => trim((string) ($input['header_text'] ?? 'Production Electrician Shop Order')),
        'layout.organization_text' => trim((string) ($input['organization_text'] ?? '')),
        'layout.footer_text' => trim((string) ($input['footer_text'] ?? 'Prepared in Backline')),
        'layout.export_notes' => trim((string) ($input['export_notes'] ?? '')),
        'layout.show_image' => !empty($input['show_image']) ? '1' : '0',
        'layout.cover_show_title' => !empty($input['cover_show_title']) ? '1' : '0',
        'layout.show_page_numbers' => !empty($input['show_page_numbers']) ? '1' : '0',
        'layout.show_revision_summary' => !empty($input['show_revision_summary']) ? '1' : '0',
        'layout.cover_title_revision_spacing' => export_layout_number($input, 'cover_title_revision_spacing', '0.52', null, null, 3),
        'layout.cover_notes_spacing' => export_layout_number($input, 'cover_notes_spacing', '0.9', null, null, 3),
        'layout.cover_footer_logo_url' => sanitize_local_asset_path((string) ($input['cover_footer_logo_url'] ?? '')) ?? '',
        'layout.cover_prepared_by_name' => trim((string) ($input['cover_prepared_by_name'] ?? '')),
        'layout.equipment_table_width' => export_layout_number($input, 'equipment_table_width', '100', null, null, 1),
        'layout.equipment_min_rows_per_page' => export_layout_number($input, 'equipment_min_rows_per_page', '0', null, null, 0),
        'layout.equipment_max_rows_per_page' => export_layout_number($input, 'equipment_max_rows_per_page', '0', null, null, 0),
        'layout.revision_summary_table_width' => export_layout_number($input, 'revision_summary_table_width', '100', null, null, 1),
        'layout.revision_summary_min_rows_per_page' => export_layout_number($input, 'revision_summary_min_rows_per_page', '0', null, null, 0),
        'layout.revision_summary_max_rows_per_page' => export_layout_number($input, 'revision_summary_max_rows_per_page', '0', null, null, 0),
        'layout.revision_summary_col_line' => export_layout_number($input, 'revision_summary_col_line', '4', null, null, 1),
        'layout.revision_summary_col_item' => export_layout_number($input, 'revision_summary_col_item', '45', null, null, 1),
        'layout.revision_summary_col_description' => export_layout_number($input, 'revision_summary_col_description', '23', null, null, 1),
        'layout.revision_summary_col_previous_total' => export_layout_number($input, 'revision_summary_col_previous_total', '6', null, null, 1),
        'layout.revision_summary_col_total' => export_layout_number($input, 'revision_summary_col_total', '6', null, null, 1),
        'layout.revision_summary_col_action' => export_layout_number($input, 'revision_summary_col_action', '10', null, null, 1),
        'layout.revision_summary_col_notes' => export_layout_number($input, 'revision_summary_col_notes', '12', null, null, 1),
        'layout.equipment_zebra_gray' => export_layout_color($input, 'equipment_zebra_gray', '#CCCCCC'),
        'layout.equipment_row_padding' => export_layout_number($input, 'equipment_row_padding', '0.016', null, null, 3),
        'layout.equipment_header_row_padding' => export_layout_number($input, 'equipment_header_row_padding', '0.22', null, null, 3),
        'layout.equipment_category_row_padding' => export_layout_number($input, 'equipment_category_row_padding', '0.26', null, null, 3),
        'layout.equipment_category_gap' => export_layout_number($input, 'equipment_category_gap', '0.08', null, null, 3),
        'layout.equipment_header_fill' => export_layout_color($input, 'equipment_header_fill', '#F3F4F6'),
        'layout.equipment_category_fill' => export_layout_color($input, 'equipment_category_fill', '#E5E7EB'),
        'layout.equipment_font_size' => export_layout_number($input, 'equipment_font_size', '7.35', null, null, 2),
        'layout.equipment_line_height' => export_layout_number($input, 'equipment_line_height', '1.1', null, null, 2),
        'layout.equipment_col_line' => export_layout_number($input, 'equipment_col_line', '4', null, null, 1),
        'layout.equipment_col_item' => export_layout_number($input, 'equipment_col_item', '45', null, null, 1),
        'layout.equipment_col_description' => export_layout_number($input, 'equipment_col_description', '23', null, null, 1),
        'layout.equipment_col_used' => export_layout_number($input, 'equipment_col_used', '5', null, null, 1),
        'layout.equipment_col_spare' => export_layout_number($input, 'equipment_col_spare', '5', null, null, 1),
        'layout.equipment_col_total' => export_layout_number($input, 'equipment_col_total', '6', null, null, 1),
        'layout.equipment_col_notes' => export_layout_number($input, 'equipment_col_notes', '12', null, null, 1),
        'layout.equipment_font_line' => export_layout_number($input, 'equipment_font_line', '6.9', null, null, 2),
        'layout.equipment_font_item' => export_layout_number($input, 'equipment_font_item', '7.35', null, null, 2),
        'layout.equipment_font_description' => export_layout_number($input, 'equipment_font_description', '7.35', null, null, 2),
        'layout.equipment_font_used' => export_layout_number($input, 'equipment_font_used', '7.35', null, null, 2),
        'layout.equipment_font_spare' => export_layout_number($input, 'equipment_font_spare', '7.35', null, null, 2),
        'layout.equipment_font_total' => export_layout_number($input, 'equipment_font_total', '7.35', null, null, 2),
        'layout.equipment_font_action' => export_layout_number($input, 'equipment_font_action', '7.35', null, null, 2),
        'layout.equipment_font_notes' => export_layout_number($input, 'equipment_font_notes', '7.35', null, null, 2),
    ];
}

function save_export_layout(array $input): void
{
    foreach (export_layout_sanitized_values($input) as $key => $value) {
        save_setting($key, $value);
    }
}

function save_show_export_layout(int $showId, array $input): void
{
    $currentLayout = export_layout_settings($showId);
    $normalizedInput = [];
    foreach (show_export_layout_override_keys() as $key) {
        $inputName = substr($key, strlen('layout.'));
        if (array_key_exists($inputName, $input)) {
            $normalizedInput[$inputName] = $input[$inputName];
            continue;
        }

        if (in_array($inputName, show_export_layout_checkbox_input_names(), true)) {
            $normalizedInput[$inputName] = ($currentLayout[$key] ?? '0') === '1' ? '1' : '0';
            continue;
        }

        $normalizedInput[$inputName] = $currentLayout[$key] ?? '';
    }

    $values = export_layout_sanitized_values($normalizedInput);
    foreach (show_export_layout_override_keys() as $key) {
        if (array_key_exists($key, $values)) {
            save_show_setting($showId, $key, $values[$key]);
        }
    }
}

function action_badge(string $action): string
{
    return match ($action) {
        'add' => ui_badge('Add', 'success'),
        'return' => ui_badge('Return', 'danger'),
        'exchange' => ui_badge('Exchange', 'warning'),
        'note' => ui_badge('See Notes', 'info'),
        default => ui_badge('No Change', 'neutral'),
    };
}

function export_row_action_class(array $revision, array $item, array $line): string
{
    if (!empty($item['is_spacer']) || !empty($revision['is_initial'])) {
        return '';
    }

    return match ((string) ($line['action'] ?? '')) {
        'add' => 'export-row-add',
        'return' => 'export-row-return',
        'exchange' => 'export-row-exchange',
        'note' => 'export-row-note',
        default => '',
    };
}
