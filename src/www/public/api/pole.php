<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/pole.php       → create a new pole
// PUT  /api/pole.php?id=X  → update an existing pole

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/wo_inherit.helper.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'GET') {
    $lat    = isset($_GET['lat'])    ? (float)$_GET['lat']    : null;
    $lon    = isset($_GET['lon'])    ? (float)$_GET['lon']    : null;
    $radius = isset($_GET['radius']) ? (int)$_GET['radius']   : 500;
    if ($lat === null || $lon === null) api_err('lat and lon are required', 400);
    $st = $pdo->prepare("
        SELECT p.fid,
               ST_Y(p.geom) AS lat,
               ST_X(p.geom) AS lon,
               ST_Distance(
                   ST_SetSRID(ST_MakePoint(CAST(:lon AS float8), CAST(:lat AS float8)), 4326)::geography,
                   p.geom::geography
               ) AS dist_m
          FROM app.pole p
         WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
           AND ST_DWithin(
               ST_SetSRID(ST_MakePoint(CAST(:lon2 AS float8), CAST(:lat2 AS float8)), 4326)::geography,
               p.geom::geography,
               :radius
           )
         ORDER BY dist_m
    ");
    $st->execute([':lat' => $lat, ':lon' => $lon, ':lat2' => $lat, ':lon2' => $lon, ':radius' => $radius]);
    api_ok(['poles' => $st->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();
    pole_create($pdo, $body);
} elseif ($method === 'PUT') {
    api_require_csrf();
    $id   = (int)($_GET['id'] ?? 0);
    $body = api_json_body();
    pole_update($pdo, $id, $body);
} elseif ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    pole_delete($pdo, $id);
} else {
    api_err('Method not allowed', 405);
}

function pole_create(PDO $pdo, array $body): never
{
    [$lon, $lat] = pole_geojson_point($body['geojson'] ?? null);

    $uid       = pole_text(($body['uid'] ?? ''), 10, 'UID');
    $type      = pole_text(($body['type'] ?? 'wood'), 80, 'Type');
    $owner     = pole_owner_check($pdo, pole_text(($body['owner'] ?? ''), 15, 'Owner'));
    $nbpNum    = pole_text(($body['nbp_num'] ?? ''), 18, 'NBP#');
    $telcoNum  = pole_text(($body['telco_num'] ?? ''), 18, 'Telco#');
    $tagsNum   = pole_text(($body['tags_num'] ?? ''), 30, 'Tags#');
    $comment   = pole_text(($body['comment'] ?? ''), 254, 'Notes');
    $workOrder  = pole_text(($body['work_order'] ?? ''), 80, 'Work Order#');
    $extraWOs   = pole_extra_work_orders($body['extra_work_orders'] ?? []);

    if ($type === '') {
        $type = 'wood';
    }

    try {
        $pdo->beginTransaction();
        $fid = (int)$pdo->query("SELECT nextval('app.pole_fid_seq')")->fetchColumn();

        $st = $pdo->prepare("
            INSERT INTO app.pole (fid, geom, uid, type, owner, comment, \"nbp#\", \"telco#\", \"tags#\", fpm_wor)
            VALUES (
                :fid,
                ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326),
                :uid,
                :type,
                :owner,
                :comment,
                :nbp_num,
                :telco_num,
                :tags_num,
                :work_order
            )
        ");
        $st->execute([
            ':fid'        => $fid,
            ':lon'        => $lon,
            ':lat'        => $lat,
            ':uid'        => $uid !== '' ? $uid : null,
            ':type'       => $type !== '' ? $type : null,
            ':owner'      => $owner !== '' ? $owner : null,
            ':comment'    => $comment !== '' ? $comment : null,
            ':nbp_num'    => $nbpNum !== '' ? $nbpNum : null,
            ':telco_num'  => $telcoNum !== '' ? $telcoNum : null,
            ':tags_num'   => $tagsNum !== '' ? $tagsNum : null,
            ':work_order' => $workOrder !== '' ? $workOrder : null,
        ]);
        pole_sync_work_orders($pdo, $fid, $workOrder, $extraWOs);
        // Closures / slack loops / FlexTIN taps mounted here, and the strands this
        // pole anchors, inherit the permit when they have none of their own.
        $inherited = $workOrder !== '' ? wo_inherit_from_pole($pdo, $fid, $workOrder) : [];
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['fid' => $fid, 'inherited' => $inherited,
            'inherited_summary' => wo_inherit_summary($inherited)]);
}

function pole_update(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }

    $geojson    = $body['geojson'] ?? null;
    $hasProps   = array_key_exists('uid', $body) || array_key_exists('type', $body);

    $setParts = [];
    $params   = [':id' => $id];

    // Only update text fields when the caller sends property keys (edit modal).
    // A geometry-only move request (just 'geojson') must not wipe existing values.
    $workOrder = '';
    $extraWOs  = [];
    if ($hasProps) {
        $uid       = pole_text(($body['uid']        ?? ''), 10,  'UID');
        $type      = pole_text(($body['type']       ?? 'wood'), 80, 'Type');
        $owner     = pole_owner_check($pdo, pole_text(($body['owner']      ?? ''), 15,  'Owner'));
        $nbpNum    = pole_text(($body['nbp_num']    ?? ''), 18,  'NBP#');
        $telcoNum  = pole_text(($body['telco_num']  ?? ''), 18,  'Telco#');
        $tagsNum   = pole_text(($body['tags_num']   ?? ''), 30,  'Tags#');
        $comment   = pole_text(($body['comment']    ?? ''), 254, 'Notes');
        $workOrder = pole_text(($body['work_order'] ?? ''), 80,  'Work Order#');
        $extraWOs  = pole_extra_work_orders($body['extra_work_orders'] ?? []);
        if ($type === '') $type = 'wood';

        $setParts[]             = 'uid        = :uid';
        $setParts[]             = 'type       = :type';
        $setParts[]             = 'owner      = :owner';
        $setParts[]             = 'comment    = :comment';
        $setParts[]             = '"nbp#"     = :nbp_num';
        $setParts[]             = '"telco#"   = :telco_num';
        $setParts[]             = '"tags#"    = :tags_num';
        $setParts[]             = 'fpm_wor = :work_order';
        $params[':uid']         = $uid       !== '' ? $uid       : null;
        $params[':type']        = $type      !== '' ? $type      : null;
        $params[':owner']       = $owner     !== '' ? $owner     : null;
        $params[':comment']     = $comment   !== '' ? $comment   : null;
        $params[':nbp_num']     = $nbpNum    !== '' ? $nbpNum    : null;
        $params[':telco_num']   = $telcoNum  !== '' ? $telcoNum  : null;
        $params[':tags_num']    = $tagsNum   !== '' ? $tagsNum   : null;
        $params[':work_order']  = $workOrder !== '' ? $workOrder : null;
    }

    if (is_array($geojson)) {
        [$lon, $lat] = pole_geojson_point($geojson);
        $setParts[]      = 'geom = ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)';
        $params[':lon']  = $lon;
        $params[':lat']  = $lat;
    }

    if (empty($setParts)) {
        api_err('Nothing to update');
    }

    $sql = 'UPDATE app.pole SET ' . implode(', ', $setParts) . ' WHERE fid = :id';

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->rowCount() === 0) {
            $stExists = $pdo->prepare('SELECT 1 FROM app.pole WHERE fid = :id');
            $stExists->execute([':id' => $id]);
            if (!$stExists->fetchColumn()) {
                $pdo->rollBack();
                api_err('Pole not found', 404);
            }
        }
        $inherited = [];
        if ($hasProps) {
            pole_sync_work_orders($pdo, $id, $workOrder, $extraWOs);
            if ($workOrder !== '') $inherited = wo_inherit_from_pole($pdo, $id, $workOrder);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['inherited' => $inherited, 'inherited_summary' => wo_inherit_summary($inherited)]);
}

function pole_delete(PDO $pdo, int $id): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }
    try {
        $st = $pdo->prepare('DELETE FROM app.pole WHERE fid = :id');
        $st->execute([':id' => $id]);
        if ($st->rowCount() === 0) {
            api_err('Pole not found', 404);
        }
    } catch (PDOException $e) {
        api_err('Database error: ' . $e->getMessage(), 500);
    }
    api_ok();
}

