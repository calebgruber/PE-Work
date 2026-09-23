<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

if (!schema_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

$showId = isset($_GET['show_id']) ? (int) $_GET['show_id'] : null;
$show = $showId ? find_show($showId) : blank_show();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_show') {
        $result = save_show_record($_POST, $showId ?: null);
        if ($result['errors']) {
            foreach ($result['errors'] as $error) {
                flash('danger', $error);
            }
            $show = array_merge(blank_show(), $result['show']);
        } else {
            $show = $result['show'];
            $showId = (int) $show['id'];
            flash('success', 'Show information saved.');
            header('Location: ' . url_for('show?show_id=' . $showId));
            exit;
        }
    }

    if ($action === 'create_initial_revision' && $showId) {
        $existing = find_latest_revision($showId);
        if ($existing) {
            $revisionId = (int) $existing['id'];
            flash('info', 'Initial shop order already exists.');
        } else {
            $revisionId = create_initial_revision($showId);
            flash('success', 'Initial shop order created.');
        }
        header('Location: ' . url_for('show?show_id=' . $showId . '&revision_id=' . $revisionId));
        exit;
    }

    if ($action === 'create_revision' && $showId) {
        $revisionId = create_next_revision($showId);
        flash('success', 'Next revision created.');
        header('Location: ' . url_for('show?show_id=' . $showId . '&revision_id=' . $revisionId));
        exit;
    }

    if ($action === 'save_revision' && !empty($_POST['revision_id'])) {
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            http_response_code(404);
            exit('Revision not found for this show.');
        }
        save_revision_lines($revisionId, $_POST['items'] ?? []);
        flash('success', 'Revision line items saved.');
        header('Location: ' . url_for('show?show_id=' . $showId . '&revision_id=' . $revisionId));
        exit;
    }
}

$revisions = $showId ? list_revisions($showId) : [];
$currentRevision = null;
if (!empty($_GET['revision_id'])) {
    $currentRevision = find_revision((int) $_GET['revision_id']);
    if ($currentRevision && $showId && (int) $currentRevision['show_id'] !== (int) $showId) {
        $currentRevision = null;
    }
}
if (!$currentRevision && $showId) {
    $currentRevision = find_latest_revision($showId);
}

$catalog = $currentRevision ? catalog_for_revision((int) $currentRevision['id']) : fetch_inventory_catalog();
$totals = $currentRevision ? revision_totals((int) $currentRevision['id']) : ['rent_total' => 0, 'spare_total' => 0, 'overall_total' => 0];
$suggestions = $currentRevision ? rule_suggestions((int) $currentRevision['id']) : [];

ui_head('Show Builder', '', APP_NAME, 'theater_comedy');
ui_sidebar(APP_NAME, 'theater_comedy', nav_items('shows'));

$actions = '<a class="btn btn-ghost" href="' . h(url_for('settings')) . '"><span class="material-symbols-outlined">inventory_2</span>Inventory</a>';
if ($showId && $currentRevision) {
    $actions .= '<a class="btn btn-primary" href="' . h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $currentRevision['id'])) . '"><span class="material-symbols-outlined">print</span>Exports</a>';
}

