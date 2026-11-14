/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
// js/splice_diagram.helper.js
// Shared connection-diagram renderer, extracted verbatim from pages/closure.php so
// both the closure page and the POP detail page can draw the same SVG.
//
// Public API:
//   _renderSpliceDiagram(containerId, D)   — D = { splices, spans, devs, feedKey }
//        keyed by cable-span id and fosc_device id (closure/POP-agnostic).
//   liveBoltPositions                      — populated during render; the page reads
//        it to overlay live-traffic ⚡ bolts (see api/live_fibers.php).
//   initSpliceDiagramHover(containerIds)   — wire splice-table rows ↔ diagram wires
//        (hover highlight/dim). Pass the diagram container id(s) to bind.

  var liveBoltPositions = []; // {containerId, spanId, fiberNum, x, y} — all fibers, filled during render

  function _renderSpliceDiagram(containerId, D) {
    var splices = D.splices, spanIdx = D.spans, devIdx = D.devs, feedKey = D.feedKey || '';
    if (!splices.length) return;

    var NS = 'http://www.w3.org/2000/svg';
    var FH = 14, FP = 1, LH = 38, ITEM_GAP = 12, TUBE_GAP = 4;
    // FP = 1px padding top+bottom of each fiber rect inside its tube
    // TUBE_GAP = 4px gap between buffer tubes (tubes are solid blocks)
    var LW = 80, DW = 185, RW = 80, LMGAP = 80, RMGAP = 150;


    var ITU = [
      '#007bff',  //  1 Blue
      '#fd7e14',  //  2 Orange
      '#28a745',  //  3 Green
      '#795548',  //  4 Brown
      '#6c757d',  //  5 Slate
      '#f8f9fa',  //  6 White   ← needs dark text
      '#dc3545',  //  7 Red
      '#212529',  //  8 Black
      '#ffc107',  //  9 Yellow  ← needs dark text
      '#6f42c1',  // 10 Violet
      '#e83e8c',  // 11 Rose
      '#20c997',  // 12 Aqua
    ];
    var ITU_ABBR = ['Blu','Org','Grn','Brn','Slt','Wht','Red','Blk','Yel','Vio','Rse','Aqe'];
    function ituC(f)    { return ITU[(f - 1 + 120) % 12]; }
    function ituAbbr(f) { return ITU_ABBR[(f - 1 + 120) % 12]; }
    function ituDk(f)   { var m = ((f - 1 + 120) % 12) + 1; return m === 6 || m === 9; }

    function se(tag, a) {
      var e = document.createElementNS(NS, tag);
      for (var k in a) e.setAttribute(k, a[k]);
      return e;
    }
    function st(a, t) { var e = se('text', a); e.textContent = t; return e; }

    // ── Build item maps ──────────────────────────────────────────
    // leftMap:  cable spans connected to device IN (or a-side of span-span)
    // rightMap: cable spans connected to device OUT (or b-side of span-span)
    // devMap:   one entry per device — single unified block in centre column
    var leftMap = {}, rightMap = {}, devMap = {};

    // Pre-pass 1: find which spans connect to device IN ports.
    // b_lo===0 is the standard sentinel for "device IN".
    // Also treat b_lo > device.outs as IN (some splices copy the cable fiber
    // number into b_lo instead of using 0, detectable because it exceeds the
    // device's actual output count).
    function _preIsDevIn(devId, lo) {
      if (lo === 0) return true;
      var dv = devIdx[devId];
      return !!(dv && dv.outs > 0 && lo > dv.outs);
    }
    var spanHasDevIn = {}, spanHasDevOut = {};
    splices.forEach(function(sj) {
      var aSpan = sj.a_target === 'cable_span';
      var bSpan = sj.b_target === 'cable_span';
      if (aSpan && !bSpan) {
        var k = 'span-' + sj.a_id + '-' + sj.a_end;
        if (_preIsDevIn(sj.b_id, sj.b_lo)) spanHasDevIn[k]  = true;
        else                                spanHasDevOut[k] = true;
      } else if (!aSpan && bSpan) {
        var k = 'span-' + sj.b_id + '-' + sj.b_end;
        if (_preIsDevIn(sj.a_id, sj.a_lo)) spanHasDevIn[k]  = true;
        else                                spanHasDevOut[k] = true;
      }
    });

    // Pre-pass 2: span-span splices establish which column each span belongs to.
    // Priority order:
    //   1. feedKey override (explicit feed direction)
    //   2. span with device-IN connections → LEFT; its partner → RIGHT
    //   3. default: a-side → LEFT, b-side → RIGHT
    // This prevents a span with device-IN wires from landing in the right column
    // (which would cause those wires to draw backwards/off-screen).
    var spanSideMap = {};
    splices.forEach(function(sj) {
      if (sj.a_target === 'cable_span' && sj.b_target === 'cable_span') {
        var aKey = 'span-' + sj.a_id + '-' + sj.a_end;
        var bKey = 'span-' + sj.b_id + '-' + sj.b_end;
        var aFk  = 'cable_span-' + sj.a_id + '-' + sj.a_end;
        var bFk  = 'cable_span-' + sj.b_id + '-' + sj.b_end;
        if (feedKey && feedKey === bFk) {
          spanSideMap[bKey] = 'left';
          spanSideMap[aKey] = 'right';
        } else if (spanHasDevIn[bKey] && !spanHasDevIn[aKey]) {
          // b-side connects to device IN → b goes LEFT, a goes RIGHT
          spanSideMap[bKey] = 'left';
          spanSideMap[aKey] = 'right';
        } else {
          spanSideMap[aKey] = 'left';
          spanSideMap[bKey] = 'right';
        }
      }
    });

    function getSpan(id, end, hintSide) {
      var key  = 'span-' + id + '-' + end;
      // Canonical side: span-span pre-pass wins; fall back to caller hint
      var side = spanSideMap[key] || hintSide;
      var myMap  = side === 'left' ? leftMap : rightMap;
      var altMap = side === 'left' ? rightMap : leftMap;
      if (myMap[key])  return myMap[key];
      if (altMap[key]) return altMap[key]; // safety: never duplicate
      var ep = spanIdx[id + '-' + end] || {};
      var lf = ep.live_fibers || {};
      return myMap[key] = {
        key: key, id: id, end: end, side: side,
        label:    ep.short_label
                  || ((ep.fibre_count ? ep.fibre_count + 'F' : '') || ('Span ' + id)),
        // Cable type, far end, bearing and length: hover only.
        detail:   ep.detail
                  || [(ep.cable_name || ''), (ep.far_fosc_label || ''), (ep.bearing_text || '')]
                     .filter(Boolean).join(' \u00b7 '),
        totalFibers: ep.fibre_count || 4, portStart: 1,
        isFeed: feedKey === ('cable_span-' + id + '-' + end),
        liveSet: lf,
        used: [],
        fiberSj: {}   // fiberNum → splice_join_id, for hover-highlight
      };
    }

    function getDev(id) {
      if (!devMap[id]) {
        var dv = devIdx[id] || {};
        devMap[id] = {
          key: 'dev-' + id, id: id,
          label:       dv.name || 'Device ' + id,
          sublabel:    dv.type_label || '',
          outCount:    dv.outs || 0,
          isOltPon:    !!dv.is_olt_pon,
          isPatchPanel:!!dv.is_patch_panel,
          // passive MPT renders 1:1 like a patch panel, but ports are explicit
          isMptPassive:!!dv.is_mpt && !!dv.mpt_passive,
          inUsed: [], outUsed: [],
          _inPortNext: 1   // running port counter for patch-panel IN assignments
        };
      }
      return devMap[id];
    }

    // Detect device-IN port: b_lo===0 is the standard sentinel.
    // Some splices store the cable fiber number as b_lo instead — detect by
    // checking if b_lo exceeds the device's known output count.
    function isDevIn(devId, lo) {
      if (lo === 0) return true;
      var dv = devIdx[devId];
      // Passive MPT: feeder fibres land on explicit numbered ports (1..N) on the
      // network/IN side — always treat cable↔MPT splices as IN, keyed on the port.
      if (dv && dv.is_mpt && dv.mpt_passive) return true;
      return !!(dv && dv.outs > 0 && lo > dv.outs);
    }

    var patchWires = [];

    // Record fiberNum → splice_join_id on a span item, so each per-fiber wire
    // drawn later can be tagged for hover-highlight (independent of band merge).
    function tagFibers(item, lo, hi, id) {
      if (id == null) return;
      for (var f = lo; f <= hi; f++) item.fiberSj[f] = id;
    }

    splices.forEach(function(sj) {
      var aSpan = sj.a_target === 'cable_span';
      var bSpan = sj.b_target === 'cable_span';
      if (aSpan && bSpan) {
        var L = getSpan(sj.a_id, sj.a_end, 'left');
        var R = getSpan(sj.b_id, sj.b_end, 'right');
        var c = ituC(sj.a_lo);
        L.used.push({lo:sj.a_lo, hi:sj.a_hi, color:c, peer:R, pLo:sj.b_lo, pHi:sj.b_hi, kind:'span'});
        R.used.push({lo:sj.b_lo, hi:sj.b_hi, color:c, peer:L, pLo:sj.a_lo, pHi:sj.a_hi, kind:'span'});
        tagFibers(L, sj.a_lo, sj.a_hi, sj.id);
        tagFibers(R, sj.b_lo, sj.b_hi, sj.id);
      } else if (aSpan && !bSpan) {
        var dv = getDev(sj.b_id);
        var c  = ituC(sj.a_lo);
        if (isDevIn(sj.b_id, sj.b_lo)) {   // → IN port: span on left
          var L = getSpan(sj.a_id, sj.a_end, 'left');
          if (dv.isMptPassive) {
            // Passive MPT: feeder lands on its explicit device port (sj.b_lo..b_hi)
            L.used.push({lo:sj.a_lo, hi:sj.a_hi, color:c, peer:dv, pLo:sj.b_lo, kind:'dev-in'});
            dv.inUsed.push({lo:sj.b_lo, hi:sj.b_hi, color:c});
          } else if (dv.isPatchPanel) {
            var pStart = dv._inPortNext;
            dv._inPortNext += (sj.a_hi - sj.a_lo + 1);
            L.used.push({lo:sj.a_lo, hi:sj.a_hi, color:c, peer:dv, pLo:pStart, kind:'dev-in'});
            dv.inUsed.push({lo:pStart, hi:pStart + (sj.a_hi - sj.a_lo), color:c});
          } else {
            L.used.push({lo:sj.a_lo, hi:sj.a_hi, color:c, peer:dv, pLo:0, kind:'dev-in'});
            dv.inUsed.push({lo:0, hi:0, color:c});
          }
          tagFibers(L, sj.a_lo, sj.a_hi, sj.id);
        } else {                             // → OUT port: span on right
          var R = getSpan(sj.a_id, sj.a_end, 'right');
          R.used.push({lo:sj.a_lo, hi:sj.a_hi, color:c, peer:dv, pLo:sj.b_lo, pHi:sj.b_hi, kind:'dev-out'});
          dv.outUsed.push({lo:sj.b_lo, hi:sj.b_hi, color:c});
          tagFibers(R, sj.a_lo, sj.a_hi, sj.id);
        }
      } else if (!aSpan && bSpan) {
        var dv = getDev(sj.a_id);
        var c  = ituC(sj.b_lo);
        if (isDevIn(sj.a_id, sj.a_lo)) {   // IN → span: span on left
          var L = getSpan(sj.b_id, sj.b_end, 'left');
          if (dv.isMptPassive) {
            // Passive MPT: feeder lands on its explicit device port (sj.a_lo..a_hi)
            L.used.push({lo:sj.b_lo, hi:sj.b_hi, color:c, peer:dv, pLo:sj.a_lo, kind:'dev-in'});
            dv.inUsed.push({lo:sj.a_lo, hi:sj.a_hi, color:c});
          } else if (dv.isPatchPanel) {
            var pStart = dv._inPortNext;
            dv._inPortNext += (sj.b_hi - sj.b_lo + 1);
            L.used.push({lo:sj.b_lo, hi:sj.b_hi, color:c, peer:dv, pLo:pStart, kind:'dev-in'});
            dv.inUsed.push({lo:pStart, hi:pStart + (sj.b_hi - sj.b_lo), color:c});
          } else {
            L.used.push({lo:sj.b_lo, hi:sj.b_hi, color:c, peer:dv, pLo:0, kind:'dev-in'});
            dv.inUsed.push({lo:0, hi:0, color:c});
          }
          tagFibers(L, sj.b_lo, sj.b_hi, sj.id);
        } else {                             // OUT → span: span on right
          var R = getSpan(sj.b_id, sj.b_end, 'right');
          R.used.push({lo:sj.b_lo, hi:sj.b_hi, color:c, peer:dv, pLo:sj.a_lo, pHi:sj.a_hi, kind:'dev-out'});
          dv.outUsed.push({lo:sj.a_lo, hi:sj.a_hi, color:c});
          tagFibers(R, sj.b_lo, sj.b_hi, sj.id);
        }
      } else {
        // Case 4: device-to-device (patch cable between two device ports)
        var dvA = devMap[sj.a_id], dvB = devMap[sj.b_id];
        if (dvA && dvB) {
          var portA = sj.a_lo, portB = sj.b_lo;
          var c = ituC(sj.a_lo);
          dvA.outUsed.push({lo:portA, hi:sj.a_hi, color:c, kind:'patch'});
          if (dvB !== dvA || portB !== portA) {
            dvB.outUsed.push({lo:portB, hi:sj.b_hi, color:c, kind:'patch'});
          }
          patchWires.push({dvA:dvA, portA:portA, dvB:dvB, portB:portB, color:c, sjId:sj.id});
        }
      }
    });

    var lArr = Object.values(leftMap);
    var dArr = Object.values(devMap);
    var rArr = Object.values(rightMap);

    // Merge consecutive same-peer same-kind fiber entries into single ribbons.
    // When the merged range covers ≥6 fibers use the buffer-tube colour so
    // a full tube-to-tube splice shows as one thick coloured band.
    (function mergeUsed(items) {
      items.forEach(function(item) {
        item.used.sort(function(a, b){ return a.lo - b.lo; });
        var m = [];
        item.used.forEach(function(u) {
          var p = m.length ? m[m.length - 1] : null;
          if (p && p.kind === u.kind && p.peer === u.peer
                && p.hi + 1 === u.lo
                && (u.pLo == null || p.pHi + 1 === u.pLo)) {
            p.hi = u.hi; p.pHi = u.pHi; // extend range
          } else {
            m.push({lo:u.lo, hi:u.hi, pLo:u.pLo, pHi:u.pHi,
                    color:u.color, peer:u.peer, kind:u.kind});
          }
        });
        // ≥6 consecutive fibers → colour ribbon by buffer tube, not by fiber
        m.forEach(function(u) {
          if (u.hi - u.lo + 1 >= 6)
            u.color = ITU[Math.floor((u.lo - 1) / 12) % 12];
        });
        item.used = m;
      });
    })(lArr.concat(rArr));

    // Sort dArr: OLT/SFP first, plain devices middle, splitters last (bottom)
    dArr.sort(function(a, b) {
      var sA = a.isOltPon ? 0 : (a.outCount > 1 ? 2 : 1);
      var sB = b.isOltPon ? 0 : (b.outCount > 1 ? 2 : 1);
      return sA - sB;
    });

    // Build device order map (index in sorted dArr) for connection-aware sorting
    var dOrder = {};
    dArr.forEach(function(dv, i) { dOrder[dv.id] = i; });

    // Sort left/right columns to minimise wire crossings. Splice/table
    // order is NOT meaningful, so each column is ordered by the barycentre
    // (mean vertical rank) of its connection endpoints in the adjacent
    // layers: device ports (dev-in/dev-out, anchored by dOrder) AND — for
    // device-less closures like patch panels / straight splices — the fibre
    // rows of the spans they connect to directly (span↔span). Left order
    // depends on right order and vice-versa, so a few sweeps are run to
    // converge. Rank = peerColumnIndex*1000 + targetFibre/port, so within a
    // peer the wires stay in fibre order and across peers in column order.
    function colIndex(arr) { var m = {}; arr.forEach(function(it, i){ m[it.key] = i; }); return m; }

    // Mean rank of an item's neighbours over the given connection kinds;
    // peerRank(u) → neighbour's vertical rank, or null to skip the link.
    function barycentre(item, kinds, peerRank) {
      var sum = 0, n = 0;
      item.used.forEach(function(u) {
        if (kinds.indexOf(u.kind) < 0) return;
        var r = peerRank(u);
        if (r === null) return;
        sum += r; n++;
      });
      return n ? sum / n : null;
    }
    function sortColumn(arr, kinds, peerRank) {
      var scored = arr.map(function(it, i) {
        return { it: it, b: barycentre(it, kinds, peerRank), orig: i };
      });
      scored.sort(function(a, b) {
        if (a.b === null && b.b === null) // unconnected: keep order, feed last
          return ((a.it.isFeed ? 1 : 0) - (b.it.isFeed ? 1 : 0)) || (a.orig - b.orig);
        if (a.b === null) return -1;       // unconnected float to top
        if (b.b === null) return 1;
        return (a.b - b.b) || (a.orig - b.orig);
      });
      return scored.map(function(s){ return s.it; });
    }
    for (var _sweep = 0; _sweep < 4; _sweep++) {
      var rIdx = colIndex(rArr);
      lArr = sortColumn(lArr, ['dev-in', 'span'], function(u) {
        if (u.kind === 'dev-in')
          return dOrder[u.peer.id] !== undefined ? dOrder[u.peer.id] * 1000 + (u.pLo || 0) : null;
        return rIdx[u.peer.key] !== undefined ? rIdx[u.peer.key] * 1000 + (u.pLo || 0) : null;
      });
      var lIdx = colIndex(lArr);
      rArr = sortColumn(rArr, ['dev-out', 'span'], function(u) {
        if (u.kind === 'dev-out')
          return dOrder[u.peer.id] !== undefined ? dOrder[u.peer.id] * 1000 + (u.pLo || 0) : null;
        return lIdx[u.peer.key] !== undefined ? lIdx[u.peer.key] * 1000 + (u.pLo || 0) : null;
      });
    }

    var hasDevs = dArr.length > 0;

    // ── Layout (assign .y and .h) ────────────────────────────────
    function layout(arr, hFn, startY) {
      var y = (startY !== undefined) ? startY : 4;
      arr.forEach(function(it) { it.y = y; it.h = hFn(it); y += it.h + ITEM_GAP; });
      return y;
    }
    // Row-pitch gap for n rows — declared here because the device height function
    // above runs before the Y helpers further down.
    function tubeGapFor(n) { return Math.floor(Math.max(0, n - 1) / 12) * TUBE_GAP; }

    // Height of a span block accounts for inter-tube gaps
    function spanH(it) {
      var f0 = Math.floor((it.portStart - 1) / 12);
      var f1 = Math.floor((it.portStart + it.totalFibers - 2) / 12);
      return LH + it.totalFibers * FH + Math.max(0, f1 - f0) * TUBE_GAP;
    }
    var lH = layout(lArr, spanH);
    var rH = layout(rArr, spanH);

    // Devices sit in their own column, so they cannot collide with cable fibre
    // rows horizontally. They used to be pushed BELOW the tallest cable as well,
    // which is harmless for a 4-way splitter and ruinous for a 144-port panel:
    // the panel lands a full cable-height down, every one of its 144 wires
    // becomes a full-height diagonal, and the fan turns into a solid block.
    //
    // A big device is aligned with the top instead, so a 1:1 termination runs as
    // short parallel wires. Small devices keep the old placement - a splitter
    // hanging below the cable it feeds reads as subordinate to it, which is what
    // it is, and its handful of wires cost nothing.
    // Same pitch as a cable block, so the two line up row for row.
    var dHFn   = function(it){ return LH + it.outCount * FH + tubeGapFor(it.outCount); };
    var tallestDev = dArr.reduce(function(m, it){ return Math.max(m, dHFn(it)); }, 0);
    var cableH     = Math.max(lH, rH);
    var dStartY = (tallestDev > cableH / 2) ? 4 : cableH + ITEM_GAP;
    var dH      = layout(dArr, dHFn, dStartY);
    var SVG_H   = Math.max(lH, rH, dH) + 8;

    // ── Horizontal corridor width ────────────────────────────────
    // The device→cable corridor is where the wire fan lives. When many wires
    // share it they run near-vertical and collapse into an unreadable overlap
    // (e.g. a 1×32 splitter fanning across a 144F cable). Give the corridor
    // ~one lane per wire so the fan opens into legible diagonals; bigger fans
    // (288F …) get proportionally more room. Density — not the span of any one
    // wire — is what drives this, so a lone long wire (a feed dropping the full
    // height) does NOT widen the corridor and sparse closures stay compact.
    // Rotated, the corridor is the SHORT axis while the fibre axis runs to several
    // thousand pixels. A wire then crosses the whole diagram through a sliver of
    // corridor, so it leaves its block at a shallow angle, runs flat across every
    // other block, and the fan collapses into the stripes you get when a 144F
    // panel is drawn 480px deep. Rotated needs both a wider lane per wire and a
    // much higher ceiling before the diagonals separate.
    function corridorGap(arr) {
      var n = 0;
      arr.forEach(function(it) { n += it.used.length; });
      return _orient() === 'h'
        ? Math.max(260, Math.min(4200, n * 44))
        : Math.max(80,  Math.min(2100, n * 22));   // ~one 22px lane per wire
    }
    RMGAP = corridorGap(rArr);
    LMGAP = corridorGap(lArr);

    // Column X positions — 3-col when devices present, 2-col otherwise
    var DEV_X = LW + LMGAP;
    var RGT_X = hasDevs ? DEV_X + DW + RMGAP : LW + (LMGAP + RMGAP);
    var SVG_W = RGT_X + (rArr.length ? RW : 0);
    if (!rArr.length && !hasDevs) SVG_W = LW + 10;

    // Extra space on each outer edge for cable-stub + stub-label pill
    var STUB_PAD = 44;
    var vbX = lArr.length ? -STUB_PAD : 0;
    var vbW = SVG_W + (lArr.length ? STUB_PAD : 0) + (rArr.length ? STUB_PAD : 0);
    var svg = se('svg', {
      viewBox: vbX + ' 0 ' + vbW + ' ' + SVG_H,
      style: 'width:100%;max-width:' + (vbW - (vbX < 0 ? 0 : 0)) + 'px;display:block',
      'font-family': 'system-ui,sans-serif'
    });
    var _clipSeq = 0;

    // ── Row Y helpers ────────────────────────────────────────────
    // ONE row pitch for everything on the diagram: a row is FH tall and a
    // TUBE_GAP is inserted every 12. Cables had it and devices did not, so a
    // 144-port panel came out 44px shorter than the 144F cable feeding it and
    // the 1:1 wires skewed a little further apart with every tube - which is
    // what made a straight-through termination look like a fan.
    // Using the same pitch on both sides, port N sits level with fibre N and the
    // wires are parallel. It also gives a panel the same visual grouping in 12s
    // that a cable gets from its buffer tubes, which is how the ports are trayed.
    function rowGap(nAbs)  { return Math.floor((nAbs - 1) / 12) * TUBE_GAP; }  // 1-based
    function rowsH(n)      { return n * FH + rowGap(n); }                      // n rows tall

    function spanRowY(item, p) {
      return item.y + LH + (p - item.portStart) * FH
           + (rowGap(p) - rowGap(item.portStart));
    }
    function devInY(dv)        { return dv.y + LH + (rowsH(dv.outCount) - FH) / 2; } // IN = vertically centred on left side
    function devOutY(dv, p)    { return dv.y + LH + (p - 1) * FH + rowGap(p); }      // OUT p on right side (1-indexed)

    // ── Render span block ────────────────────────────────────────
    // Structure: outer cable rect → per-tube solid strip → per-fiber coloured rect.
    // Buffer tube strip spans ALL its fibers as one solid block (no per-fiber seams).
    // Individual fibers have FP=1px padding top+bottom inside the tube.
    // TUBE_GAP separates consecutive tube blocks.
    function renderSpan(item, xOff, lblRight) {
      var g  = se('g', {transform:'translate('+xOff+',0)'});
      var bg = '#ffffff';
      var bd = item.isFeed ? '#d97706' : '#93c5fd';
      var TW = 14, SP = 2; // tube strip width, gap before fiber area

      // Clip-path so long cable names don't overflow the block header
      var clipId = 'hc' + (++_clipSeq);
      var defs = se('defs', {});
      var cp = se('clipPath', {id: clipId});
      cp.appendChild(se('rect', {x:'0', y:String(item.y), width:String(LW - 2), height:String(LH - 2)}));
      defs.appendChild(cp);
      g.appendChild(defs);

      g.appendChild(se('rect',{x:0,y:item.y,width:LW,height:item.h,rx:4,fill:bg,stroke:bd,'stroke-width':item.isFeed?2:1}));
      var hdrG = se('g', {'clip-path': 'url(#' + clipId + ')'});
      hdrG.appendChild(st({x:4,y:item.y+16,'font-size':11,'font-weight':'700',
                           'letter-spacing':'0.02em',fill:'#1e3a5f'}, item.label));
      if (item.isFeed) {
        // Broadcast signal icon: dot + 2 arcs + FEED label
        var iy = item.y + 31, ic_cy = iy - 4, ic_cx = 6;
        hdrG.appendChild(se('circle',{cx:String(ic_cx),cy:String(ic_cy),r:'2',fill:'#92400e'}));
        [4,7].forEach(function(r,i){
          var hx=(0.5*r).toFixed(1), hy=(0.866*r).toFixed(1);
          var d='M '+(ic_cx+ +hx)+','+(ic_cy- +hy)+' A '+r+','+r+' 0 0,1 '+(ic_cx+ +hx)+','+(ic_cy+ +hy);
          hdrG.appendChild(se('path',{d:d,fill:'none',stroke:'#92400e','stroke-width':'1.5',opacity:i?'0.55':'0.9'}));
        });
        hdrG.appendChild(st({x:'18',y:String(iy),'font-size':'8',fill:'#92400e','font-weight':'700'},'FEED'));
      }
      g.appendChild(hdrG);
      // Native tooltip on the whole block - the detail is there when wanted and
      // out of the way when not.
      if (item.detail) {
        // On the header strip only - on the whole block it showed over the
        // fibre-trace popup when hovering a fibre.
        var hdrHit = se('rect', {x:0, y:item.y, width:LW, height:LH, fill:'transparent'});
        var ttl = se('title', {});
        ttl.textContent = item.detail;
        hdrHit.appendChild(ttl);
        g.appendChild(hdrHit);
      }
      g.appendChild(se('line',{x1:0,y1:item.y+LH,x2:LW,y2:item.y+LH,stroke:bd}));
      var stripX = lblRight ? 1       : LW - TW - 1;
      var fiberX = lblRight ? TW+1+SP : 1;
      var fiberW = LW - TW - 2 - SP;
      var lblX   = lblRight ? LW - 4  : TW + SP + 4;
      var lastFiber  = item.portStart + item.totalFibers - 1;
      var firstTubeI = Math.floor((item.portStart - 1) / 12);
      var lastTubeI  = Math.floor((lastFiber - 1) / 12);
      for (var t = firstTubeI; t <= lastTubeI; t++) {
        var tLo = Math.max(item.portStart, t * 12 + 1);
        var tHi = Math.min(lastFiber, (t + 1) * 12);
        // One solid tube-colour strip spanning all fibers in this tube
        g.appendChild(se('rect',{
          x:stripX, y:spanRowY(item,tLo), width:TW,
          height:(tHi-tLo+1)*FH, fill:ITU[t%12], rx:2
        }));
        for (var p = tLo; p <= tHi; p++) {
          var fy = spanRowY(item, p);
          var u  = null;
          for (var j = 0; j < item.used.length; j++) {
            if (p >= item.used[j].lo && p <= item.used[j].hi) { u = item.used[j]; break; }
          }
          var fa = {
            x:fiberX, y:fy+FP, width:fiberW, height:FH-2*FP,
            fill:ituC(p), opacity:u?'1':'0.22', rx:1,
            stroke:'#0004', 'stroke-width':'0.5'
          };
          // Spliced fibres can be hovered for a trace (initFibreTrace).
          if (u && item.id != null && item.end) {
            fa['class'] = 'sd-fib'; fa['data-span'] = String(item.id);
            fa['data-end'] = item.end; fa['data-fib'] = String(p);
          }
          g.appendChild(se('rect', fa));
          // Fiber number (F1, F2 …) on the label side
          g.appendChild(st({x:lblX, y:fy+FH/2+4, 'font-size':9,
            fill:u?(ituDk(p)?'#333':'#fff'):(ituDk(p)?'#444':'#aaa'),
            'text-anchor':lblRight?'end':'start', 'pointer-events':'none'}, 'F'+p));
          // fiber-chip: abbreviated ITU colour name on the tube-strip side
          var CW=22, CH=10, cx=lblRight?(fiberX+2):(fiberX+fiberW-CW-2);
          g.appendChild(se('rect',{x:cx,y:fy+2,width:CW,height:CH,
            fill:ituC(p),rx:'3',
            stroke:ituDk(p)?'rgba(0,0,0,.18)':'rgba(255,255,255,.25)',
            'stroke-width':'1',opacity:u?'1':'0.35','pointer-events':'none'}));
          g.appendChild(st({x:cx+CW/2,y:fy+9,'font-size':8,'font-weight':'600',
            fill:ituDk(p)?'#222':'#fff','text-anchor':'middle',
            opacity:u?'1':'0.5','pointer-events':'none'}, ituAbbr(p)));
          // Collect bolt position for every fiber (rcApplyLiveMap filters by liveMap later)
          liveBoltPositions.push({
            containerId: containerId,
            spanId: item.id,
            fiberNum: p,
            sjId: item.fiberSj[p],   // splice this fiber belongs to (for hover dim)
            x: xOff + (lblRight ? LW + 15 : -15),
            y: fy + FH/2
          });
        }
      }

      // ── Cable stub + fiber-count pill on the OUTER edge ──────────
      // stub-left for right-span blocks (cable enters from right);
      // stub-right for left-span blocks (cable enters from left).
      var stubX  = lblRight ? -12 : LW + 2;  // outer side x
      var stubY2 = item.y + 4;
      var stubH2 = item.h - 8;
      var pillW  = 32, pillH = 14;
      var sCy    = item.y + item.h / 2;
      var pillX  = lblRight ? stubX - 2 - pillW : stubX + 10 + 2;
      // stub bar — amber for feed span, dark otherwise
      g.appendChild(se('rect', {
        x: String(stubX), y: String(stubY2), width: '10', height: String(stubH2),
        rx: '5', fill: item.isFeed ? '#d97706' : '#0f172a', opacity: '0.80'
      }));
      // Feed direction arrowhead: filled amber triangle pointing inward
      if (item.isFeed) {
        var aCy  = item.y + item.h / 2;
        var aH   = 6; // half-height of arrowhead
        var apts;
        if (lblRight) {
          // stub is left of block → arrow points right (into block)
          var ax = stubX + 10;
          apts = ax+','+aCy+' '+(ax-8)+','+(aCy-aH)+' '+(ax-8)+','+(aCy+aH);
        } else {
          // stub is right of block → arrow points left (into block)
          var ax = stubX;
          apts = ax+','+aCy+' '+(ax+8)+','+(aCy-aH)+' '+(ax+8)+','+(aCy+aH);
        }
        g.appendChild(se('polygon', {points: apts, fill: '#d97706', opacity: '0.95'}));
      }
      // pill badge
      g.appendChild(se('rect', {
        x: String(pillX), y: String(sCy - pillH / 2),
        width: String(pillW), height: String(pillH),
        rx: '7', fill: '#0f172a', opacity: '0.88'
      }));
      g.appendChild(st({
        x: String(pillX + pillW / 2), y: String(sCy + 4),
        'font-size': '9', 'font-weight': '700',
        fill: '#fff', 'text-anchor': 'middle'
      }, item.totalFibers + 'F'));

      svg.appendChild(g);
    }

    // ── Render device block: IN on LEFT, OUT 1..N stacked on RIGHT ──
    // IN wire enters LEFT edge; OUT wires exit RIGHT edge.
    function renderDev(dv) {
      var g    = se('g', {transform:'translate('+DEV_X+',0)'});
      var mid  = DW / 2;
      var IN_W = Math.round(DW * 0.40); // left portion reserved for IN tab
      g.appendChild(se('rect',{x:0,y:dv.y,width:DW,height:dv.h,rx:4,fill:'#e0f2fe',stroke:'#7dd3fc'}));
      g.appendChild(st({x:mid,y:dv.y+14,'font-size':11,'font-weight':'700',fill:'#1e3a5f','text-anchor':'middle'}, dv.label));
      g.appendChild(st({x:mid,y:dv.y+27,'font-size':9,fill:'#0e7490','text-anchor':'middle'}, dv.sublabel));
      g.appendChild(se('line',{x1:0,y1:dv.y+LH,x2:DW,y2:dv.y+LH,stroke:'#7dd3fc'}));
      // Vertical separator between IN area (left) and OUT area (right)
      g.appendChild(se('line',{x1:IN_W,y1:dv.y+LH,x2:IN_W,y2:dv.y+dv.h,stroke:'#7dd3fc','stroke-dasharray':'2,2'}));

      if (dv.isPatchPanel || dv.isMptPassive) {
        // Patch panel / passive MPT: N individual IN rows (left) aligned 1:1 with N
        // OUT rows (right). Both sides labelled "P{p}"; IN = cable/feeder side.
        for (var p = 1; p <= dv.outCount; p++) {
          var fy = devOutY(dv, p);
          // IN port (left side)
          var uIn = null;
          for (var j = 0; j < dv.inUsed.length; j++) { if (p >= dv.inUsed[j].lo && p <= dv.inUsed[j].hi) { uIn = dv.inUsed[j]; break; } }
          g.appendChild(se('rect',{x:1,y:fy+FP,width:IN_W-2,height:FH-2*FP,fill:'#3b82f6',opacity:uIn?'0.80':'0.20',rx:1}));
          g.appendChild(st({x:IN_W/2,y:fy+FH/2+4,'font-size':8,'font-weight':'700',fill:'#fff','text-anchor':'middle'},'P'+p));
          // OUT port (right side)
          var uOut = null;
          for (var j = 0; j < dv.outUsed.length; j++) { if (p >= dv.outUsed[j].lo && p <= dv.outUsed[j].hi) { uOut = dv.outUsed[j]; break; } }
          g.appendChild(se('rect',{x:IN_W+1,y:fy+FP,width:DW-IN_W-2,height:FH-2*FP,fill:'#94a3b8',opacity:uOut?'0.55':'0.20',rx:1}));
          g.appendChild(st({x:DW-5,y:fy+FH/2+4,'font-size':8,fill:'#fff','text-anchor':'end'},'P'+p));
        }
      } else {
        // Standard device: single centred IN port (left) + N stacked OUT ports (right)
        var u0   = dv.inUsed.length ? dv.inUsed[0] : null;
        var inFy = devInY(dv);
        g.appendChild(se('rect',{x:1,y:inFy+FP,width:IN_W-2,height:FH-2*FP,fill:'#3b82f6',opacity:u0?'0.85':'0.25',rx:1}));
        g.appendChild(st({x:IN_W/2,y:inFy+FH/2+4,'font-size':8,'font-weight':'700',fill:'#fff','text-anchor':'middle'},'IN'));
        // OUT port rows — right side, stacked top to bottom
        for (var p = 1; p <= dv.outCount; p++) {
          var fy = devOutY(dv, p);
          var u  = null;
          for (var j = 0; j < dv.outUsed.length; j++) { if (p >= dv.outUsed[j].lo && p <= dv.outUsed[j].hi) { u = dv.outUsed[j]; break; } }
          g.appendChild(se('rect',{x:IN_W+1,y:fy+FP,width:DW-IN_W-2,height:FH-2*FP,fill:'#94a3b8',opacity:u?'0.55':'0.20',rx:1}));
          g.appendChild(st({x:DW-5,y:fy+FH/2+4,'font-size':8,fill:'#fff','text-anchor':'end'},'OUT '+p));
        }
      }
      svg.appendChild(g);
    }

    // ── Wire helpers ─────────────────────────────────────────────
    // bezPath: picks horizontal S-curve (|dx| dominates) or vertical
    // sigmoid (|dy| dominates, e.g. device-below → span-above).
    // Horizontal S: control points at x-midpoint → horizontal tangents.
    // Vertical  S: control points at y-midpoint → vertical  tangents.
    // Bezier path with guaranteed 20px horizontal lead-out at each endpoint.
    // Uses L (line) segments for the leads, then a horizontal S-curve in between.
    // d is capped at 45% of |dx| so the lead endpoints never cross.
    function bezPath(x1, y1, x2, y2) {
      var HPAD = 20;
      var dx = x2 - x1;
      var dirX = dx >= 0 ? 1 : -1;
      var d   = Math.min(HPAD, Math.abs(dx) * 0.45);
      var lx1 = x1 + dirX * d;   // end of start lead
      var lx2 = x2 - dirX * d;   // start of end lead
      var mx = (lx1 + lx2) / 2;
      return 'M'+x1+','+y1+' L'+lx1+','+y1
           + ' C'+mx+','+y1+' '+mx+','+y2+' '+lx2+','+y2
           + ' L'+x2+','+y2;
    }

    // C-curve path: both ends exit in the SAME horizontal direction (e.g. both left).
    // lx1 = stub end at x1 side; lx2 = stub end at x2 side — same dirX as lx1.
    // (bezPath uses lx2 = x2 - dirX*d, the opposite → S-curve.)
    // CP1 at (lx2,y1): horizontal exit at lx1. CP2 tiny offset: clean tangent at lx2.
    function cCurvePath(x1, y1, x2, y2) {
      var dx = x2 - x1, dirX = dx >= 0 ? 1 : -1;
      var d   = Math.min(20, Math.abs(dx) * 0.45);
      var lx1 = x1 + dirX * d;
      var lx2 = x2 + dirX * d;   // same direction → C-curve
      var cp2x = lx2 + dirX * 2; // 2px further so tangent at lx2 matches end stub
      return 'M'+x1+','+y1+' L'+lx1+','+y1
           + ' C'+lx2+','+y1+' '+cp2x+','+y2+' '+lx2+','+y2
           + ' L'+x2+','+y2;
    }
    function drawCurveWire(pathFn, x1, y1, x2, y2, col, sjId) {
      var pd = pathFn(x1, y1, x2, y2);
      var light = col === '#f8f9fa' || col === '#ffc107';
      if (light) _wireHalo(pd, 6, 0.30);
      else svg.appendChild(se('path',{d:pd,fill:'none',stroke:'#000',
        'stroke-width':'6','stroke-opacity':'0.07','stroke-linecap':'round'}));
      var a = {d:pd,fill:'none',stroke:col,
        'stroke-width':'5','stroke-opacity':'0.90','stroke-linecap':'round'};
      if (sjId != null) a['data-sj'] = String(sjId);
      svg.appendChild(se('path', a));
    }

    // Light colours (white #f8f9fa, yellow #ffc107) get a dark halo
    // so they are visible against the light panel background.
    function _wireHalo(d, w, op) {
      svg.appendChild(se('path',{d:d,fill:'none',stroke:'#444',
        'stroke-width':String(w),'stroke-opacity':String(op),'stroke-linecap':'round'}));
    }

    // Pass-through wire: faint 2px for span↔span fibers.
    function passWire(x1, y1, x2, y2, col, sjId) {
      var d = bezPath(x1, y1, x2, y2);
      var light = col === '#f8f9fa' || col === '#ffc107';
      if (light) _wireHalo(d, 6, 0.30);
      else svg.appendChild(se('path',{d:d,fill:'none',stroke:'#000',
        'stroke-width':'6','stroke-opacity':'0.07','stroke-linecap':'round'}));
      var a = {d:d,fill:'none',stroke:col,
        'stroke-width':'5','stroke-opacity':'0.90','stroke-linecap':'round'};
      if (sjId != null) a['data-sj'] = String(sjId);
      svg.appendChild(se('path', a));
    }

    // Device wire: buffered so midpoint-Y spacing can be enforced across all fans.
    // Wires are queued here and drawn in _flushThinWires() after all fans are computed.
    var _thinWireQ = [];
    function thinWire(x1, y1, x2, y2, col, sjId) {
      _thinWireQ.push([x1, y1, x2, y2, col, sjId]);
    }

    // Flush buffered device wires with midpoint-Y separation >= MIN_SEP.
    // Wires sharing the same horizontal corridor (same ~midpoint X bucket) are sorted
    // by natural midpoint Y then spread apart so adjacent wires have >= MIN_SEP gap.
    // The adjusted midpoint is encoded by solving: y(t=0.5) = mY → cy = (8*mY-y1-y2)/6.
    function _flushThinWires() {
      if (!_thinWireQ.length) return;
      var MIN_SEP = 5, HPAD = 20, BW = 30; // BW = bucket width for midpoint-X grouping

      // Pre-compute geometry for each wire
      var wires = _thinWireQ.map(function(w) {
        var x1=w[0],y1=w[1],x2=w[2],y2=w[3],col=w[4],sjId=w[5];
        var dx=x2-x1, dirX=dx>=0?1:-1;
        var d=Math.min(HPAD,Math.abs(dx)*0.45);
        var lx1=x1+dirX*d, lx2=x2-dirX*d, mx=(lx1+lx2)/2;
        return {x1:x1,y1:y1,x2:x2,y2:y2,col:col,sjId:sjId,lx1:lx1,lx2:lx2,mx:mx,mY:(y1+y2)/2};
      });

      // V-shape stagger at both ends: outer wires (furthest from group centre)
      // get the longest horizontal lead-out so they wrap around inner wires
      // and prevent overlapping. Applied at cable end (lx2) AND device end (lx1).
      (function() {
        function vStagger(getX, getY, setLx, lxDir) {
          var grps = {};
          wires.forEach(function(w) {
            var k = Math.round(getX(w));
            if (!grps[k]) grps[k] = [];
            grps[k].push(w);
          });
          Object.keys(grps).forEach(function(k) {
            var grp = grps[k];
            grp.sort(function(a, b) { return getY(a) - getY(b); });
            var n    = grp.length;
            var mid  = (n - 1) / 2;
            var dx   = Math.abs(grp[0].x2 - grp[0].x1);
            var maxD = Math.max(HPAD, dx * 0.40);
            grp.forEach(function(w, i) {
              // frac=1 at edges, 0 at centre → outer wires get maxD
              var frac = (n > 1) ? Math.abs(i - mid) / mid : 0;
              var d    = HPAD + frac * (maxD - HPAD);
              setLx(w, d, lxDir(w));
            });
          });
        }
        // Cable end
        vStagger(
          function(w) { return w.x2; },
          function(w) { return w.y2; },
          function(w, d, dir) { w.lx2 = w.x2 - dir * d; },
          function(w) { return w.x2 >= w.x1 ? 1 : -1; }
        );
        // Device/span end
        vStagger(
          function(w) { return w.x1; },
          function(w) { return w.y1; },
          function(w, d, dir) { w.lx1 = w.x1 + dir * d; },
          function(w) { return w.x2 >= w.x1 ? 1 : -1; }
        );
        // Recompute midpoint X after both ends are updated
        wires.forEach(function(w) { w.mx = (w.lx1 + w.lx2) / 2; });
      }());

      // Group wires that share the same horizontal corridor (≈same midpoint X)
      var groups={}, gKeys=[];
      wires.forEach(function(w) {
        var k=''+Math.round(w.mx/BW)*BW;
        if(!groups[k]){groups[k]=[];gKeys.push(k);}
        groups[k].push(w);
      });

      // Within each group, spread midpoint Ys to ensure >= MIN_SEP between neighbours
      gKeys.forEach(function(k) {
        var g=groups[k];
        g.sort(function(a,b){return a.mY-b.mY;});
        for(var i=1;i<g.length;i++)
          if(g[i].mY-g[i-1].mY<MIN_SEP) g[i].mY=g[i-1].mY+MIN_SEP;
        for(var i=g.length-2;i>=0;i--)
          if(g[i+1].mY-g[i].mY<MIN_SEP) g[i].mY=g[i+1].mY-MIN_SEP;
      });

      // Spread midpoint-X for nearly-vertical wires that share the same corridor.
      // "Nearly vertical" = |dy| > |dx|*2.5.  Offset is symmetric about the
      // group centre (sorted by natural mY): the top wire bulges one way, the
      // bottom the other, the middle stays put.  A monotonic one-direction
      // push would shove the lower wires laterally across the upper ones —
      // making an already-nested fan (sources and targets both in order)
      // cross itself for no reason.  Centring keeps the nesting intact.
      gKeys.forEach(function(k) {
        var g = groups[k];
        var v = g.filter(function(w){ return Math.abs(w.y2-w.y1) > Math.abs(w.x2-w.x1)*2.5; });
        if (v.length < 2) return;
        v.sort(function(a,b){ return a.mY - b.mY; });
        var mid = (v.length - 1) / 2;
        v.forEach(function(w, i) {
          var dirX = (w.x2 >= w.x1) ? 1 : -1;
          var off  = i - mid;          // symmetric: −mid … +mid
          w.mx  += dirX * off * 18;
          // Also nudge lx1/lx2 so the wire fans out from start/end. This
          // is what opens whitespace at the "elbows" (where a horizontal
          // lead meets its curve) — too small and adjacent 5px strokes
          // touch as a neighbour's curve sweeps past.
          w.lx1 += dirX * off * 8;
          w.lx2 += dirX * off * 8;
        });
      });

      // Draw each wire; cy is the symmetric bezier CP such that y(t=0.5) = mY
      wires.forEach(function(w) {
        var yMin=Math.min(w.y1,w.y2), yMax=Math.max(w.y1,w.y2);
        var mY=Math.max(yMin,Math.min(yMax,w.mY)); // clamp inside y1..y2
        var cy=(8*mY-w.y1-w.y2)/6;
        var pd='M'+w.x1+','+w.y1
             +' L'+w.lx1+','+w.y1
             +' C'+w.mx+','+cy+' '+w.mx+','+cy+' '+w.lx2+','+w.y2
             +' L'+w.x2+','+w.y2;
        var light=w.col==='#f8f9fa'||w.col==='#ffc107';
        if(light) _wireHalo(pd,6,0.45);
        else svg.appendChild(se('path',{d:pd,fill:'none',stroke:'#000',
          'stroke-width':'6','stroke-opacity':'0.12','stroke-linecap':'round'}));
        var a={d:pd,fill:'none',stroke:w.col,
          'stroke-width':'5','stroke-opacity':'0.90','stroke-linecap':'round'};
        if(w.sjId!=null) a['data-sj']=String(w.sjId);
        svg.appendChild(se('path', a));
      });
      _thinWireQ=[];
    }

    // ── Paint order (SVG last-on-top rule) ───────────────────────
    // 1. Cable span blocks (left + right)
    lArr.forEach(function(it){ renderSpan(it, 0,     true);  });
    rArr.forEach(function(it){ renderSpan(it, RGT_X, false); });
    // 2. Span→span pass-through: one faint passWire per fiber.
    //    TUBE_GAP in spanRowY() creates natural 12-fiber buffer-tube breaks
    //    without any grouping math — tube boundaries are always respected.
    lArr.forEach(function(lItem) {
      lItem.used.forEach(function(u) {
        if (u.kind !== 'span') return;
        if (u.peer.side !== 'right') return; // same-column pairs handled below
        for (var f = u.lo; f <= u.hi; f++) {
          var pf = u.pLo + (f - u.lo);
          passWire(LW,    spanRowY(lItem,  f)  + FH/2,
                   RGT_X, spanRowY(u.peer, pf) + FH/2, ituC(f), lItem.fiberSj[f]);
        }
      });
    });
    // 2a. Same-column span↔span splices. In a 2/3-column layout a span that
    //     feeds both an inbound (left) and other same-side spans can only sit
    //     in one column, so some span↔span pairs land in the SAME column and
    //     would otherwise draw nothing. Connect those with a C-curve that
    //     bulges into the gutter from the spans' inner edge.
    (function drawSameColumnSplices() {
      var seen = {};
      splices.forEach(function(sj) {
        if (sj.a_target !== 'cable_span' || sj.b_target !== 'cable_span') return;
        var A = leftMap['span-' + sj.a_id + '-' + sj.a_end] || rightMap['span-' + sj.a_id + '-' + sj.a_end];
        var B = leftMap['span-' + sj.b_id + '-' + sj.b_end] || rightMap['span-' + sj.b_id + '-' + sj.b_end];
        if (!A || !B || A.side !== B.side) return; // opposite-column pairs already drawn
        if (seen[sj.id]) return; seen[sj.id] = true;
        // Inner edge + gutter direction for the column the pair sits in.
        var x   = A.side === 'left' ? LW : RGT_X;
        var dir = A.side === 'left' ? 1 : -1;     // bulge toward the centre gutter
        var bulge = 44;
        for (var f = sj.a_lo; f <= sj.a_hi; f++) {
          var pf = sj.b_lo + (f - sj.a_lo);
          var yA = spanRowY(A, f)  + FH / 2;
          var yB = spanRowY(B, pf) + FH / 2;
          var bx = x + dir * bulge;
          var d  = 'M ' + x + ',' + yA
                 + ' C ' + bx + ',' + yA + ' ' + bx + ',' + yB + ' ' + x + ',' + yB;
          drawCurveWire(function(){ return d; }, x, yA, x, yB, ituC(f), sj.id);
        }
      });
    })();
    // 2b. Ribbon-splice ovals — paired ovals at both span edges, colour-matched
    //     by ITU buffer tube of the larger span so left/right ovals share a colour.
    (function drawRibbonOvals() {
      var OX_L = LW + 10;      // left span exit horizontal lead-out
      var OX_R = RGT_X - 10;   // right span entry horizontal lead-out
      function drawOval(item, subLo, subHi, col, ox, sjId) {
        var y1 = spanRowY(item, subLo);
        var y2 = spanRowY(item, subHi) + FH;
        var cy = String((y1+y2)/2), ry = String((y2-y1)/2 + 3);
        var light = col === '#f8f9fa' || col === '#ffc107';
        if (light) {
          // dark outline behind light-coloured oval so it's visible on white bg
          svg.appendChild(se('ellipse', {
            cx: String(ox), cy: cy, rx: '12', ry: String((y2-y1)/2 + 5),
            fill: 'none', stroke: '#555', 'stroke-width': '1', opacity: '0.35'
          }));
        }
        var a = {
          cx: String(ox), cy: cy, rx: '10', ry: ry,
          fill: 'none', stroke: col,
          'stroke-width': '2.5', opacity: '0.75'
        };
        if (sjId != null) a['data-sj'] = String(sjId);
        svg.appendChild(se('ellipse', a));
      }
      splices.forEach(function(sj) {
        if (sj.join_kind !== 'ribbon') return;
        if (sj.a_target !== 'cable_span' || sj.b_target !== 'cable_span') return;
        var aKey = 'span-' + sj.a_id + '-' + sj.a_end;
        var bKey = 'span-' + sj.b_id + '-' + sj.b_end;
        var aIsLeft = !!leftMap[aKey];
        var lItem = aIsLeft ? leftMap[aKey]  : leftMap[bKey];
        var rItem = aIsLeft ? rightMap[bKey] : rightMap[aKey];
        if (!lItem || !rItem) return;
        var lLo = aIsLeft ? sj.a_lo : sj.b_lo, lHi = aIsLeft ? sj.a_hi : sj.b_hi;
        var rLo = aIsLeft ? sj.b_lo : sj.a_lo, rHi = aIsLeft ? sj.b_hi : sj.a_hi;
        // Colour from larger span's tube (bigger cable = authoritative buffer assignment)
        var bigLo = lItem.totalFibers >= rItem.totalFibers ? lLo : rLo;
        var n = Math.max(lHi - lLo, rHi - rLo) + 1;
        for (var i = 0; i * 12 < n; i++) {
          var col = ITU[Math.floor((bigLo + i*12 - 1) / 12) % 12];
          drawOval(lItem, lLo+i*12, Math.min(lLo+i*12+11, lHi), col, OX_L, sj.id);
          drawOval(rItem, rLo+i*12, Math.min(rLo+i*12+11, rHi), col, OX_R, sj.id);
        }
      });
    })();
    // 3. Device blocks — rendered after pass-through ribbons
    dArr.forEach(function(it){ renderDev(it); });
    // ── Tube-separator tick: drawn between buffer-tube groups in the gutter ──
    // A small dashed tick at the gutter midpoint between two tube groups.
    function tubeTick(x1, y1, x2, y2) {
      var cx = (x1 + x2) / 2, cy = (y1 + y2) / 2;
      svg.appendChild(se('line', {
        x1: cx - 10, y1: cy, x2: cx + 10, y2: cy,
        stroke: '#94a3b8', 'stroke-width': '1',
        'stroke-dasharray': '3,2', opacity: '0.7'
      }));
    }

    // ── Wire-fan helper: draw thinWires for a fiber range, with tube ticks ──
    // When cnt > 12 a tick is drawn at every 12-fiber tube boundary.
    function drawFan(x1Fn, y1Fn, x2, y2Fn, lo, hi, idFn) {
      for (var f = lo; f <= hi; f++) {
        thinWire(x1Fn(f), y1Fn(f), x2, y2Fn(f), ituC(f), idFn ? idFn(f) : null);
      }
      // Tube-boundary ticks
      if (hi - lo + 1 > 12) {
        for (var tb = lo + 12; tb <= hi; tb += 12) {
          // y between last fiber of prev tube and first of next
          var yA = y1Fn(tb - 1) + FH/2, yB = y1Fn(tb) + FH/2;
          tubeTick(x1Fn(tb), (yA + yB) / 2, x2, (yA + yB) / 2);
        }
      }
    }

    // 4. Device connections — per-fiber thin bezier lines on top of device blocks
    lArr.forEach(function(lItem) {
      lItem.used.forEach(function(u) {
        if (u.kind !== 'dev-in') return;
        var dv = u.peer;
        if ((dv.isPatchPanel || dv.isMptPassive) && u.pLo) {
          // Patch panel / passive MPT: each fiber routes to its own IN port row (1:1)
          for (var i = 0, n = u.hi - u.lo; i <= n; i++) {
            var f = u.lo + i;
            thinWire(LW, spanRowY(lItem, f) + FH/2,
                     DEV_X, devOutY(dv, u.pLo + i) + FH/2, ituC(f), lItem.fiberSj[f]);
          }
        } else {
          var devY = devInY(dv) + FH / 2;
          drawFan(
            function(f){ return LW; },
            function(f){ return spanRowY(lItem, f) + FH/2; },
            DEV_X, function(){ return devY; },
            u.lo, u.hi,
            function(f){ return lItem.fiberSj[f]; }
          );
        }
      });
    });
    // Right-column spans → device IN.
    // Both the span (exits left) and device-IN left face (exits left) are same-side
    // → use C-curve so it's a single arc, not an S. Both ends get 20px horizontal stubs.
    rArr.forEach(function(rItem) {
      rItem.used.forEach(function(u) {
        if (u.kind !== 'dev-in') return;
        var dv = u.peer;
        if ((dv.isPatchPanel || dv.isMptPassive) && u.pLo) {
          // Patch panel / passive MPT: each fiber routes to its own IN port row (1:1)
          for (var i = 0, n = u.hi - u.lo; i <= n; i++) {
            var f = u.lo + i;
            drawCurveWire(cCurvePath, RGT_X, spanRowY(rItem, f) + FH/2,
                          DEV_X, devOutY(dv, u.pLo + i) + FH/2, ituC(f), rItem.fiberSj[f]);
          }
        } else {
          var devY = devInY(dv) + FH / 2;
          for (var f = u.lo; f <= u.hi; f++) {
            drawCurveWire(cCurvePath, RGT_X, spanRowY(rItem, f) + FH/2, DEV_X, devY, ituC(f), rItem.fiberSj[f]);
          }
        }
      });
    });
    // Device OUT → right-column spans
    rArr.forEach(function(rItem) {
      rItem.used.forEach(function(u) {
        if (u.kind !== 'dev-out') return;
        var dv = u.peer, n = u.hi - u.lo + 1;
        for (var i = 0; i < n; i++) {
          var f = u.lo + i;
          thinWire(DEV_X + DW, devOutY(dv, u.pLo + i) + FH/2,
                   RGT_X, spanRowY(rItem, f) + FH/2, ituC(f), rItem.fiberSj[f]);
        }
        // Tube ticks in dev-out fan
        if (n > 12) {
          for (var tb = 12; tb < n; tb += 12) {
            var f0 = u.lo + tb, yA = spanRowY(rItem, f0 - 1) + FH/2, yB = spanRowY(rItem, f0) + FH/2;
            tubeTick(DEV_X + DW, (yA + yB) / 2, RGT_X, (yA + yB) / 2);
          }
        }
      });
    });

    _flushThinWires(); // draw buffered device wires with midpoint-Y spacing applied

    // ── Patch cable arcs (device-to-device connections) ────────────────────
    var PATCH_X = DEV_X + DW;  // right edge (OUT side) of device column
    var BULGE   = 36;           // pixels to the right for the arc handle
    patchWires.forEach(function(pw) {
      var y1 = devOutY(pw.dvA, pw.portA) + FH / 2;
      var y2 = devOutY(pw.dvB, pw.portB) + FH / 2;
      var d;
      if (pw.dvA === pw.dvB) {
        // Same device: S-curve on the right side
        d = 'M '+PATCH_X+','+y1
          +' C '+(PATCH_X+BULGE)+','+y1+' '+(PATCH_X+BULGE)+','+y2+' '+PATCH_X+','+y2;
      } else {
        // Different devices: right-angle dog-leg connecting out ports
        var xM = PATCH_X + BULGE;
        d = 'M '+PATCH_X+','+y1+' L '+xM+','+y1+' L '+xM+','+y2+' L '+PATCH_X+','+y2;
      }
      var pa = {d:d,fill:'none',stroke:pw.color,
        'stroke-width':'2','stroke-dasharray':'5,3','stroke-linecap':'round',
        opacity:'0.85'};
      if (pw.sjId != null) pa['data-sj'] = String(pw.sjId);
      svg.appendChild(se('path', pa));
    });

    var el = document.getElementById(containerId);
    if (el) {
      el.appendChild(svg);
      _mountOrientation(el, svg, vbX, vbW, SVG_H);
    }
  }

  // ── Orientation ──────────────────────────────────────────────────────────
  // The diagram is laid out vertically throughout: cables are tall blocks, one
  // row per fibre. On a 144F closure that is several thousand pixels of scroll
  // and the two cables you are comparing fall off opposite ends of the screen.
  //
  // Rather than rewrite the layout, the assembled content is rotated as a whole
  // and the viewBox transposed. Labels rotate with it and read bottom-to-top,
  // which is how a rotated fibre chart normally reads - counter-rotating them
  // would put horizontal text in a 14px-wide fibre column, where it does not fit.
  var ORIENT_KEY = 'fpm_splice_diagram_orient';
  var ZOOM_KEY   = 'fpm_splice_diagram_zoom';
  var ZOOM_STEP  = 0.25, ZOOM_MIN = 0.25, ZOOM_MAX = 2;

  function _zoom() {
    try {
      var z = parseFloat(localStorage.getItem(ZOOM_KEY));
      return (z >= ZOOM_MIN && z <= ZOOM_MAX) ? z : 1;
    } catch (e) { return 1; }
  }

  // Zoom by resizing the SVG rather than transform:scale - a transform does not
  // change the layout box, so the container would keep the unscaled scrollbars
  // and clip whatever grew past them.
  function _applyZoom(svg, z) {
    var w = parseFloat(svg.dataset.natW), h = parseFloat(svg.dataset.natH);
    if (!w || !h) return;
    svg.setAttribute('style',
      'width:' + Math.round(w * z) + 'px;height:' + Math.round(h * z) + 'px;display:block');
  }

  function _orient() {
    try { return localStorage.getItem(ORIENT_KEY) === 'v' ? 'v' : 'h'; }
    catch (e) { return 'h'; }
  }

  function _rotate(svg, vbX, vbW, H) {
    // createElementNS, not the render function's se() helper - that one is scoped
    // inside _renderSpliceDiagram and is not visible out here.
    var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('transform', 'translate(0,' + (vbX + vbW) + ') rotate(-90)');
    // Marked so anything drawn after the render - the live-traffic bolts, which
    // arrive from a fetch - can be appended inside the rotation instead of on top
    // of it, where the layout coordinates would mean nothing.
    g.setAttribute('data-sd-rot', '1');
    while (svg.firstChild) g.appendChild(svg.firstChild);
    svg.appendChild(g);
    // Transposed: the old height becomes the width. Sized in pixels rather than
    // width:100% so the fibre rows keep their real size and the container scrolls,
    // instead of a 4000px diagram being squeezed into 900px and becoming a smear.
    svg.setAttribute('viewBox', '0 0 ' + H + ' ' + vbW);
    svg.dataset.natW = H;
    svg.dataset.natH = vbW;
  }

  function _mountOrientation(el, svg, vbX, vbW, H) {
    el.style.overflowX = 'auto';
    if (_orient() === 'h') {
      _rotate(svg, vbX, vbW, H);
    } else {
      svg.dataset.natW = vbW;
      svg.dataset.natH = H;
    }
    _applyZoom(svg, _zoom());

    if (el.querySelector('.sd-orient')) return;

    var bar = document.createElement('div');
    bar.className = 'sd-orient';
    bar.innerHTML =
      '<button type="button" class="sd-orient-btn" data-o="h">Across</button>' +
      '<button type="button" class="sd-orient-btn" data-o="v">Down</button>' +
      '<span class="sd-sep"></span>' +
      '<button type="button" class="sd-zoom-btn" data-z="-1" title="Zoom out">&minus;</button>' +
      '<span class="sd-zoom-val">100%</span>' +
      '<button type="button" class="sd-zoom-btn" data-z="1" title="Zoom in">+</button>' +
      '<button type="button" class="sd-zoom-btn" data-z="0" title="Reset to 100%">Reset</button>';

    // Orientation transposes the layout, so it re-renders; zoom only resizes and
    // is applied in place.
    bar.querySelectorAll('.sd-orient-btn').forEach(function (b) {
      if (b.getAttribute('data-o') === _orient()) b.classList.add('is-on');
      b.addEventListener('click', function () {
        try { localStorage.setItem(ORIENT_KEY, b.getAttribute('data-o')); } catch (e) {}
        location.reload();
      });
    });

    var out = bar.querySelector('.sd-zoom-val');
    function show(z) { out.textContent = Math.round(z * 100) + '%'; }
    show(_zoom());

    bar.querySelectorAll('.sd-zoom-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = parseInt(b.getAttribute('data-z'), 10);
        var z = d === 0 ? 1 : Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, _zoom() + d * ZOOM_STEP));
        try { localStorage.setItem(ZOOM_KEY, String(z)); } catch (e) {}
        // Every diagram on the page, so Actual and Plan stay the same size.
        document.querySelectorAll('svg[data-nat-w]').forEach(function (s) { _applyZoom(s, z); });
        document.querySelectorAll('.sd-zoom-val').forEach(function (o) {
          o.textContent = Math.round(z * 100) + '%';
        });
      });
    });

    el.insertBefore(bar, el.firstChild);
  }

