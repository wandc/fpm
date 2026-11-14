<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.

/**
 * Fiber colour palette helpers
 */

function fiber_palette_named(): array {
    return [
        ['name'=>'Blue',   'abbr'=>'Blu', 'hex'=>'#007bff'],
        ['name'=>'Orange', 'abbr'=>'Org', 'hex'=>'#fd7e14'],
        ['name'=>'Green',  'abbr'=>'Grn', 'hex'=>'#28a745'],
        ['name'=>'Brown',  'abbr'=>'Brn', 'hex'=>'#795548'],
        ['name'=>'Slate',  'abbr'=>'Slt', 'hex'=>'#6c757d'],
        ['name'=>'White',  'abbr'=>'Wht', 'hex'=>'#f8f9fa'],
        ['name'=>'Red',    'abbr'=>'Red', 'hex'=>'#dc3545'],
        ['name'=>'Black',  'abbr'=>'Blk', 'hex'=>'#212529'],
        ['name'=>'Yellow', 'abbr'=>'Yel', 'hex'=>'#ffc107'],
        ['name'=>'Violet', 'abbr'=>'Vio', 'hex'=>'#6f42c1'],
        ['name'=>'Rose',   'abbr'=>'Ros', 'hex'=>'#e83e8c'],
        ['name'=>'Aqua',   'abbr'=>'Aqa', 'hex'=>'#20c997'],
    ];
}

function tube_palette(): array {
    return array_map(fn($r) => $r['hex'], fiber_palette_named());
}

function contrast_color(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    $y = (($r*299)+($g*587)+($b*114))/1000;

    return $y >= 140 ? '#111' : '#fff';
}

/**
 * Expand a fibre/port range into per-port link pairs.
 */
function expand_range(string $fromKey, int $aLo, int $aHi, string $toKey, int $bLo, int $bHi): array {
    $n = min($aHi - $aLo + 1, $bHi - $bLo + 1);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ['from' => [$fromKey, $aLo + $i], 'to' => [$toKey, $bLo + $i]];
    }
    return $out;
}

/**
 * Internal: render a cable chart (one column) as HTML string.
 */
