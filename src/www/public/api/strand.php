<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/strand.php  — create messenger strand
// PATCH  /api/strand.php  — update messenger strand
// DELETE /api/strand.php  — delete messenger strand

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/wo_inherit.helper.php';

/** @var PDO $pdo */

$method = api_method();

$validSides   = ['road', 'field'];
$validHeights = ['top', 'middle', 'bottom'];
$validStatuses  = ['plan', 'construction', 'active'];
$validTensions  = ['normal', 'slack_span'];

// Resolve one strand endpoint (side = 'from' | 'to') from the request body into
// [pole_fid|null, anchor_id|null, normalized_key]. Exactly one of pole/anchor is
// required, matching the messenger_strand_{from,to}_endpoint_ck CHECKs.
function strand_resolve_endpoint(array $body, string $side): array
{
    $pole   = isset($body["{$side}_pole_fid"])  ? (int)$body["{$side}_pole_fid"]  : 0;
    $anchor = isset($body["{$side}_anchor_id"]) ? (int)$body["{$side}_anchor_id"] : 0;
    if (($pole > 0) === ($anchor > 0)) {
        api_err("Exactly one of {$side}_pole_fid or {$side}_anchor_id is required", 400);
    }
    return $pole > 0
        ? [$pole, null, 'p' . $pole]
        : [null, $anchor, 'a' . $anchor];
}

