<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/span_merge.helper.php — join two spans of one cable that meet at a plain splice point
// (closure or slack loop) as if the point were not there. Used by api/merge_span.php (the Merge button)
// and by closure delete, which joins the two spans of a mid-run closure before removing it.
//
// The caller owns the transaction. Throws RuntimeException with a message for the operator when the join
// would be wrong: different cables, a real junction (a third span), a splice or device at the point itself,
// an OTDR result on either span. Splices at the far ends of the spans are carried over to the joined span
// (same cable, so the fibre numbers do not change; only the span id and the end label do).

/**
 * @return array{span:int, removed_span:int, shared:int}
 */
function cable_spans_join(PDO $pdo, int $a, int $b): array
{
    if ($a <= 0 || $b <= 0 || $a === $b) throw new RuntimeException('Two different spans are required');

    $st = $pdo->prepare('SELECT cable_span_id, cable_id, from_fosc_id, to_fosc_id, geom_snap, geom_middle
                           FROM app.cable_span WHERE cable_span_id IN (?, ?) FOR UPDATE');
    $st->execute([$a, $b]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['cable_span_id']] = $r;
    if (count($rows) !== 2) throw new RuntimeException('Span not found');
    $A = $rows[$a]; $B = $rows[$b];
    if ($A['cable_id'] !== $B['cable_id']) throw new RuntimeException('The spans are on different cables');

    // The shared closure, and which end of each span sits on it.
    $shared = null; $aEndShared = null; $bEndShared = null;
    foreach (['from_fosc_id', 'to_fosc_id'] as $ae) {
        foreach (['from_fosc_id', 'to_fosc_id'] as $be) {
            if ($shared === null && $A[$ae] === $B[$be]) { $shared = (int)$A[$ae]; $aEndShared = $ae; $bEndShared = $be; }
        }
    }
    if ($shared === null) throw new RuntimeException('The spans do not meet at a closure');
    $aFar = (int)$A[$aEndShared === 'to_fosc_id' ? 'from_fosc_id' : 'to_fosc_id'];
    $bFar = (int)$B[$bEndShared === 'to_fosc_id' ? 'from_fosc_id' : 'to_fosc_id'];
    if ($aFar === $bFar) throw new RuntimeException('Joining would make a span that starts and ends at the same closure');

    $k = $pdo->prepare('SELECT kind::text, pop_id FROM app.fosc WHERE fosc_id = ?');
    $k->execute([$shared]);
    $kind = $k->fetch(PDO::FETCH_ASSOC);
    if (!$kind || !in_array($kind['kind'], ['closure', 'slack'], true) || $kind['pop_id'] !== null) {
        throw new RuntimeException('That site is a cabinet, POP, tap or terminal, a real site, not just a splice point');
    }
    $n = $pdo->prepare('SELECT count(*) FROM app.cable_span WHERE from_fosc_id = :f OR to_fosc_id = :f');
    $n->execute([':f' => $shared]);
    if ((int)$n->fetchColumn() !== 2) throw new RuntimeException('Another cable span also uses that closure, so it is a real junction');
    $sj = $pdo->prepare('SELECT count(*) FROM app.splice_join WHERE fosc_id = ? AND retired_at IS NULL');
    $sj->execute([$shared]);
    if ((int)$sj->fetchColumn() > 0) throw new RuntimeException('There are splices inside the closure; retire them first');
    $dv = $pdo->prepare('SELECT count(*) FROM app.fosc_device WHERE fosc_id = ?');
    $dv->execute([$shared]);
    if ((int)$dv->fetchColumn() > 0) throw new RuntimeException('The closure holds devices; remove them first');
    $ot = $pdo->prepare('SELECT count(*) FROM app.otdr_result WHERE cable_span_id IN (?, ?)');
    $ot->execute([$a, $b]);
    if ((int)$ot->fetchColumn() > 0) throw new RuntimeException('One of the spans has OTDR results; they describe the old length, so remove or keep them separate first');

    // Keep the span whose far end becomes the "from" end: F runs Ffar -> shared, S runs shared -> Sfar.
    $F = $A; $S = $B; $fEnd = $aEndShared; $sEnd = $bEndShared;
    $merged = (int)$F['cable_span_id'];
    $gF = $F['geom_snap'] ?? $F['geom_middle'];
    $gS = $S['geom_snap'] ?? $S['geom_middle'];
    $fFlip = ($fEnd === 'from_fosc_id');   // F's far end is its 'to': the line and the end labels are reversed
    $sFlip = ($sEnd === 'to_fosc_id');     // S's far end is its 'from'

    // End labels at the far ends: rows on S move to F's 'to'; rows on F keep or swap their label.
    $s2 = (int)$S['cable_span_id'];
    $relabel = function (int $from, int $to, string $oldEnd, string $newEnd) use ($pdo) {
        foreach (['a', 'b'] as $side) {
            $pdo->prepare("UPDATE app.splice_join SET {$side}_span_id = :to, {$side}_end = :ne::app.join_side
                            WHERE {$side}_target = 'cable_span' AND {$side}_span_id = :from AND {$side}_end = :oe::app.join_side")
                ->execute([':to' => $to, ':ne' => $newEnd, ':from' => $from, ':oe' => $oldEnd]);
        }
    };
    if ($fFlip) $relabel($merged, $merged, 'to', 'from');   // F's far end rows: 'to' -> 'from'
    $relabel($s2, $merged, $sFlip ? 'from' : 'to', 'to');    // S's far end rows -> F 'to'

    // For a moment the joined span and S both end at the far closure. A slack loop there would then have three spans,
    // which its check refuses, so a far-end slack loop is treated as a closure until S is gone, then put back.
    $farSlack = $pdo->prepare("SELECT fosc_id FROM app.fosc WHERE fosc_id IN (?, ?) AND kind = 'slack'");
    $farSlack->execute([$aFar, $bFar]);
    $farSlack = $farSlack->fetchAll(PDO::FETCH_COLUMN);
    foreach ($farSlack as $fs) $pdo->prepare("UPDATE app.fosc SET kind = 'closure' WHERE fosc_id = ?")->execute([$fs]);

    // Geometry of the joined line, first F's far end to the shared closure, then on to S's far end.
    $pdo->prepare("
        UPDATE app.cable_span s SET
          from_fosc_id = :from, to_fosc_id = :to,
          geom_middle = m.g, geom_snap = CASE WHEN :keepsnap THEN m.g END
        FROM (SELECT ST_RemoveRepeatedPoints(ST_MakeLine(
                 CASE WHEN :ff THEN ST_Reverse(ga.g) ELSE ga.g END,
                 CASE WHEN :sf THEN ST_Reverse(gb.g) ELSE gb.g END)) AS g
                FROM (SELECT ST_GeomFromEWKT(:ga) AS g) ga, (SELECT ST_GeomFromEWKT(:gb) AS g) gb) m
        WHERE s.cable_span_id = :id")
        ->execute([':from' => $aFar, ':to' => $bFar, ':keepsnap' => ($F['geom_snap'] !== null || $S['geom_snap'] !== null) ? 't' : 'f',
                   ':ff' => $fFlip ? 't' : 'f', ':sf' => $sFlip ? 't' : 'f', ':ga' => $gF, ':gb' => $gS, ':id' => $merged]);

    // Everything else that pointed at S now points at F.
    $pdo->prepare('INSERT INTO app.cable_span_work_order (cable_span_id, work_order, is_primary)
                   SELECT ?, work_order, false FROM app.cable_span_work_order WHERE cable_span_id = ?
                   ON CONFLICT DO NOTHING')->execute([$merged, $s2]);
    $pdo->prepare('UPDATE app.circuit_fibre cf SET cable_span_id = ? WHERE cable_span_id = ?
                    AND NOT EXISTS (SELECT 1 FROM app.circuit_fibre o WHERE o.circuit_id = cf.circuit_id AND o.cable_span_id = ? AND o.fibre_num = cf.fibre_num)')
        ->execute([$merged, $s2, $merged]);
    $pdo->prepare('UPDATE app.sheath_length SET cable_span_id = ? WHERE cable_span_id = ?')->execute([$merged, $s2]);
    $pdo->prepare('UPDATE app.cable_span_tag t SET cable_span_id = ? WHERE cable_span_id = ?
                    AND NOT EXISTS (SELECT 1 FROM app.cable_span_tag o WHERE o.span_tag_id = t.span_tag_id AND o.cable_span_id = ?)')
        ->execute([$merged, $s2, $merged]);
    $pdo->prepare('DELETE FROM app.cable_span WHERE cable_span_id = ?')->execute([$s2]);
    foreach ($farSlack as $fs) $pdo->prepare("UPDATE app.fosc SET kind = 'slack' WHERE fosc_id = ?")->execute([$fs]);

    return ['span' => $merged, 'removed_span' => $s2, 'shared' => $shared];
}
