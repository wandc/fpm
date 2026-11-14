<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/ont_drops.php — GET only
// Returns a GeoJSON FeatureCollection of "drop spans" — lines from each
// associated ONT's UISP-reported location to its linked FOSC closure.
// ONT geometry comes from UISP (never stored in our DB); FOSC geometry from DB.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

if (api_method() !== 'GET') {
    api_err('Method not allowed', 405);
}

header('Cache-Control: max-age=30, private');

// Load all associations with their FOSC locations
$st = $pdo->query(
    'SELECT oa.uisp_device_id::text,
            oa.fosc_id,
            oa.notes,
            f.splice_num AS fosc_splice_num,
            ST_X(f.location) AS fosc_lon,
            ST_Y(f.location) AS fosc_lat,
            CASE WHEN oa.route_geom IS NOT NULL THEN ST_AsGeoJSON(oa.route_geom) END AS route_geojson
       FROM app.ont_association oa
       JOIN app.fosc f ON f.fosc_id = oa.fosc_id
      WHERE f.location IS NOT NULL'
);
$associations = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($associations)) {
    echo json_encode(['type' => 'FeatureCollection', 'features' => []]);
    exit;
}

// Get ONT locations from UISP cache
$devices = uisp_fetch_cached('devices', 120, $pdo);
$sites   = uisp_fetch_cached('sites?type=endpoint', 3600, $pdo);
if (isset($devices['error'])) {
    // Return empty rather than an error — drops are informational only
    echo json_encode(['type' => 'FeatureCollection', 'features' => []]);
    exit;
}

// Index devices and sites by UUID for O(1) lookup
$deviceById = [];
foreach ($devices as $dev) {
    $devId = $dev['identification']['id'] ?? null;
    if ($devId) $deviceById[$devId] = $dev;
}
$siteById = [];
foreach ((isset($sites['error']) ? [] : $sites) as $site) {
    $sid = $site['id'] ?? null;
    if ($sid) $siteById[$sid] = $site;
}

$features = [];
foreach ($associations as $assoc) {
    $devId = $assoc['uisp_device_id'];
    $dev   = $deviceById[$devId] ?? null;
    if ($dev === null) continue; // device no longer in UISP

    $id      = $dev['identification'] ?? [];
    $siteId  = $id['site']['id'] ?? null;
    $siteRec = $siteId ? ($siteById[$siteId] ?? null) : null;

    // Prefer site-level NMS GPS over device-level GPS (matches uisp_onos.php)
    $siteLoc = $siteRec ? ($siteRec['description']['location'] ?? null) : null;
    $lat = ($siteLoc['latitude']  ?? null) ?? ($dev['location']['latitude']  ?? null);
    $lon = ($siteLoc['longitude'] ?? null) ?? ($dev['location']['longitude'] ?? null);
    if ($lat === null || $lon === null) continue; // ONT has no location
    $ov  = $dev['overview'] ?? [];

    $routed = $assoc['route_geojson'] !== null;
    $geometry = $routed
        ? json_decode($assoc['route_geojson'], true)
        : [
            'type'        => 'LineString',
            'coordinates' => [
                [(float)$lon,               (float)$lat],
                [(float)$assoc['fosc_lon'], (float)$assoc['fosc_lat']],
            ],
        ];

    $features[] = [
        'type'     => 'Feature',
        'geometry' => $geometry,
        'properties' => [
            'uisp_device_id'  => $devId,
            'fosc_id'         => (int)$assoc['fosc_id'],
            'fosc_splice_num' => $assoc['fosc_splice_num'],
            'fosc_lat'        => (float)$assoc['fosc_lat'],
            'fosc_lon'        => (float)$assoc['fosc_lon'],
            'ont_lat'         => (float)$lat,
            'ont_lon'         => (float)$lon,
            'ont_name'        => $id['site']['name'] ?? $id['name'] ?? '',
            'ont_signal'      => isset($ov['signal']) ? (int)$ov['signal'] : null,
            'ont_status'      => $ov['status'] ?? 'unknown',
            'notes'           => $assoc['notes'],
            'routed'          => $routed,
        ],
    ];
}

// Build index of associated ONU GPS locations to suppress duplicate lead drops
// for the same physical address (e.g. a prospect who became a customer).
$associatedOnuPoints = array_map(
    fn($f) => [(float)$f['properties']['ont_lat'], (float)$f['properties']['ont_lon']],
    $features
);

// ── Lead drop lines ──────────────────────────────────────────────────────────
// Lead GPS comes from UCRM; FOSC GPS from DB. No UISP devices involved.
$leadAssocs = $pdo->query(
    'SELECT la.crm_client_id, la.fosc_id, la.notes,
            f.splice_num AS fosc_splice_num,
            ST_X(f.location) AS fosc_lon,
            ST_Y(f.location) AS fosc_lat,
            CASE WHEN la.route_geom IS NOT NULL THEN ST_AsGeoJSON(la.route_geom) END AS route_geojson
       FROM app.lead_association la
       JOIN app.fosc f ON f.fosc_id = la.fosc_id
      WHERE f.location IS NOT NULL'
)->fetchAll(PDO::FETCH_ASSOC);

