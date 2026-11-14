<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
require_once __DIR__ . '/span_merge.helper.php';
require_once __DIR__ . '/abandoned.helper.php';
// phpHelpers/closure.helper.php
// Helpers, constants, data-access functions, and POST handlers for the closure page.
// Included by pages/closure.php.

require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/upload.helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/timeline.helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/fiber_colors.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/splice_chart.helper.php';

// ── Constants ─────────────────────────────────────────────────────────────────

if (!defined('FOSC_STATUSES')) {
    define('FOSC_STATUSES', ['active', 'plan', 'under construction', 'retired']);
}

// Tinuum builds every slack loop to a standard 30 m coil. That cable is real
// length on the reel and on the route — it is just not in any span's geometry,
// so anything measuring a run has to add it back per loop passed. The BOM's
// per-closure overhead uses the same 30 m.
if (!defined('SLACK_LOOP_M')) {
    define('SLACK_LOOP_M', 30);
}

// ── URL helpers ───────────────────────────────────────────────────────────────

/**
 * Returns a small inline SVG broadcast icon used site-wide to indicate feed direction.
 * $size: pixel size of the icon (square). Used in HTML contexts.
 */
function feed_direction_icon_html(string $size = '14'): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 14 14" fill="none" aria-label="Feed">'
         . '<circle cx="3" cy="7" r="2" fill="#d97706"/>'
         . '<path d="M4.5,3.7 A4,4 0 0,1 4.5,10.3" stroke="#d97706" stroke-width="1.5" fill="none" opacity="0.9"/>'
         . '<path d="M6.5,1.5 A6,6 0 0,1 6.5,12.5" stroke="#d97706" stroke-width="1.5" fill="none" opacity="0.55"/>'
         . '</svg>';
}

function closure_url_build(array $params): string {
    // Always stay on the closure page of the front controller
    $params = array_merge(['page' => 'closure'], $params);
    return 'index.php?' . http_build_query($params);
}

// ── Validators ────────────────────────────────────────────────────────────────

function validate_splice_num(string $s): bool {
    return (bool)preg_match('/^([A-Z]{3}[0-9]{4}|[LR][0-9]{2})$/', $s);
}

function validate_status(string $s): bool {
    return in_array($s, FOSC_STATUSES, true);
}

function parse_coord(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [null, null, 'Coordinates required.'];

    // "lon,lat"
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $raw, $m)) {
        return [(float)$m[1], (float)$m[2], null]; // lon, lat
    }

    // "POINT(lon lat)"
    if (preg_match('/POINT\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/i', $raw, $m)) {
        return [(float)$m[1], (float)$m[2], null]; // lon, lat
    }

    return [null, null, "Coordinates must be in 'lon,lat' or 'POINT(lon lat)' format."];
}

// ── Formatters ────────────────────────────────────────────────────────────────

function status_badge_class(string $status): string {
    return match ($status) {
        'active'     => 'success',
        'plan'       => 'warning text-dark',
        'retired'    => 'secondary',
        default      => 'light text-dark',
    };
}

function parse_endpoint_key(string $ek): ?array {
    // expects "<span_id>-from" or "<span_id>-to"
    $ek = trim($ek);
    if ($ek === '' || strpos($ek, '-') === false) return null;
    [$sid, $end] = explode('-', $ek, 2);
    $sid = (int)$sid;
    if ($sid <= 0) return null;
    $end = ($end === 'from' || $end === 'to') ? $end : null;
    if (!$end) return null;
    return ['span_id' => $sid, 'end' => $end];
}

function bearing_to_compass(float $deg): string {
    $dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    return $dirs[(int)round($deg / 45) % 8];
}

/**
 * Four points, spelled out - the label a splicer wants on a cable block.
 * A closure rarely has two cables of the same size leaving the same way, so
 * "48F WEST" identifies one without the cable name taking up the header.
 */
function bearing_to_cardinal(float $deg): string {
    $dirs = ['NORTH', 'EAST', 'SOUTH', 'WEST'];
    return $dirs[(int)round($deg / 90) % 4];
}

function device_type_label(string $type, array $attrs): string {
    $outs      = isset($attrs['outs'])      ? (int)$attrs['outs']          : null;
    $ports     = isset($attrs['ports'])     ? (int)$attrs['ports']         : null;
    $connector = isset($attrs['connector']) ? (string)$attrs['connector']  : 'LC';
    $mode      = isset($attrs['mode'])      ? (string)$attrs['mode']       : '';
    return match($type) {
        'splitter'    => $outs  ? "1×{$outs} Splitter"         : 'Splitter',
        'oadm'        => 'OADM',
        'dwdm'        => $outs  ? "1×{$outs} DWDM MUX/DEMUX"  : 'DWDM MUX/DEMUX',
        'olt_pon'     => $outs && $outs > 1 ? "OLT PON ({$outs}-port)" : 'OLT PON',
        'sfp'         => $outs && $outs > 1 ? "SFP ({$outs}-port)"     : 'SFP',
        'patch_panel' => $ports ? "{$connector} {$ports}-port Panel" : "{$connector} Panel",
        'mpt'         => $mode === 'split'
                            ? ($ports ? "MPT 1×{$ports} splitter" : 'MPT splitter')
                            : ($ports ? "MPT ({$ports}-port)"      : 'MPT'),
        default       => ucfirst($type),
    };
}

function device_type_icon(string $type): string {
    // Returns inline SVG icon for a device type
    return match($type) {
        'splitter' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<line x1="1" y1="8" x2="5" y2="8"/>'
            .'<line x1="5" y1="8" x2="11" y2="3"/>'
            .'<line x1="5" y1="8" x2="11" y2="8"/>'
            .'<line x1="5" y1="8" x2="11" y2="13"/>'
            .'<circle cx="12" cy="3" r="1.5" fill="currentColor" stroke="none"/>'
            .'<circle cx="12" cy="8" r="1.5" fill="currentColor" stroke="none"/>'
            .'<circle cx="12" cy="13" r="1.5" fill="currentColor" stroke="none"/>'
            .'</svg>',
        'olt_pon' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<rect x="2" y="4" width="12" height="8" rx="1.5"/>'
            .'<line x1="5.5" y1="4" x2="5.5" y2="12" stroke-width="1"/>'
            .'<line x1="8" y1="4" x2="8" y2="12" stroke-width="1"/>'
            .'<line x1="10.5" y1="4" x2="10.5" y2="12" stroke-width="1"/>'
            .'</svg>',
        'sfp' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<rect x="1" y="5" width="11" height="6" rx="1.5"/>'
            .'<line x1="12" y1="8" x2="15" y2="8"/>'
            .'<circle cx="14" cy="8" r="1" fill="currentColor" stroke="none"/>'
            .'</svg>',
        'oadm' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<circle cx="8" cy="8" r="5"/>'
            .'<line x1="1" y1="8" x2="3" y2="8"/>'
            .'<line x1="13" y1="8" x2="15" y2="8"/>'
            .'<path d="M5.5 5.5 Q8 8 5.5 10.5"/>'
            .'<path d="M10.5 5.5 Q8 8 10.5 10.5"/>'
            .'</svg>',
        'dwdm' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<path d="M1 8 Q3.5 4 5 8 Q6.5 12 8 8 Q9.5 4 11 8 Q12.5 12 15 8"/>'
            .'</svg>',
        'patch_panel' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<rect x="1" y="5" width="14" height="6" rx="1"/>'
            .'<circle cx="4.5" cy="8" r="1.2" fill="currentColor" stroke="none"/>'
            .'<circle cx="8"   cy="8" r="1.2" fill="currentColor" stroke="none"/>'
            .'<circle cx="11.5" cy="8" r="1.2" fill="currentColor" stroke="none"/>'
            .'</svg>',
        'mpt' =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<rect x="2" y="2" width="9" height="12" rx="1.5"/>'
            .'<line x1="1" y1="5" x2="2" y2="5"/>'
            .'<circle cx="13" cy="4.5" r="1.1" fill="currentColor" stroke="none"/>'
            .'<circle cx="13" cy="8"   r="1.1" fill="currentColor" stroke="none"/>'
            .'<circle cx="13" cy="11.5" r="1.1" fill="currentColor" stroke="none"/>'
            .'<line x1="11" y1="4.5" x2="12" y2="4.5"/>'
            .'<line x1="11" y1="8"   x2="12" y2="8"/>'
            .'<line x1="11" y1="11.5" x2="12" y2="11.5"/>'
            .'</svg>',
        default =>
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            .'<rect x="3" y="3" width="10" height="10" rx="2"/>'
            .'</svg>',
    };
}

