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

            $countChanged = (int) ($line['rent_quantity'] ?? 0) !== (int) ($previousLine['rent_quantity'] ?? 0)
                || (int) ($line['spare_quantity'] ?? 0) !== (int) ($previousLine['spare_quantity'] ?? 0)
                || (int) ($line['total_quantity'] ?? 0) !== (int) ($previousLine['total_quantity'] ?? 0);
            $hasAction = in_array((string) ($line['action'] ?? ''), ['add', 'return', 'exchange', 'note'], true);

            if (!$countChanged && !$hasAction) {
                continue;
            }

            $rows[] = [
                'category' => $category['name'],
                'item' => $item,
                'line' => $line,
                'previous_line' => $previousLine,
                'description' => $category['name'],
            ];
        }
    }

    return $rows;
}

function export_notes_list(array $layout): array
{
    $notes = preg_split('/\r\n|\r|\n/', (string) ($layout['layout.export_notes'] ?? '')) ?: [];
    $notes = array_map(static fn ($note) => trim((string) $note), $notes);
    return array_values(array_filter($notes, static fn ($note) => $note !== ''));
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

function export_row_style(int $rowIndex, array $revision, array $item, array $line, string $gray = '#CCCCCC'): string
{
    $actionBackground = export_row_background($revision, $item, $line);
    if ($actionBackground !== '') {
        return $actionBackground;
    }

    return $rowIndex % 2 === 0 ? 'background:' . $gray . ';' : 'background:#FFFFFF;';
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

function export_item_description(array $item): string
{
    return trim((string) ($item['description'] ?? ''));
}

function export_note_date(?string $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('m/d/y', $timestamp);
}

function export_cover_date(?string $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('m/d/Y', $timestamp);
}

function export_revision_history(int $showId, array $revision): array
{
    $rows = list_revisions($showId);
    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) ($row['revision_index'] ?? 0) <= (int) ($revision['revision_index'] ?? 0)
    ));
}

function export_revision_history_label(array $revision): string
{
    if (!empty($revision['is_initial'])) {
        return 'INITIAL ORDER - ' . export_cover_date((string) ($revision['revision_date'] ?? ''), '');
    }

    return 'REVISION ' . revision_display_code($revision) . ' - ' . export_cover_date((string) ($revision['revision_date'] ?? ''), '');
}

function export_summary_action_label(array $line): string
{
    return match ((string) ($line['action'] ?? '')) {
        'add' => 'ADD',
        'return' => 'RETURN',
        'exchange' => 'EXCHANGE',
        'note' => 'SEE NOTES',
        default => 'CHANGE',
    };
}

function export_equipment_note(array $item, array $line): string
{
    $parts = [];
    $lineNote = trim((string) ($line['line_note'] ?? ''));
    if ($lineNote !== '') {
        $parts[] = $lineNote;
    }
    if (!empty($line['pickup_date'])) {
        $parts[] = 'Pull ' . export_note_date((string) $line['pickup_date'], (string) $line['pickup_date']);
    }
    if (!empty($line['return_date'])) {
        $parts[] = 'Return ' . export_note_date((string) $line['return_date'], (string) $line['return_date']);
    }

    return implode(' · ', $parts);
}

