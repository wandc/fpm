<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/mst.php — atomically create an MST FOSC + its internal PLC splitter device

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();
    mst_create($pdo, $body);
} else {
    api_err('Method not allowed', 405);
}

// ── handler ───────────────────────────────────────────────────────────────────

function mst_create(PDO $pdo, array $body): never
{
    $spliceNum = strtoupper(trim((string)($body['splice_num'] ?? '')));
    $status    = (string)($body['status']  ?? 'plan');
    $ports     = (int)($body['ports']      ?? 0);
    $notes     = trim((string)($body['notes'] ?? ''));
    $geojson   = $body['geojson'] ?? null;

    if ($spliceNum === '') {
        api_err('splice_num is required');
    }
    if (!preg_match('/^[LR][0-9]{2}-MST$/', $spliceNum)) {
        api_err('splice_num must be L/R + 2 digits + -MST (e.g. L01-MST, R04-MST)');
    }
    if (!in_array($status, ['active', 'plan', 'retired'], true)) {
        api_err('Invalid status value');
    }
    if (!in_array($ports, [2, 4], true)) {
        api_err('ports must be 2 or 4');
    }
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'Point') {
        api_err('geojson must be a GeoJSON Point');
    }

    [$lon, $lat] = $geojson['coordinates'];

    // Look up the MST closure_type_id
    $stType = $pdo->prepare("SELECT closure_type_id FROM app.closure_type WHERE name = 'MST' LIMIT 1");
    $stType->execute();
    $typeRow = $stType->fetch();
    if (!$typeRow) {
        api_err('MST closure type not found — ensure migration 0018 has been applied', 500);
    }
    $typeId = (int)$typeRow['closure_type_id'];

    try {
        $pdo->beginTransaction();

        // 1. Insert FOSC with kind='mst'
        $stFosc = $pdo->prepare("
            INSERT INTO app.fosc (splice_num, status, closure_type_id, location, notes, kind)
            VALUES (:splice, :status, :type,
                    ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326),
                    :notes, 'mst')
            RETURNING fosc_id
        ");
        $stFosc->execute([
            ':splice' => $spliceNum,
            ':status' => $status,
            ':type'   => $typeId,
            ':lon'    => $lon,
            ':lat'    => $lat,
            ':notes'  => $notes,
        ]);
        $foscRow = $stFosc->fetch();
        $foscId  = (int)$foscRow['fosc_id'];

        // 2. Insert the internal PLC splitter device
        $deviceName = 'PLC 1\u00d7' . $ports;   // PLC 1×2 or PLC 1×4
        $stDev = $pdo->prepare("
            INSERT INTO app.fosc_device (fosc_id, device_type, name, attrs)
            VALUES (:fosc_id, 'splitter', :name, :attrs)
            RETURNING fosc_device_id
        ");
        $stDev->execute([
            ':fosc_id' => $foscId,
            ':name'    => 'PLC 1×' . $ports,
            ':attrs'   => json_encode(['outs' => $ports]),
        ]);
        $devRow      = $stDev->fetch();
        $foscDevId   = (int)$devRow['fosc_device_id'];

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['fosc_id' => $foscId, 'fosc_device_id' => $foscDevId]);
}