// $lo = lower(int4range) inclusive, $hi_excl = upper(int4range) exclusive
function device_port_label(int $lo, int $hi_excl): string {
    $hi = $hi_excl - 1;
    if ($lo === 0 && $hi === 0) return 'IN';
    if ($lo === $hi)             return 'OUT ' . $lo;
    return 'OUT ' . $lo . '–' . $hi;
}

// ── Data access ───────────────────────────────────────────────────────────────

function fetch_span_endpoints_for_fosc(PDO $pdo, int $foscId): array {
    // bearing_deg: direction the cable runs away from this FOSC, measured
    // clockwise from true north, sampled 200 m along the route (or to the far
    // end for shorter spans).
    //
    // Not the first few metres, which give the departure off the pole rather
    // than a direction, and not end to end either: the 96F out of SAL1201 runs
    // 300 m east along Main Street then turns north up Fredericton Road, so end
    // to end it reads north when the cable leaving that closure is plainly the
    // east one. 200 m is far enough out to be the run and near enough to still
    // be this closure's cable.
    //
    // Cast to geography. ST_Azimuth on a 4326 geometry is planar over degrees,
    // and a degree of longitude at 46 N is 0.69 of a degree of latitude, so
    // every bearing came out pulled toward north/south by up to ~20 degrees.
    $st = $pdo->prepare("
      SELECT
        ep.endpoint_key,
        ep.cable_span_id,
        ep.\"end\"       AS ep_end,
        ep.fosc_id,
        ep.fosc_label,
        far_ep.fosc_label AS far_fosc_label,
        c.name          AS cable_name,
        c.fibre_count,
        -- Sample 30 m along the span from the FOSC end (or to midpoint for
        -- spans shorter than 60 m) so the bearing reflects the cable's actual
        -- departure direction rather than just the first/last tiny segment.
        CASE
          WHEN g.geom IS NULL OR ST_NPoints(g.geom) < 2 THEN NULL
          WHEN ep.\"end\" = 'from' THEN
            DEGREES(ST_Azimuth(
              ST_StartPoint(g.geom)::geography,
              ST_LineInterpolatePoint(g.geom,
                LEAST(200.0 / GREATEST(g.len_m, 1.0), 1.0))::geography))
          ELSE
            DEGREES(ST_Azimuth(
              ST_EndPoint(g.geom)::geography,
              ST_LineInterpolatePoint(g.geom,
                GREATEST(1.0 - 200.0 / GREATEST(g.len_m, 1.0), 0.0))::geography))
        END AS bearing_deg,
        round(g.len_m)::int AS span_len_m,
        -- Aggregate tag names for this span
        (SELECT string_agg(t.name, ', ' ORDER BY t.name)
           FROM app.cable_span_tag cst
           JOIN app.span_tag t ON t.span_tag_id = cst.span_tag_id
          WHERE cst.cable_span_id = ep.cable_span_id
        ) AS tag_names,
        -- Far-end connection (single-fiber cables only — see LATERAL below)
        fconn.o_target    AS far_o_target,
        o_c.name          AS far_o_cable_name,
        lower(fconn.o_range) AS far_o_lo,
        upper(fconn.o_range) AS far_o_hi_excl,
        o_dev.name        AS far_o_device_name,
        o_dev.device_type AS far_o_device_type,
        o_dev.attrs::text AS far_o_device_attrs
      FROM app.v_span_endpoints ep
      JOIN app.cable_span cs ON cs.cable_span_id = ep.cable_span_id
      JOIN app.cable c       ON c.cable_id = cs.cable_id
      -- Far-end endpoint on the same span (opposite end = other closure)
      LEFT JOIN app.v_span_endpoints far_ep
        ON far_ep.cable_span_id = ep.cable_span_id
       AND far_ep.\"end\"::text <> ep.\"end\"::text
      -- Pre-compute merged geometry and its metric length once per span
      CROSS JOIN LATERAL (
        SELECT
          COALESCE(cs.geom_snap, cs.geom) AS geom,
          ST_Length(COALESCE(cs.geom_snap, cs.geom)::geography) AS len_m
      ) g
      -- Far-end connection: for a single-fiber (patch) cable, find what the
      -- OTHER end of the span is spliced to at its far closure.  This is the
      -- detail that distinguishes otherwise-identical patch cords in the
      -- endpoint picker.  Skipped for multi-fiber cables because the
      -- connection there depends on which fiber the user hasn't picked yet.
      LEFT JOIN LATERAL (
        SELECT
          CASE WHEN fj.a_target = 'cable_span' AND fj.a_span_id = ep.cable_span_id
                    AND fj.a_end::text <> ep.\"end\"::text
               THEN fj.b_target  ELSE fj.a_target  END AS o_target,
          CASE WHEN fj.a_target = 'cable_span' AND fj.a_span_id = ep.cable_span_id
                    AND fj.a_end::text <> ep.\"end\"::text
               THEN fj.b_span_id ELSE fj.a_span_id END AS o_id,
          CASE WHEN fj.a_target = 'cable_span' AND fj.a_span_id = ep.cable_span_id
                    AND fj.a_end::text <> ep.\"end\"::text
               THEN fj.b_range   ELSE fj.a_range   END AS o_range
        FROM app.splice_join fj
        WHERE c.fibre_count = 1
          AND fj.fosc_id = far_ep.fosc_id
          AND fj.retired_at IS NULL
          AND (
            (fj.a_target = 'cable_span' AND fj.a_span_id = ep.cable_span_id
                 AND fj.a_end::text <> ep.\"end\"::text)
            OR
            (fj.b_target = 'cable_span' AND fj.b_span_id = ep.cable_span_id
                 AND fj.b_end::text <> ep.\"end\"::text)
          )
        ORDER BY fj.is_plan ASC, fj.splice_join_id ASC
        LIMIT 1
      ) fconn ON TRUE
      LEFT JOIN app.cable_span o_cs
        ON fconn.o_target = 'cable_span' AND o_cs.cable_span_id = fconn.o_id
      LEFT JOIN app.cable o_c ON o_c.cable_id = o_cs.cable_id
      LEFT JOIN app.fosc_device o_dev
        ON fconn.o_target = 'fosc_device' AND o_dev.fosc_device_id = fconn.o_id
      WHERE ep.fosc_id = :fid
        AND " . abandoned_span_sql('cs') . "          -- a span of an abandoned work order is not a cable here
      ORDER BY c.name, ep.cable_span_id, ep.\"end\"
    ");
    $st->execute([':fid' => $foscId]);
    return $st->fetchAll() ?: [];
}

/**
 * Builds a short description of what a span endpoint's FAR end is connected to
 * (resolved by fetch_span_endpoints_for_fosc for single-fiber cables only).
 * e.g. "Generic 6F ADSS F3" or "OLT-8 PON port 3 OUT 1". Empty when unknown.
 */
function far_conn_label(array $ep): string {
    $target = $ep['far_o_target'] ?? null;
    if ($target === 'cable_span' && !empty($ep['far_o_cable_name'])) {
        $lo  = (int)$ep['far_o_lo'];
        $hi  = (int)$ep['far_o_hi_excl'] - 1;
        $fib = ($lo === $hi) ? "F{$lo}" : "F{$lo}–{$hi}";
        return $ep['far_o_cable_name'] . ' ' . $fib;
    }
    if ($target === 'fosc_device') {
        $attrs = json_decode($ep['far_o_device_attrs'] ?? '{}', true) ?: [];
        $name  = trim((string)($ep['far_o_device_name'] ?? ''));
        $label = $name !== ''
            ? $name
            : device_type_label((string)($ep['far_o_device_type'] ?? ''), $attrs);
        $port  = device_port_label((int)($ep['far_o_lo'] ?? 0), (int)($ep['far_o_hi_excl'] ?? 0));
        return trim($label . ' ' . $port);
    }
    return '';
}

/**
 * Render <optgroup>-grouped <option> elements for a span-endpoint select.
 *
 * Options read the way the cable blocks and the splice instructions do -
 * "96F SOUTH → SAL1201" - so the same phrase identifies a cable everywhere on the
 * page. The optgroup still carries the cable name for the case the short label
 * cannot separate, and the exact bearing sits in the option's title.
 */
function render_endpoint_options(array $endpoints, string $selected = ''): string {
    $grouped = [];
    foreach ($endpoints as $ep) {
        $label = h($ep['cable_name']) . ' (' . (int)$ep['fibre_count'] . 'F)';
        $grouped[$label][] = $ep;
    }
    $html = '';
    foreach ($grouped as $groupLabel => $eps) {
        $html .= '<optgroup label="' . $groupLabel . '">';
        foreach ($eps as $ep) {
            $deg     = $ep['bearing_deg'] !== null ? (float)$ep['bearing_deg'] : null;
            $compass = $deg !== null
                ? bearing_to_compass($deg) . ' ' . round($deg) . '°'
                : '?';
            $farLabel = $ep['far_fosc_label'] ?? '';
            $farConn  = far_conn_label($ep);
            $tagStr  = trim($ep['tag_names'] ?? '');
            $sel = ($ep['endpoint_key'] === $selected) ? ' selected' : '';
            $short = (int)$ep['fibre_count'] . 'F'
                   . ($deg !== null ? ' ' . bearing_to_cardinal($deg) : '');
            $title = trim(implode(' · ', array_filter([
                $ep['cable_name'] ?? '',
                $deg !== null ? $compass : '',
                isset($ep['span_len_m']) ? ((int)$ep['span_len_m'] . ' m') : '',
            ])));
            $html .= '<option value="' . h($ep['endpoint_key']) . '"' . $sel
                   . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>'
                   . ($tagStr !== '' ? h($tagStr) . ' · ' : '')
                   . h($short)
                   . ($farLabel !== '' ? ' &rarr; ' . h($farLabel) : '')
                   . ($farConn !== '' ? ' &middot; ' . h($farConn) : '')
                   . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function fetch_splices_at_fosc(PDO $pdo, int $foscId): array {
    $st = $pdo->prepare("
    SELECT
      j.splice_join_id,
      j.a_target,
      j.a_span_id,
      j.a_end,
      ab.lo AS a_fiber_lo,
      ab.hi AS a_fiber_hi,
      j.b_target,
      j.b_span_id,
      j.b_end,
      bb.lo AS b_fiber_lo,
      bb.hi AS b_fiber_hi,
      j.join_kind,
      j.notes,
      j.is_plan,
      -- far-end FOSC (opposite end of the span from where the splice sits)
      a_far.fosc_id    AS a_far_fosc_id,
      a_far.fosc_label AS a_far_fosc_label,
      b_far.fosc_id    AS b_far_fosc_id,
      b_far.fosc_label AS b_far_fosc_label,
      -- cable name for span endpoints
      ac.name          AS a_cable_name,
      ac.fibre_count   AS a_fibre_count,
      bc.name          AS b_cable_name,
      bc.fibre_count   AS b_fibre_count,
      a_dev.name          AS a_device_name,
      a_dev.device_type   AS a_device_type,
      a_dev.attrs::text   AS a_device_attrs,
      b_dev.name          AS b_device_name,
      b_dev.device_type   AS b_device_type,
      b_dev.attrs::text   AS b_device_attrs,
      -- span tags
      (SELECT string_agg(t.name, ',' ORDER BY t.name)
         FROM app.cable_span_tag cst
         JOIN app.span_tag t ON t.span_tag_id = cst.span_tag_id
        WHERE j.a_target = 'cable_span' AND cst.cable_span_id = j.a_span_id
      ) AS a_tag_names,
      (SELECT string_agg(t.name, ',' ORDER BY t.name)
         FROM app.cable_span_tag cst
         JOIN app.span_tag t ON t.span_tag_id = cst.span_tag_id
        WHERE j.b_target = 'cable_span' AND cst.cable_span_id = j.b_span_id
      ) AS b_tag_names
    FROM app.splice_join j
    CROSS JOIN LATERAL app.int_range_bounds_display(j.a_range) ab
    CROSS JOIN LATERAL app.int_range_bounds_display(j.b_range) bb
    -- far-end: opposite end of the span (end <> splice end)
    LEFT JOIN app.v_span_endpoints a_far
      ON j.a_target = 'cable_span'
     AND a_far.cable_span_id = j.a_span_id
     AND a_far.\"end\"::text <> j.a_end::text
    LEFT JOIN app.v_span_endpoints b_far
      ON j.b_target = 'cable_span'
     AND b_far.cable_span_id = j.b_span_id
     AND b_far.\"end\"::text <> j.b_end::text
    -- cable info for span endpoints
    LEFT JOIN app.cable_span acs
      ON j.a_target = 'cable_span' AND acs.cable_span_id = j.a_span_id
    LEFT JOIN app.cable ac ON ac.cable_id = acs.cable_id
    LEFT JOIN app.cable_span bcs
      ON j.b_target = 'cable_span' AND bcs.cable_span_id = j.b_span_id
    LEFT JOIN app.cable bc ON bc.cable_id = bcs.cable_id
    LEFT JOIN app.fosc_device a_dev
      ON j.a_target = 'fosc_device'
     AND a_dev.fosc_device_id = j.a_span_id
    LEFT JOIN app.fosc_device b_dev
      ON j.b_target = 'fosc_device'
     AND b_dev.fosc_device_id = j.b_span_id
    WHERE j.fosc_id = :fid
      AND j.retired_at IS NULL
      -- POP patch cables (device↔device connector joins) live on the POP diagram, not here.
      -- Cable→panel terminations (cable_span↔fosc_device) and real splices stay.
      AND NOT (j.join_kind = 'connector'
               AND j.a_target = 'fosc_device' AND j.b_target = 'fosc_device')
      -- a splice onto a span of an abandoned work order does not exist for the page
      AND " . abandoned_splice_sql('j') . "
    ORDER BY j.splice_join_id DESC
  ");
    $st->execute([':fid' => $foscId]);
    return $st->fetchAll() ?: [];
}

/**
 * For a given closure, return a nested map of which (span_id → fiber_num) pairs
 * are "live" — i.e., their light path reaches an OLT_PON, SFP, or DWDM device.
 * Traces from both ends of each span so direction doesn't matter.
 * Returns: array<int span_id, array<int fiber_num, true>>
 */
function fetch_live_fibers_at_fosc(PDO $pdo, int $foscId): array
{
    try {
        $st = $pdo->prepare("
            WITH splice_fibers AS (
                SELECT DISTINCT j.a_span_id AS span_id, g.n AS fiber_num
                FROM app.splice_join j
                CROSS JOIN LATERAL generate_series(lower(j.a_range), upper(j.a_range)-1) g(n)
                WHERE j.fosc_id = :fid AND j.a_target = 'cable_span' AND NOT j.is_plan AND j.retired_at IS NULL
                UNION
                SELECT DISTINCT j.b_span_id, g.n
                FROM app.splice_join j
                CROSS JOIN LATERAL generate_series(lower(j.b_range), upper(j.b_range)-1) g(n)
                WHERE j.fosc_id = :fid AND j.b_target = 'cable_span' AND NOT j.is_plan AND j.retired_at IS NULL
            ),
            live AS (
                SELECT DISTINCT sf.span_id, sf.fiber_num
                FROM splice_fibers sf
                WHERE
                    EXISTS (
                        SELECT 1 FROM app.trace_light_path(
                            sf.span_id, sf.fiber_num::integer, 'from'::app.span_end, false, 50)
                        WHERE device_type IN ('olt_pon', 'sfp', 'dwdm')
                    )
                    OR EXISTS (
                        SELECT 1 FROM app.trace_light_path(
                            sf.span_id, sf.fiber_num::integer, 'to'::app.span_end, false, 50)
                        WHERE device_type IN ('olt_pon', 'sfp', 'dwdm')
                    )
            )
            SELECT span_id, fiber_num FROM live
        ");
        $st->execute([':fid' => $foscId]);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        error_log("fetch_live_fibers_at_fosc: {$e->getMessage()}");
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['span_id']][(int)$row['fiber_num']] = true;
    }
    return $map;
}

/**
 * Returns retired splice snapshots for a closure, grouped by retired_at timestamp.
 * Each group represents one historical "version" of the splice config.
 * Returns array of ['retired_at'=>string, 'splice_count'=>int, 'splices'=>array[]]
 */
function fetch_splice_history_at_fosc(PDO $pdo, int $foscId): array
{
    try {
        $st = $pdo->prepare("
            SELECT
                j.retired_at::text AS retired_at,
                COUNT(*) AS splice_count,
                json_agg(json_build_object(
                    'id',        j.splice_join_id,
                    'a_target',  j.a_target,
                    'a_cable',   ac.name,
                    'a_device',  a_dev.name,
                    'a_lo',      ab.lo,
                    'a_hi',      ab.hi - 1,   -- inclusive for display (ab.hi is exclusive)
                    'b_target',  j.b_target,
                    'b_cable',   bc.name,
                    'b_device',  b_dev.name,
                    'b_lo',      bb.lo,
                    'b_hi',      bb.hi - 1,
                    'join_kind', j.join_kind,
                    'is_plan',   j.is_plan
                ) ORDER BY j.splice_join_id) AS splices_json
            FROM app.splice_join j
            CROSS JOIN LATERAL app.int_range_bounds_display(j.a_range) ab
            CROSS JOIN LATERAL app.int_range_bounds_display(j.b_range) bb
            LEFT JOIN app.cable_span acs ON j.a_target = 'cable_span' AND acs.cable_span_id = j.a_span_id
            LEFT JOIN app.cable ac ON ac.cable_id = acs.cable_id
            LEFT JOIN app.cable_span bcs ON j.b_target = 'cable_span' AND bcs.cable_span_id = j.b_span_id
            LEFT JOIN app.cable bc ON bc.cable_id = bcs.cable_id
            LEFT JOIN app.fosc_device a_dev ON j.a_target = 'fosc_device' AND a_dev.fosc_device_id = j.a_span_id
            LEFT JOIN app.fosc_device b_dev ON j.b_target = 'fosc_device' AND b_dev.fosc_device_id = j.b_span_id
            WHERE j.fosc_id = :fid AND j.retired_at IS NOT NULL
            GROUP BY j.retired_at
            ORDER BY j.retired_at DESC
            LIMIT 20
        ");
        $st->execute([':fid' => $foscId]);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        error_log("fetch_splice_history_at_fosc: {$e->getMessage()}");
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $splices = json_decode($row['splices_json'] ?? '[]', true) ?: [];
        $result[] = [
            'retired_at'   => $row['retired_at'],
            'splice_count' => (int)$row['splice_count'],
            'splices'      => $splices,
        ];
    }
    return $result;
}

function fetch_closure_types(PDO $pdo): array {
    return $pdo->query("
      SELECT closure_type_id, name
        FROM app.closure_type
    ORDER BY name ASC
    ")->fetchAll();
}

function fetch_fosc_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
      SELECT f.fosc_id, f.splice_num, f.status, f.notes,
             f.created_at, f.updated_at,
             topology.ST_X(f.location) AS lon, topology.ST_Y(f.location) AS lat,
             f.closure_type_id, ct.name AS closure_type,
             f.kind, f.attrs::text AS attrs_json,
             f.pop_id, p.name AS pop_name, f.enclosure_rating
        FROM app.fosc f
        JOIN app.closure_type ct ON ct.closure_type_id = f.closure_type_id
        LEFT JOIN app.pop p ON p.pop_id = f.pop_id
       WHERE f.fosc_id = :id
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function fetch_fosc_id_by_splice(PDO $pdo, string $splice): ?int {
    $st = $pdo->prepare("
      SELECT fosc_id
        FROM app.fosc
       WHERE splice_num = UPPER(:s)
       LIMIT 1
    ");
    $st->execute([':s' => $splice]);
    $row = $st->fetch();
    return $row ? (int)$row['fosc_id'] : null;
}

function fetch_timeline_for_fosc(PDO $pdo, int $foscId, int $limit = 200): array {
    $st = $pdo->prepare("
      SELECT
        e.timeline_entry_id, e.body_text, e.created_at, e.updated_at,
        CASE WHEN e.geom IS NULL THEN NULL ELSE ST_AsGeoJSON(e.geom) END AS geom_geojson,
        COALESCE((
          SELECT string_agg(m.timeline_media_id::text, ',')
            FROM app.timeline_media m
           WHERE m.timeline_entry_id = e.timeline_entry_id
        ), '') AS media_ids
      FROM app.timeline_entry e
      WHERE e.fosc_id = :fid
      ORDER BY e.updated_at DESC
      LIMIT :lim
    ");
    $st->bindValue(':fid', $foscId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['media_ids'] = $r['media_ids'] !== '' ? explode(',', $r['media_ids']) : [];
    }
    return $rows;
}

/**
 * Next free "PLC-N" name for a splitter in this closure.
 *
 * Takes the lowest unused number rather than max+1, so removing PLC-1 from a tray
 * and fitting a new one puts it back in slot 1 instead of drifting upwards.
 */
function closure_next_plc_name(PDO $pdo, int $foscId): string
{
    $st = $pdo->prepare("
        SELECT name FROM app.fosc_device
         WHERE fosc_id = :fid AND device_type = 'splitter' AND name ~ '^PLC-[0-9]+$'
    ");
    $st->execute([':fid' => $foscId]);

    $used = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $used[(int)substr((string)$name, 4)] = true;
    }
    $n = 1;
    while (isset($used[$n])) $n++;
    return 'PLC-' . $n;
}

function fetch_devices_at_fosc(PDO $pdo, int $foscId): array {
    $st = $pdo->prepare("
        SELECT fosc_device_id, device_type, name, attrs::text AS attrs_json, notes
          FROM app.fosc_device
         WHERE fosc_id = :fid
         ORDER BY fosc_device_id
    ");
    $st->execute([':fid' => $foscId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$r) {
        $r['attrs'] = json_decode($r['attrs_json'] ?? '{}', true) ?: [];
        unset($r['attrs_json']);
    }
    return $rows;
}

/**
 * Devices that can be spliced to at this closure: the closure's own devices PLUS
 * the active devices homed at this closure's POP (if it belongs to one). A POP-homed
 * OLT can therefore be spliced to a cable landing in any closure of that POP.
 * The null-safe sub-select yields no extra rows when the closure has no POP.
 */
function fetch_devices_available_at_fosc(PDO $pdo, int $foscId): array {
    $st = $pdo->prepare("
        SELECT fosc_device_id, device_type, name, attrs::text AS attrs_json, notes,
               (pop_id IS NOT NULL) AS is_pop_device
          FROM app.fosc_device
         WHERE fosc_id = :fid
            OR pop_id = (SELECT pop_id FROM app.fosc WHERE fosc_id = :fid2)
         ORDER BY (pop_id IS NOT NULL), fosc_device_id
    ");
    $st->execute([':fid' => $foscId, ':fid2' => $foscId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$r) {
        $r['attrs'] = json_decode($r['attrs_json'] ?? '{}', true) ?: [];
        unset($r['attrs_json']);
    }
    return $rows;
}

// ── Connection-diagram payload ────────────────────────────────────────────────

/**
 * Build the JSON-able payload consumed by _renderSpliceDiagram() (see
 * /js/splice_diagram.helper.js). Keyed purely by cable-span id and fosc_device id,
 * so it is closure/POP-agnostic — the closure page calls it per is_plan split, the
 * POP detail page calls it once with patch-cable (connector) joins aggregated across
 * the member closures.
 *
 * @param array       $splices       splice_join rows (fetch_splices_at_fosc shape)
 * @param array       $spanEndpoints span endpoints (fetch_span_endpoints_for_fosc shape)
 * @param array       $devices       devices (fetch_devices_available_at_fosc shape; attrs already decoded)
 * @param array       $liveMap       cable_span_id => {fiber_num: true} (empty for POP)
 * @param string|null $feedKey       explicit feed-direction key, or null
 */
function build_diagram_payload(array $splices, array $spanEndpoints, array $devices,
                               array $liveMap = [], ?string $feedKey = null): array
{
    $dgSplices = [];
    foreach ($splices as $sj) {
        $dgSplices[] = [
            'id'        => (int)$sj['splice_join_id'],
            'join_kind' => $sj['join_kind'] ?? 'single',
            'a_target'  => $sj['a_target'],
            'a_id'      => (int)$sj['a_span_id'],
            'a_end'     => $sj['a_end'],
            'a_lo'      => (int)$sj['a_fiber_lo'],
            'a_hi'      => (int)$sj['a_fiber_hi'] - 1,
            'b_target'  => $sj['b_target'],
            'b_id'      => (int)$sj['b_span_id'],
            'b_end'     => $sj['b_end'],
            'b_lo'      => (int)$sj['b_fiber_lo'],
            'b_hi'      => (int)$sj['b_fiber_hi'] - 1,
            'notes'     => (string)($sj['notes'] ?? ''),
            'plan'      => ($sj['is_plan'] === 't' || $sj['is_plan'] === true),
        ];
    }

    $dgSpans = [];
    foreach ($spanEndpoints as $ep) {
        $deg = $ep['bearing_deg'] !== null ? (float)$ep['bearing_deg'] : null;
        $bearingText = $deg !== null
            ? bearing_to_compass($deg) . ' ' . round($deg) . '°'
            : null;
        $spanLive = $liveMap[(int)$ep['cable_span_id']] ?? [];
        // What the block shows: fibre count and the way the cable runs. Cable
        // type, far end and exact bearing are hover-only - they are what you
        // check once, not what you read while splicing.
        $fc    = (int)$ep['fibre_count'];
        $short = $fc . 'F' . ($deg !== null ? ' ' . bearing_to_cardinal($deg) : '');
        $detail = array_filter([
            $ep['cable_name'],
            $ep['far_fosc_label'] ? "\u{2192} " . $ep['far_fosc_label'] : null,
            $bearingText,
            isset($ep['span_len_m']) ? ((int)$ep['span_len_m'] . ' m') : null,
        ]);
        $dgSpans[$ep['cable_span_id'].'-'.$ep['ep_end']] = [
            'cable_name'     => $ep['cable_name'],
            'fibre_count'    => $fc,
            'far_fosc_label' => $ep['far_fosc_label'] ?? null,
            'bearing_text'   => $bearingText,
            'short_label'    => $short,
            'detail'         => implode(" \u{b7} ", $detail),
            'live_fibers'    => empty($spanLive) ? (object)[] : $spanLive,
        ];
    }

    $dgDevs = [];
    foreach ($devices as $dev) {
        $outs         = isset($dev['attrs']['outs'])   ? (int)$dev['attrs']['outs']  : 0;
        $isOltPon     = in_array($dev['device_type'], ['olt_pon', 'sfp'], true);
        $isPatchPanel = $dev['device_type'] === 'patch_panel';
        $isMpt        = $dev['device_type'] === 'mpt';
        $mptPassive   = $isMpt && (($dev['attrs']['mode'] ?? '') !== 'split');
        if ($isPatchPanel) {
            $outs = isset($dev['attrs']['ports']) ? (int)$dev['attrs']['ports'] : 0;
        }
        if ($isMpt) {
            // MPT carries a port count in attrs.ports (passive = 1:1, split = 1×N splitter).
            $outs = isset($dev['attrs']['ports']) ? (int)$dev['attrs']['ports'] : 0;
        }
        $dgDevs[(int)$dev['fosc_device_id']] = [
            'name'          => $dev['name'],
            'type_label'    => device_type_label($dev['device_type'], $dev['attrs']),
            'outs'          => $isOltPon ? max(1, $outs) : $outs,
            'is_olt_pon'    => $isOltPon,
            'is_patch_panel'=> $isPatchPanel,
            'is_mpt'        => $isMpt,
            'mpt_passive'   => $mptPassive,
        ];
    }

    return ['splices' => $dgSplices, 'spans' => $dgSpans, 'devs' => $dgDevs, 'feedKey' => $feedKey];
}

// ── Timeline wrapper ──────────────────────────────────────────────────────────

function timeline_fosc(PDO $pdo, int $foscId): array
{
    $rows = timeline_fetch_entries_for_parent($pdo, 'fosc', (string)$foscId);
    ob_start();
    timeline_render_block($pdo, $rows);
    return ['html' => ob_get_clean(), 'count' => count($rows)];
}

// ── POST handlers ─────────────────────────────────────────────────────────────

function handle_add_fosc(PDO $pdo, string $csrf, array $post): array {
    $errors = [];
    if (!hash_equals($csrf, $post['csrf'] ?? '')) $errors[] = 'Bad CSRF token.';

    $splice  = strtoupper(trim($post['splice_num'] ?? ''));
    $status  = trim($post['status'] ?? 'plan');
    $typeId  = (int)($post['closure_type_id'] ?? 0);
    $coord   = trim($post['coord'] ?? '');
    $notes   = trim($post['notes'] ?? '');

    if (!validate_splice_num($splice)) $errors[] = "Splice number must be ABC1234.";
    if (!validate_status($status))     $errors[] = "Invalid status.";
    if ($typeId <= 0)                  $errors[] = "Select a closure type.";

    [$lon, $lat, $cerr] = parse_coord($coord);
    if ($cerr) $errors[] = $cerr;

    if ($errors) return [null, $errors];

    try {
        $st = $pdo->prepare("
          INSERT INTO app.fosc (splice_num, status, location, closure_type_id, notes)
          VALUES (:splice, :status, ST_SetSRID(ST_MakePoint(:lon::double precision,:lat::double precision),4326), :type, :notes)
          RETURNING fosc_id
        ");
        $st->execute([
            ':splice' => $splice,
            ':status' => $status,
            ':lon'    => $lon,
            ':lat'    => $lat,
            ':type'   => $typeId,
            ':notes'  => $notes !== '' ? $notes : null,
        ]);
        return [(int)$st->fetchColumn(), null];
    } catch (Throwable $e) {
        return [null, ["Database error: " . $e->getMessage()]];
    }
}

function handle_edit_fosc(PDO $pdo, string $csrf, array $post): array {
    $errors = [];
    if (!hash_equals($csrf, $post['csrf'] ?? '')) $errors[] = 'Bad CSRF token.';

    $id      = (int)($post['fosc_id'] ?? 0);
    $splice  = strtoupper(trim($post['splice_num'] ?? ''));
    $status  = trim($post['status'] ?? 'plan');
    $typeId  = (int)($post['closure_type_id'] ?? 0);
    $coord   = trim($post['coord'] ?? '');
    $notes   = trim($post['notes'] ?? '');
    $kind    = trim($post['kind'] ?? 'closure');
    $streetAddress = trim($post['street_address'] ?? '');
    $popId   = (int)($post['pop_id'] ?? 0);            // 0 = no POP
    $rating  = trim($post['enclosure_rating'] ?? 'outside');

    $validKinds = ['closure', 'mst', 'terminal', 'cabinet', 'optitap'];
    if ($id <= 0)                         $errors[] = "Invalid closure id.";
    if (!validate_splice_num($splice))    $errors[] = "Splice number must be ABC1234.";
    if (!validate_status($status))        $errors[] = "Invalid status.";
    if ($typeId <= 0)                     $errors[] = "Select a closure type.";
    if (!in_array($kind, $validKinds, true)) $errors[] = "Invalid kind.";
    if (!in_array($rating, ['inside', 'outside'], true)) $errors[] = "Invalid enclosure rating.";

    [$lon, $lat, $cerr] = parse_coord($coord);
    if ($cerr) $errors[] = $cerr;

    if ($errors) return [null, $errors];

    $attrsPatch = json_encode(
        $streetAddress !== '' ? ['street_address' => $streetAddress] : ['street_address' => null]
    );

    try {
        $st = $pdo->prepare("
          UPDATE app.fosc
             SET splice_num       = :splice,
                 status           = :status,
                 kind             = :kind::app.fosc_kind,
                 location         = ST_SetSRID(ST_MakePoint(:lon::double precision,:lat::double precision), 4326),
                 closure_type_id  = :type,
                 notes            = :notes,
                 pop_id           = :pop_id,
                 enclosure_rating = :rating::app.enclosure_rating,
                 attrs            = attrs || :attrs_patch::jsonb,
                 updated_at       = now()
           WHERE fosc_id = :id
          RETURNING fosc_id
        ");
        $st->bindValue(':splice', $splice, PDO::PARAM_STR);
        $st->bindValue(':status', $status, PDO::PARAM_STR);
        $st->bindValue(':kind', $kind, PDO::PARAM_STR);
        $st->bindValue(':lon', $lon);
        $st->bindValue(':lat', $lat);
        $st->bindValue(':type', $typeId, PDO::PARAM_INT);
        $st->bindValue(':notes', $notes !== '' ? $notes : null,
                       $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':pop_id', $popId > 0 ? $popId : null,
                       $popId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':rating', $rating, PDO::PARAM_STR);
        $st->bindValue(':attrs_patch', $attrsPatch, PDO::PARAM_STR);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        return [(int)$st->fetchColumn(), null];
    } catch (Throwable $e) {
        return [null, ["Update failed: " . $e->getMessage()]];
    }
}

/** Turn a device-save constraint violation into something a human can act on. */
function closure_device_error(Throwable $e): string
{
    $m = $e->getMessage();
    if (str_contains($m, 'chk_closure_device_passive')) {
        return 'A closure can only hold passive gear — splitter, OADM, patch panel or MPT. '
             . 'Active devices (DWDM, OLT PON, SFP) have to be homed at a POP.';
    }
    if (str_contains($m, 'chk_splitter_outs'))     return 'A splitter needs an output count between 2 and 128.';
    if (str_contains($m, 'chk_mpt_attrs'))         return 'An MPT needs mode passive or split, and 1-128 ports.';
    if (str_contains($m, 'chk_patch_panel_ports')) return 'A patch panel needs a port count between 1 and 1000.';
    return 'Insert failed: ' . $m;
}

/**
 * Fibre / port availability for the splice-modal range pickers.
 *
 * Generalises the inline `usedByEp` map that used to live in the Patch Cable modal so
 * every picker on the page can share one payload: how many fibres (or ports) an
 * endpoint has, which are already spliced at this closure, and what they run to.
 *
 * Built for both layers, because a conflict is per-layer: app.splice_join_no_double_use()
 * compares against live joins with the same is_plan, so a plan splice is blocked by the
 * plan and a built one by the field. The top level is the built layer and 'plan' holds
 * the other; the picker swaps between them with the modal's Layer radio. Blocking a plan
 * route on built fibre would stop you planning over fibre that is still free, and telling
 * you a fibre is free when the plan already claims it is how you write a splice the
 * database then refuses.
 *
 * @return array{endpoints: array<string, array>, devices: array<string, array>,
 *               plan: array{endpoints: array<string, array>, devices: array<string, array>}}
 */
function splice_picker_data(array $splicesHere, array $spanEndpoints, array $devices): array
{
    // Short description of the OTHER side of a splice, for the "already spliced to …"
    // tooltip on a blocked cell.
    $otherSide = function (array $sj, string $side): string {
        $p = $side . '_';
        $lo = (int)$sj[$p . 'fiber_lo'];
        $hi = (int)$sj[$p . 'fiber_hi'] - 1;      // stored range is half-open
        $span = $lo === $hi ? (string)$lo : $lo . '–' . $hi;
        if ($sj[$p . 'target'] === 'cable_span') {
            $name = $sj[$p . 'cable_name'] ?: 'cable';
            $far  = $sj[$p . 'far_fosc_label'] ?? '';
            return trim($name . ' F' . $span . ($far !== '' ? ' → ' . $far : ''));
        }
        return trim(($sj[$p . 'device_name'] ?: 'device') . ' port ' . $span);
    };

    // One layer's worth of availability. $wantPlan picks which splices count.
    $build = function (bool $wantPlan) use ($splicesHere, $spanEndpoints, $devices, $otherSide): array {
    $endpoints = [];
    foreach ($spanEndpoints as $ep) {
        $endpoints[(string)$ep['endpoint_key']] = [
            'total'  => (int)$ep['fibre_count'],
            'used'   => [],
            'usedBy' => new stdClass(),   // json_encode as {} not [] when empty
        ];
    }

    $devs = [];
    foreach ($devices as $d) {
        $attrs = is_array($d['attrs'] ?? null) ? $d['attrs'] : [];
        $devs[(string)$d['fosc_device_id']] = [
            'ports'  => (int)($attrs['outs'] ?? $attrs['ports'] ?? 1),
            'used'   => [],
            'usedBy' => new stdClass(),
        ];
    }

    $usedBy = [];   // [bucket][key][n] = label
    $usedId = [];   // [bucket][key][n] = splice_join_id that claims it
    foreach ($splicesHere as $sj) {
        $isPlan = !empty($sj['is_plan']) && $sj['is_plan'] !== 'f';
        if ($isPlan !== $wantPlan) continue;
        foreach (['a', 'b'] as $side) {
            $other  = $side === 'a' ? 'b' : 'a';
            $isSpan = $sj[$side . '_target'] === 'cable_span';
            $key    = $isSpan
                ? (int)$sj[$side . '_span_id'] . '-' . $sj[$side . '_end']
                : (string)(int)$sj[$side . '_span_id'];   // device id shares the column
            $bucket = $isSpan ? 'endpoints' : 'devices';
            if (!isset(($bucket === 'endpoints' ? $endpoints : $devs)[$key])) continue;

            $label = $otherSide($sj, $other);
            for ($n = (int)$sj[$side . '_fiber_lo']; $n < (int)$sj[$side . '_fiber_hi']; $n++) {
                if ($bucket === 'endpoints') $endpoints[$key]['used'][] = $n;
                else                          $devs[$key]['used'][] = $n;
                $usedBy[$bucket][$key][$n] = $label;
                $usedId[$bucket][$key][$n] = (int)$sj['splice_join_id'];
            }
        }
    }

    foreach ($endpoints as $k => &$e) {
        $e['used']   = array_values(array_unique($e['used']));
        $e['usedBy'] = $usedBy['endpoints'][$k] ?? new stdClass();
        $e['usedId'] = $usedId['endpoints'][$k] ?? new stdClass();
    }
    unset($e);
    foreach ($devs as $k => &$d) {
        $d['used']   = array_values(array_unique($d['used']));
        $d['usedBy'] = $usedBy['devices'][$k] ?? new stdClass();
        $d['usedId'] = $usedId['devices'][$k] ?? new stdClass();
    }
    unset($d);

    return ['endpoints' => $endpoints, 'devices' => $devs];
    };

    $built = $build(false);
    $built['plan'] = $build(true);
    return $built;
}

/**
 * Parse the picker's "runs" field — "1:1,3:3,7:9" — into [[lo,hi], …], ascending and
 * merged. An empty field means the form is an older single-range one, so the caller's
 * lo/hi pair is used instead. Returns null if the field is malformed, which the caller
 * must treat as an error rather than quietly splicing a subset.
 *
 * @param int $min lowest legal value: 1 for fibres, 0 for ports (0 = IN)
 */
function parse_range_runs(string $raw, int $lo, int $hi, int $min = 1): ?array
{
    $raw = trim($raw);
    if ($raw === '') return [[$lo, $hi]];

    $runs = [];
    foreach (explode(',', $raw) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $tok, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
        } elseif (preg_match('/^\d+$/', $tok)) {
            $a = $b = (int)$tok;
        } else {
            return null;
        }
        if ($a > $b) [$a, $b] = [$b, $a];
        if ($a < $min) return null;
        $runs[] = [$a, $b];
    }
    if (!$runs) return null;

    usort($runs, fn($x, $y) => $x[0] <=> $y[0]);
    $merged = [];
    foreach ($runs as $r) {
        $n = count($merged);
        if ($n && $r[0] <= $merged[$n - 1][1] + 1) {
            $merged[$n - 1][1] = max($merged[$n - 1][1], $r[1]);
        } else {
            $merged[] = $r;
        }
    }
    return $merged;
}

/** Total values covered by a run list. */
function range_runs_count(array $runs): int
{
    $n = 0;
    foreach ($runs as [$lo, $hi]) $n += $hi - $lo + 1;
    return $n;
}

/**
 * Pair two run lists into the splice rows they describe.
 *
 * A splice maps A's fibres to B's fibres in order, one to one, so the two sides are
 * flattened and zipped; a new row starts wherever EITHER side stops being consecutive.
 * A = 1,3,7:9 spliced to B = 20:24 is three rows: 1→20, 3→21, 7:9→22:24.
 *
 * Returns [[[aLo,aHi],[bLo,bHi]], …], or null when the counts differ — with unequal
 * sides there is no defensible pairing, so the caller refuses instead of guessing.
 */
function align_range_runs(array $aRuns, array $bRuns): ?array
{
    $flat = function (array $runs): array {
        $out = [];
        foreach ($runs as [$lo, $hi]) for ($n = $lo; $n <= $hi; $n++) $out[] = $n;
        return $out;
    };
    $a = $flat($aRuns);
    $b = $flat($bRuns);
    if (count($a) !== count($b) || !$a) return null;

    $pairs = [];
    $aStart = $a[0]; $bStart = $b[0];
    for ($i = 1; $i <= count($a); $i++) {
        $breaks = $i === count($a)
               || $a[$i] !== $a[$i - 1] + 1
               || $b[$i] !== $b[$i - 1] + 1;
        if ($breaks) {
            $pairs[] = [[$aStart, $a[$i - 1]], [$bStart, $b[$i - 1]]];
            if ($i < count($a)) { $aStart = $a[$i]; $bStart = $b[$i]; }
        }
    }
    return $pairs;
}

/**
 * The fibre/port range control: one text box ("1:12"), the ITU colour grid, and the
 * hidden lo/hi inputs the form has always posted. See js/fiber-range-picker.js.
 *
 * When $multi is on, the selection may hold several disjoint runs (1,3,7:9). The
 * lo/hi pair still carries the FIRST run so nothing that reads it breaks; the whole
 * selection travels in the extra "{$loName}_runs" input as "1:1,3:3,7:9", and the
 * add-splice handler turns each aligned run pair into its own splice_join row.
 * Editing an existing splice stays single-run — one row holds exactly one range.
 *
 * @param string $loName  POST name for the low bound  (e.g. 'a_fiber_lo')
 * @param string $hiName  POST name for the high bound
 * @param string $ids     id prefix; inputs become "{$ids}Lo" / "{$ids}Hi"
 * @param string $source  CSS selector of the select that decides which fibres exist
 * @param string $mode    'fibre' | 'port'
 * @param bool   $multi   allow several disjoint runs in one selection
 */
function render_fiber_range_picker(string $loName, string $hiName, string $ids,
                                   string $source, string $mode = 'fibre',
                                   bool $multi = false): string
{
    $isPort = $mode === 'port';
    $label  = $isPort ? 'Ports'  : 'Fibres';
    $ph     = $isPort
        ? ($multi ? 'e.g. 0 (IN), 1:4 or 1,3' : 'e.g. 0 (IN) or 1:4')
        : ($multi ? 'e.g. 1:12 or 1,3,7:9'    : 'e.g. 1:12');
    if ($isPort) {
        $hint = 'Type a port, or click below. 0 = IN.';
    } else {
        $hint = 'Type a range like <code>1:12</code>, or click and drag the grid.';
    }
    if ($multi) {
        $hint .= ' <span class="text-muted">Ctrl/&#8984;-click to add a separate run.</span>';
    }

    $runsInput = $multi
        ? '<input type="hidden" name="' . h($loName) . '_runs" id="' . h($ids) . 'Runs" class="fp-runs">'
        : '';

    return '
      <div class="fp" data-fibre-picker data-mode="' . h($mode) . '"' .
         ($multi ? ' data-multi="1"' : '') . '
           data-lo="#' . h($ids) . 'Lo" data-hi="#' . h($ids) . 'Hi"
           data-source="' . h($source) . '">
        <label class="form-label small mb-1" for="' . h($ids) . 'Text">' . h($label) . '</label>
        <input type="text" class="form-control form-control-sm fp-text" id="' . h($ids) . 'Text"
               placeholder="' . h($ph) . '" autocomplete="off" inputmode="numeric">
        <input type="hidden" name="' . h($loName) . '" id="' . h($ids) . 'Lo">
        <input type="hidden" name="' . h($hiName) . '" id="' . h($ids) . 'Hi">
        ' . $runsInput . '
        <div class="fp-error small text-danger"></div>
        <div class="form-text mb-1">' . $hint . '</div>
        <div class="fp-grid fp-grid--empty"></div>
        <div class="fp-summary small mt-1"></div>
      </div>';
}

// ── Deleting the last closure of a run ────────────────────────────────────────

/**
 * Delete a closure, taking its one incoming span with it when it is the dead
 * end of a run.
 *
 * A FlexTIN run is a chain of optitaps, and each tap in the middle holds a
 * pass-through splice joining the span in to the span out.  That makes the
 * plain delete deadlock at the tail: the last tap cannot go while its span is
 * attached, and the span cannot go while the tap before it splices through to
 * it.  So when the closure has exactly one span, nothing spliced inside it, and
 * the only splices on that span are pass-throughs at the far end, all three go
 * in one transaction and the tap before becomes the new end.  Anything else —
 * a closure mid-run, a real splice, a device — is left for the caller's normal
 * delete to refuse, with a message saying what is in the way.
 *
 * Returns null on success or an error message.
 */
function closure_delete_with_tail_span(PDO $pdo, int $foscId): ?string
{
    $spans = $pdo->prepare("
        SELECT cable_span_id FROM app.cable_span
         WHERE from_fosc_id = :a OR to_fosc_id = :b
    ");
    $spans->execute([':a' => $foscId, ':b' => $foscId]);
    $spanIds = array_map('intval', $spans->fetchAll(PDO::FETCH_COLUMN));

    if (count($spanIds) === 2) {
        // A splice point in the middle of one cable: delete it and join the two spans as if it was never there.
        $own = $pdo->prepare("SELECT count(*) FROM app.splice_join WHERE fosc_id = :id AND retired_at IS NULL");
        $own->execute([':id' => $foscId]);
        if ((int)$own->fetchColumn() > 0) {
            return 'Cannot delete: closure still has splices inside it. Remove those first.';
        }
        try {
            $pdo->beginTransaction();
            cable_spans_join($pdo, $spanIds[0], $spanIds[1]);
            // Retired splice history has nowhere to live once the closure is gone.
            $pdo->prepare("DELETE FROM app.splice_join WHERE fosc_id = :id AND retired_at IS NOT NULL")
                ->execute([':id' => $foscId]);
            $pdo->prepare("DELETE FROM app.fosc WHERE fosc_id = :id")->execute([':id' => $foscId]);
            $pdo->commit();
            return null;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return 'Cannot delete: closure is in the middle of a run (2 spans) and they cannot be joined: ' . $e->getMessage() . '.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return str_starts_with((string)$e->getCode(), '23')
                ? 'Cannot delete: closure still has devices, ONTs, leads or OTDR results attached. Remove those first.'
                : 'Database error: ' . $e->getMessage();
        }
    }
    if (count($spanIds) > 2) {
        return 'Cannot delete: closure is a junction (' . count($spanIds)
             . ' spans). Delete from the end of the run back to this one.';
    }

    $own = $pdo->prepare("SELECT count(*) FROM app.splice_join WHERE fosc_id = :id AND retired_at IS NULL");
    $own->execute([':id' => $foscId]);
    if ((int)$own->fetchColumn() > 0) {
        return 'Cannot delete: closure still has splices inside it. Remove those first.';
    }

    try {
        $pdo->beginTransaction();

        if ($spanIds) {
            $spanId = $spanIds[0];
            $other = $pdo->prepare("
                SELECT splice_join_id, join_kind FROM app.splice_join
                 WHERE retired_at IS NULL
                   AND ((a_target = 'cable_span' AND a_span_id = :a)
                     OR (b_target = 'cable_span' AND b_span_id = :b))
            ");
            $other->execute([':a' => $spanId, ':b' => $spanId]);
            $joins = $other->fetchAll(PDO::FETCH_ASSOC);
            foreach ($joins as $j) {
                if ($j['join_kind'] !== 'pass_through') {
                    $pdo->rollBack();
                    return 'Cannot delete: the span into this closure is spliced to other fibre. Remove those splices first.';
                }
            }
            if ($joins) {
                $ids = '{' . implode(',', array_map(fn($j) => (int)$j['splice_join_id'], $joins)) . '}';
                $pdo->prepare("DELETE FROM app.splice_join WHERE splice_join_id = ANY(:ids::bigint[])")
                    ->execute([':ids' => $ids]);
            }
            $pdo->prepare("DELETE FROM app.cable_span WHERE cable_span_id = :id")->execute([':id' => $spanId]);
        }

        // Retired splice history has nowhere to live once the closure is gone.
        $pdo->prepare("DELETE FROM app.splice_join WHERE fosc_id = :id AND retired_at IS NOT NULL")
            ->execute([':id' => $foscId]);
        $st = $pdo->prepare("DELETE FROM app.fosc WHERE fosc_id = :id");
        $st->execute([':id' => $foscId]);
        if ($st->rowCount() === 0) {
            $pdo->rollBack();
            return 'Closure not found';
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return str_starts_with((string)$e->getCode(), '23')
            ? 'Cannot delete: closure still has devices, ONTs, leads or OTDR results attached. Remove those first.'
            : 'Database error: ' . $e->getMessage();
    }
    return null;
}
