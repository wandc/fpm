<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/nb_parcels.php?bbox=W,S,E,N&z=<zoom>   parcel outlines in the viewport
// GET /api/nb_parcels.php?pid=01234567             one parcel's details + its civic addresses
// GET /api/nb_parcels.php?at=<lon>,<lat>           the same, for the parcel under a point
//
// SNB digital property map from app.nb_parcel (migration 0238), which
// tools/sync_geonb.php keeps current from GeoNB. The client only asks at
// zoom >= 15. Outlines are simplified to about a pixel at the requested zoom,
// which is most of the payload saving; the row cap is a backstop.

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

if (api_method() !== 'GET') api_err('Method not allowed', 405);

// ── One parcel, by PID or by the point clicked ───────────────────────────────
if (isset($_GET['pid']) || isset($_GET['at'])) {
    if (isset($_GET['at'])) {
        [$lon, $lat] = array_map('floatval', explode(',', (string)$_GET['at']) + [0, 0]);
        // Smallest first: a strata unit or road parcel beats the lot around it.
        $st = $pdo->prepare("SELECT pid, objectid FROM app.nb_parcel
                             WHERE ST_Intersects(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
                             ORDER BY area_m2 NULLS LAST LIMIT 1");
        $st->execute([$lon, $lat]);
        [$pid, $oid] = $st->fetch(PDO::FETCH_NUM) ?: api_err('No parcel here', 404);
    } else {
        $pid = preg_replace('/\D/', '', (string)$_GET['pid']);
        $oid = null;
    }
    // PIDs 00000001 / 00000003 are placeholders shared by thousands of unrelated polygons province-wide
    // (Crown land or roads), so they identify nothing: use only the polygon that was clicked.
    $placeholder = (int)$pid < 10;
    $sel = $placeholder ? 'objectid = ?' : 'pid = ?';
    $key = $placeholder ? (int)$oid : $pid;
    $st = $pdo->prepare("SELECT pid, feature, county, property_map, last_update,
                                round(sum(area_m2)) AS area_m2
                         FROM app.nb_parcel WHERE $sel
                         GROUP BY pid, feature, county, property_map, last_update LIMIT 1");
    $st->execute([$key]);
    $parcel = $st->fetch() ?: api_err('Parcel not found', 404);
    $st = $pdo->prepare($placeholder
        ? "SELECT c.address, c.structure FROM app.nb_civic_address c JOIN app.nb_parcel p ON p.objectid = ? AND ST_Within(c.geom, p.geom)
           ORDER BY c.civic_num NULLS LAST, c.address"
        : "SELECT address, structure FROM app.nb_civic_address WHERE pid = ? ORDER BY civic_num NULLS LAST, address");
    $st->execute([$key]);
    $addresses = $st->fetchAll();
    // Zoning zones the parcel overlaps (app.salisbury_zoning; empty outside Salisbury). Slivers under 2% of the parcel are dropped.
    $st = $pdo->prepare("SELECT z.name, z.code, z.link,
                                round(sum(ST_Area(ST_Intersection(p.geom, z.geom)::geography))) AS area_m2
                         FROM app.nb_parcel p
                         JOIN app.salisbury_zoning z ON z.layer = 'zoning' AND z.geom && p.geom AND ST_Intersects(z.geom, p.geom)
                         WHERE p.$sel GROUP BY z.name, z.code, z.link ORDER BY 4 DESC");
    $st->execute([$key]);
    $zoning = array_values(array_filter($st->fetchAll(), fn($r) => (float)$r['area_m2'] >= 0.02 * max(1, (float)$parcel['area_m2'])));
    // Railway land: carries NRWN track (GeoNB), or a recorded owner that is a railway.
    // Corridor test: track length is at least 1% of the parcel's area (about 100 m of parcel per track metre),
    // so a lot with a siding or a crossing is not railway land, but a right-of-way strip is.
    $st = $pdo->prepare("SELECT min(r.owner) FROM app.nb_parcel p
                         JOIN app.nb_railway r ON r.geom && p.geom AND ST_Intersects(r.geom, p.geom)
                         WHERE p.$sel GROUP BY p.objectid
                         HAVING sum(ST_Length(ST_Intersection(r.geom, p.geom)::geography)) >= greatest(25, 0.01 * max(p.area_m2))
                         LIMIT 1");
    $st->execute([$key]);
    $parcel['rail_owner'] = $st->fetchColumn() ?: null;
    // Stored documents (easement plans etc.) that name this PID. A placeholder PID names nothing.
    $documents = [];
    $owner = null;
    if (!$placeholder) {
        $st = $pdo->prepare("SELECT owner, to_char(as_of, 'YYYY-MM-DD') AS as_of, source, notes FROM app.parcel_owner WHERE pid = ?");
        $st->execute([$pid]);
        $owner = $st->fetch() ?: null;
        $st = $pdo->prepare("SELECT d.document_id, d.title, d.category, d.mime_type
                             FROM app.document_pid dp JOIN app.document d USING (document_id)
                             WHERE dp.pid = ? ORDER BY d.category NULLS LAST, d.title");
        $st->execute([$pid]);
        $documents = $st->fetchAll();
    }
    api_ok(['parcel' => $parcel, 'addresses' => $addresses, 'zoning' => $zoning, 'documents' => $documents, 'owner' => $owner, 'placeholder' => $placeholder]);
}

// ── Outlines in view ──────────────────────────────────────────────────────────
$parts = array_map('floatval', explode(',', (string)($_GET['bbox'] ?? '')));
if (count($parts) !== 4 || count(array_filter($parts, 'is_finite')) !== 4) api_err('bbox=W,S,E,N required', 400);
[$w, $s, $e, $n] = $parts;
if (($e - $w) * ($n - $s) > 0.02) api_err('Area too large; zoom in', 400);

// Degrees per screen pixel at this zoom (256 px tiles); simplify to ~half that.
$z   = max(10, min(22, (int)($_GET['z'] ?? 16)));
$tol = 360 / (256 * 2 ** $z) / 2;

$LIMIT = 5000;
// Train icons go along the track every so many metres, sparser when zoomed out.
$SPACING = $z >= 17 ? 120 : ($z == 16 ? 250 : 500);
$st = $pdo->prepare("
    SELECT p.pid, ST_AsGeoJSON(ST_SimplifyPreserveTopology(p.geom, :tol), 6) AS g,
           (rl.owner IS NOT NULL OR o.owner ~* '(railway|railroad|chemin de fer)') AS rail,
           CASE WHEN rl.owner IS NOT NULL OR o.owner ~* '(railway|railroad|chemin de fer)' THEN
                ST_AsGeoJSON(COALESCE(
                    (SELECT ST_Collect(ST_LineInterpolatePoints(d.geom, least(1, $SPACING / nullif(ST_Length(d.geom::geography), 0)), true))
                       FROM ST_Dump(rl.clip) d WHERE GeometryType(d.geom) = 'LINESTRING'),
                    ST_PointOnSurface(p.geom)), 6) END AS c,
           ST_AsGeoJSON(ST_PointOnSurface(p.geom), 6) AS c2
    FROM app.nb_parcel p
    LEFT JOIN app.parcel_owner o ON o.pid = p.pid
    LEFT JOIN LATERAL (
        SELECT min(r.owner) AS owner, ST_Collect(ST_Intersection(r.geom, p.geom)) AS clip FROM app.nb_railway r
        WHERE r.geom && p.geom AND ST_Intersects(r.geom, p.geom)
        HAVING sum(ST_Length(ST_Intersection(r.geom, p.geom)::geography)) >= greatest(25, 0.01 * p.area_m2)
    ) rl ON true
    WHERE p.geom && ST_MakeEnvelope(:w, :s, :e, :n, 4326)
    LIMIT $LIMIT + 1
");
$st->execute([':tol' => $tol, ':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n]);
$rows = $st->fetchAll(PDO::FETCH_NUM);
foreach ($rows as &$r) $r[2] = in_array($r[2], [true, 't', 1, '1'], true);
unset($r);
$truncated = count($rows) > $LIMIT;
if ($truncated) array_pop($rows);

// Hand-built so the geometry JSON isn't decoded and re-encoded per row.
header('Content-Type: application/json');
header('Cache-Control: max-age=300, private');
echo '{"ok":true,"truncated":', $truncated ? 'true' : 'false', ',"features":[';
foreach ($rows as $i => [$pid, $g, $rail, $c, $c2]) {
    $pts = '';
    if ($rail) {
        $j = json_decode($c, true);
        $co = $j['type'] === 'Point' ? [$j['coordinates']] : $j['coordinates'];
        if (!$co) $co = [json_decode($c2, true)['coordinates']];
        $seen = [];
        foreach ($co as $q) $seen[round($q[0], 6) . ',' . round($q[1], 6)] = [round($q[0], 6), round($q[1], 6)];
        $pts = ',"rail":1,"c":' . json_encode(array_values($seen));
    }
    echo $i ? ',' : '', '{"type":"Feature","properties":{"pid":', json_encode($pid), $pts,
         '},"geometry":', $g, '}';
}
echo ']}';
