<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/uisp_refresh.php — POST only
// Force-refreshes both UISP caches (devices + sites) from the live UISP API.
// TTL=0 means: always bypass cache and fetch fresh.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

if (api_method() !== 'POST') {
    api_err('Method not allowed', 405);
}

api_require_csrf();

$devices = uisp_fetch_cached('devices', 0, $pdo);
$sites   = uisp_fetch_cached('sites?type=endpoint', 0, $pdo);
$clients = ucrm_fetch_cached('clients?limit=500', 0, $pdo);

if (isset($devices['error'])) {
    api_err('UISP error (devices): ' . $devices['error']);
}
if (isset($sites['error'])) {
    api_err('UISP error (sites): ' . $sites['error']);
}

api_ok([
    'devices_count' => count($devices),
    'sites_count'   => count($sites),
    'clients_count' => isset($clients['error']) ? 0 : count($clients),
]);
