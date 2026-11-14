<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// api/user_update.php — update a user's display_name and/or role (admin only)
// POST JSON: { "app_user_id": 1, "display_name": "D Waddy", "role": "admin" }
// X-CSRF-Token header required.

require_once __DIR__ . '/_common.php';

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();

// ── Admin only ────────────────────────────────────────────────────────────────

if (($_apiIdentity['role'] ?? 'user') !== 'admin') {
    api_err('Admin access required', 403);
}

$body        = api_json_body();
$targetId    = (int)($body['app_user_id'] ?? 0);
$displayName = trim((string)($body['display_name'] ?? ''));
$role        = trim((string)($body['role'] ?? ''));

// ── Validate inputs ───────────────────────────────────────────────────────────

if ($targetId < 1) {
    api_err('Invalid user ID', 422);
}
if ($displayName === '' || strlen($displayName) > 80) {
    api_err('Display name must be 1–80 characters', 422);
}
if (!in_array($role, ['admin', 'user'], true)) {
    api_err('Role must be admin or user', 422);
}

// ── Update ────────────────────────────────────────────────────────────────────

$stmt = $pdo->prepare("
    UPDATE app.app_user
       SET display_name = :display_name,
           role         = :role,
           updated_at   = now()
     WHERE app_user_id = :id
    RETURNING app_user_id, email, display_name, role
");
$stmt->execute([':display_name' => $displayName, ':role' => $role, ':id' => $targetId]);
$updated = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$updated) {
    api_err('User not found', 404);
}

// If admin edited their own record, refresh session display_name + role
if ($_apiIdentity['app_user_id'] === $targetId) {
    $_SESSION['auth_user']['display_name'] = $displayName;
    $_SESSION['auth_user']['role']         = $role;
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['display_name'] = $displayName;
        $_SESSION['user']['role']         = $role;
    }
}

api_ok(['user' => $updated]);
