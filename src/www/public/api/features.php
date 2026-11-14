<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/features.php
// Returns a GeoJSON FeatureCollection of all FOSCs (with location) and cable spans.

require_once __DIR__ . '/_common.php';

if (api_method() !== 'GET') {
    api_err('Method not allowed', 405);
}

/** @var PDO $pdo */

// ── Optional viewport bbox filter ─────────────────────────────────────────────
// ?bbox=W,S,E,N  When present, only features intersecting the envelope are returned.
// When absent, all features are returned (full-load fallback).
$hasBbox = false;
$bboxSql = '';
if (isset($_GET['bbox'])) {
    $parts = array_map('floatval', explode(',', (string)$_GET['bbox']));
    if (count($parts) === 4) {
        [$bboxW, $bboxS, $bboxE, $bboxN] = $parts;
        if (is_finite($bboxW) && is_finite($bboxS) && is_finite($bboxE) && is_finite($bboxN)) {
            $hasBbox = true;
            $bboxSql = sprintf(
                'ST_MakeEnvelope(%F, %F, %F, %F, 4326)',
                $bboxW, $bboxS, $bboxE, $bboxN
            );
        }
    }
}

require_once __DIR__ . '/../phpHelpers/route_coincidence.helper.php';
require_once __DIR__ . '/../phpHelpers/closure.helper.php';

/**
 * SQL (a LATERAL subquery body): the buffered corridor formed by every messenger
 * strand running alongside line $a, as column `corridor`.
 *
 * A single conduit/span often parallels several consecutive pole-to-pole strand
 * segments, so no individual strand covers it — only their combined corridor does.
 * Both `covered_by_strand` and `uncovered_geojson` are derived from this one
 * result: previously each was its own correlated subquery running the identical
 * join, and the planner shared nothing between them.
 *
 * ms.geom is a real indexed column (migration 0131), so ST_DWithin here is an
 * index scan. It used to be ST_MakeLine(pa.geom, pb.geom) over a join to app.pole,
 * which no index could serve — 378 sequential scans of app.pole per request.
 *
 * $gate is an outer-only condition (e.g. 'rcc.payload IS NULL'): when it is false
 * PostgreSQL skips the scan altogether (a one-time filter), which is how a span
 * whose answer is already in the route-coincidence cache costs nothing here.
 */
function features_strand_corridor(string $a, string $gate = 'true'): string
{
    $tol  = COINCIDE_TOL;
    $draw = COINCIDE_DRAW_TOL;
    return "SELECT ST_Union(ST_Buffer(ms.geom, {$tol}))  AS corridor,
                   ST_Union(ST_Buffer(ms.geom, {$draw})) AS corridor_draw
              FROM app.messenger_strand ms
             WHERE {$gate}
               AND ms.geom IS NOT NULL
               AND ST_DWithin({$a}, ms.geom, {$tol})
               AND " . features_parallel_local('ST_StartPoint(ms.geom)', 'ST_EndPoint(ms.geom)', $a);
}

/**
 * SQL boolean: is most of line $a inside the strand corridor $corridor?
 * (so the map can dim a span where a strand's cased hybrid already draws the route)
 */
function features_covered_by_corridor(string $a, string $corridor): string
{
    $frac = COINCIDE_FRAC;
    return "COALESCE({$corridor} IS NOT NULL
                     AND ST_Length({$a}) > 0
                     AND ST_Length(ST_Intersection({$corridor}, {$a})) >= {$frac} * ST_Length({$a}),
                     false)";
}

/**
 * SQL (GeoJSON text): the portion of line $a OUTSIDE the strand corridor — i.e. the
 * part a strand's cased hybrid does NOT already draw. `covered_by_strand` hides the
 * whole span; this lets the map still draw the off-strand remainder (e.g. the tail
 * from the last support to its closure) so the span stays visibly connected.
 */
function features_uncovered_geojson(string $a, string $corridor): string
{
    // Measured against the TIGHT corridor, not the 12 m one used to decide
    // coverage. A closure set back from the pole line - a cabinet in a yard, a
    // pedestal behind the ditch - sits inside 12 m, so differencing against that
    // buffer returns nothing and the span vanishes entirely: hidden as covered,
    // with no remainder to stand in for it. Against ~3 m the setback survives and
    // the cable is visibly connected to its closure.
    // Empty comes back as NULL so the caller can tell "nothing to draw" from
    // "a real line", rather than shipping an empty coordinate array to Leaflet.
    return "NULLIF(ST_AsGeoJSON(CASE WHEN {$corridor} IS NULL THEN {$a}
                                     ELSE ST_Difference({$a}, {$corridor}) END, 6),
                   ST_AsGeoJSON(ST_GeomFromText('LINESTRING EMPTY', 4326), 6))";
}

// ── FOSCs ─────────────────────────────────────────────────────────────────────

$features = [];

$foscBbox = $hasBbox ? "AND ST_Intersects(f.location, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT f.fosc_id,
           f.splice_num,
           f.status,
           f.kind,
           -- A FlexTIN tap: an optitap auto-chained along a tail, which carries
           -- the tail's head closure in attrs. Hand-placed optitaps have no
           -- parent, so this is what separates the two on the map.
           CASE WHEN f.kind = 'optitap' AND (f.attrs->>'parent_fosc_id') IS NOT NULL
                THEN (f.attrs->>'parent_fosc_id')::bigint
           END                  AS flextin_parent_fosc_id,
           ct.name              AS closure_type,
           ct.closure_type_id,
           f.notes,
           f.fpm_wor,
           f.pop_id,
           f.enclosure_rating,
           f.attrs->>'street_address' AS street_address,
           f.slack_length_m,
           (SELECT string_agg(w.work_order, ',' ORDER BY w.is_primary DESC, w.id)
              FROM app.fosc_work_order w WHERE w.fosc_id = f.fosc_id) AS work_orders_str,
           (f.attrs->>'damaged')::boolean AS damaged,
           ST_Y(f.location)     AS lat,
           ST_X(f.location)     AS lon,
           ST_AsGeoJSON(f.location, 6) AS geojson
      FROM app.fosc f
      JOIN app.closure_type ct ON ct.closure_type_id = f.closure_type_id
     WHERE f.location IS NOT NULL
       {$foscBbox}
     ORDER BY f.splice_num, f.fosc_id
");

foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'    => 'fosc',
            'fosc_id'         => (int)$row['fosc_id'],
            'splice_num'      => $row['splice_num'],
            'status'          => $row['status'],
            'kind'            => $row['kind'],
            'status_class'    => features_status_badge($row['status']),
            'closure_type'    => $row['closure_type'],
            'closure_type_id' => (int)$row['closure_type_id'],
            'flextin_parent_fosc_id' => $row['flextin_parent_fosc_id'] !== null ? (int)$row['flextin_parent_fosc_id'] : null,
            'notes'           => (string)($row['notes'] ?? ''),
            'fpm_wor'      => (string)($row['fpm_wor'] ?? ''),
            'pop_id'          => $row['pop_id'] !== null ? (int)$row['pop_id'] : null,
            'enclosure_rating'=> (string)($row['enclosure_rating'] ?? 'outside'),
            'street_address'  => (string)($row['street_address'] ?? ''),
            // null = the standard SLACK_LOOP_M coil; a number = the real length here.
            'slack_length_m'  => $row['slack_length_m'] !== null ? (float)$row['slack_length_m'] : null,
            'work_orders'     => array_values(array_filter(explode(',', (string)($row['work_orders_str'] ?? '')))),
            'damaged'         => (bool)$row['damaged'],
            'lat'             => (float)$row['lat'],
            'lon'             => (float)$row['lon'],
        ],
    ];
}

