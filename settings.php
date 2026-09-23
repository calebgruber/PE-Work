<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$tab = $_GET['tab'] ?? 'inventory';
$migrationLogs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_migrations') {
        $migrationLogs = run_pending_migrations();
        $hasError = false;
        foreach ($migrationLogs as $log) {
            if (($log['status'] ?? '') === 'error') {
                $hasError = true;
                break;
            }
        }
        flash($hasError ? 'danger' : 'success', $hasError ? 'Migration run finished with errors. Review the results below.' : 'Migration run completed successfully.');
        $tab = 'migrations';
    } elseif (schema_ready()) {
        if ($action === 'save_inventory') {
            save_inventory_batch($_POST['items'] ?? []);
            flash('success', 'Inventory updates saved.');
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'add_category') {
            create_category($_POST['category_name'] ?? '');
            flash('success', 'Category added.');
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'add_item') {
            $result = create_inventory_item($_POST);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'import_inventory' && !empty($_FILES['inventory_csv']['tmp_name'])) {
            $result = import_inventory_csv($_FILES['inventory_csv']['tmp_name']);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'save_rule') {
            $result = save_rule($_POST);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=rules'));
            exit;
        }

        if ($action === 'save_layout') {
            save_export_layout($_POST);
            flash('success', 'Export layout settings saved.');
            header('Location: ' . url_for('settings?tab=layout'));
            exit;
        }
    }
}

$catalog = schema_ready() ? fetch_inventory_catalog() : [];
$categories = schema_ready() ? fetch_categories() : [];
$rules = schema_ready() ? fetch_rules() : [];
$layout = export_layout_settings();
$applied = applied_migrations();
$migrationFiles = migration_files();