// ── Splice instructions ──────────────────────────────────────────────────────
// The diagram shows what connects to what; this says it in words, in the order a
// splicer works. Built from the same payload as the diagram, so Actual and Plan
// each get their own list and the two can never drift apart.
//
// Rows carry data-sj-id, which is what initSpliceDiagramHover() binds to - so
// hovering an instruction lights its wire in the diagram and vice versa.
function renderSpliceInstructions(containerId, D, baseline) {
  var el = document.getElementById(containerId);
  if (!el) return;
  var spans = D.spans || {}, devs = D.devs || {};
  var rows  = (D.splices || []).slice();
  var had   = rows.length;

  // What a splicer is told to do, not what the closure contains. A pass-through
  // is fibre that is never cut - at a midspan the sheath is opened, the one tube
  // that is wanted comes out, and the other ninety-odd are left alone - so it is
  // not an instruction. Count them instead, because "93 pass through" is the line
  // that stops someone looking for splices that were never meant to be there.
  var passFibres = 0;
  rows = rows.filter(function (r) {
    if (r.join_kind !== 'pass_through') return true;
    passFibres += (r.a_hi - r.a_lo + 1);
    return false;
  });

  // And on the plan layer, only what differs from the ground. A planned splice
  // that already exists is not work.
  if (baseline) {
    var sig = function (r) {
      var a = [r.a_target, r.a_id, r.a_end, r.a_lo, r.a_hi].join(':');
      var b = [r.b_target, r.b_id, r.b_end, r.b_lo, r.b_hi].join(':');
      // A planned cut over a built pass-through is work (butt splice), so kind-of-join is part of it.
      return (r.join_kind === 'pass_through' ? 'P|' : 'S|') + (a < b ? a + '|' + b : b + '|' + a);   // sides are unordered
    };
    var built = {};
    (baseline.splices || []).forEach(function (r) { built[sig(r)] = 1; });
    rows = rows.filter(function (r) { return !built[sig(r)]; });
  }

  var passNote = passFibres
    ? '<span class="sd-dir">' + passFibres
      + (passFibres === 1 ? ' fibre passes' : ' fibres pass') + ' through, not cut</span>'
    : '';

  if (!rows.length) {
    el.innerHTML = '<p class="text-muted small fst-italic mb-0">'
      + (had ? 'Nothing to cut here' + (passFibres ? ' \u2014 ' + passFibres
                 + (passFibres === 1 ? ' fibre passes' : ' fibres pass') + ' through' : '') + '.'
             : 'No splices on this layer yet.')
      + '</p>';
    return;
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function side(target, id, end) {
    if (target === 'cable_span') {
      var sp = spans[id + '-' + end] || {};
      var nm = sp.short_label
               || ((sp.fibre_count ? sp.fibre_count + 'F' : '') || ('span ' + id));
      var to = [];
      if (sp.cable_name)     to.push(sp.cable_name);
      if (sp.far_fosc_label) to.push('\u2192 ' + sp.far_fosc_label);
      if (sp.bearing_text)   to.push(sp.bearing_text);
      return { name: nm, dir: '', title: sp.detail || to.join(' \u00b7 ') };
    }
    var dv = devs[id] || {};
    return { name: dv.name || ('device ' + id), dir: '', title: dv.type_label || '' };
  }

  function rng(lo, hi) { return lo === hi ? ('F' + lo) : ('F' + lo + '\u2013' + hi); }

  // Group by endpoint pair before merging: sorting by fibre alone interleaves
  // runs between different cables, and two halves of one run that are not
  // adjacent in the list never get joined back together.
  function pairKey(r) {
    return r.a_target + r.a_id + r.a_end + '|' + r.b_target + r.b_id + r.b_end;
  }
  rows.sort(function (x, y) {
    var k = pairKey(x).localeCompare(pairKey(y));
    return k || (x.a_lo - y.a_lo) || (x.b_lo - y.b_lo);
  });

  // A splicer reading "F1-48 to F1-48" knows the job; whether that is 48 single
  // fusions or four ribbons is their call, not the plan's. So consecutive joins
  // between the same two endpoints are merged back into the run they describe,
  // and the technique drops into the notes column instead of splitting the work
  // into rows nobody needs to read.
  var merged = [];
  rows.forEach(function (r) {
    var last = merged[merged.length - 1];
    if (last
        && last.a_target === r.a_target && last.a_id === r.a_id && last.a_end === r.a_end
        && last.b_target === r.b_target && last.b_id === r.b_id && last.b_end === r.b_end
        && last.plan === !!r.plan
        && last.a_hi + 1 === r.a_lo && last.b_hi + 1 === r.b_lo) {
      last.a_hi = r.a_hi;
      last.b_hi = r.b_hi;
      last.ids.push(r.id);
      if (last.kinds.indexOf(r.join_kind) < 0) last.kinds.push(r.join_kind);
      if (r.notes && last.notes.indexOf(r.notes) < 0) last.notes.push(r.notes);
      return;
    }
    merged.push({
      a_target: r.a_target, a_id: r.a_id, a_end: r.a_end, a_lo: r.a_lo, a_hi: r.a_hi,
      b_target: r.b_target, b_id: r.b_id, b_end: r.b_end, b_lo: r.b_lo, b_hi: r.b_hi,
      plan: !!r.plan,
      ids: [r.id], kinds: [r.join_kind || 'single'], notes: r.notes ? [r.notes] : []
    });
  });

  // Notes are per-tray ("... (tray 3 of 12)"), so the run-number suffix goes -
  // it described the split we just undid.
  function cleanNote(t) {
    return t.replace(/\s*\((?:tray|part)\s+\d+\s+of\s+\d+\)\s*$/i, '')
            .replace(/\s*\(\d+\s+of\s+\d+\)\s*$/, '')
            .trim();
  }

  // Back into working order once the runs are whole.
  merged.sort(function (x, y) { return (x.a_lo - y.a_lo) || (x.b_lo - y.b_lo); });

  var html = '<table class="table table-sm sd-instr mb-0"><tbody>';
  merged.forEach(function (r, i) {
    var a = side(r.a_target, r.a_id, r.a_end);
    var b = side(r.b_target, r.b_id, r.b_end);
    var n = r.a_hi - r.a_lo + 1;
    var extra = [];
    // A cable spliced onto a panel's pigtails is a run of ribbon (or fusion) splices, not "a connector": the cable
    // is spliced to factory pigtails and the connectors are on the panel. (The join stays kind "connector" in the
    // data: the cabinet pinout page maps panel ports from that kind.)
    var onPanel = (r.a_target === 'fosc_device') !== (r.b_target === 'fosc_device');
    r.kinds.forEach(function (k) {
      if (k === 'connector' && onPanel) {
        var per = n / r.ids.length;
        extra.push(r.ids.length + (per > 1 ? ' ribbon splices' : ' splices'));
      } else extra.push(k);
    });
    var seen = {};
    r.notes.map(cleanNote).forEach(function (t) {
      if (t && !seen[t]) { seen[t] = 1; extra.push(t); }
    });
    html += '<tr class="sj-row" data-sj-id="' + esc(r.ids[0]) + '">'
      + '<td class="sd-n">' + (i + 1) + '</td>'
      + '<td class="sd-ep"' + (a.title ? ' title="' + esc(a.title) + '"' : '') + '>' + esc(a.name)
      + (a.dir ? ' <span class="sd-dir">' + esc(a.dir) + '</span>' : '') + '</td>'
      + '<td class="sd-f">' + esc(rng(r.a_lo, r.a_hi)) + '</td>'
      + '<td class="sd-ar">\u2192</td>'
      + '<td class="sd-ep"' + (b.title ? ' title="' + esc(b.title) + '"' : '') + '>' + esc(b.name)
      + (b.dir ? ' <span class="sd-dir">' + esc(b.dir) + '</span>' : '') + '</td>'
      + '<td class="sd-f">' + esc(rng(r.b_lo, r.b_hi)) + '</td>'
      + '<td class="sd-c">' + n + '</td>'
      + '<td class="sd-x">' + (r.plan && D.mixed ? '<span class="badge bg-warning text-dark me-1">Plan</span>' : '') + esc(extra.join(' \u00b7 ')) + '</td>'
      + '</tr>';
  });

  var fibres = merged.reduce(function (t, r) { return t + (r.a_hi - r.a_lo + 1); }, 0);
  html += '</tbody><tfoot><tr>'
    + '<td colspan="6" class="sd-dir">' + merged.length + (merged.length === 1 ? ' splice' : ' splices')
    + (passNote ? ' \u00b7 ' + passNote : '') + '</td>'
    + '<td class="sd-c">' + fibres + '</td><td class="sd-dir">fibres</td>'
    + '</tr></tfoot></table>';

  el.innerHTML = html;
}

// ── Hover-highlight: link splice table rows ↔ diagram wires ───────────────────
// Generalised from the closure page's inline IIFE. Pass the diagram container
// id(s) to bind; rows are matched by `.sj-row[data-sj-id]` ↔ `[data-sj]` wires.
function initSpliceDiagramHover(containerIds) {
  var DIAGS = containerIds || [];
  function setHl(id, on) {
    if (id == null || id === '') return;
    document.querySelectorAll('[data-sj="' + id + '"]').forEach(function(el) {
      el.classList.toggle('sj-hl', on);
    });
    DIAGS.forEach(function(cid) {
      var svg = document.querySelector('#' + cid + ' svg');
      if (!svg) return;
      // Only dim a diagram that actually contains the matched wires, so the
      // inactive (plan vs actual) view isn't faded to nothing.
      svg.classList.toggle('sj-dimming',
        on && !!svg.querySelector('[data-sj="' + id + '"]'));
    });
    document.querySelectorAll('.sj-row[data-sj-id="' + id + '"]').forEach(function(tr) {
      tr.classList.toggle('sj-hovered', on);
    });
  }
  document.querySelectorAll('.sj-row').forEach(function(tr) {
    var id = tr.getAttribute('data-sj-id');
    tr.addEventListener('mouseenter', function() { setHl(id, true); });
    tr.addEventListener('mouseleave', function() { setHl(id, false); });
  });
  DIAGS.forEach(function(cid) {
    var c = document.getElementById(cid);
    if (!c) return;
    c.addEventListener('mouseover', function(e) {
      var t = e.target.closest && e.target.closest('[data-sj]');
      if (t) setHl(t.getAttribute('data-sj'), true);
    });
    c.addEventListener('mouseout', function(e) {
      var t = e.target.closest && e.target.closest('[data-sj]');
      if (t) setHl(t.getAttribute('data-sj'), false);
    });
  });
}


// ── Hover a fibre on the connection diagram: where does it go? ───────────────
// containers: {containerId: followPlan(bool)}. Asks api/fibre_trace_brief.php and
// shows the closures the fibre reaches, away from this closure and through it.
function initFibreTrace(containers, foscId) {
  var tip = document.createElement('div');
  tip.className = 'sd-fibtip';
  tip.style.cssText = 'position:fixed;z-index:2000;display:none;max-width:420px;background:#fff;'
    + 'border:1px solid #94a3b8;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.2);'
    + 'padding:8px 10px;font-size:12px;line-height:1.35;pointer-events:none';
  document.body.appendChild(tip);
  var cache = {}, timer = null, cur = null;

  function esc(v) { return String(v).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  // One direction: the closure it finally ends at, then only the places it is
  // cut or spliced (pass-through closures are counted, not listed).
  function fmtDist(m) {
    return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : m + ' m';
  }
  function dirBlock(title, hops) {
    var h = '<div style="margin-top:6px;font-weight:600;color:#1e3a5f">' + title + '</div>';
    if (!hops.length) return h + '<div style="color:#64748b">goes no further</div>';
    var last = hops[hops.length - 1];
    var out = h + '<div style="font-size:13px"><strong>Ends at ' + esc(last.fosc) + '</strong> '
      + '<span style="color:#64748b">F' + last.fibre + '</span> \u00b7 ' + fmtDist(last.dist_m) + '</div>';
    var passed = 0, rows = '';
    hops.forEach(function (x, i) {
      if (i === hops.length - 1) return;
      if (x.action === 'through') { passed++; return; }
      rows += '<div>spliced at <strong>' + esc(x.fosc) + '</strong> <span style="color:#64748b">(' + fmtDist(x.dist_m) + ')</span>'
        + (x.next_fibre && x.next_fibre !== x.fibre ? ' <span style="color:#64748b">F' + x.fibre + ' \u2192 F' + x.next_fibre + '</span>' : '')
        + (x.plan ? ' <span style="background:#ffc107;border-radius:3px;padding:0 4px;font-size:10px">Plan</span>' : '')
        + '</div>';
    });
    if (rows) out += rows;
    if (hops.length > 1) out += '<div style="color:#64748b">via ' + (hops.length - 1) + ' closure' + (hops.length - 1 === 1 ? '' : 's')
      + (passed ? ', ' + passed + ' passed straight through' : '') + '</div>';
    if (last.plan) out += '<div><span style="background:#ffc107;border-radius:3px;padding:0 4px;font-size:10px">Plan</span> includes a planned splice</div>';
    var chain = hops.map(function (x) { return esc(x.fosc); }).join(' \u203a ');
    out += '<div style="color:#94a3b8;font-size:11px;margin-top:2px">' + chain + '</div>';
    return out;
  }
  // First few names behind a cut, then how many more. `names` is capped server-side; `total` is the full count.
  function custNames(names, total, tag) {
    if (!names || !names.length) return '';
    var more = total - names.length;
    return '<div style="font-size:12px;margin-top:3px;color:#334155">'
      + names.map(function (n) { return esc(n); }).join(', ')
      + (tag ? ' <span style="color:#64748b">(' + tag + ')</span>' : '')
      + (more > 0 ? ' <span style="color:#64748b">and ' + more + ' more</span>' : '')
      + '</div>';
  }
  function show(d, x, y, head) {
    var c = d.customers, cust = '';
    if (c && c.transport) {
      var tl = c.transport.links || [];
      cust = '<div style="margin:4px 0;padding:5px 7px;background:#fef2f2;border:1px solid #fca5a5;border-radius:4px">'
        + '<strong style="color:#b91c1c">DWDM transport fibre</strong>'
        + (tl.length ? tl.map(function (l) {
            return '<div><strong>' + esc(l.name) + '</strong>'
              + (l.mbps ? ' <span style="color:#64748b">' + (l.mbps >= 1000 ? (l.mbps / 1000) + ' Gbps' : l.mbps + ' Mbps') + '</span>' : '')
              + (l.resilient ? ' <span style="color:#15803d">resilient</span>' : ' <span style="color:#b91c1c">not resilient</span>') + '</div>';
          }).join('') : '<div style="color:#64748b">no logical link recorded for this route</div>')
        + ((c.transport.backups || []).length ? '<div style="color:#64748b">Backup: ' + c.transport.backups.map(esc).join(', ') + '</div>' : '')
        + '<div style="color:#64748b;font-size:11px">A cut takes down the link and what rides it. Customers on it are not counted here.</div>'
        + '</div>';
    } else if (c) {
      var n = c.onus;
      cust = '<div style="margin:4px 0;padding:4px 6px;background:' + (n ? '#fef2f2' : '#f1f5f9') + ';border-radius:4px">'
        + '<strong>Cut here: ' + n + ' customer' + (n === 1 ? '' : 's') + ' down</strong>'
        + ((c.leads || c.services) ? ' <span style="color:#64748b">+ ' + c.leads + ' lead' + (c.leads === 1 ? '' : 's')
            + (c.services ? ', ' + c.services + ' to build' : '') + '</span>' : '')
        + custNames(c.onu_names, c.onus, '')
        + custNames(c.lead_names, c.leads, 'lead')
        + custNames(c.service_names, c.services, 'to build')
        + (c.olt_known ? '' : '<div style="color:#b45309;font-size:11px">no OLT found on this route, counted both sides</div>')
        + '</div>';
    }
    var circ = '', cs = d.circuits || [];
    if (cs.length) {
      var kinds = {p2p: 'P2P', dwdm_channel: 'DWDM channel', pon_feeder: 'PON feeder', transport: 'Transport', other: 'Circuit'};
      circ = '<div style="margin:4px 0;padding:5px 7px;background:#fff7ed;border:1px solid #fdba74;border-radius:4px">'
        + '<strong style="color:#9a3412">' + cs.length + ' circuit' + (cs.length === 1 ? '' : 's') + ' on this fibre</strong>'
        + cs.map(function (x) {
            return '<div><strong>' + esc(x.name) + '</strong> <span style="color:#64748b">' + (kinds[x.kind] || x.kind)
              + (x.customer ? ' \u00b7 ' + esc(x.customer) : '') + (x.mbps ? ' \u00b7 ' + (x.mbps >= 1000 ? (x.mbps / 1000) + ' Gbps' : x.mbps + ' Mbps') : '')
              + (x.status === 'planned' ? ' \u00b7 planned' : '') + '</span>'
              + (x.protect_only ? ' <span style="color:#15803d">(protect path)</span>'
                  : (x.protected_by ? ' <span style="color:#15803d">protected by ' + esc(x.protected_by) + '</span>' : ' <span style="color:#b91c1c">unprotected</span>'))
              + '</div>';
          }).join('') + '</div>';
    }
    tip.innerHTML = '<div style="font-weight:700">' + head + '</div>' + cust + circ + dirBlock('Away from this closure', d.away)
      + dirBlock('Through this closure', d.through);
    tip.style.display = 'block';
    var w = tip.offsetWidth, h = tip.offsetHeight;
    tip.style.left = Math.max(4, Math.min(x + 14, window.innerWidth - w - 8)) + 'px';
    tip.style.top  = Math.max(4, Math.min(y + 14, window.innerHeight - h - 8)) + 'px';
  }

  // Click a fibre to assign it to a circuit (app.circuit); the popup then lists it.
  var panel = document.createElement('div');
  panel.style.cssText = 'position:fixed;z-index:2100;display:none;width:300px;background:#fff;border:1px solid #64748b;'
    + 'border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.28);padding:10px;font-size:12px';
  document.body.appendChild(panel);
  document.addEventListener('mousedown', function (e) { if (!panel.contains(e.target)) panel.style.display = 'none'; });

  function openAssign(t, x, y) {
    tip.style.display = 'none';
    panel.innerHTML = '<span style="color:#64748b">Loading circuits\u2026</span>';
    panel.style.display = 'block';
    panel.style.left = Math.max(4, Math.min(x + 10, window.innerWidth - 320)) + 'px';
    panel.style.top = Math.max(4, Math.min(y + 10, window.innerHeight - 220)) + 'px';
    fetch('api/circuit.php').then(function (r) { return r.json(); }).then(function (d) {
      var opts = (d.circuits || []).filter(function (c) { return c.status !== 'retired'; }).map(function (c) {
        return '<option value="' + c.circuit_id + '">' + esc(c.name) + '</option>'; }).join('');
      panel.innerHTML = '<div style="font-weight:700;margin-bottom:6px">Assign F' + esc(t.dataset.fib) + ' to a circuit</div>'
        + (opts ? '<select id="sdAsCirc" class="form-select form-select-sm mb-1">' + opts + '</select>'
              + '<select id="sdAsRole" class="form-select form-select-sm mb-2"><option value="working">Working path</option><option value="protect">Protect path</option></select>'
              + '<button type="button" id="sdAsGo" class="btn btn-primary btn-sm">Assign</button> '
            : '<div style="color:#64748b;margin-bottom:6px">No circuits yet.</div>')
        + '<a class="btn btn-outline-secondary btn-sm" href="index.php?page=circuits" target="_blank">Manage circuits</a>'
        + '<div id="sdAsMsg" style="margin-top:6px"></div>';
      var go = document.getElementById('sdAsGo');
      if (go) go.onclick = function () {
        fetch('api/circuit.php?id=' + document.getElementById('sdAsCirc').value + '&action=add_fibre', {
          method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window._csrf},
          body: JSON.stringify({span: t.dataset.span, fibre: t.dataset.fib, role: document.getElementById('sdAsRole').value})
        }).then(function (r) { return r.json(); }).then(function (r) {
          if (!r.ok) throw new Error(r.error || 'Failed');
          cache = {};
          document.getElementById('sdAsMsg').innerHTML = '<span style="color:#15803d">Assigned.</span>';
          setTimeout(function () { panel.style.display = 'none'; }, 700);
        }).catch(function (e) { document.getElementById('sdAsMsg').innerHTML = '<span style="color:#b91c1c">' + esc(e.message) + '</span>'; });
      };
    }).catch(function () { panel.innerHTML = '<span style="color:#b91c1c">Could not load circuits</span>'; });
  }

  Object.keys(containers).forEach(function (cid) {
    var el = document.getElementById(cid);
    if (!el) return;
    var plan = containers[cid] ? 1 : 0;
    el.addEventListener('mouseover', function (e) {
      var t = e.target.closest && e.target.closest('.sd-fib');
      if (!t || t === cur) return;
      cur = t; clearTimeout(timer);
      var x = e.clientX, y = e.clientY;
      var key = [t.dataset.span, t.dataset.fib, t.dataset.end, plan].join(':');
      var head = 'Fibre F' + esc(t.dataset.fib);
      timer = setTimeout(function () {
        if (cache[key]) { show(cache[key], x, y, head); return; }
        tip.innerHTML = '<span style="color:#64748b">Tracing F' + esc(t.dataset.fib) + '\u2026</span>';
        tip.style.display = 'block'; tip.style.left = (x + 14) + 'px'; tip.style.top = (y + 14) + 'px';
        fetch('api/fibre_trace_brief.php?span=' + t.dataset.span + '&fibre=' + t.dataset.fib
              + '&end=' + t.dataset.end + '&plan=' + plan + '&fosc_id=' + (foscId || 0))
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) throw new Error(d.error || 'failed');
            cache[key] = d;
            if (cur === t) show(d, x, y, head);
          })
          .catch(function () { if (cur === t) tip.innerHTML = '<span style="color:#b91c1c">Trace failed</span>'; });
      }, 250);
    });
    el.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('.sd-fib');
      if (t) { clearTimeout(timer); openAssign(t, e.clientX, e.clientY); }
    });
    el.addEventListener('mouseout', function (e) {
      var t = e.target.closest && e.target.closest('.sd-fib');
      if (!t) return;
      clearTimeout(timer); cur = null; tip.style.display = 'none';
    });
  });
}
