<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

function render_show_form(array $show): void
{
    $people = [
        ['key' => 'ld', 'label' => 'LD'],
        ['key' => 'assistant_ld', 'label' => 'Assistant LD'],
        ['key' => 'production_electrician', 'label' => 'Production Electrician'],
        ['key' => 'shop_manager', 'label' => 'Shop Manager'],
        ['key' => 'assistant_shop_manager', 'label' => 'Assistant Shop Manager'],
    ];
    ?>
      <form method="post" class="stack">
        <?= csrf_input() ?>
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
          <?php foreach ($people as $person): ?>
          <div class="summary-block">
            <strong><?= h($person['label']) ?></strong>
            <?php
              $nameId = $person['key'] . '_name';
              $emailId = $person['key'] . '_email';
              $phoneId = $person['key'] . '_phone';
            ?>
            <div class="form-group">
              <label for="<?= h($nameId) ?>"><?= h($person['label']) ?> Name</label>
              <input class="form-control" id="<?= h($nameId) ?>" name="<?= h($person['key']) ?>_name" required value="<?= h($show[$person['key'] . '_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="<?= h($emailId) ?>">Email</label>
              <input class="form-control" id="<?= h($emailId) ?>" type="email" name="<?= h($person['key']) ?>_email" required value="<?= h($show[$person['key'] . '_email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="<?= h($phoneId) ?>">Phone</label>
              <input class="form-control" id="<?= h($phoneId) ?>" name="<?= h($person['key']) ?>_phone" required value="<?= h($show[$person['key'] . '_phone'] ?? '') ?>">
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
    <?php
}

function revision_return_tab(?array $revision): string
{
    return !empty($revision['is_initial']) ? 'orders' : 'revisions';
}

function show_has_initial_revision(int $showId): bool
{
    return find_initial_revision($showId) !== null;
}

if (!schema_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

$showIdParam = $_GET['show_id'] ?? null;
if (is_array($showIdParam)) {
    http_response_code(404);
    exit('Show not found.');
}
$showIdParam = $showIdParam !== null ? trim((string) $showIdParam) : null;
if ($showIdParam !== null && ($showIdParam === '' || !ctype_digit($showIdParam) || (int) $showIdParam <= 0)) {
    http_response_code(404);
    exit('Show not found.');
}
$showId = $showIdParam !== null ? (int) $showIdParam : null;
$tab = $_GET['tab'] ?? 'info';
if (!in_array($tab, ['info', 'orders', 'revisions'], true)) {
    $tab = 'info';
}
$mode = ($_GET['mode'] ?? '') === 'edit' ? 'edit' : 'view';
$revisionOverrideItems = [];
$show = $showId ? find_show($showId) : blank_show();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Your session expired. Refresh the page and try again.');
        header('Location: ' . url_for($showId ? ('show?show_id=' . $showId . '&tab=' . $tab) : 'show'));
        exit;
    }

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
            header('Location: ' . url_for('show?show_id=' . $showId . '&tab=info'));
            exit;
        }
    }

    if ($action === 'create_initial_revision' && $showId) {
        $existing = find_initial_revision($showId);
        if ($existing) {
            $revisionId = (int) $existing['id'];
            flash('info', 'Initial shop order already exists.');
        } else {
            $revisionId = create_initial_revision($showId);
            flash('success', 'Initial shop order created.');
        }
        header('Location: ' . url_for('show?show_id=' . $showId . '&mode=edit&tab=orders&revision_id=' . $revisionId));
        exit;
    }

    if ($action === 'create_revision' && $showId) {
        if (!show_has_initial_revision($showId)) {
            flash('warning', 'Create the initial order first.');
            header('Location: ' . url_for('show?show_id=' . $showId . '&tab=orders'));
            exit;
        }
        try {
            $revisionId = create_next_revision($showId);
            flash('success', 'Next revision created.');
        } catch (RuntimeException $e) {
            flash('warning', $e->getMessage());
            header('Location: ' . url_for('show?show_id=' . $showId . '&tab=orders'));
            exit;
        }
        header('Location: ' . url_for('show?show_id=' . $showId . '&mode=edit&tab=revisions&revision_id=' . $revisionId));
        exit;
    }

    if ($action === 'validate_revision' && !empty($_POST['revision_id'])) {
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['warnings' => [['type' => 'rule', 'message' => 'Revision not found for this show.']]]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'warnings' => revision_validation_warnings(is_array($_POST['items'] ?? null) ? $_POST['items'] : []),
        ]);
        exit;
    }

    if ($action === 'save_revision' && !empty($_POST['revision_id'])) {
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            http_response_code(404);
            exit('Revision not found for this show.');
        }

        $revisionOverrideItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $validationWarnings = revision_validation_warnings($revisionOverrideItems);
        if ($validationWarnings) {
            foreach ($validationWarnings as $warning) {
                flash('danger', $warning['message']);
            }
        } else {
            save_revision_lines($revisionId, $revisionOverrideItems);
            flash('success', 'Order changes saved.');

            $returnTab = revision_return_tab($revision);
            if (isset($_POST['finish_revision'])) {
                header('Location: ' . url_for('show?show_id=' . $showId . '&tab=' . $returnTab));
            } else {
                header('Location: ' . url_for('show?show_id=' . $showId . '&mode=edit&tab=' . $returnTab . '&revision_id=' . $revisionId));
            }
            exit;
        }
    }
}

