<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/fosc.php        → create a new FOSC
// PUT  /api/fosc.php?id=X   → update an existing FOSC's location

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/closure.helper.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();
    fosc_create($pdo, $body);
} elseif ($method === 'PATCH') {
    api_require_csrf();
    $id   = (int)($_GET['id'] ?? 0);
    $body = api_json_body();
    fosc_patch($pdo, $id, $body);
} elseif ($method === 'PUT') {
    api_require_csrf();
    $id   = (int)($_GET['id'] ?? 0);
    $body = api_json_body();
    fosc_update($pdo, $id, $body);
} elseif ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    fosc_delete($pdo, $id);
} else {
    api_err('Method not allowed', 405);
}

// ── handlers ─────────────────────────────────────────────────────────────────

function fosc_patch(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) api_err('Invalid id');

    $status    = trim((string)($body['status']     ?? ''));
    $notes     = trim((string)($body['notes']      ?? ''));
    $fpmWor = trim((string)($body['fpm_wor'] ?? '')) ?: null;
    $spliceNum = strtoupper(trim((string)($body['splice_num'] ?? '')));

    $validStatuses = ['active', 'plan', 'under construction', 'retired'];
    if ($status !== '' && !in_array($status, $validStatuses, true)) {
        api_err('Invalid status value');
    }
    if ($spliceNum !== '' && !preg_match('/^([A-Z]{3}[0-9]{4}|[LR][0-9]{2}(-MST)?)$/', $spliceNum)) {
        api_err('splice_num must match ABC1234 or L/R01 format');
    }

    $sets   = ['notes = :notes', 'fpm_wor = :wor'];
    $params = [':id' => $id, ':notes' => $notes, ':wor' => $fpmWor];
    if ($status !== '') {
        $sets[]            = 'status = :status';
        $params[':status'] = $status;
    }
    if ($spliceNum !== '') {
        $sets[]              = 'splice_num = :splice_num';
        $params[':splice_num'] = $spliceNum;
    }

    // Fields below mirror the full closure edit form (pages/closure.php). Each is
    // only touched when the caller actually sends the key, so partial PATCHes
    // (e.g. the drag-to-move handler) leave them alone.
    if (array_key_exists('kind', $body)) {
        $kind = trim((string)$body['kind']);
        if (!in_array($kind, ['closure', 'mst', 'terminal', 'cabinet', 'optitap', 'slack'], true)) {
            api_err('Invalid kind value');
        }
        $sets[]          = 'kind = :kind::app.fosc_kind';
        $params[':kind'] = $kind;
    }
    if (array_key_exists('closure_type_id', $body)) {
        $typeId = (int)$body['closure_type_id'];
        if ($typeId <= 0) api_err('Invalid closure_type_id');
        $sets[]            = 'closure_type_id = :type_id';
        $params[':type_id'] = $typeId;
    }
    if (array_key_exists('enclosure_rating', $body)) {
        $rating = trim((string)$body['enclosure_rating']);
        if (!in_array($rating, ['inside', 'outside'], true)) {
            api_err('Invalid enclosure_rating value');
        }
        $sets[]            = 'enclosure_rating = :rating::app.enclosure_rating';
        $params[':rating'] = $rating;
    }
    if (array_key_exists('pop_id', $body)) {
        $popId = (int)$body['pop_id'];             // 0 / null = detach from POP
        $sets[]            = 'pop_id = :pop_id';
        $params[':pop_id'] = $popId > 0 ? $popId : null;
    }
    if (array_key_exists('slack_length_m', $body)) {
        // Cable consumed at a slack point (migration 0165). Empty/null restores the
        // standard 30 m coil rather than storing a zero.
        $raw = $body['slack_length_m'];
        if ($raw === null || trim((string)$raw) === '') {
            $sets[]            = 'slack_length_m = NULL';
        } else {
            if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 500) {
                api_err('Slack length must be between 0 and 500 m');
            }
            $sets[]            = 'slack_length_m = :slack_m';
            $params[':slack_m'] = round((float)$raw, 2);
        }
    }
    if (array_key_exists('street_address', $body)) {
        $addr = trim((string)$body['street_address']);
        $sets[]                 = "attrs = attrs || :attrs_patch::jsonb";
        $params[':attrs_patch'] = json_encode(['street_address' => $addr !== '' ? $addr : null]);
    }
    $sets[] = 'updated_at = now()';

    // The update and the work-order links change together or not at all.
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE app.fosc SET ' . implode(', ', $sets) . ' WHERE fosc_id = :id');
        $st->execute($params);
        if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('FOSC not found', 404); }
        fosc_sync_work_orders($pdo, $id, (string)($fpmWor ?? ''),
                              fosc_extra_work_orders($body['extra_work_orders'] ?? []));
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
            api_err('That splice number is already in use');
        }
        throw $e;
    }

    api_ok(['id' => $id]);
}