// ── POPs (points of presence / office buildings) ───────────────────────────────
// Each POP carries its own location (migration 0091). Member-closure counts and
// names are bundled for the marker popup.

$popBbox = $hasBbox ? "AND ST_Intersects(p.location, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT p.pop_id,
           p.name,
           p.notes,
           ST_Y(p.location) AS lat,
           ST_X(p.location) AS lon,
           ST_AsGeoJSON(p.location, 6) AS geojson,
           (SELECT count(*) FROM app.fosc        f WHERE f.pop_id = p.pop_id) AS member_ct,
           (SELECT count(*) FROM app.fosc_device d WHERE d.pop_id = p.pop_id) AS device_ct,
           (SELECT string_agg(f.splice_num, ', ' ORDER BY f.splice_num)
              FROM app.fosc f WHERE f.pop_id = p.pop_id)               AS member_names
      FROM app.pop p
     WHERE p.location IS NOT NULL
       {$popBbox}
     ORDER BY p.name, p.pop_id
");

foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'pop',
            'pop_id'       => (int)$row['pop_id'],
            'name'         => $row['name'],
            'notes'        => (string)($row['notes'] ?? ''),
            'member_ct'    => (int)$row['member_ct'],
            'device_ct'    => (int)$row['device_ct'],
            'member_names' => (string)($row['member_names'] ?? ''),
            'lat'          => (float)$row['lat'],
            'lon'          => (float)$row['lon'],
        ],
    ];
}

// ── Route-coincidence cache (migration 0246) ──────────────────────────────────
// The strand/span "runs alongside" flags take ~0.8 s to compute for a zoom-14 view
// and only change when strand/span geometry, fibre count or a closure position does.
// A cached row is used only when it carries the CURRENT version; anything else is
// recomputed by the same SQL as before and stored under the version read here.
$rcVer = false;
try { $rcVer = $pdo->query("SELECT v FROM app.route_coincidence_version WHERE id = 1")->fetchColumn(); }
catch (Throwable $e) { $rcVer = false; }          // migration not applied: compute everything, as before
$useRc = $rcVer !== false && $rcVer !== null;
$rcJoin = fn(string $kind, string $idExpr) => $useRc
    ? "LEFT JOIN app.route_coincidence_cache rcc ON rcc.kind = '{$kind}' AND rcc.id = {$idExpr} AND rcc.v = " . (int)$rcVer
    : "LEFT JOIN (SELECT NULL::jsonb AS payload) rcc ON false";
$rcNew = [];   // [kind, id, payload] computed this request, stored at the end

// ── Cable spans ───────────────────────────────────────────────────────────────

// Filter on the span's own geometry, not its end closures: a long span crossing
// the viewport with both closures outside it is still visible on screen, and
// testing only the endpoints dropped it entirely (cable + fibre-count labels
// vanished when you zoomed into the middle of a long run). cs.geom is generated
// from geom_snap and carries a GiST index, so this stays indexed.
$spanBbox = $hasBbox
    ? "AND ST_Intersects(cs.geom, {$bboxSql})"
    : '';
$st = $pdo->query("
    SELECT cs.cable_span_id,
           cs.cable_id,
           c.name         AS cable_name,
           c.fibre_count,
           c.material_id,
           c.open_ended,
           cs.from_fosc_id,
           cs.to_fosc_id,
           f1.splice_num  AS from_splice_num,
           f2.splice_num  AS to_splice_num,
           cs.notes,
           cs.fpm_wor,
           cs.is_spare,
           cs.is_private,
           cs.cable_serial,
           cs.installed,
           (SELECT string_agg(w.work_order, ',' ORDER BY w.is_primary DESC, w.id)
              FROM app.cable_span_work_order w
             WHERE w.cable_span_id = cs.cable_span_id) AS work_orders_str,
           ROUND(cs.length_m::numeric, 1) AS length_m,
           CASE
             WHEN f2.kind = 'optitap' AND (f2.attrs->>'parent_fosc_id') IS NOT NULL
               THEN (f2.attrs->>'parent_fosc_id')::bigint
             ELSE NULL
           END AS flextin_parent_fosc_id,
           CASE
             WHEN f2.kind = 'optitap' AND (f2.attrs->>'parent_fosc_id') IS NOT NULL
               THEN LEFT(f2.splice_num, 1)
             ELSE NULL
           END AS flextin_side,
           CASE
             WHEN cs.installed THEN 'active'          -- the cable is up, whatever its end sites say (migration 0270)
             WHEN f1.status::text = 'under construction' OR f2.status::text = 'under construction'
               THEN 'under construction'
             WHEN f1.status::text = 'plan' AND f2.status::text = 'plan'
               THEN 'plan'
             ELSE 'active'
           END AS span_status,
           CASE
             WHEN g.sg IS NOT NULL THEN ST_AsGeoJSON(g.sg, 6)
             ELSE NULL
           END AS geojson,
           -- Is this span drawn along a messenger strand? (so the map can dim it
           -- where a strand's cased hybrid already represents the route.)
           " . features_covered_by_corridor('g.sg', 'corr.corridor') . " AS covered_by_strand,
           -- Off-strand remainder to draw even when the span is 'covered'
           CASE WHEN rcc.payload IS NOT NULL THEN NULL
                ELSE " . features_uncovered_geojson('g.sg', 'corr.corridor_draw') . " END AS uncovered_geojson,
           rcc.payload AS rc_payload
      FROM app.cable_span cs
      JOIN app.cable  c  ON c.cable_id   = cs.cable_id
      JOIN app.fosc   f1 ON f1.fosc_id   = cs.from_fosc_id
      JOIN app.fosc   f2 ON f2.fosc_id   = cs.to_fosc_id
      -- The span's drawn line, named once so the corridor LATERAL and both
      -- coincidence outputs share it instead of repeating the COALESCE.
      CROSS JOIN LATERAL (
        SELECT COALESCE(cs.geom_snap, ST_MakeLine(f1.location, f2.location)) AS sg
      ) g
      " . $rcJoin('span', 'cs.cable_span_id') . "
      LEFT JOIN LATERAL (" . features_strand_corridor('g.sg', 'rcc.payload IS NULL') . ") corr ON true
     WHERE 1=1
       {$spanBbox}
     ORDER BY cs.cable_span_id
");

foreach ($st->fetchAll() as $row) {
    if ($row['geojson'] === null) {
        continue;
    }
    if ($row['rc_payload'] !== null) {                      // cache hit: same values, no geometry work
        $c = json_decode((string)$row['rc_payload'], true);
        $row['covered_by_strand'] = (bool)$c['covered'];
        $row['uncovered_geojson'] = $c['uncovered'];
    } elseif ($useRc) {
        $rcNew[] = ['span', (int)$row['cable_span_id'],
                    ['covered' => (bool)$row['covered_by_strand'], 'uncovered' => $row['uncovered_geojson']]];
    }
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'    => 'span',
            'cable_span_id'   => (int)$row['cable_span_id'],
            'cable_id'        => (int)$row['cable_id'],
            'cable_name'      => (string)$row['cable_name'],
            'fibre_count'     => (int)$row['fibre_count'],
            'material_id'     => $row['material_id'] !== null ? (int)$row['material_id'] : null,
            'open_ended'      => (bool)$row['open_ended'],
            'from_fosc_id'    => (int)$row['from_fosc_id'],
            'to_fosc_id'      => (int)$row['to_fosc_id'],
            'from_splice_num' => (string)$row['from_splice_num'],
            'to_splice_num'   => (string)$row['to_splice_num'],
            'notes'                  => (string)($row['notes'] ?? ''),
            'fpm_wor'             => (string)($row['fpm_wor'] ?? ''),
            'is_spare'               => $row['is_spare'] === 't' || $row['is_spare'] === true,
            'is_private'             => $row['is_private'] === 't' || $row['is_private'] === true,
            'cable_serial'           => (string)($row['cable_serial'] ?? ''),
            // Every permit this span belongs to, primary first (migration 0151).
            'work_orders'            => array_values(array_filter(explode(',', (string)($row['work_orders_str'] ?? '')))),
            'length_m'               => $row['length_m'] !== null ? (float)$row['length_m'] : null,
            'span_status'            => (string)$row['span_status'],
            'installed'              => (bool)$row['installed'],
            'covered_by_strand'      => (bool)$row['covered_by_strand'],
            'uncovered_geojson'      => $row['covered_by_strand'] && $row['uncovered_geojson'] !== null
                                          ? json_decode((string)$row['uncovered_geojson']) : null,
            'flextin_parent_fosc_id' => $row['flextin_parent_fosc_id'] !== null ? (int)$row['flextin_parent_fosc_id'] : null,
            'flextin_side'           => $row['flextin_side'] ?? null,
        ],
    ];
}