function splice_render_fiber_chart_html(
    string $chartId,
    string $title,
    int $tubeCount = 2,
    bool $showNumbering = true,
    bool $invertColumns = false,
    ?string $stubSide = null,
    ?string $stubLabel = null,
    ?int $fiberCount = null
): string {
    $fibersPerTube = 12;

    if ($fiberCount !== null) {
        $fiberCount = max(1, $fiberCount);
        $total = $fiberCount;
        $tubeCount = max(1, (int)ceil($fiberCount / $fibersPerTube));
    } else {
        $tubeCount = max(1, min(12, $tubeCount));
        $total = $tubeCount * $fibersPerTube;
    }

    $fibers = fiber_palette_named();
    $tubeColors = tube_palette();
    $cols = $invertColumns ? ['nu','bf','fiber'] : ['fiber','bf','nu'];

    ob_start();
    ?>
    <div class="fiber-chart" id="chart-<?= htmlspecialchars($chartId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="chart-title"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <div class="chart-inner">
        <?php if ($stubSide): ?>
          <div class="cable-stub <?= $stubSide === 'right' ? 'stub-right' : 'stub-left' ?>">
            <?php if ($stubLabel): ?>
              <span class="stub-label"><?= htmlspecialchars($stubLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <svg class="connector-layer" preserveAspectRatio="none"></svg>
        <table class="fiber-table">
          <thead>
            <tr>
              <?php foreach ($cols as $c): ?>
                <?php if ($c === 'fiber'): ?><th class="col-fiber">Fiber</th><?php endif; ?>
                <?php if ($c === 'bf'):    ?><th class="col-bf"></th><?php endif; ?>
                <?php if ($c === 'nu'):    ?><th class="col-nu">Nu</th><?php endif; ?>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php for ($idx = 1; $idx <= $total; $idx++): ?>
            <?php
              $tubeIndex = (int)floor(($idx - 1) / $fibersPerTube);
              $posInTube = (($idx - 1) % $fibersPerTube);
              $fiberMeta = $fibers[$posInTube];
              $tubeColor = $tubeColors[$tubeIndex % count($tubeColors)];
              $nu        = $idx;
              $rowsLeftInTube = min($fibersPerTube - $posInTube, $total - $idx + 1);
            ?>
            <tr class="fiber-row" data-fiber="<?= $idx ?>">
              <?php foreach ($cols as $c): ?>
                <?php if ($c === 'fiber'): ?>
                  <td class="cell-fiber">
                    <span
                      class="fiber-chip"
                      style="background: <?= $fiberMeta['hex'] ?>; color: <?= contrast_color($fiberMeta['hex']) ?>"
                      title="<?= htmlspecialchars($fiberMeta['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    >
                      <?= htmlspecialchars($fiberMeta['abbr'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  </td>
                <?php elseif ($c === 'bf'): ?>
                  <?php if ($posInTube === 0): ?>
                    <td
                      class="cell-bf"
                      rowspan="<?= $rowsLeftInTube ?>"
                      style="background: <?= $tubeColor ?>;"
                      title="Tube <?= ($tubeIndex + 1) ?>"
                    ></td>
                  <?php endif; ?>
                <?php else: ?>
                  <td class="cell-nu"><?= $showNumbering ? $nu : '' ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Internal: render a splitter chart (1×N) as HTML string.
 */
function splice_render_splitter_chart_html(
    string $chartId,
    string $title,
    int $ports = 32,
    bool $invert = false
): string {
    $colors = fiber_palette_named();
    $rows   = max(1, min(128, $ports));
    $cols   = $invert ? ['nu','fiber'] : ['fiber','nu'];

    ob_start();
    ?>
    <div class="fiber-chart" id="chart-<?= htmlspecialchars($chartId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="chart-title">
        <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        (1×<?= (int)$ports ?>)
      </div>
      <div class="chart-inner">
        <svg class="connector-layer" preserveAspectRatio="none"></svg>
        <table class="fiber-table">
          <thead>
            <tr>
              <?php foreach ($cols as $c): ?>
                <?php if ($c === 'fiber'): ?><th class="col-fiber">Fiber</th><?php endif; ?>
                <?php if ($c === 'nu'):    ?><th class="col-nu">Nu</th><?php endif; ?>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <!-- input row -->
            <tr class="fiber-row" data-fiber="0">
              <?php if (!$invert): ?>
                <td class="cell-fiber">
                  <span class="fiber-chip" style="background:#007bff;color:#fff" title="Input">In</span>
                </td>
                <td class="cell-nu">0</td>
              <?php else: ?>
                <td class="cell-nu">0</td>
                <td class="cell-fiber">
                  <span class="fiber-chip" style="background:#007bff;color:#fff" title="Input">In</span>
                </td>
              <?php endif; ?>
            </tr>

            <!-- outputs -->
            <?php for ($i = 1; $i <= $rows; $i++):
                $set   = intdiv($i - 1, 12);
                $color = $colors[($i - 1) % 12];
                $suffix = $set === 1 ? '′' : ($set === 2 ? '″' : ($set >= 3 ? '+' : ''));
                $label = $color['abbr'] . $suffix;
            ?>
              <tr class="fiber-row" data-fiber="<?= $i ?>">
                <?php if (!$invert): ?>
                  <td class="cell-fiber"
                      style="background:<?= $color['hex'] ?>;color:<?= contrast_color($color['hex']) ?>;">
                    <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </td>
                  <td class="cell-nu"><?= $i ?></td>
                <?php else: ?>
                  <td class="cell-nu"><?= $i ?></td>
                  <td class="cell-fiber"
                      style="background:<?= $color['hex'] ?>;color:<?= contrast_color($color['hex']) ?>;">
                    <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Internal: render a single chart descriptor to HTML string.
 *
 * $ch keys:
 *   'key' (string)
 *   'type' ('cable'|'splitter')
 *   'title' (string)
 *   'fiber_count' (int) – cable
 *   'invert' (bool)
 *   'stubSide' (left|right|null)
 *   'stubLabel' (string|null)
 *   'ports' / 'outs' (int) – splitter
 */
function splice_render_chart_item_html(array $ch): string {
    $type = $ch['type'] ?? 'cable';
    if ($type === 'splitter') {
        return splice_render_splitter_chart_html(
            $ch['key'],
            $ch['title'] ?? $ch['key'],
            (int)($ch['ports'] ?? ($ch['outs'] ?? 4)),
            (bool)($ch['invert'] ?? false)
        );
    }

    $fc    = (int)($ch['fiber_count'] ?? 12);
    $tubes = max(1, (int)ceil($fc / 12));

    return splice_render_fiber_chart_html(
        $ch['key'],
        $ch['title'] ?? $ch['key'],
        $tubes,
        (bool)($ch['show_numbers'] ?? true),
        (bool)($ch['invert'] ?? false),
        $ch['stubSide'] ?? null,
        $ch['stubLabel'] ?? null,
        $fc
    );
}

/**
 * Main helper:
 *   splice_build_chart_block($charts, $links, $ns = null)
 *
 * Returns:
 *   [
 *     'ns'   => 'sc_xxxxx',
 *     'html' => '<div id="sc_xxxxx">...</div>',
 *     'js'   => "window['CHART_ORDER_sc_xxxxx']=...; ..."
 *   ]
 */
/**
 * $groups: optional map of chart key → integer group column index.
 *   When provided, charts sharing the same group index are stacked
 *   vertically in one column; a single gutter SVG spans between groups.
 *   The JS variable CHART_GROUPS_{ns} is emitted for autoRenderLinksNS.
 *   When null, falls back to the original flat horizontal layout.
 */
function splice_build_chart_block(array $charts, array $links, ?string $ns = null, ?array $groups = null): array {
    if ($ns === null) {
        $ns = 'sc_' . bin2hex(random_bytes(3));
    }

    $order = array_column($charts, 'key');

    ob_start();

    if ($groups !== null) {
        // ── Grouped layout: vertical stacks per column ──────────────────
        // Bucket charts by group index preserving sort order within each group.
        $byGroup = [];
        foreach ($charts as $ch) {
            $g = $groups[$ch['key']] ?? 0;
            $byGroup[$g][] = $ch;
        }
        ksort($byGroup);
        $gKeys = array_values(array_keys($byGroup));
        $ns_h  = htmlspecialchars($ns, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
        <div id="<?= $ns_h ?>" class="d-flex align-items-stretch gap-0 flex-wrap">
          <?php foreach ($gKeys as $gi => $gk): ?>
            <div class="chart-group d-flex flex-column gap-2 align-items-start">
              <?php foreach ($byGroup[$gk] as $ch): ?>
                <?= splice_render_chart_item_html($ch) ?>
              <?php endforeach; ?>
            </div>
            <?php if ($gi < count($gKeys) - 1):
                $nextGk = $gKeys[$gi + 1];
                $svgId  = 'svg-g' . $gk . '-g' . $nextGk;
            ?>
              <div class="gutter">
                <svg class="between-svg"
                     id="<?= htmlspecialchars($svgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></svg>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php
    } else {
        // ── Original flat layout ─────────────────────────────────────────
        ?>
        <div id="<?= htmlspecialchars($ns, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             class="d-flex align-items-stretch gap-3 flex-wrap">
          <?php for ($i = 0; $i < count($charts); $i++): ?>
            <?php
              $ch = $charts[$i];
              echo splice_render_chart_item_html($ch);
              if ($i < count($charts) - 1):
                  $next  = $charts[$i + 1];
                  $svgId = 'svg-' . $ch['key'] . '-' . $next['key'];
            ?>
              <div class="gutter">
                <svg class="between-svg"
                     id="<?= htmlspecialchars($svgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></svg>
              </div>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
        <?php
    }

    $html = (string)ob_get_clean();

    $orderJson  = json_encode($order);
    $linksJson  = json_encode($links, JSON_NUMERIC_CHECK);
    $groupsJson = $groups !== null ? json_encode($groups) : 'null';

    $js = <<<JS
window["CHART_ORDER_{$ns}"]  = {$orderJson};
window["LINKS_RAW_{$ns}"]    = {$linksJson};
window["CHART_GROUPS_{$ns}"] = {$groupsJson};

// Defer render until all scripts (including splice_chart.helper.js) are loaded.
window.addEventListener("load", function() {
  if (typeof autoRenderLinksNS === "function") {
    var c = document.getElementById("{$ns}");
    // Only do initial render if the container is currently visible
    // (offsetWidth=0 means it is inside a hidden Bootstrap tab pane).
    if (c && c.offsetWidth > 0) autoRenderLinksNS("{$ns}");
    window.addEventListener("resize", function(){ autoRenderLinksNS("{$ns}"); });
  } else if (typeof autoRenderLinks === "function") {
    window.CHART_ORDER = {$orderJson};
    window.LINKS_RAW   = {$linksJson};
    autoRenderLinks();
    window.addEventListener("resize", function(){ autoRenderLinks(); });
  }
});
JS;

    return [
        'ns'   => $ns,
        'html' => $html,
        'js'   => $js,
    ];
}

/**
 * Build a complete splice chart block for a FOSC (actual or plan layer).
 *
 * Queries the database for nodes + per-fibre edges at the given FOSC,
 * then delegates to splice_build_chart_block().
 *
 * Returns: ['html' => string, 'js' => string]
 */
function render_fosc_splice_chart(PDO $pdo, int $foscId, bool $isPlan = false, ?string $ns = null, ?string $heading = null): array
{
    if ($ns === null) {
        $ns = 'sc_' . bin2hex(random_bytes(3));
    }

    // 1) Participating nodes
    $sqlNodes = "
      WITH nodes AS (
        SELECT a_target AS target, a_span_id AS id
          FROM app.splice_join WHERE fosc_id = :fid AND is_plan = :isPlan AND retired_at IS NULL
        UNION
        SELECT b_target, b_span_id
          FROM app.splice_join WHERE fosc_id = :fid AND is_plan = :isPlan AND retired_at IS NULL
      )
      SELECT
        n.target, n.id,
        CASE
          WHEN n.target = 'cable_span' THEN 'Span '||n.id::text
          ELSE COALESCE(d.name, 'Device '||n.id::text)
        END AS title,
        CASE
          WHEN n.target = 'cable_span' THEN COALESCE(
            (SELECT c.fibre_count FROM app.cable_span cs
               JOIN app.cable c ON c.cable_id = cs.cable_id
               WHERE cs.cable_span_id = n.id LIMIT 1), 1)
          ELSE COALESCE((d.attrs->>'outs')::int + 1, 1)
        END AS fiber_count,
        CASE WHEN n.target = 'fosc_device' THEN d.device_type ELSE 'cable' END AS type,
        CASE WHEN n.target = 'fosc_device' AND d.device_type = 'splitter'
             THEN (d.attrs->>'outs')::int ELSE NULL END AS outs
      FROM nodes n
      LEFT JOIN app.fosc_device d ON n.target = 'fosc_device' AND d.fosc_device_id = n.id
      ORDER BY (n.target = 'fosc_device')::int, n.id
    ";
    $st = $pdo->prepare($sqlNodes);
    $st->bindValue(':fid',    $foscId,       PDO::PARAM_INT);
    $st->bindValue(':isPlan', (bool)$isPlan, PDO::PARAM_BOOL);
    $st->execute();
    $nodes = $st->fetchAll() ?: [];

    $htmlPieces = [];
    if ($heading !== null) {
        $htmlPieces[] = '<h4 class="mt-4 mb-2">' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>';
    }

    if (!$nodes) {
        $htmlPieces[] = '<p class="text-muted mb-3">' .
            htmlspecialchars($isPlan ? 'No planned splices.' : 'No actual splices.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</p>';
        return ['html' => implode("\n", $htmlPieces), 'js' => ''];
    }

    // 2) Determine span roles (feed = connects to device IN; drop = connects from device OUT)
    //    using a lightweight extra query so we can order charts: feed spans → devices → drop spans.
    $stRoles = $pdo->prepare("
        SELECT DISTINCT
            CASE WHEN a_target='cable_span' THEN a_span_id ELSE b_span_id END AS span_id,
            CASE
                WHEN (a_target='cable_span' AND b_target='fosc_device' AND lower(b_range)=0)
                  OR (b_target='cable_span' AND a_target='fosc_device' AND lower(a_range)=0)
                THEN 'feed'
                ELSE 'drop'
            END AS role
        FROM app.splice_join
        WHERE fosc_id = :fid AND is_plan = :isPlan
          AND retired_at IS NULL
          AND (a_target='fosc_device' OR b_target='fosc_device')
    ");
    $stRoles->bindValue(':fid',    $foscId,       PDO::PARAM_INT);
    $stRoles->bindValue(':isPlan', (bool)$isPlan, PDO::PARAM_BOOL);
    $stRoles->execute();
    $spanRoles = [];
    foreach ($stRoles->fetchAll() as $r) {
        $spanRoles[(int)$r['span_id']] = $r['role'];
    }

    // Assign group column: 0=passthrough, 1=feed, 2=device, 3=drop
    $roleToGroup = ['feed' => 1, 'drop' => 3];
    $nodeGroup = function($n) use ($spanRoles, $roleToGroup) {
        if ($n['type'] !== 'cable') return 2; // device
        $id = (int)$n['id'];
        return $roleToGroup[$spanRoles[$id] ?? ''] ?? 0;
    };

    // Sort nodes: passthrough (0) → feed (1) → device (2) → drop (3)
    usort($nodes, function($a, $b) use ($nodeGroup) {
        $sa = $nodeGroup($a); $sb = $nodeGroup($b);
        return $sa !== $sb ? $sa - $sb : (int)$a['id'] - (int)$b['id'];
    });

    // 3) Chart descriptors + group map
    $keyMap      = [];
    $charts      = [];
    $chartGroups = [];  // key → group index, passed to splice_build_chart_block
    foreach ($nodes as $n) {
        $isCable = ($n['type'] === 'cable');
        $key     = $isCable ? ('S' . $n['id']) : ('D' . $n['id']);
        $keyMap[$n['target'] . '#' . $n['id']] = $key;
        $grp = $nodeGroup($n);
        $chartGroups[$key] = $grp;

        if ($isCable) {
            $fc = max(1, (int)$n['fiber_count']);
            // invert: drop spans face left (invert=true); all others face right (invert=false)
            $invert   = ($grp === 3);
            $stubSide = $invert ? 'right' : 'left';
            $charts[] = [
                'key'         => $key,
                'type'        => 'cable',
                'title'       => $n['title'] . ' — ' . $fc . 'F',
                'fiber_count' => $fc,
                'invert'      => $invert,
                'stubSide'    => $stubSide,
                'stubLabel'   => $fc . 'F',
            ];
        } else {
            $outs = (int)($n['outs'] ?? 0);
            $charts[] = [
                'key'   => $key,
                'type'  => 'splitter',
                'title' => $n['title'] . ($outs ? (' — 1×' . $outs) : ''),
                'outs'  => $outs,
            ];
        }
    }

    // 4) Per-fibre edges
    $sqlEdges = "
      SELECT
        j.a_target AS from_target, j.a_span_id AS from_id, gs_a.port AS from_port,
        j.b_target AS to_target,   j.b_span_id AS to_id,   gs_b.port AS to_port,
        j.join_kind
      FROM app.splice_join j
      -- generate_series includes both endpoints, and the stored range is half-open,
      -- so the top of the series is upper()-1. Using the range's own upper() here
      -- drew a link on one fibre past the splice.
      JOIN LATERAL generate_series(lower(j.a_range), upper(j.a_range) - 1) gs_a(port) ON TRUE
      JOIN LATERAL generate_series(lower(j.b_range), upper(j.b_range) - 1) gs_b(port)
        ON (gs_b.port - lower(j.b_range)) = (gs_a.port - lower(j.a_range))
      WHERE j.fosc_id = :fid AND j.is_plan = :isPlan
        AND j.retired_at IS NULL
      ORDER BY j.a_target, j.a_span_id, gs_a.port
    ";
    $st = $pdo->prepare($sqlEdges);
    $st->bindValue(':fid',    $foscId,       PDO::PARAM_INT);
    $st->bindValue(':isPlan', (bool)$isPlan, PDO::PARAM_BOOL);
    $st->execute();
    $edges = $st->fetchAll() ?: [];

    $links = [];
    foreach ($edges as $e) {
        $fromKey = $keyMap[$e['from_target'] . '#' . $e['from_id']] ?? null;
        $toKey   = $keyMap[$e['to_target']   . '#' . $e['to_id']]   ?? null;
        if ($fromKey && $toKey) {
            $links[] = [
                'from'      => [$fromKey, (int)$e['from_port']],
                'to'        => [$toKey,   (int)$e['to_port']],
                'join_kind' => $e['join_kind'] ?? 'single',
            ];
        }
    }

    // Only use grouped layout when there are multiple distinct groups (i.e. devices present)
    $hasGroups = count(array_unique(array_values($chartGroups))) > 1;
    $block = splice_build_chart_block($charts, $links, $ns, $hasGroups ? $chartGroups : null);
    $htmlPieces[] = $block['html'];

    return ['html' => implode("\n", $htmlPieces), 'js' => $block['js']];
}