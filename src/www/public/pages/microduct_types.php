<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/microduct_types.php
// CRUD for app.microduct_type

use FPM\App\Template;

if (!isset($_SESSION)) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/** Format inner_ducts JSON array as human-readable string: "2× 10/14mm, 5× 3/5mm" */
function fmt_inner_ducts($raw) {
    $arr = is_string($raw) ? (json_decode($raw, true) ?? []) : (array)$raw;
    if (!$arr) return '—';
    $parts = [];
    foreach ($arr as $d) {
        $count = (int)($d['count'] ?? 1);
        $size  = (string)($d['size'] ?? '?');
        $parts[] = $count . '× ' . $size;
    }
    return implode(', ', $parts);
}

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = '/index.php?page=microduct_types';
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'Bad CSRF token.'];
        header("Location: $back"); exit;
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name       = trim((string)($_POST['name']       ?? ''));
            $way_count  = (int)($_POST['way_count']          ?? 1);
            $inner_raw  = trim((string)($_POST['inner_ducts'] ?? '[]'));
            $notes      = trim((string)($_POST['notes']       ?? ''));
            $active     = isset($_POST['active'])     ? true : false;
            $has_tracer   = isset($_POST['has_tracer']) ? true : false;
            $is_microduct = isset($_POST['is_microduct']) ? true : false;
            $sort_order = (int)($_POST['sort_order']         ?? 0);

            if ($name === '') throw new RuntimeException('Name is required.');
            if ($way_count < 1 || $way_count > 48) throw new RuntimeException('Way count must be 1–48.');

            $inner_decoded = json_decode($inner_raw, true);
            if (!is_array($inner_decoded)) throw new RuntimeException('Inner ducts must be valid JSON array (e.g. [{"count":2,"size":"10/14mm"}])');

            $st = $pdo->prepare("
                INSERT INTO app.microduct_type (name, way_count, inner_ducts, notes, active, has_tracer, is_microduct, sort_order)
                VALUES (:name, :wc, :id, :notes, :active, :tracer, :micro, :so)
            ");
            $st->execute([
                ':name'   => $name,
                ':wc'     => $way_count,
                ':id'     => json_encode($inner_decoded),
                ':notes'  => $notes,
                ':active' => $active     ? 'true' : 'false',
                ':tracer' => $has_tracer ? 'true' : 'false',
                ':micro'  => $is_microduct ? 'true' : 'false',
                ':so'     => $sort_order,
            ]);
            $_SESSION['flash'] = ['ok' => true, 'msg' => 'Added "' . $name . '".'];

        } elseif ($action === 'update') {
            $id         = (int)($_POST['microduct_type_id']  ?? 0);
            $name       = trim((string)($_POST['name']       ?? ''));
            $way_count  = (int)($_POST['way_count']          ?? 1);
            $inner_raw  = trim((string)($_POST['inner_ducts'] ?? '[]'));
            $notes      = trim((string)($_POST['notes']       ?? ''));
            $active     = isset($_POST['active'])     ? true : false;
            $has_tracer   = isset($_POST['has_tracer']) ? true : false;
            $is_microduct = isset($_POST['is_microduct']) ? true : false;
            $sort_order = (int)($_POST['sort_order']         ?? 0);

            if ($id <= 0 || $name === '') throw new RuntimeException('Invalid ID or name.');
            if ($way_count < 1 || $way_count > 48) throw new RuntimeException('Way count must be 1–48.');

            $inner_decoded = json_decode($inner_raw, true);
            if (!is_array($inner_decoded)) throw new RuntimeException('Inner ducts must be valid JSON array.');

            $st = $pdo->prepare("
                UPDATE app.microduct_type
                   SET name        = :name,
                       way_count   = :wc,
                       inner_ducts = :id,
                       notes       = :notes,
                       active      = :active,
                       has_tracer  = :tracer,
                       is_microduct = :micro,
                       sort_order  = :so
                 WHERE id = :rowid
            ");
            $st->execute([
                ':name'   => $name,
                ':wc'     => $way_count,
                ':id'     => json_encode($inner_decoded),
                ':notes'  => $notes,
                ':active' => $active     ? 'true' : 'false',
                ':tracer' => $has_tracer ? 'true' : 'false',
                ':micro'  => $is_microduct ? 'true' : 'false',
                ':so'     => $sort_order,
                ':rowid'  => $id,
            ]);
            $_SESSION['flash'] = ['ok' => true, 'msg' => "Updated #$id."];

        } elseif ($action === 'delete') {
            $id = (int)($_POST['microduct_type_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid ID.');

            // Guard against in-use types
            $check = $pdo->prepare("SELECT COUNT(*) FROM app.conduit WHERE microduct_type_id = :id");
            $check->execute([':id' => $id]);
            $inUse = (int)$check->fetchColumn();
            if ($inUse > 0) throw new RuntimeException("Cannot delete: $inUse conduit run(s) reference this type.");

            $st = $pdo->prepare("DELETE FROM app.microduct_type WHERE id = :id");
            $st->execute([':id' => $id]);
            $_SESSION['flash'] = ['ok' => true, 'msg' => "Deleted #$id."];

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()];
    }
    header("Location: $back"); exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────

$rows = $pdo->query("
    SELECT mt.id, mt.name, mt.way_count, mt.inner_ducts, mt.notes, mt.active, mt.has_tracer, mt.is_microduct, mt.sort_order,
           (SELECT COUNT(*) FROM app.conduit c WHERE c.microduct_type_id = mt.id) AS conduit_count
      FROM app.microduct_type mt
     ORDER BY mt.sort_order, mt.id
")->fetchAll(PDO::FETCH_ASSOC);

// ── Renderers ─────────────────────────────────────────────────────────────────

$render_css = function () {
    return '
.table td,.table th { padding:.4rem .55rem }
.inner-ducts-input { font-family: monospace; font-size: 12px }
.composition-cell { font-size: 12px; color: #555 }
';
};

$render_body = function () use ($rows, $CSRF) {
    ob_start();
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    ?>
    <div class="container my-3">
      <h1 class="h4 mb-1">Microduct Types</h1>
      <p class="text-muted small mb-3">Named product catalog for buried conduit runs. Inner ducts are entered as a JSON array, e.g. <code>[{"count":2,"size":"10/14mm"},{"count":5,"size":"3/5mm"}]</code></p>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>"><?= h($flash['msg']) ?></div>
      <?php endif; ?>

      <!-- Create form -->
      <form method="post" class="card card-body mb-4">
        <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="create">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Name</label>
            <input name="name" class="form-control form-control-sm"
                   placeholder='e.g. 7-way: 2× 10/14mm + 5× 3/5mm' required>
          </div>
          <div class="col-md-1">
            <label class="form-label small fw-semibold mb-1">Ways</label>
            <input name="way_count" type="number" class="form-control form-control-sm"
                   min="1" max="48" value="1" required>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Inner ducts (JSON)</label>
            <input name="inner_ducts" class="form-control form-control-sm inner-ducts-input"
                   placeholder='[{"count":1,"size":"10/14mm"}]' value="[]">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">Notes</label>
            <input name="notes" class="form-control form-control-sm" placeholder="optional">
          </div>
          <div class="col-md-1" style="min-width:60px">
            <label class="form-label small fw-semibold mb-1">Sort</label>
            <input name="sort_order" type="number" class="form-control form-control-sm" value="0">
          </div>
          <div class="col-auto d-flex align-items-end gap-3">
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="active" id="newActive" checked>
              <label class="form-check-label small" for="newActive">Active</label>
            </div>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="has_tracer" id="newTracer">
              <label class="form-check-label small" for="newTracer">Tracer wire</label>
            </div>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="is_microduct" id="newMicro">
              <label class="form-check-label small" for="newMicro"
                     title="Fibre is blown/jetted into this duct. Leave off for rigid conduit and HDPE, which is pulled — this drives contractor blow-vs-pull pricing.">Blown (microduct)</label>
            </div>
            <button class="btn btn-primary btn-sm mb-1">Add</button>
          </div>
        </div>
      </form>

      <!-- List / Edit -->
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:50px">ID</th>
              <th>Name</th>
              <th style="width:55px" class="text-center">Ways</th>
              <th>Composition</th>
              <th>Notes</th>
              <th style="width:60px" class="text-center">Tracer</th>
              <th style="width:55px" class="text-center">Active</th>
              <th style="width:50px" class="text-center">Sort</th>
              <th style="width:100px" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php $used = (int)$r['conduit_count']; ?>
              <tr>
                <td class="text-muted">#<?= (int)$r['id'] ?></td>
                <td colspan="7">
                  <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="hidden" name="csrf"              value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action"            value="update">
                    <input type="hidden" name="microduct_type_id" value="<?= (int)$r['id'] ?>">
                    <input name="name"        class="form-control form-control-sm" style="min-width:180px"
                           value="<?= h($r['name']) ?>" required>
                    <input name="way_count"   type="number" class="form-control form-control-sm"
                           style="width:60px" min="1" max="48" value="<?= (int)$r['way_count'] ?>" required>
                    <input name="inner_ducts" class="form-control form-control-sm inner-ducts-input"
                           style="min-width:200px" value="<?= h($r['inner_ducts']) ?>">
                    <input name="notes"       class="form-control form-control-sm" style="width:120px"
                           value="<?= h($r['notes']) ?>" placeholder="notes">
                    <input name="sort_order"  type="number" class="form-control form-control-sm"
                           style="width:60px" value="<?= (int)$r['sort_order'] ?>">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="has_tracer"
                             id="tracer_<?= (int)$r['id'] ?>" <?= $r['has_tracer'] === 't' || $r['has_tracer'] === true ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="tracer_<?= (int)$r['id'] ?>">Tracer</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_microduct"
                             id="micro_<?= (int)$r['id'] ?>" <?= $r['is_microduct'] === 't' || $r['is_microduct'] === true ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="micro_<?= (int)$r['id'] ?>"
                             title="Fibre is blown into this duct. Off = pulled (rigid conduit, HDPE).">Blown</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="active"
                             id="active_<?= (int)$r['id'] ?>" <?= $r['active'] === 't' || $r['active'] === true ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="active_<?= (int)$r['id'] ?>">Active</label>
                    </div>
                    <button class="btn btn-sm btn-primary">Save</button>
                  </form>
                </td>
                <td class="text-end">
                  <?php if ($used > 0): ?>
                    <button class="btn btn-sm btn-outline-danger" disabled
                            title="In use by <?= $used ?> conduit run<?= $used !== 1 ? 's' : '' ?>">
                      Delete
                    </button>
                  <?php else: ?>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Delete #<?= (int)$r['id'] ?>?');">
                      <input type="hidden" name="csrf"              value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action"            value="delete">
                      <input type="hidden" name="microduct_type_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-muted">No types yet. Add one above.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    return ob_get_clean();
};

// ── Template ──────────────────────────────────────────────────────────────────

if (isset($PAGE) && $PAGE instanceof Template) {
    $PAGE->setTitle('Microduct Types');
    $PAGE->setInlineCssRenderer($render_css);
    $PAGE->setContentRenderer($render_body);
} else {
    ?><!doctype html><html><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <style><?= $render_css() ?></style>
    </head><body><?= $render_body() ?></body></html><?php
}
