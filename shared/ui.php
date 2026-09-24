<?php

function _ui_context(?string $setHeading = null, ?string $setIcon = null): array
{
    static $heading = '';
    static $icon = '';

    if ($setHeading !== null) {
        $heading = $setHeading;
    }
    if ($setIcon !== null) {
        $icon = $setIcon;
    }

    return ['heading' => $heading, 'icon' => $icon];
}

function _card_accent_color(string $icon): string
{
    static $map = [
        'home' => '#3b82f6',
        'settings' => '#6366f1',
        'theater_comedy' => '#8b5cf6',
        'checklist' => '#10b981',
        'rule' => '#f59e0b',
        'inventory_2' => '#f59e0b',
        'print' => '#ef4444',
    ];

    return $map[$icon] ?? '#3b82f6';
}

function _hex_to_rgb_and_text(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return [$r . ',' . $g . ',' . $b, $lum > 0.55 ? '#000000' : '#ffffff'];
}

function _alert_accent(string $type): array
{
    static $map = [
        'info' => ['#3b82f6', '59,130,246', '#ffffff'],
        'success' => ['#10b981', '16,185,129', '#ffffff'],
        'warning' => ['#f59e0b', '245,158,11', '#000000'],
        'danger' => ['#ef4444', '239,68,68', '#ffffff'],
    ];

    return $map[$type] ?? $map['info'];
}

