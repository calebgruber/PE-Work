<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

if (!schema_ready() || !auth_tables_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

require_admin();

$user = current_user();
$error = '';
$inviteInput = [
    'display_name' => '',
    'email' => '',
    'role' => 'user',
    'concentration' => 'lighting',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $inviteInput['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
        $inviteInput['email'] = trim((string) ($_POST['email'] ?? ''));
        $inviteInput['role'] = (string) ($_POST['role'] ?? 'user');
        $inviteInput['concentration'] = (string) ($_POST['concentration'] ?? 'lighting');
        $result = create_user_invite($inviteInput, $user);
        if (!($result['ok'] ?? false)) {
            $error = (string) ($result['message'] ?? 'Unable to create user.');
        } else {
            flash('success', (string) ($result['message'] ?? 'User created and invite email sent.'));
            header('Location: ' . url_for('users'));
            exit;
        }
    }
}

$users = list_users();

ui_head('Users', '', APP_NAME, 'group');
ui_sidebar(APP_NAME, 'group', nav_items('users'));
ui_page_header('Users', 'Invite-only account management for admins.', '');
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

  <?php ui_card_open('person_add', 'Create User Invite'); ?>
    <form method="post" class="stack">
      <?= csrf_input() ?>
      <div class="card-grid">
        <div class="form-group">
          <label for="display_name">Full Name</label>
          <input class="form-control" id="display_name" name="display_name" required value="<?= h($inviteInput['display_name']) ?>">
        </div>
        <div class="form-group">
          <label for="email">Email</label>
          <input class="form-control" id="email" type="email" name="email" required value="<?= h($inviteInput['email']) ?>">
        </div>
        <div class="form-group">
          <label for="role">Role</label>
          <select class="form-control" id="role" name="role">
            <?php foreach (user_roles() as $value => $label): ?>
            <option value="<?= h($value) ?>"<?= $inviteInput['role'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="concentration">Concentration</label>
          <select class="form-control" id="concentration" name="concentration">
            <?php foreach (user_concentrations() as $value => $label): ?>
            <option value="<?= h($value) ?>"<?= $inviteInput['concentration'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="helper-text">A random temporary password is generated automatically and emailed to the new user. They must change it on first login.</div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <span class="material-symbols-outlined">outgoing_mail</span>
          Create User + Send Invite
        </button>
      </div>
    </form>
  <?php ui_card_close(); ?>

  <?php ui_card_open('group', 'Existing Users'); ?>
    <div class="table-wrap">
      <table class="table table-vcenter">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Concentration</th>
            <th>First Login</th>
            <th>Last Login</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $listedUser): ?>
          <tr>
            <td>
              <strong><?= h((string) ($listedUser['display_name'] ?? '')) ?></strong><br>
              <span class="muted"><?= h((string) ($listedUser['email'] ?? '')) ?></span>
            </td>
            <td><?= ui_badge(role_label((string) ($listedUser['role'] ?? 'user')), ($listedUser['role'] ?? '') === 'admin' ? 'info' : 'neutral') ?></td>
            <td><?= ui_badge(concentration_label((string) ($listedUser['concentration'] ?? 'lighting')), 'success') ?></td>
            <td><?= (int) ($listedUser['must_change_password'] ?? 0) === 1 ? ui_badge('Password Change Required', 'warning') : ui_badge('Ready', 'success') ?></td>
            <td><?= h((string) (($listedUser['last_login_at'] ?? '') ?: '—')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ui_card_close(); ?>
</div>
<?php ui_end(); ?>
