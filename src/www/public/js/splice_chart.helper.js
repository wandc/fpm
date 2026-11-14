/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
// --- Anchor helpers that match your HTML -------------------------------------

function _dropRowCenter(chartKey /* e.g. 'DropA' */, fiberIndex) {
  const chart = document.getElementById('chart-' + chartKey);
  if (!chart) return null;
  const inner = chart.querySelector('.chart-inner');
  const row   = chart.querySelector(`tr.fiber-row[data-fiber="${fiberIndex}"]`);
  if (!inner || !row) return null;

  // Use the bf cell (narrow) to get a stable vertical center
  const cell = row.querySelector('.cell-fiber')  || row.querySelector('.cell-nu')  || row;
  const rb = cell.getBoundingClientRect();
  const ib = inner.getBoundingClientRect();
  return { y: rb.top + rb.height/2 - ib.top, innerBox: ib };
}

function _splitInCenter(splitKey /* e.g. 'S13' */) {
  const root  = document.getElementById('split-' + splitKey);
  if (!root) return null;
  const inner = root.querySelector('.chart-inner');
  const row   = root.querySelector(`tr.split-row[data-kind="in"][data-idx="1"]`);
  if (!inner || !row) return null;

  // Use the first cell for y
  const cell = row.querySelector('.cell-fiber') || row;
  const rb = cell.getBoundingClientRect();
  const ib = inner.getBoundingClientRect();
  return { y: rb.top + rb.height/2 - ib.top, innerBox: ib };
}

function _splitOutCenter(splitKey, outIdx /* 1..4 */) {
  const root  = document.getElementById('split-' + splitKey);
  if (!root) return null;
  const inner = root.querySelector('.chart-inner');
  const row   = root.querySelector(`tr.split-row[data-kind="out"][data-idx="${outIdx}"]`);
  if (!inner || !row) return null;

  const cell = row.querySelector('.cell-fiber') || row;
  const rb = cell.getBoundingClientRect();
  const ib = inner.getBoundingClientRect();
  return { y: rb.top + rb.height/2 - ib.top, innerBox: ib };
}

// --- Connection functions (use your existing stroke/outline style) -----------

function connect_A_to_S(aFiber /* e.g. 13 */, splitKey /* 'S13' */, svgId) {
  const svg = document.getElementById(svgId);
  if (!svg) return;

  // Scope all queries to the current link row (or wrap3) so we don't hit a different demo's #chart-A
  const scope = svg.closest('.link-row, .wrap3, .d-flex.align-items-start') || document;

  const aRoot = scope.querySelector('#chart-A');
  const sRoot = scope.querySelector('#split-' + splitKey);
  if (!aRoot || !sRoot) return;

  const aInner = aRoot.querySelector('.chart-inner');
  const aRow   = aRoot.querySelector(`tr.fiber-row[data-fiber="${aFiber}"] .cell-fiber`)
              || aRoot.querySelector(`tr.fiber-row[data-fiber="${aFiber}"] .cell-nu`)
              || aRoot.querySelector(`tr.fiber-row[data-fiber="${aFiber}"]`);
  const sInRow = sRoot.querySelector(`tr.split-row[data-kind="in"][data-idx="1"]`);
  const sInner = sRoot.querySelector('.chart-inner');
  if (!aInner || !aRow || !sInRow || !sInner) return;

  const svgBox = svg.getBoundingClientRect();
  const ar = aRow.getBoundingClientRect();
  const aBox = aInner.getBoundingClientRect();
  const sIn  = sInRow.getBoundingClientRect();
  const sBox = sInner.getBoundingClientRect();

  const x1 = aBox.right - svgBox.left;                 // right edge of A (relative to gutter)
  const y1 = ar.top + ar.height/2 - svgBox.top;

  const x2 = sBox.left - svgBox.left;                  // left edge of splitter (relative to gutter)
  const y2 = sIn.top + sIn.height/2 - svgBox.top;

  _drawCurved(svg, x1, y1, x2, y2, aFiber);
}

