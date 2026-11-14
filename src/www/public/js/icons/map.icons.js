/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
// icons/map.icons.js — SVG icon factories for the Tinuum network map.
// Loaded as a plain script (no IIFE) so map.helper.js can call these as globals.
// All functions return a Leaflet DivIcon or an SVG HTML string.

// Which slack-loop artwork to draw. 'omega' is the current symbol: a coil bulging
// off one side of the cable, turned to the bearing of the span it sits on. Flip to
// 'dogbone' to get the older flat two-ellipse glyph back — it ignores the angle, so
// the rotation plumbing in map.helper.js goes inert and nothing else need change.
const SLACK_LOOP_GLYPH = 'omega';   // 'omega' | 'dogbone'

// ── U-Guard ───────────────────────────────────────────────────────────────────

function uguardStatusColor(status) {
  switch (status) {
    case 'active':       return '#22c55e'; // green
    case 'construction': return '#f59e0b'; // amber
    case 'plan':         return '#94a3b8'; // slate
    case 'retired':      return '#6b7280'; // grey
    default:             return '#94a3b8';
  }
}

function uguardIcon(status, scale = 1) {
  return symbolIcon('circle', 'uguard', uguardStatusColor(status), status === 'plan', Math.round(20 * scale), 'uguard-marker-icon');
}

// ── Symbol set ───────────────────────────────────────────────────────────────
// One recipe for every map symbol other than poles and strand: an outline shape says
// what kind of thing it is (circle = hardware on a pole or in the line, square = a
// box, triangle = work to do, diamond = information, house = on a building), a glyph
// says which one, the colour says status or owner, and the fill says built (solid)
// or planned (white with a dashed edge). Anchors use the same recipe.
function symShape(shape, fill, c, dash) {
  const a = `fill="${fill}" stroke="${c}" stroke-width="2" ${dash ? 'stroke-dasharray="3 2"' : ''}`;
  switch (shape) {
    case 'square':   return `<rect x="-9.5" y="-9.5" width="19" height="19" rx="2.5" ${a}/>`;
    case 'triangle': return `<path d="M0 -10.5 L11 8.5 H-11 Z" ${a} stroke-linejoin="round"/>`;
    case 'diamond':  return `<path d="M0 -11 L11 0 L0 11 L-11 0 Z" ${a} stroke-linejoin="round"/>`;
    case 'hexagon':  return `<path d="M-10 0 L-5 -9 H5 L10 0 L5 9 H-5 Z" ${a} stroke-linejoin="round"/>`;
    case 'house':    return `<path d="M-10 -1 L0 -10.5 L10 -1 V9.5 H-10 Z" ${a} stroke-linejoin="round"/>`;
    default:         return `<circle r="10" ${a}/>`;
  }
}

function symGlyph(name, ink) {
  const g = (inner, dy = 0, sw = 1.7) =>
    `<g transform="translate(0 ${dy})" stroke="${ink}" stroke-width="${sw}" fill="none" stroke-linecap="round" stroke-linejoin="round">${inner}</g>`;
  switch (name) {
    case 'uguard':
      return `<text x="0" y="3" font-family="Arial, Helvetica, sans-serif" font-size="8.6" font-weight="700" text-anchor="middle" fill="${ink}" stroke="none">UG</text>`;
    case 'closure':
      return g(`<g transform="translate(-10.4 -4.3) scale(.62)" stroke-width="2.6"><path d="M9.5 3.1 Q5 3.5 5 7 Q5 10.5 9.5 11 Z"/><rect x="8" y="3.1" width="13" height="7.9" rx="1.1"/><path d="M11.2 3.6 V10.5 M14.5 3.6 V10.5 M17.8 3.6 V10.5" opacity=".7"/><rect x="20.5" y="6.2" width="6.8" height="1.6" rx=".5"/><rect x="26.5" y="5.5" width="2" height="3" rx=".6"/></g>`);
    case 'slack':
      // A bold, flat figure eight (option C2b): spare cable laid out in two loops.
      return g('<path d="M0 0 C-2 -3.64 -7.4 -3.64 -7.4 0 C-7.4 3.64 -2 3.64 0 0 C2 -3.64 7.4 -3.64 7.4 0 C7.4 3.64 2 3.64 0 0"/>', 0, 2.3);
    case 'vault':
      return g('<rect x="-6" y="-4" width="12" height="9" rx="1"/><path d="M-6 -1 H6 M-2 -4 V-1 M2 -4 V-1"/>', 0.5);
    case 'hand':
      return g('<path d="M-7.2 -4 V4"/><path d="M-5.6 -2.6 H0 M-5.6 3 H0"/><path d="M0 -2.6 H5.8 M0 -0.6 H6.8 M0 1.4 H6.4 M0 3 H5" stroke-width="1.5"/><path d="M-3 -2.6 L-0.4 -5.8 L1.2 -4.8 L0 -2.6"/>');
    case 'rope':
      return g('<circle cx="-0.5" cy="-0.5" r="5.2"/><circle cx="-0.5" cy="-0.5" r="2.4"/><path d="M4.4 3 Q7 5 4.5 7"/>');
    case 'onu':
      return g(`<rect x="-5.5" y="-4.5" width="11" height="7" rx="1.2"/><circle cx="-2.6" cy="-1" r=".8" fill="${ink}"/><circle cx="0" cy="-1" r=".8" fill="${ink}"/><circle cx="2.6" cy="-1" r=".8" fill="${ink}"/><path d="M0 2.5 V6.5"/>`, 0.5);
    case 'tree':
      return g(`<path d="M0 -3 L-3 1 H3 Z M0 0 L-4 4.5 H4 Z" fill="${ink}"/><path d="M0 4.5 V7"/>`, 1.5);
    case 'barrier':
      return g('<path d="M-5 -1 H5 M-5 3 H5 M-4 -1 V6 M4 -1 V6"/>', 1.5);
    case 'pin':
      return g('<circle cx="0" cy="-2.5" r="3"/><path d="M0 0.5 V6"/>');
    case 'mast':
      return g('<path d="M0 -3 V6 M-2.6 -0.6 H2.6 M-5 3 Q-4 7 0 7 Q4 7 5 3"/>', 1);
    default:
      return '';
  }
}

