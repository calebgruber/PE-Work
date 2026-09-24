<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

if (!schema_ready() || !auth_tables_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

if (user_bootstrap_required()) {
    header('Location: ' . url_for('setup'));
    exit;
}

$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? url_for('')));
if (
    $returnTo === ''
    || !str_starts_with($returnTo, '/')
    || str_starts_with($returnTo, '//')
    || str_contains($returnTo, '\\')
    || str_contains($returnTo, '://')
) {
    $returnTo = url_for('');
}

if (is_logged_in() && !auth_password_change_required()) {
    header('Location: ' . $returnTo);
    exit;
}

$error = '';
$email = trim((string) ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $result = authenticate_user($email, (string) ($_POST['password'] ?? ''));
        if (!($result['ok'] ?? false)) {
            $error = (string) ($result['message'] ?? 'Unable to sign in.');
        } else {
            $user = $result['user'] ?? [];
            $target = auth_password_change_required($user) ? url_for('profile?force_password=1') : $returnTo;
            header('Location: ' . $target);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Login | <?= h(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
  <script>
    (function(){var t=localStorage.getItem('cg-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();
  </script>
</head>
<body class="tabler-shell">
<div class="login-page">
  <div class="container-xl auth-shell">
    <section class="auth-intro">
      <div class="auth-intro-badge">Powered by Tabler</div>
      <h1>Modern shop orders for every production.</h1>
      <p>Track shows, revisions, exports, and paperwork from one clean workspace built for theatre crews.</p>
      <div class="auth-intro-points">
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">theater_comedy</span>
          <div>
            <strong>Show-based workflow</strong>
            <span>Create and revise orders per production.</span>
          </div>
        </div>
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">inventory_2</span>
          <div>
            <strong>Inventory-aware</strong>
            <span>Keep pulls, returns, and spares organized.</span>
          </div>
        </div>
        <div class="auth-intro-point">
          <span class="material-symbols-outlined">picture_as_pdf</span>
          <div>
            <strong>Export ready</strong>
            <span>Generate polished paperwork for the shop.</span>
          </div>
        </div>
      </div>
    </section>
    <section class="auth-panel">
      <div class="card login-card">
        <div class="card-body">
          <div class="login-header">
            <span class="material-symbols-outlined logo-icon">login</span>
            <h1>Sign in to <?= h(APP_NAME) ?></h1>
            <p>Use the invite email address and password issued by an admin.</p>
          </div>
          <div class="login-body">
      <?php ui_flash(); ?>
      <?php if ($error !== ''): ?>
      <div class="alerts">
        <div class="alert alert-danger" style="--alert-accent:#ef4444;--alert-accent-rgb:239,68,68;--alert-text-on-solid:#ffffff">
          <span class="material-symbols-outlined">error</span>
          <span class="alert-text"><?= h($error) ?></span>
        </div>
      </div>
      <?php endif; ?>
      <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
        <div class="form-group">
          <label for="email">Email</label>
          <input class="form-control" id="email" type="email" name="email" required autofocus value="<?= h($email) ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input class="form-control" id="password" type="password" name="password" required>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">login</span>
            Sign In
          </button>
          <a class="btn btn-ghost" href="<?= h(url_for('setup')) ?>">
            <span class="material-symbols-outlined">construction</span>
            Setup
          </a>
        </div>
      </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= h(asset_url('shared/assets/app.js')) ?>"></script>
</body>
</html>
