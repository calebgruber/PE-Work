<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$stats = dashboard_stats();
$shows = list_shows();

ui_head('Dashboard', '', APP_NAME, 'theater_comedy');
ui_sidebar(APP_NAME, 'theater_comedy', nav_items('dashboard'));

$actions = '<a class="btn btn-primary" href="' . h(url_for('show')) . '"><span class="material-symbols-outlined">add</span>New Show</a>'
    . '<a class="btn btn-ghost" href="' . h(url_for('settings')) . '"><span class="material-symbols-outlined">settings</span>System Settings</a>';

ui_page_header('Shop Order Dashboard', 'Manage shows, inventory, revisions, exports, and migrations from one place.', $actions);
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <?php if (!schema_ready()): ?>
    <?php ui_card_open('construction', 'Finish Setup'); ?>
      <div class="empty-state">
        <span class="material-symbols-outlined">construction</span>
        <h3>Run the starter migration first</h3>
        <p>This repository now includes a migration system and starter settings. Apply the migration before using the dashboard.</p>
      </div>
      <div class="form-actions">
        <a class="btn btn-primary" href="<?= h(url_for('setup')) ?>">
          <span class="material-symbols-outlined">rocket_launch</span>
          Open Setup
        </a>
      </div>
    <?php ui_card_close(); ?>
  <?php else: ?>

  <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:1.5rem;">
    <div class="stat-card">
      <div class="stat-icon"><span class="material-symbols-outlined">theater_comedy</span></div>
      <div class="stat-label">Shows</div>
      <div class="stat-value"><?= h((string) $stats['shows']) ?></div>
      <div class="stat-sub">Tracked productions</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.12);color:var(--warning)">
        <span class="material-symbols-outlined">inventory_2</span>
      </div>
      <div class="stat-label">Inventory</div>
      <div class="stat-value"><?= h((string) $stats['items']) ?></div>
      <div class="stat-sub">Available in shop</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(16,185,129,.12);color:var(--success)">
        <span class="material-symbols-outlined">history</span>
      </div>
      <div class="stat-label">Revisions</div>
      <div class="stat-value"><?= h((string) $stats['revisions']) ?></div>
      <div class="stat-sub">Initials + updates</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(239,68,68,.12);color:var(--danger)">
        <span class="material-symbols-outlined">rule</span>
      </div>
      <div class="stat-label">Rules</div>
      <div class="stat-value"><?= h((string) $stats['rules']) ?></div>
      <div class="stat-sub">Auto-pull reminders</div>
    </div>
  </div>

  <?php ui_card_open('print', 'Paperwork Workflow'); ?>
    <div class="apps-grid">
      <a href="<?= h(url_for('show')) ?>" class="app-tile">
        <span class="material-symbols-outlined">playlist_add</span>
        <span class="app-tile-name">Initial Orders</span>
      </a>
      <a href="<?= h(url_for('show')) ?>" class="app-tile">
        <span class="material-symbols-outlined">history</span>
        <span class="app-tile-name">Revisions</span>
      </a>
      <a href="<?= h(url_for('show')) ?>" class="app-tile">
        <span class="material-symbols-outlined">picture_as_pdf</span>
        <span class="app-tile-name">PDF Exports</span>
      </a>
      <a href="<?= h(url_for('settings?tab=layout')) ?>" class="app-tile">
        <span class="material-symbols-outlined">dashboard_customize</span>
        <span class="app-tile-name">Layout Editor</span>
      </a>
    </div>
  <?php ui_card_close(); ?>

  <?php ui_card_open('checklist', 'Shows'); ?>
    <?php if ($shows): ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Show</th>
            <th>Theatre</th>
            <th>Latest Revision</th>
            <th>Revision Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($shows as $show): ?>
          <tr>
            <td>
              <strong><?= h($show['show_name']) ?></strong><br>
              <span class="muted"><?= h($show['shop_name']) ?></span>
            </td>
            <td><?= h($show['theatre_name']) ?></td>
            <td><?= $show['latest_revision_code'] ? ui_badge($show['latest_revision_code'], 'info') : ui_badge('No Revision', 'neutral') ?></td>
            <td><?= h($show['latest_revision_date'] ?: '—') ?></td>
            <td><a class="btn btn-sm btn-ghost" href="<?= h(url_for('show?show_id=' . (int) $show['id'])) ?>">Open</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <span class="material-symbols-outlined">theater_comedy</span>
      <h3>No shows yet</h3>
      <p>Create the first show record, then generate the initial shop order and its revisions.</p>
    </div>
    <?php endif; ?>
  <?php ui_card_close(); ?>

  <?php endif; ?>
</div>
<?php ui_end(); ?>