function export_equipment_layout_metrics(array $layout): array
{
    $tableWidth = (float) ($layout['layout.equipment_table_width'] ?? 100);
    $minRowsPerPage = (int) ($layout['layout.equipment_min_rows_per_page'] ?? 0);
    $maxRowsPerPage = (int) ($layout['layout.equipment_max_rows_per_page'] ?? 0);
    $rowPadding = (float) ($layout['layout.equipment_row_padding'] ?? 0.016);
    $headerRowPadding = (float) ($layout['layout.equipment_header_row_padding'] ?? 0.22);
    $categoryRowPadding = (float) ($layout['layout.equipment_category_row_padding'] ?? 0.26);
    $categoryGap = (float) ($layout['layout.equipment_category_gap'] ?? 0.08);
    $fontSize = (float) ($layout['layout.equipment_font_size'] ?? 7.35);
    $lineHeight = (float) ($layout['layout.equipment_line_height'] ?? 1.1);
    $lineWidth = (float) ($layout['layout.equipment_col_line'] ?? 4);
    $rawColumns = [
        'item' => (float) ($layout['layout.equipment_col_item'] ?? 45),
        'description' => (float) ($layout['layout.equipment_col_description'] ?? 23),
        'used' => (float) ($layout['layout.equipment_col_used'] ?? 5),
        'spare' => (float) ($layout['layout.equipment_col_spare'] ?? 5),
        'total' => (float) ($layout['layout.equipment_col_total'] ?? 6),
        'notes' => (float) ($layout['layout.equipment_col_notes'] ?? 12),
    ];
    $columnFontSizes = [
        'line' => (float) ($layout['layout.equipment_font_line'] ?? 6.9),
        'item' => (float) ($layout['layout.equipment_font_item'] ?? $fontSize),
        'description' => (float) ($layout['layout.equipment_font_description'] ?? $fontSize),
        'used' => (float) ($layout['layout.equipment_font_used'] ?? $fontSize),
        'spare' => (float) ($layout['layout.equipment_font_spare'] ?? $fontSize),
        'total' => (float) ($layout['layout.equipment_font_total'] ?? $fontSize),
        'action' => (float) ($layout['layout.equipment_font_action'] ?? $fontSize),
        'notes' => (float) ($layout['layout.equipment_font_notes'] ?? $fontSize),
    ];
    $columns = [];
    foreach ($rawColumns as $key => $value) {
        $columns[$key] = round($value, 3);
    }
    $summaryActionWidth = round(($columns['used'] ?? 0) + ($columns['spare'] ?? 0), 3);

    return [
        'table_width' => $tableWidth,
        'min_rows_per_page' => $minRowsPerPage,
        'max_rows_per_page' => $maxRowsPerPage,
        'row_padding' => $rowPadding,
        'header_row_padding' => $headerRowPadding,
        'category_row_padding' => $categoryRowPadding,
        'category_gap' => $categoryGap,
        'font_size' => $fontSize,
        'line_height' => $lineHeight,
        'line_width' => $lineWidth,
        'columns' => $columns,
        'summary_action_width' => $summaryActionWidth,
        'column_font_sizes' => $columnFontSizes,
    ];
}

function export_estimated_line_count(string $text, float $columnWidthPercent, array $metrics, ?string $columnKey = null): int
{
    $text = trim($text);
    if ($text === '') {
        return 1;
    }

    $tableWidthInches = (8.5 - 0.18 - 0.18) * ($metrics['table_width'] / 100);
    $columnWidthInches = max(0.6, $tableWidthInches * ($columnWidthPercent / 100));
    $usableWidth = max(0.45, $columnWidthInches - 0.08);
    $fontSize = $columnKey !== null ? (float) ($metrics['column_font_sizes'][$columnKey] ?? $metrics['font_size']) : (float) $metrics['font_size'];
    $averageCharacterWidth = max(0.055, ($fontSize / 72) * 0.52);
    $charactersPerLine = max(8, (int) floor($usableWidth / $averageCharacterWidth));
    $segments = preg_split('/\R/u', $text) ?: [''];
    $lines = 0;
    foreach ($segments as $segment) {
        $length = function_exists('mb_strlen') ? mb_strlen($segment) : strlen($segment);
        $lines += max(1, (int) ceil($length / $charactersPerLine));
    }

    return max(1, $lines);
}

function export_equipment_page_row_height(array $row, array $metrics): float
{
    $itemLines = export_estimated_line_count((string) ($row['item']['name'] ?? ''), $metrics['columns']['item'], $metrics, 'item');
    $descriptionLines = export_estimated_line_count((string) ($row['category'] ?? ''), $metrics['columns']['description'], $metrics, 'description');
    $notesLines = export_estimated_line_count(export_equipment_note($row['item'], $row['line']), $metrics['columns']['notes'], $metrics, 'notes');
    $lineCount = max($itemLines, $descriptionLines, $notesLines);
    $rowFontSize = max(
        (float) ($metrics['column_font_sizes']['item'] ?? $metrics['font_size']),
        (float) ($metrics['column_font_sizes']['description'] ?? $metrics['font_size']),
        (float) ($metrics['column_font_sizes']['notes'] ?? $metrics['font_size']),
        (float) ($metrics['column_font_sizes']['used'] ?? $metrics['font_size']),
        (float) ($metrics['column_font_sizes']['spare'] ?? $metrics['font_size']),
        (float) ($metrics['column_font_sizes']['total'] ?? $metrics['font_size'])
    );
    $baseHeight = (($rowFontSize / 72) * $metrics['line_height']) + ($metrics['row_padding'] * 2) + 0.08;

    return max(0.18, $baseHeight * $lineCount);
}