// Inner SVG of one symbol, centred on 0,0, about 22 units across.
function symInner(shape, glyph, color, planned) {
  return symShape(shape, planned ? '#ffffff' : color, color, planned) + symGlyph(glyph, planned ? color : '#ffffff');
}

function symbolIcon(shape, glyph, color, planned, px, className, opts = {}) {
  const stroke = opts.highlight ? '#ffc107' : null;
  const filter = opts.highlight ? 'drop-shadow(0 0 3px #ffc107)' : 'drop-shadow(0 1px 1px rgba(0,0,0,0.45))';
  const inner = stroke
    ? symShape(shape, planned ? '#ffffff' : color, stroke, planned) + symGlyph(glyph, planned ? color : '#ffffff')
    : symInner(shape, glyph, color, planned);
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${px}" height="${px}" viewBox="-12 -12 24 24" style="filter:${filter}">${inner}</svg>`,
    iconSize:    [px, px],
    iconAnchor:  [Math.round(px / 2), opts.baseAnchor ? px : Math.round(px / 2)],
    popupAnchor: [0, -Math.round(px / 2) - 2],
    className,
  });
}

// Callout: a symbol set up and to the right of its true point, joined by a leader to a small dot
// that marks the position (the same device as the note icons), so the symbol stays readable
// where several things share one spot. `lead` (0..1) shortens the leader as the map zooms out;
// at 0 the symbol sits straight on its point.
function calloutLead() {
  return (typeof window !== 'undefined' && typeof window.tinNoteLead === 'function') ? window.tinNoteLead() : 1;
}

function calloutIcon(shape, glyph, color, planned, symPx, lead, className, opts = {}) {
  lead = Math.max(0, Math.min(1, lead));
  const k = symPx / 22;
  const R = symPx / 2 + 1;                       // symbol half-extent including its stroke
  // The leader runs at right angles to the cable (opts.angle is the cable's screen angle in CSS
  // degrees, clockwise from +x), toward the top of the screen so the symbol clears the strand
  // and pole. 0 / unknown: straight up.
  const a = (opts.angle || 0) * Math.PI / 180;
  const ux = Math.sin(a), uy = -Math.cos(a);
  const dist = lead > 0 ? (R + 22 * lead) : 0;   // dot to symbol centre
  const Cx = ux * dist, Cy = uy * dist;
  const minX = Math.min(-3.5, Cx - R - 1), maxX = Math.max(3.5, Cx + R + 1);
  const minY = Math.min(-3.5, Cy - R - 1), maxY = Math.max(3.5, Cy + R + 1);
  const w = Math.ceil(maxX - minX), h = Math.ceil(maxY - minY);
  const ox = -minX, oy = -minY;                  // where the true point sits inside the svg
  const inner = opts.highlight
    ? symShape(shape, planned ? '#ffffff' : color, '#ffc107', planned) + symGlyph(glyph, planned ? color : '#ffffff')
    : symInner(shape, glyph, color, planned);
  const filter = opts.highlight ? 'drop-shadow(0 0 3px #ffc107)' : 'drop-shadow(0 1px 1px rgba(0,0,0,0.45))';
  const leader = lead > 0
    ? `<line x1="${ox}" y1="${oy}" x2="${ox + Cx - ux * R}" y2="${oy + Cy - uy * R}" stroke="#334155" stroke-width="1.4" stroke-linecap="round" opacity="0.85"/>` +
      `<circle cx="${ox}" cy="${oy}" r="2.6" fill="#334155" stroke="#fff" stroke-width="1"/>`
    : '';
  const icon = L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="filter:${filter}">
             ${leader}
             <g transform="translate(${ox + Cx} ${oy + Cy}) scale(${k})">${inner}</g>
           </svg>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(ox), Math.round(oy)],
    popupAnchor: [Math.round(Cx), Math.round(Cy - R - 2)],
    className,
  });
  // The damaged cross sits on the symbol, not in the middle of the box.
  icon.options.tinCross = { x: ox + Cx, y: oy + Cy, size: Math.round(symPx * 0.45) };
  icon.options.tinCallout = { cx: Cx, cy: Cy, r: R };   // so a label can sit beside the symbol, not the dot
  return icon;
}

