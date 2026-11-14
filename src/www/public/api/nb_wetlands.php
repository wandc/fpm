<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/nb_wetlands.php?bbox=W,S,E,N&z=<zoom>   wetland polygons in view (+ 30 m band from zoom 15)
// GET /api/nb_wetlands.php?at=<lon>,<lat>           is this point in a wetland, or within 30 m of one?
//
// GeoNB wetlands from app.nb_wetland / app.nb_wetland_psw (migration 0243),
// mirrored by tools/sync_geonb.php. Work within 30 m of a wetland needs a WAWA
// permit, so the band is drawn too; it is computed here, per view, from the
// simplified polygons and merged so neighbouring wetlands give one outline.

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

if (api_method() !== 'GET') api_err('Method not allowed', 405);
header('Cache-Control: max-age=600, private');

// ── Point lookup ──────────────────────────────────────────────────────────────
if (isset($_GET['at'])) {
    [$lon, $lat] = array_map('floatval', explode(',', (string)$_GET['at']) + [0, 0]);
    $st = $pdo->prepare("
        WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) AS g),
        w AS (
            SELECT true AS psw, wc, wetland_class, hectares, geom FROM app.nb_wetland_psw
            UNION ALL
            SELECT false, wc, wetland_class, hectares, geom FROM app.nb_wetland
        )
        SELECT w.psw, w.wetland_class, round(w.hectares::numeric, 2) AS hectares,
               round(ST_Distance(w.geom::geography, p.g::geography)::numeric, 1) AS dist_m
        FROM w, p
        WHERE ST_DWithin(w.geom, p.g, 0.0005)               -- ~40 m prefilter on the index
          AND ST_DWithin(w.geom::geography, p.g::geography, 30)
        ORDER BY dist_m, w.psw DESC
        LIMIT 5");
    $st->execute([':lon' => $lon, ':lat' => $lat]);
    api_ok(['wetlands' => $st->fetchAll()]);
}

// ── Polygons in view ──────────────────────────────────────────────────────────
$parts = array_map('floatval', explode(',', (string)($_GET['bbox'] ?? '')));
if (count($parts) !== 4) api_err('bbox=W,S,E,N required', 400);
[$w, $s, $e, $n] = $parts;
$z = max(10, min(22, (int)($_GET['z'] ?? 14)));
if ($z < 13) api_err('Zoom in (13+)', 400);
$tol = 360 / (256 * 2 ** $z) / 2;        // about half a pixel

$st = $pdo->prepare("
    WITH env AS (SELECT ST_MakeEnvelope(:w, :s, :e, :n, 4326) AS g)
    SELECT psw, wetland_class, hectares,
           ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tol), 6) AS g
    FROM (
        SELECT true AS psw, wetland_class, round(hectares::numeric, 1) AS hectares, geom FROM app.nb_wetland_psw, env WHERE geom && env.g
        UNION ALL
        SELECT false, wetland_class, round(hectares::numeric, 1), geom FROM app.nb_wetland, env WHERE geom && env.g
    ) x
    LIMIT 4000");
$st->execute([':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n, ':tol' => $tol]);
$rows = $st->fetchAll(PDO::FETCH_NUM);

// The 30 m regulated band, merged across the view. Only from zoom 15, where
// it is a few pixels wide and worth drawing.
$band = null;
if ($z >= 15) {
    $b = $pdo->prepare("
        WITH env AS (SELECT ST_MakeEnvelope(:w, :s, :e, :n, 4326) AS g)
        SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(
                 ST_Union(ST_Buffer(ST_SimplifyPreserveTopology(geom, :tol)::geography, 30, 'quad_segs=4')::geometry), :tol), 6)
        FROM app.nb_wetland, env
        WHERE ST_DWithin(geom, env.g, 0.0005)");
    $b->execute([':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n, ':tol' => $tol]);
    $band = $b->fetchColumn() ?: null;
}

header('Content-Type: application/json');
echo '{"ok":true,"band":', $band ?: 'null', ',"features":[';
foreach ($rows as $i => [$psw, $cls, $ha, $g]) {
    echo $i ? ',' : '', '{"type":"Feature","properties":{"psw":', $psw ? 'true' : 'false',
         ',"cls":', json_encode($cls), ',"ha":', json_encode((float)$ha), '},"geometry":', $g, '}';
}
echo ']}';
