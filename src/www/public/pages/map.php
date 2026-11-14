<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/map.php
// Full-page Leaflet + Geoman network map editor.

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

$PAGE->setTitle('Network Map');

// Leaflet from CDN; Geoman vendored locally (api/features loads from /js + /css)
$PAGE->addExternalCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
$PAGE->addExternalCss('/css/leaflet-geoman.css');
$PAGE->addExternalJs('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
$PAGE->addExternalJs('/js/leaflet-geoman.min.js');
$PAGE->addExternalJs('/js/icons/map.icons.js');
// Permit-number autocomplete for every [data-permit-picker] field on the page.
// Loaded before map.helper.js, which drives it (window.PermitPicker).
$PAGE->addExternalJs('/js/permit-picker.js?v=' . filemtime(__DIR__ . '/../js/permit-picker.js'));

// Data for the "New Closure" and "New Span" modals
$closureTypes = $pdo->query(
    "SELECT closure_type_id, name FROM app.closure_type ORDER BY name"
)->fetchAll();

$cables = $pdo->query(
    "SELECT cable_id, name, fibre_count, open_ended FROM app.cable ORDER BY name"
)->fetchAll();

// Cable-type choices for the "Edit Span" modal (a span's parent cable material).
// 'fibre_stringing' is a LABOUR activity, not a material category — see
// bom_activity_category() in bom.helper.php, which maps it to 'fibre_install'.
// Cable materials live under 'Fibre Cable' and 'fibre_install'; the latter also
// holds ties and clamps, so keep the ones sold by the metre plus anything a cable
// already points at (the FlexTIN drop assemblies are priced 'ea' but are cable).
$cableMaterials = $pdo->query(
    "SELECT m.id, m.sku, m.description, m.category
       FROM app.bom_material m
      WHERE m.active
        AND m.category IN ('Fibre Cable', 'fibre_install')
        AND (m.unit = 'm'
             OR EXISTS (SELECT 1 FROM app.cable c WHERE c.material_id = m.id))
      ORDER BY m.category, m.description"
)->fetchAll();

// Models for the vault / handhole dialog: the BOM's Vault items, including ones no longer ordered
// (the FCST round handholes are still in the ground).
$vaultModels = $pdo->query(
    "SELECT id, sku, description, active FROM app.bom_material WHERE category = 'Vault' ORDER BY NOT active, description"
)->fetchAll();

// POP list for the "Edit Closure" modal
$allPops = $pdo->query("SELECT pop_id, name FROM app.pop ORDER BY name")->fetchAll();

$microductTypes = (function () use ($pdo) {
    $rows = $pdo->query("SELECT id, name, way_count, inner_ducts, has_tracer FROM app.microduct_type WHERE active ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['way_count']   = (int)$r['way_count'];
        $r['has_tracer']  = (bool)$r['has_tracer'];
        $r['inner_ducts'] = json_decode((string)$r['inner_ducts'], true) ?? [];
    }
    return $rows;
})();

// Deep-link: ?fosc_id=N or ?pole_fid=N — pan to asset and open popup after load
$deepLinkFoscId = isset($_GET['fosc_id'])  ? (int)$_GET['fosc_id']  : 0;
$deepLinkPoleFid = isset($_GET['pole_fid']) ? (int)$_GET['pole_fid'] : 0;
// Plan view: ?plan=N (stored plan) — render a locked, framed plan view
$deepLinkPlanId = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;

// Features are fetched for the CURRENT viewport only, so the pan-to-marker code in
// map.helper.js can only find an asset that is already on screen — a deep link to
// anywhere outside the restored view silently did nothing. Resolve the coordinates
// here so the map can move there first and load features around it.
$deepLinkLatLng = null;
if ($deepLinkFoscId > 0) {
    $st = $pdo->prepare('SELECT ST_Y(location) AS lat, ST_X(location) AS lon
                           FROM app.fosc WHERE fosc_id = :id AND location IS NOT NULL');
    $st->execute([':id' => $deepLinkFoscId]);
    $deepLinkLatLng = $st->fetch() ?: null;
} elseif ($deepLinkPoleFid > 0) {
    $st = $pdo->prepare('SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon
                           FROM app.pole WHERE fid = :id AND geom IS NOT NULL');
    $st->execute([':id' => $deepLinkPoleFid]);
    $deepLinkLatLng = $st->fetch() ?: null;
}

// Pass server-side data to map.helper.js via a global
$PAGE->setInlineJsRenderer(function () use ($CSRF, $closureTypes, $cables, $deepLinkFoscId, $deepLinkPoleFid, $deepLinkPlanId, $deepLinkLatLng) {
    $cfg = json_encode([
        'csrf'         => $CSRF,
        'closureTypes' => $closureTypes,
        'cables'       => $cables,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $out = "window.MAP_CONFIG = {$cfg};";
    if ($deepLinkFoscId > 0) {
        $out .= "\nwindow._openFoscId = {$deepLinkFoscId};";
    }
    if ($deepLinkPoleFid > 0) {
        $out .= "\nwindow._openPoleFid = {$deepLinkPoleFid};";
    }
    if ($deepLinkPlanId > 0) {
        $out .= "\nwindow._mapPlanId = {$deepLinkPlanId};";
    }
    if ($deepLinkLatLng) {
        $out .= "\nwindow._deepLinkLatLng = ["
              . (float)$deepLinkLatLng['lat'] . ',' . (float)$deepLinkLatLng['lon'] . '];';
    }
    return $out;
});

$PAGE->setInlineCssRenderer(function () {
    return <<<CSS
#mapCanvas {
    height: calc(100vh - 130px);
    width: 100%;
    border-radius: 4px;
    outline: none;
}
.map-grey-tiles { filter: grayscale(100%) brightness(1.08); }
.map-fade-row {
    display: flex; align-items: center; gap: 5px;
    padding: 4px 6px 2px; margin-top: 3px; border-top: 1px solid #e2e8f0;
}
.map-wb-row { margin-top: 6px; padding-top: 5px; border-top: 1px solid #e5e7eb; min-width: 170px; }
.map-wb-head { font-size: 10px; font-weight: 600; color: #475569; }
.map-wb-head b { font-size: 12px; color: #111827; margin-left: 4px; }
.map-wb-row input[type=range] { width: 100%; height: 14px; margin: 2px 0; }
.map-wb-src { font-size: 10px; color: #475569; }
.map-wb-note { font-size: 10px; color: #6b7280; }
.map-fade-row .map-fade-lbl { font-size: 10px; font-weight: 600; color: #475569; white-space: nowrap; }
.map-fade-row input[type=range] { width: 70px; height: 12px; }
.map-fade-row .map-fade-val { font-size: 10px; color: #64748b; width: 28px; text-align: right; font-variant-numeric: tabular-nums; }

/* ── B&W Plan view (basemap "B&W Plan" → body.map-mono) ──────────────────────
   Every colour below is set as a presentation ATTRIBUTE on the SVG — by our own
   icon markup and by Leaflet's path renderer alike — and attributes lose to any
   author rule. So these plain selectors repaint the whole map without
   !important and without touching one of the ~19 places colour is decided.
   Deliberately silent on stroke-width and stroke-dasharray: those carry fibre
   count, slack, overlash and dup-strand state, and must survive. */

/* Vectors: one black line, weight-coded exactly as before. */
body.map-mono .leaflet-overlay-pane path:not(.tin-plan-chrome) { stroke: #000; }
body.map-mono .leaflet-overlay-pane path[fill]:not([fill="none"]):not(.tin-density):not(.tin-plan-chrome) { fill: #fff; fill-opacity: 1; }

/* Plan-framing chrome (the out-of-bounds dim, the sheet outline, the furniture
   zones) is editor UI, not map content — it never prints, so the mono repaint
   must not reach it. Without the guard above, the dim's translucent navy became
   fill:#fff/fill-opacity:1 and blanked the whole out-of-bounds map instead of
   shading it, and the furniture zones turned into solid white boxes. */

/* Density hexagons are the exception: their fill IS the reading. Painted black
   with the per-hex fill-opacity left alone (0.45 rising to 0.80 with the count),
   so the ramp survives as light grey → near black. */
body.map-mono .leaflet-overlay-pane path.tin-density,
body.map-mono .leaflet-density-pane path.tin-density { fill: #000; }
/* The hexagons live in their own pane (under the road coverage); keep the mono
   outline rule consistent with the overlay pane. */
body.map-mono .leaflet-density-pane path { stroke: #000; }

/* Cased runs (strand-over-span, occupied duct): black outline, white bore — the
   drafting convention for heavy cable, and it keeps a 16px casing from printing
   as a solid slab. 'tin-core' is only set where a casing is really underneath. */
body.map-mono .leaflet-overlay-pane path.tin-core { stroke: #fff; }

/* Icons: white-filled, black-outlined line art. fill="none" is load-bearing —
   the slack omega and several rings would fill solid without the guard. */
body.map-mono .leaflet-marker-pane svg path[fill]:not([fill="none"]),
body.map-mono .leaflet-marker-pane svg rect,
body.map-mono .leaflet-marker-pane svg circle:not([fill="none"]),
body.map-mono .leaflet-marker-pane svg ellipse,
body.map-mono .leaflet-marker-pane svg polygon { fill: #fff; }
body.map-mono .leaflet-marker-pane svg * { stroke: #000; }

/* Drop shadows and coloured glows are inline style, so these do need the
   override. Flat line art has no light source. */
body.map-mono .leaflet-marker-pane svg { filter: none !important; }

/* Fill-only pictorial icons (ONU, note glyphs) are silhouettes with no strokes
   at all — whitening their fills alone would leave a white blob, so they get an
   explicit hairline. */
body.map-mono .onu-marker-icon svg *,
body.map-mono .note-marker-icon svg * { fill: #fff; stroke: #000; stroke-width: 0.7; }

/* Emoji markers (⚓ anchor, 🪝 building anchor, ❌ damaged) carry their colour in
   the font — no hex to swap, only a filter reaches them. Each sits on an inline
   filter of its own, hence !important. */
body.map-mono .anchor-marker-icon,
body.map-mono .building-anchor-icon,
body.map-mono .fosc-marker-icon span { filter: grayscale(1) brightness(0) !important; }

/* Screen-only chrome and live state that means nothing on an issued drawing. */
body.map-mono #mapValidationAlert,
body.map-mono #mapConflictAlert,
body.map-mono #mapFilterChip { display: none !important; }

/* Labels: every splice number stays — MST/optitap taps and slack loops are
   closures like any other, and their number is the whole point of the drawing.
   What goes is the annotation that isn't an asset's name. (Pole numbers are a
   separate call: Labels: Key drops those, in any view.) */
body.map-mono .flextin-dist-label,
body.map-mono .civic-label { display: none; }

/* Density hexagons and their legend never print (bare Ctrl+P while zoomed out, where
   nothing can wait for the assets to load): a paper plan shows what is there, not a heat map. */
@media print { .leaflet-density-pane, .density-legend { display: none !important; } }

.civic-label { display: none; }

/* Span lengths are the exception: a measured distance is what a build drawing is
   read for, so it stays — printed black rather than screen blue. */
body.map-mono .route-dist-label { color: #000; opacity: 1; }

/* The chips that stay are translucent white with soft shadows, which both turn
   to grey mush on a mono laser. */
body.map-mono .fosc-label,
body.map-mono .span-fibre-label,
body.map-mono .vault-label {
    background: #fff; color: #000; opacity: 1;
    border: 1px solid #000; box-shadow: none; text-shadow: none;
}
body.map-mono [class*="vault-label--"] { color: #000; }   /* 5 owner colours */

/* The small labels — pole numbers, taps, slack loops — are deliberately faint
   and box-less so they don't crowd the line. Kept that way, only darkened:
   #777/#aaa is below what a mono laser will hold, and a 1px border around 5px
   text is a blot. They all carry `fosc-label` as well, so the chip rule above
   has to be undone here rather than simply not applied. */
body.map-mono .fosc-label.pole-telco-label,
body.map-mono .fosc-label.fosc-label--tap,
body.map-mono .fosc-label.fosc-label--slack {
    color: #000; background: none; border: none; text-shadow: none;
}

/* The density legend stays — it reads the hexes above — with its gradient swatch
   desaturated so it matches the grey ramp they now draw in. */
body.map-mono .density-legend span { filter: grayscale(1); }

/* U-guards are a coloured <div>, not SVG: inline background and colour, hence
   the override. Reads as a small outlined chip on paper. */
body.map-mono .uguard-marker-icon > div {
    background: #fff !important; color: #000 !important;
    border: 1px solid #000; box-shadow: none !important;
}

/* Engineering plan overlay — hidden normally, shown in print mode */
#engPlanOverlay {
    display: none;
    position: fixed; inset: 0;
    pointer-events: none;
    z-index: 10000;
}

/* On-screen PLAN VIEW (?plan=N): make the page look like the printed plan —
   grey basemap, coloured overlays, framed title block, no app chrome. */
body.map-plan-view nav.navbar,
body.map-plan-view aside,
body.map-plan-view #mapPageHeader,
body.map-plan-view .map-toolbar,
body.map-plan-view #mapEditBar,
body.map-plan-view #mapValidationAlert,
body.map-plan-view nav[aria-label="breadcrumb"],
body.map-plan-view .leaflet-control-layers,
body.map-plan-view .leaflet-control-zoom,
body.map-plan-view .leaflet-control-scale,
body.map-plan-view .leaflet-control-attribution,
body.map-plan-view .fpm-asset-toolbar,
body.map-plan-view #tinCmdPalette { display: none !important; }

body.map-plan-view .nav-rail { display: none !important; }
body.map-plan-view .app-shell,
body.map-plan-view main,
body.map-plan-view .position-relative { padding: 0 !important; margin: 0 !important; }

/* Screen only: when printing from the plan view the canvas is pinned to the paper
   size by _expandCanvasAndPreload, and a !important 100vw/100vh here would win over
   that inline size and re-lay-out the map behind Leaflet's back. */
@media screen {
    body.map-plan-view #mapCanvas {
        position: fixed !important;
        top: 0 !important; left: 0 !important;
        width: 100vw !important; height: 100vh !important;
        z-index: 9999 !important;
        border-radius: 0 !important;
    }
}
body.map-plan-view #mapCanvas { border-radius: 0 !important; }

/* Floating plan-view controls (screen only) */
#planViewBar {
    display: none;
    position: fixed; top: 10px; right: 12px; z-index: 10001;
    gap: 6px;
}
body.map-plan-view #planViewBar { display: flex; }
@media print { #planViewBar { display: none !important; } }

/* Active-filter chip — floats over the top of the map, always visible while any
   pole/anchor or road filter is on, because the controls themselves sit inside a
   flyout that is usually closed. */
#mapFilterChip {
    position: fixed; top: 152px; left: 50%; transform: translateX(-50%);
    z-index: 1000;
    display: none; align-items: center; gap: 8px;
    background: #fee2e2; border: 1.5px solid #dc2626; border-radius: 5px;
    box-shadow: 0 2px 10px rgba(153,27,27,.28);
    padding: 5px 9px 5px 11px; font-size: .8rem; color: #7f1d1d;
    max-width: calc(100vw - 40px);
}
#mapFilterChip button {
    border: 1px solid #dc2626; background: #fff; color: #991b1b; font-weight: 600;
    border-radius: 3px; padding: 2px 8px; font-size: .74rem; cursor: pointer;
}
#mapFilterChip button:hover { background: #fca5a5; color: #7f1d1d; }
/* The map reads as normal whether or not a filter is hiding half the network, so
   this pulses — a static banner is too easy to stop seeing after a few minutes. */
#mapFilterDot {
    flex: 0 0 auto; width: 9px; height: 9px; border-radius: 50%;
    background: #dc2626; animation: mapFilterPulse 1.6s ease-in-out infinite;
}
#mapFilterChipText strong { font-weight: 700; }
@keyframes mapFilterPulse {
    0%, 100% { opacity: 1;   box-shadow: 0 0 0 0    rgba(220,38,38,.55); }
    70%      { opacity: .55; box-shadow: 0 0 0 6px  rgba(220,38,38,0); }
}
@media (prefers-reduced-motion: reduce) {
    #mapFilterDot { animation: none; }
}
#mapFilterChipText { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media print { #mapFilterChip { display: none !important; } }
body.map-plan-view #mapFilterChip { display: none !important; }

/* Unread-style dot on a rail icon — marks a panel holding something active */
.tin-rail-btn { position: relative; }
.rail-dot {
    position: absolute; top: 5px; right: 5px;
    width: 7px; height: 7px; border-radius: 50%;
    background: #e63946; border: 1px solid #fff;
}

/* Labels on the shaded "printed furniture" zones inside the plan frame */
.plan-zone-label {
    background: rgba(220,38,38,.88); color: #fff;
    border: none; box-shadow: none;
    font-size: 10px; font-weight: 600; letter-spacing: .02em;
    padding: 1px 6px; border-radius: 3px; white-space: nowrap;
}
.plan-zone-label::before { display: none; }

/* Plan framing bar — shown while choosing the sheet area */
#planFrameBar {
    display: none;
    position: fixed; bottom: 14px; left: 50%; transform: translateX(-50%);
    z-index: 10001;
    align-items: center; gap: 8px; flex-wrap: wrap;
    background: #fff; border-radius: 6px;
    box-shadow: 0 3px 16px rgba(0,0,0,.28);
    padding: 7px 12px; font-size: .82rem;
    max-width: calc(100vw - 24px);
}
body.map-framing #planFrameBar { display: flex; }
#planFrameHint  { color: #6c757d; }
#planFrameStats { color: #333; font-family: monospace; font-size: .76rem; white-space: nowrap; }
#planFrameSavedList {
    flex-basis: 100%; max-height: 34vh; overflow-y: auto;
    border-top: 1px solid #e9ecef; margin-top: 4px; padding-top: 5px;
}
.plan-saved-row {
    display: flex; align-items: center; gap: 8px;
    padding: 3px 2px; border-bottom: 1px solid #f1f3f5;
}
.plan-saved-row:last-child { border-bottom: none; }
.plan-saved-row .psr-title { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.plan-saved-row .psr-meta  { flex: 0 0 auto; color: #868e96; font-size: .72rem; font-family: monospace; }
/* While framing, the map cursor is a crosshair and the sheet mask is non-interactive */
body.map-framing #mapCanvas { cursor: crosshair; }
@media print { #planFrameBar { display: none !important; } }

@media print {
    /* Engineering mode: show overlay, greyscale map, hide all UI chrome */
    body.map-eng-mode #engPlanOverlay { display: block !important; }

    body.map-eng-mode nav.navbar,
    body.map-eng-mode aside,
    body.map-eng-mode #mapPageHeader,
    body.map-eng-mode #mapToastContainer,
    body.map-eng-mode .map-toolbar,
    body.map-eng-mode #mapEditBar,
    body.map-eng-mode #mapValidationAlert,
    body.map-eng-mode nav[aria-label="breadcrumb"],
    body.map-eng-mode .leaflet-control-layers,
    body.map-eng-mode .leaflet-control-zoom,
    body.map-eng-mode .leaflet-control-scale,
    body.map-eng-mode .leaflet-control-attribution,
    body.map-eng-mode .fpm-asset-toolbar,
    body.map-eng-mode #tinCmdPalette { display: none !important; }

    body.map-eng-mode .container-fluid,
    body.map-eng-mode .row,
    body.map-eng-mode main,
    body.map-eng-mode .position-relative { padding: 0 !important; margin: 0 !important; }

    /* Nothing may lay out wider than the sheet. Chrome's default print scale is
       "fit to printable area": one element overflowing the page width makes it
       shrink the WHOLE page to fit, and the map is the only thing that suffers
       visibly — the frame, title block and north arrow are all sized off the page
       box (inset:0 / inset:5mm) so they still fill the paper, while the canvas is
       pinned to a hard pixel size and lands at fit-scale × 1056. That reads as the
       map occupying a corner of the sheet with the rest white.
       main is the flex child beside the icon rail, so it is the usual culprit. */
    body.map-eng-mode .nav-rail { display: none !important; }
    body.map-eng-mode .app-shell,
    body.map-eng-mode main {
        width: 100% !important; max-width: 100% !important;
        flex: 0 0 100% !important; overflow: hidden !important;
    }
    body.map-eng-mode { overflow: hidden !important; }

    /* Width/height are deliberately NOT forced here: _expandCanvasAndPreload pins
       the canvas to the paper size in CSS pixels and invalidates Leaflet at that
       size. Overriding with 100vw/100vh re-lays-out the element at print time
       without Leaflet knowing, and the sheet prints as a corner slice of the
       preview. */
    body.map-eng-mode #mapCanvas {
        position: fixed !important;
        top: 0 !important; left: 0 !important;
        z-index: 9999 !important;
    }
    /* The basemap prints as chosen: a grey one (.map-grey-tiles) grey, a colour
       one in colour. Once the tiles are composited into one image the grey is
       baked in (see _flattenTilePane) and the CSS filter must go: Chrome's PDF
       writer rasterises any filtered subtree at 300 dpi, which threw away the
       resolution the sharp basemap was fetched for. */
    body .leaflet-tile-pane.tin-flattened .map-grey-tiles { filter: none !important; }


    /* Outer engineering border (double-line convention).
       The huge white box-shadow is a "mat" that paints everything OUTSIDE the
       frame white, hiding any map tiles that extend past the border (the map
       canvas stays full-bleed so Leaflet tile coverage is correct). */
    #engPlanFrame {
        position: absolute; inset: 5mm;
        border: 4px double #000;
        box-shadow: 0 0 0 100vmax #fff;
    }

    /* North arrow — top right */
    #engNorthArrow {
        position: absolute;
        top: 6mm; right: 6mm;
        width: 22mm; height: 22mm;
        background: #fff;
        border: 1px solid #000;
        border-radius: 50%;
        padding: 1mm;
    }

    /* Title block — bottom strip (AutoCAD style) */
    #engTitleBlock {
        position: absolute;
        bottom: 0; left: 0; right: 0;
        height: 26mm;
        border-top: 2px solid #000;
        display: flex;
        align-items: stretch;
        background: #fff;
    }

    #engLogoCell {
        display: flex; align-items: center; justify-content: center;
        padding: 3mm 4mm; min-width: 36mm;
    }

    #engLogo {
        filter: grayscale(100%) brightness(0) !important;
        max-height: 16mm; max-width: 32mm;
        width: auto; height: auto;
    }

    #engTitleInfo {
        flex: 1;
        border-left: 1px solid #000;
        padding: 3mm 5mm;
        display: flex; flex-direction: column; justify-content: center;
    }
    .eng-title { font-size: 10.5pt; font-weight: bold; font-family: Arial, sans-serif; }
    .eng-meta  { font-size: 6.5pt; font-family: monospace; margin-top: 1.5mm; color: #000; }
    .eng-meta span { display: block; line-height: 1.5; }
    #engScaleBar     { display: inline-block; }
    #engScaleBarLine {
        width: 100%;
        height: 2mm;
        border-top: 2px solid #000;
        border-left: 2px solid #000;
        border-right: 2px solid #000;
        box-sizing: border-box;
    }
    #engScaleBarLabel { width: 100%; font-size: 6pt; font-family: monospace; margin-top: 0.5mm; text-align: center; display: block; }

    #engLegend {
        flex: 2; min-width: 0; overflow: hidden;
        border-left: 1px solid #000;
        padding: 2mm 4mm;
        display: flex; flex-direction: column;
    }
    .eng-leg-head { font: bold 7pt Arial, sans-serif; line-height: 1; }
    #engLegendGrid {
        display: grid; grid-auto-flow: column;
        grid-template-rows: repeat(4, 4.2mm);
        column-gap: 4mm; align-content: start; justify-content: start;
        margin-top: 1.2mm;
    }
    .eng-leg-item { display: flex; align-items: center; gap: 1.2mm; font: 5.5pt Arial, sans-serif; white-space: nowrap; line-height: 1; }
    .eng-leg-ico { width: 4.2mm; height: 4.2mm; display: inline-flex; align-items: center; justify-content: center; flex: none; }
    .eng-leg-ico svg { width: 100%; height: 100%; display: block; filter: none !important; }

    #engSheetInfo {
        width: 48mm;
        border-left: 1px solid #000;
        padding: 3mm 4mm;
        font-size: 7pt;
        font-family: Arial, sans-serif;
        display: flex; flex-direction: column; justify-content: center;
        line-height: 1.6;
    }
}
/* On mobile: full-screen map — hide sidebar, breadcrumb, page title, validation banner */
@media (max-width: 767.98px) {
    .nav-rail                 { display: none !important; }
    main.app-main             { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
    main nav[aria-label="breadcrumb"] { display: none !important; }
    main > h5                 { display: none !important; }
    #mapValidationAlert       { display: none !important; }
    #mapCanvas                { height: calc(100vh - 56px); border-radius: 0; }
}
/* Keep DivIcons from getting Leaflet's default wrapper styling */
.fosc-marker-icon, .pole-marker-icon, .onu-marker-icon, .vault-marker-icon, .note-marker-icon { background: none; border: none; }

/* The slack glyph turns to its span's bearing, but Leaflet's icon box stays
   upright — so for a sideways loop the box covers empty ground beside the artwork
   and would swallow clicks meant for the span or pole underneath. Hand the hit
   target to the rotated child: a transformed element's hit region turns with it,
   and events still bubble up through the (untargetable) parent to Leaflet.
   The .leaflet-marker-icon qualifier is needed to outrank Leaflet's own
   `.leaflet-marker-icon.leaflet-interactive { pointer-events: auto }`. */
.leaflet-marker-icon.fosc-marker-icon--slack              { pointer-events: none; }
.leaflet-marker-icon.fosc-marker-icon--slack .slack-glyph { pointer-events: auto; }

/* Asset-density hexbin legend (zoomed-out overview regime) */
.density-legend {
    background: rgba(255,255,255,0.9);
    border-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
    color: #222;
    font-size: 11px;
    line-height: 1.2;
    padding: 6px 8px;
}

/* Closure splice-num labels */
.fosc-label {
    background: rgba(255,255,255,0.85);
    border: none;
    border-radius: 3px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.25);
    color: #222;
    font-size: 11px;
    font-weight: 600;
    padding: 1px 5px;
    white-space: nowrap;
    z-index: 10 !important;
}
.fosc-label::before { display: none; }  /* remove Leaflet tooltip arrow */

/* FlexTIN optitap labels — smaller, muted, no box, always behind other labels */
.fosc-label--tap {
    font-size: 4.5px;
    font-weight: 400;
    color: #777;
    background: none;
    box-shadow: none;
    border-radius: 0;
    padding: 0;
    z-index: 1 !important;
}
.fosc-label--tap.leaflet-tooltip-right { margin-left: 1px; }

/* Slack loop labels — half size of normal closure labels */
.fosc-label--slack {
    font-size: 5.5px;
    font-weight: 400;
    color: #555;
    background: rgba(255,255,255,0.75);
    padding: 0px 3px;
}

/* While drawing a cable span: make all markers/layers non-interactive so clicks fall through
   to Geoman's map-level handler. renderAll() re-creates markers each zoom/pan which strips
   Geoman's per-marker click-through listeners; pointer-events:none bypasses that entirely. */
#mapCanvas.fpm-span-drawing .leaflet-interactive { pointer-events: none !important; }

/* Zoom-responsive label sizes (class applied to #mapCanvas by refreshIconSizes on zoomend) */
#mapCanvas.zoom-mid .fosc-label        { font-size: 13px; }
#mapCanvas.zoom-mid .fosc-label--tap   { font-size: 5px; }
#mapCanvas.zoom-mid .fosc-label--slack { font-size: 6.5px; }
#mapCanvas.zoom-hi  .fosc-label        { font-size: 15px; }
#mapCanvas.zoom-hi  .fosc-label--tap   { font-size: 6px; }
#mapCanvas.zoom-hi  .fosc-label--slack { font-size: 7.5px; }
#mapCanvas.zoom-max .fosc-label        { font-size: 18px; padding: 2px 6px; }
#mapCanvas.zoom-max .fosc-label--tap   { font-size: 7px; }
#mapCanvas.zoom-max .fosc-label--slack { font-size: 9px; }

/* Pole telco# labels — very faint, small, no box */
.pole-telco-label {
    font-size: 8px;
    font-weight: 500;
    color: #333;
    text-shadow: 0 0 2px #fff, 0 0 2px #fff, 0 0 3px #fff;
    background: none;
    box-shadow: none;
    border-radius: 0;
    padding: 0;
    z-index: 1 !important;
}
/* On the Grey basemap #aaa disappears into the tiles — darker, with a little
   weight, and a hair of halo so it still lifts off a grey building footprint. */
body.map-grey .pole-telco-label {
    color: #333;
    font-weight: 500;
    text-shadow: 0 0 2px #fff, 0 0 2px #fff;
}
.pole-telco-label.leaflet-tooltip-right { margin-left: 2px; }
#mapCanvas.zoom-mid .pole-telco-label { font-size: 9px; }
#mapCanvas.zoom-hi  .pole-telco-label { font-size: 10px; }
#mapCanvas.zoom-max .pole-telco-label { font-size: 11px; }

/* Vault / handhole name labels — smaller than closure labels. Owner colours
   match the pole-arm palette in js/icons/map.icons.js (poleOwnerColor). */
.vault-label {
    font-size: 9px;
    font-weight: 600;
    color: #444;
    background: rgba(255,255,255,0.75);
    padding: 0px 3px;
}
.vault-label--bell    { color: #3b82f6; }   /* Bell blue */
.vault-label--nbpower { color: #f97316; }   /* NB Power orange */
.vault-label--rogers  { color: #ef4444; }   /* Rogers red */
.vault-label--own  { color: #198754; }   /* green: owner NULL = ours */
.vault-label--private { color: #a855f7; }   /* Private / customer-owned purple */
#mapCanvas.zoom-mid .vault-label { font-size: 10px; }
#mapCanvas.zoom-hi  .vault-label { font-size: 11px; }
#mapCanvas.zoom-max .vault-label { font-size: 13px; }

/* ── The Labels control: one button, three positions ─────────────────────────
   Labels: Off — no lettering on the map at all, so .fosc-labels-hidden has to
   reach the label types that are not tooltips (span fibre counts) and the ones
   with layer toggles of their own (FlexTIN distances, span lengths, civic
   numbers).

   Labels: Key — .min-labels keeps what an engineer reads a drawing for: closure
   and slack-loop splice numbers, POP names, fibre counts, span distances. Pole
   numbers are also unbound in JS (refreshPoleLabels) since they are the bulk of
   the DOM; the rule here covers the moment between a re-render and that sweep.

   Labels: All — neither class is set. See cycleLabelState in map.helper.js. */
.fosc-labels-hidden .fosc-label,
.fosc-labels-hidden .span-fibre-label,
.fosc-labels-hidden .flextin-dist-label,
.fosc-labels-hidden .route-dist-label,
.fosc-labels-hidden .conduit-len-label,
.fosc-labels-hidden .civic-label { display: none; }

/* Hidden by the declutter pass — it would have landed on a label that matters
   more. !important because several label types set display on themselves. */
.label-decluttered { display: none !important; }

.min-labels .pole-telco-label,
.min-labels .fosc-label--tap,
.min-labels .vault-label,
.min-labels .flextin-dist-label,
.min-labels .civic-label { display: none; }

/* Cable span fibre-count labels — rotated parallel to cable */
/* Sits directly on the cable casing (#111827, near-black), so dark low-opacity
   text was invisible. White chip keeps it readable over both the casing and the
   grey basemap. */
.span-fibre-label {
  font-size: 9px;
  font-weight: 700;
  color: #111827;
  background: rgba(255,255,255,0.88);
  border-radius: 3px;
  padding: 0px 3px;
  /* The chip lives in a 0x0 divIcon wrapper, so as a block it computed to 0 wide
     and the text spilled out of it: the white backing was a 6px sliver beside the
     label rather than behind it, and translate(-50%) centred that sliver instead
     of the text. max-content makes the chip the size of what it holds. */
  display: inline-block;
  width: max-content;
  opacity: 0.95;
  white-space: nowrap;
  pointer-events: none;
  letter-spacing: 0.04em;
  line-height: 1.25;
}
#mapCanvas.zoom-mid .span-fibre-label { font-size: 10px; }
#mapCanvas.zoom-hi  .span-fibre-label { font-size: 11px; }
#mapCanvas.zoom-max .span-fibre-label { font-size: 13px; }

/* FlexTIN pole-to-pole distance labels — rotated parallel to cable */
.flextin-dist-label {
  display: inline-block;
  width: max-content;
  font-size: 9px;
  font-weight: 700;
  color: #6f42c1;
  opacity: 0.85;
  white-space: nowrap;
  pointer-events: none;
  letter-spacing: 0.02em;
  line-height: 1;
  text-shadow: 0 0 2px #fff, 0 0 2px #fff;
}
#mapCanvas.zoom-mid .flextin-dist-label { font-size: 10px; }
#mapCanvas.zoom-hi  .flextin-dist-label { font-size: 11px; }
#mapCanvas.zoom-max .flextin-dist-label { font-size: 12px; }

/* Route distance labels — rotated parallel to the line they measure. On messenger
   strand that is the pole-to-pole spacing (a strand segment runs one pole pair);
   on a cable span no strand carries, it is the span's whole route end to end.
   Blue rather than the FlexTIN purple: the two toggles cover disjoint features,
   and on a map with both on the colour is what says which is which. Unlike the
   FlexTIN annotation these survive Labels: Key and the B&W plan view — a measured
   distance is drawing content, the thing you lay a build out from. */
.route-dist-label {
  display: inline-block;
  width: max-content;
  font-size: 9px;
  font-weight: 700;
  color: #1d4ed8;
  opacity: 0.9;
  white-space: nowrap;
  pointer-events: none;
  letter-spacing: 0.02em;
  line-height: 1;
  text-shadow: 0 0 2px #fff, 0 0 2px #fff;
}
#mapCanvas.zoom-mid .route-dist-label { font-size: 10px; }
#mapCanvas.zoom-hi  .route-dist-label { font-size: 11px; }
#mapCanvas.zoom-max .route-dist-label { font-size: 12px; }

/* Measured conduit length — rotated parallel to the duct, below the centreline so
   it never lands on the fibre-count label above it. Conduit orange rather than the
   route-distance blue: this number is a property of the DUCT, and it is the
   measured length, not the drawn one, so it must not read as the same kind of
   figure as a route distance. Survives Labels: Key for the same reason a route
   distance does — a measured length is drawing content, not annotation. */
.conduit-len-label {
  display: inline-block;
  width: max-content;
  font-size: 9px;
  font-weight: 700;
  color: #c2410c;
  opacity: 0.95;
  white-space: nowrap;
  pointer-events: none;
  letter-spacing: 0.02em;
  line-height: 1;
  text-shadow: 0 0 2px #fff, 0 0 2px #fff;
}
#mapCanvas.zoom-mid .conduit-len-label { font-size: 10px; }
#mapCanvas.zoom-hi  .conduit-len-label { font-size: 11px; }
#mapCanvas.zoom-max .conduit-len-label { font-size: 12px; }

/* A distance must not outlive the line it measures, so each one hides with the
   layer that produced it. Done on the inner div (the marker element itself is
   what the work-order filter drives) so the two never fight over `display`. */
.route-dist-span-hidden   .route-dist-label--span   { display: none; }
.route-dist-strand-hidden .route-dist-label--strand { display: none; }
.conduit-labels-hidden    .conduit-len-label        { display: none; }
/* Fibre counts belong to the cable, so they go with the Spans layer — including
   the one a conduit shows for what is running through it. The conduit's own
   measured length is not a fibre count and stays. */
.span-labels-hidden       .span-fibre-label         { display: none; }
/* Finer label tiers appear as the map zooms in (setLabelTier in map.helper.js) */
#mapCanvas[data-label-tier="0"] .span-fibre-label.lbl-t1,
#mapCanvas[data-label-tier="0"] .span-fibre-label.lbl-t2,
#mapCanvas[data-label-tier="1"] .span-fibre-label.lbl-t2 { display: none; }

/* GeoNB civic number labels (dots are canvas circleMarkers) */
.civic-label {
  display: inline-block;
  width: max-content;
  font-size: 9px;
  font-weight: 700;
  color: #1e3a8a;
  white-space: nowrap;
  text-shadow: 0 0 3px #fff, 0 0 3px #fff, 0 0 3px #fff;
  pointer-events: none;
  line-height: 1;
}

/* Custom asset toolbar — replaces Geoman's generic toolbar.
   A narrow icon rail that never grows, plus a flyout panel that opens beside it
   and scrolls internally. The toolbar's footprint no longer depends on how many
   tools exist, so nothing can run off the bottom of the map. */
.fpm-asset-toolbar {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    font-size: 13px;
}
.tin-rail {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 5px rgba(0,0,0,.4);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 0 0 auto;
}
.tin-rail-btn {
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; border-bottom: 1px solid #ddd;
    font-size: 17px; line-height: 1; color: #444; cursor: pointer;
    padding: 0;
}
.tin-rail-btn:last-child { border-bottom: none; }
.tin-rail-btn:hover  { background: #f0f4ff; color: #0d6efd; }
.tin-rail-btn.active { background: #0d6efd; color: #fff; }
/* Flyout indicator: the small corner triangle (Photoshop/Figma convention) on
   buttons that open a panel rather than act. Bottom-right, the side it opens to;
   the rail-dot owns top-right. currentColor so it follows hover/active. */
.tin-rail-btn[data-panel]::after {
    content: ""; position: absolute; right: 3px; bottom: 3px;
    width: 0; height: 0;
    border-style: solid; border-width: 0 0 6px 6px;
    border-color: transparent transparent currentColor transparent;
    opacity: .55;
}
.rail-ico, .rail-ico svg { display: block; }
.tin-rail-btn[data-panel]:hover::after,
.tin-rail-btn[data-panel].active::after { opacity: .9; }

.tin-flyout {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 5px rgba(0,0,0,.4);
    margin-left: 6px;
    min-width: 186px;
    display: flex;
    flex-direction: column;
    max-height: min(70vh, calc(100vh - 110px));
    overflow: hidden;
    transition: opacity .6s ease;
}
/* Auto-close after layer toggles: fade, then the JS hides it */
.tin-flyout.fading { opacity: 0; }
.tin-flyout-hdr {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; padding: 6px 8px 6px 10px;
    background: #f0f2f5; border-bottom: 1px solid #d0d3d9;
    font-size: 11px; font-weight: 700; color: #555;
    text-transform: uppercase; letter-spacing: .06em;
    flex: 0 0 auto;
}
.tin-flyout-hdr button {
    background: none; border: none; cursor: pointer;
    font-size: 13px; line-height: 1; color: #666; padding: 0 2px;
}
.tin-flyout-hdr button:hover { color: #000; }
.tin-flyout-body {
    overflow-y: auto; overflow-x: hidden;
    scrollbar-width: thin;
    display: flex; flex-direction: column;
}
.tin-flyout-body::-webkit-scrollbar { width: 8px; }
.tin-flyout-body::-webkit-scrollbar-thumb { background: rgba(0,0,0,.25); border-radius: 4px; }
.tin-panel { display: flex; flex-direction: column; }
.tin-panel > button:last-child { border-bottom: none; }

/* Layers panel: search + bulk actions, then two-column layer rows */
.tin-panel-controls {
    padding: 6px 8px; border-bottom: 1px solid #ddd;
    display: flex; flex-direction: column; gap: 5px;
}
.tin-panel-search {
    border: 1px solid #ccc; border-radius: 3px;
    padding: 3px 6px; font-size: 12px; width: 100%; box-sizing: border-box;
}
.tin-panel-search:focus { outline: 2px solid #0d6efd; border-color: transparent; }
.tin-panel-bulk { display: flex; gap: 5px; }
.tin-panel-bulk button {
    flex: 1; border: 1px solid #ccc !important; background: #f8f9fa;
    border-radius: 3px; padding: 2px 0 !important; cursor: pointer;
    font-size: 11px; justify-content: center;
}
.tin-panel-bulk button:hover { background: #e2e6ea; }
.tin-layer-sub {
    background: #f0f2f5; color: #666;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    padding: 3px 8px; border-bottom: 1px solid #e2e5ea;
}
.tin-layer-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.fpm-asset-toolbar .tin-layer-grid button {
    padding: 5px 7px !important;
    font-size: 12px;
    gap: 5px !important;
    min-width: 0;
    border-right: 1px solid #eee;
}
.tin-layer-grid button:nth-child(2n) { border-right: none; }
.tin-layer-grid button > span:nth-child(2) {
    overflow: hidden; text-overflow: ellipsis; min-width: 0;
}

/* Command palette (Ctrl/Cmd+K) — every toolbar command, searchable */
#tinCmdPalette {
    position: fixed;
    top: 12vh; left: 50%; transform: translateX(-50%);
    width: min(460px, calc(100vw - 32px));
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 8px 32px rgba(0,0,0,.32);
    z-index: 1050;               /* above the map, below Bootstrap modals (1055) */
    overflow: hidden;
    font-size: 13px;
}
#tinCmdInput {
    width: 100%; box-sizing: border-box;
    border: none; border-bottom: 1px solid #ddd;
    padding: 11px 14px; font-size: 15px;
}
#tinCmdInput:focus { outline: none; border-bottom-color: #0d6efd; }
#tinCmdList { max-height: 50vh; overflow-y: auto; scrollbar-width: thin; }
.tin-cmd-row {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 14px; cursor: pointer; border-bottom: 1px solid #f1f3f5;
}
.tin-cmd-row:last-child { border-bottom: none; }
.tin-cmd-row.active { background: #eef4ff; }
.tin-cmd-row:hover  { background: #f5f8ff; }
.tin-cmd-label   { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tin-cmd-section { flex: 0 0 auto; font-size: 10px; color: #8b9099; text-transform: uppercase; letter-spacing: .05em; }
.tin-cmd-state {
    flex: 0 0 auto; font-size: 10px; font-weight: 700;
    border-radius: 8px; padding: 1px 6px; text-transform: uppercase;
}
.tin-cmd-state.on  { background: #dcfce7; color: #15803d; }
.tin-cmd-state.off { background: #f1f3f5; color: #868e96; }
.tin-cmd-empty { padding: 14px; color: #868e96; text-align: center; }

.fpm-asset-toolbar .tin-panel button {
    background: none;
    border: none;
    border-bottom: 1px solid #ddd;
    padding: 7px 12px;
    text-align: left;
    cursor: pointer;
    color: #333;
    white-space: nowrap;
    line-height: 1.4;
    display: flex;
    align-items: center;
    gap: 7px;
}
.fpm-asset-toolbar .tin-panel button:hover { background: #f0f4ff; color: #0d6efd; }
.tgl-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #22c55e; border: 1px solid rgba(0,0,0,.15);
    flex-shrink: 0; margin-left: auto; transition: background .15s;
}
.tgl-off .tgl-dot { background: #d1d5db; }
.tgl-off { opacity: 0.6; }
/* Pole filter panel */
.fpm-filter-panel {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 5px rgba(0,0,0,.3);
    padding: 8px 10px;
    min-width: 190px;
    font-size: 13px;
    margin-top: 4px;
    /* Clear the icon rail so the panel opens beside it, not underneath it. */
    margin-left: 44px;
}
.filter-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 7px;
}
.filter-panel-header button {
    background: none; border: none; cursor: pointer;
    font-size: 14px; line-height: 1; padding: 0 2px; color: #666;
}
.filter-panel-header button:hover { color: #000; }
.filter-panel-fields {
    display: flex; flex-direction: column; gap: 5px;
}
.filter-panel-fields label {
    display: flex; flex-direction: column; gap: 2px;
    font-size: 11px; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: .03em;
}
.filter-panel-fields input {
    border: 1px solid #ccc; border-radius: 3px;
    padding: 3px 6px; font-size: 12px; font-weight: 400;
    width: 100%; box-sizing: border-box;
}
.filter-panel-fields input:focus { outline: 2px solid #0d6efd; border-color: transparent; }
.filter-panel-toggle {
    flex-direction: row !important; align-items: center; gap: 6px !important;
    font-size: 11px; font-weight: 600; color: #555; cursor: pointer; margin-top: 3px;
}
.filter-panel-toggle input[type=checkbox] { width: auto; cursor: pointer; }
.filter-panel-actions {
    margin-top: 7px; display: flex; gap: 5px;
}
.filter-panel-actions button {
    flex: 1; border: 1px solid #ccc; background: #f8f9fa;
    border-radius: 3px; padding: 3px 0; cursor: pointer; font-size: 12px;
}
.filter-panel-actions button:hover { background: #e2e6ea; }
.filter-status {
    margin-top: 5px; font-size: 11px; color: #555; min-height: 14px;
}
.pole-filter-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: #e63946; color: #fff; border-radius: 8px;
    min-width: 16px; height: 16px; font-size: 10px; font-weight: 700;
    padding: 0 4px; line-height: 1;
}
.tin-rail-btn .pole-filter-badge { position: absolute; top: 2px; right: 2px; }
/* Popup action buttons (Edit, Move, Delete and the rest): one look on every asset */
.tl-box { margin-top: 8px; padding-top: 6px; border-top: 1px solid #e5e7eb; max-width: 260px; }
.tl-head { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
.tl-entry { margin: 4px 0; font-size: 12px; }
.tl-when { color: #6b7280; font-size: 11px; display: flex; justify-content: space-between; }
.tl-del { border: 0; background: none; color: #9ca3af; cursor: pointer; line-height: 1; padding: 0 2px; }
.tl-del:hover { color: #dc2626; }
.tl-text { white-space: pre-wrap; }
.tl-pics { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 3px; }
.tl-pics img { height: 56px; width: 56px; object-fit: cover; border-radius: 3px; }
.tl-lb { position: fixed; inset: 0; z-index: 20000; background: rgba(0,0,0,.88); display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: zoom-out; }
.tl-lb img { max-width: 94vw; max-height: 86vh; object-fit: contain; cursor: default; box-shadow: 0 4px 24px rgba(0,0,0,.5); }
.tl-lb-cap { color: #e5e7eb; font-size: 13px; margin-top: 10px; max-width: 90vw; text-align: center; }
.tl-lb-x { position: absolute; top: 10px; right: 18px; background: none; border: 0; color: #fff; font-size: 34px; line-height: 1; cursor: pointer; opacity: .85; }
.tl-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,.12); border: 0; color: #fff; font-size: 44px; line-height: 1; padding: 6px 16px; border-radius: 6px; cursor: pointer; }
.tl-lb-prev { left: 14px; } .tl-lb-next { right: 14px; }
.tl-form textarea { width: 100%; font-size: 12px; margin-top: 4px; }
.tl-formrow { display: flex; gap: 4px; align-items: center; }
.tl-formrow input { flex: 1; min-width: 0; font-size: 11px; }
.tin-acts { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; align-items: center; }
.tin-acts-gap { flex: 1 1 8px; }
.tin-act {
    display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; font: 500 12px/1.2 system-ui, sans-serif;
    color: #374151; background: #f9fafb; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; text-decoration: none; white-space: nowrap;
}
.tin-act:hover { background: #eef2f7; border-color: #9ca3af; color: #111827; text-decoration: none; }
.tin-act .tin-act-ico { flex: none; }
.tin-act-edit  { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
.tin-act-edit:hover { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
.tin-act-move  { color: #0f766e; background: #f0fdfa; border-color: #99f6e4; }
.tin-act-move:hover { background: #ccfbf1; border-color: #5eead4; color: #115e59; }
.tin-act-save  { color: #fff; background: #1d4ed8; border-color: #1d4ed8; }
.tin-act-save:hover { background: #1e40af; border-color: #1e40af; color: #fff; }
.tin-act-del   { margin-left: auto; color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.tin-act-del:hover { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
.leaflet-popup-content .tin-acts { max-width: 330px; }
/* Saved dimensions: ink text with a white halo, no chip, along the line it measures */
.easement-label{background:none;border:0;pointer-events:none}
    .easement-label span{position:absolute;left:0;top:0;transform-origin:50% 50%;white-space:nowrap;font:600 10px/1 system-ui,sans-serif;text-shadow:0 0 2px #fff,0 0 2px #fff,0 0 3px #fff;translate:-50% -50%}
    .dim-label {
    position: absolute; white-space: nowrap; pointer-events: auto; cursor: pointer;
    font: 600 11px/1 system-ui, sans-serif; color: #1f2937;
    text-shadow: 0 0 3px #fff, 0 0 3px #fff, 0 0 2px #fff, 0 0 2px #fff;
    transform-origin: center;
}
#btnDimension.active { background: #dbeafe; }
/* Sheath marks: a small dot on the cable with the meter marks beside it, only when zoomed in close */
.mark-dot{position:absolute;left:-3px;top:-3px;width:7px;height:7px;border-radius:50%;background:#fff;border:2px solid #7c3aed;box-sizing:border-box}
.mark-label{position:absolute;left:8px;top:-7px;white-space:nowrap;pointer-events:none;font:600 11px/1.2 system-ui,sans-serif;color:#4c1d95;text-shadow:0 0 2px #fff,0 0 2px #fff,0 0 3px #fff,0 0 3px #fff}
.mark-label i{font-weight:400;color:#6b7280;font-style:normal}
.mark-serial{font-weight:700;color:#111827}
/* GeoNB community names: soft violet text with a white halo, centred on the community */
.community-label span {
    position: absolute; transform: translate(-50%, -50%); white-space: nowrap; pointer-events: none;
    font: 600 13px/1 system-ui, sans-serif; letter-spacing: .02em; color: #5b21b6;
    text-shadow: 0 0 3px #fff, 0 0 3px #fff, 0 0 2px #fff, 0 0 2px #fff;
}
/* Measurement tool labels */
.measure-label {
    background: rgba(230,57,70,.85); color: #fff;
    padding: 1px 5px; border-radius: 3px; font-size: 11px; font-weight: 600;
    white-space: nowrap; pointer-events: none;
}
.measure-label-total {
    background: rgba(30,30,30,.85); font-size: 12px;
}
.measure-cursor-tip {
    background: rgba(230,57,70,.85) !important; color: #fff !important;
    border: none !important; box-shadow: none !important;
    font-size: 11px; font-weight: 600; padding: 2px 6px !important;
}
.measure-cursor-tip::before { display: none !important; }
#btnMeasure.measure-done .tgl-dot { background: #e63946; }
.fpm-asset-toolbar .asset-btn-icon {
    width: 16px;
    height: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 16px;
}
.fpm-asset-toolbar .asset-btn-icon svg {
    display: block;
    max-width: 16px;
    max-height: 16px;
    width: auto;
    height: auto;
}
/* Note icon selector tiles */
.note-icon-opt {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: 6px 8px;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    line-height: 1.2;
    color: #495057;
    user-select: none;
    transition: border-color 0.15s, background 0.15s;
}
.note-icon-opt:hover { border-color: #0d6efd; background: #f0f4ff; }
.note-icon-opt.active { border-color: #0d6efd; background: #e8f0fe; color: #0d6efd; font-weight: 600; }
#mapToastContainer {
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 1200;
    min-width: 220px;
}
@page { size: landscape; } /* default; overridden by #mapPrintOrientStyle before each print */
@media print {
    /* Hide layout chrome */
    body.map-print-mode nav.navbar,
    body.map-print-mode aside,
    body.map-print-mode #mapPageHeader,
    body.map-print-mode #mapToastContainer,
    body.map-print-mode .map-toolbar,
    body.map-print-mode #mapEditBar,
    body.map-print-mode #mapValidationAlert,
    body.map-print-mode nav[aria-label="breadcrumb"] {
        display: none !important;
    }
    /* Strip all wrapper padding/margins so map fills the page */
    html, body { margin: 0 !important; padding: 0 !important; }
    body.map-print-mode .nav-rail { display: none !important; }
    body.map-print-mode .app-shell,
    body.map-print-mode main,
    body.map-print-mode .position-relative { padding: 0 !important; margin: 0 !important; }
    /* Expand the map canvas to fill the print area */
    /* Same reasoning as the eng-mode rule above — the inline pinned size wins. */
    body.map-print-mode #mapCanvas {
        position: fixed !important;
        top: 0 !important; left: 0 !important;
        z-index: 9999 !important;
    }
}
CSS;
});

$PAGE->setContentRenderer(function () use ($closureTypes, $cables, $microductTypes, $allPops, $cableMaterials, $vaultModels) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-2" id="mapPageHeader">
      <h5 class="mb-0">Network Map</h5>
      <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
        <div id="mapValidationAlert" style="display:none;position:relative">
          <div id="mapValidationSummary" style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:#92400e;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px;padding:2px 8px;user-select:none">
            <span>&#x26A0;</span><span id="mapValidationCount"></span><span style="opacity:.6;font-size:.72rem">&#x25BE;</span>
          </div>
          <div id="mapValidationDetail" style="display:none;position:absolute;z-index:500;right:0;margin-top:3px;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px;padding:6px 10px;font-size:.78rem;color:#78350f;max-width:340px"></div>
        </div>
        <div id="mapConflictAlert" style="display:none;position:relative">
          <div id="mapConflictSummary" style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:#92400e;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px;padding:2px 8px;user-select:none" title="Poles where Alden One disagrees with our data">
            <span>&#x26A0;</span><span id="mapConflictCount"></span><span style="opacity:.6;font-size:.72rem">&#x25BE;</span>
          </div>
          <div id="mapConflictDetail" style="display:none;position:absolute;z-index:500;right:0;margin-top:3px;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px;padding:6px 10px;font-size:.78rem;color:#78350f;max-width:380px;max-height:60vh;overflow:auto"></div>
        </div>
        <button class="btn btn-outline-secondary btn-sm" id="btnPrintOrient" onclick="togglePrintOrient()" title="Toggle print orientation (Landscape / Portrait)" style="font-size:.78rem;padding:2px 7px"></button>
        <button class="btn btn-outline-secondary btn-sm" onclick="fpmPrintMap()" title="Print map">&#x1F5A8; Print</button>
        <button class="btn btn-outline-secondary btn-sm ms-1" onclick="window.fpmStartPlanFraming && window.fpmStartPlanFraming()" title="Frame an engineering plan: drag the area you want, then print">&#x1F4D0; Plan</button>
      </div>
    </div>

    <!-- Floating controls shown only in on-screen plan view (?plan=N) -->
    <div id="planViewBar">
      <button class="btn btn-success btn-sm" id="planViewPdfBtn" title="Render this sheet server-side at an exact 1:1 scale and download it">&#x2B07; PDF</button>
      <button class="btn btn-outline-success btn-sm" onclick="fpmPlanPrint()" title="Print via the browser dialog — set Scale to Default/100 or the sheet scale will be wrong">&#x1F5A8; Print</button>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.close(); history.length>1 && history.back();" title="Close plan view">&#x2715;</button>
    </div>

    <!-- Active-filter indicator: the filter controls live inside the toolbar
         flyout, which is closed most of the time, so an active filter would
         otherwise be invisible while it is hiding assets. -->
    <div id="mapFilterChip" style="display:none">
      <span id="mapFilterDot"></span>
      <span id="mapFilterChipText"></span>
      <button type="button" id="mapFilterChipOpen" title="Open the filter panel">Edit</button>
      <button type="button" id="mapFilterChipClear" title="Clear all filters">Clear</button>
    </div>

    <!-- Plan framing mode: live sheet outline + actions (screen only) -->
    <div id="planFrameBar">
      <div id="planFrameInfo">
        <strong>Plan area</strong>
        <span id="planFrameHint">— click to place the sheet, or drag a box: it is drawn at the detail level and fitted to the page</span>
      </div>
      <div id="planFrameStats"></div>
      <select class="form-select form-select-sm w-auto" id="planFramePaper" title="Paper size"></select>
      <button class="btn btn-outline-secondary btn-sm" id="planFrameOrient" title="Toggle sheet orientation"></button>
      <button class="btn btn-outline-secondary btn-sm" id="planFrameBasemap" title="Basemap for print. Legible: largest street lettering that prints sharp (150–300 dpi) — for the plotter. Sharp: one tile level deeper, embedded at up to ~600 dpi, for a PDF you zoom on screen; lettering is half the size."></button>
      <div class="btn-group btn-group-sm" role="group" title="Detail level: the zoom the sheet is drawn at. Icons and lettering are a fixed size, so a lower zoom makes them larger against the ground (z17–z20; pole numbers need z17 or more)">
        <button class="btn btn-outline-secondary" id="planFrameZoomOut" title="Larger icons and lettering (lower zoom)">&minus;</button>
        <span class="btn btn-outline-secondary disabled" id="planFrameZoomText" style="opacity:1;color:#333;font-family:monospace">z20</span>
        <button class="btn btn-outline-secondary" id="planFrameZoomIn" title="Finer detail (higher zoom)">+</button>
      </div>
      <button class="btn btn-outline-secondary btn-sm" id="planFrameSavedBtn" title="Saved plans">&#x1F4C2; Saved</button>
      <button class="btn btn-outline-primary btn-sm" id="planFrameSaveBtn" title="Save this framing as a reusable plan">Save as plan</button>
      <button class="btn btn-success btn-sm" id="planFramePdfBtn" title="Render this sheet server-side at an exact 1:1 scale and download it">&#x2B07; PDF</button>
      <button class="btn btn-outline-success btn-sm" id="planFramePrintBtn" title="Print via the browser dialog — set Scale to Default/100 or the sheet scale will be wrong">&#x1F5A8; Print</button>
      <button class="btn btn-outline-secondary btn-sm" id="planFrameCancelBtn" title="Leave framing mode (Esc)">&#x2715;</button>
      <div id="planFrameSavedList" style="display:none"></div>
    </div>

    <!-- Save the current framing as a reusable plan -->
    <div class="modal fade" id="planSaveModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">Save as plan</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label small fw-semibold mb-1">Title</label>
            <input type="text" class="form-control form-control-sm" id="planSaveTitle" maxlength="200"
                   placeholder="e.g. Salisbury west feeder">
            <label class="form-label small fw-semibold mb-1 mt-2">Attach to</label>
            <select class="form-select form-select-sm" id="planSaveTarget">
              <option value="adhoc|map">Map — saved plans list</option>
            </select>
            <div class="form-text small">Saves the sheet, not a picture of it: centre, zoom, paper, fit,
              layers, basemap and fade, and the work-order filter. Reopening redraws it from live data.</div>
            <div class="text-danger small mt-1" id="planSaveError"></div>
          </div>
          <div class="modal-footer py-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="planSaveBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Engineering plan overlay — only visible in map-eng-mode print -->
    <div id="engPlanOverlay">
      <div id="engPlanFrame">
        <div id="engNorthArrow">
          <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%">
            <circle cx="50" cy="50" r="45" fill="none" stroke="#000" stroke-width="2"/>
            <polygon points="50,8 57,50 50,44 43,50" fill="#000"/>
            <polygon points="50,92 57,50 50,56 43,50" fill="#fff" stroke="#000" stroke-width="1.5"/>
            <circle cx="50" cy="50" r="4" fill="#000"/>
            <text x="50" y="20" text-anchor="middle" font-size="16" font-weight="bold" font-family="Arial,sans-serif" fill="#000">N</text>
            <text x="50" y="88" text-anchor="middle" font-size="11" font-family="Arial,sans-serif" fill="#000">S</text>
            <text x="12" y="54" text-anchor="middle" font-size="11" font-family="Arial,sans-serif" fill="#000">W</text>
            <text x="88" y="54" text-anchor="middle" font-size="11" font-family="Arial,sans-serif" fill="#000">E</text>
          </svg>
        </div>
        <div id="engTitleBlock">
          <div id="engLogoCell">
            <img id="engLogo" src="<?= h(org('logo')) ?>" alt="<?= h(org('long_name')) ?>">
          </div>
          <div id="engTitleInfo">
            <div class="eng-title">Network Infrastructure Plan</div>
            <div class="eng-meta">
              <span id="engDate"></span>
              <span id="engCoords"></span>
              <span id="engScaleRatio"></span>
            </div>
            <div id="engScaleBar" style="margin-top:2.5mm">
              <div id="engScaleBarLine"></div>
              <div id="engScaleBarLabel"></div>
            </div>
          </div>
          <div id="engLegend">
            <div class="eng-leg-head">Legend</div>
            <div id="engLegendGrid"></div>
          </div>
          <div id="engSheetInfo">
            <div style="font-weight:bold">Tinuum Networks Inc.</div>
            <div>Colpitts Settlement, NB</div>
            <div id="engZoom" style="margin-top:2mm;font-size:6pt;color:#555"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit bar floats inside the map canvas area -->
    <div class="position-relative">
      <div id="mapCanvas"></div>

      <!-- Wraps rather than running off the map: the road-coverage bar has a dozen buttons. -->
      <div id="mapEditBar" style="display:none;position:absolute;top:10px;left:50%;
           transform:translateX(-50%);z-index:1000;width:max-content;max-width:calc(100% - 140px)">
        <div class="alert alert-info d-flex align-items-center flex-wrap gap-1 py-2 px-3 shadow mb-0">
          <span id="mapEditMsg"></span>
          <span id="mapEditActions" class="d-flex flex-wrap align-items-center gap-1"></span>
        </div>
      </div>
    </div>

    <div id="mapToastContainer" aria-live="polite" aria-atomic="true"></div>

    <!-- ── Pole details modal (create/edit) ─────────────────────────────── -->
    <div class="modal fade" id="poleModal" tabindex="-1"
         aria-labelledby="poleModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="poleModalLabel">New Pole</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="poleModalError" style="display:none"></div>
            <input type="hidden" id="poleIdInput">

            <div class="mb-2">
              <label class="form-label small fw-semibold">Placed at</label>
              <div class="form-control-plaintext py-0 small text-muted" id="poleCoordDisplay">—</div>
            </div>

            <div class="mb-2">
              <label for="poleUid" class="form-label small fw-semibold">UID</label>
              <input type="text" class="form-control form-control-sm text-uppercase"
                     id="poleUid" maxlength="10" autocomplete="off">
            </div>

            <div class="mb-2">
              <label for="poleType" class="form-label small fw-semibold">Type</label>
              <input type="text" class="form-control form-control-sm"
                     id="poleType" maxlength="80" value="wood">
            </div>

            <div class="mb-2">
              <label for="poleOwner" class="form-label small fw-semibold">Owner</label>
              <select class="form-select form-select-sm" id="poleOwner">
                <option value="" selected></option>
                <option value="Bell Aliant">Bell Aliant</option>
                <option value="NB Power">NB Power</option>
                <option value="<?= h(org('own_name')) ?>"><?= h(org('own_name')) ?></option>
                <option value="Private">Private (customer-owned)</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="poleNbp" class="form-label small fw-semibold">NBP#</label>
              <input type="text" class="form-control form-control-sm"
                     id="poleNbp" maxlength="18">
            </div>

            <div class="mb-2">
              <label for="poleTelco" class="form-label small fw-semibold">Telco#</label>
              <input type="text" class="form-control form-control-sm"
                     id="poleTelco" maxlength="18">
            </div>

            <div class="mb-2">
              <label for="poleTags" class="form-label small fw-semibold">Tags#</label>
              <input type="text" class="form-control form-control-sm"
                     id="poleTags" maxlength="30">
            </div>

            <div class="mb-1">
              <label for="poleWorkOrder" class="form-label small fw-semibold">Tinuum Permit# <small class="text-muted fw-normal">(primary / billing)</small></label>
              <input type="text" class="form-control form-control-sm"
                     id="poleWorkOrder" maxlength="80" autocomplete="off" data-permit-picker>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Additional Permits</label>
              <div id="poleExtraPermitsChips" class="d-flex flex-wrap gap-1 mb-1" style="min-height:22px"></div>
              <input type="text" class="form-control form-control-sm" id="poleExtraPermitAdd"
                     autocomplete="off" data-permit-picker data-permit-multi placeholder="Add permit…">
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Bell Permit# <small class="text-muted fw-normal">(managed via Bell Permits page)</small></label>
              <input type="text" class="form-control form-control-sm"
                     id="poleBellPermit" readonly tabindex="-1"
                     style="background:#f8f9fa;color:#6c757d">
            </div>

            <div class="mb-1">
              <label for="poleComment" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="poleComment" rows="2" maxlength="254"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="savePoleBtn">Save Pole</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Pole multi-edit modal ─────────────────────────────────────────── -->
    <div class="modal fade" id="poleMultiEditModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Edit Poles &mdash; <span id="poleMultiEditCount"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info py-2 small mb-3">
              Check a field to apply it to all selected poles. Unchecked fields keep their current values.
            </div>
            <div class="alert alert-danger" id="poleMultiEditError" style="display:none"></div>

            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiTypeCheck" style="margin-top:0">
              <label for="poleMultiType" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">Type</label>
              <input type="text" class="form-control form-control-sm" id="poleMultiType" maxlength="80" disabled>
            </div>
            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiOwnerCheck" style="margin-top:0">
              <label for="poleMultiOwner" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">Owner</label>
              <select class="form-select form-select-sm" id="poleMultiOwner" disabled>
                <option value=""></option>
                <option value="Bell Aliant">Bell Aliant</option>
                <option value="NB Power">NB Power</option>
                <option value="<?= h(org('own_name')) ?>"><?= h(org('own_name')) ?></option>
                <option value="Private">Private (customer-owned)</option>
              </select>
            </div>
            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiNbpCheck" style="margin-top:0">
              <label for="poleMultiNbp" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">NBP#</label>
              <input type="text" class="form-control form-control-sm" id="poleMultiNbp" maxlength="18" disabled>
            </div>
            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiTelcoCheck" style="margin-top:0">
              <label for="poleMultiTelco" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">Telco#</label>
              <input type="text" class="form-control form-control-sm" id="poleMultiTelco" maxlength="18" disabled>
            </div>
            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiTagsCheck" style="margin-top:0">
              <label for="poleMultiTags" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">Tags#</label>
              <input type="text" class="form-control form-control-sm" id="poleMultiTags" maxlength="30" disabled>
            </div>
            <div class="mb-2 d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0" id="poleMultiWorkOrderCheck" style="margin-top:0">
              <label for="poleMultiWorkOrder" class="form-label small fw-semibold mb-0" style="min-width:4.5rem">Tinuum Permit#</label>
              <input type="text" class="form-control form-control-sm" id="poleMultiWorkOrder" maxlength="80" autocomplete="off" data-permit-picker disabled>
            </div>
            <div class="mb-1 d-flex align-items-start gap-2">
              <input type="checkbox" class="form-check-input flex-shrink-0 mt-1" id="poleMultiCommentCheck">
              <label for="poleMultiComment" class="form-label small fw-semibold mb-0 mt-1" style="min-width:4.5rem">Notes</label>
              <textarea class="form-control form-control-sm" id="poleMultiComment" rows="2" maxlength="254" disabled></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="savePoleMultiBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New Closure modal ──────────────────────────────────────────────── -->
    <div class="modal fade" id="newFoscModal" tabindex="-1"
         aria-labelledby="newFoscModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="newFoscModalLabel">New Closure</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="foscModalError" style="display:none"></div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Placed at</label>
              <div class="form-control-plaintext py-0 small text-muted" id="foscCoordDisplay">—</div>
            </div>

            <div class="mb-2">
              <label for="foscSpliceNum" class="form-label small fw-semibold">
                Splice number <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control form-control-sm text-uppercase"
                     id="foscSpliceNum" placeholder="ABC1234" maxlength="7" autocomplete="off"
                     pattern="^([A-Za-z]{3}[0-9]{4}|[LRlr][0-9]{2})$">
              <div class="form-text">ABC1234 &nbsp;or&nbsp; L/R + 2 digits (e.g. L01, R03)</div>
            </div>

            <div class="mb-2">
              <label for="foscKind" class="form-label small fw-semibold">Kind</label>
              <select class="form-select form-select-sm" id="foscKind">
                <option value="closure" selected>Closure</option>
                <option value="slack">Slack loop</option>
                <option value="terminal">Terminal</option>
                <option value="cabinet">Cabinet (FDC)</option>
              </select>
              <div class="form-text">A slack loop is a coil of cable, not a splice point.</div>
            </div>

            <div class="mb-2">
              <label for="foscStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="foscStatus">
                <option value="plan" selected>Plan</option>
                <option value="under construction">Under Construction</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="foscTypeId" class="form-label small fw-semibold">
                Type <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="foscTypeId">
                <option value="">— select —</option>
                <?php foreach ($closureTypes as $ct): ?>
                  <option value="<?= h($ct['closure_type_id']) ?>"><?= h($ct['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label for="foscFpmWor" class="form-label small fw-semibold">Tinuum WOR</label>
              <input type="text" class="form-control form-control-sm" id="foscFpmWor"
                     maxlength="64" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-1">
              <label for="foscNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="foscNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveFoscBtn">Create Closure</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New MST modal ─────────────────────────────────────────────────── -->
    <div class="modal fade" id="newMstModal" tabindex="-1"
         aria-labelledby="newMstModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="newMstModalLabel">&#x2B22; New MST</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="mstModalError" style="display:none"></div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Placed at</label>
              <div class="form-control-plaintext py-0 small text-muted" id="mstCoordDisplay">—</div>
            </div>

            <div class="mb-2">
              <label for="mstSpliceNum" class="form-label small fw-semibold">
                Splice number <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control form-control-sm text-uppercase"
                     id="mstSpliceNum" placeholder="L01-MST" maxlength="8" autocomplete="off"
                     pattern="^[LRlr][0-9]{2}-MST$">
              <div class="form-text">L/R + 2 digits + -MST &nbsp;(e.g. L01-MST, R04-MST)</div>
            </div>

            <div class="mb-2">
              <label for="mstStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="mstStatus">
                <option value="plan" selected>Plan</option>
                <option value="under construction">Under Construction</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">
                Port count <span class="text-danger">*</span>
              </label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="mstPorts"
                         id="mstPorts4" value="4" checked>
                  <label class="form-check-label small" for="mstPorts4">4-port (1&times;4 PLC)</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="mstPorts"
                         id="mstPorts2" value="2">
                  <label class="form-check-label small" for="mstPorts2">2-port (1&times;2 PLC)</label>
                </div>
              </div>
            </div>

            <div class="mb-1">
              <label for="mstNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="mstNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveMstBtn">Create MST</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New Span modal ─────────────────────────────────────────────────── -->
    <div class="modal fade" id="newSpanModal" tabindex="-1"
         aria-labelledby="newSpanModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="newSpanModalLabel">New Cable Span</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="spanModalError" style="display:none"></div>
            <input type="hidden" id="spanGeojsonInput">

            <div class="mb-2">
              <label for="spanCableId" class="form-label small fw-semibold">
                Cable <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="spanCableId">
                <option value="">— select —</option>
                <?php foreach ($cables as $c): ?>
                  <option value="<?= h($c['cable_id']) ?>">
                    <?= h($c['name']) ?> (<?= h($c['fibre_count']) ?>F)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="d-flex gap-3 mb-2">
              <div class="flex-fill">
                <div class="form-label small fw-semibold mb-0">From closure</div>
                <div id="spanFromDisplay" class="small py-1 text-muted fst-italic">draw to detect</div>
              </div>
              <div class="flex-fill">
                <div class="form-label small fw-semibold mb-0">To closure</div>
                <div id="spanToDisplay" class="small py-1 text-muted fst-italic">draw to detect</div>
              </div>
            </div>

            <!-- Shown only for open-ended (FlexTIN) cables when endpoint has no FOSC -->
            <div id="spanTapSection" style="display:none" class="mb-2">
              <div class="alert alert-info py-2 px-3 mb-2 small">
                No closure at endpoint — a new optitap will be created here.
              </div>
              <div class="d-flex gap-2">
                <div class="flex-fill">
                  <label for="spanTapNum" class="form-label small fw-semibold mb-1">
                    Tap number <span class="text-danger">*</span>
                  </label>
                  <input type="text" class="form-control form-control-sm text-uppercase"
                         id="spanTapNum" placeholder="L01" maxlength="3" autocomplete="off">
                  <div class="form-text">L or R + 2 digits</div>
                </div>
                <div>
                  <label for="spanTapStatus" class="form-label small fw-semibold mb-1">Status</label>
                  <select class="form-select form-select-sm" id="spanTapStatus">
                    <option value="plan" selected>Plan</option>
                    <option value="under construction">Under Construction</option>
                    <option value="active">Active</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="mb-2">
              <label for="spanFpmWor" class="form-label small fw-semibold">Tinuum Permit #</label>
              <input type="text" class="form-control form-control-sm" id="spanFpmWor"
                     maxlength="64" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-1">
              <label for="spanNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="spanNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveSpanBtn">Create Span</button>
          </div>
        </div>
      </div>
    </div>
    <!-- ── Edit Closure Properties modal ───────────────────────────────── -->
    <div class="modal fade" id="editFoscModal" tabindex="-1"
         aria-labelledby="editFoscModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editFoscModalLabel">Edit Closure</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="editFoscModalError" style="display:none"></div>
            <input type="hidden" id="editFoscId">

            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Name <span class="text-muted fw-normal">(splice #)</span></label>
                <input type="text" class="form-control form-control-sm font-monospace text-uppercase"
                       id="editFoscSpliceNum" maxlength="12" autocomplete="off"
                       placeholder="e.g. COL6608">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-semibold">Status</label>
                <select class="form-select form-select-sm" id="editFoscStatus">
                  <option value="plan">Plan</option>
                  <option value="under construction">Under Construction</option>
                  <option value="active">Active</option>
                  <option value="retired">Retired</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-semibold">Kind</label>
                <select class="form-select form-select-sm" id="editFoscKind">
                  <option value="closure">Closure</option>
                  <option value="slack">Slack loop</option>
                  <option value="mst">MST</option>
                  <option value="terminal">Terminal</option>
                  <option value="cabinet">Cabinet (FDC)</option>
                  <option value="optitap">Optitap</option>
                </select>
              </div>

              <div class="col-md-4" id="editFoscSlackWrap" style="display:none">
                <label class="form-label small fw-semibold" for="editFoscSlackLen">Slack length (m)</label>
                <input type="number" class="form-control form-control-sm" id="editFoscSlackLen"
                       min="0.1" max="500" step="0.1" placeholder="30" autocomplete="off">
                <div class="form-text">Cable used here. Blank = standard 30 m coil. A dead-end
                  hairpin is twice the stub length.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">Closure Type</label>
                <select class="form-select form-select-sm" id="editFoscTypeId">
                  <?php foreach ($closureTypes as $ct): ?>
                    <option value="<?= h($ct['closure_type_id']) ?>"><?= h($ct['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">Enclosure Rating</label>
                <select class="form-select form-select-sm" id="editFoscRating">
                  <option value="outside">Outside-rated</option>
                  <option value="inside">Inside-rated</option>
                </select>
                <div class="form-text small">Inside-rated closures allow patch cables; outside-plant does not.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">POP</label>
                <select class="form-select form-select-sm" id="editFoscPopId">
                  <option value="0">— none —</option>
                  <?php foreach ($allPops as $p): ?>
                    <option value="<?= (int)$p['pop_id'] ?>"><?= h($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text small">Group this closure into a Point of Presence.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">Street Address</label>
                <input type="text" class="form-control form-control-sm" id="editFoscStreetAddress"
                       maxlength="200" autocomplete="off" placeholder="e.g. 123 Main St, Moncton">
                <div class="form-text small">Nearest street address to this closure.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">Tinuum Permit # <small class="text-muted fw-normal">(primary)</small></label>
                <input type="text" class="form-control form-control-sm" id="editFoscFpmWor"
                       maxlength="64" autocomplete="off" data-permit-picker>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold">Additional Permits</label>
                <div id="foscExtraPermitsChips" class="d-flex flex-wrap gap-1 mb-1" style="min-height:22px"></div>
                <input type="text" class="form-control form-control-sm" id="foscExtraPermitAdd"
                       autocomplete="off" data-permit-picker data-permit-multi placeholder="Add permit…">
              </div>

              <div class="col-12">
                <label class="form-label small fw-semibold">Notes</label>
                <textarea class="form-control form-control-sm" id="editFoscNotes" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-outline-secondary btn-sm me-auto" id="editFoscOpenPage"
               target="_blank" rel="noopener">Open full closure page ↗</a>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveEditFoscBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Edit Span Properties modal ───────────────────────────────────── -->
    <div class="modal fade" id="editSpanModal" tabindex="-1"
         aria-labelledby="editSpanModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editSpanModalLabel">Edit Span</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="editSpanModalError" style="display:none"></div>
            <input type="hidden" id="editSpanId">

            <!-- Read-only context so the form is informative, not a bare permit/notes pair -->
            <dl class="row small mb-3 gy-1" id="editSpanInfo">
              <dt class="col-4 text-muted fw-normal">Span</dt>
              <dd class="col-8 mb-0" id="editSpanInfoId">&mdash;</dd>
              <dt class="col-4 text-muted fw-normal">Cable</dt>
              <dd class="col-8 mb-0" id="editSpanInfoCable">&mdash;</dd>
              <dt class="col-4 text-muted fw-normal">Route</dt>
              <dd class="col-8 mb-0" id="editSpanInfoRoute">&mdash;</dd>
              <dt class="col-4 text-muted fw-normal">Length</dt>
              <dd class="col-8 mb-0" id="editSpanInfoLength">&mdash;</dd>
              <dt class="col-4 text-muted fw-normal">Status</dt>
              <dd class="col-8 mb-0" id="editSpanInfoStatus">&mdash;</dd>
            </dl>

            <!-- Cable type + fibre count live on the parent cable, so they apply to
                 the whole cable (every span of it), not just this hop. -->
            <div class="mb-2">
              <label class="form-label small fw-semibold" for="editSpanMaterial">Cable type</label>
              <select class="form-select form-select-sm" id="editSpanMaterial">
                <option value="">— unset —</option>
                <?php foreach ($cableMaterials as $m): ?>
                  <option value="<?= h($m['id']) ?>"><?= h($m['description'] !== '' ? $m['description'] : $m['sku']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="editSpanFibreCount">Fibre count</label>
              <input type="number" class="form-control form-control-sm" id="editSpanFibreCount"
                     min="1" max="864" step="1" autocomplete="off">
              <div class="form-text">Applies to the whole cable, not just this span.</div>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Tinuum Permit # <small class="text-muted fw-normal">(primary)</small></label>
              <input type="text" class="form-control form-control-sm" id="editSpanFpmWor"
                     maxlength="64" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Additional Permits</label>
              <div id="spanExtraPermitsChips" class="d-flex flex-wrap gap-1 mb-1" style="min-height:22px"></div>
              <input type="text" class="form-control form-control-sm" id="spanExtraPermitAdd"
                     autocomplete="off" data-permit-picker data-permit-multi placeholder="Add permit…">
            </div>

            <div class="mb-2 form-check">
              <input class="form-check-input" type="checkbox" id="editSpanInstalled">
              <label class="form-check-label small" for="editSpanInstalled">
                Cable installed
              </label>
              <div class="form-text">The cable is physically up on this span. It then shows as active even while a
                closure or cabinet at either end is still plan.</div>
            </div>

            <div class="mb-2 form-check">
              <input class="form-check-input" type="checkbox" id="editSpanIsPrivate">
              <label class="form-check-label small" for="editSpanIsPrivate">
                Private client link
              </label>
              <div class="form-text">Cable for a client that is not part of the Tinuum network. Drawn dashed purple and left
                out of route tracing, so it never reads as joined to our plant.</div>
            </div>

            <div class="mb-2 form-check">
              <input class="form-check-input" type="checkbox" id="editSpanIsSpare">
              <label class="form-check-label small" for="editSpanIsSpare">
                Spare / deliberately dark
              </label>
              <div class="form-text">Cable is placed on purpose with nothing spliced — stops the plant audit
                reporting it as an unfinished record.</div>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="editSpanCableSerial">Cable serial</label>
              <input class="form-control form-control-sm" id="editSpanCableSerial" maxlength="60" placeholder="Cable ID on the sheath, e.g. 30081677">
              <div class="form-text">From the contractor as-built. Sheath marks on this span read off this cable.</div>
            </div>

            <div class="mb-1">
              <label class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="editSpanNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveEditSpanBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Building Anchor modal (create / edit) ─────────────────────────── -->
    <div class="modal fade" id="buildingAnchorModal" tabindex="-1"
         aria-labelledby="buildingAnchorModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="buildingAnchorModalLabel">New Building Anchor</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="buildingAnchorModalError" style="display:none"></div>
            <input type="hidden" id="buildingAnchorId">

            <div class="mb-2 small text-muted">
              A change-of-direction support point on a building. Strands can terminate here;
              cables still terminate at closures.
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="buildingAnchorName">Name</label>
              <input type="text" class="form-control form-control-sm" id="buildingAnchorName"
                     maxlength="120" autocomplete="off" placeholder="e.g. 123 Main St NW corner">
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="buildingAnchorStatus">Status</label>
              <select class="form-select form-select-sm" id="buildingAnchorStatus">
                <option value="plan">plan</option>
                <option value="construction">construction</option>
                <option value="active">active</option>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="buildingAnchorWor">Tinuum Permit #</label>
              <input type="text" class="form-control form-control-sm" id="buildingAnchorWor"
                     maxlength="64" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="buildingAnchorNotes">Notes</label>
              <textarea class="form-control form-control-sm" id="buildingAnchorNotes" rows="2"></textarea>
            </div>

            <div class="small text-muted">Location: <span id="buildingAnchorCoord">—</span></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveBuildingAnchorBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── POP modal (create / place existing / edit) ────────────────────── -->
    <div class="modal fade" id="popModal" tabindex="-1"
         aria-labelledby="popModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="popModalLabel">New POP</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="popModalError" style="display:none"></div>
            <input type="hidden" id="popId">

            <div class="mb-2 small text-muted">
              A point of presence — the building that houses a group of closures and
              the active gear feeding them.
            </div>

            <!-- Hidden in edit mode; lists POPs that exist but have no coordinates yet. -->
            <div class="mb-2" id="popExistingWrap">
              <label class="form-label small fw-semibold" for="popExisting">POP</label>
              <select class="form-select form-select-sm" id="popExisting">
                <option value="0">— create a new POP —</option>
              </select>
              <div class="form-text small" id="popExistingHint" style="display:none">
                These POPs were created without coordinates and don't appear on the map yet.
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="popName">Name</label>
              <input type="text" class="form-control form-control-sm" id="popName"
                     maxlength="200" autocomplete="off" placeholder="e.g. Colpoys CO">
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold" for="popNotes">Notes</label>
              <textarea class="form-control form-control-sm" id="popNotes" rows="2"></textarea>
            </div>

            <div class="small text-muted">Location: <span id="popCoord">—</span></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="savePopBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Split Span modal ──────────────────────────────────────────────── -->
    <div class="modal fade" id="splitSpanModal" tabindex="-1"
         aria-labelledby="splitSpanModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="splitSpanModalLabel">&#x2702; Split Span</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="splitSpanModalError" style="display:none"></div>
            <input type="hidden" id="splitSpanId">

            <div class="mb-2">
              <div class="form-label small fw-semibold mb-0">Span</div>
              <div id="splitSpanInfo" class="small py-1 text-muted fst-italic">—</div>
            </div>

            <div class="mb-2">
              <label for="splitSpliceNum" class="form-label small fw-semibold">
                New closure splice number <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control form-control-sm text-uppercase"
                     id="splitSpliceNum" placeholder="ABC1234" maxlength="8" autocomplete="off">
              <div class="form-text">ABC1234 &nbsp;or&nbsp; L/R + 2 digits + optional -MST</div>
            </div>

            <div class="mb-1">
              <label for="splitTypeId" class="form-label small fw-semibold">
                Closure type <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="splitTypeId">
                <option value="">— select —</option>
                <?php foreach ($closureTypes as $ct): ?>
                  <option value="<?= h($ct['closure_type_id']) ?>"><?= h($ct['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveSplitSpanBtn">Split</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New FlexTIN Run modal ──────────────────────────────────────────── -->
    <div class="modal fade" id="newFlextinModal" tabindex="-1"
         aria-labelledby="newFlextinModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="newFlextinModalLabel">&#x26A1; Add FlexTIN Run</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="flextinModalError" style="display:none"></div>

            <div class="mb-2">
              <label for="flextinFoscId" class="form-label small fw-semibold">
                Closure <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="flextinFoscId">
                <option value="">— select closure —</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="flextinCableId" class="form-label small fw-semibold">
                Cable <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="flextinCableId">
                <option value="">— select —</option>
                <?php foreach ($cables as $c): if (!$c['open_ended']) continue; ?>
                  <option value="<?= h($c['cable_id']) ?>">
                    <?= h($c['name']) ?> (<?= h($c['fibre_count']) ?>F)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2" id="flextinBearingSection" style="display:none">
              <label class="form-label small fw-semibold">Bearing direction <span class="text-danger">*</span></label>
              <div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="flextinBearingRadio"
                         id="flextinBearingA" value="A">
                  <label class="form-check-label small" id="flextinBearingALabel"
                         for="flextinBearingA">&mdash;</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="flextinBearingRadio"
                         id="flextinBearingB" value="B">
                  <label class="form-check-label small" id="flextinBearingBLabel"
                         for="flextinBearingB">&mdash;</label>
                </div>
              </div>
            </div>

            <div class="mb-2">
              <label for="flextinDirection" class="form-label small fw-semibold">
                Direction prefix <span class="text-danger">*</span>
              </label>
              <select class="form-select form-select-sm" id="flextinDirection">
                <option value="L">L (left)</option>
                <option value="R">R (right)</option>
              </select>
            </div>

            <div class="alert alert-info py-2 px-3 small mb-2"
                 id="flextinPreview" style="display:none"></div>

            <div class="mb-1">
              <label for="flextinNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="flextinNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm"
                    data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm"
                    id="saveFlextinBtn">Create FlexTIN Run</button>
          </div>
        </div>
      </div>
    </div>
    <!-- ── New Conduit modal ────────────────────────────────────────────── -->
    <div class="modal fade" id="newConduitModal" tabindex="-1"
         aria-labelledby="conduitModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="conduitModalLabel">New Conduit</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="conduitModalError" style="display:none"></div>

            <div class="mb-2">
              <label for="conduitName" class="form-label small fw-semibold">Name</label>
              <input type="text" class="form-control form-control-sm"
                     id="conduitName" maxlength="80" autocomplete="off"
                     placeholder="e.g. Main St. run (optional)">
            </div>

            <div class="mb-2">
              <label for="conduitMicroductType" class="form-label small fw-semibold">Microduct type</label>
              <select class="form-select form-select-sm" id="conduitMicroductType">
                <option value="">— unknown / other —</option>
                <!-- populated by JS from window._microductTypes -->
              </select>
            </div>

            <div class="mb-2">
              <label for="conduitInstallation" class="form-label small fw-semibold">Installation</label>
              <select class="form-select form-select-sm" id="conduitInstallation">
                <option value="buried">Buried</option>
                <option value="aerial">Aerial (lashed to the strand)</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="conduitStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="conduitStatus">
                <option value="plan" selected>Plan</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="conduitOwner" class="form-label small fw-semibold">Owner</label>
              <select class="form-select form-select-sm" id="conduitOwner">
                <option value="" selected>Tinuum</option>
                <option value="bell">Bell</option>
                <option value="nbpower">NB Power</option>
                <option value="rogers">Rogers</option>
                <option value="private">Private (customer-owned)</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="conduitFpmWor" class="form-label small fw-semibold">Tinuum Permit# <small class="text-muted fw-normal">(comma-sep. if multiple)</small></label>
              <input type="text" class="form-control form-control-sm" id="conduitFpmWor" maxlength="200"
                     autocomplete="off" data-permit-picker data-permit-comma>
            </div>

            <div class="mb-2">
              <label for="conduitBellPermit" class="form-label small fw-semibold">Bell Permit# <small class="text-muted fw-normal">(comma-sep. if multiple)</small></label>
              <input type="text" class="form-control form-control-sm" id="conduitBellPermit" maxlength="200"
                     autocomplete="off" data-permit-picker="bell" data-permit-comma
                     placeholder="e.g. NB25-M-049">
            </div>

            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="conduitIsSpare">
              <label class="form-check-label small" for="conduitIsSpare">
                Spare / empty duct
                <small class="text-muted d-block">Stops the map assuming a cable running in this trench is in
                this duct — needed where two ducts share one trench.</small>
              </label>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Measured length</label>
              <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="max-width:150px">
                  <input type="number" class="form-control form-control-sm" id="conduitKnownLength"
                         min="0.1" step="0.1" placeholder="unknown" autocomplete="off">
                  <span class="input-group-text">m</span>
                </div>
                <span class="text-muted small">&plusmn;</span>
                <div class="input-group input-group-sm" style="max-width:120px">
                  <input type="number" class="form-control form-control-sm" id="conduitKnownTol"
                         min="0" step="0.5" placeholder="—" autocomplete="off">
                  <span class="input-group-text">m</span>
                </div>
              </div>
              <small class="text-muted" style="font-size:11px">
                The real pull, when you know it — a duct into a building runs deeper and
                bends, so it is usually longer than the drawn line. Leave blank if it was
                never measured; the map length is used instead.
              </small>
            </div>

            <div class="mb-1">
              <label for="conduitNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="conduitNotes" rows="2" maxlength="500"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveConduitBtn">Save Conduit</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New Vault / Handhole modal ───────────────────────────────────── -->
    <div class="modal fade" id="newVaultModal" tabindex="-1"
         aria-labelledby="vaultModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="vaultModalLabel">New Vault</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="vaultModalError" style="display:none"></div>
            <input type="hidden" id="vaultTypeInput" value="vault">

            <div class="mb-2">
              <label for="vaultTypeSelect" class="form-label small fw-semibold">Type</label>
              <select class="form-select form-select-sm" id="vaultTypeSelect">
                <option value="vault">Vault</option>
                <option value="handhole">Handhole</option>
                <option value="pullbox">Pullbox</option>
                <option value="manhole">Manhole</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="vaultOwner" class="form-label small fw-semibold">Owner</label>
              <select class="form-select form-select-sm" id="vaultOwner">
                <option value="" selected>Tinuum</option>
                <option value="bell">Bell</option>
                <option value="nbpower">NB Power</option>
                <option value="rogers">Rogers</option>
                <option value="private">Private (customer-owned)</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="vaultModel" class="form-label small fw-semibold">Model <span id="vaultModelReq" class="text-danger">*</span></label>
              <select class="form-select form-select-sm" id="vaultModel">
                <option value="">— pick a model —</option>
                <?php foreach ($vaultModels as $vm): ?>
                  <option value="<?= (int)$vm['id'] ?>"><?= h(mb_strimwidth($vm['description'], 0, 70, '…')) ?> (<?= h($vm['sku']) ?>)<?= $vm['active'] ? '' : ' — no longer ordered' ?></option>
                <?php endforeach; ?>
                <option value="other">Other / not in the BOM…</option>
              </select>
              <input type="text" class="form-control form-control-sm mt-1" id="vaultModelOther" maxlength="120"
                     autocomplete="off" placeholder="e.g. APC (Power &amp; Tel), FCST round" style="display:none">
            </div>

            <div class="mb-2">
              <label for="vaultOwnerRef" class="form-label small fw-semibold">Owner #</label>
              <input type="text" class="form-control form-control-sm"
                     id="vaultOwnerRef" maxlength="80" autocomplete="off"
                     placeholder="owner's asset # — e.g. unkn4233">
            </div>

            <div class="mb-2">
              <label for="vaultName" class="form-label small fw-semibold">Name</label>
              <input type="text" class="form-control form-control-sm"
                     id="vaultName" maxlength="80" autocomplete="off"
                     placeholder="e.g. HH-01 (optional)">
            </div>

            <div class="mb-2">
              <label for="vaultStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="vaultStatus">
                <option value="plan" selected>Plan</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="vaultFpmWor" class="form-label small fw-semibold">Tinuum Permit#</label>
              <input type="text" class="form-control form-control-sm" id="vaultFpmWor"
                     maxlength="80" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-1">
              <label for="vaultNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="vaultNotes" rows="2" maxlength="500"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveVaultBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── New Note modal ───────────────────────────────────────────────── -->
    <div class="modal fade" id="newNoteModal" tabindex="-1"
         aria-labelledby="noteModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="noteModalLabel">New Note</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="noteModalError" style="display:none"></div>
            <input type="hidden" id="noteIconValue" value="note">

            <div class="mb-3">
              <label class="form-label small fw-semibold">Icon</label>
              <div class="d-flex gap-2 flex-wrap" id="noteIconSelect">
                <label class="note-icon-opt active" data-val="note">
                  <span style="font-size:18px">📌</span>
                  <span>Note</span>
                </label>
                <label class="note-icon-opt" data-val="tree_trim">
                  <span style="font-size:18px">🌲</span>
                  <span>Tree Trim</span>
                </label>
                <label class="note-icon-opt" data-val="construction">
                  <span style="font-size:18px">🚧</span>
                  <span>Construction</span>
                </label>
              </div>
            </div>

            <div class="mb-1">
              <label for="noteBody" class="form-label small fw-semibold">Note text</label>
              <textarea class="form-control form-control-sm" id="noteBody"
                        rows="3" maxlength="500"
                        placeholder="Enter note text (optional)…"></textarea>
            </div>
            <div class="mb-1 mt-2">
              <label for="noteFpmWor" class="form-label small fw-semibold">Tinuum Permit # <small class="text-muted fw-normal">(optional)</small></label>
              <input type="text" class="form-control form-control-sm" id="noteFpmWor"
                     maxlength="64" autocomplete="off" data-permit-picker>
              <div class="form-text">The note then follows that permit's filter and plans, for example an option-only note.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveNoteBtn">Save Note</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Anchor edit modal ─────────────────────────────────────────────── -->
    <div class="modal fade" id="anchorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="anchorModalLabel">Anchor</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="anchorModalError" style="display:none"></div>
            <input type="hidden" id="anchorIdInput">
            <input type="hidden" id="anchorPoleFidInput">

            <div class="mb-2">
              <label class="form-label small fw-semibold">Linked Pole</label>
              <input type="text" class="form-control form-control-sm" id="anchorPoleDisplay" readonly tabindex="-1" style="background:#f8f9fa;color:#6c757d">
            </div>

            <div class="mb-2">
              <label for="anchorOwner" class="form-label small fw-semibold">Owner</label>
              <select class="form-select form-select-sm" id="anchorOwner">
                <option value="">— unknown —</option>
                <option value="Bell Aliant">Bell Aliant</option>
                <option value="NB Power">NB Power</option>
                <option value="<?= h(org('own_name')) ?>"><?= h(org('own_name')) ?></option>
                <option value="Private">Private (customer-owned)</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="anchorStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="anchorStatus">
                <option value="plan">Plan</option>
                <option value="construction">Construction</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Direction from pole</label>
              <input type="hidden" id="anchorDirection">
              <div id="anchorCompassContainer" style="touch-action:none;user-select:none"></div>
            </div>

            <div class="mb-1">
              <label for="anchorNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="anchorNotes" rows="2" maxlength="254"></textarea>
            </div>
          </div>
          <div class="modal-footer justify-content-between">
            <button type="button" class="btn btn-danger btn-sm" id="deleteAnchorBtn">Delete</button>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary btn-sm" id="saveAnchorBtn">Save</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── U-Guard edit modal ────────────────────────────────────────────────── -->
    <div class="modal fade" id="uguardModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="uguardModalLabel">U-Guard</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger" id="uguardModalError" style="display:none"></div>
            <input type="hidden" id="uguardIdInput">
            <input type="hidden" id="uguardPoleFidInput">

            <div class="mb-2">
              <label class="form-label small fw-semibold">Linked Pole</label>
              <input type="text" class="form-control form-control-sm" id="uguardPoleDisplay" readonly tabindex="-1" style="background:#f8f9fa;color:#6c757d">
            </div>

            <div class="mb-2">
              <label for="uguardStatus" class="form-label small fw-semibold">Status</label>
              <select class="form-select form-select-sm" id="uguardStatus">
                <option value="plan">Plan</option>
                <option value="construction">Construction</option>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </div>

            <div class="mb-2">
              <label for="uguardFpmWor" class="form-label small fw-semibold">Tinuum Permit#</label>
              <input type="text" class="form-control form-control-sm" id="uguardFpmWor"
                     maxlength="80" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-1">
              <label for="uguardNotes" class="form-label small fw-semibold">Notes</label>
              <textarea class="form-control form-control-sm" id="uguardNotes" rows="2" maxlength="254"></textarea>
            </div>
          </div>
          <div class="modal-footer justify-content-between">
            <button type="button" class="btn btn-danger btn-sm d-none" id="deleteUguardBtn">Delete</button>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary btn-sm" id="saveUguardBtn">Save</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Messenger Strand modal ───────────────────────────────────────────── -->
    <div class="modal fade" id="strandModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h5 class="modal-title" id="strandModalLabel">Messenger Strand</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger py-1 small" id="strandModalError" style="display:none"></div>
            <input type="hidden" id="strandIdInput">
            <input type="hidden" id="strandFromFidInput">
            <input type="hidden" id="strandToFidInput">

            <div class="mb-2">
              <label class="form-label small fw-semibold mb-1">From Pole</label>
              <input type="text" class="form-control form-control-sm" id="strandFromDisplay" readonly>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Side</label>
                <select class="form-select form-select-sm" id="strandFromSide">
                  <option value="road">Road</option>
                  <option value="field">Field</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Height</label>
                <select class="form-select form-select-sm" id="strandFromHeight">
                  <option value="top">Top</option>
                  <option value="middle">Middle</option>
                  <option value="bottom">Bottom</option>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold mb-1">To Pole</label>
              <input type="text" class="form-control form-control-sm" id="strandToDisplay" readonly>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Side</label>
                <select class="form-select form-select-sm" id="strandToSide">
                  <option value="road">Road</option>
                  <option value="field">Field</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Height</label>
                <select class="form-select form-select-sm" id="strandToHeight">
                  <option value="top">Top</option>
                  <option value="middle">Middle</option>
                  <option value="bottom">Bottom</option>
                </select>
              </div>
            </div>

            <div class="form-check form-switch mb-2 mt-1">
              <input class="form-check-input" type="checkbox" id="strandIsOverlash">
              <label class="form-check-label small" for="strandIsOverlash">
                Bell Aliant strand <small class="text-muted">(overlash — BA owns the strand)</small>
              </label>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select class="form-select form-select-sm" id="strandStatus">
                  <option value="plan">Plan</option>
                  <option value="construction">Construction</option>
                  <option value="active">Active</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Tension</label>
                <select class="form-select form-select-sm" id="strandTension">
                  <option value="normal">Normal</option>
                  <option value="slack_span">Slack Span</option>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold mb-1" for="strandFpmWor">Tinuum Permit#</label>
              <input type="text" class="form-control form-control-sm" id="strandFpmWor"
                     maxlength="80" autocomplete="off" data-permit-picker>
              <div class="form-text small">Leave blank to inherit the permits of the end poles.</div>
            </div>

            <div class="mb-1">
              <label class="form-label small fw-semibold mb-1">Notes</label>
              <textarea class="form-control form-control-sm" id="strandNotes" rows="2" maxlength="500"></textarea>
            </div>
          </div>
          <div class="modal-footer d-flex gap-2">
            <button type="button" class="btn btn-danger btn-sm me-auto" id="deleteStrandBtn" style="display:none">Delete</button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveStrandBtn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Strand path creation modal (multi-pole) -->
    <div class="modal fade" id="strandPathModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h5 class="modal-title">Add Messenger Strand Path</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger py-1 small" id="strandPathError" style="display:none"></div>
            <p class="small text-muted mb-3" id="strandPathSummary"></p>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Side (all poles)</label>
                <select class="form-select form-select-sm" id="strandPathSide">
                  <option value="road">Road</option>
                  <option value="field">Field</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Height (all poles)</label>
                <select class="form-select form-select-sm" id="strandPathHeight">
                  <option value="top">Top</option>
                  <option value="middle">Middle</option>
                  <option value="bottom">Bottom</option>
                </select>
              </div>
            </div>
            <div class="form-check form-switch mb-2 mt-1">
              <input class="form-check-input" type="checkbox" id="strandPathIsOverlash">
              <label class="form-check-label small" for="strandPathIsOverlash">
                Bell Aliant strand <small class="text-muted">(overlash — BA owns the strand)</small>
              </label>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select class="form-select form-select-sm" id="strandPathStatus">
                  <option value="plan">Plan</option>
                  <option value="construction">Construction</option>
                  <option value="active">Active</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold mb-1">Tension</label>
                <select class="form-select form-select-sm" id="strandPathTension">
                  <option value="normal">Normal</option>
                  <option value="slack_span">Slack Span</option>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold mb-1" for="strandPathFpmWor">Tinuum Permit# <small class="text-muted fw-normal">(all segments)</small></label>
              <input type="text" class="form-control form-control-sm" id="strandPathFpmWor"
                     maxlength="80" autocomplete="off" data-permit-picker>
            </div>

            <div class="mb-1">
              <label class="form-label small fw-semibold mb-1">Notes</label>
              <textarea class="form-control form-control-sm" id="strandPathNotes" rows="2" maxlength="500"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveStrandPathBtn">Save Path</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    // ── Print orientation toggle ──────────────────────────────────────────────
    let _printLandscape = localStorage.getItem('mapPrintOrientation') !== 'portrait';

    function _applyOrientStyle() {
      let s = document.getElementById('mapPrintOrientStyle');
      if (!s) { s = document.createElement('style'); s.id = 'mapPrintOrientStyle'; document.head.appendChild(s); }
      // margin:0 makes the page box the whole sheet, so 100vw/100vh in print equal
      // FPM_SHEET.pxSize() and the engineering frame's 5 mm inset is measured
      // from the paper edge as its CSS assumes.
      //
      // The size is given as explicit dimensions rather than the bare 'landscape'
      // keyword. With only an orientation, the page box is whatever paper the
      // dialog happens to have selected and Chrome's default "fit to printable
      // area" is free to scale the document to reach it — which shrinks the
      // px-pinned map canvas while the frame, title block and north arrow, all
      // sized off the page box, keep filling the paper. Naming the exact sheet
      // makes the page box and FPM_SHEET.pxSize() the same box by definition,
      // so the fit has nothing left to do and the scale stays 1:1 — which the
      // printed scale bar and the 1:N ratio in the title block both depend on.
      const S = window.FPM_SHEET;
      const dims = _printLandscape
        ? S.pageLongMm + 'mm ' + S.pageShortMm + 'mm'
        : S.pageShortMm + 'mm ' + S.pageLongMm + 'mm';
      s.textContent = '@page { size: ' + dims + '; margin: 0; }';
    }

    function _updateOrientBtn() {
      const btn = document.getElementById('btnPrintOrient');
      if (btn) btn.textContent = _printLandscape ? '↔ Landscape' : '↕ Portrait';
    }

    function togglePrintOrient() {
      _printLandscape = !_printLandscape;
      localStorage.setItem('mapPrintOrientation', _printLandscape ? 'landscape' : 'portrait');
      _updateOrientBtn();
    }

    _updateOrientBtn(); // set label on page load

    // The map canvas carries a Leaflet-managed inline style (position:relative) that
    // its absolutely-positioned panes depend on. Print flows temporarily override it
    // to expand the canvas to the full page; we must restore the ORIGINAL inline style
    // afterwards — never blow it away with cssText='' (that drops position:relative and
    // leaves the on-screen map broken after the dialog is closed/cancelled).
    let _mapCanvasBaseStyle = null;
    let _tileNativeSaved = null;   // basemap tile options changed for print (see _expandCanvasAndPreload)
    let _tileSourceRes   = 1;      // tile px per canvas px of the print basemap
    let _printBasemapDpi = 96;     // what the last sheet prep achieved, for the title block
    const PRINT_BASEMAP_MIN_DPI = 150;   // floor: below this a plotter print reads blurry
    const PRINT_TILE_BUDGET     = 4000;  // an A0 at 300 dpi is ~2,100
    // Basemap for print: 'legible' takes the shallowest tile zoom that clears
    // the dpi floor (largest lettering, ~150–300 dpi, for the plotter);
    // 'sharp' goes one level deeper and embeds the composite as an image
    // rather than a canvas, past Chrome's 300 dpi print raster, for a PDF
    // that is zoomed on screen. Lettering halves; that is the trade.
    let _planBasemapRes = 'legible';
    // True while the canvas is pinned to the paper size and Leaflet has already
    // been invalidated at that size — see the beforeprint handler.
    let _canvasPinned = false;
    function _captureCanvasBase() {
      const canvas = document.getElementById('mapCanvas');
      if (canvas && _mapCanvasBaseStyle === null) _mapCanvasBaseStyle = canvas.style.cssText;
    }
    function _restoreCanvasStyle() {
      const canvas = document.getElementById('mapCanvas');
      if (canvas) canvas.style.cssText = (_mapCanvasBaseStyle || '');
      _canvasPinned = false;
      _unflattenTilePane();
      _tileSourceRes = 1;
      if (_tileNativeSaved) {
        const t = _tileNativeSaved; _tileNativeSaved = null;
        t.layer.options.maxNativeZoom = t.maxNativeZoom;
        t.layer.options.tileSize      = t.tileSize;
        t.layer.options.zoomOffset    = t.zoomOffset;
        t.layer.options.crossOrigin   = t.crossOrigin;
        if (window._tinMap && window._tinMap.hasLayer(t.layer)) {
          window._tinMap.removeLayer(t.layer);
          window._tinMap.addLayer(t.layer);
        }
      }
    }

    // Expand the canvas to fill the print page and wait for tiles, so the printed map
    // covers the whole sheet at the on-screen zoom. Leaflet paints at its invalidated
    // pixel size, so we must expand + invalidateSize BEFORE printing — otherwise it
    // renders only the small screen-sized area and the paper looks mis-zoomed.
    async function _expandCanvasAndPreload() {
      _captureCanvasBase();
      const canvas = document.getElementById('mapCanvas');
      // Size the canvas to the PAPER in CSS pixels, not the window: in print media
      // vw/vh resolve against the page, so a window-sized pane would be re-laid-out
      // to page size at print time without Leaflet re-invalidating, and the sheet
      // would print as a corner slice of what was previewed.
      // A fitted plan renders on a canvas 1/fit times the paper and is zoomed
      // down to it: the layout box stays the paper (so nothing overflows the
      // page and Chrome's fit-to-page has nothing to do), while Leaflet sees the
      // larger inner size and loads tiles and features for all of it.
      const paperPx = window.FPM_SHEET.pxSize();
      const sheetPx = { w: Math.round(paperPx.w / _planFit), h: Math.round(paperPx.h / _planFit) };
      // opacity:0 hides the on-screen resize while tiles preload; base style is kept
      // first so the later position:fixed wins but the original is remembered.
      canvas.style.cssText = (_mapCanvasBaseStyle || '') +
        ';position:fixed;top:0;left:0;width:' + sheetPx.w + 'px;height:' + sheetPx.h +
        'px;z-index:9999;opacity:0;pointer-events:none;' +
        (_planFit < 1 ? 'zoom:' + _planFit + ';' : '');
      window._tinMap.invalidateSize();
      let tileLayer = null;
      window._tinMap.eachLayer(l => { if (l instanceof L.TileLayer) tileLayer = l; });
      // Basemap tiles for print. The sheet is drawn at the map zoom, but the
      // tiles need not come from it: Leaflet fetches from a deeper zoom when
      // tileSize is halved per level and zoomOffset raised (its retina
      // mechanism), and from a shallower one through maxNativeZoom. A tile
      // pixel is (1/96 in × fit) on paper at the sheet zoom, so each level
      // deeper doubles the dpi — and HALVES the lettering, since a street name
      // is the same ~10 tile px at every zoom. At 1:8,000 z18's names are half
      // a millimetre; no dpi makes that readable. So: the SHALLOWEST zoom that
      // still clears PRINT_BASEMAP_MIN_DPI — the largest lettering that prints
      // sharp — within what the server has (maxNativeZoom) and a tile budget
      // the tile servers will serve without throttling. Restored on release.
      let tilePx = 256;
      if (tileLayer && _tileNativeSaved === null) {
        const z = window._tinMap.getZoom();
        const o = tileLayer.options;
        const native = o.maxNativeZoom ?? o.maxZoom ?? z;
        const dpiAt  = uz => 96 * Math.pow(2, uz - z) / _planFit;
        const count  = uz => Math.ceil(sheetPx.w / (256 * Math.pow(2, z - uz)) + 1) *
                             Math.ceil(sheetPx.h / (256 * Math.pow(2, z - uz)) + 1);
        let urlZoom = Math.ceil(z + Math.log2(PRINT_BASEMAP_MIN_DPI / 96 * _planFit));
        if (_planBasemapRes === 'sharp') urlZoom++;
        urlZoom = Math.min(native, urlZoom);
        while (urlZoom > z - 4 && count(urlZoom) > PRINT_TILE_BUDGET) urlZoom--;
        _printBasemapDpi = Math.round(dpiAt(urlZoom));
        // The composite is read back (greyscale bake) and embedded through
        // canvas.toBlob, both of which a canvas painted with cross-origin
        // tiles refuses unless they were fetched with CORS — so the layer is
        // re-added either way, with crossOrigin on. (Every basemap host here
        // sends ACAO *; if one did not, _flattenTilePane falls back.)
        const wantCors = !o.crossOrigin;
        if (urlZoom !== z || wantCors) {
          _tileNativeSaved = { layer: tileLayer, maxNativeZoom: o.maxNativeZoom, tileSize: o.tileSize, zoomOffset: o.zoomOffset, crossOrigin: o.crossOrigin };
          if (wantCors) o.crossOrigin = 'anonymous';
          if (urlZoom < z) {
            o.maxNativeZoom = urlZoom;
          } else {
            o.tileSize   = 256 / Math.pow(2, urlZoom - z);
            o.zoomOffset = (o.zoomOffset || 0) + (urlZoom - z);
          }
          window._tinMap.removeLayer(tileLayer);      // re-adding recomputes the grid
          window._tinMap.addLayer(tileLayer);
        }
        tilePx = 256 * Math.pow(2, z - urlZoom);
        _tileSourceRes = Math.pow(2, urlZoom - z);    // tile px per canvas px
      }
      _canvasPinned = true;
      // The features and labels for the now-larger canvas, in parallel with the
      // tiles (see settleSheet in map.helper.js); the sheet is ready when both are.
      const settled = window.fpmSettleSheet ? window.fpmSettleSheet() : Promise.resolve();
      // Bigger sheets fetch more tiles: an A1 is ~120 against Letter's ~20, so
      // the deadline grows with the tile count rather than printing white.
      const tiles = Math.ceil(sheetPx.w / tilePx + 1) * Math.ceil(sheetPx.h / tilePx + 1);
      await _awaitTilesPainted(tileLayer, Math.max(10000, Math.min(600000, tiles * 300)));
      await settled;
      await _flattenTilePane();
      canvas.style.opacity = '1';
      canvas.style.pointerEvents = '';
    }

    // The basemap as ONE image. Printed as individual tiles, every tile is its
    // own image in the PDF and viewers (Preview, Acrobat, pdf.js) resample each
    // one's edge on its own — a hairline grid over the whole sheet at any
    // scale but 1:1. Compositing the loaded tiles onto a single canvas at the
    // tiles' own resolution (fetched for print dpi, see _expandCanvasAndPreload;
    // area-capped) gives the PDF one
    // seamless image. Tile positions are read from the DOM so Leaflet's level
    // scaling and the fitted CSS zoom are both already applied; they are
    // rounded so neighbours abut exactly. Cross-origin tiles taint the canvas,
    // which only matters for reading it back — this never does.
    let _flattenedTiles = null;   // { el, url, imgs } while a flattened basemap is shown
    async function _flattenTilePane() {
      const pane = document.querySelector('.leaflet-tile-pane');
      const mapEl = document.getElementById('mapCanvas');
      if (!pane || !mapEl || _flattenedTiles) return;
      const imgs = [...pane.querySelectorAll('img.leaflet-tile')].filter(i => i.complete && i.naturalWidth > 0);
      if (!imgs.length) return;
      const size = window._tinMap.getSize();                    // canvas px (before the CSS zoom)
      const box  = mapEl.getBoundingClientRect();               // paper px (after it)
      let k = _tileSourceRes;                                    // raster px per canvas px: the tiles' own resolution
      const MAX_AREA = 160e6;                                    // an A0 at 300 dpi is 140 MP; Chrome's canvas ceiling is 268
      if (size.x * k * size.y * k > MAX_AREA) k = Math.sqrt(MAX_AREA / (size.x * size.y));
      _printBasemapDpi = Math.round(96 * k / _planFit);     // what the sheet actually carries
      const cv = document.createElement('canvas');
      cv.width = Math.round(size.x * k); cv.height = Math.round(size.y * k);
      const ctx = cv.getContext('2d');
      ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
      // The Fade slider is opacity on the layer's container, which the flat
      // image is not in — so it is baked in: tiles over white at that alpha,
      // which is what the screen shows over the pane's white too.
      const cont  = imgs[0].closest('.leaflet-layer');
      const alpha = cont ? parseFloat(getComputedStyle(cont).opacity) : 1;
      if (isFinite(alpha) && alpha < 1) {
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.globalAlpha = alpha;
      }
      const toRaster = box.width / size.x;                      // paper px per canvas px
      imgs.forEach(img => {
        const r = img.getBoundingClientRect();
        const x0 = Math.round((r.left  - box.left) / toRaster * k), y0 = Math.round((r.top    - box.top) / toRaster * k);
        const x1 = Math.round((r.right - box.left) / toRaster * k), y1 = Math.round((r.bottom - box.top) / toRaster * k);
        if (x1 > x0 && y1 > y0) { try { ctx.drawImage(img, x0, y0, x1 - x0, y1 - y0); } catch (_) {} }
      });
      ctx.globalAlpha = 1;
      // Legible mode fetches the tiles without CORS, so the canvas is usually
      // tainted and cannot be read back: then the CSS filter stays on the
      // flattened image (Chrome rasterises it at 300 dpi, plenty for that mode).
      const baked = _bakeBasemapFilter(cv, ctx, pane);
      // Cover the container: the tile pane is offset by the map pane's own
      // transform, so the canvas is placed back at the container's origin.
      const panePos = L.DomUtil.getPosition(window._tinMap.getPane('mapPane')) || L.point(0, 0);
      const css = 'position:absolute;left:' + (-panePos.x) + 'px;top:' + (-panePos.y) +
                  'px;width:' + size.x + 'px;height:' + size.y + 'px;pointer-events:none;';
      // Chrome re-rasterises a <canvas> at 300 dpi for print and embeds it
      // losslessly (an A2 of satellite came out at 34 MB); an <img> goes in
      // at its own resolution, as the JPEG it is. That needs the canvas
      // untainted (CORS tiles); if that fails, the canvas is used.
      let el = cv, url = null;
      if (baked !== false) {
        try {
          // JPEG: at 250+ dpi its artefacts are below what a plotter resolves,
          // and it is a fifth the size of PNG for a sheet this big.
          const blob = await new Promise((res, rej) => cv.toBlob(b => b ? res(b) : rej(new Error('toBlob')), 'image/jpeg', 0.92));
          url = URL.createObjectURL(blob);
          const img = document.createElement('img');
          await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = url; });
          el = img;
        } catch (_) { el = cv; }
      }
      el.style.cssText = css;
      el.className = 'tin-flat-basemap';
      pane.appendChild(el);
      if (baked) pane.classList.add('tin-flattened');
      else if (baked === false && pane.querySelector('.map-grey-tiles')) {
        el.style.filter = 'grayscale(100%) brightness(1.08)';   // the Grey layer's own filter is on its tile container, which the flat image is not in
      }
      imgs.forEach(i => { i.style.visibility = 'hidden'; });
      _flattenedTiles = { el, url, imgs, pane };
    }
    // A grey basemap's CSS filter, baked into the pixels so the pane can drop
    // it (see .tin-flattened). It sits on the tile container, which the flat
    // image is not in, so it is not read off computed style: the values are
    // the CSS's (.map-grey-tiles), applied as one per-pixel pass.
    // (Not ctx.filter: Chrome applies that per drawImage, and 4,000 filtered
    // tile draws on a 100 MP canvas never finish.)
    function _bakeBasemapFilter(cv, ctx, pane) {
      const contrast = 1, brightness = 1.08;            // .map-grey-tiles
      if (!pane.querySelector('.map-grey-tiles')) return null;   // a colour basemap: nothing to bake
      // A tainted canvas (non-CORS tiles) refuses getImageData.
      try { ctx.getImageData(0, 0, 1, 1); } catch (_) { return false; }
      // grayscale(100%) then contrast() then brightness(), as CSS composes them,
      // folded into one lookup on the luminance.
      const lut = new Uint8ClampedArray(256);
      for (let g = 0; g < 256; g++) lut[g] = ((g - 127.5) * contrast + 127.5) * brightness;
      const STRIP = Math.max(1, Math.floor(8e6 / cv.width));   // ≈ 32 MB of pixels per pass
      for (let y = 0; y < cv.height; y += STRIP) {
        const h = Math.min(STRIP, cv.height - y);
        const im = ctx.getImageData(0, y, cv.width, h), d = im.data;
        for (let i = 0; i < d.length; i += 4) {
          d[i] = d[i + 1] = d[i + 2] = lut[(0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]) | 0];
        }
        ctx.putImageData(im, 0, y);
      }
      return true;
    }
    function _unflattenTilePane() {
      if (!_flattenedTiles) return;
      _flattenedTiles.el.remove();
      _flattenedTiles.pane.classList.remove('tin-flattened');
      if (_flattenedTiles.url) URL.revokeObjectURL(_flattenedTiles.url);
      _flattenedTiles.imgs.forEach(i => { i.style.visibility = ''; });
      _flattenedTiles = null;
    }

    // A single tileLayer 'load' is not a barrier, so this polls what is actually
    // on the page instead.  Leaflet fires 'load' for whichever batch happens to be
    // in flight: the plan sheet path sets the sheet's own zoom only 120ms before
    // printing (planFramePrintBtn in map.helper.js), so the previous zoom level's
    // batch can satisfy 'load' while the sheet's level is still half-loaded — and
    // the moment Leaflet prunes the old level, the unloaded part of the sheet
    // prints white.  The opposite case is just as wrong: when the expand is a
    // shrink no new tiles are requested, 'load' never fires at all, and every
    // print used to sit on the 6s fallback.
    //
    // A tile counts as settled once it has painted, or once Leaflet has marked it
    // done — 'leaflet-tile-loaded' is set for errored tiles too, so one dead tile
    // costs a deadline wait rather than hanging the print.  Requiring the settled
    // state to hold across a few polls covers tiles Leaflet inserts a frame late.
    function _awaitTilesPainted(tileLayer, timeoutMs) {
      return new Promise(resolve => {
        const pane = document.querySelector('.leaflet-tile-pane');
        if (!pane) { setTimeout(() => resolve('no-pane'), 200); return; }
        const deadline = performance.now() + timeoutMs;
        let stable = 0;
        const onLoading = () => { stable = 0; };   // a new batch restarts the count
        if (tileLayer) tileLayer.on('loading', onLoading);
        const finish = (why) => {
          if (tileLayer) tileLayer.off('loading', onLoading);
          resolve(why);
        };
        (function poll() {
          const imgs = pane.querySelectorAll('img.leaflet-tile');
          const settled = [...imgs].every(i =>
            i.classList.contains('leaflet-tile-loaded') || (i.complete && i.naturalWidth > 0));
          stable = (imgs.length > 0 && settled) ? stable + 1 : 0;
          if (stable >= 3) { finish('painted'); return; }
          if (performance.now() > deadline) { finish('timeout'); return; }
          setTimeout(poll, 80);
        })();
      });
    }

    window.addEventListener('beforeprint', function () {
      _captureCanvasBase();
      _applyOrientStyle();
      document.body.classList.add('map-print-mode');
      // Only the bare Ctrl+P path needs this: _expandCanvasAndPreload has already
      // sized Leaflet to the paper and waited for that view's tiles, and a second
      // invalidateSize here re-centres the map (Leaflet pans by half the size
      // delta) and requests a fresh grid with no time left to load it — printing
      // the gaps.
      if (window._tinMap && !_canvasPinned) window._tinMap.invalidateSize();
    });

    window.addEventListener('afterprint', function () {
      document.body.classList.remove('map-print-mode');
      document.body.classList.remove('map-eng-mode');
      _restoreCanvasStyle();
      if (window._tinMap) window._tinMap.invalidateSize();
    });

    async function fpmPrintMap() {
      if (!window._tinMap) return;
      // Paper never gets density hexagons: a printed plan is static information, so
      // hold the real-assets regime for the print even when the screen is zoomed out
      // (same lock the Plan sheet uses), and wait for those assets to be drawn.
      const lock = window.fpmSetPlanDetailLock;
      try {
        if (lock) { try { await lock(true); } catch (e) { console.warn('print asset lock:', e); } }
        _applyOrientStyle();
        await _expandCanvasAndPreload();
        window.print();
        // afterprint also cleans up, but Chrome may skip it on Cancel and
        // window.print() is synchronous — so restore here too.
        _restoreCanvasStyle();
        window._tinMap.invalidateSize();
      } finally {
        if (lock) lock(false);
      }
    }

    // ── Plan sheet geometry ───────────────────────────────────────────────────
    // Printing expands the map to 100vw × 100vh (see _expandCanvasAndPreload), so
    // the sheet covers that pixel region at the current zoom — a good deal more
    // than the on-screen canvas shows. The framing preview in map.helper.js draws
    // exactly this rectangle, and these mm figures mirror the engineering overlay
    // CSS (#engPlanFrame inset, #engTitleBlock height, #engNorthArrow size) so the
    // shaded "obscured" zones land where the printed furniture actually will.
    // Paper sizes in mm, long edge first. Letter is the office printer; the ISO
    // sizes are for the plotter — an A1 at the same zoom carries nine Letter
    // sheets' worth of ground at full detail, which is what a build crew wants
    // pinned on the wall rather than a stack of pages.
    const PAPER_SIZES = {
      'Letter':  [279.4, 215.9],
      'Tabloid': [431.8, 279.4],
      'A3':      [420, 297],
      'A2':      [594, 420],
      'A1':      [841, 594],
      'A0':      [1189, 841],
    };
    // Plan fit: paper pixels per canvas pixel (≤ 1). The sheet is rendered at
    // the map's top zoom over the whole framed area and shrunk to the paper with
    // a CSS zoom on the canvas — so a sheet twice the paper's ground is printed
    // at 50 %, every icon and label drawn as it is fully zoomed in, only
    // smaller. 1 outside a fitted plan. Set by the framing bar's Print button
    // and by the renderer (window.fpmSetPlanFit); read by the canvas pin and
    // by fpmPlanScale, so the title block's 1:N and scale bar stay true.
    let _planFit = 1;

    // A0 by default: plans go to the plotter as wall sheets for the crew.
    let _printPaper = (() => {
      try { const v = localStorage.getItem('mapPrintPaper'); if (v && PAPER_SIZES[v]) return v; } catch (_) {}
      return 'A0';
    })();

    window.FPM_SHEET = {
      frameInsetMm:  5,
      titleBlockMm: 26,
      northArrowMm: 22,
      papers: PAPER_SIZES,
      paper: () => _printPaper,
      get pageLongMm()  { return PAPER_SIZES[_printPaper][0]; },
      get pageShortMm() { return PAPER_SIZES[_printPaper][1]; },
      landscape: () => _printLandscape,
      // The sheet in CSS pixels — the PAPER at 96 DPI, not the browser window.
      // In print media 1 CSS px is 1/96 in, so pinning the canvas to this size
      // (see _expandCanvasAndPreload) makes the on-screen render and the printed
      // page the same pixel box. Deriving it from the window instead meant the
      // paper showed a corner slice of a much larger pane, and the orientation
      // toggle never changed the sheet's shape.
      pxSize: function () {
        const mm2px = 96 / 25.4;
        const land  = _printLandscape;
        return {
          w: Math.round((land ? this.pageLongMm  : this.pageShortMm) * mm2px),
          h: Math.round((land ? this.pageShortMm : this.pageLongMm)  * mm2px),
        };
      },
    };

    // Shared by the framing preview and the printed title block, so the two can
    // never disagree about what scale the sheet is at.
    // lat/zoom default to the current view; the framing preview passes the sheet's
    // own centre and zoom, which differ from the display while framing.
    // fit defaults to the active plan fit: a canvas pixel is fit × (1/96 in) on
    // paper once the canvas is zoomed down, so the ratio grows by 1/fit.
    window.fpmPlanScale = function (lat, zoom, fit) {
      const map = window._tinMap;
      if (lat  == null) lat  = map.getCenter().lat;
      if (zoom == null) zoom = map.getZoom();
      if (fit  == null) fit  = _planFit;
      const mPerPx = 40075016.686 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom + 8);
      const mmPerPx = 25.4 / 96 * fit;   // 96 DPI, then the print-time shrink
      const scaleN = Math.round(mPerPx / (mmPerPx / 1000));
      // Nice-round distance for the bar, targeting ~50mm print width
      const metersPerMm = mPerPx / mmPerPx;
      const steps = [1,2,5,10,20,50,100,200,500,1000,2000,5000,10000,20000,50000];
      const niceM = steps.find(s => s >= metersPerMm * 50) || steps[steps.length - 1];
      return {
        mPerPx, scaleN,
        barMetres: niceM,
        barMm: niceM / metersPerMm,
        barLabel: niceM >= 1000 ? (niceM / 1000) + ' km' : niceM + ' m',
      };
    };

    const CA_PROVINCE_ABBR = {
      'Alberta':'AB','British Columbia':'BC','Manitoba':'MB','New Brunswick':'NB',
      'Newfoundland and Labrador':'NL','Northwest Territories':'NT','Nova Scotia':'NS',
      'Nunavut':'NU','Ontario':'ON','Prince Edward Island':'PE','Quebec':'QC',
      'Saskatchewan':'SK','Yukon':'YT'
    };

    // Everything that turns the live map into a print-ready sheet, stopping short
    // of the dialog. Split out so the server-side renderer (tools/plan_pdf_server
    // .mjs, behind api/plan_pdf.php) drives exactly the same code the Print button
    // does and can then call Chrome's PDF export directly — no print dialog means
    // no user-set Scale, and the sheet's 1:N ratio and scale bar are always true.
    async function _preparePlanSheet() {
      if (!window._tinMap) return false;

      const now = new Date();
      document.getElementById('engDate').textContent =
        'Date: ' + now.toLocaleDateString('en-CA', { year:'numeric', month:'long', day:'numeric' });

      const c = window._tinMap.getCenter();
      const latStr = c.lat.toFixed(5) + '°' + (c.lat >= 0 ? 'N' : 'S');
      const lngStr = Math.abs(c.lng).toFixed(5) + '°' + (c.lng >= 0 ? 'E' : 'W');
      document.getElementById('engCoords').textContent = 'Centre: ' + latStr + ', ' + lngStr;
      document.getElementById('engZoom').textContent = 'Zoom level: ' + window._tinMap.getZoom() +
        (_planFit < 1 ? ' at ' + Math.round(_planFit * 100) + '%' : '');

      // Legend: the symbols actually on this sheet (see fpmLegendHtml in map.helper.js)
      const legendGrid = document.getElementById('engLegendGrid');
      if (legendGrid && window.fpmLegendHtml) legendGrid.innerHTML = window.fpmLegendHtml();

      // Scale bar: compute metres-per-pixel at current zoom+latitude
      // At 96 DPI, 1 screen pixel = 0.2646 mm on paper
      const sc = window.fpmPlanScale();
      const scaleN = sc.scaleN;
      document.getElementById('engScaleRatio').textContent = 'Scale: 1 : ' + scaleN.toLocaleString();
      const barMm = sc.barMm, niceM = sc.barMetres;
      // Set width on the wrapper so line + label share the same width → text-align:center works
      document.getElementById('engScaleBar').style.width = barMm.toFixed(1) + 'mm';
      document.getElementById('engScaleBarLine').style.width = '100%';
      document.getElementById('engScaleBarLabel').textContent =
        niceM >= 1000 ? (niceM / 1000) + ' km' : niceM + ' m';

      // Reverse-geocode the map centre via OSM Nominatim to get locality name
      const titleEl = document.querySelector('.eng-title');
      titleEl.textContent = 'Network Infrastructure Plan';
      try {
        const resp = await fetch(
          'https://nominatim.openstreetmap.org/reverse?format=json&lat=' +
          c.lat + '&lon=' + c.lng + '&zoom=10&addressdetails=1',
          { headers: { 'Accept-Language': 'en' } }
        );
        const geo = await resp.json();
        const a = geo.address || {};
        const place = a.village || a.hamlet || a.town || a.municipality ||
                      a.suburb || a.city_district || a.city || '';
        const province = a.state || '';
        const abbr = CA_PROVINCE_ABBR[province] || province;
        if (place) {
          titleEl.textContent = place + (abbr ? ', ' + abbr : '') + ' — Network Infrastructure Plan';
        }
      } catch (_) { /* silently fall back to generic title */ }

      // Entity plan: override title + sheet info from the linked record
      // (window._planTitleData is set by enterPlanView in map.helper.js).
      const _ptd = window._planTitleData;
      if (_ptd) {
        const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const m = _ptd.plan.meta || {}, tb = _ptd.titleblock || {};
        titleEl.textContent = m.drawing_title || _ptd.plan.title || 'Site Plan';
        const parts = ['<div style="font-weight:bold">Tinuum Networks Inc.</div>'];
        if (tb.entity_label || tb.number) parts.push('<div>' + esc((tb.entity_label || '') + (tb.number ? ' ' + tb.number : '')) + '</div>');
        if (tb.party) parts.push('<div>' + esc(tb.party) + '</div>');
        const loc = m.location || tb.location || '';
        if (loc) parts.push('<div>' + esc(loc) + '</div>');
        if (m.revision) parts.push('<div>Rev: ' + esc(m.revision) + '</div>');
        parts.push('<div id="engZoom" style="margin-top:2mm;font-size:6pt;color:#555">Zoom level: ' + window._tinMap.getZoom() +
                   (_planFit < 1 ? ' at ' + Math.round(_planFit * 100) + '%' : '') + '</div>');
        document.getElementById('engSheetInfo').innerHTML = parts.join('');
      }

      _applyOrientStyle();
      document.body.classList.add('map-eng-mode');
      if (window.applyPlanEmphasis) window.applyPlanEmphasis(true);

      // Expand the canvas to the full print area and preload tiles before the dialog
      // opens (shared with the plain Print button).
      await _expandCanvasAndPreload();
      // Known only once the tiles were chosen.
      const zoomEl = document.getElementById('engZoom');
      if (zoomEl) zoomEl.textContent += ' · basemap ' + _printBasemapDpi + ' dpi';
      return true;
    }

    // Undo everything _preparePlanSheet() put in place.
    function _releasePlanSheet() {
      _restoreCanvasStyle();
      document.body.classList.remove('map-eng-mode');
      // Keep overlay emphasis if we're still in the on-screen plan view.
      if (!document.body.classList.contains('map-plan-view') && window.applyPlanEmphasis) {
        window.applyPlanEmphasis(false);
      }
      if (window._tinMap) window._tinMap.invalidateSize();
    }

    async function fpmPlanPrint() {
      if (!await _preparePlanSheet()) return;
      window.print();
      // Belt-and-suspenders cleanup: afterprint may not fire in Chrome on Cancel.
      // window.print() is synchronous in Chrome (returns after dialog closes).
      _releasePlanSheet();
    }

    // Renderer entry points. The renderer sets the view itself, then prepares the
    // sheet and leaves it pinned while it exports the PDF — it never releases.
    window.fpmPreparePlanSheet = _preparePlanSheet;
    window.fpmSetPlanBasemapRes = function (res) {
      _planBasemapRes = res === 'sharp' ? 'sharp' : 'legible';
    };
    window.fpmSetPlanFit = function (fit) {
      fit = Number(fit);
      _planFit = (isFinite(fit) && fit > 0 && fit < 1) ? Math.max(0.1, fit) : 1;
    };
    window.fpmSetPrintOrientation = function (landscape) {
      _printLandscape = !!landscape;
      _applyOrientStyle();
      _updateOrientBtn();
    };
    window.fpmSetPrintPaper = function (name) {
      if (!PAPER_SIZES[name]) return false;
      _printPaper = name;
      try { localStorage.setItem('mapPrintPaper', name); } catch (_) {}
      _applyOrientStyle();
      return true;
    };
    </script>
    <?php ?>
    <script>window._microductTypes = <?= json_encode($microductTypes, JSON_HEX_TAG) ?>;</script>
    <?php
    return ob_get_clean();
});
