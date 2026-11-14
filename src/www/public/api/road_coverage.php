<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/road_coverage.php
// GET              — GeoJSON FeatureCollection; each feature has `seg_id` + `services` array
// POST { seg_id, provider, technology, notes? } — add a service to a segment
// DELETE ?id=<service_id>                       — remove one service entry
// POST { action: 'remove', seg_ids[], provider, technology } — remove one service from many segments

require_once __DIR__ . '/_common.php';

$method = api_method();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    header('Cache-Control: max-age=15, private');

    $all   = isset($_GET['all']) && $_GET['all'] === '1';
    $where = $all ? '' : 'WHERE rc.in_boundary = true';
    $roads = $pdo->query("
        SELECT rc.seg_id, rc.osm_way_id, rc.road_name, rc.highway_type,
               rc.in_boundary, ST_AsGeoJSON(rc.geom) AS geojson
          FROM app.road_coverage rc
          {$where}
         ORDER BY rc.seg_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $svcRows = $pdo->query("
        SELECT id, seg_id, provider, technology, notes
          FROM app.road_coverage_service
         ORDER BY seg_id, provider, technology
    ")->fetchAll(PDO::FETCH_ASSOC);

    $servicesBySeg = [];
    foreach ($svcRows as $s) {
        $servicesBySeg[(int)$s['seg_id']][] = [
            'id'         => (int)$s['id'],
            'provider'   => $s['provider'],
            'technology' => $s['technology'],
            'notes'      => $s['notes'],
        ];
    }

    $features = [];
    foreach ($roads as $r) {
        $segId = (int)$r['seg_id'];
        $features[] = [
            'type'     => 'Feature',
            'geometry' => json_decode($r['geojson']),
            'properties' => [
                'seg_id'       => $segId,
                'osm_way_id'   => (int)$r['osm_way_id'],
                'road_name'    => $r['road_name'],
                'highway_type' => $r['highway_type'],
                'in_boundary'  => (bool)$r['in_boundary'],
                'services'     => $servicesBySeg[$segId] ?? [],
            ],
        ];
    }

    echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);
    exit;
}

// ── POST {action: 'remove', seg_ids, provider, technology} — bulk remove ─────
// Removes one service (e.g. Tinuum Expansion Target) from many segments at once,
// by what it is rather than by row id: rows added by a bulk add that hit an
// existing entry never got their id back to the client.
if ($method === 'POST' && (($body = api_json_body())['action'] ?? '') === 'remove') {
    api_require_csrf();
    $segIds = array_values(array_filter(array_map('intval', (array)($body['seg_ids'] ?? [])), fn($i) => $i > 0));
    if (!$segIds) api_err('No segments', 400);
    $st = $pdo->prepare("DELETE FROM app.road_coverage_service
                         WHERE seg_id = ANY(?::int[]) AND provider = ? AND technology = ?");
    $st->execute(['{' . implode(',', $segIds) . '}', (string)($body['provider'] ?? ''), (string)($body['technology'] ?? '')]);
    api_ok(['deleted' => $st->rowCount()]);
}

// ── POST — add a service entry ────────────────────────────────────────────────
if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();

    $segId    = isset($body['seg_id'])      ? (int)$body['seg_id']               : 0;
    $provider = isset($body['provider'])    ? trim((string)$body['provider'])     : '';
    $tech     = isset($body['technology'])  ? trim((string)$body['technology'])   : '';
    $notes    = isset($body['notes'])       ? trim((string)$body['notes'])        : null;

    $providers = [org('own_name'), 'Rogers', 'Bell Aliant', 'None'];
    $techs     = ['fiber_ftth', 'hybrid_coax', 'fiber_mainline', 'copper_dsl', 'no_telecom', 'fiber_coming_soon', 'fiber_expansion'];

    if ($segId <= 0)                             api_err('Invalid seg_id', 400);
    if (!in_array($provider, $providers, true))  api_err('Invalid provider', 400);
    if (!in_array($tech, $techs, true))          api_err('Invalid technology', 400);

    $st = $pdo->prepare("
        INSERT INTO app.road_coverage_service (seg_id, provider, technology, notes)
        VALUES (:sid, :prov, :tech, :notes)
        ON CONFLICT (seg_id, provider, technology) DO NOTHING
        RETURNING id
    ");
    $st->execute([':sid' => $segId, ':prov' => $provider, ':tech' => $tech, ':notes' => $notes ?: null]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    // Tinuum FTTH supersedes Tinuum Expansion Target (trigger, migration 0244):
    // tell the map when an expansion was refused, so it doesn't show it ticked.
    $skipped = !$row && $provider === org('own_name') && $tech === 'fiber_expansion'
        && $pdo->query("SELECT 1 FROM app.road_coverage_service WHERE seg_id = " . (int)$segId
                       . " AND provider = app.own_name() AND technology = 'fiber_ftth'")->fetchColumn();
    api_ok(['id' => $row ? (int)$row['id'] : null] + ($skipped ? ['skipped' => 'Already ' . org('own_name') . ' FTTH'] : []));
    exit;
}

// ── DELETE — remove a service entry ──────────────────────────────────────────
if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) api_err('Invalid id', 400);
    $pdo->prepare("DELETE FROM app.road_coverage_service WHERE id = :id")->execute([':id' => $id]);
    api_ok(['deleted' => true]);
    exit;
}

api_err('Method not allowed', 405);
