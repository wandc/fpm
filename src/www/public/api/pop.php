<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET  /api/pop.php?unplaced=1 → POPs that have no map location yet (picker list)
// POST /api/pop.php            → create a POP at a map point
// PUT  /api/pop.php?id=X       → update name/notes and/or move (geojson)
// DELETE /api/pop.php?id=X     → delete a POP that no longer has closures, devices or logical links
//
// Deletion stays on pages/pop.php, which refuses to drop a POP that still has
// member closures or homed devices.

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'GET') {
    pop_list_unplaced($pdo);
} elseif ($method === 'POST') {
    api_require_csrf();
    pop_create($pdo, api_json_body());
} elseif ($method === 'PUT') {
    api_require_csrf();
    pop_update($pdo, (int)($_GET['id'] ?? 0), api_json_body());
} elseif ($method === 'DELETE') {
    api_require_csrf();
    pop_delete($pdo, (int)($_GET['id'] ?? 0));
} else {
    api_err('Method not allowed', 405);
}

/** POPs created without coordinates — they never appear on the map until placed. */
function pop_list_unplaced(PDO $pdo): never
{
    if (($_GET['unplaced'] ?? '') === '') {
        api_err('Unsupported query');
    }
    $rows = $pdo->query("
        SELECT pop_id, name
          FROM app.pop
         WHERE location IS NULL
      ORDER BY name
    ")->fetchAll();

    api_ok(['pops' => array_map(
        fn(array $r) => ['pop_id' => (int)$r['pop_id'], 'name' => $r['name']],
        $rows
    )]);
}

function pop_create(PDO $pdo, array $body): never
{
    [$lon, $lat] = pop_geojson_point($body['geojson'] ?? null);
    $name  = pop_text($body['name']  ?? '', 200, 'Name');
    $notes = pop_text($body['notes'] ?? '', 2000, 'Notes');
    if ($name === '') {
        api_err('POP name is required');
    }

    try {
        $st = $pdo->prepare("
            INSERT INTO app.pop (name, notes, location)
            VALUES (:name, :notes,
                    ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326))
            RETURNING pop_id
        ");
        $st->execute([
            ':name'  => $name,
            ':notes' => $notes !== '' ? $notes : null,
            ':lon'   => $lon,
            ':lat'   => $lat,
        ]);
    } catch (PDOException $e) {
        pop_err_duplicate($e, $name);
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['pop_id' => (int)$st->fetchColumn(), 'name' => $name]);
}

function pop_update(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }

    $setParts = ['updated_at = now()'];
    $params   = [':id' => $id];
    $name     = null;

    // Props are only touched when the edit modal sends them; a drag-to-move sends
    // geojson alone and must not wipe the name/notes.
    if (array_key_exists('name', $body) || array_key_exists('notes', $body)) {
        $name  = pop_text($body['name']  ?? '', 200, 'Name');
        $notes = pop_text($body['notes'] ?? '', 2000, 'Notes');
        if ($name === '') {
            api_err('POP name is required');
        }
        $setParts[]        = 'name  = :name';
        $setParts[]        = 'notes = :notes';
        $params[':name']   = $name;
        $params[':notes']  = $notes !== '' ? $notes : null;
    }

    if (array_key_exists('geojson', $body)) {
        [$lon, $lat] = pop_geojson_point($body['geojson']);
        $setParts[]     = 'location = ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)';
        $params[':lon'] = $lon;
        $params[':lat'] = $lat;
    }

    if (count($setParts) === 1) {
        api_err('Nothing to update');
    }

    try {
        $st = $pdo->prepare('UPDATE app.pop SET ' . implode(', ', $setParts) . ' WHERE pop_id = :id');
        $st->execute($params);
    } catch (PDOException $e) {
        pop_err_duplicate($e, (string)$name);
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    if ($st->rowCount() === 0) {
        // rowCount is 0 both for a missing row and for a no-op write; only the
        // former is an error.
        $ex = $pdo->prepare('SELECT 1 FROM app.pop WHERE pop_id = :id');
        $ex->execute([':id' => $id]);
        if (!$ex->fetchColumn()) {
            api_err('POP not found', 404);
        }
    }

    api_ok(['pop_id' => $id]);
}

/** app.pop.name is UNIQUE — turn the constraint violation into a usable message. */
function pop_err_duplicate(PDOException $e, string $name): void
{
    if ($e->getCode() === '23505') {
        api_err('A POP named "' . $name . '" already exists', 409);
    }
}

function pop_geojson_point(mixed $geojson): array
{
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'Point') {
        api_err('geojson must be a GeoJSON Point');
    }
    $coords = $geojson['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        api_err('geojson coordinates are required');
    }
    $lon = (float)$coords[0];
    $lat = (float)$coords[1];
    if (!is_finite($lon) || !is_finite($lat)) {
        api_err('Invalid coordinates');
    }
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        api_err('Coordinates out of range');
    }
    return [$lon, $lat];
}

function pop_text(mixed $raw, int $maxLen, string $label): string
{
    $v = trim((string)$raw);
    if (mb_strlen($v) > $maxLen) {
        api_err($label . " must be {$maxLen} characters or less");
    }
    return $v;
}

/** Same rule as the POP list page: a POP that still has closures or devices is reassigned first, never orphaned. */
function pop_delete(PDO $pdo, int $id): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }
    $chk = $pdo->prepare("
        SELECT (SELECT count(*) FROM app.fosc        WHERE pop_id = :id) AS members,
               (SELECT count(*) FROM app.fosc_device WHERE pop_id = :id) AS devices,
               (SELECT count(*) FROM app.lnd_link    WHERE a_pop_id = :id OR b_pop_id = :id) AS links,
               EXISTS (SELECT 1 FROM app.pop WHERE pop_id = :id) AS found");
    $chk->execute([':id' => $id]);
    $c = $chk->fetch();
    if (!$c['found']) {
        api_err('POP not found', 404);
    }
    if ((int)$c['members'] > 0 || (int)$c['devices'] > 0 || (int)$c['links'] > 0) {
        api_err('Cannot delete: this POP still has ' . (int)$c['members'] . ' closure(s), ' . (int)$c['devices']
              . ' device(s) and ' . (int)$c['links'] . ' logical link(s). Reassign or remove them first.');
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM app.pop WHERE pop_id = :id')->execute([':id' => $id]);
    api_delete_timeline($pdo, 'pop', $id);
    $pdo->commit();
    api_ok(['deleted' => true]);
}
