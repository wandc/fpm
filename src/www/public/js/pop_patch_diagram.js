/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
// js/pop_patch_diagram.js
// Simple POP patch-cable diagram: terminated-fibre closures (patch panels) on the LEFT,
// active POP devices on the RIGHT, and a plain line per patch cable between two rows.
// Rows are generic ({key,label}) so a device block can list ports OR SFP-module slots
// (an SFP plugged into a router/OLT renders as a row inside its parent block).
// Fibre/port numbers are the focus; lines are neutral (colour de-emphasised).
//
//   _renderPopPatchDiagram(containerId, D)
//   D = { closures:[{id,label,sub,rows:[{key,label}]}],
//         devices: [{id,label,sub,rows:[{key,label}]}],
//         links:   [{a:{side,id,rowKey}, b:{side,id,rowKey}, sj}] }
// Lines carry data-sj so initSpliceDiagramHover() can light them up.

function _renderPopPatchDiagram(containerId, D) {
  var el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '';
  var closures = D.closures || [], devices = D.devices || [], links = D.links || [];
  if (!closures.length && !devices.length) return;   // render blocks even with no patches yet

  var NS = 'http://www.w3.org/2000/svg';
  function se(tag, a) { var e = document.createElementNS(NS, tag); for (var k in a) e.setAttribute(k, a[k]); return e; }
  function st(a, t) { var e = se('text', a); e.textContent = t; return e; }

  // ── Geometry ────────────────────────────────────────────────────────────────
  var PAD = 16, HEADER = 34, ROWH = 22, DOT = 3.5;
  var LEFTW = 170, RIGHTW = 230, COLGAP = 260, LEAD = 70;
  var leftX  = PAD;
  var rightX = PAD + LEFTW + COLGAP;
  var leftAnchorX  = leftX + LEFTW;   // right edge of closure blocks
  var rightAnchorX = rightX;          // left edge of device blocks

  var NOTEH = 14;                                    // extra header height for a note line
  function hdrH(b) { return HEADER + (b.note ? NOTEH : 0); }
  function blockHeight(b) { return hdrH(b) + b.rows.length * ROWH + 8; }

  // Lay closures down the left column (given order).
  var y = PAD, anchor = {};           // anchor["closure:id:rowKey"] = {x,y,out}
  closures.forEach(function (b) {
    b._x = leftX; b._y = y; b._h = blockHeight(b);
    b.rows.forEach(function (row, i) {
      anchor['closure:' + b.id + ':' + row.key] = { x: leftAnchorX, y: y + hdrH(b) + i * ROWH + ROWH / 2, out: 1 };
    });
    y += b._h + 14;
  });
  var leftBottom = y;

  // Order device blocks by the mean Y of the closure anchors they link to (reduce crossings).
  var devBary = {};
  devices.forEach(function (d) { devBary[d.id] = []; });
  links.forEach(function (lk) {
    [['a', 'b'], ['b', 'a']].forEach(function (pair) {
      var e = lk[pair[0]], o = lk[pair[1]];
      if (e.side === 'device' && o.side === 'closure') {
        var ca = anchor['closure:' + o.id + ':' + o.rowKey];
        if (ca) devBary[e.id].push(ca.y);
      }
    });
  });
  devices.sort(function (a, b) {
    var am = devBary[a.id].length ? devBary[a.id].reduce(function (s, v) { return s + v; }, 0) / devBary[a.id].length : 1e9;
    var bm = devBary[b.id].length ? devBary[b.id].reduce(function (s, v) { return s + v; }, 0) / devBary[b.id].length : 1e9;
    return am - bm;
  });

  y = PAD;
  devices.forEach(function (d) {
    d._x = rightX; d._y = y; d._h = blockHeight(d);
    d.rows.forEach(function (row, i) {
      anchor['device:' + d.id + ':' + row.key] = { x: rightAnchorX, y: y + hdrH(d) + i * ROWH + ROWH / 2, out: -1 };
    });
    y += d._h + 14;
  });
  var rightBottom = y;

  var W = rightX + RIGHTW + PAD;
  var H = Math.max(leftBottom, rightBottom, PAD + 60) + PAD;
  var svg = se('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H,
                        'font-family': 'system-ui, sans-serif' });

  // ── Click-to-draw patch: click one port, then another, to create a patch cable ─
  var pending = null;   // { epId, epPort, x, y, dot }
  var band = null;      // rubber-band preview path
  function svgPt(evt) {
    var m = svg.getScreenCTM(); if (!m) return null;
    var p = svg.createSVGPoint(); p.x = evt.clientX; p.y = evt.clientY;
    return p.matrixTransform(m.inverse());
  }
  function hint(text) {
    var h = el.querySelector('.pop-patch-hint');
    if (!text) { if (h) h.remove(); return; }
    if (!h) {
      h = document.createElement('div'); h.className = 'pop-patch-hint';
      h.style.cssText = 'font-size:12px;color:#b45309;margin-bottom:6px;font-weight:600';
      el.insertBefore(h, el.firstChild);
    }
    h.textContent = text;
  }
  function cancelPending() {
    if (pending && pending.dot) { pending.dot.setAttribute('r', DOT); pending.dot.removeAttribute('stroke'); }
    if (band) { band.remove(); band = null; }
    pending = null; hint('');
  }
  function clickEndpoint(ep, dot) {
    if (ep.epId == null) return;                 // row with no real endpoint — ignore
    if (!pending) {
      pending = { epId: ep.epId, epPort: ep.epPort, x: ep.x, y: ep.y, dot: dot };
      dot.setAttribute('r', DOT + 2); dot.setAttribute('stroke', '#f59e0b'); dot.setAttribute('stroke-width', '2.5');
      band = se('path', { fill: 'none', stroke: '#f59e0b', 'stroke-width': '2',
                          'stroke-dasharray': '5,4', 'pointer-events': 'none' });
      svg.appendChild(band);
      hint('Click another port to draw a patch · Esc to cancel');
    } else if (pending.epId === ep.epId && pending.epPort === ep.epPort) {
      cancelPending();
    } else {
      var a = pending; cancelPending();
      if (window._popCreatePatch) window._popCreatePatch(a.epId, a.epPort, ep.epId, ep.epPort);
    }
  }
  svg.addEventListener('mousemove', function (evt) {
    if (!pending || !band) return;
    var p = svgPt(evt); if (!p) return;
    band.setAttribute('d', 'M ' + pending.x + ',' + pending.y + ' L ' + p.x + ',' + p.y);
  });
  svg.addEventListener('click', function () { if (pending) cancelPending(); }); // background click cancels
  document.addEventListener('keydown', function (evt) { if (evt.key === 'Escape') cancelPending(); });

  // ── Patch-cable lines first (behind the blocks) ──────────────────────────────
  links.forEach(function (lk) {
    var ka = lk.a.side + ':' + lk.a.id + ':' + lk.a.rowKey;
    var kb = lk.b.side + ':' + lk.b.id + ':' + lk.b.rowKey;
    var A = anchor[ka], B = anchor[kb];
    if (!A || !B) return;
    var c1x = A.x + A.out * LEAD, c2x = B.x + B.out * LEAD;
    var d = 'M ' + A.x + ',' + A.y + ' C ' + c1x + ',' + A.y + ' ' + c2x + ',' + B.y + ' ' + B.x + ',' + B.y;
    // Active patch (connected to a laser source: SFP/OLT-GPON) → green wire + ⚡ bolt.
    var pa = { d: d, fill: 'none', stroke: lk.active ? '#16a34a' : '#64748b',
               'stroke-width': lk.active ? '2.2' : '1.6', opacity: '0.85', 'stroke-linecap': 'round' };
    if (lk.sj != null) pa['data-sj'] = String(lk.sj);
    svg.appendChild(se('path', pa));
    if (lk.active) {
      // Live-traffic bolt — identical style to the closure splice diagram (dark disc + white ⚡).
      var mx = (A.x + B.x) / 2, my = (A.y + B.y) / 2;
      var bg = se('circle', { cx: mx, cy: my, r: 7, fill: '#0f172a', opacity: '0.92', 'pointer-events': 'none' });
      var bolt = st({ x: mx, y: my + 3, 'font-size': '10', 'text-anchor': 'middle', fill: '#fff', 'pointer-events': 'none' }, '⚡');
      if (lk.sj != null) { bg.setAttribute('data-sj', String(lk.sj)); bolt.setAttribute('data-sj', String(lk.sj)); }
      svg.appendChild(bg); svg.appendChild(bolt);
    }
    // Wide transparent overlay so the thin wire is easy to click → delete (with confirm).
    if (lk.sj != null) {
      var hit = se('path', { d: d, fill: 'none', stroke: 'transparent', 'stroke-width': '12',
                             'data-sj': String(lk.sj), style: 'cursor:pointer' });
      var hitTitle = se('title', {}); hitTitle.textContent = 'Click to delete this patch cable'; hit.appendChild(hitTitle);
      hit.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (pending) { cancelPending(); return; }     // don't delete mid-draw
        if (window._popDeletePatch) window._popDeletePatch(lk.sj);
      });
      svg.appendChild(hit);
    }
  });

  // ── Blocks ───────────────────────────────────────────────────────────────────
  // Click a node dot → patch that port. Click anywhere else on the block → edit it.
  function editBlock(b) {
    if (window._popEditBlock) window._popEditBlock(b.id, b.name || b.label, b.note || '', b.label, b.rows);
  }
  function drawBlock(b, side, w) {
    var g = se('g', { style: 'cursor:pointer' });
    g.addEventListener('click', function () { editBlock(b); });   // block body → edit
    var fill = side === 'closure' ? '#eef2ff' : '#ecfdf5';
    var stroke = side === 'closure' ? '#6366f1' : '#10b981';
    g.appendChild(se('rect', { x: b._x, y: b._y, width: w, height: b._h, rx: 6,
                               fill: fill, stroke: stroke, 'stroke-width': '1.4' }));
    // Closure name → link to the parent closure detail page; otherwise plain label.
    var lblAttrs = { x: b._x + 8, y: b._y + 15, 'font-size': '12', 'font-weight': '700', fill: '#1e293b' };
    if (side === 'closure' && b.foscId) {
      lblAttrs.fill = '#4338ca'; lblAttrs['text-decoration'] = 'underline'; lblAttrs.style = 'cursor:pointer';
    }
    var lbl = st(lblAttrs, b.label);
    if (side === 'closure' && b.foscId) {
      var lblTitle = se('title', {}); lblTitle.textContent = 'Open closure ' + b.label; lbl.appendChild(lblTitle);
      lbl.addEventListener('click', function (ev) {
        ev.stopPropagation();
        window.location.href = 'index.php?page=closure&fosc_id=' + b.foscId;
      });
    }
    g.appendChild(lbl);
    if (b.sub) g.appendChild(st({ x: b._x + 8, y: b._y + 28, 'font-size': '9.5', fill: '#64748b' }, b.sub));
    if (b.note) {                                                // note line (any block)
      var noteTxt = b.note.length > 34 ? b.note.slice(0, 33) + '…' : b.note;
      var nt = st({ x: b._x + 8, y: b._y + 42, 'font-size': '9.5', 'font-style': 'italic', fill: '#475569' }, '📝 ' + noteTxt);
      var ntTitle = se('title', {}); ntTitle.textContent = b.note; nt.appendChild(ntTitle);
      g.appendChild(nt);
    }
    g.appendChild(se('line', { x1: b._x + 6, y1: b._y + hdrH(b) - 4, x2: b._x + w - 6, y2: b._y + hdrH(b) - 4,
                               stroke: stroke, 'stroke-width': '0.6', opacity: '0.4' }));
    // Edit pencil (top-right) — closure blocks: name navigates, so editing needs its own affordance.
    if (side === 'closure') {
      var edG = se('g', { class: 'pop-edit-block', style: 'cursor:pointer' });
      var ecx = b._x + w - 13, ecy = b._y + 13;
      edG.appendChild(se('circle', { cx: ecx, cy: ecy, r: 9, fill: '#fff', stroke: stroke, 'stroke-width': '1.3' }));
      edG.appendChild(st({ x: ecx, y: ecy + 4, 'font-size': '11', 'text-anchor': 'middle', fill: stroke }, '✎'));
      var edTitle = se('title', {}); edTitle.textContent = 'Edit ' + b.label; edG.appendChild(edTitle);
      edG.addEventListener('click', function (ev) { ev.stopPropagation(); editBlock(b); });
      g.appendChild(edG);
    }
    // "+ SFP" affordance — only on chassis devices (routers/OLTs) that hold SFP modules.
    if (side === 'device' && b.canHostSfp) {
      var addG = se('g', { class: 'pop-add-sfp', style: 'cursor:pointer' });
      var cx = b._x + w - 13, cy = b._y + 13;
      addG.appendChild(se('circle', { cx: cx, cy: cy, r: 9, fill: '#fff', stroke: stroke, 'stroke-width': '1.3' }));
      addG.appendChild(st({ x: cx, y: cy + 4, 'font-size': '14', 'font-weight': '700',
                            'text-anchor': 'middle', fill: stroke }, '+'));
      var addTitle = se('title', {}); addTitle.textContent = 'Add SFP to ' + b.label; addG.appendChild(addTitle);
      addG.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (window._popOnAddSfp) window._popOnAddSfp(b.id, b.label);
      });
      g.appendChild(addG);
    }
    b.rows.forEach(function (rowDef, i) {
      var rowY = b._y + hdrH(b) + i * ROWH + ROWH / 2;
      var dotX = side === 'closure' ? (b._x + w) : b._x;
      var row = se('g', { class: 'sj-row pop-row' });
      // transparent body hit → bubbles to the block (edit)
      row.appendChild(se('rect', { x: b._x, y: rowY - ROWH / 2, width: w, height: ROWH, fill: 'transparent' }));
      if (side === 'closure') {
        row.appendChild(st({ x: b._x + w - 10, y: rowY + 4, 'font-size': '11', 'text-anchor': 'end', fill: '#334155' }, rowDef.label));
        // Per-fibre note on the left of the row: manual override wins, else the auto trace note
        // (lighter). Click to edit (prompt seeds with the override, or the auto text to tweak).
        var ovr = rowDef.note || '', auto = rowDef.noteAuto || '';
        var eff = ovr || auto;
        var disp = eff ? (eff.length > 24 ? eff.slice(0, 23) + '…' : eff) : '+ note';
        var noteEl = st({ x: b._x + 8, y: rowY + 4, 'font-size': '8.5', 'text-anchor': 'start',
                          'font-style': 'italic', style: 'cursor:pointer',
                          fill: ovr ? '#475569' : (auto ? '#94a3b8' : '#cbd5e1') },
                         (ovr ? '📝 ' : '') + disp);
        var nTtl = se('title', {});
        nTtl.textContent = eff ? (eff + (ovr ? '' : '  (auto — click to override)')) : ('Add a note for ' + rowDef.label);
        noteEl.appendChild(nTtl);
        noteEl.addEventListener('click', function (ev) {
          ev.stopPropagation();
          if (window._popSetFibreNote) window._popSetFibreNote(b.id, rowDef.epPort, ovr, auto, rowDef.label);
        });
        row.appendChild(noteEl);
      } else {
        row.appendChild(st({ x: b._x + 10, y: rowY + 4, 'font-size': '11', 'text-anchor': 'start', fill: '#334155' }, rowDef.label));
      }
      var dot = se('circle', { cx: dotX, cy: rowY, r: DOT, fill: stroke });
      row.appendChild(dot);
      // Only the node circle (a small hit area around the dot) starts a patch.
      var ep = { epId: rowDef.epId, epPort: rowDef.epPort, x: dotX, y: rowY };
      var dotHit = se('circle', { cx: dotX, cy: rowY, r: 9, fill: 'transparent', style: 'cursor:crosshair' });
      var dotHitTitle = se('title', {}); dotHitTitle.textContent = 'Click to patch this port'; dotHit.appendChild(dotHitTitle);
      dotHit.addEventListener('click', function (evt) { evt.stopPropagation(); clickEndpoint(ep, dot); });
      row.appendChild(dotHit);
      g.appendChild(row);
    });
    svg.appendChild(g);
  }
  closures.forEach(function (b) { drawBlock(b, 'closure', LEFTW); });
  devices.forEach(function (d) { drawBlock(d, 'device', RIGHTW); });

  el.appendChild(svg);
}
