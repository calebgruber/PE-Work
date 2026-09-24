<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

if (!schema_ready() || !auth_tables_ready()) {
    header('Location: ' . url_for('setup'));
    exit;
}

require_login();
$currentUser = current_user();

function revision_json_not_found(bool $includeOk = false): void
{
    http_response_code(404);
    header('Content-Type: application/json');
    $payload = ['warnings' => [['type' => 'rule', 'message' => 'Revision not found for this show.']]];
    if ($includeOk) {
        $payload['ok'] = false;
    }
    echo json_encode($payload);
    exit;
}

function render_show_form(array $show, array $owners, array $currentUser): void
{
    $isAdmin = is_admin($currentUser);
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
            <label for="concentration">Domain</label>
            <select class="form-control" id="concentration" name="concentration" required>
              <?php foreach (user_concentrations() as $value => $label): ?>
              <option value="<?= h($value) ?>"<?= show_concentration($show) === $value ? ' selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="owner_user_id">Show Owner</label>
            <?php if ($isAdmin): ?>
            <select class="form-control" id="owner_user_id" name="owner_user_id" required>
              <option value="">Choose a user</option>
              <?php foreach ($owners as $owner): ?>
              <option value="<?= h((string) $owner['id']) ?>"<?= (int) ($show['owner_user_id'] ?? 0) === (int) $owner['id'] ? ' selected' : '' ?>>
                <?= h($owner['display_name']) ?> · <?= h(concentration_label($owner['concentration'] ?? 'lighting')) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" name="owner_user_id" value="<?= h((string) ((int) ($show['owner_user_id'] ?? 0) ?: (int) ($currentUser['id'] ?? 0))) ?>">
            <input class="form-control" id="owner_user_id" value="<?= h((string) ($currentUser['display_name'] ?? 'Assigned to you')) ?>" readonly>
            <?php endif; ?>
          </div>
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

function render_show_paperwork_form(array $layout): void
{
    ?>
      <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_show_layout">

        <section class="settings-layout-section">
          <div class="section-label">General Paperwork Settings</div>
          <div class="helper-text settings-layout-section-copy">These values apply only to this show and override the admin defaults for exports.</div>
          <div class="card-grid">
            <div class="form-group">
              <label for="header_text">Header Text</label>
              <input class="form-control" id="header_text" name="header_text" value="<?= h($layout['layout.header_text'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="organization_text">Top Right Header Text</label>
              <input class="form-control" id="organization_text" name="organization_text" value="<?= h($layout['layout.organization_text'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="footer_text">Footer Text</label>
              <input class="form-control" id="footer_text" name="footer_text" value="<?= h($layout['layout.footer_text'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group" style="margin-top:1rem;">
            <label for="export_notes">Important Notes</label>
            <textarea class="form-control" id="export_notes" name="export_notes" rows="8"><?= h($layout['layout.export_notes'] ?? '') ?></textarea>
          </div>
          <div class="pill-row" style="margin-top:1rem;">
            <label class="tab"><input type="hidden" name="show_image" value="0"><input type="checkbox" name="show_image" value="1" <?= ($layout['layout.show_image'] ?? '1') === '1' ? 'checked' : '' ?>> Show image on exports</label>
            <label class="tab"><input type="hidden" name="cover_show_title" value="0"><input type="checkbox" name="cover_show_title" value="1" <?= ($layout['layout.cover_show_title'] ?? '1') === '1' ? 'checked' : '' ?>> Show title above cover image</label>
            <label class="tab"><input type="hidden" name="show_page_numbers" value="0"><input type="checkbox" name="show_page_numbers" value="1" <?= ($layout['layout.show_page_numbers'] ?? '1') === '1' ? 'checked' : '' ?>> Page X of X</label>
            <label class="tab"><input type="hidden" name="show_revision_summary" value="0"><input type="checkbox" name="show_revision_summary" value="1" <?= ($layout['layout.show_revision_summary'] ?? '1') === '1' ? 'checked' : '' ?>> Revision summary block</label>
          </div>
        </section>

        <section class="settings-layout-section">
          <div class="section-label">Cover Page Settings</div>
          <div class="helper-text settings-layout-section-copy">Cover page controls here are saved per show.</div>
          <div class="card-grid">
            <div class="form-group">
              <label for="cover_title_revision_spacing">Cover Title To Revision Spacing (in)</label>
              <input class="form-control" id="cover_title_revision_spacing" type="text" inputmode="decimal" name="cover_title_revision_spacing" value="<?= h($layout['layout.cover_title_revision_spacing'] ?? '0.52') ?>">
            </div>
            <div class="form-group">
              <label for="cover_notes_spacing">Cards To Notes Spacing (in)</label>
              <input class="form-control" id="cover_notes_spacing" type="text" inputmode="decimal" name="cover_notes_spacing" value="<?= h($layout['layout.cover_notes_spacing'] ?? '0.9') ?>">
            </div>
            <div class="form-group">
              <label for="cover_footer_logo_url">Cover Footer Logo Path</label>
              <input class="form-control" id="cover_footer_logo_url" name="cover_footer_logo_url" placeholder="images/my-logo.png" value="<?= h($layout['layout.cover_footer_logo_url'] ?? '') ?>">
              <div class="helper-text">Optional local path for a centered logo at the bottom of the cover page.</div>
            </div>
            <div class="form-group">
              <label for="cover_prepared_by_name">Prepared By Name</label>
              <input class="form-control" id="cover_prepared_by_name" name="cover_prepared_by_name" placeholder="Your Name" value="<?= h($layout['layout.cover_prepared_by_name'] ?? '') ?>">
            </div>
          </div>
        </section>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <span class="material-symbols-outlined">save</span>
            Save Paperwork Settings
          </button>
        </div>
      </form>
    <?php
}

function revision_return_tab(?array $revision): string
{
    return !empty($revision['is_initial']) ? 'orders' : 'revisions';
}

function revision_stage_label(array $revision): string
{
    return !empty($revision['is_initial']) ? 'Initial Order' : 'Revision';
}

function render_revision_history_table(int $showId, array $revisions, ?int $activeRevisionId = null, bool $allowDelete = false): void
{
    if (!$revisions) {
        return;
    }
    ?>
    <div class="table-wrap revision-history-wrap">
      <table class="revision-history-table">
        <thead>
          <tr>
            <th>Revision</th>
            <th>Type</th>
            <th>Date</th>
            <th>Summary</th>
            <th>Rent</th>
            <th>Spares</th>
            <th>Total</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($revisions as $revision): ?>
          <?php
            $revisionId = (int) $revision['id'];
            $revisionTotals = revision_totals($revisionId);
            $returnTab = revision_return_tab($revision);
            $isCurrent = $activeRevisionId !== null && $activeRevisionId === $revisionId;
          ?>
          <tr class="revision-history-row<?= $isCurrent ? ' revision-history-row-current' : '' ?>">
            <td>
              <div class="revision-history-code">
                <strong><?= h(revision_display_code($revision)) ?></strong>
                <?php if ($isCurrent): ?><span class="badge badge-info">Open</span><?php endif; ?>
              </div>
            </td>
            <td><?= ui_badge(revision_stage_label($revision), !empty($revision['is_initial']) ? 'neutral' : 'info') ?></td>
            <td><?= h($revision['revision_date'] ?: '—') ?></td>
            <td>
              <div class="revision-history-summary">
                <?= h(trim((string) ($revision['summary_note'] ?? '')) ?: 'No summary note yet.') ?>
              </div>
            </td>
            <td><?= h((string) $revisionTotals['rent_total']) ?></td>
            <td><?= h((string) $revisionTotals['spare_total']) ?></td>
            <td><strong><?= h((string) $revisionTotals['overall_total']) ?></strong></td>
            <td>
              <div class="revision-history-actions">
                <a class="btn btn-primary btn-sm" href="<?= h(url_for('show?show_id=' . $showId . '&mode=edit&tab=' . $returnTab . '&revision_id=' . $revisionId)) ?>">
                  <span class="material-symbols-outlined">edit</span>
                  <?= !empty($revision['is_initial']) ? 'Edit Order' : 'Edit Revision' ?>
                </a>
                <a class="btn btn-ghost btn-sm" href="<?= h(url_for('export?show_id=' . $showId . '&revision_id=' . $revisionId)) ?>">
                  <span class="material-symbols-outlined">print</span>
                  Export
                </a>
                <?php if ($allowDelete && empty($revision['is_initial'])): ?>
                <form method="post">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="delete_revision">
                  <input type="hidden" name="revision_id" value="<?= h((string) $revisionId) ?>">
                  <button type="submit" class="btn btn-danger btn-sm" data-confirm-code="DELETE REVISION" data-confirm="Type DELETE REVISION to permanently remove this revision.">
                    <span class="material-symbols-outlined">delete</span>
                    Delete
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function is_ajax_request(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function show_has_initial_revision(int $showId): bool
{
    return find_initial_revision($showId) !== null;
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
if (!in_array($tab, ['info', 'paperwork', 'orders', 'revisions'], true)) {
    $tab = 'info';
}
$mode = ($_GET['mode'] ?? '') === 'edit' ? 'edit' : 'view';
$revisionOverrideItems = [];
$show = $showId ? find_show_unrestricted($showId) : blank_show();
if ($showId && (!$show || !can_access_show($show, $currentUser))) {
    http_response_code(404);
    exit('Show not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Your session expired. Refresh the page and try again.');
        header('Location: ' . url_for($showId ? ('show?show_id=' . $showId . '&tab=' . $tab) : 'show'));
        exit;
    }
    if ($showId && (!$show || !can_access_show($show, $currentUser))) {
        http_response_code(404);
        exit('Show not found.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_show') {
        $result = save_show_record($_POST, $showId ?: null);
        if ($result['errors']) {
            foreach ($result['errors'] as $error) {
                flash('danger', $error);
            }
            $show = array_merge($showId ? $show : blank_show(), $result['show']);
        } else {
            $show = $result['show'];
            $showId = (int) $show['id'];
            flash('success', 'Show information saved.');
            header('Location: ' . url_for('show?show_id=' . $showId . '&tab=info'));
            exit;
        }
    }

    if ($action === 'save_show_layout' && $showId) {
        $showLayoutInput = [];
        foreach (show_export_layout_override_keys() as $layoutKey) {
            $inputName = substr($layoutKey, strlen('layout.'));
            if (array_key_exists($inputName, $_POST)) {
                $showLayoutInput[$inputName] = $_POST[$inputName];
            }
        }
        save_show_export_layout($showId, $showLayoutInput);
        flash('success', 'Show paperwork settings saved.');
        header('Location: ' . url_for('show?show_id=' . $showId . '&tab=paperwork'));
        exit;
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

    if ($action === 'delete_revision' && $showId) {
        if (!$show || !can_access_show($show, $currentUser) || !is_admin($currentUser)) {
            http_response_code(404);
            exit('Show not found.');
        }
        $revision = find_revision((int) ($_POST['revision_id'] ?? 0));
        $revisionShow = $revision ? find_show((int) $revision['show_id']) : null;
        if (
            !$revision
            || (int) $revision['show_id'] !== (int) $showId
            || !$revisionShow
            || !can_access_show($revisionShow, $currentUser)
        ) {
            flash('warning', 'Revision not found for this show.');
        } else {
            $result = delete_show_revision((int) $revision['id']);
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
        }
        header('Location: ' . url_for('show?show_id=' . $showId . '&tab=revisions'));
        exit;
    }

    if ($action === 'validate_revision' && !empty($_POST['revision_id'])) {
        if (!$show || !can_access_show($show, $currentUser)) {
            revision_json_not_found();
        }
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            revision_json_not_found();
        }

        header('Content-Type: application/json');
        echo json_encode([
            'warnings' => revision_validation_warnings(revision_input_snapshot($revisionId, revision_request_items($_POST)), show_concentration($show)),
        ]);
        exit;
    }

    if ($action === 'autosave_revision' && !empty($_POST['revision_id'])) {
        if (!$show || !can_access_show($show, $currentUser)) {
            revision_json_not_found(true);
        }
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            revision_json_not_found(true);
        }

        $revisionOverrideItems = revision_request_items($_POST);
        save_revision_lines($revisionId, $revisionOverrideItems);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'warnings' => revision_validation_warnings(revision_input_snapshot($revisionId), show_concentration($show)),
            'totals' => revision_totals($revisionId),
        ]);
        exit;
    }

    if ($action === 'save_revision' && !empty($_POST['revision_id'])) {
        if (!$show || !can_access_show($show, $currentUser)) {
            if (is_ajax_request()) {
                revision_json_not_found(true);
            }
            http_response_code(404);
            exit('Revision not found for this show.');
        }
        $revisionId = (int) $_POST['revision_id'];
        $revision = find_revision($revisionId);
        if (!$revision || (int) $revision['show_id'] !== (int) $showId) {
            if (is_ajax_request()) {
                revision_json_not_found(true);
            }
            http_response_code(404);
            exit('Revision not found for this show.');
        }

        $revisionOverrideItems = revision_request_items($_POST);
        $validationWarnings = revision_validation_warnings(revision_input_snapshot($revisionId, $revisionOverrideItems), show_concentration($show));
        try {
            save_revision_lines($revisionId, $revisionOverrideItems);
            $savedTotals = revision_totals($revisionId);
        } catch (Throwable $e) {
            if (is_ajax_request()) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'warnings' => [['type' => 'rule', 'message' => 'Unable to save this revision right now.']],
                ]);
                exit;
            }
            throw $e;
        }
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'message' => 'Order changes saved.',
                'warnings' => $validationWarnings,
                'totals' => $savedTotals,
            ]);
            exit;
        }

        flash('success', 'Order changes saved.');
        foreach ($validationWarnings as $warning) {
            flash('warning', $warning['message']);
        }
        $returnTab = revision_return_tab($revision);
        header('Location: ' . url_for('show?show_id=' . $showId . '&mode=edit&tab=' . $returnTab . '&revision_id=' . $revisionId));
        exit;
    }
}

