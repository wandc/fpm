<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/timeline_entry.php
// Create/edit/delete timeline entries (optionally bound to a FOSC)

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

/** Where a non-closure (generic-parent) entry returns after save/delete. */
function timeline_generic_return_url(string $parentType, string $parentId): string
{
    if ($parentType === 'pop' && $parentId !== '') {
        return 'index.php?page=pop_detail&pop_id=' . (int)$parentId;
    }
    return 'index.php?page=timeline';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/upload.helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/media.helper.php';
// timeline.helper.php is only needed if you later want to show a preview list here
// require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/timeline.helper.php';

// ------------ Inputs / mode detection ------------

$foscId  = isset($_GET['fosc_id']) ? (int)$_GET['fosc_id'] : 0;
$entryId = isset($_GET['id'])      ? (int)$_GET['id']      : 0;
$mode    = $entryId > 0 ? 'edit' : 'create';

$entry = null;
if ($entryId > 0) {
    $entry = upload_fetch_entry($pdo, $entryId);
    if (!$entry) {
        http_response_code(404);
        echo "Timeline entry not found (id=$entryId).";
        exit;
    }
    // If entry is bound to a FOSC and no fosc_id was in GET, adopt it
    if (!empty($entry['fosc_id']) && $foscId === 0) {
        $foscId = (int)$entry['fosc_id'];
    }
}

$fosc = null;
$closureBound = false;
if ($foscId > 0) {
    $fosc = upload_fetch_fosc($pdo, $foscId);
    if (!$fosc) {
        http_response_code(404);
        echo "Closure not found (fosc_id=$foscId).";
        exit;
    }
    $closureBound = true;
}

// ------------ POST handling (save/delete) ------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF check is always first
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
        // Redirect back to same page so refresh is safe
        $redirParams = ['page' => 'timeline_entry'];
        if ($foscId)  $redirParams['fosc_id'] = $foscId;
        if ($entryId) $redirParams['id']      = $entryId;
        header('Location: index.php?' . http_build_query($redirParams));
        exit;
    }

    if ($action === 'delete') {
        // Thin wrapper around helper to keep the page dumb.
        $id = (int)($_POST['id'] ?? 0);
        // Capture the generic parent before deleting, so we can return to its page.
        $delEntry   = (!$closureBound && $id > 0) ? upload_fetch_entry($pdo, $id) : null;
        $res = upload_delete_timeline_entry($pdo, $id);
        if ($res['ok']) {
            $_SESSION['flash'] = ['msg' => "Deleted entry #{$id}.", 'ok' => true];
        } else {
            $_SESSION['flash'] = ['msg' => 'Delete failed: ' . $res['error'], 'ok' => false];
        }

        if ($closureBound) {
            header('Location: index.php?page=closure&fosc_id=' . $foscId);
        } else {
            header('Location: ' . timeline_generic_return_url(
                (string)($delEntry['parent_type'] ?? ''), (string)($delEntry['parent_id'] ?? '')));
        }
        exit;
    }

    if ($action === 'delete_media') {
        $id      = (int)($_POST['id'] ?? 0);        // timeline_entry_id
        $mediaId = (int)($_POST['media_id'] ?? 0);  // timeline_media_id

        if ($id <= 0 || $mediaId <= 0) {
            $_SESSION['flash'] = [
                'msg' => 'Bad media id.',
                'ok'  => false,
            ];
        } else {
            $res = timeline_delete_media($pdo, $mediaId);
            if ($res['ok']) {
                $_SESSION['flash'] = ['msg' => 'Photo removed.', 'ok' => true];
            } else {
                $_SESSION['flash'] = [
                    'msg' => 'Photo delete failed: ' . $res['error'],
                    'ok'  => false,
                ];
            }
        }

        // Go back to the same edit page (preserve closure context if any)
        $redirParams = [
            'page' => 'timeline_entry',
            'id'   => $id > 0 ? $id : $entryId,
        ];
        if ($foscId) {
            $redirParams['fosc_id'] = $foscId;
        }

        header('Location: index.php?' . http_build_query($redirParams));
        exit;
    }


    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $bodyText  = trim((string)($_POST['body_text'] ?? ''));

        // Generic parent info (only used when NOT bound to a closure)
        if ($closureBound) {
            $parentType = null;
            $parentId   = null;
            $lat        = '';
            $lon        = '';
        } else {
            $parentType = trim((string)($_POST['parent_type'] ?? ''));
            $parentId   = trim((string)($_POST['parent_id']   ?? ''));
            $lat        = trim((string)($_POST['lat'] ?? ''));
            $lon        = trim((string)($_POST['lon'] ?? ''));
        }

        // Files → upload_process_files (EXIF, GPS, etc.)
        $filesMeta = [
            'uploads'        => [],
            'errors'         => [],
            'entry_taken_at' => null,
            'entry_lat'      => null,
            'entry_lon'      => null,
        ];

        if (isset($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
            $filesMeta = upload_process_files($_FILES['images']);
        }

        $errors = $filesMeta['errors'] ?? [];

        // Build args for the helper
        $foscArg = null;
        if ($closureBound && $fosc) {
            $foscArg = [
                'fosc_id'    => (int)$fosc['fosc_id'],
                'splice_num' => $fosc['splice_num'],
            ];
        }

        $saveArgs = [
            'id'          => $id,
            'fosc'        => $foscArg,
            'parent_type' => $parentType,
            'parent_id'   => $parentId,
            'body_text'   => $bodyText,
            'lat'         => $lat,
            'lon'         => $lon,
            'exif_time'   => $filesMeta['entry_taken_at'],
            'exif_lat'    => $filesMeta['entry_lat'],
            'exif_lon'    => $filesMeta['entry_lon'],
            'uploads'     => $filesMeta['uploads'],
        ];

        $res = upload_save_timeline_entry($pdo, $saveArgs);
        $errors = array_merge($errors, $res['errors'] ?? []);

        if (!empty($errors)) {
            $_SESSION['flash'] = [
                'msg' => 'Save failed: ' . implode(' ', $errors),
                'ok'  => false,
            ];
            // Redirect to same page so we don’t resubmit on refresh
            $redirParams = ['page' => 'timeline_entry'];
            if ($foscId)  $redirParams['fosc_id'] = $foscId;
            if ($id)      $redirParams['id']      = $id;
            header('Location: index.php?' . http_build_query($redirParams));
            exit;
        }

        $entryId = (int)($res['entry_id'] ?? $id ?: 0);
        $_SESSION['flash'] = [
            'msg' => ($id > 0 ? 'Updated' : 'Created') . " entry #{$entryId}.",
            'ok'  => true,
        ];

        if ($closureBound) {
            header('Location: index.php?page=closure&fosc_id=' . $foscId);
        } else {
            header('Location: ' . timeline_generic_return_url((string)$parentType, (string)$parentId));
        }
        exit;
    }
}