// ── Utility poles ─────────────────────────────────────────────────────────────

$poleBbox = $hasBbox ? "AND ST_Intersects(p.geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT p.fid,
           p.uid,
           p.type,
           p.owner,
           p.comment,
           p.\"nbp#\" AS nbp_num,
           p.\"telco#\" AS telco_num,
           p.\"tags#\" AS tags_num,
           p.fpm_wor AS work_order,
           (SELECT string_agg(pwo.work_order, ',' ORDER BY pwo.is_primary DESC, pwo.id)
              FROM app.pole_work_order pwo
             WHERE pwo.pole_fid = p.fid) AS work_orders_str,
           (SELECT bp.permit_num
              FROM app.bell_permit_pole bpp
              JOIN app.bell_permit bp ON bp.id = bpp.permit_id
             WHERE bpp.nbp_num = p.\"nbp#\"
             ORDER BY bpp.id LIMIT 1) AS bell_permit_num,
           (SELECT string_agg(bp.permit_num, ',' ORDER BY bpp.id)
              FROM app.bell_permit_pole bpp
              JOIN app.bell_permit bp ON bp.id = bpp.permit_id
             WHERE bpp.nbp_num = p.\"nbp#\") AS bell_permit_nums_str,
           ST_AsGeoJSON(p.geom, 6) AS geojson
      FROM app.pole p
     WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
       {$poleBbox}
     ORDER BY p.fid
");

foreach ($st->fetchAll() as $row) {
    $workOrders     = array_values(array_filter(explode(',', $row['work_orders_str']     ?? '')));
    $bellPermitNums = array_values(array_filter(explode(',', $row['bell_permit_nums_str'] ?? '')));
    // The column and the junction should agree (trigger, 0231) but the map must
    // never drop a pole because they don't: the column's permit always counts.
    $primaryWO = (string)($row['work_order'] ?? '');
    if ($primaryWO !== '' && !in_array($primaryWO, $workOrders, true)) {
        array_unshift($workOrders, $primaryWO);
    }
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'pole',
            'fid'          => (int)$row['fid'],
            'uid'          => (string)($row['uid']     ?? ''),
            'type'         => (string)($row['type']    ?? ''),
            'owner'        => (string)($row['owner']   ?? ''),
            'comment'      => (string)($row['comment'] ?? ''),
            'nbp_num'      => (string)($row['nbp_num'] ?? ''),
            'telco_num'    => (string)($row['telco_num'] ?? ''),
            'tags_num'     => (string)($row['tags_num'] ?? ''),
            'work_order'      => (string)($row['work_order']      ?? ''),
            'bell_permit_num' => (string)($row['bell_permit_num'] ?? ''),
            'work_orders'     => $workOrders,
            'bell_permit_nums'=> $bellPermitNums,
        ],
    ];
}

// ── Network-map validation warnings ───────────────────────────────────────────
// Slack loop rules:
//   1) Exactly two cable spans must terminate on the slack-loop closure.
//   2) Those two spans must be joined 1:48 to 1:48 in app.splice_join.
$violations = [];

