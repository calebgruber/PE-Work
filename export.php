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

function export_type_labels(string $type): array
{
    return match ($type) {
        'spares' => ['title' => 'Spare List', 'equipment_heading' => 'SPARE BREAKDOWN'],
        'returns' => ['title' => 'Return Checklist', 'equipment_heading' => 'RETURN BREAKDOWN'],
        default => ['title' => 'Electrical Equipment List', 'equipment_heading' => 'EQUIPMENT BREAKDOWN'],
    };
}

function export_previous_revision(array $revision): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM show_revisions WHERE show_id = ? AND revision_index < ? ORDER BY revision_index DESC LIMIT 1'
    );
    $stmt->execute([(int) $revision['show_id'], (int) $revision['revision_index']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function export_revision_line_map(int $revisionId): array
{
    $stmt = db()->prepare('SELECT * FROM revision_items WHERE revision_id = ?');
    $stmt->execute([$revisionId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['inventory_item_id']] = $row;
    }
    return $map;
}

function export_equipment_rows(array $catalog, string $type): array
{
    $rows = [];
    foreach ($catalog as $category) {
        $visibleItems = [];
        foreach ($category['items'] as $item) {
            if (!empty($item['is_spacer'])) {
                continue;
            }

            $line = $item['line'];
            $include = match ($type) {
                'spares' => (int) ($line['spare_quantity'] ?? 0) > 0,
                'returns' => (int) ($line['total_quantity'] ?? 0) > 0 || !empty($line['action']),
                default => (int) ($line['total_quantity'] ?? 0) > 0,
            };
            if ($include) {
                $visibleItems[] = $item;
            }
        }

        if (!$visibleItems) {
            continue;
        }

        foreach ($visibleItems as $item) {
            $rows[] = [
                'category' => $category['name'],
                'item' => $item,
                'line' => $item['line'],
            ];
        }
    }

    return $rows;
}

function export_summary_rows(array $catalog, array $revision, string $type): array
{
    if (!empty($revision['is_initial'])) {
        return [];
    }

    $previous = export_previous_revision($revision);
    $previousMap = $previous ? export_revision_line_map((int) $previous['id']) : [];
    $rows = [];

    foreach ($catalog as $category) {
        foreach ($category['items'] as $item) {
            if (!empty($item['is_spacer'])) {
                continue;
            }

            $line = $item['line'];
            $previousLine = $previousMap[(int) $item['id']] ?? [
                'rent_quantity' => 0,
                'spare_quantity' => 0,
                'total_quantity' => 0,
                'action' => '',
                'line_note' => '',
                'pickup_date' => null,
                'return_date' => null,
            ];

            $changed = (int) ($line['rent_quantity'] ?? 0) !== (int) ($previousLine['rent_quantity'] ?? 0)
                || (int) ($line['spare_quantity'] ?? 0) !== (int) ($previousLine['spare_quantity'] ?? 0)
                || (int) ($line['total_quantity'] ?? 0) !== (int) ($previousLine['total_quantity'] ?? 0)
                || (string) ($line['action'] ?? '') !== (string) ($previousLine['action'] ?? '')
                || trim((string) ($line['line_note'] ?? '')) !== trim((string) ($previousLine['line_note'] ?? ''))
                || (string) ($line['pickup_date'] ?? '') !== (string) ($previousLine['pickup_date'] ?? '')
                || (string) ($line['return_date'] ?? '') !== (string) ($previousLine['return_date'] ?? '');

            if (!$changed && empty($line['action'])) {
                continue;
            }

            $currentRent = (int) ($line['rent_quantity'] ?? 0);
            $currentSpares = (int) ($line['spare_quantity'] ?? 0);
            $currentTotal = (int) ($line['total_quantity'] ?? 0);
            $previousRent = (int) ($previousLine['rent_quantity'] ?? 0);
            $previousSpares = (int) ($previousLine['spare_quantity'] ?? 0);
            $previousTotal = (int) ($previousLine['total_quantity'] ?? 0);
            $action = (string) ($line['action'] ?? '');

            $quantity = match ($type) {
                'spares' => max(0, abs($currentSpares - $previousSpares)),
                default => match ($action) {
                    'add' => max(0, $currentRent - $previousRent),
                    'return' => max(0, $previousRent - $currentRent),
                    'exchange' => max(1, abs($currentRent - $previousRent) ?: abs($currentTotal - $previousTotal) ?: $currentTotal ?: $previousTotal),
                    'note' => max(1, $currentRent ?: $previousRent ?: $currentTotal ?: $previousTotal),
                    default => max(1, abs($currentRent - $previousRent) ?: abs($currentTotal - $previousTotal)),
                },
            };

            if ($quantity === 0 && $type !== 'returns' && $action !== 'note') {
                continue;
            }

            $descriptionBits = [$category['name']];
            if (!empty($item['description'])) {
                $descriptionBits[] = $item['description'];
            }
            $rows[] = [
                'category' => $category['name'],
                'item' => $item,
                'line' => $line,
                'quantity' => $quantity,
                'description' => implode(' · ', array_filter($descriptionBits, static fn ($value) => trim((string) $value) !== '')),
            ];
        }
    }

    return $rows;
}

function export_notes_list(array $layout, array $show): array
{
    $notes = preg_split('/\r\n|\r|\n/', (string) ($layout['layout.export_notes'] ?? '')) ?: [];
    $showNotes = preg_split('/\r\n|\r|\n/', (string) ($show['show_notes'] ?? '')) ?: [];
    $combined = array_merge($notes, $showNotes);
    return array_values(array_filter(array_map('trim', $combined), static fn ($note) => $note !== ''));
}

function export_row_background(array $revision, array $item, array $line): string
{
    return match (export_row_action_class($revision, $item, $line)) {
        'export-row-add' => 'background:#D9EADF;',
        'export-row-return' => 'background:#F8D9D7;',
        'export-row-exchange' => 'background:#F8EDC9;',
        'export-row-note' => 'background:#DCEBFA;',
        default => '',
    };
}

function export_row_style(int $rowIndex, array $revision, array $item, array $line): string
{
    $actionBackground = export_row_background($revision, $item, $line);
    if ($actionBackground !== '') {
        return $actionBackground;
    }

    return $rowIndex % 2 === 0 ? 'background:#CCCCCC;' : 'background:#FFFFFF;';
}

function export_line_delta(array $revision, int $itemId, array $line, string $field): string
{
    if (!empty($revision['is_initial'])) {
        return '';
    }

    $previous = export_previous_revision($revision);
    if (!$previous) {
        return '';
    }

    $previousMap = export_revision_line_map((int) $previous['id']);
    $previousValue = (int) (($previousMap[$itemId][$field] ?? 0));
    $currentValue = (int) ($line[$field] ?? 0);
    $delta = $currentValue - $previousValue;

    if ($delta === 0) {
        return '';
    }

    return '(' . ($delta > 0 ? '+' : '') . (string) $delta . ')';
}

function export_value(string $value, string $fallback = '—'): string
{
    $value = trim($value);
    return $value !== '' ? $value : $fallback;
}

function export_equipment_note(array $item, array $line): string
{
    $parts = [];
    $lineNote = trim((string) ($line['line_note'] ?? ''));
    $defaultNote = trim((string) ($item['default_note'] ?? ''));
    if ($lineNote !== '') {
        $parts[] = $lineNote;
    } elseif ($defaultNote !== '') {
        $parts[] = $defaultNote;
    }
    if (!empty($line['pickup_date'])) {
        $parts[] = 'Pull ' . $line['pickup_date'];
    }
    if (!empty($line['return_date'])) {
        $parts[] = 'Return ' . $line['return_date'];
    }

    return implode(' · ', $parts);
}

$type = $_GET['type'] ?? 'order';
$labels = export_type_labels($type);
$layout = export_layout_settings();
$catalog = catalog_for_revision((int) $revision['id']);
$revisionCode = revision_display_code($revision);
$equipmentRows = export_equipment_rows($catalog, $type);
$summaryRows = (($layout['layout.show_revision_summary'] ?? '1') === '1') ? export_summary_rows($catalog, $revision, $type) : [];
$notes = export_notes_list($layout, $show);
$backTab = !empty($revision['is_initial']) ? 'orders' : 'revisions';
$editorUrl = url_for('show?show_id=' . $showId . '&tab=' . $backTab . '&mode=edit&revision_id=' . (int) $revision['id']);
$renderSummaryPage = !empty($summaryRows);
$pageNumbers = ['cover' => 1];
$nextPageNumber = 2;
if ($renderSummaryPage) {
    $pageNumbers['summary'] = $nextPageNumber++;
}
$pageNumbers['equipment'] = $nextPageNumber;
$totalPages = count($pageNumbers);
$headerOrganization = export_value((string) ($layout['layout.organization_text'] ?? ''), (string) ($show['theatre_name'] ?? ''));
$theatreAddress = trim((string) ($show['theatre_address'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($show['show_name']) ?> <?= h($labels['title']) ?></title>
  <style>
    body {
      margin: 0;
      background: #f3f4f6;
      color: #000;
      font-family: Aptos, Arial, Helvetica, sans-serif;
      font-size: 11pt;
      line-height: 1.2;
    }
    .toolbar {
      max-width: 8.5in;
      margin: 1rem auto 0;
      display: flex;
      justify-content: flex-end;
      gap: 0.75rem;
    }
    .toolbar a,
    .toolbar button {
      appearance: none;
      border: 1px solid #111827;
      background: #fff;
      color: #111827;
      padding: 0.55rem 0.9rem;
      border-radius: 999px;
      font: inherit;
      text-decoration: none;
      cursor: pointer;
    }
    .document {
      width: 8.5in;
      margin: 0 auto 2rem;
      background: #fff;
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
    }
    .page {
      position: relative;
      min-height: 11in;
      padding: 0.55in 0.7in 0.6in;
      box-sizing: border-box;
      page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
    .top-rule {
      border-bottom: 1px solid #000;
      padding-bottom: 0.1in;
      margin-bottom: 0.18in;
    }
    .top-rule p,
    .page p { margin: 0 0 0.08in; }
    .page-header-bar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 0.3in;
    }
    .page-header-title {
      min-width: 0;
    }
    .page-header-title strong {
      display: block;
      font-size: 13pt;
    }
    .page-header-subtitle {
      margin-top: 0.03in;
      font-size: 9pt;
      color: #4b5563;
    }
    .page-header-meta {
      min-width: 1.55in;
      text-align: right;
      font-size: 9pt;
      line-height: 1.35;
    }
    .center-title {
      text-align: center;
      margin-top: 0.32in;
      margin-bottom: 0.3in;
    }
    .center-title .show-name {
      font-size: 20pt;
    }
    .center-title .subtitle {
      margin-top: 0.05in;
    }
    .center-title .revised {
      margin-top: 0.22in;
      text-decoration: underline;
    }
    .contact-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.16in 0.2in;
      margin-bottom: 0.22in;
    }
    .detail-block {
      margin-bottom: 0;
      padding: 0.12in 0.14in;
      border: 1px solid #d1d5db;
      background: #f8fafc;
    }
    .detail-block-wide {
      grid-column: 1 / -1;
    }
    .detail-row {
      display: grid;
      grid-template-columns: 1.3in minmax(0, 1fr);
      gap: 0.08in;
      align-items: start;
    }
    .detail-label {
      display: block;
      font-weight: 600;
    }
    .detail-subline {
      display: block;
      padding-left: calc(1.3in + 0.08in);
      color: #374151;
    }
    .shop-team-list {
      display: grid;
      gap: 0.05in;
      padding-left: calc(1.3in + 0.08in);
      color: #374151;
    }
    .notes-heading {
      margin-top: 0.28in;
      text-decoration: underline;
      font-weight: 600;
    }
    ol.notes-list {
      margin: 0.1in 0 0 0.28in;
      padding: 0;
    }
    ol.notes-list li { margin-bottom: 0.06in; }
    .page-heading {
      text-align: center;
      font-weight: 700;
      margin-top: 0.08in;
      margin-bottom: 0.15in;
      letter-spacing: 0.01em;
    }
    .page-note {
      text-align: center;
      margin-bottom: 0.22in;
    }
    table.word-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin-top: 0.12in;
      font-size: 9pt;
    }
    table.word-table.equipment-table {
      width: 6.95in;
      max-width: 100%;
      margin-left: auto;
      margin-right: auto;
    }
    table.word-table th,
    table.word-table td {
      padding: 0.05in 0.06in;
      vertical-align: middle;
      text-align: left;
      white-space: nowrap;
      overflow: hidden;
    }
    table.word-table thead th {
      border-bottom: 1px solid #666;
      text-decoration: underline;
      font-weight: 700;
    }
    .col-line { width: 0.55in; }
    .col-item { width: 1.95in; }
    .col-description { width: 1.2in; }
    .col-action { width: 1.1in; }
    .col-qty { width: 0.7in; }
    .col-used,
    .col-spare,
    .col-total { width: 0.6in; }
    .col-notes { width: 1.55in; }
    .notes-cell {
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .line-cell { text-align: right; font-weight: 700; }
    .delta {
      margin-left: 0.12rem;
      font-size: 8pt;
      font-weight: 700;
      color: #000;
    }
    .delta-positive { color: #000; }
    .delta-negative { color: #000; }
    .spacer-row td {
      text-align: left;
      font-weight: 700;
      border-top: 1px solid #d1d5db;
      border-bottom: 1px solid #d1d5db;
      background: #f3f4f6;
    }
    @media print {
      * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
    }
    .footer {
      position: absolute;
      left: 0.7in;
      right: 0.7in;
      bottom: 0.28in;
      display: flex;
      justify-content: flex-end;
      font-size: 9pt;
      color: #374151;
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .document { width: auto; margin: 0; box-shadow: none; }
      .page { margin: 0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= h($editorUrl) ?>">Back</a>
    <button type="button" onclick="window.print()">Print / Save PDF</button>
  </div>
  <div class="document">
    <section class="page">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($layout['layout.header_text']) ?></strong>
            <div><?= h($headerOrganization) ?></div>
            <?php if ($theatreAddress !== ''): ?><div class="page-header-subtitle"><?= h($theatreAddress) ?></div><?php endif; ?>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <div><strong>Page</strong> 1 of <?= h((string) $totalPages) ?></div>
          </div>
        </div>
      </div>
      <p><?= h(export_value((string) ($show['shop_name'] ?? ''))) ?><span style="float:right;">Phone: <?= h(export_value((string) ($show['shop_manager_phone'] ?? ''))) ?></span></p>
      <p><?= h(export_value((string) ($show['shop_address'] ?? ''))) ?><span style="float:right;">Email: <?= h(export_value((string) ($show['shop_manager_email'] ?? ''))) ?></span></p>

      <div class="center-title">
        <p class="show-name">&quot;<?= h($show['show_name']) ?>&quot;</p>
        <p class="subtitle"><?= h($labels['title']) ?></p>
        <p class="revised">REVISION <?= h($revisionCode) ?> · <?= !empty($revision['is_initial']) ? 'INITIAL ORDER' : 'REVISED' ?> <?= h($revision['revision_date']) ?></p>
      </div>

      <div class="contact-grid">
        <div class="detail-block">
          <p class="detail-row"><span class="detail-label">Designer</span><span><?= h(export_value((string) ($show['ld_name'] ?? ''))) ?></span></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['ld_email'] ?? ''))) ?></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['ld_phone'] ?? ''))) ?></p>
        </div>
        <div class="detail-block">
          <p class="detail-row"><span class="detail-label">Assistant Designer</span><span><?= h(export_value((string) ($show['assistant_ld_name'] ?? ''))) ?></span></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['assistant_ld_email'] ?? ''))) ?></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['assistant_ld_phone'] ?? ''))) ?></p>
        </div>
        <div class="detail-block">
          <p class="detail-row"><span class="detail-label">Production Electrician</span><span><?= h(export_value((string) ($show['production_electrician_name'] ?? ''))) ?></span></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['production_electrician_email'] ?? ''))) ?></p>
          <p class="detail-subline"><?= h(export_value((string) ($show['production_electrician_phone'] ?? ''))) ?></p>
        </div>
        <div class="detail-block">
          <p class="detail-row"><span class="detail-label">Schedule</span><span>Load-In <?= h(export_value((string) ($show['pull_date'] ?? ''))) ?></span></p>
          <p class="detail-subline">Opening <?= h(export_value((string) ($show['opening_date'] ?? ''))) ?></p>
          <p class="detail-subline">Strike <?= h(export_value((string) ($show['strike_date'] ?? ''))) ?></p>
        </div>
        <div class="detail-block detail-block-wide">
          <p class="detail-row"><span class="detail-label">Shop Team</span><span><?= h(export_value((string) ($show['shop_name'] ?? ''))) ?></span></p>
          <div class="shop-team-list">
            <div><strong>Manager:</strong> <?= h(export_value((string) ($show['shop_manager_name'] ?? ''))) ?> · <?= h(export_value((string) ($show['shop_manager_email'] ?? ''))) ?> · <?= h(export_value((string) ($show['shop_manager_phone'] ?? ''))) ?></div>
            <div><strong>Assistant:</strong> <?= h(export_value((string) ($show['assistant_shop_manager_name'] ?? ''))) ?> · <?= h(export_value((string) ($show['assistant_shop_manager_email'] ?? ''))) ?> · <?= h(export_value((string) ($show['assistant_shop_manager_phone'] ?? ''))) ?></div>
          </div>
        </div>
      </div>

      <p class="notes-heading">IMPORTANT NOTES:</p>
      <ol class="notes-list">
        <?php foreach ($notes as $note): ?>
        <li><?= h($note) ?></li>
        <?php endforeach; ?>
      </ol>

      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>

    <?php if ($renderSummaryPage): ?>
    <section class="page">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle">Electrical Equipment List</div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <div><strong>Page</strong> <?= h((string) $pageNumbers['summary']) ?> of <?= h((string) $totalPages) ?></div>
          </div>
        </div>
      </div>
      <p class="page-heading">REVISION SUMMARY</p>
      <p class="page-note">NOTE: Not everything is included here; see full revision for complete accessories, etc.</p>
      <table class="word-table equipment-table">
        <thead>
          <tr>
            <th class="col-line">LINE</th>
            <th class="col-item">ITEM</th>
            <th class="col-description">DESCRIPTION</th>
            <th class="col-action">ACTION</th>
            <th class="col-qty">QTY.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summaryRows as $index => $row): ?>
          <tr style="<?= h(export_row_style($index, $revision, $row['item'], $row['line'])) ?>">
            <td class="line-cell"><?= h((string) ($index + 1)) ?></td>
            <td><?= h($row['item']['name']) ?></td>
            <td><?= h($row['description']) ?></td>
            <td><?= h(strtoupper((string) ($row['line']['action'] ?: 'change'))) ?></td>
            <td><?= h((string) $row['quantity']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>
    <?php endif; ?>

    <section class="page">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle">Electrical Equipment List</div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <div><strong>Page</strong> <?= h((string) $pageNumbers['equipment']) ?> of <?= h((string) $totalPages) ?></div>
          </div>
        </div>
      </div>
      <p class="page-heading"><?= h($labels['equipment_heading']) ?></p>
      <table class="word-table">
        <thead>
          <tr>
            <th class="col-line">LINE</th>
            <th class="col-item">ITEM</th>
            <th class="col-description">DESCRIPTION</th>
            <th class="col-used">USED</th>
            <th class="col-spare">SPARE</th>
            <th class="col-total">TOTAL</th>
            <th class="col-notes">NOTES</th>
          </tr>
        </thead>
        <tbody>
          <?php $lineNumber = 1; ?>
          <?php foreach ($equipmentRows as $row): ?>
          <?php $delta = export_line_delta($revision, (int) $row['item']['id'], $row['line'], 'rent_quantity'); ?>
          <tr style="<?= h(export_row_style($lineNumber - 1, $revision, $row['item'], $row['line'])) ?>">
            <td class="line-cell"><?= h((string) $lineNumber++) ?></td>
            <td><?= h($row['item']['name']) ?></td>
            <td><?= h($row['category']) ?></td>
            <td><?= h((string) ($row['line']['rent_quantity'] ?? 0)) ?></td>
            <td><?= h((string) ($row['line']['spare_quantity'] ?? 0)) ?></td>
            <td>
              <?= $type === 'returns' ? '__________' : h((string) ($row['line']['total_quantity'] ?? 0)) ?>
              <?php if ($delta !== ''): ?><span class="delta <?= str_starts_with($delta, '-') ? 'delta-negative' : 'delta-positive' ?>"><?= h($delta) ?></span><?php endif; ?>
            </td>
            <td class="notes-cell"><?= h(export_equipment_note($row['item'], $row['line'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>
  </div>
</body>
</html>
