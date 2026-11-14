<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/ont_association.php
// POST  { uisp_device_id, fosc_id, notes? } — create/update ONT→closure link
// DELETE ?uisp_device_id=<uuid>             — remove link

require_once __DIR__ . '/_common.php';

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $deviceId = trim((string)($body['uisp_device_id'] ?? ''));
    $foscId   = isset($body['fosc_id']) ? (int)$body['fosc_id'] : 0;
    $notes    = isset($body['notes']) ? trim((string)$body['notes']) : null;

    // Validate UUID format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        api_err('Invalid uisp_device_id (must be a UUID)');
    }
    if ($foscId <= 0) {
        api_err('fosc_id is required');
    }

    // Verify FOSC exists
    $st = $pdo->prepare('SELECT fosc_id FROM app.fosc WHERE fosc_id = ?');
    $st->execute([$foscId]);
    if (!$st->fetch()) {
        api_err('fosc_id not found', 404);
    }

    $st = $pdo->prepare(
        'INSERT INTO app.ont_association (uisp_device_id, fosc_id, notes, updated_at)
         VALUES (?::uuid, ?, ?, now())
         ON CONFLICT (uisp_device_id) DO UPDATE
           SET fosc_id    = EXCLUDED.fosc_id,
               notes      = EXCLUDED.notes,
               updated_at = now()
         RETURNING ont_association_id'
    );
    $st->execute([$deviceId, $foscId, $notes ?: null]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    api_ok(['ont_association_id' => (int)$row['ont_association_id']]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $body = api_json_body();

    $deviceId = trim((string)($body['uisp_device_id'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        api_err('Invalid uisp_device_id');
    }

    if (array_key_exists('geojson', $body) && $body['geojson'] !== null) {
        $geojson = json_encode($body['geojson']);
        $st = $pdo->prepare(
            'UPDATE app.ont_association
                SET route_geom = ST_GeomFromGeoJSON(:geojson), updated_at = now()
              WHERE uisp_device_id = :id::uuid'
        );
        $st->execute([':geojson' => $geojson, ':id' => $deviceId]);
    } else {
        $st = $pdo->prepare(
            'UPDATE app.ont_association
                SET route_geom = NULL, updated_at = now()
              WHERE uisp_device_id = :id::uuid'
        );
        $st->execute([':id' => $deviceId]);
    }

    api_ok(['updated' => $st->rowCount()]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $deviceId = trim((string)($_GET['uisp_device_id'] ?? ''));

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        api_err('Invalid uisp_device_id');
    }

    $st = $pdo->prepare(
        'DELETE FROM app.ont_association WHERE uisp_device_id = ?::uuid'
    );
    $st->execute([$deviceId]);

    api_ok(['deleted' => $st->rowCount()]);
}

api_err('Method not allowed', 405);