// One set-based pass rather than 1 + 4-per-closure prepared statements: with ~64
// slack closures that was ~257 round-trips on every map load. The LATERALs mirror
// the old per-closure lookups exactly — the exact-match probe, and the "what IS
// spliced here instead" fallback used only to word the violation message.
$stSlack = $pdo->query("
    WITH slack AS (
      SELECT fosc_id, splice_num
        FROM app.fosc
       WHERE kind = 'slack'
    ),
    spans AS (
      SELECT s.fosc_id,
             s.splice_num,
             count(cs.cable_span_id)::int                             AS span_count,
             array_agg(cs.cable_span_id ORDER BY cs.cable_span_id)
               FILTER (WHERE cs.cable_span_id IS NOT NULL)            AS span_ids
        FROM slack s
        LEFT JOIN app.cable_span cs
               ON cs.from_fosc_id = s.fosc_id OR cs.to_fosc_id = s.fosc_id
       GROUP BY s.fosc_id, s.splice_num
    ),
    pair AS (
      SELECT sp.*,
             CASE WHEN sp.span_count = 2 THEN sp.span_ids[1] END AS span1,
             CASE WHEN sp.span_count = 2 THEN sp.span_ids[2] END AS span2
        FROM spans sp
    )
    SELECT p.fosc_id, p.splice_num, p.span_count, p.span1, p.span2,
           COALESCE(c1.fibre_count, 0) AS f1,
           COALESCE(c2.fibre_count, 0) AS f2,
           (ok.found IS NOT NULL)      AS exact_ok,
           anyj.join_kind, anyj.a_range_txt, anyj.b_range_txt
      FROM pair p
      LEFT JOIN app.cable_span cs1 ON cs1.cable_span_id = p.span1
      LEFT JOIN app.cable      c1  ON c1.cable_id       = cs1.cable_id
      LEFT JOIN app.cable_span cs2 ON cs2.cable_span_id = p.span2
      LEFT JOIN app.cable      c2  ON c2.cable_id       = cs2.cable_id
      LEFT JOIN LATERAL (
        SELECT 1 AS found
          FROM app.splice_join sj
         WHERE sj.fosc_id   = p.fosc_id
           AND sj.a_target  = 'cable_span'
           AND sj.b_target  = 'cable_span'
           AND sj.join_kind = 'pass_through'
           AND sj.retired_at IS NULL
           AND (
                 (sj.a_span_id = p.span1 AND sj.b_span_id = p.span2
                  AND lower(sj.a_range) = 1 AND upper(sj.a_range) = c1.fibre_count + 1
                  AND lower(sj.b_range) = 1 AND upper(sj.b_range) = c2.fibre_count + 1)
              OR (sj.a_span_id = p.span2 AND sj.b_span_id = p.span1
                  AND lower(sj.a_range) = 1 AND upper(sj.a_range) = c2.fibre_count + 1
                  AND lower(sj.b_range) = 1 AND upper(sj.b_range) = c1.fibre_count + 1)
               )
         LIMIT 1
      ) ok ON true
      LEFT JOIN LATERAL (
        SELECT sj.join_kind,
               sj.a_range::text AS a_range_txt,
               sj.b_range::text AS b_range_txt
          FROM app.splice_join sj
         WHERE sj.fosc_id  = p.fosc_id
           AND sj.a_target = 'cable_span'
           AND sj.b_target = 'cable_span'
           AND sj.retired_at IS NULL
           AND (
                 (sj.a_span_id = p.span1 AND sj.b_span_id = p.span2)
              OR (sj.a_span_id = p.span2 AND sj.b_span_id = p.span1)
               )
         ORDER BY sj.splice_join_id DESC
         LIMIT 1
      ) anyj ON true
     ORDER BY p.splice_num, p.fosc_id
");

foreach ($stSlack->fetchAll() as $row) {
    $foscId    = (int)$row['fosc_id'];
    $splice    = (string)$row['splice_num'];
    $spanCount = (int)$row['span_count'];

    if ($spanCount !== 2) {
        $violations[] = [
            'code' => 'slack_span_count',
            'fosc_id' => $foscId,
            'splice_num' => $splice,
            'message' => "Slack loop {$splice} has {$spanCount} cable spans; expected exactly 2.",
        ];
        continue;
    }

    $f1 = (int)$row['f1'];
    $f2 = (int)$row['f2'];

    if ($f1 <= 0 || $f2 <= 0) {
        $violations[] = [
            'code' => 'slack_span_fibre_unknown',
            'fosc_id' => $foscId,
            'splice_num' => $splice,
            'message' => "Slack loop {$splice} has span fibre count missing/invalid. Cannot validate pass-through splice.",
        ];
        continue;
    }

    // PDO returns Postgres booleans as 't'/'f'
    if ($row['exact_ok'] === true || $row['exact_ok'] === 't') {
        continue;
    }

    $f1Upper  = $f1 + 1;
    $f2Upper  = $f2 + 1;
    $expected = "pass_through [1,{$f1Upper}) ↔ [1,{$f2Upper})";
    $actual   = $row['join_kind'] !== null
        ? "{$row['join_kind']} {$row['a_range_txt']} ↔ {$row['b_range_txt']}"
        : 'none';

    $violations[] = [
        'code' => 'slack_splice_full_passthrough_missing',
        'fosc_id' => $foscId,
        'splice_num' => $splice,
        'message' => "Slack loop {$splice} requires full pass-through splice ({$expected}); found {$actual}.",
    ];
}

// Conduit terminations nobody has classified yet (migration 0298): each is the end of a conduit that is on
// no vault or handhole. Give it a kind (building entry, pole riser, closure, POP) in its popup.
foreach ($pdo->query("SELECT t.termination_id, t.label, (SELECT string_agg(c.conduit_id::text, ', ' ORDER BY c.conduit_id) FROM app.conduit c
                                                          WHERE c.start_term_id = t.termination_id OR c.end_term_id = t.termination_id) AS conduits
                        FROM app.conduit_termination t WHERE t.kind = 'unverified' ORDER BY t.termination_id")->fetchAll() as $row) {
    $violations[] = [
        'code' => 'conduit_termination_unverified',
        'fosc_id' => 0,
        'termination_id' => (int)$row['termination_id'],
        'message' => "Conduit end (conduit " . ($row['conduits'] ?? '?') . ") is not on a vault or handhole and has no kind yet; set it to building entry, pole riser, closure or POP.",
    ];
}

// ── Anchors (inherit pole geometry) ──────────────────────────────────────────

$anchorBbox = $hasBbox ? "AND ST_Intersects(p.geom, {$bboxSql})" : '';
$stAnchor = $pdo->query("
    SELECT a.id,
           a.pole_fid,
           a.owner,
           a.status,
           a.notes,
           a.direction,
           p.uid             AS pole_uid,
           p.\"nbp#\"        AS pole_nbp,
           ST_AsGeoJSON(p.geom, 6) AS geojson
      FROM app.anchor a
      JOIN app.pole p ON p.fid = a.pole_fid
     WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
       {$anchorBbox}
     ORDER BY a.id
");

foreach ($stAnchor->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'anchor',
            'id'           => (int)$row['id'],
            'pole_fid'     => (int)$row['pole_fid'],
            'owner'        => (string)($row['owner']    ?? ''),
            'status'       => (string)($row['status']   ?? ''),
            'notes'        => (string)($row['notes']     ?? ''),
            'direction'    => (string)($row['direction'] ?? ''),
            'pole_uid'     => (string)($row['pole_uid']  ?? ''),
            'pole_nbp'     => (string)($row['pole_nbp']  ?? ''),
        ],
    ];
}

// ── U-guards (inherit pole geometry) ─────────────────────────────────────────

$uguardBbox = $hasBbox ? "AND ST_Intersects(p.geom, {$bboxSql})" : '';
$stUguard = $pdo->query("
    SELECT ug.id,
           ug.pole_fid,
           ug.status,
           ug.notes,
           ug.fpm_wor,
           p.uid             AS pole_uid,
           p.\"nbp#\"        AS pole_nbp,
           ST_AsGeoJSON(p.geom, 6) AS geojson
      FROM app.uguard ug
      JOIN app.pole p ON p.fid = ug.pole_fid
     WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
       {$uguardBbox}
     ORDER BY ug.id
");

foreach ($stUguard->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'uguard',
            'id'           => (int)$row['id'],
            'pole_fid'     => (int)$row['pole_fid'],
            'status'       => (string)($row['status']   ?? ''),
            'notes'        => (string)($row['notes']    ?? ''),
            'fpm_wor'   => (string)($row['fpm_wor'] ?? ''),
            'pole_uid'     => (string)($row['pole_uid'] ?? ''),
            'pole_nbp'     => (string)($row['pole_nbp'] ?? ''),
        ],
    ];
}

// ── Conduit runs ──────────────────────────────────────────────────────────────

