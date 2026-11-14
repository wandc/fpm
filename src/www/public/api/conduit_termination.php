<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// Conduit terminations (migration 0298): where a conduit starts or stops when it is not at a vault, handhole
// or manhole. Created on their own here, or automatically (kind `unverified`) when a conduit is drawn to
// an end with nothing on it.
//
// POST   /api/conduit_termination.php        {kind, label?, owner?, fpm_wor?, notes?, geojson Point}
// PUT    /api/conduit_termination.php?id=N   {kind, label, owner, fpm_wor, notes, geojson? (move: conduit ends follow)}
// DELETE /api/conduit_termination.php?id=N   refused while a conduit end is attached

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();
$KINDS  = ['building', 'pole', 'closure', 'pop', 'unverified'];

function term_text($v): ?string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : $v;
}

if ($method === 'POST' || $method === 'PUT') {
    api_require_csrf();
    $b    = json_decode(file_get_contents('php://input'), true) ?? [];
    $kind = (string)($b['kind'] ?? 'unverified');
    if (!in_array($kind, $KINDS, true)) api_err('Bad kind', 400);
    $geo  = $b['geojson'] ?? null;
    if ($geo !== null && (($geo['type'] ?? '') !== 'Point')) api_err('geojson must be a Point', 400);
    $args = [
        ':kind'  => $kind,
        ':label' => term_text($b['label'] ?? null),
        ':owner' => term_text($b['owner'] ?? null),
        ':wor'   => term_text($b['fpm_wor'] ?? null),
        ':notes' => term_text($b['notes'] ?? null),
    ];
    try {
        if ($method === 'POST') {
            if (!$geo) api_err('geojson Point required', 400);
            $st = $pdo->prepare("INSERT INTO app.conduit_termination (kind, label, owner, fpm_wor, notes, geom)
                                 VALUES (:kind, :label, :owner, :wor, :notes, ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)) RETURNING termination_id");
            $st->execute($args + [':g' => json_encode($geo)]);
            api_ok(['termination_id' => (int)$st->fetchColumn()]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) api_err('id required', 400);
        $st = $pdo->prepare('UPDATE app.conduit_termination SET kind = :kind, label = :label, owner = :owner, fpm_wor = :wor, notes = :notes'
                          . ($geo ? ', geom = ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)' : '') . ' WHERE termination_id = :id');
        $st->execute($args + [':id' => $id] + ($geo ? [':g' => json_encode($geo)] : []));
        if ($st->rowCount() === 0) api_err('Termination not found', 404);
        api_ok(['termination_id' => $id]);
    } catch (PDOException $e) {
        [$msg, $status] = api_db_error_message($e);
        api_err($msg, $status);
    }
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) api_err('id required', 400);
    $use = $pdo->prepare('SELECT conduit_id FROM app.conduit WHERE start_term_id = ? OR end_term_id = ? ORDER BY 1');
    $use->execute([$id, $id]);
    $ids = $use->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) api_err('Conduit ' . implode(', ', $ids) . ' ends here. A conduit end has to be on a vault, handhole or termination; edit or delete the conduit first.', 400);
    $st = $pdo->prepare('DELETE FROM app.conduit_termination WHERE termination_id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) api_err('Termination not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
