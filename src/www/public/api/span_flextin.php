<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/span_flextin.php  — create a FlexTIN run (chain of optitap taps)
// Body: { cable_id, from_fosc_id, bearing (float °), direction ('L'|'R'), model ('A'|'B'), notes }
//   or: { cable_id, from_fosc_id, pole_fids: [fid, …], direction, notes }
//
// Two ways to choose the poles. By default the run auto-chains: take every
// strand-connected pole within ±45° of `bearing` and walk out from the closure in
// distance order. That cannot express a gap, and real routes have them — a single
// NB Power pole standing in an otherwise Bell chain gets skipped, and every tap
// after it shifts by one. Passing `pole_fids` names the route outright, in tap
// order, and turns the bearing/model guesswork off.
//
// `pole_fids` stays one entry per fibre. A pole serving more than one ONU is
// listed back-to-back, once per ONU, and gets that many stacked taps sharing its
// coordinates — so the drop-and-pass numbering below needs no special case.
//
// `first_span_conduit_id` routes the closure→first-tap span along a duct instead of
// straight across the ground. A cabinet feeds its riser pole underground, and that
// leg is both longer than the straight line (it costs cable) and the only thing
// that makes the duct read as occupied — occupancy is inferred from geometry, there
// being no span↔conduit link table.

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

if (api_method() !== 'POST') {
    api_err('Method not allowed', 405);
}

api_require_csrf();
$body = api_json_body();
flextin_create($pdo, $body);

// ── handler ───────────────────────────────────────────────────────────────────

