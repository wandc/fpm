<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/pole_bulk.php — apply the same field values to multiple poles at once
// Body: { "fids": [1,2,3], "fields": { "owner": "Bell", "type": "wood" } }
// Only uid is excluded from bulk editing (should be unique per pole).

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/wo_inherit.helper.php';

/** @var PDO $pdo */

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();

$body   = api_json_body();
$fids   = $body['fids']   ?? null;
$fields = $body['fields'] ?? null;

if (!is_array($fids) || empty($fids)) api_err('fids must be a non-empty array');
if (!is_array($fields) || empty($fields)) api_err('fields must be a non-empty object');

// Validate fids
$fids = array_map('intval', $fids);
foreach ($fids as $fid) {
    if ($fid <= 0) api_err('Invalid fid in list');
}

// Allowed bulk fields (uid excluded — must be unique)
$colMap = [
    'type'        => 'type',
    'owner'       => 'owner',
    'nbp_num'     => '"nbp#"',
    'telco_num'   => '"telco#"',
    'tags_num'    => '"tags#"',
    'comment'     => 'comment',
    'work_order'  => 'fpm_wor',
];
$maxLen = [
    'type'        => 80,
    'owner'       => 15,
    'nbp_num'     => 18,
    'telco_num'   => 18,
    'tags_num'    => 30,
    'comment'     => 254,
    'work_order'  => 80,
];
$labels = [
    'type'        => 'Type',
    'owner'       => 'Owner',
    'nbp_num'     => 'NBP#',
    'telco_num'   => 'Telco#',
    'tags_num'    => 'Tags#',
    'comment'     => 'Notes',
    'work_order'  => 'Work Order#',
];

$setParts = [];
$params   = [];
$i        = 0;

foreach ($colMap as $key => $col) {
    if (!array_key_exists($key, $fields)) continue;
    $val = trim((string)($fields[$key] ?? ''));
    if (mb_strlen($val) > $maxLen[$key]) {
        api_err($labels[$key] . ' must be ' . $maxLen[$key] . ' characters or less');
    }
    if ($key === 'type' && $val === '') $val = 'wood';
    $param          = ':f' . $i++;
    $setParts[]     = $col . ' = ' . $param;
    $params[$param] = $val !== '' ? $val : null;
}

if (empty($setParts)) api_err('No valid fields to update');

// Build IN clause
$inParams = [];
foreach ($fids as $j => $fid) {
    $p            = ':id' . $j;
    $inParams[]   = $p;
    $params[$p]   = $fid;
}

$sql = 'UPDATE app.pole SET ' . implode(', ', $setParts)
     . ' WHERE fid IN (' . implode(', ', $inParams) . ')';

// Detect if work_order is being bulk-set so we can sync the junction table
$bulkWorkOrder = null;
if (array_key_exists('work_order', $fields)) {
    $bulkWorkOrder = trim((string)($fields['work_order'] ?? ''));
}

$inherited = [];

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $updated = $st->rowCount();

    if ($bulkWorkOrder !== null) {
        // Sync pole_work_order for each affected fid (bulk sets primary only; no extras)
        $delSt = $pdo->prepare('DELETE FROM app.pole_work_order WHERE pole_fid = :fid');
        $insSt = $pdo->prepare('INSERT INTO app.pole_work_order (pole_fid, work_order, is_primary) VALUES (:fid, :wo, true)');
        foreach ($fids as $fid) {
            $delSt->execute([':fid' => $fid]);
            if ($bulkWorkOrder !== '') {
                $insSt->execute([':fid' => $fid, ':wo' => $bulkWorkOrder]);
            }
        }
        // Same inheritance the single-pole save does, once per pole in the selection.
        if ($bulkWorkOrder !== '') {
            foreach ($fids as $fid) {
                foreach (wo_inherit_from_pole($pdo, (int)$fid, $bulkWorkOrder) as $k => $v) {
                    $inherited[$k] = ($inherited[$k] ?? 0) + $v;
                }
            }
        }
    }

    $pdo->commit();
    api_ok(['updated' => $updated, 'inherited' => $inherited,
            'inherited_summary' => wo_inherit_summary($inherited)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_err('Database error: ' . $e->getMessage(), 500);
}