// ── Anchor ───────────────────────────────────────────────────────────────────

function anchorStatusColor(status) {
  switch (status) {
    case 'active':       return '#22c55e'; // green
    case 'construction': return '#f59e0b'; // amber
    case 'plan':         return '#94a3b8'; // slate
    case 'retired':      return '#6b7280'; // grey
    default:             return '#94a3b8';
  }
}

// Owner colour as on the pole cross arm (Bell blue, Tinuum green, ...); status shows as
// fill: solid = built or being built, hollow dashed = planned, grey = retired.
function anchorIcon(status, scale = 1, owner = '') {
  const c = status === 'retired' ? '#6b7280' : poleOwnerColor(owner);
  const planned = status === 'plan';
  const s = Math.round(22 * scale);
  const fill = planned ? '#ffffff' : c;
  const ink  = planned ? c : '#ffffff';
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="-12 -12 24 24" style="filter:drop-shadow(0 1px 1px rgba(0,0,0,0.45))">
             <circle r="10" fill="${fill}" stroke="${c}" stroke-width="2"${planned ? ' stroke-dasharray="3 2"' : ''}/>
             <g stroke="${ink}" stroke-width="1.8" fill="none" stroke-linecap="round">
               <path d="M0 -6 V6 M-3 -3 H3 M-6 2 Q-5 7 0 7 Q5 7 6 2"/>
             </g>
           </svg>`,
    iconSize:    [s, s],
    iconAnchor:  [Math.round(s / 2), Math.round(s / 2)],
    popupAnchor: [0, -Math.round(s / 2) - 2],
    className:   'anchor-marker-icon',
  });
}

// ── Pole ─────────────────────────────────────────────────────────────────────

function poleOwnerColor(owner) {
  const o = (owner || '').toLowerCase();
  if (o.includes('nb power') || o.includes('nbpower')) return '#f97316'; // orange
  if (o.includes('bell'))                               return '#3b82f6'; // blue
  if (o.includes(((window.ORG && window.ORG.ownName) || 'own network').toLowerCase())) return '#198754'; // own network: green
  if (o.includes('private'))                            return '#a855f7'; // purple — customer/private
  if (o === '')                                         return '#8a6535'; // no owner — neutral brown
  return '#ef4444';                                                       // everyone else — red
}

function poleIcon(owner, scale = 1) {
  const armColor = poleOwnerColor(owner);
  const w = Math.round(16 * scale), h = Math.round(24 * scale);
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 16 24">
             <line x1="8" y1="24" x2="8" y2="1" stroke="#5c4220" stroke-width="2.5" stroke-linecap="round"/>
             <line x1="1"  y1="6" x2="15" y2="6" stroke="${armColor}" stroke-width="2"   stroke-linecap="round"/>
             <circle cx="1.5"  cy="6" r="1.8" fill="${armColor}"/>
             <circle cx="14.5" cy="6" r="1.8" fill="${armColor}"/>
           </svg>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(w / 2), h],
    popupAnchor: [0, -h],
    className:   'pole-marker-icon',
  });
}

// Selected variant — bright yellow halo circle so selection is obvious at any zoom
function poleIconSelected(owner, scale = 1) {
  const armColor = poleOwnerColor(owner);
  const w = Math.round(16 * scale), h = Math.round(24 * scale);
  // Extra padding around the icon for the halo ring
  const pw = w + 12, ph = h + 12;
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${pw}" height="${ph}" viewBox="-6 -6 28 36">
             <circle cx="8" cy="12" r="13" fill="#fef08a" stroke="#f59e0b" stroke-width="2.5" opacity="0.9"/>
             <line x1="8" y1="24" x2="8" y2="1" stroke="#5c4220" stroke-width="2.5" stroke-linecap="round"/>
             <line x1="1"  y1="6" x2="15" y2="6" stroke="${armColor}" stroke-width="2"   stroke-linecap="round"/>
             <circle cx="1.5"  cy="6" r="1.8" fill="${armColor}"/>
             <circle cx="14.5" cy="6" r="1.8" fill="${armColor}"/>
           </svg>`,
    iconSize:    [pw, ph],
    iconAnchor:  [Math.round(pw / 2), ph],
    popupAnchor: [0, -ph],
    className:   'pole-marker-icon pole-marker-icon--selected',
  });
}

