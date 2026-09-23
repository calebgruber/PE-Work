<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$tab = $_GET['tab'] ?? 'inventory';
$migrationLogs = $_SESSION['migration_logs'] ?? [];
unset($_SESSION['migration_logs']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_migrations') {
        $migrationLogs = run_pending_migrations();
        $_SESSION['migration_logs'] = $migrationLogs;
        $hasError = false;
        foreach ($migrationLogs as $log) {
            if (($log['status'] ?? '') === 'error') {
                $hasError = true;
                break;
            }
        }
        flash($hasError ? 'danger' : 'success', $hasError ? 'Migration run finished with errors. Review the results below.' : 'Migration run completed successfully.');
        header('Location: ' . url_for('settings?tab=migrations'));
        exit;
    } elseif (schema_ready()) {
        if ($action === 'save_inventory') {
            save_inventory_batch($_POST['items'] ?? []);
            flash('success', 'Inventory updates saved.');
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'add_category') {
            $result = create_category($_POST['category_name'] ?? '');
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'update_category') {
            $result = update_category($_POST);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'delete_category') {
            $result = delete_category((int) ($_POST['category_id'] ?? 0));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'add_item') {
            $result = create_inventory_item($_POST);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'delete_inventory_item') {
            $result = delete_inventory_item((int) ($_POST['item_id'] ?? 0));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'import_inventory') {
            $upload = $_FILES['inventory_csv'] ?? null;
            $tmpPath = (string) ($upload['tmp_name'] ?? '');
            $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            $pasteCsv = trim((string) ($_POST['inventory_csv_text'] ?? ''));
            $allowLocalUpload = defined('ALLOW_LOCAL_UPLOADS_FOR_TESTS') && ALLOW_LOCAL_UPLOADS_FOR_TESTS && is_file($tmpPath);
            $isUploadedFile = $tmpPath !== '' && (is_uploaded_file($tmpPath) || $allowLocalUpload);
            if ($pasteCsv !== '') {
                $result = import_inventory_csv_text($pasteCsv);
            } elseif ($uploadError !== UPLOAD_ERR_OK || !$isUploadedFile) {
                $result = ['ok' => false, 'message' => 'Choose a CSV file to import.'];
            } else {
                $result = import_inventory_csv($tmpPath);
            }
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

        if ($action === 'delete_rule') {
            $result = delete_rule((int) ($_POST['rule_id'] ?? 0));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=rules'));
            exit;
        }

        if ($action === 'save_layout') {
            if (!table_exists('app_settings')) {
                flash('danger', 'Layout settings storage is not available until migrations finish successfully.');
            } else {
                save_export_layout($_POST);
                flash('success', 'Export layout settings saved.');
            }
            header('Location: ' . url_for('settings?tab=layout'));
            exit;
        }

        if ($action === 'upload_resource') {
            $result = store_resource_upload($_FILES['resource_pdf'] ?? [], $_POST['resource_title'] ?? '');
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=resources'));
            exit;
        }

        if ($action === 'delete_resource') {
            $result = delete_resource((int) ($_POST['resource_id'] ?? 0));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=resources'));
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
$resources = schema_ready() ? fetch_resources() : [];

ui_head('Settings', '', APP_NAME, 'settings');
ui_sidebar(APP_NAME, 'settings', nav_items($tab === 'resources' ? 'resources' : 'settings'));
ui_page_header('System Settings', 'Manage inventory, rules, layout defaults, and database migrations.', '');
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <nav class="tabs" aria-label="Settings sections">
    <a class="tab<?= $tab === 'inventory' ? ' active' : '' ?>" aria-current="<?= $tab === 'inventory' ? 'page' : 'false' ?>" href="<?= h(url_for('settings?tab=inventory')) ?>"><span class="material-symbols-outlined">inventory_2</span>Inventory</a>
    <a class="tab<?= $tab === 'resources' ? ' active' : '' ?>" aria-current="<?= $tab === 'resources' ? 'page' : 'false' ?>" href="<?= h(url_for('settings?tab=resources')) ?>"><span class="material-symbols-outlined">folder</span>Resources</a>
    <a class="tab<?= $tab === 'rules' ? ' active' : '' ?>" aria-current="<?= $tab === 'rules' ? 'page' : 'false' ?>" href="<?= h(url_for('settings?tab=rules')) ?>"><span class="material-symbols-outlined">rule</span>Rules</a>
    <a class="tab<?= $tab === 'layout' ? ' active' : '' ?>" aria-current="<?= $tab === 'layout' ? 'page' : 'false' ?>" href="<?= h(url_for('settings?tab=layout')) ?>"><span class="material-symbols-outlined">dashboard_customize</span>Layout</a>
    <a class="tab<?= $tab === 'migrations' ? ' active' : '' ?>" aria-current="<?= $tab === 'migrations' ? 'page' : 'false' ?>" href="<?= h(url_for('settings?tab=migrations')) ?>"><span class="material-symbols-outlined">database</span>Migrations</a>
  </nav>

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
        <div class="inventory-toolbar">
          <div class="form-group">
            <label for="inventory-search">Live Search</label>
            <input class="form-control" id="inventory-search" type="search" placeholder="Search inventory..." data-inventory-search>
          </div>
          <div class="form-group">
            <label for="inventory-category-filter">Filter by Category</label>
            <select class="form-control" id="inventory-category-filter" data-category-filter>
              <option value="">All categories</option>
              <?php foreach ($categories as $category): ?>
              <option value="<?= h((string) $category['id']) ?>"><?= h($category['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="inventory-accordion-list">
          <?php foreach ($catalog as $category): ?>
          <section class="inventory-accordion" data-inventory-category data-category-id="<?= h((string) $category['id']) ?>" data-category-name="<?= h(strtolower($category['name'])) ?>">
            <button type="button" class="inventory-accordion-trigger" data-accordion-trigger aria-expanded="false">
              <span><?= h($category['name']) ?></span>
              <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="inventory-accordion-panel hidden" data-accordion-panel>
              <div class="inventory-item-grid">
                <?php foreach ($category['items'] as $item): ?>
                <div class="inventory-item-wrap" data-inventory-item data-item-name="<?= h(strtolower($item['name'] . ' ' . ($item['description'] ?? '') . ' ' . ($item['default_note'] ?? ''))) ?>">
                  <form method="post" class="inventory-item-card">
                    <input type="hidden" name="action" value="save_inventory">
                    <div class="inventory-item-header">
                      <div>
                        <h3><?= h($item['name']) ?></h3>
                        <?php if (!empty($item['description'])): ?><div class="muted"><?= h($item['description']) ?></div><?php endif; ?>
                      </div>
                      <div class="inventory-item-actions">
                        <?php if (!empty($item['default_note'])): ?>
                        <button type="button" class="icon-link" data-note-trigger data-note-title="<?= h($item['name']) ?> note" data-note-body="<?= h($item['default_note']) ?>">
                          <span class="material-symbols-outlined">visibility</span>
                        </button>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="inventory-item-fields">
                      <div class="form-group">
                        <label>Shop Has</label>
                        <input class="form-control compact-input" type="number" min="0" name="items[<?= h((string) $item['id']) ?>][shop_quantity]" value="<?= h((string) $item['shop_quantity']) ?>">
                      </div>
                      <div class="form-group">
                        <label>Unit</label>
                        <input class="form-control compact-input" name="items[<?= h((string) $item['id']) ?>][unit]" value="<?= h($item['unit'] ?? '') ?>">
                      </div>
                    </div>
                    <div class="form-group">
                      <label>Item Note</label>
                      <textarea class="form-control" name="items[<?= h((string) $item['id']) ?>][default_note]"><?= h($item['default_note'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                      <label>Description</label>
                      <textarea class="form-control" name="items[<?= h((string) $item['id']) ?>][description]"><?= h($item['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-actions">
                      <button type="submit" class="btn btn-primary btn-sm">
                        <span class="material-symbols-outlined">save</span>
                        Save
                      </button>
                    </div>
                  </form>
                  <form method="post" class="inventory-item-delete">
                    <input type="hidden" name="action" value="delete_inventory_item">
                    <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE ITEM" data-confirm="Type REMOVE ITEM to permanently remove this inventory item.">
                      <span class="material-symbols-outlined">delete</span>
                      Remove
                    </button>
                  </form>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
          <?php endforeach; ?>
        </div>
      <?php ui_card_close(); ?>

      <div class="card-grid card-grid-2">
        <?php ui_card_open('list', 'Categories'); ?>
          <div class="stack">
            <?php foreach ($categories as $category): ?>
            <form method="post" class="category-editor">
              <input type="hidden" name="action" value="update_category">
              <input type="hidden" name="category_id" value="<?= h((string) $category['id']) ?>">
              <div class="category-editor-grid">
                <div class="form-group">
                  <label>Name</label>
                  <input class="form-control" name="name" value="<?= h($category['name']) ?>">
                </div>
                <div class="form-group">
                  <label>Sort Order</label>
                  <input class="form-control compact-input" type="number" min="0" name="sort_order" value="<?= h((string) $category['sort_order']) ?>">
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">
                  <span class="material-symbols-outlined">save</span>
                  Update
                </button>
              </div>
            </form>
            <form method="post" class="category-delete-form">
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="category_id" value="<?= h((string) $category['id']) ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE CATEGORY" data-confirm="Type REMOVE CATEGORY to delete this category.">
                <span class="material-symbols-outlined">delete</span>
                Remove Category
              </button>
            </form>
            <?php endforeach; ?>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

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
        <?php ui_card_close(); ?>

        <?php ui_card_open('add_box', 'Add Inventory / Import'); ?>
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

          <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

          <p class="helper-text">Upload a CSV exported from Excel with columns: <code>category,name,shop_quantity,unit,default_note,description</code>.</p>
          <form method="post" enctype="multipart/form-data" class="stack">
            <input type="hidden" name="action" value="import_inventory">
            <div class="form-group">
              <label for="inventory_csv">CSV File</label>
              <input class="form-control" type="file" id="inventory_csv" name="inventory_csv" accept=".csv,text/csv">
            </div>
            <div class="form-group">
              <label for="inventory_csv_text">Or paste CSV rows</label>
              <textarea class="form-control" id="inventory_csv_text" name="inventory_csv_text" rows="8" placeholder="category,name,shop_quantity,unit,default_note,description&#10;FIXTURES,HES Solaframe Theatre,12,ea,.,."></textarea>
              <div class="helper-text">Paste one item per line. If you paste text here, it will import this instead of the uploaded file.</div>
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
  <?php elseif ($tab === 'resources'): ?>
    <?php ui_card_open('folder', 'Resources'); ?>
      <?php if (!schema_ready()): ?>
      <div class="empty-state">
        <span class="material-symbols-outlined">database</span>
        <h3>Run migrations first</h3>
        <p>Resources are available after the database migrations have been applied.</p>
      </div>
      <?php else: ?>
      <div class="card-grid card-grid-2">
        <div class="summary-block">
          <strong>Upload PDF Resources</strong>
          <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:1rem;">
            <input type="hidden" name="action" value="upload_resource">
            <div class="form-group">
              <label for="resource_title">Title</label>
              <input class="form-control" id="resource_title" name="resource_title" placeholder="Vectorworks guide">
            </div>
            <div class="form-group">
              <label for="resource_pdf">PDF File</label>
              <input class="form-control" type="file" id="resource_pdf" name="resource_pdf" accept="application/pdf,.pdf">
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">upload_file</span>
                Upload Resource
              </button>
            </div>
          </form>
        </div>
        <div class="summary-block">
          <strong>Use Cases</strong>
          <div class="stack" style="margin-top:1rem;">
            <div class="muted">Upload shop paperwork references, diagrams, manuals, or PDF examples.</div>
            <div class="muted">Each uploaded PDF can be previewed directly in the page on desktop or downloaded on mobile.</div>
          </div>
        </div>
      </div>

      <?php if ($resources): ?>
      <div class="resource-grid">
        <?php foreach ($resources as $resource): ?>
        <article class="resource-card">
          <div class="resource-card-header">
            <div>
              <h3><?= h($resource['title']) ?></h3>
              <div class="muted"><?= h($resource['original_name']) ?></div>
            </div>
            <form method="post">
              <input type="hidden" name="action" value="delete_resource">
              <input type="hidden" name="resource_id" value="<?= h((string) $resource['id']) ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE RESOURCE" data-confirm="Type REMOVE RESOURCE to delete this PDF.">
                <span class="material-symbols-outlined">delete</span>
                Remove
              </button>
            </form>
          </div>
          <div class="resource-actions">
            <a class="btn btn-ghost btn-sm" href="<?= h(url_for('resource_file?id=' . (int) $resource['id'])) ?>" target="_blank" rel="noopener">
              <span class="material-symbols-outlined">open_in_new</span>
              Open PDF
            </a>
            <a class="btn btn-ghost btn-sm" href="<?= h(url_for('resource_file?id=' . (int) $resource['id'] . '&download=1')) ?>">
              <span class="material-symbols-outlined">download</span>
              Download
            </a>
          </div>
          <iframe class="resource-frame" src="<?= h(url_for('resource_file?id=' . (int) $resource['id'])) ?>" title="<?= h($resource['title']) ?>"></iframe>
        </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <span class="material-symbols-outlined">folder</span>
        <h3>No resources uploaded</h3>
        <p>Upload PDFs here to keep shop references and paperwork examples inside the app.</p>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    <?php ui_card_close(); ?>
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
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rules as $rule): ?>
              <tr>
                <td>
                  <select class="form-control" name="trigger_item_id" form="rule-form-<?= (int) $rule['id'] ?>">
                    <option value="">Choose an item</option>
                    <?php foreach ($catalog as $category): foreach ($category['items'] as $item): ?>
                    <option value="<?= h((string) $item['id']) ?>" <?= (int) $rule['trigger_item_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                    <?php endforeach; endforeach; ?>
                  </select>
                  <input class="form-control" type="number" min="1" name="trigger_quantity" value="<?= h((string) $rule['trigger_quantity']) ?>" form="rule-form-<?= (int) $rule['id'] ?>" style="margin-top:0.5rem;">
                </td>
                <td>
                  <select class="form-control" name="required_item_id" form="rule-form-<?= (int) $rule['id'] ?>">
                    <option value="">Choose an item</option>
                    <?php foreach ($catalog as $category): foreach ($category['items'] as $item): ?>
                    <option value="<?= h((string) $item['id']) ?>" <?= (int) $rule['required_item_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                    <?php endforeach; endforeach; ?>
                  </select>
                  <input class="form-control" type="number" min="1" name="required_quantity" value="<?= h((string) $rule['required_quantity']) ?>" form="rule-form-<?= (int) $rule['id'] ?>" style="margin-top:0.5rem;">
                </td>
                <td>
                  <textarea class="form-control" name="note" form="rule-form-<?= (int) $rule['id'] ?>" rows="3"><?= h($rule['note']) ?></textarea>
                </td>
                <td>
                  <form method="post" id="rule-form-<?= (int) $rule['id'] ?>" class="stack">
                    <input type="hidden" name="action" value="save_rule">
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                      <span class="material-symbols-outlined">save</span>
                      Update
                    </button>
                  </form>
                  <form method="post" style="margin-top:0.5rem;">
                    <input type="hidden" name="action" value="delete_rule">
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE RULE" data-confirm="Type REMOVE RULE to delete this rule.">
                      <span class="material-symbols-outlined">delete</span>
                      Remove
                    </button>
                  </form>
                </td>
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
