<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/pop.helper.php
// Data-access helpers for the POP detail page (pages/pop_detail.php), including the
// simple patch-cable block diagram: terminated-fiber closures (patch panels) on one
// side, active POP devices on the other, plain lines for patch cables.

require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/timeline.helper.php'; // timeline_* used by the page

/** Fetch a single POP (with its map location), or null if not found. */
function fetch_pop(PDO $pdo, int $popId): ?array
{
    $st = $pdo->prepare("
        SELECT pop_id, name, notes,
               ST_Y(location) AS lat,
               ST_X(location) AS lon
          FROM app.pop
         WHERE pop_id = :pid
    ");
    $st->execute([':pid' => $popId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Member closures of a POP. */
function fetch_pop_members(PDO $pdo, int $popId): array
{
    $st = $pdo->prepare("
        SELECT fosc_id, splice_num, status, enclosure_rating
          FROM app.fosc
         WHERE pop_id = :pid
      ORDER BY splice_num ASC
    ");
    $st->execute([':pid' => $popId]);
    return $st->fetchAll() ?: [];
}

/**
 * Top-level devices homed at a POP (routers, OLTs, splitters, DWDM, …) — i.e. the real
 * devices rendered as blocks on the connection diagram. SFP/PON child modules (which plug
 * into a parent and render as rows inside its block) are excluded.
 */
function fetch_pop_devices(PDO $pdo, int $popId): array
{
    $st = $pdo->prepare("
        SELECT fosc_device_id, device_type, name
          FROM app.fosc_device
         WHERE pop_id = :pid
           AND parent_device_id IS NULL
      ORDER BY name ASC
    ");
    $st->execute([':pid' => $popId]);
    return $st->fetchAll() ?: [];
}

/**
 * Build the simple POP patch-cable diagram payload:
 *   closures: patch-panel blocks (one per member closure that terminated a cable) —
 *             label = closure splice_num, ports = used fibre numbers (port N == fibre N).
 *   devices:  POP-homed active-device blocks — ports = used port numbers.
 *   links:    one per patch cable, as terminal-to-terminal {a,b} pairs. Patches routed
 *             through intermediate jumper cable_spans are COLLAPSED to a single link by
 *             walking a connector-join graph (real distribution cables dead-end naturally
 *             because their field ends carry fusion splices, not connector joins).
 *
 * @return array{closures:array,devices:array,links:array}
 */
function fetch_pop_patch_diagram(PDO $pdo, int $popId): array
{
    $empty = ['closures' => [], 'devices' => [], 'links' => []];

    $st = $pdo->prepare("SELECT fosc_id FROM app.fosc WHERE pop_id = :pid");
    $st->execute([':pid' => $popId]);
    $memberIds = array_map('intval', array_column($st->fetchAll() ?: [], 'fosc_id'));
    if (!$memberIds) return $empty;
    $memberCsv = implode(',', $memberIds);

    // ── Blocks ────────────────────────────────────────────────────────────────
    // Closure-side = patch panels homed at a member closure (panel = cable termination).
    $panelRows = $pdo->query("
        SELECT fd.fosc_device_id AS id, fd.fosc_id, fd.name, fd.attrs::text AS attrs, fd.notes, f.splice_num
          FROM app.fosc_device fd
          JOIN app.fosc f ON f.fosc_id = fd.fosc_id
         WHERE fd.device_type = 'patch_panel'
           AND fd.fosc_id IN ($memberCsv)
      ORDER BY f.splice_num
    ")->fetchAll() ?: [];

    // Device-side = active gear homed at the POP.
    $devStmt = $pdo->prepare("
        SELECT fosc_device_id AS id, name, device_type, attrs::text AS attrs
          FROM app.fosc_device
         WHERE pop_id = :pid
      ORDER BY name
    ");
    $devStmt->execute([':pid' => $popId]);
    $devRows = $devStmt->fetchAll() ?: [];

    $panelById = [];  // device_id => panel row
    foreach ($panelRows as $p) { $panelById[(int)$p['id']] = $p; }
    $devById = [];    // device_id => active-device row
    foreach ($devRows as $d) { $devById[(int)$d['id']] = $d; }

    // Terminal device ids → which column they belong to.
    $terminalSide = [];               // device_id => 'closure' | 'device'
    foreach ($panelById as $id => $_) { $terminalSide[$id] = 'closure'; }
    foreach ($devById   as $id => $_) { $terminalSide[$id] = 'device'; }
    if (!$terminalSide) return $empty;

    // ── Connector-join graph ───────────────────────────────────────────────────
    // Load all active connector joins network-wide; BFS stays local to the POP via the
    // terminals, and field cables dead-end (no connector joins there).
    $joins = $pdo->query("
        SELECT splice_join_id AS sj,
               a_target, a_span_id, a_end, lower(a_range) AS a_lo, upper(a_range) AS a_hi,
               b_target, b_span_id, b_end, lower(b_range) AS b_lo, upper(b_range) AS b_hi
          FROM app.splice_join
         WHERE join_kind = 'connector' AND retired_at IS NULL
    ")->fetchAll() ?: [];

    // node keys:  device port  "D:<id>:<port>"   cable fibre "C:<span>:<end>:<fibre>"
    $adj = [];                         // node => [ [neighbour, sjId|null], … ]
    $addEdge = function (string $n1, string $n2, $sj) use (&$adj) {
        $adj[$n1][] = [$n2, $sj];
        $adj[$n2][] = [$n1, $sj];
    };
    $nodeOf = function (string $target, int $id, string $end, int $num): string {
        return $target === 'fosc_device' ? "D:$id:$num" : "C:$id:$end:$num";
    };
    $cableFibres = [];                 // "span:fibre" => [span, fibre]  (for pass-through)
    $noteCable = function (string $target, int $id, int $num) use (&$cableFibres) {
        if ($target === 'cable_span') { $cableFibres["$id:$num"] = [$id, $num]; }
    };

    foreach ($joins as $j) {
        $sj    = (int)$j['sj'];
        $aLo   = (int)$j['a_lo']; $aHi = (int)$j['a_hi'];
        $bLo   = (int)$j['b_lo'];
        $width = $aHi - $aLo;          // a/b ranges always share width
        for ($k = 0; $k < $width; $k++) {
            $na = $nodeOf($j['a_target'], (int)$j['a_span_id'], $j['a_end'], $aLo + $k);
            $nb = $nodeOf($j['b_target'], (int)$j['b_span_id'], $j['b_end'], $bLo + $k);
            $addEdge($na, $nb, $sj);
            $noteCable($j['a_target'], (int)$j['a_span_id'], $aLo + $k);
            $noteCable($j['b_target'], (int)$j['b_span_id'], $bLo + $k);
        }
    }
    // Cable continuity: a fibre passes straight through from 'from' end to 'to' end.
    // (Synthetic, sj=null — never itself a patch line.) Only for fibres we actually touch,
    // so real distribution cables only bridge ends that are joined to connectors.
    foreach ($cableFibres as [$span, $fibre]) {
        $addEdge("C:$span:from:$fibre", "C:$span:to:$fibre", null);
    }

    // ── End-to-end "active" set: which connector joins carry light from a laser source ──
    // Light flows through patch cables (connector joins) AND through devices: a laser source
    // (SFP / OLT-GPON) emits; a splitter broadcasts; a DWDM/OADM common ↔ its channels.
    // BFS the light graph from every laser-source port and collect every join sj it reaches.
    $devTypeAll = [];
    $devIdsInJoins = [];
    foreach ($joins as $j) {
        if ($j['a_target'] === 'fosc_device') $devIdsInJoins[(int)$j['a_span_id']] = true;
        if ($j['b_target'] === 'fosc_device') $devIdsInJoins[(int)$j['b_span_id']] = true;
    }
    if ($devIdsInJoins) {
        $din = implode(',', array_map('intval', array_keys($devIdsInJoins)));
        foreach ($pdo->query("SELECT fosc_device_id, device_type FROM app.fosc_device WHERE fosc_device_id IN ($din)")->fetchAll() ?: [] as $dr) {
            $devTypeAll[(int)$dr['fosc_device_id']] = $dr['device_type'];
        }
    }
    $passiveSet = ['dwdm', 'splitter', 'oadm', 'mux', 'demux', 'coupler'];
    $laserSet   = ['sfp', 'olt_pon'];
    $devPortNodes = [];                    // devId => set of port numbers present in the graph
    foreach ($adj as $node => $_) {
        if ($node[0] === 'D') { $pp = explode(':', $node); $devPortNodes[(int)$pp[1]][(int)$pp[2]] = true; }
    }
    $activeSj = [];
    $lvisited = [];
    $lqueue   = [];
    foreach ($devPortNodes as $devId => $ports) {
        if (in_array($devTypeAll[$devId] ?? '', $laserSet, true)) {
            foreach (array_keys($ports) as $p) {
                $n = "D:$devId:$p";
                if (!isset($lvisited[$n])) { $lvisited[$n] = true; $lqueue[] = $n; }
            }
        }
    }
    while ($lqueue) {
        $cur = array_shift($lqueue);
        foreach ($adj[$cur] ?? [] as [$nbr, $sj]) {
            if ($sj !== null) $activeSj[$sj] = true;          // a patch cable carrying light
            if (!isset($lvisited[$nbr])) { $lvisited[$nbr] = true; $lqueue[] = $nbr; }
        }
        if ($cur[0] === 'D') {                                // propagate through a passive device
            $cp = explode(':', $cur); $cdev = (int)$cp[1]; $cport = (int)$cp[2];
            if (in_array($devTypeAll[$cdev] ?? '', $passiveSet, true)) {
                $targets = [];
                if ($cport === 0) { foreach (array_keys($devPortNodes[$cdev]) as $k) if ($k !== 0) $targets[] = $k; }
                elseif (isset($devPortNodes[$cdev][0])) { $targets[] = 0; }
                foreach ($targets as $k) {
                    $n = "D:$cdev:$k";
                    if (!isset($lvisited[$n])) { $lvisited[$n] = true; $lqueue[] = $n; }
                }
            }
        }
    }

    // ── Pair terminals via BFS (stop at terminals; collapse cable/jumper hops) ──
    $isTerminal = function (string $node) use ($terminalSide): bool {
        if ($node[0] !== 'D') return false;
        $parts = explode(':', $node);
        return isset($terminalSide[(int)$parts[1]]);
    };

    $links   = [];
    $seenPair = [];
    foreach (array_keys($terminalSide) as $startId) {
        // Seed from every used port of this terminal: discover its start nodes from adjacency.
        $startNodes = [];
        foreach ($adj as $node => $_) {
            if ($node[0] === 'D' && (int)explode(':', $node)[1] === $startId) $startNodes[] = $node;
        }
        foreach ($startNodes as $start) {
            // BFS outward; do not expand through other terminal nodes.
            $queue   = [[$start, null]];   // [node, firstSj]
            $visited = [$start => true];
            while ($queue) {
                [$cur, $firstSj] = array_shift($queue);
                foreach ($adj[$cur] ?? [] as [$nbr, $sj]) {
                    if (isset($visited[$nbr])) continue;
                    $edgeFirstSj = $firstSj ?? $sj;
                    if ($isTerminal($nbr)) {
                        // Record the patch line start→nbr (dedup, ignore self).
                        $pa = explode(':', $start); $pb = explode(':', $nbr);
                        $aId = (int)$pa[1]; $aPort = (int)$pa[2];
                        $bId = (int)$pb[1]; $bPort = (int)$pb[2];
                        if ($aId === $bId && $aPort === $bPort) continue;
                        $key = $aId < $bId || ($aId === $bId && $aPort <= $bPort)
                             ? "$aId.$aPort-$bId.$bPort" : "$bId.$bPort-$aId.$aPort";
                        if (!isset($seenPair[$key])) {
                            $seenPair[$key] = true;
                            $links[] = [
                                'a'  => ['side' => $terminalSide[$aId], 'id' => $aId, 'port' => $aPort],
                                'b'  => ['side' => $terminalSide[$bId], 'id' => $bId, 'port' => $bPort],
                                'sj' => $edgeFirstSj,
                            ];
                        }
                        // Terminal absorbs — don't expand past it.
                        $visited[$nbr] = true;
                        continue;
                    }
                    $visited[$nbr] = true;
                    $queue[] = [$nbr, $edgeFirstSj];
                }
            }
        }
    }

    // ── Resolve endpoints to (block, row) — nest SFP children under their parent ──
    // Load metadata (incl. parent link) for every device-side id in the links + parents.
    $devLinkIds = [];
    foreach ($links as $lk) {
        foreach (['a', 'b'] as $s) {
            if ($lk[$s]['side'] === 'device') $devLinkIds[(int)$lk[$s]['id']] = true;
        }
    }
    $meta = pop_fetch_device_meta($pdo, array_keys($devLinkIds));
    $parentIds = [];
    foreach ($meta as $m) {
        if ($m['parent_device_id'] !== null) $parentIds[(int)$m['parent_device_id']] = true;
    }
    $meta += pop_fetch_device_meta($pdo, array_keys($parentIds));

    // Resolve a BFS endpoint {side,id,port} → {side, id(block), rowKey, rowLabel, ord, epId, epPort}.
    // epId/epPort are the real fosc_device + port the row represents (for click-to-patch).
    $resolveEndpoint = function (array $e) use ($meta): array {
        if ($e['side'] === 'closure') {
            $f = (int)$e['port'];
            return ['side' => 'closure', 'id' => (int)$e['id'], 'rowKey' => 'F' . $f, 'rowLabel' => 'F' . $f,
                    'ord' => $f, 'epId' => (int)$e['id'], 'epPort' => $f];
        }
        $id = (int)$e['id']; $port = (int)$e['port'];
        $m  = $meta[$id] ?? null;
        if ($m && $m['parent_device_id'] !== null) {     // SFP module → nest under its parent
            $slot = $m['parent_slot'] !== null ? (int)$m['parent_slot'] : null;
            $lbl  = ($slot !== null ? "s{$slot} · " : '') . $m['name'];
            return ['side' => 'device', 'id' => (int)$m['parent_device_id'],
                    'rowKey' => 'c' . $id, 'rowLabel' => $lbl, 'ord' => $slot ?? 9999,
                    'epId' => $id, 'epPort' => $port];      // endpoint = the SFP device itself
        }
        return ['side' => 'device', 'id' => $id, 'rowKey' => 'p' . $port,
                'rowLabel' => $port === 0 ? 'IN' : ('p' . $port), 'ord' => $port,
                'epId' => $id, 'epPort' => $port];
    };

    $closureRows = [];   // panel_id  => rowKey => ['label','ord','epId','epPort']
    $deviceRows  = [];   // block_id   => rowKey => ['label','ord','epId','epPort']
    $outLinks    = [];
    $seenOut     = [];
    $regRow = function (array $r) use (&$closureRows, &$deviceRows) {
        $cell = ['label' => $r['rowLabel'], 'ord' => $r['ord'], 'epId' => $r['epId'], 'epPort' => $r['epPort']];
        if ($r['side'] === 'closure') $closureRows[$r['id']][$r['rowKey']] = $cell;
        else                          $deviceRows[$r['id']][$r['rowKey']]  = $cell;
    };
    foreach ($links as $lk) {
        $ra = $resolveEndpoint($lk['a']);
        $rb = $resolveEndpoint($lk['b']);
        if ($ra['side'] === $rb['side'] && $ra['id'] === $rb['id'] && $ra['rowKey'] === $rb['rowKey']) continue;
        $regRow($ra); $regRow($rb);
        $k = $ra['side'] . $ra['id'] . $ra['rowKey'] . '|' . $rb['side'] . $rb['id'] . $rb['rowKey'];
        $kr = $rb['side'] . $rb['id'] . $rb['rowKey'] . '|' . $ra['side'] . $ra['id'] . $ra['rowKey'];
        if (isset($seenOut[$k]) || isset($seenOut[$kr])) continue;
        $seenOut[$k] = true;
        // "Active" = this patch carries light from a laser source, end-to-end (computed above).
        $active = $lk['sj'] !== null && isset($activeSj[(int)$lk['sj']]);
        $outLinks[] = [
            'a'      => ['side' => $ra['side'], 'id' => $ra['id'], 'rowKey' => $ra['rowKey']],
            'b'      => ['side' => $rb['side'], 'id' => $rb['id'], 'rowKey' => $rb['rowKey']],
            'sj'     => $lk['sj'],
            'active' => $active,
        ];
    }

    // ── Assemble blocks ─────────────────────────────────────────────────────────
    // Panels (terminated-fibre ends): show EVERY panel, and EVERY fibre 1..N — an
    // unpatched fibre is still a terminated end available to patch (click-to-draw).
    $closures = [];
    foreach ($panelById as $id => $p) {
        $attrs   = json_decode($p['attrs'] ?: '{}', true) ?: [];
        $cap     = isset($attrs['ports']) ? (int)$attrs['ports'] : 0;
        $rowsMap = $closureRows[$id] ?? [];              // patched fibres (with their resolved cells)
        for ($f = 1; $f <= $cap; $f++) {                 // fill in the rest of the terminated ends
            $key = 'F' . $f;
            if (!isset($rowsMap[$key])) {
                $rowsMap[$key] = ['label' => 'F' . $f, 'ord' => $f, 'epId' => $id, 'epPort' => $f];
            }
        }
        if (!$rowsMap) continue;                         // panel with no port count and no patches
        // Per-fibre notes: a manual override (attrs.port_notes) wins; otherwise an auto note
        // (far-end closure + distance from a light trace) is offered as the editable default.
        $portNotes = (isset($attrs['port_notes']) && is_array($attrs['port_notes'])) ? $attrs['port_notes'] : [];
        $autoNotes = pop_panel_fibre_autonotes($pdo, $id);
        $rows = pop_sort_rows($rowsMap);
        foreach ($rows as $i => $r) {
            $port = (int)($r['epPort'] ?? 0);
            if (isset($portNotes[(string)$port]) && $portNotes[(string)$port] !== '') {
                $rows[$i]['note'] = (string)$portNotes[(string)$port];
            }
            if (isset($autoNotes[$port])) $rows[$i]['noteAuto'] = $autoNotes[$port];
        }
        $closures[] = [
            'id'     => $id,
            'foscId' => (int)$p['fosc_id'],   // parent closure → name links to its detail page
            'label' => $p['splice_num'],
            'name'  => $p['name'],
            'sub'   => $p['name'] . ($cap ? " · {$cap}F" : ''),
            'note'  => (string)($p['notes'] ?? ''),
            'rows'  => $rows,
        ];
    }

    // Show EVERY POP-homed top-level device as a block — even one with no patches yet — so a
    // freshly-added device appears on the diagram and can be patched via click-to-draw (chassis
    // gets a "+ SFP" affordance; passive gear shows its defined ports below). Child SFP modules
    // are surfaced under their parent, not as their own blocks.
    $topDevs = $pdo->prepare("
        SELECT fosc_device_id AS id FROM app.fosc_device
         WHERE pop_id = :pid AND parent_device_id IS NULL
    ");
    $topDevs->execute([':pid' => $popId]);
    $needMeta = [];
    foreach (array_map('intval', array_column($topDevs->fetchAll() ?: [], 'id')) as $tid) {
        if (!isset($deviceRows[$tid])) $deviceRows[$tid] = [];
        if (!isset($meta[$tid]))       $needMeta[] = $tid;
    }
    if ($needMeta) $meta += pop_fetch_device_meta($pdo, $needMeta);

    // Show ALL installed SFP modules of each device block (even un-patched), so a device
    // reflects its populated SFP ports and a newly-added SFP appears immediately.
    $hostIds = [];  // device ids that hold SFP modules (have children)
    if ($deviceRows) {
        $blockCsv = implode(',', array_map('intval', array_keys($deviceRows)));
        $kids = $pdo->prepare("
            SELECT fosc_device_id AS id, name, parent_device_id, parent_slot
              FROM app.fosc_device
             WHERE pop_id = :pid AND parent_device_id IN ($blockCsv)
        ");
        $kids->execute([':pid' => $popId]);
        foreach ($kids->fetchAll() ?: [] as $kid) {
            $pId  = (int)$kid['parent_device_id'];
            $hostIds[$pId] = true;
            $slot = $kid['parent_slot'] !== null ? (int)$kid['parent_slot'] : null;
            $key  = 'c' . (int)$kid['id'];
            if (!isset($deviceRows[$pId][$key])) {       // don't override a patched row's label
                $deviceRows[$pId][$key] = [
                    'label'  => ($slot !== null ? "s{$slot} · " : '') . $kid['name'],
                    'ord'    => $slot ?? 9999,
                    'epId'   => (int)$kid['id'],          // the SFP device itself
                    'epPort' => 1,                        // SFP optical port (single-port module)
                ];
            }
        }
    }

    // Device types that hold SFP modules (chassis) vs passive optical devices with a
    // FIXED, defined port layout (a common in/out port + N channels — like a 1×4/1×16).
    $sfpHostTypes = ['router', 'olt_pon'];
    $passiveTypes = ['dwdm', 'splitter', 'oadm', 'mux', 'demux', 'coupler'];

    $devices = [];
    foreach ($deviceRows as $blockId => $rowsMap) {
        $m    = $meta[$blockId] ?? null;
        $type = $m['device_type'] ?? '';
        $outs = (int)($m['outs'] ?? 0);
        $canHostSfp = in_array($type, $sfpHostTypes, true) || isset($hostIds[$blockId]);

        // Passive optical device → show its defined ports: common (port 0) + 1..N channels.
        if (!$canHostSfp && in_array($type, $passiveTypes, true)) {
            if (!isset($rowsMap['p0'])) {
                $rowsMap['p0'] = ['label' => 'IN', 'ord' => -1, 'epId' => $blockId, 'epPort' => 0];
            }
            for ($p = 1; $p <= $outs; $p++) {
                if (!isset($rowsMap['p' . $p])) {
                    $rowsMap['p' . $p] = ['label' => 'p' . $p, 'ord' => $p, 'epId' => $blockId, 'epPort' => $p];
                }
            }
        }

        $devices[] = [
            'id'         => $blockId,
            'label'      => $m['name'] ?? ('Device ' . $blockId),
            'name'       => $m['name'] ?? ('Device ' . $blockId),
            'sub'        => pop_device_kind_label($type),
            'type'       => $type,
            'note'       => (string)($m['note'] ?? ''),
            'canHostSfp' => $canHostSfp,
            'rows'       => pop_sort_rows($rowsMap),
        ];
    }

    return ['closures' => $closures, 'devices' => $devices, 'links' => $outLinks];
}

/**
 * Auto-generated per-fibre notes for a patch panel: for each terminated fibre, where it goes
 * (far-end closure) and how far (distance), derived from a light trace. Returns a map
 * [port => "→ COL2103 · 349 m"]. Used as the DEFAULT note on the POP diagram; a manual note
 * stored in the panel's attrs.port_notes overrides it.
 */
function pop_panel_fibre_autonotes(PDO $pdo, int $panelId): array
{
    // The cable termination feeding this panel (cable_span ↔ panel connector join), plus the
    // fibre↔port mapping. The panel sits at $cable_end; we trace from the OPPOSITE end outward.
    $st = $pdo->prepare("
        SELECT
          CASE WHEN a_target='cable_span' THEN a_span_id ELSE b_span_id END AS span_id,
          CASE WHEN a_target='cable_span' THEN a_end::text ELSE b_end::text END AS cable_end,
          CASE WHEN a_target='cable_span' THEN lower(a_range) ELSE lower(b_range) END AS clo,
          CASE WHEN a_target='cable_span' THEN lower(b_range) ELSE lower(a_range) END AS plo,
          CASE WHEN a_target='cable_span' THEN upper(b_range) ELSE upper(a_range) END AS phi
        FROM app.splice_join
        WHERE join_kind='connector' AND retired_at IS NULL
          AND ((a_target='fosc_device' AND a_span_id=:id1 AND b_target='cable_span')
            OR (b_target='fosc_device' AND b_span_id=:id2 AND a_target='cable_span'))
        ORDER BY splice_join_id
        LIMIT 1
    ");
    $st->execute([':id1' => $panelId, ':id2' => $panelId]);
    $term = $st->fetch();
    if (!$term) return [];

    $span     = (int)$term['span_id'];
    $startEnd = $term['cable_end'] === 'from' ? 'to' : 'from';   // trace away from the panel
    $clo = (int)$term['clo']; $plo = (int)$term['plo']; $phi = (int)$term['phi'];
    if ($phi <= $plo) return [];

    // One aggregate trace over every panel port → far-end closure + summed span distance.
    $q = $pdo->prepare("
        WITH t AS (
            SELECT g.port AS port, tr.hop_num, tr.cable_span_id, tr.fosc_label
            FROM generate_series(:plo::int, :phimax::int) g(port)
            CROSS JOIN LATERAL app.trace_light_path(
                :span::bigint, (:clo::int + (g.port - :plo2::int)), :startend::app.span_end, false, 200) tr
        ),
        far AS (
            SELECT DISTINCT ON (port) port, fosc_label
            FROM t WHERE fosc_label IS NOT NULL
            ORDER BY port, hop_num DESC
        ),
        dist AS (
            SELECT d.port, SUM(DISTINCT cs.length_m) AS metres
            FROM (SELECT DISTINCT port, cable_span_id FROM t) d
            JOIN app.cable_span cs ON cs.cable_span_id = d.cable_span_id
            GROUP BY d.port
        )
        SELECT far.port AS port, far.fosc_label AS far_closure, dist.metres AS metres
        FROM far LEFT JOIN dist USING (port)
    ");
    $q->execute([
        ':plo' => $plo, ':phimax' => $phi - 1, ':span' => $span,
        ':clo' => $clo, ':plo2' => $plo, ':startend' => $startEnd,
    ]);

    $out = [];
    foreach ($q->fetchAll() ?: [] as $r) {
        if ($r['far_closure'] === null) continue;
        $m = $r['metres'];
        $dist = '';
        if ($m !== null) {
            $m = (float)$m;
            $dist = $m >= 1000 ? (round($m / 1000, 2) . ' km') : (round($m) . ' m');
        }
        $out[(int)$r['port']] = '→ ' . $r['far_closure'] . ($dist !== '' ? ' · ' . $dist : '');
    }
    return $out;
}

/** Fetch device metadata (name, type, parent link) for a set of ids, keyed by id. */
function pop_fetch_device_meta(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = $pdo->query("
        SELECT fosc_device_id AS id, name, device_type, attrs::text AS attrs, notes, parent_device_id, parent_slot
          FROM app.fosc_device
         WHERE fosc_device_id IN ($in)
    ")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $attrs = json_decode($r['attrs'] ?: '{}', true) ?: [];
        $out[(int)$r['id']] = [
            'name'             => $r['name'],
            'device_type'      => $r['device_type'],
            'note'             => (string)($r['notes'] ?? ''),
            'outs'             => isset($attrs['outs']) ? (int)$attrs['outs'] : 0,
            'parent_device_id' => $r['parent_device_id'] !== null ? (int)$r['parent_device_id'] : null,
            'parent_slot'      => $r['parent_slot'] !== null ? (int)$r['parent_slot'] : null,
        ];
    }
    return $out;
}

/**
 * True if a fosc_device port already carries a PATCH cable (one-wire-per-port rule).
 * A cable→panel *termination* (the fibre arriving on the back of the panel) is NOT a patch
 * and must be ignored — otherwise every terminated panel fibre would look "used".
 */
function pop_port_in_use(PDO $pdo, int $devId, int $port): bool
{
    $st = $pdo->prepare("
        SELECT 1
          FROM app.splice_join sj
          LEFT JOIN app.fosc_device ad ON sj.a_target = 'fosc_device' AND ad.fosc_device_id = sj.a_span_id
          LEFT JOIN app.fosc_device bd ON sj.b_target = 'fosc_device' AND bd.fosc_device_id = sj.b_span_id
         WHERE sj.join_kind = 'connector' AND sj.retired_at IS NULL
           AND (
             (sj.a_target = 'fosc_device' AND sj.a_span_id = :d1 AND :p1 >= lower(sj.a_range) AND :p1 < upper(sj.a_range)
                AND NOT (ad.device_type = 'patch_panel' AND sj.b_target = 'cable_span'))
             OR
             (sj.b_target = 'fosc_device' AND sj.b_span_id = :d2 AND :p2 >= lower(sj.b_range) AND :p2 < upper(sj.b_range)
                AND NOT (bd.device_type = 'patch_panel' AND sj.a_target = 'cable_span'))
           )
         LIMIT 1
    ");
    $st->execute([':d1' => $devId, ':p1' => $port, ':d2' => $devId, ':p2' => $port]);
    return (bool)$st->fetchColumn();
}

/** Sort a rowKey => ['label','ord'] map into an ordered [{key,label}] list. */
function pop_sort_rows(array $rowsMap): array
{
    uksort($rowsMap, function ($ka, $kb) use ($rowsMap) {
        $oa = $rowsMap[$ka]['ord']; $ob = $rowsMap[$kb]['ord'];
        return $oa <=> $ob ?: strcmp((string)$ka, (string)$kb);
    });
    $out = [];
    foreach ($rowsMap as $key => $v) {
        $out[] = ['key' => (string)$key, 'label' => $v['label'],
                  'epId' => $v['epId'] ?? null, 'epPort' => $v['epPort'] ?? null];
    }
    return $out;
}

/** Friendly device-kind label for a diagram block sub-heading. */
function pop_device_kind_label(string $type): string
{
    return match ($type) {
        'router'      => 'router',
        'olt_pon'     => 'OLT',
        'dwdm'        => 'DWDM',
        'sfp'         => 'SFP',
        'oadm'        => 'OADM',
        'splitter'    => 'splitter',
        'patch_panel' => 'panel',
        ''            => '',
        default       => $type,
    };
}

/**
 * Selectable patch endpoints for a POP (used by the "+ Patch cable" form on pop_detail).
 * Returns ['endpoints'=>[{id,kind,label,fosc_id,maxport}], 'members'=>[fosc_id,…]].
 *  - panel  : a member-closure patch panel (port == fibre); carries its closure fosc_id.
 *  - device : a POP-homed active device that is NOT a child module (its own ports).
 *  - sfp    : a POP-homed SFP module (plugged into a parent); patched on its own optical port.
 */
function fetch_pop_patch_endpoints(PDO $pdo, int $popId): array
{
    $st = $pdo->prepare("SELECT fosc_id FROM app.fosc WHERE pop_id = :pid ORDER BY fosc_id");
    $st->execute([':pid' => $popId]);
    $members = array_map('intval', array_column($st->fetchAll() ?: [], 'fosc_id'));

    $endpoints = [];

    if ($members) {
        $memberCsv = implode(',', $members);
        $panels = $pdo->query("
            SELECT fd.fosc_device_id AS id, fd.fosc_id, fd.name, fd.attrs::text AS attrs, f.splice_num
              FROM app.fosc_device fd
              JOIN app.fosc f ON f.fosc_id = fd.fosc_id
             WHERE fd.device_type = 'patch_panel' AND fd.fosc_id IN ($memberCsv)
          ORDER BY f.splice_num
        ")->fetchAll() ?: [];
        foreach ($panels as $p) {
            $attrs = json_decode($p['attrs'] ?: '{}', true) ?: [];
            $ports = isset($attrs['ports']) ? (int)$attrs['ports'] : 0;
            $endpoints[] = [
                'id'      => (int)$p['id'],
                'kind'    => 'panel',
                'label'   => $p['splice_num'] . ' — ' . $p['name'] . ($ports ? " ({$ports}F)" : ''),
                'fosc_id' => (int)$p['fosc_id'],
                'maxport' => $ports,
            ];
        }
    }

    // POP-homed active devices + SFP modules.
    $dstmt = $pdo->prepare("
        SELECT d.fosc_device_id AS id, d.name, d.device_type, d.attrs::text AS attrs,
               d.parent_device_id, d.parent_slot, pd.name AS parent_name
          FROM app.fosc_device d
          LEFT JOIN app.fosc_device pd ON pd.fosc_device_id = d.parent_device_id
         WHERE d.pop_id = :pid
      ORDER BY d.name
    ");
    $dstmt->execute([':pid' => $popId]);
    foreach ($dstmt->fetchAll() ?: [] as $d) {
        $attrs = json_decode($d['attrs'] ?: '{}', true) ?: [];
        $outs  = isset($attrs['outs']) ? (int)$attrs['outs'] : 0;
        if ($d['parent_device_id'] !== null) {           // SFP module
            $slot = $d['parent_slot'] !== null ? (int)$d['parent_slot'] : null;
            $endpoints[] = [
                'id'      => (int)$d['id'],
                'kind'    => 'sfp',
                'label'   => ($d['parent_name'] ?? 'parent') . ($slot !== null ? " s{$slot}" : '') . ' · ' . $d['name'],
                'fosc_id' => null,
                'maxport' => max(1, $outs),
            ];
        } else {
            $endpoints[] = [
                'id'      => (int)$d['id'],
                'kind'    => 'device',
                'label'   => $d['name'] . ' (' . pop_device_kind_label($d['device_type']) . ')',
                'fosc_id' => null,
                'maxport' => $outs,
            ];
        }
    }

    return ['endpoints' => $endpoints, 'members' => $members];
}