// Snap-target variant — teal ring shown when cursor is close during strand/route add
function poleIconSnap(owner, scale = 1) {
  const armColor = poleOwnerColor(owner);
  const w = Math.round(16 * scale), h = Math.round(24 * scale);
  const pw = w + 12, ph = h + 12;
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${pw}" height="${ph}" viewBox="-6 -6 28 36">
             <circle cx="8" cy="12" r="13" fill="#ccfbf1" stroke="#0d9488" stroke-width="2.5" opacity="0.92"/>
             <line x1="8" y1="24" x2="8" y2="1" stroke="#5c4220" stroke-width="2.5" stroke-linecap="round"/>
             <line x1="1"  y1="6" x2="15" y2="6" stroke="${armColor}" stroke-width="2"   stroke-linecap="round"/>
             <circle cx="1.5"  cy="6" r="1.8" fill="${armColor}"/>
             <circle cx="14.5" cy="6" r="1.8" fill="${armColor}"/>
           </svg>`,
    iconSize:    [pw, ph],
    iconAnchor:  [Math.round(pw / 2), ph],
    popupAnchor: [0, -ph],
    className:   'pole-marker-icon pole-marker-icon--snap',
  });
}

// Alden One staging pole (import candidate). Same shape as a normal pole, but the
// trunk is PURPLE — purple is the Alden One identity colour throughout the map —
// while the cross-arm keeps the owner colour (blue Bell / orange NB Power) so
// ownership still reads at a glance. Linked (already-imported) ones render muted.
const ALDEN_PURPLE = '#9333ea';
function aldenPoleIcon(owner, scale = 1, linked = false) {
  const armColor = poleOwnerColor(owner);
  const w = Math.round(16 * scale), h = Math.round(24 * scale);
  const op = linked ? 0.45 : 1;
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 16 24" opacity="${op}">
             <line x1="8" y1="24" x2="8" y2="1" stroke="${ALDEN_PURPLE}" stroke-width="2.5" stroke-linecap="round"/>
             <line x1="1"  y1="6" x2="15" y2="6" stroke="${armColor}" stroke-width="2"   stroke-linecap="round"/>
             <circle cx="1.5"  cy="6" r="1.8" fill="${armColor}"/>
             <circle cx="14.5" cy="6" r="1.8" fill="${armColor}"/>
           </svg>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(w / 2), h],
    popupAnchor: [0, -h],
    className:   'alden-pole-marker-icon',
  });
}

// ── Toolbar icons ─────────────────────────────────────────────────────────────

function conduitToolbarIconHtml() {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
    <path d="M2 12 L14 4" stroke="#b45309" stroke-width="2.8" stroke-linecap="round" fill="none" stroke-dasharray="5 3"/>
  </svg>`;
}

function spanToolbarIconHtml() {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
    <path d="M2 12 L14 4" stroke="#0d6efd" stroke-width="2.4" stroke-linecap="round" fill="none"/>
    <circle cx="2.2" cy="12" r="1.5" fill="#0d6efd"/>
    <circle cx="13.8" cy="4" r="1.5" fill="#0d6efd"/>
  </svg>`;
}

function labelsToolbarIconHtml() {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
    <rect x="1.2" y="3.2" width="13.6" height="9.6" rx="2" fill="#ffffff" stroke="#333" stroke-width="1"/>
    <text x="8" y="10.2" text-anchor="middle" font-size="5.1" font-weight="700" fill="#222">R01</text>
  </svg>`;
}

// ── Closure / FOSC markers ────────────────────────────────────────────────────

function foscIcon(color, highlight, scale = 1, planned = false, lead = null, angle = 0) {
  return calloutIcon('circle', 'closure', color, planned, Math.round(16 * scale), lead == null ? calloutLead() : lead, 'fosc-marker-icon', { highlight, angle });
}

function optitapIcon(color, highlight, scale = 1) {
  const stroke = highlight ? '#ffc107' : '#ffffff';
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 1px 2px rgba(0,0,0,0.4))';
  const s = Math.round(5 * scale);
  // Chamfered-corner square body (TR keying chamfer) — exact connector face outline
  const body = `M53.8,522 C53.8,434.7 54,347.8 53.7,261 C53.6,237.5 61.3,217.9 77.9,201.3 C124.5,154.6 171,107.7 217.6,61 C231.8,46.7 249.7,40.2 269.5,40.1 C432.3,39.9 595.2,39.9 758,40.1 C774,40.1 788.4,45.7 799.9,57.2 C848.2,105.6 896.5,153.9 944.7,202.3 C959.9,217.6 967.4,235.9 967.4,258 C967.1,460.5 967.2,663 967.2,865.5 C967.2,901.4 939.6,934.7 904.3,941.1 C891.9,943.3 879.4,942.9 866.9,942.8 C835.8,942.7 804.6,942.4 773.5,942.4 C599.2,942.4 424.8,942.4 250.5,942.4 C211,942.4 171.5,942.2 132,942.5 C113.7,942.7 96.5,939.8 81.8,928.1 C64.1,914 54,895.7 53.9,873 C53.7,756.2 53.8,639.3 53.8,522 Z`;
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="50 37 921 909" style="filter:${filter}">
             <path d="${body}" fill="#ffffff"/>
             <path d="${body}" fill="${color}" stroke="${stroke}" stroke-width="80" stroke-linejoin="round"/>
             <circle cx="510" cy="491" r="260" fill="none" stroke="${stroke}" stroke-width="70"/>
             <circle cx="510" cy="491" r="85"  fill="${stroke}"/>
           </svg>`,
    iconSize:    [s, s],
    iconAnchor:  [Math.round(s / 2), Math.round(s / 2)],
    popupAnchor: [0, -Math.round(s / 2)],
    className:   'fosc-marker-icon',
  });
}

function mstIcon(color, highlight, scale = 1) {
  const stroke = highlight ? '#ffc107' : 'rgba(255,255,255,0.9)';
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 1px 2px rgba(0,0,0,0.4))';
  const s = Math.round(14 * scale);
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 14 14"
                style="filter:${filter}">
             <path d="M7,1 L13,7 L7,13 L1,7 Z" fill="${color}" stroke="${stroke}" stroke-width="1.5" stroke-linejoin="round"/>
             <line x1="7"   y1="3.5" x2="7"   y2="7"  stroke="${stroke}" stroke-width="1.3" stroke-linecap="round" opacity="0.9"/>
             <line x1="7"   y1="7"   x2="4.5" y2="10" stroke="${stroke}" stroke-width="1.3" stroke-linecap="round" opacity="0.9"/>
             <line x1="7"   y1="7"   x2="9.5" y2="10" stroke="${stroke}" stroke-width="1.3" stroke-linecap="round" opacity="0.9"/>
           </svg>`,
    iconSize:    [s, s],
    iconAnchor:  [Math.round(s / 2), Math.round(s / 2)],
    popupAnchor: [0, -Math.round(s / 2)],
    className:   'fosc-marker-icon',
  });
}

