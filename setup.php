<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logs = run_pending_migrations();
}

$dbReady = schema_ready();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup | <?= h(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
  <script>
    (function(){var t=localStorage.getItem('cg-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();
  </script>
</head>
<body>
<div class="login-page">
  <div class="login-card" style="max-width:720px;margin:3rem auto;">
    <div class="login-header">
      <span class="material-symbols-outlined logo-icon">construction</span>
      <h1><?= h(APP_NAME) ?> Setup</h1>
      <p>Apply the starter database migration and prepare the pure PHP shop-order workspace.</p>
    </div>
    <div class="login-body">
      <div class="alerts">
        <div class="alert alert-info" style="--alert-accent:#3b82f6;--alert-accent-rgb:59,130,246;--alert-text-on-solid:#ffffff">
          <span class="material-symbols-outlined">info</span>
          <span class="alert-text">Normal runtime expects MySQL. Configure <code>config.local.php</code> with your MySQL connection details before running setup. SQLite is reserved for the automated test harness.</span>
        </div>
      </div>

      <form method="post">
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">rocket_launch</span>
            Apply Pending Migrations
          </button>
          <?php if ($dbReady): ?>
          <a class="btn btn-ghost" href="<?= h(url_for('')) ?>">
            <span class="material-symbols-outlined">arrow_forward</span>
            Open Dashboard
          </a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($logs): ?>
      <div class="section-label">Migration Results</div>
      <div class="table-wrap">
        <table>
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
<script src="<?= h(asset_url('shared/assets/app.js')) ?>"></script>
</body>
</html>