function export_equipment_header_row_height(array $metrics): float
{
    return max(0.18, (float) ($metrics['header_row_padding'] ?? 0.22));
}

function export_equipment_category_row_height(array $metrics): float
{
    return max(0.2, (float) ($metrics['category_row_padding'] ?? 0.26));
}

function export_equipment_category_transition_height(bool $hasPreviousCategory, array $metrics): float
{
    $gapHeight = $hasPreviousCategory ? (float) ($metrics['category_gap'] ?? 0.08) : 0.0;
    return $gapHeight + export_equipment_category_row_height($metrics) + export_equipment_header_row_height($metrics);
}

function export_equipment_pages(array $rows, array $layout): array
{
    if (!$rows) {
        return [[]];
    }

    $metrics = export_equipment_layout_metrics($layout);
    $availableHeight = 7.55;
    $minimumRows = (int) ($metrics['min_rows_per_page'] ?? 0);
    $maximumRows = (int) ($metrics['max_rows_per_page'] ?? 0);
    if ($maximumRows > 0 && $minimumRows > $maximumRows) {
        $minimumRows = $maximumRows;
    }
    $pages = [];
    $currentPage = [];
    $currentHeight = 0.0;
    $currentCategory = null;

    foreach ($rows as $row) {
        $rowHeight = export_equipment_page_row_height($row, $metrics);
        $transitionHeight = $currentCategory !== $row['category']
            ? export_equipment_category_transition_height($currentCategory !== null, $metrics)
            : 0.0;
        $currentRowCount = count($currentPage);
        $reachesRowCap = $maximumRows > 0 && $currentRowCount >= $maximumRows;
        $meetsMinimumRows = $minimumRows === 0 || $currentRowCount >= $minimumRows;
        if ($currentPage !== [] && ($reachesRowCap || ($meetsMinimumRows && ($currentHeight + $transitionHeight + $rowHeight) > $availableHeight))) {
            $pages[] = $currentPage;
            $currentPage = [];
            $currentHeight = 0.0;
            $currentCategory = null;
            $transitionHeight = export_equipment_category_transition_height(false, $metrics);
        }

        $currentHeight += $transitionHeight;
        $currentPage[] = $row;
        $currentHeight += $rowHeight;
        $currentCategory = $row['category'];
    }

    if ($currentPage !== []) {
        $pages[] = $currentPage;
    }

    return $pages;
}

