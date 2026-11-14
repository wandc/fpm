<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/conduit.php        — create conduit run
// PUT    /api/conduit.php?id=N   — update conduit run (properties or geometry)
// DELETE /api/conduit.php?id=N   — delete conduit run

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

// ── CREATE ────────────────────────────────────────────────────────────────────

/**
 * Optional measured duct length. Unset/blank means "we never measured this one",
 * which is the common case for building entries — it must stay NULL rather than
 * collapsing to 0, because 0 would read as a real measurement of nothing.
 */
function conduit_known_length(array $body): array
{
    $raw = $body['known_length_m']     ?? null;
    $tol = $body['known_length_tol_m'] ?? null;

    $len = ($raw === null || $raw === '') ? null : (float)$raw;
    if ($len !== null && (!is_finite($len) || $len <= 0)) {
        api_err('known_length_m must be a positive number of metres', 400);
    }

    $tolV = ($tol === null || $tol === '') ? null : (float)$tol;
    if ($tolV !== null && (!is_finite($tolV) || $tolV < 0)) {
        api_err('known_length_tol_m must be zero or more', 400);
    }
    // A tolerance with nothing to be a tolerance of is meaningless; drop it.
    if ($len === null) $tolV = null;

    return [$len, $tolV];
}

/**
 * The work orders typed into the form: "A, B" (the first is the primary). Each has to be a real
 * permit. Returns the list; empty means the conduit has none.
 */
function conduit_work_orders(PDO $pdo, array $body): array
{
    $raw = $body['work_orders'] ?? ($body['fpm_wor'] ?? '');
    $list = is_array($raw) ? $raw : explode(',', (string)$raw);
    $out = [];
    foreach ($list as $w) {
        $w = trim((string)$w);
        if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
    }
    if (count($out) > 10) api_err('At most 10 work orders per conduit', 400);
    $chk = $pdo->prepare('SELECT 1 FROM app.fpm_permit WHERE permit_num = ?');
    foreach ($out as $w) {
        $chk->execute([$w]);
        if (!$chk->fetchColumn()) api_err("Unknown Tinuum permit '$w'", 400);
    }
    return $out;
}

/**
 * Make the link rows match the list. Called after the conduit row was saved with the first
 * work order as its primary (that save creates the primary link row); this removes the links
 * no longer listed and adds the extras. Run inside the caller's transaction.
 */
function conduit_sync_work_orders(PDO $pdo, int $id, array $wos): void
{
    if ($wos) {
        $ph = implode(',', array_fill(0, count($wos), '?'));
        $pdo->prepare("DELETE FROM app.conduit_work_order WHERE conduit_id = ? AND work_order NOT IN ($ph)")
            ->execute(array_merge([$id], $wos));
    } else {
        $pdo->prepare('DELETE FROM app.conduit_work_order WHERE conduit_id = ?')->execute([$id]);
    }
    $ins = $pdo->prepare('INSERT INTO app.conduit_work_order (conduit_id, work_order, is_primary) VALUES (?, ?, false)
                          ON CONFLICT (conduit_id, work_order) DO NOTHING');
    foreach (array_slice($wos, 1) as $w) $ins->execute([$id, $w]);
}

// POST ?action=split  {id, lng, lat}  cut a conduit in two at the point nearest the click
// POST ?action=join   {a, b}          join two conduits that meet end to end (the reverse of a split)
if ($method === 'POST' && in_array($_GET['action'] ?? '', ['split', 'join'], true)) {
    api_require_csrf();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $pdo->beginTransaction();
        if ($_GET['action'] === 'split') {
            $id = (int)($body['id'] ?? 0);
            if (!$id || !is_numeric($body['lng'] ?? null) || !is_numeric($body['lat'] ?? null)) api_err('id, lng and lat required', 400);
            $st = $pdo->prepare("SELECT app.split_conduit(:id, ST_ClosestPoint((SELECT geom FROM app.conduit WHERE conduit_id = :id),
                                         ST_SetSRID(ST_MakePoint(:lng::float8, :lat::float8), 4326)))");
            $st->execute([':id' => $id, ':lng' => (float)$body['lng'], ':lat' => (float)$body['lat']]);
            $new = $st->fetchColumn();
            if (!$new) { $pdo->rollBack(); api_err('Click further from the ends of the conduit to split it', 400); }
            $pdo->commit();
            api_ok(['conduit_id' => $id, 'new_conduit_id' => (int)$new]);
        }
        $st = $pdo->prepare('SELECT app.join_conduits(:a, :b)');
        $st->execute([':a' => (int)($body['a'] ?? 0), ':b' => (int)($body['b'] ?? 0)]);
        $pdo->commit();
        api_ok(['conduit_id' => (int)$st->fetchColumn()]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // the join function raises with a message written for the operator
        if ($e->getCode() === 'P0001' && preg_match('/ERROR:\s*(.+?)(\n|$)/', $e->getMessage(), $m)) api_err($m[1], 400);
        throw $e;
    }
}

