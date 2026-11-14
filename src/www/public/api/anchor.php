<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/anchor.php  — create anchor
// PATCH  /api/anchor.php  — update anchor
// DELETE /api/anchor.php  — delete anchor

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    api_require_csrf();
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $poleFid   = isset($body['pole_fid'])  ? (int)$body['pole_fid']  : null;
    $owner     = trim($body['owner']     ?? '');
    $status    = trim($body['status']    ?? 'plan');
    $notes     = trim($body['notes']     ?? '');
    $direction = trim($body['direction'] ?? '');

    if (!$poleFid) api_err('pole_fid required', 400);

    $validStatuses   = ['plan', 'construction', 'active', 'retired'];
    $validDirections = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if ($direction && !in_array($direction, $validDirections)) api_err('Invalid direction', 400);

    $st = $pdo->prepare("
        INSERT INTO app.anchor (pole_fid, owner, status, notes, direction)
        VALUES (:pole_fid, :owner, :status::app.anchor_status, :notes, :direction)
        RETURNING id
    ");
    $st->execute([
        ':pole_fid'  => $poleFid,
        ':owner'     => $owner ?: null,
        ':status'    => $status,
        ':notes'     => $notes ?: null,
        ':direction' => $direction ?: null,
    ]);
    $id = $st->fetchColumn();
    api_ok(['id' => (int)$id]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    api_require_csrf();
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id        = isset($body['id'])       ? (int)$body['id']       : null;
    $owner     = trim($body['owner']     ?? '');
    $status    = trim($body['status']    ?? 'plan');
    $notes     = trim($body['notes']     ?? '');
    $direction = trim($body['direction'] ?? '');

    if (!$id) api_err('id required', 400);

    $validStatuses   = ['plan', 'construction', 'active', 'retired'];
    $validDirections = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if ($direction && !in_array($direction, $validDirections)) api_err('Invalid direction', 400);

    $st = $pdo->prepare("
        UPDATE app.anchor
           SET owner     = :owner,
               status    = :status::app.anchor_status,
               notes     = :notes,
               direction = :direction
         WHERE id = :id
    ");
    $st->execute([
        ':id'        => $id,
        ':owner'     => $owner ?: null,
        ':status'    => $status,
        ':notes'     => $notes ?: null,
        ':direction' => $direction ?: null,
    ]);
    if ($st->rowCount() === 0) api_err('Anchor not found', 404);
    api_ok(['id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    api_require_csrf();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = isset($body['id']) ? (int)$body['id'] : null;
    if (!$id) api_err('id required', 400);

    $st = $pdo->prepare("DELETE FROM app.anchor WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Anchor not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
