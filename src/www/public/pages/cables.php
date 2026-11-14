<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/cables.php
/** @var \FPM\App\Template $PAGE */

// If $pdo is not created in bootstrap yet, uncomment and fix path:
// require __DIR__ . '/../config.php';

global $pdo, $CSRF;

// --- Handle POST actions (PRG pattern) ---

if (is_post()) {
    $action = $_POST['action'] ?? '';

    // CSRF check
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=cables');
        exit;
    }

    try {
        if ($action === 'create') {
            $name        = trim($_POST['name'] ?? '');
            $fibre_count = trim($_POST['fibre_count'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO app.cable (name, fibre_count, notes)
                VALUES (:n, :s, :no)
            ");
            $stmt->execute([
                ':n'  => $name,
                ':s'  => $fibre_count !== '' ? $fibre_count : null,
                ':no' => $notes !== '' ? $notes : null,
            ]);

            flash_set('Cable created.');
        } elseif ($action === 'update') {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $fibre_count = trim($_POST['fibre_count'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Invalid input.');
            }

            $stmt = $pdo->prepare("
                UPDATE app.cable
                   SET name = :n,
                       fibre_count = :s,
                       notes = :no,
                       updated_at = now()
                 WHERE cable_id = :id
            ");
            $stmt->execute([
                ':n'  => $name,
                ':s'  => $fibre_count !== '' ? $fibre_count : null,
                ':no' => $notes !== '' ? $notes : null,
                ':id' => $id,
            ]);

            flash_set('Cable updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Bad id.');
            }

            $stmt = $pdo->prepare("DELETE FROM app.cable WHERE cable_id = :id");
            $stmt->execute([':id' => $id]);

            flash_set('Cable deleted.');
        }
    } catch (Throwable $e) {
        flash_set('Error: ' . $e->getMessage(), false);
    }

    // Redirect to avoid resubmission
    header('Location: index.php?page=cables');
    exit;
}

// --- Fetch list for GET requests ---

$rows = $pdo->query("
  SELECT cable_id, name, fibre_count, notes, created_at, updated_at
    FROM app.cable
ORDER BY updated_at DESC NULLS LAST, created_at DESC
")->fetchAll();

$flash = flash_get();

// --- Plug into Template ---

$PAGE->setTitle('Cables');

$PAGE->setContentRenderer(function () use ($rows, $CSRF, $flash) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Cables</h3>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreate">
        New Cable
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
            <th>Name</th>
            <th>Fibre Count</th>
            <th>Notes</th>
            <th>Created</th>
            <th>Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['cable_id'] ?></td>
              <td><strong><?= h($r['name']) ?></strong></td>
              <td><?= h($r['fibre_count'] ?? '') ?></td>
              <td><?= $r['notes'] ? nl2br(h($r['notes'])) : '—' ?></td>
              <td class="text-nowrap"><?= h($r['created_at']) ?></td>
              <td class="text-nowrap"><?= h($r['updated_at']) ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#modalEdit<?= (int)$r['cable_id'] ?>">
                  Edit
                </button>
                <form class="d-inline" method="post"
                      onsubmit="return confirm('Delete this cable?');">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['cable_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>

            <!-- Edit Modal -->
            <div class="modal fade" id="modalEdit<?= (int)$r['cable_id'] ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog"><div class="modal-content">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$r['cable_id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Cable</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Name *</label>
                      <input class="form-control" name="name" required
                             value="<?= h($r['name']) ?>">
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Fibre Count</label>
                      <input class="form-control" name="fibre_count"
                             value="<?= h($r['fibre_count'] ?? '') ?>"
                             placeholder="e.g., 48ct, 72ct">
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Notes</label>
                      <textarea class="form-control" name="notes" rows="3"><?= h($r['notes'] ?? '') ?></textarea>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">Save</button>
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  </div>
                </form>
              </div></div>
            </div>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title">New Cable</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Name *</label>
              <input class="form-control" name="name" required
                     placeholder="e.g., 48ct Moncton Loop">
            </div>
            <div class="mb-3">
              <label class="form-label">Fibre Count</label>
              <input class="form-control" name="fibre_count"
                     placeholder="e.g., 48ct, 96ct">
            </div>
            <div class="mb-3">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="3"></textarea>
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
