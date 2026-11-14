<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// tools/import_osm_roads_area.php
// Adds road geometry for an area OUTSIDE the Salisbury service territory, so
// closures there can be described by street name (see app.v_fosc_location).
//
// This is deliberately NOT import_osm_roads.php. That one truncates
// app.road_coverage and rebuilds Salisbury from the town-limits polygon; running
// it for another bbox would delete the service area. This one only ever adds.
//
// Everything imported here is in_boundary = false (shown with the map's
// "Outside Salisbury" toggle), where services such as Tinuum Expansion Target
// can be ticked on them like any other road.
//
// Safe to re-run, and safe next to roads that are already there:
//   - a way already in app.road_coverage (same osm_way_id) is skipped, so a road
//     crossing into the area from Salisbury isn't duplicated;
//   - a re-run only removes roads (whole OSM ways, every piece, even past the
//     area's edge) that touch the area and have no services on any piece, then
//     re-imports them whole. Services cascade-delete with their segment, so a
//     road someone has ticked is left exactly as it is, never partly removed.
//
// Usage: docker exec php-pg-app php /var/www/html/tools/import_osm_roads_area.php <area>

define('IS_CLI_POLLER', true);
require_once __DIR__ . '/../www/app/config.php';

// south,west,north,east — each padded past the closures it needs to cover, and
// each chosen not to overlap the Salisbury bbox (45.8827,-65.2119,46.1312,-64.8668).
$AREAS = [
    'fredericton' => ['45.9000,-66.7000,45.9800,-66.6000', 'Fredericton — FRD and KNW closures'],
    'moncton_east' => ['46.0600,-64.8500,46.1300,-64.7600', 'East Moncton — MON closures'],
    // West of Salisbury along Route 112 / Route 106 toward Petitcodiac and Havelock,
    // for expansion planning (2026-09-27). Touches the Salisbury bbox's west edge.
    'salisbury_west' => ['45.9000,-65.4500,46.2000,-65.2119', 'West of Salisbury — expansion planning'],
    // Little River (Albert County) and Parkindale, south of Colpitts Settlement
    // along Route 895; only its main roads had come in with the Salisbury import.
    'little_river_parkindale' => ['45.8200,-65.0700,45.9500,-64.9000', 'Little River / Parkindale — expansion planning'],
];

$area = $argv[1] ?? '';
if (!isset($AREAS[$area])) {
    fwrite(STDERR, "Usage: import_osm_roads_area.php <" . implode('|', array_keys($AREAS)) . ">\n");
    exit(1);
}
[$bbox, $label] = $AREAS[$area];
[$s, $w, $n, $e] = array_map('floatval', explode(',', $bbox));

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL);
$log("Area: {$area} — {$label}");

$query = "[out:json][timeout:120][bbox:{$bbox}];\n"
       . "way[\"highway\"~\"^(motorway|trunk|primary|secondary|tertiary|residential|unclassified|living_street)$\"];\n"
       . "out geom;";

// overpass-api.de throttles bursts; fall back to public mirrors of the same API.
$raw = false;
foreach (['https://overpass-api.de/api/interpreter',
          'https://overpass.private.coffee/api/interpreter',
          'https://maps.mail.ru/osm/tools/overpass/api/interpreter'] as $endpoint) {
    $raw = @file_get_contents($endpoint . '?data=' . urlencode($query), false, stream_context_create(['http' => [
        'method' => 'GET', 'header' => "User-Agent: " . org('http_agent') . "\r\n", 'timeout' => 180,
    ]]));
    if ($raw !== false && str_starts_with(ltrim($raw), '{')) break;
    $log("Overpass endpoint {$endpoint} failed; trying the next.");
    $raw = false;
}
if ($raw === false)  { fwrite(STDERR, "ERROR: Overpass unreachable.\n"); exit(1); }
$data = json_decode($raw, true);
if (!isset($data['elements'])) { fwrite(STDERR, "ERROR: unexpected Overpass response.\n"); exit(1); }

$ways = array_filter($data['elements'], fn($el) => $el['type'] === 'way' && !empty($el['geometry']));
$log('Got ' . count($ways) . ' ways.');

$pdo->beginTransaction();

