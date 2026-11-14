<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// public/closure_sheet.php — printable splice work sheet for one closure, to hand to a splicing contractor.
// Renders WITHOUT the app layout, so the browser print dialog / "Save as PDF" gives a clean two-page sheet:
// One sheet: the closure, its cables, what to splice (plan), what is already built, then the latest timeline
// (newest first, older entries dropped to fit). It prints on one page when it fits and never more than two.
// ?fosc_id=N

require __DIR__ . '/../app/config.php';                 // $pdo
require __DIR__ . '/phpHelpers/app-helper.php';         // session, h()
require __DIR__ . '/../app/user_identity.php';          // fpm_current_identity()
require __DIR__ . '/phpHelpers/closure.helper.php';     // fetch_fosc_by_id, fetch_splices_at_fosc, device_port_label

$identity = fpm_current_identity();
if (!($identity['is_authenticated'] ?? false)) {
    http_response_code(401);
    exit('Not authenticated');
}

$foscId = (int)($_GET['fosc_id'] ?? 0);
if ($foscId <= 0) { http_response_code(400); exit('Bad request'); }
$fosc = fetch_fosc_by_id($pdo, $foscId);
if (!$fosc) { http_response_code(404); exit('Closure not found'); }

// ── Data ─────────────────────────────────────────────────────────────────────

$attrs  = json_decode((string)($fosc['attrs_json'] ?? '{}'), true) ?: [];
$street = trim((string)($attrs['street_address'] ?? ''));

