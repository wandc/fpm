<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/building_anchor.php       → create a building anchor (change-of-direction point)
// PUT  /api/building_anchor.php?id=X  → update props and/or move (geojson)
// DELETE /api/building_anchor.php?id=X → delete (blocked while referenced by a strand)

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

const BA_STATUSES = ['plan', 'construction', 'active'];

if ($method === 'POST') {
    api_require_csrf();
    building_anchor_create($pdo, api_json_body());
} elseif ($method === 'PUT') {
    api_require_csrf();
    building_anchor_update($pdo, (int)($_GET['id'] ?? 0), api_json_body());
} elseif ($method === 'DELETE') {
    api_require_csrf();
    building_anchor_delete($pdo, (int)($_GET['id'] ?? 0));
} else {
    api_err('Method not allowed', 405);
}

function building_anchor_create(PDO $pdo, array $body): never
{
    [$lon, $lat] = ba_geojson_point($body['geojson'] ?? null);
    $name   = ba_text($body['name']  ?? '', 120, 'Name');
    $notes  = ba_text($body['notes'] ?? '', 500, 'Notes');
    $wor    = ba_text($body['work_order'] ?? '', 64, org('wo_label') . ' #');
    $status = in_array(($body['status'] ?? 'plan'), BA_STATUSES, true) ? $body['status'] : 'plan';

    try {
        $st = $pdo->prepare("
            INSERT INTO app.building_anchor (name, notes, status, fpm_wor, geom)
            VALUES (:name, :notes, :status, :wor,
                    ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326))
            RETURNING id
        ");
        $st->execute([
            ':name'   => $name   !== '' ? $name  : null,
            ':notes'  => $notes  !== '' ? $notes : null,
            ':status' => $status,
            ':wor'    => $wor    !== '' ? $wor   : null,
            ':lon'    => $lon,
            ':lat'    => $lat,
        ]);
    } catch (PDOException $e) {
        api_err('Database error: ' . $e->getMessage(), 500);
    }
    api_ok(['id' => (int)$st->fetchColumn()]);
}

function building_anchor_update(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) api_err('Invalid id');

    $setParts = [];
    $params   = [':id' => $id];

    // Props only updated when the edit modal sends them; a geometry-only move (just
    // 'geojson') must not wipe existing values.
    if (array_key_exists('name', $body) || array_key_exists('notes', $body)
        || array_key_exists('status', $body) || array_key_exists('work_order', $body)) {
        $name   = ba_text($body['name']  ?? '', 120, 'Name');
        $notes  = ba_text($body['notes'] ?? '', 500, 'Notes');
        $wor    = ba_text($body['work_order'] ?? '', 64, org('wo_label') . ' #');
        $status = in_array(($body['status'] ?? 'plan'), BA_STATUSES, true) ? $body['status'] : 'plan';
        $setParts[] = 'name       = :name';
        $setParts[] = 'notes      = :notes';
        $setParts[] = 'status     = :status';
        $setParts[] = 'fpm_wor = :wor';
        $params[':name']   = $name  !== '' ? $name  : null;
        $params[':notes']  = $notes !== '' ? $notes : null;
        $params[':status'] = $status;
        $params[':wor']    = $wor   !== '' ? $wor   : null;
    }

    if (is_array($body['geojson'] ?? null)) {
        [$lon, $lat] = ba_geojson_point($body['geojson']);
        $setParts[]     = 'geom = ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)';
        $params[':lon'] = $lon;
        $params[':lat'] = $lat;
    }

    if (empty($setParts)) api_err('Nothing to update');

    try {
        $st = $pdo->prepare('UPDATE app.building_anchor SET ' . implode(', ', $setParts) . ' WHERE id = :id');
        $st->execute($params);
        if ($st->rowCount() === 0) {
            $ex = $pdo->prepare('SELECT 1 FROM app.building_anchor WHERE id = :id');
            $ex->execute([':id' => $id]);
            if (!$ex->fetchColumn()) api_err('Building anchor not found', 404);
        }
    } catch (PDOException $e) {
        api_err('Database error: ' . $e->getMessage(), 500);
    }
    api_ok(['id' => $id]);
}

function building_anchor_delete(PDO $pdo, int $id): never
{
    if ($id <= 0) api_err('Invalid id');
    try {
        $st = $pdo->prepare('DELETE FROM app.building_anchor WHERE id = :id');
        $st->execute([':id' => $id]);
        if ($st->rowCount() === 0) api_err('Building anchor not found', 404);
    } catch (PDOException $e) {
        // ON DELETE RESTRICT from messenger_strand → foreign_key_violation
        if ($e->getCode() === '23503') {
            api_err('Cannot delete: a strand still connects to this anchor. Remove those strands first.', 409);
        }
        api_err('Database error: ' . $e->getMessage(), 500);
    }
    api_ok(['id' => $id]);
}

function ba_geojson_point(mixed $geojson): array
{
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'Point') {
        api_err('geojson must be a GeoJSON Point');
    }
    $coords = $geojson['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) api_err('geojson coordinates are required');
    $lon = (float)$coords[0];
    $lat = (float)$coords[1];
    if (!is_finite($lon) || !is_finite($lat)) api_err('Invalid coordinates');
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) api_err('Coordinates out of range');
    return [$lon, $lat];
}

function ba_text(mixed $raw, int $maxLen, string $label): string
{
    $v = trim((string)$raw);
    if (mb_strlen($v) > $maxLen) api_err($label . " must be {$maxLen} characters or less");
    return $v;
}