function cabinetIcon(color, highlight, scale = 1) {
  const stroke = highlight ? '#ffc107' : 'rgba(255,255,255,0.9)';
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 1px 2px rgba(0,0,0,0.5))';
  // viewBox 22×20: outer box with two doors, handles, base bar + two feet
  const s = Math.round(18 * scale);
  const h = Math.round(s * 20 / 22);
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${h}" viewBox="0 0 22 20"
                style="filter:${filter}">
             <!-- Cabinet body -->
             <rect x="1" y="1" width="20" height="14" rx="2"
                   fill="${color}" stroke="${stroke}" stroke-width="1.3"/>
             <!-- Centre door divider -->
             <line x1="11" y1="1.5" x2="11" y2="15" stroke="${stroke}" stroke-width="1"/>
             <!-- Left door handle (pill) -->
             <rect x="7" y="7.5" width="2" height="3.5" rx="1"
                   fill="${stroke}" opacity="0.85"/>
             <!-- Right door handle (pill) -->
             <rect x="13" y="7.5" width="2" height="3.5" rx="1"
                   fill="${stroke}" opacity="0.85"/>
             <!-- Base bar -->
             <rect x="1" y="14" width="20" height="2.5"
                   fill="${color}" stroke="${stroke}" stroke-width="1"/>
             <!-- Left foot -->
             <rect x="3" y="16.5" width="4" height="2.5" rx="0.5"
                   fill="${color}" stroke="${stroke}" stroke-width="0.9"/>
             <!-- Right foot -->
             <rect x="15" y="16.5" width="4" height="2.5" rx="0.5"
                   fill="${color}" stroke="${stroke}" stroke-width="0.9"/>
           </svg>`,
    iconSize:    [s, h],
    iconAnchor:  [Math.round(s / 2), Math.round(h / 2)],
    popupAnchor: [0, -Math.round(h / 2)],
    className:   'fosc-marker-icon',
  });
}

// The pre-omega slack symbol, kept whole behind SLACK_LOOP_GLYPH so the change can
// be backed out from one line. Takes `angle` only to match the omega signature; a
// flat dog-bone has nothing to point at.
function slackLoopIconDogbone(color, highlight, scale = 1, angle = 0) {   // eslint-disable-line no-unused-vars
  const outline = highlight ? '#ffc107' : 'rgba(255,255,255,0.88)';
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 1px 2px rgba(0,0,0,0.4))';
  // Dog-bone: two flat ellipses joined by a centre line (DB-C3, ry=1.4)
  const w = Math.round(13.5 * scale), h = Math.round(2.85 * scale);
  // Same .slack-glyph wrapper and class as the omega, so both variants hit-test and
  // inspect identically and the switch is a true drop-in.
  return L.divIcon({
    html: `<div class="slack-glyph" style="width:${w}px;height:${h}px">
             <svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 18 3.8"
                  style="filter:${filter}">
               <ellipse cx="4"  cy="1.9" rx="3.6" ry="1.4" fill="${color}" fill-opacity="0.2" stroke="${outline}" stroke-width="1.3"/>
               <ellipse cx="14" cy="1.9" rx="3.6" ry="1.4" fill="${color}" fill-opacity="0.2" stroke="${outline}" stroke-width="1.3"/>
               <line x1="7.0" y1="1.9" x2="11.0" y2="1.9" stroke="${outline}" stroke-width="1.8"/>
             </svg>
           </div>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(w / 2), Math.round(h / 2)],
    popupAnchor: [0, -Math.round(h / 2)],
    className:   'fosc-marker-icon fosc-marker-icon--slack',
  });
}