// Nearest pole with an official NBP number, and an approximate address: the closure's saved street address,
// or failing that the nearest civic address within 150 m.
$st = $pdo->prepare("
    SELECT p.\"nbp#\" AS nbp, round(ST_Distance(p.geom::geography, f.location::geography)) AS d
      FROM app.fosc f, app.pole p
     WHERE f.fosc_id = :f AND p.\"nbp#\" ~ '^[0-9]{4}[A-Z0-9]*_'
     ORDER BY p.geom <-> f.location LIMIT 1");
$st->execute([':f' => $foscId]);
$pole = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$addrApprox = $street;
if ($addrApprox === '') {
    $st = $pdo->prepare("
        SELECT c.address FROM app.fosc f, app.nb_civic_address c
         WHERE f.fosc_id = :f AND ST_DWithin(c.geom::geography, f.location::geography, 150)
         ORDER BY c.geom <-> f.location LIMIT 1");
    $st->execute([':f' => $foscId]);
    $addrApprox = (string)($st->fetchColumn() ?: '');
}

$st = $pdo->prepare("SELECT work_order, is_primary FROM app.fosc_work_order WHERE fosc_id = :f ORDER BY is_primary DESC, work_order");
$st->execute([':f' => $foscId]);
$wos = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'work_order');
if (!$wos) {
    $one = $pdo->prepare("SELECT fpm_wor FROM app.fosc WHERE fosc_id = :f");
    $one->execute([':f' => $foscId]);
    if ($w = $one->fetchColumn()) $wos[] = $w;
}

// Cables on this closure: one row per span end here.
$st = $pdo->prepare("
    SELECT cs.cable_span_id, c.cable_id, c.name, c.fibre_count,
           CASE WHEN cs.from_fosc_id = :f THEN 'from' ELSE 'to' END AS here_end,
           far.splice_num AS far_label, far.kind::text AS far_kind, round(cs.length_m) AS length_m, cs.installed
      FROM app.cable_span cs
      JOIN app.cable c ON c.cable_id = cs.cable_id
      JOIN app.fosc far ON far.fosc_id = CASE WHEN cs.from_fosc_id = :f2 THEN cs.to_fosc_id ELSE cs.from_fosc_id END
     WHERE (cs.from_fosc_id = :f3 OR cs.to_fosc_id = :f4)
       AND NOT EXISTS (SELECT 1 FROM app.v_hidden_asset ha WHERE ha.kind = 'span' AND ha.id = cs.cable_span_id)
     ORDER BY c.fibre_count DESC, c.name, far.splice_num");
$st->execute([':f' => $foscId, ':f2' => $foscId, ':f3' => $foscId, ':f4' => $foscId]);
$cables = $st->fetchAll(PDO::FETCH_ASSOC);

$devices = fetch_devices_at_fosc($pdo, $foscId);

// Splicers care about the next closure in each direction, not the slack loops in between: follow each cable
// through any slack loops (they hold exactly two spans of one cable) to the first real closure, adding up the
// cable length, and give that closure's approximate address.
// Where a closure is, for a splicer: the road and its nearest cross street, else just a street address with
// no city or postal code.
function cs_where(array $i): string
{
    $road = trim(preg_replace('/^Rue\s+/i', '', (string)($i['on_road'] ?? '')));
    $cross = trim(preg_replace('/^Rue\s+/i', '', (string)($i['cross_road'] ?? '')));
    if ($road !== '' && $cross !== '') return $road . ' near ' . $cross;
    $addr = trim((string)(($i['street'] ?? '') ?: ($i['civic'] ?? '')));
    $addr = trim(explode(',', $addr)[0]);
    return $addr !== '' ? $addr : $road;
}
$next = [];   // span id at this closure => ['label', 'm', 'where', 'nbp']
{
    $other = $pdo->prepare("SELECT cable_span_id, CASE WHEN from_fosc_id = :x THEN to_fosc_id ELSE from_fosc_id END AS far, length_m
                              FROM app.cable_span WHERE cable_id = :c AND cable_span_id <> :s AND (from_fosc_id = :x2 OR to_fosc_id = :x3)");
    $info  = $pdo->prepare("SELECT f.splice_num, f.kind::text AS kind, f.attrs->>'street_address' AS street,
                                   (SELECT c.address FROM app.nb_civic_address c WHERE ST_DWithin(c.geom::geography, f.location::geography, 150)
                                     ORDER BY c.geom <-> f.location LIMIT 1) AS civic,
                                   (SELECT p.\"nbp#\" FROM app.pole p WHERE p.\"nbp#\" ~ '^[0-9]{4}[A-Z0-9]*_'
                                     ORDER BY p.geom <-> f.location LIMIT 1) AS nbp,
                                   o.road_name AS on_road,
                                   -- nearest road that actually meets the road the closure is on
                                   (SELECT r2.road_name FROM app.road_coverage r2
                                     WHERE r2.road_name IS NOT NULL AND r2.road_name <> o.road_name
                                       AND r2.geom && ST_Expand(f.location, 0.01)
                                       AND EXISTS (SELECT 1 FROM app.road_coverage r1 WHERE r1.road_name = o.road_name
                                                      AND r1.geom && ST_Expand(f.location, 0.01)
                                                      AND ST_DWithin(r1.geom::geography, r2.geom::geography, 8))
                                     ORDER BY r2.geom <-> f.location LIMIT 1) AS cross_road
                              FROM app.fosc f
                              LEFT JOIN LATERAL (SELECT road_name FROM app.road_coverage WHERE road_name IS NOT NULL
                                                  ORDER BY geom <-> f.location LIMIT 1) o ON true
                             WHERE f.fosc_id = :f");
    $info->execute([':f' => $foscId]);
    $own = $info->fetch(PDO::FETCH_ASSOC) ?: [];
    $crossHere = trim(preg_replace('/^Rue\s+/i', '', (string)($own['cross_road'] ?? '')));
    foreach ($cables as $c) {
        $spanId = (int)$c['cable_span_id'];
        $q = $pdo->prepare("SELECT cable_id, CASE WHEN from_fosc_id = :f THEN to_fosc_id ELSE from_fosc_id END AS far, length_m FROM app.cable_span WHERE cable_span_id = :s");
        $q->execute([':f' => $foscId, ':s' => $spanId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        $cableId = (int)$r['cable_id']; $at = (int)$r['far']; $m = (float)$r['length_m']; $cur = $spanId;
        for ($hop = 0; $hop < 30; $hop++) {
            $info->execute([':f' => $at]);
            $i = $info->fetch(PDO::FETCH_ASSOC);
            if (!$i || $i['kind'] !== 'slack') break;
            $other->execute([':x' => $at, ':c' => $cableId, ':s' => $cur, ':x2' => $at, ':x3' => $at]);
            $o = $other->fetch(PDO::FETCH_ASSOC);
            if (!$o) break;                       // the cable ends at the slack loop
            $cur = (int)$o['cable_span_id']; $m += (float)$o['length_m']; $at = (int)$o['far'];
        }
        $next[$spanId] = ['label' => $i['splice_num'] ?? '', 'm' => (int)round($m), 'where' => cs_where($i), 'nbp' => (string)($i['nbp'] ?? '')];
    }
}

// Where the FEED comes from: the nearest POP by cable length, found on the fly with a shortest-path search
// over every cable span. The span that leaves this closure on that path is the feed side.
$feed = null;   // ['pop' => name, 'm' => metres, 'span' => first span id, 'via' => [splice numbers], 'here' => bool]
{
    $popAt = [];
    foreach ($pdo->query("SELECT f.fosc_id, p.name FROM app.fosc f JOIN app.pop p ON p.pop_id = f.pop_id")->fetchAll(PDO::FETCH_NUM) as [$fid, $pn]) {
        $popAt[(int)$fid] = $pn;
    }
    if (isset($popAt[$foscId])) {
        $feed = ['pop' => $popAt[$foscId], 'm' => 0, 'span' => 0, 'via' => [], 'here' => true];
    } else {
        $adj = [];
        foreach ($pdo->query("SELECT cable_span_id, from_fosc_id, to_fosc_id, length_m FROM app.cable_span cs
                              WHERE NOT EXISTS (SELECT 1 FROM app.v_hidden_asset ha WHERE ha.kind = 'span' AND ha.id = cs.cable_span_id)")->fetchAll(PDO::FETCH_NUM) as [$sid, $a, $b, $len]) {
            $adj[(int)$a][] = [(int)$b, (float)$len, (int)$sid];
            $adj[(int)$b][] = [(int)$a, (float)$len, (int)$sid];
        }
        $dist = [$foscId => 0.0]; $prev = []; $done = []; $found = null; $dFound = 0.0;
        while ($dist) {
            asort($dist);
            $u = array_key_first($dist); $du = $dist[$u]; unset($dist[$u]);
            if (isset($done[$u])) continue;
            $done[$u] = true;
            if (isset($popAt[$u])) { $found = $u; $dFound = $du; break; }
            foreach ($adj[$u] ?? [] as [$v, $w, $sid]) {
                if (isset($done[$v])) continue;
                if (!isset($dist[$v]) || $du + $w < $dist[$v]) { $dist[$v] = $du + $w; $prev[$v] = [$u, $sid]; }
            }
        }
        if ($found !== null) {
            $path = []; $n = $found; $firstSpan = 0;
            while ($n !== $foscId) { [$u, $sid] = $prev[$n]; $path[] = $n; $firstSpan = $sid; $n = $u; }
            // Slack loops are not places a splicer goes, so they are left out of the route.
            $nums = $pdo->query("SELECT fosc_id, splice_num FROM app.fosc WHERE kind <> 'slack' AND fosc_id IN (" . implode(',', array_map('intval', $path)) . ")")->fetchAll(PDO::FETCH_KEY_PAIR);
            $feed = ['pop' => $popAt[$found], 'm' => (int)round($dFound), 'span' => $firstSpan,
                     'via' => array_values(array_filter(array_map(fn($i) => $nums[$i] ?? '', array_reverse($path)))), 'here' => false];
        }
    }
}

// Splices: plan (to do) and built (reference). Consecutive rows that join the same two cables the same way
// are merged, so twelve ribbon splices read as one line.
$all = fetch_splices_at_fosc($pdo, $foscId);
function cs_side(array $r, string $s): array
{
    $dev = $r[$s . '_target'] === 'fosc_device';
    $lo  = (int)$r[$s . '_fiber_lo'];
    $hi  = (int)$r[$s . '_fiber_hi'] - 1;
    if ($dev) {
        $name = trim((string)$r[$s . '_device_name']);
        $label = $name !== '' ? $name : device_type_label((string)$r[$s . '_device_type'], json_decode((string)($r[$s . '_device_attrs'] ?? '{}'), true) ?: []);
        return ['id' => 'd' . $r[$s . '_span_id'], 'span' => 0, 'name' => $label, 'far' => '', 'lo' => $lo, 'hi' => $hi, 'dev' => true];
    }
    $far = $GLOBALS['next'][(int)$r[$s . '_span_id']]['label'] ?? trim((string)($r[$s . '_far_fosc_label'] ?? ''));
    return ['id' => 's' . $r[$s . '_span_id'] . $r[$s . '_end'], 'span' => (int)$r[$s . '_span_id'], 'name' => (string)$r[$s . '_cable_name'], 'far' => $far, 'lo' => $lo, 'hi' => $hi, 'dev' => false];
}
function cs_range(array $x): string
{
    if ($x['dev']) return device_port_label($x['lo'], $x['hi'] + 1);
    return $x['lo'] === $x['hi'] ? 'F' . $x['lo'] : 'F' . $x['lo'] . '–' . $x['hi'];
}
function cs_merge(array $rows): array
{
    $items = [];
    foreach ($rows as $r) {
        $items[] = ['a' => cs_side($r, 'a'), 'b' => cs_side($r, 'b'), 'kind' => (string)$r['join_kind'],
                    'notes' => trim((string)$r['notes']), 'n' => 1, 'plan' => ($r['is_plan'] === true || $r['is_plan'] === 't')];
    }
    usort($items, fn($x, $y) => [$x['a']['id'], $x['a']['lo']] <=> [$y['a']['id'], $y['a']['lo']]);
    $out = [];
    foreach ($items as $it) {
        $p = end($out);
        if ($p && $p['a']['id'] === $it['a']['id'] && $p['b']['id'] === $it['b']['id'] && $p['kind'] === $it['kind']
            && $p['a']['hi'] + 1 === $it['a']['lo'] && $p['b']['hi'] + 1 === $it['b']['lo']) {
            $k = array_key_last($out);
            $out[$k]['a']['hi'] = $it['a']['hi'];
            $out[$k]['b']['hi'] = $it['b']['hi'];
            $out[$k]['n']++;
            if ($out[$k]['notes'] !== $it['notes']) $out[$k]['notes'] = '';
            continue;
        }
        $out[] = $it;
    }
    return $out;
}
// ── Splice type per cable, worked out from the splices: ─────────────────────────────────────────────────
// a cable with two spans at this closure either is CUT here (its two ends are spliced to each other: a butt
// splice) or passes through MID-SPAN (some fibres pass straight through uncut, the rest are cut and spliced out).
$spanCable = [];
foreach ($cables as $c) $spanCable[(int)$c['cable_span_id']] = (int)$c['cable_id'];
function cs_fibres(array $f): string
{
    $f = array_values(array_unique($f)); sort($f);
    $out = []; $i = 0;
    while ($i < count($f)) {
        $j = $i;
        while ($j + 1 < count($f) && $f[$j + 1] === $f[$j] + 1) $j++;
        $out[] = $i === $j ? 'F' . $f[$i] : 'F' . $f[$i] . '–' . $f[$j];
        $i = $j + 1;
    }
    return implode(', ', $out);
}
$cutCables = [];
foreach ($cables as $c) $cutCables[(int)$c['cable_id']][] = $c;
$spliceTypes = [];   // one line per cable that runs through this closure
foreach ($cutCables as $cid => $spans) {
    if (count($spans) < 2) continue;                 // the cable ends here, it does not run through
    $ids = array_map(fn($x) => (int)$x['cable_span_id'], $spans);
    $through = []; $butt = []; $out = [];
    foreach ($all as $r) {
        $aSp = $r['a_target'] === 'cable_span' ? (int)$r['a_span_id'] : 0;
        $bSp = $r['b_target'] === 'cable_span' ? (int)$r['b_span_id'] : 0;
        $touchA = in_array($aSp, $ids, true); $touchB = in_array($bSp, $ids, true);
        if (!$touchA && !$touchB) continue;
        $aFib = range((int)$r['a_fiber_lo'], (int)$r['a_fiber_hi'] - 1);
        $bFib = range((int)$r['b_fiber_lo'], (int)$r['b_fiber_hi'] - 1);
        if ($touchA && $touchB && $aSp !== $bSp) {                       // this cable's own two ends joined
            if ($r['join_kind'] === 'pass_through') $through = array_merge($through, $aFib);
            else $butt = array_merge($butt, $aFib);
        } else {                                                         // cut and spliced to something else
            $out = array_merge($out, $touchA ? $aFib : $bFib);
        }
    }
    $name = $spans[0]['name'];
    $through = array_values(array_diff(array_unique($through), $butt, $out));   // a fibre planned to be cut is not "uncut"
    if ($through) {
        $line = 'MIDSPAN: ' . cs_fibres($through) . ' pass through uncut'
              . ($butt || $out ? '; ' . cs_fibres(array_merge($butt, $out)) . ' cut here' : '; no fibres are cut here');
        $kind = 'mid';
    } elseif ($butt) {
        $line = 'BUTT SPLICE: the cable is cut here, ' . cs_fibres($butt) . ' spliced end to end'
              . ($out ? '; ' . cs_fibres($out) . ' also spliced out to other cables or devices' : '');
        $kind = 'butt';
    } elseif ($out) {
        $line = 'CUT here: ' . cs_fibres($out) . ' spliced to other cables or devices, the rest not yet spliced';
        $kind = 'cut';
    } else {
        $line = 'No splices recorded yet';
        $kind = 'none';
    }
    $spliceTypes[] = ['name' => $name, 'fibres' => (int)$spans[0]['fibre_count'], 'line' => $line, 'kind' => $kind];
}

$plan  = cs_merge(array_filter($all, fn($r) => $r['is_plan'] === true || $r['is_plan'] === 't'));
// Pass-throughs are fibre that is not cut, so there is nothing to see or do there (and a closure that used to be a
// slack loop keeps its old pass-through joins): they are left out here and summarised in the line above instead.
$built = cs_merge(array_filter($all, fn($r) => !($r['is_plan'] === true || $r['is_plan'] === 't') && $r['join_kind'] !== 'pass_through'));

// Timeline: newest first, as much as fits one page. Older entries are counted, not shown.
$entries = timeline_fetch_entries_for_parent($pdo, 'fosc', (string)$foscId);
// The timeline follows the splice tables on the same sheet, so it gets whatever room they leave: it fills one
// page when the closure is small, and runs onto a second page (never a third) when there are many splices.
$spliceLines = 16 + 2 * count($cables) + 2.4 * (max(1, count($plan)) + max(1, count($built))) + ($devices ? 3 : 0);
$TL_BUDGET = (int)min(66, max(12, 132 - $spliceLines));   // text lines available for the timeline
$shown = []; $used = 0;
$mediaSt = $pdo->prepare("
    SELECT timeline_media_id,
           CASE WHEN exif_created_at IS NULL OR exif_created_at = date_trunc('second', created_at AT TIME ZONE 'UTC') THEN NULL
                ELSE to_char(exif_created_at, 'YYYY-MM-DD HH24:MI') END AS taken
      FROM app.timeline_media WHERE timeline_entry_id = :e
     ORDER BY exif_created_at NULLS LAST, created_at LIMIT 4");
foreach ($entries as $e) {
    $text = trim((string)($e['body_text'] ?? ''));
    if (mb_strlen($text) > 320) $text = rtrim(mb_substr($text, 0, 320)) . '…';
    $mediaSt->execute([':e' => (int)$e['timeline_entry_id']]);
    $media = $mediaSt->fetchAll(PDO::FETCH_ASSOC);
    $cost = 1 + max(1, (int)ceil(mb_strlen($text) / 120)) + ($media ? 4 : 0);
    if ($shown && $used + $cost > $TL_BUDGET) break;
    $used += $cost;
    $shown[] = ['date' => substr((string)$e['created_at'], 0, 16), 'text' => $text, 'media' => $media];
}
$hiddenEntries = count($entries) - count($shown);

$coords = ($fosc['lat'] !== null && $fosc['lon'] !== null) ? sprintf('%.6f, %.6f', (float)$fosc['lat'], (float)$fosc['lon']) : '';

function cs_cell(array $x): string
{
    $s = '<strong>' . h($x['name']) . '</strong>';
    if ($x['far'] !== '') $s .= ' <span class="muted">→ ' . h($x['far']) . '</span>';
    if ($GLOBALS['feed'] && !$GLOBALS['feed']['here'] && $x['span'] > 0 && $x['span'] === $GLOBALS['feed']['span']) $s .= ' <span class="feedtag">FEED</span>';
    return $s . '<div class="rng">' . h(cs_range($x)) . '</div>';
}
function cs_rows(array $rows, bool $plan): void
{
    if (!$rows) { echo '<tr><td colspan="4" class="muted">None.</td></tr>'; return; }
    foreach ($rows as $r) {
        $kind = match ($r['kind']) { 'ribbon' => 'Ribbon', 'pass_through' => 'Pass-through', 'connector' => 'Connector', default => 'Single' };
        // a cable spliced onto a panel's pigtails is a splice (the connectors are factory-fitted on the pigtails)
        if ($r['kind'] === 'connector' && ($r['a']['dev'] xor $r['b']['dev'])) {
            $kind = ($r['a']['hi'] - $r['a']['lo'] + 1) / max(1, $r['n']) > 1 ? 'Ribbon' : 'Single';
        }
        if ($r['n'] > 1) $kind .= ' ×' . $r['n'];
        // Two ends of the same cable spliced together (not a pass-through) means the cable is cut here: a butt splice.
        $butt = $r['kind'] !== 'pass_through' && !$r['a']['dev'] && !$r['b']['dev']
             && ($GLOBALS['spanCable'][$r['a']['span']] ?? -1) === ($GLOBALS['spanCable'][$r['b']['span']] ?? -2)
             && $r['a']['span'] !== $r['b']['span'];
        if ($butt) $kind = 'BUTT SPLICE <span class="muted" style="font-weight:400">(' . $kind . '; the cable is cut here)</span>';
        echo '<tr>';
        if ($plan) echo '<td class="chk">&#9744;</td>';
        echo '<td>' . cs_cell($r['a']) . '</td><td>' . cs_cell($r['b']) . '</td><td>' . ($butt ? '<strong>' . $kind . '</strong>' : h($kind))
           . ($r['notes'] !== '' ? '<div class="muted">' . h($r['notes']) . '</div>' : '') . '</td></tr>';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Splice sheet <?= h($fosc['splice_num']) ?></title>
<style>
  @page { size: Letter; margin: 0.45in; }
  * { box-sizing: border-box; }
  :root { --ink:#1f2a37; --accent:#5ba71c; --accent-dark:#46831a; --muted:#6b7280; --line:#d9dee3; --soft:#f3f8ed; }
  body { margin:0; font: 10.5px/1.35 "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color:var(--ink);
         -webkit-print-color-adjust:exact; print-color-adjust:exact; background:#fff; }
  .toolbar { position:sticky; top:0; background:#1f2a37; color:#fff; padding:8px 14px; text-align:center; font-size:13px; }
  .toolbar button { margin-left:10px; padding:5px 14px; border:0; border-radius:6px; background:var(--accent); color:#fff; font-weight:600; cursor:pointer; }
  .page { width:7.6in; margin:0 auto; padding:.15in 0; }
  @media print { .toolbar { display:none; } .page { padding:0; } }
  @media screen { body { background:#eef0f2; } .page { background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.15); padding:.3in; margin:14px auto; } }
  .head { display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px solid var(--accent); padding-bottom:8px; }
  .head img { height:34px; }
  .head .t { text-align:right; }
  .head .t small { display:block; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); font-size:9px; }
  .head .t b { font-size:24px; letter-spacing:-.5px; }
  .badge { display:inline-block; border-radius:4px; padding:1px 7px; font-size:10px; font-weight:700; background:#ffc107; color:#222; vertical-align:middle; }
  .badge.active { background:#198754; color:#fff; }
  .badge.tbi { letter-spacing:.04em; margin-top:2px; }   /* same yellow as the PLAN badge */
  .meta { display:grid; grid-template-columns: repeat(4, 1fr); gap:4px 14px; margin:9px 0 4px; }
  .meta div small { display:block; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); font-size:8.5px; }
  .meta div { font-size:11px; }
  h2 { margin:13px 0 4px; font-size:10px; letter-spacing:.09em; text-transform:uppercase; color:var(--accent-dark); }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:8.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); border-bottom:1.5px solid var(--ink); padding:3px 5px; }
  td { padding:4px 5px; border-bottom:1px solid var(--line); vertical-align:top; }
  .muted { color:var(--muted); }
  .feedtag { display:inline-block; background:#1f2a37; color:#fff; border-radius:3px; padding:0 5px; font-size:8.5px; font-weight:700; letter-spacing:.06em; vertical-align:1px; }
  .feedbox { background:#1f2a37; color:#fff; border-radius:6px; padding:6px 10px; margin:8px 0 0; display:flex; justify-content:space-between; gap:14px; align-items:baseline; font-size:11px; }
  .feedbox b { font-size:13px; }
  .feedbox small { color:#cbd5e1; }
  .rng { font-family: Menlo, Consolas, monospace; font-size:11px; font-weight:600; }
  .chk { width:18px; font-size:14px; line-height:1; }
  tr { page-break-inside:avoid; }
  .note { background:var(--soft); border-left:3px solid var(--accent); padding:5px 8px; margin-top:6px; }
  .tl { border-top:1px solid var(--line); padding:5px 0; page-break-inside:avoid; }
  .tl .d { color:var(--muted); font-size:9.5px; }
  .tl .imgs { display:flex; gap:4px; margin-top:3px; }
  .tl .imgs div { text-align:center; font-size:8px; color:var(--muted); }
  .tl img { width:58px; height:58px; object-fit:cover; border:1px solid var(--line); border-radius:3px; display:block; }
  .foot { margin-top:10px; font-size:8.5px; color:var(--muted); display:flex; justify-content:space-between; }
</style>
</head>
<body>
<div class="toolbar">Choose <b>Save as PDF</b> as the destination to keep a copy.
  <button onclick="window.print()">Print / Save as PDF</button></div>

<!-- ───────── Sheet ───────── -->
<div class="page">
  <div class="head">
    <img src="<?= h(org('logo')) ?>" alt="<?= h(org('legal_name')) ?>">
    <div class="t"><small>Splice work sheet</small>
      <b><?= h($fosc['splice_num']) ?></b>
      <span class="badge <?= $fosc['status'] === 'active' ? 'active' : '' ?>"><?= h(strtoupper((string)$fosc['status'])) ?></span></div>
  </div>

  <div class="meta">
    <div><small>Closure</small><?= h($fosc['closure_type']) ?>
      <?php if ($fosc['status'] === 'plan'): ?><div><span class="badge tbi">TO BE INSTALLED</span></div>
      <?php elseif ($fosc['status'] === 'under construction'): ?><div><span class="badge tbi">BEING INSTALLED</span></div><?php endif; ?></div>
    <div><small>Location (lat, lon)</small><?= h($coords ?: '—') ?></div>
    <div><small>Nearest pole (NBP #)</small><?= $pole ? '<strong>' . h($pole['nbp']) . '</strong> <span class="muted">(' . (int)$pole['d'] . ' m)</span>' : '—' ?>
      <small style="margin-top:3px">Address (approximate)</small><?= $addrApprox !== '' ? h($addrApprox) : '—' ?>
      <?php if ($crossHere !== ''): ?><div class="muted">Nearest cross street: <strong><?= h($crossHere) ?></strong></div><?php endif; ?></div>
    <div><small>Work order</small><?= h($wos ? implode(', ', $wos) : '—') ?></div>
  </div>
  <div class="feedbox">
    <?php if (!$feed): ?>
      <span><b>FEED:</b> no POP found on the cable network from here.</span>
    <?php elseif ($feed['here']): ?>
      <span><b>FEED:</b> this closure is at <b><?= h($feed['pop']) ?></b> (the feed point).</span>
    <?php else: ?>
      <span><b>FEED from <?= h($feed['pop']) ?></b>, about <?= number_format($feed['m']) ?> m of cable away</span>
      <small>via <?= h(implode(' › ', $feed['via'])) ?></small>
    <?php endif; ?>
  </div>
  <?php if (trim((string)$fosc['notes']) !== ''): ?><div class="note"><?= nl2br(h(trim((string)$fosc['notes']))) ?></div><?php endif; ?>

  <h2>Cables at this closure</h2>
  <table>
    <thead><tr><th>Cable</th><th>Fibres</th><th>Next closure</th><th>Distance</th><th>Side</th></tr></thead>
    <tbody>
    <?php foreach ($cables as $c): ?>
      <tr><td><strong><?= h($c['name']) ?></strong></td><td><?= (int)$c['fibre_count'] ?>F</td>
          <td><strong><?= h($next[(int)$c['cable_span_id']]['label']) ?></strong>
              <?php if (($next[(int)$c['cable_span_id']]['nbp'] ?? '') !== ''): ?><div>Pole <strong><?= h($next[(int)$c['cable_span_id']]['nbp']) ?></strong></div><?php endif; ?>
              <?php if (($next[(int)$c['cable_span_id']]['where'] ?? '') !== ''): ?><div class="muted"><?= h($next[(int)$c['cable_span_id']]['where']) ?></div><?php endif; ?></td>
          <td><?= number_format($next[(int)$c['cable_span_id']]['m']) ?> m</td>
          <td><?= $feed && !$feed['here'] && (int)$c['cable_span_id'] === $feed['span'] ? '<span class="feedtag">FEED</span>' : ($feed && !$feed['here'] ? '<span class="muted">downstream</span>' : '') ?></td></tr>
    <?php endforeach; if (!$cables): ?><tr><td colspan="5" class="muted">No cables connected.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($spliceTypes): ?>
    <div class="note" style="margin-top:8px">
      <?php foreach ($spliceTypes as $t): ?>
        <div><strong><?= h($t['name']) ?>:</strong> <?= h($t['line']) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>To splice (plan)</h2>
  <table>
    <thead><tr><th></th><th>Cable A</th><th>Cable B</th><th>Type</th></tr></thead>
    <tbody><?php cs_rows($plan, true); ?></tbody>
  </table>

  <h2>Already built here (do not disturb)</h2>
  <table>
    <thead><tr><th>Cable A</th><th>Cable B</th><th>Type</th></tr></thead>
    <tbody><?php cs_rows($built, false); ?></tbody>
  </table>

  <?php if ($devices): ?>
    <h2>Devices in this closure</h2>
    <div><?php foreach ($devices as $d): ?><span style="margin-right:12px"><strong><?= h($d['name'] ?? '') ?></strong>
      <span class="muted"><?= h(device_type_label((string)($d['device_type'] ?? ''), is_array($d['attrs'] ?? null) ? $d['attrs'] : [])) ?></span></span><?php endforeach; ?></div>
  <?php endif; ?>

  <h2>Timeline</h2>
  <?php if (!$shown): ?>
    <p class="muted">No timeline entries yet.</p>
  <?php endif; ?>
  <?php foreach ($shown as $t): ?>
    <div class="tl">
      <div class="d"><?= h($t['date']) ?></div>
      <?php if ($t['text'] !== ''): ?><div><?= nl2br(h($t['text'])) ?></div><?php endif; ?>
      <?php if ($t['media']): ?><div class="imgs">
        <?php foreach ($t['media'] as $m): ?>
          <div><img src="/media.php?id=<?= (int)$m['timeline_media_id'] ?>" alt="">
            <?= $m['taken'] !== null ? h(substr($m['taken'], 5)) : '' ?></div>
        <?php endforeach; ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($hiddenEntries > 0): ?>
    <p class="muted" style="margin-top:8px">+ <?= $hiddenEntries ?> older timeline entr<?= $hiddenEntries === 1 ? 'y' : 'ies' ?> not shown. See the closure page for all of them.</p>
  <?php endif; ?>

  <div class="foot"><span><?= h(implode(' · ', array_filter([org('legal_name'), org('support_email'), org('phone')]))) ?> · Report any difference from this sheet to <?= h(org('name')) ?> before closing the enclosure.</span><span>Printed <?= date('Y-m-d H:i') ?></span></div>
</div>
</body>
</html>