function flextin_create(PDO $pdo, array $body): never
{
    $cableId    = (int)($body['cable_id']     ?? 0);
    $fromFoscId = (int)($body['from_fosc_id'] ?? 0);
    $bearing    = isset($body['bearing'])   ? (float)$body['bearing']                     : null;
    $direction  = isset($body['direction']) ? strtoupper(trim((string)$body['direction'])) : '';
    $model      = isset($body['model'])     ? strtoupper(trim((string)$body['model']))     : '';
    $notes      = trim((string)($body['notes'] ?? ''));

    // An explicit route replaces bearing + model rather than refining them.
    $poleFids   = $body['pole_fids'] ?? null;
    $explicit   = is_array($poleFids) && count($poleFids) > 0;
    $firstDuct  = (int)($body['first_span_conduit_id'] ?? 0);

    // ── validation ────────────────────────────────────────────────────────────
    if ($cableId <= 0)    api_err('cable_id is required');
    if ($fromFoscId <= 0) api_err('from_fosc_id is required');
    if (!in_array($direction, ['L', 'R'], true)) api_err('direction must be L or R');
    if ($explicit) {
        $clean = [];
        foreach ($poleFids as $fid) {
            if (!is_int($fid) && !(is_string($fid) && ctype_digit($fid))) {
                api_err('pole_fids must be a list of integer pole fids');
            }
            $fid = (int)$fid;
            if ($fid <= 0) api_err('pole_fids must be positive integers');
            // A pole may repeat only back-to-back — that is a multi-ONU pole taking
            // one stacked tap per fibre. Reappearing after a different pole instead
            // means the route doubles back on itself, which is always a mistake.
            $prev = $clean ? $clean[count($clean) - 1] : null;
            if ($fid !== $prev && in_array($fid, $clean, true)) {
                api_err("pole_fids returns to fid {$fid} after leaving it");
            }
            $clean[] = $fid;
        }
        $poleFids = $clean;
    } else {
        if ($bearing === null || !is_finite($bearing) || $bearing < 0 || $bearing >= 360) {
            api_err('bearing must be a finite number in [0, 360)');
        }
        if (!in_array($model, ['A', 'B'], true)) api_err('model must be A or B');
    }

    // Cable must exist and be open_ended
    $stCable = $pdo->prepare("SELECT fibre_count, open_ended FROM app.cable WHERE cable_id = :id");
    $stCable->execute([':id' => $cableId]);
    $cable = $stCable->fetch();
    if (!$cable)               api_err('Unknown cable_id');
    if (!$cable['open_ended']) api_err('Cable is not open_ended (FlexTIN)');
    $fibreCount = (int)$cable['fibre_count'];

    // From-FOSC must exist and NOT be an optitap
    $stFosc = $pdo->prepare("
        SELECT fosc_id,
               ST_Y(location) AS lat,
               ST_X(location) AS lon,
               kind
          FROM app.fosc
         WHERE fosc_id = :id
    ");
    $stFosc->execute([':id' => $fromFoscId]);
    $fosc = $stFosc->fetch();
    if (!$fosc)                      api_err('Unknown from_fosc_id');
    if ($fosc['kind'] === 'optitap') api_err('from_fosc_id must not be an optitap');

    $closureLat = (float)$fosc['lat'];
    $closureLon = (float)$fosc['lon'];

    // ── choose the poles: named outright, or auto-chained along the bearing ───
    if ($explicit) {
        if (count($poleFids) !== $fibreCount) {
            api_err('pole_fids must name exactly ' . $fibreCount . ' poles (one per fibre), got '
                    . count($poleFids));
        }
        // Fetch in one shot, then re-order to the caller's sequence — the tap
        // numbering follows the order given, not anything the database decides.
        $ph  = implode(',', array_fill(0, count($poleFids), '?'));
        try {
            $stSel = $pdo->prepare("
                SELECT p.fid,
                       ST_Y(p.geom) AS lat,
                       ST_X(p.geom) AS lon,
                       EXISTS (
                           SELECT 1 FROM app.messenger_strand ms
                            WHERE ms.from_pole_fid = p.fid OR ms.to_pole_fid = p.fid
                       ) AS on_strand
                  FROM app.pole p
                 WHERE p.fid IN ($ph)
                   AND p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
            ");
            $stSel->execute($poleFids);
            $byFid = [];
            foreach ($stSel->fetchAll() as $row) $byFid[(int)$row['fid']] = $row;
        } catch (PDOException $e) { api_err('Pole lookup failed: ' . $e->getMessage(), 500); }

        $poles = [];
        foreach ($poleFids as $fid) {
            if (!isset($byFid[$fid])) api_err("Unknown pole fid {$fid} (or it has no location)");
            if (!$byFid[$fid]['on_strand']) {
                api_err("Pole fid {$fid} carries no messenger strand — a FlexTIN tap needs one");
            }
            $poles[] = $byFid[$fid];
        }
    } else {
        // ── find poles within 500 m in the bearing direction (±45°) ──────────────
        // CTE holds the closure point so named params are used only once each.
        try { $stPoles = $pdo->prepare("
                WITH closure AS (
                    SELECT ST_SetSRID(ST_MakePoint(CAST(:clon AS float8), CAST(:clat AS float8)), 4326) AS pt
                ),
                candidates AS (
                    SELECT p.fid,
                           ST_Y(p.geom) AS lat,
                           ST_X(p.geom) AS lon,
                           ST_Distance(c.pt::geography, p.geom::geography) AS dist_m,
                           DEGREES(ST_Azimuth(c.pt, p.geom)) AS az
                      FROM app.pole p, closure c
                     WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
                       AND ST_DWithin(c.pt::geography, p.geom::geography, 5000)
                       AND EXISTS (
                               SELECT 1 FROM app.messenger_strand ms
                                WHERE ms.from_pole_fid = p.fid OR ms.to_pole_fid = p.fid
                           )
                )
                SELECT fid, lat, lon, dist_m, az
                  FROM candidates
                 WHERE LEAST(
                         MOD(CAST(az - CAST(:bearing  AS float8) + 360 AS numeric), 360.0),
                         MOD(CAST(CAST(:bearing2 AS float8) - az + 360 AS numeric), 360.0)
                       ) <= 45
                 ORDER BY dist_m
            ");
            $stPoles->execute([
                ':clat'     => $closureLat,
                ':clon'     => $closureLon,
                ':bearing'  => $bearing,
                ':bearing2' => $bearing,
            ]);
            $poles = $stPoles->fetchAll();
        } catch (PDOException $e) { api_err('Pole query failed: ' . $e->getMessage(), 500); }

        // Model B: skip the nearest pole
        if ($model === 'B' && count($poles) > 0) {
            array_shift($poles);
        }

        if (count($poles) < $fibreCount) {
            api_err(
                "Not enough poles found in that direction (need {$fibreCount}, found " .
                count($poles) . ')'
            );
        }

        $poles = array_slice($poles, 0, $fibreCount);
    }

    // ── optional duct route for the closure → first tap leg ──────────────────
    // Oriented so the duct is walked away from the closure, whichever way it was
    // drawn; _assemble_span() then bookends it with the two endpoints.
    $firstSpanGeo = null;
    if ($firstDuct > 0) {
        $stDuct = $pdo->prepare("
            SELECT ST_AsGeoJSON(
                     CASE WHEN ST_Distance(ST_StartPoint(geom)::geography, c.pt::geography)
                             <= ST_Distance(ST_EndPoint(geom)::geography,   c.pt::geography)
                          THEN geom ELSE ST_Reverse(geom) END
                   ) AS gj
              FROM app.conduit,
                   (SELECT ST_SetSRID(ST_MakePoint(CAST(:clon AS float8), CAST(:clat AS float8)), 4326) AS pt) c
             WHERE conduit_id = :cid AND geom IS NOT NULL
        ");
        $stDuct->execute([':cid' => $firstDuct, ':clat' => $closureLat, ':clon' => $closureLon]);
        $gj = $stDuct->fetchColumn();
        if ($gj === false || $gj === null) api_err("Unknown conduit_id {$firstDuct} (or it has no geometry)");
        $firstSpanGeo = json_decode((string)$gj, true);
        if (!is_array($firstSpanGeo)) api_err('Could not read that conduit geometry');
    }

    // ── check for existing run from this closure on this side ────────────────
    $stExist = $pdo->prepare("
        SELECT COUNT(*)
          FROM app.fosc
         WHERE kind = 'optitap'
           AND (attrs->>'parent_fosc_id')::bigint = :fid
           AND splice_num LIKE :dir_prefix
    ");
    $stExist->execute([':fid' => $fromFoscId, ':dir_prefix' => $direction . '%']);
    if ((int)$stExist->fetchColumn() > 0) {
        api_err("A {$direction}-side FlexTIN run already exists from this closure. Delete it first.");
    }

    // Each run always starts at 01 — numbering is per-run, not global
    $startNum = 1;

    // ── transaction: insert optitap FOSCs + spans ─────────────────────────────
    try {
        $pdo->beginTransaction();

        $prevFoscId   = $fromFoscId;
        $tapNums      = [];
        $tapsCreated  = 0;
        $spansCreated = 0;

        $newFoscIds = [];   // 1-indexed tap fosc IDs
        $spanIds    = [];   // 1-indexed span IDs

        foreach ($poles as $pole) {
            $tapNum = $direction . str_pad((string)($startNum + $tapsCreated), 2, '0', STR_PAD_LEFT);
            $tapNums[] = $tapNum;

            $poleLat = (float)$pole['lat'];
            $poleLon = (float)$pole['lon'];

            // Insert optitap FOSC at pole location
            $stFoscIns = $pdo->prepare("
                INSERT INTO app.fosc (splice_num, status, location, closure_type_id, kind, attrs)
                VALUES (:sn, 'plan'::app.fosc_status,
                        ST_SetSRID(ST_MakePoint(CAST(:lon AS float8), CAST(:lat AS float8)), 4326),
                        8, 'optitap',
                        jsonb_build_object('parent_fosc_id', CAST(:parent_id AS bigint)))
                RETURNING fosc_id
            ");
            $stFoscIns->execute([
                ':sn'        => $tapNum,
                ':lat'       => $poleLat,
                ':lon'       => $poleLon,
                ':parent_id' => $fromFoscId,
            ]);
            $newFoscId = (int)$stFoscIns->fetchColumn();
            $tapsCreated++;
            $newFoscIds[$tapsCreated] = $newFoscId;

            // Only the first span can carry a duct route; the rest are pole-to-pole.
            // Without geom_middle the trigger auto-creates a straight-line geom_snap.
            $spanId = flextin_span_insert($pdo, $cableId, $prevFoscId, $newFoscId, $notes,
                                          $tapsCreated === 1 ? $firstSpanGeo : null);
            $spanIds[$tapsCreated] = $spanId;
            $spansCreated++;

            $prevFoscId = $newFoscId;
        }

        // Pass-through splice_join for each intermediate tap.
        // Tap i passes fibers [i+1, fibreCount+1) from incoming span i → outgoing span i+1.
        $makePassThrough = $pdo->prepare("
            INSERT INTO app.splice_join
                (fosc_id, a_target, a_span_id, a_end, a_range,
                 b_target, b_span_id, b_end, b_range, is_plan, join_kind)
            VALUES
                (:fosc_id, 'cable_span', :a_span_id, 'to', :a_range,
                 'cable_span', :b_span_id, 'from', :b_range, false, 'pass_through')
        ");
        for ($i = 1; $i < $tapsCreated; $i++) {
            $range = sprintf('[%d,%d)', $i + 1, $fibreCount + 1);
            $makePassThrough->execute([
                ':fosc_id'   => $newFoscIds[$i],
                ':a_span_id' => $spanIds[$i],
                ':a_range'   => $range,
                ':b_span_id' => $spanIds[$i + 1],
                ':b_range'   => $range,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok([
        'taps_created'  => $tapsCreated,
        'tap_nums'      => $tapNums,
        'spans_created' => $spansCreated,
    ]);
}

// ── local span insert (same body as span_insert in span.php; avoids require_once
//    entanglement with span.php's function-level code) ─────────────────────────

function flextin_span_insert(PDO $pdo, int $cableId, int $fromFoscId, int $toFoscId,
                              string $notes, ?array $geojson): int
{
    $hasGeom = is_array($geojson)
        && ($geojson['type'] ?? '') === 'LineString'
        && count($geojson['coordinates'] ?? []) >= 2;

    if ($hasGeom) {
        $st = $pdo->prepare("
            INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, geom_middle, notes)
            VALUES (:cable, :from, :to,
                    ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326),
                    :notes)
            RETURNING cable_span_id
        ");
        $st->execute([
            ':cable'   => $cableId,
            ':from'    => $fromFoscId,
            ':to'      => $toFoscId,
            ':geojson' => json_encode($geojson),
            ':notes'   => $notes,
        ]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, notes)
            VALUES (:cable, :from, :to, :notes)
            RETURNING cable_span_id
        ");
        $st->execute([
            ':cable' => $cableId,
            ':from'  => $fromFoscId,
            ':to'    => $toFoscId,
            ':notes' => $notes,
        ]);
    }
    return (int)$st->fetch()['cable_span_id'];
}
