<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/dimension.php        — save a dimension  {kind, coords:[[lon,lat],…], a_pole_fid?, b_pole_fid?, offset_m?, label?, permit_ref?, fpm_wor?}
// PUT    /api/dimension.php?id=N   — edit offset_m, label, permit_ref, fpm_wor, notes
// DELETE /api/dimension.php?id=N   — delete

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

/** Text field: trimmed, empty becomes null. */
function dim_text($v): ?string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : $v;
}

if ($method === 'POST') {
    api_require_csrf();
    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $kind   = ($b['kind'] ?? 'straight') === 'route' ? 'route' : 'straight';
    $coords = $b['coords'] ?? [];
    if (!is_array($coords) || count($coords) < 2) api_err('Two or more points required', 400);
    foreach ($coords as $c) {
        if (!is_array($c) || count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1])) api_err('Bad coordinate', 400);
    }
    if ($kind === 'straight') $coords = [$coords[0], $coords[count($coords) - 1]];

    $st = $pdo->prepare("
        INSERT INTO app.dimension (kind, geom, a_pole_fid, b_pole_fid, offset_m, label, permit_ref, fpm_wor)
        VALUES (:kind, ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326), :a, :b, :off, :label, :permit, :wor)
        RETURNING id
    ");
    $st->execute([
        ':kind'   => $kind,
        ':g'      => json_encode(['type' => 'LineString', 'coordinates' => array_map(fn($c) => [(float)$c[0], (float)$c[1]], $coords)]),
        ':a'      => !empty($b['a_pole_fid']) && $kind === 'straight' ? (int)$b['a_pole_fid'] : null,
        ':b'      => !empty($b['b_pole_fid']) && $kind === 'straight' ? (int)$b['b_pole_fid'] : null,
        ':off'    => (float)($b['offset_m'] ?? 0),
        ':label'  => dim_text($b['label'] ?? null),
        ':permit' => dim_text($b['permit_ref'] ?? null),
        ':wor'    => dim_text($b['fpm_wor'] ?? null),
    ]);
    api_ok(['dimension_id' => (int)$st->fetchColumn()]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);
    $b  = json_decode(file_get_contents('php://input'), true) ?? [];
    $st = $pdo->prepare("
        UPDATE app.dimension
           SET offset_m = :off,
               label = :label, permit_ref = :permit, fpm_wor = :wor, notes = :notes
         WHERE id = :id
    ");
    $st->execute([
        ':off'    => (float)($b['offset_m'] ?? 0),
        ':label'  => dim_text($b['label'] ?? null),
        ':permit' => dim_text($b['permit_ref'] ?? null),
        ':wor'    => dim_text($b['fpm_wor'] ?? null),
        ':notes'  => dim_text($b['notes'] ?? null),
        ':id'     => $id,
    ]);
    if ($st->rowCount() === 0) api_err('Dimension not found', 404);
    api_ok(['dimension_id' => $id]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);
    $st = $pdo->prepare("DELETE FROM app.dimension WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Dimension not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
