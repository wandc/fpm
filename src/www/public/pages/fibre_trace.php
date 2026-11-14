<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/fibre_trace.php - Fibre & Light Path Tracing

/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF, $traceFoscOnts, $traceFoscLeads, $traceFoscServices, $traceSlackFoscs, $oltTxDbm, $connectorLoss;

require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

// --- Helper functions ---

function fetch_trace_physical(PDO $pdo, int $spanId, int $fibre, string $end, bool $followPlan = false): array {
    $st = $pdo->prepare("
        SELECT * FROM app.trace_fiber_path(:span, :fibre, :end::app.span_end, :plan::boolean, 500)
    ");
    $st->execute([':span' => $spanId, ':fibre' => $fibre, ':end' => $end, ':plan' => $followPlan ? 'true' : 'false']);
    return $st->fetchAll() ?: [];
}

function fetch_trace_light(PDO $pdo, int $spanId, int $fibre, string $end, bool $followPlan = false): array {
    $st = $pdo->prepare("
        SELECT * FROM app.trace_light_path(:span, :fibre, :end::app.span_end, :plan::boolean, 500)
    ");
    $st->execute([':span' => $spanId, ':fibre' => $fibre, ':end' => $end, ':plan' => $followPlan ? 'true' : 'false']);
    return $st->fetchAll() ?: [];
}

function fetch_span_info(PDO $pdo, int $spanId): ?array {
    $st = $pdo->prepare("SELECT * FROM app.get_span_fiber_info(:span)");
    $st->execute([':span' => $spanId]);
    return $st->fetch() ?: null;
}

/** Return ITU fiber color code for a given 1-based fiber number.
 *  Fibers 1-12 return just the fiber color (e.g. "BL", "OR").
 *  Fibers 13+ return tube/fiber (e.g. "OR/BL" = orange tube, blue fiber). */
function itu_fiber_code(int $fiber): string {
    static $c = ['BL','OR','GR','BR','SL','WH','RD','BK','YL','VT','RS','AQ'];
    if ($fiber <= 0) return '';
    $i    = ($fiber - 1) % 12;
    $tube = (int)(($fiber - 1) / 12);
    return $tube === 0 ? $c[$i] : $c[$tube % 12] . '/' . $c[$i];
}

/** Inline HTML swatch + code for a fiber number. */
function itu_fiber_html(int $fiber): string {
    static $css = [
        'BL' => '#0066cc', 'OR' => '#f37320', 'GR' => '#00a651', 'BR' => '#795548',
        'SL' => '#90a4ae', 'WH' => '#e0e0e0', 'RD' => '#e53935', 'BK' => '#424242',
        'YL' => '#fdd835', 'VT' => '#8e24aa', 'RS' => '#e91e63', 'AQ' => '#00bcd4',
    ];
    $code  = itu_fiber_code($fiber);
    if ($code === '') return '';
    // For tube/fiber codes, colour the swatch using the fiber (right-hand) color
    $fiberCode = str_contains($code, '/') ? substr($code, strpos($code, '/') + 1) : $code;
    $bg    = $css[$fiberCode] ?? '#888';
    return '<span style="display:inline-block;width:32px;height:8px;border-radius:2px;'
         . 'background:' . $bg . ';border:1px solid rgba(0,0,0,.25);vertical-align:middle;margin-left:4px"></span>';
}

// --- Input handling ---

$traceType  = $_GET['type'] ?? 'physical';  // 'physical' or 'light'
$spanId     = isset($_GET['span_id']) ? (int)$_GET['span_id'] : 0;
$fibre      = isset($_GET['fibre']) ? (int)$_GET['fibre'] : 0;
$followPlan = isset($_GET['plan']) && $_GET['plan'] === '1';
// $end kept for backwards-compat with old links but no longer used for tracing

// Always trace both directions — spans are bidirectional
$traceResultsFrom = [];  // inject at FROM end → traverses toward TO-end FOSC
$traceResultsTo   = [];  // inject at TO end   → traverses toward FROM-end FOSC
$spanInfo = null;
$error = null;

// ONU/lead data for leaf closure display (populated below when tracing)
$traceFoscOnts  = [];   // int fosc_id → [['uisp_device_id', 'name', 'signal', 'status'], ...]
$traceFoscLeads = [];   // int fosc_id → [['crm_client_id', 'name'], ...]
$traceFoscServices = []; // int fosc_id → [['crm_service_id', 'name', 'service_name', 'address'], ...]
$traceSlackFoscs = []; // int fosc_id → true for all closures with kind='slack'
$oltTxDbm       = 3.25; // default OLT class B+ TX midpoint, overridden from loss_param
$connectorLoss  = 0.35; // SC/UPC connector pair loss (dB), overridden from loss_param

if ($spanId > 0 && $fibre > 0) {
    try {
        $spanInfo = fetch_span_info($pdo, $spanId);
        if (!$spanInfo) {
            $error = "Cable span #{$spanId} not found.";
        } elseif ($fibre > ($spanInfo['fiber_count'] ?? 0)) {
            $error = "Fibre #{$fibre} exceeds cable capacity ({$spanInfo['fiber_count']} fibres).";
        } else {
            if ($traceType === 'light') {
                $traceResultsFrom = fetch_trace_light($pdo, $spanId, $fibre, 'from', $followPlan);
                $traceResultsTo   = fetch_trace_light($pdo, $spanId, $fibre, 'to',   $followPlan);
            } else {
                $traceResultsFrom = fetch_trace_physical($pdo, $spanId, $fibre, 'from', $followPlan);
                $traceResultsTo   = fetch_trace_physical($pdo, $spanId, $fibre, 'to',   $followPlan);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

}
// Combined for count/empty checks
$traceResults = array_merge($traceResultsFrom, $traceResultsTo);

// ── Trace-tree PHP helpers ─────────────────────────────────────────────

/** Group trace rows by branch_path (sorted by hop_num within each group). */
function trace_build_bmap(array $rows): array {
    $m = [];
    foreach ($rows as $r) { $m[(string)($r['branch_path'] ?? '1')][] = $r; }
    foreach ($m as &$v) { usort($v, fn($a,$b) => (int)$a['hop_num'] - (int)$b['hop_num']); }
    return $m;
}

/** Direct child branch paths of $p (one level deeper only). */
function trace_kids(string $p, array $bm): array {
    $pfx = $p.'.'; $len = strlen($pfx); $kids = [];
    foreach (array_keys($bm) as $b)
        if (str_starts_with($b, $pfx) && !str_contains(substr($b, $len), '.')) $kids[] = $b;
    sort($kids, SORT_NATURAL);
    return $kids;
}

/** All terminal leaf hops reachable from $bp (recursively). */
function trace_all_leaves_bm(string $bp, array $bm): array {
    $kids = trace_kids($bp, $bm);
    if (!$kids) {
        $hops = $bm[$bp] ?? [];
        foreach (array_reverse($hops) as $h) if (empty($h['device_type'])) return [$h];
        return $hops ? [end($hops)] : [];
    }
    $out = [];
    foreach ($kids as $k) $out = array_merge($out, trace_all_leaves_bm($k, $bm));
    return $out;
}

function tloss(float $l): string { return $l < 28 ? 'loss-good' : ($l < 32 ? 'loss-marginal' : 'loss-bad'); }

/** Bootstrap badge class for a received GPON signal level (dBm). */
function tfc_rx_class(float $dbm): string {
    if ($dbm > -6.5)   return 'loss-bad';       // too strong
    if ($dbm > -8.0)   return 'loss-marginal';  // upper edge
    if ($dbm >= -28.0) return 'loss-good';       // normal range
    if ($dbm >= -29.5) return 'loss-marginal';  // lower edge
    return 'loss-bad';                           // too weak
}

/** Render ONU/lead subscriber pills for a closure at the end of a branch.
 *  $closureDbm: the already-computed absolute receive level at the optitap closure
 *  (= OLT_TX − total_path_loss including upstream offset). When provided, lead pills
 *  show an estimated ONU signal = closureDbm − 2 connector losses. */
function trace_onu_pills(int $foscId, ?float $closureDbm = null): string {
    global $traceFoscOnts, $traceFoscLeads, $traceFoscServices, $connectorLoss;
    $out = '';
    foreach ($traceFoscOnts[$foscId] ?? [] as $o) {
        $sig    = $o['signal'] !== null ? gpon_signal_info((float)$o['signal']) : ['color' => '#6c757d'];
        $sigStr = $o['signal'] !== null
            ? ' <span style="color:'.h($sig['color']).';font-size:11px;font-weight:600">'.h((string)$o['signal']).' dBm</span>'
            : '';
        $out .= '<span class="trace-onu-pill">⌂ '.h($o['name']).$sigStr.'</span>';
    }
    foreach ($traceFoscLeads[$foscId] ?? [] as $l) {
        $estStr = '';
        if ($closureDbm !== null) {
            $estDbm = $closureDbm - 2 * $connectorLoss;
            $sig    = gpon_signal_info((float)$estDbm);
            $estStr = ' <span style="color:'.h($sig['color']).';font-size:11px;font-weight:600">~'.h(number_format($estDbm, 1)).' dBm</span>';
        }
        $out .= '<span class="trace-onu-pill trace-onu-pill--lead">⌂ '.h($l['name'])
              . $estStr
              . ' <span class="badge bg-warning text-dark" style="font-size:10px">lead</span></span>';
    }
    foreach ($traceFoscServices[$foscId] ?? [] as $s) {
        // Same estimate as a lead: nothing is spliced yet, so the only figure we can
        // offer is the closure level less the two connectors the drop will add.
        $estStr = '';
        if ($closureDbm !== null) {
            $estDbm = $closureDbm - 2 * $connectorLoss;
            $sig    = gpon_signal_info((float)$estDbm);
            $estStr = ' <span style="color:'.h($sig['color']).';font-size:11px;font-weight:600">~'.h(number_format($estDbm, 1)).' dBm</span>';
        }
        $title = $s['service_name'] !== '' ? $s['service_name'] : 'prepared service';
        if ($s['address']) $title .= ' — '.$s['address'];
        $out .= '<span class="trace-onu-pill trace-onu-pill--prepared" title="'.h($title).'">⌂ '.h($s['name'])
              . $estStr
              . ' <span class="badge" style="font-size:10px;background:#f97316">to build</span></span>';
    }
    return $out ? '<div class="trace-onu-row">'.$out.'</div>' : '';
}

/** Scan trace results for an upstream terminal device (OLT PON, SFP, DWDM).
 *  Returns the first matching hop row, or null if not found. */
function trace_find_olt(array $results): ?array {
    foreach ($results as $r)
        if (in_array($r['device_type'] ?? '', ['olt_pon', 'sfp', 'dwdm'])) return $r;
    return null;
}

/**
 * Filter an upstream trace to the single linear path that reaches the OLT.
 *
 * When starting from a specific subscriber fiber, the splitter upstream fans
 * out to many sibling branches (one per subscriber). This keeps only hops on
 * the direct branch-path lineage that contains the OLT endpoint, making the
 * upstream display a clean linear flow: your fiber → splitter → OLT.
 *
 * Has no effect when the path is already linear, or when no OLT is found.
 */
function trace_linear_upstream(array $results): array {
    $olt = trace_find_olt($results);
    if (!$olt) return $results;

    $oltBranch = (string)($olt['branch_path'] ?? '1');

    // Build set of valid branch paths: every ancestor of $oltBranch plus itself.
    // e.g. "1.0" → {"1", "1.0"};  "1.2.3" → {"1", "1.2", "1.2.3"}
    $valid = []; $path = '';
    foreach (explode('.', $oltBranch) as $p) {
        $path = $path === '' ? $p : $path . '.' . $p;
        $valid[$path] = true;
    }
    return array_values(array_filter($results, fn($r) => isset($valid[(string)($r['branch_path'] ?? '1')])));
}

/**
 * Render the upstream path in OLT-first order with OLT=0 dB perspective.
 * Sorts hops by hop_num then reverses, so the OLT terminal appears at the top.
 * Cumulative losses are subtracted from the max so OLT shows 0.00 dB and loss
 * accumulates toward the FDC entry point at the bottom.
 */
function tfc_render_upstream_linear(array $results, bool $showLoss, ?float $oltTxDbm = null): void {
    if (!$results) return;
    usort($results, fn($a, $b) => (int)$a['hop_num'] - (int)$b['hop_num']);
    $results = array_reverse($results);
    $maxLoss = $showLoss
        ? max(array_map(fn($r) => (float)($r['cumulative_loss_db'] ?? 0), $results))
        : 0.0;
    echo '<div class="tfc-wrap"><div class="tfc-trunk">';
    $first = true;
    foreach ($results as $r) {
        if (!$first) echo '<div class="tfc-vline"></div>';
        $first = false;
        $adjLoss = ($showLoss && isset($r['cumulative_loss_db']))
            ? round($maxLoss - (float)$r['cumulative_loss_db'], 4)
            : null;
        if (!empty($r['device_type'])) {
            $dn = h($r['device_name'] ?? $r['device_type']);
            echo '<div class="tfc-device-card"><span class="badge bg-info text-dark">'.$dn.'</span>';
            if ($adjLoss !== null) {
                if ($oltTxDbm !== null) {
                    $rxDbm = $oltTxDbm - $adjLoss;
                    echo '<div class="tfc-card-meta mt-1"><span class="loss-badge '.tfc_rx_class($rxDbm).'">'.number_format($rxDbm, 1).' dBm</span></div>';
                } else {
                    echo '<div class="tfc-card-meta mt-1"><span class="loss-badge '.tloss($adjLoss).'">'.number_format($adjLoss, 2).' dB</span></div>';
                }
            }
            echo '</div>';
        } else {
            $rAdj = $r;
            if ($adjLoss !== null) $rAdj['cumulative_loss_db'] = $adjLoss;
            echo tfc_card_html($rAdj, $showLoss, $oltTxDbm);
        }
    }
    echo '</div></div>';
}

/** Render one hop row (used inside expanded branch bodies). */
function trace_hop_row(array $r, bool $showLoss): string {
    $fosc = !empty($r['fosc_id'])
        ? '<a href="index.php?page=closure&fosc_id='.(int)$r['fosc_id'].'">'.h($r['fosc_label']).'</a>'
        : h($r['fosc_label'] ?? '—');
    $dev = '';
    if (!empty($r['device_type'])) {
        $dn = h($r['device_name'] ?? $r['device_type']);
        $dp = isset($r['device_port']) ? ' p'.(int)$r['device_port'] : '';
        $dev = ' <span class="badge bg-info text-dark" style="font-size:.7em">'.$dn.$dp.'</span>';
    }
    $extra = '';
    if (!$showLoss && !empty($r['length_m']))
        $extra = '<span class="th-extra text-muted">'.number_format((float)$r['length_m'],0).' m</span>';
    $loss = ($showLoss && isset($r['cumulative_loss_db']))
        ? '<span class="loss-badge '.tloss((float)$r['cumulative_loss_db']).' ms-auto">'.number_format((float)$r['cumulative_loss_db'],2).' dB</span>'
        : '';
    return '<div class="th-row">'
         . '<span class="th-num">'.(int)$r['hop_num'].'</span>'
         . '<span class="th-fosc">'.$fosc.$dev.'</span>'
         . '<span class="th-cable">'.h($r['cable_name'] ?? '').' F'.(int)$r['fiber_num'].'</span>'
         . $extra.$loss.'</div>';
}

/**
 * Recursively render a branch as a collapsible tree node.
 * depth=0 is the trunk (always visible, never wrapped in <details>).
 */
function trace_render_branch_tree(string $bp, array $bm, bool $open, int $depth, bool $showLoss): string {
    $hops = $bm[$bp] ?? [];
    $kids = trace_kids($bp, $bm);

    // Locate last device hop (the split point)
    $di = null;
    foreach ($hops as $i => $r) if (!empty($r['device_type'])) $di = $i;
    $cable = ($di !== null) ? array_slice($hops, 0, $di) : $hops;
    $dev   = ($di !== null) ? $hops[$di] : null;

    // ── leaf branch (no device, no children) ─────────────────────────
    if (!$kids && !$dev) {
        if ($depth === 0) {
            $out = '';
            foreach ($hops as $h) $out .= trace_hop_row($h, $showLoss);
            $lastHop = $hops ? end($hops) : null;
            if ($lastHop && !empty($lastHop['fosc_id'])) $out .= trace_onu_pills((int)$lastHop['fosc_id']);
            return $out;
        }
        $leaf = $hops ? end($hops) : null;
        $leafFosc = $leaf
            ? (!empty($leaf['fosc_id'])
                ? '<a href="index.php?page=closure&fosc_id='.(int)$leaf['fosc_id'].'">'.h($leaf['fosc_label']).'</a>'
                : h($leaf['fosc_label'] ?? '—'))
            : '—';
        $leafLoss = $leaf ? (float)($leaf['cumulative_loss_db'] ?? 0) : 0;
        $nh = count($hops);
        $s  = '<details class="tb"'.($open?' open':'').'>';
        $s .= '<summary><span class="tb-chev">▶</span>'
            . '<span class="tb-path">'.h($bp).'</span>'
            . '<span class="tb-arrow">→</span>'
            . '<span class="tb-dest">'.$leafFosc.'</span>';
        if ($showLoss) $s .= '<span class="loss-badge '.tloss($leafLoss).'">'.number_format($leafLoss,2).' dB</span>';
        $s .= '<span class="tb-meta">('.$nh.' hop'.($nh!==1?'s':'').')</span>';
        $s .= '</summary><div class="tb-body">';
        foreach ($hops as $h) $s .= trace_hop_row($h, $showLoss);
        if ($leaf && !empty($leaf['fosc_id'])) $s .= trace_onu_pills((int)$leaf['fosc_id']);
        $s .= '</div></details>';
        return $s;
    }

    // ── branch with device / children ────────────────────────────────
    $s = '';
    if ($depth > 0) {
        $nk     = count($kids);
        $leaves = trace_all_leaves_bm($bp, $bm);
        $nl     = count($leaves);
        $dn     = $dev ? h($dev['device_name'] ?? $dev['device_type']) : '';
        $dl     = $dev && isset($dev['cumulative_loss_db']) ? (float)$dev['cumulative_loss_db'] : null;
        $s .= '<details class="tb"'.($open?' open':'').'>';
        $s .= '<summary><span class="tb-chev">▶</span>'
            . '<span class="tb-path">'.h($bp).'</span>'
            . '<span class="tb-arrow">→</span>';
        if ($dn) $s .= '<span class="badge bg-info text-dark px-2">'.$dn.'</span>';
        if ($dl !== null && $showLoss) $s .= '<span class="loss-badge '.tloss($dl).'">'.number_format($dl,2).' dB</span>';
        $s .= '<span class="tb-meta">'.$nk.' output'.($nk!==1?'s':'').', '.$nl.' leaf'.($nl!==1?'s':'').'</span>';
        $s .= '</summary><div class="tb-body">';
    }

    // Cable segment (collapsible if long)
    if ($cable) {
        $n = count($cable); $first = $cable[0]; $last = end($cable);
        if ($n > 4) {
            $s .= '<details class="tc">';
            $s .= '<summary><span class="tc-icon">▶</span>'
                . 'Hops '.(int)$first['hop_num'].'–'.(int)$last['hop_num']
                . ' · '.h($first['fosc_label'] ?? '').' → '.h($last['fosc_label'] ?? '')
                . ' · '.$n.' span'.($n!==1?'s':'')
                . '</summary><div class="tc-body">';
            foreach ($cable as $h) $s .= trace_hop_row($h, $showLoss);
            $s .= '</div></details>';
        } else {
            foreach ($cable as $h) $s .= trace_hop_row($h, $showLoss);
        }
    }

    // Device split row + children
    if ($dev) {
        $dn = h($dev['device_name'] ?? $dev['device_type']);
        $dl = isset($dev['cumulative_loss_db']) ? (float)$dev['cumulative_loss_db'] : null;
        $nk = count($kids);
        $s .= '<div class="td-row">'
            . '<span class="td-icon">⑂</span>'
            . '<span class="badge bg-info text-dark">'.$dn.'</span>';
        if ($dl !== null && $showLoss)
            $s .= '<span class="loss-badge '.tloss($dl).'">'.number_format($dl,2).' dB</span>';
        $s .= '<span class="tb-meta">→ '.$nk.' output'.($nk!==1?'s':'').'</span></div>';
        $s .= '<div class="tb-children">';
        foreach ($kids as $child)
            $s .= trace_render_branch_tree($child, $bm, false, $depth + 1, $showLoss);
        $s .= '</div>';
    }

    if ($depth > 0) $s .= '</div></details>';
    return $s;
}

/** Render one hop as a flowchart card.
 *  When $oltTxDbm is set (light-path trace), shows received dBm with GPON colour coding
 *  instead of raw accumulated dB loss. */
function tfc_card_html(array $r, bool $showLoss, ?float $oltTxDbm = null): string {
    $fosc = !empty($r['fosc_id'])
        ? '<a class="tfc-card-link" href="index.php?page=closure&fosc_id='.(int)$r['fosc_id'].'">'.h($r['fosc_label']).'</a>'
        : h($r['fosc_label'] ?? '—');
    $fNum  = (int)$r['fiber_num'];
    $cable = '<div class="tfc-card-cable">'.h($r['cable_name'] ?? '').' · F'.$fNum.itu_fiber_html($fNum).'</div>';
    $meta  = '';
    if (!$showLoss && !empty($r['length_m']))
        $meta = '<div class="tfc-card-meta">'.number_format((float)$r['length_m'], 0).' m</div>';
    if ($showLoss && isset($r['cumulative_loss_db'])) {
        $loss = (float)$r['cumulative_loss_db'];
        if ($oltTxDbm !== null) {
            $rxDbm = $oltTxDbm - $loss;
            $cls   = tfc_rx_class($rxDbm);
            $meta  = '<div class="tfc-card-meta"><span class="loss-badge '.$cls.'">'.number_format($rxDbm, 1).' dBm</span></div>';
        } else {
            $cls  = tloss($loss);
            $meta = '<div class="tfc-card-meta"><span class="loss-badge '.$cls.'">'.number_format($loss, 2).' dB</span></div>';
        }
    }
    return '<div class="tfc-card"><div class="tfc-card-fosc">'.$fosc.'</div>'.$cable.$meta.'</div>';
}

/** Recursively render a branch as a flowchart column.
 *  $skipFirstLine=true for the root trunk.
 *  $leafOnly=true: show only the terminal hop (used for splitter output branches
 *  so that pass-through closures don't cascade — only the final closure shows). */
function tfc_render_branch(string $bp, array $bm, bool $showLoss, bool $skipFirstLine = false, bool $leafOnly = false, ?float $oltTxDbm = null): string {
    global $traceSlackFoscs;
    $hops = $bm[$bp] ?? [];
    $kids = trace_kids($bp, $bm);

    $di = null;
    foreach ($hops as $i => $r) if (!empty($r['device_type'])) $di = $i;
    $cable = ($di !== null) ? array_slice($hops, 0, $di) : $hops;
    $dev   = ($di !== null) ? $hops[$di] : null;

    // In leaf-only mode: if this branch has no children and no device, show only the terminal hop.
    if ($leafOnly && !$kids && !$dev && count($cable) > 1) {
        $cable = [end($cable)];
    }

    $out   = '';
    $first = true;
    foreach ($cable as $h) {
        // Slack loops are transparent pass-throughs — skip the card node entirely
        if (!empty($h['fosc_id']) && !empty($traceSlackFoscs[(int)$h['fosc_id']])) continue;
        if (!$first || !$skipFirstLine) $out .= '<div class="tfc-vline"></div>';
        $out .= tfc_card_html($h, $showLoss, $oltTxDbm);
        $first = false;
    }

    if (!$kids && !$dev) {
        $lastCard = $cable ? end($cable) : null;
        if ($lastCard && !empty($lastCard['fosc_id'])) {
            $cdbm = ($oltTxDbm !== null && isset($lastCard['cumulative_loss_db']))
                ? $oltTxDbm - (float)$lastCard['cumulative_loss_db'] : null;
            $out .= trace_onu_pills((int)$lastCard['fosc_id'], $cdbm);
        }
    }

    if ($dev) {
        $dn = h($dev['device_name'] ?? $dev['device_type']);
        $dl = isset($dev['cumulative_loss_db']) ? (float)$dev['cumulative_loss_db'] : null;
        if (!$first || !$skipFirstLine) $out .= '<div class="tfc-vline"></div>';
        $out .= '<div class="tfc-device-card"><span class="badge bg-info text-dark">'.$dn.'</span>';
        if ($dl !== null && $showLoss) {
            if ($oltTxDbm !== null) {
                $rxDbm = $oltTxDbm - $dl;
                $out .= '<div class="tfc-card-meta mt-1"><span class="loss-badge '.tfc_rx_class($rxDbm).'">'.number_format($rxDbm, 1).' dBm</span></div>';
            } else {
                $out .= '<div class="tfc-card-meta mt-1"><span class="loss-badge '.tloss($dl).'">'.number_format($dl, 2).' dB</span></div>';
            }
        }
        $out .= '</div>';

        // A branch can also terminate ON a device — e.g. a splitter or MPT whose
        // outputs aren't spliced out yet. That still lands at a closure, so show
        // its subscribers here; the cable-hop block above never sees this case.
        if (!$kids && !empty($dev['fosc_id'])) {
            $ddbm = ($oltTxDbm !== null && isset($dev['cumulative_loss_db']))
                ? $oltTxDbm - (float)$dev['cumulative_loss_db'] : null;
            $out .= trace_onu_pills((int)$dev['fosc_id'], $ddbm);
        }

        if ($kids) {
            $out .= '<div class="tfc-row">';
            foreach ($kids as $child) {
                $out .= '<div class="tfc-branch"><div class="tfc-vline"></div>';
                $out .= tfc_render_branch($child, $bm, $showLoss, true, true, $oltTxDbm);
                $out .= '</div>';
            }
            $out .= '</div>';
        }
    }
    return $out;
}

/** Render one direction's trace as a flowchart (header + dot-grid card layout).
 *  $feedRole: 'upstream' | 'downstream' | null — shows feed-direction badge and
 *  collapses the upstream path by default (it's less interesting than the subscriber side).
 *  $oltName: name of the OLT device, shown in the upstream header when known. */
function tfc_render_direction(array $results, string $color, string $label, bool $isLight,
                              ?string $feedRole = null, ?string $oltName = null,
                              float $lossOffset = 0.0, ?float $oltTxDbm = null): void {
    // Downstream link budget: shift all cumulative losses by the upstream base loss
    if ($lossOffset > 0.0 && $isLight && $feedRole !== 'upstream') {
        foreach ($results as &$r)
            if (isset($r['cumulative_loss_db']))
                $r['cumulative_loss_db'] = round((float)$r['cumulative_loss_db'] + $lossOffset, 4);
        unset($r);
    }
    $bm      = trace_build_bmap($results);
    $leaves  = trace_all_leaves_bm('1', $bm);
    $nHops   = count($results);
    $nBr     = count($bm);
    $maxLoss = $leaves
        ? max(array_map(fn($l) => (float)($l['cumulative_loss_db'] ?? 0), $leaves))
        : 0;

    // Feed-direction badge
    if ($feedRole === 'upstream') {
        $badge = '<span class="tfc-feed-badge tfc-feed-up">↑ Upstream</span>';
        if ($oltName) $badge .= ' <span class="tfc-feed-olt">'.h($oltName).'</span>';
    } elseif ($feedRole === 'downstream') {
        $badge = '<span class="tfc-feed-badge tfc-feed-down">↓ Downstream</span>';
    } else {
        $badge = '';
    }

    echo '<div class="tfc-dir-hdr" style="border-color:'.h($color).'">';
    echo '<span style="color:'.h($color).'">●</span> '.$label.' '.$badge;
    echo ' <span class="text-muted small">'.$nHops.' hop'.($nHops !== 1 ? 's' : '');
    if ($nBr > 1)
        echo ' · '.$nBr.' branch'.($nBr !== 1 ? 'es' : '').', '.count($leaves).' leaf'.(count($leaves) !== 1 ? 'ves' : '');
    if ($isLight && $maxLoss > 0) {
        if ($oltTxDbm !== null) {
            $minRx = $oltTxDbm - $maxLoss;
            echo ' · min <span class="loss-badge '.tfc_rx_class($minRx).'">'.number_format($minRx, 1).' dBm</span>';
        } else {
            echo ' · max <span class="loss-badge '.tloss($maxLoss).'">'.number_format($maxLoss, 2).' dB</span>';
        }
    }
    echo '</span></div>';

    if ($nHops <= 1) {
        echo '<p class="text-muted small mb-0">Terminal — no splice at this end.</p>';
        $termFoscId = !empty($results[0]['fosc_id']) ? (int)$results[0]['fosc_id'] : null;
        if ($termFoscId) {
            $termLoss   = isset($results[0]['cumulative_loss_db']) ? (float)$results[0]['cumulative_loss_db'] : null;
            $termCdbm   = ($oltTxDbm !== null && $termLoss !== null) ? $oltTxDbm - $termLoss : null;
            echo trace_onu_pills($termFoscId, $termCdbm);
        }
        return;
    }

    // Upstream path collapsed by default — less interesting than the subscriber side
    if ($feedRole === 'upstream') {
        echo '<details class="tfc-upstream-wrap">';
        echo '<summary>Show upstream path ('.$nHops.' hop'.($nHops !== 1 ? 's' : '')
            .($nBr > 1 ? ', '.$nBr.' branches' : '').')</summary>';
    }

    if ($feedRole === 'upstream') {
        tfc_render_upstream_linear($results, $isLight, $oltTxDbm);
    } else {
        echo '<div class="tfc-wrap"><div class="tfc-trunk">';
        echo tfc_render_branch('1', $bm, $isLight, true, false, $oltTxDbm);
        echo '</div></div>';
    }

    if ($feedRole === 'upstream') echo '</details>';
}

// --- Get all cable spans for search dropdown ---
$spans = $pdo->query("
    SELECT cs.cable_span_id, c.name as cable_name, c.fibre_count,
           f1.splice_num as from_label, f2.splice_num as to_label
    FROM app.cable_span cs
    JOIN app.cable c ON c.cable_id = cs.cable_id
    JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
    JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
    ORDER BY c.name, cs.cable_span_id
")->fetchAll();

$flash = flash_get();

// OLT TX power for link budget (dBm → received signal at each hop)
$oltTxDbm = null;
$_lp = $pdo->query("SELECT loss_db FROM app.loss_param WHERE param_key='olt_class_b_plus_tx_dbm'")->fetch();
if ($_lp) $oltTxDbm = (float)$_lp['loss_db'];
unset($_lp);

// ── Tab 2: FDC Port View ─────────────────────────────────────────────────
$activeTab     = $_GET['tab'] ?? '1';
$fdcId2        = (int)($_GET['fdc_id']   ?? 0);
$fdcPort2      = (int)($_GET['fdc_port'] ?? 0);
$fdcTraceType2 = in_array($_GET['fdc_type'] ?? '', ['light', 'physical'])
                 ? ($_GET['fdc_type'] ?? 'light') : 'light';
// Plan splices are followed unless asked otherwise. A panel that only exists in
// the plan - KNW2020's does - would otherwise offer ports whose traces stop at
// the first planned splice down the route, which is not a useful default.
$fdcPlan2      = ($_GET['fdc_plan'] ?? '1') !== '0';

$fdcList        = $pdo->query(
    "SELECT fosc_id, splice_num, kind, notes FROM app.fosc WHERE kind IN ('cabinet','patch_panel') ORDER BY splice_num"
)->fetchAll();

// Auto-select first FDC when Tab 2 opened without explicit choice
if ($fdcId2 === 0 && $activeTab === '2' && !empty($fdcList))
    $fdcId2 = (int)$fdcList[0]['fosc_id'];

$fdcInfo2       = null;
$fdcMainSpan2   = null;
$fdcPanel2      = null;   // the patch panel that defines the port numbering
$fdcPortMap2    = [];     // port => cable span + fibre behind it
$fdcFiberCount2 = 0;
$fdcConnected2  = [];   // [fiber_num => true], ksorted
$fdcTraceFrom2  = [];
$fdcTraceTo2    = [];
$fdcError2      = null;

if ($fdcId2 > 0) {
    $st = $pdo->prepare(
        "SELECT fosc_id, splice_num FROM app.fosc WHERE fosc_id=:id AND kind IN ('cabinet','patch_panel')"
    );
    $st->execute([':id' => $fdcId2]);
    $fdcInfo2 = $st->fetch() ?: null;

    if (!$fdcInfo2) {
        $fdcError2 = "FDC #$fdcId2 not found.";
    } else {
        // Largest cable span at this FDC
        $st2 = $pdo->prepare("
            SELECT cs.cable_span_id, cs.from_fosc_id, cs.to_fosc_id,
                   c.name AS cable_name, c.fibre_count,
                   f1.splice_num AS from_label, f2.splice_num AS to_label,
                   CASE WHEN cs.from_fosc_id = :fid THEN 'to' ELSE 'from' END AS trace_end
            FROM app.cable_span cs
            JOIN app.cable c ON c.cable_id = cs.cable_id
            JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
            JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
            WHERE cs.from_fosc_id = :fid OR cs.to_fosc_id = :fid
            ORDER BY c.fibre_count DESC, cs.cable_span_id
            LIMIT 1
        ");
        $st2->execute([':fid' => $fdcId2]);
        $fdcMainSpan2 = $st2->fetch() ?: null;

        // A cabinet's ports belong to its panel, not to whichever cable happens to
        // be the fattest one at the door. FRD1122 has a 288-port panel fed by two
        // cables - a 96F and a 144F - and reading the port count off the bigger
        // cable showed 144 ports and hid everything on the trunk.
        $pst2 = $pdo->prepare("
            SELECT d.fosc_device_id, d.name, (d.attrs->>'ports')::int AS ports
              FROM app.fosc_device d
             WHERE d.fosc_id = :fid AND d.device_type = 'patch_panel'
               AND d.attrs->>'ports' ~ '^[0-9]+$'
             ORDER BY (d.attrs->>'ports')::int DESC, d.fosc_device_id
             LIMIT 1
        ");
        $pst2->execute([':fid' => $fdcId2]);
        $fdcPanel2 = $pst2->fetch() ?: null;

        if ($fdcPanel2) {
            // port -> the cable fibre behind it, and the end of that span facing here
            $mst2 = $pdo->prepare("
                WITH pj AS (
                  SELECT j.is_plan,
                         CASE WHEN j.a_target='fosc_device' THEN j.a_range   ELSE j.b_range   END AS drange,
                         CASE WHEN j.a_target='fosc_device' THEN j.b_span_id ELSE j.a_span_id END AS sid,
                         CASE WHEN j.a_target='fosc_device' THEN j.b_range   ELSE j.a_range   END AS frange,
                         CASE WHEN j.a_target='fosc_device' THEN j.b_end     ELSE j.a_end     END AS near_end
                    FROM app.splice_join j
                   WHERE j.retired_at IS NULL AND j.fosc_id = :fid
                     AND (:plan::boolean OR NOT j.is_plan)
                     AND ((j.a_target='fosc_device' AND j.a_span_id = :did AND j.b_target='cable_span')
                       OR (j.b_target='fosc_device' AND j.b_span_id = :did AND j.a_target='cable_span'))
                )
                SELECT lower(drange) + g AS port, lower(frange) + g AS fibre, sid, near_end, is_plan,
                       c.name AS cable_name, c.fibre_count
                  FROM pj
                  CROSS JOIN LATERAL generate_series(0, upper(drange) - lower(drange) - 1) g
                  JOIN app.cable_span cs ON cs.cable_span_id = pj.sid
                  JOIN app.cable c ON c.cable_id = cs.cable_id
                 ORDER BY port, is_plan
            ");
            $mst2->execute([':fid' => $fdcId2, ':did' => (int)$fdcPanel2['fosc_device_id'],
                            ':plan' => $fdcPlan2 ? 'true' : 'false']);
            foreach ($mst2->fetchAll() as $r) $fdcPortMap2[(int)$r['port']] = $r;
        }

        if ($fdcPanel2) {
            $fdcFiberCount2 = (int)$fdcPanel2['ports'];
            foreach ($fdcPortMap2 as $port => $_) $fdcConnected2[$port] = true;
            ksort($fdcConnected2);

            if ($fdcPort2 > 0 && isset($fdcPortMap2[$fdcPort2])) {
                $pm   = $fdcPortMap2[$fdcPort2];
                $sid2 = (int)$pm['sid'];
                $end2 = (string)$pm['near_end'];
                $opp2 = ($end2 === 'to') ? 'from' : 'to';
                $fib2 = (int)$pm['fibre'];
                try {
                    if ($fdcTraceType2 === 'light') {
                        $fdcTraceFrom2 = fetch_trace_light($pdo, $sid2, $fib2, $end2, $fdcPlan2);
                        $fdcTraceTo2   = fetch_trace_light($pdo, $sid2, $fib2, $opp2, $fdcPlan2);
                    } else {
                        $fdcTraceFrom2 = fetch_trace_physical($pdo, $sid2, $fib2, $end2, $fdcPlan2);
                        $fdcTraceTo2   = fetch_trace_physical($pdo, $sid2, $fib2, $opp2, $fdcPlan2);
                    }
                } catch (Throwable $e) {
                    $fdcError2 = $e->getMessage();
                }
            }
        } elseif ($fdcMainSpan2) {
            $sid2           = (int)$fdcMainSpan2['cable_span_id'];
            $fdcFiberCount2 = (int)$fdcMainSpan2['fibre_count'];

            // Connected fibres: expand each splice_join range so every fiber in [lo,hi) appears
            $st3 = $pdo->prepare("
                SELECT generate_series(lower(a_range), upper(a_range)-1) AS fn FROM app.splice_join
                WHERE fosc_id = :fid AND a_target = 'cable_span' AND a_span_id = :sid AND retired_at IS NULL
                UNION
                SELECT generate_series(lower(b_range), upper(b_range)-1) FROM app.splice_join
                WHERE fosc_id = :fid AND b_target = 'cable_span' AND b_span_id = :sid AND retired_at IS NULL
                ORDER BY 1
            ");
            $st3->execute([':fid' => $fdcId2, ':sid' => $sid2]);
            foreach ($st3->fetchAll() as $r) $fdcConnected2[(int)$r['fn']] = true;
            ksort($fdcConnected2);

            // Trace selected port
            if ($fdcPort2 > 0) {
                $end2 = $fdcMainSpan2['trace_end'];
                $opp2 = ($end2 === 'to') ? 'from' : 'to';
                try {
                    if ($fdcTraceType2 === 'light') {
                        $fdcTraceFrom2 = fetch_trace_light($pdo, $sid2, $fdcPort2, $end2, $fdcPlan2);
                        $fdcTraceTo2   = fetch_trace_light($pdo, $sid2, $fdcPort2, $opp2, $fdcPlan2);
                    } else {
                        $fdcTraceFrom2 = fetch_trace_physical($pdo, $sid2, $fdcPort2, $end2, $fdcPlan2);
                        $fdcTraceTo2   = fetch_trace_physical($pdo, $sid2, $fdcPort2, $opp2, $fdcPlan2);
                    }
                } catch (Throwable $e) {
                    $fdcError2 = $e->getMessage();
                }
            }
        }
    }
}

// ONU/lead data — loaded whenever any trace is active (Tab 1 or Tab 2)
$uispDevicesAge = null;   // seconds since devices cache was last fetched
if ($spanId > 0 || $fdcId2 > 0) {
    $devices = uisp_fetch_cached('devices', 120, $pdo);
    // Record how old the devices cache entry is for display
    $ageRow = $pdo->query(
        "SELECT EXTRACT(EPOCH FROM (now() - fetched_at))::int AS age_s
         FROM app.uisp_cache WHERE endpoint = 'devices'"
    )->fetch();
    if ($ageRow) $uispDevicesAge = (int)$ageRow['age_s'];
    $devById  = [];
    foreach ((is_array($devices) && !isset($devices['error'])) ? $devices : [] as $d) {
        $did = $d['identification']['id'] ?? null;
        if ($did) $devById[$did] = $d;
    }
    foreach ($pdo->query('SELECT oa.uisp_device_id::text, oa.fosc_id FROM app.ont_association oa')->fetchAll() as $row) {
        $dev = $devById[$row['uisp_device_id']] ?? null;
        if (!$dev) continue;
        $id  = $dev['identification']; $ov = $dev['overview'] ?? [];
        $traceFoscOnts[(int)$row['fosc_id']][] = [
            'uisp_device_id' => $row['uisp_device_id'],
            'name'           => $id['site']['name'] ?? $id['name'] ?? '?',
            'signal'         => isset($ov['signal']) ? (int)$ov['signal'] : null,
            'status'         => $ov['status'] ?? 'unknown',
        ];
    }

    $ucrmClients = ucrm_fetch_cached('clients?limit=500', 300, $pdo);
    $clientById  = [];
    foreach ((is_array($ucrmClients) && !isset($ucrmClients['error'])) ? $ucrmClients : [] as $c) {
        $clientById[(int)$c['id']] = $c;
    }
    foreach ($pdo->query('SELECT la.crm_client_id, la.fosc_id FROM app.lead_association la')->fetchAll() as $row) {
        $client = $clientById[(int)$row['crm_client_id']] ?? null;
        $name = $client
            ? (trim(($client['firstName'] ?? '').' '.($client['lastName'] ?? '')) ?: ($client['companyName'] ?? '?'))
            : ('Lead #'.$row['crm_client_id']);
        $traceFoscLeads[(int)$row['fosc_id']][] = ['crm_client_id' => (int)$row['crm_client_id'], 'name' => $name];
    }

    // Prepared services — sold but not yet built. They carry no ONU and no splice
    // yet, so the trace is the only place the future load on a closure shows up.
    $preparedSvcs = ucrm_fetch_cached('clients/services?statuses[]=0&limit=500', 300, $pdo);
    $svcById = [];
    foreach ((is_array($preparedSvcs) && !isset($preparedSvcs['error'])) ? $preparedSvcs : [] as $s) {
        $svcById[(int)$s['id']] = $s;
    }
    foreach ($pdo->query('SELECT sa.crm_service_id, sa.crm_client_id, sa.fosc_id FROM app.service_association sa')->fetchAll() as $row) {
        $svc = $svcById[(int)$row['crm_service_id']] ?? null;
        if (!$svc) continue;   // no longer prepared (built or cancelled) — link is stale
        $client = $clientById[(int)$row['crm_client_id']] ?? null;
        $name = $client
            ? (trim(($client['firstName'] ?? '').' '.($client['lastName'] ?? '')) ?: ($client['companyName'] ?? '?'))
            : ('Service #'.$row['crm_service_id']);
        $traceFoscServices[(int)$row['fosc_id']][] = [
            'crm_service_id' => (int)$row['crm_service_id'],
            'name'           => $name,
            'service_name'   => $svc['name'] ?? '',
            'address'        => $svc['fullAddress'] ?: null,
        ];
    }

    foreach ($pdo->query("SELECT fosc_id FROM app.fosc WHERE kind = 'slack'")->fetchAll() as $row) {
        $traceSlackFoscs[(int)$row['fosc_id']] = true;
    }

    foreach ($pdo->query("SELECT param_key, loss_db::float FROM app.loss_param")->fetchAll() as $p) {
        if ($p['param_key'] === 'olt_class_b_plus_tx_dbm') $oltTxDbm      = (float)$p['loss_db'];
        if ($p['param_key'] === 'connector_sc_upc')         $connectorLoss = (float)$p['loss_db'];
    }
}

// ── Tab 3: Cable Fiber Map ────────────────────────────────────────────────
$cMapFoscId   = ($activeTab === '3') ? (int)($_GET['cmap_fosc'] ?? 0) : 0;
$cMapPlan     = ($activeTab === '3') && !empty($_GET['cmap_plan']);

// Auto-select first feed closure when tab opened without explicit choice
if ($cMapFoscId === 0 && $activeTab === '3' && !empty($fdcList))
    $cMapFoscId = (int)$fdcList[0]['fosc_id'];

$cMapFoscInfo = null;
$cMapSpans    = [];    // array of span info rows (one per cable at the closure)
$cMapRows     = [];    // [cable_span_id => [fiber_num => row]]
$cMapPanels   = [];    // patch panels here, with port -> span/fibre

if ($cMapFoscId > 0) {
    foreach ($fdcList as $f) {
        if ((int)$f['fosc_id'] === $cMapFoscId) { $cMapFoscInfo = $f; break; }
    }

    if ($cMapFoscInfo) {
        // Every cable at this closure, whichever way it was drawn. Only taking
        // spans that start here missed the 96F trunk at FRD1122 entirely - it was
        // drawn SAL1116 -> FRD1122, so the head end's biggest cable was absent
        // from its own cable map.
        $st = $pdo->prepare("
            SELECT cs.cable_span_id, c.name AS cable_name, c.fibre_count,
                   CASE WHEN cs.from_fosc_id = :fid THEN 'from' ELSE 'to' END AS near_end,
                   CASE WHEN cs.from_fosc_id = :fid THEN f2.splice_num
                        ELSE f1.splice_num END AS to_label
              FROM app.cable_span cs
              JOIN app.cable c ON c.cable_id = cs.cable_id
              JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
              JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
             WHERE cs.from_fosc_id = :fid OR cs.to_fosc_id = :fid
             ORDER BY c.fibre_count DESC, c.name, cs.cable_span_id
        ");
        $st->execute([':fid' => $cMapFoscId]);
        $cMapSpans = $st->fetchAll();

        // Bulk-trace all fibers in each span via LATERAL.
        // plan_taps CTE: when plan=true, actual pass-throughs can coexist with plan device
        // taps on the same fiber at the same closure. trace_fiber_path follows the actual
        // cable→cable connection and overshoots the plan tap. We detect the earliest plan
        // device tap along the path and use it as the terminal instead of the last hop.
        $traceStmt = $pdo->prepare("
            WITH fiber_series AS (
                SELECT generate_series(1, :fc) AS fiber_num
            ),
            raw_hops AS (
                SELECT fs.fiber_num,
                       t.hop_num, t.fosc_id, t.fosc_label, t.cable_span_id, t.length_m
                  FROM fiber_series fs
                  CROSS JOIN LATERAL (
                      SELECT hop_num, fosc_id, fosc_label, cable_span_id, length_m
                        FROM app.trace_fiber_path(
                            :span_id, fs.fiber_num,
                            :near_end::app.span_end, :plan::boolean, 300)
                  ) t
            ),
            deduped AS (
                -- Collapse branching paths (plan mode produces multiple rows per hop_num
                -- when both actual and plan splices match). Take one row per hop so the
                -- running-sum below counts each span length exactly once.
                SELECT DISTINCT ON (fiber_num, hop_num)
                       fiber_num, hop_num, fosc_id, fosc_label, cable_span_id, length_m
                  FROM raw_hops
                 ORDER BY fiber_num, hop_num, cable_span_id
            ),
            all_hops AS (
                SELECT fiber_num, hop_num, fosc_id, fosc_label, cable_span_id,
                       SUM(length_m) OVER (
                           PARTITION BY fiber_num ORDER BY hop_num
                       ) AS cumulative_m
                  FROM deduped
            ),
            plan_taps AS (
                -- First hop per fiber where a plan device splice exists at that closure/span
                SELECT ah.fiber_num, MIN(ah.hop_num) AS tap_hop
                  FROM all_hops ah
                  JOIN app.splice_join sj
                    ON sj.fosc_id = ah.fosc_id
                   AND sj.is_plan = true
                   -- Retired taps were still stopping live traces: SAL1116's drops
                   -- moved off F43-47 and the retired joins kept catching them, so
                   -- ports 43-44 reported the trunk ending one hop out.
                   AND sj.retired_at IS NULL
                   AND (
                       (sj.a_target = 'cable_span' AND sj.a_span_id = ah.cable_span_id
                        AND sj.a_range @> ah.fiber_num AND sj.b_target = 'fosc_device')
                    OR (sj.b_target = 'cable_span' AND sj.b_span_id = ah.cable_span_id
                        AND sj.b_range @> ah.fiber_num AND sj.a_target = 'fosc_device')
                   )
                 GROUP BY ah.fiber_num
            ),
            ranked AS (
                SELECT ah.fiber_num, ah.hop_num, ah.fosc_id, ah.fosc_label,
                       round(ah.cumulative_m::numeric, 0)::int AS dist_m,
                       MAX(ah.hop_num) OVER (PARTITION BY ah.fiber_num) AS max_hop,
                       pt.tap_hop
                  FROM all_hops ah
                  LEFT JOIN plan_taps pt ON pt.fiber_num = ah.fiber_num
            ),
            terminal AS (
                SELECT DISTINCT ON (fiber_num)
                       fiber_num, fosc_id, fosc_label, dist_m, hop_num AS hops
                  FROM ranked
                 WHERE (tap_hop IS NULL     AND hop_num = max_hop)
                    OR (tap_hop IS NOT NULL AND hop_num = tap_hop)
                 ORDER BY fiber_num
            )
            SELECT fs.fiber_num,
                   t.fosc_id,
                   t.fosc_label,
                   t.dist_m,
                   t.hops
              FROM fiber_series fs
              LEFT JOIN terminal t USING (fiber_num)
             ORDER BY fs.fiber_num
        ");

        foreach ($cMapSpans as $span) {
            $sid = (int)$span['cable_span_id'];
            $fc  = (int)$span['fibre_count'];
            $traceStmt->execute([':fc' => $fc, ':span_id' => $sid,
                                 ':near_end' => $span['near_end'],
                                 ':plan' => $cMapPlan ? 'true' : 'false']);
            $cMapRows[$sid] = [];
            foreach ($traceStmt->fetchAll() as $row) {
                $cMapRows[$sid][(int)$row['fiber_num']] = $row;
            }
        }

        // The cabinet as it is actually worked on: a panel of ports, each one a
        // cable fibre going somewhere. A fibre number means nothing to someone
        // standing at the rack - the port number is what they patch.
        $pst = $pdo->prepare("
            SELECT d.fosc_device_id, d.name,
                   (d.attrs->>'ports')::int AS ports
              FROM app.fosc_device d
             WHERE d.fosc_id = :fid AND d.device_type = 'patch_panel'
               AND d.attrs->>'ports' ~ '^[0-9]+$'
             ORDER BY d.fosc_device_id
        ");
        $pst->execute([':fid' => $cMapFoscId]);
        $panels = $pst->fetchAll();

        if ($panels) {
            // Port n of a range maps to fibre n of the paired range: the two sides
            // of one join are the same width, in order.
            $mst = $pdo->prepare("
                WITH pj AS (
                  SELECT j.is_plan,
                         CASE WHEN j.a_target='fosc_device' THEN j.a_span_id ELSE j.b_span_id END AS dev_id,
                         CASE WHEN j.a_target='fosc_device' THEN j.a_range   ELSE j.b_range   END AS drange,
                         CASE WHEN j.a_target='fosc_device' THEN j.b_span_id ELSE j.a_span_id END AS sid,
                         CASE WHEN j.a_target='fosc_device' THEN j.b_range   ELSE j.a_range   END AS frange
                    FROM app.splice_join j
                   WHERE j.retired_at IS NULL
                     AND j.fosc_id = :fid
                     AND ((j.a_target='fosc_device' AND j.b_target='cable_span')
                       OR (j.b_target='fosc_device' AND j.a_target='cable_span'))
                     AND (:plan::boolean OR NOT j.is_plan)
                )
                SELECT dev_id, lower(drange) + g AS port, lower(frange) + g AS fibre, sid, is_plan
                  FROM pj
                  CROSS JOIN LATERAL generate_series(0, upper(drange) - lower(drange) - 1) g
                 ORDER BY dev_id, port, is_plan
            ");
            $mst->execute([':fid' => $cMapFoscId, ':plan' => $cMapPlan ? 'true' : 'false']);
            $map = [];
            foreach ($mst->fetchAll() as $r) {
                // A plan splice wins the port: it is the state being worked toward.
                $map[(int)$r['dev_id']][(int)$r['port']] = $r;
            }
            foreach ($panels as $pn) {
                $cMapPanels[] = [
                    'id'    => (int)$pn['fosc_device_id'],
                    'name'  => (string)$pn['name'],
                    'ports' => (int)$pn['ports'],
                    'map'   => $map[(int)$pn['fosc_device_id']] ?? [],
                ];
            }
        }
    }
}

$PAGE->setTitle('Fibre Trace');

// Add Leaflet for map
$PAGE->addExternalCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
$PAGE->addExternalJs('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');

// Inline CSS for trace visualization
$PAGE->setInlineCssRenderer(function () {
    return <<<CSS
/* ── Map ──────────────────────────────────────────────────────── */
#traceMap, #traceMap2 { height: 400px; border: 1px solid #ddd; border-radius: 4px; }
.span-label-tooltip {
    background: rgba(13,110,253,.85); border: none; border-radius: 3px;
    color: #fff; font-size: .75em; font-weight: 600; padding: 2px 5px; white-space: nowrap;
}
.span-label-upstream {
    background: rgba(217,119,6,.9);
}

/* ── Loss badges ──────────────────────────────────────────────── */
.loss-badge {
    display: inline-block; padding: 1px 7px; border-radius: 3px;
    font-size: .8em; font-weight: 600; white-space: nowrap;
}
.loss-good     { background: #dcfce7; color: #166534; }
.loss-marginal { background: #fef9c3; color: #854d0e; }
.loss-bad      { background: #fee2e2; color: #991b1b; }

/* ── Flowchart (TFC) ─────────────────────────────────────────── */
.tfc-dir-hdr {
    border-left: 4px solid; padding: 6px 12px; margin-bottom: 12px;
    border-radius: 0 4px 4px 0; background: #f8fafc;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.tfc-wrap {
    background-color: #f8fafc;
    background-image: radial-gradient(circle, #cbd5e1 1.2px, transparent 1.2px);
    background-size: 18px 18px;
    padding: 24px 28px; border-radius: 6px;
    overflow: auto; min-height: 80px;
}
.tfc-trunk {
    display: flex; flex-direction: column; align-items: center;
    min-width: max-content;
}
.tfc-vline { width: 2px; height: 22px; background: #94a3b8; flex-shrink: 0; }
.tfc-card {
    background: white; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 9px 16px; box-shadow: 0 2px 5px rgba(0,0,0,0.08);
    text-align: center; min-width: 130px; white-space: nowrap;
    position: relative; z-index: 1;
}
.tfc-card-fosc { font-weight: 700; font-size: .88rem; color: #1e293b; }
.tfc-card-link { color: inherit; text-decoration: none; }
.tfc-card-link:hover { text-decoration: underline; }
.tfc-card-cable { font-size: .75rem; color: #64748b; margin-top: 3px; }
.tfc-itu-code   { font-size: .72rem; color: #475569; font-weight: 600; letter-spacing: .02em; }
.tfc-card-meta  { font-size: .72rem; color: #94a3b8; margin-top: 2px; }
.tfc-device-card {
    background: #f0f9ff; border: 1.5px solid #7dd3fc; border-radius: 8px;
    padding: 6px 16px; text-align: center; white-space: nowrap;
    position: relative; z-index: 1;
}
/* T-junction bracket: border-top spans the row; each branch drops a vline */
.tfc-row    { display: flex; align-items: flex-start; gap: 0; }
.tfc-branch { display: flex; flex-direction: column; align-items: center; padding: 0 16px; border-top: 2px solid #94a3b8; }

/* ── Feed-direction badges ────────────────────────────────────── */
.tfc-feed-badge {
    display: inline-block; padding: 1px 8px; border-radius: 20px;
    font-size: .7em; font-weight: 700; letter-spacing: .03em;
    vertical-align: middle; white-space: nowrap;
}
.tfc-feed-up   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.tfc-feed-down { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.tfc-feed-olt  { font-size: .75em; color: #92400e; font-style: italic; }

/* ── Upstream collapsed path ──────────────────────────────────── */
.tfc-upstream-wrap > summary {
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer; list-style: none;
    font-size: .8rem; color: #64748b;
    padding: 4px 0 8px;
}
.tfc-upstream-wrap > summary::before { content: '▶'; font-size: .65em; }
.tfc-upstream-wrap[open] > summary::before { content: '▼'; }
.tfc-upstream-wrap > summary::-webkit-details-marker { display: none; }

/* ── FDC Port View chips (Tab 2) ──────────────────────────────────────── */
.fdc2-ports {
    display: flex; flex-wrap: wrap; gap: 5px;
}
.fdc2-port {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 52px; height: 34px; padding: 0 8px;
    border-radius: 5px; border: 2px solid #cbd5e1;
    background: #f8fafc; color: #475569;
    font-size: .8rem; font-weight: 600;
    text-decoration: none; transition: all .1s;
}
.fdc2-port:hover  { border-color: #0d6efd; background: #eff6ff; color: #0d6efd; }
.fdc2-port.fdc2-active { border-color: #0d6efd; background: #0d6efd; color: #fff; }
/* A port with nothing on it is still a port: shown, but not a link. */
.fdc2-port.fdc2-free { border-style: dashed; border-color: #e2e8f0; background: #fff;
                       color: #cbd5e1; cursor: default; }

/* ── ONU/lead subscriber pills at leaf closures ───────────────── */
.trace-onu-row  { display:flex; flex-wrap:wrap; gap:4px; padding:3px 8px 6px; }
.trace-onu-pill {
    display:inline-flex; align-items:center; gap:4px; font-size:11px;
    padding:2px 7px; border-radius:99px;
    background:rgba(25,135,84,0.1); border:1px solid rgba(25,135,84,0.35);
}
.trace-onu-pill--lead {
    background:rgba(224,168,0,0.1); border-color:rgba(224,168,0,0.4);
}
.trace-onu-pill--prepared {
    background:rgba(249,115,22,0.1); border-color:rgba(249,115,22,0.45);
}
CSS;
});

// Inline JS for map visualization (Tab 1 and/or Tab 2)
if (!empty($traceResults) || !empty($fdcTraceFrom2) || !empty($fdcTraceTo2)) {
    $PAGE->setInlineJsRenderer(function () use (
        $traceResultsFrom, $traceResultsTo, $traceResults, $traceType, $pdo, $spanId,
        $fdcTraceFrom2, $fdcTraceTo2, $fdcTraceType2
    ) {
        // ── Helper: fetch span geometries ─────────────────────────────────
        $fetchGeoms = function (array $ids) use ($pdo): array {
            if (empty($ids)) return [];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                $st = $pdo->prepare("
                    SELECT cs.cable_span_id, c.fibre_count,
                           (f_to.kind = 'optitap' AND (f_to.attrs->>'parent_fosc_id') IS NOT NULL) AS is_flextin,
                           topology.ST_AsGeoJSON(cs.geom, 6, 0) AS geojson
                    FROM app.cable_span cs
                    JOIN app.cable c ON c.cable_id = cs.cable_id
                    JOIN app.fosc f_to ON f_to.fosc_id = cs.to_fosc_id
                    WHERE cs.cable_span_id IN ($ph)
                ");
                $st->execute(array_values($ids));
                $out = [];
                while ($row = $st->fetch()) {
                    $out[(int)$row['cable_span_id']] = [
                        'geom'       => $row['geojson'] ? json_decode($row['geojson'], true) : null,
                        'fibreCount' => (int)($row['fibre_count'] ?? 0),
                        'isFlexTIN'  => (bool)$row['is_flextin'],
                    ];
                }
                return $out;
            } catch (Exception $e) { return []; }
        };

        // ── Helper: build hop array with geometry ─────────────────────────
        $buildHops = function (array $results, string $direction, array $geomMap): array {
            $out = [];
            foreach ($results as $r) {
                $sid = (int)$r['cable_span_id'];
                $ge  = $geomMap[$sid] ?? null;
                $out[] = [
                    'hop'        => (int)$r['hop_num'],
                    'direction'  => $direction,
                    'spanId'     => $sid,
                    'spanEnd'    => (string)($r['span_end'] ?? 'to'),
                    'branch'     => $r['branch_path'] ?? '1',
                    'cable'      => $r['cable_name'],
                    'fibre'      => (int)$r['fiber_num'],
                    'fibreCount' => $ge ? (int)$ge['fibreCount'] : 0,
                    'fosc'       => $r['fosc_label'],
                    'foscId'     => isset($r['fosc_id']) && $r['fosc_id'] !== null ? (int)$r['fosc_id'] : null,
                    'isSlack'    => isset($r['fosc_id']) && !empty($traceSlackFoscs[(int)$r['fosc_id']]),
                    'isFlexTIN'  => $ge ? (bool)$ge['isFlexTIN'] : false,
                    'loss'       => $r['cumulative_loss_db'] ?? 0,
                    'geom'       => $ge ? $ge['geom'] : null,
                ];
            }
            return $out;
        };

        // ── Tab 1 data ────────────────────────────────────────────────────
        $spanIds1 = array_unique(array_map(fn($r) => (int)$r['cable_span_id'], $traceResults));
        $geoms1   = $fetchGeoms($spanIds1);

        // Connected cable spans (background layer for Tab 1)
        $connectedSpans = [];
        if (!empty($spanIds1)) {
            $ph = implode(',', array_fill(0, count($spanIds1), '?'));
            try {
                $st = $pdo->prepare("
                    WITH trace_spans AS (
                        SELECT cs.cable_span_id, cs.cable_id, cs.from_fosc_id, cs.to_fosc_id
                        FROM app.cable_span cs WHERE cs.cable_span_id IN ($ph)
                    ),
                    connected AS (
                        SELECT ts.cable_span_id FROM trace_spans ts
                        UNION
                        SELECT cs2.cable_span_id
                        FROM trace_spans ts
                        JOIN app.cable_span cs2
                          ON cs2.cable_id = ts.cable_id
                         AND cs2.cable_span_id <> ts.cable_span_id
                         AND (cs2.from_fosc_id IN (ts.from_fosc_id, ts.to_fosc_id)
                           OR cs2.to_fosc_id   IN (ts.from_fosc_id, ts.to_fosc_id))
                    )
                    SELECT cs.cable_span_id, c.name AS cable_name, c.fibre_count,
                           topology.ST_AsGeoJSON(cs.geom, 6, 0) AS geojson
                    FROM app.cable_span cs
                    JOIN (SELECT DISTINCT cable_span_id FROM connected) x
                      ON x.cable_span_id = cs.cable_span_id
                    JOIN app.cable c ON c.cable_id = cs.cable_id
                    ORDER BY cs.cable_span_id
                ");
                $st->execute(array_values($spanIds1));
                while ($row = $st->fetch()) {
                    $j = $row['geojson'] ?? null;
                    $connectedSpans[] = [
                        'id'         => (int)$row['cable_span_id'],
                        'cable'      => (string)($row['cable_name'] ?? ''),
                        'fibreCount' => (int)($row['fibre_count'] ?? 0),
                        'geom'       => $j ? json_decode($j, true) : null,
                    ];
                }
            } catch (Exception $e) { $connectedSpans = []; }
        }

        $hops1     = array_merge($buildHops($traceResultsFrom, 'from', $geoms1), $buildHops($traceResultsTo, 'to', $geoms1));
        // Deduplicate by spanId — splitter-input fibres fan out into N branches, all
        // repeating the same cable spans back to the OLT, creating a huge JSON payload.
        $hops1Map = []; $seen1 = [];
        foreach ($hops1 as $h) {
            $sid = (int)($h['spanId'] ?? 0);
            if ($sid && !isset($seen1[$sid])) { $seen1[$sid] = true; $hops1Map[] = $h; }
        }
        $hopsJson           = json_encode($hops1Map);
        $connectedSpansJson = json_encode($connectedSpans);
        $isLight            = $traceType === 'light' ? 'true' : 'false';

        // ── Tab 2 data ────────────────────────────────────────────────────
        $all2      = array_merge($fdcTraceFrom2, $fdcTraceTo2);
        $spanIds2  = array_values(array_unique(array_map(fn($r) => (int)$r['cable_span_id'], $all2)));
        $geoms2    = $fetchGeoms($spanIds2);
        $hops2     = array_merge($buildHops($fdcTraceFrom2, 'from', $geoms2), $buildHops($fdcTraceTo2, 'to', $geoms2));
        // Deduplicate by spanId — light-path fan-out (e.g. PLC-32) repeats the same spans
        // across 32 branches, creating a huge JSON with duplicated geometry.
        $hops2Map = []; $seen2 = [];
        foreach ($hops2 as $h) {
            $sid = (int)($h['spanId'] ?? 0);
            if ($sid && !isset($seen2[$sid])) { $seen2[$sid] = true; $hops2Map[] = $h; }
        }
        $hops2Json = json_encode($hops2Map);
        $isLight2  = $fdcTraceType2 === 'light' ? 'true' : 'false';

        // Feed direction for map coloring ('from'|'to'|null)
        $upDir1 = trace_find_olt($traceResultsFrom) ? '"from"' : (trace_find_olt($traceResultsTo) ? '"to"' : 'null');
        $upDir2 = trace_find_olt($fdcTraceFrom2)    ? '"from"' : (trace_find_olt($fdcTraceTo2)    ? '"to"' : 'null');

        return <<<JS
document.addEventListener('DOMContentLoaded', function() {
    const hops1          = {$hopsJson};
    const connectedSpans = {$connectedSpansJson};
    const isLightPath1   = {$isLight};
    const upstreamDir1   = {$upDir1};
    const hops2          = {$hops2Json};
    const isLightPath2   = {$isLight2};
    const upstreamDir2   = {$upDir2};

    function getLineParts(geom) {
        if (!geom || !geom.type) return [];
        if (geom.type === 'LineString'         && Array.isArray(geom.coordinates)) return [geom.coordinates];
        if (geom.type === 'MultiLineString'    && Array.isArray(geom.coordinates)) return geom.coordinates;
        if (geom.type === 'GeometryCollection' && Array.isArray(geom.geometries))  {
            const p = []; geom.geometries.forEach(g => p.push(...getLineParts(g))); return p;
        }
        return [];
    }

    function initTraceMap(mapId, hops, connectedSpans, isLightPath, upstreamDir) {
        const el = document.getElementById(mapId);
        if (!el || hops.length === 0) return;

        let map;
        try {
            map = L.map(mapId).setView([46.0899, -64.7803], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            // Feed upstream = amber; downstream = blue/green; FlexTIN = purple
            const upstreamColors  = ['#f59e0b', '#d97706'];
            const dirColors       = {from: '#0d6efd', to: '#198754'};
            const lightColorsFrom = ['#0d6efd', '#0a58ca', '#6ea8fe', '#084298'];
            const lightColorsTo   = ['#198754', '#146c43', '#75b798', '#0a3622'];
            const flexTINColor    = '#fd7e14';
            const dirHopIndex     = {from: 0, to: 0};
            const allCoords       = [];

            // Background: connected cable spans
            const tracedIds = new Set(hops.map(h => Number(h.spanId || 0)).filter(v => v > 0));
            connectedSpans.forEach(span => {
                if (!span || !span.geom) return;
                const parts = getLineParts(span.geom);
                if (!parts.length) return;
                const isHop = tracedIds.has(Number(span.id || 0));
                parts.forEach(part => {
                    const coords = (part || []).filter(c => Array.isArray(c) && c.length >= 2).map(c => [c[1], c[0]]);
                    if (!coords.length) return;
                    allCoords.push(...coords);
                    L.polyline(coords, {color: isHop ? '#8fb7ff' : '#9ca3af', weight: 3,
                        opacity: isHop ? 0.35 : 0.55, dashArray: isHop ? '8,8' : '4,8'})
                        .bindPopup('<strong>Span #' + span.id + (span.fibreCount ? ' (' + span.fibreCount + 'F)' : '') + '</strong><br>Cable: ' + (span.cable || '—'))
                        .addTo(map);
                });
            });

            // Upstream layer group — hidden by default
            const upstreamGroup = L.layerGroup();
            let upstreamVisible = false;
            let labelsVisible   = false;
            const labelMarkers  = []; // [{mk, label}] — all closure circle markers

            // Helper: make a control button
            function makeCtrlBtn(title, cssExtra) {
                const btn = L.DomUtil.create('button', 'leaflet-bar leaflet-control');
                btn.title = title;
                btn.style.cssText = 'background:#fff;border:none;padding:4px 8px;font-size:12px;cursor:pointer;white-space:nowrap;line-height:1.4;border-radius:4px;box-shadow:0 1px 5px rgba(0,0,0,.4);' + (cssExtra||'');
                return btn;
            }

            // Custom Leaflet control: toggle upstream (orange) path
            const UpstreamToggle = L.Control.extend({
                options: { position: 'topright' },
                onAdd() {
                    const btn = makeCtrlBtn('Toggle upstream (OLT feed) path');
                    const update = () => {
                        btn.style.color      = upstreamVisible ? '#d97706' : '#6b7280';
                        btn.style.fontWeight = upstreamVisible ? '600'     : '400';
                        btn.textContent      = '↑ Upstream ' + (upstreamVisible ? 'ON' : 'OFF');
                    };
                    update();
                    L.DomEvent.on(btn, 'click', e => {
                        L.DomEvent.stopPropagation(e);
                        upstreamVisible = !upstreamVisible;
                        if (upstreamVisible) upstreamGroup.addTo(map);
                        else map.removeLayer(upstreamGroup);
                        update();
                    });
                    return btn;
                }
            });
            new UpstreamToggle().addTo(map);

            // Custom Leaflet control: toggle closure name labels
            const LabelsToggle = L.Control.extend({
                options: { position: 'topright' },
                onAdd() {
                    const btn = makeCtrlBtn('Toggle closure labels', 'margin-top:4px');
                    const update = () => {
                        btn.style.color      = labelsVisible ? '#0d6efd' : '#6b7280';
                        btn.style.fontWeight = labelsVisible ? '600'     : '400';
                        btn.textContent      = '🏷 Labels ' + (labelsVisible ? 'ON' : 'OFF');
                    };
                    update();
                    L.DomEvent.on(btn, 'click', e => {
                        L.DomEvent.stopPropagation(e);
                        labelsVisible = !labelsVisible;
                        labelMarkers.forEach(({mk, label}) => {
                            mk.unbindTooltip();
                            if (labelsVisible)
                                mk.bindTooltip(label, {permanent: true, direction: 'top', className: 'span-label-tooltip'});
                        });
                        update();
                    });
                    return btn;
                }
            });
            new LabelsToggle().addTo(map);

            // Trace hops
            hops.forEach(hop => {
                const parts = getLineParts(hop.geom);
                if (!parts.length) { console.warn('Hop', hop.hop, 'skipped - no geometry'); return; }
                const dir = hop.direction || 'from';
                const isUpstream = upstreamDir && dir === upstreamDir;
                let color;
                if (hop.isFlexTIN) {
                    color = flexTINColor;
                } else if (isUpstream) {
                    color = upstreamColors[dirHopIndex[dir] % 2];
                } else if (isLightPath) {
                    color = (dir === 'from' ? lightColorsFrom : lightColorsTo)[dirHopIndex[dir] % 4];
                } else {
                    color = dirColors[dir] || '#0d6efd';
                }
                dirHopIndex[dir]++;
                const allPartCoords = [];
                parts.forEach(part => {
                    const c = (part || []).filter(c => Array.isArray(c) && c.length >= 2).map(c => [c[1], c[0]]);
                    if (c.length) allPartCoords.push(...c);
                });
                if (!allPartCoords.length) return;
                const feedLabel = isUpstream ? '<br><em style="color:#d97706">↑ OLT feed</em>' : '';
                const target = isUpstream ? upstreamGroup : map;
                parts.forEach((part, pi) => {
                    const coords = (part || []).filter(c => Array.isArray(c) && c.length >= 2).map(c => [c[1], c[0]]);
                    if (!coords.length) return;
                    allCoords.push(...coords);
                    const pl = L.polyline(coords, {color, weight: isUpstream ? 5 : 4, opacity: isUpstream ? 0.85 : 0.7,
                            dashArray: isUpstream ? null : null})
                        .bindPopup('<strong>Hop ' + hop.hop + (parts.length > 1 ? ' (part ' + (pi+1) + ')' : '') + '</strong>' +
                            (hop.isFlexTIN ? ' <span style="color:#fd7e14;font-size:11px">FlexTIN</span>' : '') + '<br>' +
                            'Cable: ' + hop.cable + (hop.fibreCount ? ' (' + hop.fibreCount + 'F)' : '') + '<br>' +
                            'Fibre: ' + hop.fibre + '<br>FOSC: ' + hop.fosc +
                            (isLightPath ? '<br>Loss: ' + hop.loss + ' dB' : '') + feedLabel)
                        .addTo(target);
                });
                const mc = hop.spanEnd === 'to' ? allPartCoords[allPartCoords.length - 1] : allPartCoords[0];
                const foscLabel = hop.fosc || '';
                const mk = L.circleMarker(mc, {radius: 6, fillColor: color, color: '#fff', weight: 2, opacity: 1, fillOpacity: 0.8})
                    .addTo(target);
                if (foscLabel && !hop.isSlack) labelMarkers.push({mk, label: foscLabel});
                if (hop.foscId) mk.bindPopup('<strong>' + hop.fosc + '</strong><br>' +
                    '<a href="index.php?page=closure&fosc_id=' + hop.foscId + '" class="btn btn-sm btn-outline-secondary mt-1" style="font-size:11px;padding:2px 7px">Details</a>');
            });

            if (allCoords.length > 0) {
                map.fitBounds(allCoords, {padding: [30, 30], maxZoom: 17});
            }
            setTimeout(() => map.invalidateSize(), 200);

            // ── ONU drops (async) ─────────────────────────────────────────
            const traceFoscIds = new Set(hops.map(h => h.foscId).filter(Boolean));
            if (traceFoscIds.size > 0) {
                fetch('/api/ont_drops.php')
                    .then(r => r.json())
                    .then(fc => {
                        (fc.features || []).forEach(f => {
                            if (!traceFoscIds.has(f.properties.fosc_id)) return;
                            const coords = f.geometry.coordinates; // [[lon,lat],[lon,lat]]
                            if (!coords || coords.length < 2) return;
                            const onuLatLon  = [coords[0][1], coords[0][0]];
                            const name = f.properties.ont_name || f.properties.fosc_splice_num || '';
                            const isLead = f.properties.drop_type === 'lead';
                            // Drop line
                            L.polyline(coords.map(c => [c[1], c[0]]), {
                                color: isLead ? '#E0A800' : '#6b7280',
                                weight: 1.5, opacity: 0.8, dashArray: '5 4'
                            }).bindTooltip((isLead ? '⌂ Lead: ' : '⌂ ') + name, {sticky: true})
                              .addTo(map);
                            // ONU house marker
                            L.circleMarker(onuLatLon, {
                                radius: 5, fillColor: isLead ? '#E0A800' : '#374151',
                                color: '#fff', weight: 1.5, fillOpacity: 0.9
                            }).bindPopup('<strong>' + name + '</strong><br>' +
                                '<small class="text-muted">' + f.properties.fosc_splice_num + '</small>' +
                                (f.properties.ont_signal != null ? '<br>Signal: ' + f.properties.ont_signal + ' dBm' : ''))
                              .addTo(map);
                        });
                    })
                    .catch(() => {}); // non-critical
            }
        } catch (err) {
            console.error('initTraceMap error for', mapId, err);
            if (map) { try { map.remove(); } catch(e) {} }
            el.innerHTML = '<div class="text-muted p-3 small">Map could not be rendered.</div>';
        }
    }

    initTraceMap('traceMap',  hops1, connectedSpans, isLightPath1, upstreamDir1);
    initTraceMap('traceMap2', hops2, [],             isLightPath2, upstreamDir2);
});
JS;
    });
}

// Normalize variables for content renderer
$viewSpans            = $spans;
$viewSpanId           = $spanId;
$viewFibre            = $fibre;
$viewTraceType        = $traceType;
$viewTraceResults     = $traceResults;
$viewTraceResultsFrom = $traceResultsFrom;
$viewTraceResultsTo   = $traceResultsTo;
$viewSpanInfo         = $spanInfo;
$viewError            = $error;
$viewFlash            = $flash;
// Tab 2
$viewActiveTab      = $activeTab;
$viewFdcList        = $fdcList;
$viewFdcId2         = $fdcId2;
$viewFdcPort2       = $fdcPort2;
$viewFdcTraceType2  = $fdcTraceType2;
$viewFdcPlan2       = $fdcPlan2;
$viewFdcInfo2       = $fdcInfo2;
$viewFdcMainSpan2   = $fdcMainSpan2;
$viewFdcPanel2      = $fdcPanel2;
$viewFdcPortMap2    = $fdcPortMap2;
$viewFdcFiberCount2 = $fdcFiberCount2;
$viewFdcConnected2  = $fdcConnected2;
$viewFdcTraceFrom2  = $fdcTraceFrom2;
$viewFdcTraceTo2    = $fdcTraceTo2;
$viewFdcError2      = $fdcError2;
$viewOltTxDbm       = $oltTxDbm;
$viewFollowPlan     = $followPlan;
$viewUispDevicesAge = $uispDevicesAge;
// Tab 3
$viewCMapFoscId     = $cMapFoscId;
$viewCMapPlan       = $cMapPlan;
$viewCMapFoscInfo   = $cMapFoscInfo;
$viewCMapSpans      = $cMapSpans;
$viewCMapRows       = $cMapRows;
$viewCMapPanels     = $cMapPanels;

$PAGE->setContentRenderer(function () use (
    $viewSpans, $viewSpanId, $viewFibre, $viewTraceType,
    $viewTraceResults, $viewTraceResultsFrom, $viewTraceResultsTo,
    $viewSpanInfo, $viewError, $viewFlash, $CSRF,
    $viewActiveTab, $viewFdcList, $viewFdcId2, $viewFdcPort2,
    $viewFdcTraceType2, $viewFdcPlan2, $viewFdcInfo2, $viewFdcMainSpan2, $viewFdcPanel2, $viewFdcPortMap2,
    $viewFdcFiberCount2, $viewFdcConnected2,
    $viewFdcTraceFrom2, $viewFdcTraceTo2, $viewFdcError2,
    $viewOltTxDbm, $viewFollowPlan, $viewUispDevicesAge,
    $viewCMapFoscId, $viewCMapPlan, $viewCMapFoscInfo, $viewCMapSpans, $viewCMapRows,
    $viewCMapPanels
) {
    $spans            = $viewSpans;
    $spanId           = $viewSpanId;
    $fibre            = $viewFibre;
    $traceType        = $viewTraceType;
    $followPlan       = $viewFollowPlan;
    $traceResults     = $viewTraceResults;
    $traceResultsFrom = $viewTraceResultsFrom;
    $traceResultsTo   = $viewTraceResultsTo;
    $spanInfo         = $viewSpanInfo;
    $error            = $viewError;
    $flash            = $viewFlash;
    $activeTab        = $viewActiveTab;
    $fdcList          = $viewFdcList;
    $fdcId2           = $viewFdcId2;
    $fdcPort2         = $viewFdcPort2;
    $fdcTraceType2    = $viewFdcTraceType2;
    $fdcPlan2         = $viewFdcPlan2;
    $fdcInfo2         = $viewFdcInfo2;
    $fdcMainSpan2     = $viewFdcMainSpan2;
    $fdcPanel2        = $viewFdcPanel2;
    $fdcPortMap2      = $viewFdcPortMap2;
    $fdcFiberCount2   = $viewFdcFiberCount2;
    $fdcConnected2    = $viewFdcConnected2;
    $fdcTraceFrom2    = $viewFdcTraceFrom2;
    $fdcTraceTo2      = $viewFdcTraceTo2;
    $fdcError2        = $viewFdcError2;
    $oltTxDbm         = $viewOltTxDbm;
    $uispDevicesAge   = $viewUispDevicesAge;
    $cMapFoscId       = $viewCMapFoscId;
    $cMapPlan         = $viewCMapPlan;
    $cMapFoscInfo     = $viewCMapFoscInfo;
    $cMapSpans        = $viewCMapSpans;
    $cMapRows         = $viewCMapRows;
    $cMapPanels       = $viewCMapPanels;

    $tab1Href = 'index.php?page=fibre_trace'
              . ($spanId ? '&span_id='.$spanId.'&fibre='.$fibre.'&type='.urlencode($traceType) : '');
    $tab2Href = 'index.php?page=fibre_trace&tab=2'
              . ($fdcId2 ? '&fdc_id='.$fdcId2.'&fdc_type='.urlencode($fdcTraceType2) : '');
    $tab3Href = 'index.php?page=fibre_trace&tab=3'
              . ($cMapFoscId ? '&cmap_fosc='.$cMapFoscId : '')
              . ($cMapPlan   ? '&cmap_plan=1' : '');

    ob_start();
    ?>
    <!-- Tab navigation — top of page -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === '1' || $activeTab === '' ? 'active' : '' ?>"
               href="<?= $tab1Href ?>">Trace</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === '2' ? 'active' : '' ?>"
               href="<?= $tab2Href ?>">Patch Panel View</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === '3' ? 'active' : '' ?>"
               href="<?= $tab3Href ?>">Cable Map</a>
        </li>
    </ul>

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0">Fibre Trace</h3>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=cable_spans">Cable Spans</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>">
            <?= h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === '1' || $activeTab === ''): ?>
    <!-- ════ TAB 1: Fibre Trace ════════════════════════════════════════ -->

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Trace Fibre Path</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <input type="hidden" name="page" value="fibre_trace">
                
                <div class="col-md-4">
                    <label class="form-label">Cable Span *</label>
                    <select class="form-select" name="span_id" required onchange="updateFibreMax(this)">
                        <option value="">Select cable span...</option>
                        <?php foreach ($spans as $s): ?>
                            <option value="<?= (int)$s['cable_span_id'] ?>" 
                                    data-fibre-count="<?= (int)$s['fibre_count'] ?>"
                                    <?= $spanId === (int)$s['cable_span_id'] ? 'selected' : '' ?>>
                                <?= h($s['cable_name']) ?> — 
                                <?= h($s['from_label']) ?> → <?= h($s['to_label']) ?>
                                (<?= (int)$s['fibre_count'] ?>F)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Fibre # *</label>
                    <input type="number" class="form-control" name="fibre"
                           min="1" max="288" step="1"
                           value="<?= $fibre > 0 ? $fibre : '' ?>"
                           id="fibreInput" required>
                    <div class="form-text" id="fibreHint">1-288</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Trace Type *</label>
                    <select class="form-select" name="type" required>
                        <option value="physical" <?= $traceType === 'physical' ? 'selected' : '' ?>>
                            Physical Fibre
                        </option>
                        <option value="light" <?= $traceType === 'light' ? 'selected' : '' ?>>
                            Light Path (Splitters)
                        </option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="plan" value="1"
                               id="planModeCheck" <?= $followPlan ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="planModeCheck">Include planned</label>
                    </div>
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Trace</button>
                </div>
            </form>

            <script>
            function updateFibreMax(select) {
                const option = select.options[select.selectedIndex];
                const fibreCount = option.getAttribute('data-fibre-count');
                const input = document.getElementById('fibreInput');
                const hint = document.getElementById('fibreHint');
                if (fibreCount) {
                    input.max = fibreCount;
                    hint.textContent = '1-' + fibreCount;
                }
            }
            </script>
        </div>
    </div>

    <?php if ($spanInfo && !empty($traceResults)): ?>
        <!-- Span Info -->
        <div class="card mb-3 shadow-sm">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Cable:</strong> <?= h($spanInfo['cable_name']) ?>
                        (<?= (int)$spanInfo['fiber_count'] ?>F)
                    </div>
                    <div class="col-md-4">
                        <strong>Route:</strong>
                        <?= h($spanInfo['from_label']) ?> ↔ <?= h($spanInfo['to_label']) ?>
                        (<?= number_format((float)$spanInfo['length_m'], 1) ?> m)
                    </div>
                    <div class="col-md-4">
                        <span class="badge me-1" style="background:#0d6efd">●</span>
                        Via <strong><?= h($spanInfo['to_label']) ?></strong>
                        &nbsp;
                        <span class="badge me-1" style="background:#198754">●</span>
                        Via <strong><?= h($spanInfo['from_label']) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Map Visualization -->
        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light">
                <strong>Map View</strong>
            </div>
            <div class="card-body">
                <div id="traceMap"></div>
            </div>
        </div>

        <!-- Trace Results Flowchart -->
        <?php $isLight = ($traceType === 'light'); ?>
        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>Trace Results</strong>
                <span class="badge bg-secondary"><?= count($traceResults) ?> hop<?= count($traceResults) !== 1 ? 's' : '' ?> total</span>
            </div>
            <div class="card-body">
                <?php
                $oltFrom  = trace_find_olt($traceResultsFrom);
                $oltTo    = trace_find_olt($traceResultsTo);
                $fromRole = $oltFrom ? 'upstream' : ($oltTo ? 'downstream' : null);
                $toRole   = $oltTo   ? 'upstream' : ($oltFrom ? 'downstream' : null);
                $fromOlt  = $oltFrom ? ($oltFrom['device_name'] ?? null) : null;
                $toOlt    = $oltTo   ? ($oltTo['device_name']   ?? null) : null;
                // For upstream direction: keep only the single linear path to OLT.
                // Sibling branches (other subscriber fibres sharing the same upstream
                // splitter) are irrelevant when tracing one specific fibre.
                $traceFromDisp = ($fromRole === 'upstream') ? trace_linear_upstream($traceResultsFrom) : $traceResultsFrom;
                $traceToDisp   = ($toRole   === 'upstream') ? trace_linear_upstream($traceResultsTo)   : $traceResultsTo;
                $hasTo    = !empty($traceToDisp);
                // Total upstream path loss — carried as base into downstream link budget
                $upLoss1 = 0.0;
                if ($isLight) {
                    $upArr1 = ($fromRole === 'upstream') ? $traceFromDisp : (($toRole === 'upstream') ? $traceToDisp : []);
                    if ($upArr1) {
                        $ls = array_filter(array_map(fn($r) => (float)($r['cumulative_loss_db'] ?? 0), $upArr1));
                        $upLoss1 = $ls ? max($ls) : 0.0;
                    }
                }
                ?>
                <?php if ($isLight): ?>
                    <?php if ($fromRole === 'upstream' && $hasTo): ?>
                        <?php /* Downstream first — more interesting subscriber path */ ?>
                        <?php tfc_render_direction($traceToDisp, '#198754',
                            'Via <strong>'.h($spanInfo['from_label']).'</strong>', $isLight, $toRole, $toOlt, $upLoss1, $oltTxDbm); ?>
                        <hr class="my-4">
                        <?php tfc_render_direction($traceFromDisp, '#0d6efd',
                            'Via <strong>'.h($spanInfo['to_label']).'</strong>', $isLight, $fromRole, $fromOlt, 0.0, $oltTxDbm); ?>
                    <?php elseif ($toRole === 'upstream'): ?>
                        <?php tfc_render_direction($traceFromDisp, '#0d6efd',
                            'Via <strong>'.h($spanInfo['to_label']).'</strong>', $isLight, $fromRole, $fromOlt, $upLoss1, $oltTxDbm); ?>
                        <?php if ($hasTo): ?>
                            <hr class="my-4">
                            <?php tfc_render_direction($traceToDisp, '#198754',
                                'Via <strong>'.h($spanInfo['from_label']).'</strong>', $isLight, $toRole, $toOlt, 0.0, $oltTxDbm); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php /* No OLT detected — original order */ ?>
                        <?php tfc_render_direction($traceFromDisp, '#0d6efd',
                            'Via <strong>'.h($spanInfo['to_label']).'</strong>', $isLight, null, null, 0.0, $oltTxDbm); ?>
                        <?php if ($hasTo): ?>
                            <hr class="my-4">
                            <?php tfc_render_direction($traceToDisp, '#198754',
                                'Via <strong>'.h($spanInfo['from_label']).'</strong>', $isLight, null, null, 0.0, $oltTxDbm); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <?php tfc_render_direction($traceFromDisp, '#0d6efd',
                                'Via <strong>'.h($spanInfo['to_label']).'</strong>', $isLight, $fromRole, $fromOlt); ?>
                        </div>
                        <div class="col-md-6" style="border-left:1px solid #dee2e6">
                            <?php tfc_render_direction($traceToDisp, '#198754',
                                'Via <strong>'.h($spanInfo['from_label']).'</strong>', $isLight, $toRole, $toOlt); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($spanId > 0 && $fibre > 0 && empty($traceResults) && !$error): ?>
        <div class="alert alert-warning">
            No path found for fibre #<?= $fibre ?> on span #<?= $spanId ?>.
            This fibre may not be spliced yet.
        </div>
    <?php endif; ?>

    <?php elseif ($activeTab === '2'): ?>
    <!-- ════ TAB 2: FDC Port View ══════════════════════════════════════ -->

    <!-- Patch panel selector: Bootstrap cards -->
    <div class="mb-3">
        <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
            <span class="fw-semibold text-muted small text-uppercase" style="letter-spacing:.04em">Select panel</span>
            <div class="d-flex align-items-center gap-1">
                <span class="small text-muted me-1">Type:</span>
                <?php foreach (['light' => 'Light Path', 'physical' => 'Physical'] as $tv => $tl): ?>
                    <a href="index.php?page=fibre_trace&tab=2&fdc_id=<?= $fdcId2 ?>&fdc_type=<?= $tv ?>&fdc_plan=<?= $fdcPlan2 ? '1' : '0' ?>"
                       class="btn btn-sm <?= $fdcTraceType2 === $tv ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= $tl ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="form-check ms-2">
                <input class="form-check-input" type="checkbox" id="fdcPlan2chk"
                       <?= $fdcPlan2 ? 'checked' : '' ?>
                       onchange="location.href='index.php?page=fibre_trace&tab=2&fdc_id=<?= $fdcId2 ?>&fdc_type=<?= h($fdcTraceType2) ?><?= $fdcPort2 ? '&fdc_port='.$fdcPort2 : '' ?>&fdc_plan=' + (this.checked ? '1' : '0')">
                <label class="form-check-label small" for="fdcPlan2chk">Include plan splices</label>
            </div>
        </div>
        <div class="row g-2">
            <?php foreach ($fdcList as $f):
                $fid     = (int)$f['fosc_id'];
                $isActive = $fdcId2 === $fid;
                $kindBadge = $f['kind'] === 'cabinet'
                    ? '<span class="badge bg-warning text-dark">FDC</span>'
                    : '<span class="badge bg-info text-dark">Patch Panel</span>';
                $href = "index.php?page=fibre_trace&tab=2&fdc_id={$fid}&fdc_type=" . h($fdcTraceType2)
                      . '&fdc_plan=' . ($fdcPlan2 ? '1' : '0');
            ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <a href="<?= $href ?>" class="text-decoration-none">
                    <div class="card h-100 shadow-sm<?= $isActive ? ' border-primary' : '' ?>"
                         style="<?= $isActive ? 'border-width:2px' : '' ?>">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong class="fs-6<?= $isActive ? ' text-primary' : '' ?>"><?= h($f['splice_num']) ?></strong>
                                <?= $kindBadge ?>
                            </div>
                            <?php if (!empty($f['notes'])): ?>
                                <div class="small text-muted" style="line-height:1.3"><?= h($f['notes']) ?></div>
                            <?php else: ?>
                                <div class="small text-muted fst-italic">No notes</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($fdcError2 && !$fdcInfo2): ?>
        <div class="alert alert-danger"><?= h($fdcError2) ?></div>
    <?php elseif ($fdcInfo2 && !$fdcMainSpan2): ?>
        <div class="alert alert-warning">No cable span found for <?= h($fdcInfo2['splice_num']) ?>.</div>
    <?php elseif ($fdcInfo2 && ($fdcPanel2 || $fdcMainSpan2)): ?>

    <!-- Port grid -->
    <div class="card mb-3 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><?= h($fdcInfo2['splice_num']) ?> — Connected Ports</strong>
            <div class="d-flex align-items-center gap-3">
                <?php if ($uispDevicesAge !== null):
                    $ageMin  = (int)round($uispDevicesAge / 60);
                    $ageTxt  = $ageMin < 2 ? 'just now' : $ageMin . ' min ago';
                    $ageClass = $uispDevicesAge > 300 ? 'text-warning' : 'text-muted';
                ?>
                <span class="small <?= $ageClass ?>" title="UISP devices cache age">
                    UISP: <?= $ageTxt ?>
                </span>
                <?php endif; ?>
                <span class="text-muted small">
                    <?php if ($fdcPanel2): ?>
                        <?= h($fdcPanel2['name']) ?> ·
                        <?= (int)$fdcFiberCount2 ?> ports ·
                        <?= count($fdcConnected2) ?> connected
                    <?php else: ?>
                        <?= h($fdcMainSpan2['cable_name']) ?> (<?= $fdcFiberCount2 ?>F) ·
                        <?= h($fdcMainSpan2['from_label']) ?> ↔ <?= h($fdcMainSpan2['to_label']) ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($fdcConnected2)): ?>
                <p class="text-muted mb-0">Nothing is spliced to this panel yet.</p>
            <?php elseif ($fdcPanel2): ?>
                <div class="fdc2-ports">
                    <?php for ($fn = 1; $fn <= (int)$fdcFiberCount2; $fn++):
                        $pm       = $fdcPortMap2[$fn] ?? null;
                        $isActive = ($fdcPort2 === $fn);
                        $portUrl  = 'index.php?page=fibre_trace&tab=2&fdc_id='.$fdcId2
                                  . '&fdc_port='.$fn.'&fdc_type='.urlencode($fdcTraceType2)
                                  . '&fdc_plan='.($fdcPlan2 ? '1' : '0');
                        $title    = $pm
                            ? $pm['cable_name'].' F'.(int)$pm['fibre']
                              . (($pm['is_plan'] === true || $pm['is_plan'] === 't') ? ' (plan)' : '')
                            : 'not connected';
                    ?>
                        <?php if ($pm): ?>
                            <a href="<?= $portUrl ?>" title="<?= h($title) ?>"
                               class="fdc2-port<?= $isActive ? ' fdc2-active' : '' ?>"><?= $fn ?></a>
                        <?php else: ?>
                            <span class="fdc2-port fdc2-free" title="not connected"><?= $fn ?></span>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <div class="fdc2-ports">
                    <?php foreach ($fdcConnected2 as $fn => $_): ?>
                        <?php
                        $portUrl  = 'index.php?page=fibre_trace&tab=2&fdc_id='.$fdcId2
                                  . '&fdc_port='.$fn.'&fdc_type='.urlencode($fdcTraceType2)
                                  . '&fdc_plan='.($fdcPlan2 ? '1' : '0');
                        $isActive = ($fdcPort2 === $fn);
                        ?>
                        <a href="<?= $portUrl ?>"
                           class="fdc2-port<?= $isActive ? ' fdc2-active' : '' ?>">F<?= $fn ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trace results for selected port -->
    <?php if ($fdcPort2 > 0): ?>
    <div class="card mb-3 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong>
                <?= h($fdcInfo2['splice_num']) ?> ·
                <?php if ($fdcPanel2 && isset($fdcPortMap2[$fdcPort2])):
                        $pm2 = $fdcPortMap2[$fdcPort2]; ?>
                  Port <?= $fdcPort2 ?>
                  <span class="text-muted fw-normal small">
                    <?= h($pm2['cable_name']) ?> F<?= (int)$pm2['fibre'] ?></span> &mdash;
                <?php else: ?>
                  F<?= $fdcPort2 ?> &mdash;
                <?php endif; ?>
                <?= $fdcTraceType2 === 'light' ? 'Light Path' : 'Physical' ?>
            </strong>
            <?php
              $openSid = ($fdcPanel2 && isset($fdcPortMap2[$fdcPort2]))
                       ? (int)$fdcPortMap2[$fdcPort2]['sid'] : (int)($fdcMainSpan2['cable_span_id'] ?? 0);
              $openFib = ($fdcPanel2 && isset($fdcPortMap2[$fdcPort2]))
                       ? (int)$fdcPortMap2[$fdcPort2]['fibre'] : $fdcPort2;
            ?>
            <a href="index.php?page=fibre_trace&span_id=<?= $openSid ?>&fibre=<?= $openFib ?>&type=<?= h($fdcTraceType2) ?>"
               class="btn btn-outline-secondary btn-sm">Open in Trace ↗</a>
        </div>
        <div class="card-body">
            <?php if (empty($fdcError2) && (!empty($fdcTraceFrom2) || !empty($fdcTraceTo2))): ?>
            <div id="traceMap2" class="mb-3"></div>
            <?php endif; ?>
            <?php if ($fdcError2): ?>
                <div class="alert alert-danger mb-0"><?= h($fdcError2) ?></div>
            <?php elseif (empty($fdcTraceFrom2) && empty($fdcTraceTo2)): ?>
                <div class="alert alert-warning mb-0">No path found for
                  <?= $fdcPanel2 ? 'port ' . (int)$fdcPort2 : 'F' . (int)$fdcPort2 ?>.</div>
            <?php else: ?>
                <?php
                $isLight2  = ($fdcTraceType2 === 'light');
                $te2       = $fdcMainSpan2['trace_end'];   // direction away from FDC
                $farLabel  = h($te2 === 'to' ? $fdcMainSpan2['to_label']   : $fdcMainSpan2['from_label']);
                $nearLabel = h($te2 === 'to' ? $fdcMainSpan2['from_label'] : $fdcMainSpan2['to_label']);
                $hasOut    = !empty($fdcTraceFrom2) && count($fdcTraceFrom2) > 1;
                $hasIn     = !empty($fdcTraceTo2)   && count($fdcTraceTo2)   > 1;
                ?>
                <?php
                $oltFdcFrom  = trace_find_olt($fdcTraceFrom2);
                $oltFdcTo    = trace_find_olt($fdcTraceTo2);
                $fdcFromRole = $oltFdcFrom ? 'upstream' : ($oltFdcTo ? 'downstream' : null);
                $fdcToRole   = $oltFdcTo   ? 'upstream' : ($oltFdcFrom ? 'downstream' : null);
                $fdcFromOlt  = $oltFdcFrom ? ($oltFdcFrom['device_name'] ?? null) : null;
                $fdcToOlt    = $oltFdcTo   ? ($oltFdcTo['device_name']   ?? null) : null;
                $fdcFromDisp = ($fdcFromRole === 'upstream') ? trace_linear_upstream($fdcTraceFrom2) : $fdcTraceFrom2;
                $fdcToDisp   = ($fdcToRole   === 'upstream') ? trace_linear_upstream($fdcTraceTo2)   : $fdcTraceTo2;
                $hasOut = !empty($fdcFromDisp);
                $hasIn  = !empty($fdcToDisp);
                $upLoss2 = 0.0;
                if ($isLight2) {
                    $upArr2 = ($fdcFromRole === 'upstream') ? $fdcFromDisp : (($fdcToRole === 'upstream') ? $fdcToDisp : []);
                    if ($upArr2) {
                        $ls2 = array_filter(array_map(fn($r) => (float)($r['cumulative_loss_db'] ?? 0), $upArr2));
                        $upLoss2 = $ls2 ? max($ls2) : 0.0;
                    }
                }
                ?>
                <?php if (!$hasOut && !$hasIn): ?>
                    <p class="text-muted mb-0">Terminal — fibre not spliced at either end.</p>
                <?php elseif ($fdcFromRole === 'upstream' && $hasIn): ?>
                    <?php /* Downstream first */ ?>
                    <?php tfc_render_direction($fdcToDisp, '#198754',
                        'Via <strong>'.$nearLabel.'</strong>', $isLight2, $fdcToRole, $fdcToOlt, $upLoss2, $isLight2 ? $oltTxDbm : null); ?>
                    <?php if ($hasOut): ?>
                        <hr class="my-4">
                        <?php tfc_render_direction($fdcFromDisp, '#0d6efd',
                            'Via <strong>'.$farLabel.'</strong>', $isLight2, $fdcFromRole, $fdcFromOlt, 0.0, $isLight2 ? $oltTxDbm : null); ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($hasOut): ?>
                        <?php tfc_render_direction($fdcFromDisp, '#0d6efd',
                            'Via <strong>'.$farLabel.'</strong>', $isLight2, $fdcFromRole, $fdcFromOlt, $fdcFromRole === 'downstream' ? $upLoss2 : 0.0, $isLight2 ? $oltTxDbm : null); ?>
                    <?php endif; ?>
                    <?php if ($hasOut && $hasIn): ?><hr class="my-4"><?php endif; ?>
                    <?php if ($hasIn): ?>
                        <?php tfc_render_direction($fdcToDisp, '#198754',
                            'Via <strong>'.$nearLabel.'</strong>', $isLight2, $fdcToRole, $fdcToOlt, $fdcToRole === 'downstream' ? $upLoss2 : 0.0, $isLight2 ? $oltTxDbm : null); ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // fdcPort2 > 0 ?>

    <?php endif; // fdcInfo2 && fdcMainSpan2 ?>

    <?php elseif ($activeTab === '3'): ?>
    <!-- ════ TAB 3: Cable Map ══════════════════════════════════════════ -->

    <!-- Feed-closure selector -->
    <div class="card mb-3 shadow-sm">
      <div class="card-body py-2">
        <form method="get" class="d-flex align-items-center gap-3 flex-wrap">
          <input type="hidden" name="page" value="fibre_trace">
          <input type="hidden" name="tab"  value="3">
          <label class="form-label mb-0 small fw-semibold">Feed Point</label>
          <select name="cmap_fosc" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <?php foreach ($fdcList as $f): ?>
              <option value="<?= (int)$f['fosc_id'] ?>"
                      <?= $cMapFoscId === (int)$f['fosc_id'] ? 'selected' : '' ?>>
                <?= h($f['splice_num']) ?> (<?= h($f['kind']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-check ms-2">
            <input class="form-check-input" type="checkbox" name="cmap_plan" id="cmapPlan"
                   value="1" <?= $cMapPlan ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label small" for="cmapPlan">Include plan splices</label>
          </div>
        </form>
      </div>
    </div>

    <?php if ($cMapFoscInfo && !empty($cMapPanels)): ?>
      <?php foreach ($cMapPanels as $pan):
        // Merge consecutive ports that do the same thing. 288 rows answer "where
        // does port 173 go"; the blocks answer "what is this cabinet wired to",
        // which is the question someone opening the page actually has.
        $blocks = [];
        for ($port = 1; $port <= $pan['ports']; $port++) {
            $m    = $pan['map'][$port] ?? null;
            $sid  = $m ? (int)$m['sid'] : 0;
            $fib  = $m ? (int)$m['fibre'] : 0;
            $tr   = $sid ? ($cMapRows[$sid][$fib] ?? null) : null;
            $span = null;
            foreach ($cMapSpans as $sp) if ((int)$sp['cable_span_id'] === $sid) { $span = $sp; break; }
            $end  = $tr && $tr['fosc_id'] ? (string)$tr['fosc_label'] : ($sid ? '' : null);
            $sig  = $sid . '|' . $end . '|' . ($m && ($m['is_plan'] === true || $m['is_plan'] === 't') ? 'p' : 'b');
            $last = $blocks ? $blocks[count($blocks) - 1] : null;
            if ($last && $last['sig'] === $sig && $last['hi'] + 1 === $port) {
                $blocks[count($blocks) - 1]['hi']   = $port;
                $blocks[count($blocks) - 1]['fhi']  = $fib;
                $blocks[count($blocks) - 1]['dist'] = $tr ? (int)$tr['dist_m'] : null;
                continue;
            }
            $blocks[] = ['lo' => $port, 'hi' => $port, 'flo' => $fib, 'fhi' => $fib,
                         'sig' => $sig, 'sid' => $sid, 'span' => $span, 'end' => $end,
                         'fosc_id' => $tr ? $tr['fosc_id'] : null,
                         'dist' => $tr ? (int)$tr['dist_m'] : null,
                         'plan' => $m && ($m['is_plan'] === true || $m['is_plan'] === 't')];
        }
        $usedPorts = 0;
        foreach ($blocks as $bk) if ($bk['sid']) $usedPorts += $bk['hi'] - $bk['lo'] + 1;
      ?>
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2 d-flex align-items-center gap-2 flex-wrap">
          <strong><?= h($pan['name']) ?></strong>
          <span class="text-muted small">
            <?= h($cMapFoscInfo['splice_num']) ?> &middot; <?= (int)$pan['ports'] ?> ports
            &middot; <?= (int)$usedPorts ?> connected
          </span>
        </div>
        <div class="card-body p-0">
          <!-- What the panel does, in blocks: the quick read before the port list. -->
          <div class="px-3 py-2 border-bottom bg-light-subtle small">
            <?php foreach ($blocks as $bk):
              $pr = $bk['lo'] === $bk['hi'] ? $bk['lo'] : $bk['lo'].'&ndash;'.$bk['hi'];
            ?>
              <span class="d-inline-block me-3 mb-1">
                <span class="font-monospace fw-semibold"><?= $pr ?></span>
                <?php if ($bk['sid']): ?>
                  <span class="text-muted">&rarr;</span>
                  <?= $bk['end'] !== '' ? h($bk['end']) : '<span class="text-muted fst-italic">no trace</span>' ?>
                  <?php if ($bk['dist'] !== null): ?>
                    <span class="text-muted">(<?= number_format($bk['dist']) ?> m)</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted fst-italic">free</span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>

          <table class="table table-sm table-hover mb-0 align-middle" style="width:auto;min-width:620px">
            <thead class="table-light">
              <tr>
                <th class="ps-3" style="width:70px">Port</th>
                <th style="width:200px">Cable</th>
                <th style="width:110px">Fibre</th>
                <th class="text-end" style="width:100px">Distance</th>
                <th>Ends at</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($port = 1; $port <= $pan['ports']; $port++):
                $m    = $pan['map'][$port] ?? null;
                $sid  = $m ? (int)$m['sid'] : 0;
                $fib  = $m ? (int)$m['fibre'] : 0;
                $tr   = $sid ? ($cMapRows[$sid][$fib] ?? null) : null;
                $span = null;
                foreach ($cMapSpans as $sp) if ((int)$sp['cable_span_id'] === $sid) { $span = $sp; break; }
                $isPlan = $m && ($m['is_plan'] === true || $m['is_plan'] === 't');
              ?>
              <tr<?= $sid ? '' : ' class="text-muted"' ?>>
                <td class="ps-3 font-monospace fw-semibold"><?= $port ?></td>
                <td>
                  <?php if ($span): ?>
                    <?= (int)$span['fibre_count'] ?>F
                    <span class="text-muted small">&rarr; <?= h($span['to_label']) ?></span>
                  <?php else: ?>
                    <span class="small fst-italic">not connected</span>
                  <?php endif; ?>
                </td>
                <td class="font-monospace small">
                  <?php if ($sid): ?>
                    <?= itu_fiber_html($fib) ?> F<?= $fib ?>
                  <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="text-end"><?= $tr && $tr['dist_m'] !== null ? number_format((int)$tr['dist_m']).' m' : '&mdash;' ?></td>
                <td>
                  <?php if ($tr && $tr['fosc_id']): ?>
                    <a href="index.php?page=closure&fosc_id=<?= (int)$tr['fosc_id'] ?>"
                       class="text-decoration-none"><?= h($tr['fosc_label']) ?></a>
                    <?php if ($isPlan): ?>
                      <span class="badge bg-warning text-dark ms-1 small">plan</span>
                    <?php endif; ?>
                  <?php elseif ($sid): ?>
                    <span class="text-muted small fst-italic">spliced, no trace</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($cMapFoscInfo && !empty($cMapSpans)): ?>
      <?php
        $svgC = [
            'BL'=>'#0066cc','OR'=>'#f37320','GR'=>'#00a651','BR'=>'#795548',
            'SL'=>'#90a4ae','WH'=>'#e0e0e0','RD'=>'#e53935','BK'=>'#424242',
            'YL'=>'#fdd835','VT'=>'#8e24aa','RS'=>'#e91e63','AQ'=>'#00bcd4',
        ];
      ?>
      <?php foreach ($cMapSpans as $span):
        $sid  = (int)$span['cable_span_id'];
        $rows = $cMapRows[$sid] ?? [];
      ?>
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2 d-flex align-items-center gap-2 flex-wrap">
          <strong><?= h($span['cable_name']) ?></strong>
          <span class="text-muted small">
            <?= h($cMapFoscInfo['splice_num']) ?> → <?= h($span['to_label']) ?>
            · <?= (int)$span['fibre_count'] ?>F
          </span>
          <?php if ($cMapPlan): ?>
            <span class="badge bg-warning text-dark ms-1 small">+plan</span>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <table class="table table-sm table-hover mb-0 align-middle" style="width:auto;min-width:420px">
            <thead class="table-light">
              <tr>
                <th class="ps-3" style="width:70px">Fiber</th>
                <th style="width:44px"></th>
                <th class="text-end pe-3" style="width:100px">Distance</th>
                <th>Terminates at</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $f => $row):
                $hasTrace = isset($row['hops']) && $row['hops'] !== null;
                $foscId   = $row['fosc_id'] ? (int)$row['fosc_id'] : null;
              ?>
              <tr>
                <td class="ps-3 fw-semibold text-secondary small">F<?= $f ?></td>
                <td><?= itu_fiber_html($f) ?></td>
                <td class="text-end pe-3 <?= $hasTrace ? 'fw-semibold' : 'text-muted' ?>">
                  <?= $hasTrace ? number_format((int)$row['dist_m']).' m' : '—' ?>
                </td>
                <td>
                  <?php if ($foscId): ?>
                    <a href="index.php?page=closure&fosc_id=<?= $foscId ?>" class="text-decoration-none">
                      <?= h($row['fosc_label']) ?>
                    </a>
                  <?php elseif ($hasTrace): ?>
                    <span class="text-muted small fst-italic">no closure data</span>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border small">No splice</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <!-- ── SVG fiber-length map ── -->
          <div class="border-top" style="overflow-x:auto">
          <?php
            $maxDist = 1;
            foreach ($rows as $r) {
                if (isset($r['dist_m']) && (int)$r['dist_m'] > $maxDist)
                    $maxDist = (int)$r['dist_m'];
            }
            $svgFc  = (int)$span['fibre_count'];
            $rowH   = 14;   // px per row in SVG units
            $barH   = 8;
            $barTop = 3;    // offset from row top to bar top
            $lx     = 36;   // left x: where bars start (fiber-number label on left)
            $maxBW  = 520;  // max bar width
            $svgW   = 860;  // total SVG width (bar area + label area)
            $svgH   = $svgFc * $rowH + 8;

            $svg  = '<svg viewBox="0 0 '.$svgW.' '.$svgH.'" xmlns="http://www.w3.org/2000/svg"'
                  . ' style="width:100%;min-width:560px;max-width:'.$svgW.'px;height:'.$svgH.'px;display:block;padding:4px 0">'
                  . '<style>.cmfn{font:10px sans-serif;fill:#aaa}.cmlb{font:11px sans-serif;fill:#1565c0}'
                  . '.cmln{font:10px sans-serif;fill:#999}</style>';

            for ($fi = 1; $fi <= $svgFc; $fi++) {
                $r   = $rows[$fi] ?? null;
                $yb  = ($fi - 1) * $rowH + 4 + $barTop;   // bar top y
                $ytx = $yb + 7;                            // text baseline

                $code = itu_fiber_code($fi);
                $ck   = str_contains($code, '/') ? substr($code, strpos($code, '/') + 1) : $code;
                $col  = $svgC[$ck] ?? '#888';
                $stk  = ($ck === 'WH') ? ' stroke="#ccc" stroke-width="0.5"' : '';

                // Gray track (full width background)
                $svg .= '<rect x="'.$lx.'" y="'.$yb.'" width="'.$maxBW.'" height="'.$barH.'" rx="2" fill="#ebebeb"/>';

                $hasT = $r && isset($r['hops']) && $r['hops'] !== null && (int)($r['dist_m'] ?? 0) > 0;
                if ($hasT) {
                    $bw = max(3, (int)round((int)$r['dist_m'] / $maxDist * $maxBW));
                    $ex = $lx + $bw;
                    $ey = $yb + intdiv($barH, 2);
                    // Colored bar
                    $svg .= '<rect x="'.$lx.'" y="'.$yb.'" width="'.$bw.'" height="'.$barH
                          . '" rx="2" fill="'.$col.'"'.$stk.'/>';
                    // Endpoint dot
                    $svg .= '<circle cx="'.$ex.'" cy="'.$ey.'" r="3" fill="'.$col.'"/>';
                    // Closure label
                    $lbl = h($r['fosc_label'] ?? '');
                    if ($r['fosc_id']) {
                        $href = h('index.php?page=closure&fosc_id='.(int)$r['fosc_id']);
                        $svg .= '<a href="'.$href.'"><text x="'.($ex+6).'" y="'.$ytx.'" class="cmlb">'.$lbl.'</text></a>';
                    } elseif ($lbl) {
                        $svg .= '<text x="'.($ex+6).'" y="'.$ytx.'" class="cmln">'.$lbl.'</text>';
                    }
                } else {
                    // No trace: tiny colored stub on left end only
                    $svg .= '<rect x="'.$lx.'" y="'.$yb.'" width="3" height="'.$barH.'" rx="1" fill="'.$col.'"'.$stk.'/>';
                }

                // Fiber number label (left of track)
                $svg .= '<text x="'.($lx-3).'" y="'.$ytx.'" class="cmfn" text-anchor="end">'.$fi.'</text>';
            }
            $svg .= '</svg>';
            echo $svg;
          ?>
          </div>

        </div>
      </div>
      <?php endforeach; ?>

    <?php elseif ($cMapFoscId > 0): ?>
      <div class="alert alert-warning">No outgoing cable spans found from this closure.</div>
    <?php endif; ?>

    <?php endif; // activeTab ?>

    <?php
    return ob_get_clean();
});
