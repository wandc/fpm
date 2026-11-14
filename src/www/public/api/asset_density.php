<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/asset_density.php?z=<zoom>&bbox=W,S,E,N
// Returns a GeoJSON FeatureCollection of pre-aggregated density hexagons for the
// zoomed-out map "overview" regime. Each hexagon carries a `cnt` (number of DB
// point assets inside it). `max_cnt` is the max over the whole zoom band, so the
// client's colour scale stays stable while panning.
//
// Data is served from the app.asset_density_hex cache (migration 0124), rebuilt
// by app.refresh_asset_density(). We rebuild lazily if the cache is empty or
// stale, and on bulk pole imports.

require_once __DIR__ . '/_common.php';

if (api_method() !== 'GET') {
    api_err('Method not allowed', 405);
}

/** @var PDO $pdo */

header('Cache-Control: max-age=60, private');

// Zoom is clamped to the bands precomputed by refresh_asset_density() (8..14).
$z = isset($_GET['z']) ? (int)floor((float)$_GET['z']) : 12;
if ($z < 8)  $z = 8;
if ($z > 14) $z = 14;

// Optional viewport bbox — only hexagons intersecting the envelope are returned.
$bboxSql = 'NULL';
if (isset($_GET['bbox'])) {
    $parts = array_map('floatval', explode(',', (string)$_GET['bbox']));
    if (count($parts) === 4 && count(array_filter($parts, 'is_finite')) === 4) {
        [$w, $s, $e, $n] = $parts;
        $bboxSql = sprintf('ST_MakeEnvelope(%F, %F, %F, %F, 4326)', $w, $s, $e, $n);
    }
}

// ── Lazy refresh ──────────────────────────────────────────────────────────────
// Rebuild if the cache is empty or older than the staleness window. The overview
// is approximate by nature, so brief staleness after ad-hoc edits is acceptable.
$STALE_SECONDS = 900; // 15 min
$meta = $pdo->query("
    SELECT (SELECT count(*) FROM app.asset_density_hex) AS rows,
           EXTRACT(EPOCH FROM (now() - COALESCE(
               (SELECT refreshed_at FROM app.asset_density_meta LIMIT 1),
               to_timestamp(0)))) AS age
")->fetch(PDO::FETCH_ASSOC);

if ((int)$meta['rows'] === 0 || (float)$meta['age'] > $STALE_SECONDS) {
    $pdo->query('SELECT app.refresh_asset_density()');
}

// ── Query the requested band ──────────────────────────────────────────────────
$maxCnt = (int)$pdo->query(
    "SELECT COALESCE(max(cnt), 0) FROM app.asset_density_hex WHERE zoom = {$z}"
)->fetchColumn();

$where = "zoom = {$z}";
if ($bboxSql !== 'NULL') {
    $where .= " AND ST_Intersects(geom, {$bboxSql})";
}

$st = $pdo->query("
    SELECT ST_AsGeoJSON(geom, 6) AS geojson, cnt
      FROM app.asset_density_hex
     WHERE {$where}
");

$features = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => ['cnt' => (int)$row['cnt']],
    ];
}

echo json_encode([
    'type'     => 'FeatureCollection',
    'zoom'     => $z,
    'max_cnt'  => $maxCnt,
    'features' => $features,
]);