if ($showId && !$show) {
    http_response_code(404);
    exit('Show not found.');
}

$revisions = $showId ? list_revisions($showId) : [];
$initialRevision = null;
$savedRevisions = [];
foreach ($revisions as $revisionRow) {
    if ((int) ($revisionRow['is_initial'] ?? 0) === 1) {
        $initialRevision = $revisionRow;
    } else {
        $savedRevisions[] = $revisionRow;
    }
}

$latestRevision = $showId ? find_latest_revision($showId) : null;
$currentRevision = null;
if ($mode === 'edit' && !empty($_GET['revision_id'])) {
    $currentRevision = find_revision((int) $_GET['revision_id']);
    if (!$currentRevision || (int) $currentRevision['show_id'] !== (int) $showId) {
        http_response_code(404);
        exit('Revision not found for this show.');
    }
}

$catalog = [];
$totals = ['rent_total' => 0, 'spare_total' => 0, 'overall_total' => 0];
if ($mode === 'edit' && $currentRevision) {
    $catalog = catalog_for_revision((int) $currentRevision['id'], $revisionOverrideItems);
    $totals = revision_totals((int) $currentRevision['id']);
    if ($revisionOverrideItems) {
        $totals = ['rent_total' => 0, 'spare_total' => 0, 'overall_total' => 0];
        foreach (normalize_revision_lines_input($revisionOverrideItems) as $line) {
            $totals['rent_total'] += (int) ($line['rent_quantity'] ?? 0);
            $totals['spare_total'] += (int) ($line['spare_quantity'] ?? 0);
            $totals['overall_total'] += (int) ($line['total_quantity'] ?? 0);
        }
    }
}

ui_head('Show Builder', '', APP_NAME, 'theater_comedy');
ui_sidebar(APP_NAME, 'theater_comedy', nav_items('shows'));

$actions = '<a class="btn btn-ghost" href="' . h(url_for('settings')) . '"><span class="material-symbols-outlined">inventory_2</span>Inventory</a>';
if ($showId && $latestRevision) {
    $actions .= '<a class="btn btn-primary" href="' . h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $latestRevision['id'])) . '"><span class="material-symbols-outlined">print</span>Exports</a>';
}