if ($method === 'POST') {
    api_require_csrf();
    $body             = json_decode(file_get_contents('php://input'), true) ?? [];
    $name             = trim($body['name']              ?? '');
    $status           = trim($body['status']            ?? 'plan');
    $owner            = trim($body['owner']             ?? '');
    $microduct_type_id = isset($body['microduct_type_id']) ? ((int)$body['microduct_type_id'] ?: null) : null;
    $notes            = trim($body['notes']             ?? '');
    $woList           = conduit_work_orders($pdo, $body);
    $fpmWor        = $woList[0] ?? null;      // the primary; NULL when the conduit has none
    $bellPermitNum    = substr(trim($body['bell_permit_num'] ?? ''), 0, 200);
    $geojson          = $body['geojson']                 ?? null;
    [$knownLenM, $knownTolM] = conduit_known_length($body);

    $validStatuses = ['plan', 'active', 'retired'];
    $validOwners   = ['', 'bell', 'nbpower', 'rogers', 'private'];
    $isSpare       = !empty($body['is_spare']);
    $installation  = isset($body['installation']) ? (string)$body['installation'] : null;      // null = leave as it is (update) or buried (create)
    if ($installation !== null && !in_array($installation, ['buried', 'aerial'], true)) api_err('installation must be buried or aerial', 400);
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if (!in_array($owner, $validOwners))    api_err('Invalid owner', 400);
    if (!$geojson || ($geojson['type'] ?? '') !== 'LineString') api_err('geojson LineString required', 400);

    if ($microduct_type_id !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM app.microduct_type WHERE id = :id");
        $chk->execute([':id' => $microduct_type_id]);
        if (!$chk->fetchColumn()) api_err('microduct_type_id not found', 400);
    }

    $pdo->beginTransaction();               // the conduit and its work-order links are saved together
    $st = $pdo->prepare("
        INSERT INTO app.conduit (name, status, owner, microduct_type_id, notes, fpm_wor, bell_permit_num, is_spare, known_length_m, known_length_tol_m, installation, geom)
        VALUES (:name, :status::app.conduit_status, :owner, :mtid, :notes, :fpm_wor, :bell_permit_num, :is_spare, :known_len, :known_tol, COALESCE(:installation, 'buried'),
                ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326))
        RETURNING conduit_id
    ");
    $st->execute([
        ':name'           => $name,
        ':status'         => $status,
        ':owner'          => $owner !== '' ? $owner : null,
        ':mtid'           => $microduct_type_id,
        ':notes'          => $notes,
        ':fpm_wor'     => $fpmWor,
        ':bell_permit_num'=> $bellPermitNum,
        ':is_spare'       => $isSpare ? 'true' : 'false',
        ':known_len'      => $knownLenM,
        ':known_tol'      => $knownTolM,
        ':installation'   => $installation,
        ':geojson'        => json_encode($geojson),
    ]);
    $id = (int)$st->fetchColumn();
    conduit_sync_work_orders($pdo, $id, $woList);
    $pdo->commit();
    api_ok(['conduit_id' => $id]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $body             = json_decode(file_get_contents('php://input'), true) ?? [];

    // Geometry-only save (map vertex drag): a body carrying geojson but no `name`
    // comes from the map's route editor, which knows nothing about the property
    // fields — so touch geom alone and leave the rest of the row as it is.
    if (isset($body['geojson']) && !array_key_exists('name', $body)) {
        $geojson = $body['geojson'];
        if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'LineString') {
            api_err('geojson must be a GeoJSON LineString', 400);
        }
        if (count($geojson['coordinates'] ?? []) < 2) {
            api_err('LineString must have at least 2 points', 400);
        }
        $st = $pdo->prepare("
            UPDATE app.conduit
               SET geom = ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)
             WHERE conduit_id = :id
        ");
        $st->execute([':geojson' => json_encode($geojson), ':id' => $id]);
        if ($st->rowCount() === 0) api_err('Conduit not found', 404);
        api_ok(['conduit_id' => $id]);
    }

    $name             = trim($body['name']              ?? '');
    $status           = trim($body['status']            ?? 'plan');
    $owner            = trim($body['owner']             ?? '');
    $microduct_type_id = isset($body['microduct_type_id']) ? ((int)$body['microduct_type_id'] ?: null) : null;
    $notes            = trim($body['notes']             ?? '');
    $woList           = conduit_work_orders($pdo, $body);
    $fpmWor        = $woList[0] ?? null;      // the primary; NULL when the conduit has none
    $bellPermitNum    = substr(trim($body['bell_permit_num'] ?? ''), 0, 200);
    $geojson          = $body['geojson']                 ?? null;
    [$knownLenM, $knownTolM] = conduit_known_length($body);

    $validStatuses = ['plan', 'active', 'retired'];
    $validOwners   = ['', 'bell', 'nbpower', 'rogers', 'private'];
    $isSpare       = !empty($body['is_spare']);
    $installation  = isset($body['installation']) ? (string)$body['installation'] : null;      // null = leave as it is (update) or buried (create)
    if ($installation !== null && !in_array($installation, ['buried', 'aerial'], true)) api_err('installation must be buried or aerial', 400);
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if (!in_array($owner, $validOwners))    api_err('Invalid owner', 400);

    if ($microduct_type_id !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM app.microduct_type WHERE id = :id");
        $chk->execute([':id' => $microduct_type_id]);
        if (!$chk->fetchColumn()) api_err('microduct_type_id not found', 400);
    }

    if ($geojson && ($geojson['type'] ?? '') !== 'LineString') api_err('geojson must be LineString', 400);
    $pdo->beginTransaction();               // the conduit and its work-order links are saved together
    if ($geojson) {
        $st = $pdo->prepare("
            UPDATE app.conduit
               SET name              = :name,
                   status            = :status::app.conduit_status,
                   owner             = :owner,
                   microduct_type_id = :mtid,
                   notes             = :notes,
                   fpm_wor        = :fpm_wor,
                   bell_permit_num   = :bell_permit_num,
                   is_spare          = :is_spare,
                   known_length_m     = :known_len,
                   known_length_tol_m = :known_tol,
                   installation      = COALESCE(:installation, installation),
                   geom              = ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)
             WHERE conduit_id = :id
        ");
        $st->execute([
            ':name'           => $name,
            ':status'         => $status,
            ':owner'          => $owner !== '' ? $owner : null,
            ':mtid'           => $microduct_type_id,
            ':notes'          => $notes,
            ':fpm_wor'     => $fpmWor,
            ':bell_permit_num'=> $bellPermitNum,
            ':is_spare'       => $isSpare ? 'true' : 'false',
            ':known_len'      => $knownLenM,
            ':known_tol'      => $knownTolM,
        ':installation'   => $installation,
        ':known_len'      => $knownLenM,
        ':known_tol'      => $knownTolM,
        ':installation'   => $installation,
            ':geojson'        => json_encode($geojson),
            ':id'             => $id,
        ]);
    } else {
        $st = $pdo->prepare("
            UPDATE app.conduit
               SET name              = :name,
                   status            = :status::app.conduit_status,
                   owner             = :owner,
                   microduct_type_id = :mtid,
                   notes             = :notes,
                   fpm_wor        = :fpm_wor,
                   bell_permit_num   = :bell_permit_num,
                   is_spare          = :is_spare,
                   known_length_m     = :known_len,
                   known_length_tol_m = :known_tol,
                   installation      = COALESCE(:installation, installation)
             WHERE conduit_id = :id
        ");
        $st->execute([
            ':name'           => $name,
            ':status'         => $status,
            ':owner'          => $owner !== '' ? $owner : null,
            ':mtid'           => $microduct_type_id,
            ':notes'          => $notes,
            ':fpm_wor'     => $fpmWor,
            ':bell_permit_num'=> $bellPermitNum,
            ':is_spare'       => $isSpare ? 'true' : 'false',
            ':known_len'      => $knownLenM,
            ':known_tol'      => $knownTolM,
        ':installation'   => $installation,
        ':known_len'      => $knownLenM,
        ':known_tol'      => $knownTolM,
        ':installation'   => $installation,
            ':id'             => $id,
        ]);
    }
    if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Conduit not found', 404); }
    conduit_sync_work_orders($pdo, $id, $woList);
    $pdo->commit();
    api_ok(['conduit_id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $pdo->beginTransaction();
    $st = $pdo->prepare("DELETE FROM app.conduit WHERE conduit_id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Conduit not found', 404); }
    api_delete_timeline($pdo, 'conduit', $id);
    $pdo->commit();
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
