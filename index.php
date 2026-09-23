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
        <p>This repository now includes a migration system, seeded inventory, and starter settings. Apply the migration before using the dashboard.</p>
      </div>
      <div class="form-actions">
        <a class="btn btn-primary" href="<?= h(url_for('setup')) ?>">
          <span class="material-symbols-outlined">rocket_launch</span>
          Open Setup
        </a>
      </div>
    <?php ui_card_close(); ?>
  <?php else: ?>

  <div class="card-grid">
    <?php ui_card_open('dashboard', 'At a Glance'); ?>
      <div class="stats-grid">
        <div class="stat-tile"><div class="stat-label">Shows</div><div class="stat-value"><?= h((string) $stats['shows']) ?></div><div class="stat-subtext">Tracked productions</div></div>
        <div class="stat-tile"><div class="stat-label">Inventory Items</div><div class="stat-value"><?= h((string) $stats['items']) ?></div><div class="stat-subtext">Available for orders</div></div>
        <div class="stat-tile"><div class="stat-label">Revisions</div><div class="stat-value"><?= h((string) $stats['revisions']) ?></div><div class="stat-subtext">Initials + change sets</div></div>
        <div class="stat-tile"><div class="stat-label">Global Rules</div><div class="stat-value"><?= h((string) $stats['rules']) ?></div><div class="stat-subtext">Auto-pull reminders</div></div>
      </div>
    <?php ui_card_close(); ?>

    <?php ui_card_open('print', 'Paperwork Workflow'); ?>
      <div class="stack">
        <div class="summary-block">
          <strong>Revision-friendly ordering</strong>
          Each show keeps the initial order plus every saved revision so you can track what changed from first pull through strike.
        </div>
        <div class="summary-block">
          <strong>PDF-ready export views</strong>
          Use the Order, Spare List, and Return Checklist print layouts, then save to PDF from the browser for shop distribution.
        </div>
        <div class="summary-block">
          <strong>Starter layout settings</strong>
          The layout tab stores export header/footer toggles now so future drag-and-drop paperwork editing has a migration-backed home.
        </div>
      </div>
    <?php ui_card_close(); ?>
  </div>

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