// Turn a violation of messenger_strand_identical_uidx (migration 0126) into a
// clear 409 instead of a raw 500. Returns normally for any other error so the
// caller can rethrow.
function strand_dup_guard(PDOException $e): void
{
    if ($e->getCode() === '23505'
        && str_contains($e->getMessage(), 'messenger_strand_identical_uidx')) {
        api_err('An identical strand already exists between these two poles '
              . '(same kind, status, tension, and side/height).', 409);
    }
}

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    api_require_csrf();
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    // Each endpoint is a pole OR a building anchor (migration 0127).
    [$fromPole, $fromAnchor, $fromKey] = strand_resolve_endpoint($body, 'from');
    [$toPole,   $toAnchor,   $toKey]   = strand_resolve_endpoint($body, 'to');
    $fromSide   = trim($body['from_side']   ?? '');
    $fromHeight = trim($body['from_height'] ?? '');
    $toSide     = trim($body['to_side']     ?? '');
    $toHeight   = trim($body['to_height']   ?? '');
    $status     = trim($body['status']      ?? 'plan');
    $tension    = trim($body['tension']     ?? 'normal');
    $notes      = trim($body['notes']       ?? '');
    $isOverlash = !empty($body['is_overlash']);
    // Optional Tinuum permit tag (migration 0152) — blank falls back to the
    // permits of the strand's end poles, which is how it worked before.
    $fpmWor  = trim($body['fpm_wor']  ?? '') ?: null;

    if ($fromKey === $toKey) api_err('from and to endpoints must differ', 400);
    if (!in_array($fromSide,   $validSides,   true)) api_err('Invalid from_side', 400);
    if (!in_array($fromHeight, $validHeights, true)) api_err('Invalid from_height', 400);
    if (!in_array($toSide,     $validSides,   true)) api_err('Invalid to_side', 400);
    if (!in_array($toHeight,   $validHeights, true)) api_err('Invalid to_height', 400);
    if (!in_array($status,     $validStatuses, true)) api_err('Invalid status', 400);
    if (!in_array($tension,    $validTensions, true)) api_err('Invalid tension', 400);

    $pdo->beginTransaction();
    $st = $pdo->prepare("
        INSERT INTO app.messenger_strand
               (from_pole_fid, from_anchor_id, to_pole_fid, to_anchor_id,
                from_side, from_height, to_side, to_height, status, tension, notes,
                is_overlash, fpm_wor)
        VALUES (:from_fid, :from_anchor, :to_fid, :to_anchor,
                :from_side, :from_height, :to_side, :to_height, :status, :tension, :notes,
                :is_overlash, :fpm_wor)
        RETURNING id
    ");
    try {
        $st->execute([
            ':from_fid'    => $fromPole,
            ':from_anchor' => $fromAnchor,
            ':to_fid'      => $toPole,
            ':to_anchor'   => $toAnchor,
            ':from_side'   => $fromSide,
            ':from_height' => $fromHeight,
            ':to_side'     => $toSide,
            ':to_height'   => $toHeight,
            ':status'      => $status,
            ':tension'     => $tension,
            ':notes'       => $notes,
            ':is_overlash' => $isOverlash ? 'true' : 'false',
            ':fpm_wor'  => $fpmWor,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        strand_dup_guard($e);
        throw $e;
    }
    $newId = (int)$st->fetchColumn();
    // Cable drawn along this strand (and its closures / FlexTIN) inherits the permit.
    $inherited = $fpmWor !== null ? wo_inherit_from_strand($pdo, $newId, $fpmWor) : [];
    $pdo->commit();
    api_ok(['id' => $newId, 'inherited' => $inherited,
            'inherited_summary' => wo_inherit_summary($inherited)]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    api_require_csrf();
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $id         = isset($body['id']) ? (int)$body['id'] : 0;
    $fromSide   = trim($body['from_side']   ?? '');
    $fromHeight = trim($body['from_height'] ?? '');
    $toSide     = trim($body['to_side']     ?? '');
    $toHeight   = trim($body['to_height']   ?? '');
    $status     = trim($body['status']      ?? 'plan');
    $tension    = trim($body['tension']     ?? 'normal');
    $notes      = trim($body['notes']       ?? '');
    $isOverlash = !empty($body['is_overlash']);
    $fpmWor  = trim($body['fpm_wor']  ?? '') ?: null;

    if (!$id) api_err('id required', 400);
    if (!in_array($fromSide,   $validSides,   true)) api_err('Invalid from_side', 400);
    if (!in_array($fromHeight, $validHeights, true)) api_err('Invalid from_height', 400);
    if (!in_array($toSide,     $validSides,   true)) api_err('Invalid to_side', 400);
    if (!in_array($toHeight,   $validHeights, true)) api_err('Invalid to_height', 400);
    if (!in_array($status,     $validStatuses, true)) api_err('Invalid status', 400);
    if (!in_array($tension,    $validTensions, true)) api_err('Invalid tension', 400);

    $pdo->beginTransaction();
    $st = $pdo->prepare("
        UPDATE app.messenger_strand
           SET from_side   = :from_side,
               from_height = :from_height,
               to_side     = :to_side,
               to_height   = :to_height,
               status      = :status,
               tension     = :tension,
               notes       = :notes,
               is_overlash = :is_overlash,
               fpm_wor  = :fpm_wor
         WHERE id = :id
    ");
    try {
        $st->execute([
            ':id'          => $id,
            ':from_side'   => $fromSide,
            ':from_height' => $fromHeight,
            ':to_side'     => $toSide,
            ':to_height'   => $toHeight,
            ':status'      => $status,
            ':tension'     => $tension,
            ':notes'       => $notes,
            ':is_overlash' => $isOverlash ? 'true' : 'false',
            ':fpm_wor'  => $fpmWor,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        strand_dup_guard($e);
        throw $e;
    }
    if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Strand not found', 404); }
    $inherited = $fpmWor !== null ? wo_inherit_from_strand($pdo, $id, $fpmWor) : [];
    $pdo->commit();
    api_ok(['id' => $id, 'inherited' => $inherited,
            'inherited_summary' => wo_inherit_summary($inherited)]);
}

// ── SPLIT (insert pole atomically) ───────────────────────────────────────────
// PUT { id, insert_pole_fid } → delete A→B, insert A→C and C→B, return { id_ac, id_cb }
if ($method === 'PUT') {
    api_require_csrf();
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id        = isset($body['id'])              ? (int)$body['id']              : 0;
    $insertFid = isset($body['insert_pole_fid']) ? (int)$body['insert_pole_fid'] : 0;

    if (!$id)        api_err('id required', 400);
    if (!$insertFid) api_err('insert_pole_fid required', 400);

    $pdo->beginTransaction();

    $fetch = $pdo->prepare("SELECT * FROM app.messenger_strand WHERE id = :id FOR UPDATE");
    $fetch->execute([':id' => $id]);
    $orig = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$orig) { $pdo->rollBack(); api_err('Strand not found', 404); }

    if ($insertFid === (int)$orig['from_pole_fid'] || $insertFid === (int)$orig['to_pole_fid']) {
        $pdo->rollBack();
        api_err('Pole is already an endpoint of this strand', 400);
    }

    $pdo->prepare("DELETE FROM app.messenger_strand WHERE id = :id")->execute([':id' => $id]);

    $ins = $pdo->prepare("
        INSERT INTO app.messenger_strand
               (from_pole_fid, from_anchor_id, to_pole_fid, to_anchor_id,
                from_side, from_height, to_side, to_height, status, tension, notes,
                is_overlash, fpm_wor)
        VALUES (:from_fid, :from_anchor, :to_fid, :to_anchor,
                :fs, :fh, :ts, :th, :status, :tension, :notes, :overlash, :fpm_wor)
        RETURNING id
    ");
    $params = [
        ':fs'      => $orig['from_side'],
        ':fh'      => $orig['from_height'],
        ':ts'      => $orig['to_side'],
        ':th'      => $orig['to_height'],
        ':status'  => $orig['status'],
        ':tension' => $orig['tension'],
        ':notes'   => $orig['notes'],
        ':overlash'=> $orig['is_overlash'] ? 'true' : 'false',
        ':fpm_wor' => $orig['fpm_wor'],
    ];

    // A→C keeps the original FROM endpoint (pole or anchor); C is the inserted pole.
    $ins->execute(array_merge($params, [
        ':from_fid'   => $orig['from_pole_fid'], ':from_anchor' => $orig['from_anchor_id'],
        ':to_fid'     => $insertFid,             ':to_anchor'   => null,
    ]));
    $idAC = (int)$ins->fetchColumn();

    // C→B: inserted pole is FROM; keeps the original TO endpoint (pole or anchor).
    $ins->execute(array_merge($params, [
        ':from_fid'   => $insertFid,           ':from_anchor' => null,
        ':to_fid'     => $orig['to_pole_fid'], ':to_anchor'   => $orig['to_anchor_id'],
    ]));
    $idCB = (int)$ins->fetchColumn();

    $pdo->commit();
    api_ok(['id_ac' => $idAC, 'id_cb' => $idCB]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    api_require_csrf();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) api_err('id required', 400);

    $st = $pdo->prepare("DELETE FROM app.messenger_strand WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Strand not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