$conduitBbox = $hasBbox ? "AND ST_Intersects(c.geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT c.conduit_id, c.name, c.status, c.notes,
           c.known_length_m, c.known_length_tol_m,
           ST_Length(c.geom::geography) AS geom_length_m,
           COALESCE((SELECT string_agg(w.work_order, ', ' ORDER BY w.is_primary DESC, w.work_order)
                       FROM app.conduit_work_order w WHERE w.conduit_id = c.conduit_id), '') AS fpm_wor,
           c.bell_permit_num, c.owner,
           c.microduct_type_id,
           c.installation,
           c.is_spare,
           mt.name        AS microduct_type_name,
           mt.way_count,
           mt.inner_ducts,
           mt.has_tracer,
           ST_AsGeoJSON(c.geom, 6) AS geojson,
           -- Conduit is always drawn at its own true geometry — it is never hidden
           -- or collapsed onto a strand's pole line, even when it runs near poles.
           false AS covered_by_strand,
           -- Fibre count of the largest cable running through this conduit, so
           -- the map can label an occupied duct the same way it labels a span.
           -- This is a GEOMETRIC guess — any span running along the duct counts —
           -- a cable's spans count together (one duct can carry several), so two ducts sharing a trench both match the one cable in it. A duct
           -- flagged spare opts out of the guess entirely (migration 0155).
           CASE WHEN c.is_spare THEN NULL ELSE
             (SELECT max(x.fc)
                FROM (SELECT max(cb.fibre_count) AS fc, ST_Union(cs.geom) AS ug
                        FROM app.cable_span cs
                        JOIN app.cable cb ON cb.cable_id = cs.cable_id
                       WHERE cs.geom IS NOT NULL AND ST_DWithin(c.geom, cs.geom, " . COINCIDE_TOL . ")
                       GROUP BY cb.cable_id) x
               WHERE " . features_runs_alongside('c.geom', 'x.ug') . ")
           END AS cable_fibre_count,
           -- ...and that cable's name, so the duct label reads FLEX 12F A rather
           -- than a bare 12F when what is inside it is a FlexTIN tail.
           CASE WHEN c.is_spare THEN NULL ELSE
             (SELECT cb.name
                FROM app.cable_span cs
                JOIN app.cable cb ON cb.cable_id = cs.cable_id
               WHERE cs.geom IS NOT NULL
                 AND " . features_runs_alongside('c.geom', 'cs.geom') . "
               ORDER BY cb.fibre_count DESC NULLS LAST
               LIMIT 1)
           END AS cable_name
      FROM app.conduit c
      LEFT JOIN app.microduct_type mt ON mt.id = c.microduct_type_id
     WHERE c.geom IS NOT NULL
       {$conduitBbox}
     ORDER BY c.conduit_id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'        => 'conduit',
            'conduit_id'          => (int)$row['conduit_id'],
            'name'                => (string)$row['name'],
            'status'              => (string)$row['status'],
            'notes'               => (string)$row['notes'],
            'fpm_wor'          => (string)$row['fpm_wor'],
            'bell_permit_num'     => (string)$row['bell_permit_num'],
            'owner'               => (string)($row['owner'] ?? ''),
            'microduct_type_id'   => $row['microduct_type_id'] !== null ? (int)$row['microduct_type_id'] : null,
            'installation'        => (string)($row['installation'] ?? 'buried'),
            'microduct_type_name' => $row['microduct_type_name'] !== null ? (string)$row['microduct_type_name'] : null,
            'way_count'           => $row['way_count'] !== null ? (int)$row['way_count'] : null,
            'has_tracer'          => $row['has_tracer'] === 't' || $row['has_tracer'] === true,
            'inner_ducts'         => $row['inner_ducts'] !== null ? json_decode((string)$row['inner_ducts'], true) : [],
            'covered_by_strand'   => (bool)$row['covered_by_strand'],
            'is_spare'            => $row['is_spare'] === 't' || $row['is_spare'] === true,
            // Drawn length always travels with the feature; the measured one only
            // when it exists, so the client can tell "27 m measured" from "21 m drawn".
            'geom_length_m'       => $row['geom_length_m'] !== null ? round((float)$row['geom_length_m'], 1) : null,
            'known_length_m'      => $row['known_length_m'] !== null ? (float)$row['known_length_m'] : null,
            'known_length_tol_m'  => $row['known_length_tol_m'] !== null ? (float)$row['known_length_tol_m'] : null,
            'cable_fibre_count'   => $row['cable_fibre_count'] !== null ? (int)$row['cable_fibre_count'] : null,
            'cable_name'          => $row['cable_name'] !== null ? (string)$row['cable_name'] : null,
        ],
    ];
}

// ── Vaults & handholes ────────────────────────────────────────────────────────

$vaultBbox = $hasBbox ? "AND ST_Intersects(v.geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT v.vault_id, v.name, v.type, v.owner, v.owner_ref, v.status, v.notes, v.fpm_wor,
           v.model_id, v.model_other, m.sku AS model_sku, m.description AS model_desc,
           ST_AsGeoJSON(v.geom, 6) AS geojson
      FROM app.vault v
      LEFT JOIN app.bom_material m ON m.id = v.model_id
     WHERE v.geom IS NOT NULL
       {$vaultBbox}
     ORDER BY v.vault_id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => (string)$row['type'],   // 'vault' | 'handhole' | 'pullbox' | 'manhole'
            'vault_id'     => (int)$row['vault_id'],
            'name'         => (string)$row['name'],
            'type'         => (string)$row['type'],
            'owner'        => (string)($row['owner'] ?? ''),
            'owner_ref'    => (string)($row['owner_ref'] ?? ''),
            'status'       => (string)$row['status'],
            'notes'        => (string)$row['notes'],
            'fpm_wor'   => (string)($row['fpm_wor'] ?? ''),
            'model_id'     => $row['model_id'] !== null ? (int)$row['model_id'] : null,
            'model_other'  => (string)($row['model_other'] ?? ''),
            'model_label'  => $row['model_id'] !== null ? (string)$row['model_desc'] . ' (' . $row['model_sku'] . ')' : (string)($row['model_other'] ?? ''),
        ],
    ];
}

// ── Conduit terminations ──────────────────────────────────────────────────────

$termBbox = $hasBbox ? "AND ST_Intersects(geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT termination_id, kind, label, owner, fpm_wor, notes, ST_AsGeoJSON(geom, 7) AS geojson,
           (SELECT string_agg(c.conduit_id::text, ', ' ORDER BY c.conduit_id) FROM app.conduit c
             WHERE c.start_term_id = t.termination_id OR c.end_term_id = t.termination_id) AS conduits
      FROM app.conduit_termination t WHERE geom IS NOT NULL {$termBbox} ORDER BY termination_id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'   => 'conduit_termination',
            'termination_id' => (int)$row['termination_id'],
            'kind'           => (string)$row['kind'],
            'label'          => (string)($row['label'] ?? ''),
            'owner'          => (string)($row['owner'] ?? ''),
            'fpm_wor'     => (string)($row['fpm_wor'] ?? ''),
            'notes'          => (string)($row['notes'] ?? ''),
            'conduits'       => (string)($row['conduits'] ?? ''),
        ],
    ];
}

// ── Map notes ─────────────────────────────────────────────────────────────────

$noteBbox = $hasBbox ? "AND ST_Intersects(geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT id, icon, body, fpm_wor, done_at IS NOT NULL AS done, ST_AsGeoJSON(geom, 6) AS geojson
      FROM app.map_note WHERE geom IS NOT NULL {$noteBbox} ORDER BY id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'map_note',
            'note_id'      => (int)$row['id'],
            'icon'         => (string)$row['icon'],
            'body'         => (string)$row['body'],
            'fpm_wor'   => (string)($row['fpm_wor'] ?? ''),
            'done'         => in_array($row['done'], [true, 't', '1', 1], true),
        ],
    ];
}

// ── Draft survey lines (tentative; replaced when real parcel data arrives) ────

$svBbox = $hasBbox ? "AND ST_Intersects(geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT id, plan_no, plan_date, status, label, notes, markers, ST_AsGeoJSON(geom, 7) AS geojson
      FROM app.survey_draft WHERE status <> 'superseded' {$svBbox} ORDER BY id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'survey_draft',
            'id'           => (int)$row['id'],
            'plan_no'      => (string)$row['plan_no'],
            'plan_date'    => (string)$row['plan_date'],
            'status'       => (string)$row['status'],
            'label'        => (string)$row['label'],
            'notes'        => (string)($row['notes'] ?? ''),
        ],
    ];
}

// ── Utility easements (polygons placed from registered plans) ────────────────

$esBbox = $hasBbox ? "AND ST_Intersects(geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT id, pid, plan_no, plan_date, status, easement_type, label, width_m, notes, calls,
           ST_Area(geom::geography) AS area_m2, ST_AsGeoJSON(geom, 7) AS geojson
      FROM app.easement WHERE status <> 'superseded' {$esBbox} ORDER BY id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'  => 'easement',
            'id'            => (int)$row['id'],
            'plan_no'       => (string)$row['plan_no'],
            'plan_date'     => (string)($row['plan_date'] ?? ''),
            'status'        => (string)$row['status'],
            'easement_type' => (string)$row['easement_type'],
            'pid'           => (string)($row['pid'] ?? ''),
            'label'         => (string)$row['label'],
            'width_m'       => $row['width_m'] === null ? null : (float)$row['width_m'],
            'area_m2'       => round((float)$row['area_m2'], 1),
            'notes'         => (string)($row['notes'] ?? ''),
            'calls'         => json_decode((string)$row['calls'], true) ?: [],
        ],
    ];
}

// ── WiFi access points (Salisbury municipal WiFi): position, model and aim; the cone is drawn client-side ──