$type = $_GET['type'] ?? 'order';
$labels = export_type_labels($type);
$layout = export_layout_settings();
$equipmentMetrics = export_equipment_layout_metrics($layout);
$catalog = catalog_for_revision((int) $revision['id']);
$revisionCode = revision_display_code($revision);
$revisionHistory = export_revision_history($showId, $revision);
$equipmentRows = export_equipment_rows($catalog, $type);
$equipmentPages = export_equipment_pages($equipmentRows, $layout);
$summaryRows = !empty($revision['is_initial']) ? [] : export_summary_rows($catalog, $revision, $type);
$notes = export_notes_list($layout);
$backTab = !empty($revision['is_initial']) ? 'orders' : 'revisions';
$editorUrl = url_for('show?show_id=' . $showId . '&tab=' . $backTab . '&mode=edit&revision_id=' . (int) $revision['id'] . '&export_type=' . rawurlencode((string) $type));
$renderSummaryPage = empty($revision['is_initial']);
$pageNumbers = ['cover' => 1, 'details' => 2, 'equipment' => []];
$nextPageNumber = 3;
if ($renderSummaryPage) {
    $pageNumbers['summary'] = $nextPageNumber++;
}
foreach ($equipmentPages as $_equipmentPage) {
    $pageNumbers['equipment'][] = $nextPageNumber++;
}
$totalPages = $nextPageNumber - 1;
$headerOrganization = export_value((string) ($layout['layout.organization_text'] ?? ''), (string) ($show['theatre_name'] ?? ''));
$theatreAddress = trim((string) ($show['theatre_address'] ?? ''));
$equipmentZebraGray = strtoupper(trim((string) ($layout['layout.equipment_zebra_gray'] ?? '#CCCCCC')));
if (!preg_match('/^#[0-9A-F]{6}$/', $equipmentZebraGray)) {
    $equipmentZebraGray = '#CCCCCC';
}
$equipmentHeaderFill = strtoupper(trim((string) ($layout['layout.equipment_header_fill'] ?? '#F3F4F6')));
if (!preg_match('/^#[0-9A-F]{6}$/', $equipmentHeaderFill)) {
    $equipmentHeaderFill = '#F3F4F6';
}
$equipmentCategoryFill = strtoupper(trim((string) ($layout['layout.equipment_category_fill'] ?? '#E5E7EB')));
if (!preg_match('/^#[0-9A-F]{6}$/', $equipmentCategoryFill)) {
    $equipmentCategoryFill = '#E5E7EB';
}
$coverTitleRevisionSpacing = max(0.0, (float) ($layout['layout.cover_title_revision_spacing'] ?? 0.52));
$coverNotesSpacing = max(0.0, (float) ($layout['layout.cover_notes_spacing'] ?? 0.9));
$coverFooterLogoPath = sanitize_local_asset_path((string) ($layout['layout.cover_footer_logo_url'] ?? ''));
$coverFooterLogoUrl = $coverFooterLogoPath ? url_for($coverFooterLogoPath) : '';
$coverPreparedByName = trim((string) ($layout['layout.cover_prepared_by_name'] ?? ''));
if ($coverPreparedByName === '') {
    $coverPreparedByName = (string) (current_user()['display_name'] ?? '');
}
$footerText = trim((string) ($layout['layout.footer_text'] ?? ''));
$globalFooterText = $coverPreparedByName !== '' ? 'Prepared by: ' . $coverPreparedByName : $footerText;
$coverShowTitle = ($layout['layout.cover_show_title'] ?? '1') === '1';
$showPageNumbers = ($layout['layout.show_page_numbers'] ?? '1') === '1';
$coverTheatreName = trim((string) ($show['theatre_name'] ?? ''));
$coverTheatreAddress = trim((string) ($show['theatre_address'] ?? ''));
$coverTitle = $type === 'order' ? 'LIGHTING SHOP ORDER' : strtoupper($labels['title']);
$showImagePath = trim((string) ($show['show_image_url'] ?? ''));
$showImageUrl = $showImagePath !== '' && ($layout['layout.show_image'] ?? '1') === '1' ? url_for($showImagePath) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($show['show_name']) ?> <?= h($labels['title']) ?></title>
  <style>
    @page {
      size: Letter portrait;
      margin: 0;
    }
    body {
      margin: 0;
      background: #f3f4f6;
      color: #000;
      font-family: Aptos, Arial, Helvetica, sans-serif;
      font-size: 11pt;
      line-height: 1.1;
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
      width: 100%;
      margin: 0 auto 2rem;
    }
    .page {
      position: relative;
      width: 8.5in;
      min-height: 11in;
      height: 11in;
      margin: 0 auto 1rem;
      padding: 0.55in 0.7in 0.6in;
      box-sizing: border-box;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      background: #fff;
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
      break-inside: avoid-page;
      page-break-inside: avoid;
      page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
    .page.equipment-page {
      padding-left: 0.18in;
      padding-right: 0.18in;
    }
    .page-content {
      min-height: 0;
      overflow: hidden;
    }
    .page p { margin: 0 0 0.08in; }
    .top-rule {
      border-bottom: 1px solid #000;
      padding-bottom: 0.08in;
      margin-bottom: 0.12in;
    }
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
    .cover-page {
      padding: 0.55in 0.7in 0.42in;
    }
    .cover-page .page-content {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .cover-body {
      width: 100%;
      text-align: center;
      line-height: 1.55;
    }
    .cover-show-title {
      margin-bottom: 0.18in;
      font-size: 18pt;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    .cover-art {
      margin: 0 auto 0.55in;
      max-width: 6.5in;
    }
    .cover-art img {
      display: block;
      max-width: 100%;
      max-height: 3.5in;
      width: auto;
      height: auto;
      margin: 0 auto;
      object-fit: contain;
    }
    .cover-title-fallback {
      font-size: 29pt;
      font-style: italic;
      font-weight: 600;
      letter-spacing: 0.01em;
    }
    .cover-venue-name {
      margin-bottom: 0.04in;
      font-size: 18pt;
      font-style: italic;
      letter-spacing: 0.01em;
    }
    .cover-venue-address {
      margin-bottom: 0.1in;
      font-size: 15pt;
      letter-spacing: 0.01em;
    }
    .cover-document-title {
      margin-bottom: <?= h(number_format($coverTitleRevisionSpacing, 3, '.', '')) ?>in;
      font-size: 25pt;
      letter-spacing: 0.02em;
    }
    .cover-revision-current {
      font-size: 17pt;
      font-weight: 600;
      letter-spacing: 0.01em;
      line-height: 1.55;
    }
    .cover-revision-history {
      margin-top: 0.12in;
      display: grid;
      gap: 0.11in;
      font-size: 13.5pt;
      line-height: 1.45;
    }
    .details-page .page-content {
      display: flex;
      flex-direction: column;
    }
    .details-page-heading {
      text-align: center;
      font-weight: 700;
      letter-spacing: 0.03em;
      margin-bottom: 0.16in;
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
    .notes-section {
      margin-top: <?= h(number_format($coverNotesSpacing, 3, '.', '')) ?>in;
    }
    .notes-heading {
      margin-top: 0;
      text-decoration: underline;
      font-weight: 600;
    }
    table.word-table.revision-summary-table {
      width: 92%;
      margin-left: auto;
      margin-right: auto;
    }
    ol.notes-list {
      margin: 0.1in 0 0 0.28in;
      padding: 0;
    }
    ol.notes-list li { margin-bottom: 0.06in; }
    .page-heading {
      text-align: center;
      font-weight: 700;
      margin-top: 0.03in;
      margin-bottom: 0.08in;
      letter-spacing: 0.01em;
    }
    .page-note {
      text-align: center;
      margin-bottom: 0.14in;
    }
    table.word-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin-top: 0.12in;
      font-size: 9pt;
    }
    table.word-table.equipment-table {
      width: <?= h(number_format($equipmentMetrics['table_width'], 1, '.', '')) ?>%;
      max-width: 100%;
      margin: 0 auto;
      font-size: <?= h(number_format($equipmentMetrics['font_size'], 2, '.', '')) ?>pt;
    }
    .equipment-table-wrap {
      display: flex;
      justify-content: center;
      width: 100%;
      margin: 0 auto;
    }
    table.word-table th,
    table.word-table td {
      padding: <?= h(number_format($equipmentMetrics['row_padding'], 3, '.', '')) ?>in 0.03in;
      vertical-align: middle;
      text-align: left;
      white-space: nowrap;
    }
    table.word-table tbody tr:not(.category-gap-row):not(.category-header-row):not(.category-column-header-row) td {
      min-height: <?= h(number_format(max(0.0, $equipmentMetrics['row_padding'] * 2), 3, '.', '')) ?>in;
      line-height: <?= h(number_format($equipmentMetrics['line_height'], 2, '.', '')) ?>;
    }
    table.word-table th:first-child,
    table.word-table td:first-child {
      padding-left: 0.08in;
    }
    table.word-table thead tr {
      height: <?= h(number_format(export_equipment_header_row_height($equipmentMetrics), 3, '.', '')) ?>in;
    }
    table.word-table thead th {
      border-bottom: 1px solid #666;
      text-decoration: underline;
      font-weight: 700;
      height: <?= h(number_format(export_equipment_header_row_height($equipmentMetrics), 3, '.', '')) ?>in;
      padding-top: 0;
      padding-bottom: 0;
      line-height: 1.1;
      box-sizing: border-box;
      background: <?= h($equipmentHeaderFill) ?>;
    }
    .col-line { width: <?= h(number_format($equipmentMetrics['line_width'], 3, '.', '')) ?>%; }
    .col-item { width: <?= h(number_format($equipmentMetrics['columns']['item'], 3, '.', '')) ?>%; }
    .col-description { width: <?= h(number_format($equipmentMetrics['columns']['description'], 3, '.', '')) ?>%; }
    .col-action { width: 7%; }
    .col-qty { width: 13%; }
    .col-used { width: <?= h(number_format($equipmentMetrics['columns']['used'], 3, '.', '')) ?>%; }
    .col-spare { width: <?= h(number_format($equipmentMetrics['columns']['spare'], 3, '.', '')) ?>%; }
    .col-total { width: <?= h(number_format($equipmentMetrics['columns']['total'], 3, '.', '')) ?>%; }
    .col-notes { width: <?= h(number_format($equipmentMetrics['columns']['notes'], 3, '.', '')) ?>%; }
    .col-line,
    .line-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['line'], 2, '.', '')) ?>pt; }
    .col-item,
    .item-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['item'], 2, '.', '')) ?>pt; }
    .col-description,
    .description-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['description'], 2, '.', '')) ?>pt; }
    .col-used,
    .used-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['used'], 2, '.', '')) ?>pt; }
    .col-spare,
    .spare-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['spare'], 2, '.', '')) ?>pt; }
    .col-total,
    .total-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['total'], 2, '.', '')) ?>pt; }
    .col-action,
    .action-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['action'], 2, '.', '')) ?>pt; }
    .col-notes,
    .notes-cell { font-size: <?= h(number_format($equipmentMetrics['column_font_sizes']['notes'], 2, '.', '')) ?>pt; }
    .item-cell,
    .description-cell,
    .notes-cell {
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .line-cell {
      text-align: right;
      font-weight: 700;
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
    .category-gap-row td {
      padding: <?= h(number_format($equipmentMetrics['category_gap'], 3, '.', '')) ?>in 0 0;
      border: 0;
      background: #fff;
    }
    .category-header-row {
      height: <?= h(number_format(export_equipment_category_row_height($equipmentMetrics), 3, '.', '')) ?>in;
    }
    .category-header-row td {
      height: <?= h(number_format(export_equipment_category_row_height($equipmentMetrics), 3, '.', '')) ?>in;
      min-height: <?= h(number_format(export_equipment_category_row_height($equipmentMetrics), 3, '.', '')) ?>in;
      padding-top: 0;
      padding-bottom: 0;
      line-height: 1.1;
      box-sizing: border-box;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      border-top: 1px solid #111827;
      border-bottom: 1px solid #9ca3af;
      background: <?= h($equipmentCategoryFill) ?>;
    }
    .category-column-header-row {
      height: <?= h(number_format(export_equipment_header_row_height($equipmentMetrics), 3, '.', '')) ?>in;
    }
    .category-column-header-row td {
      height: <?= h(number_format(export_equipment_header_row_height($equipmentMetrics), 3, '.', '')) ?>in;
      min-height: <?= h(number_format(export_equipment_header_row_height($equipmentMetrics), 3, '.', '')) ?>in;
      padding-top: 0;
      padding-bottom: 0;
      line-height: 1.1;
      box-sizing: border-box;
      border-bottom: 1px solid #666;
      text-decoration: underline;
      font-weight: 700;
      background: <?= h($equipmentHeaderFill) ?>;
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
      align-items: flex-end;
      min-height: 0.24in;
      padding-top: 0.08in;
      font-size: 9pt;
      color: #374151;
      page-break-inside: avoid;
    }
    .cover-footer {
      justify-content: center;
      text-align: center;
      flex-direction: column;
      gap: 0.14in;
      align-items: center;
    }
    .cover-footer-logo {
      width: 100%;
      display: flex;
      justify-content: center;
    }
    .cover-footer-logo img {
      display: block;
      max-width: 3.2in;
      max-height: 1.05in;
      width: auto;
      height: auto;
      margin: 0 auto;
      object-fit: contain;
    }
    .cover-footer-prepared-by {
      font-weight: 600;
      text-align: center;
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .document { width: auto; margin: 0; }
      .page {
        width: auto;
        margin: 0;
        box-shadow: none;
      }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= h($editorUrl) ?>">Back</a>
    <button type="button" onclick="window.print()">Print / Save PDF</button>
  </div>
  <div class="document">
    <section class="page cover-page">
      <div class="page-content">
      <div class="cover-body">
        <?php if ($coverShowTitle): ?><p class="cover-show-title"><?= h($show['show_name']) ?></p><?php endif; ?>
        <div class="cover-art">
          <?php if ($showImageUrl !== ''): ?>
          <img src="<?= h($showImageUrl) ?>" alt="<?= h($show['show_name']) ?>">
          <?php elseif (!$coverShowTitle): ?>
          <div class="cover-title-fallback"><?= h($show['show_name']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($coverTheatreName !== ''): ?><p class="cover-venue-name"><?= h($coverTheatreName) ?></p><?php endif; ?>
        <?php if ($coverTheatreAddress !== ''): ?><p class="cover-venue-address"><?= h($coverTheatreAddress) ?></p><?php endif; ?>
        <p class="cover-document-title"><?= h($coverTitle) ?></p>
        <p class="cover-revision-current">&gt;&gt; <?= h(export_revision_history_label($revision)) ?> &lt;&lt;</p>
        <?php if (count($revisionHistory) > 1): ?>
        <div class="cover-revision-history">
          <?php foreach (array_slice($revisionHistory, 1) as $historyRevision): ?>
          <div><?= h(export_revision_history_label($historyRevision)) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      </div>

      <div class="footer cover-footer">
        <?php if ($coverFooterLogoUrl !== ''): ?>
        <div class="cover-footer-logo"><img src="<?= h($coverFooterLogoUrl) ?>" alt="Cover footer logo"></div>
        <?php endif; ?>
        <?php if ($coverPreparedByName !== ''): ?><div class="cover-footer-prepared-by">Prepared by: <?= h($coverPreparedByName) ?></div><?php endif; ?>
        <?php if ($coverPreparedByName === '' && $globalFooterText !== ''): ?><span><?= h($globalFooterText) ?></span><?php endif; ?>
      </div>
    </section>

    <section class="page details-page">
      <div class="page-content">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle"><?= h($coverTitle) ?></div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <?php if ($showPageNumbers): ?><div><strong>Page</strong> <?= h((string) $pageNumbers['details']) ?> of <?= h((string) $totalPages) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <p class="details-page-heading">CREW &amp; NOTES</p>
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
              <div class="cover-panel-copy"><?= h(export_cover_date((string) ($show['pull_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Opening</div>
              <div class="cover-panel-copy"><?= h(export_cover_date((string) ($show['opening_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Return</div>
              <div class="cover-panel-copy"><?= h(export_cover_date((string) ($show['return_date'] ?? ''))) ?></div>
            </div>
            <div>
              <div class="cover-entry-label">Strike</div>
              <div class="cover-panel-copy"><?= h(export_cover_date((string) ($show['strike_date'] ?? ''))) ?></div>
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

      <div class="notes-section">
        <p class="notes-heading">IMPORTANT NOTES:</p>
        <ol class="notes-list">
          <?php foreach ($notes as $note): ?>
          <li><?= h($note) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
      </div>

      <div class="footer">
        <span><?= h($globalFooterText) ?></span>
      </div>
    </section>

    <?php if ($renderSummaryPage): ?>
    <section class="page">
      <div class="page-content">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle"><?= h($coverTitle) ?></div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <?php if ($showPageNumbers): ?><div><strong>Page</strong> <?= h((string) $pageNumbers['summary']) ?> of <?= h((string) $totalPages) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <p class="page-heading">REVISION SUMMARY</p>
      <p class="page-note">Only lines with changed counts or explicit revision actions are listed here.</p>
      <div class="equipment-table-wrap">
      <table class="word-table equipment-table revision-summary-table">
        <colgroup>
          <col style="width: <?= h(number_format($equipmentMetrics['line_width'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['item'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['description'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['total'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['summary_action_width'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['notes'], 3, '.', '')) ?>%;">
        </colgroup>
        <tbody>
          <?php if ($summaryRows): ?>
          <?php $summaryCategory = null; ?>
          <?php foreach ($summaryRows as $index => $row): ?>
          <?php if ($summaryCategory !== $row['category']): ?>
          <?php if ($summaryCategory !== null): ?>
          <tr class="category-gap-row"><td colspan="6"></td></tr>
          <?php endif; ?>
          <tr class="category-header-row">
            <td colspan="6"><?= h($row['category']) ?></td>
          </tr>
          <tr class="category-column-header-row">
            <td class="col-line">LINE</td>
            <td class="col-item">ITEM</td>
            <td class="col-description">DESCRIPTION</td>
            <td class="col-total">TOTAL</td>
            <td class="col-action">ACTION</td>
            <td class="col-notes">NOTES</td>
          </tr>
          <?php $summaryCategory = $row['category']; ?>
          <?php endif; ?>
          <tr style="<?= h(export_row_style($index, $revision, $row['item'], $row['line'], $equipmentZebraGray)) ?>">
            <td class="line-cell"><?= h((string) ($index + 1)) ?></td>
            <td class="item-cell"><?= h($row['item']['name']) ?></td>
            <td class="description-cell"><?= h($row['description']) ?></td>
            <td class="total-cell">
              <?= h((string) ($row['line']['total_quantity'] ?? 0)) ?>
              <?php $delta = export_line_delta($revision, (int) $row['item']['id'], $row['line'], 'total_quantity'); ?>
              <?php if ($delta !== ''): ?><span class="delta <?= str_starts_with($delta, '-') ? 'delta-negative' : 'delta-positive' ?>"><?= h($delta) ?></span><?php endif; ?>
            </td>
            <td class="action-cell"><?= h(export_summary_action_label($row['line'])) ?></td>
            <td class="notes-cell">
              <?php
                $equipmentNote = export_equipment_note($row['item'], $row['line']);
              ?>
              <?= h($equipmentNote) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php else: ?>
          <tr>
            <td class="line-cell">1</td>
            <td colspan="5">No line-item changes recorded for this revision.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
      </div>
      <div class="footer">
        <span><?= h($globalFooterText) ?></span>
      </div>
    </section>
    <?php endif; ?>

    <?php $lineNumber = 1; ?>
    <?php foreach ($equipmentPages as $equipmentPageIndex => $equipmentPageRows): ?>
    <section class="page equipment-page">
      <div class="page-content">
      <div class="top-rule">
        <div class="page-header-bar">
          <div class="page-header-title">
            <strong><?= h($show['show_name']) ?></strong>
            <div class="page-header-subtitle"><?= h($coverTitle) ?></div>
          </div>
          <div class="page-header-meta">
            <div><strong>Revision</strong> <?= h($revisionCode) ?></div>
            <?php if ($showPageNumbers): ?><div><strong>Page</strong> <?= h((string) $pageNumbers['equipment'][$equipmentPageIndex]) ?> of <?= h((string) $totalPages) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <p class="page-heading"><?= h($labels['equipment_heading']) ?></p>
      <div class="equipment-table-wrap">
      <table class="word-table equipment-table">
        <colgroup>
          <col style="width: <?= h(number_format($equipmentMetrics['line_width'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['item'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['description'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['used'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['spare'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['total'], 3, '.', '')) ?>%;">
          <col style="width: <?= h(number_format($equipmentMetrics['columns']['notes'], 3, '.', '')) ?>%;">
        </colgroup>
        <tbody>
          <?php $pageCategory = null; ?>
          <?php foreach ($equipmentPageRows as $pageRowIndex => $row): ?>
          <?php if ($pageCategory !== $row['category']): ?>
          <?php if ($pageCategory !== null): ?>
          <tr class="category-gap-row"><td colspan="7"></td></tr>
          <?php endif; ?>
          <tr class="category-header-row">
            <td colspan="7"><?= h($row['category']) ?></td>
          </tr>
          <tr class="category-column-header-row">
            <td class="col-line">LINE</td>
            <td class="col-item">ITEM</td>
            <td class="col-description">DESCRIPTION</td>
            <td class="col-used">USED</td>
            <td class="col-spare">SPARE</td>
            <td class="col-total">TOTAL</td>
            <td class="col-notes">NOTES</td>
          </tr>
          <?php $pageCategory = $row['category']; ?>
          <?php endif; ?>
          <?php $delta = export_line_delta($revision, (int) $row['item']['id'], $row['line'], 'total_quantity'); ?>
          <tr style="<?= h(export_row_style($pageRowIndex, $revision, $row['item'], $row['line'], $equipmentZebraGray)) ?>">
            <td class="line-cell"><?= h((string) $lineNumber++) ?></td>
            <td class="item-cell"><?= h($row['item']['name']) ?></td>
            <td class="description-cell"><?= h($row['category']) ?></td>
            <td class="used-cell"><?= h((string) ($row['line']['rent_quantity'] ?? 0)) ?></td>
            <td class="spare-cell"><?= h((string) ($row['line']['spare_quantity'] ?? 0)) ?></td>
            <td class="total-cell">
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
        <span><?= h($globalFooterText) ?></span>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</body>
</html>
