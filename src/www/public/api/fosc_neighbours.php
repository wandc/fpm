<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/fosc_neighbours.php?id=X
//   → what is next along the cable from this closure: which way, and how far.
//
// Answers one closure at a time, on popup open, rather than riding along with
// api/features.php: the map refetches features on every pan and zoom, and these
// graph walks doubled that request's time for something only a popup ever reads.
//
// Distance is the routed length of the spans — what you would pull — not a
// straight line. Bearing is the straight line, which is what a compass shows.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/closure.helper.php';

/** @var PDO $pdo */

if (api_method() !== 'GET') api_err('Method not allowed', 405);

$foscId = (int)($_GET['id'] ?? 0);
if ($foscId <= 0) api_err('Invalid id');

// ── Along the cable ───────────────────────────────────────────────────────────
// Mainline only: FlexTIN spans are not walked at all. A tail is a distribution
// run hung off a closure, so what lies along it is not the "next closure" in any
// useful sense — and its taps would bury the answer besides.
//
// Slack loops are walked THROUGH rather than reported: a loop is a coil in the
// middle of a run, not a destination, so its spans fold into the distance to the
// closure on the far side — plus the slack itself, cable that exists on the route
// but in no span's geometry. That is SLACK_LOOP_M for a standard coil, or the
// point's own slack_length_m where the real figure differs (migration 0165) — a
// dead-end hairpin consumes twice its stub length, not 30 m. The path array keeps
// the walk from doubling back, and DISTINCT ON keeps the shortest route when two
// cables join the same pair.
$st = $pdo->prepare("
    WITH RECURSIVE mainline AS (
      SELECT cs.from_fosc_id, cs.to_fosc_id, cs.length_m
        FROM app.cable_span cs
        JOIN app.cable c ON c.cable_id = cs.cable_id
       WHERE c.name !~* 'flextin'
    ),
    edges AS (
      SELECT from_fosc_id AS a, to_fosc_id   AS b, COALESCE(length_m, 0) AS len FROM mainline
      UNION ALL
      SELECT to_fosc_id   AS a, from_fosc_id AS b, COALESCE(length_m, 0) AS len FROM mainline
    ),
    walk(node, dist, loops, path) AS (
      SELECT e.b, e.len, 0, ARRAY[e.a, e.b] FROM edges e WHERE e.a = :id
      UNION ALL
      SELECT e.b, w.dist + e.len + COALESCE(f.slack_length_m, " . SLACK_LOOP_M . "), w.loops + 1, w.path || e.b
        FROM walk w
        JOIN app.fosc f ON f.fosc_id = w.node AND f.kind = 'slack'
        JOIN edges e    ON e.a = w.node AND NOT (e.b = ANY(w.path))
    )
    SELECT DISTINCT ON (w.node)
           fn.splice_num, fn.fosc_id, w.dist AS dist_m, w.loops,
           degrees(ST_Azimuth(fo.location, fn.location)) AS bearing_deg
      FROM walk w
      JOIN app.fosc fn ON fn.fosc_id = w.node AND fn.kind NOT IN ('slack', 'optitap')
      JOIN app.fosc fo ON fo.fosc_id = :id2
     WHERE w.node <> :id3
     ORDER BY w.node, w.dist
");
$st->execute([':id' => $foscId, ':id2' => $foscId, ':id3' => $foscId]);
$rows = $st->fetchAll();
$via  = 'cable';

// ── Or, where nothing is built yet, along the strand ──────────────────────────
// Half the planned network has no spans drawn — 19 of 39 'plan' closures and
// every unbuilt slack loop — so the walk above finds nothing for exactly the
// closures being laid out. The cable will follow the strand, so that is the
// honest planned length; the popup says which of the two it is showing.
//
// Closures are not nodes in the strand graph, they hang on a pole, so each is
// tied to the pole under it (they sit dead on one — 0.0 m — but 5 m allows for a
// nudge). The walk stops at a pole carrying another real closure, passes through
// slack loops adding the same coil, and gives up past 2 km: beyond that "next"
// means nothing.
if (!$rows) {
    $st = $pdo->prepare("
        WITH RECURSIVE at_pole AS (
          SELECT f.fosc_id, f.splice_num, f.kind, f.location, p.fid AS pole_fid
            FROM app.fosc f
            JOIN LATERAL (SELECT fid, geom FROM app.pole p ORDER BY p.geom <-> f.location LIMIT 1) p ON true
           WHERE f.kind NOT IN ('optitap') AND ST_DWithin(f.location, p.geom, 5)
        ),
        strand_edges AS (
          SELECT from_pole_fid AS a, to_pole_fid AS b, ST_Length(geom::geography) AS len
            FROM app.messenger_strand
           WHERE NOT COALESCE(is_overlash, false) AND from_pole_fid IS NOT NULL AND to_pole_fid IS NOT NULL
          UNION ALL
          SELECT to_pole_fid AS a, from_pole_fid AS b, ST_Length(geom::geography) AS len
            FROM app.messenger_strand
           WHERE NOT COALESCE(is_overlash, false) AND from_pole_fid IS NOT NULL AND to_pole_fid IS NOT NULL
        ),
        walk(pole, dist, loops, path) AS (
          SELECT o.pole_fid, 0::float8, 0, ARRAY[o.pole_fid] FROM at_pole o WHERE o.fosc_id = :id
          UNION ALL
          SELECT e.b,
                 w.dist + e.len + COALESCE((
                   SELECT COALESCE(f2.slack_length_m, " . SLACK_LOOP_M . ")
                     FROM at_pole c2
                     JOIN app.fosc f2 ON f2.fosc_id = c2.fosc_id
                    WHERE c2.pole_fid = w.pole AND c2.kind = 'slack'
                    LIMIT 1), 0),
                 w.loops + CASE WHEN EXISTS (
                   SELECT 1 FROM at_pole c2 WHERE c2.pole_fid = w.pole AND c2.kind = 'slack') THEN 1 ELSE 0 END,
                 w.path || e.b
            FROM walk w
            JOIN strand_edges e ON e.a = w.pole AND NOT (e.b = ANY(w.path))
           WHERE w.dist < 2000
             AND NOT EXISTS (
               SELECT 1 FROM at_pole c3
                WHERE c3.pole_fid = w.pole AND c3.fosc_id <> :id2 AND c3.kind <> 'slack')
        )
        -- One row per destination POLE, not per closure: a site can carry three
        -- closures on the same pole, and listing each at the same distance in the
        -- same direction says nothing three times.
        SELECT DISTINCT ON (c.pole_fid)
               c.splice_num, c.fosc_id, w.dist AS dist_m, w.loops,
               degrees(ST_Azimuth(o.location, c.location)) AS bearing_deg
          FROM walk w
          JOIN at_pole c ON c.pole_fid = w.pole AND c.fosc_id <> :id3 AND c.kind <> 'slack'
          JOIN at_pole o ON o.fosc_id = :id4
         ORDER BY c.pole_fid, w.dist, c.splice_num
    ");
    $st->execute([':id' => $foscId, ':id2' => $foscId, ':id3' => $foscId, ':id4' => $foscId]);
    $rows = $st->fetchAll();
    $via  = 'strand';
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'splice_num'  => $r['splice_num'],
        'fosc_id'     => (int)$r['fosc_id'],
        'dist_m'      => round((float)$r['dist_m'], 1),
        'bearing_deg' => $r['bearing_deg'] !== null ? round((float)$r['bearing_deg']) : null,
        'loops'       => (int)$r['loops'],
    ];
}
usort($out, fn($x, $y) => $x['dist_m'] <=> $y['dist_m']);

api_ok(['via' => $via, 'neighbours' => array_slice($out, 0, 6)]);