$wfBbox = $hasBbox ? "AND ST_Intersects(a.geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT a.id, a.name, a.model, a.status, a.fpm_wor, a.azimuth_deg, a.notes, a.unifi_mac,
           a.beam_deg AS beam_override, a.range_m AS range_override,
           m.label AS model_label, m.pattern, m.beam_deg AS model_beam, m.range_m AS model_range, m.fringe_range_m, m.fringe_beam_deg,
           ST_X(a.geom) AS lon, ST_Y(a.geom) AS lat
      FROM app.wifi_ap a LEFT JOIN app.wifi_ap_model m ON m.model = a.model
     WHERE true {$wfBbox} ORDER BY a.id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => ['type' => 'Point', 'coordinates' => [(float)$row['lon'], (float)$row['lat']]],
        'properties' => [
            'feature_type'   => 'wifi_ap',
            'id'             => (int)$row['id'],
            'name'           => (string)$row['name'],
            'model'          => (string)$row['model'],
            'model_label'    => (string)($row['model_label'] ?? $row['model']),
            'status'         => (string)$row['status'],
            'fpm_wor'     => (string)($row['fpm_wor'] ?? ''),
            'pattern'        => (string)($row['pattern'] ?? 'omni'),
            'azimuth_deg'    => $row['azimuth_deg'] === null ? null : (float)$row['azimuth_deg'],
            'beam_deg'       => (float)($row['beam_override'] ?? $row['model_beam'] ?? 360),
            'range_m'        => (float)($row['range_override'] ?? $row['model_range'] ?? 60),
            // the outer "usable" (2.4 GHz) outline; an edited range scales it by the same ratio
            'fringe_m'       => $row['fringe_range_m'] === null ? null : round((float)$row['fringe_range_m'] * ($row['range_override'] !== null && (float)$row['model_range'] > 0 ? (float)$row['range_override'] / (float)$row['model_range'] : 1)),
            'fringe_beam'    => $row['fringe_beam_deg'] === null ? null : (float)$row['fringe_beam_deg'],
            'beam_override'  => $row['beam_override'] === null ? null : (float)$row['beam_override'],
            'range_override' => $row['range_override'] === null ? null : (float)$row['range_override'],
            'unifi_mac'      => (string)($row['unifi_mac'] ?? ''),
            'notes'          => (string)($row['notes'] ?? ''),
        ],
    ];
}

// ── Sheath marks: the meter marks read off the cable sheath (app.sheath_length), with the cable's serial ──

$shBbox = $hasBbox ? "AND ST_Intersects(sl.geom, {$bboxSql})" : '';
$st = $pdo->query("
    SELECT sl.sheath_length_id, sl.cable_span_id, sl.measure_m, sl.notes,
           s.cable_serial, s.fpm_wor, c.fibre_count,
           (SELECT string_agg(w.work_order, ',' ORDER BY w.is_primary DESC, w.id)
              FROM app.cable_span_work_order w
             WHERE w.cable_span_id = s.cable_span_id) AS work_orders_str,
           ST_X(sl.geom) AS lon, ST_Y(sl.geom) AS lat
      FROM app.sheath_length sl
      LEFT JOIN app.cable_span s ON s.cable_span_id = sl.cable_span_id
      LEFT JOIN app.cable c      ON c.cable_id = s.cable_id
     WHERE true {$shBbox}
     ORDER BY sl.sheath_length_id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => ['type' => 'Point', 'coordinates' => [(float)$row['lon'], (float)$row['lat']]],
        'properties' => [
            'feature_type'  => 'sheath_mark',
            'id'            => (int)$row['sheath_length_id'],
            'cable_span_id' => $row['cable_span_id'] !== null ? (int)$row['cable_span_id'] : null,
            'measure_m'     => (float)$row['measure_m'],
            'notes'         => (string)($row['notes'] ?? ''),
            'cable_serial'  => (string)($row['cable_serial'] ?? ''),
            'fibre_count'   => $row['fibre_count'] !== null ? (int)$row['fibre_count'] : null,
            'fpm_wor'    => (string)($row['fpm_wor'] ?? ''),
            // Inherited from the span: every permit the span carries, primary first.
            'work_orders'   => array_values(array_unique(array_filter(array_merge(
                [(string)($row['fpm_wor'] ?? '')],
                $row['work_orders_str'] !== null ? explode(',', (string)$row['work_orders_str']) : []
            )))),
        ],
    ];
}

// ── Saved dimensions. A straight one follows its end poles; the length is measured here ──

$dmBbox = $hasBbox ? "AND ST_Intersects(d.geom, {$bboxSql})" : '';
$st = $pdo->query("
    WITH d AS (
      SELECT d.*,
             CASE WHEN d.kind = 'straight' THEN
               ST_MakeLine(COALESCE(pa.geom, ST_StartPoint(d.geom)), COALESCE(pb.geom, ST_EndPoint(d.geom)))
             ELSE d.geom END AS g
        FROM app.dimension d
        LEFT JOIN app.pole pa ON pa.fid = d.a_pole_fid
        LEFT JOIN app.pole pb ON pb.fid = d.b_pole_fid
       WHERE true {$dmBbox}
    )
    SELECT id, kind, offset_m, label, permit_ref, fpm_wor, notes, a_pole_fid, b_pole_fid,
           ST_Length(g::geography) AS length_m, ST_AsGeoJSON(g, 7) AS geojson
      FROM d ORDER BY id
");
foreach ($st->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'dimension',
            'id'           => (int)$row['id'],
            'kind'         => (string)$row['kind'],
            'offset_m'     => (float)$row['offset_m'],
            'label'        => (string)($row['label'] ?? ''),
            'permit_ref'   => (string)($row['permit_ref'] ?? ''),
            'fpm_wor'   => (string)($row['fpm_wor'] ?? ''),
            'a_pole_fid'   => $row['a_pole_fid'] !== null ? (int)$row['a_pole_fid'] : null,
            'b_pole_fid'   => $row['b_pole_fid'] !== null ? (int)$row['b_pole_fid'] : null,
            'notes'        => (string)($row['notes'] ?? ''),
            'length_m'     => round((float)$row['length_m'], 2),
        ],
    ];
}

// ── Messenger strands (pole-to-pole, geometry computed from pole locations) ───

// Each endpoint is a pole OR a building anchor (migration 0127). Resolve both via
// LEFT JOINs and COALESCE the geometry/labels; `fg`/`tg` are the from/to points.
// ms.geom is maintained by trigger and GiST-indexed (migration 0131), so filtering
// on it is an index scan — the old filter sat on the CTE's computed fg/tg and could
// never use sidx_poles_geom.
$strandBbox = $hasBbox
    ? "AND ST_Intersects(ms.geom, {$bboxSql})"
    : '';
