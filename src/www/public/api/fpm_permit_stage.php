<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/fpm_permit_stage.php?permit_num=X → stage history for a work order
// POST   /api/fpm_permit_stage.php              → record a stage change
// DELETE /api/fpm_permit_stage.php              → remove a mistaken log entry
//
// The construction stage lives on the Tinuum work order and nowhere else, so the
// BOM page, the Tinuum Permits page and the Bell Permits page all write here.
// That is the whole point: one value, entered once.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/construction_stage.helper.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'GET') {
    $wor = trim((string)($_GET['permit_num'] ?? ''));
    if ($wor === '') api_err('permit_num required');

    $stmt = $pdo->prepare("
        SELECT id, stage::text AS stage, effective_date::text AS effective_date, note, created_at
        FROM app.fpm_permit_stage
        WHERE permit_num = :wor
        ORDER BY effective_date DESC, id DESC
    ");
    $stmt->execute([':wor' => $wor]);
    api_ok(['history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $wor   = trim((string)($body['permit_num'] ?? ''));
    $stage = trim((string)($body['stage']      ?? ''));
    $date  = trim((string)($body['effective_date'] ?? ''));
    $note  = trim((string)($body['note']       ?? ''));

    if ($wor === '')   api_err('permit_num required');

    // Validate the enum here rather than letting Postgres raise a 22P02 the
    // caller can't act on — same approach as api/bell_permit.php.
    if (!in_array($stage, CONSTRUCTION_STAGES, true)) {
        api_err('invalid stage');
    }
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_err('effective_date must be YYYY-MM-DD');
    }

    // A work order reaches each stage once. Checked here so the caller gets a
    // message naming the stage and its existing date, rather than the generic
    // "That record already exists" _common.php would produce from the 23505.
    $dup = $pdo->prepare("
        SELECT effective_date::text AS effective_date
        FROM app.fpm_permit_stage
        WHERE permit_num = :wor AND stage = :stage::app.construction_stage
    ");
    $dup->execute([':wor' => $wor, ':stage' => $stage]);
    if ($existing = $dup->fetchColumn()) {
        api_err(
            construction_stage_label($stage) . ' is already recorded for ' . $wor
            . ' as of ' . $existing . '. Delete that entry first to re-date it.',
            409
        );
    }

    $stmt = $pdo->prepare("
        INSERT INTO app.fpm_permit_stage (permit_num, stage, effective_date, note)
        VALUES (:wor, :stage::app.construction_stage,
                COALESCE(:eff::date, CURRENT_DATE), :note)
        RETURNING id, effective_date::text AS effective_date
    ");
    // The FK to app.fpm_permit rejects an unknown work order; _common.php
    // turns that into a readable 409 rather than an HTML error page.
    $stmt->execute([
        ':wor'   => $wor,
        ':stage' => $stage,
        ':eff'   => $date !== '' ? $date : null,
        ':note'  => $note,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    api_ok([
        'id'             => (int)$row['id'],
        'stage'          => $stage,
        'effective_date' => $row['effective_date'],
        'label'          => construction_stage_label($stage),
        'badge_class'    => construction_stage_class($stage),
    ]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $body = api_json_body();
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) api_err('id required');

    $stmt = $pdo->prepare("DELETE FROM app.fpm_permit_stage WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) api_err('stage entry not found', 404);

    api_ok();
}

api_err('Method not allowed', 405);
