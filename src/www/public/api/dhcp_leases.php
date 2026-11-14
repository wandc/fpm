<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// api/dhcp_leases.php — DHCP leases from DB cache (written by cron poller)
// GET /api/dhcp_leases.php           — all routers
// GET /api/dhcp_leases.php?router=rt1 — single router

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config.php';

$filterRouter = $_GET['router'] ?? '';

try {
    if ($filterRouter !== '') {
        $st = $pdo->prepare("SELECT router_id, router_name, leases, fetched_at
                              FROM app.dhcp_lease_cache WHERE router_id = ?");
        $st->execute([$filterRouter]);
    } else {
        $st = $pdo->query("SELECT router_id, router_name, leases, fetched_at
                           FROM app.dhcp_lease_cache ORDER BY router_name");
    }
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

$allLeases  = [];
$fetchedAt  = null;

foreach ($rows as $row) {
    $leases = json_decode($row['leases'], true) ?: [];
    $rname  = $row['router_name'];
    $rid    = $row['router_id'];
    $fat    = $row['fetched_at'];
    if (!$fetchedAt || $fat > $fetchedAt) $fetchedAt = $fat;

    foreach ($leases as $lease) {
        $allLeases[] = array_merge($lease, [
            'router'    => $rname,
            'router_id' => $rid,
        ]);
    }
}

// Index maps for ONU cross-reference
$byRemoteId = [];
$byMac      = [];
foreach ($allLeases as $lease) {
    $rid = $lease['remote_id'] ?? '';
    $mac = $lease['mac_norm']  ?? '';
    if ($rid !== '') $byRemoteId[$rid] = $lease;
    if ($mac !== '') $byMac[$mac]      = $lease;
}

// UISP cross-reference: ONU MAC (normalised) → { site_name, plan }
// OLT MAC (normalised) → device name (from UISP infrastructure devices)
$uispByMac = (object)[];
$oltNames  = (object)[];
try {
    $devRow = $pdo->query("SELECT data FROM app.uisp_cache WHERE endpoint = 'devices'")->fetch();
    if ($devRow) {
        $planById = [];
        $sitesRow = $pdo->query("SELECT data FROM app.uisp_cache WHERE endpoint = 'sites?type=endpoint'")->fetch();
        if ($sitesRow) {
            foreach (json_decode($sitesRow['data'], true) ?: [] as $site) {
                $sid = $site['id'] ?? null;
                if ($sid) $planById[$sid] = $site['ucrm']['service']['name'] ?? null;
            }
        }
        foreach (json_decode($devRow['data'], true) ?: [] as $dev) {
            $type = $dev['identification']['type'] ?? '';
            $mac  = strtolower(str_replace(':', '', $dev['identification']['mac'] ?? ''));
            if ($mac === '') continue;
            if ($type === 'onu') {
                $siteId = $dev['identification']['site']['id'] ?? null;
                $uispByMac->$mac = [
                    'site_name' => $dev['identification']['site']['name'] ?? null,
                    'plan'      => $siteId ? ($planById[$siteId] ?? null) : null,
                ];
            } elseif ($type === 'olt') {
                $oltNames->$mac = $dev['identification']['name'] ?? '';
            }
        }
    }
} catch (PDOException $e) {
    // UISP cross-ref is optional — ignore
}

echo json_encode([
    'ok'           => true,
    'fetched_at'   => $fetchedAt,
    'leases'       => $allLeases,
    'by_remote_id' => $byRemoteId,
    'by_mac'       => $byMac,
    'uisp_by_mac'  => $uispByMac,
    'olt_names'    => $oltNames,
], JSON_UNESCAPED_SLASHES);