$stStrand = $pdo->query("
    WITH s AS (
      SELECT ms.*,
             COALESCE(p1.geom, ba1.geom) AS fg,
             COALESCE(p2.geom, ba2.geom) AS tg,
             p1.uid    AS from_pole_uid, p2.uid    AS to_pole_uid,
             p1.\"nbp#\" AS from_pole_nbp, p2.\"nbp#\" AS to_pole_nbp,
             ba1.name  AS from_anchor_name, ba2.name AS to_anchor_name,
             ba1.fpm_wor AS from_anchor_wor, ba2.fpm_wor AS to_anchor_wor
        FROM app.messenger_strand ms
        LEFT JOIN app.pole p1            ON p1.fid  = ms.from_pole_fid
        LEFT JOIN app.pole p2            ON p2.fid  = ms.to_pole_fid
        LEFT JOIN app.building_anchor ba1 ON ba1.id = ms.from_anchor_id
        LEFT JOIN app.building_anchor ba2 ON ba2.id = ms.to_anchor_id
       WHERE ms.geom IS NOT NULL
         {$strandBbox}
    )
    SELECT s.id,
           s.from_pole_fid, s.to_pole_fid,
           s.from_anchor_id, s.to_anchor_id,
           s.from_side, s.from_height, s.to_side, s.to_height,
           s.status, s.tension, s.notes, s.is_overlash, s.fpm_wor,
           s.from_pole_uid, s.to_pole_uid, s.from_pole_nbp, s.to_pole_nbp,
           s.from_anchor_name, s.to_anchor_name,
           (SELECT string_agg(w.work_order, ',' ORDER BY w.is_primary DESC, w.id)
              FROM app.pole_work_order w WHERE w.pole_fid = s.from_pole_fid) AS from_work_orders,
           (SELECT string_agg(w.work_order, ',' ORDER BY w.is_primary DESC, w.id)
              FROM app.pole_work_order w WHERE w.pole_fid = s.to_pole_fid)   AS to_work_orders,
           s.from_anchor_wor, s.to_anchor_wor,
           ST_AsGeoJSON(ST_MakeLine(s.fg, s.tg), 6) AS geojson,
           -- Coincidence flags (computed against ALL data so they don't flip with
           -- the viewport): does a span run ALONG this strand segment?
           --   has_span         — drives the cased-hybrid styling
           --   carried_span_ids — IDs of the spans this strand's casing represents
           --                      (drawn ON the pole line while the span's own
           --                      geometry is offset a few metres). The map uses
           --                      these to offer the covered span in the click-to-edit
           --                      chooser, since its own (invisible) click target sits
           --                      off where the visible casing is drawn.
           --   span_fibre_count — fibre count of the biggest coincident span, which
           --                      drives the casing width
           -- All three come from ONE pass: they were three textually distinct copies
           -- of the same predicate, so PostgreSQL evaluated it three times per strand.
           COALESCE(sp.has_span, false) AS has_span,
           sp.carried_span_ids,
           -- Conduit is NOT drawn on the pole line: it is a free geographic
           -- linestring drawn where it is laid, even when it passes near a pole
           -- (a strand transitioning into conduit just ends at the nearest pole,
           -- and can overrun the conduit entirely). So a strand never carries a
           -- conduit casing — see the conduit query below (covered_by_strand=false).
           false AS has_conduit,
           sp.span_fibre_count,
           -- Data-integrity check: more than one strand of the SAME kind (both plain,
           -- or both overlash) between the SAME unordered endpoint pair is an error.
           -- Endpoints normalised via app.strand_endpoint_key so pole/anchor mixes work.
           -- Counted over ALL rows so the flag doesn't flip with the viewport.
           (CASE WHEN rcc.payload IS NOT NULL THEN NULL ELSE (SELECT array_agg(ms2.id ORDER BY ms2.id) FROM app.messenger_strand ms2
             WHERE least(app.strand_endpoint_key(ms2.from_pole_fid, ms2.from_anchor_id),
                         app.strand_endpoint_key(ms2.to_pole_fid,   ms2.to_anchor_id))
                 = least(app.strand_endpoint_key(s.from_pole_fid, s.from_anchor_id),
                         app.strand_endpoint_key(s.to_pole_fid,   s.to_anchor_id))
               AND greatest(app.strand_endpoint_key(ms2.from_pole_fid, ms2.from_anchor_id),
                            app.strand_endpoint_key(ms2.to_pole_fid,   ms2.to_anchor_id))
                 = greatest(app.strand_endpoint_key(s.from_pole_fid, s.from_anchor_id),
                            app.strand_endpoint_key(s.to_pole_fid,   s.to_anchor_id))
               AND ms2.is_overlash = s.is_overlash) END) AS same_kind_ids,
           rcc.payload AS rc_payload
      FROM s
      " . $rcJoin('strand', 's.id') . "
      LEFT JOIN LATERAL (
        SELECT true                            AS has_span,
               -- ORDER BY so the route chooser lists carried spans in a stable
               -- order; a bare array_agg follows whatever order the plan produces,
               -- which shifts whenever the planner's statistics change.
               array_agg(cs.cable_span_id ORDER BY cs.cable_span_id) AS carried_span_ids,
               max(cb.fibre_count)             AS span_fibre_count
          FROM app.cable_span cs
          JOIN app.cable cb ON cb.cable_id = cs.cable_id
         WHERE rcc.payload IS NULL
           AND cs.geom IS NOT NULL
           AND " . features_runs_alongside('s.geom', 'cs.geom') . "
        HAVING count(*) > 0
      ) sp ON true
     WHERE s.fg IS NOT NULL AND NOT ST_IsEmpty(s.fg)
       AND s.tg IS NOT NULL AND NOT ST_IsEmpty(s.tg)
     ORDER BY s.id
");
foreach ($stStrand->fetchAll() as $row) {
    if ($row['rc_payload'] !== null) {                      // cache hit
        $c = json_decode((string)$row['rc_payload'], true);
        $row['has_span']         = $c['has_span'];
        $row['carried_span_ids'] = $c['carried'];
        $row['span_fibre_count'] = $c['fibre'];
        $row['same_kind_ids']    = $c['same'];
    } elseif ($useRc) {
        $rcNew[] = ['strand', (int)$row['id'], ['has_span' => (bool)$row['has_span'], 'carried' => $row['carried_span_ids'],
                                                'fibre' => $row['span_fibre_count'], 'same' => $row['same_kind_ids']]];
    }
    $fromWOs = array_values(array_filter(array_map('trim', explode(',', (string)($row['from_work_orders'] ?? '')))));
    $toWOs   = array_values(array_filter(array_map('trim', explode(',', (string)($row['to_work_orders']   ?? '')))));
    // Anchor endpoints carry their own (free-text) permit tag; fold it into that side's WOs.
    if (($row['from_anchor_wor'] ?? '') !== '') $fromWOs[] = trim((string)$row['from_anchor_wor']);
    if (($row['to_anchor_wor']   ?? '') !== '') $toWOs[]   = trim((string)$row['to_anchor_wor']);
    // A strand tagged with its own permit (migration 0152) belongs to that job
    // and no other: the endpoints are only a fallback for the untagged ones.
    // Deriving from the poles as well put every strand that merely STARTS on
    // another job's pole onto that job too - the Forestry build leaves Regent
    // pole 7556_7, so its strand and the cable it carries turned up on the
    // Regent permit, which is not what anyone is building there.
    $strandWor = trim((string)($row['fpm_wor'] ?? ''));
    $allWOs  = $strandWor !== ''
        ? [$strandWor]
        : array_values(array_unique(array_merge($fromWOs, $toWOs)));
    // same_kind_ids: all strand IDs of this row's kind on the same endpoint pair. >1 = a
    // duplicate-strand data error the map should flag.
    $sameKindIds = array_values(array_filter(array_map('intval', explode(',',
        trim((string)($row['same_kind_ids'] ?? ''), '{}')))));
    $dupIds = count($sameKindIds) > 1 ? $sameKindIds : [];
    // Spans whose casing this strand paints on the pole line (for the click chooser).
    $carriedSpanIds = array_values(array_filter(array_map('intval', explode(',',
        trim((string)($row['carried_span_ids'] ?? ''), '{}')))));
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type'      => 'strand',
            'id'                => (int)$row['id'],
            'from_pole_fid'     => $row['from_pole_fid'] !== null ? (int)$row['from_pole_fid'] : null,
            'to_pole_fid'       => $row['to_pole_fid']   !== null ? (int)$row['to_pole_fid']   : null,
            'from_anchor_id'    => $row['from_anchor_id'] !== null ? (int)$row['from_anchor_id'] : null,
            'to_anchor_id'      => $row['to_anchor_id']   !== null ? (int)$row['to_anchor_id']   : null,
            'from_anchor_name'  => (string)($row['from_anchor_name'] ?? ''),
            'to_anchor_name'    => (string)($row['to_anchor_name']   ?? ''),
            'from_side'         => (string)$row['from_side'],
            'from_height'       => (string)$row['from_height'],
            'to_side'           => (string)$row['to_side'],
            'to_height'         => (string)$row['to_height'],
            'status'            => (string)$row['status'],
            'tension'           => (string)($row['tension'] ?? 'normal'),
            'notes'             => (string)($row['notes'] ?? ''),
            'from_pole_uid'     => (string)($row['from_pole_uid'] ?? ''),
            'to_pole_uid'       => (string)($row['to_pole_uid']   ?? ''),
            'from_pole_nbp'     => (string)($row['from_pole_nbp'] ?? ''),
            'to_pole_nbp'       => (string)($row['to_pole_nbp']   ?? ''),
            'is_overlash'       => (bool)$row['is_overlash'],
            'fpm_wor'        => $strandWor,
            'from_work_orders'  => $fromWOs,
            'to_work_orders'    => $toWOs,
            'work_orders'       => $allWOs,
            'has_span'          => (bool)$row['has_span'],
            'has_conduit'       => (bool)$row['has_conduit'],
            'span_fibre_count'  => $row['span_fibre_count'] !== null ? (int)$row['span_fibre_count'] : null,
            'dup_strand'        => count($dupIds) > 1,
            'dup_strand_ids'    => $dupIds,
            'carried_span_ids'  => $carriedSpanIds,
        ],
    ];
}

