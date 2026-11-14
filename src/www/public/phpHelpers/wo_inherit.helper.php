<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// Work-order inheritance: tagging a cable span or a messenger strand with a Tinuum
// permit pulls the untagged things on the same route onto that permit too.
//
// The permit lives on whatever the planner happened to tag — usually the cable —
// while the closures, FlexTIN taps and the strand carrying it stay blank, so the
// map's WO filter drops half a job. Rather than a bulk backfill, inheritance runs
// at save time from the thing you just tagged.
//
// TWO RULES, both deliberate:
//   • Blanks only. An asset that already carries any permit is never touched, so
//     inheritance can't quietly move work between permits.
//   • The permit becomes the asset's PRIMARY (it had none), mirrored into the
//     multi-permit join tables the same way the APIs write them.

require_once __DIR__ . '/route_coincidence.helper.php';

/** Does this closure have no permit at all (neither primary nor secondary)? */
function wo_fosc_is_untagged(PDO $pdo, int $foscId): bool
{
    $st = $pdo->prepare("
        SELECT COALESCE(NULLIF(f.fpm_wor, ''), '') = ''
           AND NOT EXISTS (SELECT 1 FROM app.fosc_work_order w WHERE w.fosc_id = f.fosc_id)
          FROM app.fosc f WHERE f.fosc_id = :id
    ");
    $st->execute([':id' => $foscId]);
    return (bool)$st->fetchColumn();
}

/** Tag one closure, primary + join row. Returns true if it was actually written. */
function wo_tag_fosc(PDO $pdo, int $foscId, string $wo): bool
{
    if ($foscId <= 0 || !wo_fosc_is_untagged($pdo, $foscId)) return false;
    $pdo->prepare("UPDATE app.fosc SET fpm_wor = :wo WHERE fosc_id = :id")
        ->execute([':wo' => $wo, ':id' => $foscId]);
    $pdo->prepare("INSERT INTO app.fosc_work_order (fosc_id, work_order, is_primary)
                   VALUES (:id, :wo, true) ON CONFLICT DO NOTHING")
        ->execute([':id' => $foscId, ':wo' => $wo]);
    return true;
}

/** Tag one span, primary + join row (migration 0151). */
function wo_tag_span(PDO $pdo, int $spanId, string $wo): bool
{
    $st = $pdo->prepare("
        SELECT COALESCE(NULLIF(cs.fpm_wor, ''), '') = ''
           AND NOT EXISTS (SELECT 1 FROM app.cable_span_work_order w WHERE w.cable_span_id = cs.cable_span_id)
          FROM app.cable_span cs WHERE cs.cable_span_id = :id
    ");
    $st->execute([':id' => $spanId]);
    if (!$st->fetchColumn()) return false;

    $pdo->prepare("UPDATE app.cable_span SET fpm_wor = :wo WHERE cable_span_id = :id")
        ->execute([':wo' => $wo, ':id' => $spanId]);
    $pdo->prepare("INSERT INTO app.cable_span_work_order (cable_span_id, work_order, is_primary)
                   VALUES (:id, :wo, true) ON CONFLICT DO NOTHING")
        ->execute([':id' => $spanId, ':wo' => $wo]);
    return true;
}

/**
 * The FlexTIN tail hanging off a closure: its optitaps and the little spans that
 * chain them (parent → L01 → L02 …). A tail is one continuous run of cable, so it
 * belongs to the same job as whatever feeds it.
 *
 * @return int rows written (taps + tail spans)
 */
function wo_inherit_flextin_from_fosc(PDO $pdo, int $foscId, string $wo): int
{
    if ($foscId <= 0 || trim($wo) === '') return 0;

    $st = $pdo->prepare("
        SELECT f.fosc_id FROM app.fosc f
         WHERE f.kind = 'optitap' AND (f.attrs->>'parent_fosc_id')::bigint = :id
    ");
    $st->execute([':id' => $foscId]);

    $written = 0;
    $seen    = [];
    $queue   = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    while ($queue) {
        $tapId = array_pop($queue);
        if (isset($seen[$tapId])) continue;
        $seen[$tapId] = true;
        if (wo_tag_fosc($pdo, $tapId, $wo)) $written++;

        $stChain = $pdo->prepare("
            SELECT cs.cable_span_id, cs.from_fosc_id, cs.to_fosc_id
              FROM app.cable_span cs
              JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
              JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
             WHERE (cs.from_fosc_id = :id1 OR cs.to_fosc_id = :id2)
               AND (f1.kind = 'optitap' OR f2.kind = 'optitap')
        ");
        $stChain->execute([':id1' => $tapId, ':id2' => $tapId]);
        foreach ($stChain->fetchAll(PDO::FETCH_ASSOC) as $hop) {
            if (wo_tag_span($pdo, (int)$hop['cable_span_id'], $wo)) $written++;
            foreach ([(int)$hop['from_fosc_id'], (int)$hop['to_fosc_id']] as $end) {
                if (!isset($seen[$end])) $queue[] = $end;
            }
        }
    }
    return $written;
}

/**
 * Everything mounted on one pole inherits its permit: the closures and slack loops
 * hanging on it, their FlexTIN tails, and the strands it anchors.
 *
 * Closures have no FK to a pole — they are simply mounted on one — so the tie is
 * proximity. WO_POLE_MOUNT_M is deliberately tight (taps sit a median 0.4 m from
 * their pole, closures ~10 m; indoor patch panels are ~95 m away and stay out).
 *
 * AMBIGUITY GUARD: a closure is only tagged when every permitted pole within reach
 * of it agrees on the permit. Where two jobs meet at a pole line, nothing is
 * guessed — those are left blank and reported by the backfill.
 *
 * @return array{closures:int,flextin:int,strands:int}
 */
const WO_POLE_MOUNT_M = 12;

function wo_inherit_from_pole(PDO $pdo, int $poleFid, string $wo): array
{
    $out = ['closures' => 0, 'flextin' => 0, 'strands' => 0];
    if ($poleFid <= 0 || trim($wo) === '') return $out;
    $r = WO_POLE_MOUNT_M;

    // Closures / slack loops / taps mounted on this pole, where no other permit
    // is in reach of them.
    $st = $pdo->prepare("
        SELECT f.fosc_id, f.kind::text AS kind
          FROM app.fosc f, app.pole p
         WHERE p.fid = :fid
           AND f.location IS NOT NULL AND p.geom IS NOT NULL
           AND ST_DWithin(f.location::geography, p.geom::geography, {$r})
           AND COALESCE(NULLIF(f.fpm_wor, ''), '') = ''
           AND NOT EXISTS (SELECT 1 FROM app.fosc_work_order w WHERE w.fosc_id = f.fosc_id)
           AND NOT EXISTS (
                 SELECT 1 FROM app.pole p2
                  WHERE p2.geom IS NOT NULL
                    AND ST_DWithin(f.location::geography, p2.geom::geography, {$r})
                    AND COALESCE(NULLIF(p2.fpm_wor, ''),
                          (SELECT w.work_order FROM app.pole_work_order w
                            WHERE w.pole_fid = p2.fid ORDER BY w.is_primary DESC, w.id LIMIT 1))
                        NOT IN (:wo1)
                    AND COALESCE(NULLIF(p2.fpm_wor, ''),
                          (SELECT w.work_order FROM app.pole_work_order w
                            WHERE w.pole_fid = p2.fid ORDER BY w.is_primary DESC, w.id LIMIT 1))
                        IS NOT NULL
               )
    ");
    $st->execute([':fid' => $poleFid, ':wo1' => $wo]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $foscId = (int)$row['fosc_id'];
        // A tap caught by proximity is FlexTIN, not a closure — keep the report honest.
        $bucket = in_array($row['kind'], ['optitap', 'mst'], true) ? 'flextin' : 'closures';
        if (wo_tag_fosc($pdo, $foscId, $wo)) $out[$bucket]++;
        $out['flextin'] += wo_inherit_flextin_from_fosc($pdo, $foscId, $wo);
    }

    // Strands anchored here, but only when BOTH end poles carry this permit — a
    // strand spanning two jobs belongs to neither by default.
    $stStrand = $pdo->prepare("
        UPDATE app.messenger_strand ms SET fpm_wor = :wo
         WHERE ms.fpm_wor IS NULL
           AND (ms.from_pole_fid = :fid1 OR ms.to_pole_fid = :fid2)
           AND (SELECT COALESCE(NULLIF(p.fpm_wor, ''),
                  (SELECT w.work_order FROM app.pole_work_order w
                    WHERE w.pole_fid = p.fid ORDER BY w.is_primary DESC, w.id LIMIT 1))
                  FROM app.pole p WHERE p.fid = ms.from_pole_fid) = :wo2
           AND (SELECT COALESCE(NULLIF(p.fpm_wor, ''),
                  (SELECT w.work_order FROM app.pole_work_order w
                    WHERE w.pole_fid = p.fid ORDER BY w.is_primary DESC, w.id LIMIT 1))
                  FROM app.pole p WHERE p.fid = ms.to_pole_fid) = :wo3
    ");
    $stStrand->execute([':wo' => $wo, ':fid1' => $poleFid, ':fid2' => $poleFid,
                        ':wo2' => $wo, ':wo3' => $wo]);
    $out['strands'] = $stStrand->rowCount();

    return $out;
}

/**
 * Everything on the route of one span inherits its permit.
 *
 * @return array{closures:int,flextin:int,strands:int} counts actually written
 */
function wo_inherit_from_span(PDO $pdo, int $spanId, string $wo): array
{
    $out = ['closures' => 0, 'flextin' => 0, 'strands' => 0];
    if ($spanId <= 0 || trim($wo) === '') return $out;

    $st = $pdo->prepare("SELECT from_fosc_id, to_fosc_id FROM app.cable_span WHERE cable_span_id = :id");
    $st->execute([':id' => $spanId]);
    $span = $st->fetch(PDO::FETCH_ASSOC);
    if (!$span) return $out;

    $ends = [(int)$span['from_fosc_id'], (int)$span['to_fosc_id']];

    // 1. The closures at each end.
    foreach ($ends as $foscId) {
        if (wo_tag_fosc($pdo, $foscId, $wo)) $out['closures']++;
    }

    // 2. The FlexTIN tail hanging off either end.
    foreach ($ends as $foscId) {
        $out['flextin'] += wo_inherit_flextin_from_fosc($pdo, $foscId, $wo);
    }

    // 3. The messenger strand(s) carrying this span — "the underlying strand".
    //    Same coincidence test the map uses to draw them as one cased line, so what
    //    inherits is exactly what looks like one route on screen.
    $alongside = features_runs_alongside('ms.geom', 'cs.geom');
    $stStrand = $pdo->prepare("
        UPDATE app.messenger_strand ms
           SET fpm_wor = :wo
          FROM app.cable_span cs
         WHERE cs.cable_span_id = :id
           AND ms.fpm_wor IS NULL
           AND ms.geom IS NOT NULL AND cs.geom IS NOT NULL
           AND {$alongside}
    ");
    $stStrand->execute([':wo' => $wo, ':id' => $spanId]);
    $out['strands'] = $stStrand->rowCount();

    return $out;
}

/**
 * Everything on the route of one strand inherits its permit: the cable spans drawn
 * along it, and in turn those spans' closures and FlexTIN tails.
 *
 * @return array{spans:int,closures:int,flextin:int,strands:int}
 */
function wo_inherit_from_strand(PDO $pdo, int $strandId, string $wo): array
{
    $out = ['spans' => 0, 'closures' => 0, 'flextin' => 0, 'strands' => 0];
    if ($strandId <= 0 || trim($wo) === '') return $out;

    // Spans whose length mostly runs along this strand (note the argument order:
    // here it's the SPAN that must lie inside the strand's corridor).
    $alongside = features_runs_alongside('cs.geom', 'ms.geom');
    $st = $pdo->prepare("
        SELECT cs.cable_span_id
          FROM app.cable_span cs, app.messenger_strand ms
         WHERE ms.id = :id
           AND cs.geom IS NOT NULL AND ms.geom IS NOT NULL
           AND COALESCE(NULLIF(cs.fpm_wor, ''), '') = ''
           AND NOT EXISTS (SELECT 1 FROM app.cable_span_work_order w WHERE w.cable_span_id = cs.cable_span_id)
           AND {$alongside}
    ");
    $st->execute([':id' => $strandId]);

    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $spanId) {
        if (!wo_tag_span($pdo, $spanId, $wo)) continue;
        $out['spans']++;
        $sub = wo_inherit_from_span($pdo, $spanId, $wo);
        $out['closures'] += $sub['closures'];
        $out['flextin']  += $sub['flextin'];
        $out['strands']  += $sub['strands'];
    }

    return $out;
}

/** "2 closures, 1 strand" — for the toast the map shows after a save. */
function wo_inherit_summary(array $counts): string
{
    $labels = [
        'spans'    => ['span', 'spans'],
        'closures' => ['closure', 'closures'],
        'flextin'  => ['FlexTIN section', 'FlexTIN sections'],
        'strands'  => ['strand', 'strands'],
    ];
    $parts = [];
    foreach ($labels as $key => [$one, $many]) {
        $n = (int)($counts[$key] ?? 0);
        if ($n > 0) $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
    }
    return implode(', ', $parts);
}