$revisions = $showId ? list_revisions($showId) : [];
$initialRevision = null;
foreach ($revisions as $revisionRow) {
    if ((int) ($revisionRow['is_initial'] ?? 0) === 1) {
        $initialRevision = $revisionRow;
    }
}

$latestRevision = $showId ? find_latest_revision($showId) : null;
$currentRevision = null;
$showOwners = show_owner_options();
if ($mode === 'edit' && !empty($_GET['revision_id'])) {
    $currentRevision = find_revision((int) $_GET['revision_id']);
    $revisionShow = $currentRevision ? find_show_unrestricted((int) ($currentRevision['show_id'] ?? 0)) : null;
    if (
        !$currentRevision
        || !$revisionShow
        || !can_access_show($revisionShow, $currentUser)
        || (int) $currentRevision['show_id'] !== (int) $showId
    ) {
        http_response_code(404);
        exit('Revision not found for this show.');
    }
}

$catalog = [];
$totals = ['rent_total' => 0, 'spare_total' => 0, 'overall_total' => 0];
$showLayout = $showId ? export_layout_settings($showId) : export_layout_settings();
if ($mode === 'edit' && $currentRevision) {
    $normalizedRevisionOverrideItems = $revisionOverrideItems ? normalize_revision_lines_input($revisionOverrideItems) : [];
    $catalog = catalog_for_revision((int) $currentRevision['id'], $normalizedRevisionOverrideItems);
    $totals = revision_totals((int) $currentRevision['id']);
    if ($normalizedRevisionOverrideItems) {
        $totals = ['rent_total' => 0, 'spare_total' => 0, 'overall_total' => 0];
        foreach ($normalizedRevisionOverrideItems as $line) {
            $totals['rent_total'] += (int) ($line['rent_quantity'] ?? 0);
            $totals['spare_total'] += (int) ($line['spare_quantity'] ?? 0);
            $totals['overall_total'] += (int) ($line['total_quantity'] ?? 0);
        }
    }
}