// ── Building anchors (change-of-direction support points, migration 0127) ─────
$anchorBbox = $hasBbox ? "AND ST_Intersects(ba.geom, {$bboxSql})" : '';
$stAnchor = $pdo->query("
    SELECT ba.id, ba.name, ba.notes, ba.status, ba.fpm_wor,
           ST_AsGeoJSON(ba.geom, 6) AS geojson
      FROM app.building_anchor ba
     WHERE ba.geom IS NOT NULL AND NOT ST_IsEmpty(ba.geom)
       {$anchorBbox}
     ORDER BY ba.id
");
foreach ($stAnchor->fetchAll() as $row) {
    $features[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode((string)$row['geojson']),
        'properties' => [
            'feature_type' => 'building_anchor',
            'id'           => (int)$row['id'],
            'name'         => (string)($row['name']   ?? ''),
            'notes'        => (string)($row['notes']  ?? ''),
            'status'       => (string)($row['status'] ?? 'plan'),
            'work_order'   => (string)($row['fpm_wor'] ?? ''),
        ],
    ];
}

// ── Alden import conflicts (pole worklist) ────────────────────────────────────
// Guarded: the view only exists where the Alden import migration has been applied.
$conflicts = [];
if ($pdo->query("SELECT to_regclass('app.v_pole_alden_conflict')")->fetchColumn()) {
    $cStmt = $pdo->query("
        SELECT c.fid, c.your_telco, c.alden_telco, c.your_nbp, c.alden_power_number,
               c.your_owner, c.alden_owner, c.geom_gap_m,
               ST_Y(p.geom) AS lat, ST_X(p.geom) AS lon
          FROM app.v_pole_alden_conflict c
          JOIN app.pole p ON p.fid = c.fid
         ORDER BY c.geom_gap_m DESC NULLS LAST, c.fid
    ");
    foreach ($cStmt as $r) {
        $kinds = [];
        if ($r['your_telco'] !== $r['alden_telco'])             $kinds[] = 'telco';
        if ($r['your_nbp']   !== $r['alden_power_number'])       $kinds[] = 'nbp';
        if ($r['your_owner']  !== $r['alden_owner'])             $kinds[] = 'owner';
        if ($r['geom_gap_m'] !== null && (float)$r['geom_gap_m'] > 5) $kinds[] = 'coord';
        $conflicts[] = [
            'fid'         => (int)$r['fid'],
            'lat'         => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lon'         => $r['lon'] !== null ? (float)$r['lon'] : null,
            'telco'       => $r['your_telco'],
            'alden_telco' => $r['alden_telco'],
            'nbp'         => $r['your_nbp'],
            'gap_m'       => $r['geom_gap_m'] !== null ? round((float)$r['geom_gap_m'], 1) : null,
            'kinds'       => $kinds,
        ];
    }
}

// Store what was computed this request (under the version read at the start; if a
// write landed meanwhile the version has moved on and these rows are simply ignored).
if ($useRc && $rcNew) {
    try {
        foreach (array_chunk($rcNew, 200) as $chunk) {
            $vals = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?::jsonb)'));
            $args = [];
            foreach ($chunk as [$k, $id, $pl]) { array_push($args, $k, $id, (int)$rcVer, json_encode($pl)); }
            $pdo->prepare("INSERT INTO app.route_coincidence_cache (kind, id, v, payload) VALUES {$vals}
                           ON CONFLICT (kind, id) DO UPDATE SET v = EXCLUDED.v, payload = EXCLUDED.payload
                           WHERE app.route_coincidence_cache.v <= EXCLUDED.v")->execute($args);
        }
    } catch (Throwable $e) { /* a cache write must never break the map */ }
}

// ── Abandoned work orders (migration 0305) ───────────────────────────────────
// Plant that belongs only to abandoned work orders is left out. With ?abandoned=1 it is kept and flagged, so the
// map can draw it as a faded ghost without restoring anything.
require_once __DIR__ . '/../phpHelpers/abandoned.helper.php';
$hidden = abandoned_sets($pdo);
if ($hidden) {
    $showAbandoned = abandoned_show();
    $kindOf = ['fosc' => ['fosc', 'fosc_id'], 'span' => ['span', 'cable_span_id'], 'pole' => ['pole', 'fid'],
               'uguard' => ['uguard', 'id'], 'conduit' => ['conduit', 'conduit_id'],
               'vault' => ['vault', 'vault_id'], 'handhole' => ['vault', 'vault_id'], 'pullbox' => ['vault', 'vault_id'],
               'manhole' => ['vault', 'vault_id'], 'conduit_termination' => ['conduit_termination', 'termination_id'],
               'map_note' => ['map_note', 'note_id'], 'survey_draft' => ['survey_draft', 'id'], 'easement' => ['easement', 'id'],
               'dimension' => ['dimension', 'id'], 'strand' => ['strand', 'id'], 'building_anchor' => ['building_anchor', 'id']];
    $kept = [];
    foreach ($features as $f) {
        $p  = $f['properties'] ?? [];
        $ft = $p['feature_type'] ?? '';
        $isHidden = false;
        if (isset($kindOf[$ft])) {
            [$kind, $idKey] = $kindOf[$ft];
            $isHidden = isset($hidden[$kind][(int)($p[$idKey] ?? 0)]);
        }
        // anchors and underground guards hang off a pole: hidden with it
        if (!$isHidden && in_array($ft, ['anchor', 'uguard'], true)) {
            $isHidden = isset($hidden['pole'][(int)($p['pole_fid'] ?? 0)]);
        }
        if ($isHidden) {
            if (!$showAbandoned) continue;
            $f['properties']['abandoned'] = true;
        }
        $kept[] = $f;
    }
    $features = $kept;
}

echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features,
    'violations' => $violations,
    'conflicts' => $conflicts,
]);

// ── helpers ───────────────────────────────────────────────────────────────────

function features_status_badge(string $status): string
{
    return match ($status) {
        'active'             => 'success',
        'plan'               => 'warning',
        'under construction' => 'orange',
        'retired'            => 'secondary',
        default              => 'light',
    };
}