// Slack loop: a coil bulging off ONE side of the cable, turned to the bearing of
// the span it sits on (angle from slackGlyphAngle() in map.helper.js). The cable is
// the span polyline underneath — this glyph adds only the stored slack, so the run
// still reads continuous and nothing knocks a gap in it. That is how slack is drawn
// on an as-built, and it can't be mistaken for a closure at a glance.
//
// Units below are px at scale 1, origin (0,0) ON the cable at the closure's true
// position, cable along +x, bulge toward -y:
//   loop    circle centre (0,-8.6) r 4.6, open between ±62° of straight down
//   feet    y = 0, from x = ±2 to ±4.4
//   viewBox -7 -16.5 14 18.5, so the origin lands at (7, 16.5) — the iconAnchor
//
// Sized to clear the casing without shouting. spanWeight caps at 16 ⇒ 8 px
// half-width, and below DENSITY_ZOOM there are no markers at all, so the smallest
// scale a slack loop is ever drawn at is 1.8. The box renders 9 px wide per unit
// scale ⇒ 0.643 px per viewBox unit, so the apex (14.9 units out) sits 17 px off
// the centreline at s=1.8 and 24 px at s=2.5 — still clear of even the 16 px cap,
// while staying the size of a closure rather than twice it. Drawn any bigger it
// reads as the main feature of the span instead of an annotation on it.
// The loop's BOTTOM stays inside a thick casing on purpose: the feet run into the
// cable rather than floating above it.
//
// The loop path is left open and filled — SVG closes it implicitly with the chord
// along the cable, so the tint lands on the loop interior and nowhere else.
const SLACK_LOOP_PATH =
  'M -2 0 C -2.75 -3 -2.93 -4.32 -4.06 -6.44 ' +
  'A 4.6 4.6 0 1 1 4.06 -6.44 ' +
  'C 2.93 -4.32 2.75 -3 2 0';
const SLACK_FEET_PATH = 'M -4.4 0 L -2 0 M 2 0 L 4.4 0';