function fosc_create(PDO $pdo, array $body): never
{
    $spliceNum = strtoupper(trim((string)($body['splice_num'] ?? '')));
    $status    = (string)($body['status']           ?? 'plan');
    $kind      = (string)($body['kind']             ?? 'closure');
    $typeId    = (int)($body['closure_type_id']     ?? 0);
    $notes     = trim((string)($body['notes']       ?? ''));
    $fpmWor = trim((string)($body['fpm_wor']  ?? '')) ?: null;
    $geojson   = $body['geojson'] ?? null;

    if ($spliceNum === '') {
        api_err('splice_num is required');
    }
    if (!preg_match('/^([A-Z]{3}[0-9]{4}|[LR][0-9]{2})$/', $spliceNum)) {
        api_err('splice_num must be ABC1234 or L/R + 2 digits (e.g. ABC1234, L01, R03)');
    }
    if (!in_array($status, ['active', 'plan', 'under construction', 'retired'], true)) {
        api_err('Invalid status value');
    }
    if (!in_array($kind, ['closure', 'mst', 'terminal', 'cabinet', 'optitap', 'slack'], true)) {
        api_err('Invalid kind value');
    }
    if ($typeId <= 0) {
        api_err('closure_type_id is required');
    }
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'Point') {
        api_err('geojson must be a GeoJSON Point');
    }

    [$lon, $lat] = $geojson['coordinates'];

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("
            INSERT INTO app.fosc (splice_num, status, kind, closure_type_id, location, notes, fpm_wor)
            VALUES (:splice, :status, :kind::app.fosc_kind, :type,
                    ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326),
                    :notes, :wor)
            RETURNING fosc_id
        ");
        $st->execute([
            ':splice' => $spliceNum,
            ':status' => $status,
            ':kind'   => $kind,
            ':type'   => $typeId,
            ':lon'    => $lon,
            ':lat'    => $lat,
            ':notes'  => $notes,
            ':wor'    => $fpmWor,
        ]);
        $row = $st->fetch();
        fosc_sync_work_orders($pdo, (int)$row['fosc_id'], (string)($fpmWor ?? ''),
                              fosc_extra_work_orders($body['extra_work_orders'] ?? []));
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['fosc_id' => (int)$row['fosc_id']]);
}

function fosc_update(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }

    // Currently supports updating the location.
    // Extend here (status, notes, etc.) as needed.
    $geojson = $body['geojson'] ?? null;
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'Point') {
        api_err('geojson must be a GeoJSON Point');
    }

    [$lon, $lat] = $geojson['coordinates'];

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("
            UPDATE app.fosc
               SET location   = ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326),
                   updated_at = now()
             WHERE fosc_id = :id
        ");
        $st->execute([':lon' => $lon, ':lat' => $lat, ':id' => $id]);

        if ($st->rowCount() === 0) {
            $pdo->rollBack();
            api_err('FOSC not found', 404);
        }

        // Connected spans follow the closure. Every span keeps its whole route and only its end point moves to
        // the closure, so the cable stays on its strand or in its conduit. (Rebuilding from the stored
        // waypoints, as this once did, can run past the far end when they are stale: span 599 came back
        // 1069 m long instead of 406 m.) A span with no geometry yet is rebuilt by the endpoint trigger.
        $stSpans = $pdo->prepare("
            UPDATE app.cable_span s
               SET geom_snap  = CASE
                       WHEN s.geom_snap IS NOT NULL
                       THEN ST_SetPoint(s.geom_snap,
                                        CASE WHEN s.from_fosc_id = :id THEN 0 ELSE ST_NPoints(s.geom_snap) - 1 END,
                                        (SELECT location FROM app.fosc WHERE fosc_id = :id2))
                   END,
                   updated_at = now()
             WHERE s.from_fosc_id = :id3 OR s.to_fosc_id = :id4
        ");
        $stSpans->execute([':id' => $id, ':id2' => $id, ':id3' => $id, ':id4' => $id]);
        $pdo->commit();                     // the closure and its spans move together
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok();
}

// ── Multiple work orders per closure (mirrors pole_work_order) ────────────────

function fosc_extra_work_orders(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $wo) {
        $v = trim((string)$wo);
        if ($v !== '' && mb_strlen($v) <= 80) $out[] = $v;
    }
    return array_values(array_unique($out));
}

function fosc_sync_work_orders(PDO $pdo, int $foscId, string $primaryWO, array $extraWOs): void
{
    $pdo->prepare('DELETE FROM app.fosc_work_order WHERE fosc_id = :fid')
        ->execute([':fid' => $foscId]);
    if ($primaryWO !== '') {
        $pdo->prepare('INSERT INTO app.fosc_work_order (fosc_id, work_order, is_primary) VALUES (:fid, :wo, true)')
            ->execute([':fid' => $foscId, ':wo' => $primaryWO]);
    }
    $st = $pdo->prepare('INSERT INTO app.fosc_work_order (fosc_id, work_order, is_primary) VALUES (:fid, :wo, false) ON CONFLICT DO NOTHING');
    foreach ($extraWOs as $wo) {
        if ($wo !== $primaryWO) $st->execute([':fid' => $foscId, ':wo' => $wo]);
    }
}

function fosc_delete(PDO $pdo, int $id): never
{
    if ($id <= 0) api_err('Invalid id');

    $err = closure_delete_with_tail_span($pdo, $id);
    if ($err !== null) api_err($err, $err === 'Closure not found' ? 404 : 400);

    api_ok();
}
