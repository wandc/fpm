<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/closure_type.php
// Minimal CRUD for app.closure_type (closure_type_id, name)

use FPM\App\Template;

if (!isset($_SESSION)) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ---------------- POST handlers ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Where to redirect back to
  $back = '/index.php?page=closure_type';

  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'Bad CSRF token.'];
    header("Location: $back"); exit;
  }

  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') throw new RuntimeException('Name is required.');
      $st = $pdo->prepare("INSERT INTO app.closure_type (name) VALUES (:n)");
      $st->execute([':n'=>$name]);
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>'Added “' . $name . '”.'];

    } elseif ($action === 'update') {
      $id   = (int)($_POST['closure_type_id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      if ($id <= 0 || $name === '') throw new RuntimeException('Invalid ID or name.');
      $st = $pdo->prepare("UPDATE app.closure_type SET name=:n WHERE closure_type_id=:id");
      $st->execute([':n'=>$name, ':id'=>$id]);
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>"Updated #$id."];

    } elseif ($action === 'delete') {
      $id = (int)($_POST['closure_type_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid ID.');
      $st = $pdo->prepare("DELETE FROM app.closure_type WHERE closure_type_id=:id");
      $st->execute([':id'=>$id]);
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>"Deleted #$id."];

    } else {
      throw new RuntimeException('Unknown action.');
    }
  } catch (Throwable $e) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'Error: '.$e->getMessage()];
  }
  header("Location: $back"); exit;
}

// ---------------- Data ----------------
$rows = (function(PDO $pdo){
  $st = $pdo->query("SELECT closure_type_id, name FROM app.closure_type ORDER BY closure_type_id ASC");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
})($pdo);

// ---------------- Renderers ----------------
$render_body = function() use ($rows, $CSRF){
  ob_start();
  $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
  ?>
  <div class="container my-3">
    <h1 class="h4 mb-3">Closure Types</h1>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['ok']?'alert-success':'alert-danger' ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Create -->
    <form method="post" class="card card-body mb-4">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label">Name</label>
          <input name="name" class="form-control" placeholder="e.g., Coyote Dome 17" required>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary">Add</button>
        </div>
      </div>
    </form>

    <!-- List / Edit -->
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr><th style="width:90px">ID</th><th>Name</th><th class="text-end" style="width:220px">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['closure_type_id'] ?></td>
              <td>
                <form method="post" class="d-flex gap-2">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="closure_type_id" value="<?= (int)$r['closure_type_id'] ?>">
                  <input name="name" class="form-control form-control-sm" value="<?= h($r['name']) ?>">
                  <button class="btn btn-sm btn-primary">Save</button>
                </form>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Delete #<?= (int)$r['closure_type_id'] ?>?');">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="closure_type_id" value="<?= (int)$r['closure_type_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="3" class="text-muted">No rows yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  return ob_get_clean();
};

$render_css = function(){
  return '.table td,.table th{padding:.45rem .5rem}';
};

// ---------------- Use Template if available; else fallback ----------------
if (isset($PAGE) && $PAGE instanceof Template) {
  $PAGE->setTitle('Closure Types');
  $PAGE->setInlineCssRenderer($render_css);
  $PAGE->setContentRenderer($render_body);
} else {
  // Fallback: render immediately (no Template object provided)
  ?><!doctype html><html><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style><?= $render_css() ?></style>
  </head><body><?= $render_body() ?></body></html><?php
}
