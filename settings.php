<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

$tab = $_GET['tab'] ?? 'inventory';
if (!in_array($tab, ['inventory', 'resources', 'rules', 'layout', 'migrations'], true)) {
    $tab = 'inventory';
}
$resourceFolderParam = $_GET['folder'] ?? null;
$selectedResourceFolderId = null;
if (!is_array($resourceFolderParam) && $resourceFolderParam !== null && ctype_digit((string) $resourceFolderParam) && (int) $resourceFolderParam > 0) {
    $selectedResourceFolderId = (int) $resourceFolderParam;
}
$migrationLogs = $_SESSION['migration_logs'] ?? [];
unset($_SESSION['migration_logs']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Your session expired. Refresh the page and try again.');
        header('Location: ' . url_for('settings?tab=' . $tab));
        exit;
    }

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
        if ($action === 'save_inventory_item') {
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

        if ($action === 'add_item_spacer') {
            $result = create_spacer_near_inventory_item((int) ($_POST['item_id'] ?? 0), (string) ($_POST['position'] ?? ''));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'clear_inventory') {
            $result = clear_inventory_items();
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=inventory'));
            exit;
        }

        if ($action === 'import_inventory') {
            $upload = $_FILES['inventory_csv'] ?? null;
            $tmpPath = (string) ($upload['tmp_name'] ?? '');
            $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            $pasteCsv = trim((string) ($_POST['inventory_csv_text'] ?? ''));
            if ($pasteCsv !== '') {
                $result = import_inventory_csv_text($pasteCsv);
            } elseif ($uploadError !== UPLOAD_ERR_OK || $tmpPath === '' || !is_trusted_uploaded_file($tmpPath)) {
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
            $folderId = isset($_POST['folder_id']) && ctype_digit((string) $_POST['folder_id']) ? (int) $_POST['folder_id'] : null;
            $result = store_resource_upload($_FILES['resource_pdf'] ?? [], $_POST['resource_title'] ?? '', $folderId);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=resources'));
            exit;
        }

        if ($action === 'create_resource_folder') {
            $parentFolderId = isset($_POST['parent_folder_id']) && ctype_digit((string) $_POST['parent_folder_id']) && (int) $_POST['parent_folder_id'] > 0
                ? (int) $_POST['parent_folder_id']
                : null;
            $result = create_resource_folder($_POST['folder_name'] ?? '', $parentFolderId);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=resources'));
            exit;
        }

        if ($action === 'delete_resource_folder') {
            $result = delete_resource_folder((int) ($_POST['folder_id'] ?? 0));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            header('Location: ' . url_for('settings?tab=resources'));
            exit;
        }

        if ($action === 'move_resource_folder') {
            $folderId = isset($_POST['folder_id']) && ctype_digit((string) $_POST['folder_id']) ? (int) $_POST['folder_id'] : null;
            $result = move_resource_to_folder((int) ($_POST['resource_id'] ?? 0), $folderId);
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

        flash('warning', 'That settings action is not available right now.');
        header('Location: ' . url_for('settings?tab=' . $tab));
        exit;
    } else {
        flash('warning', 'Run migrations before changing settings.');
        header('Location: ' . url_for('settings?tab=migrations'));
        exit;
    }
}

$catalog = schema_ready() ? fetch_inventory_catalog() : [];
$categories = schema_ready() ? fetch_categories() : [];
$rules = schema_ready() ? fetch_rules() : [];
$layout = export_layout_settings();
$applied = applied_migrations();
$migrationFiles = migration_files();
$resourceFolders = schema_ready() ? fetch_resource_folders() : [];
$resources = schema_ready() ? fetch_resources($selectedResourceFolderId) : [];
$ruleCatalog = [];
foreach ($catalog as $category) {
    $filteredItems = array_values(array_filter($category['items'], static fn (array $item): bool => empty($item['is_spacer'])));
    if ($filteredItems) {
        $category['items'] = $filteredItems;
        $ruleCatalog[] = $category;
    }
}

ui_head('Settings', '', APP_NAME, 'settings');
ui_sidebar(APP_NAME, 'settings', nav_items($tab === 'resources' ? 'resources' : 'settings'));
ui_page_header('System Settings', 'Manage inventory, rules, layout defaults, and database migrations.', '');
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <nav class="tabs" aria-label="Settings sections">
    <a class="tab<?= $tab === 'inventory' ? ' active' : '' ?>"<?= $tab === 'inventory' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('settings?tab=inventory')) ?>"><span class="material-symbols-outlined">inventory_2</span>Inventory</a>
    <a class="tab<?= $tab === 'resources' ? ' active' : '' ?>"<?= $tab === 'resources' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('settings?tab=resources')) ?>"><span class="material-symbols-outlined">folder</span>Resources</a>
    <a class="tab<?= $tab === 'rules' ? ' active' : '' ?>"<?= $tab === 'rules' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('settings?tab=rules')) ?>"><span class="material-symbols-outlined">rule</span>Rules</a>
    <a class="tab<?= $tab === 'layout' ? ' active' : '' ?>"<?= $tab === 'layout' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('settings?tab=layout')) ?>"><span class="material-symbols-outlined">dashboard_customize</span>Layout</a>
    <a class="tab<?= $tab === 'migrations' ? ' active' : '' ?>"<?= $tab === 'migrations' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('settings?tab=migrations')) ?>"><span class="material-symbols-outlined">database</span>Migrations</a>
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
          <form method="post" class="inventory-bulk-actions">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="clear_inventory">
            <button type="submit" class="btn btn-danger" data-confirm-code="CLEAR INVENTORY" data-confirm="Type CLEAR INVENTORY to permanently remove every inventory item from the database.">
              <span class="material-symbols-outlined">delete_sweep</span>
              Clear All Inventory
            </button>
          </form>
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
                  <section class="inventory-item-accordion">
                    <button type="button" class="inventory-accordion-trigger inventory-item-trigger" data-accordion-trigger aria-expanded="false">
                      <span class="inventory-item-trigger-copy">
                        <strong><?= !empty($item['is_spacer']) ? '&nbsp;' : h($item['name']) ?></strong>
                        <span class="muted">
                          <?= !empty($item['is_spacer']) ? '&nbsp;' : ('Shop has ' . h((string) $item['shop_quantity']) . (!empty($item['unit']) ? ' ' . h($item['unit']) : '')) ?>
                        </span>
                      </span>
                      <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <div class="inventory-accordion-panel hidden" data-accordion-panel>
                      <form method="post" class="inventory-item-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="save_inventory_item">
                        <div class="inventory-item-header">
                          <div>
                            <h3><?= !empty($item['is_spacer']) ? '&nbsp;' : h($item['name']) ?></h3>
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
                            <label>Order</label>
                            <input class="form-control compact-input" type="number" min="0" name="items[<?= h((string) $item['id']) ?>][sort_order]" value="<?= h((string) ($item['sort_order'] ?? 0)) ?>">
                          </div>
                          <div class="form-group">
                            <label>Unit</label>
                            <input class="form-control compact-input" name="items[<?= h((string) $item['id']) ?>][unit]" value="<?= h($item['unit'] ?? '') ?>">
                          </div>
                        </div>
                        <div class="form-group">
                          <input type="hidden" name="items[<?= h((string) $item['id']) ?>][is_spacer]" value="0">
                          <label class="check-label">
                            <input type="checkbox" name="items[<?= h((string) $item['id']) ?>][is_spacer]" value="1" <?= !empty($item['is_spacer']) ? 'checked' : '' ?>>
                            Spacer row
                          </label>
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
                      <div class="inventory-item-form-actions">
                        <form method="post">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="add_item_spacer">
                          <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                          <input type="hidden" name="position" value="above">
                          <button type="submit" class="btn btn-ghost btn-sm">
                            <span class="material-symbols-outlined">vertical_align_top</span>
                            Spacer Above
                          </button>
                        </form>
                        <form method="post">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="add_item_spacer">
                          <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                          <input type="hidden" name="position" value="below">
                          <button type="submit" class="btn btn-ghost btn-sm">
                            <span class="material-symbols-outlined">vertical_align_bottom</span>
                            Spacer Below
                          </button>
                        </form>
                      </div>
                      <form method="post" class="inventory-item-delete">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete_inventory_item">
                        <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE ITEM" data-confirm="Type REMOVE ITEM to permanently remove this inventory item.">
                          <span class="material-symbols-outlined">delete</span>
                          Remove
                        </button>
                      </form>
                    </div>
                  </section>
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
              <?= csrf_input() ?>
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
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="category_id" value="<?= h((string) $category['id']) ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE CATEGORY" data-confirm="Type REMOVE CATEGORY to permanently delete this category.">
                <span class="material-symbols-outlined">delete</span>
                Remove Category
              </button>
            </form>
            <?php endforeach; ?>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

          <form method="post" class="stack">
            <?= csrf_input() ?>
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
            <?= csrf_input() ?>
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
                <label for="sort_order">Order</label>
                <input class="form-control" type="number" min="0" id="sort_order" name="sort_order" placeholder="Auto">
              </div>
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
              <label class="check-label">
                <input type="checkbox" name="is_spacer" value="1">
                Create as spacer row
              </label>
              <div class="helper-text">Spacer rows show up in the editor/export as visual separators.</div>
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
            <?= csrf_input() ?>
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
      <div class="resource-manager">
        <aside class="resource-sidebar">
          <div class="summary-block">
            <strong>Folders</strong>
            <div class="resource-folder-list">
              <a class="tab<?= $selectedResourceFolderId === null ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=resources')) ?>">All PDFs</a>
              <div class="resource-folder-row">
                <a class="tab<?= $selectedResourceFolderId === null ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=resources')) ?>">
                  <span class="material-symbols-outlined">home_storage</span>
                  All Resources
                </a>
              </div>
              <?php foreach ($resourceFolders as $folder): ?>
              <?php $folderDepth = max(0, (int) ($folder['depth'] ?? 0)); ?>
              <div class="resource-folder-row">
                <a class="tab<?= $selectedResourceFolderId === (int) $folder['id'] ? ' active' : '' ?>" href="<?= h(url_for('settings?tab=resources&folder=' . (int) $folder['id'])) ?>" style="padding-left: calc(0.85rem + <?= h((string) $folderDepth) ?> * 1rem);">
                  <span class="material-symbols-outlined"><?= $folderDepth > 0 ? 'folder_open' : 'folder' ?></span>
                  <?= h($folder['name']) ?>
                  <span class="muted">(<?= h((string) $folder['resource_count']) ?>)</span>
                </a>
                <form method="post">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="delete_resource_folder">
                  <input type="hidden" name="folder_id" value="<?= h((string) $folder['id']) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Delete this empty folder?">
                    <span class="material-symbols-outlined">delete</span>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
            <form method="post" class="stack" style="margin-top:1rem;">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="create_resource_folder">
              <div class="form-group">
                <label for="folder_name">New Folder</label>
                <input class="form-control" id="folder_name" name="folder_name" placeholder="Show References">
              </div>
              <div class="form-group">
                <label for="parent_folder_id">Inside Folder</label>
                <select class="form-control" id="parent_folder_id" name="parent_folder_id">
                  <option value="">Top Level</option>
                  <?php foreach ($resourceFolders as $folder): ?>
                  <option value="<?= h((string) $folder['id']) ?>" <?= $selectedResourceFolderId === (int) $folder['id'] ? 'selected' : '' ?>><?= h($folder['full_path'] ?? $folder['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-ghost">
                  <span class="material-symbols-outlined">create_new_folder</span>
                  Create Folder
                </button>
              </div>
            </form>
          </div>
        </aside>
        <div class="resource-main">
          <div class="card-grid card-grid-2">
            <div class="summary-block">
              <strong>Upload PDF Resource</strong>
              <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:1rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="upload_resource">
                <div class="form-group">
                  <label for="resource_title">Title</label>
                  <input class="form-control" id="resource_title" name="resource_title" placeholder="Vectorworks guide">
                </div>
                <div class="form-group">
                  <label for="resource_folder_id">Folder</label>
                  <select class="form-control" id="resource_folder_id" name="folder_id">
                    <option value="">No Folder</option>
                    <?php foreach ($resourceFolders as $folder): ?>
                    <option value="<?= h((string) $folder['id']) ?>" <?= $selectedResourceFolderId === (int) $folder['id'] ? 'selected' : '' ?>><?= h($folder['full_path'] ?? $folder['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
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
              <strong>Library View</strong>
              <div class="stack" style="margin-top:1rem;">
                <div class="muted">Use folders and subfolders to separate shop paperwork, diagrams, manuals, and reference PDFs.</div>
                <div class="muted">Open, download, move, and preview PDFs from one place without leaving the app.</div>
              </div>
            </div>
          </div>

          <?php if ($resources): ?>
          <div class="resource-grid">
            <?php foreach ($resources as $resource): ?>
            <?php $resourceUrl = url_for('resource_file?id=' . (int) $resource['id'] . '&token=' . rawurlencode(resource_access_token($resource))); ?>
            <article class="resource-card">
              <div class="resource-card-header">
                <div>
                  <h3><?= h($resource['title']) ?></h3>
                  <div class="muted"><?= h($resource['original_name']) ?></div>
                  <div class="helper-text"><?= h($resource['folder_path'] ?? $resource['folder_name'] ?? 'No Folder') ?></div>
                </div>
                <form method="post">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="delete_resource">
                  <input type="hidden" name="resource_id" value="<?= h((string) $resource['id']) ?>">
                  <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="REMOVE RESOURCE" data-confirm="Type REMOVE RESOURCE to delete this PDF.">
                    <span class="material-symbols-outlined">delete</span>
                    Remove
                  </button>
                </form>
              </div>
              <div class="resource-actions">
                <a class="btn btn-ghost btn-sm" href="<?= h($resourceUrl) ?>" target="_blank" rel="noopener">
                  <span class="material-symbols-outlined">open_in_new</span>
                  Open PDF
                </a>
                <a class="btn btn-ghost btn-sm" href="<?= h($resourceUrl . '&download=1') ?>">
                  <span class="material-symbols-outlined">download</span>
                  Download
                </a>
              </div>
              <form method="post" class="stack" style="margin-top:0.75rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="move_resource_folder">
                <input type="hidden" name="resource_id" value="<?= h((string) $resource['id']) ?>">
                <div class="form-group">
                  <label for="resource-folder-<?= (int) $resource['id'] ?>">Folder</label>
                  <select class="form-control" id="resource-folder-<?= (int) $resource['id'] ?>" name="folder_id">
                    <option value="">No Folder</option>
                    <?php foreach ($resourceFolders as $folder): ?>
                    <option value="<?= h((string) $folder['id']) ?>" <?= (int) ($resource['folder_id'] ?? 0) === (int) $folder['id'] ? 'selected' : '' ?>><?= h($folder['full_path'] ?? $folder['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-actions">
                  <button type="submit" class="btn btn-ghost btn-sm">
                    <span class="material-symbols-outlined">drive_file_move</span>
                    Move
                  </button>
                </div>
              </form>
              <iframe class="resource-frame" src="<?= h($resourceUrl) ?>" title="<?= h($resource['title']) ?>">
                PDF preview unavailable. Use the Open PDF or Download buttons above.
              </iframe>
            </article>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="empty-state">
            <span class="material-symbols-outlined">folder</span>
            <h3>No resources here yet</h3>
            <p>Upload PDFs and organize them into folders to build out the resource library.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>
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
                  <select class="form-control" aria-label="Trigger item for rule <?= (int) $rule['id'] ?>" name="trigger_item_id" form="rule-form-<?= (int) $rule['id'] ?>">
                    <option value="">Choose an item</option>
                    <?php foreach ($ruleCatalog as $category): foreach ($category['items'] as $item): ?>
                    <option value="<?= h((string) $item['id']) ?>" <?= (int) $rule['trigger_item_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                    <?php endforeach; endforeach; ?>
                  </select>
                  <input class="form-control" aria-label="Trigger quantity for rule <?= (int) $rule['id'] ?>" type="number" min="1" name="trigger_quantity" value="<?= h((string) $rule['trigger_quantity']) ?>" form="rule-form-<?= (int) $rule['id'] ?>" style="margin-top:0.5rem;">
                </td>
                <td>
                  <select class="form-control" aria-label="Suggested item for rule <?= (int) $rule['id'] ?>" name="required_item_id" form="rule-form-<?= (int) $rule['id'] ?>">
                    <option value="">Choose an item</option>
                    <?php foreach ($ruleCatalog as $category): foreach ($category['items'] as $item): ?>
                    <option value="<?= h((string) $item['id']) ?>" <?= (int) $rule['required_item_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($category['name']) ?> · <?= h($item['name']) ?></option>
                    <?php endforeach; endforeach; ?>
                  </select>
                  <input class="form-control" aria-label="Suggested quantity for rule <?= (int) $rule['id'] ?>" type="number" min="1" name="required_quantity" value="<?= h((string) $rule['required_quantity']) ?>" form="rule-form-<?= (int) $rule['id'] ?>" style="margin-top:0.5rem;">
                </td>
                <td>
                  <textarea class="form-control" aria-label="Note for rule <?= (int) $rule['id'] ?>" name="note" form="rule-form-<?= (int) $rule['id'] ?>" rows="3"><?= h($rule['note']) ?></textarea>
                </td>
                <td>
                  <form method="post" id="rule-form-<?= (int) $rule['id'] ?>" class="stack">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="save_rule">
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                      <span class="material-symbols-outlined">save</span>
                      Update
                    </button>
                  </form>
                  <form method="post" style="margin-top:0.5rem;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_rule">
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove this rule?">
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
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="save_rule">
          <div class="card-grid">
            <div class="form-group">
              <label for="trigger_item_id">Trigger Item</label>
              <select class="form-control" id="trigger_item_id" name="trigger_item_id">
                <option value="">Choose an item</option>
                <?php foreach ($ruleCatalog as $category): foreach ($category['items'] as $item): ?>
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
                <?php foreach ($ruleCatalog as $category): foreach ($category['items'] as $item): ?>
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
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_layout">
        <div class="card-grid">
          <div class="form-group">
            <label for="header_text">Header Text</label>
            <input class="form-control" id="header_text" name="header_text" value="<?= h($layout['layout.header_text']) ?>">
          </div>
          <div class="form-group">
            <label for="organization_text">Top Right Header Text</label>
            <input class="form-control" id="organization_text" name="organization_text" value="<?= h($layout['layout.organization_text'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="footer_text">Footer Text</label>
            <input class="form-control" id="footer_text" name="footer_text" value="<?= h($layout['layout.footer_text']) ?>">
          </div>
        </div>
        <div class="form-group" style="margin-top:1rem;">
          <label for="export_notes">Default Important Notes</label>
          <textarea class="form-control" id="export_notes" name="export_notes" rows="8"><?= h($layout['layout.export_notes'] ?? '') ?></textarea>
        </div>
        <div class="pill-row" style="margin-top:1rem;">
          <label class="tab"><input type="checkbox" name="show_image" value="1" <?= $layout['layout.show_image'] === '1' ? 'checked' : '' ?>> Show image on exports</label>
          <label class="tab"><input type="checkbox" name="show_page_numbers" value="1" <?= $layout['layout.show_page_numbers'] === '1' ? 'checked' : '' ?>> Page X of X</label>
          <label class="tab"><input type="checkbox" name="show_revision_summary" value="1" <?= $layout['layout.show_revision_summary'] === '1' ? 'checked' : '' ?>> Revision summary block</label>
        </div>
        <div class="section-label" style="margin-top:1rem;">Equipment Breakdown Layout</div>
        <div class="card-grid">
          <div class="form-group">
            <label for="equipment_table_width">Table Width %</label>
            <input class="form-control" id="equipment_table_width" type="number" min="70" max="100" step="0.1" name="equipment_table_width" value="<?= h($layout['layout.equipment_table_width'] ?? '100') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_row_padding">Row Padding (in)</label>
            <input class="form-control" id="equipment_row_padding" type="number" min="0.008" max="0.04" step="0.001" name="equipment_row_padding" value="<?= h($layout['layout.equipment_row_padding'] ?? '0.016') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_font_size">Font Size (pt)</label>
            <input class="form-control" id="equipment_font_size" type="number" min="6.5" max="10" step="0.01" name="equipment_font_size" value="<?= h($layout['layout.equipment_font_size'] ?? '7.35') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_item">Item Width</label>
            <input class="form-control" id="equipment_col_item" type="number" min="20" max="70" step="0.1" name="equipment_col_item" value="<?= h($layout['layout.equipment_col_item'] ?? '45') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_description">Description Width</label>
            <input class="form-control" id="equipment_col_description" type="number" min="8" max="40" step="0.1" name="equipment_col_description" value="<?= h($layout['layout.equipment_col_description'] ?? '23') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_used">Used Width</label>
            <input class="form-control" id="equipment_col_used" type="number" min="2" max="12" step="0.1" name="equipment_col_used" value="<?= h($layout['layout.equipment_col_used'] ?? '5') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_spare">Spare Width</label>
            <input class="form-control" id="equipment_col_spare" type="number" min="2" max="12" step="0.1" name="equipment_col_spare" value="<?= h($layout['layout.equipment_col_spare'] ?? '5') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_total">Total Width</label>
            <input class="form-control" id="equipment_col_total" type="number" min="2" max="14" step="0.1" name="equipment_col_total" value="<?= h($layout['layout.equipment_col_total'] ?? '6') ?>">
          </div>
          <div class="form-group">
            <label for="equipment_col_notes">Notes Width</label>
            <input class="form-control" id="equipment_col_notes" type="number" min="4" max="30" step="0.1" name="equipment_col_notes" value="<?= h($layout['layout.equipment_col_notes'] ?? '12') ?>">
          </div>
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
        <?= csrf_input() ?>
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