function slackLoopIconOmega(color, highlight, scale = 1, angle = 0) {
  const outline = highlight ? '#ffc107' : 'rgba(255,255,255,0.92)';
  // A symmetric glow rather than the usual directional 0 1px 2px: this glyph
  // rotates, and a directional shadow turning with it reads as the sun spinning
  // around the map.
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 0 2px rgba(0,0,0,0.45))';
  const w = Math.round(9 * scale), h = Math.round(11.9 * scale);
  const ax = Math.round(w / 2), ay = Math.round(10.6 * scale);

  // Halo under, core over. The glyph sits on top of the span casing, where a lone
  // dark stroke disappears — and a lone white one disappears on a light basemap.
  // The halo is on the loop only, never the feet, so no white gap is painted across
  // the cable.
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="-7 -16.5 14 18.5"
          style="filter:${filter}">
       <path d="${SLACK_FEET_PATH}" fill="none" stroke="${color}"
             stroke-width="1.5" stroke-linecap="round"/>
       <path d="${SLACK_LOOP_PATH}" fill="none" stroke="${outline}"
             stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
       <path d="${SLACK_LOOP_PATH}" fill="${color}" fill-opacity="0.18" stroke="${color}"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
     </svg>`;

  // The rotation goes on a plain DIV, never on the <svg>: transform-box defaults to
  // view-box for SVG elements, so a px transform-origin there would be read in
  // viewBox user units (14 wide) instead of element px. A div is border-box.
  // iconSize/iconAnchor stay the UNROTATED box and never vary with the angle — the
  // turn happens inside that box about the anchor — so Leaflet's positioning,
  // getLatLng(), dragging and the move-fosc flow are all untouched.
  const rot = angle
    ? `transform:rotate(${angle.toFixed(1)}deg);transform-origin:${ax}px ${ay}px;`
    : '';

  const icon = L.divIcon({
    html: `<div class="slack-glyph" style="width:${w}px;height:${h}px;${rot}">${svg}</div>`,
    iconSize:    [w, h],
    iconAnchor:  [ax, ay],
    popupAnchor: [0, -Math.round(6 * scale)],
    className:   'fosc-marker-icon fosc-marker-icon--slack',
  });
  // Where a damaged ❌ belongs on this icon: the closure's true point, not the box
  // centre — the loop rotates away from that — and sized to the loop, not the tall
  // box. foscIconForKind reads this; every other icon has no opinion and gets the
  // box centre as before.
  icon.options.tinCross = { x: ax, y: ay, size: Math.round(7 * scale) };
  return icon;
}

// Slack loop: spare fibre stored in a loop. A hexagon with the loop glyph; it no longer
// turns to the cable's bearing, so it needs no special hit-testing.
function slackLoopIcon(color, highlight, scale = 1, angle = 0, planned = false, lead = null) {   // eslint-disable-line no-unused-vars
  return calloutIcon('hexagon', 'slack', color, planned, Math.round(16 * scale), lead == null ? calloutLead() : lead, 'fosc-marker-icon', { highlight, angle });
}

// ── POP (point of presence / office building) ──────────────────────────────────
// Multi-storey office building: a taller body with rows of windows, a door, and a
// flat roof. Distinct from the squat street-cabinet icon so a POP reads as "building".
function popIcon(highlight, scale = 1) {
  const color  = '#6f42c1'; // purple — distinct from green/blue field assets
  const stroke = highlight ? '#ffc107' : 'rgba(255,255,255,0.92)';
  const filter = highlight
    ? 'drop-shadow(0 0 3px #ffc107)'
    : 'drop-shadow(0 1px 2px rgba(0,0,0,0.5))';
  const win    = 'rgba(255,255,255,0.9)';
  const w = Math.round(20 * scale);
  const h = Math.round(26 * scale);
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 20 26"
                style="filter:${filter}">
             <!-- Building body -->
             <rect x="2" y="2" width="16" height="22" rx="1.2"
                   fill="${color}" stroke="${stroke}" stroke-width="1.3"/>
             <!-- Roof cap -->
             <rect x="1" y="1" width="18" height="3" rx="1"
                   fill="${color}" stroke="${stroke}" stroke-width="1.1"/>
             <!-- Window rows (3 cols × 3 rows) -->
             <g fill="${win}">
               <rect x="4.2"  y="6"  width="2.6" height="2.6" rx="0.4"/>
               <rect x="8.7"  y="6"  width="2.6" height="2.6" rx="0.4"/>
               <rect x="13.2" y="6"  width="2.6" height="2.6" rx="0.4"/>
               <rect x="4.2"  y="10" width="2.6" height="2.6" rx="0.4"/>
               <rect x="8.7"  y="10" width="2.6" height="2.6" rx="0.4"/>
               <rect x="13.2" y="10" width="2.6" height="2.6" rx="0.4"/>
               <rect x="4.2"  y="14" width="2.6" height="2.6" rx="0.4"/>
               <rect x="13.2" y="14" width="2.6" height="2.6" rx="0.4"/>
             </g>
             <!-- Door -->
             <rect x="8.4" y="17.5" width="3.2" height="6.5" rx="0.4"
                   fill="${win}"/>
           </svg>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(w / 2), h],
    popupAnchor: [0, -h],
    className:   'fosc-marker-icon',
  });
}

// `angle` is used only by the slack loop, which turns to its span's bearing.
function foscIconForKind(kind, status, color, highlight, scale, damaged = false, angle = 0, fanSlot = 0, lead = null) {
  let icon;
  if (kind === 'mst')          icon = mstIcon(color, highlight, scale);
  else if (kind === 'optitap') icon = optitapIcon(color, highlight, scale);
  else if (kind === 'cabinet') icon = cabinetIcon(color, highlight, scale);
  else if (kind === 'slack')   icon = slackLoopIcon(color, highlight, scale, angle, status === 'plan', lead);
  else                         icon = foscIcon(color, highlight, scale, status === 'plan', lead, angle);

  if (damaged) {
    const [iw, ih] = icon.options.iconSize;
    // Centre the ❌ on the box, unless the icon says otherwise. Only the slack loop
    // does: its anchor is near the bottom of a tall box and its glyph rotates away
    // from the centre, so a box-centred cross would float off the cable. The ❌ is a
    // SIBLING of the rotated div, so it stays upright either way.
    const c  = icon.options.tinCross;
    const cx = c ? c.x    : iw / 2;
    const cy = c ? c.y    : ih / 2;
    const fs = c ? c.size : Math.max(iw, ih) + 4;
    icon.options.html =
      `<div style="position:relative;width:${iw}px;height:${ih}px">` +
        icon.options.html +
        `<span style="position:absolute;left:${cx}px;top:${cy}px;` +
               `transform:translate(-50%,-50%);font-size:${fs}px;line-height:1;` +
               `pointer-events:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,0.6))">❌</span>` +
      `</div>`;
  }

  // Closures sharing one location — a pole serving several ONUs takes one stacked
  // tap per fibre — would hide each other completely, leaving only the last one
  // drawn visible or clickable. Fan the group into a horizontal row by moving the
  // anchor within the icon box: the marker keeps its true latlng, so geometry,
  // spans and the move flow never see the offset, and popupAnchor is measured from
  // iconAnchor so the popup follows its icon. Slots are signed and centred
  // (-1, 0, +1 for three) and stepped by the icon's own width, so the row stays
  // touching at every zoom as the icons scale.
  if (fanSlot) {
    const [aw, ah] = icon.options.iconAnchor;
    icon.options.iconAnchor = [aw - Math.round(fanSlot * icon.options.iconSize[0]), ah];
  }

  return icon;
}

// ── Buried infrastructure ─────────────────────────────────────────────────────

function conduitStatusColor(status) {
  switch (status) {
    case 'active':  return '#b45309'; // earth-tone brown — underground
    case 'plan':    return '#94a3b8'; // slate
    case 'retired': return '#6b7280'; // grey
    default:        return '#94a3b8';
  }
}

