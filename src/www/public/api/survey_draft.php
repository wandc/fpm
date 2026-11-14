<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/survey_draft.php        draw a survey line  {label, plan_no, plan_date, status, notes, geojson LineString}
// PUT    /api/survey_draft.php?id=N   edit its details, and/or replace its line with {geojson}
// DELETE /api/survey_draft.php?id=N   remove it (when the real parcel data replaces the draft)

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

$method = api_method();
$statuses = ['tentative', 'final', 'superseded'];

function sd_text($v): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
function sd_line($g): string
{
    if (!is_array($g) || ($g['type'] ?? '') !== 'LineString' || count($g['coordinates'] ?? []) < 2) api_err('geojson must be a LineString with two or more points', 400);
    return json_encode($g);
}

if ($method === 'POST') {
    api_require_csrf();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $label = sd_text($b['label'] ?? null);
    if ($label === null) api_err('A label is required', 400);
    $status = (string)($b['status'] ?? 'tentative');
    if (!in_array($status, $statuses, true)) api_err('Invalid status', 400);
    $st = $pdo->prepare("INSERT INTO app.survey_draft (plan_no, plan_date, status, label, notes, geom)
                         VALUES (:plan, :date, :status, :label, :notes, ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)) RETURNING id");
    $st->execute([':plan' => sd_text($b['plan_no'] ?? null) ?? 'n/a', ':date' => sd_text($b['plan_date'] ?? null) ?? date('Y-m-d'),
                  ':status' => $status, ':label' => $label, ':notes' => sd_text($b['notes'] ?? null), ':g' => sd_line($b['geojson'] ?? null)]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sets = []; $p = [':id' => $id];
    if (array_key_exists('label', $b)) {
        $l = sd_text($b['label']); if ($l === null) api_err('A label is required', 400);
        $sets[] = 'label = :label'; $p[':label'] = $l;
    }
    foreach (['plan_no', 'notes'] as $k) if (array_key_exists($k, $b)) { $sets[] = "$k = :$k"; $p[":$k"] = sd_text($b[$k]); }
    if (array_key_exists('plan_date', $b) && sd_text($b['plan_date'])) { $sets[] = 'plan_date = :plan_date'; $p[':plan_date'] = sd_text($b['plan_date']); }
    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], $statuses, true)) api_err('Invalid status', 400);
        $sets[] = 'status = :status'; $p[':status'] = $b['status'];
    }
    if (isset($b['geojson'])) { $sets[] = 'geom = ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)'; $p[':g'] = sd_line($b['geojson']); }
    if (!$sets) api_err('Nothing to update', 400);
    $st = $pdo->prepare('UPDATE app.survey_draft SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $st->execute($p);
    if ($st->rowCount() === 0) api_err('Survey line not found', 404);
    api_ok(['id' => $id]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $st = $pdo->prepare('DELETE FROM app.survey_draft WHERE id = :id');
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Survey line not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
