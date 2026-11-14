<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/split_span.php — split an existing cable span by inserting a new midpoint closure.
//
// Request body:
//   cable_span_id    int     (required) span to split
//   splice_num       string  (required) splice number for the new closure (ABC1234 or L/R+2+opt -MST)
//   closure_type_id  int     (required) closure type for the new closure
//   split_at         float   (optional, 0–1, default 0.5) fractional position along the line
//
// Transaction:
//   1. Lock and load the original span (cable_id, from_fosc_id, to_fosc_id, geom, notes)
//   2. Create a new fosc at ST_LineInterpolatePoint(geom, split_at)
//   3. Update original span: to_fosc_id = new_fosc, geom = first half, geom_snap = NULL
//   4. Insert second span:   from_fosc_id = new_fosc, to_fosc_id = original_to, geom = second half

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();

$body = api_json_body();

$spanId  = (int)($body['cable_span_id']     ?? 0);
$spliceNum = strtoupper(trim((string)($body['splice_num']      ?? '')));
$typeId    = (int)($body['closure_type_id'] ?? 0);
$splitAt   = isset($body['split_at']) ? (float)$body['split_at'] : 0.5;

// ── validation ────────────────────────────────────────────────────────────────

if ($spanId <= 0) {
    api_err('cable_span_id is required');
}
if ($spliceNum === '') {
    api_err('splice_num is required');
}
if (!preg_match('/^([A-Z]{3}[0-9]{4}|[LR][0-9]{2}(-MST)?)$/', $spliceNum)) {
    api_err('splice_num must be ABC1234, L/R + 2 digits, or L/R + 2 digits + -MST');
}
if ($typeId <= 0) {
    api_err('closure_type_id is required');
}
if ($splitAt <= 0.0 || $splitAt >= 1.0) {
    api_err('split_at must be between 0 and 1 (exclusive)');
}

// ── transaction ───────────────────────────────────────────────────────────────

try {
    $pdo->beginTransaction();

    // 1. Lock and load the span
    $stSpan = $pdo->prepare("
        SELECT cable_id, from_fosc_id, to_fosc_id, notes,
               COALESCE(geom_snap, geom_middle) AS geom
          FROM app.cable_span
         WHERE cable_span_id = :id
         FOR UPDATE
    ");
    $stSpan->execute([':id' => $spanId]);
    $span = $stSpan->fetch(PDO::FETCH_ASSOC);
    if (!$span) {
        $pdo->rollBack();
        api_err('Span not found', 404);
    }
    if ($span['geom'] === null) {
        $pdo->rollBack();
        api_err('Span has no geometry — draw a route first before splitting');
    }

    // 2. Create the new midpoint closure at ST_LineInterpolatePoint
    $stFosc = $pdo->prepare("
        INSERT INTO app.fosc (splice_num, status, location, closure_type_id, kind)
        VALUES (:sn, 'plan'::app.fosc_status,
                ST_LineInterpolatePoint(ST_GeomFromEWKT(:geom), :frac),
                :type_id, 'closure')
        RETURNING fosc_id
    ");
    $stFosc->execute([
        ':sn'      => $spliceNum,
        ':geom'    => $span['geom'],
        ':frac'    => $splitAt,
        ':type_id' => $typeId,
    ]);
    $newFoscId = (int)$stFosc->fetchColumn();

    // 3. Update the original span: shorten to first half, re-point to new closure
    $stUpdate = $pdo->prepare("
        UPDATE app.cable_span
           SET to_fosc_id  = :new_fosc,
               geom_middle = ST_LineSubstring(ST_GeomFromEWKT(:geom), 0, :frac),
               geom_snap   = NULL,
               updated_at  = now()
         WHERE cable_span_id = :id
    ");
    $stUpdate->execute([
        ':new_fosc' => $newFoscId,
        ':geom'     => $span['geom'],
        ':frac'     => $splitAt,
        ':id'       => $spanId,
    ]);

    // 4. Insert the second span (new closure → original destination)
    $stNew = $pdo->prepare("
        INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, geom_middle, notes)
        VALUES (:cable, :from, :to,
                ST_LineSubstring(ST_GeomFromEWKT(:geom), :frac, 1),
                :notes)
        RETURNING cable_span_id
    ");
    $stNew->execute([
        ':cable' => $span['cable_id'],
        ':from'  => $newFoscId,
        ':to'    => $span['to_fosc_id'],
        ':geom'  => $span['geom'],
        ':frac'  => $splitAt,
        ':notes' => (string)($span['notes'] ?? ''),
    ]);
    $newSpanId = (int)$stNew->fetchColumn();

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_err('Database error: ' . $e->getMessage(), 500);
}

api_ok([
    'new_fosc_id' => $newFoscId,
    'new_span_id' => $newSpanId,
]);
