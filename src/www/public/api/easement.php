<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/easement.php        draw an easement  {label, easement_type, width_ft, plan_no, plan_date, status, notes, geojson Polygon}
// PUT    /api/easement.php?id=N   edit its details, and/or replace its outline with {geojson}
// DELETE /api/easement.php?id=N   remove it

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

$method = api_method();
$statuses = ['tentative', 'final', 'superseded'];
$types = ['utility', 'power', 'telecom', 'sewer', 'water', 'drainage', 'access', 'other'];

function es_text($v): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
function es_poly($g): string
{
    if (!is_array($g) || ($g['type'] ?? '') !== 'Polygon' || count($g['coordinates'][0] ?? []) < 4) api_err('geojson must be a Polygon with three or more corners', 400);
    return json_encode($g);
}
// Width is typed in feet (plans are in feet) and stored in metres.
function es_width($ft): ?float
{
    $s = trim((string)($ft ?? ''));
    if ($s === '') return null;
    if (!is_numeric($s) || (float)$s <= 0 || (float)$s > 1000) api_err('Width must be a number of feet', 400);
    return round((float)$s * 0.3048, 3);
}

if ($method === 'POST') {
    api_require_csrf();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $label = es_text($b['label'] ?? null);
    if ($label === null) api_err('A label is required', 400);
    $status = (string)($b['status'] ?? 'tentative');
    if (!in_array($status, $statuses, true)) api_err('Invalid status', 400);
    $type = (string)($b['easement_type'] ?? 'utility');
    if (!in_array($type, $types, true)) api_err('Invalid easement type', 400);
    $st = $pdo->prepare("INSERT INTO app.easement (plan_no, plan_date, status, easement_type, label, width_m, notes, geom)
                         VALUES (:plan, :date, :status, :type, :label, :w, :notes, ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)) RETURNING id");
    $st->execute([':plan' => es_text($b['plan_no'] ?? null) ?? 'n/a', ':date' => es_text($b['plan_date'] ?? null), ':status' => $status,
                  ':type' => $type, ':label' => $label, ':w' => es_width($b['width_ft'] ?? null), ':notes' => es_text($b['notes'] ?? null),
                  ':g' => es_poly($b['geojson'] ?? null)]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sets = []; $p = [':id' => $id];
    if (array_key_exists('label', $b)) {
        $l = es_text($b['label']); if ($l === null) api_err('A label is required', 400);
        $sets[] = 'label = :label'; $p[':label'] = $l;
    }
    foreach (['plan_no', 'notes'] as $k) if (array_key_exists($k, $b)) { $sets[] = "$k = :$k"; $p[":$k"] = es_text($b[$k]); }
    if (array_key_exists('plan_date', $b)) { $sets[] = 'plan_date = :plan_date'; $p[':plan_date'] = es_text($b['plan_date']); }
    if (array_key_exists('width_ft', $b)) { $sets[] = 'width_m = :w'; $p[':w'] = es_width($b['width_ft']); }
    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], $statuses, true)) api_err('Invalid status', 400);
        $sets[] = 'status = :status'; $p[':status'] = $b['status'];
    }
    if (array_key_exists('easement_type', $b)) {
        if (!in_array($b['easement_type'], $types, true)) api_err('Invalid easement type', 400);
        $sets[] = 'easement_type = :type'; $p[':type'] = $b['easement_type'];
    }
    if (isset($b['geojson'])) { $sets[] = 'geom = ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)'; $p[':g'] = es_poly($b['geojson']); }
    if (!$sets) api_err('Nothing to update', 400);
    $st = $pdo->prepare('UPDATE app.easement SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $st->execute($p);
    if ($st->rowCount() === 0) api_err('Easement not found', 404);
    api_ok(['id' => $id]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $st = $pdo->prepare('DELETE FROM app.easement WHERE id = :id');
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Easement not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
