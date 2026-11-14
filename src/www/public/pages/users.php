<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/users.php — user management (admin only)
/** @var \FPM\App\Template $PAGE */

require_once __DIR__ . '/../../app/user_identity.php';

$PAGE->setTitle('Users');

$PAGE->setInlineCssRenderer(function () { return '.btn-xs { padding:.15rem .4rem; font-size:.75rem; }'; });

$PAGE->setContentRenderer(function () use ($pdo) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $identity = fpm_current_identity();
    if (($identity['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        return '<div class="alert alert-danger mt-4">Admin access required.</div>';
    }

    $users = $pdo->query("
        SELECT app_user_id, email, display_name, role, provider,
               last_login_at, created_at
          FROM app.app_user
         ORDER BY created_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    $csrf = json_encode((string)($_SESSION['csrf'] ?? ''));

    ob_start(); ?>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0">Users</h4>
  <span class="text-muted small">Display names and roles</span>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="usersTable">
      <thead class="table-dark">
        <tr>
          <th>Email</th>
          <th style="width:16rem">Display Name</th>
          <th style="width:8rem">Role</th>
          <th style="width:9rem">Last Login</th>
          <th style="width:5rem">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr data-user-id="<?= (int)$u['app_user_id'] ?>">
          <td class="small"><?= h($u['email']) ?></td>
          <td>
            <span class="user-name-text"><?= h($u['display_name']) ?></span>
            <input type="text" class="form-control form-control-sm user-name-edit d-none"
                   maxlength="80" value="<?= h($u['display_name']) ?>" style="width:14rem">
          </td>
          <td>
            <span class="user-role-text">
              <span class="badge <?= $u['role']==='admin' ? 'bg-danger' : 'bg-secondary' ?>">
                <?= h($u['role']) ?>
              </span>
            </span>
            <select class="form-select form-select-sm user-role-edit d-none" style="width:7rem">
              <option value="user"  <?= $u['role']==='user'  ? 'selected' : '' ?>>user</option>
              <option value="admin" <?= $u['role']==='admin' ? 'selected' : '' ?>>admin</option>
            </select>
          </td>
          <td class="small text-muted">
            <?php if ($u['last_login_at']): ?>
              <span class="ts-ago" data-ts="<?= h($u['last_login_at']) ?>"><?= h($u['last_login_at']) ?></span>
            <?php else: ?>
              Never
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-xs btn-outline-secondary user-edit-btn">Edit</button>
            <button class="btn btn-xs btn-success user-save-btn d-none">Save</button>
            <button class="btn btn-xs btn-outline-secondary user-cancel-btn d-none">✕</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var CSRF = <?= $csrf ?>;

  document.querySelectorAll('#usersTable tbody tr').forEach(function (row) {
    var userId     = parseInt(row.dataset.userId, 10);
    var nameText   = row.querySelector('.user-name-text');
    var nameInput  = row.querySelector('.user-name-edit');
    var roleText   = row.querySelector('.user-role-text');
    var roleSelect = row.querySelector('.user-role-edit');
    var editBtn    = row.querySelector('.user-edit-btn');
    var saveBtn    = row.querySelector('.user-save-btn');
    var cancelBtn  = row.querySelector('.user-cancel-btn');

    var origName, origRole;

    editBtn.addEventListener('click', function () {
      origName = nameText.textContent.trim();
      origRole = roleSelect.value;
      nameText.classList.add('d-none');
      nameInput.classList.remove('d-none');
      roleText.classList.add('d-none');
      roleSelect.classList.remove('d-none');
      editBtn.classList.add('d-none');
      saveBtn.classList.remove('d-none');
      cancelBtn.classList.remove('d-none');
      nameInput.focus();
      nameInput.select();
    });

    cancelBtn.addEventListener('click', function () {
      nameInput.value = origName;
      roleSelect.value = origRole;
      showText();
    });

    saveBtn.addEventListener('click', function () {
      var newName = nameInput.value.trim();
      var newRole = roleSelect.value;
      if (!newName) { nameInput.focus(); return; }

      saveBtn.disabled = true;
      saveBtn.textContent = '…';

      fetch('/api/user_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ app_user_id: userId, display_name: newName, role: newRole }),
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (d.ok) {
          nameText.textContent = d.user.display_name;
          nameInput.value      = d.user.display_name;
          var badge = roleText.querySelector('.badge');
          badge.textContent   = d.user.role;
          badge.className     = 'badge ' + (d.user.role === 'admin' ? 'bg-danger' : 'bg-secondary');
          roleSelect.value    = d.user.role;
          showText();
        } else {
          alert('Save failed: ' + (d.error || 'unknown error'));
        }
      })
      .catch(function (e) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        alert('Request failed: ' + e.message);
      });
    });

    function showText() {
      nameText.classList.remove('d-none');
      nameInput.classList.add('d-none');
      roleText.classList.remove('d-none');
      roleSelect.classList.add('d-none');
      editBtn.classList.remove('d-none');
      saveBtn.classList.add('d-none');
      cancelBtn.classList.add('d-none');
    }
  });
}());
</script>

    <?php return ob_get_clean();
});
