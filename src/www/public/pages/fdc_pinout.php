<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/fdc_pinout.php — FDC Port Pinout
//
// Automatically discovers all cable spans at the FDC, assigns sequential port
// numbers (sorted by cable name → span id), and traces each fiber from its
// far-end outward to find leaf closures, road labels, and active-laser status.
// No manual "feed direction" configuration required.

set_time_limit(120);

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

// ──────────────────────────────────────────────────────────────────────────────
// SQL helper
// ──────────────────────────────────────────────────────────────────────────────

/**
 * For every cable span at the FDC, expand its fiber range into individual port
 * numbers and run a light-path trace from the far end (away from the FDC).
 * Returns one row per (port_num, branch_path) leaf combination.
 */
function fetch_fdc_port_map(PDO $pdo, int $fdcId): array
{
    $st = $pdo->prepare("
        -- Step 1: cable spans at the FDC, ordered for consistent port numbering
        WITH fdc_spans AS (
            SELECT
                cs.cable_span_id,
                c.name              AS cable_name,
                c.fibre_count,
                -- Trace from the FAR end (away from FDC) to follow outbound cable paths
                CASE WHEN cs.from_fosc_id = :fid THEN 'to' ELSE 'from' END AS trace_end,
                ROW_NUMBER() OVER (ORDER BY c.name, cs.cable_span_id) AS span_order
            FROM app.cable_span cs
            JOIN app.cable c ON c.cable_id = cs.cable_id
            WHERE cs.from_fosc_id = :fid OR cs.to_fosc_id = :fid
        ),
        -- Step 2: cumulative port offset per span
        span_offsets AS (
            SELECT *,
                COALESCE(SUM(fibre_count) OVER (
                    ORDER BY span_order
                    ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                ), 0) AS port_offset
            FROM fdc_spans
        ),
        -- Step 3: one row per (span, fiber) → global port number
        fiber_series AS (
            SELECT
                s.cable_span_id,
                s.cable_name,
                s.trace_end,
                gs.n                    AS span_fiber,
                s.port_offset + gs.n    AS port_num
            FROM span_offsets s
            CROSS JOIN generate_series(1, s.fibre_count) gs(n)
        ),
        -- Step 4: trace each fiber from the far end
        all_hops AS (
            SELECT fs.port_num, fs.cable_span_id AS fdc_span_id, fs.span_fiber, t.*
            FROM fiber_series fs
            CROSS JOIN LATERAL (
                SELECT *
                FROM app.trace_light_path(
                    fs.cable_span_id,
                    fs.span_fiber,
                    fs.trace_end::app.span_end,
                    false,
                    50
                )
            ) t
        ),
        -- Step 5: identify active fibres (laser source reachable anywhere in path)
        active_set AS (
            SELECT DISTINCT port_num
            FROM all_hops
            WHERE device_type IN ('olt_pon', 'sfp', 'dwdm')
        ),
        -- Step 6: leaf = last hop in each (port, branch_path)
        leaves AS (
            SELECT DISTINCT ON (port_num, branch_path)
                port_num,
                branch_path,
                hop_num         AS leaf_hop,
                fosc_id         AS leaf_fosc_id,
                fosc_label      AS leaf_label,
                device_type,
                cumulative_loss_db,
                cable_span_id   AS last_span_id
            FROM all_hops
            ORDER BY port_num, branch_path, hop_num DESC
        ),
        -- Step 6b: upstream closure = last hop with a non-null fosc strictly before the leaf
        parent_closures AS (
            SELECT DISTINCT ON (ah.port_num, ah.branch_path)
                ah.port_num,
                ah.branch_path,
                ah.fosc_id    AS parent_fosc_id,
                ah.fosc_label AS parent_label
            FROM all_hops ah
            JOIN leaves l ON l.port_num = ah.port_num AND l.branch_path = ah.branch_path
            WHERE ah.fosc_id IS NOT NULL
              AND ah.fosc_id <> :fid
              AND ah.hop_num < l.leaf_hop
            ORDER BY ah.port_num, ah.branch_path, ah.hop_num DESC
        )
        -- Step 7: enrich with closure address + span tags
        SELECT
            l.port_num,
            l.branch_path,
            l.leaf_hop,
            l.leaf_fosc_id,
            l.leaf_label,
            l.device_type,
            ROUND(l.cumulative_loss_db, 2)          AS loss_db,
            f.attrs->>'street_address'              AS street_address,
            f.kind                                  AS leaf_kind,
            (a.port_num IS NOT NULL)::bool          AS is_active,
            (
                -- Search tags on any span in this branch OR any ancestor branch
                -- (e.g. branch '1.3' inherits tags from spans in branch '1')
                SELECT string_agg(DISTINCT st.name, ',' ORDER BY st.name)
                FROM all_hops ah
                JOIN app.cable_span_tag cst ON cst.cable_span_id = ah.cable_span_id
                JOIN app.span_tag st ON st.span_tag_id = cst.span_tag_id
                WHERE ah.port_num = l.port_num
                  AND (ah.branch_path = l.branch_path
                       OR l.branch_path LIKE ah.branch_path || '.%')
            ) AS span_tags,
            pc.parent_fosc_id,
            pc.parent_label
        FROM leaves l
        LEFT JOIN app.fosc f ON f.fosc_id = l.leaf_fosc_id
        LEFT JOIN active_set a ON a.port_num = l.port_num
        LEFT JOIN parent_closures pc ON pc.port_num = l.port_num AND pc.branch_path = l.branch_path
        ORDER BY l.port_num, l.branch_path
    ");
    $st->execute([':fid' => $fdcId]);
    return $st->fetchAll() ?: [];
}

/**
 * Fetch connector-patch connections at the FDC.
 * Returns [$port_num => ['device_name'=>…, 'device_port'=>…, 'notes'=>…]]
 */
function fetch_fdc_patch_connections(PDO $pdo, int $fdcId): array
{
    $st = $pdo->prepare("
        WITH fdc_spans AS (
            SELECT
                cs.cable_span_id,
                CASE WHEN cs.from_fosc_id = :fid THEN 'from' ELSE 'to' END AS fdc_end,
                ROW_NUMBER() OVER (ORDER BY c.name, cs.cable_span_id)       AS span_order,
                c.fibre_count
            FROM app.cable_span cs
            JOIN app.cable c ON c.cable_id = cs.cable_id
            WHERE cs.from_fosc_id = :fid OR cs.to_fosc_id = :fid
        ),
        span_offsets AS (
            SELECT *,
                COALESCE(SUM(fibre_count) OVER (
                    ORDER BY span_order
                    ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                ), 0) AS port_offset
            FROM fdc_spans
        ),
        patches AS (
            -- cable = a_side, device = b_side
            SELECT so.port_offset + ab.lo AS port_num,
                   j.b_span_id AS device_id, bb.lo AS device_port, j.notes
            FROM app.splice_join j
            CROSS JOIN LATERAL app.int_range_bounds_display(j.a_range) ab
            CROSS JOIN LATERAL app.int_range_bounds_display(j.b_range) bb
            JOIN span_offsets so
              ON so.cable_span_id = j.a_span_id
             AND so.fdc_end::text = j.a_end::text
            WHERE j.join_kind = 'connector'
              AND j.a_target = 'cable_span'
              AND j.b_target = 'fosc_device'
              AND j.fosc_id  = :fid
              AND j.retired_at IS NULL
            UNION ALL
            -- cable = b_side, device = a_side
            SELECT so.port_offset + bb.lo AS port_num,
                   j.a_span_id AS device_id, ab.lo AS device_port, j.notes
            FROM app.splice_join j
            CROSS JOIN LATERAL app.int_range_bounds_display(j.a_range) ab
            CROSS JOIN LATERAL app.int_range_bounds_display(j.b_range) bb
            JOIN span_offsets so
              ON so.cable_span_id = j.b_span_id
             AND so.fdc_end::text = j.b_end::text
            WHERE j.join_kind = 'connector'
              AND j.b_target = 'cable_span'
              AND j.a_target = 'fosc_device'
              AND j.fosc_id  = :fid
              AND j.retired_at IS NULL
        )
        SELECT p.port_num, p.device_port, p.notes, fd.name AS device_name
        FROM patches p
        JOIN app.fosc_device fd ON fd.fosc_device_id = p.device_id
        ORDER BY p.port_num
    ");
    $st->execute([':fid' => $fdcId]);
    $out = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $out[(int)$row['port_num']] = $row;
    }
    return $out;
}

/**
 * Get the list of cable spans at the FDC (for header display).
 * Returns [{cable_span_id, cable_name, fibre_count, far_label, port_lo, port_hi}]
 */
function fetch_fdc_span_list(PDO $pdo, int $fdcId): array
{
    $st = $pdo->prepare("
        WITH spans AS (
            SELECT
                cs.cable_span_id,
                c.name      AS cable_name,
                c.fibre_count,
                CASE WHEN cs.from_fosc_id = :fid
                     THEN f2.splice_num
                     ELSE f1.splice_num END AS far_label,
                ROW_NUMBER() OVER (ORDER BY c.name, cs.cable_span_id) AS span_order
            FROM app.cable_span cs
            JOIN app.cable c ON c.cable_id = cs.cable_id
            JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
            JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
            WHERE cs.from_fosc_id = :fid OR cs.to_fosc_id = :fid
        )
        SELECT *,
            COALESCE(SUM(fibre_count) OVER (
                ORDER BY span_order
                ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
            ), 0) + 1                                                AS port_lo,
            SUM(fibre_count) OVER (ORDER BY span_order)             AS port_hi
        FROM spans
        ORDER BY span_order
    ");
    $st->execute([':fid' => $fdcId]);
    return $st->fetchAll() ?: [];
}

// ──────────────────────────────────────────────────────────────────────────────
// Road-label helpers
// ──────────────────────────────────────────────────────────────────────────────

function pinout_road_from_address(?string $addr): ?string
{
    if (!$addr) return null;
    $first = trim(explode(',', $addr)[0] ?? '');
    $road  = preg_replace('/^\d+[-\w]*\s+/', '', $first);
    return ($road !== '' && $road !== null) ? $road : null;
}

function pinout_road_label(array $leaf): ?string
{
    $tags = trim($leaf['span_tags'] ?? '');
    if ($tags !== '') return explode(',', $tags)[0];
    return pinout_road_from_address($leaf['street_address'] ?? null);
}

// ──────────────────────────────────────────────────────────────────────────────
// Input / validation
// ──────────────────────────────────────────────────────────────────────────────

$foscId    = (int)($_GET['fosc_id'] ?? 0);
$fosc      = null;
$error     = null;

if ($foscId > 0) {
    $st = $pdo->prepare("
        SELECT fosc_id, splice_num, kind
          FROM app.fosc WHERE fosc_id = :id
    ");
    $st->execute([':id' => $foscId]);
    $fosc = $st->fetch() ?: null;

    if ($fosc) {
        if (!in_array($fosc['kind'], ['cabinet', 'patch_panel'], true)) {
            $error = 'This closure is not a patch panel (kind must be "cabinet" or "patch_panel").';
        }
    } else {
        $error = "Closure #{$foscId} not found.";
    }
} else {
    $error = 'No closure specified.';
}

// ──────────────────────────────────────────────────────────────────────────────
// Data retrieval
// ──────────────────────────────────────────────────────────────────────────────

$spanList      = [];
$totalFibers   = 0;
$portMap       = [];
$patchMap      = [];
$roads         = [];
$roadSummary   = [];
$reservedCount = 0;

if (!$error) {
    $spanList    = fetch_fdc_span_list($pdo, $foscId);
    $totalFibers = (int)array_sum(array_column($spanList, 'fibre_count'));

    if ($totalFibers === 0) {
        $error = 'No cable spans are connected to this FDC.';
    } else {
        try {
            $rows = fetch_fdc_port_map($pdo, $foscId);
        } catch (Throwable $e) {
            $error = 'Trace query failed: ' . $e->getMessage();
            $rows  = [];
        }
        $patchMap = fetch_fdc_patch_connections($pdo, $foscId);

        foreach ($rows as $row) {
            $row['road'] = pinout_road_label($row);
            $portMap[(int)$row['port_num']][] = $row;
        }

        // Remove device-hop "false leaves".
        // trace_light_path now traverses through splitters: device hops appear as
        // the last entry in branch '1' while real homes appear in sub-branches
        // '1.1', '1.2', etc. (device_type != NULL marks device hops). If any real
        // cable-endpoint leaves exist for a port, discard the device-hop leaves.
        // If no cable-endpoint leaves exist (device connected but no outputs
        // configured in DB), keep the device-hop leaf so the port isn't invisible.
        foreach ($portMap as $portNum => $leaves) {
            $realLeaves = array_values(
                array_filter($leaves, fn($l) => empty($l['device_type']))
            );
            if (count($realLeaves) > 0) {
                $portMap[$portNum] = $realLeaves;
            }
        }

        for ($i = 1; $i <= $totalFibers; $i++) {
            if (!isset($portMap[$i])) $portMap[$i] = [];
        }

        // --- Customer ONT data for tooltips ---
        require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

        // Collect all terminal fosc IDs from portMap
        $leafFoscIds = [];
        foreach ($portMap as $leaves) {
            foreach ($leaves as $leaf) {
                if (!empty($leaf['leaf_fosc_id'])) $leafFoscIds[] = (int)$leaf['leaf_fosc_id'];
            }
        }
        $leafFoscIds = array_values(array_unique($leafFoscIds));

        // $assocByFoscId[fosc_id] = [uuid, ...]
        $assocByFoscId = [];
        if ($leafFoscIds) {
            $ph   = implode(',', array_fill(0, count($leafFoscIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT fosc_id, uisp_device_id::text FROM app.ont_association WHERE fosc_id IN ($ph)"
            );
            $stmt->execute($leafFoscIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $assocByFoscId[(int)$row['fosc_id']][] = $row['uisp_device_id'];
            }
        }

        // $customerByDeviceId[uuid] = ['name' => ..., 'addr' => ...]
        $customerByDeviceId = [];
        if ($assocByFoscId) {
            $devices  = uisp_fetch_cached('devices', 120, $pdo);
            $sites    = uisp_fetch_cached('sites?type=endpoint', 3600, $pdo);
            $siteById = [];
            foreach (($sites ?: []) as $site) {
                if (isset($site['id'])) $siteById[$site['id']] = $site;
            }
            $neededUuids = array_fill_keys(array_merge(...array_values($assocByFoscId)), true);
            foreach (($devices ?: []) as $dev) {
                $devId = $dev['identification']['id'] ?? null;
                if (!$devId || !isset($neededUuids[$devId])) continue;
                $siteId   = $dev['identification']['site']['id'] ?? null;
                $site     = $siteId ? ($siteById[$siteId] ?? null) : null;
                $name     = $dev['identification']['site']['name'] ?? $dev['identification']['name'] ?? '';
                $fullAddr = trim($site['description']['address'] ?? '');
                $shortAddr = $fullAddr ? trim(explode(',', $fullAddr)[0]) : '';
                $customerByDeviceId[$devId] = ['name' => $name, 'addr' => $shortAddr];
            }
        }

        // Roads in first-appearance (port-number) order → stable colours
        foreach ($portMap as $leaves) {
            // Determine if this port is reserved (trace stopped at hop 2 = first downstream closure)
            $maxLeafHop = $leaves ? max(array_column($leaves, 'leaf_hop')) : 0;
            if ($maxLeafHop <= 1) continue;  // skip reserved/dark ports for road colour assignment
            foreach ($leaves as $l) {
                $r = $l['road'] ?? null;
                if ($r && !isset($roads[$r])) $roads[$r] = count($roads);
            }
        }

        // Per-road summary for legend (only connected, non-reserved ports)
        $reservedCount = 0;
        foreach ($portMap as $leaves) {
            $maxLeafHop = $leaves ? max(array_column($leaves, 'leaf_hop')) : 0;
            if ($maxLeafHop <= 1) {
                $reservedCount++;
                continue;  // reserved ports go in their own bucket
            }
            $road   = ($leaves && ($leaves[0]['road'] ?? null))
                        ? $leaves[0]['road'] : '—';
            $active = (bool)array_reduce(
                $leaves, fn ($c, $l) => $c || (bool)$l['is_active'], false
            );
            $roadSummary[$road]['total'] = ($roadSummary[$road]['total'] ?? 0) + 1;
            if ($active) $roadSummary[$road]['active'] =
                ($roadSummary[$road]['active'] ?? 0) + 1;
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Colour palette
// ──────────────────────────────────────────────────────────────────────────────

$palette = [
    '#3b82f6', '#f97316', '#8b5cf6', '#ef4444',
    '#22c55e', '#ca8a04', '#06b6d4', '#ec4899',
    '#14b8a6', '#84cc16', '#f43f5e', '#64748b',
];
$emptyColor = '#e2e8f0';

// ──────────────────────────────────────────────────────────────────────────────
// Page setup
// ──────────────────────────────────────────────────────────────────────────────

$PAGE->setTitle(($fosc ? h($fosc['splice_num']) . ' ' : '') . 'Pinout');

$PAGE->setInlineCssRenderer(function () {
    return <<<CSS
.fdc-half {
    display: grid;
    grid-template-rows: repeat(12, auto);
    grid-auto-flow: column;
    grid-auto-columns: 62px;
    row-gap: 4px;
    column-gap: 3px;
    user-select: none;
}
.fdc-port {
    position: relative;
    min-height: 66px;
    padding: 5px 4px 3px;
    border-radius: 5px;
    border: 1px solid rgba(0,0,0,.10);
    text-align: center;
    cursor: default;
    overflow: hidden;
}
.fdc-port.fdc-active   { box-shadow: 0 0 0 2px #22c55e; }
.fdc-port.fdc-reserved { border: 1px dashed rgba(0,0,0,.25); opacity:.85; }
.fdc-port.fdc-crossed::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(to bottom right,
            transparent calc(50% - 0.8px), rgba(0,0,0,.35) calc(50% - 0.8px),
            rgba(0,0,0,.35) calc(50% + 0.8px), transparent calc(50% + 0.8px)),
        linear-gradient(to bottom left,
            transparent calc(50% - 0.8px), rgba(0,0,0,.35) calc(50% - 0.8px),
            rgba(0,0,0,.35) calc(50% + 0.8px), transparent calc(50% + 0.8px));
    pointer-events: none;
    border-radius: 4px;
}
.fdc-num  { display:block; font-size:.7rem; font-weight:700; opacity:.65; line-height:1.3; }
.fdc-dot  { position:absolute; top:4px; right:5px; font-size:9px; color:#22c55e; line-height:1; }
.fdc-lbl  { display:block; font-size:.65rem; overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap; margin-top:3px; }
.fdc-port.fdc-patched { border: 2px solid #6366f1; }
.fdc-patch-lbl {
    display:block; font-size:.6rem; color:#6366f1; font-weight:700;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px;
}
.fdc-legend-chip {
    display:inline-block; padding:3px 10px; border-radius:4px;
    margin:2px; font-size:.8rem; color:#fff; font-weight:600;
    text-shadow:0 1px 2px rgba(0,0,0,.35);
}
.tooltip .tooltip-inner { max-width:340px; text-align:left; white-space:nowrap; }
CSS;
});

$PAGE->setInlineJsRenderer(function () {
    return <<<JS
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            new bootstrap.Tooltip(el);
        }
    });
});
JS;
});

// ──────────────────────────────────────────────────────────────────────────────
// Content renderer
// ──────────────────────────────────────────────────────────────────────────────

$PAGE->setContentRenderer(function () use (
    $foscId, $fosc, $error, $spanList, $totalFibers,
    $portMap, $patchMap, $roads, $roadSummary, $reservedCount, $palette, $emptyColor
) {
    ob_start();
    ?>

    <!-- Page header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div>
        <h3 class="mb-0">
          <?= $fosc ? h($fosc['splice_num']) : 'FDC' ?> — Pinout
          <?php if ($fosc): ?>
            <span class="badge bg-warning text-dark ms-2">FDC</span>
          <?php endif; ?>
        </h3>
        <?php if (!empty($spanList)): ?>
          <p class="text-muted small mb-0">
            <?php foreach ($spanList as $s): ?>
              <strong><?= h($s['cable_name']) ?></strong>
              (<?= (int)$s['fibre_count'] ?>F, ports <?= (int)$s['port_lo'] ?>–<?= (int)$s['port_hi'] ?>
              → <?= h($s['far_label']) ?>)<?= $s !== end($spanList) ? ' &middot; ' : '' ?>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($foscId): ?>
          <a href="index.php?page=closure&fosc_id=<?= $foscId ?>"
             class="btn btn-outline-secondary btn-sm">&larr; Closure</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php elseif ($totalFibers === 0): ?>
      <div class="alert alert-secondary">No cable spans connected to this FDC.</div>
    <?php else: ?>

      <!-- Legend -->
      <?php if (!empty($roadSummary) || $reservedCount > 0): ?>
        <div class="mb-3">
          <?php
          foreach ($roadSummary as $road => $info):
              $idx   = $roads[$road] ?? (count($palette) - 1);
              $color = $palette[$idx % count($palette)];
              $isDash = ($road === '—');
              $chipStyle = $isDash
                  ? 'background:' . $emptyColor . ';color:#6b7280;text-shadow:none'
                  : 'background:' . h($color);
          ?>
            <span class="fdc-legend-chip" style="<?= $chipStyle ?>">
              <?= h($road) ?>
              (<?= (int)$info['total'] ?> port<?= $info['total'] !== 1 ? 's' : '' ?>
              <?php if ($info['active'] ?? 0): ?>
                &middot; <?= (int)$info['active'] ?> active
              <?php endif; ?>)
            </span>
          <?php endforeach; ?>
          <?php if ($reservedCount > 0): ?>
            <span class="fdc-legend-chip"
                  style="background:#fbbf24;color:#1c1917;text-shadow:none;border:1px dashed rgba(0,0,0,.25)">
              Reserved (<?= $reservedCount ?> port<?= $reservedCount !== 1 ? 's' : '' ?>, future use)
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Patch-panel grid: two half-panels, column-major (1-12 down col 1, etc.) -->
      <?php $half = (int)ceil($totalFibers / 2); ?>
      <div class="d-flex flex-column gap-3">
      <?php foreach ([[1, $half], [$half + 1, $totalFibers]] as [$lo, $hi]):
            if ($lo > $totalFibers) break; ?>
      <div class="fdc-half">
        <?php for ($i = $lo; $i <= $hi; $i++):
            $leaves    = $portMap[$i] ?? [];
            $maxLeafHop = $leaves ? max(array_column($leaves, 'leaf_hop')) : 0;
            $isReserved = ($maxLeafHop <= 1 && !empty($leaves));
            $road      = (!$isReserved && $leaves) ? ($leaves[0]['road'] ?? null) : null;
            $active    = !$isReserved && (bool)array_reduce(
                $leaves, fn ($c, $l) => $c || (bool)$l['is_active'], false
            );

            // Patch connection (connector splice to a device)
            $patch = $patchMap[$i] ?? null;
            $patchPortLabel = $patch
                ? (((int)$patch['device_port'] === 0) ? 'IN' : 'OUT ' . (int)$patch['device_port'])
                : null;

            // Background colour
            if ($isReserved) {
                $color = '#fbbf24'; // amber — reserved / not yet extended
            } elseif ($road && isset($roads[$road])) {
                $color = $palette[$roads[$road] % count($palette)];
            } else {
                $color = $emptyColor;
            }

            // Leaf closure labels (e.g. R01, R02) — used in tooltip
            $closureLabels = implode(', ', array_filter(
                array_unique(array_column($leaves, 'leaf_label'))
            ));
            $addr = trim($leaves[0]['street_address'] ?? '');

            // Cell label
            if ($isReserved) {
                $cellLabel = 'RSV';
            } elseif ($road && $road !== '—') {
                $cellLabel = $road;
            } else {
                $cellLabel = $closureLabels;
            }

            $isDeadEnd        = false;
            $hasCustomerPorts = false;

            // Full tooltip (HTML)
            $tipParts = ['<strong>F' . $i . '</strong>'];
            if ($isReserved) {
                $tipParts[] = h($closureLabels ?: 'junction') . ' <em>(reserved, not yet extended)</em>';
            } elseif (empty($leaves)) {
                $tipParts[] = '<em>(unconnected)</em>';
            } else {
                // Display rules:
                //  - Leaf is XXX1234 pattern → it IS the dist closure; show as italic header
                //    only (no customer ports mapped yet)
                //  - Leaf is L/R pattern → customer port; group under its 7-digit parent_label
                // Deduplicate by leaf_fosc_id first (multiple branches to same MST = show once)
                $seenFoscIds = [];
                $byDist      = [];  // dist_label => [leaf_row, ...] (null = dist-only, no ports)
                foreach ($leaves as $leaf) {
                    $foscId = $leaf['leaf_fosc_id'] ? (int)$leaf['leaf_fosc_id'] : null;
                    if ($foscId !== null && isset($seenFoscIds[$foscId])) continue;
                    if ($foscId !== null) $seenFoscIds[$foscId] = true;

                    $leafLabel = $leaf['leaf_label'] ?? '';
                    if (preg_match('/^[A-Z]{3}[0-9]{4}$/', $leafLabel)) {
                        // Terminal IS the dist closure — header only, no customer port row
                        $byDist[$leafLabel][] = null;
                    } else {
                        // Customer port (L/R) — group under 7-digit parent when present
                        $p       = $leaf['parent_label'] ?? '';
                        $distKey = preg_match('/^[A-Z]{3}[0-9]{4}$/', $p) ? $p : '';
                        $byDist[$distKey][] = $leaf;
                    }
                }
                $portsAdded = 0;
                foreach ($byDist as $distLabel => $distLeaves) {
                    $portLeaves = array_filter($distLeaves);
                    if (empty($portLeaves)) continue; // dead-end dist closure — no customer ports
                    if ($distLabel !== '') $tipParts[] = '<em>' . h($distLabel) . '</em>';
                    foreach ($portLeaves as $leaf) {
                        $lbl    = h($leaf['leaf_label'] ?? '?');
                        $foscId = isset($leaf['leaf_fosc_id']) ? (int)$leaf['leaf_fosc_id'] : 0;
                        $uuids  = $foscId ? ($assocByFoscId[$foscId] ?? []) : [];
                        if (empty($uuids)) {
                            $tipParts[] = $lbl . ': <em>(free)</em>';
                        } else {
                            foreach ($uuids as $uuid) {
                                $c    = $customerByDeviceId[$uuid] ?? null;
                                $info = $c
                                    ? h(implode(', ', array_filter([$c['name'], $c['addr']])))
                                    : '<em>(unknown)</em>';
                                $tipParts[] = $lbl . ': ' . $info;
                            }
                        }
                        $portsAdded++;
                    }
                }
                // All leaves are dead-end dist closures (no customer drops in DB yet)
                $isDeadEnd        = ($portsAdded === 0);
                $hasCustomerPorts = ($portsAdded > 0);
                if ($isDeadEnd && $road && $road !== '—') {
                    $tipParts[] = h($road) . ' <em>(not yet connected onward)</em>';
                }
            }
            if ($active) $tipParts[] = '<span class="text-success">&#10003; active</span>';
            if ($patch) {
                $pl = '&#8644; ' . h($patch['device_name']) . ' ' . h($patchPortLabel);
                if (trim($patch['notes'] ?? '') !== '') $pl .= ' (' . h($patch['notes']) . ')';
                $tipParts[] = $pl;
            }
            $tipHtml = implode('<br>', $tipParts);
            // X overlay rule: port has no useful connectivity AND no active signal AND not patched.
            // Active ports (laser present) and ports with customer-facing slots never get an X.
            $showX = !$active && !$patch && !$hasCustomerPorts;
        ?>
          <div class="fdc-port<?= $active ? ' fdc-active' : '' ?><?= $isReserved ? ' fdc-reserved' : '' ?><?= $isDeadEnd ? ' fdc-dead-end' : '' ?><?= $showX ? ' fdc-crossed' : '' ?><?= $patch ? ' fdc-patched' : '' ?>"
               style="background:<?= h($color) ?>"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               data-bs-html="true"
               title="<?= str_replace('"', '&quot;', $tipHtml) ?>">
            <span class="fdc-num"><?= $i ?></span>
            <?php if ($active): ?><span class="fdc-dot">●</span><?php endif; ?>
            <?php if ($cellLabel): ?><span class="fdc-lbl"><?= h($cellLabel) ?></span><?php endif; ?>
            <?php if ($patch): ?>
              <span class="fdc-patch-lbl">⇄ <?= h($patchPortLabel) ?></span>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
      <?php endforeach; ?>
      </div><!-- /d-flex -->

      <p class="text-muted small mt-3">
        <?= $totalFibers ?> ports
        &middot; <?= array_sum(array_column($roadSummary, 'active')) ?> active
        &middot; <?= $reservedCount ?> reserved
        &middot; <?= count($roads) ?> road section<?= count($roads) !== 1 ? 's' : '' ?>
      </p>

    <?php endif; ?>

    <?php
    return ob_get_clean();
});
