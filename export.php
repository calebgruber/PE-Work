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

function export_equipment_pages(array $rows, int $rowsPerPage = 25): array
{
    if (!$rows) {
        return [[]];
    }

    return array_chunk($rows, max(1, $rowsPerPage));
}

$type = $_GET['type'] ?? 'order';
$labels = export_type_labels($type);
$layout = export_layout_settings();
$catalog = catalog_for_revision((int) $revision['id']);
$revisionCode = revision_display_code($revision);
$equipmentRows = export_equipment_rows($catalog, $type);
$equipmentPages = export_equipment_pages($equipmentRows);
$summaryRows = (($layout['layout.show_revision_summary'] ?? '1') === '1') ? export_summary_rows($catalog, $revision, $type) : [];
$notes = export_notes_list($layout, $show);
$backTab = !empty($revision['is_initial']) ? 'orders' : 'revisions';
$editorUrl = url_for('show?show_id=' . $showId . '&tab=' . $backTab . '&mode=edit&revision_id=' . (int) $revision['id'] . '&export_type=' . rawurlencode((string) $type));
$renderSummaryPage = !empty($summaryRows);
$pageNumbers = ['cover' => 1, 'equipment' => []];
$nextPageNumber = 2;
if ($renderSummaryPage) {
    $pageNumbers['summary'] = $nextPageNumber++;
}
foreach ($equipmentPages as $_equipmentPage) {
    $pageNumbers['equipment'][] = $nextPageNumber++;
}
$totalPages = $nextPageNumber - 1;
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
      min-height: 11in;
      padding: 0.55in 0.7in 0.6in;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
    .page-content {
      flex: 1 1 auto;
      min-height: 0;
    }
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
      margin-top: 0.2in;
      margin-bottom: 0.22in;
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
    .cover-contact-bar {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.1in 0.18in;
      margin-bottom: 0.18in;
      padding: 0.12in 0.16in;
      border: 1px solid #d6dbe3;
      border-radius: 0.1in;
      background: #fafbfc;
    }
    .cover-contact-cell {
      min-width: 0;
    }
    .cover-contact-label {
      display: block;
      margin-bottom: 0.03in;
      font-size: 8.5pt;
      font-weight: 700;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      color: #4b5563;
    }
    .cover-contact-value {
      color: #111827;
      word-break: break-word;
    }
    .cover-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.14in;
      margin-bottom: 0.18in;
    }
    .cover-panel {
      padding: 0.14in 0.16in;
      border: 1px solid #d8dde6;
      border-radius: 0.1in;
      background: #fff;
    }
    .cover-panel-wide {
      grid-column: 1 / -1;
    }
    .cover-panel-title {
      margin: 0 0 0.08in;
      font-weight: 700;
      font-size: 9.5pt;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    .cover-list {
      display: grid;
      gap: 0.1in;
    }
    .cover-entry {
      display: grid;
      gap: 0.03in;
    }
    .cover-entry-label {
      font-weight: 600;
      color: #111827;
    }
    .cover-entry-meta,
    .cover-panel-copy {
      color: #374151;
    }
    .cover-entry-meta {
      display: grid;
      gap: 0.02in;
    }
    .cover-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.1in 0.16in;
    }
    .notes-heading {
      margin-top: 0.22in;
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
      width: 100%;
      max-width: 7.08in;
      margin: 0 auto;
    }
    .equipment-table-wrap {
      display: flex;
      justify-content: center;
      width: 100%;
      margin: 0 auto;
    }
    table.word-table th,
    table.word-table td {
      padding: 0.05in 0.075in;
      vertical-align: middle;
      text-align: left;
      white-space: nowrap;
      overflow: hidden;
    }
    table.word-table th:first-child,
    table.word-table td:first-child {
      padding-left: 0.08in;
    }
    table.word-table thead th {
      border-bottom: 1px solid #666;
      text-decoration: underline;
      font-weight: 700;
    }
    .col-line { width: 5%; }
    .col-item { width: 36%; }
    .col-description { width: 17%; }
    .col-action { width: 17%; }
    .col-qty { width: 13%; }
    .col-used,
    .col-spare { width: 6%; }
    .col-total { width: 10%; }
    .col-notes { width: 19%; }
    .notes-cell {
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .line-cell {
      text-align: right;
      font-weight: 700;
      font-size: 7.5pt;
      padding-right: 0.04in;
    }
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
      display: flex;
      justify-content: flex-end;
      margin-top: auto;
      padding-top: 0.2in;
      font-size: 9pt;
      color: #374151;
      page-break-inside: avoid;
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
      <div class="page-content">
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
      <div class="cover-contact-bar">
        <div class="cover-contact-cell">
          <span class="cover-contact-label">Shop</span>
          <div class="cover-contact-value"><?= h(export_value((string) ($show['shop_name'] ?? ''))) ?></div>
        </div>
        <div class="cover-contact-cell">
          <span class="cover-contact-label">Manager Contact</span>
          <div class="cover-contact-value"><?= h(export_value((string) ($show['shop_manager_phone'] ?? ''))) ?></div>
        </div>
        <div class="cover-contact-cell">
          <span class="cover-contact-label">Address</span>
          <div class="cover-contact-value"><?= h(export_value((string) ($show['shop_address'] ?? ''))) ?></div>
        </div>
        <div class="cover-contact-cell">
          <span class="cover-contact-label">Email</span>
          <div class="cover-contact-value"><?= h(export_value((string) ($show['shop_manager_email'] ?? ''))) ?></div>
        </div>
      </div>

      <div class="center-title">
        <p class="show-name">&quot;<?= h($show['show_name']) ?>&quot;</p>
        <p class="subtitle"><?= h($labels['title']) ?></p>
        <p class="revised">REVISION <?= h($revisionCode) ?> · <?= !empty($revision['is_initial']) ? 'INITIAL ORDER' : 'REVISED' ?> <?= h($revision['revision_date']) ?></p>
      </div>

      <div class="cover-grid">
        <div class="cover-panel">
          <p class="cover-panel-title">Creative Team</p>
          <div class="cover-list">
            <div class="cover-entry">
              <div class="cover-entry-label">Designer · <?= h(export_value((string) ($show['ld_name'] ?? ''))) ?></div>
              <div class="cover-entry-meta">
                <div><?= h(export_value((string) ($show['ld_email'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['ld_phone'] ?? ''))) ?></div>
              </div>
            </div>
            <div class="cover-entry">
              <div class="cover-entry-label">Assistant Designer · <?= h(export_value((string) ($show['assistant_ld_name'] ?? ''))) ?></div>
              <div class="cover-entry-meta">
                <div><?= h(export_value((string) ($show['assistant_ld_email'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['assistant_ld_phone'] ?? ''))) ?></div>
              </div>
            </div>
            <div class="cover-entry">
              <div class="cover-entry-label">Production Electrician · <?= h(export_value((string) ($show['production_electrician_name'] ?? ''))) ?></div>
              <div class="cover-entry-meta">
                <div><?= h(export_value((string) ($show['production_electrician_email'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['production_electrician_phone'] ?? ''))) ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="cover-panel">
          <p class="cover-panel-title">Show Schedule</p>
          <div class="cover-detail-grid">
            <div>
              <div class="cover-entry-label">Load-In</div>
              <div class="cover-panel-copy"><?= h(export_value((string) ($show['pull_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Opening</div>
              <div class="cover-panel-copy"><?= h(export_value((string) ($show['opening_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Return</div>
              <div class="cover-panel-copy"><?= h(export_value((string) ($show['return_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Strike</div>
              <div class="cover-panel-copy"><?= h(export_value((string) ($show['strike_date'] ?? ''))) ?></div>
            </div>
          </div>
        </div>
        <div class="cover-panel cover-panel-wide">
          <p class="cover-panel-title">Shop Team</p>
          <div class="cover-detail-grid">
            <div class="cover-entry">
              <div class="cover-entry-label"><?= h(export_value((string) ($show['shop_name'] ?? ''))) ?> · Manager</div>
              <div class="cover-entry-meta">
                <div><?= h(export_value((string) ($show['shop_manager_name'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['shop_manager_email'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['shop_manager_phone'] ?? ''))) ?></div>
              </div>
            </div>
            <div class="cover-entry">
              <div class="cover-entry-label">Assistant Shop Manager</div>
              <div class="cover-entry-meta">
                <div><?= h(export_value((string) ($show['assistant_shop_manager_name'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['assistant_shop_manager_email'] ?? ''))) ?></div>
                <div><?= h(export_value((string) ($show['assistant_shop_manager_phone'] ?? ''))) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p class="notes-heading">IMPORTANT NOTES:</p>
      <ol class="notes-list">
        <?php foreach ($notes as $note): ?>
        <li><?= h($note) ?></li>
        <?php endforeach; ?>
      </ol>
      </div>

      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>

    <?php if ($renderSummaryPage): ?>
    <section class="page">
      <div class="page-content">
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
      <table class="word-table">
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
      </div>
      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>
    <?php endif; ?>

    <?php $lineNumber = 1; ?>
    <?php foreach ($equipmentPages as $equipmentPageIndex => $equipmentPageRows): ?>
    <section class="page">
      <div class="page-content">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle">Electrical Equipment List</div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <div><strong>Page</strong> <?= h((string) $pageNumbers['equipment'][$equipmentPageIndex]) ?> of <?= h((string) $totalPages) ?></div>
          </div>
        </div>
      </div>
      <p class="page-heading"><?= h($labels['equipment_heading']) ?></p>
      <div class="equipment-table-wrap">
      <table class="word-table equipment-table">
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
          <?php foreach ($equipmentPageRows as $row): ?>
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
      </div>
      </div>
      <div class="footer">
        <span><?= h($layout['layout.footer_text']) ?></span>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</body>
</html>
