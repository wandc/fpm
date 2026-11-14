<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// api/otdr_upload.php — Upload one or more .sor OTDR files
//
// POST multipart/form-data: files[] (one or more .sor files)
// X-CSRF-Token header required.
//
// Returns JSON: { ok: true, ids: [N, ...], results: [{id, filename, tested_at, ...}, ...] }

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/otdr.helper.php';

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();

$userId = (int)($_apiIdentity['app_user_id'] ?? 0);

if (empty($_FILES['files'])) {
    api_err('No files uploaded', 422);
}

// Normalise $_FILES['files'] into a flat list
$files = $_FILES['files'];
$count = is_array($files['name']) ? count($files['name']) : 1;

$ids     = [];
$results = [];
$errors  = [];

for ($i = 0; $i < $count; $i++) {
    $name     = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
    $tmpName  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
    $error    = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];
    $size     = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];

    // ── Validate ──────────────────────────────────────────────────────────────

    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = "$name: upload error code $error";
        continue;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'sor') {
        $errors[] = "$name: only .sor files are accepted";
        continue;
    }

    if ($size > 20 * 1024 * 1024) {
        $errors[] = "$name: file exceeds 20 MB limit";
        continue;
    }

    $raw = file_get_contents($tmpName);
    if ($raw === false || strlen($raw) < 10) {
        $errors[] = "$name: could not read file";
        continue;
    }

    // ── Duplicate check ───────────────────────────────────────────────────────

    $hash = hash('sha256', $raw);

    $dup = $pdo->prepare("
        SELECT otdr_result_id, filename FROM app.otdr_result WHERE file_hash = :h
    ");
    $dup->execute([':h' => $hash]);
    if ($dupRow = $dup->fetch(PDO::FETCH_ASSOC)) {
        $errors[] = "$name: duplicate of existing record #{$dupRow['otdr_result_id']} ({$dupRow['filename']})";
        continue;
    }

    // ── Parse ─────────────────────────────────────────────────────────────────

    $parsed = otdr_parse_sor($raw);

    // ── Insert ────────────────────────────────────────────────────────────────

    $testedAt    = $parsed['tested_at']     ? new DateTime($parsed['tested_at']) : null;
    $testedAtStr = $testedAt ? $testedAt->format('Y-m-d H:i:sP') : null;

    $parsedMeta = json_encode([
        'version'        => $parsed['version'],
        'unit'           => $parsed['unit'],
        'cable_id'       => $parsed['cable_id'] ?? null,
        'fiber_id'       => $parsed['fiber_id'] ?? null,
        'dx_m'           => $parsed['dx_m'],
        'launch_cable_m' => $parsed['launch_cable_m'] ?? null,
    ]);

    $st = $pdo->prepare("
        INSERT INTO app.otdr_result
            (uploaded_by, filename, file_size, file_hash, raw_file,
             tested_at, wavelength_nm, fiber_length_m, total_loss_db,
             supplier, model, parsed_meta, events, trace_pts)
        VALUES
            (:uploaded_by, :filename, :file_size, :file_hash, :raw_file,
             :tested_at, :wavelength_nm, :fiber_length_m, :total_loss_db,
             :supplier, :model, :parsed_meta, :events, :trace_pts)
        RETURNING otdr_result_id
    ");

    $st->execute([
        ':uploaded_by'    => $userId ?: null,
        ':filename'       => $name,
        ':file_size'      => $size,
        ':file_hash'      => $hash,
        ':raw_file'       => '\x' . bin2hex($raw),
        ':tested_at'      => $testedAtStr,
        ':wavelength_nm'  => $parsed['wavelength_nm'],
        ':fiber_length_m' => $parsed['fiber_length_m'],
        ':total_loss_db'  => $parsed['total_loss_db'],
        ':supplier'       => $parsed['supplier'],
        ':model'          => $parsed['model'],
        ':parsed_meta'    => $parsedMeta,
        ':events'         => json_encode($parsed['events']),
        ':trace_pts'      => json_encode($parsed['trace']),
    ]);

    $newId = (int)$st->fetchColumn();
    $ids[] = $newId;

    $results[] = [
        'id'             => $newId,
        'filename'       => $name,
        'tested_at'      => $parsed['tested_at'],
        'wavelength_nm'  => $parsed['wavelength_nm'],
        'fiber_length_m' => $parsed['fiber_length_m'],
        'total_loss_db'  => $parsed['total_loss_db'],
        'supplier'       => $parsed['supplier'],
        'model'          => $parsed['model'],
    ];
}

if (empty($ids) && !empty($errors)) {
    api_err('All uploads failed: ' . implode('; ', $errors), 422);
}

$resp = ['ok' => true, 'ids' => $ids, 'results' => $results];
if (!empty($errors)) {
    $resp['warnings'] = $errors;
}
echo json_encode($resp);