// ------------ View model ------------

$mediaRows = [];
if ($entryId > 0) {
    $st = $pdo->prepare("
        SELECT timeline_media_id,
               file_name,
               mime_type,
               width,
               height,
               exif_created_at,
               created_at
          FROM app.timeline_media
         WHERE timeline_entry_id = :id
         ORDER BY COALESCE(exif_created_at, created_at), timeline_media_id
    ");
    $st->execute([':id' => $entryId]);
    $mediaRows = $st->fetchAll() ?: [];
}


$flash = function_exists('flash_get') ? flash_get() : ($_SESSION['flash'] ?? null);
if (isset($_SESSION['flash'])) unset($_SESSION['flash']);

$val_parent_type = $entry['parent_type'] ?? ($_GET['parent_type'] ?? '');
$val_parent_id   = $entry['parent_id']   ?? ($_GET['parent_id'] ?? '');
$val_body_text   = $entry['body_text']   ?? '';

$val_lat = $val_lon = '';
$pageTitle = $closureBound
    ? (($mode === 'edit' ? 'Edit' : 'Add') . ' Timeline Entry for ' . $fosc['splice_num'])
    : ($mode === 'edit' ? 'Edit Timeline Entry' : 'Create Timeline Entry');

$PAGE->setTitle($pageTitle);

// ------------ Render via Template ------------

$PAGE->setContentRenderer(function () use (
    $flash,
    $mode,
    $closureBound,
    $fosc,
    $CSRF,
    $val_parent_type,
    $val_parent_id,
    $val_body_text,
    $val_lat,
    $val_lon,
    $entryId,
    $foscId,
    $mediaRows
) {
    ob_start();
    ?>

    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0"><?= h($pageTitle = ($closureBound
          ? (($mode === 'edit' ? 'Edit' : 'Add') . ' Timeline Entry for ' . $fosc['splice_num'])
          : ($mode === 'edit' ? 'Edit Timeline Entry' : 'Create Timeline Entry'))) ?></h3>
      <div>
        <?php if ($closureBound): ?>
          <a class="btn btn-outline-secondary btn-sm"
             href="index.php?page=closure&fosc_id=<?= (int)$foscId ?>">
            Back to Closure
          </a>
        <?php else: ?>
          <a class="btn btn-outline-secondary btn-sm"
             href="<?= h(timeline_generic_return_url((string)$val_parent_type, (string)$val_parent_id)) ?>">
            <?= $val_parent_type === 'pop' ? 'Back to POP' : 'Back to Timeline' ?>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= !empty($flash['ok']) ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>

    <?php if ($closureBound): ?>
      <div class="alert alert-info">
        <div><strong>Bound to closure:</strong> <code><?= h($fosc['splice_num']) ?></code></div>
        <div><strong>Location:</strong>
          <code><?= h($fosc['lon']) ?>, <?= h($fosc['lat']) ?></code>
          <span class="text-muted">(photo GPS used if present)</span>
        </div>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <?php if ($mode === 'edit'): ?>
            <input type="hidden" name="id" value="<?= (int)$entryId ?>">
          <?php endif; ?>
          <?php if ($closureBound): ?>
            <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
          <?php endif; ?>

          <?php if (!$closureBound): ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Parent Type *</label>
                <input class="form-control"
                       type="text"
                       name="parent_type"
                       value="<?= h($val_parent_type) ?>"
                       required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Parent ID *</label>
                <input class="form-control"
                       type="text"
                       name="parent_id"
                       value="<?= h($val_parent_id) ?>"
                       required>
              </div>
            </div>
          <?php endif; ?>

          <div class="mt-3">
            <label class="form-label">Text (optional)</label>
            <textarea class="form-control"
                      name="body_text"
                      rows="4"
                      placeholder="What happened?"><?= h($val_body_text) ?></textarea>
          </div>

          <?php if (!$closureBound): ?>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Latitude (optional)</label>
                <input class="form-control"
                       type="text"
                       name="lat"
                       value="<?= h($val_lat) ?>"
                       placeholder="46.09">
              </div>
              <div class="col-md-6">
                <label class="form-label">Longitude (optional)</label>
                <input class="form-control"
                       type="text"
                       name="lon"
                       value="<?= h($val_lon) ?>"
                       placeholder="-64.78">
              </div>
            </div>
          <?php endif; ?>

          <div class="mt-3">
            <label class="form-label">Photos (optional)</label>
            <input class="form-control"
                   type="file"
                   name="images[]"
                   accept="image/*"
                   multiple>
            <div class="form-text">
              Max 20MB each. JPEG gives best EXIF/GPS metadata; PNG/WEBP/GIF/etc
              are also supported via XMP and fallbacks.
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <?= $mode === 'edit' ? 'Save Changes' : 'Create Entry' ?>
            </button>
            <?php if ($closureBound): ?>
              <a class="btn btn-outline-secondary"
                 href="index.php?page=closure&fosc_id=<?= (int)$foscId ?>">
                Cancel
              </a>
            <?php else: ?>
              <a class="btn btn-outline-secondary"
                 href="<?= h(timeline_generic_return_url((string)$val_parent_type, (string)$val_parent_id)) ?>">
                Cancel
              </a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($mode === 'edit' && !empty($mediaRows)): ?>
          <div class="mt-3">
            <label class="form-label d-block">Existing photos</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($mediaRows as $m): ?>
                <div class="text-center">
                  <div class="border rounded p-1 bg-light">
                    <img
                      src="/media.php?id=<?= (int)$m['timeline_media_id'] ?>"
                      alt="<?= h($m['file_name'] ?? 'photo') ?>"
                      style="max-width: 120px; max-height: 120px; object-fit: cover;"
                    >
                  </div>
                  <div class="small text-muted mt-1">
                    <?= h($m['file_name'] ?? '') ?>
                  </div>
                  <form method="post"
                        class="mt-1"
                        onsubmit="return confirm('Delete this photo?');">
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="id" value="<?= (int)$entryId ?>">
                    <input type="hidden" name="media_id" value="<?= (int)$m['timeline_media_id'] ?>">
                    <?php if ($closureBound): ?>
                      <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                      Remove
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($mode === 'edit'): ?>
          <hr>
          <form method="post"
                onsubmit="return confirm('Delete this entry and all its images?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="id"   value="<?= (int)$entryId ?>">
            <?php if ($closureBound): ?>
              <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
            <?php endif; ?>
            <button class="btn btn-outline-danger" type="submit">
              Delete Entry
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <?php
    return ob_get_clean();
});
