<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// api/otdr_result.php — OTDR result CRUD + link management
//
// GET  (no params)       → list ALL results (no raw_file/trace_pts)
// GET  ?fosc_id=N        → list results linked to closure N
// GET  ?id=N             → single result with events + trace_pts
// GET  ?id=N&download=1  → serve raw .sor file
// POST {id, fosc_id, cable_span_id?, fiber_num?}  → link to closure
// POST {id, unlink:true}                          → remove closure link
// DELETE ?id=N           → delete result
//
// X-CSRF-Token required for POST and DELETE.

require_once __DIR__ . '/_common.php';

$method = api_method();

// ── GET ───────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    $id      = isset($_GET['id'])      ? (int)$_GET['id']      : null;
    $foscId  = isset($_GET['fosc_id']) ? (int)$_GET['fosc_id'] : null;
    $download = !empty($_GET['download']);

    // Single result — download raw file
    if ($id !== null && $download) {
        $st = $pdo->prepare("
            SELECT filename, file_size, raw_file
            FROM app.otdr_result
            WHERE otdr_result_id = :id
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_err('Not found', 404);

        $raw = hex2bin(ltrim($row['raw_file'], '\\x'));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($row['filename']) . '"');
        header('Content-Length: ' . strlen($raw));
        echo $raw;
        exit;
    }

    // Single result — metadata + events + trace
    if ($id !== null) {
        $st = $pdo->prepare("
            SELECT r.otdr_result_id AS id,
                   r.fosc_id, r.cable_span_id, r.fiber_num,
                   r.linked_at, r.linked_by,
                   r.uploaded_by, r.uploaded_at,
                   r.filename, r.file_size,
                   r.tested_at, r.wavelength_nm, r.fiber_length_m, r.total_loss_db,
                   r.supplier, r.model, r.parsed_meta,
                   r.events, r.trace_pts,
                   f.splice_num AS fosc_splice_num
            FROM app.otdr_result r
            LEFT JOIN app.fosc f ON f.fosc_id = r.fosc_id
            WHERE r.otdr_result_id = :id
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_err('Not found', 404);

        $row['parsed_meta'] = json_decode($row['parsed_meta'] ?? 'null', true);
        $row['events']      = json_decode($row['events']      ?? '[]',   true);
        $row['trace_pts']   = json_decode($row['trace_pts']   ?? '[]',   true);

        api_ok(['result' => $row]);
    }

    // List by closure
    if ($foscId !== null) {
        $st = $pdo->prepare("
            SELECT r.otdr_result_id AS id,
                   r.fosc_id, r.cable_span_id, r.fiber_num,
                   r.linked_at, r.filename, r.file_size,
                   r.tested_at, r.wavelength_nm, r.fiber_length_m, r.total_loss_db,
                   r.supplier, r.model, r.parsed_meta,
                   fa.splice_num || ' ↔ ' || fb.splice_num AS cable_span_name
            FROM app.otdr_result r
            LEFT JOIN app.cable_span cs ON cs.cable_span_id = r.cable_span_id
            LEFT JOIN app.fosc fa ON fa.fosc_id = cs.from_fosc_id
            LEFT JOIN app.fosc fb ON fb.fosc_id = cs.to_fosc_id
            WHERE r.fosc_id = :fosc_id
            ORDER BY r.tested_at DESC NULLS LAST, r.uploaded_at DESC
        ");
        $st->execute([':fosc_id' => $foscId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['parsed_meta'] = json_decode($row['parsed_meta'] ?? 'null', true);
        }
        api_ok(['results' => $rows]);
    }

    // List all
    $st = $pdo->query("
        SELECT r.otdr_result_id AS id,
               r.fosc_id, r.cable_span_id, r.fiber_num,
               r.linked_at, r.filename, r.file_size,
               r.tested_at, r.wavelength_nm, r.fiber_length_m, r.total_loss_db,
               r.supplier, r.model, r.parsed_meta,
               f.splice_num AS fosc_splice_num,
               fa.splice_num || ' ↔ ' || fb.splice_num AS cable_span_name
        FROM app.otdr_result r
        LEFT JOIN app.fosc f  ON f.fosc_id       = r.fosc_id
        LEFT JOIN app.cable_span cs ON cs.cable_span_id = r.cable_span_id
        LEFT JOIN app.fosc fa ON fa.fosc_id = cs.from_fosc_id
        LEFT JOIN app.fosc fb ON fb.fosc_id = cs.to_fosc_id
        ORDER BY r.uploaded_at DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['parsed_meta'] = json_decode($row['parsed_meta'] ?? 'null', true);
    }
    api_ok(['results' => $rows]);
}

// ── POST (link / unlink) ──────────────────────────────────────────────────────

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $id = (int)($body['id'] ?? 0);
    if ($id < 1) api_err('id required', 422);

    // Verify record exists
    $check = $pdo->prepare("SELECT otdr_result_id FROM app.otdr_result WHERE otdr_result_id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) api_err('Not found', 404);

    $userId = (int)($_apiIdentity['app_user_id'] ?? 0);

    // Unlink
    if (!empty($body['unlink'])) {
        $pdo->prepare("
            UPDATE app.otdr_result
            SET fosc_id = NULL, cable_span_id = NULL, fiber_num = NULL,
                linked_at = NULL, linked_by = NULL
            WHERE otdr_result_id = :id
        ")->execute([':id' => $id]);
        api_ok(['id' => $id]);
    }

    // Link
    $foscId      = isset($body['fosc_id'])      ? (int)$body['fosc_id']      : null;
    $cableSpanId = isset($body['cable_span_id']) ? (int)$body['cable_span_id'] : null;
    $fiberNum    = isset($body['fiber_num'])     ? (int)$body['fiber_num']     : null;

    if ($foscId === null) api_err('fosc_id required for linking', 422);

    $pdo->prepare("
        UPDATE app.otdr_result
        SET fosc_id       = :fosc_id,
            cable_span_id = :cable_span_id,
            fiber_num     = :fiber_num,
            linked_at     = now(),
            linked_by     = :linked_by
        WHERE otdr_result_id = :id
    ")->execute([
        ':fosc_id'       => $foscId,
        ':cable_span_id' => $cableSpanId,
        ':fiber_num'     => $fiberNum,
        ':linked_by'     => $userId ?: null,
        ':id'            => $id,
    ]);

    api_ok(['id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    api_require_csrf();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id < 1) api_err('id required', 422);

    $st = $pdo->prepare("DELETE FROM app.otdr_result WHERE otdr_result_id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Not found', 404);

    api_ok(['id' => $id]);
}

api_err('Method not allowed', 405);