if (!empty($leadAssocs)) {
    $ucrmClients = ucrm_fetch_cached('clients?limit=500', 300, $pdo);
    $clientById  = [];
    foreach (is_array($ucrmClients) && !isset($ucrmClients['error']) ? $ucrmClients : [] as $c) {
        $clientById[(int)$c['id']] = $c;
    }

    foreach ($leadAssocs as $assoc) {
        $client = $clientById[(int)$assoc['crm_client_id']] ?? null;
        if (!$client) continue;
        $lat = $client['addressGpsLat'] ?? null;
        $lon = $client['addressGpsLon'] ?? null;
        if ($lat === null || $lon === null) continue;

        // Skip lead drop if an associated ONU is at the same address (within 50 m)
        $suppressed = false;
        foreach ($associatedOnuPoints as [$onuLat, $onuLon]) {
            $dlat = ((float)$lat - $onuLat) * 111320;
            $dlon = ((float)$lon - $onuLon) * 111320 * cos(deg2rad((float)$lat));
            if (sqrt($dlat * $dlat + $dlon * $dlon) < 50) { $suppressed = true; break; }
        }
        if ($suppressed) continue;

        $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
                ?: ($client['companyName'] ?? '');

        $routed = $assoc['route_geojson'] !== null;
        $geometry = $routed
            ? json_decode($assoc['route_geojson'], true)
            : [
                'type'        => 'LineString',
                'coordinates' => [
                    [(float)$lon,               (float)$lat],
                    [(float)$assoc['fosc_lon'], (float)$assoc['fosc_lat']],
                ],
            ];

        $features[] = [
            'type'     => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'drop_type'       => 'lead',
                'crm_client_id'   => (int)$assoc['crm_client_id'],
                'fosc_id'         => (int)$assoc['fosc_id'],
                'fosc_splice_num' => $assoc['fosc_splice_num'],
                'fosc_lat'        => (float)$assoc['fosc_lat'],
                'fosc_lon'        => (float)$assoc['fosc_lon'],
                'ont_lat'         => (float)$lat,
                'ont_lon'         => (float)$lon,
                'ont_name'        => $name,
                'notes'           => $assoc['notes'],
                'routed'          => $routed,
            ],
        ];
    }
}

// ── Prepared-service drop lines ──────────────────────────────────────────────
// A sold-but-unbuilt service has no ONU, so its premises GPS comes from the CRM
// service record. These are the drops that still have to be spliced.
$svcAssocs = $pdo->query(
    'SELECT sa.crm_service_id, sa.crm_client_id, sa.notes,
            sa.fosc_id,
            f.splice_num AS fosc_splice_num,
            ST_X(f.location) AS fosc_lon,
            ST_Y(f.location) AS fosc_lat,
            CASE WHEN sa.route_geom IS NOT NULL THEN ST_AsGeoJSON(sa.route_geom) END AS route_geojson
       FROM app.service_association sa
       JOIN app.fosc f ON f.fosc_id = sa.fosc_id
      WHERE f.location IS NOT NULL'
)->fetchAll(PDO::FETCH_ASSOC);

if (!empty($svcAssocs)) {
    $preparedSvcs = ucrm_fetch_cached('clients/services?statuses[]=0&limit=500', 300, $pdo);
    $svcById = [];
    foreach (is_array($preparedSvcs) && !isset($preparedSvcs['error']) ? $preparedSvcs : [] as $s) {
        $svcById[(int)$s['id']] = $s;
    }
    $ucrmClients = ucrm_fetch_cached('clients?limit=500', 300, $pdo);
    $clientById2 = [];
    foreach (is_array($ucrmClients) && !isset($ucrmClients['error']) ? $ucrmClients : [] as $c) {
        $clientById2[(int)$c['id']] = $c;
    }

    foreach ($svcAssocs as $assoc) {
        // Service left the prepared state (installed, or cancelled) — its link is stale.
        $svc = $svcById[(int)$assoc['crm_service_id']] ?? null;
        if (!$svc) continue;
        $lat = $svc['addressGpsLat'] ?? null;
        $lon = $svc['addressGpsLon'] ?? null;
        if ($lat === null || $lon === null) continue;

        $client = $clientById2[(int)$assoc['crm_client_id']] ?? null;
        $name   = $client
            ? (trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
               ?: ($client['companyName'] ?? ''))
            : ('Service #' . $assoc['crm_service_id']);

        $routed   = $assoc['route_geojson'] !== null;
        $geometry = $routed
            ? json_decode($assoc['route_geojson'], true)
            : [
                'type'        => 'LineString',
                'coordinates' => [
                    [(float)$lon,               (float)$lat],
                    [(float)$assoc['fosc_lon'], (float)$assoc['fosc_lat']],
                ],
            ];

        $features[] = [
            'type'     => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'drop_type'       => 'service',
                'crm_service_id'  => (int)$assoc['crm_service_id'],
                'crm_client_id'   => (int)$assoc['crm_client_id'],
                'fosc_id'         => (int)$assoc['fosc_id'],
                'fosc_splice_num' => $assoc['fosc_splice_num'],
                'fosc_lat'        => (float)$assoc['fosc_lat'],
                'fosc_lon'        => (float)$assoc['fosc_lon'],
                'ont_lat'         => (float)$lat,
                'ont_lon'         => (float)$lon,
                'ont_name'        => $name . ' — ' . ($svc['name'] ?? 'service'),
                'notes'           => $assoc['notes'],
                'routed'          => $routed,
            ],
        ];
    }
}

echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);
