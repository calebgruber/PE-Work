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
if (!$revision || (int) $revision['show_id'] !== $showId) {
    http_response_code(404);
    echo 'Revision not found.';
    exit;
}

$type = $_GET['type'] ?? 'order';
$layout = export_layout_settings();
$catalog = catalog_for_revision((int) $revision['id']);
$showImageUrl = '';
if ($layout['layout.show_image'] === '1' && !empty($show['show_image_url'])) {
    $relativeImagePath = ltrim((string) $show['show_image_url'], '/');
    $resolvedImagePath = realpath(__DIR__ . '/' . $relativeImagePath);
    $repoRoot = realpath(__DIR__);
    if ($resolvedImagePath && $repoRoot && str_starts_with($resolvedImagePath, $repoRoot . DIRECTORY_SEPARATOR) && is_file($resolvedImagePath)) {
        $showImageUrl = asset_url($relativeImagePath);
    }
}
$showImage = $showImageUrl !== '';

function export_rows(array $catalog, string $type): array
{
    $rows = [];
    foreach ($catalog as $category) {
        $categoryItems = $category['items'];
        $shouldInclude = [];
        foreach ($categoryItems as $index => $item) {
            $line = $item['line'];
            if (!empty($item['is_spacer'])) {
                $shouldInclude[$index] = false;
                continue;
            }
            $shouldInclude[$index] = match ($type) {
                'order' => (int) $line['total_quantity'] > 0,
                'spares' => (int) $line['spare_quantity'] > 0,
                'returns' => ($line['action'] ?? '') === 'return' && (int) ($line['total_quantity'] ?? 0) > 0,
                default => (int) $line['total_quantity'] > 0,
            };
        }

        foreach ($categoryItems as $index => $item) {
            $line = $item['line'];
            if (!empty($item['is_spacer'])) {
                $hasPreviousVisible = false;
                for ($previous = $index - 1; $previous >= 0; $previous--) {
                    if (!empty($shouldInclude[$previous])) {
                        $hasPreviousVisible = true;
                        break;
                    }
                }
                $hasLaterVisible = false;
                for ($next = $index + 1, $count = count($categoryItems); $next < $count; $next++) {
                    if (!empty($shouldInclude[$next])) {
                        $hasLaterVisible = true;
                        break;
                    }
                }
                if ($hasPreviousVisible || $hasLaterVisible) {
                    $rows[] = ['category' => $category['name'], 'item' => $item, 'line' => $line, 'is_spacer' => true];
                }
                continue;
            }

            if (empty($shouldInclude[$index])) {
                continue;
            }

            $rows[] = ['category' => $category['name'], 'item' => $item, 'line' => $line, 'is_spacer' => false];
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
$backTab = !empty($revision['is_initial']) ? 'orders' : 'revisions';
$editorUrl = url_for('show?show_id=' . $showId . '&tab=' . $backTab . '&mode=edit&revision_id=' . (int) $revision['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($show['show_name']) ?> <?= h($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('shared/assets/pe-work.css')) ?>">
</head>
<body>
  <div class="print-shell">
    <div class="print-toolbar">
      <a class="btn btn-ghost" href="<?= h($editorUrl) ?>">
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
      <img src="<?= h($showImageUrl) ?>" alt="<?= h($show['show_name']) ?> image">
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
            <?php if ($type !== 'returns'): ?><th>Spares</th><?php endif; ?>
            <?php if ($type === 'returns'): ?><th>Return Qty</th><?php else: ?><th>Total</th><?php endif; ?>
            <th>Action</th>
            <th>Item Pull</th>
            <th>Item Return</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <?php if (!empty($row['is_spacer'])): ?>
          <tr class="print-spacer-row">
            <td><?= h($row['category']) ?></td>
            <td colspan="<?= $type === 'returns' ? '6' : '8' ?>">
              <strong><?= h($row['item']['name']) ?></strong>
              <?php if (!empty($row['item']['description'])): ?>
                <span class="muted"> · <?= h($row['item']['description']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php continue; endif; ?>
          <tr class="<?= h(export_row_action_class($revision, $row['item'], $row['line'])) ?>">
            <td><?= h($row['category']) ?></td>
            <td><?= h($row['item']['name']) ?></td>
            <?php if ($type !== 'returns'): ?><td><?= h((string) $row['line']['rent_quantity']) ?></td><?php endif; ?>
            <?php if ($type !== 'returns'): ?><td><?= h((string) $row['line']['spare_quantity']) ?></td><?php endif; ?>
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
