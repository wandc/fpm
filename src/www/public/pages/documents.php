<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/documents.php
/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;

/** Pull 8-digit PIDs out of free text (commas, spaces, newlines; dashes in "70119-219" are not expected). */
function doc_parse_pids(string $txt): array {
    preg_match_all('/\d{8}/', $txt, $m);
    return array_values(array_unique($m[0]));
}
/** Replace a document's PID list. The caller owns the transaction. */
function doc_set_pids(PDO $pdo, int $docId, array $pids): void {
    $pdo->prepare("DELETE FROM app.document_pid WHERE document_id = ? AND NOT (pid = ANY(string_to_array(?, ',')))")
        ->execute([$docId, implode(',', $pids)]);
    $ins = $pdo->prepare("INSERT INTO app.document_pid (document_id, pid) VALUES (?, ?) ON CONFLICT DO NOTHING");
    foreach ($pids as $pid) $ins->execute([$docId, $pid]);
}

// ── POST handling (PRG pattern) ───────────────────────────────────────────────
if (is_post()) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=documents');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'upload') {
            $title    = trim($_POST['title']       ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $category = trim($_POST['category']    ?? '');

            if ($title === '') throw new RuntimeException('Title is required.');

            $err = $_FILES['doc_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                $msgs = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                    UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file selected.',
                    UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory.',
                    UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk.',
                ];
                throw new RuntimeException($msgs[$err] ?? 'Upload error ' . $err . '.');
            }

            $tmp  = $_FILES['doc_file']['tmp_name'];
            $size = $_FILES['doc_file']['size'];

            if ($size > 50 * 1024 * 1024) throw new RuntimeException('File too large (max 50 MB).');

            $mime     = mime_content_type($tmp) ?: 'application/octet-stream';
            $filename = basename($_FILES['doc_file']['name']);
            $hexData  = '\\x' . bin2hex(file_get_contents($tmp));

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO app.document
                    (title, description, category, filename, mime_type, file_size, file_data)
                VALUES
                    (:title, :desc, :cat, :fn, :mime, :sz, :data)
                RETURNING document_id
            ");
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':desc',  $desc     !== '' ? $desc     : null);
            $stmt->bindValue(':cat',   $category !== '' ? $category : null);
            $stmt->bindValue(':fn',    $filename);
            $stmt->bindValue(':mime',  $mime);
            $stmt->bindValue(':sz',    $size, PDO::PARAM_INT);
            $stmt->bindValue(':data',  $hexData);
            $stmt->execute();
            $newId = (int)$stmt->fetchColumn();
            $pids  = doc_parse_pids((string)($_POST['pids'] ?? ''));
            if ($pids) doc_set_pids($pdo, $newId, $pids);
            $pdo->commit();

            flash_set('Document uploaded successfully.' . ($pids ? ' Linked to ' . count($pids) . ' PID' . (count($pids) === 1 ? '' : 's') . '.' : ''));

        } elseif ($action === 'pids') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid document ID.');
            $pids = doc_parse_pids((string)($_POST['pids'] ?? ''));
            $pdo->beginTransaction();
            doc_set_pids($pdo, $id, $pids);
            $pdo->commit();
            flash_set('Saved ' . count($pids) . ' PID' . (count($pids) === 1 ? '' : 's') . '.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid document ID.');
            $pdo->prepare("DELETE FROM app.document WHERE document_id = :id")
                ->execute([':id' => $id]);
            flash_set('Document deleted.');
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set($e->getMessage(), false);
    }

    header('Location: index.php?page=documents');
    exit;
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
$docs = $pdo->query("
    SELECT d.document_id, d.title, d.description, d.category,
           d.filename, d.mime_type, d.file_size, d.created_at,
           COALESCE((SELECT string_agg(p.pid, ' ' ORDER BY p.pid) FROM app.document_pid p WHERE p.document_id = d.document_id), '') AS pids
    FROM app.document d
    ORDER BY d.category NULLS LAST, d.title
")->fetchAll();

$grouped = [];
foreach ($docs as $d) {
    $grouped[$d['category'] ?? ''][] = $d;
}

$categories = array_filter(array_unique(array_column($docs, 'category')));
sort($categories);

$flash = flash_get();

// ── Template ──────────────────────────────────────────────────────────────────
$PAGE->setTitle('Documents');

$PAGE->setContentRenderer(function () use ($grouped, $categories, $CSRF, $flash) {
    ob_start();

    function fmt_bytes(int $b): string {
        if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
        if ($b >= 1024)    return round($b / 1024, 1)    . ' KB';
        return $b . ' B';
    }
    ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Documents &amp; Manuals</h3>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadModal">
        + Upload
      </button>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($grouped)): ?>
      <p class="text-muted">No documents yet. Upload one to get started.</p>
    <?php else: ?>

      <?php foreach ($grouped as $cat => $items): ?>
        <h6 class="text-uppercase text-muted fw-semibold mt-4 mb-2"
            style="font-size:.72rem;letter-spacing:.06em">
          <?= $cat !== '' ? h($cat) : 'Uncategorised' ?>
        </h6>
        <div class="list-group mb-2">
        <?php foreach ($items as $d): ?>
          <?php
            $isPdf = str_contains($d['mime_type'], 'pdf');
            $base  = 'api/document_serve.php?id=' . $d['document_id'];
          ?>
          <div class="list-group-item d-flex justify-content-between align-items-start gap-2 py-2">
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold"><?= h($d['title']) ?></div>
              <?php if ($d['description']): ?>
                <div class="text-muted small"><?= nl2br(preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', h($d['description']))) ?></div>
              <?php endif; ?>
              <?php $pidList = $d['pids'] !== '' ? explode(' ', $d['pids']) : []; ?>
              <?php if ($pidList): ?>
                <div class="small" title="Shown in the map popup of each of these PIDs">
                  <span class="text-muted">PIDs (<?= count($pidList) ?>):</span>
                  <?= h(implode(', ', array_slice($pidList, 0, 12))) ?><?= count($pidList) > 12 ? ' …' : '' ?>
                </div>
              <?php endif; ?>
              <div class="text-muted" style="font-size:.72rem">
                <?= h($d['filename']) ?>
                &middot; <?= $d['file_size'] ? fmt_bytes((int)$d['file_size']) : '—' ?>
                &middot; <?= date('Y-m-d', strtotime($d['created_at'])) ?>
              </div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-shrink-0">
              <?php if ($isPdf): ?>
                <a href="<?= $base ?>&amp;inline=1" target="_blank"
                   class="btn btn-sm btn-outline-secondary">View</a>
              <?php endif; ?>
              <a href="<?= $base ?>" class="btn btn-sm btn-outline-primary">Download</a>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pidsModal"
                      data-id="<?= $d['document_id'] ?>" data-title="<?= h($d['title']) ?>" data-pids="<?= h($d['pids']) ?>">PIDs</button>
              <form method="post" action="index.php?page=documents"
                    onsubmit="return confirm('Delete this document?')">
                <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= $d['document_id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Delete">✕</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" action="index.php?page=documents"
                enctype="multipart/form-data">
            <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-header">
              <h5 class="modal-title">Upload Document</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required
                       placeholder="e.g. Tripp Lite B096-016 KVM Manual">
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Category</label>
                <input type="text" name="category" class="form-control"
                       list="doc-cat-list"
                       placeholder="e.g. KVM, PDU, Fibre, Networking">
                <datalist id="doc-cat-list">
                  <?php foreach ($categories as $c): ?>
                    <option value="<?= h($c) ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Description</label>
                <input type="text" name="description" class="form-control"
                       placeholder="Optional short description">
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">PIDs this document pertains to</label>
                <textarea name="pids" class="form-control" rows="2" placeholder="70119219 70119227 70119235"></textarea>
                <div class="form-text">Optional. 8-digit PIDs separated by spaces, commas or new lines. The document is then offered in each parcel's map popup.</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">File <span class="text-danger">*</span></label>
                <input type="file" name="doc_file" class="form-control" required
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.zip">
                <div class="form-text">Max 50 MB. PDF, Office, images, ZIP.</div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary"
                      data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success">Upload</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- PIDs Modal (one, shared by every row) -->
    <div class="modal fade" id="pidsModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" action="index.php?page=documents">
            <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="pids">
            <input type="hidden" name="id" id="pidsId">
            <div class="modal-header">
              <h5 class="modal-title">PIDs: <span id="pidsTitle"></span></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <textarea name="pids" id="pidsText" class="form-control" rows="6"></textarea>
              <div class="form-text">8-digit PIDs separated by spaces, commas or new lines. Saving replaces the list; clear it to unlink the document from every parcel.</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <script>
    document.getElementById('pidsModal').addEventListener('show.bs.modal', function (ev) {
      const d = ev.relatedTarget ? ev.relatedTarget.dataset : null; if (!d) return;
      document.getElementById('pidsId').value = d.id;
      document.getElementById('pidsTitle').textContent = d.title;
      document.getElementById('pidsText').value = (d.pids || '').split(' ').filter(Boolean).join('\n');
    });
    </script>

    <?php
    return ob_get_clean();
});