// Remove only what a previous run of this same area left behind. Scoped to
// in_boundary = false and to segments wholly inside the bbox, so Salisbury's
// rows can never be caught by it even if the boxes were ever made to touch.
// Whole roads only: every piece of an OSM way (or NBRN road) that touches the
// area, is entirely outside Salisbury, and has no services on any piece is
// removed, including pieces beyond the area's edge, and then re-imported whole.
// Deleting only the pieces inside the box (as this used to) left roads that
// cross the edge with a hole that the "already present" skip never refilled.
$del = $pdo->prepare("
    DELETE FROM app.road_coverage
     WHERE osm_way_id IN (
        SELECT x.osm_way_id
          FROM app.road_coverage x
         GROUP BY x.osm_way_id
        HAVING bool_or(ST_Intersects(x.geom, ST_MakeEnvelope(:w, :s, :e, :n, 4326)))
           AND bool_and(NOT x.in_boundary)
           AND NOT EXISTS (SELECT 1 FROM app.road_coverage_service sv
                           JOIN app.road_coverage o ON o.seg_id = sv.seg_id
                           WHERE o.osm_way_id = x.osm_way_id))
");
$del->execute([':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n]);
$log('Removed ' . $del->rowCount() . ' rows of unticked roads from a previous run of this area.');
$have = array_flip($pdo->query("SELECT DISTINCT osm_way_id FROM app.road_coverage")->fetchAll(PDO::FETCH_COLUMN));

// Same ~200 m sub-segmenting as the Salisbury importer, so the table stays uniform.
$ins = $pdo->prepare("
    WITH src AS (SELECT ST_GeomFromText(:wkt, 4326) AS g),
    params AS (SELECT GREATEST(1, CEIL(ST_Length(src.g::geography) / 200)::int) AS n FROM src),
    segs AS (
        SELECT ST_LineSubstring(src.g, gs.i::float / params.n,
                                LEAST((gs.i + 1)::float / params.n, 1.0)) AS geom
        FROM src, params, generate_series(0, params.n - 1) AS gs(i)
    )
    INSERT INTO app.road_coverage (osm_way_id, road_name, highway_type, geom, in_boundary)
    SELECT :id, :name, :hw, segs.geom, false FROM segs
");

$inserted = 0; $named = 0; $skipped = 0;
foreach ($ways as $el) {
    if (count($el['geometry']) < 2) continue;
    if (isset($have[(int)$el['id']])) { $skipped++; continue; }   // already there (e.g. from the Salisbury import)
    $tags = $el['tags'] ?? [];
    $name = $tags['name'] ?? ($tags['ref'] ?? null);
    if ($name !== null) $named++;
    $ins->execute([
        ':id'   => (int)$el['id'],
        ':name' => $name,
        ':hw'   => $tags['highway'] ?? null,
        ':wkt'  => 'LINESTRING(' . implode(',', array_map(
                       fn($p) => $p['lon'] . ' ' . $p['lat'], $el['geometry'])) . ')',
    ]);
    $inserted += $ins->rowCount();
}

$pdo->commit();
$log("Done. {$inserted} sub-segments inserted from {$named} named ways; {$skipped} ways already present were left alone.");

// ── Fill gaps from GeoNB's road network (NBRN) ────────────────────────────────
// OSM misses some roads GeoNB has (W. Wilson Road, Little River: houses 60–79,
// nothing in OSM but a forest track 2 km away). NBRN is the provincial road
// network the civic addresses are built on, so add any NBRN piece in the area
// that isn't already covered by a road we have (more than half of it further
// than 25 m from every existing segment). These rows get osm_way_id = -NID, so
// a negative id always means "from NBRN, not OSM", and a re-run skips them the
// same way it skips OSM ways already present.
$nbrnUrl = 'https://geonb.snb.ca/arcgis/rest/services/GeoNB_Basemap_NBRN/MapServer/1/query';
$feats = []; $offset = 0;
do {
    $raw = @file_get_contents($nbrnUrl . '?' . http_build_query([
        'geometry' => "$w,$s,$e,$n", 'geometryType' => 'esriGeometryEnvelope', 'inSR' => 4326,
        'spatialRel' => 'esriSpatialRelIntersects', 'outFields' => 'NID,Left_Street_Name,Official_Street_Type,Func_Road_Class',
        'outSR' => 4326, 'orderByFields' => 'OBJECTID', 'resultOffset' => $offset, 'resultRecordCount' => 1000, 'f' => 'geojson',
    ]), false, stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: " . org('http_agent') . "\r\n"]]));
    $page = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($page) || !isset($page['features'])) { $log('NBRN: request failed; OSM roads were still imported.'); exit(0); }
    $feats = array_merge($feats, $page['features']);
    $offset += count($page['features']);
} while (count($page['features']) > 0 && !empty($page['properties']['exceededTransferLimit'] ?? $page['exceededTransferLimit'] ?? false));
$log('NBRN: ' . count($feats) . ' road pieces in the area.');

// Re-evaluate every NBRN road this area returned, even the parts past its
// edge (NBRN returns whole lines): drop our earlier copy unless something on it
// is ticked, then decide afresh what's still a gap.
$ids = array_values(array_unique(array_filter(array_map(fn($f) => -(int)($f['properties']['NID'] ?? 0), $feats))));
if ($ids) {
    $d = $pdo->prepare("
        DELETE FROM app.road_coverage rc
         WHERE rc.osm_way_id = ANY(?::bigint[])
           AND NOT EXISTS (SELECT 1 FROM app.road_coverage_service sv
                           JOIN app.road_coverage o ON o.seg_id = sv.seg_id
                           WHERE o.osm_way_id = rc.osm_way_id)");
    $d->execute(['{' . implode(',', $ids) . '}']);
    $log('NBRN: cleared ' . $d->rowCount() . ' unticked rows from an earlier run.');
}
$have = array_flip($pdo->query("SELECT DISTINCT osm_way_id FROM app.road_coverage")->fetchAll(PDO::FETCH_COLUMN));
// Only the parts of an NBRN line we don't already have are added: whatever is
// further than 25 m from every road we have, and further than 75 m from any
// road of the same name (OSM and NBRN draw some roads 25-60 m apart, e.g.
// Buckley Road, and those are the same road). NBRN lines can be kilometres
// long and only partly missing, so this works on parts, not whole lines;
// leftovers under 50 m are noise at junctions and are dropped.
$gap = $pdo->prepare("
    WITH g AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:gj), 4326) AS g),
    cover AS (
        SELECT ST_Union(ST_Buffer(rc.geom::geography,
                   CASE WHEN lower(rc.road_name) = lower(:name) THEN 75 ELSE 25 END)::geometry) AS b
        FROM app.road_coverage rc, g WHERE ST_DWithin(rc.geom, g.g, 0.002)),
    rest AS (SELECT CASE WHEN cover.b IS NULL THEN g.g ELSE ST_Difference(g.g, cover.b) END AS r FROM g, cover)
    SELECT ST_AsText(d.geom)
    FROM rest, ST_Dump(ST_LineMerge(ST_CollectionExtract(rest.r, 2))) d
    WHERE ST_Length(d.geom::geography) >= 50");
$added = 0; $pieces = 0;
$pdo->beginTransaction();
foreach ($feats as $f) {
    $p = $f['properties'] ?? [];
    $geom = $f['geometry'] ?? null;
    if (!$geom || empty($p['NID'])) continue;
    $id = -(int)$p['NID'];
    if (isset($have[$id])) continue;
    // NBRN pieces are single lines; a multi-part one is imported part by part.
    $lines = $geom['type'] === 'MultiLineString' ? $geom['coordinates'] : [$geom['coordinates']];
    $street = trim(ucwords(strtolower((string)($p['Left_Street_Name'] ?? '')), " -'."));
    $type   = (string)($p['Official_Street_Type'] ?? '');
    $name   = $street === '' ? null : (preg_match('/^(route|highway)\b/i', $street) ? $street : trim("$street $type"));
    foreach ($lines as $coords) {
        if (count($coords) < 2) continue;
        $gj = json_encode(['type' => 'LineString', 'coordinates' => $coords]);
        $gap->execute([':gj' => $gj, ':name' => (string)$name]);
        foreach ($gap->fetchAll(PDO::FETCH_COLUMN) as $wkt) {
            $ins->execute([':id' => $id, ':name' => $name, ':hw' => 'unclassified', ':wkt' => $wkt]);
            $pieces += $ins->rowCount();
            $added++;
        }
    }
    $have[$id] = true;
}
$pdo->commit();
$log("NBRN: filled {$added} gaps OSM didn't have ({$pieces} sub-segments).");
