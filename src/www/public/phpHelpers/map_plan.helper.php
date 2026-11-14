<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// public/phpHelpers/map_plan.helper.php
// Shared helpers for the map-plan feature: canonical layer list, title-block
// resolution from the linked entity, and PostGIS auto-framing.

/**
 * Canonical overlay layer keys → human labels (order = display order).
 * Shared by the API, the plan form, and the map JS.
 */
function map_plan_layer_keys(): array
{
    return [
        'poles'    => 'Poles',
        'spans'    => 'Cable spans',
        'strands'  => 'Strands',
        'conduits' => 'Conduits',
        'closures' => 'Closures',
        'anchors'  => 'Anchors',
        // planLayerGroups() in map.helper.js has always had this group; without the
        // key here map_plan_clean_layers() silently dropped it on save.
        'buildingAnchors' => 'Building anchors',
        'drops'    => 'Drops / ONUs',
        'vaults'   => 'Vaults / handholes',
        'dimensions' => 'Dimensions',
    ];
}

/** Normalise a client-supplied layers array to known keys. */
function map_plan_clean_layers(array $layers): array
{
    $known = array_keys(map_plan_layer_keys());
    $out = [];
    foreach ($layers as $l) {
        $l = (string)$l;
        if (in_array($l, $known, true) && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out;
}

/**
 * Resolve the title-block fields for a plan from its linked record.
 * Returns: ['number','party','date','location','entity_label'].
 * Manual overrides in $meta (drawing_title, location, revision, notes) are
 * surfaced too but applied client-side; this returns the record-derived values.
 */
function map_plan_titleblock(PDO $pdo, string $entityType, string $entityId): array
{
    $tb = ['number' => '', 'party' => '', 'date' => '', 'location' => '', 'entity_label' => ''];

    if ($entityType === 'bell_permit') {
        $st = $pdo->prepare("SELECT permit_num, applicant, municipality, permit_date FROM app.bell_permit WHERE id = :id");
        $st->execute([':id' => (int)$entityId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $tb['number']       = (string)($r['permit_num'] ?? '');
            $tb['party']        = (string)($r['applicant'] ?? '');
            $tb['location']     = (string)($r['municipality'] ?? '');
            $tb['entity_label'] = 'Bell Permit';
            $tb['date']         = $r['permit_date'] ? date('F j, Y', strtotime($r['permit_date'])) : '';
        }
    } elseif ($entityType === 'fpm_permit') {
        $st = $pdo->prepare("SELECT permit_num FROM app.fpm_permit WHERE permit_num = :id");
        $st->execute([':id' => $entityId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $tb['number']       = (string)$r['permit_num'];
            $tb['entity_label'] = org('wo_label');
        }
    }
    return $tb;
}

/**
 * Compute a sensible centre + zoom from the entity's geometry (PostGIS extent).
 * Tinuum permit: poles (via pole_work_order) + cable_spans + closures.
 * Bell permit:   poles (via bell_permit_pole).
 * Returns ['lat','lon','zoom'] or null if the entity has no geometry.
 */
function map_plan_autoframe(PDO $pdo, string $entityType, string $entityId): ?array
{
    if ($entityType === 'fpm_permit') {
        $sql = "
            WITH g AS (
                SELECT p.geom AS geom FROM app.pole p
                  JOIN app.pole_work_order pwo ON pwo.pole_fid = p.fid
                 WHERE pwo.work_order = :id
                UNION ALL SELECT cs.geom FROM app.cable_span cs
                           WHERE cs.fpm_wor = :id
                              OR EXISTS (SELECT 1 FROM app.cable_span_work_order w
                                          WHERE w.cable_span_id = cs.cable_span_id AND w.work_order = :id)
                UNION ALL SELECT f.location FROM app.fosc f
                            JOIN app.fosc_work_order fwo ON fwo.fosc_id = f.fosc_id AND fwo.work_order = :id
            )
            SELECT ST_YMin(ext) ymin, ST_YMax(ext) ymax, ST_XMin(ext) xmin, ST_XMax(ext) xmax
            FROM (SELECT ST_Extent(geom)::geometry ext FROM g) e";
        $params = [':id' => $entityId];
    } elseif ($entityType === 'bell_permit') {
        $sql = "
            WITH g AS (
                SELECT p.geom AS geom FROM app.pole p
                  JOIN app.bell_permit_pole bpp ON bpp.pole_fid = p.fid
                 WHERE bpp.permit_id = :id
            )
            SELECT ST_YMin(ext) ymin, ST_YMax(ext) ymax, ST_XMin(ext) xmin, ST_XMax(ext) xmax
            FROM (SELECT ST_Extent(geom)::geometry ext FROM g) e";
        $params = [':id' => (int)$entityId];
    } else {
        return null; // tender / adhoc have no intrinsic geometry
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['ymin'] === null) return null;

    $lat = ((float)$r['ymin'] + (float)$r['ymax']) / 2.0;
    $lon = ((float)$r['xmin'] + (float)$r['xmax']) / 2.0;

    // Estimate zoom so the bounding box fits comfortably (lon scaled by cos(lat)).
    $wDeg = ((float)$r['xmax'] - (float)$r['xmin']) * cos(deg2rad($lat));
    $hDeg = (float)$r['ymax'] - (float)$r['ymin'];
    $span = max($wDeg, $hDeg);
    if ($span <= 0) {
        $zoom = 18;
    } else {
        $zoom = (int)floor(log(360.0 / $span) / log(2)) - 1; // -1 = padding
        $zoom = max(12, min(19, $zoom));
    }
    return ['lat' => round($lat, 6), 'lon' => round($lon, 6), 'zoom' => $zoom];
}
