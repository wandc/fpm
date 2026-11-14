<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// phpHelpers/splice_export.helper.php — builds the machine-readable snapshot of the
// splice plant that splice_export.php serves.  The audience is an outside reader
// (typically an AI agent asked to review the plant), so everything this file emits
// is denormalised and self-describing: no int4range literals, no polymorphic ids
// left unresolved, and a conventions block explaining the house rules.

/**
 * Resolve the set of fosc_ids the export covers.
 *
 *  - $opts['fosc_id'] + $opts['hops']  → that closure plus everything within N
 *                                        cable-span hops of it
 *  - $opts['wor']                      → closures and span endpoints on one Tinuum permit
 *  - neither                           → the whole plant
 *
 * Always returns a plain int list so every downstream query can use one uniform
 * `= ANY(...)` predicate instead of branching on scope.
 *
 * @return int[]
 */
function splice_export_scope(PDO $pdo, array $opts): array
{
    $foscId = (int)($opts['fosc_id'] ?? 0);
    $wor    = trim((string)($opts['wor'] ?? ''));

    if ($foscId > 0) {
        $hops = max(0, min(20, (int)($opts['hops'] ?? 3)));
        // Undirected walk over cable_span; depth is capped by :hops, and the
        // visited-set guard stops the ring topologies from looping forever.
        $st = $pdo->prepare("
            WITH RECURSIVE walk(fosc_id, depth, path) AS (
                SELECT :start::bigint, 0, ARRAY[:start2::bigint]
                UNION ALL
                SELECT nxt.fosc_id, w.depth + 1, w.path || nxt.fosc_id
                  FROM walk w
                  CROSS JOIN LATERAL (
                        SELECT CASE WHEN cs.from_fosc_id = w.fosc_id
                                    THEN cs.to_fosc_id ELSE cs.from_fosc_id END AS fosc_id
                          FROM app.cable_span cs
                         WHERE cs.from_fosc_id = w.fosc_id OR cs.to_fosc_id = w.fosc_id
                  ) nxt
                 WHERE w.depth < :hops
                   AND NOT nxt.fosc_id = ANY(w.path)
            )
            SELECT DISTINCT fosc_id FROM walk ORDER BY fosc_id
        ");
        $st->bindValue(':start',  $foscId, PDO::PARAM_INT);
        $st->bindValue(':start2', $foscId, PDO::PARAM_INT);
        $st->bindValue(':hops',   $hops,   PDO::PARAM_INT);
        $st->execute();
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    if ($wor !== '') {
        // A permit reaches a closure four ways: the closure's own primary permit, an
        // additional permit via fosc_work_order, or either endpoint of a span carrying
        // the permit (primary column or the cable_span_work_order link table).
        $st = $pdo->prepare("
            SELECT DISTINCT fosc_id FROM (
                SELECT fosc_id FROM app.fosc            WHERE fpm_wor = :w1
                UNION ALL
                SELECT fosc_id FROM app.fosc_work_order WHERE work_order = :w2
                UNION ALL
                SELECT cs.from_fosc_id FROM app.cable_span cs WHERE cs.fpm_wor = :w3
                UNION ALL
                SELECT cs.to_fosc_id   FROM app.cable_span cs WHERE cs.fpm_wor = :w4
                UNION ALL
                SELECT cs.from_fosc_id FROM app.cable_span cs
                  JOIN app.cable_span_work_order o ON o.cable_span_id = cs.cable_span_id
                 WHERE o.work_order = :w5
                UNION ALL
                SELECT cs.to_fosc_id FROM app.cable_span cs
                  JOIN app.cable_span_work_order o ON o.cable_span_id = cs.cable_span_id
                 WHERE o.work_order = :w6
            ) q
            WHERE fosc_id IS NOT NULL
            ORDER BY fosc_id
        ");
        // EMULATE_PREPARES=false: every occurrence of a value needs its own placeholder.
        $st->execute([':w1'=>$wor, ':w2'=>$wor, ':w3'=>$wor, ':w4'=>$wor, ':w5'=>$wor, ':w6'=>$wor]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $rows = $pdo->query('SELECT fosc_id FROM app.fosc ORDER BY fosc_id')->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
}

/**
 * Render an int list as a Postgres array literal for `= ANY(:ids::bigint[])`.
 * Values are cast to int first, so this cannot carry anything but digits.
 */
function splice_export_id_array(array $ids): string
{
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

/** The house rules an outside reader would otherwise have to guess. */
function splice_export_conventions(): array
{
    return [
        'about' =>
            'Snapshot of a fibre plant: closures (fosc), cable spans, cables, in-closure '
          . 'devices and the splice joins between them. Ids are stable primary keys.',
        'fibre_numbering' => [
            'inclusive' =>
                'fibre_lo and fibre_hi are INCLUSIVE. They are rendered from Postgres '
              . 'int4range columns, which are stored half-open; the raw form never appears here.',
            'mapping' =>
                'A join maps side A to side B by offset: A.fibre_lo pairs with B.fibre_lo, '
              . 'A.fibre_lo+1 with B.fibre_lo+1, and so on. The two sides always cover the '
              . 'same count of fibres.',
            'colour_order' =>
                'TIA/EIA-598 order, repeating every 12: Blue, Orange, Green, Brown, Slate, '
              . 'White, Red, Black, Yellow, Violet, Rose, Aqua. Fibre 13 is Blue in tube 2.',
        ],
        'splices' => [
            'is_plan'    => 'true = designed but not yet spliced. false = as-built.',
            'retired'    => 'Retired splices are soft-deleted and are excluded from this file entirely.',
            'join_kind'  => [
                'single'       => 'Ordinary fusion splice, one fibre to one fibre across the range.',
                'ribbon'       => 'Mass-fusion of a whole ribbon at once.',
                'pass_through' => 'Fibre routed through the closure untouched (stored so the path traces).',
                'connector'    => 'Patch/connector, not a fusion splice. Device-to-device connectors are POP patch cables.',
            ],
            'ends' =>
                'a_end / b_end are "from" or "to" and refer to the SPAN\'s own stored direction, '
              . 'not to geography or signal flow. The closure hosting a splice should be the same '
              . 'closure as that end of the span (see the splice_not_at_span_endpoint check).',
            'targets' =>
                'target is "cable_span" or "fosc_device". The underlying column is polymorphic; '
              . 'this export has already resolved it into cable_span_id / fosc_device_id plus a '
              . 'human-readable ref.',
        ],
        'closures' => [
            'splice_num' => 'Label format is ^([A-Z]{3}[0-9]{4}|[LR][0-9]{2}(-MST)?)$.',
            'kinds' =>
                'closure, mst, terminal, cabinet (an FDC), optitap, patch_panel, and slack. '
              . 'kind="slack" is a 30 m slack loop, NOT a junction: it is a coil of cable parked '
              . 'on the route, so it is normal for one to hold no splices, and a pass_through join '
              . 'is the only kind expected there. kind="optitap" is a subscriber drop terminal — '
              . 'the closest thing in this data to a premises, and the basis of the downstream_optitaps '
              . 'figure on each span.',
        ],
        'devices' => [
            'types'    => 'splitter, oadm, patch_panel, mpt, olt_pon, sfp, dwdm.',
            'splitter' => 'Fan-out is attrs.outs (2-128). Port 0 is the input; 1..outs are the outputs.',
            'homing'   => 'A device lives at a closure OR at a POP, never both.',
        ],
        'capacity' => [
            'fibres_used'    => 'Distinct fibre positions on the span touched by at least one as-built splice.',
            'fibres_planned' => 'Same, for planned (is_plan) splices only.',
            'open_ended' =>
                'An open_ended cable is a drop/distribution run rather than a trunk. Full fibre '
              . 'occupancy on one is the normal end state, not a shortage.',
            'downstream_optitaps' =>
                'Cut the span and the closure graph splits in two. This counts the optitap drop '
              . 'terminals on the side that no longer reaches a POP — i.e. what this cable feeds. '
              . 'null with on_ring=true means the span sits on a ring, so neither side is downstream. '
              . 'Compare it against fibres_free to judge whether a cable was sized too small.',
            'no_premises_data' =>
                'IMPORTANT: this database holds no premises, dwelling, or homes-passed counts, and '
              . 'no civic address layer. Nothing here says how many houses a road has. Judge cable '
              . 'sizing from fibre occupancy and downstream branch counts only, and do not infer or '
              . 'invent demand figures.',
        ],
        'optical' => [
            'gpon_rx_window_dbm' => 'Normal ONT receive range is -8 dBm (max) to -28 dBm (min).',
        ],
        'checks' =>
            'The checks[] array is produced by deterministic SQL in this exporter, not by judgement. '
          . 'Each entry lists the offending object ids. Treat them as leads to confirm, and look for '
          . 'problems they do not cover.',
    ];
}

/**
 * Deterministic data-integrity checks over the scoped plant.
 * Each returns id / severity / title / description / count / refs[].
 *
 * Kept separate from splice_export_array() so the same findings can later be shown
 * in the UI without building the whole export.
 */
function splice_export_checks(PDO $pdo, array $foscIds): array
{
    $ids  = splice_export_id_array($foscIds);
    $out  = [];

    $run = function (string $sql, array $params) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    };

    // Every splice side, exploded to one row per fibre. Reused by several checks.
    $sidesCte = "
        sides AS (
            SELECT j.splice_join_id, j.fosc_id, j.is_plan,
                   j.a_span_id AS obj_id, j.a_target::text AS target, j.a_end::text AS side,
                   'a' AS which, generate_series(lower(j.a_range), upper(j.a_range) - 1) AS fibre
              FROM app.splice_join j
             WHERE j.retired_at IS NULL AND j.fosc_id = ANY(:ids1::bigint[])
            UNION ALL
            SELECT j.splice_join_id, j.fosc_id, j.is_plan,
                   j.b_span_id, j.b_target::text, j.b_end::text,
                   'b', generate_series(lower(j.b_range), upper(j.b_range) - 1)
              FROM app.splice_join j
             WHERE j.retired_at IS NULL AND j.fosc_id = ANY(:ids2::bigint[])
        )";

    // 1. A splice must sit in the closure that terminates the span end it references.
    $rows = $run("
        WITH sides AS (
            SELECT splice_join_id, fosc_id, a_span_id AS obj_id, a_target::text AS target,
                   a_end::text AS side, 'a' AS which
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids1::bigint[])
            UNION ALL
            SELECT splice_join_id, fosc_id, b_span_id, b_target::text, b_end::text, 'b'
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids2::bigint[])
        )
        SELECT s.splice_join_id, s.which AS side_letter, s.side AS span_end,
               s.fosc_id AS splice_at_fosc_id, jf.splice_num AS splice_at_label,
               s.obj_id AS cable_span_id,
               (CASE WHEN s.side = 'from' THEN cs.from_fosc_id ELSE cs.to_fosc_id END) AS expected_fosc_id,
               ef.splice_num AS expected_label
          FROM sides s
          JOIN app.cable_span cs ON cs.cable_span_id = s.obj_id AND s.target = 'cable_span'
          LEFT JOIN app.fosc jf ON jf.fosc_id = s.fosc_id
          LEFT JOIN app.fosc ef ON ef.fosc_id = (CASE WHEN s.side = 'from' THEN cs.from_fosc_id ELSE cs.to_fosc_id END)
         WHERE s.fosc_id <> (CASE WHEN s.side = 'from' THEN cs.from_fosc_id ELSE cs.to_fosc_id END)
         ORDER BY s.splice_join_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'splice_not_at_span_endpoint',
        'severity'    => 'error',
        'title'       => 'Splice recorded at a closure the span does not reach',
        'description' => 'The join lives in one closure but references a span end that terminates in a '
                       . 'different closure. Either the join is on the wrong closure or it names the wrong '
                       . 'end of the span. These corrupt every trace that crosses them.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 2. One fibre at one span end can only go to one place.
    $rows = $run("
        WITH {$sidesCte},
        dup AS (
            SELECT s.obj_id, s.target, s.side, s.fibre,
                   array_agg(DISTINCT s.splice_join_id ORDER BY s.splice_join_id) AS joins
              FROM sides s
              -- A patch-panel port is a bulkhead with two sides: the cable terminated
              -- on the back and the patch cord on the front. Two joins on one port is
              -- how a panel works, so panels are exempt. Splitter and cable positions
              -- are not — those are single physical terminations.
              LEFT JOIN app.fosc_device pd
                     ON s.target = 'fosc_device' AND pd.fosc_device_id = s.obj_id
             WHERE NOT s.is_plan
               AND COALESCE(pd.device_type, '') <> 'patch_panel'
             GROUP BY s.obj_id, s.target, s.side, s.fibre
            HAVING count(*) > 1
        )
        SELECT d.target, d.obj_id, d.side, d.joins AS splice_join_ids,
               min(d.fibre) AS fibre_lo, max(d.fibre) AS fibre_hi, count(*) AS fibres,
               c.name AS cable_name, dev.name AS device_name
          FROM dup d
          LEFT JOIN app.cable_span cs ON d.target = 'cable_span'  AND cs.cable_span_id = d.obj_id
          LEFT JOIN app.cable c       ON c.cable_id = cs.cable_id
          LEFT JOIN app.fosc_device dev ON d.target = 'fosc_device' AND dev.fosc_device_id = d.obj_id
         GROUP BY d.target, d.obj_id, d.side, d.joins, c.name, dev.name
         ORDER BY d.target, d.obj_id, fibre_lo
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'fibre_spliced_twice',
        'severity'    => 'error',
        'title'       => 'Same fibre (or device port) spliced by more than one as-built join',
        'description' => 'A fibre at a given span end is one physical position, so it can terminate in only '
                       . 'one place. Two live as-built joins claiming it means one of them is wrong. Often a '
                       . 'consequence of splice_not_at_span_endpoint. Patch-panel ports are excluded: a panel '
                       . 'port legitimately carries a cable on the back and a patch cord on the front.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 3. The two sides of a join must cover the same number of fibres.
    $rows = $run("
        SELECT j.splice_join_id, j.fosc_id, f.splice_num AS fosc_label,
               lower(j.a_range) AS a_fibre_lo, upper(j.a_range) - 1 AS a_fibre_hi,
               (upper(j.a_range) - lower(j.a_range)) AS a_width,
               lower(j.b_range) AS b_fibre_lo, upper(j.b_range) - 1 AS b_fibre_hi,
               (upper(j.b_range) - lower(j.b_range)) AS b_width
          FROM app.splice_join j
          LEFT JOIN app.fosc f ON f.fosc_id = j.fosc_id
         WHERE j.retired_at IS NULL AND j.fosc_id = ANY(:ids1::bigint[])
           AND (upper(j.a_range) - lower(j.a_range)) <> (upper(j.b_range) - lower(j.b_range))
         ORDER BY j.splice_join_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'range_width_mismatch',
        'severity'    => 'error',
        'title'       => 'Join maps unequal fibre counts',
        'description' => 'Side A and side B cover different numbers of fibres, so the offset mapping between '
                       . 'them is undefined and some fibres land nowhere.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 4. A join whose two sides are the same physical position.
    $rows = $run("
        SELECT j.splice_join_id, j.fosc_id, f.splice_num AS fosc_label,
               j.a_target::text AS target, j.a_span_id AS obj_id, j.a_end::text AS span_end,
               lower(j.a_range) AS fibre_lo, upper(j.a_range) - 1 AS fibre_hi
          FROM app.splice_join j
          LEFT JOIN app.fosc f ON f.fosc_id = j.fosc_id
         WHERE j.retired_at IS NULL AND j.fosc_id = ANY(:ids1::bigint[])
           AND j.a_target = j.b_target AND j.a_span_id = j.b_span_id AND j.a_end = j.b_end
           -- Same device/span on both sides is fine when the ranges are disjoint: a cord
           -- looping panel ports 1-2 to 7-8 is real. Only overlapping ranges are a fault.
           AND j.a_range && j.b_range
         ORDER BY j.splice_join_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'self_splice',
        'severity'    => 'error',
        'title'       => 'Join splices a span end to itself',
        'description' => 'Both sides name the same span or device, the same end, and overlapping fibres — a '
                       . 'loop back to the same position, which cannot exist in the field. A cord between two '
                       . 'different ports of one panel is not flagged.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 5. Fibre numbers must fall inside the cable's fibre count.
    $rows = $run("
        WITH sides AS (
            SELECT splice_join_id, fosc_id, a_span_id AS obj_id, a_target::text AS target,
                   lower(a_range) AS lo, upper(a_range) - 1 AS hi
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids1::bigint[])
            UNION ALL
            SELECT splice_join_id, fosc_id, b_span_id, b_target::text,
                   lower(b_range), upper(b_range) - 1
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids2::bigint[])
        )
        SELECT s.splice_join_id, s.fosc_id, s.obj_id AS cable_span_id, c.name AS cable_name,
               c.fibre_count, s.lo AS fibre_lo, s.hi AS fibre_hi
          FROM sides s
          JOIN app.cable_span cs ON cs.cable_span_id = s.obj_id AND s.target = 'cable_span'
          JOIN app.cable c ON c.cable_id = cs.cable_id
         WHERE s.hi > c.fibre_count OR s.lo < 1
         ORDER BY s.splice_join_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'fibre_beyond_cable_count',
        'severity'    => 'error',
        'title'       => 'Splice references a fibre the cable does not have',
        'description' => 'The fibre range runs past the cable\'s fibre_count (or below fibre 1).',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 6. Device ports must fall inside the device's port/output count.
    $rows = $run("
        WITH sides AS (
            SELECT splice_join_id, fosc_id, a_span_id AS obj_id, a_target::text AS target,
                   lower(a_range) AS lo, upper(a_range) - 1 AS hi
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids1::bigint[])
            UNION ALL
            SELECT splice_join_id, fosc_id, b_span_id, b_target::text,
                   lower(b_range), upper(b_range) - 1
              FROM app.splice_join WHERE retired_at IS NULL AND fosc_id = ANY(:ids2::bigint[])
        )
        SELECT s.splice_join_id, s.fosc_id, d.fosc_device_id, d.name AS device_name,
               d.device_type, cap.n AS capacity, s.lo AS port_lo, s.hi AS port_hi
          FROM sides s
          JOIN app.fosc_device d ON d.fosc_device_id = s.obj_id AND s.target = 'fosc_device'
          CROSS JOIN LATERAL (
                SELECT COALESCE((d.attrs->>'outs')::int, (d.attrs->>'ports')::int) AS n
          ) cap
         WHERE cap.n IS NOT NULL AND s.hi > cap.n
         ORDER BY s.splice_join_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'device_port_beyond_capacity',
        'severity'    => 'error',
        'title'       => 'Splice references a port the device does not have',
        'description' => 'The port range runs past the splitter\'s attrs.outs or the panel\'s attrs.ports. '
                       . 'Port 0 is the splitter input, so outputs run 1..outs.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 7. A span nobody splices is either unfinished or forgotten.
    $rows = $run("
        SELECT cs.cable_span_id, c.name AS cable_name, c.fibre_count,
               cs.from_fosc_id, ff.splice_num AS from_label,
               cs.to_fosc_id,   tf.splice_num AS to_label,
               round(cs.length_m::numeric, 1) AS length_m
          FROM app.cable_span cs
          JOIN app.cable c ON c.cable_id = cs.cable_id
          LEFT JOIN app.fosc ff ON ff.fosc_id = cs.from_fosc_id
          LEFT JOIN app.fosc tf ON tf.fosc_id = cs.to_fosc_id
         WHERE (cs.from_fosc_id = ANY(:ids1::bigint[]) OR cs.to_fosc_id = ANY(:ids2::bigint[]))
           -- Both ends must be built. A span running into a closure that is still on
           -- plan, being manufactured or shipped has nothing to splice to yet, which
           -- is the normal state for work in progress, not a missing record.
           AND ff.status = 'active' AND tf.status = 'active'
           -- Cable placed on purpose and left dark is not an unfinished record.
           AND NOT cs.is_spare
           AND NOT EXISTS (
                SELECT 1 FROM app.splice_join j
                 WHERE j.retired_at IS NULL
                   AND ((j.a_target = 'cable_span' AND j.a_span_id = cs.cable_span_id)
                     OR (j.b_target = 'cable_span' AND j.b_span_id = cs.cable_span_id))
           )
         ORDER BY cs.cable_span_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'orphan_span',
        'severity'    => 'warning',
        'title'       => 'Cable span with no splices at either end',
        'description' => 'Both closures are active but nothing is spliced at either end, so the cable carries '
                       . 'no light. Excluded: spans into unbuilt closures, and spans flagged is_spare.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 8. A closure with no cable is not on the network.
    $rows = $run("
        SELECT f.fosc_id, f.splice_num, f.kind::text AS kind, f.status::text AS status, f.fpm_wor
          FROM app.fosc f
         WHERE f.fosc_id = ANY(:ids1::bigint[])
           AND f.kind <> 'slack'
           -- Planned and in-manufacture closures legitimately have no cable yet.
           AND f.status = 'active'
           AND NOT EXISTS (
                SELECT 1 FROM app.cable_span cs
                 WHERE cs.from_fosc_id = f.fosc_id OR cs.to_fosc_id = f.fosc_id
           )
         ORDER BY f.fosc_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'closure_without_span',
        'severity'    => 'warning',
        'title'       => 'Closure with no cable span attached',
        'description' => 'An active, non-slack closure that no cable reaches. Closures still on plan are '
                       . 'excluded — those legitimately have no cable yet.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 9. Slack loops are coils of cable, not junctions. A pass_through join at one is
    //    normal and expected (the fibre runs straight through the coil); anything that
    //    actually terminates a fibre there is not.
    $rows = $run("
        SELECT f.fosc_id, f.splice_num, count(j.splice_join_id) AS splice_count,
               string_agg(DISTINCT j.join_kind::text, ',') AS join_kinds
          FROM app.fosc f
          JOIN app.splice_join j ON j.fosc_id = f.fosc_id AND j.retired_at IS NULL
         WHERE f.fosc_id = ANY(:ids1::bigint[]) AND f.kind = 'slack'
           AND j.join_kind <> 'pass_through'
         GROUP BY f.fosc_id, f.splice_num
         ORDER BY f.fosc_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'slack_loop_with_real_splices',
        'severity'    => 'warning',
        'title'       => 'Slack loop holding a splice that is not a pass-through',
        'description' => 'kind="slack" marks a 30 m coil of cable on the route, so a pass_through join there '
                       . 'is normal and is not reported. A fusion or connector join means the record is really '
                       . 'a closure and the kind is wrong, or the splices belong elsewhere.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 11. Cable names carry their fibre count by convention ("FlexTIN 12F A"), so a
    //     name that disagrees with fibre_count means one of the two was edited alone —
    //     and fibre_count is what every capacity figure here is measured against.
    $rows = $run("
        SELECT c.cable_id, c.name, c.fibre_count,
               (regexp_match(c.name, '(\\d+)\\s*[Ff]\\M'))[1]::int AS name_implies,
               (SELECT count(*) FROM app.cable_span cs WHERE cs.cable_id = c.cable_id) AS spans_affected
          FROM app.cable c
         WHERE (regexp_match(c.name, '(\\d+)\\s*[Ff]\\M'))[1] IS NOT NULL
           AND (regexp_match(c.name, '(\\d+)\\s*[Ff]\\M'))[1]::int <> c.fibre_count
         ORDER BY c.cable_id
    ", []);
    $out[] = [
        'id'          => 'cable_name_fibre_count_mismatch',
        'severity'    => 'warning',
        'title'       => 'Cable name and fibre_count disagree',
        'description' => 'The name states one fibre count and the fibre_count column another. Every '
                       . 'utilisation and headroom figure in this file uses the column, so if the name is the '
                       . 'correct one the capacity picture for every span on this cable is wrong.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 10. Not a fault — the sizing signal. A full span cannot take another branch.
    //     open_ended drop cables sitting at 100% are normal and would drown the real
    //     signal, so they are reported with the flag rather than filtered out silently.
    $rows = $run("
        WITH used AS (
            SELECT obj_id AS cable_span_id, count(DISTINCT fibre) AS fibres_used
              FROM (
                SELECT a_span_id AS obj_id, generate_series(lower(a_range), upper(a_range) - 1) AS fibre
                  FROM app.splice_join
                 WHERE retired_at IS NULL AND NOT is_plan AND a_target = 'cable_span'
                UNION ALL
                SELECT b_span_id, generate_series(lower(b_range), upper(b_range) - 1)
                  FROM app.splice_join
                 WHERE retired_at IS NULL AND NOT is_plan AND b_target = 'cable_span'
              ) q
             GROUP BY obj_id
        )
        SELECT cs.cable_span_id, c.name AS cable_name, c.fibre_count, c.open_ended, u.fibres_used,
               cs.from_fosc_id, ff.splice_num AS from_label,
               cs.to_fosc_id,   tf.splice_num AS to_label
          FROM app.cable_span cs
          JOIN app.cable c ON c.cable_id = cs.cable_id
          JOIN used u ON u.cable_span_id = cs.cable_span_id
          LEFT JOIN app.fosc ff ON ff.fosc_id = cs.from_fosc_id
          LEFT JOIN app.fosc tf ON tf.fosc_id = cs.to_fosc_id
         WHERE (cs.from_fosc_id = ANY(:ids1::bigint[]) OR cs.to_fosc_id = ANY(:ids2::bigint[]))
           AND u.fibres_used >= c.fibre_count
         ORDER BY c.fibre_count DESC, cs.cable_span_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'span_at_capacity',
        'severity'    => 'info',
        'title'       => 'Span with every fibre in use',
        'description' => 'Not an error — a headroom signal. Any new branch off this span needs an overlash, a '
                       . 'larger cable, or a splitter further upstream. Rows with open_ended = true are drop '
                       . 'cables, where full occupancy is the normal end state and not a finding; weigh the '
                       . 'others against the span\'s downstream_optitaps in spans[].',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 12. A splitter needs its input spliced. Port 0 is the input; 1..outs are outputs.
    // One join can cover several ports at once ([1,5) is four outputs, not one row), so
    // the port range is expanded and counted distinctly rather than counting rows.
    $splitterCte = "
        sides AS (
            SELECT a_span_id AS obj_id, a_target::text AS target,
                   generate_series(lower(a_range), upper(a_range) - 1) AS port
              FROM app.splice_join WHERE retired_at IS NULL AND NOT is_plan
            UNION ALL
            SELECT b_span_id, b_target::text,
                   generate_series(lower(b_range), upper(b_range) - 1)
              FROM app.splice_join WHERE retired_at IS NULL AND NOT is_plan
        ),
        sp AS (
            SELECT d.fosc_device_id, d.fosc_id, d.name, (d.attrs->>'outs')::int AS outs,
                   COALESCE(bool_or(s.port = 0), false)              AS has_input,
                   count(DISTINCT s.port) FILTER (WHERE s.port >= 1) AS outputs_used
              FROM app.fosc_device d
              LEFT JOIN sides s ON s.obj_id = d.fosc_device_id AND s.target = 'fosc_device'
             WHERE d.device_type = 'splitter' AND d.fosc_id = ANY(:ids1::bigint[])
             GROUP BY d.fosc_device_id, d.fosc_id, d.name, d.attrs
        )";

    $rows = $run("WITH {$splitterCte}
        SELECT fosc_device_id, fosc_id, name, outs, outputs_used
          FROM sp WHERE NOT has_input AND outputs_used > 0 ORDER BY fosc_device_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'splitter_without_input',
        'severity'    => 'error',
        'title'       => 'Splitter with outputs spliced but no input',
        'description' => 'Port 0 is the splitter input. Outputs are spliced out to customers but nothing feeds '
                       . 'the splitter, so none of those fibres can carry light. Almost always a missing splice '
                       . 'record rather than a real dark splitter.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    $rows = $run("WITH {$splitterCte}
        SELECT fosc_device_id, fosc_id, name, outs, outputs_used
          FROM sp WHERE has_input AND outputs_used = 0 ORDER BY fosc_device_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'splitter_without_output',
        'severity'    => 'info',
        'title'       => 'Splitter fed but with no outputs spliced',
        'description' => 'The input is connected and every output port is idle. Legitimate for a splitter '
                       . 'placed ahead of customers, so read it as available capacity rather than a fault.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 13. Length feeds both the loss budget and the BOM.
    $rows = $run("
        SELECT cs.cable_span_id, c.name AS cable_name, cs.from_fosc_id, cs.to_fosc_id,
               cs.length_m, (cs.geom IS NULL) AS geom_missing
          FROM app.cable_span cs
          JOIN app.cable c ON c.cable_id = cs.cable_id
         WHERE (cs.from_fosc_id = ANY(:ids1::bigint[]) OR cs.to_fosc_id = ANY(:ids2::bigint[]))
           AND (cs.length_m IS NULL OR cs.length_m <= 0)
         ORDER BY cs.cable_span_id
    ", [':ids1' => $ids, ':ids2' => $ids]);
    $out[] = [
        'id'          => 'span_length_missing',
        'severity'    => 'warning',
        'title'       => 'Cable span with no length',
        'description' => 'length_m is null or zero, normally because the span has no drawn geometry. Any loss '
                       . 'budget or material take-off across this span is wrong.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 14. An optitap is a drop terminal. One that is active and has cable landing on it
    //     but no splice record is a tap nobody wrote down — the drop cannot be traced.
    $rows = $run("
        SELECT f.fosc_id, f.splice_num, f.status::text AS status, f.fpm_wor,
               (SELECT count(*) FROM app.cable_span cs
                 WHERE cs.from_fosc_id = f.fosc_id OR cs.to_fosc_id = f.fosc_id) AS spans_attached
          FROM app.fosc f
         WHERE f.fosc_id = ANY(:ids1::bigint[])
           AND f.kind = 'optitap' AND f.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM app.splice_join j
                            WHERE j.fosc_id = f.fosc_id AND j.retired_at IS NULL)
           AND EXISTS (SELECT 1 FROM app.cable_span cs
                        WHERE cs.from_fosc_id = f.fosc_id OR cs.to_fosc_id = f.fosc_id)
           -- A terminal on an open-ended run (FlexTIN and friends) is where the cable
           -- stops, and it stops in a factory-terminated pigtail. One span attached and
           -- that span open-ended means there is simply nothing to splice through to.
           AND NOT (
                (SELECT count(*) FROM app.cable_span cs
                  WHERE cs.from_fosc_id = f.fosc_id OR cs.to_fosc_id = f.fosc_id) = 1
                AND (SELECT bool_and(c.open_ended)
                       FROM app.cable_span cs JOIN app.cable c ON c.cable_id = cs.cable_id
                      WHERE cs.from_fosc_id = f.fosc_id OR cs.to_fosc_id = f.fosc_id)
           )
         ORDER BY f.fosc_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'active_optitap_without_splice',
        'severity'    => 'warning',
        'title'       => 'Active drop terminal with cable but no splice',
        'description' => 'The terminal is live and cable reaches it, yet nothing records which fibre it taps, '
                       . 'so no trace can reach a customer through it. Excluded: terminals still on plan or in '
                       . 'manufacture, and the last terminal of an open-ended drop run, where the cable simply '
                       . 'ends in its factory pigtail.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    // 15. Splitter outputs are the other capacity ceiling, alongside span fibres.
    $rows = $run("WITH {$splitterCte}
        SELECT sp.fosc_device_id, sp.fosc_id, f.splice_num AS fosc_label, sp.name,
               sp.outs, sp.outputs_used
          FROM sp JOIN app.fosc f ON f.fosc_id = sp.fosc_id
         WHERE sp.outs IS NOT NULL AND sp.outputs_used >= sp.outs
         ORDER BY sp.fosc_device_id
    ", [':ids1' => $ids]);
    $out[] = [
        'id'          => 'splitter_fully_subscribed',
        'severity'    => 'info',
        'title'       => 'Splitter with every output port taken',
        'description' => 'Not a fault — the other capacity ceiling. Serving one more customer from this '
                       . 'closure needs a second splitter and a spare feeder fibre to drive it.',
        'count'       => count($rows),
        'refs'        => $rows,
    ];

    return $out;
}

/**
 * Build the whole export payload.
 *
 * @param array $opts fosc_id, hops, wor, include_plan (default true), compact (default false)
 */
function splice_export_array(PDO $pdo, array $opts = []): array
{
    $foscIds     = splice_export_scope($pdo, $opts);
    $ids         = splice_export_id_array($foscIds);
    $compact     = !empty($opts['compact']);
    $includePlan = !array_key_exists('include_plan', $opts) || !empty($opts['include_plan']);

    // ── closures ──────────────────────────────────────────────────────────────
    $st = $pdo->prepare("
        SELECT f.fosc_id, f.splice_num, f.kind::text AS kind, f.status::text AS status,
               f.enclosure_rating::text AS enclosure_rating, ct.name AS closure_type,
               f.fpm_wor, f.notes, f.attrs->>'street_address' AS street_address,
               round(ST_Y(f.location)::numeric, 6) AS lat,
               round(ST_X(f.location)::numeric, 6) AS lon,
               p.name AS pop_name,
               (SELECT count(*) FROM app.ont_association oa WHERE oa.fosc_id = f.fosc_id) AS ont_count,
               (SELECT count(*) FROM app.service_association sa WHERE sa.fosc_id = f.fosc_id) AS prepared_service_count,
               (SELECT string_agg(w.work_order, ',' ORDER BY w.work_order)
                  FROM app.fosc_work_order w WHERE w.fosc_id = f.fosc_id) AS additional_work_orders
          FROM app.fosc f
          LEFT JOIN app.closure_type ct ON ct.closure_type_id = f.closure_type_id
          LEFT JOIN app.pop p ON p.pop_id = f.pop_id
         WHERE f.fosc_id = ANY(:ids1::bigint[])
         ORDER BY f.fosc_id
    ");
    $st->execute([':ids1' => $ids]);
    $closureRows = $st->fetchAll() ?: [];

    // ── devices (closure-homed, plus POP-homed devices reachable from those closures) ──
    $st = $pdo->prepare("
        SELECT d.fosc_device_id, d.fosc_id, d.pop_id, d.device_type, d.name,
               d.attrs::text AS attrs_json, d.notes, d.parent_device_id, d.parent_slot,
               p.name AS pop_name
          FROM app.fosc_device d
          LEFT JOIN app.pop p ON p.pop_id = d.pop_id
         WHERE d.fosc_id = ANY(:ids1::bigint[])
            OR d.pop_id IN (SELECT pop_id FROM app.fosc
                             WHERE fosc_id = ANY(:ids2::bigint[]) AND pop_id IS NOT NULL)
         ORDER BY d.fosc_device_id
    ");
    $st->execute([':ids1' => $ids, ':ids2' => $ids]);
    $deviceRows = $st->fetchAll() ?: [];

    // ── spans, with fibre occupancy ───────────────────────────────────────────
    $st = $pdo->prepare("
        WITH used AS (
            SELECT obj_id AS cable_span_id,
                   count(DISTINCT fibre) FILTER (WHERE NOT is_plan) AS fibres_used,
                   count(DISTINCT fibre) FILTER (WHERE is_plan)     AS fibres_planned
              FROM (
                SELECT a_span_id AS obj_id, is_plan,
                       generate_series(lower(a_range), upper(a_range) - 1) AS fibre
                  FROM app.splice_join
                 WHERE retired_at IS NULL AND a_target = 'cable_span'
                UNION ALL
                SELECT b_span_id, is_plan, generate_series(lower(b_range), upper(b_range) - 1)
                  FROM app.splice_join
                 WHERE retired_at IS NULL AND b_target = 'cable_span'
              ) q
             GROUP BY obj_id
        )
        SELECT cs.cable_span_id, cs.cable_id, c.name AS cable_name, c.fibre_count, c.open_ended,
               cs.from_fosc_id, ff.splice_num AS from_splice_num,
               cs.to_fosc_id,   tf.splice_num AS to_splice_num,
               round(cs.length_m::numeric, 1) AS length_m, cs.notes, cs.fpm_wor, cs.is_spare,
               COALESCE(u.fibres_used, 0)    AS fibres_used,
               COALESCE(u.fibres_planned, 0) AS fibres_planned,
               (SELECT string_agg(t.name, ',' ORDER BY t.name)
                  FROM app.cable_span_tag cst
                  JOIN app.span_tag t ON t.span_tag_id = cst.span_tag_id
                 WHERE cst.cable_span_id = cs.cable_span_id) AS tags,
               (SELECT string_agg(w.work_order, ',' ORDER BY w.work_order)
                  FROM app.cable_span_work_order w
                 WHERE w.cable_span_id = cs.cable_span_id) AS additional_work_orders
          FROM app.cable_span cs
          JOIN app.cable c ON c.cable_id = cs.cable_id
          LEFT JOIN used u ON u.cable_span_id = cs.cable_span_id
          LEFT JOIN app.fosc ff ON ff.fosc_id = cs.from_fosc_id
          LEFT JOIN app.fosc tf ON tf.fosc_id = cs.to_fosc_id
         WHERE cs.from_fosc_id = ANY(:ids1::bigint[]) OR cs.to_fosc_id = ANY(:ids2::bigint[])
         ORDER BY cs.cable_span_id
    ");
    $st->execute([':ids1' => $ids, ':ids2' => $ids]);
    $spanRows = $st->fetchAll() ?: [];

    // ── splices ───────────────────────────────────────────────────────────────
    // Plant-wide sibling of fetch_splices_at_fosc() (closure.helper.php:359). One
    // deliberate difference: that function hides device-to-device connector joins
    // because POP patch cables belong on the POP diagram, not the closure page. They
    // are real plant, so the export keeps them.
    $planClause = $includePlan ? '' : ' AND NOT j.is_plan';
    $st = $pdo->prepare("
        SELECT j.splice_join_id, j.fosc_id, jf.splice_num AS fosc_label,
               j.join_kind::text AS join_kind, j.is_plan, j.notes, j.created_at::text AS created_at,
               j.a_target::text AS a_target, j.a_span_id, j.a_end::text AS a_end,
               lower(j.a_range) AS a_fibre_lo, upper(j.a_range) - 1 AS a_fibre_hi,
               j.b_target::text AS b_target, j.b_span_id, j.b_end::text AS b_end,
               lower(j.b_range) AS b_fibre_lo, upper(j.b_range) - 1 AS b_fibre_hi,
               ac.name AS a_cable_name, ac.fibre_count AS a_fibre_count,
               bc.name AS b_cable_name, bc.fibre_count AS b_fibre_count,
               a_far.fosc_id AS a_far_fosc_id, a_far.fosc_label AS a_far_label,
               b_far.fosc_id AS b_far_fosc_id, b_far.fosc_label AS b_far_label,
               a_dev.name AS a_device_name, a_dev.device_type AS a_device_type,
               b_dev.name AS b_device_name, b_dev.device_type AS b_device_type
          FROM app.splice_join j
          LEFT JOIN app.fosc jf ON jf.fosc_id = j.fosc_id
          LEFT JOIN app.v_span_endpoints a_far
                 ON j.a_target = 'cable_span' AND a_far.cable_span_id = j.a_span_id
                AND a_far.\"end\"::text <> j.a_end::text
          LEFT JOIN app.v_span_endpoints b_far
                 ON j.b_target = 'cable_span' AND b_far.cable_span_id = j.b_span_id
                AND b_far.\"end\"::text <> j.b_end::text
          LEFT JOIN app.cable_span acs ON j.a_target = 'cable_span' AND acs.cable_span_id = j.a_span_id
          LEFT JOIN app.cable ac ON ac.cable_id = acs.cable_id
          LEFT JOIN app.cable_span bcs ON j.b_target = 'cable_span' AND bcs.cable_span_id = j.b_span_id
          LEFT JOIN app.cable bc ON bc.cable_id = bcs.cable_id
          LEFT JOIN app.fosc_device a_dev ON j.a_target = 'fosc_device' AND a_dev.fosc_device_id = j.a_span_id
          LEFT JOIN app.fosc_device b_dev ON j.b_target = 'fosc_device' AND b_dev.fosc_device_id = j.b_span_id
         WHERE j.retired_at IS NULL AND j.fosc_id = ANY(:ids1::bigint[]){$planClause}
         ORDER BY j.fosc_id, j.splice_join_id
    ");
    $st->execute([':ids1' => $ids]);
    $spliceRows = $st->fetchAll() ?: [];

    // ── cables actually referenced by the scoped spans ────────────────────────
    $cableIds = array_values(array_unique(array_map(fn($s) => (int)$s['cable_id'], $spanRows)));
    $cables   = [];
    if ($cableIds) {
        $st = $pdo->prepare("
            SELECT c.cable_id, c.name, c.fibre_count, c.open_ended, c.notes,
                   m.sku AS material_sku, m.description AS material_description
              FROM app.cable c
              LEFT JOIN app.bom_material m ON m.id = c.material_id
             WHERE c.cable_id = ANY(:ids1::bigint[])
             ORDER BY c.cable_id
        ");
        $st->execute([':ids1' => splice_export_id_array($cableIds)]);
        foreach ($st->fetchAll() ?: [] as $r) {
            $cables[] = [
                'cable_id'    => (int)$r['cable_id'],
                'name'        => $r['name'],
                'fibre_count' => (int)$r['fibre_count'],
                'open_ended'  => (bool)$r['open_ended'],
                'material'    => $r['material_sku'] === null ? null
                                 : ['sku' => $r['material_sku'], 'description' => $r['material_description']],
            ] + ($compact ? [] : ['notes' => $r['notes']]);
        }
    }

    // ── shape the devices, grouped by their home closure ──────────────────────
    $devicesByFosc = [];
    $popDevices    = [];
    foreach ($deviceRows as $r) {
        $dev = [
            'fosc_device_id' => (int)$r['fosc_device_id'],
            'name'           => $r['name'],
            'device_type'    => $r['device_type'],
            'attrs'          => json_decode($r['attrs_json'] ?? '{}', true) ?: [],
        ];
        if ($r['parent_device_id'] !== null) {
            $dev['parent_device_id'] = (int)$r['parent_device_id'];
            $dev['parent_slot']      = $r['parent_slot'] === null ? null : (int)$r['parent_slot'];
        }
        if (!$compact && ($r['notes'] ?? '') !== '') $dev['notes'] = $r['notes'];

        if ($r['fosc_id'] !== null) {
            $devicesByFosc[(int)$r['fosc_id']][] = $dev;
        } else {
            $dev['pop_id']   = (int)$r['pop_id'];
            $dev['pop_name'] = $r['pop_name'];
            $popDevices[]    = $dev;
        }
    }

    // ── spans by closure, so each closure lists what reaches it ───────────────
    $spanIdsByFosc = [];
    foreach ($spanRows as $r) {
        $spanIdsByFosc[(int)$r['from_fosc_id']][] = (int)$r['cable_span_id'];
        $spanIdsByFosc[(int)$r['to_fosc_id']][]   = (int)$r['cable_span_id'];
    }

    $closures = [];
    foreach ($closureRows as $r) {
        $fid = (int)$r['fosc_id'];
        $c = [
            'fosc_id'                => $fid,
            'splice_num'             => $r['splice_num'],
            'kind'                   => $r['kind'],
            'status'                 => $r['status'],
            'enclosure_rating'       => $r['enclosure_rating'],
            'closure_type'           => $r['closure_type'],
            'street_address'         => $r['street_address'],
            'work_order'             => $r['fpm_wor'],
            'additional_work_orders' => splice_export_split($r['additional_work_orders']),
            'pop'                    => $r['pop_name'],
            'devices'                => $devicesByFosc[$fid] ?? [],
            'installed_ont_count'    => (int)$r['ont_count'],
            'prepared_service_count' => (int)$r['prepared_service_count'],
        ];
        if (!$compact) {
            // span_ids rejoin from spans[]; kept in full mode so a closure reads standalone.
            $c['span_ids'] = array_values(array_unique($spanIdsByFosc[$fid] ?? []));
            $c['lat']      = $r['lat'] === null ? null : (float)$r['lat'];
            $c['lon']      = $r['lon'] === null ? null : (float)$r['lon'];
            $c['notes']    = $r['notes'];
        }
        $closures[] = $c;
    }

    $demand = splice_export_span_demand($spanRows, $closureRows);

    $spans = [];
    foreach ($spanRows as $r) {
        $fc   = (int)$r['fibre_count'];
        $used = (int)$r['fibres_used'];
        $dem  = $demand[(int)$r['cable_span_id']] ?? ['downstream_closures'=>null,'downstream_optitaps'=>null,'on_ring'=>false];
        $s = [
            'cable_span_id'          => (int)$r['cable_span_id'],
            'cable_id'               => (int)$r['cable_id'],
            'cable_name'             => $r['cable_name'],
            'fibre_count'            => $fc,
            'open_ended'             => (bool)$r['open_ended'],
            'from'                   => $compact ? (int)$r['from_fosc_id']
                                                  : ['fosc_id' => (int)$r['from_fosc_id'], 'splice_num' => $r['from_splice_num']],
            'to'                     => $compact ? (int)$r['to_fosc_id']
                                                  : ['fosc_id' => (int)$r['to_fosc_id'],   'splice_num' => $r['to_splice_num']],
            'length_m'               => $r['length_m'] === null ? null : (float)$r['length_m'],
            'tags'                   => splice_export_split($r['tags']),
            'work_order'             => $r['fpm_wor'],
            'additional_work_orders' => splice_export_split($r['additional_work_orders']),
            'is_spare'               => (bool)$r['is_spare'],
            'fibres_used'            => $used,
            'fibres_planned'         => (int)$r['fibres_planned'],
            'fibres_free'            => max(0, $fc - $used),
            'utilisation_pct'        => $fc > 0 ? round($used * 100 / $fc, 1) : null,
            'downstream_closures'    => $dem['downstream_closures'],
            'downstream_optitaps'    => $dem['downstream_optitaps'],
            'on_ring'                => $dem['on_ring'],
        ];
        if (!$compact) $s['notes'] = $r['notes'];
        $spans[] = $s;
    }

    $splices = [];
    foreach ($spliceRows as $r) {
        $j = [
            'splice_join_id' => (int)$r['splice_join_id'],
            'fosc_id'        => (int)$r['fosc_id'],
            'fosc_label'     => $r['fosc_label'],
            'join_kind'      => $r['join_kind'],
            'is_plan'        => (bool)$r['is_plan'],
            'a'              => splice_export_side($r, 'a', $compact),
            'b'              => splice_export_side($r, 'b', $compact),
        ];
        if (!$compact) {
            if (($r['notes'] ?? '') !== '') $j['notes'] = $r['notes'];
            $j['created_at'] = $r['created_at'];
        }
        $splices[] = $j;
    }

    // ── summary ───────────────────────────────────────────────────────────────
    $byFibreCount = [];
    $fibrePositions = 0;
    foreach ($spans as $s) {
        $k = (string)$s['fibre_count'];
        if (!isset($byFibreCount[$k])) {
            $byFibreCount[$k] = ['spans' => 0, 'fibres_used' => 0, 'spans_full' => 0];
        }
        $byFibreCount[$k]['spans']++;
        $byFibreCount[$k]['fibres_used'] += $s['fibres_used'];
        if ($s['fibre_count'] > 0 && $s['fibres_used'] >= $s['fibre_count']) {
            $byFibreCount[$k]['spans_full']++;
        }
    }
    ksort($byFibreCount, SORT_NUMERIC);
    foreach ($splices as $j) {
        $fibrePositions += ($j['a']['fibre_hi'] - $j['a']['fibre_lo'] + 1);
    }

    $checks = splice_export_checks($pdo, $foscIds);

    $payload = [
        'generated_at' => date('c'),
        'source'       => 'Tinuum fibre plant database',
        'scope'        => [
            'mode'          => ($opts['fosc_id'] ?? 0) ? 'closure_subtree' : (($opts['wor'] ?? '') ? 'work_order' : 'whole_plant'),
            'fosc_id'       => ($opts['fosc_id'] ?? 0) ?: null,
            'hops'          => ($opts['fosc_id'] ?? 0) ? (int)($opts['hops'] ?? 3) : null,
            'work_order'    => ($opts['wor'] ?? '') ?: null,
            'includes_plan' => $includePlan,
            'compact'       => $compact,
        ],
        'conventions'  => splice_export_conventions(),
        'summary'      => [
            'closures'               => count($closures),
            'closures_by_kind'       => array_count_values(array_column($closures, 'kind')),
            'cable_spans'            => count($spans),
            'cables'                 => count($cables),
            'devices'                => count($deviceRows),
            'splice_joins'           => count($splices),
            'planned_splice_joins'   => count(array_filter($splices, fn($j) => $j['is_plan'])),
            'fibre_positions'        => $fibrePositions,
            'installed_onts'         => array_sum(array_column($closures, 'installed_ont_count')),
            'spans_by_fibre_count'   => $byFibreCount,
            'check_errors'           => array_sum(array_map(fn($c) => $c['severity'] === 'error'   ? $c['count'] : 0, $checks)),
            'check_warnings'         => array_sum(array_map(fn($c) => $c['severity'] === 'warning' ? $c['count'] : 0, $checks)),
        ],
        'checks'   => $checks,
        'cables'   => $cables,
        'closures' => $closures,
        'spans'    => $spans,
        'splices'  => $splices,
        'pop_devices' => $popDevices,
    ];

    return $compact ? splice_export_prune($payload) : $payload;
}

/**
 * How much of the plant hangs off each end of each span.
 *
 * Cutting one span in two splits the closure graph into the side that still reaches a
 * POP (the source side) and the side that does not (downstream). Counting the optitap
 * drop terminals on the downstream side gives the closest thing this database has to
 * "premises served by this cable" — see the no_premises_data note in conventions.
 *
 * A span on a ring is not a cut edge: both ends still reach the POP without it, so
 * "downstream" is undefined and the counts are reported as null with on_ring = true.
 *
 * Pure graph work in PHP — 503 nodes and 435 edges, so the per-span BFS is cheap and
 * avoids a recursive CTE over the cycle-bearing span graph.
 *
 * @return array<int, array{downstream_closures:?int, downstream_optitaps:?int, on_ring:bool}>
 */
function splice_export_span_demand(array $spanRows, array $closureRows): array
{
    $isOptitap = [];
    $isSource  = [];
    foreach ($closureRows as $c) {
        $fid = (int)$c['fosc_id'];
        $isOptitap[$fid] = ($c['kind'] === 'optitap');
        $isSource[$fid]  = ($c['pop_name'] !== null);
    }

    // adjacency: fosc_id => [[span_id, neighbour_fosc_id], ...]
    $adj = [];
    foreach ($spanRows as $r) {
        $a = (int)$r['from_fosc_id'];
        $b = (int)$r['to_fosc_id'];
        $sid = (int)$r['cable_span_id'];
        $adj[$a][] = [$sid, $b];
        $adj[$b][] = [$sid, $a];
    }

    // Reachable set from $start, refusing to traverse span $blockSpan.
    $reach = function (int $start, int $blockSpan) use ($adj): array {
        $seen  = [$start => true];
        $queue = [$start];
        while ($queue) {
            $n = array_pop($queue);
            foreach ($adj[$n] ?? [] as [$sid, $next]) {
                if ($sid === $blockSpan || isset($seen[$next])) continue;
                $seen[$next] = true;
                $queue[] = $next;
            }
        }
        return $seen;
    };

    $out = [];
    foreach ($spanRows as $r) {
        $sid  = (int)$r['cable_span_id'];
        $from = (int)$r['from_fosc_id'];
        $to   = (int)$r['to_fosc_id'];

        $fromSide = $reach($from, $sid);
        if (isset($fromSide[$to])) {           // still connected without this span
            $out[$sid] = ['downstream_closures' => null, 'downstream_optitaps' => null, 'on_ring' => true];
            continue;
        }
        $toSide = $reach($to, $sid);

        $fromHasSource = (bool)array_filter(array_keys($fromSide), fn($f) => $isSource[$f] ?? false);
        $toHasSource   = (bool)array_filter(array_keys($toSide),   fn($f) => $isSource[$f] ?? false);

        if ($fromHasSource === $toHasSource) {
            // No POP on either side (or, impossibly, both): fall back to the smaller
            // side, which is the leaf end of any tree.
            $downstream = count($fromSide) <= count($toSide) ? $fromSide : $toSide;
        } else {
            $downstream = $fromHasSource ? $toSide : $fromSide;
        }

        $out[$sid] = [
            'downstream_closures' => count($downstream),
            'downstream_optitaps' => count(array_filter(array_keys($downstream), fn($f) => $isOptitap[$f] ?? false)),
            'on_ring'             => false,
        ];
    }
    return $out;
}

/** Split a string_agg CSV into a list, with null and '' both becoming []. */
function splice_export_split(?string $csv): array
{
    if ($csv === null || $csv === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
}

/**
 * Resolve one side of a splice join into a self-contained object: the polymorphic
 * id becomes a named field, and `ref` is the label a human would use.
 */
function splice_export_side(array $r, string $side, bool $compact = false): array
{
    $target = $r["{$side}_target"];
    $out = [
        'target'    => $target,
        'fibre_lo'  => (int)$r["{$side}_fibre_lo"],
        'fibre_hi'  => (int)$r["{$side}_fibre_hi"],
    ];

    if ($target === 'cable_span') {
        $out['cable_span_id'] = (int)$r["{$side}_span_id"];
        $out['span_end']      = $r["{$side}_end"];
        if ($compact) return $out;   // cable, far end and ref all rejoin from spans[]

        $out['cable_name']        = $r["{$side}_cable_name"];
        $out['cable_fibre_count'] = $r["{$side}_fibre_count"] === null ? null : (int)$r["{$side}_fibre_count"];
        // The closure at the OTHER end of this span — where these fibres are heading.
        $out['far_end'] = $r["{$side}_far_fosc_id"] === null ? null : [
            'fosc_id'    => (int)$r["{$side}_far_fosc_id"],
            'splice_num' => $r["{$side}_far_label"],
        ];
        $out['ref'] = trim(($r["{$side}_cable_name"] ?? 'span') . ' #' . (int)$r["{$side}_span_id"]
                    . ' (' . $r["{$side}_end"] . ' end)');
    } else {
        $out['fosc_device_id'] = (int)$r["{$side}_span_id"];
        // Device sides carry ports, not fibres; they share the range columns, so name
        // them both ways rather than making the reader know which applies.
        $out['port_lo'] = (int)$r["{$side}_fibre_lo"];
        $out['port_hi'] = (int)$r["{$side}_fibre_hi"];
        if ($compact) return $out;   // device name and type rejoin from closures[].devices

        $out['device_name'] = $r["{$side}_device_name"];
        $out['device_type'] = $r["{$side}_device_type"];
        $out['ref'] = trim(($r["{$side}_device_name"] ?? 'device') . ' [' . ($r["{$side}_device_type"] ?? '?') . ']');
    }

    return $out;
}

/**
 * Strip nulls and empty arrays from a nested structure. Used only in compact mode:
 * "absent" and "null" mean the same thing in this payload, and roughly a third of a
 * full export is `"notes": null` / `"tags": []`.
 */
function splice_export_prune(array $a): array
{
    // Only drop keys from associative arrays. Dropping an element of a list would
    // leave a gap in the numeric keys, and json_encode would then emit that list as
    // an object — silently changing the shape the reader is told to expect.
    $isList = array_is_list($a);

    $out = [];
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            $v = splice_export_prune($v);
            if ($v === [] && !$isList) continue;
        } elseif ($v === null && !$isList) {
            continue;
        }
        $out[$k] = $v;
    }
    return $isList ? array_values($out) : $out;
}
