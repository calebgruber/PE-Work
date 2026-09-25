<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$logs = [];
$bootstrapErrors = [];
$bootstrapInput = [
    'display_name' => '',
    'email' => '',
    'concentration' => 'lighting',
];

$dbReady = schema_ready();
$needsBootstrap = $dbReady && auth_tables_ready() && user_bootstrap_required();
$usersReady = $dbReady && auth_tables_ready() && !$needsBootstrap;
$currentUser = current_user();

if ($usersReady && !is_admin($currentUser)) {
    if (!empty($currentUser)) {
        http_response_code(403);
        exit('Admin access required.');
    }

    header('Location: ' . url_for('login'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $action = (string) ($_POST['action'] ?? 'run_migrations');
    if ($action === 'bootstrap_admin') {
        $bootstrapInput['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
        $bootstrapInput['email'] = trim((string) ($_POST['email'] ?? ''));
        $bootstrapInput['concentration'] = (string) ($_POST['concentration'] ?? 'lighting');
        $result = bootstrap_admin_user($_POST);
        if (!($result['ok'] ?? false)) {
            $bootstrapErrors[] = (string) ($result['message'] ?? 'Unable to create the admin account.');
        } else {
            flash('success', 'Admin account created.');
            header('Location: ' . url_for(''));
            exit;
        }
    } else {
        $logs = run_pending_migrations();
    }
}
$brandName = app_display_name();
$brandLogo = app_logo_markup('auth-brand-logo', $brandName . ' logo');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup | <?= h($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
  <script>
    (function(){var t=localStorage.getItem('cg-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();
  </script>
</head>
<body class="tabler-shell">
<div class="login-page">
  <div class="container-xl auth-shell auth-shell-wide">
    <section class="auth-intro">
      <div class="auth-intro-badge">Setup &amp; migrations</div>
      <h1>Get the system ready without leaving the browser.</h1>
      <p>Apply database changes, preserve your local config, and bootstrap the first admin account from one clean setup flow.</p>
      <div class="auth-intro-points">
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">upgrade</span>
          <div>
            <strong>Run migrations safely</strong>
            <span>See exactly what applied and what still needs attention.</span>
          </div>
        </div>
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">admin_panel_settings</span>
          <div>
            <strong>Bootstrap access</strong>
            <span>Create the first admin once auth tables are ready.</span>
          </div>
        </div>
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">settings</span>
          <div>
            <strong>cPanel friendly</strong>
            <span>Keep the app deployable with minimal server setup.</span>
          </div>
        </div>
      </div>
    </section>
    <section class="auth-panel auth-panel-wide">
      <div class="card login-card login-card-wide">
        <div class="card-body">
          <div class="login-header">
            <?php if ($brandLogo === ''): ?>
            <span class="material-symbols-outlined logo-icon">construction</span>
            <?php endif; ?>
            <?php if ($brandLogo !== ''): ?>
            <div class="mb-3"><?= $brandLogo ?></div>
            <?php endif; ?>
            <h1><?= $brandLogo !== '' ? 'Setup' : (h($brandName) . ' Setup') ?></h1>
            <p>Apply migrations, keep local config intact, and bootstrap the first admin account.</p>
          </div>
          <div class="login-body">
      <?php ui_flash(); ?>
      <?php foreach ($bootstrapErrors as $error): ?>
      <div class="alerts">
        <div class="alert alert-danger" style="--alert-accent:#ef4444;--alert-accent-rgb:239,68,68;--alert-text-on-solid:#ffffff">
          <span class="material-symbols-outlined">error</span>
          <span class="alert-text"><?= h($error) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="alerts">
        <div class="alert alert-info" style="--alert-accent:#3b82f6;--alert-accent-rgb:59,130,246;--alert-text-on-solid:#ffffff">
          <span class="material-symbols-outlined">info</span>
          <span class="alert-text">Runtime still reads <code>config.local.php</code> when present. You can also use environment variables like <code>APP_SITE_URL</code> and <code>APP_EMAIL_FROM_ADDRESS</code> for invite emails.</span>
        </div>
      </div>

      <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="run_migrations">
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">rocket_launch</span>
            Apply Pending Migrations
          </button>
          <?php if ($usersReady): ?>
          <a class="btn btn-ghost" href="<?= h(url_for('')) ?>">
            <span class="material-symbols-outlined">arrow_forward</span>
            Open Dashboard
          </a>
          <?php elseif ($needsBootstrap): ?>
          <a class="btn btn-ghost" href="#bootstrap-admin">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            Create First Admin
          </a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($needsBootstrap): ?>
      <div class="settings-layout-section" id="bootstrap-admin">
        <div class="section-label">Create the First Admin</div>
        <p class="settings-layout-section-copy muted">This only appears when the users table exists but no accounts have been created yet.</p>
        <form method="post" class="stack">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="bootstrap_admin">
          <div class="card-grid">
            <div class="form-group">
              <label for="display_name">Full Name</label>
              <input class="form-control" id="display_name" name="display_name" required value="<?= h($bootstrapInput['display_name']) ?>">
            </div>
            <div class="form-group">
              <label for="email">Email</label>
              <input class="form-control" id="email" type="email" name="email" required value="<?= h($bootstrapInput['email']) ?>">
            </div>
            <div class="form-group">
              <label for="concentration">Concentration</label>
              <select class="form-control" id="concentration" name="concentration">
                <?php foreach (user_concentrations() as $value => $label): ?>
                <option value="<?= h($value) ?>"<?= $bootstrapInput['concentration'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="card-grid">
            <div class="form-group">
              <label for="password">Password</label>
              <input class="form-control" id="password" type="password" name="password" required>
              <div class="helper-text">Use at least 10 characters.</div>
            </div>
            <div class="form-group">
              <label for="password_confirmation">Confirm Password</label>
              <input class="form-control" id="password_confirmation" type="password" name="password_confirmation" required>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <span class="material-symbols-outlined">person_add</span>
              Create Admin Account
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($logs): ?>
      <div class="section-label">Migration Results</div>
      <div class="table-wrap">
        <table class="table table-vcenter">
          <caption>Migration execution results</caption>
          <thead>
            <tr>
              <th>Migration</th>
              <th>Status</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?= h($log['name']) ?></td>
              <td><?= ui_badge(ucfirst($log['status']), $log['status'] === 'applied' ? 'success' : ($log['status'] === 'error' ? 'danger' : 'neutral')) ?></td>
              <td><?= h($log['message']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.5.1/dist/js/tabler.min.js"></script>
<script src="<?= h(asset_url('shared/assets/app.js')) ?>"></script>
</body>
</html>
