<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/map_plan.php — CRUD for map plans + geometry auto-frame.
//
//   GET  ?id=N                                  → { plan, titleblock }
//   GET  ?entity_type=&entity_id=               → { plans:[...] }
//   GET  ?autoframe=1&entity_type=&entity_id=   → { frame:{lat,lon,zoom} | null }
//   POST {id?, entity_type, entity_id, title, center_lat, center_lon, zoom,
//         orientation, layers:[], meta:{}}      → create/update
//   DELETE ?id=N

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/map_plan.helper.php';

/** @var PDO $pdo */
/** @var array $_apiIdentity */

$actor  = (string)($_apiIdentity['username'] ?? $_apiIdentity['display_name'] ?? 'unknown');
$method = api_method();

$ENTITY_TYPES = [
    'bell_permit', 'fpm_permit', 'adhoc',
];

/** Decode a map_plan row's jsonb columns for output. */
function mp_row_out(array $r): array
{
    $r['plan_id']    = (int)$r['plan_id'];
    $r['zoom']       = (int)$r['zoom'];
    $r['sort_order'] = (int)$r['sort_order'];
    $r['center_lat'] = (float)$r['center_lat'];
    $r['center_lon'] = (float)$r['center_lon'];
    $r['layers']     = json_decode((string)$r['layers'], true) ?: [];
    $r['meta']       = json_decode((string)$r['meta'], true) ?: [];
    unset($r['rendered_png']); // never ship the blob in JSON
    return $r;
}

$PLAN_COLS = "plan_id, entity_type, entity_id, title, center_lat, center_lon, zoom,
              orientation, layers, meta, sort_order, rendered_at, created_by, created_at,
              updated_by, updated_at";

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if (($_GET['autoframe'] ?? '') === '1') {
        $type = (string)($_GET['entity_type'] ?? '');
        $eid  = (string)($_GET['entity_id'] ?? '');
        if (!in_array($type, ['fpm_permit', 'bell_permit'], true) || $eid === '') {
            api_err('autoframe requires entity_type (fpm_permit|bell_permit) and entity_id', 400);
        }
        api_ok(['frame' => map_plan_autoframe($pdo, $type, $eid)]);
    }

    if ($id > 0) {
        $st = $pdo->prepare("SELECT $PLAN_COLS FROM app.map_plan WHERE plan_id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_err('Plan not found', 404);
        $out = mp_row_out($row);
        api_ok([
            'plan'       => $out,
            'titleblock' => map_plan_titleblock($pdo, $out['entity_type'], $out['entity_id']),
        ]);
    }

    $type = (string)($_GET['entity_type'] ?? '');
    $eid  = (string)($_GET['entity_id'] ?? '');
    if ($type === '' || $eid === '') api_err('entity_type and entity_id required', 400);
    $st = $pdo->prepare("SELECT $PLAN_COLS FROM app.map_plan
                         WHERE entity_type = :t AND entity_id = :e
                         ORDER BY sort_order, plan_id");
    $st->execute([':t' => $type, ':e' => $eid]);
    api_ok(['plans' => array_map('mp_row_out', $st->fetchAll(PDO::FETCH_ASSOC))]);
}

if ($method === 'POST') {
    api_require_csrf();
    $b = api_json_body();

    $type = (string)($b['entity_type'] ?? '');
    $eid  = (string)($b['entity_id'] ?? '');
    if (!in_array($type, $ENTITY_TYPES, true)) api_err('Invalid entity_type', 400);
    if ($eid === '') api_err('entity_id required', 400);

    $title = trim((string)($b['title'] ?? '')) ?: 'Site Plan';
    $lat   = (float)($b['center_lat'] ?? 0);
    $lon   = (float)($b['center_lon'] ?? 0);
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0 && $lon == 0)) {
        api_err('Valid center_lat / center_lon required', 400);
    }
    $zoom   = max(1, min(23, (int)($b['zoom'] ?? 16)));
    $orient = ($b['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
    $layers = map_plan_clean_layers(is_array($b['layers'] ?? null) ? $b['layers'] : []);
    $meta   = is_array($b['meta'] ?? null) ? $b['meta'] : [];
    $id     = (int)($b['id'] ?? 0);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE app.map_plan SET
                title = :title, center_lat = :lat, center_lon = :lon, zoom = :zoom,
                orientation = :orient, layers = CAST(:layers AS jsonb), meta = CAST(:meta AS jsonb),
                updated_by = :actor, updated_at = now()
            WHERE plan_id = :id");
        $st->execute([
            ':title' => $title, ':lat' => $lat, ':lon' => $lon, ':zoom' => $zoom,
            ':orient' => $orient, ':layers' => json_encode($layers), ':meta' => json_encode($meta),
            ':actor' => $actor, ':id' => $id,
        ]);
        api_ok(['id' => $id]);
    }

    // sort_order = append to end for this entity
    $st = $pdo->prepare("
        INSERT INTO app.map_plan
            (entity_type, entity_id, title, center_lat, center_lon, zoom, orientation,
             layers, meta, sort_order, created_by, updated_by)
        VALUES
            (:t, :e, :title, :lat, :lon, :zoom, :orient,
             CAST(:layers AS jsonb), CAST(:meta AS jsonb),
             COALESCE((SELECT MAX(sort_order)+1 FROM app.map_plan WHERE entity_type=:t2 AND entity_id=:e2), 0),
             :actor, :actor2)
        RETURNING plan_id");
    $st->execute([
        ':t' => $type, ':e' => $eid, ':title' => $title, ':lat' => $lat, ':lon' => $lon,
        ':zoom' => $zoom, ':orient' => $orient, ':layers' => json_encode($layers),
        ':meta' => json_encode($meta), ':t2' => $type, ':e2' => $eid,
        ':actor' => $actor, ':actor2' => $actor,
    ]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) api_err('id required', 400);
    $pdo->prepare("DELETE FROM app.map_plan WHERE plan_id = :id")->execute([':id' => $id]);
    api_ok(['id' => $id]);
}

api_err('Method not allowed', 405);
