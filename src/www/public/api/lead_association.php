<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/lead_association.php
// POST  { crm_client_id, fosc_id, notes? } — create/update lead→closure link
// DELETE ?crm_client_id=<int>              — remove link

require_once __DIR__ . '/_common.php';

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $crmClientId = isset($body['crm_client_id']) ? (int)$body['crm_client_id'] : 0;
    $foscId      = isset($body['fosc_id'])        ? (int)$body['fosc_id']       : 0;
    $notes       = isset($body['notes'])           ? trim((string)$body['notes']) : null;

    if ($crmClientId <= 0) {
        api_err('crm_client_id is required');
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
        'INSERT INTO app.lead_association (crm_client_id, fosc_id, notes, updated_at)
         VALUES (?, ?, ?, now())
         ON CONFLICT (crm_client_id) DO UPDATE
           SET fosc_id    = EXCLUDED.fosc_id,
               notes      = EXCLUDED.notes,
               updated_at = now()
         RETURNING lead_association_id'
    );
    $st->execute([$crmClientId, $foscId, $notes ?: null]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    api_ok(['lead_association_id' => (int)$row['lead_association_id']]);
}

if ($method === 'PUT') {
    // PUT { crm_client_id, geojson: LineString|null } — save or clear drop route
    api_require_csrf();
    $body = api_json_body();

    $crmClientId = isset($body['crm_client_id']) ? (int)$body['crm_client_id'] : 0;
    if ($crmClientId <= 0) api_err('crm_client_id is required');

    if (array_key_exists('geojson', $body) && $body['geojson'] !== null) {
        $geojson = json_encode($body['geojson']);
        $st = $pdo->prepare(
            'UPDATE app.lead_association
                SET route_geom = ST_GeomFromGeoJSON(:geojson), updated_at = now()
              WHERE crm_client_id = :id'
        );
        $st->execute([':geojson' => $geojson, ':id' => $crmClientId]);
    } else {
        $st = $pdo->prepare(
            'UPDATE app.lead_association
                SET route_geom = NULL, updated_at = now()
              WHERE crm_client_id = :id'
        );
        $st->execute([':id' => $crmClientId]);
    }
    api_ok(['updated' => $st->rowCount()]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $crmClientId = (int)($_GET['crm_client_id'] ?? 0);

    if ($crmClientId <= 0) {
        api_err('crm_client_id is required');
    }

    $st = $pdo->prepare('DELETE FROM app.lead_association WHERE crm_client_id = ?');
    $st->execute([$crmClientId]);

    api_ok(['deleted' => $st->rowCount()]);
}

api_err('Method not allowed', 405);
