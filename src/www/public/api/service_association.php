<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/service_association.php
// POST   { crm_service_id, crm_client_id, fosc_id, notes? } — create/update prepared service→closure link
// PUT    { crm_service_id, geojson: LineString|null }        — save or clear the drop route
// DELETE ?crm_service_id=<int>                               — remove link
//
// Mirrors lead_association.php, but keys on the CRM *service* so one client can
// hold several pending installs that each feed from a different closure.

require_once __DIR__ . '/_common.php';

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $crmServiceId = isset($body['crm_service_id']) ? (int)$body['crm_service_id'] : 0;
    $crmClientId  = isset($body['crm_client_id'])  ? (int)$body['crm_client_id']  : 0;
    $foscId       = isset($body['fosc_id'])         ? (int)$body['fosc_id']        : 0;
    $notes        = isset($body['notes'])           ? trim((string)$body['notes']) : null;

    if ($crmServiceId <= 0) {
        api_err('crm_service_id is required');
    }
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
        'INSERT INTO app.service_association (crm_service_id, crm_client_id, fosc_id, notes, updated_at)
         VALUES (?, ?, ?, ?, now())
         ON CONFLICT (crm_service_id) DO UPDATE
           SET crm_client_id = EXCLUDED.crm_client_id,
               fosc_id       = EXCLUDED.fosc_id,
               notes         = EXCLUDED.notes,
               updated_at    = now()
         RETURNING service_association_id'
    );
    $st->execute([$crmServiceId, $crmClientId, $foscId, $notes ?: null]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    api_ok(['service_association_id' => (int)$row['service_association_id']]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $body = api_json_body();

    $crmServiceId = isset($body['crm_service_id']) ? (int)$body['crm_service_id'] : 0;
    if ($crmServiceId <= 0) api_err('crm_service_id is required');

    if (array_key_exists('geojson', $body) && $body['geojson'] !== null) {
        $geojson = json_encode($body['geojson']);
        $st = $pdo->prepare(
            'UPDATE app.service_association
                SET route_geom = ST_GeomFromGeoJSON(:geojson), updated_at = now()
              WHERE crm_service_id = :id'
        );
        $st->execute([':geojson' => $geojson, ':id' => $crmServiceId]);
    } else {
        $st = $pdo->prepare(
            'UPDATE app.service_association
                SET route_geom = NULL, updated_at = now()
              WHERE crm_service_id = :id'
        );
        $st->execute([':id' => $crmServiceId]);
    }
    api_ok(['updated' => $st->rowCount()]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $crmServiceId = (int)($_GET['crm_service_id'] ?? 0);

    if ($crmServiceId <= 0) {
        api_err('crm_service_id is required');
    }

    $st = $pdo->prepare('DELETE FROM app.service_association WHERE crm_service_id = ?');
    $st->execute([$crmServiceId]);

    api_ok(['deleted' => $st->rowCount()]);
}

api_err('Method not allowed', 405);
