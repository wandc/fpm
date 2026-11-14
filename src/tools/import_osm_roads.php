<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// tools/import_osm_roads.php
// Fetches road geometries for the Salisbury NB area from the Overpass API
// and imports them into app.road_coverage, splitting each OSM way into
// sub-segments of at most ~200 m using PostGIS ST_LineSubstring.
// Existing coverage service assignments are LOST on re-import (fresh truncate).
// Run via: docker exec php-pg-app php /var/www/html/tools/import_osm_roads.php

define('IS_CLI_POLLER', true);
require_once __DIR__ . '/../www/app/config.php';

// Bbox derived from SalisburyTownLimits.geojson extent + 0.01° buffer
$bbox  = '45.8827,-65.2119,46.1312,-64.8668';
$query = "[out:json][bbox:{$bbox}];\nway[\"highway\"~\"^(primary|secondary|tertiary|residential|unclassified|living_street)$\"];\nout geom;";

echo '[' . date('Y-m-d H:i:s') . '] Querying Overpass API...' . PHP_EOL;

$url = 'https://overpass-api.de/api/interpreter?data=' . urlencode($query);
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "User-Agent: " . org('http_agent') . "\r\n",
    'timeout' => 60,
]]);

$raw = file_get_contents($url, false, $ctx);
if ($raw === false) {
    echo 'ERROR: Failed to reach Overpass API.' . PHP_EOL;
    exit(1);
}

$data = json_decode($raw, true);
if (!isset($data['elements'])) {
    echo 'ERROR: Unexpected Overpass response.' . PHP_EOL;
    exit(1);
}

$elements = array_filter($data['elements'], fn($e) => $e['type'] === 'way' && !empty($e['geometry']));
echo '[' . date('Y-m-d H:i:s') . '] Got ' . count($elements) . ' road ways. Importing...' . PHP_EOL;

// Load Salisbury town limits as spatial filter
$gjData   = json_decode(file_get_contents(__DIR__ . '/../www/public/data/SalisburyTownLimits.geojson'), true);
$boundary = json_encode($gjData['features'][0]['geometry']);

$pdo->beginTransaction();

// Snapshot existing service assignments before truncating, preserving geometry
// so we can re-match them to new segments after re-import.
$pdo->exec("
    CREATE TEMP TABLE _saved_services AS
    SELECT rcs.provider, rcs.technology, rcs.notes, rc.geom AS seg_geom
    FROM app.road_coverage_service rcs
    JOIN app.road_coverage rc ON rc.seg_id = rcs.seg_id
");
$savedCount = (int)$pdo->query('SELECT count(*) FROM _saved_services')->fetchColumn();
echo '[' . date('Y-m-d H:i:s') . '] Saved ' . $savedCount . ' existing service records.' . PHP_EOL;

$pdo->exec('TRUNCATE app.road_coverage CASCADE');

// Split each way at ~200 m intervals, keeping only sub-segments that
// intersect (or are within) the Salisbury town limits polygon.
$st = $pdo->prepare("
    WITH src AS (
        SELECT ST_GeomFromText(:wkt, 4326) AS g
    ),
    params AS (
        SELECT GREATEST(1, CEIL(ST_Length(src.g::geography) / 200)::int) AS n
        FROM src
    ),
    boundary AS (
        SELECT ST_GeomFromGeoJSON(:boundary) AS geom
    ),
    segs AS (
        SELECT ST_LineSubstring(src.g,
                   gs.i::float / params.n,
                   LEAST((gs.i + 1)::float / params.n, 1.0)) AS geom
        FROM src, params, generate_series(0, params.n - 1) AS gs(i)
    )
    INSERT INTO app.road_coverage (osm_way_id, road_name, highway_type, geom, in_boundary)
    SELECT :id, :name, :hw, segs.geom,
           ST_Intersects(segs.geom, boundary.geom)
    FROM segs, boundary
");

$inserted = 0;
foreach ($elements as $el) {
    $nodes = $el['geometry'];
    if (count($nodes) < 2) continue;

    $pts = implode(',', array_map(fn($n) => $n['lon'] . ' ' . $n['lat'], $nodes));
    $wkt = 'LINESTRING(' . $pts . ')';

    $tags = $el['tags'] ?? [];
    $name = $tags['name'] ?? ($tags['ref'] ?? null);
    $hw   = $tags['highway'] ?? null;

    $st->execute([
        ':id'       => (int)$el['id'],
        ':name'     => $name,
        ':hw'       => $hw,
        ':wkt'      => $wkt,
        ':boundary' => $boundary,
    ]);
    $inserted += $st->rowCount();
}

// Re-match saved services to new segments using spatial proximity.
// For each saved service, find every new segment within 10 m of the old geometry.
$restored = $pdo->exec("
    INSERT INTO app.road_coverage_service (seg_id, provider, technology, notes)
    SELECT DISTINCT rc.seg_id, ss.provider, ss.technology, ss.notes
    FROM _saved_services ss
    JOIN app.road_coverage rc
      ON ST_DWithin(rc.geom::geography, ss.seg_geom::geography, 10)
    ON CONFLICT (seg_id, provider, technology) DO NOTHING
");

$pdo->commit();
echo '[' . date('Y-m-d H:i:s') . '] Done. ' . $inserted . ' sub-segments inserted.' . PHP_EOL;
echo '[' . date('Y-m-d H:i:s') . '] Service records restored: ' . $restored . PHP_EOL;