function vaultIcon(status, scale = 1) {
  return symbolIcon('square', 'vault', conduitStatusColor(status), status === 'plan', Math.round(18 * scale), 'vault-marker-icon');
}

function handholeIcon(status, scale = 1) {
  return symbolIcon('square', 'hand', conduitStatusColor(status), status === 'plan', Math.round(20 * scale), 'vault-marker-icon');
}

function pullboxIcon(status, scale = 1) {
  return symbolIcon('square', 'rope', conduitStatusColor(status), status === 'plan', Math.round(18 * scale), 'vault-marker-icon');
}

// ── ONU / subscriber house marker ─────────────────────────────────────────────

function onuSignalColor(signal) {
  if (signal == null)  return '#6c757d';
  if (signal > -6.5)   return '#dc3545'; // above GPON max
  if (signal > -8.0)   return '#E0A800'; // upper edge
  if (signal >= -28.0) return '#198754'; // normal range
  if (signal >= -29.5) return '#E0A800'; // lower edge
  return '#dc3545';                      // below GPON min
}

function onuIcon(color, scale = 1) {
  return symbolIcon('circle', 'onu', color, false, Math.round(14 * scale), 'onu-marker-icon', { baseAnchor: false });
}

// ── Map-note icons ────────────────────────────────────────────────────────────
//
// Notes sit on the thing they are about - a pole, a span - so drawing them on the
// point buried them under it. Each note icon is instead drawn up and to the right
// of its anchor with a leader back to a small dot, the way a callout works on a
// drawing: the dot keeps the true position, the symbol stays readable.
//
// NOTE_DX/DY are in viewBox units, which are icon pixels before the scale factor.
// They are the FULL leader, for a zoomed-in map. `lead` (0..1) shortens it as
// the map zooms out: a fixed 32 px leader is a metre at z19 and a house at z14,
// so far out the symbol is set straight on its point with no leader at all.
const NOTE_DX = 32;   // how far right of the anchor the symbol sits
const NOTE_DY = 26;   // and how far above it

/**
 * Anchor dot plus the leader out to the symbol. Shared by every note icon.
 * The symbol's base sits at y=24 (it is drawn from 0,0 and translated right by
 * dx), so the leader runs from the dot to just under its bottom-left corner —
 * it used to stop 19 units short, which read as a stray dash with the symbol
 * adrift beside it.
 */
function noteLeader(dx, dy) {
  if (dx <= 0 && dy <= 0) return '';
  return `<line x1="3" y1="${24 + dy - 1}" x2="${dx + 3}" y2="${24 + 1}"
                stroke="#334155" stroke-width="1.4" stroke-linecap="round" opacity="0.85"/>
          <circle cx="3" cy="${24 + dy - 1}" r="2.6" fill="#334155" stroke="#fff" stroke-width="1"/>`;
}

/**
 * Frame a 24-high symbol (`symW` wide, drawn from 0,0) as a note icon: with a
 * leader the true point is the dot at bottom-left; without one it is the
 * symbol's own bottom-centre.
 */
function noteFrame(symW, symbol, scale, lead) {
  lead = Math.max(0, Math.min(1, lead));
  const dx = Math.round(NOTE_DX * lead), dy = Math.round(NOTE_DY * lead);
  const vw = symW + dx, vh = 24 + dy;
  const w = Math.round(vw * scale), h = Math.round(vh * scale);
  const ax = lead > 0 ? 3 : symW / 2;
  const ay = lead > 0 ? vh - 1 : 24;
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${vw} ${vh}"
               style="filter:drop-shadow(0 1px 2px rgba(0,0,0,0.50))">
             ${noteLeader(dx, dy)}
             <g transform="translate(${dx},0)">${symbol}</g>
           </svg>`,
    iconSize:    [w, h],
    iconAnchor:  [Math.round(ax * scale), Math.round(ay * scale)],
    popupAnchor: [Math.round((dx + symW / 2 - ax) * scale), Math.round(-ay * scale)],
    className:   'note-marker-icon',
  });
}

function treeNoteIcon(scale = 1, lead = 1) {
  return noteFrame(24, `<g transform="translate(12 12) scale(1.1)">${symInner('triangle', 'tree', '#f59e0b', false)}</g>`, scale, lead);
}

function constructionNoteIcon(scale = 1, lead = 1) {
  return noteFrame(24, `<g transform="translate(12 12) scale(1.1)">${symInner('triangle', 'barrier', '#f59e0b', false)}</g>`, scale, lead);
}

function genericNoteIcon(scale = 1, lead = 1) {
  return noteFrame(24, `<g transform="translate(12 12) scale(1.1)">${symInner('diamond', 'pin', '#6b7280', false)}</g>`, scale, lead);
}

function noteIcon(iconType, scale = 1, lead = 1) {
  switch (iconType) {
    case 'tree_trim':    return treeNoteIcon(scale, lead);
    case 'construction': return constructionNoteIcon(scale, lead);
    default:             return genericNoteIcon(scale, lead);
  }
}