ui_head('Show Builder', '', APP_NAME, 'theater_comedy');
ui_sidebar(APP_NAME, 'theater_comedy', nav_items('shows'));

$actions = '';
if ($showId && $latestRevision) {
    $actions .= '<a class="btn btn-primary" href="' . h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $latestRevision['id'])) . '"><span class="material-symbols-outlined">print</span>Exports</a>';
}

if ($mode === 'edit' && $showId && $currentRevision) {
    $backTab = revision_return_tab($currentRevision);
    $actions = '<a class="btn btn-ghost" href="' . h(url_for('show?show_id=' . $showId . '&tab=' . $backTab)) . '"><span class="material-symbols-outlined">arrow_back</span>Back</a>';
    $actions .= '<a class="btn btn-primary" href="' . h(url_for('export?show_id=' . $showId . '&revision_id=' . (int) $currentRevision['id'])) . '"><span class="material-symbols-outlined">print</span>Exports</a>';
    ui_page_header(($show['show_name'] ?: 'Show Workspace') . ' · ' . revision_display_code($currentRevision), 'Edit line items in a focused workspace. Search, review warnings, and save anytime while keeping this revision editable.', $actions);
} else {
    ui_page_header($showId ? ($show['show_name'] ?: 'Show Workspace') : 'Create Show', 'Required contacts are enforced; dates, addresses, and image are optional.', $actions);
}
?>
<div class="page-body">
  <?php ui_flash(); ?>

  <?php if (!$showId): ?>
    <?php ui_card_open('theater_comedy', 'Create Show'); ?>
      <?php render_show_form($show, $showOwners, $currentUser); ?>
    <?php ui_card_close(); ?>
  <?php elseif ($mode === 'edit' && $currentRevision): ?>
    <?php ui_card_open($currentRevision['is_initial'] ? 'checklist' : 'history', $currentRevision['is_initial'] ? 'Edit Initial Order' : 'Edit ' . revision_display_code($currentRevision)); ?>
      <div class="show-summary">
        <div class="summary-block"><strong>Revision</strong><?= h(revision_display_code($currentRevision)) ?></div>
        <div class="summary-block"><strong>Type</strong><?= h(revision_stage_label($currentRevision)) ?></div>
        <div class="summary-block"><strong>Date</strong><?= h($currentRevision['revision_date']) ?></div>
        <div class="summary-block"><strong>Rent Total</strong><span data-revision-rent-total><?= h((string) $totals['rent_total']) ?></span></div>
        <div class="summary-block"><strong>Spare Total</strong><span data-revision-spare-total><?= h((string) $totals['spare_total']) ?></span></div>
        <div class="summary-block"><strong>Combined Total</strong><span data-revision-overall-total><?= h((string) $totals['overall_total']) ?></span></div>
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
            <div class="stack" style="gap:0.35rem;">
              <span>Warnings update live as you edit. Saving still keeps your order changes while flagging stock and rule follow-up.</span>
              <div class="revision-autosave-status" data-revision-autosave-status data-state="idle">Autosave ready.</div>
            </div>
          </div>
        </div>

        <div class="revision-alerts hidden" data-revision-warnings-wrap>
          <strong>Warnings</strong>
          <div class="muted">Review these before sending paperwork, but your edits will still save.</div>
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
                        <div class="revision-spacer-copy">&nbsp;</div>
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
          <button type="submit" class="btn btn-primary" data-revision-submit>
            <span class="material-symbols-outlined">save</span>
            Save Changes
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
      <a class="tab<?= $tab === 'paperwork' ? ' active' : '' ?>"<?= $tab === 'paperwork' ? ' aria-current="page"' : '' ?> href="<?= h(url_for('show?show_id=' . $showId . '&tab=paperwork')) ?>">
        <span class="material-symbols-outlined">description</span>
        Paperwork
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
        <?php render_show_form($show, $showOwners, $currentUser); ?>
      <?php ui_card_close(); ?>
    <?php elseif ($tab === 'paperwork'): ?>
      <?php ui_card_open('description', 'Show Paperwork Settings'); ?>
        <?php render_show_paperwork_form($showLayout); ?>
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
            <div class="summary-block"><strong>Order</strong><?= h(revision_display_code($initialRevision)) ?></div>
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

          <div class="helper-text" style="margin-bottom:1rem;">All revisions stay in one table so you can scan the full sequence from the initial order through the latest revision.</div>
          <?php render_revision_history_table((int) $showId, $revisions, null, is_admin($currentUser)); ?>
        <?php endif; ?>
      <?php ui_card_close(); ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php ui_end(); ?>
