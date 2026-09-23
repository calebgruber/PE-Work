<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

if (!schema_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

$showId = isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0;
$show = find_show($showId);
if (!$show) {
    http_response_code(404);
    echo 'Show not found.';
    exit;
}

$revision = !empty($_GET['revision_id']) ? find_revision((int) $_GET['revision_id']) : find_latest_revision($showId);
if (!$revision) {
    http_response_code(404);
    echo 'Revision not found.';
    exit;
}

$type = $_GET['type'] ?? 'order';
$layout = export_layout_settings();
$catalog = catalog_for_revision((int) $revision['id']);
$showImage = $layout['layout.show_image'] === '1' && !empty($show['show_image_url']);

function export_rows(array $catalog, string $type): array
{
    $rows = [];
    foreach ($catalog as $category) {
        foreach ($category['items'] as $item) {
            $line = $item['line'];
            if ($type === 'spares' && (int) $line['spare_quantity'] <= 0) {
                continue;
            }
            if ($type === 'returns' && ($line['action'] ?? '') !== 'return') {
                continue;
            }
            $rows[] = ['category' => $category['name'], 'item' => $item, 'line' => $line];
        }
    }
    return $rows;
}

$rows = export_rows($catalog, $type);
$titleMap = [
    'order' => 'Shop Order',
    'spares' => 'Spare List',
    'returns' => 'Return Checklist',
];
$pageTitle = $titleMap[$type] ?? $titleMap['order'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($show['show_name']) ?> <?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
</head>
<body>
  <div class="print-shell">
    <div class="print-toolbar">
      <a class="btn btn-ghost" href="<?= h(url_for('show?show_id=' . $showId . '&revision_id=' . (int) $revision['id'])) ?>">
        <span class="material-symbols-outlined">arrow_back</span>
        Back
      </a>
      <a class="btn btn-ghost" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $revision['id'] . '&type=order')) ?>">Order</a>
      <a class="btn btn-ghost" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $revision['id'] . '&type=spares')) ?>">Spares</a>
      <a class="btn btn-ghost" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $revision['id'] . '&type=returns')) ?>">Returns</a>
      <button type="button" class="btn btn-primary" data-print-page>
        <span class="material-symbols-outlined">picture_as_pdf</span>
        Print / Save PDF
      </button>
    </div>

    <div class="print-header">
      <div>
        <div class="muted"><?= h($layout['layout.header_text']) ?></div>
        <h1 style="margin:0.25rem 0;"><?= h($show['show_name']) ?> · <?= h($pageTitle) ?></h1>
        <div class="inline-list">
          <span><strong>Revision:</strong> <?= h($revision['revision_code']) ?> (<?= h($revision['revision_date']) ?>)</span>
          <span><strong>Theatre:</strong> <?= h($show['theatre_name']) ?></span>
          <span><strong>Shop:</strong> <?= h($show['shop_name']) ?></span>
          <?php if (!empty($show['pull_date']) || !empty($show['return_date'])): ?>
          <span><strong>Pull / Return:</strong> <?= h($show['pull_date'] ?: '—') ?> → <?= h($show['return_date'] ?: '—') ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($showImage): ?>
      <img src="<?= h($show['show_image_url']) ?>" alt="<?= h($show['show_name']) ?> image">
      <?php endif; ?>
    </div>

    <?php if ($layout['layout.show_revision_summary'] === '1'): ?>
    <div class="print-grid">
      <div class="summary-block"><strong>LD</strong><?= h($show['ld_name']) ?><br><?= h($show['ld_email']) ?><br><?= h($show['ld_phone']) ?></div>
      <div class="summary-block"><strong>Assistant LD</strong><?= h($show['assistant_ld_name']) ?><br><?= h($show['assistant_ld_email']) ?><br><?= h($show['assistant_ld_phone']) ?></div>
      <div class="summary-block"><strong>Production Electrician</strong><?= h($show['production_electrician_name']) ?><br><?= h($show['production_electrician_email']) ?><br><?= h($show['production_electrician_phone']) ?></div>
      <div class="summary-block"><strong>Shop Team</strong><?= h($show['shop_manager_name']) ?><br><?= h($show['assistant_shop_manager_name']) ?><br><?= h($show['shop_manager_phone']) ?></div>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Category</th>
            <th>Item</th>
            <?php if ($type !== 'returns'): ?><th>Rent</th><?php endif; ?>
            <th>Spares</th>
            <?php if ($type === 'returns'): ?><th>Return Qty</th><?php else: ?><th>Total</th><?php endif; ?>
            <th>Action</th>
            <th>Item Pull</th>
            <th>Item Return</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= h($row['category']) ?></td>
            <td><?= h($row['item']['name']) ?></td>
            <?php if ($type !== 'returns'): ?><td><?= h((string) $row['line']['rent_quantity']) ?></td><?php endif; ?>
            <td><?= h((string) $row['line']['spare_quantity']) ?></td>
            <?php if ($type === 'returns'): ?><td>__________</td><?php else: ?><td><?= h((string) $row['line']['total_quantity']) ?></td><?php endif; ?>
            <td><?= action_badge((string) ($row['line']['action'] ?? '')) ?></td>
            <td><?= h($row['line']['pickup_date'] ?: ($show['pull_date'] ?: '—')) ?></td>
            <td><?= h($row['line']['return_date'] ?: ($show['return_date'] ?: '—')) ?></td>
            <td><?= h($row['line']['line_note'] ?: ($row['item']['default_note'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:1rem;" class="muted">
      <?= h($layout['layout.footer_text']) ?>
      <?php if ($layout['layout.show_page_numbers'] === '1'): ?> · Page 1 of 1<?php endif; ?>
    </div>
  </div>

  <script src="<?= h(asset_url('shared/assets/pe-work.js')) ?>"></script>
</body>
</html>