ui_page_header($showId ? ($show['show_name'] ?: 'Show Workspace') : 'Create Show', 'Required contacts are enforced; dates, addresses, and image are optional.', $actions);
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <?php if (!$showId): ?>
    <?php ui_card_open('theater_comedy', 'Create Show'); ?>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="save_show">

        <div class="section-label">Required</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="show_name">Show Name</label>
            <input class="form-control" id="show_name" name="show_name" required value="<?= h($show['show_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="theatre_name">Theatre Name</label>
            <input class="form-control" id="theatre_name" name="theatre_name" required value="<?= h($show['theatre_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="shop_name">Shop Name</label>
            <input class="form-control" id="shop_name" name="shop_name" required value="<?= h($show['shop_name'] ?? '') ?>">
          </div>
        </div>

        <div class="card-grid">
          <?php
          $people = [
              ['key' => 'ld', 'label' => 'LD'],
              ['key' => 'assistant_ld', 'label' => 'Assistant LD'],
              ['key' => 'production_electrician', 'label' => 'Production Electrician'],
              ['key' => 'shop_manager', 'label' => 'Shop Manager'],
              ['key' => 'assistant_shop_manager', 'label' => 'Assistant Shop Manager'],
          ];
          foreach ($people as $person):
          ?>
          <div class="summary-block">
            <strong><?= h($person['label']) ?></strong>
            <div class="form-group">
              <label><?= h($person['label']) ?> Name</label>
              <input class="form-control" name="<?= h($person['key']) ?>_name" required value="<?= h($show[$person['key'] . '_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input class="form-control" type="email" name="<?= h($person['key']) ?>_email" required value="<?= h($show[$person['key'] . '_email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Phone</label>
              <input class="form-control" name="<?= h($person['key']) ?>_phone" required value="<?= h($show[$person['key'] . '_phone'] ?? '') ?>">
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="section-label">Optional</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="show_image_url">Show Image Path</label>
            <input class="form-control" id="show_image_url" name="show_image_url" placeholder="images/hamlet.jpg" value="<?= h($show['show_image_url'] ?? '') ?>">
            <div class="helper-text">Use an app-relative path only. External image URLs are blocked.</div>
          </div>
          <div class="form-group">
            <label for="pull_date">Pull Date</label>
            <input class="form-control" type="date" id="pull_date" name="pull_date" value="<?= h($show['pull_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="return_date">Return Date</label>
            <input class="form-control" type="date" id="return_date" name="return_date" value="<?= h($show['return_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="strike_date">Strike Date</label>
            <input class="form-control" type="date" id="strike_date" name="strike_date" value="<?= h($show['strike_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="opening_date">Opening Date</label>
            <input class="form-control" type="date" id="opening_date" name="opening_date" value="<?= h($show['opening_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="closing_date">Closing Date</label>
            <input class="form-control" type="date" id="closing_date" name="closing_date" value="<?= h($show['closing_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="theatre_address">Theatre Address</label>
            <textarea class="form-control" id="theatre_address" name="theatre_address"><?= h($show['theatre_address'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label for="shop_address">Shop Address</label>
            <textarea class="form-control" id="shop_address" name="shop_address"><?= h($show['shop_address'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-group">
          <label for="show_notes">Show Notes</label>
          <textarea class="form-control" id="show_notes" name="show_notes"><?= h($show['show_notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Show
          </button>
        </div>
      </form>
    <?php ui_card_close(); ?>
  <?php else: ?>
  <div class="card-grid">
    <?php ui_card_open('theater_comedy', 'Show Information'); ?>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="save_show">

        <div class="section-label">Required</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="show_name">Show Name</label>
            <input class="form-control" id="show_name" name="show_name" required value="<?= h($show['show_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="theatre_name">Theatre Name</label>
            <input class="form-control" id="theatre_name" name="theatre_name" required value="<?= h($show['theatre_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="shop_name">Shop Name</label>
            <input class="form-control" id="shop_name" name="shop_name" required value="<?= h($show['shop_name'] ?? '') ?>">
          </div>
        </div>

        <div class="card-grid">
          <?php
          $people = [
              ['key' => 'ld', 'label' => 'LD'],
              ['key' => 'assistant_ld', 'label' => 'Assistant LD'],
              ['key' => 'production_electrician', 'label' => 'Production Electrician'],
              ['key' => 'shop_manager', 'label' => 'Shop Manager'],
              ['key' => 'assistant_shop_manager', 'label' => 'Assistant Shop Manager'],
          ];
          foreach ($people as $person):
          ?>
          <div class="summary-block">
            <strong><?= h($person['label']) ?></strong>
            <div class="form-group">
              <label><?= h($person['label']) ?> Name</label>
              <input class="form-control" name="<?= h($person['key']) ?>_name" required value="<?= h($show[$person['key'] . '_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input class="form-control" type="email" name="<?= h($person['key']) ?>_email" required value="<?= h($show[$person['key'] . '_email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Phone</label>
              <input class="form-control" name="<?= h($person['key']) ?>_phone" required value="<?= h($show[$person['key'] . '_phone'] ?? '') ?>">
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="section-label">Optional</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="show_image_url">Show Image Path</label>
            <input class="form-control" id="show_image_url" name="show_image_url" placeholder="images/hamlet.jpg" value="<?= h($show['show_image_url'] ?? '') ?>">
            <div class="helper-text">Use an app-relative path only. External image URLs are blocked.</div>
          </div>
          <div class="form-group">
            <label for="pull_date">Pull Date</label>
            <input class="form-control" type="date" id="pull_date" name="pull_date" value="<?= h($show['pull_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="return_date">Return Date</label>
            <input class="form-control" type="date" id="return_date" name="return_date" value="<?= h($show['return_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="strike_date">Strike Date</label>
            <input class="form-control" type="date" id="strike_date" name="strike_date" value="<?= h($show['strike_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="opening_date">Opening Date</label>
            <input class="form-control" type="date" id="opening_date" name="opening_date" value="<?= h($show['opening_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="closing_date">Closing Date</label>
            <input class="form-control" type="date" id="closing_date" name="closing_date" value="<?= h($show['closing_date'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="theatre_address">Theatre Address</label>
            <textarea class="form-control" id="theatre_address" name="theatre_address"><?= h($show['theatre_address'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label for="shop_address">Shop Address</label>
            <textarea class="form-control" id="shop_address" name="shop_address"><?= h($show['shop_address'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-group">
          <label for="show_notes">Show Notes</label>
          <textarea class="form-control" id="show_notes" name="show_notes"><?= h($show['show_notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Show
          </button>
        </div>
      </form>
    <?php ui_card_close(); ?>

    <?php ui_card_open('history', 'Revisions'); ?>
      <div class="show-summary">
        <div class="summary-block"><strong>Selected Revision</strong><?= h($currentRevision['revision_code'] ?? 'None yet') ?></div>
        <div class="summary-block"><strong>Revision Date</strong><?= h($currentRevision['revision_date'] ?? '—') ?></div>
        <div class="summary-block"><strong>Rent Total</strong><?= h((string) $totals['rent_total']) ?></div>
        <div class="summary-block"><strong>Spare Total</strong><?= h((string) $totals['spare_total']) ?></div>
        <div class="summary-block"><strong>Combined Total</strong><?= h((string) $totals['overall_total']) ?></div>
      </div>

      <div class="pill-row" style="margin:1rem 0;">
        <?php foreach ($revisions as $revision): ?>
          <a class="tab<?= $currentRevision && (int) $currentRevision['id'] === (int) $revision['id'] ? ' active' : '' ?>" href="<?= h(url_for('show?show_id=' . $showId . '&revision_id=' . (int) $revision['id'])) ?>">
            <span class="material-symbols-outlined">history</span>
            <?= h($revision['revision_code']) ?> · <?= h($revision['revision_date']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="form-actions">
        <?php if (!$revisions): ?>
        <form method="post">
          <input type="hidden" name="action" value="create_initial_revision">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">playlist_add</span>
            Create Initial Order
          </button>
        </form>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="action" value="create_revision">
          <button type="submit" class="btn btn-ghost">
            <span class="material-symbols-outlined">add_circle</span>
            Create Next Revision
          </button>
        </form>
        <?php endif; ?>
      </div>
    <?php ui_card_close(); ?>
  </div>

  <?php if ($showId && $currentRevision): ?>
  <div class="card-grid">
    <?php ui_card_open('rule', 'Rule Suggestions'); ?>
      <?php if ($suggestions): ?>
      <div class="stack">
        <?php foreach ($suggestions as $suggestion): ?>
        <div class="summary-block">
          <strong><?= h($suggestion['rule']['trigger_item_name']) ?> → <?= h($suggestion['rule']['required_item_name']) ?></strong>
          <div class="muted">Recommended: <?= h((string) $suggestion['recommended_quantity']) ?> | Currently on order: <?= h((string) $suggestion['current_quantity']) ?></div>
          <?php if ($suggestion['rule']['note']): ?><div style="margin-top:0.5rem;"><?= h($suggestion['rule']['note']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <span class="material-symbols-outlined">rule</span>
        <h3>No rule alerts for this revision</h3>
        <p>Add or adjust global item rules in system settings to surface pack-planning reminders here.</p>
      </div>
      <?php endif; ?>
    <?php ui_card_close(); ?>

    <?php ui_card_open('checklist', 'Revision Line Items'); ?>
      <?php if (!$catalog): ?>
        <div class="empty-state">
          <span class="material-symbols-outlined">inventory_2</span>
          <h3>No inventory yet</h3>
          <p>Seeded items should appear after migration. Add more items or import a CSV from the inventory tab in settings.</p>
        </div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="save_revision">
        <input type="hidden" name="revision_id" value="<?= h((string) $currentRevision['id']) ?>">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Shop Has</th>
                <th>Rent</th>
                <th>Spares</th>
                <th>Total</th>
                <th>Action</th>
                <th>Item Pull</th>
                <th>Item Return</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($catalog as $category): ?>
                <tr class="table-section-row"><td colspan="9"><?= h($category['name']) ?></td></tr>
                <?php foreach ($category['items'] as $item): ?>
                <?php $line = $item['line']; ?>
                <tr class="revision-row" data-action-row>
                  <td>
                    <strong><?= h($item['name']) ?></strong>
                    <?php if (!empty($item['default_note'])): ?>
                      <button type="button" class="icon-link" data-note-trigger data-note-title="<?= h($item['name']) ?> note" data-note-body="<?= h($item['default_note']) ?>">
                        <span class="material-symbols-outlined">info</span>
                      </button>
                    <?php endif; ?>
                    <?php if (!empty($item['description'])): ?><div class="muted"><?= h($item['description']) ?></div><?php endif; ?>
                  </td>
                  <td><?= h((string) $item['shop_quantity']) ?><?= !empty($item['unit']) ? ' ' . h($item['unit']) : '' ?></td>
                  <td><input class="form-control compact-input" data-rent-input type="number" min="0" name="items[<?= h((string) $item['id']) ?>][rent_quantity]" value="<?= h((string) ($line['rent_quantity'] ?? 0)) ?>"></td>
                  <td><input class="form-control compact-input" data-spare-input type="number" min="0" name="items[<?= h((string) $item['id']) ?>][spare_quantity]" value="<?= h((string) ($line['spare_quantity'] ?? 0)) ?>"></td>
                  <td class="qty-total" data-total-output><?= h((string) ($line['total_quantity'] ?? 0)) ?></td>
                  <td>
                    <select class="form-control compact-input" data-action-select name="items[<?= h((string) $item['id']) ?>][action]">
                      <option value="" <?= empty($line['action']) ? 'selected' : '' ?>>Blank</option>
                      <option value="add" <?= ($line['action'] ?? '') === 'add' ? 'selected' : '' ?>>Add</option>
                      <option value="return" <?= ($line['action'] ?? '') === 'return' ? 'selected' : '' ?>>Return</option>
                      <option value="exchange" <?= ($line['action'] ?? '') === 'exchange' ? 'selected' : '' ?>>Exchange</option>
                      <option value="note" <?= ($line['action'] ?? '') === 'note' ? 'selected' : '' ?>>See Notes</option>
                    </select>
                  </td>
                  <td><input class="form-control" type="date" name="items[<?= h((string) $item['id']) ?>][pickup_date]" value="<?= h($line['pickup_date'] ?? '') ?>"></td>
                  <td><input class="form-control" type="date" name="items[<?= h((string) $item['id']) ?>][return_date]" value="<?= h($line['return_date'] ?? '') ?>"></td>
                  <td><textarea class="form-control" name="items[<?= h((string) $item['id']) ?>][line_note]"><?= h($line['line_note'] ?? '') ?></textarea></td>
                </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Revision
          </button>
        </div>
      </form>
      <?php endif; ?>
    <?php ui_card_close(); ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php ui_end(); ?>