function connect_S_to_D(splitKey, outIdx /*1..4*/, dropKey /* 'DropA' | 'DropB' | 'DropC' */, dropFiber /*1..12*/, svgId) {
  const svg = document.getElementById(svgId);
  if (!svg) return;

  // Scope to THIS link row so we don’t hit another demo’s DOM
  const scope = svg.closest('.link-row, .wrap3, .d-flex.align-items-start') || document;

  const sRoot = scope.querySelector('#split-' + splitKey);
  const dRoot = scope.querySelector('#chart-' + dropKey);
  if (!sRoot || !dRoot) return;

  const sInner = sRoot.querySelector('.chart-inner');
  const dInner = dRoot.querySelector('.chart-inner');
  const sRow   = sRoot.querySelector(`tr.split-row[data-kind="out"][data-idx="${outIdx}"]`);
  const dRow   = dRoot.querySelector(`tr.fiber-row[data-fiber="${dropFiber}"] .cell-fiber`)
             || dRoot.querySelector(`tr.fiber-row[data-fiber="${dropFiber}"] .cell-nu`)
             || dRoot.querySelector(`tr.fiber-row[data-fiber="${dropFiber}"]`);
  if (!sInner || !dInner || !sRow || !dRow) return;

  // Measure everything relative to the gutter SVG
  const svgBox = svg.getBoundingClientRect();

  const sBox = sInner.getBoundingClientRect();
  const sr   = sRow.getBoundingClientRect();
  const x1   = sBox.right - svgBox.left;                 // right edge of splitter
  const y1   = sr.top + sr.height / 2 - svgBox.top;

  const dBox = dInner.getBoundingClientRect();
  const dr   = dRow.getBoundingClientRect();
  const x2   = dBox.left - svgBox.left;                  // left edge of drop
  const y2   = dr.top + dr.height / 2 - svgBox.top;

  // Color by splitter out index (Telcordia-ish)
  // TIA-598-C / ITU-T fibre colour sequence — must match itu_colours.php
  const palette = ['#1565C0','#E65100','#2E7D32','#4E342E','#546E7A','#FFFFFF',
                   '#C62828','#212121','#F9A825','#6A1B9A','#E91E8C','#00838F'];
  const color = palette[(outIdx - 1) % 12];

  _drawCurved(svg, x1, y1, x2, y2, outIdx, color);
}

function _drawCurved(svg, x1,y1, x2,y2, colorIndex, forceColor) {
  const dx = (x2 - x1) * 0.45;
  const c1x = x1 + dx, c1y = y1;
  const c2x = x2 - dx, c2y = y2;

  // TIA-598-C / ITU-T fibre colour sequence — must match itu_colours.php
  const color = forceColor || ['#1565C0','#E65100','#2E7D32','#4E342E','#546E7A','#FFFFFF',
                               '#C62828','#212121','#F9A825','#6A1B9A','#E91E8C','#00838F'][(colorIndex-1)%12];

  // outline for contrast
  const bg = document.createElementNS('http://www.w3.org/2000/svg','path');
  bg.setAttribute('d', `M ${x1},${y1} C ${c1x},${c1y} ${c2x},${c2y} ${x2},${y2}`);
  bg.setAttribute('fill','none');
  bg.setAttribute('stroke','#000');
  bg.setAttribute('stroke-width','3');
  bg.setAttribute('stroke-linecap','round');
  bg.setAttribute('opacity','0.3');
  svg.appendChild(bg);

  const fg = document.createElementNS('http://www.w3.org/2000/svg','path');
  fg.setAttribute('d', `M ${x1},${y1} C ${c1x},${c1y} ${c2x},${c2y} ${x2},${y2}`);
  fg.setAttribute('fill','none');
  fg.setAttribute('stroke', color);
  fg.setAttribute('stroke-width','1.6');
  fg.setAttribute('stroke-linecap','round');
  fg.setAttribute('opacity','0.95');
  svg.appendChild(fg);
}

// --- Your demo (update targets to 'DropA'/'DropB') ---------------------------
function demo() {
  const svgAS = 'svg-A-S';
  const svgSD = 'svg-S-D';

  // A → Splitters
  connect_A_to_S(13, 'S13', svgAS);
  connect_A_to_S(14, 'S14', svgAS);
  connect_A_to_S(15, 'S15', svgAS);

  // Splitter → Drops
  // S13: Out 1..4 → DropA fibers 1..4
  [1,2,3,4].forEach((k,i)=>connect_S_to_D('S13', k, 'DropA', i+1, svgSD));

  // S14: Out1→DA5, Out2→DA6, Out3→DB5, Out4→DB6
  connect_S_to_D('S14', 1, 'DropA', 5, svgSD);
  connect_S_to_D('S14', 2, 'DropA', 6, svgSD);
  connect_S_to_D('S14', 3, 'DropB', 5, svgSD);
  connect_S_to_D('S14', 4, 'DropB', 6, svgSD);

  // S15: Out 1..4 → DropB fibers 1..4
  [1,2,3,4].forEach((k,i)=>connect_S_to_D('S15', k, 'DropB', i+1, svgSD));
}

window.addEventListener('load', function() {
  // demo() only applies to the static test_page demo elements; skip on other pages
  const svgAS = document.getElementById('svg-A-S');
  const svgSD = document.getElementById('svg-S-D');
  if (svgAS && svgSD) {
    demo();
  }
});
window.addEventListener('resize', function() {
  // Only clear and redraw if the demo elements exist on this page
  const svgAS = document.getElementById('svg-A-S');
  const svgSD = document.getElementById('svg-S-D');
  if (svgAS) svgAS.innerHTML = '';
  if (svgSD) svgSD.innerHTML = '';
  if (svgAS && svgSD) {
    demo();
  }
});

