<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/microduct_type.php        — list active types (add ?all=1 for all incl. inactive)
// POST   /api/microduct_type.php        — create type
// PUT    /api/microduct_type.php?id=N   — update type
// DELETE /api/microduct_type.php?id=N   — delete type (refused if conduit rows reference it)

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

// ── LIST ──────────────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $all = isset($_GET['all']);
    $sql = $all
        ? "SELECT id, name, way_count, inner_ducts, notes, active, has_tracer, sort_order
             FROM app.microduct_type ORDER BY sort_order, id"
        : "SELECT id, name, way_count, inner_ducts, notes, active, has_tracer, sort_order
             FROM app.microduct_type WHERE active ORDER BY sort_order, id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['way_count']   = (int)$r['way_count'];
        $r['sort_order']  = (int)$r['sort_order'];
        $r['active']      = (bool)$r['active'];
        $r['has_tracer']  = (bool)$r['has_tracer'];
        $r['inner_ducts'] = json_decode((string)$r['inner_ducts'], true) ?? [];
    }
    unset($r);
    api_ok(['types' => $rows]);
}

// ── CREATE ────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    api_require_csrf();
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $name       = trim($body['name']       ?? '');
    $way_count  = (int)($body['way_count'] ?? 1);
    $inner_raw  = $body['inner_ducts']     ?? [];
    $notes      = trim($body['notes']      ?? '');
    $active     = isset($body['active'])   ? (bool)$body['active'] : true;
    $has_tracer = isset($body['has_tracer']) ? (bool)$body['has_tracer'] : false;
    $sort_order = (int)($body['sort_order'] ?? 0);

    if ($name === '')                api_err('name is required', 400);
    if ($way_count < 1 || $way_count > 48) api_err('way_count must be 1–48', 400);
    if (!is_array($inner_raw))       api_err('inner_ducts must be an array', 400);

    $inner_json = json_encode(array_values($inner_raw));

    $st = $pdo->prepare("
        INSERT INTO app.microduct_type (name, way_count, inner_ducts, notes, active, has_tracer, sort_order)
        VALUES (:name, :way_count, :inner_ducts, :notes, :active, :has_tracer, :sort_order)
        RETURNING id
    ");
    $st->execute([
        ':name'        => $name,
        ':way_count'   => $way_count,
        ':inner_ducts' => $inner_json,
        ':notes'       => $notes,
        ':active'      => $active ? 'true' : 'false',
        ':has_tracer'  => $has_tracer ? 'true' : 'false',
        ':sort_order'  => $sort_order,
    ]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $name       = trim($body['name']       ?? '');
    $way_count  = (int)($body['way_count'] ?? 1);
    $inner_raw  = $body['inner_ducts']     ?? [];
    $notes      = trim($body['notes']      ?? '');
    $active     = isset($body['active'])   ? (bool)$body['active'] : true;
    $has_tracer = isset($body['has_tracer']) ? (bool)$body['has_tracer'] : false;
    $sort_order = (int)($body['sort_order'] ?? 0);

    if ($name === '')                api_err('name is required', 400);
    if ($way_count < 1 || $way_count > 48) api_err('way_count must be 1–48', 400);
    if (!is_array($inner_raw))       api_err('inner_ducts must be an array', 400);

    $inner_json = json_encode(array_values($inner_raw));

    $st = $pdo->prepare("
        UPDATE app.microduct_type
           SET name        = :name,
               way_count   = :way_count,
               inner_ducts = :inner_ducts,
               notes       = :notes,
               active      = :active,
               has_tracer  = :has_tracer,
               sort_order  = :sort_order
         WHERE id = :id
    ");
    $st->execute([
        ':name'        => $name,
        ':way_count'   => $way_count,
        ':inner_ducts' => $inner_json,
        ':notes'       => $notes,
        ':active'      => $active ? 'true' : 'false',
        ':has_tracer'  => $has_tracer ? 'true' : 'false',
        ':sort_order'  => $sort_order,
        ':id'          => $id,
    ]);
    if ($st->rowCount() === 0) api_err('Microduct type not found', 404);
    api_ok(['id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    // Guard: refuse if any conduit references this type
    $check = $pdo->prepare("SELECT COUNT(*) FROM app.conduit WHERE microduct_type_id = :id");
    $check->execute([':id' => $id]);
    $inUse = (int)$check->fetchColumn();
    if ($inUse > 0) api_err("Cannot delete: $inUse conduit run(s) reference this type", 409);

    $st = $pdo->prepare("DELETE FROM app.microduct_type WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Microduct type not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