ui_head('Settings', '', APP_NAME, 'settings');
ui_sidebar(APP_NAME, 'settings', nav_items('settings'));
ui_page_header('System Settings', 'Manage inventory, rules, layout defaults, and database migrations.', '');
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <div class="tabs">
    <a class="tab<?= $tab === 'inventory' ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=inventory')) ?>"><span class="material-symbols-outlined">inventory_2</span>Inventory</a>
    <a class="tab<?= $tab === 'rules' ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=rules')) ?>"><span class="material-symbols-outlined">rule</span>Rules</a>
    <a class="tab<?= $tab === 'layout' ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=layout')) ?>"><span class="material-symbols-outlined">dashboard_customize</span>Layout</a>
    <a class="tab<?= $tab === 'migrations' ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=migrations')) ?>"><span class="material-symbols-outlined">database</span>Migrations</a>
  </div>

  <?php if ($tab === 'inventory'): ?>
    <?php if (!schema_ready()): ?>
      <?php ui_card_open('database', 'Apply Migrations First'); ?>
        <div class="empty-state">
          <span class="material-symbols-outlined">database</span>
          <h3>Inventory settings are waiting on the starter migration</h3>
          <p>Run the initial migration from the migrations tab or setup page before editing categories and items.</p>
        </div>
      <?php ui_card_close(); ?>
    <?php else: ?>
      <?php ui_card_open('inventory_2', 'Inventory Catalog'); ?>
        <form method="post">
          <input type="hidden" name="action" value="save_inventory">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Item</th>
                  <th>Shop Has</th>
                  <th>Unit</th>
                  <th>Item Note</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($catalog as $category): ?>
                  <?php foreach ($category['items'] as $item): ?>
                  <tr class="inventory-row">
                    <td><?= h($category['name']) ?></td>
                    <td><strong><?= h($item['name']) ?></strong></td>
                    <td><input class="form-control compact-input" type="number" min="0" name="items[<?= h((string) $item['id']) ?>][shop_quantity]" value="<?= h((string) $item['shop_quantity']) ?>"></td>
                    <td><input class="form-control compact-input" name="items[<?= h((string) $item['id']) ?>][unit]" value="<?= h($item['unit'] ?? '') ?>"></td>
                    <td>
                      <textarea class="form-control" name="items[<?= h((string) $item['id']) ?>][default_note]"><?= h($item['default_note'] ?? '') ?></textarea>
                      <?php if (!empty($item['default_note'])): ?>
                      <button type="button" class="icon-link" data-note-trigger data-note-title="<?= h($item['name']) ?> note" data-note-body="<?= h($item['default_note']) ?>">
                        <span class="material-symbols-outlined">visibility</span>
                      </button>
                      <?php endif; ?>
                    </td>
                    <td><textarea class="form-control" name="items[<?= h((string) $item['id']) ?>][description]"><?= h($item['description'] ?? '') ?></textarea></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <span class="material-symbols-outlined">save</span>
              Save Inventory
            </button>
          </div>
        </form>
      <?php ui_card_close(); ?>

      <div class="card-grid">
        <?php ui_card_open('add_box', 'Add Category / Item'); ?>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group">
              <label for="category_name">New Category</label>
              <input class="form-control" id="category_name" name="category_name" placeholder="Fixtures">
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-ghost">
                <span class="material-symbols-outlined">add</span>
                Add Category
              </button>
            </div>
          </form>

          <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

          <form method="post" class="stack">
            <input type="hidden" name="action" value="add_item">
            <div class="form-group">
              <label for="category_id">Category</label>
              <select class="form-control" id="category_id" name="category_id">
                <option value="">Choose a category</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?= h((string) $category['id']) ?>"><?= h($category['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="name">Item Name</label>
              <input class="form-control" id="name" name="name">
            </div>
            <div class="card-grid">
              <div class="form-group">
                <label for="shop_quantity">Shop Has</label>
                <input class="form-control" type="number" min="0" id="shop_quantity" name="shop_quantity" value="0">
              </div>
              <div class="form-group">
                <label for="unit">Unit</label>
                <input class="form-control" id="unit" name="unit" value="ea">
              </div>
            </div>
            <div class="form-group">
              <label for="default_note">Item Note</label>
              <textarea class="form-control" id="default_note" name="default_note"></textarea>
            </div>
            <div class="form-group">
              <label for="description">Description</label>
              <textarea class="form-control" id="description" name="description"></textarea>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">add_circle</span>
                Add Item
              </button>
            </div>
          </form>
        <?php ui_card_close(); ?>

        <?php ui_card_open('upload_file', 'Import From Excel-Style CSV'); ?>
          <p class="helper-text">Upload a CSV exported from Excel with columns: <code>category,name,shop_quantity,unit,default_note,description</code>.</p>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_inventory">
            <div class="form-group">
              <label for="inventory_csv">CSV File</label>
              <input class="form-control" type="file" id="inventory_csv" name="inventory_csv" accept=".csv,text/csv">
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">upload</span>
                Import Inventory
              </button>
            </div>
          </form>
        <?php ui_card_close(); ?>
      </div>
    <?php endif; ?>
  <?php elseif ($tab === 'rules'): ?>
    <?php ui_card_open('rule', 'Global Auto-Pull Rules'); ?>
      <?php if (!schema_ready()): ?>
        <p class="helper-text">Run the starter migration before saving rules.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>When You Pull</th>
                <th>Suggest</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rules as $rule): ?>
              <tr>
                <td><?= h((string) $rule['trigger_quantity']) ?> × <?= h($rule['trigger_item_name']) ?></td>
                <td><?= h((string) $rule['required_quantity']) ?> × <?= h($rule['required_item_name']) ?></td>
                <td><?= h($rule['note']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <form method="post" class="stack" style="margin-top:1rem;">
          <input type="hidden" name="action" value="save_rule">
          <div class="card-grid">
            <div class="form-group">
              <label for="trigger_item_id">Trigger Item</label>
              <select class="form-control" id="trigger_item_id" name="trigger_item_id">
                <option value="">Choose an item</option>
                <?php foreach ($catalog as $category): foreach ($category['items'] as $item): ?>
                <option value="<?= h((string) $item['id']) ?>"><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                <?php endforeach; endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="trigger_quantity">Trigger Quantity</label>
              <input class="form-control" id="trigger_quantity" type="number" min="1" name="trigger_quantity" value="1">
            </div>
            <div class="form-group">
              <label for="required_item_id">Suggested Item</label>
              <select class="form-control" id="required_item_id" name="required_item_id">
                <option value="">Choose an item</option>
                <?php foreach ($catalog as $category): foreach ($category['items'] as $item): ?>
                <option value="<?= h((string) $item['id']) ?>"><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                <?php endforeach; endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="required_quantity">Suggested Quantity</label>
              <input class="form-control" id="required_quantity" type="number" min="1" name="required_quantity" value="1">
            </div>
          </div>
          <div class="form-group">
            <label for="rule_note">Rule Note</label>
            <textarea class="form-control" id="rule_note" name="note" placeholder="Example: Every SolaFrame should ship with a stagepin to True1 adapter."></textarea>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <span class="material-symbols-outlined">save</span>
              Save Rule
            </button>
          </div>
        </form>
      <?php endif; ?>
    <?php ui_card_close(); ?>
  <?php elseif ($tab === 'layout'): ?>
    <?php ui_card_open('dashboard_customize', 'Paperwork Layout Starter'); ?>
      <p class="helper-text">This tab stores the first export layout controls in the database so later drag-and-drop editor work has a migration-backed home.</p>
      <form method="post">
        <input type="hidden" name="action" value="save_layout">
        <div class="card-grid">
          <div class="form-group">
            <label for="header_text">Header Text</label>
            <input class="form-control" id="header_text" name="header_text" value="<?= h($layout['layout.header_text']) ?>">
          </div>
          <div class="form-group">
            <label for="footer_text">Footer Text</label>
            <input class="form-control" id="footer_text" name="footer_text" value="<?= h($layout['layout.footer_text']) ?>">
          </div>
        </div>
        <div class="pill-row" style="margin-top:1rem;">
          <label class="tab"><input type="checkbox" name="show_image" value="1" <?= $layout['layout.show_image'] === '1' ? 'checked' : '' ?>> Show image on exports</label>
          <label class="tab"><input type="checkbox" name="show_page_numbers" value="1" <?= $layout['layout.show_page_numbers'] === '1' ? 'checked' : '' ?>> Page X of X</label>
          <label class="tab"><input type="checkbox" name="show_revision_summary" value="1" <?= $layout['layout.show_revision_summary'] === '1' ? 'checked' : '' ?>> Revision summary block</label>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Layout
          </button>
        </div>
      </form>
    <?php ui_card_close(); ?>
  <?php else: ?>
    <?php ui_card_open('database', 'Database Migrations'); ?>
      <form method="post">
        <input type="hidden" name="action" value="run_migrations">
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">sync</span>
            Apply Pending Migrations
          </button>
          <a class="btn btn-ghost" href="<?= h(url_for('setup')) ?>">
            <span class="material-symbols-outlined">rocket_launch</span>
            Open Setup
          </a>
        </div>
      </form>

      <?php if ($migrationLogs): ?>
      <div class="section-label">Latest Run</div>
      <div class="stack" style="margin-bottom:1rem;">
        <?php foreach ($migrationLogs as $log): ?>
        <div class="summary-block">
          <strong><?= h($log['name']) ?> · <?= h(ucfirst($log['status'])) ?></strong>
          <div class="muted"><?= h($log['message']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="table-wrap" style="margin-top:1rem;">
        <table>
          <thead>
            <tr>
              <th>Migration</th>
              <th>Status</th>
              <th>Applied At / Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($migrationFiles as $file): ?>
              <?php $name = basename($file, '.php'); ?>
              <tr>
                <td><?= h($name) ?></td>
                <td><?= isset($applied[$name]) ? ui_badge('Applied', 'success') : ui_badge('Pending', 'warning') ?></td>
                <td><?= h($applied[$name] ?? 'Waiting to run') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php ui_card_close(); ?>
  <?php endif; ?>
</div>
<?php ui_end(); ?>
