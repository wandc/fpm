<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/uguard.php  — create u-guard
// PATCH  /api/uguard.php  — update u-guard, or move it to another pole with {id, pole_fid}
// DELETE /api/uguard.php  — delete u-guard

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

$validStatuses = ['plan', 'construction', 'active', 'retired'];

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    api_require_csrf();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $poleFid = isset($body['pole_fid']) ? (int)$body['pole_fid'] : null;
    $status  = trim($body['status'] ?? 'plan');
    $notes   = trim($body['notes']  ?? '');
    $fpmWor = trim($body['fpm_wor'] ?? '');

    if (!$poleFid) api_err('pole_fid required', 400);
    if (!in_array($status, $validStatuses, true)) api_err('Invalid status', 400);

    $st = $pdo->prepare("
        INSERT INTO app.uguard (pole_fid, status, notes, fpm_wor)
        VALUES (:pole_fid, :status::app.anchor_status, :notes, :fpm_wor)
        RETURNING id
    ");
    $st->execute([
        ':pole_fid' => $poleFid,
        ':status'   => $status,
        ':notes'    => $notes,
        ':fpm_wor' => $fpmWor !== '' ? $fpmWor : null,
    ]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    api_require_csrf();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = isset($body['id'])     ? (int)$body['id']     : 0;
    $status = trim($body['status'] ?? 'plan');
    $notes  = trim($body['notes']  ?? '');
    $fpmWor = trim($body['fpm_wor'] ?? '');

    if (!$id) api_err('id required', 400);

    // Move only: {id, pole_fid} puts it on another pole and leaves status, notes and work order alone.
    if (isset($body['pole_fid']) && !array_key_exists('status', $body)) {
        $to = (int)$body['pole_fid'];
        if ($to <= 0) api_err('pole_fid required', 400);
        $chk = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM app.pole WHERE fid = :p), EXISTS (SELECT 1 FROM app.uguard WHERE pole_fid = :p AND id <> :id)');
        $chk->execute([':p' => $to, ':id' => $id]);
        [$poleOk, $taken] = $chk->fetch(PDO::FETCH_NUM);
        if (!$poleOk) api_err('Pole not found', 404);
        if ($taken)   api_err('That pole already has a u-guard', 400);
        $st = $pdo->prepare('UPDATE app.uguard SET pole_fid = :p WHERE id = :id');
        $st->execute([':p' => $to, ':id' => $id]);
        if ($st->rowCount() === 0) api_err('U-guard not found', 404);
        api_ok(['id' => $id, 'pole_fid' => $to]);
    }

    if (!in_array($status, $validStatuses, true)) api_err('Invalid status', 400);

    $st = $pdo->prepare("
        UPDATE app.uguard
           SET status = :status::app.anchor_status,
               notes  = :notes,
               fpm_wor = :fpm_wor
         WHERE id = :id
    ");
    $st->execute([':id' => $id, ':status' => $status, ':notes' => $notes,
                  ':fpm_wor' => $fpmWor !== '' ? $fpmWor : null]);
    if ($st->rowCount() === 0) api_err('U-guard not found', 404);
    api_ok(['id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    api_require_csrf();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) api_err('id required', 400);

    $st = $pdo->prepare("DELETE FROM app.uguard WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('U-guard not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
