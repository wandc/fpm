<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/fpm_permits_list.php → list all permit numbers
// POST   /api/fpm_permits_list.php → add a new permit number
// DELETE /api/fpm_permits_list.php → remove an unused permit number

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'GET') {
    $rows = $pdo->query(
        "SELECT permit_num FROM app.fpm_permit ORDER BY permit_num"
    )->fetchAll(PDO::FETCH_COLUMN);
    // Abandoned work orders stay in the list, flagged, so pickers can label them.
    $abandoned = $pdo->query("SELECT permit_num FROM app.fpm_permit WHERE abandoned_at IS NOT NULL ORDER BY permit_num")
                     ->fetchAll(PDO::FETCH_COLUMN);
    api_ok(['permits' => $rows, 'abandoned' => $abandoned]);
}

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();
    $num  = trim((string)($body['permit_num'] ?? ''));
    if ($num === '') {
        api_err('permit_num is required');
    }
    $pdo->prepare(
        "INSERT INTO app.fpm_permit (permit_num) VALUES (:num) ON CONFLICT DO NOTHING"
    )->execute([':num' => $num]);
    api_ok();
}

if ($method === 'DELETE') {
    api_require_csrf();
    $body = api_json_body();
    $num  = trim((string)($body['permit_num'] ?? ''));
    if ($num === '') {
        api_err('permit_num is required');
    }

    $refs = [];

    $st = $pdo->prepare("SELECT count(*) FROM app.pole_work_order WHERE work_order = :num");
    $st->execute([':num' => $num]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) $refs[] = "$n pole(s)";

    $st = $pdo->prepare("SELECT count(*) FROM app.cable_span_work_order WHERE work_order = :num");
    $st->execute([':num' => $num]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) $refs[] = "$n span(s)";

    $st = $pdo->prepare("SELECT count(*) FROM app.fosc_work_order WHERE work_order = :num");
    $st->execute([':num' => $num]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) $refs[] = "$n closure(s)";

    $st = $pdo->prepare("SELECT count(*) FROM app.messenger_strand WHERE fpm_wor = :num");
    $st->execute([':num' => $num]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) $refs[] = "$n strand(s)";

    if ($refs) {
        api_err('Still referenced by: ' . implode(', ', $refs));
    }

    $pdo->prepare("DELETE FROM app.fpm_permit WHERE permit_num = :num")
        ->execute([':num' => $num]);
    api_ok();
}

api_err('Method not allowed', 405);
