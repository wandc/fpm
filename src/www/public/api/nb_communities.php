<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/nb_communities.php?bbox=W,S,E,N&z=<zoom>   NB 9-1-1 community boundaries and names in view
//
// From app.nb_community (migration 0277), loaded from the GeoNB Community Boundaries shapefile by
// scripts/db/load_nb_communities.py. Each row carries the outline (simplified for the zoom), the name,
// and `label`: whether the community is wide enough on screen to carry its name.

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

if (api_method() !== 'GET') api_err('Method not allowed', 405);
header('Cache-Control: max-age=3600, private');

$parts = array_map('floatval', explode(',', (string)($_GET['bbox'] ?? '')));
if (count($parts) !== 4) api_err('bbox=W,S,E,N required', 400);
[$w, $s, $e, $n] = $parts;
$z = max(5, min(22, (int)($_GET['z'] ?? 12)));
$tol = 360 / (256 * 2 ** $z) / 2;               // about half a pixel
$pxPerDeg = 256 * 2 ** $z / 360;

$st = $pdo->prepare("
    WITH env AS (SELECT ST_MakeEnvelope(:w, :s, :e, :n, 4326) AS g)
    SELECT name, bell_name,
           round(ST_Y(ST_PointOnSurface(geom))::numeric, 6) AS lat, round(ST_X(ST_PointOnSurface(geom))::numeric, 6) AS lon,
           (ST_XMax(geom) - ST_XMin(geom)) * :ppd >= 60 AS label,
           ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tol), 6) AS g
    FROM app.nb_community, env
    WHERE geom && env.g AND (ST_XMax(geom) - ST_XMin(geom)) * :ppd >= 8
    ORDER BY ST_Area(geom) DESC
    LIMIT 1500");
$st->execute([':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n, ':tol' => $tol, ':ppd' => $pxPerDeg]);

header('Content-Type: application/json');
echo '{"ok":true,"features":[';
foreach ($st->fetchAll(PDO::FETCH_NUM) as $i => [$name, $bell, $lat, $lon, $label, $g]) {
    echo $i ? ',' : '', '{"type":"Feature","properties":{"name":', json_encode($name), ',"bell":', json_encode($bell),
         ',"lat":', $lat, ',"lon":', $lon, ',"label":', $label ? 'true' : 'false', '},"geometry":', $g, '}';
}
echo ']}';