function rowCenterY(chartKey, fiberIndex, root) {
  const chart = root.querySelector('#chart-' + chartKey);
  if (!chart) return null;
  const inner = chart.querySelector('.chart-inner');
  const row   = chart.querySelector(`tr.fiber-row[data-fiber="${fiberIndex}"]`);
  if (!inner || !row) return null;
   const cell = row.querySelector('.cell-fiber') || row.querySelector('.cell-nu')  || row;
  const rb = cell.getBoundingClientRect();
  const ib = inner.getBoundingClientRect();
  return { y: rb.top + rb.height/2 - ib.top, innerBox: ib };
}

function drawConnections(fromKey, toKey, mappings, svgId) {
  const svg = document.getElementById(svgId);
  if (!svg) return;

  // ← scope to the demo this svg lives in
  const root = svg.closest('.d-flex.align-items-start, .wrap3, .container') || document;

  svg.innerHTML = '';
  const fromInner = root.querySelector('#chart-' + fromKey + ' .chart-inner');
  const toInner   = root.querySelector('#chart-' + toKey   + ' .chart-inner');
  if (!fromInner || !toInner) return;

  const midBox = svg.getBoundingClientRect();
  const fBox   = fromInner.getBoundingClientRect();
  const tBox   = toInner.getBoundingClientRect();

  // TIA-598-C / ITU-T fibre colour sequence — must match itu_colours.php
  const palette = ['#1565C0','#E65100','#2E7D32','#4E342E','#546E7A','#FFFFFF',
                   '#C62828','#212121','#F9A825','#6A1B9A','#E91E8C','#00838F'];

  function path(stroke, w, op) {
    const p = document.createElementNS('http://www.w3.org/2000/svg','path');
    p.setAttribute('fill','none');
    p.setAttribute('stroke', stroke);
    p.setAttribute('stroke-width', w);
    p.setAttribute('stroke-linecap','round');
    if (op != null) p.setAttribute('opacity', op);
    return p;
  }

  mappings.forEach(m => {
    const F = rowCenterY(fromKey, m.a, root);
    const T = rowCenterY(toKey,   m.b, root);
    if (!F || !T) return;

    const ax = fBox.right - midBox.left;
    const ay = F.y + fBox.top - midBox.top;
    const bx = tBox.left  - midBox.left;
    const by = T.y + tBox.top - midBox.top;

    const dx = (bx - ax) * 0.45;
    const c1x = ax + dx, c1y = ay;
    const c2x = bx - dx, c2y = by;

    const color = palette[((m.a-1) % 12)];

    const bg = path('#000', 3, 0.30);
    bg.setAttribute('d', `M ${ax},${ay} C ${c1x},${c1y} ${c2x},${c2y} ${bx},${by}`);
    svg.appendChild(bg);

    const fg = path(color, 1.6, 0.95);
    fg.setAttribute('d', `M ${ax},${ay} C ${c1x},${c1y} ${c2x},${c2y} ${bx},${by}`);
    svg.appendChild(fg);
  });
}
// ---------- consume CHART_ORDER + LINKS_RAW produced by render_layout ----------
function autoRenderLinks() {
  if (!window.CHART_ORDER || !window.LINKS_RAW) return;

  const groups = {};
  LINKS_RAW.forEach(link => {
    const fromKey = link.from[0], a = link.from[1];
    const toKey   = link.to[0],   b = link.to[1];

    // Pick the immediate neighbor gutter to the right of fromKey
    const i = CHART_ORDER.indexOf(fromKey);
    const nextKey = CHART_ORDER[i + 1];
    if (!nextKey) return;

    const svgId = `svg-${fromKey}-${nextKey}`;

    // Bucket by svgId + toKey so A→B and A→C don't overwrite each other
    const bucketKey = `${svgId}::${toKey}`;
    (groups[bucketKey] ||= { svgId, from: fromKey, to: toKey, pairs: [] }).pairs.push({ a, b });
  });

  Object.values(groups).forEach(g => {
    drawConnections(g.from, g.to, g.pairs, g.svgId);
  });
}

