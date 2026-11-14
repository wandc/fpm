<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/cable_spans.php
// CRUD for app.cable_span (non-geometry fields)
// Geometry is edited in QGIS; this page manages IDs & notes.

/** @var \FPM\App\Template $PAGE */

// If $pdo is not created in bootstrap yet, uncomment this:
// require __DIR__ . '/../config.php';

global $pdo, $CSRF;

// --- Pull select options ---

$cables = $pdo->query("
  SELECT c.cable_id, c.name, c.fibre_count
    FROM app.cable c
ORDER BY c.name ASC, c.cable_id ASC
")->fetchAll();

$fosc = $pdo->query("
  SELECT fosc_id, splice_num
    FROM app.fosc
ORDER BY splice_num ASC, fosc_id ASC
")->fetchAll();

// --- POST actions ---

if (is_post()) {
    $action = $_POST['action'] ?? '';

    // CSRF check
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=cable_spans');
        exit;
    }

    try {
        if ($action === 'create') {
            $cable_id  = (int)($_POST['cable_id'] ?? 0);
            $from_fosc = (int)($_POST['from_fosc_id'] ?? 0);
            $to_fosc   = (int)($_POST['to_fosc_id'] ?? 0);
            $notes     = trim($_POST['notes'] ?? '');

            if ($cable_id <= 0 || $from_fosc <= 0 || $to_fosc <= 0) {
                throw new RuntimeException('Cable, From FOSC and To FOSC are required.');
            }
            if ($from_fosc === $to_fosc) {
                throw new RuntimeException('From and To FOSC must differ.');
            }

            // Geometry not set here; QGIS handles geom_middle. DB triggers build geom_snap/length_m.
            $stmt = $pdo->prepare("
                INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, notes)
                VALUES (:cid, :fid, :tid, :n)
            ");
            $stmt->execute([
                ':cid' => $cable_id,
                ':fid' => $from_fosc,
                ':tid' => $to_fosc,
                ':n'   => $notes !== '' ? $notes : null,
            ]);

            flash_set('Cable span created.');
        } elseif ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $cable_id  = (int)($_POST['cable_id'] ?? 0);
            $from_fosc = (int)($_POST['from_fosc_id'] ?? 0);
            $to_fosc   = (int)($_POST['to_fosc_id'] ?? 0);
            $notes     = trim($_POST['notes'] ?? '');
            $serial    = trim($_POST['cable_serial'] ?? '');

            if ($id <= 0 || $cable_id <= 0 || $from_fosc <= 0 || $to_fosc <= 0) {
                throw new RuntimeException('Invalid input.');
            }
            if ($from_fosc === $to_fosc) {
                throw new RuntimeException('From and To FOSC must differ.');
            }

            $stmt = $pdo->prepare("
                UPDATE app.cable_span
                   SET cable_id     = :cid,
                       from_fosc_id = :fid,
                       to_fosc_id   = :tid,
                       notes        = :n,
                       cable_serial = :sn,
                       updated_at   = now()
                 WHERE cable_span_id = :id
            ");
            $stmt->execute([
                ':cid' => $cable_id,
                ':fid' => $from_fosc,
                ':tid' => $to_fosc,
                ':n'   => $notes !== '' ? $notes : null,
                ':sn'  => $serial !== '' ? $serial : null,
                ':id'  => $id,
            ]);

            flash_set('Cable span updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Bad id.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM app.cable_span
                 WHERE cable_span_id = :id
            ");
            $stmt->execute([':id' => $id]);

            flash_set('Cable span deleted.');
        }
    } catch (Throwable $e) {
        flash_set('Error: ' . $e->getMessage(), false);
    }

    header('Location: index.php?page=cable_spans');
    exit;
}

// --- List spans with labels ---

$rows = $pdo->query("
  SELECT
    s.cable_span_id,
    s.cable_id,
    c.name AS cable_name,
    s.from_fosc_id,
    f1.splice_num AS from_label,
    s.to_fosc_id,
    f2.splice_num AS to_label,
    s.length_m,
    s.created_at,
    s.updated_at,
    s.notes,
    s.cable_serial,
    COALESCE((
        SELECT string_agg(t.name, ',' ORDER BY t.name)
          FROM app.cable_span_tag cst
          JOIN app.span_tag t ON t.span_tag_id = cst.span_tag_id
         WHERE cst.cable_span_id = s.cable_span_id
    ), '') AS tags
  FROM app.cable_span s
  JOIN app.cable c ON c.cable_id = s.cable_id
  JOIN app.fosc  f1 ON f1.fosc_id = s.from_fosc_id
  JOIN app.fosc  f2 ON f2.fosc_id = s.to_fosc_id
  ORDER BY s.updated_at DESC NULLS LAST, s.created_at DESC
")->fetchAll();

$flash = flash_get();

// --- Plug into Template ---

$PAGE->setTitle('Cable Spans');

$PAGE->setContentRenderer(function () use ($rows, $cables, $fosc, $CSRF, $flash) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Cable Spans</h3>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreate">
        New Span
      </button>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Cable</th>
            <th>Serial</th>
            <th>Tags</th>
            <th>From FOSC</th>
            <th>To FOSC</th>
            <th class="text-end">Length (m)</th>
            <th>Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['cable_span_id'] ?></td>
              <td><?= h($r['cable_name']) ?></td>
              <td><?= h($r['cable_serial'] ?? '') ?></td>
              <td>
                <?php foreach (array_filter(explode(',', $r['tags'])) as $tag): ?>
                  <span class="badge bg-secondary"><?= h($tag) ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <code><?= h($r['from_label']) ?></code>
                (<?= (int)$r['from_fosc_id'] ?>)
              </td>
              <td>
                <code><?= h($r['to_label']) ?></code>
                (<?= (int)$r['to_fosc_id'] ?>)
              </td>
              <td class="text-end">
                <?= $r['length_m'] !== null ? number_format((float)$r['length_m'], 1) : '—' ?>
              </td>
              <td class="text-nowrap"><?= h($r['updated_at']) ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#modalEdit"
                        data-id="<?= (int)$r['cable_span_id'] ?>"
                        data-cable="<?= (int)$r['cable_id'] ?>"
                        data-from="<?= (int)$r['from_fosc_id'] ?>"
                        data-to="<?= (int)$r['to_fosc_id'] ?>"
                        data-length="<?= $r['length_m'] !== null ? number_format((float)$r['length_m'], 1) : '' ?>"
                        data-serial="<?= h($r['cable_serial'] ?? '') ?>"
                        data-notes="<?= h($r['notes'] ?? '') ?>">
                  Edit
                </button>
                <form class="d-inline" method="post"
                      onsubmit="return confirm('Delete this cable span?');">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['cable_span_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Edit Modal: one for the whole table, filled from the clicked row's data-* attributes -->
    <div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="editId">
          <div class="modal-header">
            <h5 class="modal-title">Edit Cable Span #<span id="editIdLabel"></span></h5>
            <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Cable *</label>
                <select class="form-select" name="cable_id" id="editCable" required>
                  <option value="">Select…</option>
                  <?php foreach ($cables as $c): ?>
                    <option value="<?= (int)$c['cable_id'] ?>"><?= h($c['name']) ?><?= $c['fibre_count'] ? ' — ' . h($c['fibre_count']) : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">From FOSC *</label>
                <select class="form-select" name="from_fosc_id" id="editFrom" required>
                  <option value="">Select…</option>
                  <?php foreach ($fosc as $f): ?>
                    <option value="<?= (int)$f['fosc_id'] ?>"><?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">To FOSC *</label>
                <select class="form-select" name="to_fosc_id" id="editTo" required>
                  <option value="">Select…</option>
                  <?php foreach ($fosc as $f): ?>
                    <option value="<?= (int)$f['fosc_id'] ?>"><?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Length (m)</label>
                <input class="form-control" id="editLength" disabled>
                <div class="form-text">Calculated from snapped geometry.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Cable serial</label>
                <input class="form-control" name="cable_serial" id="editSerial" maxlength="60">
                <div class="form-text">Cable ID on the sheath, from the as-built.</div>
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" id="editNotes" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary" type="submit">Save</button>
            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>
    <script>
    document.getElementById('modalEdit').addEventListener('show.bs.modal', function (ev) {
      const d = ev.relatedTarget ? ev.relatedTarget.dataset : null;
      if (!d) return;
      document.getElementById('editId').value = d.id;
      document.getElementById('editIdLabel').textContent = d.id;
      document.getElementById('editCable').value = d.cable;
      document.getElementById('editFrom').value = d.from;
      document.getElementById('editTo').value = d.to;
      document.getElementById('editLength').value = d.length;
      document.getElementById('editSerial').value = d.serial;
      document.getElementById('editNotes').value = d.notes;
    });
    </script>

    <!-- Create Modal -->
    <div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title">New Cable Span</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Cable *</label>
                <select class="form-select" name="cable_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($cables as $c): ?>
                    <option value="<?= (int)$c['cable_id'] ?>">
                      <?= h($c['name']) ?>
                      <?= $c['fibre_count'] ? ' — ' . h($c['fibre_count']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">From FOSC *</label>
                <select class="form-select" name="from_fosc_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($fosc as $f): ?>
                    <option value="<?= (int)$f['fosc_id'] ?>">
                      <?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">To FOSC *</label>
                <select class="form-select" name="to_fosc_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($fosc as $f): ?>
                    <option value="<?= (int)$f['fosc_id'] ?>">
                      <?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-success" type="submit">Create</button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>
    <?php
    return ob_get_clean();
});
