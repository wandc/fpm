<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/bell_permits_list.php → list all Bell permit numbers (autocomplete source)
//
// Read-only on purpose: a Bell permit is a real permit with a date, municipality
// and status, so it gets created on the Bell Permits page — never inline from a
// map field. Mirrors the shape of api/fpm_permits_list.php.

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

if (api_method() !== 'GET') {
    api_err('Method not allowed', 405);
}

$rows = $pdo->query(
    "SELECT permit_num FROM app.bell_permit WHERE permit_num <> '' ORDER BY permit_num"
)->fetchAll(PDO::FETCH_COLUMN);

api_ok(['permits' => $rows]);