// Namespaced rendering: run links for a specific container (ns = container id)
function autoRenderLinksNS(ns) {
  const root = document.getElementById(ns);
  if (!root) return;

  const CHART_ORDER  = window['CHART_ORDER_'  + ns];
  const LINKS_RAW    = window['LINKS_RAW_'    + ns];
  const CHART_GROUPS = window['CHART_GROUPS_' + ns]; // null when not using grouped layout
  if (!CHART_ORDER || !LINKS_RAW) return;

  const groups = {};
  LINKS_RAW.forEach(link => {
    const fromKey = link.from[0], a = link.from[1];
    const toKey   = link.to[0],   b = link.to[1];

    let svgId, effectiveFrom, effectiveTo, pa, pb;

    if (CHART_GROUPS) {
      // ── Grouped layout: route through inter-group gutter ──────────────
      const fg = CHART_GROUPS[fromKey];
      const tg = CHART_GROUPS[toKey];
      if (fg === undefined || tg === undefined || fg === tg) return; // same group or unknown

      const leftGrp  = Math.min(fg, tg);
      const rightGrp = leftGrp + 1;
      svgId = `svg-g${leftGrp}-g${rightGrp}`;

      if (fg <= leftGrp) {
        effectiveFrom = fromKey; effectiveTo = toKey; pa = a; pb = b;
      } else {
        effectiveFrom = toKey; effectiveTo = fromKey; pa = b; pb = a;
      }
    } else {
      // ── Flat layout: route through gutter between adjacent chart keys ──
      const fi = CHART_ORDER.indexOf(fromKey);
      const ti = CHART_ORDER.indexOf(toKey);
      if (fi === -1 || ti === -1 || fi === ti) return;

      const leftIdx = Math.min(fi, ti);
      const leftKey  = CHART_ORDER[leftIdx];
      const rightKey = CHART_ORDER[leftIdx + 1];
      if (!rightKey) return;

      svgId = `svg-${leftKey}-${rightKey}`;

      if (fi <= leftIdx) {
        effectiveFrom = fromKey; effectiveTo = toKey; pa = a; pb = b;
      } else {
        effectiveFrom = toKey; effectiveTo = fromKey; pa = b; pb = a;
      }
    }

    const bucketKey = `${svgId}::${effectiveFrom}::${effectiveTo}`;
    (groups[bucketKey] ||= { svgId, from: effectiveFrom, to: effectiveTo, pairs: [] }).pairs.push({ a: pa, b: pb });
  });

  // Clear all gutter SVGs once before drawing (avoids per-call clear wiping earlier groups)
  root.querySelectorAll('.between-svg').forEach(s => { s.innerHTML = ''; });

  Object.values(groups).forEach(g => {
    drawConnectionsInRoot(root, g.from, g.to, g.pairs, g.svgId);
  });
}

// Same as drawConnections(), but scoped to a specific root container
function drawConnectionsInRoot(root, fromKey, toKey, mappings, svgId) {
  const svg = root.querySelector('#' + svgId);
  if (!svg) return;

  // Note: caller is responsible for clearing SVGs before the first call

  const fromInner = root.querySelector('#chart-' + fromKey + ' .chart-inner');
  const toInner   = root.querySelector('#chart-' + toKey   + ' .chart-inner');
  if (!fromInner || !toInner) return;

  const midBox = svg.getBoundingClientRect();
  const fBox   = fromInner.getBoundingClientRect();
  const tBox   = toInner.getBoundingClientRect();

  // TIA-598-C / ITU-T fibre colour sequence — must match itu_colours.php
  const palette = ['#1565C0','#E65100','#2E7D32','#4E342E','#546E7A','#FFFFFF',
                   '#C62828','#212121','#F9A825','#6A1B9A','#E91E8C','#00838F'];

  mappings.forEach(m => {
    const F = rowCenterY(fromKey, m.a, root);
    const T = rowCenterY(toKey,   m.b, root);
    if (!F || !T) return;

    const ax = fBox.right - midBox.left;
    const ay = F.y + fBox.top - midBox.top;
    const bx = tBox.left  - midBox.left;
    const by = T.y + tBox.top - midBox.top;

    const dx = (bx - ax) * 0.45;
    const c1x = ax + dx, c1y = ay;
    const c2x = bx - dx, c2y = by;

    const color = palette[((m.a - 1) % 12)];

    const bg = document.createElementNS('http://www.w3.org/2000/svg','path');
    bg.setAttribute('fill','none'); bg.setAttribute('stroke','#000');
    bg.setAttribute('stroke-width','3'); bg.setAttribute('stroke-linecap','round');
    bg.setAttribute('opacity','0.30');
    bg.setAttribute('d', `M ${ax},${ay} C ${c1x},${c1y} ${c2x},${c2y} ${bx},${by}`);
    svg.appendChild(bg);

    const fg = document.createElementNS('http://www.w3.org/2000/svg','path');
    fg.setAttribute('fill','none'); fg.setAttribute('stroke', color);
    fg.setAttribute('stroke-width','1.6'); fg.setAttribute('stroke-linecap','round');
    fg.setAttribute('opacity','0.95');
    fg.setAttribute('d', `M ${ax},${ay} C ${c1x},${c1y} ${c2x},${c2y} ${bx},${by}`);
    svg.appendChild(fg);
  });
}