function pole_geojson_point(mixed $geojson): array
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

// Owners are a fixed list (app.pole_owner). Empty means Unknown; anything else must be on the list.
function pole_owner_check(PDO $pdo, string $owner): string
{
    if ($owner === '') return '';
    $st = $pdo->prepare('SELECT owner FROM app.pole_owner WHERE lower(owner) = lower(:o)');
    $st->execute([':o' => $owner]);
    $ok = $st->fetchColumn();
    if ($ok === false) {
        $all = implode(', ', $pdo->query('SELECT owner FROM app.pole_owner ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN));
        api_err("Owner must be one of: $all", 400);
    }
    return (string)$ok;
}

function pole_text(mixed $raw, int $maxLen, string $label): string
{
    $v = trim((string)$raw);
    if (mb_strlen($v) > $maxLen) {
        api_err($label . " must be {$maxLen} characters or less");
    }
    return $v;
}

function pole_extra_work_orders(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $wo) {
        $v = trim((string)$wo);
        if ($v !== '' && mb_strlen($v) <= 80) $out[] = $v;
    }
    return array_values(array_unique($out));
}

function pole_sync_work_orders(PDO $pdo, int $poleFid, string $primaryWO, array $extraWOs): void
{
    $pdo->prepare('DELETE FROM app.pole_work_order WHERE pole_fid = :fid')
        ->execute([':fid' => $poleFid]);
    if ($primaryWO !== '') {
        $pdo->prepare('INSERT INTO app.pole_work_order (pole_fid, work_order, is_primary) VALUES (:fid, :wo, true)')
            ->execute([':fid' => $poleFid, ':wo' => $primaryWO]);
    }
    $st = $pdo->prepare('INSERT INTO app.pole_work_order (pole_fid, work_order, is_primary) VALUES (:fid, :wo, false) ON CONFLICT DO NOTHING');
    foreach ($extraWOs as $wo) {
        if ($wo !== $primaryWO) {
            $st->execute([':fid' => $poleFid, ':wo' => $wo]);
        }
    }
}