if ($mode === 'edit' && $showId && $currentRevision) {
    $backTab = revision_return_tab($currentRevision);
    $actions = '<a class="btn btn-ghost" href="' . h(url_for('show?show_id=' . $showId . '&tab=' . $backTab)) . '"><span class="material-symbols-outlined">arrow_back</span>Back</a>';
    $actions .= '<a class="btn btn-primary" href="' . h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $currentRevision['id'])) . '"><span class="material-symbols-outlined">print</span>Exports</a>';
    ui_page_header(($show['show_name'] ?: 'Show Workspace') . ' · ' . $currentRevision['revision_code'], 'Edit line items in a focused workspace. Search, review warnings, then click Done when you are finished.', $actions);
} else {
    ui_page_header($showId ? ($show['show_name'] ?: 'Show Workspace') : 'Create Show', 'Required contacts are enforced; dates, addresses, and image are optional.', $actions);
}
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <?php if (!$showId): ?>
    <?php ui_card_open('theater_comedy', 'Create Show'); ?>
      <?php render_show_form($show); ?>
    <?php ui_card_close(); ?>
  <?php elseif ($mode === 'edit' && $currentRevision): ?>
    <?php ui_card_open($currentRevision['is_initial'] ? 'checklist' : 'history', $currentRevision['is_initial'] ? 'Edit Initial Order' : 'Edit ' . $currentRevision['revision_code']); ?>
      <div class="show-summary">
        <div class="summary-block"><strong>Revision</strong><?= h($currentRevision['revision_code']) ?></div>
        <div class="summary-block"><strong>Date</strong><?= h($currentRevision['revision_date']) ?></div>
        <div class="summary-block"><strong>Rent Total</strong><?= h((string) $totals['rent_total']) ?></div>
        <div class="summary-block"><strong>Spare Total</strong><?= h((string) $totals['spare_total']) ?></div>
        <div class="summary-block"><strong>Combined Total</strong><?= h((string) $totals['overall_total']) ?></div>
      </div>

      <?php if (!$catalog): ?>
        <div class="empty-state">
          <span class="material-symbols-outlined">inventory_2</span>
          <h3>No inventory yet</h3>
          <p>Add inventory in settings before building orders or revisions.</p>
        </div>
      <?php else: ?>
      <form method="post" data-revision-editor>
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_revision">
        <input type="hidden" name="revision_id" value="<?= h((string) $currentRevision['id']) ?>">

        <div class="revision-editor-toolbar">
          <div class="form-group">
            <label for="revision-search">Search Items</label>
            <input class="form-control" id="revision-search" type="search" placeholder="Search by item, description, or notes..." data-revision-search>
          </div>
          <div class="revision-editor-helper">
            <span class="material-symbols-outlined">info</span>
            <span>Blocking warnings update live as you edit. You cannot save until stock and rule requirements are corrected.</span>
          </div>
        </div>

        <div class="revision-alerts hidden" data-revision-warnings-wrap>
          <strong>Blocking warnings</strong>
          <div class="muted">Every required rule and every stock overage must be fixed before you can save or finish this order.</div>
          <div class="revision-alert-list" data-revision-warnings></div>
        </div>

        <div class="revision-category-list">
          <?php foreach ($catalog as $category): ?>
          <section class="revision-category inventory-accordion" data-revision-category data-category-name="<?= h(strtolower($category['name'])) ?>">
            <button type="button" class="inventory-accordion-trigger revision-category-trigger" data-accordion-trigger aria-expanded="false">
              <span><?= h($category['name']) ?></span>
              <span class="muted"><?= h((string) count($category['items'])) ?> item<?= count($category['items']) === 1 ? '' : 's' ?></span>
              <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="inventory-accordion-panel hidden" data-accordion-panel>
              <div class="table-wrap revision-sheet-wrap">
                <table class="revision-sheet">
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
                    <?php foreach ($category['items'] as $item): ?>
                    <?php $line = $item['line']; $currentTotal = (int) ($line['total_quantity'] ?? 0); $shopQuantity = (int) ($item['shop_quantity'] ?? 0); ?>
                    <?php if (!empty($item['is_spacer'])): ?>
                    <tr
                      class="revision-spacer-row"
                      data-revision-item
                      data-item-id="<?= h((string) $item['id']) ?>"
                      data-item-name="<?= h(strtolower($item['name'] . ' ' . ($item['description'] ?? '') . ' ' . ($item['default_note'] ?? ''))) ?>"
                      data-item-label="<?= h($item['name']) ?>"
                      data-shop-quantity="0"
                    >
                      <td colspan="9">
                        <div class="revision-spacer-copy">
                          <strong><?= h($item['name']) ?></strong>
                          <?php if (!empty($item['description'])): ?><span><?= h($item['description']) ?></span><?php endif; ?>
                        </div>
                      </td>
                    </tr>
                    <?php continue; endif; ?>
                    <tr
                      class="revision-row"
                      data-action-row
                      data-revision-item
                      data-item-id="<?= h((string) $item['id']) ?>"
                      data-item-name="<?= h(strtolower($item['name'] . ' ' . ($item['description'] ?? '') . ' ' . ($item['default_note'] ?? '') . ' ' . ($line['line_note'] ?? ''))) ?>"
                      data-item-label="<?= h($item['name']) ?>"
                      data-shop-quantity="<?= h((string) $shopQuantity) ?>"
                    >
                      <td>
                        <div class="revision-item-cell">
                          <strong><?= h($item['name']) ?></strong>
                          <?php if (!empty($item['description'])): ?><div class="muted"><?= h($item['description']) ?></div><?php endif; ?>
                          <?php if (!empty($item['default_note'])): ?>
                          <button type="button" class="icon-link" data-note-trigger data-note-title="<?= h($item['name']) ?> note" data-note-body="<?= h($item['default_note']) ?>">
                            <span class="material-symbols-outlined">info</span>
                          </button>
                          <?php endif; ?>
                        </div>
                        <div class="revision-inline-warning<?= $currentTotal > $shopQuantity ? '' : ' hidden' ?>" data-stock-warning>
                          Over shop stock.
                        </div>
                      </td>
                      <td><?= h((string) $shopQuantity) ?><?= !empty($item['unit']) ? ' ' . h($item['unit']) : '' ?></td>
                      <td><input class="form-control compact-input revision-qty-input" data-rent-input type="number" min="0" name="items[<?= h((string) $item['id']) ?>][rent_quantity]" value="<?= h((string) ($line['rent_quantity'] ?? 0)) ?>"></td>
                      <td><input class="form-control compact-input revision-qty-input" data-spare-input type="number" min="0" name="items[<?= h((string) $item['id']) ?>][spare_quantity]" value="<?= h((string) ($line['spare_quantity'] ?? 0)) ?>"></td>
                      <td><span class="revision-total-box revision-total-inline" data-total-output><?= h((string) $currentTotal) ?></span></td>
                      <td>
                        <select class="form-control compact-input" data-action-select name="items[<?= h((string) $item['id']) ?>][action]">
                          <option value="" <?= empty($line['action']) ? 'selected' : '' ?>>Blank</option>
                          <option value="add" <?= ($line['action'] ?? '') === 'add' ? 'selected' : '' ?>>Add</option>
                          <option value="return" <?= ($line['action'] ?? '') === 'return' ? 'selected' : '' ?>>Return</option>
                          <option value="exchange" <?= ($line['action'] ?? '') === 'exchange' ? 'selected' : '' ?>>Exchange</option>
                          <option value="note" <?= ($line['action'] ?? '') === 'note' ? 'selected' : '' ?>>See Notes</option>
                        </select>
                      </td>
                      <td><input class="form-control compact-input" type="date" name="items[<?= h((string) $item['id']) ?>][pickup_date]" value="<?= h($line['pickup_date'] ?? '') ?>"></td>
                      <td><input class="form-control compact-input" type="date" name="items[<?= h((string) $item['id']) ?>][return_date]" value="<?= h($line['return_date'] ?? '') ?>"></td>
                      <td><textarea class="form-control revision-note-input" name="items[<?= h((string) $item['id']) ?>][line_note]" rows="1"><?= h($line['line_note'] ?? '') ?></textarea></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </section>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-ghost" name="save_continue" value="1" data-revision-submit>
            <span class="material-symbols-outlined">save</span>
            Save &amp; Keep Editing
          </button>
          <button type="submit" class="btn btn-primary" name="finish_revision" value="1" data-revision-submit>
            <span class="material-symbols-outlined">done</span>
            Done
          </button>
        </div>
      </form>
      <?php endif; ?>
    <?php ui_card_close(); ?>
  <?php else: ?>
    <nav class="pill-row workspace-tabs" aria-label="Show workspace sections">
      <a class="tab<?= $tab === 'info' ? ' active' : '' ?>"<?= $tab === 'info' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('show?show_id=' . $showId . '&tab=info')) ?>">
        <span class="material-symbols-outlined">badge</span>
        Show Information
      </a>
      <a class="tab<?= $tab === 'orders' ? ' active' : '' ?>"<?= $tab === 'orders' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('show?show_id=' . $showId . '&tab=orders')) ?>">
        <span class="material-symbols-outlined">assignment</span>
        Orders
      </a>
      <a class="tab<?= $tab === 'revisions' ? ' active' : '' ?>"<?= $tab === 'revisions' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('show?show_id=' . $showId . '&tab=revisions')) ?>">
        <span class="material-symbols-outlined">history</span>
        Revisions
      </a>
    </nav>

    <?php if ($tab === 'info'): ?>
      <?php ui_card_open('theater_comedy', 'Show Information'); ?>
        <?php render_show_form($show); ?>
      <?php ui_card_close(); ?>
    <?php elseif ($tab === 'orders'): ?>
      <?php ui_card_open('assignment', 'Initial Order'); ?>
        <?php if (!$initialRevision): ?>
          <div class="empty-state">
            <span class="material-symbols-outlined">assignment</span>
            <h3>No initial order yet</h3>
            <p>Create the initial order first, then edit it on its own full-page workspace.</p>
          </div>
          <div class="form-actions">
            <form method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="create_initial_revision">
              <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">playlist_add</span>
                Create Initial Order
              </button>
            </form>
          </div>
        <?php else: ?>
          <?php $initialTotals = revision_totals((int) $initialRevision['id']); ?>
          <div class="show-summary">
            <div class="summary-block"><strong>Order</strong><?= h($initialRevision['revision_code']) ?></div>
            <div class="summary-block"><strong>Date</strong><?= h($initialRevision['revision_date']) ?></div>
            <div class="summary-block"><strong>Rent Total</strong><?= h((string) $initialTotals['rent_total']) ?></div>
            <div class="summary-block"><strong>Spare Total</strong><?= h((string) $initialTotals['spare_total']) ?></div>
            <div class="summary-block"><strong>Combined Total</strong><?= h((string) $initialTotals['overall_total']) ?></div>
          </div>
          <div class="helper-text" style="margin-top:1rem;">Initial orders stay editable. Open it any time to adjust quantities, dates, or notes.</div>
          <div class="form-actions">
            <a class="btn btn-primary" href="<?= h(url_for('show?show_id=' . $showId . '&mode=edit&tab=orders&revision_id=' . (int) $initialRevision['id'])) ?>">
              <span class="material-symbols-outlined">edit</span>
              Edit Initial Order
            </a>
            <a class="btn btn-ghost" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $initialRevision['id'])) ?>">
              <span class="material-symbols-outlined">print</span>
              Export Initial Order
            </a>
          </div>
        <?php endif; ?>
      <?php ui_card_close(); ?>
    <?php else: ?>
      <?php ui_card_open('history', 'Revisions'); ?>
        <?php if (!$initialRevision): ?>
          <div class="empty-state">
            <span class="material-symbols-outlined">history</span>
            <h3>Create the initial order first</h3>
            <p>Revisions build from the initial order, so start there before adding revision rounds.</p>
          </div>
        <?php else: ?>
          <div class="form-actions">
            <form method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="create_revision">
              <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">add_circle</span>
                Create Next Revision
              </button>
            </form>
          </div>

          <?php if ($savedRevisions): ?>
          <div class="stack">
            <?php foreach ($savedRevisions as $revision): ?>
            <?php $revisionTotals = revision_totals((int) $revision['id']); ?>
            <div class="summary-block revision-list-card">
              <div class="revision-list-header">
                <div>
                  <strong><?= h($revision['revision_code']) ?></strong>
                  <div class="muted"><?= h($revision['revision_date']) ?></div>
                </div>
                <div class="pill-row">
                  <span class="revision-stock-pill">Rent <?= h((string) $revisionTotals['rent_total']) ?></span>
                  <span class="revision-stock-pill">Spares <?= h((string) $revisionTotals['spare_total']) ?></span>
                  <span class="revision-stock-pill">Total <?= h((string) $revisionTotals['overall_total']) ?></span>
                </div>
              </div>
              <?php if (!empty($revision['summary_note'])): ?><div class="muted"><?= h($revision['summary_note']) ?></div><?php endif; ?>
              <div class="form-actions">
                <a class="btn btn-primary btn-sm" href="<?= h(url_for('show?show_id=' . $showId . '&mode=edit&tab=revisions&revision_id=' . (int) $revision['id'])) ?>">
                  <span class="material-symbols-outlined">edit</span>
                  Edit Revision
                </a>
                <a class="btn btn-ghost btn-sm" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $revision['id'])) ?>">
                  <span class="material-symbols-outlined">print</span>
                  Export
                </a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="empty-state">
            <span class="material-symbols-outlined">history</span>
            <h3>No revisions yet</h3>
            <p>Create the next revision to open a full-page editing workspace for changes.</p>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php ui_card_close(); ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php ui_end(); ?>