function ui_head(string $pageTitle, string $appSlug = '', string $appHeading = '', string $headerIcon = 'home'): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
  <script>
    (function(){var t=localStorage.getItem('cg-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();
    document.addEventListener('DOMContentLoaded',function(){var l=document.getElementById('page-loader');if(l)l.classList.add('pg-done');});
  </script>
</head>
<body class="tabler-shell">
<div id="page-loader"></div>
<div class="app page">
<?php
}

function ui_sidebar(string $appHeading, string $headerIcon, array $navItems, string $userLogoutUrl = ''): void
{
    _ui_context($appHeading, $headerIcon);
    $user = current_user();
    $profileUrl = url_for('profile');
    $logoutUrl = $userLogoutUrl !== '' ? $userLogoutUrl : url_for('logout');
    ?>
  <div class="topbar navbar navbar-expand-md">
    <div class="container-xl topbar-shell">
      <a href="<?= h(url_for('')) ?>" class="navbar-brand navbar-brand-autodark topbar-app topbar-app-link">
        <span class="material-symbols-outlined"><?= h($headerIcon) ?></span>
        <?= h($appHeading) ?>
      </a>
      <nav class="navbar-nav top-nav" aria-label="Primary">
<?php foreach ($navItems as $item): ?>
<?php if (!isset($item['section'])): ?>
        <a href="<?= h($item['href'] ?? '#') ?>" class="nav-link top-nav-item<?= !empty($item['active']) ? ' active' : '' ?>">
          <span class="material-symbols-outlined"><?= h($item['icon'] ?? 'circle') ?></span>
          <?= h($item['label'] ?? '') ?>
        </a>
<?php endif; ?>
<?php endforeach; ?>
      </nav>

      <div class="navbar-nav flex-row topbar-right">
      <?php if ($user): ?>
        <a href="<?= h($profileUrl) ?>" class="topbar-user" style="text-decoration:none;">
          <span class="topbar-avatar">
            <img src="<?= h((string) ($user['avatar_url'] ?? user_avatar_url($user))) ?>" alt="" class="topbar-avatar-image">
          </span>
          <span>
            <strong style="display:block;color:#f8fafc;"><?= h((string) ($user['display_name'] ?? 'User')) ?></strong>
            <?= h(role_label((string) ($user['role'] ?? 'user'))) ?>
          </span>
        </a>
        <a href="<?= h($profileUrl) ?>" class="topbar-btn" title="Profile" aria-label="Profile">
          <span class="material-symbols-outlined">person</span>
        </a>
        <form method="post" action="<?= h($logoutUrl) ?>" class="topbar-inline-form" data-start-loader>
          <?= csrf_input() ?>
          <button type="submit" class="topbar-btn" title="Logout" aria-label="Logout">
            <span class="material-symbols-outlined">logout</span>
          </button>
        </form>
      <?php endif; ?>
        <button id="theme-toggle" class="topbar-btn" title="Toggle theme" aria-label="Toggle theme">
          <span class="material-symbols-outlined" id="theme-icon">dark_mode</span>
        </button>
      </div>
    </div>
  </div>

  <main class="content page-wrapper">
<?php
}

function ui_page_header(string $title, string $breadcrumb = '', string $extraHtml = ''): void
{
    $ctx = _ui_context();
    ?>
    <div class="page-header">
      <div>
        <?php if ($ctx['heading']): ?>
        <div class="page-app-name">
          <?= h($ctx['heading']) ?>
        </div>
        <?php endif; ?>
        <h1><?= h($title) ?></h1>
        <?php if ($breadcrumb): ?>
        <div class="breadcrumb"><?= h($breadcrumb) ?></div>
        <?php endif; ?>
      </div>
      <div class="header-actions"><?= $extraHtml ?></div>
    </div>
<?php
}

function ui_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }

    echo '<div class="alerts">';
    foreach ($_SESSION['flash'] as $flash) {
        $type = in_array($flash['type'] ?? '', ['info', 'success', 'warning', 'danger'], true)
            ? $flash['type']
            : 'info';
        $icons = ['info' => 'info', 'success' => 'check_circle', 'warning' => 'warning', 'danger' => 'error'];
        [$color, $rgb, $textOn] = _alert_accent($type);
        echo '<div class="alert alert-' . h($type) . '" style="--alert-accent:' . h($color) . ';--alert-accent-rgb:' . h($rgb) . ';--alert-text-on-solid:' . h($textOn) . '" data-auto-dismiss="5000">';
        echo '<span class="material-symbols-outlined">' . h($icons[$type] ?? 'info') . '</span>';
        echo '<span class="alert-text">' . h($flash['message'] ?? '') . '</span>';
        echo '<button class="alert-close"><span class="material-symbols-outlined">close</span></button>';
        echo '</div>';
    }
    echo '</div>';

    unset($_SESSION['flash']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function ui_card_open(string $icon, string $title, string $extraHeaderHtml = '', string $color = ''): void
{
    if ($color === '') {
        $color = _card_accent_color($icon);
    }
    [$rgb, $textOn] = _hex_to_rgb_and_text($color);
    $cardStyle = 'border-left:3px solid ' . $color . ';--card-accent:' . $color . ';--card-accent-rgb:' . $rgb . ';--card-text-on-solid:' . $textOn;
    echo '<div class="card" style="' . h($cardStyle) . '">';
    echo '<div class="card-top">';
    echo '<div class="card-tab"><span class="material-symbols-outlined">' . h($icon) . '</span><h3>' . h($title) . '</h3></div>';
    if ($extraHeaderHtml) {
        echo '<div class="card-header-actions">' . $extraHeaderHtml . '</div>';
    }
    echo '</div><div class="card-body">';
}

function ui_card_close(string $footerHtml = ''): void
{
    echo '</div>';
    if ($footerHtml) {
        echo '<div class="card-footer">' . $footerHtml . '</div>';
    }
    echo '</div>';
}

function ui_end(): void
{
    ?>
  </main>
</div>
<div id="note-modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="note-modal-title" tabindex="-1">
  <div class="modal-panel">
    <div class="modal-header">
      <h3 id="note-modal-title">Item Note</h3>
      <button type="button" class="btn btn-ghost btn-sm" data-close-modal aria-label="Close note dialog">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="modal-body">
      <p id="note-modal-body" class="modal-copy"></p>
    </div>
  </div>
</div>
<script src="<?= h(asset_url('shared/assets/app.js')) ?>"></script>
<script src="<?= h(asset_url('shared/assets/pe-work.js')) ?>"></script>
</body>
</html>
<?php
}

function ui_badge(string $text, string $type = 'neutral'): string
{
    return '<span class="badge badge-' . h($type) . '">' . h($text) . '</span>';
}
