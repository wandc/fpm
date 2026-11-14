<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/span.php        → create a new cable span
// PUT  /api/span.php?id=X   → update an existing span's route geometry

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/wo_inherit.helper.php';

/** @var PDO $pdo */

$method = api_method();

if ($method === 'POST') {
    api_require_csrf();
    $body = api_json_body();
    span_create($pdo, $body);
} elseif ($method === 'PATCH') {
    api_require_csrf();
    $id   = (int)($_GET['id'] ?? 0);
    $body = api_json_body();
    span_patch($pdo, $id, $body);
} elseif ($method === 'PUT') {
    api_require_csrf();
    $id   = (int)($_GET['id'] ?? 0);
    $body = api_json_body();
    span_update($pdo, $id, $body);
} elseif ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    span_delete($pdo, $id);
} else {
    api_err('Method not allowed', 405);
}

// ── handlers ─────────────────────────────────────────────────────────────────

function span_patch(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) api_err('Invalid id');

    $notes     = trim((string)($body['notes']      ?? ''));
    $fpmWor = trim((string)($body['fpm_wor'] ?? '')) ?: null;
    // Secondary permits (migration 0151). Absent key = leave the join table alone,
    // so callers that only patch notes/geometry don't wipe a span's extra permits.
    $hasExtras = array_key_exists('extra_work_orders', $body);
    $extraWOs  = span_extra_work_orders($body['extra_work_orders'] ?? []);
    // Deliberately-dark cable (migration 0164). Absent key leaves the flag alone so
    // callers that only patch notes or geometry can't clear it by omission.
    $hasSpare  = array_key_exists('is_spare', $body);
    $isSpare   = !empty($body['is_spare']);
    // Cable physically installed (migration 0270). Absent key leaves it alone, like is_spare.
    $hasInst   = array_key_exists('installed', $body);
    $isInst    = !empty($body['installed']);
    // Private client link (migration 0295). Absent key leaves it alone.
    $hasPriv   = array_key_exists('is_private', $body);
    $isPriv    = !empty($body['is_private']);
    // Serial / cable ID printed on the sheath (migration 0310). Absent key leaves it alone; blank clears it.
    $hasSerial = array_key_exists('cable_serial', $body);
    $serial    = trim((string)($body['cable_serial'] ?? '')) ?: null;

    // Optional cable-level edits (cable type + fibre count). These live on the parent
    // app.cable, so they apply to the whole cable (every span of it). Only touched when
    // the client sends the key, keeping other API callers backward-compatible.
    $editCable   = array_key_exists('material_id', $body) || array_key_exists('fibre_count', $body);
    $materialId  = array_key_exists('material_id', $body) && $body['material_id'] !== null
                     ? (int)$body['material_id'] : null;
    $fibreCount  = array_key_exists('fibre_count', $body) && $body['fibre_count'] !== null
                     ? (int)$body['fibre_count'] : null;
    if ($fibreCount !== null && $fibreCount < 1) api_err('fibre_count must be a positive integer');

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('UPDATE app.cable_span
                                SET notes      = :notes,
                                    fpm_wor = :wor,
                                    is_spare   = CASE WHEN :has_spare THEN :is_spare ELSE is_spare END,
                                    installed  = CASE WHEN :has_inst THEN :is_inst ELSE installed END,
                                    is_private = CASE WHEN :has_priv THEN :is_priv ELSE is_private END,
                                    cable_serial = CASE WHEN :has_serial THEN :serial::text ELSE cable_serial END
                              WHERE cable_span_id = :id
                          RETURNING to_fosc_id, cable_id');
        $st->execute([
            ':id'        => $id,
            ':notes'     => $notes,
            ':wor'       => $fpmWor,
            ':has_spare' => $hasSpare ? 'true' : 'false',
            ':is_spare'  => $isSpare  ? 'true' : 'false',
            ':has_inst'  => $hasInst  ? 'true' : 'false',
            ':is_inst'   => $isInst   ? 'true' : 'false',
            ':has_priv'  => $hasPriv  ? 'true' : 'false',
            ':is_priv'   => $isPriv   ? 'true' : 'false',
            ':has_serial' => $hasSerial ? 'true' : 'false',
            ':serial'    => $serial,
        ]);
        if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Span not found', 404); }
        $spanRow  = $st->fetch(PDO::FETCH_ASSOC);
        $toFoscId = (int)$spanRow['to_fosc_id'];
        $cableId  = (int)$spanRow['cable_id'];

        span_sync_work_orders($pdo, $id, (string)($fpmWor ?? ''), $hasExtras ? $extraWOs : null);

        // Pull the untagged things on this route onto the same permit (closures,
        // FlexTIN tail, the strand underneath). Blanks only — never reassigns.
        $inherited = $fpmWor !== null && $fpmWor !== ''
            ? wo_inherit_from_span($pdo, $id, $fpmWor)
            : [];

        if ($editCable) {
            // Guard: reducing fibre_count below a fibre already spliced anywhere on
            // this cable would orphan those splices — block it with a clear message.
            if ($fibreCount !== null) {
                $stMax = $pdo->prepare("
                    SELECT max(hi) FROM (
                        SELECT upper(sj.a_range) - 1 AS hi
                          FROM app.splice_join sj
                          JOIN app.cable_span cs ON cs.cable_span_id = sj.a_span_id
                         WHERE sj.a_target = 'cable_span' AND sj.retired_at IS NULL
                           AND cs.cable_id = :cable AND sj.a_range IS NOT NULL
                        UNION ALL
                        SELECT upper(sj.b_range) - 1
                          FROM app.splice_join sj
                          JOIN app.cable_span cs ON cs.cable_span_id = sj.b_span_id
                         WHERE sj.b_target = 'cable_span' AND sj.retired_at IS NULL
                           AND cs.cable_id = :cable AND sj.b_range IS NOT NULL
                    ) q
                ");
                $stMax->execute([':cable' => $cableId]);
                $maxUsed = $stMax->fetchColumn();
                if ($maxUsed !== null && $fibreCount < (int)$maxUsed) {
                    $pdo->rollBack();
                    api_err("Cannot set fibre count to {$fibreCount}: fibre #{$maxUsed} is already spliced on this cable. Remove those splices first.");
                }
            }

            // Only overwrite fibre_count when provided; always apply material_id (null = unset).
            if ($fibreCount !== null) {
                $stC = $pdo->prepare('UPDATE app.cable SET material_id = :mat, fibre_count = :fc, updated_at = now() WHERE cable_id = :cid');
                $stC->execute([':mat' => $materialId, ':fc' => $fibreCount, ':cid' => $cableId]);
            } else {
                $stC = $pdo->prepare('UPDATE app.cable SET material_id = :mat, updated_at = now() WHERE cable_id = :cid');
                $stC->execute([':mat' => $materialId, ':cid' => $cableId]);
            }
        }

        // Propagate fpm_wor to the optitap fosc endpoint (FlexTIN tap), if applicable
        $stTap = $pdo->prepare("
            UPDATE app.fosc SET fpm_wor = :wor
             WHERE fosc_id = :fid
               AND kind = 'optitap'
               AND (attrs->>'parent_fosc_id') IS NOT NULL
        ");
        $stTap->execute([':wor' => $fpmWor, ':fid' => $toFoscId]);
        // Keep the closure's work-order join table (migration 0125) in sync with
        // the auto-tagged primary so multi-permit filters/counts stay correct.
        if ($stTap->rowCount() > 0) {
            $pdo->prepare('DELETE FROM app.fosc_work_order WHERE fosc_id = :fid')->execute([':fid' => $toFoscId]);
            if ($fpmWor !== null && $fpmWor !== '') {
                $pdo->prepare('INSERT INTO app.fosc_work_order (fosc_id, work_order, is_primary) VALUES (:fid, :wor, true)')
                    ->execute([':fid' => $toFoscId, ':wor' => $fpmWor]);
            }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok(['id' => $id, 'inherited' => $inherited, 'inherited_summary' => wo_inherit_summary($inherited)]);
}

// ── Multiple permits per span (mirrors pole_work_order / fosc_work_order) ─────

function span_extra_work_orders(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $wo) {
        $v = trim((string)$wo);
        if ($v !== '' && mb_strlen($v) <= 80) $out[] = $v;
    }
    return array_values(array_unique($out));
}

/**
 * Keep app.cable_span_work_order in step with the span's permits.
 *
 * $extraWOs === null means "the caller didn't send extras" (e.g. a notes-only
 * patch): the secondary permits are left untouched and only the primary flag is
 * re-pointed, so an unrelated save can't silently drop them.
 */
function span_sync_work_orders(PDO $pdo, int $spanId, string $primaryWO, ?array $extraWOs): void
{
    if ($extraWOs === null) {
        $pdo->prepare('DELETE FROM app.cable_span_work_order WHERE cable_span_id = :id AND is_primary')
            ->execute([':id' => $spanId]);
        if ($primaryWO !== '') {
            $pdo->prepare('INSERT INTO app.cable_span_work_order (cable_span_id, work_order, is_primary)
                           VALUES (:id, :wo, true)
                           ON CONFLICT (cable_span_id, work_order) DO UPDATE SET is_primary = true')
                ->execute([':id' => $spanId, ':wo' => $primaryWO]);
        }
        return;
    }

    $pdo->prepare('DELETE FROM app.cable_span_work_order WHERE cable_span_id = :id')
        ->execute([':id' => $spanId]);
    if ($primaryWO !== '') {
        $pdo->prepare('INSERT INTO app.cable_span_work_order (cable_span_id, work_order, is_primary) VALUES (:id, :wo, true)')
            ->execute([':id' => $spanId, ':wo' => $primaryWO]);
    }
    $st = $pdo->prepare('INSERT INTO app.cable_span_work_order (cable_span_id, work_order, is_primary) VALUES (:id, :wo, false) ON CONFLICT DO NOTHING');
    foreach ($extraWOs as $wo) {
        if ($wo !== $primaryWO) $st->execute([':id' => $spanId, ':wo' => $wo]);
    }
}

function span_create(PDO $pdo, array $body): never
{
    $cableId    = (int)($body['cable_id']     ?? 0);
    $fromFoscId = (int)($body['from_fosc_id'] ?? 0);
    $toFoscId   = isset($body['to_fosc_id']) ? (int)$body['to_fosc_id'] : null;
    $notes      = trim((string)($body['notes']      ?? ''));
    $fpmWor  = trim((string)($body['fpm_wor'] ?? '')) ?: null;
    $extraWOs   = span_extra_work_orders($body['extra_work_orders'] ?? []);
    $geojson    = $body['geojson'] ?? null;

    // Open-ended (optitap) fields — only used when to_fosc_id is absent
    $tapNum     = isset($body['tap_num'])    ? strtoupper(trim((string)$body['tap_num'])) : null;
    $tapStatus  = (string)($body['tap_status'] ?? 'plan');
    $toPoint    = $body['to_point'] ?? null;  // Point GeoJSON for new optitap location

    if ($cableId <= 0)    api_err('cable_id is required');
    if ($fromFoscId <= 0) api_err('from_fosc_id is required');

    // Look up cable to check open_ended flag
    $cableRow = $pdo->prepare("SELECT open_ended FROM app.cable WHERE cable_id = :id");
    $cableRow->execute([':id' => $cableId]);
    $cable = $cableRow->fetch();
    if (!$cable) api_err('Unknown cable_id');
    $isOpenEnded = (bool)$cable['open_ended'];

    // Resolve to_fosc_id — either provided, or auto-create an optitap FOSC
    if ($toFoscId === null || $toFoscId <= 0) {
        if (!$isOpenEnded) {
            api_err('to_fosc_id is required for this cable type');
        }
        // Validate optitap fields
        if (!$tapNum) api_err('tap_num is required when creating a new optitap endpoint');
        if (!preg_match('/^[LR][0-9]{2}$/', $tapNum)) {
            api_err('tap_num must be L or R followed by 2 digits (e.g. L01, R04)');
        }
        $validStatuses = ['plan', 'active', 'retired'];
        if (!in_array($tapStatus, $validStatuses, true)) $tapStatus = 'plan';
        if (!is_array($toPoint) || ($toPoint['type'] ?? '') !== 'Point'
            || count($toPoint['coordinates'] ?? []) < 2) {
            api_err('to_point must be a valid GeoJSON Point');
        }

        // Create optitap FOSC + span in one transaction
        try {
            $pdo->beginTransaction();

            // closure_type_id 8 = Unknown (no specific optitap type yet)
            $stFosc = $pdo->prepare("
                INSERT INTO app.fosc (splice_num, status, location, closure_type_id, kind)
                VALUES (:sn, :status::app.fosc_status,
                        ST_SetSRID(ST_GeomFromGeoJSON(:pt), 4326),
                        8, 'optitap')
                RETURNING fosc_id
            ");
            $stFosc->execute([
                ':sn'     => $tapNum,
                ':status' => $tapStatus,
                ':pt'     => json_encode($toPoint),
            ]);
            $toFoscId = (int)$stFosc->fetchColumn();

            $spanId = span_insert($pdo, $cableId, $fromFoscId, $toFoscId, $notes, $fpmWor, $geojson, $extraWOs);
            if ($fpmWor !== null && $fpmWor !== '') wo_inherit_from_span($pdo, $spanId, $fpmWor);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_err('Database error: ' . $e->getMessage(), 500);
        }

        api_ok(['cable_span_id' => $spanId, 'optitap_fosc_id' => $toFoscId]);
    }

    // Normal path: to_fosc_id provided directly
    if ($fromFoscId === $toFoscId) api_err('from and to closures must differ');
    try {
        $pdo->beginTransaction();
        $spanId = span_insert($pdo, $cableId, $fromFoscId, $toFoscId, $notes, $fpmWor, $geojson, $extraWOs);
        $inherited = $fpmWor !== null && $fpmWor !== ''
            ? wo_inherit_from_span($pdo, $spanId, $fpmWor) : [];
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }
    api_ok(['cable_span_id' => $spanId, 'inherited' => $inherited,
            'inherited_summary' => wo_inherit_summary($inherited)]);
}

function span_insert(PDO $pdo, int $cableId, int $fromFoscId, int $toFoscId,
                     string $notes, ?string $fpmWor, ?array $geojson,
                     array $extraWOs = []): int
{
    $hasGeom = is_array($geojson)
        && ($geojson['type'] ?? '') === 'LineString'
        && count($geojson['coordinates'] ?? []) >= 2;

    if ($hasGeom) {
        $st = $pdo->prepare("
            INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, geom_middle, notes, fpm_wor)
            VALUES (:cable, :from, :to,
                    ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326),
                    :notes, :wor)
            RETURNING cable_span_id
        ");
        $st->execute([
            ':cable'   => $cableId,
            ':from'    => $fromFoscId,
            ':to'      => $toFoscId,
            ':geojson' => json_encode($geojson),
            ':notes'   => $notes,
            ':wor'     => $fpmWor,
        ]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, notes, fpm_wor)
            VALUES (:cable, :from, :to, :notes, :wor)
            RETURNING cable_span_id
        ");
        $st->execute([
            ':cable' => $cableId,
            ':from'  => $fromFoscId,
            ':to'    => $toFoscId,
            ':notes' => $notes,
            ':wor'   => $fpmWor,
        ]);
    }
    $spanId = (int)$st->fetch()['cable_span_id'];
    span_sync_work_orders($pdo, $spanId, (string)($fpmWor ?? ''), $extraWOs);
    return $spanId;
}

function span_delete(PDO $pdo, int $id): never
{
    if ($id <= 0) api_err('Invalid id');

    // Check for active splice_join rows referencing this span (polymorphic — no FK)
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM app.splice_join
         WHERE retired_at IS NULL
           AND ((a_target = 'cable_span' AND a_span_id = :id)
            OR  (b_target = 'cable_span' AND b_span_id = :id))
    ");
    $st->execute([':id' => $id]);
    $spliceCount = (int)$st->fetchColumn();
    if ($spliceCount > 0) {
        api_err("Cannot delete: span has {$spliceCount} splice(s). Remove splices first.");
    }

    // Find the span to check if to_fosc is an auto-created optitap
    $stSpan = $pdo->prepare("
        SELECT cs.to_fosc_id, f.kind
          FROM app.cable_span cs
          JOIN app.fosc f ON f.fosc_id = cs.to_fosc_id
         WHERE cs.cable_span_id = :id
    ");
    $stSpan->execute([':id' => $id]);
    $spanRow = $stSpan->fetch(PDO::FETCH_ASSOC);
    if (!$spanRow) api_err('Span not found', 404);

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM app.cable_span WHERE cable_span_id = :id")
            ->execute([':id' => $id]);

        // If the terminal end was an auto-created optitap, delete it too
        if ($spanRow['kind'] === 'optitap') {
            $pdo->prepare("DELETE FROM app.fosc WHERE fosc_id = :id")
                ->execute([':id' => $spanRow['to_fosc_id']]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    api_ok();
}

function span_update(PDO $pdo, int $id, array $body): never
{
    if ($id <= 0) {
        api_err('Invalid id');
    }

    $geojson = $body['geojson'] ?? null;
    if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'LineString') {
        api_err('geojson must be a GeoJSON LineString');
    }
    if (count($geojson['coordinates'] ?? []) < 2) {
        api_err('LineString must have at least 2 points');
    }

    // Set geom_middle to the edited route; null out geom_snap so the
    // cable_span_enforce_endpoints trigger re-assembles it (snapping endpoints
    // to the FOSC locations and recalculating length_m).
    try {
        $st = $pdo->prepare("
            UPDATE app.cable_span
               SET geom_middle = ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326),
                   geom_snap   = NULL,
                   updated_at  = now()
             WHERE cable_span_id = :id
        ");
        $st->execute([':geojson' => json_encode($geojson), ':id' => $id]);
    } catch (PDOException $e) {
        api_err('Database error: ' . $e->getMessage(), 500);
    }

    if ($st->rowCount() === 0) {
        api_err('Span not found', 404);
    }

    api_ok();
}
