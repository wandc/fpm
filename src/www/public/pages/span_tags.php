<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/span_tags.php
// Manage span tags — create/edit/delete tags, assign cable spans to them.

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

$tagId = isset($_GET['tag_id']) ? (int)$_GET['tag_id'] : 0;

// ── POST handlers ─────────────────────────────────────────────────────────────

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=span_tags' . ($tagId ? '&tag_id=' . $tagId : ''));
        exit;
    }

    try {
        if ($action === 'create_tag') {
            $name  = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if ($name === '' || strlen($name) > 24) {
                throw new RuntimeException('Tag name is required and must be ≤ 24 characters.');
            }
            $st = $pdo->prepare("
                INSERT INTO app.span_tag (name, notes) VALUES (:n, :notes) RETURNING span_tag_id
            ");
            $st->execute([':n' => $name, ':notes' => $notes ?: null]);
            $newId = (int)$st->fetchColumn();
            flash_set('Tag "' . $name . '" created.');
            header('Location: index.php?page=span_tags&tag_id=' . $newId);
            exit;

        } elseif ($action === 'update_tag') {
            $id    = (int)($_POST['tag_id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if ($id <= 0 || $name === '' || strlen($name) > 24) {
                throw new RuntimeException('Invalid input.');
            }
            $pdo->prepare("
                UPDATE app.span_tag SET name = :n, notes = :notes, updated_at = now()
                 WHERE span_tag_id = :id
            ")->execute([':n' => $name, ':notes' => $notes ?: null, ':id' => $id]);
            flash_set('Tag updated.');
            header('Location: index.php?page=span_tags&tag_id=' . $id);
            exit;

        } elseif ($action === 'delete_tag') {
            $id = (int)($_POST['tag_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid id.');
            $pdo->prepare("DELETE FROM app.span_tag WHERE span_tag_id = :id")
                ->execute([':id' => $id]);
            flash_set('Tag deleted.');
            header('Location: index.php?page=span_tags');
            exit;

        } elseif ($action === 'add_spans') {
            $id   = (int)($_POST['tag_id'] ?? 0);
            $sids = array_map('intval', (array)($_POST['span_ids'] ?? []));
            $sids = array_filter($sids, fn($v) => $v > 0);
            if ($id <= 0) throw new RuntimeException('Invalid tag.');
            if ($sids) {
                $pdo->beginTransaction();
                $st = $pdo->prepare("
                    INSERT INTO app.cable_span_tag (span_tag_id, cable_span_id)
                    VALUES (:tid, :sid) ON CONFLICT DO NOTHING
                ");
                foreach ($sids as $sid) {
                    $st->execute([':tid' => $id, ':sid' => $sid]);
                }
                $pdo->commit();
            }
            flash_set(count($sids) . ' span(s) added.');
            header('Location: index.php?page=span_tags&tag_id=' . $id);
            exit;

        } elseif ($action === 'remove_span') {
            $id  = (int)($_POST['tag_id'] ?? 0);
            $sid = (int)($_POST['cable_span_id'] ?? 0);
            if ($id <= 0 || $sid <= 0) throw new RuntimeException('Invalid input.');
            $pdo->prepare("
                DELETE FROM app.cable_span_tag WHERE span_tag_id = :tid AND cable_span_id = :sid
            ")->execute([':tid' => $id, ':sid' => $sid]);
            flash_set('Span removed from tag.');
            header('Location: index.php?page=span_tags&tag_id=' . $id);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('Error: ' . $e->getMessage(), false);
        header('Location: index.php?page=span_tags' . ($tagId ? '&tag_id=' . $tagId : ''));
        exit;
    }
}

// ── Data loading ──────────────────────────────────────────────────────────────

$flash = flash_get();

if ($tagId > 0) {
    // Detail view: single tag
    $tags = [];
    $tag = $pdo->prepare("SELECT * FROM app.span_tag WHERE span_tag_id = :id");
    $tag->execute([':id' => $tagId]);
    $tag = $tag->fetch() ?: null;

    // Spans already in this tag
    $taggedSpans = $pdo->prepare("
        SELECT s.cable_span_id, c.name AS cable_name, c.fibre_count,
               f1.splice_num AS from_label, f2.splice_num AS to_label,
               s.length_m
          FROM app.cable_span_tag t
          JOIN app.cable_span s  ON s.cable_span_id = t.cable_span_id
          JOIN app.cable     c  ON c.cable_id       = s.cable_id
          JOIN app.fosc      f1 ON f1.fosc_id       = s.from_fosc_id
          JOIN app.fosc      f2 ON f2.fosc_id       = s.to_fosc_id
         WHERE t.span_tag_id = :id
         ORDER BY c.name, s.cable_span_id
    ");
    $taggedSpans->execute([':id' => $tagId]);
    $taggedSpans = $taggedSpans->fetchAll();

    // Spans NOT yet in this tag (for the add modal)
    $availSpans = $pdo->prepare("
        SELECT s.cable_span_id, c.name AS cable_name, c.fibre_count,
               f1.splice_num AS from_label, f2.splice_num AS to_label,
               s.length_m
          FROM app.cable_span s
          JOIN app.cable c  ON c.cable_id     = s.cable_id
          JOIN app.fosc  f1 ON f1.fosc_id     = s.from_fosc_id
          JOIN app.fosc  f2 ON f2.fosc_id     = s.to_fosc_id
         WHERE s.cable_span_id NOT IN (
               SELECT cable_span_id FROM app.cable_span_tag WHERE span_tag_id = :id
         )
         ORDER BY c.name, s.cable_span_id
    ");
    $availSpans->execute([':id' => $tagId]);
    $availSpans = $availSpans->fetchAll();
} else {
    // List view: all tags
    $tag = null;
    $taggedSpans = [];
    $availSpans  = [];
    $tags = $pdo->query("
        SELECT t.span_tag_id, t.name, t.notes, t.updated_at,
               COUNT(cst.cable_span_id) AS span_count
          FROM app.span_tag t
          LEFT JOIN app.cable_span_tag cst ON cst.span_tag_id = t.span_tag_id
         GROUP BY t.span_tag_id
         ORDER BY t.name
    ")->fetchAll();
}

// ── Render ────────────────────────────────────────────────────────────────────

$PAGE->setTitle($tagId && $tag ? 'Tag: ' . $tag['name'] : 'Span Tags');

$PAGE->setContentRenderer(function () use (
    $tagId, $tag, $taggedSpans, $availSpans, $tags, $flash, $CSRF
) {
    ob_start();

    if ($flash): ?>
      <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?> mb-3">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif;

    // ── DETAIL VIEW ──────────────────────────────────────────────────────────
    if ($tagId > 0):
        if (!$tag): ?>
          <div class="alert alert-danger">Tag not found.</div>
        <?php return ob_get_clean(); endif; ?>

      <!-- Header -->
      <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="index.php?page=span_tags" class="btn btn-outline-secondary btn-sm">← Tags</a>
        <h3 class="mb-0 me-auto">
          <span class="badge bg-secondary fw-semibold" style="font-size:1rem;letter-spacing:.02em">
            <?= h($tag['name']) ?>
          </span>
        </h3>
        <button class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#editTagModal">Edit Tag</button>
        <form method="post" class="d-inline"
              onsubmit="return confirm('Delete tag \'<?= h(addslashes($tag['name'])) ?>\'? The tag will be removed from all spans.');">
          <input type="hidden" name="action"  value="delete_tag">
          <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
          <input type="hidden" name="tag_id"  value="<?= $tagId ?>">
          <button class="btn btn-outline-danger btn-sm">Delete Tag</button>
        </form>
      </div>

      <?php if ($tag['notes']): ?>
        <p class="text-muted small mb-3"><?= nl2br(h($tag['notes'])) ?></p>
      <?php endif; ?>

      <!-- Spans in tag -->
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h5 class="mb-0">Spans
          <span class="badge bg-secondary"><?= count($taggedSpans) ?></span>
        </h5>
        <button class="btn btn-success btn-sm"
                data-bs-toggle="modal" data-bs-target="#addSpansModal">
          + Add Spans
        </button>
      </div>

      <?php if (!$taggedSpans): ?>
        <div class="alert alert-secondary">No spans in this tag yet. Use "Add Spans" to assign some.</div>
      <?php else: ?>
        <div class="table-responsive mb-4">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Cable</th>
                <th>From</th>
                <th>To</th>
                <th class="text-end">Length (m)</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($taggedSpans as $s): ?>
                <tr>
                  <td><?= (int)$s['cable_span_id'] ?></td>
                  <td><?= h($s['cable_name']) ?> <small class="text-muted">(<?= (int)$s['fibre_count'] ?>F)</small></td>
                  <td><code><?= h($s['from_label']) ?></code></td>
                  <td><code><?= h($s['to_label']) ?></code></td>
                  <td class="text-end">
                    <?= $s['length_m'] !== null ? number_format((float)$s['length_m'], 1) : '—' ?>
                  </td>
                  <td class="text-end">
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Remove span #<?= (int)$s['cable_span_id'] ?> from this tag?');">
                      <input type="hidden" name="action"        value="remove_span">
                      <input type="hidden" name="csrf"          value="<?= h($CSRF) ?>">
                      <input type="hidden" name="tag_id"        value="<?= $tagId ?>">
                      <input type="hidden" name="cable_span_id" value="<?= (int)$s['cable_span_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- ── Edit Tag modal ─────────────────────────────────────────────── -->
      <div class="modal fade" id="editTagModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
          <form method="post">
            <input type="hidden" name="action" value="update_tag">
            <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
            <input type="hidden" name="tag_id" value="<?= $tagId ?>">
            <div class="modal-header">
              <h5 class="modal-title">Edit Tag</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" maxlength="24" required
                       value="<?= h($tag['name']) ?>">
                <div class="form-text">Max 24 characters.</div>
              </div>
              <div class="mb-1">
                <label class="form-label fw-semibold">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= h($tag['notes'] ?? '') ?></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary">Save</button>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div></div>
      </div>

      <!-- ── Add Spans modal ────────────────────────────────────────────── -->
      <div class="modal fade" id="addSpansModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
          <form method="post">
            <input type="hidden" name="action" value="add_spans">
            <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
            <input type="hidden" name="tag_id" value="<?= $tagId ?>">
            <div class="modal-header">
              <h5 class="modal-title">Add Spans to "<?= h($tag['name']) ?>"</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <?php if (!$availSpans): ?>
                <p class="text-muted">All spans are already in this tag.</p>
              <?php else: ?>
                <input type="search" class="form-control form-control-sm mb-2"
                       id="spanSearch" placeholder="Filter by cable, span ID, or closure…"
                       autocomplete="off">
                <div style="max-height:380px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px">
                  <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                      <tr>
                        <th style="width:36px">
                          <input type="checkbox" id="checkAll" title="Select all visible">
                        </th>
                        <th>ID</th>
                        <th>Cable</th>
                        <th>From → To</th>
                        <th class="text-end">m</th>
                      </tr>
                    </thead>
                    <tbody id="spanCheckList">
                      <?php foreach ($availSpans as $s): ?>
                        <tr data-search="<?= h(strtolower(
                            $s['cable_span_id'] . ' ' .
                            $s['cable_name']    . ' ' .
                            $s['from_label']    . ' ' .
                            $s['to_label']
                        )) ?>">
                          <td>
                            <input type="checkbox" name="span_ids[]"
                                   value="<?= (int)$s['cable_span_id'] ?>"
                                   class="span-check">
                          </td>
                          <td><?= (int)$s['cable_span_id'] ?></td>
                          <td><?= h($s['cable_name']) ?> <small class="text-muted">(<?= (int)$s['fibre_count'] ?>F)</small></td>
                          <td>
                            <code><?= h($s['from_label']) ?></code>
                            → <code><?= h($s['to_label']) ?></code>
                          </td>
                          <td class="text-end">
                            <?= $s['length_m'] !== null ? number_format((float)$s['length_m'], 0) : '—' ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="form-text mt-1" id="spanSelCount">0 spans selected</div>
                <script>
                (function() {
                  var search = document.getElementById('spanSearch');
                  var checkAll = document.getElementById('checkAll');
                  var list = document.getElementById('spanCheckList');
                  var counter = document.getElementById('spanSelCount');

                  function countSel() {
                    var n = list.querySelectorAll('input.span-check:checked').length;
                    counter.textContent = n + ' span' + (n !== 1 ? 's' : '') + ' selected';
                  }

                  function visibleRows() {
                    return Array.from(list.querySelectorAll('tr')).filter(function(tr) {
                      return tr.style.display !== 'none';
                    });
                  }

                  search.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    list.querySelectorAll('tr').forEach(function(tr) {
                      tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
                    });
                    checkAll.checked = false;
                  });

                  checkAll.addEventListener('change', function() {
                    visibleRows().forEach(function(tr) {
                      var cb = tr.querySelector('input.span-check');
                      if (cb) cb.checked = checkAll.checked;
                    });
                    countSel();
                  });

                  list.addEventListener('change', function(e) {
                    if (e.target.classList.contains('span-check')) countSel();
                  });
                })();
                </script>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <?php if ($availSpans): ?>
                <button class="btn btn-success">Add Selected</button>
              <?php endif; ?>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div></div>
      </div>

    <?php
    // ── LIST VIEW ────────────────────────────────────────────────────────────
    else: ?>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0">Span Tags</h3>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createTagModal">
          + New Tag
        </button>
      </div>

      <?php if (!$tags): ?>
        <div class="alert alert-secondary">No tags yet. Create one to start grouping spans.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th class="text-center">Spans</th>
                <th>Notes</th>
                <th>Updated</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tags as $t): ?>
                <tr>
                  <td>
                    <a href="index.php?page=span_tags&tag_id=<?= (int)$t['span_tag_id'] ?>"
                       class="fw-semibold text-decoration-none">
                      <?= h($t['name']) ?>
                    </a>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-secondary"><?= (int)$t['span_count'] ?></span>
                  </td>
                  <td class="text-muted small"><?= h($t['notes'] ?? '') ?></td>
                  <td class="text-nowrap small text-muted"><?= h($t['updated_at']) ?></td>
                  <td class="text-end">
                    <a href="index.php?page=span_tags&tag_id=<?= (int)$t['span_tag_id'] ?>"
                       class="btn btn-sm btn-outline-secondary">Manage</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- ── Create Tag modal ──────────────────────────────────────────── -->
      <div class="modal fade" id="createTagModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
          <form method="post">
            <input type="hidden" name="action" value="create_tag">
            <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
            <div class="modal-header">
              <h5 class="modal-title">New Tag</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" maxlength="24" required
                       placeholder="e.g. Bannister Rd.">
                <div class="form-text">Max 24 characters.</div>
              </div>
              <div class="mb-1">
                <label class="form-label fw-semibold">Notes</label>
                <textarea class="form-control" name="notes" rows="2"
                          placeholder="Optional description"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-success">Create</button>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div></div>
      </div>
    <?php endif;

    return ob_get_clean();
});
