<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/span_via_conduits.php  {cable_span_id, conduit_ids: [id, id, ...]}
// Route a cable span through conduits, in the order given: from its first closure, along each conduit
// (turned whichever way meets the previous point), to its second closure. The conduit lines themselves
// are the route, so the cable sits exactly in them, and closure moves later keep it there.
//
// Refused when a conduit is more than 60 m from the point reached so far (wrong order, or not the same
// run), or when the span or a conduit is missing.

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();
$body = api_json_body();
$spanId = (int)($body['cable_span_id'] ?? 0);
$ids = array_values(array_filter(array_map('intval', (array)($body['conduit_ids'] ?? []))));
if ($spanId <= 0 || !$ids) api_err('cable_span_id and at least one conduit are required');

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT ST_AsEWKT(f1.location) AS a, ST_AsEWKT(f2.location) AS b FROM app.cable_span s
                           JOIN app.fosc f1 ON f1.fosc_id = s.from_fosc_id JOIN app.fosc f2 ON f2.fosc_id = s.to_fosc_id
                          WHERE s.cable_span_id = ? FOR UPDATE OF s');
    $st->execute([$spanId]);
    $ends = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ends) { $pdo->rollBack(); api_err('Span not found', 404); }

    $cg = $pdo->prepare('SELECT ST_AsEWKT(geom) FROM app.conduit WHERE conduit_id = ?');
    $parts = [$ends['a']];                       // EWKT pieces, joined in PostGIS below
    $cur = $ends['a'];
    $far = $pdo->prepare('SELECT ST_Distance(ST_StartPoint(g)::geography, p::geography) AS ds, ST_Distance(ST_EndPoint(g)::geography, p::geography) AS de
                            FROM (SELECT ST_GeomFromEWKT(:g) AS g, ST_GeomFromEWKT(:p) AS p) x');
    foreach ($ids as $cid) {
        $cg->execute([$cid]);
        $g = $cg->fetchColumn();
        if (!$g) { $pdo->rollBack(); api_err("Conduit $cid not found or has no line", 404); }
        $far->execute([':g' => $g, ':p' => $cur]);
        $d = $far->fetch(PDO::FETCH_ASSOC);
        if (min((float)$d['ds'], (float)$d['de']) > 60) {
            $pdo->rollBack();
            api_err("Conduit $cid is " . round(min((float)$d['ds'], (float)$d['de'])) . ' m from where the cable has got to; click the conduits in the order the cable runs through them');
        }
        // orient: the end nearer the current point goes first
        if ((float)$d['de'] < (float)$d['ds']) {
            $r = $pdo->prepare('SELECT ST_AsEWKT(ST_Reverse(ST_GeomFromEWKT(?)))'); $r->execute([$g]); $g = $r->fetchColumn();
        }
        $parts[] = $g;
        $e = $pdo->prepare('SELECT ST_AsEWKT(ST_EndPoint(ST_GeomFromEWKT(?)))'); $e->execute([$g]); $cur = $e->fetchColumn();
    }
    $parts[] = $ends['b'];

    $sql = 'SELECT ST_AsEWKT(ST_RemoveRepeatedPoints(ST_MakeLine(ARRAY[' . implode(',', array_fill(0, count($parts), 'ST_GeomFromEWKT(?)')) . ']::geometry[])))';
    $m = $pdo->prepare($sql); $m->execute($parts);
    $geom = $m->fetchColumn();

    $pdo->prepare('UPDATE app.cable_span SET geom_middle = ST_GeomFromEWKT(:g), geom_snap = ST_GeomFromEWKT(:g), installation = COALESCE(installation, \'conduit\'), updated_at = now()
                    WHERE cable_span_id = :id')->execute([':g' => $geom, ':id' => $spanId]);
    $sup = $pdo->prepare('SELECT length_m FROM app.cable_span WHERE cable_span_id = ?');
    $sup->execute([$spanId]);
    $pdo->commit();
    api_ok(['cable_span_id' => $spanId, 'length_m' => round((float)$sup->fetchColumn(), 1), 'conduits' => $ids]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_err('Database error: ' . $e->getMessage(), 500);
}
