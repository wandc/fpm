<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// phpHelpers/install_sheet.helper.php
// Model for the printable aerial install sheet: a permit's strand route walked pole by
// pole from the feed end, with span lengths, attachment, Bell make-ready notes (tree
// trimming, down guys, dead-ends), anchors (existing vs new), closures and slack loops.
//
// install_sheet_build($pdo, $permit, $startFid = null) returns
//   ['permit', 'bell_permits'=>[num], 'start_fid', 'starts'=>[fid=>label],
//    'stops'=>[ ['kind'=>'pole|anchor', 'fid', 'label', 'owner', 'street', 'span_m', 'run_m',
//                'attach', 'note', 'tt', 'guy', 'dend', 'anchors'=>[['status','new'=>bool,'dir']],
//                'fosc'=>[['splice_num','kind','slack_m','note','dist_m']] ] ],
//    'elsewhere'=>[ fosc rows not within reach of a stop ], 'off_route'=>[labels], 'warnings'=>[str] ]

require_once __DIR__ . '/pole_route.helper.php'; // pole_haversine_m()

if (!function_exists('install_sheet_build')) {

function install_sheet_attach(string $pos, string $note): string
{
    $map = ['bottom fieldside' => 'BFS', 'top fieldside' => 'TFS', 'middle fieldside' => 'MFS'];
    $a = $map[strtolower(trim($pos))] ?? trim($pos);
    // A pole can carry both strands; the Bell note says so as "New B.F.S" and "New T.F.S"
    $b = (bool)preg_match('/\bB\.?F\.?S\b/i', $note);
    $t = (bool)preg_match('/\bT\.?F\.?S\b/i', $note);
    if ($b && $t) return 'BFS + TFS';
    return $a;
}

function install_sheet_build(PDO $pdo, string $permit, ?int $startFid = null): array
{
    $out = ['permit' => $permit, 'bell_permits' => [], 'start_fid' => null, 'starts' => [], 'stops' => [],
            'elsewhere' => [], 'off_route' => [], 'warnings' => []];

    // ── Strand edges tagged to the permit (poles or building anchors at the ends) ──
    $st = $pdo->prepare("
        SELECT s.id, s.from_pole_fid AS fp, s.to_pole_fid AS tp, s.from_anchor_id AS fa, s.to_anchor_id AS ta,
               s.from_height, s.to_height, s.from_side, s.to_side, s.is_overlash
          FROM app.messenger_strand s
         WHERE s.fpm_wor = :pn AND COALESCE(s.status,'') <> 'retired'");
    $st->execute([':pn' => $permit]);
    $strands = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$strands) {
        $out['warnings'][] = 'No messenger strand is tagged to this work order, so there is no route to walk.';
        return $out;
    }

    // ── Nodes ──────────────────────────────────────────────────────────────────
    $poleIds = []; $anchorIds = [];
    foreach ($strands as $s) {
        foreach (['fp', 'tp'] as $k) if ($s[$k] !== null) $poleIds[(int)$s[$k]] = true;
        foreach (['fa', 'ta'] as $k) if ($s[$k] !== null) $anchorIds[(int)$s[$k]] = true;
    }
    $nodes = [];   // key 'p123' / 'a4' => info
    if ($poleIds) {
        $in = implode(',', array_keys($poleIds));
        $q = $pdo->query("
            SELECT p.fid, p.owner, p.street, ST_Y(p.geom) AS lat, ST_X(p.geom) AS lon,
                   COALESCE(NULLIF(p.\"nbp#\",''), NULLIF(p.alden_pole_tag,''), NULLIF(p.uid,''), 'Pole '||p.fid) AS label,
                   (p.fid IN (SELECT pole_fid FROM app.pole_work_order WHERE work_order = " . $pdo->quote($permit) . ")) AS in_wo
              FROM app.pole p WHERE p.fid IN ($in)");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nodes['p' . $r['fid']] = ['kind' => 'pole', 'fid' => (int)$r['fid'], 'label' => (string)$r['label'],
                'owner' => (string)$r['owner'], 'street' => (string)$r['street'],
                'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'in_wo' => (bool)$r['in_wo']];
        }
    }
    if ($anchorIds) {
        $in = implode(',', array_keys($anchorIds));
        $q = $pdo->query("SELECT id, name, ST_Y(geom) AS lat, ST_X(geom) AS lon FROM app.building_anchor WHERE id IN ($in)");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nodes['a' . $r['id']] = ['kind' => 'anchor', 'fid' => (int)$r['id'], 'label' => (string)$r['name'],
                'owner' => '', 'street' => 'Building anchor', 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'in_wo' => true];
        }
    }

    // ── Graph ──────────────────────────────────────────────────────────────────
    $adj = []; $seenPair = [];
    foreach ($strands as $s) {
        $a = $s['fp'] !== null ? 'p' . $s['fp'] : 'a' . $s['fa'];
        $b = $s['tp'] !== null ? 'p' . $s['tp'] : 'a' . $s['ta'];
        if (!isset($nodes[$a], $nodes[$b]) || $a === $b) continue;
        $pk = $a < $b ? "$a|$b" : "$b|$a";
        if (isset($seenPair[$pk])) continue;       // a bottom and top strand over the same span count once
        $seenPair[$pk] = true;
        $m = pole_haversine_m([$nodes[$a]['lat'], $nodes[$a]['lon']], [$nodes[$b]['lat'], $nodes[$b]['lon']]);
        $adj[$a][] = ['to' => $b, 'm' => $m];
        $adj[$b][] = ['to' => $a, 'm' => $m];
    }

    // ── Feed end: chosen, else a pole at the end of the line that is not on this work order,
    //    else the northernmost end pole ────────────────────────────────────────────────────────
    $ends = [];
    foreach ($adj as $k => $e) if (count($e) === 1 && $nodes[$k]['kind'] === 'pole') $ends[] = $k;
    foreach ($ends as $k) $out['starts'][$nodes[$k]['fid']] = $nodes[$k]['label'];
    $start = null;
    if ($startFid !== null && isset($nodes['p' . $startFid])) $start = 'p' . $startFid;
    if ($start === null) {
        foreach ($ends as $k) if (!$nodes[$k]['in_wo']) { $start = $k; break; }
    }
    if ($start === null && $ends) {
        usort($ends, fn($x, $y) => $nodes[$y]['lat'] <=> $nodes[$x]['lat']);
        $start = $ends[0];
    }
    if ($start === null) { $start = array_key_first($adj); }
    if ($start === null) { $out['warnings'][] = 'The strand on this work order does not connect any poles.'; return $out; }
    $out['start_fid'] = $nodes[$start]['kind'] === 'pole' ? $nodes[$start]['fid'] : null;

    // ── Longest simple path from the feed end (a single run is the normal case) ────────────────
    $best = function (string $at, array $seen) use (&$best, $adj): array {
        $seen[$at] = true; $top = [0.0, [$at]];
        foreach ($adj[$at] ?? [] as $e) {
            if (isset($seen[$e['to']])) continue;
            $sub = $best($e['to'], $seen);
            if ($e['m'] + $sub[0] > $top[0]) $top = [$e['m'] + $sub[0], array_merge([$at], $sub[1])];
        }
        return $top;
    };
    $path = $best($start, [])[1];
    $onPath = array_flip($path);
    foreach ($nodes as $k => $n) if (!isset($onPath[$k])) $out['off_route'][] = $n['label'];
    if ($out['off_route']) {
        $out['warnings'][] = 'These are on the work order strand but off the main run, so they are not on the sheet: '
                           . implode(', ', $out['off_route']) . '.';
    }

    // ── Bell make-ready notes for the work order ────────────────────────────────────────────
    $bp = $pdo->prepare("SELECT id, permit_num FROM app.bell_permit WHERE fpm_permit_num = :pn ORDER BY id");
    $bp->execute([':pn' => $permit]);
    $bellIds = [];
    foreach ($bp->fetchAll(PDO::FETCH_ASSOC) as $r) { $bellIds[] = (int)$r['id']; $out['bell_permits'][] = $r['permit_num']; }
    $bell = [];
    if ($bellIds) {
        $q = $pdo->query("SELECT pole_fid, attachment_position, notes FROM app.bell_permit_pole
                           WHERE permit_id IN (" . implode(',', $bellIds) . ") AND pole_fid IS NOT NULL ORDER BY id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $bell[(int)$r['pole_fid']] = $r;   // last row wins
    }

    // ── Anchors on the route's poles ────────────────────────────────────────────────────────
    $anchors = [];
    if ($poleIds) {
        $q = $pdo->query("SELECT pole_fid, status::text AS status, direction FROM app.anchor
                           WHERE pole_fid IN (" . implode(',', array_keys($poleIds)) . ") AND status::text <> 'retired' ORDER BY id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $anchors[(int)$r['pole_fid']][] = ['status' => $r['status'], 'new' => $r['status'] === 'plan', 'dir' => (string)$r['direction']];
        }
    }

    // ── Closures and slack loops: this order's, plus the ends of its cable spans ──────────────
    $fq = $pdo->prepare("
        SELECT f.fosc_id, f.splice_num, f.kind::text AS kind, f.slack_length_m, f.notes, f.status::text AS status,
               ST_Y(f.location) AS lat, ST_X(f.location) AS lon
          FROM app.fosc f
         WHERE f.kind IN ('closure','slack')
           AND ( f.fpm_wor = :pn
              OR f.fosc_id IN (SELECT fosc_id FROM app.fosc_work_order WHERE work_order = :pn2)
              OR f.fosc_id IN (SELECT from_fosc_id FROM app.cable_span WHERE fpm_wor = :pn3)
              OR f.fosc_id IN (SELECT to_fosc_id   FROM app.cable_span WHERE fpm_wor = :pn4) )");
    $fq->execute([':pn' => $permit, ':pn2' => $permit, ':pn3' => $permit, ':pn4' => $permit]);
    $foscs = $fq->fetchAll(PDO::FETCH_ASSOC);

    // ── Stops in order ──────────────────────────────────────────────────────────────────────
    $stopIdx = [];
    $run = 0.0; $prev = null;
    foreach ($path as $i => $k) {
        $n = $nodes[$k];
        $span = 0.0;
        if ($prev !== null) {
            foreach ($adj[$prev] as $e) if ($e['to'] === $k) { $span = $e['m']; break; }
        }
        $run += $span;
        $note = ''; $attach = ''; $tt = $guy = $dend = false;
        if ($n['kind'] === 'pole') {
            $b = $bell[$n['fid']] ?? null;
            if ($b) {
                $note = trim((string)$b['notes']);
                $attach = install_sheet_attach((string)$b['attachment_position'], $note);
                $tt   = (bool)preg_match('/tree trimming required|\bTT required/i', $note);
                // "no d.guy" is Bell saying there is none, so it must not count as one
                $guy  = (bool)preg_match('/(?<!no )d\.?\s?guy/i', $note);
                $dend = (bool)preg_match('/\bD\.?\s?end/i', $note);
            }
        }
        // a new anchor on the pole needs its down guy and guard whatever the Bell note says
        foreach ($anchors[$n['fid']] ?? [] as $an) if ($n['kind'] === 'pole' && $an['new']) $guy = true;
        $stopIdx[$k] = count($out['stops']);
        $out['stops'][] = ['kind' => $n['kind'], 'fid' => $n['fid'], 'label' => $n['label'], 'owner' => $n['owner'],
            'street' => $n['street'], 'span_m' => $span, 'run_m' => $run, 'attach' => $attach, 'note' => $note,
            'tt' => $tt, 'guy' => $guy, 'dend' => $dend,
            'anchors' => $n['kind'] === 'pole' ? ($anchors[$n['fid']] ?? []) : [], 'fosc' => [],
            'lat' => $n['lat'], 'lon' => $n['lon']];
        $prev = $k;
    }

    foreach ($foscs as $f) {
        $bestI = null; $bestD = INF;
        foreach ($out['stops'] as $i => $s) {
            $d = pole_haversine_m([$s['lat'], $s['lon']], [(float)$f['lat'], (float)$f['lon']]);
            if ($d < $bestD) { $bestD = $d; $bestI = $i; }
        }
        $row = ['splice_num' => $f['splice_num'], 'kind' => $f['kind'], 'slack_m' => $f['slack_length_m'],
                'status' => $f['status'], 'note' => trim((string)$f['notes']), 'dist_m' => $bestD,
                'near' => $bestI !== null ? $out['stops'][$bestI]['label'] : ''];
        if ($bestI !== null && $bestD <= 30.0) $out['stops'][$bestI]['fosc'][] = $row;
        else $out['elsewhere'][] = $row;
    }
    return $out;
}

} // function_exists guard
