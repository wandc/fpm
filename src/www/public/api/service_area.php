<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/service_area.php
// GET — GeoJSON FeatureCollection of Tinuum service area (75 m buffered road polygons).
// Two features: status=active (fiber_ftth) and status=coming (fiber_coming_soon).
// Public endpoint: CORS enabled, no auth required.

// Public endpoint — no session auth required. Load only DB config.
require_once __DIR__ . '/../../app/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Cache-Control: max-age=300, public');
header('Content-Type: application/json');

$rows = $pdo->query("
    SELECT
        CASE WHEN rcs.technology = 'fiber_ftth' THEN 'active' ELSE 'coming' END AS status,
        ST_AsGeoJSON(
            ST_Union(ST_Buffer(rc.geom::geography, 75)::geometry)
        ) AS geojson
    FROM app.road_coverage rc
    JOIN app.road_coverage_service rcs ON rcs.seg_id = rc.seg_id
    WHERE rcs.provider = app.own_name()
      AND rcs.technology IN ('fiber_ftth', 'fiber_coming_soon')
    GROUP BY status
    ORDER BY status
")->fetchAll(PDO::FETCH_ASSOC);

$features = [];
foreach ($rows as $row) {
    if ($row['geojson'] === null) continue;
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode($row['geojson']),
        'properties' => ['status' => $row['status']],
    ];
}

echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);
