<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

require_login(['allow_password_change' => true]);

$user = current_user();
$forcePassword = auth_password_change_required($user) || (($_GET['force_password'] ?? '') === '1');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $result = update_user_profile($user, $_POST);
        if (!($result['ok'] ?? false)) {
            $error = (string) ($result['message'] ?? 'Unable to save your profile.');
            $user = array_merge($user, [
                'display_name' => trim((string) ($_POST['display_name'] ?? $user['display_name'] ?? '')),
                'concentration' => (string) ($_POST['concentration'] ?? ($user['concentration'] ?? 'lighting')),
            ]);
        } else {
            flash('success', (string) ($result['message'] ?? 'Profile updated.'));
            header('Location: ' . url_for('profile'));
            exit;
        }
    }
}

ui_head('Profile', '', APP_NAME, 'person');
ui_sidebar(APP_NAME, 'person', nav_items('profile'));
ui_page_header('My Profile', $forcePassword ? 'Change your temporary password before using the rest of the system.' : 'Manage your name, concentration, avatar, and password.', '');
?>
<div class="page-body">
  <?php ui_flash(); ?>
  <?php if ($error !== ''): ?>
  <div class="alerts">
    <div class="alert alert-danger" style="--alert-accent:#ef4444;--alert-accent-rgb:239,68,68;--alert-text-on-solid:#ffffff">
      <span class="material-symbols-outlined">error</span>
      <span class="alert-text"><?= h($error) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="card-grid" style="grid-template-columns:minmax(240px,320px) minmax(0,1fr);align-items:start;">
    <?php ui_card_open('account_circle', 'Avatar'); ?>
      <div class="stack" style="justify-items:center;text-align:center;">
        <img src="<?= h((string) ($user['avatar_url'] ?? user_avatar_url($user))) ?>" alt="<?= h($user['display_name'] ?? 'User') ?> avatar" style="width:128px;height:128px;border-radius:50%;border:1px solid var(--border);background:var(--surface-raised);padding:.5rem;">
        <div>
          <strong><?= h((string) ($user['display_name'] ?? 'User')) ?></strong><br>
          <span class="muted"><?= h((string) ($user['email'] ?? '')) ?></span><br>
          <?= ui_badge(role_label((string) ($user['role'] ?? 'user')), is_admin($user) ? 'info' : 'neutral') ?>
          <?= ui_badge(concentration_label((string) ($user['concentration'] ?? 'lighting')), 'success') ?>
        </div>
      </div>
    <?php ui_card_close(); ?>

    <?php ui_card_open('person', $forcePassword ? 'Finish Account Setup' : 'Profile Details'); ?>
      <form method="post" class="stack">
        <?= csrf_input() ?>
        <div class="card-grid">
          <div class="form-group">
            <label for="display_name">Name</label>
            <input class="form-control" id="display_name" name="display_name" required value="<?= h((string) ($user['display_name'] ?? '')) ?>">
          </div>
          <div class="form-group">
            <label for="email">Email</label>
            <input class="form-control" id="email" value="<?= h((string) ($user['email'] ?? '')) ?>" disabled>
          </div>
          <div class="form-group">
            <label for="concentration">Concentration</label>
            <select class="form-control" id="concentration" name="concentration">
              <?php foreach (user_concentrations() as $value => $label): ?>
              <option value="<?= h($value) ?>"<?= (($user['concentration'] ?? 'lighting') === $value) ? ' selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="section-label">Password</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="current_password"><?= $forcePassword ? 'Temporary Password' : 'Current Password' ?></label>
            <input class="form-control" id="current_password" type="password" name="current_password"<?= $forcePassword ? '' : '' ?>>
          </div>
          <div class="form-group">
            <label for="new_password">New Password</label>
            <input class="form-control" id="new_password" type="password" name="new_password">
            <div class="helper-text">Use at least 10 characters.</div>
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input class="form-control" id="confirm_password" type="password" name="confirm_password">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Profile
          </button>
        </div>
      </form>
    <?php ui_card_close(); ?>
  </div>
</div>
<?php ui_end(); ?>
