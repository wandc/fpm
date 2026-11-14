/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
/* global L, bootstrap */
// map.helper.js — asset-specific Leaflet map editor for Tinuum.
// Loaded automatically by the template for page=map.
// Reads window.MAP_CONFIG (set by map.php inline script after this file loads).

(function () {
  'use strict';

  // How this installation names its own network (pole owner, coverage provider); set in app/org.php.
  const OWN_NAME = (window.ORG && window.ORG.ownName) || 'Own network';
  const OWN_KEY  = OWN_NAME.toLowerCase();
  const OWN_MARK = (window.ORG && window.ORG.ownMark) || '/images/own-mark.svg';

  // ── constants ───────────────────────────────────────────────────────────────

  const MAP_VIEW_KEY             = 'fpm_map_view';
  const MAP_LAYERS_KEY           = 'fpm_map_layers';
  const MAP_TOOLBAR_SECTIONS_KEY = 'fpm_map_toolbar_sections';
  const MAP_BASEMAP_KEY          = 'fpm_map_basemap';
  const MAP_GREY_OPACITY_KEY     = 'fpm_map_grey_opacity';  // legacy: Grey+B&W shared fade, read once to seed the per-basemap keys
  const MAP_FADE_KEY_PREFIX      = 'fpm_map_fade_';         // + slugged basemap name, one key per basemap
  const MAP_FADE_MIN             = 0.15;                       // floor, so a basemap can never be faded to nothing
  const MAP_MONO_OFF_KEY         = 'fpm_map_mono_off';   // layers B&W switched off, to put back on the way out
  const MAP_FIXUPS_KEY           = 'fpm_map_fixups';     // one-time repairs already applied in this browser
  const MAP_POLE_FILTER_KEY      = 'fpm_map_pole_filter'; // filter survives a reload; see savePoleFilterState

  const STATUS_COLORS = {
    active:                '#198754',
    plan:                  '#E0A800',
    'under construction':  '#f97316',
    retired:               '#6c757d',
  };

  // Span/casing thickness grows with fibre count on a log scale (non-linear — 96ct
  // is not 2× of 48ct), wide spread. 48ct ≈ 7, 96 ≈ 9, 144 ≈ 11, 288 ≈ 13.
  function spanWeight(fibreCount) {
    const f = fibreCount || 0;
    if (f < 12) return 4;
    return Math.max(4, Math.min(16, Math.round(2.6 * Math.log2(f) - 8)));
  }

  const API = {
    features:   '/api/features.php',
    fosc:       '/api/fosc.php',
    foscNbrs:   '/api/fosc_neighbours.php',
    pop:        '/api/pop.php',
    pole:       '/api/pole.php',
    poleBulk:   '/api/pole_bulk.php',
    span:       '/api/span.php',
    splitSpan:  '/api/split_span.php',
    flextin:    '/api/span_flextin.php',
    onos:       '/api/uisp_onos.php',
    drops:      '/api/ont_drops.php',
    assoc:      '/api/ont_association.php',
    leadAssoc:  '/api/lead_association.php',
    svcAssoc:   '/api/service_association.php',
    mst:        '/api/mst.php',
    anchor:     '/api/anchor.php',
    buildingAnchor: '/api/building_anchor.php',
    uguard:     '/api/uguard.php',
    strand:     '/api/strand.php',
    conduit:    '/api/conduit.php',
    termination: '/api/conduit_termination.php',
    vault:      '/api/vault.php',
    note:       '/api/note.php',
    density:    '/api/asset_density.php',
    aldenPoles:  '/api/alden_poles.php',
    aldenVaults: '/api/alden_vaults.php',
    aldenImport: '/api/alden_import.php',
    aldenDensity: '/api/alden_density.php',
    nbAddresses:  '/api/nb_addresses.php',
    nbParcels:    '/api/nb_parcels.php',
  };

  // ── Label tooltips: measure once, not once per label ─────────────────────────
  // Leaflet 1.9.4 Tooltip._setPosition reads container.offsetWidth/offsetHeight for
  // EVERY tooltip, and each read forces a synchronous layout because the previous
  // label was just written to the page. With ~300 permanent closure labels that was
  // ~1.2 s of a ~2 s redraw (CPU profile, zoom-14 pan). A permanent label placed to
  // the 'right' needs only its HEIGHT, and every label of one style has the same
  // height, so measure it once per style and font size (the zoom-scale class on the
  // map ramps the font) and reuse it. Positions come out exactly as before.
  if (window.L && L.version === '1.9.4' && L.Tooltip && !L.Tooltip.prototype._tinFastSetPosition) {
    const _origSetPosition = L.Tooltip.prototype._setPosition;
    const _labelH = {};
    L.Tooltip.prototype._tinFastSetPosition = true;
    L.Tooltip.prototype._setPosition = function (pos) {
      const o = this.options;
      if (o.direction !== 'right' || !o.permanent) return _origSetPosition.call(this, pos);
      const mc = this._map.getContainer();
      const key = (o.className || '') + '|' + (mc.classList.contains('zoom-max') ? 3 : mc.classList.contains('zoom-hi') ? 2 : mc.classList.contains('zoom-mid') ? 1 : 0) +
                  '|' + (document.body.classList.contains('map-mono') ? 'm' : '');
      let h = _labelH[key];
      if (h === undefined) { h = this._container.offsetHeight; if (h > 0) _labelH[key] = h; }
      if (!h) return _origSetPosition.call(this, pos);
      const c = this._container;
      pos = pos.subtract(L.point(0, h / 2, true)).add(L.point(o.offset)).add(this._getAnchor());
      L.DomUtil.removeClass(c, 'leaflet-tooltip-left');
      L.DomUtil.removeClass(c, 'leaflet-tooltip-top');
      L.DomUtil.removeClass(c, 'leaflet-tooltip-bottom');
      L.DomUtil.addClass(c, 'leaflet-tooltip-right');
      L.DomUtil.setPosition(c, pos);
    };
  }

  // Below this zoom the map shows the cached asset-density hexagons instead of
  // thousands of individual markers (see loadDensity / applyZoomRegime). Was 15;
  // 14 (a ~1 km scale bar) still draws real assets. 13 was tried and loads ~4x as
  // much (2,600+ markers, ~4 s per redraw), so hexagons take over below 14.
  const DENSITY_ZOOM = 14;
  // Span/conduit labels and Alden candidate poles stay off until this zoom: at
  // z13-14 the labels are unreadable and Alden is 10k+ more markers.
  const DETAIL_TEXT_ZOOM = 15;

  // Overlash lashing ticks have a 14 px pitch; below this zoom a pole span is short
  // enough on screen that they collapse into a smudge.
  const LASH_TICK_ZOOM = 15;

  // Pole numbers (nbp/telco). At lat 46 a 50 m pole span is ~15 px at z15 and ~60 px
  // at z17, so below 17 the labels are illegible mush — and binding all ~2400 of
  // them as permanent tooltips is the single biggest DOM cost on the page.
  const POLE_LABEL_ZOOM = 17;

  // ── One task at a time ──────────────────────────────────────────────────────
  // While the user is placing, drawing, moving or picking something, clicks on other assets must not open
  // their popups: the task owns the map. Every such task shows the edit bar (showEditBar) or sets one of
  // the flags below, so one test covers them all. Leaflet opens a layer's popup in _openPopup, which is
  // patched here (before any layer binds a popup) to do nothing while a task is on; the click still
  // reaches the task's own handlers.
  function mapTaskActive() {
    const bar = document.getElementById('mapEditBar');
    if (bar && bar.style.display !== 'none') return true;
    if (addingStrand || drawingSpan || routingSpanViaPoles || routingOnuDrop || routingCustDrop) return true;
    if (mapPick || activeEdit || measureActive) return true;
    return !!(map && map.pm && ((map.pm.globalDrawModeEnabled && map.pm.globalDrawModeEnabled()) ||
                                (map.pm.globalDragModeEnabled && map.pm.globalDragModeEnabled())));
  }
  (function () {
    const open = L.Layer.prototype._openPopup;
    L.Layer.include({
      _openPopup: function (e) {
        if (mapTaskActive()) return;
        if ((this._tinFosc || this._tinVault) && openPointChooser(this, e)) return;
        return open.call(this, e);
      },
    });
  })();

  // ── module state ────────────────────────────────────────────────────────────

  let map, poleGroup, foscGroup, popGroup, spanGroup, spanLabelGroup, flextinDistGroup, routeDistGroup, onuGroup, dropGroup, anchorGroup, uguardGroup;
  let buildingAnchorGroup;          // building anchors (change-of-direction strand supports)
  const buildingAnchorMarkerById = {};
  let wifiGroup, zoningGroup, communityGroup, dimGroup, markGroup, surveyGroup, easementGroup, conduitGroup, vaultGroup, handholeGroup, noteGroup, strandGroup, civicGroup, parcelGroup, wetlandGroup, boundaryGroup, exchGroup, roadCovGroup, roadCovOutsideGroup, serviceAreaGroup, rogersFundedGroup, gnbWanGroup, bellGroup, overbuildGroup;
  let aldenGroup, aldenVaultGroup;  // Alden One staging poles/vaults (import candidates)
  let densityGroup;                 // zoomed-out asset-density hexagons
  let aldenDensityGroup;            // zoomed-out Alden-candidate density hexagons (purple)
  let densityLegend    = null;      // Leaflet control, shown only in overview
  let inOverview       = null;      // null=unknown, then true/false regime tracking
  let cfg = {};

  // Track the single active edit so we can cancel it before starting another
  let activeEdit = null;   // { type: 'fosc'|'span', layer, cancel }

  let pendingFoscLatLng  = null;
  let pendingMstLatLng   = null;
  let pendingPoleLatLng  = null;
  let pendingDrawMode    = 'fosc'; // 'fosc' | 'mst' | 'pole' | 'pop' | 'note' | 'note-move'
  let pendingMoveNoteProps = null;
  let pendingSpanFoscs   = null;  // { fromId, fromName, toId, toName }
  let foscData           = [];    // { fosc_id, splice_num, lat, lon, kind } — built from loaded features
  let poleData           = [];    // { fid, lat, lon } — built from loaded features
  let strandData         = [];    // { id, from_pole_fid, to_pole_fid, from_pole_uid, to_pole_uid, is_overlash, from_lat, from_lon, to_lat, to_lon }
  let hasSavedView       = false; // true when map position was restored from localStorage
  let labelsVisible      = true;  // closure splice-num labels on/off
  let minLabelsMode      = false; // reduced label set — today: no pole numbers (off by default)
  let declutterLabels    = true;  // hide labels that would land on top of a more important one
  let polesVisible       = true;  // pole layer on/off (visible by default)
  let spansVisible       = true;  // cable span layer on/off
  let closuresVisible    = true;  // closure layer on/off
  let popsVisible        = true;  // POP (office building) layer on/off
  let anchorsVisible     = true;  // anchor layer on/off
  let buildingAnchorsVisible = true; // building-anchor layer on/off
  let uguardsVisible     = true;  // u-guard layer on/off
  let onuVisible         = true;  // ONU layer on/off (visible by default)
  let aldenVisible       = false; // Alden One staging poles (import candidates) — off by default
  let aldenLinkedVisible = false; // also show already-imported Alden poles (muted)
  let conduitsVisible    = true;  // conduit run layer on/off
  let vaultsVisible      = true;  // vault layer on/off
  let handholesVisible   = true;  // handhole layer on/off
  let notesVisible       = true;  // map notes layer on/off
  let doneNotesVisible   = false; // finished notes (tree trim done, etc.): hidden unless asked for
  let abandonedVisible   = false; // plant that belongs only to abandoned work orders: hidden unless asked for
  let abandonedGroup;             // ghosts of that plant (grey, dashed), drawn only when the toggle is on
  let dimsVisible        = true;  // saved dimensions layer on/off
  let marksVisible       = true;  // cable sheath marks (meter marks off the sheath), shown when zoomed in
  let easementsVisible   = true;  // utility easement polygons on/off
  let wifiVisible        = true;  // WiFi access points and coverage cones on/off
  let strandsVisible     = true;  // messenger strand layer on/off
  let flextinDistVisible = false; // FlexTIN pole-to-pole distance labels (off by default — reduces clutter)
  let flextinClosuresVisible = true; // Optitaps (taps + MSTs) within the Closures layer — off leaves the real closures
  let routeDistVisible   = false; // pole-to-pole / route distances in metres (off by default — reduces clutter)
  let scaleVisible       = true;  // metric scale bar on/off
  let densityVisible     = true;  // zoomed-out density hexagons on/off (on by default)
  let civicVisible       = false; // GeoNB civic address layer (off by default)
  let parcelVisible      = false; // SNB property parcels (off by default)
  let wetlandVisible     = false; // GeoNB wetlands + 30 m WAWA band (off by default)
  let zoningVisible      = false; // Village of Salisbury zoning, infill overlay and serviceable area (off by default)
  let communityVisible   = false; // GeoNB community names and boundaries (off by default)
  let boundaryVisible    = false; // Salisbury town limits (off by default)
  let boundaryOpacity    = 0.25; // fill opacity for town limits polygon
  let boundaryLoaded     = false; // true once GeoJSON has been fetched
  let exchVisible        = false; // Salisbury phone exchange boundary (off by default)
  let exchLoaded         = false; // true once GeoJSON has been fetched
  let roadCovVisible     = false; // road coverage layer (off by default)
  let roadCovLoaded      = false; // true once fetched from API
  let serviceAreaVisible = false; // service area polygon layer (off by default)
  let serviceAreaLoaded  = false; // true once fetched from API
  let outsideRoadsVisible  = false; // show roads outside Salisbury boundary
  let _poleExtraPermits   = [];     // additional permits for current pole modal (chip list)
  let _foscExtraPermits   = [];     // additional permits for current closure modal (chip list)
  let _spanExtraPermits   = [];     // additional permits for current span modal (chip list)
  let drawingSpan          = false; // true while Geoman Line draw for a new span is active
  let _spanDrawMouseMove   = null;  // mousemove ref for closure snap highlight during span draw
  let _spanFirstVertCheck  = null;  // pm:vertexadded ref for first-vertex closure validation
  let _spanWorkingLayer    = null;  // Geoman working layer (pm:vertexadded fires here, not map)
  let _closureSnapMarker   = null;  // fosc marker currently snap-highlighted during span draw
  let _spanFromFoscId      = null;  // fosc_id confirmed as span start
  let _spanFromFosc        = null;  // full foscData entry saved at first-vertex confirm (viewport-stable)
  let _spanPickEndMode     = false; // true while waiting for user to click an end closure
  let _spanPickEndGeom     = null;  // saved LineString coords while picking end closure
  let salisburyOnlyMode    = false; // hide assets outside Salisbury town limits
  let _salisburyRing      = null;  // cached [lng,lat][] outer ring (loaded on demand)
  let _salisburyBbox      = null;  // [minLng, minLat, maxLng, maxLat] for fast bbox pre-check
  const roadCovSelection = new Set(); // seg_ids of shift-selected segments
  const rcComboHidden    = new Set(); // "provider|technology" keys currently hidden by filter
  let rcBoxSelecting     = false; // true while drawing a box-select rectangle
  let rcBoxStart         = null;  // L.LatLng where drag started
  let rcBoxRect          = null;  // L.Rectangle preview while dragging
  let rcBoxDownHandler   = null;  // native mousedown listener ref for cleanup
  let addingStrand       = false; // true while building a strand path
  let strandPath         = [];    // ordered array of { fid, uid, nbp, lat, lon } pole objects
  let strandPreviewLine  = null;  // L.Polyline showing committed path so far
  let strandRubberBand   = null;  // L.Polyline rubber-band from last pole to cursor
  let strandSnapFid      = null;  // fid of pole currently highlighted as snap target

  let routeSnapFid        = null;  // fid of pole currently snap-highlighted during routing modes

  // ── Span route-via-poles state ───────────────────────────────────────────────
  let routingSpanViaPoles = false;
  let spanRouteTarget     = null;  // span properties { cable_span_id, ... }
  let spanRouteFromLL     = null;  // L.LatLng of from-FOSC (first point of existing line)
  let spanRouteToLL       = null;  // L.LatLng of to-FOSC (last point of existing line)
  let spanRoutePoles      = [];    // { lat, lon } waypoints clicked by user
  let spanRoutePreview    = null;  // L.Polyline preview of full route
  let spanRouteRubber     = null;  // L.Polyline rubber-band cursor line

  // ── ONU drop route-via-poles state ──────────────────────────────────────────
  let routingOnuDrop      = false;
  let onuDropRouteTarget  = null;  // { uisp_device_id, fosc_ll, onu_ll }
  let onuDropRoutePoles   = [];    // { lat, lon } waypoints
  let onuDropRoutePreview = null;  // L.Polyline preview
  let onuDropRouteRubber  = null;  // L.Polyline rubber-band

  // ── Customer drop route-via-poles state ─────────────────────────────────────
  // Shared by CRM leads (keyed on crm_client_id) and prepared services (keyed on
  // crm_service_id); `kind` picks which association endpoint the route saves to.
  let routingCustDrop      = false;
  let custDropRouteTarget  = null;  // { kind: 'lead'|'service', id, fosc_ll, cust_ll }
  let custDropRoutePoles   = [];    // { lat, lon } waypoints
  let custDropRoutePreview = null;  // L.Polyline preview
  let custDropRouteRubber  = null;  // L.Polyline rubber-band

  let pendingNoteLatLng  = null;  // L.LatLng set after user clicks map in note-place mode
  let editingNoteId      = 0;     // >0 when editing an existing note

  let pendingConduitGeojson = null;
  let pendingVaultLatLng    = null;
  let pendingVaultType      = 'vault';  // 'vault' | 'handhole' | 'pullbox'
  let editingConduitId      = 0;        // >0 when editing an existing conduit
  let editingVaultId        = 0;        // >0 when editing an existing vault
  let onuLoaded            = false; // whether ONU markers have been fetched at least once
  let aldenLoaded          = false; // whether Alden staging markers have been fetched at least once
  const onuPropsById       = {};    // uisp_device_id → props, for popup onclick handlers
  const dropPropsByDevId      = {};    // uisp_device_id → { fosc_lat, fosc_lon, ont_lat, ont_lon, routed }
  const leadDropPropsByCrmId  = {};    // crm_client_id (string) → { fosc_lat, fosc_lon, ont_lat, ont_lon, routed }
  const leadPropsById         = {};    // crm_client_id (string) → props, for lead popup handlers
  const svcDropPropsBySvcId   = {};    // crm_service_id (string) → { fosc_lat, fosc_lon, ont_lat, ont_lon, routed }
  const svcPropsById          = {};    // crm_service_id (string) → props, for prepared-service popup handlers

  // ── Multi-select state ───────────────────────────────────────────────────────
  const poleSelection   = new Set();  // Set of selected fids
  const poleMarkerByFid = {};         // fid → Leaflet marker, rebuilt on each renderAll

  // ── Pole filter state ───────────────────────────────────────────────────────
  let addAnchorMode    = false; // true while waiting for user to click a pole
  let addUguardMode    = false; // true while waiting for user to click a pole
  let aldenLinkPoleId  = null;  // Alden pole_id awaiting a click on an existing pole to link to
  let insertStrandMode = false; // true while waiting for user to click a pole to insert into a strand
  let insertStrandId   = 0;     // id of the strand being split
  const uguardByPoleFid = {};   // pole fid → uguard props, for Add/Edit toggle in popup

  let poleFilterOpen   = false;
  // Layers that carry no permit, so a permit filter cannot match them. The filter is a whitelist: with a
  // Tinuum Permit / Bell Permit filter on they are hidden unless ticked in the panel ("Add back").
  const ADDBACK_LAYERS = [
    { key: 'customers', label: 'ONUs and drops' },
    { key: 'easements', label: 'Easements' },
    { key: 'survey',    label: 'Survey lines' },
    { key: 'alden',     label: 'Alden staging poles and vaults' },
  ];
  const addBackGroups = () => ({
    customers: [onuGroup, dropGroup], overbuild: [overbuildGroup], easements: [easementGroup],
    survey: [surveyGroup], alden: [aldenGroup, aldenVaultGroup],
  });
  const addBackChecked = () => Array.from(document.querySelectorAll('#pfAddBack input[data-addback]:checked'))
    .map(i => i.dataset.addback);
  function setGroupHidden(layer, hidden) {
    if (!layer) return;
    if (layer.eachLayer && !layer.getElement) layer.eachLayer(l => setGroupHidden(l, hidden));
    else setLayerHidden(layer, hidden);
  }
  // keep = keys to leave showing; null shows every group (no permit filter).
  function applyAddBack(keep) {
    const g = addBackGroups();
    Object.keys(g).forEach(k => g[k].forEach(grp => setGroupHidden(grp, keep !== null && !keep.includes(k))));
  }
  let poleFilterValues = {};   // { work_order, uid, owner, type, nbp_num, telco_num, tags_num }
  let allPermitsLower         = [];    // every Tinuum permit number, lower case, for the permit filter's exact matches
  fetch('/api/fpm_permits_list.php').then(r => r.json()).then(d => {
    if (d && d.ok && Array.isArray(d.permits)) allPermitsLower = d.permits.map(x => String(x).toLowerCase());
  }).catch(() => {});
  let poleFilterHasWO         = false; // fade poles without a work order
  let poleFilterHasBellPermit = false; // fade poles without a Bell permit
  let poleFilterTimer  = null;

  // ── Measure tool state ───────────────────────────────────────────────────────
  let measureActive   = false;
  let measurePoints   = [];   // L.LatLng[]
  let measurePolyline = null; // L.Polyline on map
  let measureLabels   = [];   // L.Marker[] for segment / total labels
  let measureCursor   = null; // L.Polyline rubber-band line to cursor

  // ── init ────────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function () {
    cfg = window.MAP_CONFIG || {};
    if (!document.getElementById('mapCanvas')) return;

    map = L.map('mapCanvas', { zoomControl: true, maxZoom: 23, boxZoom: false });
    map.getContainer().focus();  // focus on load so arrow keys work immediately
    window._tinMap = map; // expose for debugging

    // updateWhenZooming:false — don't re-request tiles mid animation; keepBuffer:4
    // keeps a wider ring of tiles alive so a small pan doesn't refetch. Both apply
    // to every basemap below.
    const TILE_PERF = { updateWhenZooming: false, keepBuffer: 4 };

    // Every basemap carries its own fade, remembered separately, so Google
    // satellite can sit at 40% for tracing while Street stays fully opaque.
    const fades   = {};   // basemap name → current opacity
    const fadeKey = (name) => MAP_FADE_KEY_PREFIX + name.toLowerCase().replace(/[^a-z0-9]+/g, '_');
    const fadeFor = function (name, ...legacyKeys) {
      let v = parseFloat(localStorage.getItem(fadeKey(name)));
      // Earlier keys for the same basemap (Grey and B&W shared one before fades
      // went per-basemap; Grey was renamed Street (Grey)); seed from them.
      for (const k of legacyKeys) { if (!isFinite(v)) v = parseFloat(localStorage.getItem(k)); }
      if (!isFinite(v) || v < MAP_FADE_MIN || v > 1) v = 1.0;
      fades[name] = v;
      return v;
    };

    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      ...TILE_PERF,
      maxNativeZoom: 19, maxZoom: 23,
      opacity: fadeFor('Street'),
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    });

    const satelliteLayer = L.tileLayer(
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      ...TILE_PERF,
      maxNativeZoom: 19, maxZoom: 23,
      opacity: fadeFor('Satellite (ESRI)'),
      attribution: 'Tiles &copy; Esri &mdash; Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, GIS User Community',
    });

    // Esri Wayback: the same World Imagery, one frozen copy per release (2014 on). {rel} is the release
    // number; the Imagery date slider below picks it (the default is a recent release).
    const HIST = 'Satellite (ESRI history)';
    const wbLayer = L.tileLayer(
      'https://wayback.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/WMTS/1.0.0/default028mm/MapServer/tile/{rel}/{z}/{y}/{x}', {
      ...TILE_PERF,
      maxNativeZoom: 19, maxZoom: 23,
      rel: 43291,
      opacity: fadeFor(HIST),
      attribution: 'Imagery &copy; Esri World Imagery Wayback',
    });

    const googleSatLayer = L.tileLayer(
      'https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
      ...TILE_PERF,
      maxNativeZoom: 20, maxZoom: 23,
      opacity: fadeFor('Satellite (Google)'),
      attribution: 'Imagery &copy; Google',
      subdomains: ['mt0','mt1','mt2','mt3'],
    });

    // Street (Grey) — the Street tiles through a greyscale filter (.map-grey-tiles),
    // so coloured overlays pop out. Was called "Grey".
    const greyLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      ...TILE_PERF,
      maxNativeZoom: 19, maxZoom: 23,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      className: 'map-grey-tiles',
      opacity: fadeFor('Street (Grey)', fadeKey('Grey'), MAP_GREY_OPACITY_KEY),
    });

    // The same for Google's imagery. A separate instance, as with B&W below.
    const googleSatGreyLayer = L.tileLayer(
      'https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
      ...TILE_PERF,
      maxNativeZoom: 20, maxZoom: 23,
      opacity: fadeFor('Satellite (Google) Grey'),
      attribution: 'Imagery &copy; Google',
      subdomains: ['mt0','mt1','mt2','mt3'],
      className: 'map-grey-tiles',
    });

    // B&W Plan — the same Grey tiles (same class), but the overlays
    // are repainted as black line art by the body.map-mono stylesheet in map.php.
    // A separate tileLayer instance is required because L.control.layers keys its
    // entries by layer identity; the tiles themselves come from browser cache.
    const bwLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      ...TILE_PERF,
      maxNativeZoom: 19, maxZoom: 23,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      className: 'map-grey-tiles',
      opacity: fadeFor('B&W Plan', MAP_GREY_OPACITY_KEY),
    });

    // A plan prints the basemap as chosen here — colour stays colour — so the
    // grey variants are choices, not a print mode.
    const basemaps = {
      'Street':                  streetLayer,
      'Street (Grey)':           greyLayer,
      'Satellite (ESRI)':        satelliteLayer,
      [HIST]:                    wbLayer,
      'Satellite (Google)':      googleSatLayer,
      'Satellite (Google) Grey': googleSatGreyLayer,
      'B&W Plan':                bwLayer,
    };

    // Restore saved basemap, default to Street
    let savedBasemap = localStorage.getItem(MAP_BASEMAP_KEY);
    if (savedBasemap === 'Grey') savedBasemap = 'Street (Grey)';   // renamed
    const activeBasemap = basemaps[savedBasemap] ? savedBasemap : 'Street';
    basemaps[activeBasemap].addTo(map);

    // On a phone the open panel takes too much of the screen: keep just the layers button.
    const layersCtrl = L.control.layers(basemaps, {}, { position: 'topright', collapsed: window.matchMedia('(max-width: 768px)').matches }).addTo(map);

    // ── Fade slider — tucked into the layers control panel. One slider that acts
    //    on whichever basemap is currently active, reading and writing that
    //    basemap's own stored fade. Appended to the control's outer section (NOT
    //    the base-layer list, which Leaflet's _update() rebuilds on every
    //    layeradd, wiping it). ──
    let fadeInput = null;
    let fadeVal   = null;
    let fadeName  = activeBasemap;   // basemap the slider currently drives

    const lc = layersCtrl.getContainer();
    if (lc) {
      const section = lc.querySelector('.leaflet-control-layers-list') || lc;
      const fadeRow = document.createElement('div');
      fadeRow.className = 'map-fade-row';
      fadeRow.innerHTML =
        '<span class="map-fade-lbl">Fade</span>' +
        '<input type="range" min="' + MAP_FADE_MIN + '" max="1" step="0.05" aria-label="Basemap opacity">' +
        '<span class="map-fade-val"></span>';
      section.appendChild(fadeRow);
      L.DomEvent.disableClickPropagation(fadeRow);
      L.DomEvent.disableScrollPropagation(fadeRow);
      fadeInput = fadeRow.querySelector('input');
      fadeVal   = fadeRow.querySelector('.map-fade-val');
      fadeInput.addEventListener('input', function () {
        const o = parseFloat(this.value);
        fades[fadeName] = o;
        if (basemaps[fadeName]) basemaps[fadeName].setOpacity(o);
        fadeVal.textContent = Math.round(o * 100) + '%';
        try { localStorage.setItem(fadeKey(fadeName), String(o)); } catch {}
      });
    }

    // ── Imagery date (Wayback): shown only while that basemap is on. Many releases carry identical
    //    pictures, so the slider steps through the releases where the picture at the map centre
    //    actually differs: one z16 tile per release is fetched and compared by hash (about 2 MB, a
    //    few seconds), and redone when the view moves to another tile. ──
    const WB_BASE = 'https://wayback.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/WMTS/1.0.0/default028mm/MapServer/tile/';
    const WB_DATE_KEY = 'fpm_map_wb_date';
    let wbReleases = null;            // [{id, date}] oldest first
    let wbList = [];                  // distinct pictures here: [{id, date}]
    let wbTile = null;                // 'x/y' the list was built for
    let wbScanId = 0;
    const wbCache = {};               // 'x/y' → wbList
    let wbRow = null;
    if (lc) {
      const section = lc.querySelector('.leaflet-control-layers-list') || lc;
      wbRow = document.createElement('div');
      wbRow.className = 'map-wb-row';
      wbRow.style.display = 'none';
      wbRow.innerHTML = '<div class="map-wb-head">Photo taken <b class="map-wb-date"></b></div>' +
        '<input type="range" min="0" max="0" step="1" aria-label="Photo date">' +
        '<div class="map-wb-src"></div><div class="map-wb-note"></div>';
      section.appendChild(wbRow);
      L.DomEvent.disableClickPropagation(wbRow);
      L.DomEvent.disableScrollPropagation(wbRow);
    }
    const wbEls = () => wbRow && ({ date: wbRow.querySelector('.map-wb-date'), src: wbRow.querySelector('.map-wb-src'), range: wbRow.querySelector('input'), note: wbRow.querySelector('.map-wb-note') });

    const wbTileOf = (z) => {
      const c = map.getCenter(), n = Math.pow(2, z);
      const x = Math.floor((c.lng + 180) / 360 * n);
      const y = Math.floor((1 - Math.asinh(Math.tan(c.lat * Math.PI / 180)) / Math.PI) / 2 * n);
      return { x, y, key: x + '/' + y };
    };

    // How close a release goes differs (here 2014-2020 stop at z17, the recent ones reach z20): find the
    // highest zoom with a tile at the view centre so the layer enlarges the last real one instead of
    // asking for tiles that do not exist (grey).
    let wbSetSeq = 0;
    const wbSetRelease = async (id) => {
      const my = ++wbSetSeq;
      let top = 16;
      for (const z of [20, 19, 18, 17]) {
        const t = wbTileOf(z);
        try { if ((await fetch(WB_BASE + id + '/' + z + '/' + t.y + '/' + t.x)).ok) { top = z; break; } } catch {}
      }
      if (my !== wbSetSeq) return;
      wbLayer.options.rel = id;
      wbLayer.options.maxNativeZoom = top;
      wbLayer.redraw();
    };

    const wbShow = (i) => {
      const e = wbEls(), r = wbList[i]; if (!e || !r) return;
      e.date.textContent = (r.exact ? '' : '~') + r.date;
      e.src.textContent = (r.src ? r.src + ' · ' : '') + 'Esri release ' + r.rel;
    };

    const wbPick = (i, save) => {
      const e = wbEls(); if (!e || !wbList.length) return;
      i = Math.max(0, Math.min(wbList.length - 1, i));
      e.range.value = i;
      wbShow(i);
      if (wbLayer.options.rel !== wbList[i].id) wbSetRelease(wbList[i].id);
      if (save) { try { localStorage.setItem(WB_DATE_KEY, wbList[i].date); } catch {} }
    };

    const wbSetList = (list) => {
      wbList = list;
      const e = wbEls(); if (!e) return;
      e.range.max = Math.max(0, list.length - 1);
      e.range.disabled = list.length < 2;
      e.note.textContent = list.length > 1 ? list.length + ' different pictures here, ' + list[0].date.slice(0, 4) + ' to ' + list[list.length - 1].date.slice(0, 4) : 'Only one picture here';
      // keep the date the user was on (nearest earlier picture), else the newest
      let want = null; try { want = localStorage.getItem(WB_DATE_KEY); } catch {}
      let i = list.length - 1;
      if (want) { i = 0; list.forEach((r, k) => { if (r.date <= want) i = k; }); }
      wbPick(i, false);
    };

    const wbScan = async () => {
      const e = wbEls(); if (!e) return;
      const t = wbTileOf(16);
      if (t.key === wbTile && wbList.length) return;
      if (wbCache[t.key]) { wbTile = t.key; wbSetList(wbCache[t.key]); return; }
      const my = ++wbScanId;
      e.note.textContent = 'Finding imagery dates…';
      try {
        if (!wbReleases) {
          const cfgJson = await (await fetch('https://s3-us-west-2.amazonaws.com/config.maptiles.arcgis.com/waybackconfig.json')).json();
          wbReleases = Object.entries(cfgJson).map(([id, v]) => ({ id: Number(id), rel: (v.itemTitle.match(/(\d{4}-\d{2}-\d{2})/) || [])[1], meta: v.metadataLayerUrl }))
            .filter(r => r.rel).sort((a, b) => a.rel < b.rel ? -1 : 1);
        }
        const hashes = new Array(wbReleases.length);
        let next = 0, done = 0;
        const worker = async () => {
          while (next < wbReleases.length) {
            const k = next++;
            try {
              const buf = await (await fetch(WB_BASE + wbReleases[k].id + '/16/' + t.y + '/' + t.x)).arrayBuffer();
              hashes[k] = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-1', buf)), b => b.toString(16).padStart(2, '0')).join('');
            } catch { hashes[k] = null; }
            if (my === wbScanId && (++done % 20 === 0)) e.note.textContent = 'Finding imagery dates… ' + done + '/' + wbReleases.length;
          }
        };
        await Promise.all(Array.from({ length: 10 }, worker));
        if (my !== wbScanId) return;       // the view moved on
        const picked = []; let prev;
        wbReleases.forEach((r, k) => { if (hashes[k] && hashes[k] !== prev) { picked.push(r); prev = hashes[k]; } });
        if (!picked.length) throw new Error('no tiles');
        // A release is when Esri published a version, not when the photo was taken: ask the release's
        // metadata service for the capture date (and the source) of the picture at the map centre.
        e.note.textContent = 'Looking up capture dates…';
        const c = map.getCenter();
        const meta = await Promise.all(picked.map(async (r) => {
          for (const lid of [6, 5, 7, 8]) {     // the layer for the zoom the imagery is shown at; coarser as a fallback
            try {
              const j = await (await fetch(r.meta + '/' + lid + '/query?f=json&where=1%3D1&geometry=' + c.lng + ',' + c.lat +
                '&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects&outFields=SRC_DATE2,SRC_RES,SRC_DESC,NICE_NAME&returnGeometry=false')).json();
              const a = j.features && j.features[0] && j.features[0].attributes;
              if (a && a.SRC_DATE2 > 0) return { date: new Date(a.SRC_DATE2).toISOString().slice(0, 10), src: [a.NICE_NAME && a.NICE_NAME.trim(), a.SRC_DESC, a.SRC_RES ? a.SRC_RES + ' m' : ''].filter(Boolean).join(' · ') };
            } catch {}
          }
          return null;
        }));
        if (my !== wbScanId) return;
        // two releases can carry the same photo (re-encoded): keep the first of each capture date
        const list = []; const seenDates = new Set();
        picked.forEach((r, k) => {
          const m = meta[k]; const date = m ? m.date : r.rel;
          if (seenDates.has(date)) return;
          seenDates.add(date);
          list.push({ id: r.id, date, src: m ? m.src : '', rel: r.rel, exact: !!m });
        });
        wbCache[t.key] = list; wbTile = t.key;
        wbSetList(list);
      } catch (err) {
        if (my === wbScanId) e.note.textContent = 'Could not load imagery dates';
      }
    };

    let wbTimer = null;
    const wbSchedule = () => { clearTimeout(wbTimer); wbTimer = setTimeout(() => { if (fadeName === HIST) wbScan(); }, 1200); };
    map.on('moveend', wbSchedule);
    if (wbRow) {
      const e = wbEls(); let redrawT = null;
      e.range.addEventListener('input', function () {
        const i = parseInt(this.value, 10);
        wbShow(i);
        clearTimeout(redrawT); redrawT = setTimeout(() => wbPick(i, true), 150);
      });
    }

    // Point the slider at a basemap and show that basemap's own fade.
    const syncFadeSlider = function (name) {
      fadeName = name;
      if (wbRow) { wbRow.style.display = name === HIST ? '' : 'none'; if (name === HIST) wbScan(); }
      if (!fadeInput) return;
      const o = fades[name] != null ? fades[name] : 1.0;
      fadeInput.value       = o;
      fadeVal.textContent   = Math.round(o * 100) + '%';
    };
    syncFadeSlider(activeBasemap);
    // The plan renderer's page has no localStorage of the user's, so the fade
    // it should print at arrives with the request (plan_pdf.php ?fade=).
    window.fpmSetBasemapFade = function (o) {
      o = parseFloat(o);
      if (!isFinite(o)) return;
      o = Math.max(MAP_FADE_MIN, Math.min(1, o));
      fades[fadeName] = o;
      if (basemaps[fadeName]) basemaps[fadeName].setOpacity(o);
      syncFadeSlider(fadeName);
    };

    // B&W Plan is a body class, not a layer property — everything downstream
    // (vectors, icons, labels) is repainted by CSS. Toggled here and on the
    // restore path below, which adds the layer directly and never fires
    // baselayerchange.
    const setMonoMode = function (name) {
      document.body.classList.toggle('map-mono', name === 'B&W Plan');
      // The grey basemaps get their own class too: the faint pole numbers that
      // read fine on Street vanish into a grey basemap, so CSS darkens them there.
      document.body.classList.toggle('map-grey', /Grey\b/.test(name));
      applyMonoPaintOrder();   // no-op outside mono; features may already be drawn
    };
    setMonoMode(activeBasemap);

    let basemapInitDone = false;   // see the baselayerchange handler below

    // The overlays that don't belong on an issued drawing — competitive intel,
    // decoration, import candidates — as [name, isOn, toggle]. Read fresh on every
    // call. Going through the same toggle functions the buttons call keeps the
    // buttons in sync and lets the user add any of them back by hand while in B&W.
    const monoClutter = () => [
      ['roadCov',      roadCovVisible,      toggleRoadCoverage],
      ['svcArea',      serviceAreaVisible,  toggleServiceArea],
      ['boundary',     boundaryVisible,     toggleBoundary],
      ['exch',         exchVisible,         toggleExch],
      ['alden',        aldenVisible,        toggleAlden],
      ['notes',        notesVisible,        toggleNotes],
    ];

    // Entering B&W drops them; leaving puts back exactly the ones it dropped, and
    // only those. The toggles write through to the saved layer state, so without
    // the restore one visit to B&W would leave the density hexes (and the rest)
    // switched off in every other view, permanently. The list is persisted rather
    // than held in memory because the user can reload while in B&W and switch away
    // in a later session.
    const dropMonoClutter = function () {
      const off = [];
      monoClutter().forEach(function (row) { if (row[1]) { row[2](); off.push(row[0]); } });
      try { localStorage.setItem(MAP_MONO_OFF_KEY, JSON.stringify(off)); } catch {}
    };

    const restoreMonoClutter = function () {
      let off = null;
      try { off = JSON.parse(localStorage.getItem(MAP_MONO_OFF_KEY)); } catch {}
      if (Array.isArray(off)) {
        // Anything switched back on by hand in the meantime is left alone.
        monoClutter().forEach(function (row) { if (off.indexOf(row[0]) !== -1 && !row[1]) row[2](); });
      }
      try { localStorage.removeItem(MAP_MONO_OFF_KEY); } catch {}
    };

    map.on('baselayerchange', function (e) {
      try { localStorage.setItem(MAP_BASEMAP_KEY, e.name); } catch {}
      syncFadeSlider(e.name);
      setMonoMode(e.name);
      // Only for a real pick from the control. Leaflet defers adding the restored
      // base layer until the map first has a view, so the setView below fires
      // baselayerchange from inside itself — at which point the layer groups the
      // toggles reach for (noteGroup, densityGroup, …) are still uninitialised
      // and throw, and the throw unwinds into setView's caller and loses the
      // restored view.
      if (!basemapInitDone) return;
      if (e.name === 'B&W Plan') dropMonoClutter();
      else                       restoreMonoClutter();
    });

    // Switch the basemap the way the control does, but WITHOUT recording it as
    // the user's choice: a plan carries its own basemap, and opening one should
    // not repaint every other map this browser shows afterwards.
    // The basemap actually on the map right now — which is not necessarily the
    // user's stored choice, since a plan applies its own without recording it.
    window.fpmActiveBasemap = function () { return fadeName; };

    // The active basemap's fade, for whoever is recording the current view.
    window.fpmBasemapFade = function () {
      const o = fades[fadeName];
      return isFinite(o) ? o : 1;
    };

    window.fpmApplyBasemap = function (name, fade) {
      if (basemaps[name]) {
        const keep = localStorage.getItem(MAP_BASEMAP_KEY);
        Object.keys(basemaps).forEach((n) => { if (n !== name && map.hasLayer(basemaps[n])) map.removeLayer(basemaps[n]); });
        if (!map.hasLayer(basemaps[name])) map.addLayer(basemaps[name]);
        fadeName = name;
        setMonoMode(name);
        try { keep === null ? localStorage.removeItem(MAP_BASEMAP_KEY) : localStorage.setItem(MAP_BASEMAP_KEY, keep); } catch {}
      }
      const o = parseFloat(fade);
      if (isFinite(o)) {
        const v = Math.max(MAP_FADE_MIN, Math.min(1, o));
        fades[fadeName] = v;
        if (basemaps[fadeName]) basemaps[fadeName].setOpacity(v);
      }
      syncFadeSlider(fadeName);
    };

    // A link can open the map on a spot: ?lat=..&lon=..&zoom=.. (e.g. from the
    // Expansion Roads page). Otherwise restore the saved view, or the default.
    try {
      const q = new URLSearchParams(location.search);
      const lat = parseFloat(q.get('lat')), lon = parseFloat(q.get('lon'));
      if (isFinite(lat) && isFinite(lon)) {
        map.setView([lat, lon], parseInt(q.get('zoom'), 10) || 16);
        hasSavedView = true;
        // One-shot: drop the position from the address bar so a reload keeps the
        // view the user has panned to (saved in localStorage) instead of snapping
        // back here every time.
        ['lat', 'lon', 'zoom'].forEach(k => q.delete(k));
        const qs = q.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
      }
    } catch {}
    if (!hasSavedView) try {
      const v = JSON.parse(localStorage.getItem(MAP_VIEW_KEY));
      if (v && isFinite(v.lat) && isFinite(v.lng) && isFinite(v.zoom)) {
        map.setView([v.lat, v.lng], v.zoom);
        hasSavedView = true;
      }
    } catch {}
    if (!hasSavedView) map.setView([45.5, -63.5], 10);

    // Persist view on every pan/zoom. A zoom fires BOTH moveend and zoomend, so
    // write on a trailing timer rather than twice per notch.
    let _viewSaveTimer = null;
    map.on('moveend zoomend', function () {
      clearTimeout(_viewSaveTimer);
      _viewSaveTimer = setTimeout(function () {
        try {
          const c = map.getCenter();
          localStorage.setItem(MAP_VIEW_KEY, JSON.stringify(
            { lat: c.lat, lng: c.lng, zoom: map.getZoom() }
          ));
        } catch {}
      }, 200);
    });

    // A first cut of the B&W clutter drop switched the density hexagons off through
    // the toggle, which persists — so browsers that visited B&W before that was
    // fixed have `density: false` saved without the user ever having chosen it, and
    // zooming out looks broken with only the toolbar button to explain it. Undo it
    // once per browser. Anyone who really did want the hexes off just clicks again.
    try {
      const fx = JSON.parse(localStorage.getItem(MAP_FIXUPS_KEY)) || {};
      if (!fx.monoDensity) {
        const ls = JSON.parse(localStorage.getItem(MAP_LAYERS_KEY));
        if (ls && ls.density === false) {
          ls.density = true;
          localStorage.setItem(MAP_LAYERS_KEY, JSON.stringify(ls));
        }
        fx.monoDensity = true;
        localStorage.setItem(MAP_FIXUPS_KEY, JSON.stringify(fx));
      }
    } catch {}

    // Restore saved layer toggle states
    try {
      const ls = JSON.parse(localStorage.getItem(MAP_LAYERS_KEY));
      if (ls) {
        if (ls.poles      === false) polesVisible      = false;
        if (ls.labels     === false) labelsVisible     = false;
        if (ls.minLabels  === true)  minLabelsMode     = true;
        if (ls.declutter  === false) declutterLabels   = false;
        if (ls.onus       === false) onuVisible        = false;
        if (ls.alden      === true)  aldenVisible      = true;
        if (ls.aldenLinked === true) aldenLinkedVisible = true;
        if (ls.spans      === false) spansVisible      = false;
        if (ls.closures   === false) closuresVisible   = false;
        if (ls.pops       === false) popsVisible       = false;
        if (ls.anchors    === false) anchorsVisible    = false;
        if (ls.buildingAnchors === false) buildingAnchorsVisible = false;
        if (ls.uguards    === false) uguardsVisible    = false;
        if (ls.conduits   === false) conduitsVisible   = false;
        if (ls.vaults     === false) vaultsVisible     = false;
        if (ls.handholes  === false) handholesVisible  = false;
        if (ls.notes      === false) notesVisible      = false;
        if (ls.doneNotes  === true)  doneNotesVisible  = true;
        if (ls.abandoned  === true)  abandonedVisible  = true;
        if (ls.dims       === false) dimsVisible       = false;
        if (ls.marks      === false) marksVisible      = false;
        if (ls.easements  === false) easementsVisible  = false;
        if (ls.wifi       === false) wifiVisible       = false;
        if (ls.strands    === false) strandsVisible    = false;
        if (ls.flextinDist === true) flextinDistVisible = true;
        if (ls.flextinClosures === false) flextinClosuresVisible = false;
        if (ls.routeDist   === true) routeDistVisible   = true;
        if (ls.scale      === false) scaleVisible      = false;
        if (ls.density    === false) densityVisible    = false;
        if (ls.civics     === true)  civicVisible      = true;
        if (ls.parcels    === true)  parcelVisible     = true;
        if (ls.wetlands   === true)  wetlandVisible    = true;
        if (ls.zoning     === true)  zoningVisible     = true;
        if (ls.communities === true) communityVisible  = true;
        if (ls.boundary   === true)  boundaryVisible   = true;
        if (ls.exch       === true)  exchVisible       = true;
        if (ls.roadCov    === true)  roadCovVisible    = true;
        if (ls.outsideRoads === true) outsideRoadsVisible = true;
        if (ls.svcArea    === true)  serviceAreaVisible = true;
        if (ls.salisburyOnly === true) salisburyOnlyMode = true;
      }
    } catch {}

    // Layer order: service area → rogers funded → conduits/vaults → poles → spans → closures → drops → ONUs → notes (later = on top)
    serviceAreaGroup = L.featureGroup(); // coverage area polygons (bottom-most layer)
    abandonedGroup = L.featureGroup().addTo(map);   // ghosts of abandoned work-order plant (empty unless the toggle is on)
    if (serviceAreaVisible) { serviceAreaGroup.addTo(map); loadServiceArea(); }
    conduitGroup   = L.featureGroup();
    if (conduitsVisible)  conduitGroup.addTo(map);
    vaultGroup     = L.featureGroup();
    if (vaultsVisible)    vaultGroup.addTo(map);
    handholeGroup  = L.featureGroup();
    if (handholesVisible) handholeGroup.addTo(map);
    surveyGroup = L.featureGroup().addTo(map);   // draft survey lines (tentative boundaries)
    map.createPane('easementPane').style.zIndex = 360;   // over the parcels, under the plant
    easementGroup = L.featureGroup();                    // utility easement polygons
    if (easementsVisible) easementGroup.addTo(map);
    map.createPane('wifiConePane').style.zIndex = 365;   // coverage cones: over the easements, under the plant
    map.createPane('wifiPane').style.zIndex = 640;       // AP markers and aim handles: over the plant
    wifiGroup = L.featureGroup();                        // Salisbury WiFi APs, aim arrows and cones
    map.on('zoomend', wifiReplaceArrows);
    if (wifiVisible) wifiGroup.addTo(map);
    noteGroup = L.featureGroup();     // notes on top of everything else
    if (notesVisible) noteGroup.addTo(map);
    dimGroup = L.featureGroup();      // saved dimensions
    if (dimsVisible) dimGroup.addTo(map);
    markGroup = L.featureGroup();     // sheath marks
    if (marksVisible) markGroup.addTo(map);
    map.on('zoomend', redrawMarks);   // labels only appear when zoomed in
    map.on('zoomend', redrawEasementLabels);
    map.on('zoomend', redrawDimensions);   // ticks and offsets are in pixels, so they are redrawn per zoom
    poleGroup = L.featureGroup();
    if (polesVisible) poleGroup.addTo(map);
    spanGroup      = L.featureGroup();
    spanLabelGroup = L.featureGroup();
    if (spansVisible) {
      spanGroup.addTo(map);
      if (map.getZoom() >= DETAIL_TEXT_ZOOM) spanLabelGroup.addTo(map);
    }
    flextinDistGroup = L.featureGroup(); // FlexTIN pole-to-pole distance labels
    if (flextinDistVisible) flextinDistGroup.addTo(map);
    routeDistGroup = L.featureGroup();    // pole-to-pole / route distance labels
    if (routeDistVisible) routeDistGroup.addTo(map);
    strandGroup = L.featureGroup();
    if (strandsVisible) strandGroup.addTo(map);
    foscGroup   = L.featureGroup();
    if (closuresVisible) foscGroup.addTo(map);
    popGroup    = L.featureGroup();
    if (popsVisible) popGroup.addTo(map);
    anchorGroup = L.featureGroup();
    if (anchorsVisible) anchorGroup.addTo(map);
    buildingAnchorGroup = L.featureGroup();
    if (buildingAnchorsVisible) buildingAnchorGroup.addTo(map);
    uguardGroup = L.featureGroup();
    if (uguardsVisible) uguardGroup.addTo(map);
    window._tinFoscGroup = foscGroup; // expose for debugging
    boundaryGroup = L.featureGroup(); // Salisbury town limits
    if (boundaryVisible) { boundaryGroup.addTo(map); loadBoundaryLayer(); }
    if (salisburyOnlyMode) loadSalisburyRing(); // pre-load PIP ring so filter is ready on first render
    exchGroup = L.featureGroup(); // Salisbury phone exchange
    if (exchVisible) { exchGroup.addTo(map); loadExchLayer(); }
    roadCovGroup        = L.featureGroup(); // road coverage (inside Salisbury)
    roadCovOutsideGroup = L.featureGroup(); // road coverage (outside Salisbury)
    if (roadCovVisible) { roadCovGroup.addTo(map); loadRoadCoverage(); }
    dropGroup  = L.featureGroup(); // drop spans
    onuGroup   = L.featureGroup(); // ONUs
    if (onuVisible) { dropGroup.addTo(map); onuGroup.addTo(map); }
    civicGroup = L.featureGroup(); // GeoNB civic addresses
    if (civicVisible) civicGroup.addTo(map);
    // Parcels sit in their own pane under the overlay pane (400), so every plant
    // line and marker draws over the property lines and wins the click.
    map.createPane('parcelPane').style.zIndex = 350;
    parcelGroup = L.featureGroup(); // SNB property parcels
    if (parcelVisible) parcelGroup.addTo(map);
    map.createPane('zoningPane').style.zIndex = 337;      // zoning tint under wetlands, parcels and plant
    map.createPane('communityPane').style.zIndex = 336;   // outlines under everything else
    { const lp = map.createPane('communityLabelPane'); lp.style.zIndex = 336; lp.style.pointerEvents = 'none'; }
    map.createPane('wetlandPane').style.zIndex = 340;   // under parcels and plant
    // Zoomed-out density hexagons sit under everything else. They used to share the
    // default overlay pane and, added later, covered the road-coverage lines (Tinuum
    // expansion, Bell, Rogers) with a 45–80% fill exactly when you want to read them.
    map.createPane('densityPane').style.zIndex = 330;
    // Pole dots (zoomed-out band, see PoleDot): canvas, under the plant lines, and
    // transparent to the mouse so it never swallows clicks meant for the parcel,
    // wetland or cable layers — the map-level click handler picks the nearest dot.
    { const pdp = map.createPane('poleDotPane'); pdp.style.zIndex = 395; pdp.style.pointerEvents = 'none'; }
    wetlandGroup = L.featureGroup(); // GeoNB wetlands
    if (wetlandVisible) wetlandGroup.addTo(map);
    zoningGroup = L.featureGroup(); // Salisbury zoning
    if (zoningVisible) zoningGroup.addTo(map);
    communityGroup = L.featureGroup(); // GeoNB community names and boundaries
    if (communityVisible) communityGroup.addTo(map);
    aldenGroup      = L.featureGroup(); // Alden One staging poles (import candidates)
    aldenVaultGroup = L.featureGroup(); // Alden One staging vaults
    if (aldenVisible) {
      aldenGroup.addTo(map);
      aldenVaultGroup.addTo(map);
      loadAldenPoles();
      loadAldenVaults();
      ensureAldenBulkControl();
    }
    densityGroup = L.featureGroup(); // asset-density hexagons (overview regime only)
    aldenDensityGroup = L.featureGroup(); // Alden-candidate density hexagons (overview + Alden toggle on)

    // Every layer group now exists, so a basemap change is safe to act on.
    basemapInitDone = true;
    // Loading straight into another basemap with a drop list still recorded means
    // B&W was left by closing the page rather than by picking a layer — put them
    // back now, or they stay off with nothing left to trigger the restore.
    if (activeBasemap !== 'B&W Plan') restoreMonoClutter();

    // ── Metric scale bar ──────────────────────────────────────────────────────
    const scaleCtrl = L.control.scale({ metric: true, imperial: false, position: 'bottomleft', maxWidth: 200 });
    if (scaleVisible) scaleCtrl.addTo(map);

    // ── Custom asset toolbar (no Geoman built-in toolbar at all) ─────────────
    // We use Geoman's drawing engine programmatically; its toolbar is not shown.
    const assetCtrl = L.control({ position: 'topleft' });
    assetCtrl.onAdd = function () {
      // Do NOT use 'leaflet-bar' — that class forces each <a> to 26×26 px squares.
      const div = L.DomUtil.create('div', 'leaflet-control fpm-asset-toolbar');
      const closureBtnIcon = toolbarIconHtml(foscIconForKind('closure', 'plan', STATUS_COLORS.plan, false, 1, false, 0, 0, 0));
      const popBtnIcon = toolbarIconHtml(popIcon(false, 1));
      const poleBtnIcon = toolbarIconHtml(poleIcon('', 1));
      const spanBtnIcon = spanToolbarIconHtml();
      const mstBtnIcon = toolbarIconHtml(mstIcon(STATUS_COLORS.plan, false, 1));
      const labelsBtnIcon = labelsToolbarIconHtml();
      const onuBtnIcon = toolbarIconHtml(onuIcon(STATUS_COLORS.plan, 1));
      const conduitBtnIcon = conduitToolbarIconHtml();
      const vaultBtnIcon = toolbarIconHtml(vaultIcon('plan', 1));
      const handholeBtnIcon = toolbarIconHtml(handholeIcon('plan', 1));
      const noteBtnIcon = toolbarIconHtml(genericNoteIcon(1));
      const anchorBtnIcon = toolbarIconHtml(anchorIcon('plan', 0.9, OWN_NAME));
      const buildingBtnIcon = toolbarIconHtml(symbolIcon('house', 'mast', '#198754', true, 20, 'building-anchor-icon'));
      const uguardBtnIcon = toolbarIconHtml(uguardIcon('plan', 0.9));
      div.innerHTML =
        // ── Icon rail: one button per panel, always visible ───────
        `<div class="tin-rail">` +
          `<button class="tin-rail-btn" data-panel="add"      title="Add assets (A)"><span>＋</span></button>` +
          `<button class="tin-rail-btn" data-panel="layers"   title="Layers (L)"><span>▤</span>` +
            `<span class="rail-dot" id="railDotLayers" style="display:none"></span></button>` +
          `<button class="tin-rail-btn" data-panel="coverage" title="Coverage &amp; competition (C)"><span>◼</span></button>` +
          `<button class="tin-rail-btn" id="btnCmdPalette"    title="Search commands (Ctrl+K)"><span>🔍</span></button>` +
          `<button class="tin-rail-btn" id="btnFilterPoles" title="Filter assets by work order, permit and field values (F)"><span class="rail-ico"><svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5zm1 .5v1.308l4.372 4.858A.5.5 0 0 1 7 8.5v5.306l2-.666V8.5a.5.5 0 0 1 .128-.334L13.5 3.308V2z"/></svg></span><span id="poleFilterBadge" class="pole-filter-badge" style="display:none"></span></button>` +
          // Measure: on the rail, one click away (was in Tools). Blue while measuring
          // or while a finished measurement is still on the map.
          `<button class="tin-rail-btn" id="btnMeasure"       title="Measure distance (M): click points, double-click to finish, Esc to clear"><span class="rail-ico"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><g transform="rotate(-45 10 10)"><rect x="1" y="6.5" width="18" height="7" rx="1.2" fill="#f59e0b" stroke="#b45309" stroke-width="1"/><path d="M4 6.5v3M7 6.5v2M10 6.5v3M13 6.5v2M16 6.5v3" stroke="#78350f" stroke-width="1"/></g></svg></span></button>` +
          `<button class="tin-rail-btn" id="btnDimension"     title="Dimension (D): click two assets or points to save a dimension on the plan; Along route follows strand and conduit"><span class="rail-ico"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4v12M17 4v12M3 10h14M3 10l3-2M3 10l3 2M17 10l-3-2M17 10l-3 2" fill="none" stroke="#1f2937" stroke-width="1.4"/></svg></span></button>` +
          `<button class="tin-rail-btn" id="btnPickCoord"     title="Pick coordinates: click the map to copy decimal lat/lon + zoom to the clipboard (Esc to stop)"><span>📍</span></button>` +
          // Box-select road coverage segments; on the rail so it's one click, not
          // two panels deep. Shown only while Road Coverage is on (toggleRoadCoverage).
          `<button class="tin-rail-btn" id="btnRoadCovBoxSel" title="Box-select road segments: drag a rectangle, then pick a service to add or remove" style="display:none"><span class="rail-ico"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><rect x="2" y="2" width="13" height="11" rx="1" fill="#dbeafe" stroke="#2563eb" stroke-width="1.6" stroke-dasharray="2.6 1.8"/><path d="M11 9l7 3.2-3 1 2.2 3.3-1.6 1-2.1-3.3-2.2 2.2z" fill="#1e3a8a" stroke="#fff" stroke-width=".8" stroke-linejoin="round"/></svg></span></button>` +
        `</div>` +
        // ── Flyout: holds one panel at a time, opens beside the rail
        `<div class="tin-flyout" id="tinFlyout" style="display:none">` +
        `<div class="tin-flyout-hdr"><span id="tinFlyoutTitle"></span>` +
          `<button id="tinFlyoutClose" title="Close (Esc)">✕</button></div>` +
        `<div class="tin-flyout-body">` +
        // ── ADD ──────────────────────────────────────────────────
        `<div class="tin-panel" id="toolbarSectionAdd">` +
          `<button id="btnDrawClosure"   title="Place a new closure on the map"><span class="asset-btn-icon">${closureBtnIcon}</span><span>+ Closure</span></button>` +
          `<button id="btnPlacePop"      title="Place a POP (point of presence) on the map"><span class="asset-btn-icon">${popBtnIcon}</span><span>+ POP</span></button>` +
          `<button id="btnDrawPole"      title="Place a new pole on the map"><span class="asset-btn-icon">${poleBtnIcon}</span><span>+ Pole</span></button>` +
          `<button id="btnDrawSpan"      title="Draw a new cable span route"><span class="asset-btn-icon">${spanBtnIcon}</span><span>+ Span</span></button>` +
          `<button id="btnDrawFlextin"   title="Auto-chain optitaps along poles">&#x26A1; + FlexTIN</button>` +
          `<button id="btnAddMst"        title="Add a Multi-Service Terminal (contains PLC splitter)"><span class="asset-btn-icon">${mstBtnIcon}</span><span>+ MST</span></button>` +
          `<button id="btnAddAnchor"     title="Add a guy wire anchor to an existing pole"><span class="asset-btn-icon">${anchorBtnIcon}</span><span>+ Anchor</span></button>` +
          `<button id="btnAddUguard"     title="Add a u-guard conduit protector to an existing pole"><span class="asset-btn-icon">${uguardBtnIcon}</span><span>+ U-Guard</span></button>` +
          `<button id="btnAddStrand"     title="Add messenger strand between two poles"><span class="asset-btn-icon">〰</span><span>+ Strand / OL</span></button>` +
          `<button id="btnAddBuildingAnchor" title="Place a building anchor (change-of-direction strand support)"><span class="asset-btn-icon">${buildingBtnIcon}</span><span>+ Building</span></button>` +
          `<button id="btnDrawConduit"   title="Draw a buried conduit run"><span class="asset-btn-icon">${conduitBtnIcon}</span><span>+ Conduit</span></button>` +
          `<button id="btnPlaceVault"    title="Place a vault (underground chamber)"><span class="asset-btn-icon">${vaultBtnIcon}</span><span>+ Vault</span></button>` +
          `<button id="btnPlaceHandhole" title="Place a handhole (small access box)"><span class="asset-btn-icon">${handholeBtnIcon}</span><span>+ Handhole</span></button>` +
          `<button id="btnDrawSurvey"    title="Draw a survey line (draft or final property line from a plan)"><span class="asset-btn-icon">╱╱</span><span>+ Survey line</span></button>` +
          `<button id="btnPlaceWifi"     title="Place a WiFi access point (Salisbury municipal WiFi), then set its model and the direction it points"><span class="asset-btn-icon">&#x1F4F6;</span><span>+ WiFi AP</span></button>` +
          `<button id="btnDrawEasement"  title="Draw a utility easement (outline it from a plan, over the parcel layer)"><span class="asset-btn-icon">▨</span><span>+ Easement</span></button>` +
          `<button id="btnPlaceNote"     title="Place a map note"><span class="asset-btn-icon">${noteBtnIcon}</span><span>+ Note</span></button>` +
        `</div>` +
        // ── LAYERS ────────────────────────────────────────────────
        // Two compact columns rather than one long stack, with a type-to-filter box
        // and bulk on/off — a layer list, not a pile of buttons.
        `<div class="tin-panel" id="toolbarSectionLayers">` +
          `<div class="tin-panel-controls">` +
            `<input id="layerFilterInput" class="tin-panel-search" type="text" placeholder="Filter layers…" autocomplete="off">` +
            `<div class="tin-panel-bulk">` +
              `<button id="btnLayersAllOn"  title="Show every layer">All on</button>` +
              `<button id="btnLayersAllOff" title="Hide every layer">All off</button>` +
            `</div>` +
          `</div>` +
          `<div class="tin-layer-sub">Points</div>` +
          `<div class="tin-layer-grid">` +
            `<button id="btnTogglePoles"     title="Toggle poles"><span class="asset-btn-icon">${poleBtnIcon}</span><span>Poles</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleClosures"  title="Toggle closures"><span class="asset-btn-icon">${closureBtnIcon}</span><span>Closures</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleFlextinClosures" title="Toggle optitaps (FlexTIN taps and MSTs) and their numbers — off leaves the splice closures, slack loops and cabinets"><span class="asset-btn-icon">${mstBtnIcon}</span><span>Optitaps</span><span class="tgl-dot"></span></button>` +
            `<button id="btnTogglePops"      title="Toggle POPs (points of presence)"><span class="asset-btn-icon">${popBtnIcon}</span><span>POPs</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleOnus"      title="Toggle customer ONU locations"><span class="asset-btn-icon">${onuBtnIcon}</span><span>ONUs</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleAnchors"   title="Toggle guy wire anchors"><span class="asset-btn-icon">${anchorBtnIcon}</span><span>Anchors</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleBuildingAnchors" title="Toggle building anchors"><span class="asset-btn-icon">🪝</span><span>Buildings</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleUguards"   title="Toggle u-guard conduit protectors"><span class="asset-btn-icon">${uguardBtnIcon}</span><span>U-Guards</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleVaults"    title="Toggle vaults"><span class="asset-btn-icon">${vaultBtnIcon}</span><span>Vaults</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleHandholes" title="Toggle handholes"><span class="asset-btn-icon">${handholeBtnIcon}</span><span>Handholes</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleNotes"     title="Toggle map notes"><span class="asset-btn-icon">${noteBtnIcon}</span><span>Notes</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleDoneNotes" title="Show finished notes (tree trimming done, etc.), faded"><span class="asset-btn-icon">${noteBtnIcon}</span><span>Done notes</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleAbandoned" title="Show plant that belongs only to abandoned work orders (grey, dashed). Restore the work order on the Permits page to bring it back for real."><span class="asset-btn-icon">${noteBtnIcon}</span><span>Abandoned</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleDims"      title="Toggle saved dimensions"><span class="asset-btn-icon">↔</span><span>Dimensions</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleMarks"     title="Cable sheath marks (meter marks and cable serial from the as-builts); labels show when zoomed in close"><span class="asset-btn-icon">#</span><span>Sheath marks</span><span class="tgl-dot"></span></button>` +
          `</div>` +
          `<div class="tin-layer-sub">Lines &amp; labels</div>` +
          `<div class="tin-layer-grid">` +
            `<button id="btnToggleSpans"     title="Toggle cable spans"><span class="asset-btn-icon">${spanBtnIcon}</span><span>Spans</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleStrands"   title="Toggle messenger strand"><span class="asset-btn-icon">〰</span><span>Strands</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleConduits"  title="Toggle buried conduit runs"><span class="asset-btn-icon">${conduitBtnIcon}</span><span>Conduits</span><span class="tgl-dot"></span></button>` +
            `<button id="btnCycleLabels" data-tin-cycle="1"><span class="asset-btn-icon">${labelsBtnIcon}</span><span id="btnCycleLabelsText">Labels: All</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleDeclutter" data-tin-bulk-skip="1" title="Declutter labels — where two labels land on the same pixels, keep the more important one and hide the other. Zoom in to get them back."><span class="asset-btn-icon" style="font-size:11px;font-weight:700">&#x21F2;</span><span>Declutter</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleFlextinDist" title="Toggle FlexTIN pole-to-pole distances (metres)"><span class="asset-btn-icon" style="font-size:10px;font-weight:700">⚡m</span><span>FlexTIN m</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleRouteDist" title="Toggle route distances (metres) — pole-to-pole on messenger strand, whole-route on cable spans no strand carries. FlexTIN tails have their own toggle."><span class="asset-btn-icon" style="font-size:10px;font-weight:700">↔m</span><span>Distances</span><span class="tgl-dot"></span></button>` +
            `<button id="btnToggleCivics"   title="Toggle GeoNB civic addresses (click a dot for the address and PID)"><span class="asset-btn-icon" style="font-size:10px;font-weight:700">#</span><span>Civic #s</span><span class="tgl-dot"></span></button>` +
          `</div>` +
          `<div class="tin-layer-sub">Zoomed out</div>` +
          `<div class="tin-layer-grid">` +
            `<button id="btnToggleDensity"   title="Toggle the asset-density hexagons drawn below zoom ${DENSITY_ZOOM} — turn off to read the coverage layers on a zoomed-out map"><span class="asset-btn-icon">⬢</span><span>Density Hexes</span><span class="tgl-dot"></span></button>` +
          `</div>` +
          // Map furniture (the asset filter lives on the rail under search): not layers, so "All on/off" skips them.
          `<div class="tin-layer-sub">Map</div>` +
          `<div class="tin-layer-grid">` +
            `<button id="btnToggleScale" data-tin-bulk-skip="1" title="Toggle metric scale bar"><span class="asset-btn-icon">↔</span><span>Scale bar</span><span class="tgl-dot"></span></button>` +
          `</div>` +
        `</div>` +
        // ── COVERAGE & COMPETITORS ────────────────────────────────
        // The area-context toggles (Salisbury / town limits / exchange) live here
        // rather than in Layers — they scope the map, they aren't asset layers.
        `<div class="tin-panel" id="toolbarSectionCoverage">` +
          `<button id="btnSalisburyOnly"  title="Hide all assets outside Salisbury town limits"><span class="asset-btn-icon">◎</span><span>Salisbury Only</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleServiceArea" title="Toggle ${OWN_NAME} service area (75 m coverage polygons — active &amp; coming soon)"><span class="asset-btn-icon">◼</span><span>Service Area</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleRoadCov"  title="Toggle road coverage (Fiber / Hybrid-Coax / None)"><span class="asset-btn-icon">🛣</span><span>Road Coverage</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleParcels"  title="Toggle SNB property parcels (zoom 15+; click a parcel for PID, area and addresses)"><span class="asset-btn-icon" style="font-size:11px">▱</span><span>Parcels</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleWifi"    title="Toggle WiFi access points: model, aim and estimated coverage cone (red)"><span class="asset-btn-icon">&#x1F4F6;</span><span>WiFi</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleEasements" title="Toggle utility easements (polygons from registered plans)"><span class="asset-btn-icon">▨</span><span>Easements</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleCommunities" title="GeoNB community names and boundaries (NB 9-1-1 communities, from zoom 8). Names appear when a community is wide enough on screen."><span class="asset-btn-icon">Aa</span><span>Communities</span><span class="tgl-dot"></span></button>` +
          `<button id="btnToggleWetlands" title="GeoNB wetlands (zoom 13+) and the 30 m band where work needs a WAWA permit (zoom 15+). Purple outline = Provincially Significant. Click a spot to check it."><span class="asset-btn-icon">≈</span><span>Wetlands</span><span class="tgl-dot"></span></button>` +
          `<button id="btnOutsideRoads"    class="tgl-off" title="Also show UNMARKED roads outside Salisbury town limits, to mark new ones. Outside roads that already carry a service always show." style="display:none"><span class="asset-btn-icon">⊕</span><span>Outside Salisbury <small class="text-muted">(unmarked)</small></span><span class="tgl-dot"></span></button>` +
          `<button id="btnRoadTypeFilter"  title="Filter by service/provider" style="display:none"><span class="asset-btn-icon">⚙</span><span>Filter Services</span><span id="roadTypeFilterBadge" class="pole-filter-badge" style="display:none"></span></button>` +
        `</div>` +
        `</div></div>`;   // .tin-flyout-body, .tin-flyout
      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.disableScrollPropagation(div);
      return div;
    };
    assetCtrl.addTo(map);

    // ── Pole filter panel (second topleft control, stacks below toolbar) ─────
    const filterCtrl = L.control({ position: 'topleft' });
    filterCtrl.onAdd = function () {
      const div = L.DomUtil.create('div', 'leaflet-control fpm-filter-panel');
      div.id = 'poleFilterPanel';
      div.style.display = 'none';
      div.innerHTML =
        `<div class="filter-panel-header">` +
          `<strong>Filter Assets</strong>` +
          `<button id="poleFilterClose" title="Close">✕</button>` +
        `</div>` +
        `<div class="filter-panel-fields">` +
          `<label>Tinuum Permit<input id="pfWorkOrder" data-field="work_order" placeholder="" autocomplete="off" list="pfWorkOrderList"><datalist id="pfWorkOrderList"></datalist></label>` +
          `<label>Pole UID<input id="pfUid" data-field="uid" placeholder="" autocomplete="off"></label>` +
          `<label>Owner<input id="pfOwner" data-field="owner" placeholder="Bell Aliant, NB Power…" autocomplete="off"></label>` +
          `<label>Type<input id="pfType" data-field="type" placeholder="wood, concrete…" autocomplete="off"></label>` +
          `<label>NBP #<input id="pfNbp" data-field="nbp_num" placeholder="" autocomplete="off"></label>` +
          `<label>Bell #<input id="pfTelco" data-field="telco_num" placeholder="" autocomplete="off"></label>` +
          `<label>Tag #<input id="pfTags" data-field="tags_num" placeholder="" autocomplete="off"></label>` +
          `<label class="filter-panel-toggle"><input type="checkbox" id="pfHasWO"> Fade poles without Tinuum Permit</label>` +
          `<label class="filter-panel-toggle"><input type="checkbox" id="pfHasBellPermit"> Fade poles without Bell Permit</label>` +
        `</div>` +
        `<div class="filter-panel-fields" id="pfAddBack">` +
          `<div class="small text-muted" title="With a Tinuum Permit or Bell Permit filter on, everything that is not tagged to it is hidden. Tick a layer to bring it back (the layer must also be switched on).">Permit filter hides everything else. Add back:</div>` +
          ADDBACK_LAYERS.map(l => `<label class="filter-panel-toggle"><input type="checkbox" data-addback="${l.key}"> ${l.label}</label>`).join('') +
        `</div>` +
        `<div class="filter-panel-actions">` +
          `<button id="poleFilterClear">Clear</button>` +
        `</div>` +
        `<div id="poleFilterStatus" class="filter-status"></div>`;
      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.disableScrollPropagation(div);
      return div;
    };
    filterCtrl.addTo(map);
    // The panel's inputs exist now, so a saved filter can go back in. Markers do
    // not exist yet — the post-render hooks apply it once they do.
    restorePoleFilterState();

    // ── Road type filter panel ────────────────────────────────────────────────
    const rcTypeFilterCtrl = L.control({ position: 'topleft' });
    rcTypeFilterCtrl.onAdd = function () {
      const div = L.DomUtil.create('div', 'leaflet-control fpm-filter-panel');
      div.id = 'roadTypeFilterPanel';
      div.style.display = 'none';
      const swatchStyle = 'display:inline-block;width:20px;height:4px;border-radius:2px;vertical-align:middle;margin-right:5px;flex-shrink:0';
      const checks = RC_COMBOS.map(function (c) {
        const [prov, tech, provLabel, techLabel, col] = c;
        const key   = prov + '|' + tech;
        const label = provLabel + (techLabel ? ' – ' + techLabel : '');
        return `<label class="filter-panel-toggle" style="font-size:.8rem;gap:4px;align-items:center">` +
          `<input type="checkbox" class="rc-type-chk" data-combo="${key}" checked>` +
          `<span style="${swatchStyle};background:${col}"></span>${label}` +
          `</label>`;
      }).join('') +
      `<label class="filter-panel-toggle" style="font-size:.8rem;gap:4px;align-items:center">` +
        `<input type="checkbox" class="rc-type-chk" data-combo="unassigned" checked>` +
        `<span style="${swatchStyle};border-bottom:3px dashed #94a3b8"></span>Unassigned` +
      `</label>`;
      div.innerHTML =
        `<div class="filter-panel-header" style="margin-bottom:4px">` +
          `<strong style="font-size:.82rem">Filter Services</strong>` +
          `<button id="roadTypeFilterClose" title="Close">✕</button>` +
        `</div>` +
        `<div style="display:flex;flex-direction:column;gap:2px">${checks}</div>` +
        `<div style="margin-top:6px;display:flex;gap:6px">` +
          `<button id="rcTypeAll" style="font-size:.72rem;padding:1px 6px">All</button>` +
          `<button id="rcTypeNone" style="font-size:.72rem;padding:1px 6px">None</button>` +
        `</div>`;
      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.disableScrollPropagation(div);
      return div;
    };
    rcTypeFilterCtrl.addTo(map);

    // ── Icon rail → flyout panels ─────────────────────────────────────────────
    document.querySelectorAll('.tin-rail-btn[data-panel]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        // Clicking the open panel's own icon closes it — the rail doubles as the
        // close affordance, so the map is never more than one click away.
        setToolbarPanel(openToolbarPanel === btn.dataset.panel ? null : btn.dataset.panel);
      });
    });
    document.getElementById('tinFlyoutClose')
      ?.addEventListener('click', function () { setToolbarPanel(null); });

    // Picking a tool from a panel closes the flyout — you chose it, and the next
    // thing you want is the map, not the panel. Layer toggles are different: you
    // usually flip several in a row, so the panel stays put and fades out on its
    // own once you stop clicking (any click inside restarts the countdown).
    // Delegated on the flyout so it runs after the button's own handler.
    document.getElementById('tinFlyout')
      ?.addEventListener('click', function (e) {
        if (bulkLayerToggle) return;                       // All on/off drives clicks itself
        const btn = e.target.closest('.tin-panel button');
        if (!btn) { if (flyoutCloseTimer) armFlyoutAutoClose(); return; }
        if (btn.closest('.tin-panel-controls') || btn.querySelector('.tgl-dot')) {
          armFlyoutAutoClose();                            // toggles, search, All on/off
          return;
        }
        setToolbarPanel(null);
      });
    // Every rail button is a toggle: pressing it again puts the map back.
    document.getElementById('btnCmdPalette')
      ?.addEventListener('click', function () {
        cmdPaletteOpen() ? closeCmdPalette() : openCmdPalette();
      });

    // ── Active-filter chip ────────────────────────────────────────────────────
    document.getElementById('mapFilterChipOpen')
      ?.addEventListener('click', function () {
        // Take the user to the control that owns the filter, wherever it lives.
        setToolbarPanel('layers');
        if (!poleFilterOpen) togglePoleFilterPanel();
      });
    document.getElementById('mapFilterChipClear')
      ?.addEventListener('click', clearAllMapFilters);

    // ── Plan framing bar ──────────────────────────────────────────────────────
    document.getElementById('planFrameOrient')
      ?.addEventListener('click', togglePlanOrientation);
    const paperSel = document.getElementById('planFramePaper');
    if (paperSel && window.FPM_SHEET) {
      paperSel.innerHTML = Object.keys(window.FPM_SHEET.papers).map(n => {
        const d = window.FPM_SHEET.papers[n];
        return `<option value="${n}">${n} (${d[0]} × ${d[1]} mm)</option>`;
      }).join('');
      paperSel.value = window.FPM_SHEET.paper();
      paperSel.addEventListener('change', function () { setPlanPaper(this.value); });
    }
    document.getElementById('planFrameBasemap')
      ?.addEventListener('click', togglePlanBasemapRes);
    syncPlanBasemapBtn();
    document.getElementById('planFrameZoomOut')
      ?.addEventListener('click', function () { if (planSheet) setPlanSheetZoom(planSheet.zoom - 1); });
    document.getElementById('planFrameZoomIn')
      ?.addEventListener('click', function () { if (planSheet) setPlanSheetZoom(planSheet.zoom + 1); });

    document.getElementById('planFrameSavedBtn')
      ?.addEventListener('click', function () { loadSavedPlans(false); });
    document.getElementById('planFrameSaveBtn')
      ?.addEventListener('click', function () {
        const err = document.getElementById('planSaveError');
        if (err) showErr(err, '');
        const inp = document.getElementById('planSaveTitle');
        if (inp && !inp.value) inp.value = 'Plan — ' + new Date().toLocaleDateString('en-CA');
        new bootstrap.Modal(document.getElementById('planSaveModal')).show();
      });
    document.getElementById('planSaveBtn')
      ?.addEventListener('click', savePlanFraming);
    document.getElementById('planSaveModal')
      ?.addEventListener('show.bs.modal', loadPlanSaveTargets);
    document.getElementById('planFramePrintBtn')
      ?.addEventListener('click', async function () {
        // The display sits further out than the sheet so the outline is visible,
        // so move the map onto the sheet's own centre/zoom before printing — that
        // is the view fpmPlanPrint() captures. Leave framing first so the
        // outline and dim mask are gone from the page.
        const sheet = planSheet;
        const fit = planFit();
        exitPlanFraming(false);
        // Leaving framing would drop a wide sheet back to density hexagons, and a
        // sheet is never that — keep the assets drawn until the dialog closes.
        setPlanDetailLock(true);
        try {
          if (sheet) {
            map.setView(sheet.center, sheet.zoom, { animate: false });
            await new Promise(r => setTimeout(r, 120));   // let the pane settle
          }
          if (window.fpmSetPlanFit) window.fpmSetPlanFit(fit);
          if (window.fpmSetPlanBasemapRes) window.fpmSetPlanBasemapRes(planBasemapRes());
          if (window.fpmPlanPrint) await window.fpmPlanPrint();
        } finally {
          if (window.fpmSetPlanFit) window.fpmSetPlanFit(1);
          setPlanDetailLock(false);
        }
      });
    // Server-rendered PDF: same sheet, but Chrome's print dialog — and the Scale
    // setting it remembers, which the page cannot read or override — is not in
    // the loop, so the drawing is always at the 1:N the title block claims.
    document.getElementById('planFramePdfBtn')
      ?.addEventListener('click', function () {
        if (planSheet) downloadPlanPdf(this, planSheet.center, planSheet.zoom, { fit: planFit().toFixed(4) });
      });
    // Plan view (?plan=N): the same renderer, at the plan's centre; the plan id
    // goes along so the sheet gets the record's title block.
    document.getElementById('planViewPdfBtn')
      ?.addEventListener('click', function () {
        const p = window._planTitleData && window._planTitleData.plan;
        if (!p) return;
        // The plan's paper/fit/basemap are already on the page (enterPlanView →
        // applyPlanRenderMeta); re-apply the paper in case the user changed it.
        const g = applyPlanRenderMeta(p, { filter: false });
        downloadPlanPdf(this, L.latLng(p.center_lat, p.center_lon), clampDetailZoom(p.zoom),
                        Object.assign({ fit: g.fit.toFixed(4) }, p.plan_id ? { plan: p.plan_id } : {}));
      });

    // Server-rendered PDF of one sheet: same centre/zoom/paper/layers as the
    // screen, without Chrome's print dialog — and the Scale setting it remembers,
    // which the page can neither read nor override — so the drawing is always at
    // the 1:N the title block claims. btn is disabled and relabelled while it runs.
    async function downloadPlanPdf(btn, center, zoom, extra) {
        if (btn.disabled) return;
        const label = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '&#x231B; Rendering…';
        // What is on the map now — in plan view that is the plan's own basemap,
        // which is deliberately not written to localStorage. The name is the one
        // the renderer has to click in its layers control, so it must be exact.
        const KNOWN = ['Street', 'Street (Grey)', 'Satellite (ESRI)', 'Satellite (Google)', 'Satellite (Google) Grey', 'B&W Plan'];
        let basemap = window.fpmActiveBasemap ? window.fpmActiveBasemap() : null;
        if (!KNOWN.includes(basemap)) { try { basemap = localStorage.getItem(MAP_BASEMAP_KEY); } catch (_) {} }
        if (!KNOWN.includes(basemap)) basemap = null;   // never send a stale name
        const qs = new URLSearchParams({
          lat: center.lat.toFixed(6),
          lon: center.lng.toFixed(6),
          zoom: String(zoom),
          orientation: window.FPM_SHEET.landscape() ? 'landscape' : 'portrait',
          paper: window.FPM_SHEET.paper(),
          basemap_res: planBasemapRes(),
        });
        if (basemap) {
          qs.set('basemap', basemap);
          if (window.fpmBasemapFade) qs.set('fade', String(window.fpmBasemapFade()));   // the Fade slider, as on screen
        }
        Object.keys(extra || {}).forEach(k => qs.set(k, String(extra[k])));
        // The sheet must show what the screen shows: the same layers and label
        // mode, and with a work-order filter on, one job rather than the whole
        // network. See mapFilterState() / applyMapFilterState().
        qs.set('filter', JSON.stringify(mapFilterState()));
        try {
          const resp = await fetch('/api/plan_pdf.php?' + qs.toString());
          if (!resp.ok) {
            let msg = 'HTTP ' + resp.status;
            try { msg = (await resp.json()).error || msg; } catch (_) {}
            throw new Error(msg);
          }
          const blob = await resp.blob();
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = (resp.headers.get('Content-Disposition') || '')
            .match(/filename="([^"]+)"/)?.[1] || 'plan.pdf';
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 10000);
        } catch (e) {
          toast('PDF render failed: ' + (e.message || e), 'danger');
        } finally {
          btn.disabled = false;
          btn.innerHTML = label;
        }
    }

    document.getElementById('planFrameCancelBtn')
      ?.addEventListener('click', exitPlanFraming);
    restoreToolbarPanel();

    // ── Keyboard: command palette + panel accelerators ────────────────────────
    // Ignored while typing, and while a Bootstrap modal is up.
    document.addEventListener('keydown', function (e) {
      if (document.querySelector('.modal.show')) return;
      const t = e.target;
      const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' ||
                           t.tagName === 'SELECT' || t.isContentEditable);

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        cmdPaletteOpen() ? closeCmdPalette() : openCmdPalette();
        return;
      }
      if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key === '/') { e.preventDefault(); openCmdPalette(); return; }

      const panelKeys = { a: 'add', l: 'layers', c: 'coverage' };
      const panel = panelKeys[e.key.toLowerCase()];
      if (panel) {
        e.preventDefault();
        setToolbarPanel(openToolbarPanel === panel ? null : panel);
        return;
      }
      if (e.key.toLowerCase() === 'm') { e.preventDefault(); document.getElementById('btnMeasure')?.click(); }
      if (e.key.toLowerCase() === 'd') { e.preventDefault(); document.getElementById('btnDimension')?.click(); }
      if (e.key.toLowerCase() === 'f') { e.preventDefault(); document.getElementById('btnFilterPoles')?.click(); }
    });

    // ── Layers panel: type-to-filter + bulk on/off ────────────────────────────
    document.getElementById('layerFilterInput')
      ?.addEventListener('input', function () { filterLayerList(this.value); });
    // Bulk toggles go through each button's own click handler so the layer group,
    // the tgl-dot and localStorage all stay in step — no duplicated side effects.
    document.getElementById('btnLayersAllOn')
      ?.addEventListener('click', function () { setAllLayers(true); });
    document.getElementById('btnLayersAllOff')
      ?.addEventListener('click', function () { setAllLayers(false); });
    populateMicroductTypeSelect();

    document.getElementById('btnDrawClosure')
      .addEventListener('click', startDrawClosure);
    document.getElementById('btnDrawPole')
      .addEventListener('click', startDrawPole);
    document.getElementById('btnDrawSpan')
      .addEventListener('click', startDrawSpan);
    document.getElementById('btnDrawFlextin')
      .addEventListener('click', startFlextin);
    document.getElementById('btnAddMst')
      ?.addEventListener('click', startAddMst);
    document.getElementById('btnCycleLabels')
      ?.addEventListener('click', cycleLabelState);
    document.getElementById('btnToggleOnus')
      ?.addEventListener('click', toggleOnus);
    document.getElementById('btnToggleAlden')
      ?.addEventListener('click', toggleAlden);
    document.getElementById('btnTogglePoles')
      ?.addEventListener('click', togglePoles);
    document.getElementById('btnToggleSpans')
      ?.addEventListener('click', toggleSpans);
    document.getElementById('btnToggleClosures')
      ?.addEventListener('click', toggleClosures);
    document.getElementById('btnToggleFlextinClosures')
      ?.addEventListener('click', toggleFlextinClosures);
    document.getElementById('btnTogglePops')
      ?.addEventListener('click', togglePops);
    document.getElementById('btnPlacePop')
      ?.addEventListener('click', startPlacePop);
    document.getElementById('savePopBtn')
      ?.addEventListener('click', savePop);
    document.getElementById('popExisting')
      ?.addEventListener('change', syncPopModalMode);
    document.getElementById('btnToggleAnchors')
      ?.addEventListener('click', toggleAnchors);
    document.getElementById('btnAddAnchor')
      ?.addEventListener('click', startAddAnchor);
    document.getElementById('btnToggleUguards')
      ?.addEventListener('click', toggleUguards);
    document.getElementById('btnAddUguard')
      ?.addEventListener('click', startAddUguard);
    document.getElementById('btnDrawSurvey')?.addEventListener('click', () => startDrawSurvey(0));
    document.getElementById('btnDrawEasement')?.addEventListener('click', () => startDrawEasement(0));
    document.getElementById('btnDrawConduit')
      ?.addEventListener('click', startDrawConduit);
    document.getElementById('btnPlaceVault')
      ?.addEventListener('click', function () { startPlaceVault('vault'); });
    document.getElementById('btnPlaceHandhole')
      ?.addEventListener('click', function () { startPlaceVault('handhole'); });
    document.getElementById('btnToggleConduits')
      ?.addEventListener('click', toggleConduits);
    document.getElementById('btnToggleVaults')
      ?.addEventListener('click', toggleVaults);
    document.getElementById('btnToggleHandholes')
      ?.addEventListener('click', toggleHandholes);
    document.getElementById('btnPlaceNote')
      ?.addEventListener('click', startPlaceNote);
    document.getElementById('btnToggleNotes')
      ?.addEventListener('click', toggleNotes);
    document.getElementById('btnToggleDoneNotes')
      ?.addEventListener('click', toggleDoneNotes);
    document.getElementById('btnToggleAbandoned')
      ?.addEventListener('click', toggleAbandoned);
    if (!abandonedVisible) document.getElementById('btnToggleAbandoned')?.classList.add('tgl-off');
    if (!doneNotesVisible) document.getElementById('btnToggleDoneNotes')?.classList.add('tgl-off');
    document.getElementById('btnToggleDims')?.addEventListener('click', toggleDims);
    document.getElementById('btnToggleMarks')?.addEventListener('click', toggleMarks);
    document.getElementById('btnToggleEasements')?.addEventListener('click', toggleEasements);
    document.getElementById('btnToggleWifi')?.addEventListener('click', toggleWifi);
    document.getElementById('btnPlaceWifi')?.addEventListener('click', startPlaceWifi);
    document.getElementById('btnDimension')?.addEventListener('click', toggleDimTool);
    document.getElementById('btnAddStrand')
      ?.addEventListener('click', startAddStrand);
    document.getElementById('btnAddBuildingAnchor')
      ?.addEventListener('click', startAddBuildingAnchor);
    document.getElementById('btnToggleBuildingAnchors')
      ?.addEventListener('click', toggleBuildingAnchors);
    document.getElementById('saveBuildingAnchorBtn')
      ?.addEventListener('click', saveBuildingAnchor);
    document.getElementById('btnToggleStrands')
      ?.addEventListener('click', toggleStrands);
    document.getElementById('btnToggleDeclutter')
      ?.addEventListener('click', toggleDeclutter);
    document.getElementById('btnToggleFlextinDist')
      ?.addEventListener('click', toggleFlextinDist);
    document.getElementById('btnToggleRouteDist')
      ?.addEventListener('click', toggleRouteDist);
    document.getElementById('btnToggleCivics')
      ?.addEventListener('click', toggleCivics);
    document.getElementById('btnToggleParcels')
      ?.addEventListener('click', toggleParcels);
    document.getElementById('btnToggleWetlands')
      ?.addEventListener('click', toggleWetlands);
    document.getElementById('btnToggleZoning')
      ?.addEventListener('click', toggleZoning);
    document.getElementById('btnToggleCommunities')
      ?.addEventListener('click', toggleCommunities);
    document.getElementById('btnToggleDensity')
      ?.addEventListener('click', toggleDensity);
    document.getElementById('btnMeasure')
      ?.addEventListener('click', toggleMeasure);
    document.getElementById('btnPickCoord')
      ?.addEventListener('click', togglePickCoord);
    document.getElementById('btnFilterPoles')
      ?.addEventListener('click', togglePoleFilterPanel);
    document.getElementById('btnToggleScale')
      ?.addEventListener('click', function () {
        scaleVisible = !scaleVisible;
        if (scaleVisible) scaleCtrl.addTo(map); else scaleCtrl.remove();
        document.getElementById('btnToggleScale')?.classList.toggle('tgl-off', !scaleVisible);
        saveLayerState();
      });
    // Filter panel controls (wired after panel is in DOM)
    document.getElementById('poleFilterClose')
      ?.addEventListener('click', closePoleFilterPanel);
    document.getElementById('poleFilterClear')
      ?.addEventListener('click', clearPoleFilter);
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        clearTimeout(poleFilterTimer);
        poleFilterTimer = setTimeout(applyPoleFilter, 200);
      });
    });
    document.getElementById('pfHasWO')
      ?.addEventListener('change', applyPoleFilter);
    document.getElementById('pfHasBellPermit')
      ?.addEventListener('change', applyPoleFilter);
    document.querySelectorAll('#pfAddBack input[data-addback]').forEach(function (inp) {
      inp.addEventListener('change', applyPoleFilter);
    });
    // Enter = "that's what I wanted": apply and get the panel off the map. The
    // filter stays active — closePoleFilterPanel keeps it, and the chip says so.
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      inp.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(poleFilterTimer);
        applyPoleFilter();
        closePoleFilterPanel();
      });
    });

    // Apply initial button states from restored localStorage
    if (!labelsVisible)    map.getContainer().classList.add('fosc-labels-hidden');
    if (minLabelsMode)     map.getContainer().classList.add('min-labels');
    syncLabelCycleButton();
    if (!onuVisible)       document.getElementById('btnToggleOnus')      ?.classList.add('tgl-off');
    if (!aldenVisible)     document.getElementById('btnToggleAlden')     ?.classList.add('tgl-off');
    if (!polesVisible)     document.getElementById('btnTogglePoles')     ?.classList.add('tgl-off');
    if (!spansVisible)     document.getElementById('btnToggleSpans')     ?.classList.add('tgl-off');
    if (!closuresVisible)  document.getElementById('btnToggleClosures')  ?.classList.add('tgl-off');
    if (!flextinClosuresVisible) document.getElementById('btnToggleFlextinClosures')?.classList.add('tgl-off');
    if (!popsVisible)      document.getElementById('btnTogglePops')      ?.classList.add('tgl-off');
    if (!anchorsVisible)   document.getElementById('btnToggleAnchors')   ?.classList.add('tgl-off');
    if (!buildingAnchorsVisible) document.getElementById('btnToggleBuildingAnchors')?.classList.add('tgl-off');
    if (!uguardsVisible)   document.getElementById('btnToggleUguards')   ?.classList.add('tgl-off');
    if (!conduitsVisible)  document.getElementById('btnToggleConduits')  ?.classList.add('tgl-off');
    if (!vaultsVisible)    document.getElementById('btnToggleVaults')    ?.classList.add('tgl-off');
    if (!handholesVisible) document.getElementById('btnToggleHandholes') ?.classList.add('tgl-off');
    if (!notesVisible)     document.getElementById('btnToggleNotes')     ?.classList.add('tgl-off');
    if (!dimsVisible)      document.getElementById('btnToggleDims')      ?.classList.add('tgl-off');
    if (!marksVisible)     document.getElementById('btnToggleMarks')     ?.classList.add('tgl-off');
    if (!easementsVisible) document.getElementById('btnToggleEasements')?.classList.add('tgl-off');
    if (!wifiVisible)      document.getElementById('btnToggleWifi')?.classList.add('tgl-off');
    if (!strandsVisible)   document.getElementById('btnToggleStrands')   ?.classList.add('tgl-off');
    if (!conduitsVisible)  map.getContainer().classList.add('conduit-labels-hidden');
    if (!spansVisible)     map.getContainer().classList.add('route-dist-span-hidden');
    if (!spansVisible)     map.getContainer().classList.add('span-labels-hidden');
    if (!strandsVisible)   map.getContainer().classList.add('route-dist-strand-hidden');
    if (!declutterLabels)  document.getElementById('btnToggleDeclutter')  ?.classList.add('tgl-off');
    if (!flextinDistVisible) document.getElementById('btnToggleFlextinDist')?.classList.add('tgl-off');
    if (!routeDistVisible)    document.getElementById('btnToggleRouteDist')   ?.classList.add('tgl-off');
    if (!scaleVisible)     document.getElementById('btnToggleScale')     ?.classList.add('tgl-off');
    if (!civicVisible)     document.getElementById('btnToggleCivics')    ?.classList.add('tgl-off');
    if (!densityVisible)   document.getElementById('btnToggleDensity')   ?.classList.add('tgl-off');
    if (!parcelVisible)    document.getElementById('btnToggleParcels')   ?.classList.add('tgl-off');
    if (civicVisible)      loadCivicNumbers();
    if (parcelVisible)     loadParcels();
    if (!wetlandVisible)   document.getElementById('btnToggleWetlands')  ?.classList.add('tgl-off');
    if (wetlandVisible)    loadWetlands();
    if (!zoningVisible)    document.getElementById('btnToggleZoning')    ?.classList.add('tgl-off');
    if (zoningVisible)     loadZoning();
    if (!communityVisible) document.getElementById('btnToggleCommunities')?.classList.add('tgl-off');
    if (communityVisible)  loadCommunities();
    document.getElementById('btnSalisburyOnly')
      ?.addEventListener('click', toggleSalisburyOnly);
    if (!salisburyOnlyMode) document.getElementById('btnSalisburyOnly')?.classList.add('tgl-off');
    document.getElementById('btnToggleBoundary')
      ?.addEventListener('click', toggleBoundary);
    if (!boundaryVisible)  document.getElementById('btnToggleBoundary')  ?.classList.add('tgl-off');
    document.getElementById('btnToggleExch')
      ?.addEventListener('click', toggleExch);
    if (!exchVisible)      document.getElementById('btnToggleExch')       ?.classList.add('tgl-off');
    document.getElementById('btnToggleServiceArea')
      ?.addEventListener('click', soloServiceArea);
    if (!serviceAreaVisible) document.getElementById('btnToggleServiceArea')?.classList.add('tgl-off');
    document.getElementById('btnToggleRoadCov')
      ?.addEventListener('click', toggleRoadCoverage);
    if (!roadCovVisible)   document.getElementById('btnToggleRoadCov')    ?.classList.add('tgl-off');
    const _boxSelBtn    = document.getElementById('btnRoadCovBoxSel');
    const _outsideBtn   = document.getElementById('btnOutsideRoads');
    const _typeFilterBtn= document.getElementById('btnRoadTypeFilter');
    _boxSelBtn    ?.addEventListener('click', startRoadCovBoxSelect);
    _outsideBtn   ?.addEventListener('click', toggleOutsideRoads);
    _typeFilterBtn?.addEventListener('click', toggleRoadTypeFilterPanel);
    if (roadCovVisible) {
      if (_boxSelBtn)     _boxSelBtn.style.display     = '';
      if (_outsideBtn)    _outsideBtn.style.display    = '';
      if (_typeFilterBtn) _typeFilterBtn.style.display = '';
      // Restored from the saved layer state: the button's dot must say so.
      if (outsideRoadsVisible) {
        _outsideBtn?.classList.remove('tgl-off');
        roadCovOutsideGroup.addTo(map);
      }
    } else {
      outsideRoadsVisible = false;
    }

    // ── Parcels: a click on bare map shows the parcel under it ───────────────
    map.on('popupopen', function () { lastPopupOpenAt = performance.now(); });
    map.on('preclick', function () { lastClickStartAt = performance.now(); });
    setLabelTier();
    map.on('zoomend', setLabelTier);
    map.on('click', onMapClickForParcel);
    map.on('click', onMapClickPoleDot);   // pole dots (zoomed-out band) are not interactive themselves
    // Right-click with Civic #s on: add an address GeoNB is missing.
    map.on('contextmenu', function (ev) {
      if (!civicVisible || map.getZoom() < CIVIC_MIN_ZOOM) return;
      openCivicAddForm(ev.latlng);
    });

    // ── Civic number layer: reload on pan/zoom ───────────────────────────────
    map.on('moveend', function () {
      if (civicVisible) loadCivicNumbers();
      // Alden poles follow the view (detail regime only; overview shows hexagons).
      if (aldenVisible && map.getZoom() >= DETAIL_TEXT_ZOOM) {
        clearTimeout(aldenMoveTimer);
        aldenMoveTimer = setTimeout(loadAldenPoles, 250);
      } else if (aldenVisible) {
        aldenGroup.clearLayers();           // z13-14: too many candidates to draw
      }
      if (parcelVisible) loadParcels();
      if (wetlandVisible) loadWetlands();
      if (communityVisible) loadCommunities();
      if (zoningVisible) loadZoning();
      if (aldenVisible) updateAldenBulkControl();
    });

    // ── Icon scaling on zoom ─────────────────────────────────────────────────
    // Wrapped, not passed directly: Leaflet hands the handler an event object,
    // which would land in refreshIconSizes' `force` parameter and defeat the guard.
    map.on('zoomend', function () { refreshIconSizes(); });
    map.on('zoomend', function () {
      // Only in the detail regime — below DENSITY_ZOOM the overview shows density
      // hexagons and these labels must not float over them (inOverview guards the
      // one-notch band where a zoom lands before applyZoomRegime's debounce runs).
      // The group holds conduit labels as well as span ones, so either layer being
      // on is reason enough to have it; which labels then show is CSS's business.
      if (spanLabelsWanted() && map.getZoom() >= DETAIL_TEXT_ZOOM && inOverview !== true) spanLabelGroup.addTo(map);
      else map.removeLayer(spanLabelGroup);
    });
    // Slack wiggle / lashing ticks / span-label offsets are the only pixel-space
    // geometry on the map, so they alone need redrawing when the scale changes.
    map.on('zoomend', refreshZoomGeometry);

    // Label overlaps are a screen-space property, so they have to be recomputed
    // whenever the screen changes — not only when the features are re-rendered.
    map.on('moveend zoomend', function () { scheduleLabelDeclutter(); });

    // ── Viewport-based feature reload on pan/zoom ─────────────────────────────
    // Below DENSITY_ZOOM we show cached density hexagons instead of individual
    // markers (huge perf win when the whole service area is in view).
    map.on('moveend zoomend', function () {
      // A geometry edit in progress must survive the map moving — dragging a
      // vertex near the viewport edge auto-pans, and the reload would call
      // cancelActiveEdit() and silently discard the drag.
      if (activeEdit && (activeEdit.type === 'conduit' || activeEdit.type === 'span')) return;
      clearTimeout(_viewportReloadTimer);
      _viewportReloadTimer = setTimeout(() => applyZoomRegime(true), 350);
    });

    // ── Geoman draw events ────────────────────────────────────────────────────
    map.on('pm:create', onPmCreate);

    // ── Suppress all popups while a draw/route mode is active ────────────────
    map.on('popupopen', function (e) {
      if (addingStrand || drawingSpan || routingSpanViaPoles || routingOnuDrop || routingCustDrop) {
        map.closePopup();
      } else if (mapTaskActive() && e && e.popup && e.popup._source) {
        map.closePopup();   // belt and braces: a layer-bound popup that opened anyway
      }
    });

    // Conduit and vault popups carry an empty .tl-box; fill it with the asset's notes and photos.
    map.on('popupopen', function (e) { const el = e.popup.getElement(); if (el) el.querySelectorAll('.tl-box').forEach(tlLoad); });

    // Pole popup wiring, delegated off the map — see onPolePopupOpen.
    map.on('popupopen', function (e) {
      const marker = e.popup._source;
      if (marker && marker._poleProps) onPolePopupOpen(marker);
    });

    // ── Modal save buttons ────────────────────────────────────────────────────
    document.getElementById('saveFoscBtn')?.addEventListener('click', saveNewFosc);
    document.getElementById('savePoleBtn')?.addEventListener('click', savePole);
    document.getElementById('savePoleMultiBtn')?.addEventListener('click', savePoleMulti);
    document.getElementById('saveSpanBtn')?.addEventListener('click', saveNewSpan);
    document.getElementById('saveFlextinBtn')?.addEventListener('click', saveFlextinRun);
    document.getElementById('saveMstBtn')?.addEventListener('click', saveMst);
    document.getElementById('saveConduitBtn')?.addEventListener('click', saveConduit);
    document.getElementById('saveVaultBtn')?.addEventListener('click', saveVault);
    document.getElementById('saveNoteBtn')?.addEventListener('click', saveNote);

    document.getElementById('newConduitModal')
      ?.addEventListener('hidden.bs.modal', resetConduitModal);
    document.getElementById('newVaultModal')
      ?.addEventListener('hidden.bs.modal', resetVaultModal);
    document.getElementById('newNoteModal')
      ?.addEventListener('hidden.bs.modal', resetNoteModal);

    // Note icon selector: click on a tile to select it
    document.querySelectorAll('.note-icon-opt').forEach(function (el) {
      el.addEventListener('click', function () {
        setNoteIconSelection(el.dataset.val);
      });
    });

    // Pole multi-edit: checkboxes enable their corresponding input
    ['Type', 'Owner', 'Nbp', 'Telco', 'Tags', 'WorkOrder', 'Comment'].forEach(function (f) {
      const chk = document.getElementById('poleMulti' + f + 'Check');
      const inp = document.getElementById('poleMulti' + f);
      if (chk && inp) {
        chk.addEventListener('change', function () {
          inp.disabled = !chk.checked;
          if (chk.checked) inp.focus();
        });
      }
    });

    // Escape key: cancel active edit (move, span edit, pole selection) or measurement
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || document.querySelector('.modal.show')) return;
      if (aldenLinkPoleId)  { aldenLinkPoleId = null; map.getContainer().style.cursor = ''; toast('Link cancelled', 'info'); return; }
      if (rcBoxSelecting)   { exitRoadCovBoxSelect(); return; }
      if (pickCoordActive) { stopPickCoord(); return; }
      if (mapPick)         { mapPick(); return; }
      if (dimTool)         { stopDimTool(); return; }
      if (measureActive)   { clearMeasure(); return; }
      if (addAnchorMode)    { exitAddAnchorMode();    return; }
      if (addUguardMode)    { exitAddUguardMode();    return; }
      if (insertStrandMode) { exitInsertStrandMode(); return; }
      if (addingStrand)    { exitAddStrandMode(); return; }
      if (drawingSpan)     { exitSpanDrawMode();  return; }
      if (planFraming)     { exitPlanFraming();   return; }
      if (activeEdit)      { cancelActiveEdit();  return; }
      if (openToolbarPanel) setToolbarPanel(null);
    });

    // Shift+click pole selection: document-level capture so it fires before Leaflet
    // opens any popup. Checks if the click target is inside a pole marker element.
    document.addEventListener('click', function (e) {
      if (!e.shiftKey) return;
      if (!poleGroup) return;
      var target = e.target;
      var found = false;
      poleGroup.eachLayer(function (m) {
        if (found) return;
        var el = m.getElement ? m.getElement() : null;
        if (el && (el === target || el.contains(target))) {
          found = true;
          e.stopImmediatePropagation();
          e.preventDefault();
          togglePoleSelect(m._poleFid);
        }
      });
    }, true); // capture phase
    document.getElementById('newFoscModal')
      ?.addEventListener('hidden.bs.modal', resetFoscModal);
    document.getElementById('poleModal')
      ?.addEventListener('hidden.bs.modal', resetPoleModal);
    document.getElementById('newSpanModal')
      ?.addEventListener('hidden.bs.modal', resetSpanModal);
    document.getElementById('newFlextinModal')
      ?.addEventListener('hidden.bs.modal', resetFlextinModal);
    document.getElementById('newMstModal')
      ?.addEventListener('hidden.bs.modal', resetMstModal);
    document.getElementById('spanCableId')
      ?.addEventListener('change', updateSpanTapSection);
    document.getElementById('flextinFoscId')
      ?.addEventListener('change', updateFlextinBearing);
    ['flextinCableId', 'flextinModelA', 'flextinModelB',
     'flextinBearingA', 'flextinBearingB', 'flextinDirection']
      .forEach(id => document.getElementById(id)
        ?.addEventListener('change', updateFlextinPreview));

    loadPermitList();
    // ONUs + drop spans load once on startup (shown per regime + their toggle).
    loadOnuMarkers();
    loadDropSpans();
    // Deep-links / plan view must open at detail zoom even if the saved view is
    // zoomed out; otherwise honour the saved zoom (density hexagons when far out).
    const forceDetail = /[?&](plan|fosc_id|pole_fid)=/.test(location.search)
                        || !!window._openFoscId || !!window._openPoleFid;
    // Same one-shot rule for item links: reloading should not re-jump to the item.
    try {
      const q2 = new URLSearchParams(location.search);
      if (q2.has('fosc_id') || q2.has('pole_fid')) {
        q2.delete('fosc_id'); q2.delete('pole_fid');
        const qs2 = q2.toString();
        history.replaceState(null, '', location.pathname + (qs2 ? '?' + qs2 : '') + location.hash);
      }
    } catch {}
    // A deep link must move the map BEFORE the first feature fetch: features come
    // back for the current bbox, so panning afterwards would look for a marker that
    // was never loaded. runDetailStartup still opens the popup once it exists.
    if (window._deepLinkLatLng) {
      map.setView(window._deepLinkLatLng, Math.max(map.getZoom(), 18));
    }
    // Seed the label font ramp for the restored zoom — renderAll builds icons at
    // the right scale on its own, but the container class is only set by zoomend.
    applyZoomScaleClasses(iconScale());
    applyZoomRegime(false, forceDetail);
  });

  // ── Live optitap colouring ───────────────────────────────────────────────────
  // Which optitaps currently carry light, from /api/live_optitaps.php. Null until
  // that answers; the map renders fine without it.
  let _liveOptitapIds = null;

  // A live optitap shows cyan instead of its status colour. EVERY path that builds
  // a fosc icon must go through here: both renderFosc and refreshIconSizes rebuild
  // from _tinIcon.color (the status colour), so a tint written straight onto the
  // icon was silently lost on the next re-render or zoom-bucket change.
  function foscDisplayColor(ti, foscId) {
    return (ti.kind === 'optitap' && _liveOptitapIds && _liveOptitapIds[foscId])
      ? '#06b6d4'          // cyan = live traffic
      : ti.color;
  }

  function applyLiveOptitapColours() {
    if (!_liveOptitapIds) return;
    const s = iconScale();
    foscGroup.eachLayer(marker => {
      const ti = marker._tinIcon;
      if (!ti || ti.kind !== 'optitap') return;
      marker.setIcon(foscIconForKind('optitap', ti.status, foscDisplayColor(ti, marker._foscId),
                                     ti.highlight, s, !!ti.damaged, ti.angle || 0, ti.fanSlot || 0));
    });
  }

  // Open a deep-linked closure or pole popup and keep it open through the
  // re-render that follows.
  //
  // Two separate faults made ?fosc_id= and ?pole_fid= pan to the right place with
  // no popup. First, the original code scanned the layer groups once from
  // runDetailStartup; that runs off loadFeaturesCached, which short-circuits to
  // Promise.resolve() on a bounds cache hit, so the scan often ran before the
  // marker existed, found nothing, and never looked again. Second — and why a
  // plain retry still failed — panning to the target raises the zoom, which
  // refetches, and renderAll() calls foscGroup.clearLayers(): the marker we had
  // just opened was destroyed along with its popup a moment later.
  //
  // So: pan once, then re-open whenever the popup is not showing, and keep
  // watching briefly after it first appears so a rebuild cannot swallow it.
  let _deepLinkPanned = false;
  function openDeepLinkTargets(attempt = 0, settle = 0) {
    const ensure = (group, prop, want) => {
      let hit = null;
      group.eachLayer(function (layer) { if (layer[prop] === want) hit = layer; });
      if (!hit) return false;                      // not rendered yet — try again
      if (!_deepLinkPanned) {
        map.setView(hit.getLatLng(), Math.max(map.getZoom(), 18));
        _deepLinkPanned = true;
      }
      if (!hit.isPopupOpen()) hit.openPopup();
      return true;
    };

    let wanted = false, shown = true;
    if (window._openFoscId) {
      wanted = true;
      if (!ensure(foscGroup, '_foscId', window._openFoscId)) shown = false;
    }
    if (window._openPoleFid) {
      wanted = true;
      if (!ensure(poleGroup, '_poleFid', window._openPoleFid)) shown = false;
    }
    if (!wanted) return;

    // ~8s to find the marker at all; once found, ~2.4s more of holding it open
    // against the rebuild, then leave the popup to the user.
    if (!shown) {
      if (attempt < 20) setTimeout(function () { openDeepLinkTargets(attempt + 1, 0); }, 400);
    } else if (settle < 6) {
      setTimeout(function () { openDeepLinkTargets(attempt, settle + 1); }, 400);
    } else {
      window._openFoscId = null;
      window._openPoleFid = null;
    }
  }

  // Runs once, the first time we render the detailed (marker) view: live optitap
  // colouring, deep-link targets, and plan view. Guarded so it fires a single time.
  let _detailStartupDone = false;
  function runDetailStartup() {
    if (_detailStartupDone) return;
    _detailStartupDone = true;


    // Deep-link: ?fosc_id=N / ?pole_fid=N — pan and open the popup.
    openDeepLinkTargets();
    // Plan view: ?plan=N or ad-hoc params — enter locked, framed plan view
    loadPlanFromUrl();
  }

  // ── feature loading & rendering ─────────────────────────────────────────────

  // Permit autocomplete lives in /js/permit-picker.js (shared with the Bell
  // Permits page). This page owns the chip lists that the multi-permit fields
  // feed via the `permit:selected` event.
  function loadPermitList() {
    if (window.PermitPicker) PermitPicker.refresh();   // a save may have added a permit
    initPermitPickers();
  }

  function initPermitPickers() {
    window.PermitPicker && PermitPicker.init(() => cfg.csrf);
    _initExtraPermitsField();
    _initFoscExtraField();
    _initSpanExtraField();
  }

  function _renderFoscExtraChips() {
    _renderPermitChips('foscExtraPermitsChips', _foscExtraPermits, _renderFoscExtraChips);
  }

  function _initFoscExtraField() {
    const addInp = document.getElementById('foscExtraPermitAdd');
    if (!addInp || addInp.dataset.multiInit) return;
    addInp.dataset.multiInit = '1';
    addInp.addEventListener('permit:selected', e => {
      const p = e.detail;
      if (p && !_foscExtraPermits.includes(p)) { _foscExtraPermits.push(p); _renderFoscExtraChips(); }
    });
  }

  // Spans carry several permits too (migration 0151) — same chip UI as closures.
  function _renderSpanExtraChips() {
    _renderPermitChips('spanExtraPermitsChips', _spanExtraPermits, _renderSpanExtraChips);
  }

  function _initSpanExtraField() {
    const addInp = document.getElementById('spanExtraPermitAdd');
    if (!addInp || addInp.dataset.multiInit) return;
    addInp.dataset.multiInit = '1';
    addInp.addEventListener('permit:selected', e => {
      const p = e.detail;
      if (p && !_spanExtraPermits.includes(p)) { _spanExtraPermits.push(p); _renderSpanExtraChips(); }
    });
  }

  // Chip row shared by the pole / closure / span "Additional Permits" fields.
  function _renderPermitChips(containerId, permits, rerender) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    permits.forEach((p, i) => {
      const chip = document.createElement('span');
      chip.className = 'badge rounded-pill bg-secondary d-inline-flex align-items-center gap-1';
      chip.style.cssText = 'font-size:.78rem;font-weight:normal;padding:.25em .55em';
      chip.textContent = p;
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'btn-close btn-close-white';
      x.style.cssText = 'font-size:.55rem;padding:0';
      x.setAttribute('aria-label', 'Remove');
      x.addEventListener('click', () => { permits.splice(i, 1); rerender(); });
      chip.appendChild(x);
      container.appendChild(chip);
    });
  }

  function _renderExtraPermitChips() {
    _renderPermitChips('poleExtraPermitsChips', _poleExtraPermits, _renderExtraPermitChips);
  }

  function _initExtraPermitsField() {
    const addInp = document.getElementById('poleExtraPermitAdd');
    if (!addInp || addInp.dataset.multiInit) return;
    addInp.dataset.multiInit = '1';
    addInp.addEventListener('permit:selected', e => {
      const p = e.detail;
      if (p && !_poleExtraPermits.includes(p)) {
        _poleExtraPermits.push(p);
        _renderExtraPermitChips();
      }
    });
  }

  // ── Zoom regime: detailed markers vs. cached density hexagons ────────────────
  // Below DENSITY_ZOOM the whole service area can be in view (thousands of poles),
  // so we draw pre-aggregated hexagons instead of individual markers. The heavy
  // asset layers are toggled only on regime transitions to avoid per-pan churn.
  function applyZoomRegime(preserveView = false, forceDetail = false) {
    // Framing pulls the display back so the whole sheet fits, which can cross
    // DENSITY_ZOOM — but you cannot frame a plan over density hexagons, so real
    // assets stay drawn while framing.
    const detail = forceDetail || planFraming || planDetailLock || map.getZoom() >= DENSITY_ZOOM;
    setAddToolsAvailable(detail);
    if (detail) {
      if (inOverview !== false) {           // transition overview → detail (or first run)
        densityGroup.clearLayers();
        map.removeLayer(densityGroup);
        aldenDensityGroup.clearLayers();
        map.removeLayer(aldenDensityGroup);
        hideDensityLegend();
        showDetailAssetLayers();
        if (aldenVisible) { ensureAldenBulkControl(); updateAldenBulkControl(); }
        inOverview = false;
        _lastLoadedBounds = null;           // layers were only detached, not rebuilt
      }
      return loadFeaturesCached(preserveView).then(runDetailStartup);   // callers that must wait for the assets (print) can
    } else {
      if (inOverview !== true) {            // transition detail → overview
        hideDetailAssetLayers();
        // Can't import from hexagons — the per-asset bulk control belongs to detail.
        if (aldenBulkControl) { map.removeControl(aldenBulkControl); aldenBulkControl = null; }
        if (densityVisible) {
          densityGroup.addTo(map);
          if (aldenVisible) aldenDensityGroup.addTo(map);
          showDensityLegend();
        }
        inOverview = true;
        _lastLoadedBounds = null;
      }
      loadDensity();
      loadAldenDensity();                    // no-op unless the Alden layer is on
    }
  }

  // In the overview regime individual assets aren't drawn, so nothing can be
  // placed — the Add rail button goes away rather than offering dead tools.
  function setAddToolsAvailable(available) {
    const btn = document.querySelector('.tin-rail-btn[data-panel="add"]');
    if (!btn) return;
    btn.style.display = available ? '' : 'none';
    if (!available && openToolbarPanel === 'add') setToolbarPanel(null);
  }

  // The per-asset marker/line layers hidden in the overview regime (contextual
  // polygon layers — coverage, boundary, exchange — keep their own toggles).
  // Must list every group showDetailAssetLayers() adds — anything missing here is
  // added on entering detail regime and then never removed, so it floats over the
  // density hexagons in the overview.
  function detailAssetLayers() {
    return [poleGroup, foscGroup, popGroup, spanGroup, spanLabelGroup, flextinDistGroup, routeDistGroup,
            strandGroup, anchorGroup, buildingAnchorGroup, uguardGroup, conduitGroup,
            vaultGroup, handholeGroup, noteGroup, dimGroup, markGroup, dropGroup, onuGroup, civicGroup, parcelGroup,
            aldenGroup, aldenVaultGroup,
          ];
  }

  function hideDetailAssetLayers() {
    detailAssetLayers().forEach(g => { if (g && map.hasLayer(g)) map.removeLayer(g); });
  }

  // Re-add the layers that are toggled on — mirrors the init block's conditions.
  function showDetailAssetLayers() {
    if (conduitsVisible)    conduitGroup.addTo(map);
    if (vaultsVisible)      vaultGroup.addTo(map);
    if (handholesVisible)   handholeGroup.addTo(map);
    if (notesVisible)       noteGroup.addTo(map);
    if (dimsVisible)        dimGroup.addTo(map);
    if (marksVisible)       markGroup.addTo(map);
    if (polesVisible)       poleGroup.addTo(map);
    if (spansVisible)       spanGroup.addTo(map);
    if (spanLabelsWanted() && map.getZoom() >= DETAIL_TEXT_ZOOM) spanLabelGroup.addTo(map);
    if (flextinDistVisible) flextinDistGroup.addTo(map);
    if (routeDistVisible)    routeDistGroup.addTo(map);
    if (strandsVisible)     strandGroup.addTo(map);
    if (closuresVisible)    foscGroup.addTo(map);
    if (popsVisible)        popGroup.addTo(map);
    if (anchorsVisible)     anchorGroup.addTo(map);
    if (buildingAnchorsVisible) buildingAnchorGroup.addTo(map);
    if (uguardsVisible)     uguardGroup.addTo(map);
    if (onuVisible)       { dropGroup.addTo(map); onuGroup.addTo(map); }
    if (civicVisible)       civicGroup.addTo(map);
    if (parcelVisible)      parcelGroup.addTo(map);
    if (aldenVisible)     { aldenGroup.addTo(map); aldenVaultGroup.addTo(map);
                            if (!aldenLoaded && map.getZoom() >= DETAIL_TEXT_ZOOM) { loadAldenPoles(); loadAldenVaults(); } }
  }

  // The overview hexagons stand in for the asset layers, so they belong in the
  // Layers panel and are swept by its "All off" — with them off a zoomed-out map
  // shows only the contextual layers (road coverage, Rogers-funded, boundaries).
  // The regime transition in applyZoomRegime only fires on a zoom change, so the
  // add/remove has to happen here too when the toggle is hit while already out.
  function toggleDensity() {
    densityVisible = !densityVisible;
    document.getElementById('btnToggleDensity')?.classList.toggle('tgl-off', !densityVisible);
    if (map.getZoom() < DENSITY_ZOOM && !planFraming && !planDetailLock) {
      if (densityVisible) {
        densityGroup.addTo(map);
        if (aldenVisible) aldenDensityGroup.addTo(map);
        showDensityLegend();
        loadDensity();
        loadAldenDensity();
      } else {
        densityGroup.clearLayers();
        map.removeLayer(densityGroup);
        aldenDensityGroup.clearLayers();
        map.removeLayer(aldenDensityGroup);
        hideDensityLegend();
      }
    }
    saveLayerState();
  }

  async function loadDensity() {
    if (!densityVisible) return;
    try {
      const b = map.getBounds().pad(0.15);
      const bbox = `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
      const z = Math.floor(map.getZoom());
      const resp = await fetch(API.density + '?z=' + z + '&bbox=' + bbox);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      renderDensity(await resp.json());
    } catch (e) {
      toast('Failed to load density: ' + e.message, 'danger');
    }
  }

  // Shared hexagon renderer for both density layers. `ramp` picks the colour scale
  // (teal for curated assets, purple for Alden candidates); `noun` labels the tooltip.
  function renderDensityInto(group, data, ramp, noun) {
    group.clearLayers();
    const maxCnt = Math.max(1, data.max_cnt || 1);
    const logMax = Math.log(maxCnt + 1);
    (data.features || []).forEach(f => {
      const g = f.geometry;
      if (!g || g.type !== 'Polygon') return;
      const latlngs = g.coordinates[0].map(c => [c[1], c[0]]);
      const cnt = f.properties.cnt || 0;
      const t = Math.log(cnt + 1) / logMax;   // 0..1, log-scaled (darker = more)
      const poly = L.polygon(latlngs, Object.assign({ pane: 'densityPane' }, densityStyle(t, ramp)));
      poly.bindTooltip(cnt.toLocaleString() + ' ' + noun + (cnt === 1 ? '' : 's'),
                       { sticky: true, direction: 'top', opacity: 0.9 });
      poly.on('click', () => map.fitBounds(poly.getBounds(), { maxZoom: DENSITY_ZOOM + 2 }));
      group.addLayer(poly);
    });
  }

  function renderDensity(data) {
    renderDensityInto(densityGroup, data, DENSITY_RAMP_TEAL, 'asset');
  }

  // Curated assets = teal; Alden candidates = purple (the Alden One identity colour).
  const DENSITY_RAMP_TEAL   = { lo: [153, 246, 228], hi: [4, 47, 46],   line: '#0f766e' }; // teal-200 → teal-950
  const DENSITY_RAMP_PURPLE = { lo: [216, 180, 254], hi: [59, 7, 100],  line: '#6b21a8' }; // purple-300 → purple-950

  // Light (few) → dark (many); opacity also rises so denser cells read darker.
  function densityStyle(t, ramp) {
    const c = ramp.lo.map((v, i) => Math.round(v + (ramp.hi[i] - v) * t));
    return {
      color: ramp.line, weight: 1, opacity: 0.55,
      fillColor: `rgb(${c[0]},${c[1]},${c[2]})`, fillOpacity: 0.45 + 0.35 * t,
      // Hook for the B&W sheet: these are the one filled shape on the map whose
      // fill carries a value, so mono has to darken them rather than blank them.
      className: 'tin-density',
    };
  }

  // Alden-candidate density hexagons — shown in the overview regime only while the
  // "Alden One" layer is toggled on, so the two density views don't fight for the map.
  async function loadAldenDensity() {
    if (!aldenVisible || !densityVisible) return;
    try {
      const b = map.getBounds().pad(0.15);
      const bbox = `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
      const z = Math.floor(map.getZoom());
      const resp = await fetch(API.aldenDensity + '?z=' + z + '&bbox=' + bbox);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      renderDensityInto(aldenDensityGroup, await resp.json(), DENSITY_RAMP_PURPLE, 'Alden candidate');
    } catch (e) { /* overview is approximate; silent */ }
  }

  function showDensityLegend() {
    if (densityLegend) return;
    densityLegend = L.control({ position: 'bottomright' });
    densityLegend.onAdd = function () {
      const div = L.DomUtil.create('div', 'density-legend');
      div.innerHTML =
        '<div style="font-weight:600;margin-bottom:3px">Asset density</div>' +
        '<div style="display:flex;align-items:center;gap:6px">' +
          '<span style="font-size:10px">fewer</span>' +
          '<span style="flex:1;height:10px;border-radius:2px;display:inline-block;min-width:80px;' +
            'background:linear-gradient(90deg,rgb(153,246,228),rgb(4,47,46))"></span>' +
          '<span style="font-size:10px">more</span>' +
        '</div>' +
        '<div id="aldenDensityLegendRow" style="display:none;margin-top:5px">' +
          '<div style="font-weight:600;margin-bottom:3px;color:#7e22ce">Alden One candidates</div>' +
          '<div style="display:flex;align-items:center;gap:6px">' +
            '<span style="font-size:10px">fewer</span>' +
            '<span style="flex:1;height:10px;border-radius:2px;display:inline-block;min-width:80px;' +
              'background:linear-gradient(90deg,rgb(216,180,254),rgb(59,7,100))"></span>' +
            '<span style="font-size:10px">more</span>' +
          '</div>' +
        '</div>' +
        '<div style="font-size:10px;color:#666;margin-top:2px">Zoom in for detail</div>';
      return div;
    };
    densityLegend.addTo(map);
    updateAldenDensityLegend();
  }

  function hideDensityLegend() {
    if (densityLegend) { map.removeControl(densityLegend); densityLegend = null; }
  }

  // The purple "Alden One candidates" legend row shows only when that layer is on.
  function updateAldenDensityLegend() {
    const row = document.getElementById('aldenDensityLegendRow');
    if (row) row.style.display = aldenVisible ? '' : 'none';
  }

  // The extent of the last successful fetch. Null means "nothing trustworthy is
  // drawn" — see loadFeaturesCached.
  let _lastLoadedBounds = null;
  let _viewportReloadTimer = null;   // the pan/zoom reload debounce (see map.on('moveend zoomend'))

  // Always fetches. Every post-mutation caller uses this (there are ~35 of them),
  // so an edit is never served from a stale render.
  // Only the newest request may render. Two loads can be in flight at once — a
  // detail-lock reload of a wide view and the plan sheet's own, or two quick
  // pans — and the earlier one, arriving second, would repaint the map with
  // features for a view it no longer shows (a plan sheet came out with no
  // assets at all this way).
  let _featureLoadSeq = 0;
  async function loadFeatures(preserveView = false) {
    const seq = ++_featureLoadSeq;
    try {
      const b = map.getBounds().pad(0.15);
      const bbox = `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
      const resp = await fetch(API.features + '?bbox=' + bbox + (abandonedVisible ? '&abandoned=1' : ''));
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      if (seq !== _featureLoadSeq) return;   // superseded while in flight
      renderAll(data, preserveView);
      _lastLoadedBounds = b;
    } catch (e) {
      if (seq !== _featureLoadSeq) return;
      _lastLoadedBounds = null;   // never claim coverage we don't have
      toast('Failed to load features: ' + e.message, 'danger');
    }
  }

  // Used only by the pan/zoom handler. Zooming IN always lands strictly inside the
  // bounds we already fetched — Leaflet zooms about an anchor within the current
  // view — so the old unconditional refetch was re-running ~2.5 s of PostGIS and
  // rebuilding ~10k DOM nodes to arrive at exactly what was already on screen. On a
  // hit we only refresh the viewport-scoped pole labels; the pixel-space geometry
  // is already handled by the zoomend → refreshZoomGeometry binding, which runs
  // immediately rather than waiting out this debounce.
  function loadFeaturesCached(preserveView = true) {
    if (_lastLoadedBounds && _lastLoadedBounds.contains(map.getBounds().pad(0.15))) {
      setPoleDotMode(wantPoleDots());      // crossing POLE_DOT_ZOOM swaps icons <-> dots from the data already loaded
      refreshPoleLabels();
      return Promise.resolve();
    }
    return loadFeatures(preserveView);
  }

  // Coincident-line flags (has_span on strands, covered_by_strand on spans) are
  // computed server-side in features.php against ALL data, so the cased-hybrid
  // styling is stable across zoom (see project memory). Conduit does NOT
  // participate — it is always drawn at its own geometry.

  // ── Click-to-edit chooser for stacked routes ────────────────────────────────
  // A pole route often stacks several line features on (nearly) the same pixels:
  // a strand + the cable span it carries, or a span running over a conduit. Their
  // individual click targets overlap or (for a covered span) sit offset from where
  // the casing is actually drawn, so the wrong feature — or nothing — gets hit.
  // Instead, clicking any line collects every feature on that route and, when more
  // than one is present, shows a small chooser; each entry opens that feature's own
  // (unchanged) detail popup. `lineFeatIndex` maps kind→id→its primary click layer.
  const lineFeatIndex = { strand: {}, span: {}, conduit: {} };

  function registerLineFeat(kind, id, layer) { lineFeatIndex[kind][id] = layer; }

  function enableRouteChooser(clickLayer, kind, id) {
    clickLayer._chooserRef = { kind, id };
    clickLayer.off('click');        // drop bindPopup's default click-to-open handler
    clickLayer.on('click', onLineClick);
  }

  // Min pixel distance from a latlng to a (possibly multi-part) polyline.
  function pxDistToPolyline(latlng, latlngs) {
    if (!latlngs || !latlngs.length) return Infinity;
    const p = map.latLngToContainerPoint(latlng);
    const rings = Array.isArray(latlngs[0]) ? latlngs : [latlngs];
    let best = Infinity;
    for (const ring of rings) {
      if (!ring.length) continue;
      const pts = ring.map(ll => map.latLngToContainerPoint(ll));
      if (pts.length === 1) { best = Math.min(best, p.distanceTo(pts[0])); continue; }
      for (let i = 0; i < pts.length - 1; i++) {
        best = Math.min(best, L.LineUtil.pointToSegmentDistance(p, pts[i], pts[i + 1]));
      }
    }
    return best;
  }

  function _lineFeatProps(layer) { return layer._tinStrand || layer._tinSpan || layer._tinConduit || {}; }

  // Every line feature within ~14 px of the click, plus the spans a nearby strand
  // carries (whose own targets sit off the visible pole-line casing).
  function lineFeaturesNear(latlng) {
    const TOL = 14;
    const found = new Map();
    const add = (kind, id) => {
      const layer = lineFeatIndex[kind][id];
      if (layer) found.set(kind + ':' + id, { kind, id: String(id), layer, props: _lineFeatProps(layer) });
    };
    for (const kind of ['strand', 'span', 'conduit']) {
      for (const id in lineFeatIndex[kind]) {
        const layer = lineFeatIndex[kind][id];
        if (layer && layer.getLatLngs && pxDistToPolyline(latlng, layer.getLatLngs()) <= TOL) add(kind, id);
      }
    }
    for (const e of [...found.values()]) {
      if (e.kind !== 'strand') continue;
      for (const sid of (e.props.carried_span_ids || [])) add('span', sid);
    }
    return [...found.values()];
  }

  // A closure sits in its handhole or vault, so the two (or more) icons stack on the same spot and one
  // always wins the click. Clicking either asks which one is meant, as the route chooser does for lines:
  // every closure, vault, handhole or manhole within 8 m of the clicked one (and within 60 px on screen).
  // Returns true when it showed the chooser; false leaves the marker to open its own popup.
  function openPointChooser(marker, e) {
    if (!marker._map || !e || !e.latlng) return false;
    const here = marker.getLatLng(), herePx = map.latLngToContainerPoint(here);
    const entries = [];
    const consider = (m) => {
      if (!(m._tinFosc || m._tinVault) || !map.hasLayer(m) || !m._icon || m._icon.style.display === 'none') return;
      if (m !== marker && (map.distance(here, m.getLatLng()) > 8 || herePx.distanceTo(map.latLngToContainerPoint(m.getLatLng())) > 60)) return;
      const f = m._tinFosc, v = m._tinVault && m._tinVault.props;
      const label = f
        ? (f.kind && f.kind !== 'closure' ? f.kind.charAt(0).toUpperCase() + f.kind.slice(1) : 'Closure') + ' ' + esc(f.splice_num) + ' <span class="text-muted">' + esc(f.status || '') + '</span>'
        : esc(v.type.charAt(0).toUpperCase() + v.type.slice(1)) + ' ' + esc(v.name || vaultRef(v)) + ' <span class="text-muted">' + esc(v.status || '') + '</span>';
      entries.push({ marker: m, label, kind: f ? 'fosc' : 'vault' });
    };
    [foscGroup, vaultGroup, handholeGroup].forEach(g => g && g.eachLayer(consider));
    if (entries.length < 2) return false;
    if (e.originalEvent) L.DomEvent.stop(e.originalEvent);
    entries.sort((a, b) => (a.marker === marker ? -1 : 0) - (b.marker === marker ? -1 : 0) || a.kind.localeCompare(b.kind));
    const rows = entries.map((en, i) =>
      `<button class="btn btn-sm btn-outline-secondary d-flex align-items-center w-100 mb-1 route-choose-btn" data-pt="${i}" style="text-align:left">` +
      `<span style="display:inline-block;width:12px;height:12px;border-radius:${en.kind === 'fosc' ? '50%' : '2px'};background:${en.kind === 'fosc' ? '#e0a800' : '#b45309'};margin-right:8px;flex:0 0 auto"></span>` +
      `<span>${en.label}</span></button>`).join('');
    const popup = L.popup({ minWidth: 200, className: 'route-chooser-popup' })
      .setLatLng(e.latlng)
      .setContent(`<div><div class="small text-muted mb-1">Here:</div>${rows}</div>`)
      .openOn(map);
    const root = popup.getElement();
    if (root) root.querySelectorAll('[data-pt]').forEach(btn => btn.addEventListener('click', function () {
      const en = entries[Number(this.getAttribute('data-pt'))];
      map.closePopup(popup);
      en.marker.openPopup();
    }));
    return true;
  }

  function onLineClick(e) {
    if (e.originalEvent) L.DomEvent.stopPropagation(e.originalEvent);
    const self = this._chooserRef;
    const entries = lineFeaturesNear(e.latlng);
    if (self && !entries.some(x => x.kind === self.kind && x.id === String(self.id))) {
      const layer = lineFeatIndex[self.kind][self.id];
      if (layer) entries.push({ kind: self.kind, id: String(self.id), layer, props: _lineFeatProps(layer) });
    }
    if (entries.length <= 1) {
      const only = entries[0];
      if (only && only.layer) only.layer.openPopup(e.latlng);
      return;
    }
    openRouteChooser(entries, e.latlng);
  }

  function openRouteChooser(entries, latlng) {
    const poleLbl = (uid, nbp, fid) => uid || (nbp ? 'NBP# ' + nbp : (fid != null ? 'fid ' + fid : '?'));
    // Every row ends with its id. Two spans of one cable, or two strands between the
    // same pole pair (the dup_strand data error), otherwise render as identical rows
    // with no way to tell which one you are about to open.
    const idTag = e => ` <span class="text-muted">#${esc(e.id)}</span>`;
    const label = e => {
      const p = e.props || {};
      if (e.kind === 'strand') {
        const ends = esc(poleLbl(p.from_pole_uid, p.from_pole_nbp, p.from_pole_fid)) + '→' +
                     esc(poleLbl(p.to_pole_uid, p.to_pole_nbp, p.to_pole_fid));
        return (p.is_overlash ? 'Overlash' : 'Messenger strand') + ' · ' + ends + idTag(e);
      }
      if (e.kind === 'span')    return 'Cable span' + (p.fibre_count ? ' ' + p.fibre_count + 'F' : '') + (p.cable_name ? ' · ' + esc(p.cable_name) : '') + idTag(e);
      if (e.kind === 'conduit') return 'Conduit' + (p.name ? ' · ' + esc(p.name) : '') + idTag(e);
      return e.kind + idTag(e);
    };
    const swatch = e => e.kind === 'conduit' ? '#fb8500' : e.kind === 'span' ? '#111827' : '#5EB030';
    const rows = entries.map(e =>
      `<button class="btn btn-sm btn-outline-secondary d-flex align-items-center w-100 mb-1 route-choose-btn" ` +
      `data-choose="${e.kind}:${e.id}" style="text-align:left">` +
      `<span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:${swatch(e)};margin-right:8px;flex:0 0 auto"></span>` +
      `<span>${label(e)}</span></button>`).join('');
    const popup = L.popup({ minWidth: 200, className: 'route-chooser-popup' })
      .setLatLng(latlng)
      .setContent(`<div><div class="small text-muted mb-1">On this route:</div>${rows}</div>`)
      .openOn(map);
    const root = popup.getElement();
    if (root) root.querySelectorAll('.route-choose-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const [kind, id] = this.getAttribute('data-choose').split(':');
        map.closePopup(popup);
        const layer = lineFeatIndex[kind][id];
        if (layer) layer.openPopup(latlng);
      });
    });
  }

  function renderAll(geojson, preserveView = false) {
    // Don't interrupt a mode that spans several clicks on a zoom/pan reload. A
    // span draw and plan framing were already exempt (framing deliberately zooms
    // out, which triggers one); the route-via-poles modes need it just as much —
    // you pan and zoom along the route while clicking the poles, every pan calls
    // loadFeatures, and cancelActiveEdit() was quietly ending the mode: bar gone,
    // further pole clicks ignored, nothing ever saved.
    const inMultiClickMode = drawingSpan || planFraming || addingStrand ||
                             routingSpanViaPoles || routingOnuDrop || routingCustDrop;
    if (!inMultiClickMode) cancelActiveEdit();
    conduitGroup.clearLayers();
    vaultGroup.clearLayers();
    handholeGroup.clearLayers();
    noteGroup.clearLayers();
    dimGroup.clearLayers();
    dimFeatures = [];
    markGroup.clearLayers();
    markFeatures = [];
    surveyGroup.clearLayers();
    easementGroup.clearLayers();
    easementFeatures = []; easementLabels = [];
    wifiGroup.clearLayers();
    poleGroup.clearLayers();
    foscGroup.clearLayers();
    popGroup.clearLayers();
    spanGroup.clearLayers();
    spanLabelGroup.clearLayers();
    flextinDistGroup.clearLayers();
    routeDistGroup.clearLayers();
    for (const k in routeDistLabelByKey) delete routeDistLabelByKey[k];
    anchorGroup.clearLayers();
    buildingAnchorGroup.clearLayers();
    for (const k in buildingAnchorMarkerById) delete buildingAnchorMarkerById[k];
    uguardGroup.clearLayers();
    strandGroup.clearLayers();
    clearZoomGeomRegistries();
    for (const k of ['strand', 'span', 'conduit'])
      for (const id in lineFeatIndex[k]) delete lineFeatIndex[k][id];
    foscData = [];
    poleData = [];
    poleFeatures = [];
    polesAsDots = wantPoleDots();
    poleSelection.clear();
    for (const k in poleMarkerByFid) delete poleMarkerByFid[k];
    for (const k in uguardByPoleFid) delete uguardByPoleFid[k];

    const bounds = [];
    abandonedGroup.clearLayers();
    // Plant of abandoned work orders (only sent when the toggle is on) is drawn as grey ghosts, apart from the live plant.
    const features = [];
    for (const f of (geojson.features || [])) {
      if (f.properties && f.properties.abandoned) renderAbandonedGhost(f); else features.push(f);
    }
    renderValidationAlert(geojson.violations || []);
    renderConflictAlert(geojson.conflicts || []);

    // Slack-loop glyphs turn to the bearing of the cable they sit on. Built from
    // the span FEATURES up here, before anything renders — see buildSlackAngles.
    const slackAngles = buildSlackAngles(features);
    // Stacked taps (one per ONU on a multi-ONU pole) share a location — see
    // buildFoscFanOffsets for why they get spread apart on screen.
    const fanSlots = buildFoscFanOffsets(features);

    // Conduits and vaults first so aerial assets render on top
    features.filter(f => f.properties.feature_type === 'conduit')
            .forEach(f => renderConduit(f, bounds));
    features.filter(f => f.properties.feature_type === 'conduit_termination')
            .forEach(f => renderTermination(f));
    features.filter(f => f.properties.feature_type === 'vault' || f.properties.feature_type === 'manhole')
            .forEach(f => renderVaultFeature(f));
    features.filter(f => f.properties.feature_type === 'handhole')
            .forEach(f => renderHandholeFeature(f));
    features.filter(f => f.properties.feature_type === 'pullbox')
            .forEach(f => renderHandholeFeature(f));

    // Poles first so they render behind spans and closures
    features.filter(f => f.properties.feature_type === 'pole')
            .forEach(f => { poleFeatures.push(f); renderPole(f); });
    features.filter(f => f.properties.feature_type === 'span')
            .forEach(f => renderSpan(f, bounds));
    features.filter(f => f.properties.feature_type === 'fosc')
            .forEach(f => renderFosc(f, bounds, slackAngles, fanSlots));
    features.filter(f => f.properties.feature_type === 'pop')
            .forEach(f => renderPop(f, bounds));
    features.filter(f => f.properties.feature_type === 'anchor')
            .forEach(f => renderAnchor(f));
    features.filter(f => f.properties.feature_type === 'building_anchor')
            .forEach(f => renderBuildingAnchor(f));
    features.filter(f => f.properties.feature_type === 'uguard')
            .forEach(f => renderUguard(f));
    // ── Strand chain-length computation ─────────────────────────────────────
    // Build two deduped adjacency maps — one for primary strands, one for overlash.
    // Walk each chain to find total distance from endpoint to endpoint.
    const strandFeatures = features.filter(f => f.properties.feature_type === 'strand');

    // Endpoint identity key: pole OR building anchor (mirrors app.strand_endpoint_key).
    const endKey = (p, side) => p[side + '_pole_fid'] != null
      ? 'p' + p[side + '_pole_fid']
      : 'a' + p[side + '_anchor_id'];

    function buildAdj(feats, overlash) {
      const adj = {};
      for (const f of feats) {
        if (!!f.properties.is_overlash !== overlash) continue;
        const p = f.properties;
        const coords = f.geometry.coordinates.map(c => [c[1], c[0]]);
        let seg = 0;
        for (let i = 0; i < coords.length - 1; i++) seg += L.latLng(coords[i]).distanceTo(L.latLng(coords[i+1]));
        seg = Math.round(seg);
        const a = endKey(p, 'from'), b = endKey(p, 'to');
        const pk = (a < b ? a + '_' + b : b + '_' + a);
        (adj[a] = adj[a] || []).push({ otherKey: b, lengthM: seg, pk });
        (adj[b] = adj[b] || []).push({ otherKey: a, lengthM: seg, pk });
      }
      for (const k in adj) {
        const seen = new Set();
        adj[k] = adj[k].filter(e => seen.has(e.pk) ? false : seen.add(e.pk));
      }
      return adj;
    }

    function walkChainLen(adj, startKey, prevKey) {
      let len = 0, cur = startKey, prev = prevKey;
      // Guard against cyclic strand graphs (e.g. a branch looped back onto its
      // mainline): every node has degree 2, so without a visited set this walk
      // would spin forever and freeze the render. Stop once we revisit a node.
      const visited = new Set([startKey]);
      for (;;) {
        const nexts = (adj[cur] || []).filter(e => e.otherKey !== prev);
        if (nexts.length !== 1) break;
        len += nexts[0].lengthM;
        prev = cur; cur = nexts[0].otherKey;
        if (visited.has(cur)) break; // closed a loop — chain fully traversed
        visited.add(cur);
      }
      return len;
    }

    function computeChainLengths(feats, adj, overlash) {
      const out = {};
      for (const f of feats) {
        if (!!f.properties.is_overlash !== overlash) continue;
        const p = f.properties;
        const coords = f.geometry.coordinates.map(c => [c[1], c[0]]);
        let seg = 0;
        for (let i = 0; i < coords.length - 1; i++) seg += L.latLng(coords[i]).distanceTo(L.latLng(coords[i+1]));
        seg = Math.round(seg);
        const a = endKey(p, 'from'), b = endKey(p, 'to');
        out[p.id] = Math.round(walkChainLen(adj, a, b) + seg + walkChainLen(adj, b, a));
      }
      return out;
    }

    const primaryAdj  = buildAdj(strandFeatures, false);
    const overlashAdj = buildAdj(strandFeatures, true);
    const strandChainLengths = {
      ...computeChainLengths(strandFeatures, primaryAdj,  false),
      ...computeChainLengths(strandFeatures, overlashAdj, true),
    };

    strandFeatures.forEach(f => renderStrand(f, strandChainLengths[f.properties.id]));
    strandData = strandFeatures.map(f => ({
      id:            f.properties.id,
      from_pole_fid: f.properties.from_pole_fid,
      to_pole_fid:   f.properties.to_pole_fid,
      from_pole_uid: f.properties.from_pole_uid || '',
      to_pole_uid:   f.properties.to_pole_uid   || '',
      is_overlash:   f.properties.is_overlash,
      from_lat: f.geometry.coordinates[0][1],
      from_lon: f.geometry.coordinates[0][0],
      to_lat:   f.geometry.coordinates[f.geometry.coordinates.length - 1][1],
      to_lon:   f.geometry.coordinates[f.geometry.coordinates.length - 1][0],
    }));
    features.filter(f => f.properties.feature_type === 'map_note')
            .forEach(f => renderNote(f));
    features.filter(f => f.properties.feature_type === 'survey_draft')
            .forEach(f => renderSurveyDraft(f));
    features.filter(f => f.properties.feature_type === 'easement')
            .forEach(f => renderEasement(f));
    features.filter(f => f.properties.feature_type === 'wifi_ap')
            .forEach(f => renderWifiAp(f));
    features.filter(f => f.properties.feature_type === 'dimension')
            .forEach(f => renderDimension(f));
    features.filter(f => f.properties.feature_type === 'sheath_mark')
            .forEach(f => markFeatures.push(f));
    redrawMarks();

    if (!preserveView && !hasSavedView && bounds.length) {
      // Clip outliers before fitting (centroid ±3° ≈ 330 km)
      const latAvg = bounds.reduce((s, b) => s + b[0], 0) / bounds.length;
      const lonAvg = bounds.reduce((s, b) => s + b[1], 0) / bounds.length;
      const sane = bounds.filter(b =>
        Math.sqrt(Math.pow(b[0] - latAvg, 2) + Math.pow(b[1] - lonAvg, 2)) < 3
      );
      map.fitBounds(sane.length ? sane : bounds, { padding: [40, 40], maxZoom: 16 });
    }

    // Populate work-order autocomplete datalist from loaded pole features
    const woSet = new Set();
    features.filter(f => f.properties.feature_type === 'pole').forEach(f => {
      const wos = f.properties.work_orders || (f.properties.work_order ? [f.properties.work_order] : []);
      wos.forEach(w => { if (w) woSet.add(w); });
    });
    const dl = document.getElementById('pfWorkOrderList');
    if (dl) {
      dl.innerHTML = Array.from(woSet).sort().map(w => `<option value="${esc(w)}">`).join('');
    }

    // Every icon above was built at the current scale, so the refreshIconSizes
    // memo is satisfied — record it so the next zoom inside the same bucket is a
    // no-op instead of rebuilding markers that already match.
    _lastIconScale = iconScale();
    _lastPoleScale = poleScale();
    _lastNoteLead  = noteLead();
    // Markers were just recreated without tooltips — bind the ones that qualify.
    // This also re-applies the pole filter when one is active, which the recreated
    // markers need anyway.
    refreshPoleLabels();
    // Re-apply Salisbury-only filter (runs after pole filter so both hide correctly)
    if (salisburyOnlyMode) applySalisburyFilter();
    applyFlextinClosureFilter();   // last word: it only ever hides
    applyMonoPaintOrder();
    scheduleLabelDeclutter();
  }

  // B&W draws an occupied duct's core white to hollow out its casing. The
  // aerial-on-top order above then lets the cable span that runs inside the duct
  // — same route, same weight — fill that bore straight back in with black, so in
  // mono the duct decor has to finish on top. Colour mode keeps buried under
  // aerial: there the core and the span are both #111827 and indistinguishable.
  function applyMonoPaintOrder() {
    // Also called while the basemap is being restored, before the layer groups
    // exist — there is nothing drawn to reorder yet, and renderAll calls it again.
    if (!conduitGroup || !document.body.classList.contains('map-mono')) return;
    conduitGroup.eachLayer(function (l) {
      (l._tinDecorLayers || []).forEach(function (d) { d.bringToFront(); });
    });
  }

  function renderValidationAlert(violations) {
    const box     = document.getElementById('mapValidationAlert');
    const summary = document.getElementById('mapValidationSummary');
    const count   = document.getElementById('mapValidationCount');
    const detail  = document.getElementById('mapValidationDetail');
    if (!box) return;

    if (!Array.isArray(violations) || violations.length === 0) {
      box.style.display = 'none';
      return;
    }

    const rows = violations.map(v => {
      const msg = esc(v.message || 'Validation error');
      const fid = Number(v.fosc_id);
      return fid > 0
        ? `<li><a style="color:#92400e;font-weight:600" href="/index.php?page=closure&fosc_id=${fid}">${msg}</a></li>`
        : `<li>${msg}</li>`;
    }).join('');

    count.textContent = `${violations.length} network warning${violations.length !== 1 ? 's' : ''}`;
    detail.innerHTML  = `<ul style="margin:0;padding-left:18px">${rows}</ul>`;
    box.style.display = '';

    summary.onclick = () => {
      const open = detail.style.display !== 'none';
      detail.style.display = open ? 'none' : '';
      summary.querySelector('span:last-child').textContent = open ? '▾' : '▴';
    };
  }

  // Alden import conflict worklist — a sibling amber pill listing poles where
  // Alden One disagrees with our data; click a row to fly to that pole.
  function renderConflictAlert(conflicts) {
    const box     = document.getElementById('mapConflictAlert');
    const summary = document.getElementById('mapConflictSummary');
    const count   = document.getElementById('mapConflictCount');
    const detail  = document.getElementById('mapConflictDetail');
    if (!box) return;

    if (!Array.isArray(conflicts) || conflicts.length === 0) {
      box.style.display = 'none';
      return;
    }

    const rows = conflicts.map(c => {
      const label = esc(c.telco || c.nbp || ('pole ' + c.fid));
      const bits = [];
      const k = c.kinds || [];
      if (k.includes('telco')) bits.push('telco ' + esc(c.telco || '—') + '≠' + esc(c.alden_telco || '—'));
      if (k.includes('owner')) bits.push('owner');
      if (k.includes('nbp'))   bits.push('nbp');
      if (k.includes('coord')) bits.push(c.gap_m != null ? (c.gap_m + ' m') : 'coord');
      return `<li style="margin:2px 0"><a href="#" data-fid="${c.fid}" data-lat="${c.lat ?? ''}" data-lon="${c.lon ?? ''}" style="color:#92400e;font-weight:600;text-decoration:none">${label}</a> <span style="opacity:.7">${esc(bits.join(', '))}</span></li>`;
    }).join('');

    count.textContent = `${conflicts.length} pole conflict${conflicts.length !== 1 ? 's' : ''}`;
    detail.innerHTML  = `<ul style="margin:0;padding-left:0;list-style:none">${rows}</ul>`;
    box.style.display = '';

    summary.onclick = () => {
      const open = detail.style.display !== 'none';
      detail.style.display = open ? 'none' : '';
      summary.querySelector('span:last-child').textContent = open ? '▾' : '▴';
    };
    detail.onclick = (e) => {
      const a = e.target.closest('a[data-fid]');
      if (!a) return;
      e.preventDefault();
      const fid = Number(a.dataset.fid);
      const m = poleMarkerByFid[fid];
      if (m) {
        map.setView(m.getLatLng(), Math.max(map.getZoom(), 18));
        if (m.openPopup) m.openPopup();
      } else if (a.dataset.lat && a.dataset.lon) {
        map.setView([Number(a.dataset.lat), Number(a.dataset.lon)], Math.max(map.getZoom(), 18));
      }
    };
  }

  // ── Pole rendering ───────────────────────────────────────────────────────────
  // poleOwnerColor / poleIcon → see /js/icons/map.icons.js

  function iconScale() {
    const z = map.getZoom();
    if (z >= 17) return 2.5;
    if (z >= 15) return 1.8;
    if (z >= 13) return 1.3;
    return 1.0;
  }

  // How much of a note's callout leader to draw. Full when zoomed in enough
  // for the 32 px offset to be a metre or two; none once it would be a house.
  function noteLead() {
    const z = map.getZoom();
    if (z >= 18) return 1.0;
    if (z >= 16) return 0.5;
    return 0;
  }
  window.tinNoteLead = noteLead;   // closure and slack-loop icons draw the same callout leader

  // Poles are reference markers — cap growth so they don't dominate at high zoom.
  function poleScale() {
    const z = map.getZoom();
    if (z >= 15) return 1.0;
    if (z >= 13) return 0.55;   // z13-14 (assets instead of hexes): ~50 m spans are 4-8 px, so full-size poles merge into a fence
    return 0.85;
  }

  // The container class drives the label font ramp (see map.php) and is the only
  // part of refreshIconSizes that must also run on first paint — refreshIconSizes
  // itself is bound to zoomend, so without this a fresh load at z18 kept the
  // unscaled font until the user touched the zoom.
  function applyZoomScaleClasses(s) {
    const mc = map.getContainer();
    mc.classList.remove('zoom-mid', 'zoom-hi', 'zoom-max');
    if      (s >= 2.5) mc.classList.add('zoom-max');
    else if (s >= 1.8) mc.classList.add('zoom-hi');
    else if (s >= 1.3) mc.classList.add('zoom-mid');
  }

  // Rebuilding ~3900 DivIcons costs the better part of a second, and iconScale()
  // only has two reachable values in the detail regime (1.8 at z15-16, 2.5 above)
  // while poleScale() never changes there at all — so most zoom notches were
  // replacing every marker's DOM element with an identical one. Bail when neither
  // bucket moved. Callers that changed something else pass force = true.
  let _lastIconScale = null;
  let _lastPoleScale = null;
  let _lastNoteLead  = null;

  function refreshIconSizes(force = false) {
    const s  = iconScale();
    const ps = poleScale();
    const nl = noteLead();
    if (!force && s === _lastIconScale && ps === _lastPoleScale && nl === _lastNoteLead) return;
    _lastIconScale = s;
    _lastPoleScale = ps;
    _lastNoteLead  = nl;
    applyZoomScaleClasses(s);

    noteGroup.eachLayer(m => {
      if (m._tinNote === undefined) return;
      m.setIcon(noteIcon(m._tinNote, ps, nl));
    });

    poleGroup.eachLayer(m => {
      if (!m._tinIcon) return;
      const sel = poleSelection.has(m._poleFid);
      m.setIcon(sel ? poleIconSelected(m._tinIcon.owner, poleScale()) : poleIcon(m._tinIcon.owner, poleScale()));
    });
    foscGroup.eachLayer(m => {
      if (!m._tinIcon) return;
      m.setIcon(foscIconForKind(m._tinIcon.kind, m._tinIcon.status,
                                foscDisplayColor(m._tinIcon, m._foscId),
                                m._tinIcon.highlight, s, !!m._tinIcon.damaged,
                                m._tinIcon.angle || 0, m._tinIcon.fanSlot || 0));
      const tt = m.getTooltip();
      if (tt) {
        const isTap = m._tinIcon.kind === 'optitap' || m._tinIcon.kind === 'mst';
        const [dx, dy] = fanLabelOffset(m._tinIcon.fanSlot || 0, m.options.icon.options.iconSize[0]);
        const place = foscLabelPlacement(m, s, isTap, dx, dy);
        tt.options.offset = place.offset;
        tt.options.direction = place.direction;
        tt.update();
      }
    });
    onuGroup.eachLayer(m => {
      if (!m._tinIcon) return;
      m.setIcon(onuIcon(m._tinIcon.color || STATUS_COLORS.plan, s));
    });
    anchorGroup.eachLayer(m => {
      if (!m._tinAnchor) return;
      m.setIcon(anchorIcon(m._tinAnchor.status, poleScale(), m._tinAnchor.props && m._tinAnchor.props.owner));
    });
    uguardGroup.eachLayer(m => {
      if (!m._tinUguard) return;
      m.setIcon(uguardIcon(m._tinUguard.status, poleScale()));
    });

    // setIcon() above replaces each marker's DOM element, discarding the
    // display/opacity that the pole filter applied — re-apply so the active
    // Tinuum-permit (or other) filter survives a zoom in/out.
    if (poleFilterActive()) applyPoleFilter();
    applyFlextinClosureFilter();
  }

  function toolbarIconHtml(divIcon) {
    return (divIcon && divIcon.options && divIcon.options.html) ? divIcon.options.html : '';
  }

  // spanToolbarIconHtml / labelsToolbarIconHtml → see /js/icons/map.icons.js

  // ── Pole dots (zoomed-out band) ─────────────────────────────────────────────────
  // Below POLE_DOT_ZOOM a pole is a 3.5 px dot on one canvas instead of an HTML icon:
  // at zoom 14 that is ~1,400 fewer page elements for Leaflet to create, position and
  // the browser to lay out (the profile put ~0.4 s of a redraw in _setPosition alone).
  // From POLE_DOT_ZOOM up — where you edit — poles are the full icons, unchanged.
  //
  // PoleDot answers the handful of marker methods the rest of the file calls on a
  // pole (setIcon for select/snap highlights, getElement for the pole filter's
  // dim/hide), so selection, snapping and filtering work on either kind.
  const POLE_DOT_ZOOM = 16;
  let polesAsDots = false;
  let poleFeatures = [];
  let poleDotRenderer = null;
  function poleDotStyle(owner, state) {
    if (state === 'sel')  return { radius: 7, fillColor: '#fef08a', color: '#f59e0b', weight: 2.5, fillOpacity: 0.95, opacity: 1 };
    if (state === 'snap') return { radius: 7, fillColor: '#ccfbf1', color: '#0d9488', weight: 2.5, fillOpacity: 0.95, opacity: 1 };
    return { radius: 3.5, fillColor: poleOwnerColor(owner), color: '#5c4220', weight: 1, fillOpacity: 0.95, opacity: 1 };
  }
  const PoleDot = L.CircleMarker.extend({
    setIcon(icon) {                     // the pole icon builders name their state in className
      const c = (icon && icon.options && icon.options.className) || '';
      this._dotState = c.includes('--selected') ? 'sel' : c.includes('--snap') ? 'snap' : '';
      this._paintDot();
      return this;
    },
    _paintDot() {
      const st = poleDotStyle(this._tinIcon && this._tinIcon.owner, this._dotState);
      const v = this._dotVis || {};
      if (v.display === 'none') { st.fillOpacity = 0; st.opacity = 0; }
      else if (v.opacity) { st.fillOpacity = st.opacity = Number(v.opacity); }
      this.setStyle(st);
    },
    // The pole filter dims/hides poles through el.style; forward that to the dot.
    getElement() {
      if (!this._elProxy) {
        const self = this, vis = this._dotVis = this._dotVis || { display: '', opacity: '' };
        this._elProxy = {
          contains: () => false,
          style: { get display() { return vis.display; }, set display(x) { vis.display = x; self._paintDot(); },
                   get opacity() { return vis.opacity; }, set opacity(x) { vis.opacity = x; self._paintDot(); } },
        };
      }
      return this._elProxy;
    },
  });

  // Swap every pole between icon and dot when the zoom crosses POLE_DOT_ZOOM.
  function setPoleDotMode(want) {
    if (want === polesAsDots) return;
    polesAsDots = want;
    poleGroup.clearLayers();
    for (const k in poleMarkerByFid) delete poleMarkerByFid[k];
    poleData = [];
    poleFeatures.forEach(renderPole);
    poleSelection.forEach(fid => {                 // keep the multi-selection highlighted
      const m = poleMarkerByFid[fid];
      if (m) m.setIcon(poleIconSelected(m._tinIcon.owner, poleScale()));
    });
    refreshPoleLabels();
    if (poleFilterActive()) applyPoleFilter();
  }
  const wantPoleDots = () => map.getZoom() < POLE_DOT_ZOOM && !planFraming && !planDetailLock;

  // Dots are not interactive (see poleDotPane), so one map click handler finds the
  // nearest pole dot to the cursor. Clicks that landed on a marker, a line or a popup
  // belong to those, and the draw/route/measure modes keep their own clicks.
  function onMapClickPoleDot(e) {
    if (!polesAsDots || !polesVisible) return;
    if (drawingSpan || addingStrand || routingSpanViaPoles || routingOnuDrop || routingCustDrop ||
        measureActive || dimTool || pickCoordActive || planFraming || rcBoxSelecting) return;
    const t = e.originalEvent && e.originalEvent.target;
    if (t && t.closest && t.closest('.leaflet-marker-icon, .leaflet-popup, .leaflet-control, path.leaflet-interactive')) return;
    let best = null, bd = 9;
    for (const fid in poleMarkerByFid) {
      const m = poleMarkerByFid[fid];
      if (!(m instanceof PoleDot)) continue;
      const pt = map.latLngToContainerPoint(m.getLatLng());
      const d = Math.hypot(pt.x - e.containerPoint.x, pt.y - e.containerPoint.y);
      if (d < bd) { bd = d; best = m; }
    }
    if (!best) return;
    if (e.originalEvent.shiftKey) togglePoleSelect(best._poleFid);
    else best.openPopup();
  }

  function renderPole(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;
    const poleProps = { ...p, lat, lon };

    if (!poleDotRenderer) poleDotRenderer = L.canvas({ pane: 'poleDotPane', padding: 0.3 });
    const marker = polesAsDots
      ? new PoleDot([lat, lon], Object.assign({ renderer: poleDotRenderer, pane: 'poleDotPane', interactive: false, bubblingMouseEvents: true }, poleDotStyle(poleProps.owner, '')))
      : L.marker([lat, lon], { icon: poleIcon(poleProps.owner, poleScale()), draggable: false });
    marker._tinIcon = { owner: poleProps.owner };
    marker._poleFid = poleProps.fid;
    marker._poleProps = poleProps;

    marker.bindPopup(() => buildPolePopup(poleProps), { minWidth: 170 });

    // Stashed rather than bound — refreshPoleLabels() binds it only for poles in
    // view at POLE_LABEL_ZOOM or above. See there for why.
    marker._poleLabel = poleProps.nbp_num || poleProps.telco_num || '';

    poleGroup.addLayer(marker);
    poleMarkerByFid[poleProps.fid] = marker;
    poleData.push({ fid: p.fid, lat, lon });
  }

  // Binding every pole's number as a permanent tooltip put ~2400 extra DOM nodes on
  // the page, all of them animated on every zoom and unreadable below POLE_LABEL_ZOOM
  // anyway. Bind only what's legible and on screen; the bind/unbind sweep costs a
  // couple hundred ms once when crossing the threshold and nothing on other notches.
  // Where to put a pole's number so it clears the strand through that pole: the line is
  // taken as the axis to the (up to) two nearest poles within 150 m, and the label goes
  // to the right of the pole and 14 px off that axis, on the upper or right-hand side.
  // A label beside the pole on a horizontal run sat right on the strand and could not be read.
  function poleLabelOffset(m) {
    const ll = m.getLatLng(), k = Math.cos(ll.lat * Math.PI / 180);
    const near = [];
    for (let i = 0; i < poleData.length; i++) {
      const q = poleData[i];
      const dx = (q.lon - ll.lng) * k, dy = -(q.lat - ll.lat);      // screen-like: y down
      const d = Math.hypot(dx, dy) * 111320;
      if (d > 1 && d < 150) near.push({ d, dx, dy });
    }
    if (!near.length) return [6, 0];
    near.sort((x, y) => x.d - y.d);
    let ax = 0, ay = 0;
    near.slice(0, 2).forEach(n => {
      let ux = n.dx / Math.hypot(n.dx, n.dy), uy = n.dy / Math.hypot(n.dx, n.dy);
      if (ux < 0 || (Math.abs(ux) < 1e-6 && uy < 0)) { ux = -ux; uy = -uy; }  // one direction per axis
      ax += ux; ay += uy;
    });
    const len = Math.hypot(ax, ay) || 1;
    let nx = -ay / len, ny = ax / len;                                // normal to the line
    if (nx < -1e-6 || (Math.abs(nx) < 1e-6 && ny > 0)) { nx = -nx; ny = -ny; }   // right-hand side, else up
    return [Math.round(6 + nx * 14), Math.round(ny * 14)];
  }

  function refreshPoleLabels() {
    const on = map.getZoom() >= POLE_LABEL_ZOOM && polesVisible && !minLabelsMode;
    const vb = on ? map.getBounds().pad(0.2) : null;
    poleGroup.eachLayer(m => {
      if (!m._poleLabel) return;
      const want = on && vb.contains(m.getLatLng());
      const has  = !!m.getTooltip();
      if (want && !has) {
        m.bindTooltip(m._poleLabel, {
          permanent:  true,
          direction:  'right',
          className:  'fosc-label pole-telco-label',
          offset:     poleLabelOffset(m),
        });
      } else if (!want && has) {
        m.unbindTooltip();
      }
    });
    // A fresh tooltip element doesn't carry the display/opacity the filter set.
    if (poleFilterActive()) applyPoleFilter();
  }

  // Leaflet keeps a closing popup's DOM alive through its ~200 ms fade-out, so a
  // quick re-open — the deep-link settle loop does exactly this — leaves two
  // popups in the document carrying the same element ids. document.getElementById
  // then returns the stale one, and the visible popup's buttons get wired to a
  // node that is about to be removed: that is why deep-linked popups opened from
  // the global search rendered fine but had dead Edit/Move/Delete buttons.
  // Look popup elements up inside the popup's own container instead.
  function popupFinder(layer) {
    const popup = layer.getPopup && layer.getPopup();
    const root  = popup ? popup.getElement() : null;
    return function (id) {
      return (root || document).querySelector('#' + CSS.escape(id));
    };
  }

  // One delegated handler instead of one closure per pole marker — this body is
  // ~120 lines and there are ~3400 poles, so binding it per marker allocated the
  // whole chain 3400 times on every render. Registered on the map in init();
  // Leaflet fires map-level popupopen before the source layer's own handlers, which
  // is the order the per-marker version already ran in.
  function onPolePopupOpen(marker) {
      const poleProps = marker._poleProps;
      // In Alden link mode: the next existing-pole click attaches the pending
      // Alden staging pole to it (rather than opening the pole's normal popup).
      if (aldenLinkPoleId) {
        marker.closePopup();
        const poleId = aldenLinkPoleId;
        aldenLinkPoleId = null;
        map.getContainer().style.cursor = '';
        aldenLinkTo(poleId, poleProps.fid);
        return;
      }

      // In add-anchor mode: intercept pole click to create a new anchor on this pole
      if (addAnchorMode) {
        marker.closePopup();
        exitAddAnchorMode();
        openAnchorModal({ pole_fid: poleProps.fid, pole_uid: poleProps.uid, pole_nbp: poleProps.nbp_num }, true);
        return;
      }

      // In add-uguard mode: intercept pole click to create a new u-guard on this pole
      if (addUguardMode) {
        marker.closePopup();
        exitAddUguardMode();
        openUguardModal({ pole_fid: poleProps.fid, pole_uid: poleProps.uid, pole_nbp: poleProps.nbp_num }, true);
        return;
      }

      // In insert-strand mode: split the target strand at this pole
      if (insertStrandMode) {
        marker.closePopup();
        const sid = insertStrandId;
        exitInsertStrandMode();
        (async () => {
          try {
            const r = await fetch(API.strand, {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
              body: JSON.stringify({ id: sid, insert_pole_fid: poleProps.fid }),
            });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Split failed');
            toast('Strand split — pole inserted', 'success');
            await loadFeatures(true);
          } catch (err) {
            toast('Error: ' + err.message, 'danger');
          }
        })();
        return;
      }

      // While drawing a cable span: suppress popup (Geoman captures the click for the line vertex)
      if (drawingSpan) {
        marker.closePopup();
        return;
      }

      // In add-strand mode: add each clicked pole to the path
      if (addingStrand) {
        marker.closePopup();
        const last = strandPath[strandPath.length - 1];
        if (last && last.kind === 'pole' && last.fid === poleProps.fid) return; // ignore duplicate
        strandPath.push({ kind: 'pole', fid: poleProps.fid, uid: poleProps.uid, nbp: poleProps.nbp_num, lat: poleProps.lat, lon: poleProps.lon });
        updateStrandPreviewLine();
        updateStrandPathBar();
        map.getContainer().focus();
        return;
      }

      // In route-span-via-poles mode: add each clicked pole as a waypoint
      if (routingSpanViaPoles) {
        marker.closePopup();
        spanRoutePoles.push({ lat: poleProps.lat, lon: poleProps.lon });
        updateSpanRoutePreview();
        updateSpanRouteBar();
        map.getContainer().focus();
        return;
      }

      // In ONU drop route mode: add each clicked pole as a waypoint
      if (routingOnuDrop) {
        marker.closePopup();
        onuDropRoutePoles.push({ lat: poleProps.lat, lon: poleProps.lon });
        updateOnuDropRoutePreview();
        updateOnuDropRouteBar();
        map.getContainer().focus();
        return;
      }

      // In lead drop route mode: add each clicked pole as a waypoint
      if (routingCustDrop) {
        marker.closePopup();
        custDropRoutePoles.push({ lat: poleProps.lat, lon: poleProps.lon });
        updateCustDropRoutePreview();
        updateCustDropRouteBar();
        map.getContainer().focus();
        return;
      }

      const el = popupFinder(marker);

      // Click the coordinates to copy them — same clipboard guard as the
      // pick-coordinate tool, since writeText throws on an insecure origin or a
      // denied permission and the popup must not die with it.
      const llEl = el('pole-ll-' + poleProps.fid);
      llEl?.addEventListener('click', function () {
        const text = poleProps.lat.toFixed(6) + ', ' + poleProps.lon.toFixed(6);

        // Select the coordinates so Ctrl+C still works when the clipboard API is
        // unavailable or refused, rather than a click that does nothing.
        const selectText = function () {
          const range = document.createRange();
          range.selectNodeContents(llEl);
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        };

        let writing = null;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            writing = navigator.clipboard.writeText(text);
          }
        } catch (_) { writing = null; }

        if (!writing) { selectText(); return; }

        // writeText rejects asynchronously when permission is denied, so the
        // success message has to wait for the promise rather than the call.
        writing.then(function () {
          llEl.textContent = '\u2713 copied';
          setTimeout(function () { llEl.textContent = text; }, 900);
        }).catch(selectText);
      });

      el('edit-pole-' + poleProps.fid)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openPoleModalForEdit(poleProps);
        });
      el('move-pole-' + poleProps.fid)
        ?.addEventListener('click', function () {
          marker.closePopup();
          startMovePole(marker, poleProps);
        });
      el('del-pole-' + poleProps.fid)
        ?.addEventListener('click', function () {
          deletePole(poleProps.fid, poleProps.uid, marker);
        });
      el('uguard-pole-' + poleProps.fid)
        ?.addEventListener('click', function () {
          marker.closePopup();
          const existing = uguardByPoleFid[poleProps.fid];
          if (existing) {
            openUguardModal(existing, false);
          } else {
            openUguardModal({ pole_fid: poleProps.fid, pole_uid: poleProps.uid, pole_nbp: poleProps.nbp_num }, true);
          }
        });
      el('anchor-pole-' + poleProps.fid)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openAnchorModal({ pole_fid: poleProps.fid, pole_uid: poleProps.uid, pole_nbp: poleProps.nbp_num }, true);
        });
  }


  // ── Popup action buttons: one look for Edit, Move, Delete and the rest, on every asset ──
  // Icon plus a short word, so nothing has to be guessed. Handlers find buttons by the id or class
  // passed in `attr`; only the markup lives here.
  const ACT_ICONS = {
    edit:  '<path d="M11.5 1.8l2.7 2.7L5.2 13.5l-3.4.7.7-3.4z"/><path d="M10 3.3l2.7 2.7"/>',
    move:  '<path d="M8 1.5v13M1.5 8h13M8 1.5L6 3.5M8 1.5l2 2M8 14.5l-2-2M8 14.5l2-2M1.5 8l2-2M1.5 8l2 2M14.5 8l-2-2M14.5 8l-2 2"/>',
    del:   '<path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4M6.8 6.5v5M9.2 6.5v5"/>',
    dim:   '<path d="M2 3v10M14 3v10M2 8h12M2 8l2.6-2M2 8l2.6 2M14 8l-2.6-2M14 8l-2.6 2"/>',
    split: '<path d="M1.5 8h4M10.5 8h4M8 2.5v11"/><path d="M5.5 5.5L2.5 8l3 2.5M10.5 5.5L13.5 8l-3 2.5" opacity=".0"/>',
    join:  '<path d="M6 5H4.5a3 3 0 000 6H6M10 5h1.5a3 3 0 010 6H10M5.5 8h5"/>',
    route: '<path d="M8 14.5s4.5-4.6 4.5-8a4.5 4.5 0 00-9 0c0 3.4 4.5 8 4.5 8z"/><circle cx="8" cy="6.5" r="1.6"/>',
    open:  '<path d="M9 2h5v5M14 2L7.5 8.5M12 9.5V14H2V4h4.5"/>',
    add:   '<path d="M8 2.5v11M2.5 8h11"/>',
    save:  '<path d="M2.5 8.5l3.5 3.5 7.5-8"/>',
    plain: '',
  };
  const actIcon = (k) => ACT_ICONS[k] ? `<svg class="tin-act-ico" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ACT_ICONS[k]}</svg>` : '';
  const actBtn = (kind, attr, label, title, cls) =>
    `<button type="button" ${attr} class="tin-act tin-act-${kind}${cls ? ' ' + cls : ''}"${title ? ` title="${esc(title)}"` : ''}>${actIcon(kind)}<span>${label}</span></button>`;
  const actLink = (kind, href, label, title) =>
    `<a href="${href}" class="tin-act tin-act-${kind}"${title ? ` title="${esc(title)}"` : ''}>${actIcon(kind)}<span>${label}</span></a>`;
  // buttons left to right, the destructive one set apart on the right
  const actRow = (buttons, del) =>
    `<div class="tin-acts">${buttons.filter(Boolean).join('')}${del ? `<span class="tin-acts-gap"></span>${del}` : ''}</div>`;

  // Owner mark: Bell and NB Power (their own site icons, images/owners), Tinuum (our mark), Private (P) beside the pole's owner name
  function ownerMark(owner, px) {
    const o = String(owner || '').toLowerCase();
    if (o === 'private') {
      return `<span title="Private pole" style="display:inline-block;width:${px || 18}px;text-align:center;font-size:${Math.round((px || 18) * 0.85)}px;line-height:1;vertical-align:-3px;margin-right:4px">&#127359;&#65039;</span>`;
    }
    const src = o.startsWith('bell') ? '/images/owners/bell.ico'
              : o.startsWith('nb power') ? '/images/owners/nbpower.png'
              : o === OWN_KEY ? OWN_MARK : '';
    return src ? `<img src="${src}" alt="" width="${px || 18}" height="${px || 18}" style="vertical-align:-4px;margin-right:4px;background:#fff;border:1px solid #ced4da;border-radius:3px">` : '';
  }

  function buildPolePopup(p) {
    const mark  = ownerMark(p.owner, 22);
    const uid   = mark + (p.uid ? `<strong>${esc(p.uid)}</strong>` : '<strong>Pole</strong>');
    const type  = p.type  ? `<br><small class="text-muted">${esc(p.type)}</small>`    : '';
    // Owner shows even when blank: it is a required field on Alden One's Add Pole
    // form, so "we don't know" is worth seeing rather than a line that vanishes.
    // Owners with a mark show it beside the title; only Unknown still gets the text line
    const owner = mark ? '' : `<br><small class="text-muted">Owner: ${p.owner ? esc(p.owner) : '&mdash;'}</small>`;
    // Lat/lon transcribes straight into Alden One. 6 dp is ~0.11 m — the extra
    // digits Alden echoes back are noise, not precision.
    const coord = (isFinite(p.lat) && isFinite(p.lon))
      ? `<br><small class="text-muted font-monospace" id="pole-ll-${p.fid}"
             role="button" tabindex="0" title="Click to copy"
             style="cursor:pointer;border-bottom:1px dotted #adb5bd;user-select:text;-webkit-user-select:text"
           >${p.lat.toFixed(6)}, ${p.lon.toFixed(6)}</small>`
      : '';
    const nbp   = p.nbp_num    ? `<br><small class="text-muted">NBP# ${esc(p.nbp_num)}</small>`         : '';
    const bell  = p.telco_num  ? `<br><small class="text-muted">Bell# ${esc(p.telco_num)}</small>`     : '';
    const tags  = p.tags_num   ? `<br><small class="text-muted">Tag# ${esc(p.tags_num)}</small>`       : '';
    const wos   = (p.work_orders && p.work_orders.length)
                    ? `<br><small class="text-muted">Tinuum# ${p.work_orders.map(esc).join(', ')}</small>` : '';
    const bps   = (p.bell_permit_nums && p.bell_permit_nums.length)
                    ? `<br><small class="text-muted">Bell Permit: ${p.bell_permit_nums.map(esc).join(', ')}</small>` : '';
    const note  = p.comment    ? `<br><small class="text-muted">${esc(p.comment)}</small>`             : '';
    const hasUguard = !!uguardByPoleFid[p.fid];
    const uguardBtn = actBtn('plain', `id="uguard-pole-${p.fid}"`, hasUguard ? 'U-Guard' : '+ U-Guard');
    return `<div>
      ${uid}${type}${owner}${coord}${nbp}${bell}${tags}${wos}${bps}${note}
      ${actRow([
        actBtn('edit', `id="edit-pole-${p.fid}"`, 'Edit'),
        actBtn('move', `id="move-pole-${p.fid}"`, 'Move'),
        uguardBtn,
        actBtn('plain', `id="anchor-pole-${p.fid}"`, '+ Anchor'),
      ], actBtn('del', `id="del-pole-${p.fid}"`, 'Delete', 'Delete pole'))}
    </div>`;
  }

  // ── Building anchor rendering ────────────────────────────────────────────────
  // A pole-like change-of-direction attachment on a building. Strands may terminate
  // here (pole↔anchor); it is NOT a cable endpoint. Distinct from the guy-wire
  // app.anchor (⚓) — this uses a building glyph.
  function buildingAnchorIcon(scale, status) {
    // Tinuum-owned, so Tinuum green; hollow and dashed until built (same recipe as every symbol).
    return symbolIcon('house', 'mast', '#198754', status === 'plan', Math.round(22 * (scale || 1)), 'building-anchor-icon');
  }

  function buildBuildingAnchorPopup(p) {
    const name   = p.name ? `<strong>${esc(p.name)}</strong>` : '<strong>Building Anchor</strong>';
    const status = p.status ? `<br><small class="text-muted">${esc(p.status)}</small>` : '';
    const wor    = p.work_order ? `<br><small class="text-muted">Tinuum# ${esc(p.work_order)}</small>` : '';
    const note   = p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';
    return `<div>
      ${name}${status}${wor}${note}
      ${actRow([
        actBtn('edit', `id="edit-banchor-${p.id}"`, 'Edit'),
        actBtn('move', `id="move-banchor-${p.id}"`, 'Move'),
      ], actBtn('del', `id="del-banchor-${p.id}"`, 'Delete', 'Delete building anchor'))}
    </div>`;
  }

  function renderBuildingAnchor(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = { ...feature.properties, lat, lon };

    const marker = L.marker([lat, lon], { icon: buildingAnchorIcon(poleScale(), p.status), draggable: false });
    marker._banchorId    = p.id;
    marker._banchorProps = p;
    marker.bindPopup(() => buildBuildingAnchorPopup(p), { minWidth: 170 });
    if (p.name) {
      marker.bindTooltip(p.name, { permanent: true, direction: 'right', className: 'fosc-label pole-telco-label', offset: [6, 0] });
    }

    marker.on('popupopen', function () {
      // While building a strand path: add this anchor as an endpoint node.
      if (addingStrand) {
        marker.closePopup();
        const last = strandPath[strandPath.length - 1];
        if (last && last.kind === 'anchor' && last.id === p.id) return; // ignore duplicate
        strandPath.push({ kind: 'anchor', id: p.id, label: p.name || ('anchor ' + p.id), lat, lon });
        updateStrandPreviewLine();
        updateStrandPathBar();
        map.getContainer().focus();
        return;
      }
      // A building anchor is a change-of-direction point, so it can serve as a
      // geometry waypoint when routing a cable span (or a drop) — click it mid-route
      // to bend the line through the building before continuing to the closure.
      if (routingSpanViaPoles) {
        marker.closePopup();
        spanRoutePoles.push({ lat, lon });
        updateSpanRoutePreview();
        updateSpanRouteBar();
        map.getContainer().focus();
        return;
      }
      if (routingOnuDrop) {
        marker.closePopup();
        onuDropRoutePoles.push({ lat, lon });
        updateOnuDropRoutePreview();
        updateOnuDropRouteBar();
        map.getContainer().focus();
        return;
      }
      if (routingCustDrop) {
        marker.closePopup();
        custDropRoutePoles.push({ lat, lon });
        updateCustDropRoutePreview();
        updateCustDropRouteBar();
        map.getContainer().focus();
        return;
      }
      document.getElementById('edit-banchor-' + p.id)
        ?.addEventListener('click', function () { marker.closePopup(); openBuildingAnchorModalForEdit(p); });
      document.getElementById('move-banchor-' + p.id)
        ?.addEventListener('click', function () { marker.closePopup(); startMoveBuildingAnchor(marker, p); });
      document.getElementById('del-banchor-' + p.id)
        ?.addEventListener('click', function () { deleteBuildingAnchor(p.id, p.name, marker); });
    });

    buildingAnchorGroup.addLayer(marker);
    buildingAnchorMarkerById[p.id] = marker;
  }

  // ── FOSC rendering ───────────────────────────────────────────────────────────
  // Use L.Marker with a DivIcon circle so Leaflet's native .dragging works.
  // foscIcon / optitapIcon / mstIcon / cabinetIcon / slackLoopIcon / foscIconForKind
  // → see /js/icons/map.icons.js

  function renderFosc(feature, bounds, slackAngles = {}, fanSlots = {}) {
    const [lon, lat] = feature.geometry.coordinates;
    const p          = feature.properties;
    const color      = STATUS_COLORS[p.status] || '#0d6efd';

    const isTap   = p.kind === 'optitap' || p.kind === 'mst';
    const isSlack = p.kind === 'slack';
    const s = iconScale();
    // Lives on _tinIcon because that is what every rebuild path already reads from
    // (refreshIconSizes, the move flow, the snap highlights) — so the glyph keeps
    // its bearing without any of them needing to know where the angle came from.
    const angle = isTap ? 0 : (slackAngles[p.fosc_id] ?? 0);   // cable direction: closures and slack loops hang their callout at right angles to it
    // Same reasoning as `angle`: the fan slot rides on _tinIcon so every rebuild
    // path (refreshIconSizes, the live-optitap recolour) keeps the icon in place.
    const fanSlot = fanSlots[p.fosc_id] || 0;
    const ti = { kind: p.kind, status: p.status, color, highlight: false, damaged: !!p.damaged, angle, fanSlot };
    const marker = L.marker([lat, lon], {
      icon:      foscIconForKind(p.kind, p.status, foscDisplayColor(ti, p.fosc_id), false, s, !!p.damaged, angle, fanSlot),
      draggable: false,
    });
    marker._tinIcon  = ti;
    marker._foscId   = p.fosc_id;
    marker._spliceNum = p.splice_num;

    marker.bindPopup(() => buildFoscPopup(p), { minWidth: 200 });
    const baseW = isTap ? 5 : 16;
    // A tooltip hangs off the marker's latlng, not off iconAnchor, so a fanned
    // icon would leave its label behind on the stack. Shift it by the same amount,
    // and step it down by one row as well: the icons are only an icon-width apart
    // but "L10" is wider than that, so a purely horizontal fan still prints the
    // numbers on top of each other. Cascading reads left-to-right, top-to-bottom.
    const [fanDx, fanDy] = fanLabelOffset(fanSlot, marker.options.icon.options.iconSize[0]);
    marker.bindTooltip(p.splice_num, {
      permanent:  true,
      direction:  foscLabelPlacement(marker, s, isTap, fanDx, fanDy).direction,
      className:  isTap ? 'fosc-label fosc-label--tap' : isSlack ? 'fosc-label fosc-label--slack' : 'fosc-label',
      offset:     foscLabelPlacement(marker, s, isTap, fanDx, fanDy).offset,
    });

    // Wire up buttons once the popup opens
    marker.on('popupopen', function () {
      const el = popupFinder(marker);
      fillFoscNeighbours(p.fosc_id, el);
      el('edit-fosc-' + p.fosc_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openEditFoscModal(p);
        });
      el('move-fosc-' + p.fosc_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          startMoveFosc(marker, p, color);
        });
      el('del-fosc-' + p.fosc_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          deleteFosc(p.fosc_id, p.splice_num, marker);
        });
    });

    marker._tinFosc = p; // props (incl. fpm_wor) for the pole/WO filter
    marker._tinFlextinTap = isTap;                           // for the Optitaps toggle
    foscGroup.addLayer(marker);
    bounds.push([lat, lon]);
    foscData.push({ fosc_id: p.fosc_id, splice_num: p.splice_num, lat, lon, kind: p.kind });
  }

  // ── POP rendering (office building) ───────────────────────────────────────────

  function buildPopPopup(p) {
    const memberLine = p.member_ct
      ? `<br><small class="text-muted">${p.member_ct} closure${p.member_ct === 1 ? '' : 's'}` +
        (p.device_ct ? ` · ${p.device_ct} device${p.device_ct === 1 ? '' : 's'}` : '') +
        `</small>` +
        (p.member_names ? `<br><small class="text-muted">${esc(p.member_names)}</small>` : '')
      : `<br><small class="text-muted">No member closures yet</small>`;
    const notesLine = p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';

    return `<div><strong>${esc(p.name)}</strong> <span class="badge bg-secondary">POP</span>` +
      `${memberLine}${notesLine}` +
      tlBox('pop', p.pop_id) +
      actRow([
        actBtn('edit', `data-pop-id="${p.pop_id}"`, 'Edit', '', 'pop-edit-link'),
        actBtn('move', `data-pop-id="${p.pop_id}"`, 'Move', '', 'pop-move-link'),
        actLink('open', `index.php?page=pop_detail&pop_id=${p.pop_id}`, 'Open'),
      ], actBtn('del', `data-pop-id="${p.pop_id}"`, 'Delete', 'Delete this POP (only when it has no closures, devices or links)', 'pop-delete-link')) +
      `</div>`;
  }

  async function deletePop(p) {
    if (!(await confirmDeleteAsset('pop', p.pop_id, 'Delete POP ' + p.name + '?\n\nThis only works when it has no closures, devices or logical links.'))) return;
    try {
      const data = await (await apiDelete(API.pop + '?id=' + p.pop_id)).json();
      if (!data.ok) throw new Error(data.error || 'delete failed');
      toast('POP deleted', 'success');
      loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  }

  function renderPop(feature, bounds) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    const p          = feature.properties;
    const s          = iconScale();

    const marker = L.marker([lat, lon], {
      icon:      popIcon(false, s),
      draggable: false,
    });
    marker._tinPop = { pop_id: p.pop_id, name: p.name, highlight: false };

    marker.bindPopup(() => buildPopPopup(p), { minWidth: 200 });

    // The label goes above the building icon. A POP usually shares its spot with a cabinet or closure, and
    // that one's label sits to the right of the point, so a right-hand POP label printed over it.
    marker.bindTooltip(p.name, {
      permanent: true,
      direction: 'top',
      className: 'fosc-label',
      offset:    [0, -Math.round(26 * s) - 2],
    });

    marker.on('popupopen', function () {
      document.querySelector(`.pop-move-link[data-pop-id="${p.pop_id}"]`)
        ?.addEventListener('click', function () {
          marker.closePopup();
          startMovePop(marker, p);
        });
      document.querySelector(`.pop-edit-link[data-pop-id="${p.pop_id}"]`)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openPopModalForEdit(p);
        });
      document.querySelector(`.pop-delete-link[data-pop-id="${p.pop_id}"]`)
        ?.addEventListener('click', function () {
          marker.closePopup();
          deletePop(p);
        });
    });

    popGroup.addLayer(marker);
    bounds.push([lat, lon]);
  }

  function startMovePop(marker, p) {
    cancelActiveEdit();

    const makeIcon = (hl) => popIcon(hl, iconScale());
    marker._tinPop.highlight = true;
    marker.setIcon(makeIcon(true));
    map.getContainer().style.cursor = 'grab';
    marker.dragging.enable();

    const cancel = function () {
      marker.dragging.disable();
      marker._tinPop.highlight = false;
      marker.setIcon(makeIcon(false));
      map.getContainer().style.cursor = '';
    };

    activeEdit = { type: 'pop', layer: marker, cancel };

    showEditBar(
      '🏢 Moving <strong>' + esc(p.name) + '</strong> — drag to new position',
      null,
      function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); }
    );

    marker.once('dragend', function () {
      const ll = marker.getLatLng();
      cancel();
      popGroup.removeLayer(marker);
      hideEditBar();
      activeEdit = null;
      movePop(p.pop_id, ll);
    });
  }

  async function movePop(popId, latlng) {
    toast('Saving…', 'secondary');
    try {
      const resp = await apiPut(API.pop + '?id=' + encodeURIComponent(popId), {
        geojson: { type: 'Point', coordinates: [latlng.lng, latlng.lat] },
      });
      const data = await resp.json();
      data.ok ? toast('POP location saved', 'success')
              : toast('Save failed: ' + data.error, 'danger');
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    } finally {
      loadFeatures(true);
    }
  }

  // ── POP create / place / edit ────────────────────────────────────────────────

  let _pendingPopLatLng = null;

  function startPlacePop() {
    cancelActiveEdit();
    pendingDrawMode = 'pop';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      '\u{1F3E2} Click on the map (on the building) to place the POP',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  function openPopModalForCreate(latlng) {
    _pendingPopLatLng = latlng;
    document.getElementById('popModalLabel').textContent = 'New POP';
    document.getElementById('popId').value    = '';
    document.getElementById('popName').value  = '';
    document.getElementById('popNotes').value = '';
    document.getElementById('popCoord').textContent =
      `${latlng.lat.toFixed(6)}, ${latlng.lng.toFixed(6)}`;
    document.getElementById('popExistingWrap').style.display = '';
    showErr(document.getElementById('popModalError'), '');
    loadUnplacedPops();
    syncPopModalMode();
    new bootstrap.Modal(document.getElementById('popModal')).show();
  }

  function openPopModalForEdit(p) {
    _pendingPopLatLng = null;   // editing props only — never touch the location
    document.getElementById('popModalLabel').textContent = 'Edit POP';
    document.getElementById('popId').value    = String(p.pop_id);
    document.getElementById('popName').value  = p.name  || '';
    document.getElementById('popNotes').value = p.notes || '';
    document.getElementById('popCoord').textContent =
      `${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}`;
    document.getElementById('popExistingWrap').style.display = 'none';
    showErr(document.getElementById('popModalError'), '');
    syncPopModalMode();
    new bootstrap.Modal(document.getElementById('popModal')).show();
  }

  // POPs created on the catalog page without coordinates are invisible on the map;
  // offering them here is the only way to give them a location by clicking.
  async function loadUnplacedPops() {
    const sel = document.getElementById('popExisting');
    if (!sel) return;
    sel.innerHTML = '<option value="0">— create a new POP —</option>';
    document.getElementById('popExistingHint').style.display = 'none';
    try {
      const resp = await fetch(API.pop + '?unplaced=1');
      const data = await resp.json();
      if (!data.ok || !data.pops.length) return;
      data.pops.forEach(function (p) {
        const opt = document.createElement('option');
        opt.value       = String(p.pop_id);
        opt.textContent = p.name;
        sel.appendChild(opt);
      });
      document.getElementById('popExistingHint').style.display = '';
    } catch (e) {
      // A failed picker fetch still leaves "create new" usable — don't block the modal.
    }
  }

  // Name/notes belong to the chosen POP when placing an existing one, so the
  // fields go read-only rather than pretending they'd be saved.
  function syncPopModalMode() {
    const sel    = document.getElementById('popExisting');
    const wrapOn = document.getElementById('popExistingWrap').style.display !== 'none';
    const lock   = wrapOn && sel.value !== '0';
    document.getElementById('popName').disabled  = lock;
    document.getElementById('popNotes').disabled = lock;
    if (lock) {
      document.getElementById('popName').value  = sel.options[sel.selectedIndex].textContent;
      document.getElementById('popNotes').value = '';
    } else if (wrapOn) {
      // Back to "create new" — drop the borrowed name rather than pre-filling a
      // name that already exists.
      document.getElementById('popName').value  = '';
      document.getElementById('popNotes').value = '';
    }
  }

  async function savePop() {
    const errEl    = document.getElementById('popModalError');
    const id       = (document.getElementById('popId').value || '').trim();
    const name     = (document.getElementById('popName').value || '').trim();
    const notes    = (document.getElementById('popNotes').value || '').trim();
    const wrapOn   = document.getElementById('popExistingWrap').style.display !== 'none';
    const existing = wrapOn ? (document.getElementById('popExisting').value || '0') : '0';
    showErr(errEl, '');

    const ll = _pendingPopLatLng;
    if (!id && (!ll || !isFinite(ll.lat) || !isFinite(ll.lng))) {
      showErr(errEl, 'No location provided.');
      return;
    }
    if (existing === '0' && name === '') {
      showErr(errEl, 'POP name is required.');
      return;
    }

    const geojson = ll ? { type: 'Point', coordinates: [ll.lng, ll.lat] } : null;
    try {
      let resp;
      if (existing !== '0') {
        // Place an existing POP: geojson only, so its name/notes are untouched.
        resp = await apiPut(API.pop + '?id=' + encodeURIComponent(existing), { geojson });
      } else if (id) {
        // Edit: props only, so the location is untouched.
        resp = await apiPut(API.pop + '?id=' + encodeURIComponent(id), { name, notes });
      } else {
        resp = await apiPost(API.pop, { name, notes, geojson });
      }
      const data = await resp.json();
      if (!data.ok) { showErr(errEl, data.error || 'Unknown error'); return; }

      bootstrap.Modal.getInstance(document.getElementById('popModal')).hide();
      if (data.pop_id && !id && existing === '0') addPopToClosureSelect(data.pop_id, data.name);
      // A saved POP that lands on a hidden layer looks like nothing happened.
      if (!popsVisible && !id) togglePops();
      toast(id ? 'POP updated' : (existing !== '0' ? 'POP placed' : 'POP created'), 'success');
      loadFeatures(true);
    } catch (e) {
      showErr(errEl, 'Error: ' + e.message);
    }
  }

  // The Edit-Closure modal's POP list is server-rendered at page load, so a POP
  // created here would be unassignable until a reload without this.
  function addPopToClosureSelect(popId, name) {
    const sel = document.getElementById('editFoscPopId');
    if (!sel || sel.querySelector(`option[value="${popId}"]`)) return;
    const opt = document.createElement('option');
    opt.value       = String(popId);
    opt.textContent = name;
    sel.appendChild(opt);
  }

  // ── Span rendering ───────────────────────────────────────────────────────────

  // Returns [deltaLat, deltaLon] in degrees for the given compass direction.
  // Offset is ~8 m — close enough to read as "attached to this pole" but no overlap.
  const ANCHOR_OFFSET_DEG = 0.000027; // ~3 m
  const COMPASS_OFFSET = {
    N:   [ 1,    0   ],
    NNE: [ 0.92, 0.38],
    NE:  [ 0.71, 0.71],
    ENE: [ 0.38, 0.92],
    E:   [ 0,    1   ],
    ESE: [-0.38, 0.92],
    SE:  [-0.71, 0.71],
    SSE: [-0.92, 0.38],
    S:   [-1,    0   ],
    SSW: [-0.92,-0.38],
    SW:  [-0.71,-0.71],
    WSW: [-0.38,-0.92],
    W:   [ 0,   -1   ],
    WNW: [ 0.38,-0.92],
    NW:  [ 0.71,-0.71],
    NNW: [ 0.92,-0.38],
  };

  function anchorLatLon(poleLat, poleLon, direction) {
    const d = COMPASS_OFFSET[direction];
    if (!d) return [poleLat, poleLon];
    return [
      poleLat + d[0] * ANCHOR_OFFSET_DEG,
      poleLon + d[1] * ANCHOR_OFFSET_DEG,
    ];
  }

  // Shared by the anchor and u-guard popups — both hang off a pole and label it
  // the same way.
  function poleRefLabel(p) {
    return p.pole_uid ? `Pole ${esc(p.pole_uid)}`
         : (p.pole_nbp ? `NBP# ${esc(p.pole_nbp)}` : `Pole fid ${p.pole_fid}`);
  }

  function buildAnchorPopup(p) {
    const statusLabel = p.status ? `<span class="badge bg-secondary">${esc(p.status)}</span>` : '';
    const ownerLine  = p.owner     ? `<br>${ownerMark(p.owner, 16)}<small class="text-muted">${esc(p.owner)}</small>` : '';
    const dirLine    = p.direction ? `<br><small class="text-muted">Direction: ${esc(p.direction)}</small>` : '';
    const notesLine  = p.notes     ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';

    return `<div><strong>Anchor</strong> ${statusLabel}<br>` +
      `<small class="text-muted">on ${poleRefLabel(p)}</small>` +
      `${ownerLine}${dirLine}${notesLine}` +
      actRow([actBtn('edit', `data-anchor-id="${p.id}"`, 'Edit', 'Edit or delete this anchor', 'anchor-edit-link')]) + `</div>`;
  }

  function buildUguardPopup(p) {
    const statusLabel = p.status ? `<span class="badge bg-secondary">${esc(p.status)}</span>` : '';
    const woLine      = (p.work_orders && p.work_orders.length)
      ? `<br><small class="text-muted"><strong>WO:</strong> ${p.work_orders.map(esc).join(', ')}</small>`
      : (p.fpm_wor ? `<br><small class="text-muted"><strong>WO:</strong> ${esc(p.fpm_wor)}</small>` : '');
    const notesLine   = p.notes  ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';

    return `<div><strong>U-Guard</strong> ${statusLabel}<br>` +
      `<small class="text-muted">on ${poleRefLabel(p)}</small>` +
      `${woLine}${notesLine}` +
      actRow([
        actBtn('edit', `data-uguard-id="${p.id}"`, 'Edit', '', 'uguard-edit-link'),
        actBtn('move', `data-uguard-id="${p.id}"`, 'Move', 'Drag it onto another pole', 'uguard-move-link'),
      ], actBtn('del', `data-uguard-id="${p.id}"`, 'Delete', 'Delete this u-guard', 'uguard-delete-link')) + `</div>`;
  }

  function renderAnchor(feature) {
    if (!feature.geometry) return;
    const coords = feature.geometry.coordinates;
    const poleLat = coords[1], poleLon = coords[0];
    const p = feature.properties;
    const [lat, lon] = anchorLatLon(poleLat, poleLon, p.direction);

    const marker = L.marker([lat, lon], {
      icon: anchorIcon(p.status, poleScale(), p.owner),
      zIndexOffset: -100,
    });

    marker._tinAnchor = { status: p.status, props: p };

    marker.bindPopup(() => buildAnchorPopup(p), { maxWidth: 220 });

    marker.on('popupopen', function () {
      document.querySelector(`.anchor-edit-link[data-anchor-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          marker.closePopup();
          openAnchorModal(p, false);
        });
    });

    anchorGroup.addLayer(marker);
  }

  // Drag a u-guard onto another pole; letting go anywhere else puts it back.
  function startMoveUguard(marker, p) {
    cancelActiveEdit();
    marker.dragging.enable();
    map.getContainer().style.cursor = 'grab';
    const cancel = function () { marker.dragging.disable(); map.getContainer().style.cursor = ''; };
    activeEdit = { type: 'uguard', layer: marker, cancel };
    showEditBar('Moving the u-guard: drag it onto the pole it should be on',
      null, function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); });
    marker.once('dragend', async function () {
      const drop = map.latLngToContainerPoint(marker.getLatLng());
      let best = null, bestD = 34;
      Object.entries(poleMarkerByFid).forEach(([fid, m]) => {
        if (Number(fid) === p.pole_fid || !m.getLatLng) return;
        const d = drop.distanceTo(map.latLngToContainerPoint(m.getLatLng()));
        if (d < bestD) { bestD = d; best = Number(fid); }
      });
      cancel(); hideEditBar(); activeEdit = null;
      if (best === null) { toast('Drop it on a pole to move it there', 'warning'); loadFeatures(true); return; }
      try {
        const data = await (await fetch(API.uguard, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
          body: JSON.stringify({ id: p.id, pole_fid: best }) })).json();
        if (!data.ok) throw new Error(data.error || 'Move failed');
        toast('U-guard moved', 'success');
      } catch (e) { toast(e.message, 'danger'); }
      loadFeatures(true);
    });
  }

  function renderUguard(feature) {
    if (!feature.geometry) return;
    const coords = feature.geometry.coordinates;
    const poleLat = coords[1], poleLon = coords[0];
    const p = feature.properties;

    // Same offset distance as anchors, fixed S direction — anchor zIndex takes priority
    const lat = poleLat - ANCHOR_OFFSET_DEG;
    const lon = poleLon;

    const marker = L.marker([lat, lon], {
      icon: uguardIcon(p.status, poleScale()),
      zIndexOffset: -200,  // below anchors (-100) so anchors always render on top
    });

    marker._tinUguard = { status: p.status, props: p };
    uguardByPoleFid[p.pole_fid] = p;

    marker.bindPopup(() => buildUguardPopup(p), { maxWidth: 200 });

    marker.on('popupopen', function () {
      document.querySelector(`.uguard-edit-link[data-uguard-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          marker.closePopup();
          openUguardModal(p, false);
        });
      document.querySelector(`.uguard-delete-link[data-uguard-id="${p.id}"]`)
        ?.addEventListener('click', async function () {
          marker.closePopup();
          if (!confirm('Delete this u-guard?')) return;
          try {
            const data = await (await fetch(API.uguard, { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf }, body: JSON.stringify({ id: p.id }) })).json();
            if (!data.ok) throw new Error(data.error || 'Delete failed');
            toast('U-guard deleted', 'success'); loadFeatures(true);
          } catch (e) { toast(e.message, 'danger'); }
        });
      document.querySelector(`.uguard-move-link[data-uguard-id="${p.id}"]`)
        ?.addEventListener('click', function () { marker.closePopup(); startMoveUguard(marker, p); });
    });

    uguardGroup.addLayer(marker);
  }

  /**
   * Convert a straight [lat,lng] polyline into a wavy one by generating
   * sinusoidal intermediate points in pixel space, then unprojecting back.
   * amplitude: px swing either side; cycles: full waves per segment.
   *
   * wavelengthPx (optional): when set, the wave count is derived from each
   * segment's on-screen length instead of `cycles`, so the ripple keeps a
   * constant pitch whether the span is 20 m or 200 m. Without it a short span
   * and a long one both get `cycles` waves and read as different patterns.
   * It is a pitch for the FULL amplitude — wherever the amplitude is capped
   * below that (see below), the pitch is scaled down with it.
   */
  function wavyCoords(coords, amplitude = 5, cycles = 10, wavelengthPx = null) {
    const result = [];
    for (let s = 0; s < coords.length - 1; s++) {
      const a = map.latLngToLayerPoint(L.latLng(coords[s]));
      const b = map.latLngToLayerPoint(L.latLng(coords[s + 1]));
      const dx = b.x - a.x, dy = b.y - a.y;
      const len = Math.sqrt(dx * dx + dy * dy);
      if (len < 1) { result.push(coords[s]); continue; }
      const ux = dx / len, uy = dy / len;   // along segment
      const px = -uy, py = ux;              // perpendicular
      // Cap the amplitude to a fraction of the segment's on-screen length. The
      // wiggle is recomputed at every zoom (refreshZoomGeometry), so a fixed
      // amplitude sits on ever-shorter segments as you zoom out and balloons
      // relative to the strand. Tying it to segment length lets the wiggle shrink
      // smoothly with zoom instead of jumping.
      const amp = Math.min(amplitude, len * 0.18);
      // Pitch follows the amplitude that was ACTUALLY used, not the one asked for.
      // Otherwise the cap above shrinks the swing while the pitch stays put, and
      // the wave flattens into a limp ripple as you zoom out — different aspect
      // ratio at every zoom, and a different one again on the next strand along,
      // since each is capped by its own length. Scaling both together keeps one
      // shape that simply gets smaller.
      const wl = wavelengthPx ? Math.max(4, wavelengthPx * (amp / amplitude)) : null;
      // Quantised to HALF cycles, not whole ones. A sine over any multiple of half
      // a wave still lands back on the route at both ends — the reason for
      // quantising at all — but the pitch can now land within 25% of the target
      // instead of 50%. Whole-cycle rounding is what made one span read as a single
      // stretched bump while the next one along rippled: at 1–2 cycles the rounding
      // error IS the shape.
      const segCycles = wl
        ? Math.max(0.5, Math.round(2 * len / wl) / 2)
        : cycles;
      // ≥10 samples per wave so the higher-frequency ripple stays smooth, not polygonal.
      const steps = Math.max(24, Math.ceil(segCycles * 10), Math.round(len / 2));
      for (let i = 0; i <= steps; i++) {
        const t    = i / steps;
        const wave = amp * Math.sin(t * 2 * Math.PI * segCycles);
        result.push(map.layerPointToLatLng(
          L.point(a.x + t * dx + wave * px, a.y + t * dy + wave * py)
        ));
      }
    }
    result.push(coords[coords.length - 1]);
    return result;
  }

  /**
   * Lashing-tick decoration for overlash strands: short diagonal ticks crossing
   * the line at a constant on-screen pitch — the same symbol the permit strip
   * diagram draws (strip_segment_svg(), 'overlash' branch in permit_strip.php).
   * Overlash is a cable lashed onto an existing one, and the ticks are the
   * lashing wire; the wiggle is reserved for slack loops.
   *
   * Returns an array of 2-point latlng pairs — L.polyline renders nested arrays
   * as N disconnected segments in ONE layer, which is how we draw ticks without
   * a decorator plugin (none is vendored).
   *
   * Built in pixel space from the STRAIGHT route, so pitch and length are
   * zoom-stable (refreshZoomGeometry re-runs this on zoom).
   *
   *   halfHeightPx — perpendicular half-extent; must exceed the casing's half
   *                  width so the tick visibly crosses the black cable outline
   *   pitchPx      — centre-to-centre tick spacing
   *   slant        — along-axis half-extent as a fraction of halfHeightPx
   *                  (2.5/4 in the strip diagram ⇒ same apparent angle)
   */
  function lashTicks(coords, halfHeightPx = 5, pitchPx = 14, slant = 0.625) {
    const out = [];
    const alongHalf = halfHeightPx * slant;
    for (let s = 0; s < coords.length - 1; s++) {
      const a = map.latLngToLayerPoint(L.latLng(coords[s]));
      const b = map.latLngToLayerPoint(L.latLng(coords[s + 1]));
      const dx = b.x - a.x, dy = b.y - a.y;
      const len = Math.sqrt(dx * dx + dy * dy);
      if (len < 10) continue;                        // no room for even one tick
      // Normalise lean direction so ticks slant the same way on screen whichever
      // order the strand happened to be digitised in.
      const flip = (dx < 0 || (dx === 0 && dy < 0)) ? -1 : 1;
      const ux = (dx / len) * flip, uy = (dy / len) * flip;
      const px = -uy, py = ux;                       // perpendicular
      const n     = Math.max(1, Math.floor((len - 2 * pitchPx * 0.4) / pitchPx) + 1);
      const start = (len - (n - 1) * pitchPx) / 2;   // symmetric about midpoint
      for (let k = 0; k < n; k++) {
        const d  = start + k * pitchPx;
        // Step along with the UNflipped direction; flip only tilts the tick.
        const cx = a.x + (dx / len) * d, cy = a.y + (dy / len) * d;
        out.push([
          map.layerPointToLatLng(L.point(cx - alongHalf * ux - halfHeightPx * px,
                                         cy - alongHalf * uy - halfHeightPx * py)),
          map.layerPointToLatLng(L.point(cx + alongHalf * ux + halfHeightPx * px,
                                         cy + alongHalf * uy + halfHeightPx * py)),
        ]);
      }
    }
    return out;
  }

  function buildStrandPopup(p, coords, chainLengthM) {
    const isOverlash = !!p.is_overlash;
    const isDup      = !!p.dup_strand;

    const fromLabel = p.from_pole_uid || (p.from_pole_nbp ? 'NBP# ' + p.from_pole_nbp : 'fid ' + p.from_pole_fid);
    const toLabel   = p.to_pole_uid   || (p.to_pole_nbp   ? 'NBP# ' + p.to_pole_nbp   : 'fid ' + p.to_pole_fid);
    const posLabel  = (side, height) => `${height} / ${side} side`;

    const woLine = (p.work_orders && p.work_orders.length)
      ? `<br><small class="text-muted"><strong>WO:</strong> ${p.work_orders.map(esc).join(', ')}</small>`
      : '';
    const typeLabel = isOverlash
      ? `<span class="badge ms-1" style="background:#005EB8">Overlash</span>`
      : `<span class="badge bg-secondary ms-1">${esc(p.status)}</span>`;
    const tensionBadge = (p.tension && p.tension !== 'normal')
      ? `<span class="badge bg-warning text-dark ms-1">Slack Span</span>`
      : '';
    const dupBadge = isDup ? `<span class="badge bg-danger ms-1">Duplicate</span>` : '';
    // List the other same-kind strand IDs sharing this pole pair so it can be cleaned up.
    const dupWarn = isDup
      ? `<br><small class="text-danger"><strong>⚠ Duplicate strand:</strong> ` +
        `${(isOverlash ? 'overlashes' : 'strands')} #${(p.dup_strand_ids || []).map(esc).join(', #')} ` +
        `run between the same two poles — only one of each kind should exist.</small>`
      : '';
    const lengthM = coords.length >= 2
      ? Math.round(L.latLng(coords[0]).distanceTo(L.latLng(coords[coords.length - 1])))
      : null;
    const segLabel   = lengthM !== null ? `<span class="text-muted small ms-2">${lengthM} m</span>` : '';
    const chainLabel = (chainLengthM != null && chainLengthM !== lengthM)
      ? `<span class="text-muted small ms-2">(${chainLengthM} m total)</span>` : '';
    const lengthLabel = segLabel + chainLabel;

    return `<div>` +
      `<strong>${isOverlash ? 'Overlash' : 'Messenger Strand'}</strong> ${typeLabel}${tensionBadge}${dupBadge}${lengthLabel}<br>` +
      `<small class="text-muted">` +
        `<strong>From:</strong> ${esc(fromLabel)} — ${esc(posLabel(p.from_side, p.from_height))}<br>` +
        `<strong>To:</strong> ${esc(toLabel)} — ${esc(posLabel(p.to_side, p.to_height))}` +
      `</small>` +
      dupWarn +
      woLine +
      `${p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : ''}` +
      actRow([
        actBtn('edit', `data-strand-id="${p.id}"`, 'Edit', '', 'strand-edit-link'),
        actBtn('plain', `data-strand-id="${p.id}"`, '+ Pole', 'Insert a pole in this strand', 'strand-insert-link'),
        actBtn('dim', `data-strand-id="${p.id}"`, 'Dimension', 'Save this pole-to-pole distance as a dimension on the plan', 'strand-dim-link'),
      ], actBtn('del', `data-strand-id="${p.id}"`, 'Delete', 'Delete strand', 'strand-delete-link')) +
      `</div>`;
  }

  function renderStrand(feature, chainLengthM) {
    if (!feature.geometry) return;
    const p      = feature.properties;
    const coords = feature.geometry.coordinates.map(c => [c[1], c[0]]);
    const isOverlash = !!p.is_overlash;
    const isSlack    = p.tension && p.tension !== 'normal';
    // Data error: 2+ strands of the SAME kind between the same pole pair (server
    // sets dup_strand + dup_strand_ids). Draw the stripe warning-red and dashed so
    // it's impossible to miss; the popup lists the colliding strand IDs.
    const isDup      = !!p.dup_strand;
    // Bell overlash → blue; Tinuum messenger strand → Tinuum-logo green.
    const color   = isDup ? '#dc2626' : (isOverlash ? '#005EB8' : '#5EB030');
    // If an aerial span runs alongside this strand (a lashed cable follows the same
    // pole route, though often drawn a few metres off the pole line), build a single
    // cased line ON the strand (the authoritative pole route): black span casing →
    // green strand centre stripe. The offset span copy is dimmed elsewhere.
    // Conduit is NOT part of this hybrid — it is drawn at its own geometry (see
    // renderConduit) because it has no relationship to the pole line.
    const nearSpan    = !!p.has_span;      // computed server-side (zoom-stable)
    const hybrid      = nearSpan;
    // Everything solid (colour + width encode type). Strand is a thin centre stripe
    // when cased, a thin line on its own; the black span casing carries the fibre-count width.
    const weight  = hybrid ? 2 : 3;
    const opacity = 0.95;

    // Casing layer beneath the strand stripe (added first ⇒ underneath, non-interactive):
    // black span casing sized by fibre count.
    const spanCW    = spanWeight(p.span_fibre_count || 48);

    // Slack deforms the WHOLE cased line — casing and stripe ride the same wave, so
    // the cable reads as one wavy object instead of a straight black bar with a
    // squiggle laid over it. (This used to keep the casing straight because a wavy
    // conduit casing swallowed the 2 px stripe; conduit left the hybrid in July 2026
    // and is drawn at its own geometry, so the only casing left here is the span's.)
    // It also fixes B&W Plan view, where the stripe is painted white to hollow the
    // casing: a wave that swung clear of a straight casing just cut chevrons out of
    // it and the rest of the wave vanished against the white page.

    // A wiggly line means SLACK, exclusively — that's the only thing that
    // deforms the route. Overlash keeps a straight stripe and is marked instead
    // by lashing ticks below, so the two are never confused.
    //
    // The wave has to swing clear of the casing or it just ripples inside
    // it and stays invisible, so the amplitude starts at that casing's half width.
    // Wavelength tracks amplitude (~3.7×, the ratio the bare-strand wiggle used) to
    // keep the wave's shape constant instead of getting spikier as it grows.
    // Passing an explicit on-screen wavelength also keeps the pitch AND the sampling
    // density (steps/cycle = wavelengthPx/2) constant regardless of span length —
    // otherwise short spans undersample the sine and the polyline never reaches the
    // ±amplitude peaks, making the wiggle look shallower than on long spans.
    const widestCasingHalf = nearSpan ? spanCW / 2 : 0;
    const slackAmp         = Math.max(3, widestCasingHalf + 3);
    const slackCoords      = isSlack
      ? wavyCoords(coords, slackAmp, 10, Math.round(slackAmp * 3.7))
      : coords;
    // Both layers take the same path: the casing IS the wave, the stripe its centre.
    const casingCoords     = slackCoords;
    const stripeCoords     = slackCoords;

    // Tag the casing with the strand's props + _tinDecor so the pole/WO filter dims &
    // HIDES it alongside the strand. Without this the black span casing (e.g. a
    // 144F) survives a work-order filter even though its strand is hidden, because
    // the filter skips strandGroup layers that carry no _tinStrand.
    if (nearSpan) {
      // Interactive so a click on the wide black casing (not just the thin centre
      // stripe) reaches the route chooser — the casing is what the user sees & aims
      // at. Routed to this strand; the chooser adds the span(s) it carries.
      const sLayer = L.polyline(casingCoords, { color: '#111827', weight: spanCW, opacity: 1, interactive: true, className: 'tin-casing' });
      sLayer._tinStrand = p; sLayer._baseOpacity = 1; sLayer._tinDecor = true;
      if (isSlack) {
        sLayer._tinZoomGeom = { coords, amp: slackAmp };
        _slackStripes.push(sLayer);   // same registry: zoom rebuilds casing + stripe together
      }
      enableRouteChooser(sLayer, 'strand', p.id);
      strandGroup.addLayer(sLayer);
    }

    // Bell overlash → lashing ticks crossing the line (matches the permit strip
    // diagram's symbol). Ticks come off the straight route even when the stripe
    // is wavy: wavyCoords emits hundreds of 1–2 px sub-segments, which would all
    // fail the minimum-length guard. Half-height clears the widest casing so the
    // tick visibly crosses the outline rather than disappearing inside it.
    // The layer is created even below LASH_TICK_ZOOM (empty), so refreshZoomGeometry
    // can fill it in on zoom without a re-render.
    if (isOverlash) {
      const halfH = widestCasingHalf ? widestCasingHalf + 3 : 4.5;
      const ticks = map.getZoom() >= LASH_TICK_ZOOM ? lashTicks(coords, halfH, 14, 0.625) : [];
      const tickLayer = L.polyline(ticks, { color, weight: 1.5, opacity, interactive: false });
      tickLayer._tinStrand   = p;      // so the WO filter dims ticks with their strand
      tickLayer._baseOpacity = opacity;
      tickLayer._tinDecor    = true;   // non-primary geometry layer
      tickLayer._tinZoomGeom = { coords, halfH };
      _lashTickLayers.push(tickLayer);
      strandGroup.addLayer(tickLayer);
    }

    // 'tin-core' only when a casing is actually under it: in B&W the core is drawn
    // white to hollow out the casing, and a bare strand has nothing behind it to
    // hollow — it would simply vanish.
    const line = L.polyline(stripeCoords, { color, weight: isDup ? Math.max(weight, 3) : weight, opacity, dashArray: isDup ? '6,5' : null, className: nearSpan ? 'tin-core' : undefined });

    if (isSlack) {
      line._tinZoomGeom = { coords, amp: slackAmp };
      _slackStripes.push(line);
    }

    line.bindPopup(() => buildStrandPopup(p, coords, chainLengthM), { maxWidth: 280 });

    line.on('popupopen', function () {
      document.querySelector(`.strand-edit-link[data-strand-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          line.closePopup();
          openStrandModal(null, null, p);
        });
      document.querySelector(`.strand-insert-link[data-strand-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          line.closePopup();
          startInsertStrandMode(p.id);
        });
      document.querySelector(`.strand-dim-link[data-strand-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          line.closePopup();
          const a = L.latLng(coords[0]), b = L.latLng(coords[coords.length - 1]);
          saveDimension('straight', [{ ll: a, pole: p.from_pole_fid || null }, { ll: b, pole: p.to_pole_fid || null }], null)
            .catch(err => toast('Could not save: ' + err.message, 'danger'));
        });
      document.querySelector(`.strand-delete-link[data-strand-id="${p.id}"]`)
        ?.addEventListener('click', function (e) {
          e.preventDefault();
          line.closePopup();
          deleteStrandById(p.id, p.is_overlash);
        });
    });

    // Stash props + base opacity so the pole/WO filter can dim non-matching strands.
    line._tinStrand   = p;
    line._baseOpacity = opacity;
    registerLineFeat('strand', p.id, line);
    enableRouteChooser(line, 'strand', p.id);
    strandGroup.addLayer(line);

    // The pole-to-pole distance. A strand segment runs one pole pair, so its own
    // length IS the spacing — this is the number the Distances toggle is for, and
    // most of the plant is strand with no cable span recorded against it yet.
    // Measured off the straight route rather than the wavy slack stripe, so a
    // slack strand reports the pole spacing and not the length of the wiggle.
    renderRouteDistLabel(coords, null,
      (p.work_orders && p.work_orders.length) ? p.work_orders.join(',') : (p.work_order || ''),
      'route-dist-label--strand');
  }

  function renderSpan(feature, bounds) {
    if (!feature.geometry) return;
    const p      = feature.properties;
    const coords = feature.geometry.coordinates.map(c => [c[1], c[0]]);

    // Span → black; open-ended spans flagged red (orange is reserved for conduit).
    // Private client links (not part of our network) are dashed purple.
    const spanColor = p.is_private ? '#7c3aed' : (p.open_ended ? '#dc2626' : '#111827');
    // Where a strand's cased hybrid already carries this span (usually drawn a few
    // metres off the pole line), hide the offset copy — keep it only as an invisible
    // click target so it stays editable. Solid, thickness by fibre count.
    const covered = !!p.covered_by_strand;   // computed server-side (zoom-stable)
    const line = L.polyline(coords, {
      color:     spanColor,
      weight:    covered ? 10 : spanWeight(p.fibre_count),
      opacity:   covered ? 0 : 0.9,
      dashArray: p.is_private ? '7 5' : null,
    });

    let routeLenM = 0;
    for (let i = 0; i < coords.length - 1; i++) {
      routeLenM += L.latLng(coords[i]).distanceTo(L.latLng(coords[i + 1]));
    }
    routeLenM = Math.round(routeLenM);

    line.bindPopup(() => buildSpanPopup(p, routeLenM), { minWidth: 210 });

    line.on('popupopen', function () {
      document.getElementById('via-conduit-' + p.cable_span_id)
        ?.addEventListener('click', function () { line.closePopup(); startViaConduits(p); });
      document.getElementById('merge-span-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          pickOnMap('Click the next span of the same cable to merge with it', (pick) => {
            let other = null, bestD = 14;
            spanGroup.eachLayer(l => {
              const sp = l._tinSpan;
              if (!sp || sp.cable_span_id === p.cable_span_id || !l.getLatLngs) return;
              const rings = l.getLatLngs(), flat = Array.isArray(rings[0]) ? rings[0] : rings;
              let prev = null;
              for (const ll of flat) {
                const q = map.latLngToContainerPoint(ll);
                if (prev) {
                  const dx = q.x - prev.x, dy = q.y - prev.y, l2 = dx * dx + dy * dy;
                  const t = l2 ? Math.max(0, Math.min(1, ((pick.point.x - prev.x) * dx + (pick.point.y - prev.y) * dy) / l2)) : 0;
                  const d = Math.hypot(pick.point.x - (prev.x + t * dx), pick.point.y - (prev.y + t * dy));
                  if (d < bestD) { bestD = d; other = sp; }
                }
                prev = q;
              }
            });
            if (!other) { toast('No other span there', 'warning'); return; }
            if (!confirm('Merge span ' + other.cable_span_id + ' into span ' + p.cable_span_id + '?\n\nThe closure between them is removed if nothing else uses it.')) return;
            apiPost('/api/merge_span.php', { cable_span_id: p.cable_span_id, with_span_id: other.cable_span_id })
              .then(r => r.json()).then(d => {
                if (!d.ok) throw new Error(d.error || 'merge failed');
                toast(d.closure_removed ? 'Spans merged, closure removed' : 'Spans merged; the closure was left in place because something still refers to it', 'success');
                loadFeatures(true);
              }).catch(e => toast(e.message, 'danger'));
          });
        });
      document.getElementById('dim-span-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          const lls = coords.map(c => L.latLng(c));
          saveDimension(lls.length === 2 ? 'straight' : 'route', [{ ll: lls[0], pole: null }, { ll: lls[lls.length - 1], pole: null }], lls)
            .catch(err => toast('Could not save: ' + err.message, 'danger'));
        });
      document.getElementById('edit-span-props-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          openEditSpanModal(p);
        });
      document.getElementById('edit-span-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          startEditSpan(line, p);
        });
      document.getElementById('route-span-poles-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          startRouteSpanViaPoles(line, p);
        });
      document.getElementById('split-span-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          openSplitSpanModal(p);
        });
      document.getElementById('del-span-' + p.cable_span_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          deleteSpan(p.cable_span_id, line);
        });
    });

    // Stash props + base opacity so the pole/WO filter can dim non-matching spans.
    line._tinSpan     = p;
    line._baseOpacity = p.span_status === 'plan' ? 0.6 : 0.8;
    registerLineFeat('span', p.cable_span_id, line);
    enableRouteChooser(line, 'span', p.cable_span_id);
    spanGroup.addLayer(line);

    // A 'covered' span is drawn invisibly (strand casings carry the on-route part),
    // but its OFF-strand remainder — e.g. the tail from the last support to its
    // closure — has no casing and would vanish. Draw that remainder solid so the
    // span stays visibly connected to the closure.
    if (covered && p.uncovered_geojson) {
      const g = p.uncovered_geojson;
      const toLL = ring => ring.map(c => [c[1], c[0]]);
      const uncLatLngs = g.type === 'MultiLineString' ? g.coordinates.map(toLL) : toLL(g.coordinates);
      const uncLine = L.polyline(uncLatLngs, { color: spanColor, weight: spanWeight(p.fibre_count), opacity: 0.9, interactive: false });
      uncLine._tinSpan = p; uncLine._baseOpacity = 0.9; uncLine._tinDecor = true;
      spanGroup.addLayer(uncLine);
    }
    // Labels hide with their span, so they need the same permit set it filters on.
    renderSpanLabels(coords, p.fibre_count,
      (p.work_orders && p.work_orders.length) ? p.work_orders.join(',') : p.fpm_wor,
      p.cable_name);
    // FlexTIN spans (to-end is an optitap) carry a pole-to-pole distance label.
    // Every other span gets the same label on its own toggle — the two sets are
    // disjoint, so a FlexTIN tail is never labelled twice with both switches on.
    const distWor = (p.work_orders && p.work_orders.length) ? p.work_orders.join(',') : p.fpm_wor;
    if (p.flextin_parent_fosc_id != null) {
      renderDistLabel(coords, routeLenM, distWor, 'flextin-dist-label', flextinDistGroup);
    } else if (!covered) {
      // A covered span shares its route with a strand, and that strand's legs are
      // already labelled — one per pole pair, which is the finer and more useful
      // number. Labelling the span too would stack its whole-run length on top.
      renderRouteDistLabel(coords, routeLenM, distWor, 'route-dist-label--span');
    }
    coords.forEach(c => bounds.push(c));
  }

  // Coincident features (several cables on one pole route, or a Tinuum strand and
  // a Bell overlash on the same pole pair) would stack identical "45 m" labels on
  // top of each other. Keyed on midpoint + length so the first one wins and later
  // duplicates only lend it their permit.
  const routeDistLabelByKey = {};

  // Distance label for one route leg: same rendering as the FlexTIN one, deduped
  // across the features that share a route. `lenM` may be null, in which case the
  // geometry's own length is used.
  function renderRouteDistLabel(latlngs, lenM, wor, srcClass) {
    if (!latlngs || latlngs.length < 2) return;
    const seg = midSegment(latlngs);
    if (!seg) return;
    lenM = Math.round(lenM != null ? lenM : seg.total);
    if (!lenM) return;
    const key = seg.mid.lat.toFixed(5) + ',' + seg.mid.lng.toFixed(5) + ',' + lenM;
    const existing = routeDistLabelByKey[key];
    if (existing) {
      // Keep the label alive for every permit that runs this route, so the WO
      // filter cannot drop a distance whose route is still drawn.
      const have = String(existing._tinLabelWor || '').split(',').map(v => v.trim()).filter(Boolean);
      String(wor || '').split(',').map(v => v.trim()).filter(Boolean)
        .forEach(v => { if (!have.includes(v)) have.push(v); });
      existing._tinLabelWor = have.join(',');
      return;
    }
    const marker = renderDistLabel(latlngs, lenM, wor, 'route-dist-label ' + srcClass, routeDistGroup);
    if (marker) routeDistLabelByKey[key] = marker;
  }

  // Metre-distance label at the midpoint of a span's route, rotated parallel to
  // the cable. Added to the caller's toggleable layer group.
  function renderDistLabel(latlngs, lenM, wor, cssClass, group) {
    if (latlngs.length < 2 || !lenM) return null;

    const seg = midSegment(latlngs);
    if (!seg) return null;

    const marker = L.marker(seg.mid, {
      icon: L.divIcon({
        html: `<div class="${cssClass}" style="transform:translate(-50%,-140%) rotate(${-seg.angle}deg)">${lenM} m</div>`,
        className: '',
        iconSize: [0, 0],
        iconAnchor: [0, 0],
      }),
      interactive: false,
      keyboard:    false,
    });
    marker._tinLabelWor = wor || '';   // so the WO filter hides the label with its span
    group.addLayer(marker);
    return marker;
  }

  // Half-way point of a polyline — by distance travelled, not by vertex count —
  // plus the bearing of the segment it lands on (already flipped to stay
  // right-way-up as map text) and the route's total length.
  function midSegment(latlngs) {
    if (!latlngs || latlngs.length < 2) return null;

    const cumDist = [0];
    for (let i = 1; i < latlngs.length; i++) {
      cumDist.push(cumDist[i - 1] + L.latLng(latlngs[i - 1]).distanceTo(L.latLng(latlngs[i])));
    }
    const half = cumDist[cumDist.length - 1] / 2;

    let seg = 0;
    while (seg < cumDist.length - 2 && cumDist[seg + 1] < half) seg++;
    const segLen = cumDist[seg + 1] - cumDist[seg];
    const t      = segLen > 0 ? (half - cumDist[seg]) / segLen : 0;
    const a      = L.latLng(latlngs[seg]);
    const b      = L.latLng(latlngs[Math.min(seg + 1, latlngs.length - 1)]);
    const mid    = L.latLng(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t);

    const dy  = b.lat - a.lat;
    const dx  = (b.lng - a.lng) * Math.cos(a.lat * Math.PI / 180);
    let angle = Math.atan2(dy, dx) * 180 / Math.PI;
    if (angle < -90 || angle > 90) angle += 180;

    return { mid: mid, angle: angle, total: cumDist[cumDist.length - 1] };
  }

  // A main cable is known by its fibre count. A FlexTIN tail is not: they come in
  // 4F/6F/12F, each as a parallel A and B run that share a pole route, so the count
  // alone cannot tell you which one you are looking at — the run letter is the part
  // that was missing. Anything not named FlexTIN keeps the bare count.
  function spanLabelText(fibreCount, cableName) {
    const name = String(cableName || '');
    if (!/flextin/i.test(name)) return fibreCount + 'F';
    const run = name.match(/([A-Za-z])\s*$/);
    return 'FLEX ' + fibreCount + 'F' + (run ? ' ' + run[1].toUpperCase() : '');
  }

  function renderSpanLabels(latlngs, fibreCount, wor, cableName) {
    if (!fibreCount) return;
    const label = spanLabelText(fibreCount, cableName);

    const cumDist = [0];
    for (let i = 1; i < latlngs.length; i++) {
      cumDist.push(cumDist[i - 1] + L.latLng(latlngs[i - 1]).distanceTo(L.latLng(latlngs[i])));
    }
    const totalLen = cumDist[cumDist.length - 1];
    if (totalLen < 20) return;

    // Spacing is along the CABLE, not along each span. A run is drawn as one span
    // per pole pair — 40-60 m each — so a per-span minimum of one label put a
    // label on every pole span, five times more often than the spacing claimed.
    // Candidates are still generated per span; _spanLabelPlaced then drops any
    // that lands within LABEL_SPACING_M of a label already placed for the same
    // cable, which leaves roughly one per 250 m however the run is cut up. The
    // first label of a cable always places, so a lone short span still gets one.
    // Zoomed in, a 560 m run with two labels reads as having none. So labels come in tiers of
    // finer and finer spacing; CSS shows the tiers the current zoom allows (see
    // setLabelTier). A finer tier never doubles up on a coarser one: it skips any spot within
    // its own spacing of a label already placed at its tier or a coarser one.
    const placed = (_spanLabelPlaced[label] ||= []);

    for (let tier = 0; tier < LABEL_TIER_SPACING_M.length; tier++) {
      const spacing  = LABEL_TIER_SPACING_M[tier];
      const finest   = tier === LABEL_TIER_SPACING_M.length - 1;
      const count    = Math.max(1, Math.round(totalLen / spacing));
      const interval = totalLen / count;
      // The finest tier is one label per pole-to-pole stretch (those under `spacing` skipped);
      // the coarser ones are placed by distance along the cable.
      const picks = [];
      if (finest) {
        for (let sgi = 0; sgi < cumDist.length - 1; sgi++) if (cumDist[sgi + 1] - cumDist[sgi] >= spacing) picks.push(sgi);
      } else {
        for (let n = 0; n < count; n++) {
          const targetDist = interval * (n + 0.5);
          let sgi = 0;
          while (sgi < cumDist.length - 2 && cumDist[sgi + 1] < targetDist) sgi++;
          picks.push(sgi);
        }
      }

      for (const seg of picks) {
        const segLen = cumDist[seg + 1] - cumDist[seg];
        // Sit the label at the middle of the pole-to-pole stretch it falls in, not wherever the
        // spacing lands: that is usually right beside a pole icon, where Declutter then drops it.
        const t      = segLen > 0 ? 0.5 : 0;
        const a      = L.latLng(latlngs[seg]);
        const b      = L.latLng(latlngs[Math.min(seg + 1, latlngs.length - 1)]);
        const midPt  = L.latLng(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t);

        // Angle and offset are computed in SCREEN space. Deriving the angle from
        // lat/lng with a cos() correction left the text visibly off-parallel, and
        // sitting on the centreline it was hidden under the casing and the
        // overlash ticks. Offsetting perpendicular lifts it clear of both.
        const geom  = { a, b, midPt };
        const angle = spanLabelAngle(geom);
        if (angle === null) continue;
        if (placed.some(q => q.tier <= tier && q.pt.distanceTo(midPt) < spacing)) continue;
        placed.push({ pt: midPt, tier });

        const marker = L.marker(spanLabelAnchor(geom), {
          icon: L.divIcon({
            html: `<div class="span-fibre-label lbl-t${tier}" style="transform:translate(-50%,-50%) rotate(${angle}deg)">${label}</div>`,
            className: '',
            iconSize: [0, 0],
            iconAnchor: [0, 0],
          }),
          interactive: false,
          keyboard:    false,
        });
        marker._tinLabelWor = wor || '';   // so the WO filter can hide the label with its span/conduit
        // Only the anchor moves with zoom (the offset is in px); the rotation is a
        // screen angle, which Web Mercator zoom leaves unchanged. So refreshZoomGeometry
        // does setLatLng() and never rebuilds the icon.
        marker._tinZoomGeom = geom;
        _spanLabelMarkers.push(marker);
        spanLabelGroup.addLayer(marker);
      }
    }
  }

  // Label spacing along a cable, per tier, and the zoom at which each tier appears.
  const LABEL_TIER_SPACING_M = [250, 110, 25];
  function setLabelTier() {
    const c = document.getElementById('mapCanvas');
    if (!c || !map) return;
    const z = map.getZoom();
    c.dataset.labelTier = z >= 19 ? '2' : z >= 18 ? '1' : '0';
    scheduleLabelDeclutter();
  }

  // Screen-space bearing of the label's segment, in CSS rotate degrees (clockwise),
  // flipped so text always reads upright. Returns null for a degenerate segment.
  // Deriving this from lat/lng with a cos() correction left the text visibly
  // off-parallel, hence the layer-point round trip.
  function spanLabelAngle(g) {
    const pa = map.latLngToLayerPoint(g.a);
    const pb = map.latLngToLayerPoint(g.b);
    let sdx = pb.x - pa.x, sdy = pb.y - pa.y;
    if (Math.sqrt(sdx * sdx + sdy * sdy) < 1) return null;
    if (sdx < 0) { sdx = -sdx; sdy = -sdy; }
    return Math.atan2(sdy, sdx) * 180 / Math.PI;
  }

  // ── Plan-sheet legend ───────────────────────────────────────────────────────
  // The key printed in the title block. Built from the icon functions the map itself uses, so
  // a symbol that changes on screen changes here, and it lists only what is actually on the map.
  window.fpmLegendHtml = function () {
    const items = [];
    const seen = new Set();
    const add = (key, ico, label) => {
      if (seen.has(key) || !ico) return;
      seen.add(key);
      items.push('<div class="eng-leg-item"><span class="eng-leg-ico">' + ico + '</span><span>' + esc(label) + '</span></div>');
    };
    const html = di => (di && di.options && di.options.html && /viewBox/.test(di.options.html)) ? di.options.html : '';
    const group = g => (g && g.getLayers) ? g.getLayers() : [];
    const word = st => st === 'plan' ? 'planned' : st === 'under construction' || st === 'construction' ? 'under construction' : st === 'retired' ? 'retired' : 'built';
    const line = (w1, c1, w2, c2) =>
      '<svg viewBox="0 0 24 8" xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="4" x2="24" y2="4" stroke="' + c1 + '" stroke-width="' + w1 + '"/>' +
      (w2 ? '<line x1="0" y1="4" x2="24" y2="4" stroke="' + c2 + '" stroke-width="' + w2 + '"/>' : '') + '</svg>';

    // Lines
    if (group(strandGroup).some(l => l._tinStrand))
      add('strand', line(3.6, '#111827', 1.5, '#005EB8'), 'Messenger strand');
    if (group(spanGroup).some(l => l._tinSpan))
      add('span', line(4.4, '#111827'), 'Fibre cable');
    if (group(conduitGroup).some(l => l._tinConduit))
      add('conduit', line(5, '#fb8500', 2.2, '#111827'), 'Conduit');

    // Poles, by owner (the cross arm colour)
    const owners = new Set();
    group(poleGroup).forEach(m => { if (m._tinIcon) owners.add(m._tinIcon.owner || ''); });
    [...owners].sort().forEach(o => add('pole:' + o, html(poleIcon(o, 1)), (o || 'Unknown owner') + ' pole'));

    // Anchors, by owner and state
    group(anchorGroup).forEach(m => {
      const a = m._tinAnchor; if (!a) return;
      const o = (a.props && a.props.owner) || '';
      add('anchor:' + o + ':' + a.status, html(anchorIcon(a.status, 1, o)), 'Anchor, ' + (o || 'owner unknown') + ' (' + word(a.status) + ')');
    });
    if (group(buildingAnchorGroup).length)
      add('banchor', html(symbolIcon('house', 'mast', '#198754', false, 22, 'x')), 'Building anchor');

    // Closures, slack loops and the rest of the fibre hardware
    const KIND = { closure: 'Closure', slack: 'Slack loop', cabinet: 'Cabinet', mst: 'MST', optitap: 'Tap' };
    group(foscGroup).forEach(m => {
      const t = m._tinIcon; if (!t) return;
      const col = (typeof foscDisplayColor === 'function') ? foscDisplayColor(t, m._foscId) : (STATUS_COLORS[t.status] || '#0d6efd');
      add('fosc:' + t.kind + ':' + t.status, html(foscIconForKind(t.kind, t.status, col, false, 1, false, 0, 0, 0)),
          (KIND[t.kind] || 'Closure') + ' (' + word(t.status) + ')');
    });
    if (group(popGroup).length) add('pop', html(popIcon(false, 1)), 'POP');
    group(uguardGroup).forEach(m => { const u = m._tinUguard; if (u) add('ug:' + u.status, html(uguardIcon(u.status, 1)), 'U-guard (' + word(u.status) + ')'); });
    [vaultGroup, handholeGroup].forEach(g => group(g).forEach(m => {
      const v = m._tinVault; if (!v) return;
      const type = (v.props && v.props.type) || 'vault';
      const fn = type === 'pullbox' ? pullboxIcon : type === 'handhole' ? handholeIcon : vaultIcon;
      add('v:' + type + ':' + v.status, html(fn(v.status, 1)), (type === 'pullbox' ? 'Pullbox' : type === 'handhole' ? 'Handhole' : 'Vault') + ' (' + word(v.status) + ')');
    }));
    if (group(onuGroup).length) add('onu', html(onuIcon('#6c757d', 1)), 'ONU / customer');

    // Notes
    const NOTE = { tree_trim: ['Tree trim', treeNoteIcon], construction: ['Construction note', constructionNoteIcon], note: ['Note', genericNoteIcon] };
    group(noteGroup).forEach(m => {
      const k = m._tinNote === undefined ? null : (NOTE[m._tinNote] ? m._tinNote : 'note');
      if (k) add('note:' + k, html(NOTE[k][1](1, 0)), NOTE[k][0]);
    });

    return items.join('');
  };

  // Perpendicular clearance from the centreline, in px. Must exceed half the widest
  // casing (spanWeight caps at 16 ⇒ 8) plus the overlash tick overhang — on the
  // centreline the label hides under both.
  const LABEL_OFFSET_PX = 13;

  // side: +1 above the centreline (fibre counts), -1 below it (conduit lengths),
  // so two labels on one line never share the same edge.
  function spanLabelAnchor(g, side = 1) {
    const pa = map.latLngToLayerPoint(g.a);
    const pb = map.latLngToLayerPoint(g.b);
    let sdx = pb.x - pa.x, sdy = pb.y - pa.y;
    const slen = Math.sqrt(sdx * sdx + sdy * sdy);
    if (slen < 1) return g.midPt;
    if (sdx < 0) { sdx = -sdx; sdy = -sdy; }
    const mid = map.latLngToLayerPoint(g.midPt);
    const off = side * LABEL_OFFSET_PX;
    return map.layerPointToLatLng(
      L.point(mid.x + (sdy / slen) * off, mid.y + (-sdx / slen) * off));
  }

  // The point a given fraction along a polyline, plus the segment it lands on —
  // the { a, b, midPt } shape spanLabelAngle and spanLabelAnchor both take.
  function pointAlongGeom(latlngs, frac) {
    if (!latlngs || latlngs.length < 2) return null;
    const cum = [0];
    for (let i = 1; i < latlngs.length; i++) {
      cum.push(cum[i - 1] + L.latLng(latlngs[i - 1]).distanceTo(L.latLng(latlngs[i])));
    }
    const total = cum[cum.length - 1];
    if (!total) return null;

    const target = total * frac;
    let seg = 0;
    while (seg < cum.length - 2 && cum[seg + 1] < target) seg++;
    const segLen = cum[seg + 1] - cum[seg];
    const t      = segLen > 0 ? (target - cum[seg]) / segLen : 0;
    const a      = L.latLng(latlngs[seg]);
    const b      = L.latLng(latlngs[Math.min(seg + 1, latlngs.length - 1)]);
    return { a, b, midPt: L.latLng(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t) };
  }

  // ── Slack-loop glyph bearing ─────────────────────────────────────────────────
  // The slack symbol is a coil bulging off one side of the cable, so it has to know
  // which way the cable runs. Same screen-space reasoning as spanLabelAngle above:
  // a lat/lng angle with a cos() correction reads visibly off-parallel. And like
  // that angle it is zoom-invariant under Web Mercator, so it is computed once per
  // render and never recomputed on zoomend.

  // How far along a span to look for its departure direction. Mirrors the 30 m in
  // fetch_span_endpoints_for_fosc (closure.helper.php) and for the same reason:
  // geom_snap leaves a vertex centimetres from the closure, and the bearing of that
  // stub is noise. 20 m rather than 30 — slack loops sit mid-block on short spans.
  const SLACK_BEARING_SAMPLE_M = 20;

  // Project at a FIXED zoom rather than using latLngToLayerPoint, which rounds to
  // whole pixels: at z16 a 20 m leg is only ~10 px long, so that rounding swung the
  // angle by more than a degree between zoom levels and the glyph visibly twitched
  // as you zoomed. project() is unrounded, and pinning the zoom makes the bearing
  // identical at every zoom — which is the whole reason it can be computed once.
  const SLACK_BEARING_PROJECT_ZOOM = 22;

  function slackDeparturePoint(coords, fromStart) {
    const pts = fromStart ? coords : coords.slice().reverse();
    let acc = 0;
    for (let i = 1; i < pts.length; i++) {
      acc += L.latLng(pts[i - 1]).distanceTo(L.latLng(pts[i]));
      if (acc >= SLACK_BEARING_SAMPLE_M) return L.latLng(pts[i]);
    }
    return L.latLng(pts[pts.length - 1]);
  }

  // Screen bearing for a slack-loop glyph, in CSS rotate degrees.
  // Two spans: the through-line is the chord between the two departure directions,
  // so at a corner the glyph mitres the bend instead of siding with one arm.
  // One span (migration 0008 allows 0 or 1 "while designing"): that span alone.
  // Nothing usable: null ⇒ unrotated, which is exactly how the dog-bone drew.
  function slackGlyphAngle(legs) {
    const vs = [];
    for (const leg of legs) {
      const end = leg.fromStart ? leg.coords[0] : leg.coords[leg.coords.length - 1];
      const o = map.project(L.latLng(end), SLACK_BEARING_PROJECT_ZOOM);
      const d = map.project(slackDeparturePoint(leg.coords, leg.fromStart), SLACK_BEARING_PROJECT_ZOOM);
      const vx = d.x - o.x, vy = d.y - o.y;
      const len = Math.sqrt(vx * vx + vy * vy);
      if (len < 1) continue;                       // degenerate: both ends coincide
      vs.push({ x: vx / len, y: vy / len });
    }

    let dx, dy;
    if (vs.length >= 2) {
      // One leg points back down the cable and the other forward, so their
      // difference is the through-direction — and at a corner it bisects the bend.
      // Leg order only flips the sign, which the normalisation below cancels.
      dx = vs[1].x - vs[0].x; dy = vs[1].y - vs[0].y;
      // Hairpin: both spans leave the same way, so the chord collapses and its
      // direction is noise. Fall back to one leg rather than spin at random.
      if (Math.sqrt(dx * dx + dy * dy) < 0.2) { dx = vs[0].x; dy = vs[0].y; }
    } else if (vs.length === 1) {
      dx = vs[0].x; dy = vs[0].y;
    } else {
      return null;
    }

    // The same upright normalisation spanLabelAngle uses — but for an asymmetric
    // glyph it is load-bearing, not cosmetic: without it the bulge side is decided
    // by whether a span happens to be stored from→to, so two loops on one cable
    // could hang opposite ways. With it the angle lands in [-90°, 90°] and the loop
    // always hangs toward the top of the screen. The dy tie-break pins due-N/S runs,
    // which spanLabelAngle's dx < 0 test alone leaves ambiguous (harmless for text,
    // not for a glyph that would flip east/west between two identical pole lines).
    if (dx < 0 || (dx === 0 && dy > 0)) { dx = -dx; dy = -dy; }
    return Math.atan2(dy, dx) * 180 / Math.PI;   // y-down layer space ⇒ CSS-clockwise
  }

  // fosc_id → glyph angle, built from the span features BEFORE anything renders.
  // Deriving it inside renderSpan would only work because spans happen to render
  // before foscs, and would break silently the first time those calls are reordered.
  // A pole serving several ONUs carries one stacked tap per fibre, and those taps
  // sit on identical coordinates — so drawn as-is only the last one is visible or
  // clickable, and L10/L11 disappear under L12. Give each member of a co-located
  // group a signed, centred slot number; the icon builder turns that into a
  // horizontal shift of one icon width, leaving the stored location untouched.
  // Where a fanned closure's label goes relative to an unfanned one: across by an
  // icon width to sit over its own icon, and down by the same, so the labels of a
  // stacked group step diagonally instead of printing on one another.
  function fanLabelOffset(fanSlot, iconW) {
    if (!fanSlot) return [0, 0];
    const step = iconW || 0;
    return [Math.round(fanSlot * step), Math.round(fanSlot * step)];
  }

  // Tooltip offset and side for a closure label. A callout icon puts the symbol away from the true
  // point, so the label goes beside the symbol (left of it when the symbol hangs to the left).
  function foscLabelPlacement(marker, s, isTap, fanDx, fanDy) {
    const c = marker.options.icon && marker.options.icon.options.tinCallout;
    if (!isTap && c) {
      const left = c.cx < -c.r * 0.5;
      return { direction: left ? 'left' : 'right',
               offset: [Math.round(c.cx + (left ? -(c.r + 3) : (c.r + 3))) + fanDx, Math.round(c.cy) + fanDy] };
    }
    return { direction: 'right', offset: [Math.round((isTap ? 14 : 16) * s / 2) + 4 + fanDx, fanDy] };
  }

  function buildFoscFanOffsets(features) {
    const groups = {};
    for (const f of features) {
      const p = f.properties;
      if (!p || p.feature_type !== 'fosc' || !f.geometry) continue;
      const [lon, lat] = f.geometry.coordinates;
      // ~0.1 m — tight enough that only genuinely co-located closures group.
      const key = lat.toFixed(6) + ',' + lon.toFixed(6);
      (groups[key] ||= []).push(p);
    }
    const out = {};
    for (const key in groups) {
      const g = groups[key];
      if (g.length < 2) continue;
      // Left-to-right in splice order, so a run reads L10, L11, L12 across.
      g.sort((a, b) => String(a.splice_num || '')
        .localeCompare(String(b.splice_num || ''), undefined, { numeric: true }));
      g.forEach((p, i) => { out[p.fosc_id] = i - (g.length - 1) / 2; });
    }
    return out;
  }

  function buildSlackAngles(features) {
    const legs = {};                                  // fosc_id → up to 2 legs
    for (const f of features) {
      const p = f.properties;
      if (!p || p.feature_type !== 'span' || !f.geometry) continue;
      const coords = f.geometry.coordinates.map(c => [c[1], c[0]]);   // lon/lat → lat/lng
      if (coords.length < 2) continue;
      if (p.from_fosc_id != null) (legs[p.from_fosc_id] ||= []).push({ coords, fromStart: true });
      if (p.to_fosc_id   != null) (legs[p.to_fosc_id]   ||= []).push({ coords, fromStart: false });
    }
    const out = {};
    for (const id in legs) {
      const a = slackGlyphAngle(legs[id]);
      if (a !== null) out[id] = a;
    }
    return out;
  }

  // ── Zoom-dependent geometry ──────────────────────────────────────────────────
  // Three things are built in PIXEL space and so genuinely change with zoom: the
  // slack wiggle, the overlash lashing ticks, and the span label's perpendicular
  // offset. Everything else renderAll produces is zoom-invariant. These registries
  // let refreshZoomGeometry update just those layers in place, instead of the old
  // approach of refetching and rebuilding the entire map on every zoom notch.
  // label text → where that label has already been placed this render, so the
  // spacing rule can span the many short spans that make up one cable run.
  const _spanLabelPlaced = {};
  const _slackStripes    = [];
  const _lashTickLayers  = [];
  const _spanLabelMarkers = [];

  function clearZoomGeomRegistries() {
    for (const k in _spanLabelPlaced) delete _spanLabelPlaced[k];
    _slackStripes.length     = 0;
    _lashTickLayers.length   = 0;
    _spanLabelMarkers.length = 0;
  }

  function refreshZoomGeometry() {
    const wantTicks = map.getZoom() >= LASH_TICK_ZOOM;
    for (const l of _slackStripes) {
      const g = l._tinZoomGeom;
      l.setLatLngs(wavyCoords(g.coords, g.amp, 10, Math.round(g.amp * 3.7)));
    }
    for (const t of _lashTickLayers) {
      const g = t._tinZoomGeom;
      t.setLatLngs(wantTicks ? lashTicks(g.coords, g.halfH, 14, 0.625) : []);
    }
    for (const m of _spanLabelMarkers) m.setLatLng(spanLabelAnchor(m._tinZoomGeom, m._tinLabelSide || 1));
  }

  // ── popup builders ───────────────────────────────────────────────────────────

  // 16-point compass abbreviation for a true-north bearing in degrees.
  const COMPASS_16 = ['N','NNE','NE','ENE','E','ESE','SE','SSE',
                      'S','SSW','SW','WSW','W','WNW','NW','NNW'];
  function compassPoint(deg) {
    return COMPASS_16[Math.round(((deg % 360) + 360) % 360 / 22.5) % 16];
  }

  // Where the cable goes next: bearing to each neighbouring closure and how far it
  // is ALONG THE CABLE (the API sums the spans' routed lengths, walking through
  // slack loops and counting their coils). Bearing is the straight line to that
  // closure — what a compass shows you — while the distance is what you would pull.
  //
  // Fetched when the popup opens rather than shipped with every feature: the map
  // refetches features on every pan and zoom, and the graph walks behind this
  // doubled that request's time for something only a popup reads.
  async function fillFoscNeighbours(foscId, el) {
    const box = el ? el('fosc-nbr-' + foscId)
                   : document.getElementById('fosc-nbr-' + foscId);
    if (!box) return;
    try {
      const resp = await fetch(API.foscNbrs + '?id=' + foscId);
      const data = await resp.json();
      if (!data.ok || !data.neighbours.length) { box.innerHTML = ''; return; }
      const viaStrand = data.via === 'strand';
      const rows = data.neighbours.map(function (n) {
        const b = n.bearing_deg == null ? '&mdash;'
                : `${compassPoint(n.bearing_deg)}&nbsp;${Math.round(n.bearing_deg)}\u00B0`;
        const d = n.dist_m >= 1000 ? (n.dist_m / 1000).toFixed(2) + '\u202fkm'
                                   : Math.round(n.dist_m) + '\u202fm';
        const via = n.loops > 0
          ? ` <span class="text-muted" title="through ${n.loops} slack loop${n.loops > 1 ? 's' : ''} — their 30 m coils are included in the distance">&#8634;${n.loops}</span>`
          : '';
        return `<tr>
          <td class="pe-2" style="white-space:nowrap">${esc(n.splice_num)}${via}</td>
          <td class="pe-2 text-muted" style="white-space:nowrap">${b}</td>
          <td class="fw-semibold text-end" style="white-space:nowrap">${d}</td>
        </tr>`;
      }).join('');
      box.innerHTML = `
        <small class="text-muted">Next closures &mdash; bearing &amp; ${viaStrand ? 'strand' : 'cable'} distance</small>
        ${viaStrand ? '<br><small class="text-muted fst-italic" style="font-size:10px">no spans here yet &mdash; measured along the strand</small>' : ''}
        <table class="table table-sm mb-0" style="font-size:11px">${rows}</table>`;
    } catch (e) {
      box.innerHTML = '<small class="text-muted">Next closures: unavailable</small>';
    }
  }

  function buildFoscPopup(p) {
    const worBadge = p.fpm_wor ? `<br><small class="text-muted">WOR: ${esc(p.fpm_wor)}</small>` : '';
    return `<div>
      <strong>${esc(p.splice_num)}</strong>
      <span class="badge bg-${esc(p.status_class)} ms-1">${esc(p.status)}</span>
      ${p.damaged ? '<span class="badge bg-danger ms-1">&#x2715; Damaged</span>' : ''}<br>
      <small class="text-muted">${esc(p.closure_type)}</small>
      ${p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : ''}
      ${worBadge}
      <div class="mt-2" id="fosc-nbr-${p.fosc_id}"><small class="text-muted">Next closures&hellip;</small></div>
      ${tlBox('fosc', p.fosc_id)}
      ${actRow([
        actLink('open', `/index.php?page=closure&fosc_id=${p.fosc_id}`, 'Details'),
        actBtn('edit', `id="edit-fosc-${p.fosc_id}"`, 'Edit'),
        actBtn('move', `id="move-fosc-${p.fosc_id}"`, 'Move'),
      ], actBtn('del', `id="del-fosc-${p.fosc_id}"`, 'Delete', 'Delete closure (only if nothing is connected)'))}
    </div>`;
  }

  function buildSpanPopup(p, routeLenM) {
    const len = routeLenM != null ? routeLenM + '\u202fm' : (p.length_m != null ? Math.round(p.length_m) + '\u202fm' : 'N/A');
    const flextinLink = p.flextin_parent_fosc_id
      ? `<a href="/index.php?page=flextin_tail#run-${p.flextin_parent_fosc_id}-${esc(p.flextin_side)}"
            class="btn btn-xs btn-outline-info" style="font-size:11px;padding:2px 7px" title="View FlexTIN run">FlexTIN</a>`
      : '';
    // Every permit the span belongs to (migration 0151), primary first.
    const spanWos  = (p.work_orders && p.work_orders.length)
                       ? p.work_orders : (p.fpm_wor ? [p.fpm_wor] : []);
    const worBadge = spanWos.length
      ? `<br><small class="text-muted">WOR: ${spanWos.map(esc).join(', ')}</small>` : '';
    return `<div>
      <strong>Span #${p.cable_span_id}</strong><br>
      <small>${esc(p.cable_name)} &mdash; ${p.fibre_count}F</small><br>
      ${p.cable_serial ? `<small>Serial <b>${esc(p.cable_serial)}</b></small><br>` : ''}
      <small class="text-muted">${esc(p.from_splice_num)} &rarr; ${esc(p.to_splice_num)}</small><br>
      <span class="fw-semibold">${len}</span>
      ${p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : ''}
      ${worBadge}
      ${actRow([
        actLink('open', '/index.php?page=cable_spans', 'Details'),
        flextinLink,
        actBtn('edit', `id="edit-span-props-${p.cable_span_id}"`, 'Edit'),
        actBtn('route', `id="edit-span-${p.cable_span_id}"`, 'Edit route', 'Drag the cable line to a new route'),
        actBtn('route', `id="route-span-poles-${p.cable_span_id}"`, 'Via poles', 'Route this span by clicking poles in order'),
        actBtn('route', `id="via-conduit-${p.cable_span_id}"`, 'Via conduit', 'Route this span through conduits: click them in order'),
        actBtn('dim', `id="dim-span-${p.cable_span_id}"`, 'Dimension', "Save this span's length as a dimension on the plan"),
        actBtn('split', `id="split-span-${p.cable_span_id}"`, 'Split', 'Split this span with a new closure'),
        actBtn('join', `id="merge-span-${p.cable_span_id}"`, 'Merge', 'Join this span to the next span of the same cable, removing the closure between them'),
      ], actBtn('del', `id="del-span-${p.cable_span_id}"`, 'Delete', 'Delete span'))}
    </div>`;
  }

  // ── Move a single FOSC ───────────────────────────────────────────────────────

  function startMoveFosc(marker, p, color) {
    cancelActiveEdit();

    // Highlight the marker
    const makeIcon = (hl) => foscIconForKind(marker._tinIcon.kind, marker._tinIcon.status, color, hl, iconScale(), !!marker._tinIcon.damaged, marker._tinIcon.angle || 0, marker._tinIcon.fanSlot || 0);
    marker._tinIcon.highlight = true;
    marker.setIcon(makeIcon(true));
    map.getContainer().style.cursor = 'grab';

    marker.dragging.enable();
    const zWas = marker.options.zIndexOffset || 0;
    marker.setZIndexOffset(100000);   // sit above any neighbour so the drag grabs this one

    const cancel = function () {
      marker.dragging.disable();
      marker.setZIndexOffset(zWas);
      marker._tinIcon.highlight = false;
      marker.setIcon(makeIcon(false));
      map.getContainer().style.cursor = '';
    };

    activeEdit = { type: 'fosc', layer: marker, cancel };

    showEditBar(
      '\u2B1C Moving <strong>' + esc(p.splice_num) + '</strong> — drag to new position',
      null,   // no explicit Save button; saves on drop
      function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); }
    );

    marker.once('dragend', function () {
      const ll = marker.getLatLng();
      cancel();
      foscGroup.removeLayer(marker);  // remove immediately — avoids ghost duplicate on re-render
      hideEditBar();
      activeEdit = null;
      moveFosc(p.fosc_id, ll);
    });
  }

  // ── Move a single pole ───────────────────────────────────────────────────────

  function startMovePole(marker, p) {
    cancelActiveEdit();

    map.getContainer().style.cursor = 'grab';
    marker.dragging.enable();

    const cancel = function () {
      marker.dragging.disable();
      map.getContainer().style.cursor = '';
    };

    activeEdit = { type: 'pole', layer: marker, cancel };

    showEditBar(
      '\u2B1C Moving pole <strong>' + esc(p.uid || p.fid) + '</strong> — drag to new position',
      null,
      function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); }
    );

    marker.once('dragend', function () {
      const ll = marker.getLatLng();
      cancel();
      poleGroup.removeLayer(marker);
      hideEditBar();
      activeEdit = null;
      movePole(p.fid, ll);
    });
  }

  async function movePole(fid, latlng) {
    toast('Saving…', 'secondary');
    try {
      const resp = await apiPut(API.pole + '?id=' + encodeURIComponent(fid), {
        geojson: { type: 'Point', coordinates: [latlng.lng, latlng.lat] },
      });
      const data = await resp.json();
      data.ok ? toast('Pole location saved', 'success')
              : toast('Save failed: ' + data.error, 'danger');
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    } finally {
      loadFeatures(true);
    }
  }

  // ── Pole multi-select ────────────────────────────────────────────────────────

  function togglePoleSelect(fid) {
    const marker = poleMarkerByFid[fid];
    if (!marker) return;

    // If another non-selection edit is active, cancel it first
    if (activeEdit && activeEdit.type !== 'poleMultiSelect') cancelActiveEdit();

    if (poleSelection.has(fid)) {
      poleSelection.delete(fid);
      marker.setIcon(poleIcon(marker._tinIcon.owner, poleScale()));
    } else {
      poleSelection.add(fid);
      marker.setIcon(poleIconSelected(marker._tinIcon.owner, poleScale()));
    }

    updatePoleSelectionBar();
  }

  function updatePoleSelectionBar() {
    const n = poleSelection.size;
    if (n === 0) {
      if (activeEdit?.type === 'poleMultiSelect') { hideEditBar(); activeEdit = null; }
      return;
    }
    const label = n + ' pole' + (n !== 1 ? 's' : '') + ' selected';
    activeEdit = { type: 'poleMultiSelect', layer: null, cancel: clearPoleSelection };
    showEditBar(
      '&#x2B1C; <strong>' + n + '</strong> pole' + (n !== 1 ? 's' : '') + ' selected — Shift+click to add/remove  <span class="text-muted small">[Esc to clear]</span>',
      function () { openPoleMultiEditModal(); },
      function () { clearPoleSelection(); hideEditBar(); activeEdit = null; },
      'Edit ' + label
    );
  }

  function clearPoleSelection() {
    poleSelection.forEach(function (fid) {
      const m = poleMarkerByFid[fid];
      if (m) m.setIcon(poleIcon(m._tinIcon.owner, poleScale()));
    });
    poleSelection.clear();
  }

  function openPoleMultiEditModal() {
    const n = poleSelection.size;
    const countEl = document.getElementById('poleMultiEditCount');
    if (countEl) countEl.textContent = n + ' pole' + (n !== 1 ? 's' : '');
    const saveCountEl = document.getElementById('poleMultiSaveCount');
    if (saveCountEl) saveCountEl.textContent = n + ' pole' + (n !== 1 ? 's' : '');

    // Reset checkboxes + disable/clear inputs
    ['Type', 'Owner', 'Nbp', 'Telco', 'Tags', 'WorkOrder', 'Comment'].forEach(function (f) {
      const chk = document.getElementById('poleMulti' + f + 'Check');
      const inp = document.getElementById('poleMulti' + f);
      if (chk) chk.checked = false;
      if (inp) { inp.disabled = true; inp.value = ''; }
    });
    showErr(document.getElementById('poleMultiEditError'), '');

    const saveBtn = document.getElementById('savePoleMultiBtn');
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save ' + n + ' pole' + (n !== 1 ? 's' : ''); }

    new bootstrap.Modal(document.getElementById('poleMultiEditModal')).show();
  }

  async function savePoleMulti() {
    const errEl  = document.getElementById('poleMultiEditError');
    const saveBtn = document.getElementById('savePoleMultiBtn');
    showErr(errEl, '');

    const fields = {};
    if (document.getElementById('poleMultiTypeCheck')?.checked)
      fields.type    = (document.getElementById('poleMultiType')?.value    || '').trim() || 'wood';
    if (document.getElementById('poleMultiOwnerCheck')?.checked)
      fields.owner   = (document.getElementById('poleMultiOwner')?.value   || '').trim();
    if (document.getElementById('poleMultiNbpCheck')?.checked)
      fields.nbp_num = (document.getElementById('poleMultiNbp')?.value     || '').trim();
    if (document.getElementById('poleMultiTelcoCheck')?.checked)
      fields.telco_num = (document.getElementById('poleMultiTelco')?.value || '').trim();
    if (document.getElementById('poleMultiTagsCheck')?.checked)
      fields.tags_num   = (document.getElementById('poleMultiTags')?.value       || '').trim();
    if (document.getElementById('poleMultiWorkOrderCheck')?.checked)
      fields.work_order = (document.getElementById('poleMultiWorkOrder')?.value  || '').trim();
    if (document.getElementById('poleMultiCommentCheck')?.checked)
      fields.comment   = (document.getElementById('poleMultiComment')?.value || '').trim();

    if (Object.keys(fields).length === 0) {
      showErr(errEl, 'No fields checked — nothing to update.');
      return;
    }

    const fids = Array.from(poleSelection);
    const origText = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

    try {
      const resp = await apiPost(API.poleBulk, { fids, fields });
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('poleMultiEditModal')).hide();
        toast('Updated ' + fids.length + ' pole' + (fids.length !== 1 ? 's' : ''), 'success');
        clearPoleSelection();
        hideEditBar();
        activeEdit = null;
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = origText; }
      }
    } catch (e) {
      showErr(errEl, 'Error: ' + e.message);
      if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = origText; }
    }
  }

  // ── Edit a single span's route ───────────────────────────────────────────────

  function startEditSpan(line, p) {
    cancelActiveEdit();

    const origColor = p.open_ended ? '#fd7e14' : '#0d6efd';
    line.setStyle({ color: '#ffc107', weight: 4 });
    line.pm.enable({ snappable: false });

    const originalLatLngs = line.getLatLngs().map(ll => [ll.lat, ll.lng]);

    const save = async function () {
      line.pm.disable();
      line.setStyle({ color: origColor, weight: 3 });
      hideEditBar();
      activeEdit = null;
      const latlngs = line.getLatLngs();
      const coords  = latlngs.map(ll => [ll.lng, ll.lat]);
      await editSpan(p.cable_span_id, { type: 'LineString', coordinates: coords });
    };

    const cancel = function () {
      line.pm.disable();
      line.setLatLngs(originalLatLngs);
      line.setStyle({ color: origColor, weight: 3 });
    };

    activeEdit = { type: 'span', layer: line, cancel };

    showEditBar(
      '\u270F Editing route of <strong>Span #' + p.cable_span_id +
      '</strong> (' + esc(p.from_splice_num) + ' \u2192 ' + esc(p.to_splice_num) + ') — drag vertices',
      async function () { await save(); },
      function () { cancel(); hideEditBar(); activeEdit = null; }
    );
  }

  // ── Hover-to-drag vertex editing for conduits ────────────────────────────────
  // Hovering a conduit fades in its Geoman vertex handles (plus midpoint handles
  // to insert, right-click to delete) so a route can be reshaped without going
  // through the popup. Nothing is committed until a handle is actually dragged —
  // that promotes the hover into a real edit with a Save/Cancel bar.

  let armedConduit = null;  // { line, props, originalLatLngs, watcher, style }

  // Hovering must not steal the pointer from a draw/route/measure mode, or from
  // an edit that is already in progress.
  function conduitHoverAllowed() {
    return !activeEdit && !drawingSpan && pendingDrawMode === 'fosc' &&
           !routingSpanViaPoles && !routingOnuDrop && !routingCustDrop && !measureActive;
  }

  function armConduitVertices(line, p) {
    if (!conduitHoverAllowed()) return;
    if (armedConduit && armedConduit.line === line) return;
    disarmConduitVertices();

    // An occupied duct's click layer is drawn invisible over its casing+core
    // decor (see renderConduit) — show it and hide the decor so the line being
    // dragged is the line you see.
    const style = { weight: line.options.weight, opacity: line.options.opacity };
    line.setStyle({ opacity: 0.95, weight: style.weight + 2 });
    setConduitDecorVisible(line, false);
    line.pm.enable({ snappable: false, draggable: false, allowSelfIntersection: true });

    // Disarm once the pointer leaves the line's vicinity. A distance test (rather
    // than mouseout) keeps the handles alive while the cursor sits on a handle,
    // which is always within a few px of the line.
    const watcher = function (e) {
      if (pxDistToPolyline(e.latlng, line.getLatLngs()) > 25) disarmConduitVertices();
    };
    map.on('mousemove', watcher);

    armedConduit = { line, props: p, originalLatLngs: line.getLatLngs().map(ll => [ll.lat, ll.lng]),
                     watcher, style };

    line.once('pm:markerdragstart', function () { promoteArmedConduitToEdit(); });
  }

  function disarmConduitVertices() {
    if (!armedConduit) return;
    const { line, watcher, style } = armedConduit;
    armedConduit = null;
    map.off('mousemove', watcher);
    line.off('pm:markerdragstart');
    try { line.pm.disable(); } catch (_) {}
    line.setStyle(style);
    setConduitDecorVisible(line, true);
  }

  // The casing + black core of an occupied duct live in strandGroup at the same
  // coords; they don't follow the handles, so they hide for the duration.
  function setConduitDecorVisible(line, visible) {
    (line._tinDecorLayers || []).forEach(function (l) {
      try { l.setStyle({ opacity: visible ? l._tinDecorOpacity : 0 }); } catch (_) {}
    });
  }

  // First vertex drag turns the hover into a committed edit: the handles stay put
  // when the pointer wanders off, and the edit bar offers Save / Cancel.
  function promoteArmedConduitToEdit() {
    if (!armedConduit) return;
    const { line, props: p, originalLatLngs, watcher, style } = armedConduit;
    map.off('mousemove', watcher);
    armedConduit = null;

    const cancel = function () {
      try { line.pm.disable(); } catch (_) {}
      line.setLatLngs(originalLatLngs);
      line.setStyle(style);
      setConduitDecorVisible(line, true);
    };

    const save = async function () {
      const coords = line.getLatLngs().map(ll => [ll.lng, ll.lat]);
      try { line.pm.disable(); } catch (_) {}
      line.setStyle(style);
      setConduitDecorVisible(line, true);
      hideEditBar();
      activeEdit = null;
      await editConduitRoute(p.conduit_id, { type: 'LineString', coordinates: coords });
    };

    activeEdit = { type: 'conduit', layer: line, cancel };

    showEditBar(
      '✏ Editing route of <strong>' + esc(p.name || 'Conduit #' + p.conduit_id) +
      '</strong> — drag vertices, or drag a midpoint to add one',
      async function () { await save(); },
      function () { cancel(); hideEditBar(); activeEdit = null; }
    );
  }

  function cancelActiveEdit() {
    disarmConduitVertices();
    if (planFraming)         exitPlanFraming();
    if (drawingSpan)         exitSpanDrawMode();
    if (routingSpanViaPoles) exitSpanRouteMode();
    if (routingOnuDrop)      exitOnuDropRouteMode();
    if (routingCustDrop)     exitCustDropRouteMode();
    if (!activeEdit) return;
    activeEdit.cancel();
    hideEditBar();
    activeEdit = null;
  }

  // ── Draw mode for new assets ─────────────────────────────────────────────────

  function startDrawClosure() {
    cancelActiveEdit();
    pendingDrawMode = 'fosc';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      '&#x1F4CD; Click on the map to place the new closure',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  function startDrawPole() {
    cancelActiveEdit();
    pendingDrawMode = 'pole';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      '&#x1F4CC; Click on the map to place the new pole',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  function startAddBuildingAnchor() {
    cancelActiveEdit();
    pendingDrawMode = 'building_anchor';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      '\u{1F3E2} Click on the map (on the building) to place the new building anchor',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  let _pendingBuildingAnchorLatLng = null;

  function openBuildingAnchorModalForCreate(latlng) {
    _pendingBuildingAnchorLatLng = latlng;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    document.getElementById('buildingAnchorModalLabel').textContent = 'New Building Anchor';
    set('buildingAnchorId', '');
    set('buildingAnchorName', '');
    set('buildingAnchorStatus', 'plan');
    set('buildingAnchorWor', '');
    set('buildingAnchorNotes', '');
    const coord = document.getElementById('buildingAnchorCoord');
    if (coord) coord.textContent = `${latlng.lat.toFixed(6)}, ${latlng.lng.toFixed(6)}`;
    showErr(document.getElementById('buildingAnchorModalError'), '');
    new bootstrap.Modal(document.getElementById('buildingAnchorModal')).show();
  }

  function openBuildingAnchorModalForEdit(p) {
    _pendingBuildingAnchorLatLng = { lat: p.lat, lng: p.lon };
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    document.getElementById('buildingAnchorModalLabel').textContent = 'Edit Building Anchor';
    set('buildingAnchorId', String(p.id));
    set('buildingAnchorName', p.name || '');
    set('buildingAnchorStatus', p.status || 'plan');
    set('buildingAnchorWor', p.work_order || '');
    set('buildingAnchorNotes', p.notes || '');
    const coord = document.getElementById('buildingAnchorCoord');
    if (coord) coord.textContent = `${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}`;
    showErr(document.getElementById('buildingAnchorModalError'), '');
    new bootstrap.Modal(document.getElementById('buildingAnchorModal')).show();
  }

  async function saveBuildingAnchor() {
    const id     = (document.getElementById('buildingAnchorId')?.value || '').trim();
    const name   = (document.getElementById('buildingAnchorName')?.value || '').trim();
    const status = (document.getElementById('buildingAnchorStatus')?.value || 'plan').trim();
    const wor    = (document.getElementById('buildingAnchorWor')?.value || '').trim();
    const notes  = (document.getElementById('buildingAnchorNotes')?.value || '').trim();
    const errEl  = document.getElementById('buildingAnchorModalError');
    showErr(errEl, '');

    const ll = _pendingBuildingAnchorLatLng;
    if (!ll || !isFinite(ll.lat) || !isFinite(ll.lng)) { showErr(errEl, 'No location provided.'); return; }

    const payload = {
      name, status, work_order: wor, notes,
      geojson: { type: 'Point', coordinates: [ll.lng, ll.lat] },
    };
    try {
      const resp = id
        ? await apiPut(API.buildingAnchor + '?id=' + encodeURIComponent(id), payload)
        : await apiPost(API.buildingAnchor, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('buildingAnchorModal')).hide();
        toast(id ? 'Building anchor updated' : 'Building anchor created', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) {
      showErr(errEl, 'Error: ' + e.message);
    }
  }

  function startMoveBuildingAnchor(marker, p) {
    cancelActiveEdit();
    map.getContainer().style.cursor = 'grab';
    marker.dragging.enable();
    const cancel = function () { marker.dragging.disable(); map.getContainer().style.cursor = ''; };
    activeEdit = { type: 'building_anchor', layer: marker, cancel };
    showEditBar(
      '⬜ Moving building anchor <strong>' + esc(p.name || p.id) + '</strong> — drag to new position',
      null,
      function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); }
    );
    marker.once('dragend', async function () {
      const ll = marker.getLatLng();
      cancel(); hideEditBar(); activeEdit = null;
      try {
        const resp = await apiPut(API.buildingAnchor + '?id=' + encodeURIComponent(p.id), {
          geojson: { type: 'Point', coordinates: [ll.lng, ll.lat] },
        });
        const data = await resp.json();
        toast(data.ok ? 'Building anchor moved' : 'Save failed: ' + data.error, data.ok ? 'success' : 'danger');
      } catch (e) {
        toast('Error: ' + e.message, 'danger');
      } finally {
        loadFeatures(true);
      }
    });
  }

  async function deleteBuildingAnchor(id, name, marker) {
    if (!confirm(`Delete building anchor "${name || id}"?`)) return;
    try {
      const resp = await apiDelete(API.buildingAnchor + '?id=' + encodeURIComponent(id));
      const data = await resp.json();
      if (data.ok) {
        marker?.closePopup();
        toast('Building anchor deleted', 'success');
        loadFeatures(true);
      } else {
        toast(data.error || 'Delete failed', 'danger');
      }
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  function toggleBuildingAnchors() {
    buildingAnchorsVisible = !buildingAnchorsVisible;
    if (buildingAnchorsVisible) buildingAnchorGroup.addTo(map);
    else map.removeLayer(buildingAnchorGroup);
    document.getElementById('btnToggleBuildingAnchors')?.classList.toggle('tgl-off', !buildingAnchorsVisible);
    saveLayerState();
  }

  function startAddMst() {
    cancelActiveEdit();
    pendingDrawMode = 'mst';
    // Custom tooltip text and cursor icon for MST placement
    map.pm.setLang('mst', { tooltips: { placeMarker: 'Click to place MST' } }, 'en');
    map.pm.enableDraw('Marker', {
      snappable: false,
      markerStyle: { icon: mstIcon(STATUS_COLORS.plan, false, iconScale()) },
    });
    showEditBar(
      '&#x2B22; Click map to place the MST',
      null,
      function () {
        map.pm.disableDraw();
        map.pm.setLang('en');
        pendingDrawMode = 'fosc';
        hideEditBar();
      }
    );
  }

  function _applyClosureSnapHighlight(cursorPt) {
    let bestMarker = null, bestDist = 28;
    foscGroup.eachLayer(m => {
      if (!m._tinIcon) return;
      // A closure hidden by a layer filter (FlexTIN taps, work order, Salisbury)
      // must not be a snap target — you can't see what you'd be snapping to.
      const el = m.getElement();
      if (el && el.style.display === 'none') return;
      const pt = map.latLngToContainerPoint(m.getLatLng());
      const d  = Math.hypot(pt.x - cursorPt.x, pt.y - cursorPt.y);
      if (d < bestDist) { bestDist = d; bestMarker = m; }
    });
    if (bestMarker === _closureSnapMarker) return;
    _clearClosureSnapHighlight();
    _closureSnapMarker = bestMarker;
    if (bestMarker) {
      const ti = bestMarker._tinIcon;
      bestMarker.setIcon(foscIconForKind(ti.kind, ti.status, foscDisplayColor(ti, bestMarker._foscId), true, iconScale(), !!ti.damaged, ti.angle || 0, ti.fanSlot || 0));
    }
  }

  function _clearClosureSnapHighlight() {
    if (!_closureSnapMarker) return;
    const ti = _closureSnapMarker._tinIcon;
    if (ti) _closureSnapMarker.setIcon(foscIconForKind(ti.kind, ti.status, foscDisplayColor(ti, _closureSnapMarker._foscId), false, iconScale(), !!ti.damaged, ti.angle || 0, ti.fanSlot || 0));
    _closureSnapMarker = null;
  }

  function exitSpanDrawMode() {
    drawingSpan = false;
    _spanPickEndMode = false;
    _spanPickEndGeom = null;
    _spanFromFoscId  = null;
    _spanFromFosc    = null;
    map.getContainer().classList.remove('fpm-span-drawing');
    if (_spanFirstVertCheck && _spanWorkingLayer) {
      _spanWorkingLayer.off('pm:vertexadded', _spanFirstVertCheck);
    }
    _spanFirstVertCheck = null;
    _spanWorkingLayer = null;
    if (_spanDrawMouseMove)  { map.off('mousemove', _spanDrawMouseMove); _spanDrawMouseMove = null; }
    _clearClosureSnapHighlight();
    clearRouteSnapHighlight();
    map.getContainer().style.cursor = '';
    map.pm.disableDraw();
    hideEditBar();
    loadFeatures(true);
  }

  function startDrawSpan() {
    cancelActiveEdit();
    drawingSpan = true;
    map.getContainer().classList.add('fpm-span-drawing');
    map.getContainer().style.cursor = 'crosshair';

    _spanDrawMouseMove = function (e) {
      _applyClosureSnapHighlight(map.latLngToContainerPoint(e.latlng));
    };
    map.on('mousemove', _spanDrawMouseMove);

    _spanFirstVertCheck = function () {
      // Detach from working layer (fired once, but clean up explicitly)
      if (_spanWorkingLayer) _spanWorkingLayer.off('pm:vertexadded', _spanFirstVertCheck);
      _spanFirstVertCheck = null;
      _spanWorkingLayer = null;

      if (!_closureSnapMarker) {
        exitSpanDrawMode();
        toast('Start must be at a closure — hover over one until it highlights, then click', 'warning');
        return;
      }
      _spanFromFoscId = _closureSnapMarker._foscId;
      _spanFromFosc   = foscData.find(f => f.fosc_id === _spanFromFoscId) || null;
      _clearClosureSnapHighlight(); // start confirmed; switch to pole+closure hybrid mode

      // Replace mousemove: poles for intermediate waypoints, closures for end selection
      map.off('mousemove', _spanDrawMouseMove);
      _spanDrawMouseMove = function (ev) {
        const cursorPt = map.latLngToContainerPoint(ev.latlng);
        // Find nearest non-start closure within 28 px
        let nearFoscMarker = null, bestD = 28;
        foscGroup.eachLayer(m => {
          if (!m._tinIcon || m._foscId === _spanFromFoscId) return;
          const pt = map.latLngToContainerPoint(m.getLatLng());
          const d  = Math.hypot(pt.x - cursorPt.x, pt.y - cursorPt.y);
          if (d < bestD) { bestD = d; nearFoscMarker = m; }
        });
        if (nearFoscMarker) {
          clearRouteSnapHighlight();
          if (nearFoscMarker !== _closureSnapMarker) {
            _clearClosureSnapHighlight();
            _closureSnapMarker = nearFoscMarker;
            const ti = nearFoscMarker._tinIcon;
            nearFoscMarker.setIcon(foscIconForKind(ti.kind, ti.status, foscDisplayColor(ti, nearFoscMarker._foscId), true, iconScale(), !!ti.damaged, ti.angle || 0, ti.fanSlot || 0));
          }
        } else {
          _clearClosureSnapHighlight();
          applyRouteSnapHighlight(cursorPt);
        }
      };
      map.on('mousemove', _spanDrawMouseMove);
    };

    // pm:vertexadded fires on the Geoman working layer, NOT the map.
    // pm:drawstart (which fires on the map) gives us the working layer reference.
    map.once('pm:drawstart', function (startEvt) {
      _spanWorkingLayer = startEvt.workingLayer;
      _spanWorkingLayer.once('pm:vertexadded', _spanFirstVertCheck);
    });

    map.pm.enableDraw('Line', { snappable: false });
    showEditBar(
      '╌ Hover a <strong>closure</strong> to highlight it, click to start &mdash; click intermediate points &mdash; double-click a highlighted <strong>closure</strong> to finish',
      null,
      exitSpanDrawMode
    );
  }

  function onPmCreate(e) {
    hideEditBar();

    if (e.shape === 'Marker') {
      map.pm.disableDraw(); // stop continuous draw so pointer events are restored
      map.removeLayer(e.layer);
      if (pendingDrawMode === 'mst') {
        pendingDrawMode = 'fosc';
        map.pm.setLang('en');
        pendingMstLatLng = e.layer.getLatLng();
        document.getElementById('mstCoordDisplay').textContent =
          pendingMstLatLng.lat.toFixed(6) + ', ' + pendingMstLatLng.lng.toFixed(6);
        new bootstrap.Modal(document.getElementById('newMstModal')).show();
      } else if (pendingDrawMode === 'pole') {
        pendingDrawMode = 'fosc';
        openPoleModalForCreate(e.layer.getLatLng());
      } else if (pendingDrawMode === 'building_anchor') {
        pendingDrawMode = 'fosc';
        openBuildingAnchorModalForCreate(e.layer.getLatLng());
      } else if (pendingDrawMode === 'pop') {
        pendingDrawMode = 'fosc';
        openPopModalForCreate(e.layer.getLatLng());
      } else if (pendingDrawMode === 'note-move') {
        pendingDrawMode = 'fosc';
        hideEditBar();
        const latlng = e.layer.getLatLng();
        const props  = pendingMoveNoteProps;
        pendingMoveNoteProps = null;
        apiPut(API.note + '?id=' + props.note_id, {
          icon: props.icon,
          body: props.body || '',
          geojson: { type: 'Point', coordinates: [latlng.lng, latlng.lat] },
        }).then(r => r.json()).then(d => {
          if (d.ok) { toast('Note moved', 'success'); loadFeatures(true); }
          else toast('Move failed: ' + (d.error || 'unknown'), 'danger');
        }).catch(e2 => toast('Error: ' + e2.message, 'danger'));
      } else if (pendingDrawMode === 'wifi') {
        pendingDrawMode = 'fosc';
        const ll = e.layer.getLatLng();
        map.removeLayer(e.layer);
        wifiNewPopup(ll);
      } else if (pendingDrawMode === 'note') {
        pendingDrawMode  = 'fosc';
        pendingNoteLatLng = e.layer.getLatLng();
        editingNoteId    = 0;
        document.getElementById('noteBody').value = '';
        setNoteIconSelection('note');
        new bootstrap.Modal(document.getElementById('newNoteModal')).show();
      } else if (pendingDrawMode === 'vault') {
        pendingDrawMode = 'fosc';
        pendingVaultLatLng = e.layer.getLatLng();
        document.getElementById('vaultTypeInput').value  = pendingVaultType;
        document.getElementById('vaultTypeSelect').value = pendingVaultType;
        document.getElementById('vaultModalLabel').textContent =
          pendingVaultType === 'handhole' ? 'New Handhole'
          : pendingVaultType === 'pullbox' ? 'New Pullbox'
          : 'New Vault';
        editingVaultId = 0;
        new bootstrap.Modal(document.getElementById('newVaultModal')).show();
      } else {
        pendingFoscLatLng = e.layer.getLatLng();
        document.getElementById('foscCoordDisplay').textContent =
          pendingFoscLatLng.lat.toFixed(6) + ', ' + pendingFoscLatLng.lng.toFixed(6);
        new bootstrap.Modal(document.getElementById('newFoscModal')).show();
      }

    } else if (e.shape === 'Polygon' && pendingDrawMode === 'easement') {
      pendingDrawMode = 'fosc';
      const ring = e.layer.getLatLngs()[0].map(ll => [ll.lng, ll.lat]);
      map.removeLayer(e.layer);
      easementDrawn(ring);
    } else if (e.shape === 'Line' && pendingDrawMode === 'survey') {
      pendingDrawMode = 'fosc';
      const coords = e.layer.getLatLngs().map(ll => [ll.lng, ll.lat]);
      map.removeLayer(e.layer);
      surveyDrawn(coords);
    } else if (e.shape === 'Line' && pendingDrawMode === 'conduit') {
      pendingDrawMode = 'fosc';
      const latlngs = e.layer.getLatLngs();
      const coords  = latlngs.map(ll => [ll.lng, ll.lat]);
      map.removeLayer(e.layer);
      pendingConduitGeojson = { type: 'LineString', coordinates: coords };
      editingConduitId = 0;
      new bootstrap.Modal(document.getElementById('newConduitModal')).show();
    } else if (e.shape === 'Line') {
      // Save snap state before exitSpanDrawMode() wipes it
      const toFoscMarker  = _closureSnapMarker;
      const fromFosc      = _spanFromFosc;   // saved at first-vertex confirm; stable across viewport changes
      exitSpanDrawMode();

      const latlngs = e.layer.getLatLngs();
      const coords  = latlngs.map(ll => [ll.lng, ll.lat]);
      map.removeLayer(e.layer);

      if (!fromFosc) {
        toast('Start closure was not confirmed — please redraw the span', 'warning');
        return;
      }

      function _openSpanModal(toFosc) {
        document.getElementById('spanGeojsonInput').value =
          JSON.stringify({ type: 'LineString', coordinates: coords });
        pendingSpanFoscs = {
          fromId:   fromFosc.fosc_id,
          fromName: fromFosc.splice_num,
          toId:     toFosc.fosc_id,
          toName:   toFosc.splice_num,
          toKind:   toFosc.kind,
          endPoint: coords[coords.length - 1],
        };
        const fromEl = document.getElementById('spanFromDisplay');
        const toEl   = document.getElementById('spanToDisplay');
        if (fromEl) { fromEl.textContent = fromFosc.splice_num; fromEl.className = 'small py-1 fw-semibold text-success'; }
        if (toEl)   { toEl.textContent   = toFosc.splice_num;   toEl.className   = 'small py-1 fw-semibold text-success'; }
        new bootstrap.Modal(document.getElementById('newSpanModal')).show();
        updateSpanTapSection();
      }

      // If the cursor was over a closure at double-click time, use it directly
      if (toFoscMarker) {
        const toFosc = foscData.find(f => f.fosc_id === toFoscMarker._foscId);
        if (toFosc) { _openSpanModal(toFosc); return; }
      }

      // End wasn't on a highlighted closure — enter pick-end mode
      _spanPickEndMode = true;
      _spanPickEndGeom = coords;
      map.getContainer().classList.add('fpm-span-drawing'); // keep pointer-events suppressed
      const moveH = e2 => _applyClosureSnapHighlight(map.latLngToContainerPoint(e2.latlng));
      map.on('mousemove', moveH);
      showEditBar(
        '⚠ Double-click wasn\'t on a closure — hover over the <strong>end closure</strong> to highlight it, then click it',
        null,
        () => {
          _spanPickEndMode = false; _spanPickEndGeom = null;
          map.off('mousemove', moveH); _clearClosureSnapHighlight();
          map.getContainer().classList.remove('fpm-span-drawing');
          hideEditBar();
        }
      );
      map.once('click', () => {
        if (!_closureSnapMarker) { toast('Hover over a closure first to highlight it', 'warning'); return; }
        const toFosc = foscData.find(f => f.fosc_id === _closureSnapMarker._foscId);
        map.off('mousemove', moveH); _clearClosureSnapHighlight();
        map.getContainer().classList.remove('fpm-span-drawing');
        _spanPickEndMode = false; _spanPickEndGeom = null;
        hideEditBar();
        if (toFosc) _openSpanModal(toFosc);
      });
    }
  }

  // ── Edit bar overlay ─────────────────────────────────────────────────────────

  function showEditBar(msgHtml, onSave, onCancel, saveLabel) {
    const bar     = document.getElementById('mapEditBar');
    const msgEl   = document.getElementById('mapEditMsg');
    const actEl   = document.getElementById('mapEditActions');
    if (!bar) return;

    msgEl.innerHTML = msgHtml;
    actEl.innerHTML = '';

    if (onSave) {
      const saveBtn = document.createElement('button');
      saveBtn.className = 'btn btn-sm btn-success ms-2';
      saveBtn.textContent = saveLabel || 'Save Changes';
      saveBtn.addEventListener('click', onSave);
      actEl.appendChild(saveBtn);
    }

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-outline-secondary ms-1';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', onCancel);
    actEl.appendChild(cancelBtn);

    bar.style.display = '';
  }

  function hideEditBar() {
    const bar = document.getElementById('mapEditBar');
    if (bar) bar.style.display = 'none';
  }

  // ── API calls ────────────────────────────────────────────────────────────────

  async function moveFosc(foscId, latlng) {
    toast('Saving…', 'secondary');
    try {
      const resp = await apiPut(API.fosc + '?id=' + foscId, {
        geojson: { type: 'Point', coordinates: [latlng.lng, latlng.lat] },
      });
      const data = await resp.json();
      data.ok ? toast('Closure location saved', 'success')
              : toast('Save failed: ' + data.error, 'danger');
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    } finally {
      loadFeatures(true);  // preserve zoom/pan — user is already looking at the right area
    }
  }

  async function deletePole(fid, uid, marker) {
    const label = uid || ('Pole #' + fid);
    if (!confirm(`Delete ${label}? This cannot be undone.`)) return;
    try {
      const resp = await apiDelete(API.pole + '?id=' + encodeURIComponent(fid));
      const data = await resp.json();
      if (data.ok) {
        poleGroup.removeLayer(marker);
        delete poleMarkerByFid[fid];
        toast('Pole deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  async function deleteFosc(foscId, spliceNum, marker) {
    if (!(await confirmDeleteAsset('fosc', foscId, `Delete closure ${spliceNum}? This will fail if spans, splices, or devices are still connected.`))) return;
    toast('Deleting…', 'secondary');
    try {
      const resp = await apiDelete(API.fosc + '?id=' + foscId);
      const data = await resp.json();
      if (data.ok) {
        foscGroup.removeLayer(marker);
        toast('Closure deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  async function editSpan(spanId, geojson) {
    toast('Saving route…', 'secondary');
    try {
      const resp = await apiPut(API.span + '?id=' + spanId, { geojson });
      const data = await resp.json();
      data.ok ? toast('Span route saved', 'success')
              : toast('Save failed: ' + data.error, 'danger');
      if (data.ok) loadFeatures(true);
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  // Geometry-only save — the conduit PUT leaves the property columns alone when
  // the body carries geojson without `name` (see api/conduit.php).
  async function editConduitRoute(conduitId, geojson) {
    toast('Saving route…', 'secondary');
    try {
      const resp = await apiPut(API.conduit + '?id=' + conduitId, { geojson });
      const data = await resp.json();
      data.ok ? toast('Conduit route saved', 'success')
              : toast('Save failed: ' + data.error, 'danger');
      if (data.ok) loadFeatures(true);
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  async function deleteSpan(spanId, line) {
    if (!confirm(`Delete Span #${spanId}? This cannot be undone.`)) return;
    toast('Deleting…', 'secondary');
    try {
      const resp = await apiDelete(API.span + '?id=' + spanId);
      const data = await resp.json();
      if (data.ok) {
        spanGroup.removeLayer(line);
        toast('Span deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) {
      toast('Error: ' + e.message, 'danger');
    }
  }

  // ── Split span ───────────────────────────────────────────────────────────────

  function openSplitSpanModal(p) {
    const infoEl = document.getElementById('splitSpanInfo');
    if (infoEl) {
      infoEl.textContent = `Span #${p.cable_span_id}: ${p.from_splice_num} → ${p.to_splice_num}`;
    }
    document.getElementById('splitSpanId').value = p.cable_span_id;
    document.getElementById('splitSpliceNum').value = '';
    document.getElementById('splitTypeId').value = '';
    const errEl = document.getElementById('splitSpanModalError');
    if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }

    const modal = new bootstrap.Modal(document.getElementById('splitSpanModal'));
    modal.show();

    document.getElementById('saveSplitSpanBtn').onclick = () => saveSpanSplit(modal);
  }

  async function saveSpanSplit(modal) {
    const spanId    = parseInt(document.getElementById('splitSpanId').value, 10);
    const spliceNum = document.getElementById('splitSpliceNum').value.trim().toUpperCase();
    const typeId    = document.getElementById('splitTypeId').value;
    const errEl     = document.getElementById('splitSpanModalError');

    showErr(errEl, '');
    if (!spliceNum) { showErr(errEl, 'Splice number is required.'); return; }
    if (!/^([A-Z]{3}[0-9]{4}|[LR][0-9]{2}(-MST)?)$/.test(spliceNum)) {
      showErr(errEl, 'Invalid splice number format (e.g. ABC1234, L01, R04-MST).'); return;
    }
    if (!typeId) { showErr(errEl, 'Closure type is required.'); return; }

    try {
      const resp = await apiPost(API.splitSpan, {
        cable_span_id:    spanId,
        splice_num:       spliceNum,
        closure_type_id:  parseInt(typeId, 10),
      });
      const data = await resp.json();
      if (data.ok) {
        modal.hide();
        toast('Span split — new closure ' + spliceNum + ' created', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) {
      showErr(errEl, 'Error: ' + e.message);
    }
  }

  async function saveNewFosc() {
    const spliceNum = document.getElementById('foscSpliceNum').value.trim().toUpperCase();
    const status    = document.getElementById('foscStatus').value;
    const kind      = document.getElementById('foscKind').value;
    const typeId    = document.getElementById('foscTypeId').value;
    const notes     = document.getElementById('foscNotes').value.trim();
    const fpmWor = (document.getElementById('foscFpmWor')?.value || '').trim();
    const errEl     = document.getElementById('foscModalError');

    showErr(errEl, '');
    if (!pendingFoscLatLng) { showErr(errEl, 'No location placed.'); return; }
    if (!spliceNum)          { showErr(errEl, 'Splice number is required.'); return; }
    if (!typeId)             { showErr(errEl, 'Type is required.'); return; }

    try {
      const resp = await apiPost(API.fosc, {
        splice_num: spliceNum, status, kind, closure_type_id: typeId, notes, fpm_wor: fpmWor,
        geojson: { type: 'Point', coordinates: [pendingFoscLatLng.lng, pendingFoscLatLng.lat] },
      });
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newFoscModal')).hide();
        toast('Closure created', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  // The slack-length field appears only when Kind is "Slack loop" — it is the one
  // field that is meaningless on every other kind.
  function _toggleSlackLenField() {
    const wrap = document.getElementById('editFoscSlackWrap');
    const kind = document.getElementById('editFoscKind');
    if (wrap && kind) wrap.style.display = kind.value === 'slack' ? '' : 'none';
  }
  document.getElementById('editFoscKind')?.addEventListener('change', _toggleSlackLenField);

  function openEditFoscModal(p) {
    document.getElementById('editFoscId').value        = String(p.fosc_id);
    document.getElementById('editFoscSpliceNum').value = p.splice_num || '';
    document.getElementById('editFoscStatus').value    = p.status || 'plan';
    document.getElementById('editFoscFpmWor').value = p.fpm_wor || '';
    document.getElementById('editFoscNotes').value     = p.notes || '';
    document.getElementById('editFoscKind').value      = p.kind || 'closure';
    // Slack length only means anything at a slack point; blank shows the 30 m
    // placeholder so it is obvious what leaving it empty gets you.
    const slackLen = document.getElementById('editFoscSlackLen');
    if (slackLen) slackLen.value = p.slack_length_m != null ? String(p.slack_length_m) : '';
    _toggleSlackLenField();
    document.getElementById('editFoscTypeId').value    = String(p.closure_type_id || '');
    document.getElementById('editFoscRating').value    = p.enclosure_rating || 'outside';
    document.getElementById('editFoscPopId').value     = String(p.pop_id || 0);
    document.getElementById('editFoscStreetAddress').value = p.street_address || '';
    const openLink = document.getElementById('editFoscOpenPage');
    if (openLink) openLink.href = '/index.php?page=closure&fosc_id=' + p.fosc_id;
    // Additional permits = every work order except the primary (fpm_wor).
    _foscExtraPermits = (p.work_orders || []).filter(w => w && w !== (p.fpm_wor || ''));
    const foscAddInp = document.getElementById('foscExtraPermitAdd'); if (foscAddInp) foscAddInp.value = '';
    _renderFoscExtraChips();
    showErr(document.getElementById('editFoscModalError'), '');
    const modal = new bootstrap.Modal(document.getElementById('editFoscModal'));
    document.getElementById('saveEditFoscBtn').onclick = () => saveEditFosc(modal);
    modal.show();
  }

  async function saveEditFosc(modal) {
    const id        = parseInt(document.getElementById('editFoscId').value, 10);
    const spliceNum = document.getElementById('editFoscSpliceNum').value.trim().toUpperCase();
    const status    = document.getElementById('editFoscStatus').value;
    const fpmWor = document.getElementById('editFoscFpmWor').value.trim();
    const notes     = document.getElementById('editFoscNotes').value.trim();
    const errEl     = document.getElementById('editFoscModalError');

    showErr(errEl, '');
    try {
      const payload = {
        status, notes, fpm_wor: fpmWor, extra_work_orders: _foscExtraPermits,
        kind:             document.getElementById('editFoscKind').value,
        closure_type_id:  parseInt(document.getElementById('editFoscTypeId').value, 10) || 0,
        enclosure_rating: document.getElementById('editFoscRating').value,
        pop_id:           parseInt(document.getElementById('editFoscPopId').value, 10) || 0,
        street_address:   document.getElementById('editFoscStreetAddress').value.trim(),
      };
      // Only meaningful on a slack point; '' clears it back to the standard coil.
      if (document.getElementById('editFoscKind').value === 'slack') {
        payload.slack_length_m = document.getElementById('editFoscSlackLen').value.trim();
      }
      if (spliceNum) payload.splice_num = spliceNum;
      const resp = await apiPatch(API.fosc + '?id=' + id, payload);
      const data = await resp.json();
      if (data.ok) {
        modal.hide();
        toast('Closure updated', 'success');
        loadPermitList();
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function openEditSpanModal(p) {
    document.getElementById('editSpanId').value          = String(p.cable_span_id);
    document.getElementById('editSpanFpmWor').value   = p.fpm_wor || '';
    // Additional permits = every work order except the primary (fpm_wor).
    _spanExtraPermits = (p.work_orders || []).filter(w => w && w !== (p.fpm_wor || ''));
    const spanAddInp = document.getElementById('spanExtraPermitAdd'); if (spanAddInp) spanAddInp.value = '';
    _renderSpanExtraChips();
    document.getElementById('editSpanNotes').value       = p.notes || '';
    document.getElementById('editSpanCableSerial').value = p.cable_serial || '';
    document.getElementById('editSpanIsSpare').checked    = !!p.is_spare;
    document.getElementById('editSpanIsPrivate').checked  = !!p.is_private;
    document.getElementById('editSpanInstalled').checked  = !!p.installed;
    document.getElementById('editSpanMaterial').value    = p.material_id != null ? String(p.material_id) : '';
    document.getElementById('editSpanFibreCount').value  = p.fibre_count != null ? String(p.fibre_count) : '';

    // Read-only context block — everything already lives in the span feature props.
    const set = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };
    set('editSpanInfoId', `#${esc(p.cable_span_id)}`);
    set('editSpanInfoCable',
      `${esc(p.cable_name || '—')} <span class="text-muted">· ${esc(p.fibre_count)}F</span>` +
      (p.open_ended ? ` <span class="badge bg-danger">open-ended</span>` : ''));
    set('editSpanInfoRoute', `${esc(p.from_splice_num || '?')} &rarr; ${esc(p.to_splice_num || '?')}`);
    set('editSpanInfoLength', p.length_m != null ? `${esc(Math.round(p.length_m))} m` : '<span class="text-muted">N/A</span>');
    const statusColor = { 'active': 'success', 'plan': 'secondary', 'under construction': 'warning' }[p.span_status] || 'secondary';
    set('editSpanInfoStatus', `<span class="badge bg-${statusColor}">${esc(p.span_status || 'unknown')}</span>`);

    showErr(document.getElementById('editSpanModalError'), '');
    const modal = new bootstrap.Modal(document.getElementById('editSpanModal'));
    document.getElementById('saveEditSpanBtn').onclick = () => saveEditSpan(modal);
    modal.show();
  }

  async function saveEditSpan(modal) {
    const id        = parseInt(document.getElementById('editSpanId').value, 10);
    const fpmWor = document.getElementById('editSpanFpmWor').value.trim();
    const notes     = document.getElementById('editSpanNotes').value.trim();
    const cableSerial = document.getElementById('editSpanCableSerial').value.trim();
    const isSpare     = document.getElementById('editSpanIsSpare').checked;
    const isPrivate   = document.getElementById('editSpanIsPrivate').checked;
    const isInstalled = document.getElementById('editSpanInstalled').checked;
    const materialRaw = document.getElementById('editSpanMaterial').value;
    const fibreRaw    = document.getElementById('editSpanFibreCount').value.trim();
    const errEl     = document.getElementById('editSpanModalError');

    showErr(errEl, '');
    const fibreCount = fibreRaw === '' ? null : parseInt(fibreRaw, 10);
    if (fibreCount !== null && (!Number.isInteger(fibreCount) || fibreCount < 1)) {
      showErr(errEl, 'Fibre count must be a positive whole number.');
      return;
    }
    try {
      const resp = await apiPatch(API.span + '?id=' + id, {
        notes, fpm_wor: fpmWor, extra_work_orders: _spanExtraPermits, cable_serial: cableSerial,
        is_spare: isSpare, is_private: isPrivate, installed: isInstalled,
        material_id: materialRaw === '' ? null : parseInt(materialRaw, 10),
        fibre_count: fibreCount,
      });
      const data = await resp.json();
      if (data.ok) {
        modal.hide();
        // Say what the permit pulled in with it, so inheritance is never silent.
        toast(data.inherited_summary
                ? 'Span updated — permit also applied to ' + data.inherited_summary
                : 'Span updated', 'success');
        loadPermitList();
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function openPoleModalForCreate(latlng) {
    pendingPoleLatLng = latlng;
    const title = document.getElementById('poleModalLabel');
    if (title) title.textContent = 'New Pole';
    const idInput = document.getElementById('poleIdInput');
    if (idInput) idInput.value = '';
    const coord = document.getElementById('poleCoordDisplay');
    if (coord) coord.textContent = latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6);
    const typeEl = document.getElementById('poleType');
    if (typeEl) typeEl.value = 'wood';
    const ownerEl = document.getElementById('poleOwner');
    if (ownerEl) ownerEl.value = '';
    const nbpEl = document.getElementById('poleNbp');
    if (nbpEl) nbpEl.value = '';
    const telcoEl = document.getElementById('poleTelco');
    if (telcoEl) telcoEl.value = '';
    const tagsEl = document.getElementById('poleTags');
    if (tagsEl) tagsEl.value = '';
    const woEl = document.getElementById('poleWorkOrder');
    if (woEl) woEl.value = '';
    _poleExtraPermits = [];
    _renderExtraPermitChips();
    const bpEl = document.getElementById('poleBellPermit');
    if (bpEl) bpEl.value = '';
    const uidEl = document.getElementById('poleUid');
    if (uidEl) uidEl.value = '';
    const commentEl = document.getElementById('poleComment');
    if (commentEl) commentEl.value = '';
    showErr(document.getElementById('poleModalError'), '');
    new bootstrap.Modal(document.getElementById('poleModal')).show();
  }

  function openPoleModalForEdit(p) {
    pendingPoleLatLng = { lat: Number(p.lat), lng: Number(p.lon) };
    const title = document.getElementById('poleModalLabel');
    if (title) title.textContent = 'Edit Pole';
    const idInput = document.getElementById('poleIdInput');
    if (idInput) idInput.value = String(p.fid);
    const coord = document.getElementById('poleCoordDisplay');
    if (coord) coord.textContent = `${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}`;
    const uidEl = document.getElementById('poleUid');
    if (uidEl) uidEl.value = String(p.uid || '');
    const typeEl = document.getElementById('poleType');
    if (typeEl) typeEl.value = String(p.type || 'wood');
    const ownerEl = document.getElementById('poleOwner');
    if (ownerEl) ownerEl.value = String(p.owner || '');
    const nbpEl = document.getElementById('poleNbp');
    if (nbpEl) nbpEl.value = String(p.nbp_num || '');
    const telcoEl = document.getElementById('poleTelco');
    if (telcoEl) telcoEl.value = String(p.telco_num || '');
    const tagsEl = document.getElementById('poleTags');
    if (tagsEl) tagsEl.value = String(p.tags_num || '');
    const woEl = document.getElementById('poleWorkOrder');
    if (woEl) woEl.value = String(p.work_order || '');
    _poleExtraPermits = (p.work_orders || []).filter(wo => wo !== p.work_order);
    _renderExtraPermitChips();
    const bpEl = document.getElementById('poleBellPermit');
    if (bpEl) bpEl.value = String(p.bell_permit_nums ? p.bell_permit_nums.join(', ') : (p.bell_permit_num || ''));
    const commentEl = document.getElementById('poleComment');
    if (commentEl) commentEl.value = String(p.comment || '');
    showErr(document.getElementById('poleModalError'), '');
    new bootstrap.Modal(document.getElementById('poleModal')).show();
  }

  async function savePole() {
    const id      = (document.getElementById('poleIdInput')?.value || '').trim();
    const uid     = (document.getElementById('poleUid')?.value || '').trim().toUpperCase();
    const type    = (document.getElementById('poleType')?.value || '').trim() || 'wood';
    const owner   = (document.getElementById('poleOwner')?.value || '').trim();
    const nbpNum  = (document.getElementById('poleNbp')?.value || '').trim();
    const telcoNum = (document.getElementById('poleTelco')?.value || '').trim();
    const tagsNum   = (document.getElementById('poleTags')?.value || '').trim();
    const workOrder = (document.getElementById('poleWorkOrder')?.value || '').trim();
    const extraWorkOrders = _poleExtraPermits.slice();
    const comment   = (document.getElementById('poleComment')?.value || '').trim();
    const errEl   = document.getElementById('poleModalError');

    showErr(errEl, '');
    if (!pendingPoleLatLng || !isFinite(pendingPoleLatLng.lat) || !isFinite(pendingPoleLatLng.lng)) {
      showErr(errEl, 'No pole location provided.');
      return;
    }
    if (uid.length > 10)      { showErr(errEl, 'UID must be 10 characters or less.'); return; }
    if (type.length > 80)     { showErr(errEl, 'Type must be 80 characters or less.'); return; }
    if (owner.length > 15)    { showErr(errEl, 'Owner must be 15 characters or less.'); return; }
    if (nbpNum.length > 18)   { showErr(errEl, 'NBP# must be 18 characters or less.'); return; }
    if (telcoNum.length > 18) { showErr(errEl, 'Telco# must be 18 characters or less.'); return; }
    if (tagsNum.length > 30)  { showErr(errEl, 'Tags# must be 30 characters or less.'); return; }
    if (comment.length > 254) { showErr(errEl, 'Notes must be 254 characters or less.'); return; }

    const payload = {
      uid,
      type,
      owner,
      nbp_num: nbpNum,
      telco_num:  telcoNum,
      work_order: workOrder,
      extra_work_orders: extraWorkOrders,
      tags_num: tagsNum,
      comment,
      geojson: { type: 'Point', coordinates: [pendingPoleLatLng.lng, pendingPoleLatLng.lat] },
    };

    try {
      const resp = id
        ? await apiPut(API.pole + '?id=' + encodeURIComponent(id), payload)
        : await apiPost(API.pole, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('poleModal')).hide();
        toast(id ? 'Pole updated' : 'Pole created', 'success');
        loadPermitList();
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) {
      showErr(errEl, 'Error: ' + e.message);
    }
  }

  async function saveNewSpan() {
    const cableId   = document.getElementById('spanCableId').value;
    const notes     = document.getElementById('spanNotes').value.trim();
    const fpmWor = (document.getElementById('spanFpmWor')?.value || '').trim();
    const errEl     = document.getElementById('spanModalError');
    let   geojson = null;

    showErr(errEl, '');
    if (!cableId)                  { showErr(errEl, 'Cable is required.'); return; }
    if (!pendingSpanFoscs?.fromId) {
      showErr(errEl, 'Start point not near a closure — redraw starting at a closure.'); return;
    }

    const cable       = (cfg.cables || []).find(c => String(c.cable_id) === String(cableId));
    const isOpenEnded = !!(cable?.open_ended);
    const rawToId     = pendingSpanFoscs?.toId   || null;
    const toKind      = pendingSpanFoscs?.toKind || null;
    // For open-ended cables, only accept an existing optitap-kind FOSC as the "to" endpoint
    const effectiveToId = rawToId && (!isOpenEnded || toKind === 'optitap') ? rawToId : null;

    if (!effectiveToId && !isOpenEnded) {
      showErr(errEl, 'End point not near a closure — redraw ending at a closure.'); return;
    }
    if (!effectiveToId && isOpenEnded) {
      const tapNum = (document.getElementById('spanTapNum')?.value || '').trim().toUpperCase();
      if (!tapNum) { showErr(errEl, 'Tap number is required (e.g. L01, R04).'); return; }
      if (!/^[LR][0-9]{2}$/.test(tapNum)) {
        showErr(errEl, 'Tap number must be L or R followed by 2 digits (e.g. L01, R04).'); return;
      }
    }

    try { geojson = JSON.parse(document.getElementById('spanGeojsonInput').value); } catch (_) {}

    let payload;
    if (effectiveToId) {
      // Normal: both endpoints matched to existing FOSCs
      payload = {
        cable_id:     cableId,
        from_fosc_id: pendingSpanFoscs.fromId,
        to_fosc_id:   effectiveToId,
        notes,
        fpm_wor:   fpmWor,
        geojson,
      };
    } else {
      // Open-ended: auto-create optitap FOSC at the drawn endpoint
      const tapNum    = document.getElementById('spanTapNum').value.trim().toUpperCase();
      const tapStatus = document.getElementById('spanTapStatus')?.value || 'plan';
      payload = {
        cable_id:     cableId,
        from_fosc_id: pendingSpanFoscs.fromId,
        tap_num:      tapNum,
        tap_status:   tapStatus,
        to_point:     { type: 'Point', coordinates: pendingSpanFoscs.endPoint },
        notes,
        fpm_wor:   fpmWor,
        geojson,
      };
    }

    try {
      const resp = await apiPost(API.span, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newSpanModal')).hide();
        toast('Span created', 'success');
        loadPermitList();
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  // ── fetch helpers ────────────────────────────────────────────────────────────

  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
      body: JSON.stringify(body),
    });
  }

  function apiPut(url, body) {
    return fetch(url, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
      body: JSON.stringify(body),
    });
  }

  function apiPatch(url, body) {
    return fetch(url, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
      body: JSON.stringify(body),
    });
  }

  function apiDelete(url) {
    return fetch(url, {
      method: 'DELETE',
      headers: { 'X-CSRF-Token': cfg.csrf || '' },
    });
  }

  // ── modal reset helpers ──────────────────────────────────────────────────────

  function resetFoscModal() {
    pendingFoscLatLng = null;
    ['foscSpliceNum', 'foscNotes', 'foscFpmWor'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('foscStatus').value  = 'plan';
    document.getElementById('foscKind').value    = 'closure';
    document.getElementById('foscTypeId').value  = '';
    document.getElementById('foscCoordDisplay').textContent = '—';
    showErr(document.getElementById('foscModalError'), '');
  }

  function resetPoleModal() {
    pendingPoleLatLng = null;
    const idInput = document.getElementById('poleIdInput');
    if (idInput) idInput.value = '';
    const title = document.getElementById('poleModalLabel');
    if (title) title.textContent = 'New Pole';
    const uidEl = document.getElementById('poleUid');
    if (uidEl) uidEl.value = '';
    const typeEl = document.getElementById('poleType');
    if (typeEl) typeEl.value = 'wood';
    const ownerEl = document.getElementById('poleOwner');
    if (ownerEl) ownerEl.value = '';
    const nbpEl = document.getElementById('poleNbp');
    if (nbpEl) nbpEl.value = '';
    const telcoEl = document.getElementById('poleTelco');
    if (telcoEl) telcoEl.value = '';
    const tagsEl = document.getElementById('poleTags');
    if (tagsEl) tagsEl.value = '';
    const woEl2 = document.getElementById('poleWorkOrder');
    if (woEl2) woEl2.value = '';
    const commentEl = document.getElementById('poleComment');
    if (commentEl) commentEl.value = '';
    const coordEl = document.getElementById('poleCoordDisplay');
    if (coordEl) coordEl.textContent = '—';
    showErr(document.getElementById('poleModalError'), '');
  }

  async function saveMst() {
    const spliceNum = document.getElementById('mstSpliceNum').value.trim().toUpperCase();
    const status    = document.getElementById('mstStatus').value;
    const ports     = parseInt(document.querySelector('input[name="mstPorts"]:checked')?.value || '0', 10);
    const notes     = document.getElementById('mstNotes').value.trim();
    const errEl     = document.getElementById('mstModalError');

    showErr(errEl, '');
    if (!pendingMstLatLng)       { showErr(errEl, 'No location placed.'); return; }
    if (!spliceNum)               { showErr(errEl, 'Splice number is required.'); return; }
    if (![2, 4].includes(ports)) { showErr(errEl, 'Select port count.'); return; }

    try {
      const resp = await apiPost(API.mst, {
        splice_num: spliceNum,
        status,
        ports,
        notes,
        geojson: { type: 'Point', coordinates: [pendingMstLatLng.lng, pendingMstLatLng.lat] },
      });
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newMstModal')).hide();
        toast('MST created', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function resetMstModal() {
    pendingMstLatLng = null;
    const spliceEl = document.getElementById('mstSpliceNum');
    if (spliceEl) spliceEl.value = '';
    const notesEl = document.getElementById('mstNotes');
    if (notesEl) notesEl.value = '';
    const statusEl = document.getElementById('mstStatus');
    if (statusEl) statusEl.value = 'plan';
    const ports4 = document.getElementById('mstPorts4');
    if (ports4) ports4.checked = true;
    const ports2 = document.getElementById('mstPorts2');
    if (ports2) ports2.checked = false;
    const coordEl = document.getElementById('mstCoordDisplay');
    if (coordEl) coordEl.textContent = '—';
    showErr(document.getElementById('mstModalError'), '');
  }

  // Shows/hides the optitap tap-number section based on cable type + endpoint detection.
  // For open-ended cables, only an existing optitap-kind FOSC counts as a valid "to" endpoint.
  function updateSpanTapSection() {
    const cableId     = document.getElementById('spanCableId')?.value || '';
    const cable       = (cfg.cables || []).find(c => String(c.cable_id) === String(cableId));
    const isOpenEnded = !!(cable?.open_ended);
    const rawToId     = pendingSpanFoscs?.toId   || null;
    const toKind      = pendingSpanFoscs?.toKind || null;
    // For open-ended cables, only accept optitap-kind FOSCs as the "to" endpoint
    const effectiveToId = rawToId && (!isOpenEnded || toKind === 'optitap') ? rawToId : null;
    const hasToFosc     = !!effectiveToId;
    const tapSection    = document.getElementById('spanTapSection');
    const toEl          = document.getElementById('spanToDisplay');

    if (tapSection) {
      tapSection.style.display = (isOpenEnded && !hasToFosc) ? '' : 'none';
    }
    if (toEl && isOpenEnded && !hasToFosc) {
      toEl.textContent = 'new optitap (endpoint)';
      toEl.className   = 'small py-1 fw-semibold text-info';
    } else if (toEl && pendingSpanFoscs) {
      toEl.textContent = effectiveToId ? pendingSpanFoscs.toName : 'not detected';
      toEl.className   = 'small py-1 fw-semibold ' +
        (effectiveToId ? 'text-success' : 'text-danger');
    }
  }

  function resetSpanModal() {
    pendingSpanFoscs = null;
    ['spanCableId', 'spanNotes', 'spanFpmWor', 'spanGeojsonInput']
      .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const fromEl = document.getElementById('spanFromDisplay');
    const toEl   = document.getElementById('spanToDisplay');
    if (fromEl) { fromEl.textContent = 'draw to detect'; fromEl.className = 'small py-1 text-muted fst-italic'; }
    if (toEl)   { toEl.textContent   = 'draw to detect'; toEl.className   = 'small py-1 text-muted fst-italic'; }
    const tapSection = document.getElementById('spanTapSection');
    if (tapSection) tapSection.style.display = 'none';
    const tapNum = document.getElementById('spanTapNum');
    if (tapNum) tapNum.value = '';
    const tapStatus = document.getElementById('spanTapStatus');
    if (tapStatus) tapStatus.value = 'plan';
    showErr(document.getElementById('spanModalError'), '');
  }

  // ── utilities ────────────────────────────────────────────────────────────────

  // Returns the closest FOSC in foscData to (lat, lon), with .d = squared degree distance.
  // Pass kindFilter (e.g. 'optitap') to restrict results to that kind.
  function nearestFosc(lat, lon, kindFilter) {
    const pool = kindFilter ? foscData.filter(f => f.kind === kindFilter) : foscData;
    if (!pool.length) return null;
    return pool.reduce(function (best, f) {
      const d = Math.pow(f.lat - lat, 2) + Math.pow(f.lon - lon, 2);
      return (!best || d < best.d) ? { fosc_id: f.fosc_id, splice_num: f.splice_num, lat: f.lat, lon: f.lon, kind: f.kind, d } : best;
    }, null);
  }

  function showErr(el, msg) {
    if (!el) return;
    el.style.display = msg ? '' : 'none';
    el.textContent   = msg;
  }

  function toast(msg, type) {
    const c = document.getElementById('mapToastContainer');
    if (!c) return;
    const id = 'maptst-' + Date.now();
    c.insertAdjacentHTML('beforeend',
      `<div id="${id}" class="toast align-items-center text-bg-${type || 'info'} border-0" role="alert">
         <div class="d-flex">
           <div class="toast-body">${msg}</div>
           <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
         </div>
       </div>`);
    const el = document.getElementById(id);
    new bootstrap.Toast(el, { delay: 3000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  // ── FlexTIN run ──────────────────────────────────────────────────────────────

  function startFlextin() {
    cancelActiveEdit();
    const sel = document.getElementById('flextinFoscId');
    if (!sel) return;
    sel.innerHTML = '<option value="">— select closure —</option>';
    foscData
      .filter(f => f.kind !== 'optitap')
      .sort((a, b) => a.splice_num.localeCompare(b.splice_num))
      .forEach(f => {
        const opt = document.createElement('option');
        opt.value       = f.fosc_id;
        opt.textContent = f.splice_num;
        sel.appendChild(opt);
      });
    new bootstrap.Modal(document.getElementById('newFlextinModal')).show();
  }

  function updateFlextinBearing() {
    const foscId         = document.getElementById('flextinFoscId')?.value;
    const bearingSection = document.getElementById('flextinBearingSection');
    const previewEl      = document.getElementById('flextinPreview');

    if (!foscId) {
      if (bearingSection) bearingSection.style.display = 'none';
      if (previewEl)      previewEl.style.display      = 'none';
      return;
    }

    const fosc = foscData.find(f => String(f.fosc_id) === String(foscId));
    if (!fosc) return;

    const bearings = computePoleAxis(fosc.lat, fosc.lon);
    if (!bearings) {
      if (bearingSection) bearingSection.style.display = 'none';
      toast('Not enough nearby poles to compute bearing axis.', 'warning');
      return;
    }

    const [bA, bB] = bearings;
    const labelA   = document.getElementById('flextinBearingALabel');
    const labelB   = document.getElementById('flextinBearingBLabel');
    const radioA   = document.getElementById('flextinBearingA');
    const radioB   = document.getElementById('flextinBearingB');

    if (labelA) labelA.textContent = bearingLabel(bA);
    if (labelB) labelB.textContent = bearingLabel(bB);
    if (radioA) { radioA.dataset.bearing = bA; radioA.checked = true; }
    if (radioB) { radioB.dataset.bearing = bB; radioB.checked = false; }

    if (bearingSection) bearingSection.style.display = '';
    updateFlextinPreview();
  }

  function updateFlextinPreview() {
    const previewEl = document.getElementById('flextinPreview');
    if (!previewEl) return;

    const foscId    = document.getElementById('flextinFoscId')?.value;
    const cableId   = document.getElementById('flextinCableId')?.value;
    const direction = document.getElementById('flextinDirection')?.value || 'L';
    const model     = 'A';

    const radioA      = document.getElementById('flextinBearingA');
    const radioB      = document.getElementById('flextinBearingB');
    const selRadio    = radioA?.checked ? radioA : (radioB?.checked ? radioB : null);
    const bearing     = selRadio ? parseFloat(selRadio.dataset.bearing || '') : null;

    if (!foscId || !cableId || bearing == null || isNaN(bearing)) {
      previewEl.style.display = 'none';
      return;
    }

    const fosc  = foscData.find(f => String(f.fosc_id) === String(foscId));
    const cable = (cfg.cables || []).find(c => String(c.cable_id) === String(cableId));
    if (!fosc || !cable) { previewEl.style.display = 'none'; return; }

    const poles  = polesInBearingDir(fosc.lat, fosc.lon, bearing, model);
    const needed = cable.fibre_count || 4;

    if (poles.length < needed) {
      previewEl.className   = 'alert alert-warning py-2 px-3 small mb-2';
      previewEl.textContent =
        `Warning: only ${poles.length} pole(s) found in that direction, need ${needed}.`;
    } else {
      const start = direction + '01';
      const end   = direction + String(needed).padStart(2, '0');
      previewEl.className   = 'alert alert-info py-2 px-3 small mb-2';
      previewEl.textContent = `Will create ${start}\u2013${end} at ${needed} pole(s).`;
    }
    previewEl.style.display = '';
  }

  async function saveFlextinRun() {
    const foscId    = document.getElementById('flextinFoscId')?.value;
    const cableId   = document.getElementById('flextinCableId')?.value;
    const direction = document.getElementById('flextinDirection')?.value || 'L';
    const model     = 'A';
    const notes     = document.getElementById('flextinNotes')?.value.trim() || '';
    const errEl     = document.getElementById('flextinModalError');

    const radioA   = document.getElementById('flextinBearingA');
    const radioB   = document.getElementById('flextinBearingB');
    const selRadio = radioA?.checked ? radioA : (radioB?.checked ? radioB : null);
    const bearing  = selRadio ? parseFloat(selRadio.dataset.bearing || '') : null;

    showErr(errEl, '');
    if (!foscId)                          { showErr(errEl, 'Closure is required.');          return; }
    if (!cableId)                         { showErr(errEl, 'Cable is required.');            return; }
    if (bearing == null || isNaN(bearing)) { showErr(errEl, 'Bearing direction is required.'); return; }

    try {
      const resp = await apiPost(API.flextin, {
        cable_id:     parseInt(cableId, 10),
        from_fosc_id: parseInt(foscId,  10),
        bearing,
        direction,
        model,
        notes,
      });
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newFlextinModal')).hide();
        toast(
          `FlexTIN run created: ${data.taps_created} tap(s), ${data.spans_created} span(s)`,
          'success'
        );
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function resetFlextinModal() {
    const foscSel = document.getElementById('flextinFoscId');
    if (foscSel) foscSel.value = '';
    const cableSel = document.getElementById('flextinCableId');
    if (cableSel) cableSel.value = '';
    const bearingSection = document.getElementById('flextinBearingSection');
    if (bearingSection) bearingSection.style.display = 'none';
    const radioA = document.getElementById('flextinBearingA');
    const radioB = document.getElementById('flextinBearingB');
    if (radioA) { radioA.checked = false; radioA.dataset.bearing = ''; }
    if (radioB) { radioB.checked = false; radioB.dataset.bearing = ''; }
    const dirSel = document.getElementById('flextinDirection');
    if (dirSel) dirSel.value = 'L';
    const preview = document.getElementById('flextinPreview');
    if (preview) preview.style.display = 'none';
    const notes = document.getElementById('flextinNotes');
    if (notes) notes.value = '';
    showErr(document.getElementById('flextinModalError'), '');
  }

  // Every layer toggle and the label mode, as one object. Persisted to
  // localStorage on each change, and shipped to the PDF renderer so its fresh
  // browser starts the map with the same layers the user is looking at.
  function layerState() {
    return { poles: polesVisible, labels: labelsVisible, minLabels: minLabelsMode, declutter: declutterLabels, onus: onuVisible, alden: aldenVisible, aldenLinked: aldenLinkedVisible, spans: spansVisible, closures: closuresVisible, pops: popsVisible, anchors: anchorsVisible, buildingAnchors: buildingAnchorsVisible, uguards: uguardsVisible, conduits: conduitsVisible, vaults: vaultsVisible, handholes: handholesVisible, notes: notesVisible, doneNotes: doneNotesVisible, abandoned: abandonedVisible, dims: dimsVisible, marks: marksVisible, easements: easementsVisible, wifi: wifiVisible, strands: strandsVisible, flextinDist: flextinDistVisible, flextinClosures: flextinClosuresVisible, routeDist: routeDistVisible, scale: scaleVisible, density: densityVisible, civics: civicVisible, parcels: parcelVisible, wetlands: wetlandVisible, communities: communityVisible, zoning: zoningVisible, boundary: boundaryVisible, exch: exchVisible, roadCov: roadCovVisible, outsideRoads: outsideRoadsVisible, svcArea: serviceAreaVisible,
      salisburyOnly: salisburyOnlyMode };
  }
  function saveLayerState() {
    try { localStorage.setItem(MAP_LAYERS_KEY, JSON.stringify(layerState())); } catch {}
  }

  // ── Toolbar flyout panels ────────────────────────────────────────────────────
  // One panel is open at a time (or none). The rail stays put; only the flyout
  // beside it changes, so the toolbar's footprint never depends on how many tools
  // a section holds.

  const TOOLBAR_PANELS = {
    add:      'Add',
    layers:   'Layers',
    coverage: 'Coverage & Competition',
  };

  let openToolbarPanel = null;

  // After a layer toggle the panel lingers for this long, then fades. Long enough
  // to find the next layer, short enough that the map is not hidden behind it.
  const FLYOUT_AUTO_CLOSE_MS = 4000;
  let flyoutCloseTimer = null;

  function cancelFlyoutAutoClose() {
    if (flyoutCloseTimer) { clearTimeout(flyoutCloseTimer); flyoutCloseTimer = null; }
    document.getElementById('tinFlyout')?.classList.remove('fading');
  }

  function armFlyoutAutoClose() {
    cancelFlyoutAutoClose();
    flyoutCloseTimer = setTimeout(function () {
      flyoutCloseTimer = null;
      const flyout = document.getElementById('tinFlyout');
      if (!flyout || !openToolbarPanel) return;
      flyout.classList.add('fading');
      // The transition is CSS; hide for real once it has run. A click meanwhile
      // cancels it (see cancelFlyoutAutoClose) and the panel snaps back.
      flyoutCloseTimer = setTimeout(function () {
        flyoutCloseTimer = null;
        if (flyout.classList.contains('fading')) setToolbarPanel(null);
      }, 700);
    }, FLYOUT_AUTO_CLOSE_MS);
  }

  function setToolbarPanel(name) {
    if (name && !TOOLBAR_PANELS[name]) return;
    openToolbarPanel = name || null;
    cancelFlyoutAutoClose();

    const flyout = document.getElementById('tinFlyout');
    const title  = document.getElementById('tinFlyoutTitle');
    if (!flyout) return;

    document.querySelectorAll('.tin-rail-btn[data-panel]').forEach(function (b) {
      b.classList.toggle('active', b.dataset.panel === openToolbarPanel);
    });
    Object.keys(TOOLBAR_PANELS).forEach(function (key) {
      const panel = document.getElementById('toolbarSection' + key.charAt(0).toUpperCase() + key.slice(1));
      if (panel) panel.style.display = (key === openToolbarPanel) ? '' : 'none';
    });

    flyout.style.display = openToolbarPanel ? '' : 'none';
    if (openToolbarPanel && title) title.textContent = TOOLBAR_PANELS[openToolbarPanel];
    saveToolbarPanel();
  }

  // ── Command palette ──────────────────────────────────────────────────────────
  // One searchable list of every toolbar command, on Ctrl/Cmd+K. Commands are
  // read out of the toolbar DOM at open time and run by clicking the real button,
  // so the palette can never drift out of step with the toolbar or duplicate a
  // handler's side effects.

  let cmdPaletteEl = null;
  let cmdMatches   = [];
  let cmdActiveIdx = 0;

  function cmdPaletteOpen() { return !!cmdPaletteEl && cmdPaletteEl.style.display !== 'none'; }

  function ensureCmdPalette() {
    if (cmdPaletteEl) return cmdPaletteEl;
    const el = document.createElement('div');
    el.id = 'tinCmdPalette';
    el.style.display = 'none';
    el.innerHTML =
      `<input id="tinCmdInput" type="text" autocomplete="off" spellcheck="false" ` +
        `placeholder="Search commands — e.g. conduit, measure, poles">` +
      `<div id="tinCmdList"></div>`;
    document.body.appendChild(el);

    el.querySelector('#tinCmdInput').addEventListener('input', function () {
      renderCmdResults(this.value);
    });
    el.querySelector('#tinCmdInput').addEventListener('keydown', onCmdPaletteKeydown);
    el.querySelector('#tinCmdList').addEventListener('click', function (e) {
      const row = e.target.closest('[data-cmd]');
      if (row) runCmd(row.dataset.cmd);
    });
    // Click anywhere else dismisses it — except on the rail button that opened it.
    // Without that exception the button could never close the palette: mousedown
    // closed it, then the click immediately reopened it.
    document.addEventListener('mousedown', function (e) {
      if (!cmdPaletteOpen()) return;
      if (cmdPaletteEl.contains(e.target)) return;
      if (e.target.closest && e.target.closest('#btnCmdPalette')) return;
      closeCmdPalette();
    });
    cmdPaletteEl = el;
    return el;
  }

  // Every enabled toolbar button, with the panel it lives in. Buttons hidden by
  // context gating (e.g. the road-coverage sub-tools) are genuinely unavailable
  // and stay out of the list.
  function collectCommands() {
    const out = [];
    Object.keys(TOOLBAR_PANELS).forEach(function (key) {
      const panel = document.getElementById('toolbarSection' + key.charAt(0).toUpperCase() + key.slice(1));
      if (!panel) return;
      panel.querySelectorAll('button[id]').forEach(function (btn) {
        if (btn.style.display === 'none') return;
        const label = (btn.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label) return;
        out.push({
          id:      btn.id,
          label:   label,
          section: TOOLBAR_PANELS[key],
          toggle:  !!btn.querySelector('.tgl-dot'),
          on:      !btn.classList.contains('tgl-off'),
        });
      });
    });
    return out;
  }

  function renderCmdResults(query) {
    const terms = (query || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
    cmdMatches = collectCommands().filter(function (c) {
      const hay = (c.label + ' ' + c.section).toLowerCase();
      return terms.every(t => hay.includes(t));
    });
    cmdActiveIdx = 0;

    const list = cmdPaletteEl.querySelector('#tinCmdList');
    if (!cmdMatches.length) {
      list.innerHTML = `<div class="tin-cmd-empty">No matching command</div>`;
      return;
    }
    list.innerHTML = cmdMatches.map(function (c, i) {
      const state = c.toggle
        ? `<span class="tin-cmd-state ${c.on ? 'on' : 'off'}">${c.on ? 'on' : 'off'}</span>` : '';
      return `<div class="tin-cmd-row${i === 0 ? ' active' : ''}" data-cmd="${c.id}">` +
             `<span class="tin-cmd-label">${esc(c.label)}</span>${state}` +
             `<span class="tin-cmd-section">${esc(c.section)}</span></div>`;
    }).join('');
  }

  function highlightCmd(idx) {
    const rows = cmdPaletteEl.querySelectorAll('.tin-cmd-row');
    if (!rows.length) return;
    cmdActiveIdx = (idx + rows.length) % rows.length;
    rows.forEach((r, i) => r.classList.toggle('active', i === cmdActiveIdx));
    rows[cmdActiveIdx].scrollIntoView({ block: 'nearest' });
  }

  function onCmdPaletteKeydown(e) {
    if (e.key === 'Escape')    { e.stopPropagation(); closeCmdPalette(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightCmd(cmdActiveIdx + 1); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); highlightCmd(cmdActiveIdx - 1); return; }
    if (e.key === 'Enter') {
      e.preventDefault();
      const hit = cmdMatches[cmdActiveIdx];
      if (hit) runCmd(hit.id);
    }
  }

  function runCmd(id) {
    closeCmdPalette();
    document.getElementById(id)?.click();
  }

  function openCmdPalette() {
    const el = ensureCmdPalette();
    // Clear any layer filter first, so a stale filter can't hide commands.
    const lf = document.getElementById('layerFilterInput');
    if (lf && lf.value) { lf.value = ''; filterLayerList(''); }
    el.style.display = '';
    document.getElementById('btnCmdPalette')?.classList.add('active');
    const inp = el.querySelector('#tinCmdInput');
    inp.value = '';
    renderCmdResults('');
    inp.focus();
  }

  function closeCmdPalette() {
    if (cmdPaletteEl) cmdPaletteEl.style.display = 'none';
    document.getElementById('btnCmdPalette')?.classList.remove('active');
  }

  // Hide layer rows that don't match the query. Subgroup headings disappear with
  // their last visible row so the list never shows an empty heading.
  function filterLayerList(query) {
    const q = (query || '').trim().toLowerCase();
    document.querySelectorAll('#toolbarSectionLayers .tin-layer-grid').forEach(function (grid) {
      let anyVisible = false;
      grid.querySelectorAll('button').forEach(function (btn) {
        const hit = !q || btn.textContent.toLowerCase().includes(q);
        btn.style.display = hit ? '' : 'none';
        if (hit) anyVisible = true;
      });
      grid.style.display = anyVisible ? '' : 'none';
      const sub = grid.previousElementSibling;
      if (sub && sub.classList.contains('tin-layer-sub')) sub.style.display = anyVisible ? '' : 'none';
    });
  }

  // `tgl-off` is the single source of truth for a layer button's state, so click
  // only the ones that actually need flipping. The flag stops those synthetic
  // clicks from tripping the "picking something closes the flyout" rule — you are
  // driving the panel here, not leaving it.
  let bulkLayerToggle = false;

  function setAllLayers(on) {
    bulkLayerToggle = true;
    try {
      document.querySelectorAll('#toolbarSectionLayers .tin-layer-grid button').forEach(function (btn) {
        // The Labels control cycles through three positions, so clicking it blindly
        // would land wherever it happened to be — set it outright instead.
        if (btn.dataset.tinCycle) return;
        // Declutter is a rendering rule, not a layer — "All off" should not switch
        // it off and leave it off for every layer you turn back on afterwards.
        if (btn.dataset.tinBulkSkip) return;
        if (btn.classList.contains('tgl-off') === on) btn.click();
      });
      setLabelState(on ? 'all' : 'off');
    } finally {
      bulkLayerToggle = false;
    }
  }

  function saveToolbarPanel() {
    try { localStorage.setItem(MAP_TOOLBAR_SECTIONS_KEY, JSON.stringify({ open: openToolbarPanel })); } catch {}
  }

  // Opens closed by default: the map is the point, the tools are on demand.
  function restoreToolbarPanel() {
    let open = null;
    try {
      const raw = localStorage.getItem(MAP_TOOLBAR_SECTIONS_KEY);
      if (raw) {
        const saved = JSON.parse(raw);
        if (saved && typeof saved.open === 'string') open = saved.open;
      }
    } catch {}
    setToolbarPanel(open);
  }

  function populateMicroductTypeSelect() {
    const sel = document.getElementById('conduitMicroductType');
    if (!sel || !window._microductTypes || !window._microductTypes.length) return;
    window._microductTypes.forEach(function (t) {
      const opt = document.createElement('option');
      opt.value = String(t.id);
      opt.textContent = t.name;
      sel.appendChild(opt);
    });
  }

  // Labelling was two buttons that had to be read together — a master "Labels"
  // switch and a "Min labels" one that lit up green while hiding things, in a panel
  // where green means shown everywhere else. They were never independent: what they
  // really encode is one setting with three positions, so they are now one control
  // that names its position instead of leaving you to decode two dots.
  //
  //   All — everything, including pole and tap numbers, vaults, civic numbers
  //   Key — what an engineer reads a drawing for: closure and slack-loop splice
  //         numbers, POP names, fibre counts, span distances
  //   Off — no lettering on the map at all
  //
  // The two underlying flags are unchanged, so the saved setting, the CSS classes
  // and the plan/print views carry on reading exactly what they always did.
  const LABEL_STATES = ['all', 'key', 'off'];

  function labelState() {
    if (!labelsVisible) return 'off';
    return minLabelsMode ? 'key' : 'all';
  }

  // Key mode hides most lettering with the .min-labels class, but pole numbers are
  // ALSO unbound in refreshPoleLabels(): they are the bulk of the label DOM (~2400
  // permanent tooltips, animated on every zoom), and display:none would still pay
  // for them. Hence the refreshPoleLabels() call on every transition.
  function setLabelState(state) {
    labelsVisible = (state !== 'off');
    minLabelsMode = (state === 'key');
    map.getContainer().classList.toggle('fosc-labels-hidden', !labelsVisible);
    map.getContainer().classList.toggle('min-labels', minLabelsMode);
    syncLabelCycleButton();
    refreshPoleLabels();
    scheduleLabelDeclutter();
    saveLayerState();
  }

  // ── Label declutter ─────────────────────────────────────────────────────────
  // Each label type is placed by its own rule and none of them can see the others:
  // closure names hang off their icon, fibre counts sit every 250 m along a cable,
  // distances sit at a segment's midpoint. Where two land on the same pixels the
  // later one in the DOM paints over the earlier and both become unreadable.
  //
  // So: a screen-space pass, most important type first, keeping what fits and
  // hiding what would land on something already kept. Zooming in spreads the map
  // out and the hidden ones come back on their own — the same way a paper drawing
  // gets more lettering as the scale grows.
  //
  // Tiers are least-droppable first. Within a tier it is DOM order, which is
  // render order, which is stable between runs — so a label does not flicker in
  // and out as you pan.
  const DECLUTTER_TIERS = [
    // Closure / POP names: the thing the whole drawing is indexed by.
    // :not(.drop-hover-label) — that one only exists while the pointer is on the
    // drop line, and hiding what you are pointing at is never the right call.
    '.fosc-label:not(.fosc-label--tap):not(.fosc-label--slack):not(.pole-telco-label):not(.vault-label):not(.drop-hover-label)',
    '.conduit-len-label',     // a surveyed duct length — one duct, one number
    '.span-fibre-label',      // fibre counts — what a span IS
    '.route-dist-label',      // measured distances — you build from these
    '.fosc-label--slack',     // slack loop numbers
    '.vault-label',
    '.flextin-dist-label',    // annotation, already off in Labels: Key
    '.fosc-label--tap',       // tap numbers — tiny and readable only zoomed right in
    '.pole-telco-label',
    // Civic numbers are deliberately absent: their text overflows a 0x0 divIcon,
    // so the element measures 0x0 and there is no rect to collide with.
  ];
  const DECLUTTER_PAD = 1;    // px of breathing room between two kept labels

  // Point assets are obstacles as well: a label lying across a pole, closure or
  // vault icon hides the very thing the drawing is about. The asset always wins —
  // it is the data, the label is annotation — so an icon can only ever drop a
  // label, never the other way round. Same groups the Salisbury filter walks,
  // plus the read-only overlays (ONUs, Alden candidates).
  //
  // This applies ONLY to the free-floating labels — fibre counts, distances, duct
  // lengths — which are placed against a LINE and can land anywhere. It must never
  // apply to a tooltip: a tooltip is deliberately anchored a few px off its own
  // icon, so it overlaps that icon by construction and an icon rule would delete
  // every asset's own name. Tooltips still declutter against other labels.
  const _iconObstacleGroups = () => [
    poleGroup, foscGroup, popGroup, anchorGroup, buildingAnchorGroup, uguardGroup,
    vaultGroup, handholeGroup, noteGroup, onuGroup, aldenGroup, aldenVaultGroup,
  ];

  // Marker icons carry transparent margin (drop shadows, the pin's point), and a
  // label grazing that edge still reads fine. Pull each icon rect in a little so
  // only a real overlap counts.
  const ICON_OBSTACLE_INSET = 2;   // px

  let _declutterTimer = null;

  function scheduleLabelDeclutter(delay = 120) {
    clearTimeout(_declutterTimer);
    _declutterTimer = setTimeout(applyLabelDeclutter, delay);
  }

  // Rects of every visible asset icon on screen.
  function _collectIconObstacles(view) {
    const out = [];
    _iconObstacleGroups().forEach(function (g) {
      if (!g) return;
      g.eachLayer(function (m) {
        if (!m.getLatLng) return;                       // lines and shapes are not icons
        const el = m.getElement && m.getElement();
        if (!el || !el.offsetParent) return;            // hidden by a layer toggle or filter
        const r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return;      // 0x0 divIcon: a label, not an asset
        if (r.right  <= view.left || r.left >= view.right ||
            r.bottom <= view.top  || r.top  >= view.bottom) return;
        out.push({
          left:  r.left  + ICON_OBSTACLE_INSET, right:  r.right  - ICON_OBSTACLE_INSET,
          top:   r.top   + ICON_OBSTACLE_INSET, bottom: r.bottom - ICON_OBSTACLE_INSET,
        });
      });
    });
    return out;
  }

  function applyLabelDeclutter() {
    const canvas = document.getElementById('mapCanvas');
    if (!canvas) return;

    // Always start from a clean slate: a label hidden last time may be the one
    // that fits now, and its rect reads as 0×0 while it is still hidden.
    canvas.querySelectorAll('.label-decluttered')
          .forEach(el => el.classList.remove('label-decluttered'));
    if (!declutterLabels) return;

    const view  = canvas.getBoundingClientRect();
    const kept  = [];
    const icons = _collectIconObstacles(view);

    DECLUTTER_TIERS.forEach(function (sel) {
      const els = canvas.querySelectorAll(sel);
      if (!els.length) return;
      // Read every rect in this tier before writing a single class — interleaving
      // reads and writes would force one layout per label instead of per tier.
      const rects = [];
      els.forEach(function (el) {
        // offsetParent is null when a layer toggle or filter already hid it.
        if (!el.offsetParent) { rects.push(null); return; }
        // A removed tooltip stays in the DOM for Leaflet's 200 ms fade-out at
        // inline opacity 0. After a feature reload every pole's old label is
        // such a ghost, sitting exactly where its replacement is bound — and
        // counted as kept, it blocked every fresh pole number on the sheet.
        if (el.style.opacity === '0') { rects.push(null); return; }
        const r = el.getBoundingClientRect();
        const onScreen = r.width > 0 && r.height > 0 &&
                         r.right  > view.left && r.left < view.right &&
                         r.bottom > view.top  && r.top  < view.bottom;
        rects.push(onScreen ? r : null);
      });
      els.forEach(function (el, i) {
        const r = rects[i];
        if (!r) return;
        // A tooltip belongs to an icon and sits against it on purpose, so only the
        // free-floating line labels are measured against the asset icons.
        const isTooltip = el.classList.contains('leaflet-tooltip');
        const blocked   = kept.some(k => _labelRectsOverlap(k, r)) ||
                          (!isTooltip && icons.some(o => _labelRectsOverlap(o, r)));
        if (blocked) el.classList.add('label-decluttered');
        else kept.push(r);
      });
    });
  }

  function _labelRectsOverlap(a, b) {
    return a.left  < b.right  + DECLUTTER_PAD &&
           b.left  < a.right  + DECLUTTER_PAD &&
           a.top   < b.bottom + DECLUTTER_PAD &&
           b.top   < a.bottom + DECLUTTER_PAD;
  }

  function toggleDeclutter() {
    declutterLabels = !declutterLabels;
    const btn = document.getElementById('btnToggleDeclutter');
    if (btn) btn.classList.toggle('tgl-off', !declutterLabels);
    applyLabelDeclutter();   // immediate: this one is a direct answer to a click
    saveLayerState();
  }

  function cycleLabelState() {
    const i = LABEL_STATES.indexOf(labelState());
    setLabelState(LABEL_STATES[(i + 1) % LABEL_STATES.length]);
  }

  // The dot stays lit for All and Key — both show lettering — and goes out for Off,
  // so it still reads the same way as every other row in the panel.
  function syncLabelCycleButton() {
    const st  = labelState();
    const btn = document.getElementById('btnCycleLabels');
    const txt = document.getElementById('btnCycleLabelsText');
    if (txt) txt.textContent = 'Labels: ' + { all: 'All', key: 'Key', off: 'Off' }[st];
    if (!btn) return;
    btn.classList.toggle('tgl-off', st === 'off');
    btn.title = {
      all: 'Labels: All — every label, including pole numbers, FlexTIN tap numbers, vault and handhole names, FlexTIN distances and civic numbers. Click for Key.',
      key: 'Labels: Key — only what an engineer reads a drawing for: closure and slack-loop splice numbers, POP names, fibre counts, span distances. Click for Off.',
      off: 'Labels: Off — no lettering on the map. Click for All.',
    }[st];
  }

  // ── ONU layer ────────────────────────────────────────────────────────────────

  function togglePoles() {
    polesVisible = !polesVisible;
    const btn = document.getElementById('btnTogglePoles');
    if (btn) btn.classList.toggle('tgl-off', !polesVisible);
    if (polesVisible) poleGroup.addTo(map);
    else map.removeLayer(poleGroup);
    // Pole numbers are bound per on-screen pole and were unbound while the layer
    // was off, so switching it back on has to re-bind them — otherwise the poles
    // return unlabelled until the next pan or zoom happens to run the sweep.
    refreshPoleLabels();
    saveLayerState();
  }

  // spanLabelGroup is the map's label group for linear features: span fibre
  // counts, and for a conduit both its fibre count and its measured length. So it
  // belongs on the map whenever either layer is on, and the container classes
  // below decide which of its labels are actually drawn.
  function spanLabelsWanted() { return spansVisible || conduitsVisible; }

  function toggleSpans() {
    spansVisible = !spansVisible;
    const btn = document.getElementById('btnToggleSpans');
    if (btn) btn.classList.toggle('tgl-off', !spansVisible);
    if (spansVisible) {
      // In the overview regime detail layers stay hidden; the flag is enough and
      // showDetailAssetLayers() will draw them when the user zooms back in.
      if (inOverview !== true) spanGroup.addTo(map);
    } else {
      map.removeLayer(spanGroup);
    }
    syncSpanLabelGroup();
    map.getContainer().classList.toggle('route-dist-span-hidden', !spansVisible);
    map.getContainer().classList.toggle('span-labels-hidden', !spansVisible);
    saveLayerState();
  }

  // Add or drop the shared label group to match the two layers that feed it.
  function syncSpanLabelGroup() {
    if (spanLabelsWanted() && inOverview !== true && map.getZoom() >= DETAIL_TEXT_ZOOM) spanLabelGroup.addTo(map);
    else map.removeLayer(spanLabelGroup);
  }

  function toggleClosures() {
    closuresVisible = !closuresVisible;
    const btn = document.getElementById('btnToggleClosures');
    if (btn) btn.classList.toggle('tgl-off', !closuresVisible);
    if (closuresVisible) foscGroup.addTo(map);
    else map.removeLayer(foscGroup);
    saveLayerState();
  }

  // FlexTIN taps are the optitaps the tail builder chains along the poles — on a
  // built-out street they outnumber every hand-placed closure and bury it. This
  // hides just those, inside the Closures layer, rather than all closures.
  //
  // Hiding is display-only (same as the pole/WO and Salisbury filters) because the
  // markers stay in foscGroup, which the snap, deep-link and neighbour lookups all
  // walk. It only ever hides: turning the taps back on hands visibility back to
  // whichever other filter is running, since one of them may want them hidden too.
  function applyFlextinClosureFilter() {
    if (flextinClosuresVisible || !foscGroup) return;
    foscGroup.eachLayer(function (m) {
      if (m._tinFlextinTap) setLayerHidden(m, true);
    });
  }

  function toggleFlextinClosures() {
    flextinClosuresVisible = !flextinClosuresVisible;
    const btn = document.getElementById('btnToggleFlextinClosures');
    if (btn) btn.classList.toggle('tgl-off', !flextinClosuresVisible);
    if (flextinClosuresVisible) {
      foscGroup.eachLayer(function (m) { if (m._tinFlextinTap) setLayerHidden(m, false); });
      if (poleFilterActive())  applyPoleFilter();
      if (salisburyOnlyMode)   applySalisburyFilter();
    } else {
      applyFlextinClosureFilter();
    }
    saveLayerState();
  }

  function togglePops() {
    popsVisible = !popsVisible;
    const btn = document.getElementById('btnTogglePops');
    if (btn) btn.classList.toggle('tgl-off', !popsVisible);
    if (popsVisible) popGroup.addTo(map);
    else map.removeLayer(popGroup);
    saveLayerState();
  }

  function toggleAnchors() {
    anchorsVisible = !anchorsVisible;
    const btn = document.getElementById('btnToggleAnchors');
    if (btn) btn.classList.toggle('tgl-off', !anchorsVisible);
    if (anchorsVisible) anchorGroup.addTo(map);
    else map.removeLayer(anchorGroup);
    saveLayerState();
  }

  function toggleUguards() {
    uguardsVisible = !uguardsVisible;
    const btn = document.getElementById('btnToggleUguards');
    if (btn) btn.classList.toggle('tgl-off', !uguardsVisible);
    if (uguardsVisible) uguardGroup.addTo(map);
    else map.removeLayer(uguardGroup);
    saveLayerState();
  }

  function toggleConduits() {
    conduitsVisible = !conduitsVisible;
    const btn = document.getElementById('btnToggleConduits');
    if (btn) btn.classList.toggle('tgl-off', !conduitsVisible);
    if (conduitsVisible) conduitGroup.addTo(map);
    else map.removeLayer(conduitGroup);
    // The length label rides in spanLabelGroup, so it neither comes on nor off
    // with the conduit layer by itself — a measurement must not outlive the duct
    // it measures, and it must appear when the duct does even if spans are off.
    syncSpanLabelGroup();
    map.getContainer().classList.toggle('conduit-labels-hidden', !conduitsVisible);
    saveLayerState();
  }

  function toggleVaults() {
    vaultsVisible = !vaultsVisible;
    const btn = document.getElementById('btnToggleVaults');
    if (btn) btn.classList.toggle('tgl-off', !vaultsVisible);
    if (vaultsVisible) vaultGroup.addTo(map);
    else map.removeLayer(vaultGroup);
    saveLayerState();
  }

  function toggleHandholes() {
    handholesVisible = !handholesVisible;
    const btn = document.getElementById('btnToggleHandholes');
    if (btn) btn.classList.toggle('tgl-off', !handholesVisible);
    if (handholesVisible) handholeGroup.addTo(map);
    else map.removeLayer(handholeGroup);
    saveLayerState();
  }

  // ── Map notes ────────────────────────────────────────────────────────────────

  function toggleNotes() {
    notesVisible = !notesVisible;
    const btn = document.getElementById('btnToggleNotes');
    if (btn) btn.classList.toggle('tgl-off', !notesVisible);
    if (notesVisible) noteGroup.addTo(map);
    else map.removeLayer(noteGroup);
    saveLayerState();
  }

  function toggleAbandoned() {
    abandonedVisible = !abandonedVisible;
    document.getElementById('btnToggleAbandoned')?.classList.toggle('tgl-off', !abandonedVisible);
    saveLayerState();
    loadFeatures(true);
    toast(abandonedVisible ? 'Showing abandoned work-order plant (grey, dashed)' : 'Abandoned work-order plant hidden', 'secondary');
  }

  // An item of an abandoned work order: a plain grey ghost with a tooltip, so it can be seen without being mistaken
  // for live plant. Restoring the work order (Permits page) draws it normally again.
  function renderAbandonedGhost(f) {
    const p = f.properties, g = f.geometry;
    if (!g) return;
    const name = p.splice_num || p.cable_name || p.name || p.nbp || p.uid || p.label || ('#' + (p.fid || p.id || ''));
    const tip = 'Abandoned · ' + String(p.feature_type || '').replace('_', ' ') + ' ' + name;
    const style = { color: '#6b7280', weight: 2, opacity: 0.55, dashArray: '5 5' };
    let layer = null;
    if (g.type === 'Point') {
      layer = L.circleMarker([g.coordinates[1], g.coordinates[0]], { radius: 5, color: '#6b7280', weight: 1.5, fillColor: '#d1d5db', fillOpacity: 0.7, dashArray: '3 2' });
    } else if (g.type === 'LineString' || g.type === 'MultiLineString') {
      layer = L.geoJSON(f, { style: () => style, interactive: true });
    }
    if (!layer) return;
    layer.bindTooltip(tip, { sticky: true });
    abandonedGroup.addLayer(layer);
  }

  function toggleDoneNotes() {
    doneNotesVisible = !doneNotesVisible;
    document.getElementById('btnToggleDoneNotes')?.classList.toggle('tgl-off', !doneNotesVisible);
    saveLayerState();
    loadFeatures(true);
  }

  async function setNoteDone(id, done) {
    try {
      const d = await (await apiPut(API.note + '?id=' + id, { done })).json();
      if (!d.ok) throw new Error(d.error || 'failed');
      map.closePopup();
      toast(done ? 'Marked done: hidden unless "Done notes" is on' : 'Reopened', 'success');
      loadFeatures(true);
    } catch (e) { toast('Could not update note: ' + e.message, 'danger'); }
  }

  async function setWoNotesDone(wor, icon, done) {
    if (!confirm((done ? 'Mark every open ' : 'Reopen every done ') + (icon === 'tree_trim' ? 'tree trim' : 'note') + ' on ' + wor + '?')) return;
    try {
      const d = await (await apiPut(API.note + '?wor=' + encodeURIComponent(wor) + '&icon=' + encodeURIComponent(icon), { done })).json();
      if (!d.ok) throw new Error(d.error || 'failed');
      map.closePopup();
      toast((done ? 'Marked done: ' : 'Reopened: ') + d.changed + ' on ' + wor, 'success');
      loadFeatures(true);
    } catch (e) { toast('Could not update notes: ' + e.message, 'danger'); }
  }

  function startPlaceNote() {
    cancelActiveEdit();
    pendingDrawMode = 'note';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      '&#x1F4CC; Click on the map to place a note',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  function setNoteIconSelection(val) {
    document.getElementById('noteIconValue').value = val;
    document.querySelectorAll('.note-icon-opt').forEach(function (el) {
      el.classList.toggle('active', el.dataset.val === val);
    });
  }

  function renderNote(feature) {
    const [lon, lat] = feature.geometry.coordinates;
    const p = feature.properties;
    if (p.done && !doneNotesVisible) return;   // finished: hidden unless "Done notes" is on
    const marker = L.marker([lat, lon], {
      icon: noteIcon(p.icon, poleScale(), noteLead()),
      zIndexOffset: 500,
      opacity: p.done ? 0.4 : 1,
    });
    marker._tinNote = p.icon || 'note';   // refreshIconSizes re-frames it per zoom
    marker._tinNoteWor = p.fpm_wor || '';
    marker.bindPopup(() => buildNotePopup(p), { minWidth: 200 });
    marker.on('popupopen', function () {
      document.getElementById('edit-note-' + p.note_id)
        ?.addEventListener('click', function () { marker.closePopup(); openNoteModalForEdit(p); });
      document.getElementById('move-note-' + p.note_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          pendingMoveNoteProps = p;
          pendingDrawMode = 'note-move';
          map.pm.enableDraw('Marker', { snappable: false });
          showEditBar(
            '&#x1F4CC; Click map to set new note location',
            null,
            function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; pendingMoveNoteProps = null; hideEditBar(); }
          );
        });
      document.getElementById('del-note-' + p.note_id)
        ?.addEventListener('click', function () { marker.closePopup(); deleteNote(p.note_id, marker); });
      document.getElementById('done-note-' + p.note_id)
        ?.addEventListener('click', function () { setNoteDone(p.note_id, !p.done); });
      document.getElementById('done-wo-' + p.note_id)
        ?.addEventListener('click', function () { setWoNotesDone(p.fpm_wor, p.icon, !p.done); });
    });
    noteGroup.addLayer(marker);
  }

  // A survey line: dashed magenta, with its plan details in an editable popup (Save, Redraw, Delete).
  // Tentative lines are replaced when the real parcel data arrives, so they can be drawn and removed here.
  function buildSurveyPopup(p) {
    const f = (id, label, val, type) => `<label class="small d-block mt-1">${label}<input id="sv-${id}-${p.id}" type="${type || 'text'}" class="form-control form-control-sm" value="${esc(val || '')}"></label>`;
    return `<div class="dim-popup" style="min-width:230px">
      <div class="fw-semibold mb-1">Survey line</div>
      ${f('label', 'Label', p.label)}${f('plan', 'Plan number', p.plan_no)}${f('date', 'Plan date', p.plan_date, 'date')}
      <label class="small d-block mt-1">Status<select id="sv-status-${p.id}" class="form-select form-select-sm">
        ${['tentative', 'final', 'superseded'].map(v => `<option${v === p.status ? ' selected' : ''}>${v}</option>`).join('')}</select></label>
      <label class="small d-block mt-1">Notes<textarea id="sv-notes-${p.id}" rows="3" class="form-control form-control-sm">${esc(p.notes || '')}</textarea></label>
      ${actRow([actBtn('save', `onclick="window._svSave(${p.id})"`, 'Save'), actBtn('route', `onclick="window._svRedraw(${p.id})"`, 'Redraw', 'Draw a new line to replace this one')],
               actBtn('del', `onclick="window._svDelete(${p.id})"`, 'Delete', 'Delete this survey line'))}
    </div>`;
  }

  function renderSurveyDraft(feature) {
    const p = feature.properties;
    const coords = (feature.geometry && feature.geometry.coordinates || []).map(c => [c[1], c[0]]);
    if (coords.length < 2) return;
    const line = L.polyline(coords, { color: '#c026d3', weight: 3, opacity: 0.9, dashArray: '8 6' });
    line.bindPopup(() => buildSurveyPopup(p), { minWidth: 250, maxWidth: 300 });
    line.bindTooltip(`${esc(p.label)} (${esc(p.status)})`, { sticky: true });
    surveyGroup.addLayer(line);
    coords.forEach(c => surveyGroup.addLayer(L.circleMarker(c, { radius: 3.5, color: '#c026d3', weight: 1.5, fillColor: '#fff', fillOpacity: 1, interactive: false })));
  }

  let surveyRedrawId = 0;
  function startDrawSurvey(redrawId) {
    cancelActiveEdit();
    surveyRedrawId = redrawId || 0;
    pendingDrawMode = 'survey';
    map.pm.enableDraw('Line', { snappable: false });
    showEditBar(redrawId ? 'Draw the new survey line: click points, double-click to finish' : 'Draw a survey line: click points, double-click to finish', null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; surveyRedrawId = 0; hideEditBar(); });
  }

  // A line was just drawn: replace an existing one, or ask for the details of a new one.
  async function surveyDrawn(coords) {
    const geojson = { type: 'LineString', coordinates: coords };
    hideEditBar();
    if (surveyRedrawId) {
      const id = surveyRedrawId; surveyRedrawId = 0;
      try {
        const d = await (await apiPut('/api/survey_draft.php?id=' + id, { geojson })).json();
        if (!d.ok) throw new Error(d.error || 'save failed');
        toast('Survey line redrawn', 'success'); loadFeatures(true);
      } catch (e) { toast(e.message, 'danger'); }
      return;
    }
    const mid = coords[Math.floor(coords.length / 2)];
    window._svNew = geojson;
    L.popup({ minWidth: 250 }).setLatLng([mid[1], mid[0]]).setContent(
      `<div class="dim-popup" style="min-width:230px"><div class="fw-semibold mb-1">New survey line</div>
        <label class="small d-block">Label<input id="sv-label-new" class="form-control form-control-sm" placeholder="e.g. Draft survey, north line of lot 26-1"></label>
        <label class="small d-block mt-1">Plan number<input id="sv-plan-new" class="form-control form-control-sm"></label>
        <label class="small d-block mt-1">Plan date<input id="sv-date-new" type="date" class="form-control form-control-sm" value="${new Date().toISOString().slice(0, 10)}"></label>
        <label class="small d-block mt-1">Status<select id="sv-status-new" class="form-select form-select-sm"><option>tentative</option><option>final</option></select></label>
        <label class="small d-block mt-1">Notes<textarea id="sv-notes-new" rows="2" class="form-control form-control-sm"></textarea></label>
        ${actRow([actBtn('save', 'onclick="window._svCreate()"', 'Save')])}</div>`).openOn(map);
  }

  const svVal = (k, id) => document.getElementById('sv-' + k + '-' + id)?.value ?? '';
  window._svCreate = async function () {
    try {
      const d = await (await apiPost('/api/survey_draft.php', { label: svVal('label', 'new'), plan_no: svVal('plan', 'new'), plan_date: svVal('date', 'new'),
        status: svVal('status', 'new'), notes: svVal('notes', 'new'), geojson: window._svNew })).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Survey line saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._svSave = async function (id) {
    try {
      const d = await (await apiPut('/api/survey_draft.php?id=' + id, { label: svVal('label', id), plan_no: svVal('plan', id), plan_date: svVal('date', id),
        status: svVal('status', id), notes: svVal('notes', id) })).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Survey line saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._svRedraw = function (id) { map.closePopup(); startDrawSurvey(id); };
  window._svDelete = async function (id) {
    if (!confirm('Delete this survey line?')) return;
    try {
      const d = await (await apiDelete('/api/survey_draft.php?id=' + id)).json();
      if (!d.ok) throw new Error(d.error || 'delete failed');
      map.closePopup(); toast('Survey line deleted', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };

  // A utility easement: an outlined polygon with its plan details, the metes (bearings and distances)
  // as printed on the plan, and an editable popup (Save, Redraw, Delete).
  const EASEMENT_TYPES = { utility: 'Utility', power: 'Power', telecom: 'Telecom', sewer: 'Sewer', water: 'Water', drainage: 'Drainage', access: 'Access', other: 'Other' };
  const EASEMENT_COLOURS = { utility: '#ea580c', power: '#dc2626', telecom: '#2563eb', sewer: '#92400e', water: '#0284c7', drainage: '#65a30d', access: '#7c3aed', other: '#6b7280' };
  const esVal = (k, id) => document.getElementById('es-' + k + '-' + id)?.value ?? '';
  const ftOf = (m) => m == null ? '' : +(m / 0.3048).toFixed(2);

  function easementFields(id, p) {
    const f = (k, label, val, type) => `<label class="small d-block mt-1">${label}<input id="es-${k}-${id}" type="${type || 'text'}" class="form-control form-control-sm" value="${esc(val == null ? '' : String(val))}"></label>`;
    const opts = (obj, cur) => Object.entries(obj).map(([v, l]) => `<option value="${v}"${v === cur ? ' selected' : ''}>${l}</option>`).join('');
    return `${f('label', 'Label', p.label)}
      <label class="small d-block mt-1">Type<select id="es-type-${id}" class="form-select form-select-sm">${opts(EASEMENT_TYPES, p.easement_type || 'utility')}</select></label>
      ${f('width', 'Width (feet)', ftOf(p.width_m), 'number')}${f('plan', 'Plan number', p.plan_no)}${f('date', 'Plan date', p.plan_date, 'date')}
      <label class="small d-block mt-1">Status<select id="es-status-${id}" class="form-select form-select-sm">
        ${['tentative', 'final', 'superseded'].map(v => `<option${v === p.status ? ' selected' : ''}>${v}</option>`).join('')}</select></label>
      <label class="small d-block mt-1">Notes<textarea id="es-notes-${id}" rows="${id === 'new' ? 2 : 4}" class="form-control form-control-sm">${esc(p.notes || '')}</textarea></label>`;
  }
  function easementBody(id) {
    return { label: esVal('label', id), easement_type: esVal('type', id), width_ft: esVal('width', id), plan_no: esVal('plan', id),
             plan_date: esVal('date', id), status: esVal('status', id), notes: esVal('notes', id) };
  }

  function buildEasementPopup(p) {
    const calls = (p.calls || []).map(c => {
      const d = c.dist_ft ? `${c.dist_ft} ft (${c.dist_m} m)` : (c.length_ft_approx ? `about ${c.length_ft_approx} ft (${c.length_m_approx} m)` : (c.plan_total_ft ? `plan total ${c.plan_total_ft} ft (${c.plan_total_m} m)` : ''));
      return `<li>${esc(c.line || '')}${c.bearing ? ' &middot; ' + esc(c.bearing) : ''}${d ? ' &middot; ' + d : ''}</li>`;
    }).join('');
    return `<div class="dim-popup" style="min-width:250px;max-width:300px">
      <div class="fw-semibold mb-1">${esc(EASEMENT_TYPES[p.easement_type] || 'Utility')} easement</div>
      <div class="small text-muted mb-1">${p.pid ? `Parcel PID ${esc(p.pid)} &middot; ` : ''}${p.width_m ? `${ftOf(p.width_m)} ft (${(+p.width_m).toFixed(2)} m) wide &middot; ` : ''}${Math.round(p.area_m2)} m&sup2; (${Math.round(p.area_m2 * 10.7639).toLocaleString()} ft&sup2;)</div>
      ${calls ? `<ul class="small ps-3 mb-1">${calls}</ul>` : ''}
      ${easementFields(p.id, p)}
      ${actRow([actBtn('save', `onclick="window._esSave(${p.id})"`, 'Save'), actBtn('route', `onclick="window._esRedraw(${p.id})"`, 'Redraw', 'Draw a new outline to replace this one')],
               actBtn('del', `onclick="window._esDelete(${p.id})"`, 'Delete', 'Delete this easement'))}
    </div>`;
  }

  function renderEasement(feature) {
    const p = feature.properties;
    const rings = ((feature.geometry && feature.geometry.coordinates) || []).map(r => r.map(c => [c[1], c[0]]));
    const ring = rings[0] || [];
    if (ring.length < 4) return;
    const col = EASEMENT_COLOURS[p.easement_type] || EASEMENT_COLOURS.other;
    const poly = L.polygon(rings, { pane: 'easementPane', color: col, weight: 1.5, opacity: 0.95, fillColor: col, fillOpacity: p.status === 'final' ? 0.45 : 0.3,
                                   dashArray: p.status === 'final' ? null : '5 4' });
    poly.bindPopup(() => buildEasementPopup(p), { minWidth: 270, maxWidth: 320 });
    poly.bindTooltip(`${esc(p.label)} (${esc(p.status)})`, { sticky: true });
    easementGroup.addLayer(poly);
    easementFeatures.push({ p, ring, col, band: rings.length > 1 });   // band: a ring round a lot, not a strip
    drawEasementLabels(easementFeatures[easementFeatures.length - 1]);
  }

  // Small labels repeated along each easement, spaced in screen pixels so a zoom in gets more of them
  // and a zoom out gets fewer (none under zoom 16). A strip's centre line is taken from its two long
  // sides (the ring is one side then the other back); a hand-drawn outline just gets one label at its centre.
  let easementFeatures = [], easementLabels = [];
  const EASEMENT_LABEL_MIN_ZOOM = 16, EASEMENT_LABEL_GAP_PX = 320;
  function drawEasementLabels(f) {
    if (!map || map.getZoom() < EASEMENT_LABEL_MIN_ZOOM) return;
    const z = map.getZoom();
    const pts = f.ring.slice(0, -1).map(c => map.project(L.latLng(c[0], c[1]), z));   // ring is [lat,lng]; last point repeats the first
    const n = pts.length;
    const text = (EASEMENT_TYPES[f.p.easement_type] || 'Utility') + ' easement';
    const place = (pt, ang) => {
      let deg = ang * 180 / Math.PI;
      if (deg > 90) deg -= 180; else if (deg < -90) deg += 180;       // keep the text upright
      const ll = map.unproject(pt, z);
      const m = L.marker(ll, { pane: 'easementPane', interactive: false, keyboard: false,
        icon: L.divIcon({ className: 'easement-label', html: `<span style="color:${f.col};transform:rotate(${deg.toFixed(1)}deg)">${text}</span>`, iconSize: [0, 0] }) });
      easementGroup.addLayer(m); easementLabels.push(m);
    };
    if (f.band) {                                                      // a band round a lot: one label, on its longest side, inside the band
      const mpp = 156543.03 * Math.cos(f.ring[0][0] * Math.PI / 180) / Math.pow(2, z);
      if (!f.p.width_m || f.p.width_m / mpp < 11) return;              // band too thin on screen to hold the text
      const cx = pts.reduce((a, q) => a + q.x, 0) / n, cy = pts.reduce((a, q) => a + q.y, 0) / n;
      let best = null, bestLen = 0;
      for (let i = 0; i < n; i++) { const a = pts[i], b = pts[(i + 1) % n], l = a.distanceTo(b); if (l > bestLen) { bestLen = l; best = [a, b]; } }
      if (!best || bestLen < 100) return;                              // side too short to hold the text
      const [a, b] = best, mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2, dx = (b.x - a.x) / bestLen, dy = (b.y - a.y) / bestLen;
      let nx = -dy, ny = dx; if ((cx - mx) * nx + (cy - my) * ny < 0) { nx = -nx; ny = -ny; }   // normal towards the lot
      const off = f.p.width_m / mpp / 2;
      place(L.point(mx + nx * off, my + ny * off), Math.atan2(b.y - a.y, b.x - a.x));
      return;
    }
    if (n < 4 || n % 2) {                                              // not a two-sided strip: centre of its box
      const xs = pts.map(q => q.x), ys = pts.map(q => q.y);
      place(L.point((Math.min(...xs) + Math.max(...xs)) / 2, (Math.min(...ys) + Math.max(...ys)) / 2), 0);
      return;
    }
    const mid = [];
    for (let i = 0; i < n / 2; i++) { const a = pts[i], b = pts[n - 1 - i]; mid.push(L.point((a.x + b.x) / 2, (a.y + b.y) / 2)); }
    const lens = [0];
    for (let i = 1; i < mid.length; i++) lens.push(lens[i - 1] + mid[i].distanceTo(mid[i - 1]));
    const total = lens[lens.length - 1];
    if (total < 90) return;                                            // too short to hold a label at this zoom
    const count = Math.max(1, Math.round(total / EASEMENT_LABEL_GAP_PX));
    for (let k = 0; k < count; k++) {
      const d = total * (k + 0.5) / count;
      let i = 1; while (i < lens.length - 1 && lens[i] < d) i++;
      const a = mid[i - 1], b = mid[i], t = (d - lens[i - 1]) / ((lens[i] - lens[i - 1]) || 1);
      place(L.point(a.x + (b.x - a.x) * t, a.y + (b.y - a.y) * t), Math.atan2(b.y - a.y, b.x - a.x));
    }
  }
  function redrawEasementLabels() {
    easementLabels.forEach(m => easementGroup.removeLayer(m));
    easementLabels = [];
    easementFeatures.forEach(drawEasementLabels);
  }

  let easementRedrawId = 0;
  function startDrawEasement(redrawId) {
    cancelActiveEdit();
    easementRedrawId = redrawId || 0;
    pendingDrawMode = 'easement';
    map.pm.enableDraw('Polygon', { snappable: false });
    showEditBar(redrawId ? 'Outline the new easement: click the corners, click the first corner to finish' : 'Outline an easement: click the corners, click the first corner to finish', null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; easementRedrawId = 0; hideEditBar(); });
  }

  async function easementDrawn(ring) {
    const geojson = { type: 'Polygon', coordinates: [ring.concat([ring[0]])] };
    hideEditBar();
    if (easementRedrawId) {
      const id = easementRedrawId; easementRedrawId = 0;
      try {
        const d = await (await apiPut('/api/easement.php?id=' + id, { geojson })).json();
        if (!d.ok) throw new Error(d.error || 'save failed');
        toast('Easement redrawn', 'success'); loadFeatures(true);
      } catch (e) { toast(e.message, 'danger'); }
      return;
    }
    const lat = ring.reduce((a, c) => a + c[1], 0) / ring.length, lng = ring.reduce((a, c) => a + c[0], 0) / ring.length;
    window._esNew = geojson;
    L.popup({ minWidth: 250 }).setLatLng([lat, lng]).setContent(
      `<div class="dim-popup" style="min-width:230px"><div class="fw-semibold mb-1">New easement</div>
        ${easementFields('new', { label: '', easement_type: 'utility', width_m: 3.048, plan_no: '', plan_date: new Date().toISOString().slice(0, 10), status: 'tentative', notes: '' })}
        ${actRow([actBtn('save', 'onclick="window._esCreate()"', 'Save')])}</div>`).openOn(map);
  }

  window._esCreate = async function () {
    try {
      const d = await (await apiPost('/api/easement.php', Object.assign(easementBody('new'), { geojson: window._esNew }))).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Easement saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._esSave = async function (id) {
    try {
      const d = await (await apiPut('/api/easement.php?id=' + id, easementBody(id))).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Easement saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._esRedraw = function (id) { map.closePopup(); startDrawEasement(id); };
  window._esDelete = async function (id) {
    if (!confirm('Delete this easement?')) return;
    try {
      const d = await (await apiDelete('/api/easement.php?id=' + id)).json();
      if (!d.ok) throw new Error(d.error || 'delete failed');
      map.closePopup(); toast('Easement deleted', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };


  // ── Salisbury municipal WiFi: access points, aim vector and estimated coverage cone ─────────────────
  // A cone is a sector of a circle: radius = the model's range, angle = its beam width, centred on the aim.
  // Omnidirectional models get a full circle and no aim vector. Ranges are estimates (open ground).
  const WIFI_RED = '#dc2626';      // active access points
  const WIFI_PLAN = '#eab308';     // planned ones are yellow
  const WIFI_PLAN_DARK = '#a16207';
  let wifiModels = null;
  async function loadWifiModels() {
    if (wifiModels) return wifiModels;
    try { const d = await (await fetch('/api/wifi_ap.php')).json(); wifiModels = d.models || []; }
    catch (e) { wifiModels = []; }
    return wifiModels;
  }
  const wifiOpts = (cur) => (wifiModels || []).map(m => `<option value="${esc(m.model)}"${m.model === cur ? ' selected' : ''}>${esc(m.label)} (${esc(m.model)})</option>`).join('');

  // point `dist` metres from ll on compass bearing `deg`
  function wifiDest(ll, deg, dist) {
    const R = 6378137, d = dist / R, b = deg * Math.PI / 180, la = ll.lat * Math.PI / 180, lo = ll.lng * Math.PI / 180;
    const la2 = Math.asin(Math.sin(la) * Math.cos(d) + Math.cos(la) * Math.sin(d) * Math.cos(b));
    const lo2 = lo + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(la), Math.cos(d) - Math.sin(la) * Math.sin(la2));
    return L.latLng(la2 * 180 / Math.PI, lo2 * 180 / Math.PI);
  }
  function wifiBearing(a, b) {
    const f1 = a.lat * Math.PI / 180, f2 = b.lat * Math.PI / 180, dl = (b.lng - a.lng) * Math.PI / 180;
    const y = Math.sin(dl) * Math.cos(f2), x = Math.cos(f1) * Math.sin(f2) - Math.sin(f1) * Math.cos(f2) * Math.cos(dl);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
  }
  // An omni model that has been given a direction: a teardrop. Full range straight ahead, falling off to
  // about an eighth of it behind the AP (a rough stand-in for a pattern aimed with a reflector or on a wall).
  function wifiTeardropPoints(ll, az, range) {
    const pts = [];
    for (let d = -180; d < 180; d += 6) {
      const k = Math.pow((1 + Math.cos(d * Math.PI / 180)) / 2, 3);
      pts.push(wifiDest(ll, az + d, range * (0.12 + 0.88 * k)));
    }
    return pts;
  }
  function wifiConePoints(ll, az, beam, range) {
    if (beam >= 359.9) { const pts = []; for (let a = 0; a < 360; a += 10) pts.push(wifiDest(ll, a, range)); return pts; }
    const pts = [ll], steps = Math.max(6, Math.round(beam / 5));
    for (let i = 0; i <= steps; i++) pts.push(wifiDest(ll, az - beam / 2 + beam * i / steps, range));
    return pts;
  }
  // Yellow band edges between the 5 GHz range and the 2.4 GHz range: four bands, each as wide as the gap after it.
  function wifiBandRadii(r0, r1) {
    const n = 4, w = (r1 - r0) / (n * 2), out = [];
    for (let i = 0; i < n; i++) out.push([r0 + w * (2 * i), r0 + w * (2 * i + 1)]);
    return out;
  }
  // A ring (full circle) or an arc-shaped strip (cone) between two radii.
  function wifiBandPoints(ll, az, beam, rIn, rOut) {
    if (beam >= 359.9) return [wifiConePoints(ll, az, 360, rOut), wifiConePoints(ll, az, 360, rIn)];
    const steps = Math.max(6, Math.round(beam / 5)), pts = [];
    for (let i = 0; i <= steps; i++) pts.push(wifiDest(ll, az - beam / 2 + beam * i / steps, rOut));
    for (let i = steps; i >= 0; i--) pts.push(wifiDest(ll, az - beam / 2 + beam * i / steps, rIn));
    return pts;
  }
  const wifiCompass = (d) => ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'][Math.round(d / 22.5) % 16];
  const wifiIcon = (plan) => L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [13, 13],
    html: `<div style="width:26px;height:26px;border-radius:50%;background:${plan ? WIFI_PLAN : WIFI_RED};border:2px solid ${plan ? WIFI_PLAN_DARK : WIFI_RED};display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff">` +
          `<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="${plan ? '#422006' : '#fff'}" stroke-width="1.7" stroke-linecap="round"><path d="M1.5 6a9 9 0 0 1 13 0M4 8.7a5.4 5.4 0 0 1 8 0M6.4 11.2a2 2 0 0 1 3.2 0"/><circle cx="8" cy="13.3" r=".9" fill="${plan ? '#422006' : '#fff'}"/></svg></div>` });
  const wifiArrowIcon = (az, plan) => L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10],
    html: `<div title="Drag to change the direction" style="width:20px;height:20px;transform:rotate(${az}deg);cursor:grab"><svg width="20" height="20" viewBox="0 0 20 20"><path d="M10 1.5L16.5 15 10 11.5 3.5 15Z" fill="${plan ? WIFI_PLAN : WIFI_RED}" stroke="${plan ? WIFI_PLAN_DARK : '#fff'}" stroke-width="1.6" stroke-linejoin="round"/></svg></div>` });

  // Same 16-point compass as the anchor dialog. Click a point to aim the AP there (click it again to clear);
  // the value is kept in a hidden input, in degrees, and saved with Save. Fine tuning: drag the white handle.
  function wifiCompassHtml(id, az) {
    const cx = 70, cy = 70, R = 50;
    const sel = az == null ? -1 : Math.round(az / 22.5) % 16;
    let svg = `<svg width="140" height="140" viewBox="0 0 140 140" style="display:block;margin:0 auto"><circle cx="${cx}" cy="${cy}" r="66" fill="#f8f9fa" stroke="#ced4da" stroke-width="1.5"/>` +
      `<circle cx="${cx}" cy="${cy}" r="33" fill="none" stroke="#dee2e6" stroke-width=".75" stroke-dasharray="3,3"/>` +
      `<circle cx="${cx}" cy="${cy}" r="3" fill="#dc2626"/>`;
    ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'].forEach((dir, i) => {
      const rad = (i * 22.5 - 90) * Math.PI / 180, x = cx + R * Math.cos(rad), y = cy + R * Math.sin(rad);
      const r = dir.length === 1 ? 12 : 10, fs = dir.length === 1 ? 9 : dir.length === 2 ? 8 : 7, on = i === sel;
      svg += `<g class="wfc" data-i="${i}" style="cursor:pointer" onclick="window._wfCompass('${id}',${i})"><circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${r}" fill="${on ? '#dc2626' : 'white'}" stroke="${on ? '#991b1b' : '#ced4da'}" stroke-width="1.2"/>` +
             `<text x="${x.toFixed(1)}" y="${(y + fs * .35).toFixed(1)}" text-anchor="middle" font-size="${fs}" font-weight="${dir.length === 1 ? 'bold' : 'normal'}" fill="${on ? 'white' : '#212529'}" style="pointer-events:none">${dir}</text></g>`;
    });
    return `<div class="small mt-1">Direction pointed <span class="text-muted">(click a point; again to clear; or Aim and drag the handle)</span></div>` +
      `<input type="hidden" id="wf-az-${id}" value="${az == null ? '' : Math.round(az * 10) / 10}"><div id="wf-compass-${id}">${svg}</svg></div>`;
  }
  window._wfCompass = function (id, i) {
    const inp = document.getElementById('wf-az-' + id); if (!inp) return;
    const deg = i * 22.5, same = inp.value !== '' && Math.abs(parseFloat(inp.value) - deg) < 0.01;
    inp.value = same ? '' : deg;
    document.querySelectorAll('#wf-compass-' + id + ' .wfc').forEach(g => {
      const on = !same && +g.dataset.i === i, c = g.querySelector('circle'), t = g.querySelector('text');
      c.setAttribute('fill', on ? '#dc2626' : 'white'); c.setAttribute('stroke', on ? '#991b1b' : '#ced4da'); t.setAttribute('fill', on ? 'white' : '#212529');
    });
  };

  function buildWifiPopup(p) {
    const dir = p.azimuth_deg == null ? (p.pattern === 'omni' ? 'Omnidirectional, no direction set' : 'Direction not set') : (`Pointing ${Math.round(p.azimuth_deg)}° (${wifiCompass(p.azimuth_deg)})`);
    const cone = p.beam_deg >= 359.9 ? `360°, ${Math.round(p.range_m)} m` : `${Math.round(p.beam_deg)}° cone, ${Math.round(p.range_m)} m`;
    const f = (k, label, val, type, ph) => `<label class="small d-block mt-1">${label}<input id="wf-${k}-${p.id}" type="${type || 'text'}" class="form-control form-control-sm" value="${esc(val == null ? '' : String(val))}"${ph ? ` placeholder="${esc(ph)}"` : ''}></label>`;
    return `<div class="dim-popup" style="min-width:250px;max-width:300px">
      <div class="fw-semibold">${esc(p.name)}</div>
      <div class="small text-muted mb-1">${p.status === 'plan' ? 'Planned' : 'Active'} WiFi access point &middot; model <b>${esc(p.model_label)}</b> (${esc(p.model)})</div>
      <div class="small mb-1">${dir} &middot; 50 Mbit service ${cone}${p.fringe_m && p.fringe_m > p.range_m + 5 ? `, slower service to ~${Math.round(p.fringe_m)} m on 2.4 GHz` : ''} <span class="text-muted">(${p.beam_override || p.range_override ? 'edited' : 'link-budget estimate, open ground, no buildings or trees'})</span></div>
      ${f('name', 'Name', p.name)}
      <label class="small d-block mt-1">Model<select id="wf-model-${p.id}" class="form-select form-select-sm">${wifiOpts(p.model)}</select></label>
      <label class="small d-block mt-1">Status<select id="wf-status-${p.id}" class="form-select form-select-sm"><option value="plan"${p.status === 'plan' ? ' selected' : ''}>Plan</option><option value="active"${p.status === 'active' ? ' selected' : ''}>Active</option></select></label>
      ${wifiCompassHtml(p.id, p.azimuth_deg)}
      ${f('range', 'Range override (m)', p.range_override, 'number', 'model default ' + Math.round(p.range_m))}
      ${f('beam', 'Beam width override (deg)', p.beam_override, 'number')}
      <label class="small d-block mt-1">Work order<input id="wf-wor-${p.id}" list="pfWorkOrderList" class="form-control form-control-sm" value="${esc(p.fpm_wor || '')}" autocomplete="off"></label>
      ${f('mac', 'UniFi MAC (to link the real device later)', p.unifi_mac)}
      <label class="small d-block mt-1">Notes<textarea id="wf-notes-${p.id}" rows="2" class="form-control form-control-sm">${esc(p.notes || '')}</textarea></label>
      ${actRow([actBtn('save', `onclick="window._wfSave(${p.id})"`, 'Save'), actBtn('route', `onclick="window._wfAim(${p.id})"`, 'Aim', 'Show the direction handle (starts north), then drag it'), actBtn('move', `onclick="window._wfMove(${p.id})"`, 'Move', 'Drag the AP to a new spot')],
               actBtn('del', `onclick="window._wfDelete(${p.id})"`, 'Delete', 'Delete this access point'))}
    </div>`;
  }

  const wifiMarkers = {};
  function renderWifiAp(feature) {
    const p = feature.properties;
    const [lon, lat] = feature.geometry.coordinates;
    const ll = L.latLng(lat, lon), plan = p.status === 'plan';
    const hasAim = p.azimuth_deg != null, directional = hasAim && p.beam_deg < 359.9;   // an omni model can still be given a direction (vector only, cone stays a circle)
    const cone = L.polygon(hasAim && !directional ? wifiTeardropPoints(ll, p.azimuth_deg, p.range_m) : wifiConePoints(ll, p.azimuth_deg || 0, directional ? p.beam_deg : 360, p.range_m),
      { pane: 'wifiConePane', color: plan ? WIFI_PLAN_DARK : WIFI_RED, weight: plan ? 1.5 : 1, opacity: plan ? 0.85 : 0.55, dashArray: plan ? '6 5' : null,
        fillColor: plan ? WIFI_PLAN : WIFI_RED, fillOpacity: plan ? 0.34 : 0.28, interactive: false });
    cone._tinWifiWor = p.fpm_wor || '';
    wifiGroup.addLayer(cone);
    // outer outline: where 2.4 GHz is still usable (slower, reaches further). Same aim, its own beam width.
    // The 2.4 GHz area (slower, but it reaches further): bands (red when active, yellow when planned) with clear gaps between them, running out from
    // the 5 GHz cone, inside a thin dashed outline. Same aim; its own beam width.
    let fringe = null;
    if (p.fringe_m && p.fringe_m > p.range_m + 5) {
      const fb = p.beam_override ? p.beam_override : (p.fringe_beam || p.beam_deg);
      const fBeam = () => (hasAim && fb < 359.9 ? fb : 360);
      fringe = { outline: null, bands: [] };
      fringe.outline = L.polygon(wifiConePoints(ll, p.azimuth_deg || 0, fBeam(), p.fringe_m),
        { pane: 'wifiConePane', color: plan ? WIFI_PLAN_DARK : WIFI_RED, weight: 1, opacity: 0.5, dashArray: '2 5', fill: false, interactive: false });
      fringe.outline._tinWifiWor = p.fpm_wor || '';
      wifiGroup.addLayer(fringe.outline);
      wifiBandRadii(p.range_m, p.fringe_m).forEach(function (r) {
        const band = L.polygon(wifiBandPoints(ll, p.azimuth_deg || 0, fBeam(), r[0], r[1]),
          { pane: 'wifiConePane', stroke: false, fillColor: plan ? WIFI_PLAN : WIFI_RED, fillOpacity: plan ? 0.5 : 0.45, interactive: false });
        band._tinWifiWor = p.fpm_wor || '';
        band._tinBandR = r;
        fringe.bands.push(band);
        wifiGroup.addLayer(band);
      });
      fringe.update = function (az) { fringe.outline.setLatLngs(wifiConePoints(ll, az, fBeam(), p.fringe_m)); fringe.bands.forEach(b => b.setLatLngs(wifiBandPoints(ll, az, fBeam(), b._tinBandR[0], b._tinBandR[1]))); };
    }
    const marker = L.marker(ll, { pane: 'wifiPane', icon: wifiIcon(plan), draggable: false, zIndexOffset: 700 });
    marker.bindPopup(() => { try { return buildWifiPopup(p); } catch (e) { console.error('wifi popup', e); throw e; } }, { minWidth: 260, maxWidth: 320 });
    if (!wifiModels) marker.on('popupopen', async function () { await loadWifiModels(); marker.getPopup().setContent(buildWifiPopup(p)); });
    marker.bindTooltip(`${esc(p.name)} &middot; ${esc(p.model)}`, { direction: 'top', offset: [0, -12] });
    marker._tinWifiWor = p.fpm_wor || '';
    wifiGroup.addLayer(marker);
    wifiMarkers[p.id] = marker;
    marker.on('dragend', async function () {
      const n = marker.getLatLng();
      try { const d = await (await apiPut('/api/wifi_ap.php?id=' + p.id, { lat: n.lat, lon: n.lng })).json(); if (!d.ok) throw new Error(d.error || 'save failed'); toast('AP moved', 'success'); loadFeatures(true); }
      catch (e) { toast(e.message, 'danger'); loadFeatures(true); }
    });
    if (hasAim) {
      // A small arrow just outside the wifi symbol, a fixed size on screen whatever the zoom (no length meaning).
      // Drag it round the AP to turn it.
      const ARROW_PX = 25;
      const place = (az) => { const c = map.latLngToLayerPoint(ll), r = az * Math.PI / 180; return map.layerPointToLatLng(c.add([Math.sin(r) * ARROW_PX, -Math.cos(r) * ARROW_PX])); };
      const handle = L.marker(place(p.azimuth_deg), { pane: 'wifiPane', icon: wifiArrowIcon(p.azimuth_deg, plan), draggable: true, zIndexOffset: 800 });
      handle._tinWifiArrow = { ll, az: p.azimuth_deg, place };
      handle.on('drag', function () {
        const az = wifiBearing(ll, handle.getLatLng());
        cone.setLatLngs(directional ? wifiConePoints(ll, az, p.beam_deg, p.range_m) : wifiTeardropPoints(ll, az, p.range_m));
        if (fringe) fringe.update(az);
        handle.setIcon(wifiArrowIcon(az, plan));
      });
      handle.on('dragend', async function () {
        const az = Math.round(wifiBearing(ll, handle.getLatLng()) * 10) / 10 % 360;
        try { const d = await (await apiPut('/api/wifi_ap.php?id=' + p.id, { azimuth_deg: az })).json(); if (!d.ok) throw new Error(d.error || 'save failed'); toast(`${p.name}: pointing ${Math.round(az)}\u00b0 ${wifiCompass(az)}`, 'success'); loadFeatures(true); }
        catch (e) { toast(e.message, 'danger'); loadFeatures(true); }
      });
      handle._tinWifiWor = p.fpm_wor || '';
      wifiGroup.addLayer(handle);
    }
  }
  // the arrows sit a fixed number of pixels from their AP, so they are moved when the zoom changes
  function wifiReplaceArrows() {
    if (!wifiGroup) return;
    wifiGroup.eachLayer(function (l) { if (l._tinWifiArrow && !l.dragging?._draggable?._moving) l.setLatLng(l._tinWifiArrow.place(l._tinWifiArrow.az)); });
  }

  async function startPlaceWifi() {
    cancelActiveEdit();
    await loadWifiModels();
    pendingDrawMode = 'wifi';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar('&#x1F4F6; Click on the map to place a WiFi access point', null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); });
  }

  function wifiNewPopup(ll) {
    hideEditBar();
    window._wfNewLL = ll;
    const f = (k, label, val, type) => `<label class="small d-block mt-1">${label}<input id="wf-${k}-new" type="${type || 'text'}" class="form-control form-control-sm" value="${esc(val)}"></label>`;
    L.popup({ minWidth: 250 }).setLatLng(ll).setContent(
      `<div class="dim-popup" style="min-width:230px"><div class="fw-semibold mb-1">New WiFi access point</div>
        ${f('name', 'Name', '')}
        <label class="small d-block mt-1">Model<select id="wf-model-new" class="form-select form-select-sm">${wifiOpts('U7-Pro-Outdoor')}</select></label>
        <label class="small d-block mt-1">Status<select id="wf-status-new" class="form-select form-select-sm"><option value="plan" selected>Plan</option><option value="active">Active</option></select></label>
        <label class="small d-block mt-1">Work order<input id="wf-wor-new" list="pfWorkOrderList" class="form-control form-control-sm" value="${(window.ORG && window.ORG.defaultWifiWor) || ''}" autocomplete="off"></label>
        ${wifiCompassHtml('new', null)}
        ${actRow([actBtn('save', 'onclick="window._wfCreate()"', 'Save')])}</div>`).openOn(map);
  }
  const wfVal = (k, id) => document.getElementById('wf-' + k + '-' + id)?.value ?? '';
  const wfBody = (id) => ({ name: wfVal('name', id), model: wfVal('model', id), status: wfVal('status', id), fpm_wor: wfVal('wor', id), azimuth_deg: wfVal('az', id) });
  window._wfCreate = async function () {
    const ll = window._wfNewLL;
    try {
      const body = Object.assign(wfBody('new'), { lat: ll.lat, lon: ll.lng });
      const d = await (await apiPost('/api/wifi_ap.php', body)).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Access point saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._wfSave = async function (id) {
    try {
      const body = Object.assign(wfBody(id), { range_m: wfVal('range', id), beam_deg: wfVal('beam', id), unifi_mac: wfVal('mac', id), notes: wfVal('notes', id) });
      const d = await (await apiPut('/api/wifi_ap.php?id=' + id, body)).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Access point saved', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._wfAim = async function (id) {
    try {
      const cur = wfVal('az', id);
      const d = await (await apiPut('/api/wifi_ap.php?id=' + id, { azimuth_deg: cur === '' ? 0 : cur })).json();
      if (!d.ok) throw new Error(d.error || 'save failed');
      map.closePopup(); toast('Drag the white handle to point the AP', 'info'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };
  window._wfMove = function (id) {
    const m = wifiMarkers[id]; if (!m) return;
    map.closePopup(); m.dragging.enable(); toast('Drag the access point to its new spot', 'info');
  };
  window._wfDelete = async function (id) {
    if (!confirm('Delete this access point?')) return;
    try {
      const d = await (await apiDelete('/api/wifi_ap.php?id=' + id)).json();
      if (!d.ok) throw new Error(d.error || 'delete failed');
      map.closePopup(); toast('Access point deleted', 'success'); loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  };

  function buildNotePopup(p) {
    const labels = { tree_trim: '&#x1F333; Tree Trim', construction: '&#x1F6A7; Construction', note: '&#x1F4CC; Note' };
    const label = labels[p.icon] || '&#x1F4CC; Note';
    const body = p.body ? `<p class="mb-1" style="white-space:pre-wrap">${esc(p.body)}</p>` : '<p class="text-muted mb-1"><em>No text</em></p>';
    const wo = p.fpm_wor ? `<p class="mb-1 small text-muted"><strong>WO:</strong> ${esc(p.fpm_wor)}</p>` : '';
    return `<div>
      <div class="fw-semibold mb-1">${label}</div>
      ${body}
      ${wo}
      ${p.done ? '<p class="mb-1 small"><span class="badge bg-success">Done</span></p>' : ''}
      ${actRow([
        actBtn('edit', `id="edit-note-${p.note_id}"`, 'Edit'),
        actBtn('move', `id="move-note-${p.note_id}"`, 'Move'),
      ], actBtn('del', `id="del-note-${p.note_id}"`, 'Delete', 'Delete note'))}
      <div class="mt-2 d-flex flex-wrap gap-1">
        <button type="button" id="done-note-${p.note_id}" class="btn btn-sm ${p.done ? 'btn-outline-secondary' : 'btn-outline-success'}" style="font-size:11px;padding:1px 8px">${p.done ? 'Reopen' : 'Mark done'}</button>
        ${p.fpm_wor && p.icon === 'tree_trim' ? `<button type="button" id="done-wo-${p.note_id}" class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:1px 8px">${p.done ? 'Reopen' : 'All'} ${esc(p.fpm_wor)} tree trims${p.done ? '' : ' done'}</button>` : ''}
      </div>
    </div>`;
  }

  function openNoteModalForEdit(p) {
    editingNoteId = p.note_id;
    pendingNoteLatLng = null; // not moving geom on edit
    document.getElementById('noteBody').value = p.body || '';
    document.getElementById('noteFpmWor').value = p.fpm_wor || '';
    setNoteIconSelection(p.icon || 'note');
    document.getElementById('noteModalLabel').textContent = 'Edit Note';
    new bootstrap.Modal(document.getElementById('newNoteModal')).show();
  }

  async function saveNote() {
    const icon  = document.getElementById('noteIconValue').value || 'note';
    const body  = document.getElementById('noteBody').value.trim();
    const errEl = document.getElementById('noteModalError');
    showErr(errEl, '');

    if (!editingNoteId && !pendingNoteLatLng) {
      showErr(errEl, 'No location placed.'); return;
    }

    const payload = { icon, body, fpm_wor: document.getElementById('noteFpmWor').value.trim() };
    if (pendingNoteLatLng) {
      payload.geojson = { type: 'Point', coordinates: [pendingNoteLatLng.lng, pendingNoteLatLng.lat] };
    }

    try {
      const resp = editingNoteId
        ? await apiPut(API.note + '?id=' + editingNoteId, payload)
        : await apiPost(API.note, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newNoteModal')).hide();
        toast(editingNoteId ? 'Note updated' : 'Note saved', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function resetNoteModal() {
    editingNoteId    = 0;
    pendingNoteLatLng = null;
    document.getElementById('noteBody').value = '';
    document.getElementById('noteFpmWor').value = '';
    setNoteIconSelection('note');
    document.getElementById('noteModalLabel').textContent = 'New Note';
    showErr(document.getElementById('noteModalError'), '');
  }

  async function deleteNote(id, marker) {
    if (!confirm('Delete this note? This cannot be undone.')) return;
    try {
      const resp = await apiDelete(API.note + '?id=' + id);
      const data = await resp.json();
      if (data.ok) {
        noteGroup.removeLayer(marker);
        toast('Note deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) { toast('Error: ' + e.message, 'danger'); }
  }

  // ── Add Anchor mode ──────────────────────────────────────────────────────────

  function startAddAnchor() {
    cancelActiveEdit();
    addAnchorMode = true;
    map.getContainer().style.cursor = 'crosshair';
    showEditBar(
      '⚓ Click on a pole to attach a new anchor',
      null,
      function () { exitAddAnchorMode(); }
    );
  }

  function exitAddAnchorMode() {
    addAnchorMode = false;
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  // ── Add U-Guard mode ─────────────────────────────────────────────────────────

  function startAddUguard() {
    cancelActiveEdit();
    addUguardMode = true;
    map.getContainer().style.cursor = 'crosshair';
    showEditBar(
      '<strong>UG</strong> Click on a pole to attach a new u-guard',
      null,
      function () { exitAddUguardMode(); }
    );
  }

  function exitAddUguardMode() {
    addUguardMode = false;
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  // ── Insert pole into strand mode ─────────────────────────────────────────────

  function startInsertStrandMode(strandId) {
    cancelActiveEdit();
    insertStrandMode = true;
    insertStrandId   = strandId;
    map.getContainer().style.cursor = 'crosshair';
    showEditBar(
      '〰 Click a pole to insert it into this strand',
      null,
      function () { exitInsertStrandMode(); }
    );
  }

  function exitInsertStrandMode() {
    insertStrandMode = false;
    insertStrandId   = 0;
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  // ── Anchor modal (create & edit) ─────────────────────────────────────────────

  // ── Anchor direction compass ─────────────────────────────────────────────────

  const _COMPASS_DIRS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];

  function initAnchorCompass() {
    const container = document.getElementById('anchorCompassContainer');
    if (!container || container.dataset.init) return;
    container.dataset.init = '1';

    const cx = 80, cy = 80, R = 58; // center and label-ring radius within 160×160 viewBox

    let svg = `<svg width="160" height="160" viewBox="0 0 160 160" style="display:block;margin:0 auto">` +
      // Outer ring
      `<circle cx="${cx}" cy="${cy}" r="76" fill="#f8f9fa" stroke="#ced4da" stroke-width="1.5"/>` +
      // Subtle inner guide ring
      `<circle cx="${cx}" cy="${cy}" r="38" fill="none" stroke="#dee2e6" stroke-width="0.75" stroke-dasharray="3,3"/>` +
      // Crosshair lines N–S and E–W
      `<line x1="${cx}" y1="${cy-36}" x2="${cx}" y2="${cy+36}" stroke="#dee2e6" stroke-width="0.75"/>` +
      `<line x1="${cx-36}" y1="${cy}" x2="${cx+36}" y2="${cy}" stroke="#dee2e6" stroke-width="0.75"/>` +
      // Centre pole dot
      `<circle cx="${cx}" cy="${cy}" r="3" fill="#6c757d"/>`;

    _COMPASS_DIRS.forEach((dir, i) => {
      const rad   = (i * 22.5 - 90) * Math.PI / 180;
      const x     = cx + R * Math.cos(rad);
      const y     = cy + R * Math.sin(rad);
      const r     = dir.length === 1 ? 13 : 11; // cardinals slightly larger
      const fw    = dir.length === 1 ? 'bold' : 'normal';
      const fs    = dir.length === 1 ? 10 : dir.length === 2 ? 9 : 8;
      svg +=
        `<g class="acp" data-dir="${dir}" style="cursor:pointer">` +
          `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${r}" fill="white" stroke="#ced4da" stroke-width="1.2"/>` +
          `<text x="${x.toFixed(1)}" y="${(y + fs * 0.35).toFixed(1)}" text-anchor="middle" ` +
                `font-size="${fs}" font-weight="${fw}" fill="#212529" style="pointer-events:none">${dir}</text>` +
        `</g>`;
    });

    svg += '</svg>';
    container.innerHTML = svg;

    container.querySelectorAll('.acp').forEach(g => {
      g.addEventListener('click', () => {
        const d = g.dataset.dir;
        setAnchorCompassDir(d === document.getElementById('anchorDirection').value ? '' : d);
      });
    });
  }

  function setAnchorCompassDir(dir) {
    document.getElementById('anchorDirection').value = dir || '';
    const container = document.getElementById('anchorCompassContainer');
    if (!container) return;
    container.querySelectorAll('.acp').forEach(g => {
      const sel = g.dataset.dir === dir && dir !== '';
      g.querySelector('circle').setAttribute('fill',   sel ? '#0d6efd' : 'white');
      g.querySelector('circle').setAttribute('stroke', sel ? '#0a58ca' : '#ced4da');
      g.querySelector('text').setAttribute('fill',     sel ? 'white'   : '#212529');
    });
  }

  function openAnchorModal(props, isNew) {
    const modal   = document.getElementById('anchorModal');
    const title   = document.getElementById('anchorModalLabel');
    const errEl   = document.getElementById('anchorModalError');
    const idInput = document.getElementById('anchorIdInput');
    const fidInput= document.getElementById('anchorPoleFidInput');
    const display = document.getElementById('anchorPoleDisplay');
    const owner     = document.getElementById('anchorOwner');
    const status    = document.getElementById('anchorStatus');
    const notes     = document.getElementById('anchorNotes');
    const delBtn    = document.getElementById('deleteAnchorBtn');
    if (!modal) return;

    initAnchorCompass();
    errEl.style.display = 'none';
    errEl.textContent = '';

    if (isNew) {
      title.textContent = 'New Anchor';
      idInput.value  = '';
      fidInput.value = props.pole_fid || '';
      const poleLabel = props.pole_uid || (props.pole_nbp ? 'NBP# ' + props.pole_nbp : 'fid ' + props.pole_fid);
      display.value     = poleLabel;
      owner.value       = '';
      status.value      = 'plan';
      setAnchorCompassDir('');
      notes.value       = '';
      delBtn.style.display = 'none';
    } else {
      title.textContent = 'Edit Anchor';
      idInput.value  = props.id || '';
      fidInput.value = props.pole_fid || '';
      const poleLabel = props.pole_uid || (props.pole_nbp ? 'NBP# ' + props.pole_nbp : 'fid ' + props.pole_fid);
      display.value     = poleLabel;
      owner.value       = props.owner     || '';
      status.value      = props.status    || 'plan';
      setAnchorCompassDir(props.direction || '');
      notes.value       = props.notes     || '';
      delBtn.style.display = '';
    }

    // Wire save / delete each time modal opens (clone to remove old listeners)
    const saveBtn = document.getElementById('saveAnchorBtn');
    const newSave = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newSave, saveBtn);
    newSave.addEventListener('click', function () { saveAnchor(isNew, modal); });

    const newDel = delBtn.cloneNode(true);
    newDel.style.display = isNew ? 'none' : '';
    delBtn.parentNode.replaceChild(newDel, delBtn);
    newDel.addEventListener('click', function () { deleteAnchor(modal); });

    new bootstrap.Modal(modal).show();
  }

  async function saveAnchor(isNew, modal) {
    const errEl  = document.getElementById('anchorModalError');
    const id        = parseInt(document.getElementById('anchorIdInput').value, 10) || 0;
    const poleFid   = parseInt(document.getElementById('anchorPoleFidInput').value, 10) || 0;
    const owner     = document.getElementById('anchorOwner').value.trim();
    const status    = document.getElementById('anchorStatus').value;
    const direction = document.getElementById('anchorDirection').value;
    const notes     = document.getElementById('anchorNotes').value.trim();

    errEl.style.display = 'none';

    const body = isNew
      ? { pole_fid: poleFid, owner, status, direction, notes }
      : { id, owner, status, direction, notes };

    try {
      const resp = await fetch(API.anchor, {
        method:  isNew ? 'POST' : 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Save failed');
      bootstrap.Modal.getInstance(modal)?.hide();
      loadFeatures(true);
      toast(isNew ? 'Anchor added' : 'Anchor saved', 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  async function deleteAnchor(modal) {
    const errEl = document.getElementById('anchorModalError');
    const id    = parseInt(document.getElementById('anchorIdInput').value, 10) || 0;
    if (!id) return;
    if (!confirm('Delete this anchor?')) return;

    try {
      const resp = await fetch(API.anchor, {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify({ id }),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Delete failed');
      bootstrap.Modal.getInstance(modal)?.hide();
      loadFeatures(true);
      toast('Anchor deleted', 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  // ── U-Guard modal (create & edit) ───────────────────────────────────────────

  function openUguardModal(props, isNew) {
    const modal   = document.getElementById('uguardModal');
    const title   = document.getElementById('uguardModalLabel');
    const errEl   = document.getElementById('uguardModalError');
    const idInput = document.getElementById('uguardIdInput');
    const fidInput= document.getElementById('uguardPoleFidInput');
    const display = document.getElementById('uguardPoleDisplay');
    const status  = document.getElementById('uguardStatus');
    const notes   = document.getElementById('uguardNotes');
    const fpmWor = document.getElementById('uguardFpmWor');
    const delBtn  = document.getElementById('deleteUguardBtn');
    if (!modal) return;

    errEl.style.display = 'none';
    errEl.textContent = '';

    if (isNew) {
      title.textContent = 'New U-Guard';
      idInput.value  = '';
      fidInput.value = props.pole_fid || '';
      const poleLabel = props.pole_uid || (props.pole_nbp ? 'NBP# ' + props.pole_nbp : 'fid ' + props.pole_fid);
      display.value    = poleLabel;
      status.value     = 'plan';
      notes.value      = '';
      if (fpmWor) fpmWor.value = '';
      delBtn.classList.add('d-none');
    } else {
      title.textContent = 'Edit U-Guard';
      idInput.value  = props.id || '';
      fidInput.value = props.pole_fid || '';
      const poleLabel = props.pole_uid || (props.pole_nbp ? 'NBP# ' + props.pole_nbp : 'fid ' + props.pole_fid);
      display.value    = poleLabel;
      status.value     = props.status || 'plan';
      notes.value      = props.notes  || '';
      if (fpmWor) fpmWor.value = props.fpm_wor || '';
      delBtn.classList.remove('d-none');
    }

    const saveBtn = document.getElementById('saveUguardBtn');
    const newSave = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newSave, saveBtn);
    newSave.addEventListener('click', function () { saveUguard(isNew, modal); });

    const newDel = delBtn.cloneNode(true);
    if (isNew) newDel.classList.add('d-none'); else newDel.classList.remove('d-none');
    delBtn.parentNode.replaceChild(newDel, delBtn);
    newDel.addEventListener('click', function () { deleteUguard(modal); });

    new bootstrap.Modal(modal).show();
  }

  async function saveUguard(isNew, modal) {
    const errEl  = document.getElementById('uguardModalError');
    const id     = parseInt(document.getElementById('uguardIdInput').value, 10) || 0;
    const poleFid= parseInt(document.getElementById('uguardPoleFidInput').value, 10) || 0;
    const status = document.getElementById('uguardStatus').value;
    const notes  = document.getElementById('uguardNotes').value.trim();
    const fpmWor = (document.getElementById('uguardFpmWor')?.value || '').trim();

    errEl.style.display = 'none';

    const body = isNew
      ? { pole_fid: poleFid, status, notes, fpm_wor: fpmWor }
      : { id, status, notes, fpm_wor: fpmWor };

    try {
      const resp = await fetch(API.uguard, {
        method:  isNew ? 'POST' : 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Save failed');
      bootstrap.Modal.getInstance(modal)?.hide();
      loadFeatures(true);
      toast(isNew ? 'U-guard added' : 'U-guard saved', 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  async function deleteUguard(modal) {
    const errEl = document.getElementById('uguardModalError');
    const id    = parseInt(document.getElementById('uguardIdInput').value, 10) || 0;
    if (!id) return;
    if (!confirm('Delete this u-guard?')) return;

    try {
      const resp = await fetch(API.uguard, {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify({ id }),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Delete failed');
      bootstrap.Modal.getInstance(modal)?.hide();
      loadFeatures(true);
      toast('U-guard deleted', 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  // ── Add Strand mode ──────────────────────────────────────────────────────────

  function startAddStrand() {
    cancelActiveEdit();
    addingStrand = true;
    strandPath   = [];
    map.getContainer().style.cursor = 'crosshair';
    map.on('mousemove', onStrandMouseMove);
    updateStrandPathBar();
  }

  function updateStrandPathBar() {
    const n    = strandPath.length;
    const segs = n > 1 ? ` — <strong>${n - 1} segment${n > 2 ? 's' : ''}</strong> ready` : '';
    const msg  = `〰 <strong>${n}</strong> point${n !== 1 ? 's' : ''} selected${segs} — click a pole or building anchor to add to path`;
    showEditBar(
      msg,
      n >= 2 ? function () { openStrandPathModal(strandPath.slice()); } : null,
      function () { exitAddStrandMode(); },
      'Save Path'
    );
  }

  function updateStrandPreviewLine() {
    if (strandPreviewLine) { strandPreviewLine.remove(); strandPreviewLine = null; }
    if (strandPath.length < 2) return;
    const latlngs = strandPath.map(p => [p.lat, p.lon]);
    strandPreviewLine = L.polyline(latlngs, {
      color: '#94a3b8', weight: 4, opacity: 0.75, dashArray: '8 5', interactive: false,
    }).addTo(map);
  }

  function onStrandMouseMove(e) {
    if (strandRubberBand) { strandRubberBand.remove(); strandRubberBand = null; }

    // Snap detection: find nearest pole within 24 px
    const cursorPt = map.latLngToContainerPoint(e.latlng);
    let snapFid = null, snapLL = e.latlng, bestDist = 24;
    for (const fid in poleMarkerByFid) {
      const m = poleMarkerByFid[fid];
      const pt = map.latLngToContainerPoint(m.getLatLng());
      const d  = Math.hypot(pt.x - cursorPt.x, pt.y - cursorPt.y);
      if (d < bestDist) { bestDist = d; snapFid = Number(fid); snapLL = m.getLatLng(); }
    }

    // Update snap highlight icon
    if (snapFid !== strandSnapFid) {
      if (strandSnapFid !== null) {
        const prev = poleMarkerByFid[strandSnapFid];
        if (prev) prev.setIcon(
          poleSelection.has(strandSnapFid)
            ? poleIconSelected(prev._tinIcon.owner, poleScale())
            : poleIcon(prev._tinIcon.owner, poleScale())
        );
      }
      strandSnapFid = snapFid;
      if (snapFid !== null) {
        const m = poleMarkerByFid[snapFid];
        if (m) m.setIcon(poleIconSnap(m._tinIcon.owner, poleScale()));
      }
    }

    if (strandPath.length === 0) return;
    const last = strandPath[strandPath.length - 1];
    strandRubberBand = L.polyline([[last.lat, last.lon], snapLL], {
      color: '#94a3b8', weight: 3, opacity: 0.55, dashArray: '5 5', interactive: false,
    }).addTo(map);
  }

  function exitAddStrandMode() {
    addingStrand = false;
    strandPath   = [];
    if (strandPreviewLine) { strandPreviewLine.remove(); strandPreviewLine = null; }
    if (strandRubberBand)  { strandRubberBand.remove();  strandRubberBand  = null; }
    if (strandSnapFid !== null) {
      const m = poleMarkerByFid[strandSnapFid];
      if (m) m.setIcon(
        poleSelection.has(strandSnapFid)
          ? poleIconSelected(m._tinIcon.owner, poleScale())
          : poleIcon(m._tinIcon.owner, poleScale())
      );
      strandSnapFid = null;
    }
    map.off('mousemove', onStrandMouseMove);
    map.getContainer().style.cursor = '';
    hideEditBar();
    loadFeatures(true); // reload any features that entered the viewport while draw was frozen
  }

  // ── Shared pole snap highlight for routing modes ──────────────────────────────

  function applyRouteSnapHighlight(cursorPt) {
    let snapFid = null, bestDist = 24;
    for (const fid in poleMarkerByFid) {
      const m  = poleMarkerByFid[fid];
      const pt = map.latLngToContainerPoint(m.getLatLng());
      const d  = Math.hypot(pt.x - cursorPt.x, pt.y - cursorPt.y);
      if (d < bestDist) { bestDist = d; snapFid = Number(fid); }
    }
    if (snapFid !== routeSnapFid) {
      if (routeSnapFid !== null) {
        const prev = poleMarkerByFid[routeSnapFid];
        if (prev) prev.setIcon(
          poleSelection.has(routeSnapFid)
            ? poleIconSelected(prev._tinIcon.owner, poleScale())
            : poleIcon(prev._tinIcon.owner, poleScale())
        );
      }
      routeSnapFid = snapFid;
      if (snapFid !== null) {
        const m = poleMarkerByFid[snapFid];
        if (m) m.setIcon(poleIconSnap(m._tinIcon.owner, poleScale()));
      }
    }
  }

  function clearRouteSnapHighlight() {
    if (routeSnapFid !== null) {
      const m = poleMarkerByFid[routeSnapFid];
      if (m) m.setIcon(
        poleSelection.has(routeSnapFid)
          ? poleIconSelected(m._tinIcon.owner, poleScale())
          : poleIcon(m._tinIcon.owner, poleScale())
      );
      routeSnapFid = null;
    }
  }

  // ── Span route-via-poles ──────────────────────────────────────────────────────

  function startRouteSpanViaPoles(line, p) {
    cancelActiveEdit();
    routingSpanViaPoles = true;
    spanRouteTarget     = p;
    spanRoutePoles      = [];
    const lls           = line.getLatLngs();
    spanRouteFromLL     = lls[0];
    spanRouteToLL       = lls[lls.length - 1];
    map.getContainer().style.cursor = 'crosshair';
    map.on('mousemove', onSpanRouteMouseMove);
    updateSpanRouteBar();
  }

  function updateSpanRouteBar() {
    const n    = spanRoutePoles.length;
    const segs = n > 0 ? ` — <strong>${n} pole${n !== 1 ? 's' : ''}</strong> added` : '';
    showEditBar(
      `&#x1F4CD; <strong>Route via poles</strong>: ${esc(spanRouteTarget.from_splice_num)} &rarr; ${esc(spanRouteTarget.to_splice_num)}${segs} &mdash; click poles in order`,
      n > 0 ? saveSpanRoutePoles : null,
      function () { exitSpanRouteMode(); },
      'Save Route'
    );
  }

  function updateSpanRoutePreview() {
    if (spanRoutePreview) { spanRoutePreview.remove(); spanRoutePreview = null; }
    const pts = [spanRouteFromLL, ...spanRoutePoles.map(p => [p.lat, p.lon]), spanRouteToLL];
    if (pts.length < 2) return;
    spanRoutePreview = L.polyline(pts, {
      color: '#fd7e14', weight: 4, opacity: 0.8, dashArray: '8 5', interactive: false,
    }).addTo(map);
  }

  function onSpanRouteMouseMove(e) {
    applyRouteSnapHighlight(map.latLngToContainerPoint(e.latlng));
    if (spanRouteRubber) { spanRouteRubber.remove(); spanRouteRubber = null; }
    const last = spanRoutePoles.length > 0
      ? [spanRoutePoles[spanRoutePoles.length - 1].lat, spanRoutePoles[spanRoutePoles.length - 1].lon]
      : spanRouteFromLL;
    spanRouteRubber = L.polyline([last, e.latlng], {
      color: '#fd7e14', weight: 2, opacity: 0.5, dashArray: '5 5', interactive: false,
    }).addTo(map);
  }

  async function saveSpanRoutePoles() {
    // Read the target BEFORE exiting: exitSpanRouteMode() clears spanRouteTarget,
    // so reading it afterwards threw on null and the save never ran — the bar had
    // already closed, so Save Route looked like it had worked and quietly did
    // nothing. The drop-route flows next door capture theirs first for this reason.
    const t = spanRouteTarget;
    if (!t) return;
    const coords = [
      [spanRouteFromLL.lng, spanRouteFromLL.lat],
      ...spanRoutePoles.map(p => [p.lon, p.lat]),
      [spanRouteToLL.lng, spanRouteToLL.lat],
    ];
    exitSpanRouteMode();
    await editSpan(t.cable_span_id, { type: 'LineString', coordinates: coords });
  }

  function exitSpanRouteMode() {
    routingSpanViaPoles = false;
    spanRoutePoles      = [];
    spanRouteTarget     = null;
    if (spanRoutePreview) { spanRoutePreview.remove(); spanRoutePreview = null; }
    if (spanRouteRubber)  { spanRouteRubber.remove();  spanRouteRubber  = null; }
    clearRouteSnapHighlight();
    map.off('mousemove', onSpanRouteMouseMove);
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  // ── ONU drop route-via-poles functions ──────────────────────────────────────

  window._onuStartRouteDrop = function (deviceId) {
    const dp = dropPropsByDevId[deviceId];
    if (!dp || dp.fosc_lat == null || dp.ont_lat == null) {
      toast('Drop location data not available — reload drops first', 'warning');
      return;
    }
    map.closePopup();
    cancelActiveEdit();

    // Hide the existing drop line so it doesn't conflict with the routing preview
    let hiddenDropLayer = null;
    dropGroup.eachLayer(l => { if (l._uispDeviceId === deviceId) hiddenDropLayer = l; });
    if (hiddenDropLayer) dropGroup.removeLayer(hiddenDropLayer);

    routingOnuDrop     = true;
    onuDropRouteTarget = {
      uisp_device_id: deviceId,
      fosc_ll:        L.latLng(dp.fosc_lat, dp.fosc_lon),
      onu_ll:         L.latLng(dp.ont_lat, dp.ont_lon),
      hiddenLayer:    hiddenDropLayer,  // restore on cancel
    };
    onuDropRoutePoles  = [];
    map.getContainer().style.cursor = 'crosshair';
    map.on('mousemove', onOnuDropRouteMouseMove);
    updateOnuDropRouteBar();
  };

  function updateOnuDropRouteBar() {
    const n    = onuDropRoutePoles.length;
    const segs = n > 0 ? ` — <strong>${n} pole${n !== 1 ? 's' : ''}</strong> added` : '';
    showEditBar(
      `&#x1F4CD; <strong>Route ONU drop via poles</strong>${segs} &mdash; click poles from closure toward house`,
      n > 0 ? saveOnuDropRoute : null,
      function () { exitOnuDropRouteMode(); },
      'Save Route'
    );
  }

  function updateOnuDropRoutePreview() {
    if (onuDropRoutePreview) { onuDropRoutePreview.remove(); onuDropRoutePreview = null; }
    const t = onuDropRouteTarget;
    if (!t) return;
    const pts = [t.fosc_ll, ...onuDropRoutePoles.map(p => [p.lat, p.lon]), t.onu_ll];
    if (pts.length < 2) return;
    onuDropRoutePreview = L.polyline(pts, {
      color: '#f97316', weight: 3, opacity: 0.8, dashArray: '8 5', interactive: false,
    }).addTo(map);
  }

  function onOnuDropRouteMouseMove(e) {
    applyRouteSnapHighlight(map.latLngToContainerPoint(e.latlng));
    if (onuDropRouteRubber) { onuDropRouteRubber.remove(); onuDropRouteRubber = null; }
    const t = onuDropRouteTarget;
    if (!t) return;
    const last = onuDropRoutePoles.length > 0
      ? [onuDropRoutePoles[onuDropRoutePoles.length - 1].lat, onuDropRoutePoles[onuDropRoutePoles.length - 1].lon]
      : t.fosc_ll;
    onuDropRouteRubber = L.polyline([last, e.latlng], {
      color: '#f97316', weight: 2, opacity: 0.5, dashArray: '5 5', interactive: false,
    }).addTo(map);
  }

  async function saveOnuDropRoute() {
    const t = onuDropRouteTarget;
    if (!t) return;
    const coords = [
      [t.fosc_ll.lng, t.fosc_ll.lat],
      ...onuDropRoutePoles.map(p => [p.lon, p.lat]),
      [t.onu_ll.lng, t.onu_ll.lat],
    ];
    exitOnuDropRouteMode(false);  // don't restore old drop — loadDropSpans() rebuilds it
    try {
      const resp = await fetch('/api/ont_association.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify({ uisp_device_id: t.uisp_device_id, geojson: { type: 'LineString', coordinates: coords } }),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Save failed');
      loadDropSpans();
    } catch (err) {
      toast('Route save failed: ' + err.message, 'danger');
    }
  }

  function exitOnuDropRouteMode(restoreDrop = true) {
    if (restoreDrop && onuDropRouteTarget?.hiddenLayer) {
      dropGroup.addLayer(onuDropRouteTarget.hiddenLayer);
    }
    routingOnuDrop     = false;
    onuDropRoutePoles  = [];
    onuDropRouteTarget = null;
    if (onuDropRoutePreview) { onuDropRoutePreview.remove(); onuDropRoutePreview = null; }
    if (onuDropRouteRubber)  { onuDropRouteRubber.remove();  onuDropRouteRubber  = null; }
    clearRouteSnapHighlight();
    map.off('mousemove', onOnuDropRouteMouseMove);
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  window._onuClearRoute = async function (deviceId) {
    map.closePopup();
    try {
      const resp = await fetch('/api/ont_association.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify({ uisp_device_id: deviceId, geojson: null }),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Clear failed');
      loadDropSpans();
    } catch (err) {
      toast('Clear route failed: ' + err.message, 'danger');
    }
  };

  // ── Customer drop route-via-poles functions ──────────────────────────────────
  // One flow, two subjects: a CRM lead (no service sold yet) and a prepared
  // service (sold, not yet built). They differ only in which id identifies the
  // drop and which association endpoint stores the route.

  const CUST_DROP_KINDS = {
    lead: {
      label:    'lead',
      endpoint: '/api/lead_association.php',
      idKey:    'crm_client_id',
      props:    id => leadDropPropsByCrmId[String(id)] || null,
      layerId:  (layer, id) => layer._crmClientId === id,
    },
    service: {
      label:    'service',
      endpoint: '/api/service_association.php',
      idKey:    'crm_service_id',
      props:    id => svcDropPropsBySvcId[String(id)] || null,
      layerId:  (layer, id) => layer._crmServiceId === id,
    },
  };

  function startCustDropRoute(kind, id) {
    const spec = CUST_DROP_KINDS[kind];
    const dp   = spec.props(id);
    if (!dp) { toast(`${spec.label} drop data not loaded yet — try again`, 'warning'); return; }
    map.closePopup();
    cancelActiveEdit();

    // Hide the existing drop line so it doesn't conflict with the routing preview
    let hiddenDropLayer = null;
    dropGroup.eachLayer(l => { if (spec.layerId(l, id)) hiddenDropLayer = l; });
    if (hiddenDropLayer) dropGroup.removeLayer(hiddenDropLayer);

    routingCustDrop    = true;
    custDropRouteTarget = {
      kind:        kind,
      id:          id,
      fosc_ll:     L.latLng(dp.fosc_lat, dp.fosc_lon),
      cust_ll:     L.latLng(dp.ont_lat,  dp.ont_lon),
      hiddenLayer: hiddenDropLayer,
    };
    custDropRoutePoles = [];
    map.getContainer().style.cursor = 'crosshair';
    map.on('mousemove', onCustDropRouteMouseMove);
    updateCustDropRouteBar();
  }

  async function saveCustDropRouteGeom(kind, id, geojson) {
    const spec = CUST_DROP_KINDS[kind];
    const resp = await fetch(spec.endpoint, {
      method:  'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body:    JSON.stringify({ [spec.idKey]: id, geojson }),
    });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    loadDropSpans();
  }

  window._leadStartRouteDrop = function (crmClientId) { startCustDropRoute('lead', crmClientId); };
  window._svcStartRouteDrop  = function (crmServiceId) { startCustDropRoute('service', crmServiceId); };

  function updateCustDropRouteBar() {
    const n     = custDropRoutePoles.length;
    const label = CUST_DROP_KINDS[custDropRouteTarget?.kind]?.label ?? 'customer';
    const segs  = n > 0 ? ` — <strong>${n} pole${n !== 1 ? 's' : ''}</strong> added` : '';
    showEditBar(
      `&#x1F4CD; <strong>Route ${label} drop via poles</strong>${segs} &mdash; click poles from closure toward house`,
      n > 0 ? saveCustDropRoute : null,
      function () { exitCustDropRouteMode(); },
      'Save Route',
    );
  }

  function updateCustDropRoutePreview() {
    if (custDropRoutePreview) { custDropRoutePreview.remove(); custDropRoutePreview = null; }
    const t = custDropRouteTarget;
    if (!t) return;
    const pts = [t.fosc_ll, ...custDropRoutePoles.map(p => [p.lat, p.lon]), t.cust_ll];
    if (pts.length < 2) return;
    custDropRoutePreview = L.polyline(pts, {
      color: '#f59e0b', weight: 3, opacity: 0.8, dashArray: '8 5', interactive: false,
    }).addTo(map);
  }

  function onCustDropRouteMouseMove(e) {
    applyRouteSnapHighlight(map.latLngToContainerPoint(e.latlng));
    if (custDropRouteRubber) { custDropRouteRubber.remove(); custDropRouteRubber = null; }
    const t = custDropRouteTarget;
    if (!t) return;
    const last = custDropRoutePoles.length > 0
      ? [custDropRoutePoles[custDropRoutePoles.length - 1].lat, custDropRoutePoles[custDropRoutePoles.length - 1].lon]
      : t.fosc_ll;
    custDropRouteRubber = L.polyline([last, e.latlng], {
      color: '#f59e0b', weight: 2, opacity: 0.5, dashArray: '5 5', interactive: false,
    }).addTo(map);
  }

  async function saveCustDropRoute() {
    const t = custDropRouteTarget;
    if (!t) return;
    const coords = [
      [t.fosc_ll.lng, t.fosc_ll.lat],
      ...custDropRoutePoles.map(p => [p.lon, p.lat]),
      [t.cust_ll.lng, t.cust_ll.lat],
    ];
    exitCustDropRouteMode(false);
    try {
      await saveCustDropRouteGeom(t.kind, t.id, { type: 'LineString', coordinates: coords });
    } catch (err) {
      toast('Route save failed: ' + err.message, 'danger');
    }
  }

  function exitCustDropRouteMode(restoreDrop = true) {
    if (restoreDrop && custDropRouteTarget?.hiddenLayer) {
      dropGroup.addLayer(custDropRouteTarget.hiddenLayer);
    }
    routingCustDrop     = false;
    custDropRoutePoles  = [];
    custDropRouteTarget = null;
    if (custDropRoutePreview) { custDropRoutePreview.remove(); custDropRoutePreview = null; }
    if (custDropRouteRubber)  { custDropRouteRubber.remove();  custDropRouteRubber  = null; }
    clearRouteSnapHighlight();
    map.off('mousemove', onCustDropRouteMouseMove);
    map.getContainer().style.cursor = '';
    hideEditBar();
  }

  async function clearCustDropRoute(kind, id) {
    map.closePopup();
    try {
      await saveCustDropRouteGeom(kind, id, null);
    } catch (err) {
      toast('Clear route failed: ' + err.message, 'danger');
    }
  }

  window._leadClearRoute = function (crmClientId)  { return clearCustDropRoute('lead', crmClientId); };
  window._svcClearRoute  = function (crmServiceId) { return clearCustDropRoute('service', crmServiceId); };

  function openStrandPathModal(path) {
    const modal = document.getElementById('strandPathModal');
    if (!modal) return;
    const n = path.length;
    document.getElementById('strandPathSummary').textContent =
      `${n} poles → ${n - 1} strand${n > 2 ? 's' : ''} will be created`;
    document.getElementById('strandPathSide').value         = 'road';
    document.getElementById('strandPathHeight').value       = 'top';
    document.getElementById('strandPathStatus').value       = 'plan';
    document.getElementById('strandPathTension').value      = 'normal';
    document.getElementById('strandPathNotes').value        = '';
    document.getElementById('strandPathFpmWor').value    = '';
    document.getElementById('strandPathIsOverlash').checked = false;
    document.getElementById('strandPathError').style.display = 'none';

    const saveBtn = document.getElementById('saveStrandPathBtn');
    const newSave = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newSave, saveBtn);
    newSave.addEventListener('click', function () { saveStrandPath(path); });

    new bootstrap.Modal(modal).show();
  }

  async function saveStrandPath(path) {
    const modal = document.getElementById('strandPathModal');
    const errEl = document.getElementById('strandPathError');
    errEl.style.display = 'none';

    const side       = document.getElementById('strandPathSide').value;
    const height     = document.getElementById('strandPathHeight').value;
    const status     = document.getElementById('strandPathStatus').value;
    const notes      = document.getElementById('strandPathNotes').value.trim();
    const fpmWor  = document.getElementById('strandPathFpmWor').value.trim();
    const isOverlash = document.getElementById('strandPathIsOverlash').checked;

    // Each endpoint is a pole OR a building anchor (migration 0127).
    const endpointFields = (node, side) => node.kind === 'anchor'
      ? { [`${side}_anchor_id`]: node.id }
      : { [`${side}_pole_fid`]: node.fid };
    try {
      for (let i = 0; i < path.length - 1; i++) {
        const body = {
          ...endpointFields(path[i],     'from'),
          ...endpointFields(path[i + 1], 'to'),
          from_side:     side,
          from_height:   height,
          to_side:       side,
          to_height:     height,
          status,
          notes,
          fpm_wor:    fpmWor,
          is_overlash:   isOverlash,
        };
        const resp = await fetch('/api/strand.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
          body:    JSON.stringify(body),
        });
        const data = await resp.json();
        if (!data.ok) throw new Error(`Segment ${i + 1}: ` + (data.error || 'Save failed'));
      }
      bootstrap.Modal.getInstance(modal)?.hide();
      exitAddStrandMode();
      loadPermitList();
      loadFeatures(true);
      toast(`${path.length - 1} strand${path.length > 2 ? 's' : ''} added`, 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  // ── Strand modal (create & edit) ─────────────────────────────────────────────

  function openStrandModal(fromPole, toPole, existingProps) {
    const modal    = document.getElementById('strandModal');
    const errEl    = document.getElementById('strandModalError');
    const idInput  = document.getElementById('strandIdInput');
    const fromFid  = document.getElementById('strandFromFidInput');
    const toFid    = document.getElementById('strandToFidInput');
    const fromDisp = document.getElementById('strandFromDisplay');
    const toDisp   = document.getElementById('strandToDisplay');
    const delBtn   = document.getElementById('deleteStrandBtn');
    if (!modal) return;

    errEl.style.display = 'none';

    const isNew = !existingProps;
    document.getElementById('strandModalLabel').textContent = isNew ? 'Add Messenger Strand' : 'Edit Messenger Strand';

    if (isNew) {
      idInput.value = '';
      fromFid.value = fromPole.fid;
      toFid.value   = toPole.fid;
      fromDisp.value = fromPole.uid || (fromPole.nbp ? 'NBP# ' + fromPole.nbp : 'fid ' + fromPole.fid);
      toDisp.value   = toPole.uid   || (toPole.nbp   ? 'NBP# ' + toPole.nbp   : 'fid ' + toPole.fid);
      document.getElementById('strandFromSide').value   = 'road';
      document.getElementById('strandFromHeight').value = 'top';
      document.getElementById('strandToSide').value     = 'road';
      document.getElementById('strandToHeight').value   = 'top';
      document.getElementById('strandStatus').value     = 'plan';
      document.getElementById('strandTension').value    = 'normal';
      document.getElementById('strandNotes').value      = '';
      document.getElementById('strandFpmWor').value  = '';
      document.getElementById('strandIsOverlash').checked = false;
      delBtn.style.display = 'none';
    } else {
      idInput.value = existingProps.id;
      fromFid.value = existingProps.from_pole_fid;
      toFid.value   = existingProps.to_pole_fid;
      const fLabel  = existingProps.from_pole_uid || (existingProps.from_pole_nbp ? 'NBP# ' + existingProps.from_pole_nbp : 'fid ' + existingProps.from_pole_fid);
      const tLabel  = existingProps.to_pole_uid   || (existingProps.to_pole_nbp   ? 'NBP# ' + existingProps.to_pole_nbp   : 'fid ' + existingProps.to_pole_fid);
      fromDisp.value = fLabel;
      toDisp.value   = tLabel;
      document.getElementById('strandFromSide').value   = existingProps.from_side   || 'road';
      document.getElementById('strandFromHeight').value = existingProps.from_height || 'top';
      document.getElementById('strandToSide').value     = existingProps.to_side     || 'road';
      document.getElementById('strandToHeight').value   = existingProps.to_height   || 'top';
      document.getElementById('strandStatus').value     = existingProps.status      || 'plan';
      document.getElementById('strandTension').value    = existingProps.tension     || 'normal';
      document.getElementById('strandNotes').value      = existingProps.notes       || '';
      document.getElementById('strandFpmWor').value  = existingProps.fpm_wor  || '';
      document.getElementById('strandIsOverlash').checked = !!existingProps.is_overlash;
      delBtn.style.display = '';
    }

    const saveBtn = document.getElementById('saveStrandBtn');
    const newSave = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newSave, saveBtn);
    newSave.addEventListener('click', function () { saveStrand(isNew); });

    const newDel = delBtn.cloneNode(true);
    newDel.style.display = isNew ? 'none' : '';
    delBtn.parentNode.replaceChild(newDel, delBtn);
    newDel.addEventListener('click', deleteStrand);

    new bootstrap.Modal(modal).show();
  }

  async function saveStrand(isNew) {
    const modal  = document.getElementById('strandModal');
    const errEl  = document.getElementById('strandModalError');
    const id     = parseInt(document.getElementById('strandIdInput').value, 10) || 0;
    errEl.style.display = 'none';

    const body = {
      from_side:   document.getElementById('strandFromSide').value,
      from_height: document.getElementById('strandFromHeight').value,
      to_side:     document.getElementById('strandToSide').value,
      to_height:   document.getElementById('strandToHeight').value,
      status:      document.getElementById('strandStatus').value,
      tension:     document.getElementById('strandTension').value,
      notes:       document.getElementById('strandNotes').value.trim(),
      fpm_wor:  document.getElementById('strandFpmWor').value.trim(),
      is_overlash: document.getElementById('strandIsOverlash').checked,
    };
    if (isNew) {
      body.from_pole_fid = parseInt(document.getElementById('strandFromFidInput').value, 10);
      body.to_pole_fid   = parseInt(document.getElementById('strandToFidInput').value, 10);
    } else {
      body.id = id;
    }

    try {
      const resp = await fetch('/api/strand.php', {
        method:  isNew ? 'POST' : 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body:    JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Save failed');
      bootstrap.Modal.getInstance(modal)?.hide();
      loadPermitList();
      loadFeatures(true);
      toast((isNew ? 'Strand added' : 'Strand saved')
              + (data.inherited_summary ? ' — permit also applied to ' + data.inherited_summary : ''),
            'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  async function deleteStrand() {
    const modal = document.getElementById('strandModal');
    const errEl = document.getElementById('strandModalError');
    const id    = parseInt(document.getElementById('strandIdInput').value, 10) || 0;
    if (!id || !confirm('Delete this messenger strand?')) return;
    errEl.style.display = 'none';
    try {
      await strandDeleteRequest(id);
      bootstrap.Modal.getInstance(modal)?.hide();
      loadFeatures(true);
      toast('Strand deleted', 'success');
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = '';
    }
  }

  async function strandDeleteRequest(id) {
    const resp = await fetch('/api/strand.php', {
      method:  'DELETE',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body:    JSON.stringify({ id }),
    });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Delete failed');
    return data;
  }

  // Popup delete: no modal is open, so errors surface as a toast rather than in
  // the edit form's error div.
  async function deleteStrandById(id, isOverlash) {
    const what = isOverlash ? 'overlash' : 'messenger strand';
    if (!id || !confirm(`Delete this ${what}?`)) return;
    try {
      await strandDeleteRequest(id);
      loadFeatures(true);
      toast((isOverlash ? 'Overlash' : 'Strand') + ' deleted', 'success');
    } catch (e) {
      toast(e.message, 'danger');
    }
  }

  function toggleStrands() {
    strandsVisible = !strandsVisible;
    document.getElementById('btnToggleStrands')?.classList.toggle('tgl-off', !strandsVisible);
    if (strandsVisible) strandGroup.addTo(map); else map.removeLayer(strandGroup);
    map.getContainer().classList.toggle('route-dist-strand-hidden', !strandsVisible);
    saveLayerState();
  }

  function toggleFlextinDist() {
    flextinDistVisible = !flextinDistVisible;
    document.getElementById('btnToggleFlextinDist')?.classList.toggle('tgl-off', !flextinDistVisible);
    if (flextinDistVisible) flextinDistGroup.addTo(map); else map.removeLayer(flextinDistGroup);
    saveLayerState();
  }

  function toggleRouteDist() {
    routeDistVisible = !routeDistVisible;
    document.getElementById('btnToggleRouteDist')?.classList.toggle('tgl-off', !routeDistVisible);
    if (routeDistVisible) routeDistGroup.addTo(map); else map.removeLayer(routeDistGroup);
    saveLayerState();
  }

  function toggleBoundary() {
    boundaryVisible = !boundaryVisible;
    const btn = document.getElementById('btnToggleBoundary');
    if (btn) btn.classList.toggle('tgl-off', !boundaryVisible);
    if (boundaryVisible) {
      boundaryGroup.addTo(map);
      if (!boundaryLoaded) loadBoundaryLayer();
    } else {
      map.removeLayer(boundaryGroup);
    }
    saveLayerState();
  }

  async function loadBoundaryLayer() {
    if (boundaryLoaded) return;
    try {
      const resp = await fetch('data/SalisburyTownLimits.geojson');
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      L.geoJSON(geojson, {
        style: { color: '#920e0e', weight: 2.5, opacity: 0.9, fill: false },
        interactive: false,
      }).addTo(boundaryGroup);
      boundaryLoaded = true;
    } catch (e) {
      console.error('Failed to load town boundary:', e);
    }
  }

  // ── Salisbury-only asset filter ──────────────────────────────────────────────
  // Loads the town boundary ring once, then uses ray-casting PIP to hide any
  // marker or polyline whose representative coordinate falls outside.
  // All work is DOM display toggling — no server round-trips on each toggle.

  async function loadSalisburyRing() {
    if (_salisburyRing) return;
    try {
      const resp = await fetch('data/SalisburyTownLimits.geojson');
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const gj  = await resp.json();
      const ring = gj.features[0].geometry.coordinates[0][0]; // MultiPolygon→poly0→outer ring
      _salisburyRing = ring;
      const lngs = ring.map(c => c[0]);
      const lats = ring.map(c => c[1]);
      _salisburyBbox = [Math.min(...lngs), Math.min(...lats), Math.max(...lngs), Math.max(...lats)];
    } catch (e) {
      console.error('Failed to load Salisbury ring for filter:', e);
    }
  }

  function _inSalisbury(lng, lat) {
    if (!_salisburyRing) return true; // ring not loaded yet — show everything
    const [minLng, minLat, maxLng, maxLat] = _salisburyBbox;
    if (lng < minLng || lng > maxLng || lat < minLat || lat > maxLat) return false; // fast bbox rejection
    // Ray-casting point-in-polygon
    const ring = _salisburyRing;
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const xi = ring[i][0], yi = ring[i][1];
      const xj = ring[j][0], yj = ring[j][1];
      if ((yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi) / (yj - yi) + xi))
        inside = !inside;
    }
    return inside;
  }

  function _setLayerDisplay(layer, show) {
    const el = layer.getElement?.() ?? layer._path;
    if (el) el.style.display = show ? '' : 'none';
  }

  function applySalisburyFilter() {
    // Point markers: check exact coordinate
    [poleGroup, foscGroup, popGroup, anchorGroup, buildingAnchorGroup, uguardGroup, vaultGroup, handholeGroup, noteGroup].forEach(group => {
      group.eachLayer(layer => {
        if (!layer.getLatLng) return;
        const ll = layer.getLatLng();
        _setLayerDisplay(layer, _inSalisbury(ll.lng, ll.lat));
      });
    });
    // Polylines: check midpoint coordinate (fast, accurate for coherent work areas)
    [spanGroup, strandGroup, conduitGroup].forEach(group => {
      group.eachLayer(layer => {
        let lls = layer.getLatLngs?.();
        if (!lls || !lls.length) return;
        if (Array.isArray(lls[0])) lls = lls.flat();
        const mid = lls[Math.floor(lls.length / 2)];
        _setLayerDisplay(layer, _inSalisbury(mid.lng, mid.lat));
      });
    });
  }

  function clearSalisburyFilter() {
    [poleGroup, foscGroup, popGroup, anchorGroup, uguardGroup, vaultGroup, handholeGroup, noteGroup,
     spanGroup, strandGroup, conduitGroup].forEach(group => {
      group.eachLayer(layer => _setLayerDisplay(layer, true));
    });
    applyFlextinClosureFilter();
  }

  async function toggleSalisburyOnly() {
    salisburyOnlyMode = !salisburyOnlyMode;
    const btn = document.getElementById('btnSalisburyOnly');
    if (btn) btn.classList.toggle('tgl-off', !salisburyOnlyMode);
    if (salisburyOnlyMode) {
      await loadSalisburyRing();
      applySalisburyFilter();
    } else {
      clearSalisburyFilter();
      // Re-apply pole attribute filter so its hidden poles stay hidden
      if (poleFilterActive()) applyPoleFilter();
    }
    saveLayerState();
  }

  function toggleExch() {
    exchVisible = !exchVisible;
    const btn = document.getElementById('btnToggleExch');
    if (btn) btn.classList.toggle('tgl-off', !exchVisible);
    if (exchVisible) {
      exchGroup.addTo(map);
      if (!exchLoaded) loadExchLayer();
    } else {
      map.removeLayer(exchGroup);
    }
    saveLayerState();
  }

  async function loadExchLayer() {
    if (exchLoaded) return;
    try {
      const resp = await fetch('data/SalisburyPhoneExchange.geojson');
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      L.geoJSON(geojson, {
        style: { color: '#005BAC', weight: 2, opacity: 0.85, fillColor: '#005BAC', fillOpacity: 0.25 },
        interactive: false,
      }).addTo(exchGroup);
      exchLoaded = true;
    } catch (e) {
      console.error('Failed to load phone exchange boundary:', e);
    }
  }

  // ── Road Coverage layer ──────────────────────────────────────────────────────

  // [provider, technology, providerLabel, techLabel, lineColor]
  // Colors: Bell Aliant = shades of Bell blue, Rogers = shades of red, Tinuum = shades of green
  // Display precedence: 1=Tinuum, 2=Bell FTTH, 3=Rogers FTTH, 4=Rogers Hybrid-Coax, then rest.
  // Expansion targets sit below Tinuum's built and committed plant but above the
  // competitors: an expansion road almost always already has a Bell or Rogers
  // service, and we want to see the target, not who is on it today.
  const RC_COMBOS = [
    [OWN_NAME,      'fiber_ftth',       OWN_NAME,      'FTTH',           '#22C55E'],
    [OWN_NAME,      'fiber_mainline',   OWN_NAME,      'Fibre Mainline', '#15803D'],
    ['Tinuum',      'fiber_coming_soon','Tinuum',      'Coming Soon',    '#86EFAC'],
    [OWN_NAME,      'fiber_expansion',  OWN_NAME,      'Expansion Target','#EAB308'],
    ['Bell Aliant', 'fiber_ftth',       'Bell Aliant', 'FTTH',           '#3B82F6'],
    ['Bell Aliant', 'fiber_mainline',   'Bell Aliant', 'Fibre Mainline', '#005BAC'],
    ['Bell Aliant', 'copper_dsl',       'Bell Aliant', 'Copper (DSL)',   '#1D4ED8'],
    ['Rogers',      'fiber_ftth',       'Rogers',      'FTTH',           '#EF4444'],
    ['Rogers',      'hybrid_coax',      'Rogers',      'Hybrid-Coax',    '#EF4444'],
    ['Rogers',      'fiber_mainline',   'Rogers',      'Fibre Mainline', '#991B1B'],
    ['None',        'no_telecom',       'No Telecom',  '',               '#6B7280'],
  ];
  // Which service colours a segment when it has several: best service wins.
  // Separate from RC_COMBOS, which stays grouped by provider for the popup and
  // the selection bar. (RC_COMBOS order used to double as this, so every Bell
  // service outranked every Rogers one: Bell copper hid Rogers coax and FTTH.)
  const RC_PRIORITY = [
    'Tinuum|fiber_ftth', 'Tinuum|fiber_mainline', 'Tinuum|fiber_coming_soon', 'Tinuum|fiber_expansion',
    'Bell Aliant|fiber_ftth', 'Rogers|fiber_ftth',
    'Rogers|hybrid_coax',
    'Bell Aliant|fiber_mainline',
    'Rogers|fiber_mainline',
    'Bell Aliant|copper_dsl',
    'None|no_telecom',
  ];
  const RC_BY_KEY = Object.fromEntries(RC_COMBOS.map(c => [c[0] + '|' + c[1], c]));
  window._rcLines = {};

  const RC_TECH_STYLE = {
    fiber_ftth:        { weight: 4, opacity: 0.90, dashArray: null   },
    hybrid_coax:       { weight: 4, opacity: 0.75, dashArray: null   },
    fiber_mainline:    { weight: 3, opacity: 0.85, dashArray: '8 4'  },
    copper_dsl:        { weight: 3, opacity: 0.80, dashArray: null   },
    no_telecom:        { weight: 3, opacity: 0.80, dashArray: null   },
    fiber_coming_soon: { weight: 4, opacity: 0.85, dashArray: '10 4' },
    fiber_expansion:   { weight: 4, opacity: 0.90, dashArray: '2 6'  },
  };

  function roadCovStyleFromServices(services) {
    if (!services || services.length === 0)
      return { color: '#94a3b8', weight: 3, opacity: 0.75, dashArray: '6 5' };
    for (const key of RC_PRIORITY) {
      const [prov, tech, , , col] = RC_BY_KEY[key];
      if (services.find(function (s) { return s.provider === prov && s.technology === tech; })) {
        const ts = RC_TECH_STYLE[tech];
        if (ts) return Object.assign({ color: col }, ts);
      }
    }
    return { color: '#94a3b8', weight: 3, opacity: 0.75, dashArray: '6 5' };
  }

  function rcWinningComboKey(services) {
    if (!services || services.length === 0) return 'unassigned';
    for (var i = 0; i < RC_PRIORITY.length; i++) {
      var prov = RC_BY_KEY[RC_PRIORITY[i]][0], tech = RC_BY_KEY[RC_PRIORITY[i]][1];
      for (var j = 0; j < services.length; j++) {
        if (services[j].provider === prov && services[j].technology === tech)
          return prov + '|' + tech;
      }
    }
    return 'unassigned';
  }

  function applyRoadTypeFilter() {
    const badge = document.getElementById('roadTypeFilterBadge');
    if (badge) {
      const active = rcComboHidden.size > 0;
      badge.style.display = active ? 'inline-flex' : 'none';
      badge.textContent   = active ? rcComboHidden.size : '';
    }
    eachRoadLayer(function (l) {
      if (!l._rcProps) return;
      const key    = rcWinningComboKey(l._rcProps.services);
      const target = rcComboHidden.has(key) ? 0 : (l._rcBaseStyle.opacity || 0.9);
      if (l.options.opacity !== target) l.setStyle({ opacity: target });
    });
    updateActiveFilterChip();
  }

  function toggleRoadTypeFilterPanel() {
    const panel = document.getElementById('roadTypeFilterPanel');
    if (!panel) return;
    const opening = panel.style.display === 'none';
    panel.style.display = opening ? '' : 'none';
    if (opening) {
      // bind checkboxes each open so state is always fresh
      panel.querySelectorAll('.rc-type-chk').forEach(function (cb) {
        cb.checked = !rcComboHidden.has(cb.dataset.combo);
        cb.onchange = function () {
          if (this.checked) rcComboHidden.delete(this.dataset.combo);
          else              rcComboHidden.add(this.dataset.combo);
          applyRoadTypeFilter();
        };
      });
      const allBtn  = document.getElementById('rcTypeAll');
      const noneBtn = document.getElementById('rcTypeNone');
      if (allBtn) allBtn.onclick = function () {
        rcComboHidden.clear();
        panel.querySelectorAll('.rc-type-chk').forEach(function (cb) { cb.checked = true; });
        applyRoadTypeFilter();
      };
      if (noneBtn) noneBtn.onclick = function () {
        panel.querySelectorAll('.rc-type-chk').forEach(function (cb) {
          rcComboHidden.add(cb.dataset.combo);
          cb.checked = false;
        });
        applyRoadTypeFilter();
      };
      const closeBtn = document.getElementById('roadTypeFilterClose');
      if (closeBtn) closeBtn.onclick = function () { panel.style.display = 'none'; };
    }
  }

  function toggleRoadCoverage() {
    roadCovVisible = !roadCovVisible;
    const btn         = document.getElementById('btnToggleRoadCov');
    const boxBtn      = document.getElementById('btnRoadCovBoxSel');
    const outsideBtn  = document.getElementById('btnOutsideRoads');
    const typeBtn     = document.getElementById('btnRoadTypeFilter');
    if (btn) btn.classList.toggle('tgl-off', !roadCovVisible);
    if (boxBtn)     boxBtn.style.display    = roadCovVisible ? '' : 'none';
    if (outsideBtn) outsideBtn.style.display = roadCovVisible ? '' : 'none';
    if (typeBtn)    typeBtn.style.display    = roadCovVisible ? '' : 'none';
    if (roadCovVisible) {
      roadCovGroup.addTo(map);
      if (!roadCovLoaded) loadRoadCoverage();
    } else {
      exitRoadCovBoxSelect();
      map.removeLayer(roadCovGroup);
      const panel = document.getElementById('roadTypeFilterPanel');
      if (panel) panel.style.display = 'none';
      if (outsideRoadsVisible) {
        map.removeLayer(roadCovOutsideGroup);
        outsideRoadsVisible = false;
        if (outsideBtn) outsideBtn.classList.add('tgl-off');
      }
    }
    saveLayerState();
  }

  // Put each outside segment in the right group for what it carries now:
  // marked → always-shown group; unmarked → the "Outside Salisbury" group.
  function rcRegroupOutside() {
    const move = [];
    roadCovGroup.eachLayer(function (l) {
      if (l._rcProps && !l._rcProps.in_boundary && !(l._rcProps.services || []).length) move.push([l, roadCovOutsideGroup, roadCovGroup]);
    });
    roadCovOutsideGroup.eachLayer(function (l) {
      if (l._rcProps && (l._rcProps.services || []).length) move.push([l, roadCovGroup, roadCovOutsideGroup]);
    });
    move.forEach(function ([l, to, from]) { from.removeLayer(l); to.addLayer(l); });
  }

  function toggleOutsideRoads() {
    rcRegroupOutside();
    outsideRoadsVisible = !outsideRoadsVisible;
    const btn = document.getElementById('btnOutsideRoads');
    if (btn) btn.classList.toggle('tgl-off', !outsideRoadsVisible);
    if (outsideRoadsVisible) {
      roadCovOutsideGroup.addTo(map);
      if (!roadCovLoaded) loadRoadCoverage();
    } else {
      map.removeLayer(roadCovOutsideGroup);
    }
    saveLayerState();
  }

  // ── Service Area (75 m buffered coverage polygons) ────────────────────────

  const SA_STYLE = {
    active: { color: '#16a34a', weight: 2, opacity: 0.8, fillColor: '#22C55E', fillOpacity: 0.20 },
    coming: { color: '#ea580c', weight: 2, opacity: 0.8, fillColor: '#F97316', fillOpacity: 0.15, dashArray: '8 4' },
  };

  // Service area works as a "solo" view: turning it on switches every other map layer off, and turning it off
  // again puts back the ones that were on. (Switching it on at page load from the saved state does neither.)
  const SOLO_OTHER_LAYERS = ['Poles', 'Closures', 'FlextinClosures', 'Pops', 'Onus', 'Alden', 'Anchors', 'BuildingAnchors', 'Uguards',
    'Vaults', 'Handholes', 'Notes', 'Dims', 'Marks', 'Spans', 'Strands', 'Conduits', 'FlextinDist', 'RouteDist', 'Civics', 'Density',
    'Boundary', 'Exch', 'RoadCov', 
    'Parcels', 'Wifi', 'Easements', 'Zoning', 'Communities',
    'Wetlands',
  ].map(n => 'btnToggle' + n);
  let soloRestore = null, soloSurvey = false;
  // The Service Area button: solo view. (toggleServiceArea itself stays a plain toggle, for the B&W mode and layer restore.)
  function soloServiceArea() {
    if (!serviceAreaVisible) {
      soloRestore = SOLO_OTHER_LAYERS.filter(id => { const b = document.getElementById(id); return b && !b.classList.contains('tgl-off'); });
      soloRestore.forEach(id => document.getElementById(id).click());
      // draft survey lines (the purple dashed ones) have no toggle button of their own
      soloSurvey = map.hasLayer(surveyGroup);
      if (soloSurvey) map.removeLayer(surveyGroup);
    }
    toggleServiceArea();
    if (!serviceAreaVisible && soloSurvey) { soloSurvey = false; surveyGroup.addTo(map); }
    if (!serviceAreaVisible && soloRestore) { const back = soloRestore; soloRestore = null; back.forEach(id => { const b = document.getElementById(id); if (b && b.classList.contains('tgl-off')) b.click(); }); }
  }
  function toggleServiceArea() {
    serviceAreaVisible = !serviceAreaVisible;
    const btn = document.getElementById('btnToggleServiceArea');
    if (btn) btn.classList.toggle('tgl-off', !serviceAreaVisible);
    if (serviceAreaVisible) {
      serviceAreaGroup.addTo(map);
      if (!serviceAreaLoaded) loadServiceArea();
    } else {
      map.removeLayer(serviceAreaGroup);
    }
    saveLayerState();
  }

  async function loadServiceArea() {
    if (serviceAreaLoaded) return;
    try {
      const resp = await fetch('/api/service_area.php', { cache: 'no-cache' });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      serviceAreaGroup.clearLayers();
      (geojson.features || []).forEach(function (f) {
        const status = f.properties.status;
        const style  = SA_STYLE[status] || SA_STYLE.active;
        const layer  = L.geoJSON(f, {
          style: function () { return style; },
        });
        layer.bindTooltip(status === 'active' ? 'Tinuum Active Service Area' : 'Tinuum Coming Soon', { sticky: true, className: 'svc-area-tip' });
        serviceAreaGroup.addLayer(layer);
      });
      serviceAreaLoaded = true;
    } catch (e) {
      console.error('Failed to load service area:', e);
      toast('Failed to load service area', 'danger');
    }
  }

  async function loadRoadCoverage() {
    if (roadCovLoaded) return;
    try {
      const resp = await fetch('/api/road_coverage.php?all=1');
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      roadCovGroup.clearLayers();
      roadCovOutsideGroup.clearLayers();
      // Outside the town boundary, a road someone has marked (any service, e.g.
      // a Tinuum Expansion Target) always shows; "Outside Salisbury" only adds
      // the unmarked ones. Marked outside roads used to vanish with that toggle
      // off, which is its default after every reload.
      (geojson.features || []).forEach(function (f) {
        const shown = f.properties.in_boundary || (f.properties.services || []).length > 0;
        renderRoadSegment(f, shown ? roadCovGroup : roadCovOutsideGroup);
      });
      roadCovLoaded = true;
      if (rcComboHidden.size > 0) applyRoadTypeFilter();
    } catch (e) {
      console.error('Failed to load road coverage:', e);
      toast('Failed to load road coverage', 'danger');
    }
  }

  // OSM splits a road into ~200 m pieces (Goose Road is 13). Clicking one piece
  // and unticking a service only changed that piece, so the rest stayed yellow.
  // With "Whole road" on (the default), the popup's ticks apply to every loaded
  // piece of the same OSM way.
  let rcWholeRoad = true;
  // Same OSM way AND same side of the town boundary: Route 112 is one way with
  // 20 pieces in town and 9 outside, and ticking the outside stretch mustn't
  // stamp the in-town pieces too.
  function rcRoadSegIds(segId) {
    const p = window._rcLines[segId]?._rcProps;
    if (!p || !p.osm_way_id) return [segId];
    return Object.keys(window._rcLines).map(Number).filter(function (id) {
      const q = window._rcLines[id]._rcProps;
      return q.osm_way_id === p.osm_way_id && q.in_boundary === p.in_boundary;
    });
  }
  function rcTargets(segId) {
    return rcWholeRoad ? rcRoadSegIds(segId) : [segId];
  }
  window._rcSetWhole = function (segId, on) {
    rcWholeRoad = on;
    const pop = window._rcLines[segId]?.getPopup();
    if (pop && pop.isOpen()) pop.setContent(buildRoadPopupHtml(segId));
  };

  // Apply one tick/untick to several pieces, then restyle them and the popup.
  async function rcApplyToRoad(segId, provider, technology, add) {
    const ids = rcTargets(segId);
    const h = { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' };
    if (add) {
      await Promise.all(ids.map(async function (id) {
        const line = window._rcLines[id];
        const has = (line._rcProps.services || []).some(function (x) { return x.provider === provider && x.technology === technology; });
        if (has) return;
        const r = await (await fetch('/api/road_coverage.php', { method: 'POST', headers: h,
          body: JSON.stringify({ seg_id: id, provider, technology }) })).json();
        const next = rcServicesAfterAdd(line._rcProps.services, provider, technology, r.id);
        if (next) line._rcProps.services = next;
      }));
    } else {
      const r = await fetch('/api/road_coverage.php', { method: 'POST', headers: h,
        body: JSON.stringify({ action: 'remove', seg_ids: ids, provider, technology }) });
      const d = await r.json();
      if (!r.ok || d.ok === false) throw new Error(d.error || ('HTTP ' + r.status));
      ids.forEach(function (id) {
        const line = window._rcLines[id];
        line._rcProps.services = (line._rcProps.services || []).filter(function (x) {
          return !(x.provider === provider && x.technology === technology);
        });
      });
    }
    ids.forEach(function (id) {
      const line = window._rcLines[id];
      line._rcBaseStyle = roadCovStyleFromServices(line._rcProps.services);
      if (!roadCovSelection.has(id)) line.setStyle(line._rcBaseStyle);
    });
    const pop = window._rcLines[segId].getPopup();
    if (pop && pop.isOpen()) pop.setContent(buildRoadPopupHtml(segId));
  }

  function buildRoadPopupHtml(segId) {
    const line = window._rcLines[segId];
    if (!line) return '';
    const p = line._rcProps;
    const pieces = rcRoadSegIds(segId).length;
    const name = p.road_name ? esc(p.road_name) : '<em style="color:#94a3b8">Unnamed road</em>';
    const services = p.services || [];
    const svcMap = {};
    services.forEach(function (s) { svcMap[s.provider + '|' + s.technology] = s.id; });

    const rows = RC_COMBOS.map(function (combo) {
      const prov = combo[0], tech = combo[1], provLabel = combo[2], techLabel = combo[3], col = combo[4];
      const key = prov + '|' + tech;
      const checked = key in svcMap;
      const svcId = svcMap[key] || 0;
      return `<label style="display:flex;align-items:center;gap:7px;cursor:pointer;user-select:none;margin-bottom:5px;font-size:.82rem">` +
        `<input type="checkbox" onclick="window._rcToggle(${segId},'${prov}','${tech}',${svcId},this)"` +
        `${checked ? ' checked' : ''} style="width:14px;height:14px;flex-shrink:0;accent-color:${col}">` +
        `<span><span style="font-weight:600;color:${col}">${esc(provLabel)}</span>` +
        (techLabel ? ` <span style="color:${col}">— ${esc(techLabel)}</span>` : '') +
        `</span>` +
      `</label>`;
    }).join('');

    const clearBtn = services.length > 0
      ? `<div style="margin-top:7px;padding-top:6px;border-top:1px solid #e5e7eb">` +
        `<button onclick="window._rcClearAll(${segId})" style="font-size:.75rem;color:#6b7280;background:none;border:none;cursor:pointer;padding:0">✕ Clear all services</button>` +
        `</div>`
      : '';

    const whole = pieces > 1
      ? `<label style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:#475569;margin:-4px 0 8px;cursor:pointer" ` +
        `title="This road is split into ${pieces} pieces. Ticked: changes apply to all of them. Unticked: only the piece you clicked.">` +
        `<input type="checkbox" onclick="window._rcSetWhole(${segId}, this.checked)"${rcWholeRoad ? ' checked' : ''}>` +
        `Whole road (${pieces} pieces)</label>`
      : '';

    return `<div style="min-width:210px">` +
      `<div style="font-weight:600;margin-bottom:8px">${name}</div>` +
      whole +
      `<div>${rows}</div>` +
      clearBtn +
    `</div>`;
  }

  window._rcClearAll = async function (segId) {
    const line = window._rcLines[segId];
    if (!line) return;
    if (rcWholeRoad && rcRoadSegIds(segId).length > 1) {
      const ids = rcRoadSegIds(segId);
      if (!confirm('Clear all services from all ' + ids.length + ' pieces of this road?')) return;
      await bulkClearRoadServices(ids);
      line.closePopup();
      return;
    }
    const services = line._rcProps.services || [];
    if (services.length === 0) return;
    try {
      await Promise.all(services.map(function (s) {
        return fetch('/api/road_coverage.php?id=' + s.id, {
          method: 'DELETE',
          headers: { 'X-CSRF-Token': cfg.csrf || '' },
        });
      }));
      line._rcProps.services = [];
      const newStyle = roadCovStyleFromServices([]);
      line._rcBaseStyle = newStyle;
      if (!roadCovSelection.has(line._rcSegId)) line.setStyle(newStyle);
      const pop = line.getPopup();
      if (pop && pop.isOpen()) pop.setContent(buildRoadPopupHtml(segId));
    } catch (e) {
      toast('Failed to clear services: ' + e.message, 'danger');
    }
  };

  window._rcToggle = async function (segId, provider, technology, svcId, cb) {
    const line = window._rcLines[segId];
    if (!line) return;
    // Whole road, or a road that is one piece: by what it is, not by row id
    // (a row added by a bulk add may never have had its id sent back).
    if (rcWholeRoad || rcRoadSegIds(segId).length === 1) {
      cb.disabled = true;
      try { await rcApplyToRoad(segId, provider, technology, cb.checked); }
      catch (e) { cb.checked = !cb.checked; toast('Failed: ' + e.message, 'danger'); }
      cb.disabled = false;
      return;
    }
    if (cb.checked) {
      cb.disabled = true;
      await addRoadService(line, provider, technology);
      // update svcId in onclick attr for future unchecks
      const svc = (line._rcProps.services || []).find(function (s) {
        return s.provider === provider && s.technology === technology;
      });
      if (svc && svc.id) {
        cb.setAttribute('onclick',
          `window._rcToggle(${segId},'${provider}','${technology}',${svc.id},this)`);
      }
      cb.disabled = false;
    } else {
      if (!svcId) { cb.checked = false; return; }
      cb.disabled = true;
      await deleteRoadService(line, svcId);
      cb.setAttribute('onclick',
        `window._rcToggle(${segId},'${provider}','${technology}',0,this)`);
      cb.disabled = false;
    }
  };

  function renderRoadSegment(feature, targetGroup) {
    if (!feature.geometry) return;
    const p      = feature.properties;
    const coords = feature.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
    const style  = roadCovStyleFromServices(p.services);
    const line   = L.polyline(coords, style);

    line._rcSegId     = p.seg_id;
    line._rcProps     = p;
    line._rcBaseStyle = style;
    window._rcLines[p.seg_id] = line;

    line.bindPopup(function () { return buildRoadPopupHtml(line._rcSegId); }, { maxWidth: 280 });

    line.on('click', function (e) {
      if (e.originalEvent && e.originalEvent.shiftKey) {
        e.originalEvent.stopPropagation();
        L.DomEvent.stop(e);
        toggleRoadCovSelect(line);
      }
    });

    (targetGroup || roadCovGroup).addLayer(line);
  }

  // Mirror of the database rule (migration 0244): Tinuum FTTH means the
  // expansion is done, so it replaces Tinuum Expansion Target, and an Expansion
  // Target can't be added on top of FTTH. Returns the segment's services after
  // adding (provider, technology), or null when the add is refused.
  function rcServicesAfterAdd(services, provider, technology, id) {
    services = services || [];
    const isT = function (s, t) { return s.provider === OWN_NAME && s.technology === t; };
    if (provider === OWN_NAME && technology === 'fiber_expansion' && services.some(function (s) { return isT(s, 'fiber_ftth'); })) return null;
    if (provider === OWN_NAME && technology === 'fiber_ftth') services = services.filter(function (s) { return !isT(s, 'fiber_expansion'); });
    if (!services.some(function (s) { return s.provider === provider && s.technology === technology; })) {
      services = services.concat([{ id: id || null, provider, technology }]);
    }
    return services;
  }

  async function addRoadService(line, provider, technology) {
    try {
      const resp = await fetch('/api/road_coverage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
        body: JSON.stringify({ seg_id: line._rcSegId, provider, technology }),
      });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      if (data.skipped) toast(data.skipped + ' — Expansion Target not added', 'info');
      const next = rcServicesAfterAdd(line._rcProps.services, provider, technology, data.id);
      if (next) line._rcProps.services = next;
      const newStyle = roadCovStyleFromServices(line._rcProps.services);
      line._rcBaseStyle = newStyle;
      if (!roadCovSelection.has(line._rcSegId)) line.setStyle(newStyle);
    } catch (e) {
      toast('Failed to add service: ' + e.message, 'danger');
    }
  }

  async function deleteRoadService(line, svcId) {
    try {
      const resp = await fetch('/api/road_coverage.php?id=' + svcId, {
        method: 'DELETE',
        headers: { 'X-CSRF-Token': cfg.csrf || '' },
      });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      line._rcProps.services = (line._rcProps.services || []).filter(function (s) { return s.id !== svcId; });
      const newStyle = roadCovStyleFromServices(line._rcProps.services);
      line._rcBaseStyle = newStyle;
      if (!roadCovSelection.has(line._rcSegId)) line.setStyle(newStyle);
    } catch (e) {
      toast('Failed to remove service: ' + e.message, 'danger');
    }
  }

  function toggleRoadCovSelect(line) {
    const id = line._rcSegId;
    if (roadCovSelection.has(id)) {
      roadCovSelection.delete(id);
      line.setStyle(line._rcBaseStyle);
    } else {
      roadCovSelection.add(id);
      line.setStyle({ color: '#fbbf24', weight: 7, opacity: 0.6, dashArray: null });
    }
    updateRoadCovEditBar();
  }

  function eachRoadLayer(fn) {
    roadCovGroup.eachLayer(fn);
    roadCovOutsideGroup.eachLayer(fn);
  }

  function clearRoadCovSelection() {
    eachRoadLayer(function (l) {
      if (l._rcSegId && roadCovSelection.has(l._rcSegId)) l.setStyle(l._rcBaseStyle);
    });
    roadCovSelection.clear();
    updateRoadCovEditBar();
  }

  // Selection bar for road coverage. An Add / Remove switch decides what the
  // service buttons do: it used to be add-only, with removal tucked in a
  // dropdown at the end, so clicking "Expansion Target" to get rid of it added
  // it instead. Each button shows how many selected segments it would change.
  let rcBarMode = 'add';

  function updateRoadCovEditBar() {
    const n = roadCovSelection.size;
    if (n === 0) { hideEditBar(); return; }

    const remove = rcBarMode === 'remove';
    showEditBar('<b>' + n + ' road segment' + (n !== 1 ? 's' : '') + ' selected</b>' +
      ' <small class="text-muted">(Shift+click to add/remove segments)</small>', null, clearRoadCovSelection, null);
    const actEl = document.getElementById('mapEditActions');
    if (!actEl) return;

    // How many selected segments already have each service.
    const have = {};
    eachRoadLayer(function (l) {
      if (!l._rcSegId || !roadCovSelection.has(l._rcSegId)) return;
      (l._rcProps.services || []).forEach(function (sv) {
        const k = sv.provider + '|' + sv.technology;
        have[k] = (have[k] || 0) + 1;
      });
    });

    // Add / Remove switch
    const sw = document.createElement('div');
    sw.className = 'btn-group btn-group-sm ms-2';
    sw.innerHTML =
      '<button type="button" class="btn ' + (remove ? 'btn-outline-success' : 'btn-success') + '" data-mode="add">＋ Add</button>' +
      '<button type="button" class="btn ' + (remove ? 'btn-danger' : 'btn-outline-danger') + '" data-mode="remove">− Remove</button>';
    sw.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () { rcBarMode = b.dataset.mode; updateRoadCovEditBar(); });
    });
    actEl.appendChild(sw);

    RC_COMBOS.forEach(function (combo) {
      const prov = combo[0], tech = combo[1], col = combo[4] || '#64748b';
      const name = combo[2] + (combo[3] ? ' — ' + combo[3] : '');
      const k = prov + '|' + tech;
      // Add count: segments that would actually change (not already having it,
      // and not refused, e.g. Expansion Target on a Tinuum FTTH segment).
      let addable = 0;
      if (!remove) eachRoadLayer(function (l) {
        if (!l._rcSegId || !roadCovSelection.has(l._rcSegId)) return;
        const sv = l._rcProps.services || [];
        if (!sv.some(function (x) { return x.provider === prov && x.technology === tech; })
            && rcServicesAfterAdd(sv, prov, tech)) addable++;
      });
      const count = remove ? (have[k] || 0) : addable;
      const btn = document.createElement('button');
      btn.className = 'btn btn-sm ms-1';
      btn.style.cssText = remove
        ? 'background:#fff;color:' + col + ';border:1.5px solid ' + col + ';font-size:.75rem'
        : 'background:' + col + ';color:#fff;border:none;font-size:.75rem';
      btn.textContent = (remove ? '− ' : '＋ ') + name + ' (' + count + ')';
      btn.title = remove
        ? (count ? 'Remove ' + name + ' from the ' + count + ' selected segment(s) that have it' : 'None of the selected segments have ' + name)
        : (count ? 'Add ' + name + ' to the ' + count + ' selected segment(s) that don\'t have it' : 'All selected segments already have ' + name);
      if (!count) { btn.disabled = true; btn.style.opacity = '.35'; }
      btn.addEventListener('click', function () {
        if (remove) {
          if (!confirm('Remove "' + name + '" from ' + count + ' segment(s)? This can\'t be undone.')) return;
          bulkRemoveRoadService([...roadCovSelection], prov, tech);
        } else {
          bulkAddRoadService([...roadCovSelection], prov, tech);
        }
      });
      actEl.appendChild(btn);
    });

    const clearBtn = document.createElement('button');
    clearBtn.className = 'btn btn-sm ms-2';
    clearBtn.style.cssText = 'background:#374151;color:#fff;border:none;font-size:.75rem';
    clearBtn.textContent = '✕ Clear all services';
    clearBtn.title = 'Remove every service from the selected segments';
    clearBtn.addEventListener('click', function () {
      if (!confirm('Remove ALL services from ' + n + ' selected segment(s)? This can\'t be undone.')) return;
      bulkClearRoadServices([...roadCovSelection]);
    });
    actEl.appendChild(clearBtn);
  }

  function startRoadCovBoxSelect() {
    if (rcBoxSelecting) { exitRoadCovBoxSelect(); return; }
    rcBoxSelecting = true;
    const btn = document.getElementById('btnRoadCovBoxSel');
    if (btn) btn.classList.add('active');
    map.dragging.disable();
    const container = map.getContainer();
    container.style.cursor = 'crosshair';

    function onNativeDown(ev) {
      if (!rcBoxSelecting) return;
      ev.preventDefault();
      container.removeEventListener('mousedown', onNativeDown);
      rcBoxStart = map.mouseEventToLatLng(ev);
      rcBoxRect  = L.rectangle([rcBoxStart, rcBoxStart], {
        color: '#fbbf24', weight: 1.5, opacity: 0.9,
        fillColor: '#fbbf24', fillOpacity: 0.08,
        interactive: false,
      }).addTo(map);

      function onNativeMove(mev) {
        if (rcBoxRect) rcBoxRect.setBounds(L.latLngBounds(rcBoxStart, map.mouseEventToLatLng(mev)));
      }
      function onNativeUp(uev) {
        container.removeEventListener('mousemove', onNativeMove);
        container.removeEventListener('mouseup', onNativeUp);
        if (rcBoxRect) {
          const bounds = rcBoxRect.getBounds();
          map.removeLayer(rcBoxRect);
          rcBoxRect = null;
          eachRoadLayer(function (l) {
            if (!l._rcSegId || !l._map) return;          // not drawn (e.g. unmarked outside road, toggle off)
            if (bounds.intersects(l.getBounds())) {
              if (!roadCovSelection.has(l._rcSegId)) {
                roadCovSelection.add(l._rcSegId);
                l.setStyle({ color: '#fbbf24', weight: 7, opacity: 0.6, dashArray: null });
              }
            }
          });
          updateRoadCovEditBar();
        }
        exitRoadCovBoxSelect();
      }
      container.addEventListener('mousemove', onNativeMove);
      container.addEventListener('mouseup', onNativeUp);
    }
    rcBoxDownHandler = onNativeDown;
    container.addEventListener('mousedown', onNativeDown);
  }

  function exitRoadCovBoxSelect() {
    rcBoxSelecting = false;
    rcBoxStart     = null;
    if (rcBoxRect) { map.removeLayer(rcBoxRect); rcBoxRect = null; }
    if (rcBoxDownHandler) {
      map.getContainer().removeEventListener('mousedown', rcBoxDownHandler);
      rcBoxDownHandler = null;
    }
    map.dragging.enable();
    map.getContainer().style.cursor = '';
    const btn = document.getElementById('btnRoadCovBoxSel');
    if (btn) btn.classList.remove('active');
  }

  async function bulkClearRoadServices(segIds) {
    try {
      // Collect all service IDs to delete across selected segments
      const deletes = [];
      eachRoadLayer(function (l) {
        if (!l._rcSegId || !segIds.includes(l._rcSegId)) return;
        (l._rcProps.services || []).forEach(function (s) {
          if (s.id) deletes.push({ line: l, svcId: s.id });
        });
      });
      await Promise.all(deletes.map(function (d) {
        return fetch('/api/road_coverage.php?id=' + d.svcId, {
          method: 'DELETE',
          headers: { 'X-CSRF-Token': cfg.csrf || '' },
        });
      }));
      // Reset all selected lines to default style
      eachRoadLayer(function (l) {
        if (!l._rcSegId || !segIds.includes(l._rcSegId)) return;
        l._rcProps.services = [];
        l._rcBaseStyle = roadCovStyleFromServices([]);
        l.setStyle(l._rcBaseStyle);          // also pieces cleared via "Whole road", which aren't selected
      });
      clearRoadCovSelection();
    } catch (e) {
      toast('Failed to clear services: ' + e.message, 'danger');
    }
  }

  async function bulkRemoveRoadService(segIds, provider, technology) {
    try {
      const resp = await fetch('/api/road_coverage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
        body: JSON.stringify({ action: 'remove', seg_ids: segIds, provider, technology }),
      });
      const data = await resp.json();
      if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
      eachRoadLayer(function (l) {
        if (!l._rcSegId || !segIds.includes(l._rcSegId)) return;
        l._rcProps.services = (l._rcProps.services || []).filter(function (s) {
          return !(s.provider === provider && s.technology === technology);
        });
        l._rcBaseStyle = roadCovStyleFromServices(l._rcProps.services);
      });
      clearRoadCovSelection();
      toast('Removed from ' + data.deleted + ' segment' + (data.deleted === 1 ? '' : 's'), 'success');
    } catch (e) {
      toast('Failed to remove: ' + e.message, 'danger');
    }
  }

  async function bulkAddRoadService(segIds, provider, technology) {
    try {
      const results = await Promise.all(segIds.map(async function (segId) {
        const resp = await fetch('/api/road_coverage.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
          body: JSON.stringify({ seg_id: segId, provider, technology }),
        });
        const data = await resp.json();
        return { segId, id: data.id || null };
      }));
      // Build a segId→id map from POST responses
      const idMap = {};
      results.forEach(function (r) { if (r.id) idMap[r.segId] = r.id; });
      // Update in-place for each selected line with the real service id
      eachRoadLayer(function (l) {
        if (!l._rcSegId || !segIds.includes(l._rcSegId)) return;
        const next = rcServicesAfterAdd(l._rcProps.services, provider, technology, idMap[l._rcSegId]);
        if (next) l._rcProps.services = next;
        const newStyle = roadCovStyleFromServices(l._rcProps.services);
        l._rcBaseStyle = newStyle;
      });
      clearRoadCovSelection();
    } catch (e) {
      toast('Failed to bulk-add services: ' + e.message, 'danger');
    }
  }

  function toggleOnus() {
    onuVisible = !onuVisible;
    const btn = document.getElementById('btnToggleOnus');
    if (btn) btn.classList.toggle('tgl-off', !onuVisible);
    if (onuVisible) {
      dropGroup.addTo(map);
      onuGroup.addTo(map);
      if (!onuLoaded) {
        loadOnuMarkers();
        loadDropSpans();
      }
    } else {
      map.removeLayer(dropGroup);
      map.removeLayer(onuGroup);
    }
    saveLayerState();
  }


  async function loadOnuMarkers() {
    try {
      const resp = await fetch(API.onos);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      if (geojson.error) {
        toast('UISP ONUs: ' + geojson.error, 'warning');
        return;
      }
      onuGroup.clearLayers();
      Object.keys(onuPropsById).forEach(k => delete onuPropsById[k]);
      Object.keys(svcPropsById).forEach(k => delete svcPropsById[k]);
      (geojson.features || []).forEach(f => renderOnu(f));
      onuLoaded = true;
    } catch (e) {
      toast('Failed to load ONUs: ' + e.message, 'warning');
    }
  }

  async function loadDropSpans() {
    try {
      const resp = await fetch(API.drops);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      dropGroup.clearLayers();
      Object.keys(dropPropsByDevId).forEach(k => delete dropPropsByDevId[k]);
      Object.keys(leadDropPropsByCrmId).forEach(k => delete leadDropPropsByCrmId[k]);
      Object.keys(svcDropPropsBySvcId).forEach(k => delete svcDropPropsBySvcId[k]);
      (geojson.features || []).forEach(f => renderDrop(f));
    } catch (e) {
      // Drop spans are informational — silent failure
    }
  }

  // ── Alden One staging poles (import candidates) ───────────────────────────────
  // These are Bell/NB Power poles scraped into app.alden_pole that are NOT yet in
  // our curated app.pole. The layer lets you pull them in one at a time or in bulk;
  // importing fills missing data and, when a pole matches one we already have,
  // never moves our (hand-corrected) geometry. See api/alden_import.php.

  const aldenMarkerById = {};        // pole_id → Leaflet marker
  let   aldenBulkControl = null;     // Leaflet control: "import all in view"

  function toggleAlden() {
    aldenVisible = !aldenVisible;
    document.getElementById('btnToggleAlden')?.classList.toggle('tgl-off', !aldenVisible);
    // Decide from the live zoom, not the debounced `inOverview` flag: that flag only
    // catches up ~350ms after a zoom, so a click right after zooming would otherwise
    // take the wrong branch and draw hexagons (or nothing) instead of poles.
    const overview = map.getZoom() < DENSITY_ZOOM;
    if (aldenVisible) {
      if (overview) {
        // Zoomed out: show the purple candidate-density hexagons, not markers —
        // unless the density layer itself is switched off.
        if (densityVisible) { aldenDensityGroup.addTo(map); loadAldenDensity(); }
      } else {
        aldenGroup.addTo(map);
        aldenVaultGroup.addTo(map);
        // Always refetch on an explicit switch-on, rather than only the first
        // time. Staging is reloaded out of band whenever a new Alden scrape is
        // imported, and with a first-time-only guard the map kept showing the
        // previous scrape until the page was reloaded — while toggling the layer
        // off and on, the obvious way to ask for a refresh, did nothing.
        if (map.getZoom() >= DETAIL_TEXT_ZOOM) { loadAldenPoles(); loadAldenVaults(); }
        ensureAldenBulkControl();
        updateAldenBulkControl();
      }
    } else {
      map.removeLayer(aldenGroup);
      map.removeLayer(aldenVaultGroup);
      aldenDensityGroup.clearLayers();
      map.removeLayer(aldenDensityGroup);
      if (aldenBulkControl) { map.removeControl(aldenBulkControl); aldenBulkControl = null; }
      // cancel any pending link if the layer is switched off mid-flow
      if (aldenLinkPoleId) { aldenLinkPoleId = null; map.getContainer().style.cursor = ''; }
    }
    updateAldenDensityLegend();
    saveLayerState();
  }

  // Alden poles in view only. Loading all of staging (10k+ after the Sept 2026
  // scrapes) as HTML markers made the map crawl; the overview regime shows the
  // density hexagons instead, and here we fetch the viewport plus a margin, and
  // reload after a pan/zoom (see the moveend hook).
  let aldenAbort = null, aldenMoveTimer = null;
  async function loadAldenPoles() {
    if (aldenAbort) aldenAbort.abort();
    aldenAbort = new AbortController();
    try {
      const b = map.getBounds().pad(0.3);
      const qs = new URLSearchParams({ bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',') });
      if (aldenLinkedVisible) qs.set('include_linked', '1');
      const url = API.aldenPoles + '?' + qs;
      const resp = await fetch(url, { credentials: 'same-origin', signal: aldenAbort.signal });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const geojson = await resp.json();
      if (geojson.error) { toast('Alden poles: ' + geojson.error, 'warning'); return; }
      aldenGroup.clearLayers();
      Object.keys(aldenMarkerById).forEach(k => delete aldenMarkerById[k]);
      (geojson.features || []).forEach(f => renderAldenPole(f));
      aldenLoaded = true;
      updateAldenBulkControl();
    } catch (e) {
      if (e.name !== 'AbortError') toast('Failed to load Alden poles: ' + e.message, 'warning');
    }
  }

  async function loadAldenVaults() {
    try {
      const url = API.aldenVaults + (aldenLinkedVisible ? '?include_linked=1' : '');
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) return;
      const geojson = await resp.json();
      aldenVaultGroup.clearLayers();
      (geojson.features || []).forEach(f => renderAldenVault(f));
    } catch (e) { /* no vault extract yet — silent */ }
  }

  function renderAldenPole(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;
    const linked = p.linked_fid !== null && p.linked_fid !== undefined;

    const marker = L.marker([lat, lon], {
      icon: aldenPoleIcon(p.owner_name, poleScale(), linked),
      draggable: false,
    });
    marker._aldenPoleId = p.pole_id;
    marker._aldenProps  = { ...p, lat, lon };
    marker.bindPopup(() => buildAldenPolePopup(marker._aldenProps), { minWidth: 200 });
    aldenGroup.addLayer(marker);
    aldenMarkerById[p.pole_id] = marker;
  }

  function renderAldenVault(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;
    const linked = p.linked_vault_id != null;
    const marker = L.circleMarker([lat, lon], {
      radius: 6, color: '#9333ea', weight: 2, fillColor: '#a855f7',   // purple — Alden One identity
      fillOpacity: linked ? 0.25 : 0.7, dashArray: '3 2',
    });
    marker._aldenVaultId = p.vault_id_alden;
    marker._aldenVaultProps = { ...p, lat, lon };
    marker.bindPopup(() => {
      const label = p.vault_ref || p.name || p.vault_id_alden;
      let html =
        `<div style="min-width:190px">` +
        `<div class="fw-semibold mb-1" style="color:#7e22ce">Alden One vault</div>` +
        `<table class="small mb-2" style="border-collapse:collapse">` +
          rowKV('Ref', p.vault_ref) +
          rowKV('Type', p.vault_type) +
          rowKV('Owner', p.owner_name) +
          rowKV('Address', p.address) +
          rowKV('Alden id', p.vault_id_alden) +
        `</table>`;
      if (linked) {
        html += `<div class="small text-success">✓ Already in your vaults (id ${p.linked_vault_id})</div>`;
      } else {
        html += `<button class="btn btn-sm btn-primary w-100" onclick="window._aldenImportVault('${esc(p.vault_id_alden)}')">Import to my vaults</button>`;
      }
      return html + `</div>`;
    });
    aldenVaultGroup.addLayer(marker);
  }

  window._aldenImportVault = async function (aid) {
    try {
      const j = await aldenPost({ action: 'import_vault', vault_ids: [aid] });
      const r = (j.results || [])[0] || {};
      const label = { inserted: 'added new', adopted_spatial: 'linked by location', skipped_linked: 'already linked', error: 'error' };
      toast('Vault ' + aid + ': ' + (label[r.action] || r.action) + (r.vault_id ? ' (id ' + r.vault_id + ')' : ''),
            r.action === 'error' ? 'danger' : 'success');
      await afterAldenImport();
    } catch (e) { toast('Vault import failed: ' + e.message, 'danger'); }
  };

  function buildAldenPolePopup(p) {
    const linked = p.linked_fid !== null && p.linked_fid !== undefined;
    const tag = p.power_number || p.telco_number || p.pole_id;
    let html =
      `<div style="min-width:200px">` +
      `<div class="fw-semibold mb-1" style="color:#7e22ce">Alden One pole</div>` +
      `<table class="small mb-2" style="border-collapse:collapse">` +
        rowKV('Owner', p.owner_name) +
        rowKV('Power #', p.power_number) +
        rowKV('Telco #', p.telco_number) +
        rowKV('Tag', p.pole_tag) +
        rowKV('Address', p.address) +
        rowKV('Alden id', p.pole_id) +
      `</table>`;
    if (linked) {
      html += `<div class="small text-success">✓ Already in your poles (fid ${p.linked_fid})</div>` +
              `<button class="btn btn-sm btn-outline-secondary w-100 mt-1" onclick="window._aldenGoto(${p.linked_fid})">Show my pole</button>`;
    } else {
      html += `<button class="btn btn-sm btn-primary w-100" onclick="window._aldenImport('${esc(p.pole_id)}')">Import to my poles</button>` +
              `<button class="btn btn-sm btn-outline-secondary w-100 mt-1" onclick="window._aldenLink('${esc(p.pole_id)}')">Link to existing pole…</button>`;
    }
    html += `</div>`;
    return html;
  }

  function rowKV(k, v) {
    if (v === null || v === undefined || v === '') return '';
    return `<tr><td class="text-muted pe-2" style="vertical-align:top">${esc(k)}</td><td>${esc(v)}</td></tr>`;
  }

  // Single-pole import (runs the server-side match cascade).
  window._aldenImport = async function (poleId) {
    try {
      const j = await aldenPost({ action: 'import', pole_ids: [poleId] });
      const r = (j.results || [])[0] || {};
      reportAldenOutcome([r]);
      await afterAldenImport();
    } catch (e) { toast('Import failed: ' + e.message, 'danger'); }
  };

  // Enter link mode: the next click on one of your existing poles links to it.
  window._aldenLink = function (poleId) {
    aldenLinkPoleId = poleId;
    map.closePopup();
    map.getContainer().style.cursor = 'crosshair';
    toast('Click one of your poles to link Alden pole ' + poleId + ' to it (Esc to cancel)', 'info');
  };

  window._aldenGoto = function (fid) {
    const m = poleMarkerByFid[fid];
    if (m) { map.setView(m.getLatLng(), Math.max(map.getZoom(), 18)); m.openPopup(); }
  };

  async function aldenLinkTo(poleId, fid) {
    try {
      await aldenPost({ action: 'link', pole_id: poleId, fid: fid });
      toast('Linked Alden pole ' + poleId + ' to pole fid ' + fid, 'success');
      await afterAldenImport();
    } catch (e) { toast('Link failed: ' + e.message, 'danger'); }
  }

  // Count importable Alden poles + vaults currently in the viewport.
  function aldenInView() {
    const b = map.getBounds();
    const poleIds = [], vaultIds = [];
    Object.values(aldenMarkerById).forEach(m => {
      const p = m._aldenProps;
      const linked = p.linked_fid !== null && p.linked_fid !== undefined;
      if (!linked && b.contains(m.getLatLng())) poleIds.push(p.pole_id);
    });
    aldenVaultGroup.eachLayer(m => {
      const p = m._aldenVaultProps;
      if (p && p.linked_vault_id == null && b.contains(m.getLatLng())) vaultIds.push(p.vault_id_alden);
    });
    return { poleIds, vaultIds };
  }

  // Bulk import every importable Alden pole AND vault currently in the viewport.
  window._aldenBulkImport = async function () {
    const { poleIds, vaultIds } = aldenInView();
    const total = poleIds.length + vaultIds.length;
    if (!total) { toast('No importable Alden assets in view', 'info'); return; }
    if (!confirm('Import ' + poleIds.length + ' pole(s) and ' + vaultIds.length + ' vault(s) in the current view?')) return;
    try {
      if (poleIds.length) {
        const j = await aldenPost({ action: 'import', pole_ids: poleIds });
        reportAldenOutcome(j.results || [], j.summary || {});
      }
      if (vaultIds.length) {
        const j = await aldenPost({ action: 'import_vault', vault_ids: vaultIds });
        const n = (j.results || []).length;
        toast('Imported ' + n + ' vault(s)', 'success');
      }
      await afterAldenImport();
    } catch (e) { toast('Bulk import failed: ' + e.message, 'danger'); }
  };

  async function aldenPost(body) {
    const resp = await fetch(API.aldenImport, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body),
    });
    const j = await resp.json();
    if (!resp.ok || !j.ok) throw new Error(j.error || ('HTTP ' + resp.status));
    return j;
  }

  // After any import/link: reload the Alden layer (imported poles flip to muted or
  // vanish) and the normal features so the new app.pole rows appear.
  async function afterAldenImport() {
    await loadAldenPoles();
    await loadAldenVaults();
    await loadFeatures(true);
    updateAldenBulkControl();
  }

  function reportAldenOutcome(results, summary) {
    const label = {
      inserted: 'added new', adopted_nbp: 'linked by #', adopted_spatial: 'linked by location',
      skipped_linked: 'already linked', linked: 'linked', error: 'error',
    };
    if (results.length === 1) {
      const r = results[0];
      const kind = r.action === 'inserted' ? 'success'
                 : r.action === 'error'    ? 'danger' : 'success';
      let msg = 'Pole ' + r.pole_id + ': ' + (label[r.action] || r.action) +
                (r.fid ? ' (fid ' + r.fid + ')' : '') + (r.error ? ' — ' + r.error : '');
      // If a net-new insert had nearby candidates, hint that a link may be better.
      if (r.action === 'inserted' && (r.candidates || []).length) {
        const c = r.candidates[0];
        msg += ` · note: an existing pole is ${c.dist_m}m away` + (c.nbp ? ` (${c.nbp})` : '');
      }
      toast(msg, kind);
      return;
    }
    const parts = Object.entries(summary).map(([k, n]) => n + ' ' + (label[k] || k));
    toast('Imported ' + results.length + ' Alden pole(s): ' + parts.join(', '), 'success');
  }

  // Bottom-right control offering the in-view bulk import; label tracks the count.
  function ensureAldenBulkControl() {
    if (aldenBulkControl) return;
    const Ctl = L.Control.extend({
      options: { position: 'bottomright' },
      onAdd: function () {
        const div = L.DomUtil.create('div', 'leaflet-bar alden-bulk-ctl');
        div.innerHTML = `<a href="#" id="aldenBulkBtn" title="Import all importable Alden poles in the current view"
                            style="padding:4px 8px;white-space:nowrap;font-weight:600;color:#7e22ce">⬇ Import Alden in view</a>`;
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.on(div.querySelector('#aldenBulkBtn'), 'click', function (e) {
          L.DomEvent.preventDefault(e);
          window._aldenBulkImport();
        });
        return div;
      },
    });
    aldenBulkControl = new Ctl();
    aldenBulkControl.addTo(map);
  }

  function updateAldenBulkControl() {
    const el = document.getElementById('aldenBulkBtn');
    if (!el) return;
    const { poleIds, vaultIds } = aldenInView();
    const n = poleIds.length + vaultIds.length;
    el.textContent = '⬇ Import Alden in view (' + n + ')';
    el.style.opacity = n ? '1' : '0.5';
  }

  function toggleCivics() {
    civicVisible = !civicVisible;
    document.getElementById('btnToggleCivics')?.classList.toggle('tgl-off', !civicVisible);
    if (civicVisible) {
      civicGroup.addTo(map);
      loadCivicNumbers();
    } else {
      map.removeLayer(civicGroup);
    }
    saveLayerState();
  }

  // GeoNB civic addresses (app.nb_civic_address, synced nightly by
  // tools/sync_geonb.php). Dots from zoom 14, clickable for the full address and
  // PID; the numbers themselves from zoom 16, where they stop overprinting, and
  // they follow the label modes like every other label.
  const CIVIC_MIN_ZOOM = 14, CIVIC_NUM_ZOOM = 16;
  const civicRenderer = L.canvas({ padding: 0.3, tolerance: 6 });   // dots are tiny; make them easy to click
  let civicAbort = null;

  // Re-render an open address popup from the dot's current state, and keep it a
  // function so every later opening is rendered fresh too.
  function civicRerender(dot, label, addr, pid, struct, civId) {
    const pop = dot.getPopup();
    if (!pop) return;
    pop.setContent(() => civicPopup(dot, label, addr, pid, struct, civId));
    pop.update();
  }

  // Address popup with Ignore: hides the address for good (app.nb_civic_address_ignore,
  // keyed by GeoNB's civ_id so the nightly GeoNB reload doesn't bring it back).
  function civicPopup(dot, label, addr, pid, struct, civId) {
    const el = document.createElement('div');
    if (dot._civAdded) {
      el.innerHTML =
        `<b>${esc(addr)}</b>` + (struct ? `<br>${esc(struct)}` : '') + (pid ? `<br>PID ${esc(pid)}` : '') +
        '<br><small class="text-muted">Added by us (not in GeoNB)</small>' +
        '<div class="mt-1 d-flex gap-1">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary py-0 civic-move">Move</button>' +
        '<button type="button" class="btn btn-sm btn-outline-danger py-0 civic-del">Delete</button></div>';
      el.querySelector('.civic-move').addEventListener('click', () => startCivicMove(dot, addr, { add_id: civId }));
      el.querySelector('.civic-del').addEventListener('click', async () => {
        if (!confirm('Delete ' + addr + '? (Only our added address; GeoNB is not affected.)')) return;
        try {
          await postCivic({ add_id: civId, delete: true });
          dot.closePopup();
          civicGroup.removeLayer(dot);
          if (label) civicGroup.removeLayer(label);
        } catch (e) { toast('Delete failed: ' + e.message, 'danger'); }
      });
      return el;
    }
    // Just ignored: offer Undo, which brings back the normal popup (Move etc.).
    // Rendered from state each time, so a reopened popup is never stale.
    if (dot._civIgnored) {
      el.innerHTML = `<b>${esc(addr)}</b><br><small class="text-muted">Ignored — won't show again.</small> ` +
                     '<a href="#" class="civic-undo">Undo</a>';
      el.querySelector('.civic-undo').addEventListener('click', async (ev) => {
        ev.preventDefault();
        try {
          await setCivicIgnored(civId, false);
          dot._civIgnored = false;
          dot.setStyle(dot._civMoved ? { opacity: 1, fillOpacity: 0.9, ...CIVIC_MOVED_STYLE } : { opacity: 1, fillOpacity: 0.9 });
          if (label) civicGroup.addLayer(label);
          civicRerender(dot, label, addr, pid, struct, civId);
        } catch (e) { toast('Undo failed: ' + e.message, 'danger'); }
      });
      return el;
    }
    el.innerHTML =
      `<b>${esc(addr)}</b>` +
      (struct ? `<br>${esc(struct)}` : '') +
      (pid ? `<br>PID ${esc(pid)}` : '') +
      '<br><small class="text-muted">GeoNB civic address' + (dot._civMoved ? ' · <b>position corrected by us</b>' : '') + '</small>' +
      (civId ? '<div class="mt-1 d-flex gap-1">' +
               '<button type="button" class="btn btn-sm btn-outline-secondary py-0 civic-move" ' +
               'title="Drag it to where it really is. Survives the nightly GeoNB update.">Move</button>' +
               '<button type="button" class="btn btn-sm btn-outline-secondary py-0 civic-ignore" ' +
               'title="Hide this address on the map. Not deleted; survives the nightly GeoNB update.">Ignore</button>' +
               (dot._civMoved ? '<button type="button" class="btn btn-sm btn-link py-0 civic-reset" title="Put it back where GeoNB has it">Reset</button>' : '') +
               '</div>' : '');
    el.querySelector('.civic-move')?.addEventListener('click', () => startCivicMove(dot, addr, { civ_id: civId }));
    el.querySelector('.civic-reset')?.addEventListener('click', async () => {
      try {
        await postCivic({ civ_id: civId, move: null });
        dot.closePopup();
        loadCivicNumbers();             // GeoNB's position comes back from the server
      } catch (e) { toast('Reset failed: ' + e.message, 'danger'); }
    });
    const btn = el.querySelector('.civic-ignore');
    if (btn) btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await setCivicIgnored(civId, true);
        dot._civIgnored = true;
        dot.setStyle({ opacity: 0.25, fillOpacity: 0.1 });
        if (label) civicGroup.removeLayer(label);
        civicRerender(dot, label, addr, pid, struct, civId);
        // Gone from the map once the popup closes, unless it was undone first.
        dot.once('popupclose', () => { if (dot._civIgnored) civicGroup.removeLayer(dot); });
      } catch (e) {
        btn.disabled = false;
        toast('Ignore failed: ' + e.message, 'danger');
      }
    });
    return el;
  }

  // Add an address GeoNB is missing (app.nb_civic_address_add). Street and
  // community are pre-filled from the nearest GeoNB address; the parcel's PID is
  // looked up by the server. The civic number is optional.
  async function openCivicAddForm(latlng) {
    const popup = L.popup({ minWidth: 250 }).setLatLng(latlng).setContent('Looking up the street…').openOn(map);
    let near = {};
    try {
      const r = await (await fetch(API.nbAddresses + '?near=' + latlng.lat + ',' + latlng.lng)).json();
      near = r.near || {};
    } catch (e) { /* the form still works, just not pre-filled */ }
    const street = near.street ? near.street.replace(/\w\S*/g, w => w[0] + w.slice(1).toLowerCase()) : '';
    const el = document.createElement('form');
    el.innerHTML =
      '<div class="fw-semibold mb-1">Add address</div>' +
      '<div class="d-flex gap-1 mb-1"><input name="num" class="form-control form-control-sm" style="width:70px" placeholder="#" inputmode="numeric">' +
      `<input name="street" class="form-control form-control-sm" placeholder="Street" value="${esc(street)}"></div>` +
      `<input name="community" class="form-control form-control-sm mb-1" placeholder="Community" value="${esc(near.community || '')}">` +
      '<select name="structure" class="form-select form-select-sm mb-1">' +
      ['Residence', 'Cottage/Camp', 'Commercial/Business', 'Non-Commercial'].map(o => `<option>${o}</option>`).join('') + '</select>' +
      '<input name="note" class="form-control form-control-sm mb-1" placeholder="Note (optional), e.g. why">' +
      '<div class="small text-muted mb-1">' + (near.pid ? 'Parcel PID ' + esc(near.pid) : 'No parcel found here') +
      (near.street ? ` · nearest GeoNB street ${esc(street)} (${near.dist_m} m)` : '') + '</div>' +
      '<button class="btn btn-sm btn-primary">Add</button>';
    el.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const f = new FormData(el);
      try {
        const r = await postCivic({ add: {
          lat: latlng.lat, lon: latlng.lng, civic_num: f.get('num').trim(),
          street: f.get('street').trim(), st_type: f.get('street').trim().toUpperCase() === (near.street || '') ? near.st_type : '',
          community: f.get('community').trim(), structure: f.get('structure'), note: f.get('note').trim(),
        } });
        map.closePopup();
        toast('Added ' + r.address, 'success');
        loadCivicNumbers();
      } catch (e) { toast('Add failed: ' + e.message, 'danger'); }
    });
    popup.setContent(el);
    el.querySelector('[name=num]').focus();
  }

  function setCivicIgnored(civId, ignore) {
    return postCivic({ civ_id: civId, ignore });
  }

  async function postCivic(body) {
    const resp = await fetch(API.nbAddresses, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '' },
      body: JSON.stringify(body),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
    return data;
  }

  // Moved (corrected) addresses: green, so a correction is visible at a glance.
  const CIVIC_MOVED_STYLE = { color: '#166534', fillColor: '#4ade80' };
  // Added by us (GeoNB is missing it): purple.
  const CIVIC_ADDED_STYLE = { color: '#6b21a8', fillColor: '#c084fc' };

  // Move: a draggable pin on the address; the edit bar saves or cancels.
  // Stored in app.nb_civic_address_move, keyed by GeoNB's civ_id.
  let civicMoving = null;
  function startCivicMove(dot, addr, ref) {
    if (civicMoving) civicMoving.cancel();
    dot.closePopup();
    const start = dot.getLatLng();
    const pin = L.marker(start, { draggable: true, zIndexOffset: 2000, autoPan: true }).addTo(map);
    pin.bindTooltip('Drag me', { permanent: true, direction: 'top', offset: [-15, -12] }).openTooltip();
    const finish = () => { map.removeLayer(pin); hideEditBar(); civicMoving = null; };
    civicMoving = { cancel: finish };
    showEditBar(`Moving <b>${esc(addr)}</b>: drag the pin to where it really is, then save.`, async () => {
      const ll = pin.getLatLng();
      try {
        await postCivic({ ...ref, move: [ll.lat, ll.lng] });
        dot.setLatLng(ll);
        if (!dot._civAdded) { dot._civMoved = true; dot.setStyle(CIVIC_MOVED_STYLE); }
        if (dot._civLabel) dot._civLabel.setLatLng(ll);
        finish();
        toast('Position saved', 'success');
      } catch (e) {
        toast('Move failed: ' + e.message, 'danger');
      }
    }, finish, 'Save position');
  }

  async function loadCivicNumbers() {
    if (civicAbort) civicAbort.abort();
    const z = map.getZoom();
    if (z < CIVIC_MIN_ZOOM) {
      civicGroup.clearLayers();
      return;
    }
    const b = map.getBounds().pad(0.1);
    const bbox = `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
    civicAbort = new AbortController();
    try {
      const resp = await fetch(API.nbAddresses + '?bbox=' + bbox, { signal: civicAbort.signal });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      civicGroup.clearLayers();
      const showNums = z >= CIVIC_NUM_ZOOM;
      (data.rows || []).forEach(([lat, lon, num, addr, pid, struct, civId, moved, added]) => {
        const dot = L.circleMarker([lat, lon], {
          renderer: civicRenderer, radius: 2.5, weight: 1,
          color: '#1e3a8a', fillColor: '#93c5fd', fillOpacity: 0.9,
        });
        dot._civMoved = !!moved;
        dot._civAdded = !!added;
        if (moved) dot.setStyle(CIVIC_MOVED_STYLE);
        if (added) dot.setStyle(CIVIC_ADDED_STYLE);
        const label = showNums && num ? L.marker([lat, lon], {
          icon: L.divIcon({ className: 'civic-label', html: esc(num), iconSize: [0, 0], iconAnchor: [-4, 5] }),
          interactive: false,
        }) : null;
        dot.bindPopup(() => civicPopup(dot, label, addr, pid, struct, civId));
        dot._civLabel = label;
        civicGroup.addLayer(dot);
        if (label) civicGroup.addLayer(label);
      });
      if (data.truncated) toast('Civic addresses: too many in view, zoom in to see them all', 'warning');
    } catch (e) {
      if (e.name !== 'AbortError') toast('Civic addresses: ' + e.message, 'warning');
    }
  }

  // SNB property parcels (app.nb_parcel, synced from GeoNB by tools/sync_geonb.php).
  // Outlines only, from zoom 15, simplified server-side to the current zoom. The
  // outlines are not clickable (they sit under the other canvases, which would
  // swallow the click anyway): a click on bare map asks the server which parcel
  // is under that point, and shows its PID, area and civic addresses.
  const PARCEL_MIN_ZOOM = 15;
  const parcelRenderer = L.canvas({ padding: 0.3, pane: 'parcelPane' });
  let parcelAbort = null;

  function toggleParcels() {
    parcelVisible = !parcelVisible;
    document.getElementById('btnToggleParcels')?.classList.toggle('tgl-off', !parcelVisible);
    if (parcelVisible) {
      parcelGroup.addTo(map);
      if (map.getZoom() < PARCEL_MIN_ZOOM) toast('Parcels show from zoom ' + PARCEL_MIN_ZOOM + ' — zoom in', 'info');
      loadParcels();
    } else {
      map.removeLayer(parcelGroup);
    }
    saveLayerState();
  }

  async function loadParcels() {
    if (parcelAbort) parcelAbort.abort();
    const z = Math.floor(map.getZoom());
    if (z < PARCEL_MIN_ZOOM) {
      parcelGroup.clearLayers();
      return;
    }
    const b = map.getBounds().pad(0.1);
    const bbox = `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
    parcelAbort = new AbortController();
    try {
      const resp = await fetch(API.nbParcels + '?z=' + z + '&bbox=' + bbox, { signal: parcelAbort.signal });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      parcelGroup.clearLayers();
      L.geoJSON(data.features || [], {
        renderer: parcelRenderer, pane: 'parcelPane',
        interactive: false,
        // Railway land (carries NRWN track, or recorded owner is a railway): slate fill, heavier outline.
        style: f => f.properties.rail
          ? { color: '#1f2937', weight: 2, opacity: 0.9, fill: true, fillColor: '#475569', fillOpacity: 0.35 }
          : { color: '#b45309', weight: 1, opacity: 0.8, fill: false },
      }).addTo(parcelGroup);
      // Track-and-ties symbol along the railway parcels, black with a white outline (non-interactive, so clicks still reach the parcel lookup).
      const trackSvg = '<svg viewBox="0 0 64 36" width="44" height="25" xmlns="http://www.w3.org/2000/svg" fill="#000" stroke="#fff" stroke-width="1.6" stroke-linejoin="round" style="paint-order:stroke;display:block">' +
        '<rect x="3" y="12" width="58" height="2.6"/><rect x="3" y="23" width="58" height="2.6"/>' +
        [6, 15, 24, 33, 42, 51].map(x => `<rect x="${x}" y="9" width="3.4" height="20"/>`).join('') + '</svg>';
      const loco = L.divIcon({ className: '', iconSize: [44, 25], iconAnchor: [22, 12], html: trackSvg });
      let nLoco = 0;
      for (const f of data.features || []) {
        if (!f.properties.rail) continue;
        for (const c of f.properties.c || []) {
          if (nLoco++ >= 400) break;
          L.marker([c[1], c[0]], { interactive: false, keyboard: false, pane: 'parcelPane', icon: loco }).addTo(parcelGroup);
        }
      }
      if (data.truncated) toast('Parcels: too many in view, zoom in to see them all', 'warning');
    } catch (e) {
      if (e.name !== 'AbortError') toast('Parcels: ' + e.message, 'warning');
    }
  }

  // ── GeoNB wetlands ───────────────────────────────────────────────────────────
  // Polygons from zoom 13 (teal; purple outline = Provincially Significant), and
  // from zoom 15 the merged 30 m band (dashed) where work needs a WAWA permit.
  // Not clickable themselves: a map click asks the server what's under the point
  // (see showParcelPopup), same as parcels.
  const WETLAND_MIN_ZOOM = 13;
  const wetlandRenderer = L.canvas({ padding: 0.3, pane: 'wetlandPane' });
  let wetlandAbort = null;

  function toggleWetlands() {
    wetlandVisible = !wetlandVisible;
    document.getElementById('btnToggleWetlands')?.classList.toggle('tgl-off', !wetlandVisible);
    if (wetlandVisible) {
      wetlandGroup.addTo(map);
      if (map.getZoom() < WETLAND_MIN_ZOOM) toast('Wetlands show from zoom ' + WETLAND_MIN_ZOOM + ' — zoom in', 'info');
      loadWetlands();
    } else {
      map.removeLayer(wetlandGroup);
    }
    saveLayerState();
  }

  // ── Village of Salisbury zoning (app.salisbury_zoning, mirrored weekly) ──
  const ZONING_MIN_ZOOM = 12;
  const ZONING_COLOURS = { A: '#84cc16', CU: '#a855f7', DG: '#78716c', HC: '#f43f5e', I: '#64748b', ID: '#475569',
                           OSC: '#16a34a', RA: '#fbbf24', RD: '#fb923c', RNS: '#f97316', RR: '#facc15', RS: '#f59e0b', TC: '#ef4444' };
  const zoningRenderer = L.canvas({ padding: 0.3, pane: 'zoningPane' });
  let zoningAbort = null;

  function toggleZoning() {
    zoningVisible = !zoningVisible;
    document.getElementById('btnToggleZoning')?.classList.toggle('tgl-off', !zoningVisible);
    if (zoningVisible) {
      zoningGroup.addTo(map);
      if (map.getZoom() < ZONING_MIN_ZOOM) toast('Zoning shows from zoom ' + ZONING_MIN_ZOOM + ' — zoom in', 'info');
      loadZoning();
    } else {
      map.removeLayer(zoningGroup);
    }
    saveLayerState();
  }

  async function loadZoning() {
    if (zoningAbort) zoningAbort.abort();
    const z = Math.floor(map.getZoom());
    if (z < ZONING_MIN_ZOOM) { zoningGroup.clearLayers(); return; }
    const b = map.getBounds().pad(0.1);
    zoningAbort = new AbortController();
    try {
      const resp = await fetch('/api/salisbury_zoning.php?z=' + z + '&bbox=' + b.getWest() + ',' + b.getSouth() + ',' + b.getEast() + ',' + b.getNorth(),
                               { signal: zoningAbort.signal });
      const data = await resp.json();
      if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
      zoningGroup.clearLayers();
      const style = f => {
        const l = f.properties.layer;
        if (l === 'infill') return { color: '#0369a1', weight: 2, opacity: 0.8, dashArray: '8 6', fill: false };
        if (l === 'serviceable') return { color: '#0f766e', weight: 2, opacity: 0.7, dashArray: '2 6', fill: false };
        const c = ZONING_COLOURS[f.properties.code] || '#94a3b8';
        return { color: c, weight: 1, opacity: 0.9, fillColor: c, fillOpacity: 0.28 };
      };
      L.geoJSON(data.features || [], { renderer: zoningRenderer, pane: 'zoningPane', style,
        onEachFeature: (f, lyr) => {
          const p = f.properties;
          if (p.layer === 'infill' || p.layer === 'serviceable') { lyr.options.interactive = false; return; }
          lyr.bindPopup('<b>' + esc(p.name || '') + '</b> (' + esc(p.code || '') + ')<br><small class="text-muted">Village of Salisbury zoning</small>' +
            (p.link ? '<br><a href="' + esc(p.link) + '" target="_blank" rel="noopener">By-law page</a>' : ''));
        } }).addTo(zoningGroup);
    } catch (e) {
      if (e.name !== 'AbortError') toast('Zoning: ' + e.message, 'warning');
    }
  }

  // ── GeoNB communities: the NB 9-1-1 community names used in civic and mailing addresses ──
  // Dashed outlines and a name at the middle of each community wide enough to carry one. Neither is
  // clickable, so a click still asks the server about the parcel under the point.
  const COMMUNITY_MIN_ZOOM = 8;
  const communityRenderer = L.canvas({ padding: 0.3, pane: 'communityPane' });
  let communityAbort = null;

  function toggleCommunities() {
    communityVisible = !communityVisible;
    document.getElementById('btnToggleCommunities')?.classList.toggle('tgl-off', !communityVisible);
    if (communityVisible) {
      communityGroup.addTo(map);
      if (map.getZoom() < COMMUNITY_MIN_ZOOM) toast('Communities show from zoom ' + COMMUNITY_MIN_ZOOM + ' — zoom in', 'info');
      loadCommunities();
    } else {
      map.removeLayer(communityGroup);
    }
    saveLayerState();
  }

  async function loadCommunities() {
    if (communityAbort) communityAbort.abort();
    const z = Math.floor(map.getZoom());
    if (z < COMMUNITY_MIN_ZOOM) { communityGroup.clearLayers(); return; }
    const b = map.getBounds().pad(0.1);
    communityAbort = new AbortController();
    try {
      const resp = await fetch('/api/nb_communities.php?z=' + z + '&bbox=' + b.getWest() + ',' + b.getSouth() + ',' + b.getEast() + ',' + b.getNorth(),
                               { signal: communityAbort.signal });
      const data = await resp.json();
      if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
      communityGroup.clearLayers();
      const feats = data.features || [];
      L.geoJSON(feats, { renderer: communityRenderer, pane: 'communityPane', interactive: false,
        style: { color: '#7c3aed', weight: 1.2, opacity: 0.55, dashArray: '6 5', fill: false } }).addTo(communityGroup);
      feats.filter(f => f.properties.label).forEach(f => {
        const p = f.properties;
        L.marker([p.lat, p.lon], { interactive: false, keyboard: false, pane: 'communityLabelPane',
          icon: L.divIcon({ className: 'community-label', iconSize: [0, 0], html: '<span>' + esc(p.name) + '</span>' }) }).addTo(communityGroup);
      });
    } catch (e) {
      if (e.name !== 'AbortError') toast('Communities: ' + e.message, 'warning');
    }
  }

  async function loadWetlands() {
    if (wetlandAbort) wetlandAbort.abort();
    const z = Math.floor(map.getZoom());
    if (z < WETLAND_MIN_ZOOM) { wetlandGroup.clearLayers(); return; }
    const b = map.getBounds().pad(0.1);
    wetlandAbort = new AbortController();
    try {
      const resp = await fetch('/api/nb_wetlands.php?z=' + z + '&bbox=' + b.getWest() + ',' + b.getSouth() + ',' + b.getEast() + ',' + b.getNorth(),
                               { signal: wetlandAbort.signal });
      const data = await resp.json();
      if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
      wetlandGroup.clearLayers();
      if (data.band) {
        L.geoJSON(data.band, { renderer: wetlandRenderer, pane: 'wetlandPane', interactive: false,
          style: { color: '#0e7490', weight: 1.2, opacity: 0.8, dashArray: '4 4', fill: false } }).addTo(wetlandGroup);
      }
      L.geoJSON(data.features || [], { renderer: wetlandRenderer, pane: 'wetlandPane', interactive: false,
        style: f => f.properties.psw
          ? { color: '#7e22ce', weight: 2, opacity: 0.9, fillColor: '#14b8a6', fillOpacity: 0.3 }
          : { color: '#0f766e', weight: 1, opacity: 0.8, fillColor: '#2dd4bf', fillOpacity: 0.25 },
      }).addTo(wetlandGroup);
    } catch (e) {
      if (e.name !== 'AbortError') toast('Wetlands: ' + e.message, 'warning');
    }
  }

  // Clicks on a span line bubble up to the map too; if that click opened the
  // span's own popup, it was a question about the span, not the ground under it.
  let lastPopupOpenAt = 0;
  let lastClickStartAt = 0;
  // lastClickStartAt is stamped by a 'preclick' handler (registered with the other parcel hooks):
  // that fires before any layer handler, so a popup opened by an asset under the pointer is
  // stamped after it and the parcel answer is skipped. The layer's own handler runs before the
  // map's 'click', so stamping the time inside the click handler was too late.
  function onMapClickForParcel(ev) {
    const z = map.getZoom();
    if (!(parcelVisible && z >= PARCEL_MIN_ZOOM) && !(wetlandVisible && z >= WETLAND_MIN_ZOOM)) return;
    if (map.pm?.globalDrawModeEnabled?.() || map.pm?.globalEditModeEnabled?.()) return;
    const started = lastClickStartAt;
    setTimeout(() => {
      if (lastPopupOpenAt >= started) return;            // an asset already answered
      // A click a few pixels beside a conduit, strand or span is still a click on it: assets
      // matter more than the parcel under them, and a thin line is hard to hit.
      const near = nearestAssetLineAt(ev.containerPoint, 10);
      if (near) { near.openPopup(ev.latlng); return; }
      showParcelPopup(ev.latlng);
    }, 0);
  }

  // The popup-bearing line (conduit, strand, span) within `tol` px of a container point, or null.
  function nearestAssetLineAt(pt, tol) {
    let best = null, bestD = tol;
    const segDist = (p, a, b) => {
      const dx = b.x - a.x, dy = b.y - a.y, l2 = dx * dx + dy * dy;
      const t = l2 ? Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / l2)) : 0;
      return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
    };
    [conduitGroup, spanGroup, strandGroup].forEach(g => {
      if (!g || !map.hasLayer(g)) return;
      g.eachLayer(l => {
        if (!l.getLatLngs || !l.getPopup || !l.getPopup() || (l.options && l.options.interactive === false)) return;
        if (l._path && l._path.style && l._path.style.display === 'none') return;
        const rings = l.getLatLngs();
        const flat = Array.isArray(rings[0]) ? rings[0] : rings;
        let prev = null;
        for (const ll of flat) {
          const q = map.latLngToContainerPoint(ll);
          if (prev) { const d = segDist(pt, prev, q); if (d < bestD) { bestD = d; best = l; } }
          prev = q;
        }
      });
    });
    return best;
  }

  // Set or change a parcel's owner (✎ in the PID popup). Blank name removes it.
  const parcelOwnerByPid = {};
  window._parcelOwnerSet = async function (pid) {
    const cur = parcelOwnerByPid[pid] || null;
    const name = window.prompt('Owner of PID ' + pid + ' (blank removes it):', cur ? cur.owner : '');
    if (name === null) return;
    try {
      if (!name.trim()) { await apiDelete('/api/parcel_owner.php?pid=' + encodeURIComponent(pid)); }
      else {
        const d = window.prompt('Date this was read from the register (YYYY-MM-DD, blank if unknown):',
                                (cur && cur.as_of) || new Date().toISOString().slice(0, 10));
        if (d === null) return;
        const src = window.prompt('Source (e.g. SNB PLANET, deed, plan):', (cur && cur.source) || 'SNB PLANET');
        if (src === null) return;
        await apiPut('/api/parcel_owner.php', { pid, owner: name.trim(), as_of: d.trim(), source: src.trim() });
      }
      map.closePopup();
    } catch (e) { alert('Could not save the owner: ' + (e.message || e)); }
  };

  window._civicAddAt = function (lat, lng) {
    if (!civicVisible) toggleCivics();            // so the new dot is visible
    openCivicAddForm(L.latLng(lat, lng));
  };

  // What's under this point: wetland (if that layer is on) and parcel (if on).
  async function showParcelPopup(latlng) {
    const z = map.getZoom();
    const wantWet = wetlandVisible && z >= WETLAND_MIN_ZOOM;
    const wantParcel = parcelVisible && z >= PARCEL_MIN_ZOOM;
    const popup = L.popup().setLatLng(latlng).setContent('…').openOn(map);
    let wetHtml = '';
    if (wantWet) {
      try {
        const d = await (await fetch('/api/nb_wetlands.php?at=' + latlng.lng + ',' + latlng.lat)).json();
        const w = (d.wetlands || [])[0];
        const psw = (d.wetlands || []).some(x => x.psw && Number(x.dist_m) === 0);
        wetHtml = !w
          ? '<div><span style="color:#0f766e">≈</span> <small class="text-muted">Not in a wetland or its 30 m band</small></div>'
          : Number(w.dist_m) === 0
            ? `<div><b style="color:#0f766e">≈ In a wetland</b>: ${esc(w.wetland_class)}, ${esc(w.hectares)} ha` +
              (psw ? ' · <b style="color:#7e22ce">Provincially Significant</b>' : '') +
              '<br><small class="text-danger">Work here needs a WAWA permit</small></div>'
            : `<div><b style="color:#0e7490">≈ ${esc(w.dist_m)} m from a wetland</b> (${esc(w.wetland_class)})` +
              '<br><small class="text-danger">Inside the 30 m band: work needs a WAWA permit</small></div>';
      } catch (e) {
        wetHtml = '<div><small class="text-muted">Wetland check failed</small></div>';
      }
      if (!wantParcel) { popup.setContent(wetHtml + '<small class="text-muted">GeoNB wetlands</small>'); return; }
      wetHtml += '<hr class="my-1">';
    }
    try {
      const resp = await fetch(API.nbParcels + '?at=' + latlng.lng + ',' + latlng.lat);
      if (resp.status === 404) { popup.setContent(wetHtml + '<small class="text-muted">No SNB parcel here</small>'); return; }
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const { parcel: p, addresses, zoning = [], documents = [], owner = null, placeholder = false } = await resp.json();
      const ha = (p.area_m2 || 0) / 10000;
      parcelOwnerByPid[p.pid] = owner;
      popup.setContent(wetHtml +
        `<b>PID ${esc(p.pid)}</b>` + (placeholder ? ' <small class="text-muted">(placeholder PID: Crown land or road)</small>' : '') +
        (addresses.length ? '<br>' + addresses.map(a => esc(a.address) + (a.structure ? ` <small class="text-muted">(${esc(a.structure)})</small>` : '')).join('<br>')
                          : '<br><small class="text-muted">No civic address</small>') +
        (placeholder ? '' :
          '<br><b>Owner:</b> ' + (owner
            ? esc(owner.owner) + ` <small class="text-muted">(${owner.as_of ? 'as of ' + esc(owner.as_of) : 'date unknown'}${owner.source ? ', ' + esc(owner.source) : ''})</small>`
            : '<small class="text-muted">not recorded</small>') +
          ` <a href="#" style="text-decoration:none;font-size:12px" title="${owner ? 'Change' : 'Set'} the owner" onclick="window._parcelOwnerSet('${esc(p.pid)}');return false">✎</a>`) +
        (p.rail_owner ? `<br><b>🚆 Railway land</b> <small class="text-muted">(track: ${esc(p.rail_owner)}, GeoNB NRWN)</small>` : '') +
        `<br>${ha.toFixed(2)} ha · ${(ha * 2.47105).toFixed(2)} ac` +
        (p.county ? ` · ${esc(p.county)} County` : '') +
        zoning.map(z => `<br><b>Zoning:</b> ` + (z.link ? `<a href="${esc(z.link)}" target="_blank" rel="noopener">${esc(z.name)} (${esc(z.code)})</a>` : `${esc(z.name)} (${esc(z.code)})`) +
          (zoning.length > 1 ? ` <small class="text-muted">${Math.round(100 * z.area_m2 / Math.max(1, p.area_m2))}%</small>` : '')).join('') +
        (documents.length ? '<div class="mt-1"><b>Maps &amp; documents</b>' + documents.map(d => {
          const u = '/api/document_serve.php?id=' + encodeURIComponent(d.document_id);
          const view = /pdf|image/.test(d.mime_type || '') ? u + '&inline=1' : u;
          return `<br><a href="${view}" target="_blank" rel="noopener">${esc(d.title)}</a>` +
                 (d.category ? ` <small class="text-muted">${esc(d.category)}</small>` : '') +
                 ` <a href="${u}" title="Download" style="text-decoration:none">⬇</a>`;
        }).join('') + '</div>' : '') +
        (p.last_update ? `<br><small class="text-muted">SNB updated ${esc(p.last_update)}</small>` : '') +
        '<br><small class="text-muted">SNB property map</small>' +
        '<div class="mt-1"><button type="button" class="btn btn-sm btn-outline-secondary py-0" ' +
        'onclick="window._civicAddAt(' + latlng.lat + ',' + latlng.lng + ')" ' +
        'title="GeoNB has no address here? Add one (shows purple on the Civic #s layer)">＋ Add address here</button></div>');
    } catch (e) {
      popup.setContent(wetHtml + '<small class="text-muted">Parcel details unavailable: ' + esc(e.message) + '</small>');
    }
  }

  function renderDrop(feature) {
    if (!feature.geometry) return;
    const p = feature.properties;
    const isLead      = p.drop_type === 'lead';
    const isService   = p.drop_type === 'service';
    const isRouted    = p.routed === true;

    // Store drop props for routing buttons
    if (p.uisp_device_id) {
      dropPropsByDevId[p.uisp_device_id] = {
        fosc_lat: p.fosc_lat, fosc_lon: p.fosc_lon,
        ont_lat:  p.ont_lat,  ont_lon:  p.ont_lon,
        routed:   p.routed,
        lenM:     null,  // filled in below after latlngs computed
      };
    }
    if (isLead && p.crm_client_id) {
      leadDropPropsByCrmId[String(p.crm_client_id)] = {
        fosc_lat: p.fosc_lat, fosc_lon: p.fosc_lon,
        ont_lat:  p.ont_lat,  ont_lon:  p.ont_lon,
        routed:   p.routed,
        lenM:     null,  // filled in below after latlngs computed
      };
    }

    if (isService && p.crm_service_id) {
      svcDropPropsBySvcId[String(p.crm_service_id)] = {
        fosc_lat: p.fosc_lat, fosc_lon: p.fosc_lon,
        ont_lat:  p.ont_lat,  ont_lon:  p.ont_lon,
        routed:   p.routed,
        lenM:     null,  // filled in below after latlngs computed
      };
    }

    // Prepared services are drops that still have to be built, so they share the
    // "under construction" orange of their ONU marker rather than the lead yellow.
    const color  = isService ? '#f97316'
                 : isRouted  ? (isLead ? '#d97706' : '#f97316')
                 : isLead    ? '#E0A800' : '#555';
    const weight = isLead ? 1.5 : 2;
    const style  = { color, weight, dashArray: '6 4', opacity: isRouted ? 0.9 : 0.75 };

    const latlngs = feature.geometry.coordinates.map(c => [c[1], c[0]]);
    const line = L.polyline(latlngs, style);

    // Compute path length (works for straight 2-point lines and routed multi-segment paths)
    let dropLenM = 0;
    for (let i = 0; i < latlngs.length - 1; i++) {
      dropLenM += L.latLng(latlngs[i]).distanceTo(L.latLng(latlngs[i + 1]));
    }
    const dropLenStr = dropLenM >= 1000
      ? (dropLenM / 1000).toFixed(2) + ' km'
      : Math.round(dropLenM) + ' m';

    // Store computed length back into the drop props lookup for use in ONU/lead popups
    if (p.uisp_device_id && dropPropsByDevId[p.uisp_device_id])
      dropPropsByDevId[p.uisp_device_id].lenM = dropLenM;
    if (isLead && p.crm_client_id && leadDropPropsByCrmId[String(p.crm_client_id)])
      leadDropPropsByCrmId[String(p.crm_client_id)].lenM = dropLenM;
    if (isService && p.crm_service_id && svcDropPropsBySvcId[String(p.crm_service_id)])
      svcDropPropsBySvcId[String(p.crm_service_id)].lenM = dropLenM;

    const label = p.ont_name || p.uisp_device_id || '';
    const routeNote = isRouted ? ' (routed)' : '';
    line.bindTooltip(
      `${esc(label)} → ${esc(p.fosc_splice_num)}<br><span style="color:#aaa">${dropLenStr}${routeNote}</span>`,
      { sticky: true, className: 'fosc-label drop-hover-label' }
    );
    if (p.uisp_device_id)        line._uispDeviceId = p.uisp_device_id;
    // Lead drops only: a service drop also carries crm_client_id, and tagging it
    // here would make the route editor hide the wrong line for the same client.
    if (isLead && p.crm_client_id) line._crmClientId  = p.crm_client_id;
    if (isService && p.crm_service_id) line._crmServiceId = p.crm_service_id;
    dropGroup.addLayer(line);
  }

  // onuSignalColor / onuIcon / poleIcon / foscIcon / etc. → see /js/icons/map.icons.js

  function renderOnu(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;

    if (p.uisp_device_id)  onuPropsById[p.uisp_device_id]    = p;
    if (p.crm_client_id)   leadPropsById[String(p.crm_client_id)] = p;
    if (p.crm_service_id)  svcPropsById[String(p.crm_service_id)] = p;
    const onuColor = p.status === 'active'       ? STATUS_COLORS.active
                   : p.status === 'disconnected' ? '#6c757d'
                   : p.status === 'prepared'     ? STATUS_COLORS['under construction']
                   : p.status === 'client'       ? '#0d6efd'
                   : STATUS_COLORS.plan;
    const marker = L.marker([lat, lon], { icon: onuIcon(onuColor, iconScale()) });
    marker._tinIcon = { color: onuColor };
    marker._onuProps = p; // full props for popup actions
    const popup = L.popup({ minWidth: 210 }).setContent(() => buildOnuPopup(marker._onuProps));
    marker.bindPopup(popup);
    onuGroup.addLayer(marker);
  }

  function buildOnuPopup(p) {
    const statusBadge = p.status === 'active'   ? '<span class="badge bg-success">active</span>'
                      : p.status === 'lead'     ? '<span class="badge bg-warning text-dark">lead</span>'
                      : p.status === 'client'   ? '<span class="badge bg-primary">UISP client</span>'
                      : p.status === 'prepared' ? '<span class="badge" style="background:' + STATUS_COLORS['under construction'] + '">prepared</span>'
                      : `<span class="badge bg-secondary">${esc(p.status)}</span>`;

    const signalHtml = p.signal != null
      ? `<span style="color:${onuSignalColor(p.signal)};font-weight:600">${p.signal} dBm</span>`
      : '<span class="text-muted">—</span>';

    const speedHtml = (p.dl_speed || p.ul_speed)
      ? `${fmtSpeed(p.dl_speed)} / ${fmtSpeed(p.ul_speed)}`
      : '—';

    const dp = dropPropsByDevId[p.uisp_device_id] || null;
    const routeBtn = p.linked_fosc_splice_num && dp
      ? (dp.routed
          ? `<button class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:1px 6px"
               onclick="window._onuClearRoute('${p.uisp_device_id}')">Clear route</button>`
          : `<button class="btn btn-xs btn-outline-warning" style="font-size:11px;padding:1px 6px"
               onclick="window._onuStartRouteDrop('${p.uisp_device_id}')">Route via poles</button>`)
      : '';

    const linkHtml = p.linked_fosc_splice_num
      ? `<div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
           <span class="text-success" style="font-size:12px">&#x1F517; ${esc(p.linked_fosc_splice_num)}</span>
           <button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:1px 6px"
             onclick="window._onuUnlink('${p.uisp_device_id}')">Unlink</button>
           ${routeBtn}
         </div>`
      : `<div class="mt-2">
           <button class="btn btn-xs btn-outline-primary" style="font-size:11px;padding:1px 6px"
             onclick="event.stopPropagation(); window._onuStartLink('${p.uisp_device_id}')">&#x1F517; Link to closure&hellip;</button>
         </div>`;

    const ldp = leadDropPropsByCrmId[String(p.crm_client_id)] || null;
    const leadRouteBtn = p.linked_fosc_splice_num && ldp
      ? (ldp.routed
          ? `<button class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:1px 6px"
               onclick="window._leadClearRoute(${p.crm_client_id})">Clear route</button>`
          : `<button class="btn btn-xs btn-outline-warning" style="font-size:11px;padding:1px 6px"
               onclick="window._leadStartRouteDrop(${p.crm_client_id})">Route via poles</button>`)
      : '';

    const leadLinkHtml = p.linked_fosc_splice_num
      ? `<div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
           <span class="text-warning" style="font-size:12px">&#x1F517; ${esc(p.linked_fosc_splice_num)}</span>
           <button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:1px 6px"
             onclick="window._leadUnlink(${p.crm_client_id})">Unlink</button>
           ${leadRouteBtn}
         </div>`
      : `<div class="mt-2">
           <button class="btn btn-xs btn-outline-warning" style="font-size:11px;padding:1px 6px"
             onclick="event.stopPropagation(); window._leadStartLink(${p.crm_client_id})">&#x1F517; Link to closure&hellip;</button>
         </div>`;

    // Prepared CRM service: keyed on the service, not the client, so a customer
    // with several pending installs can feed each one from a different closure.
    const sdp = svcDropPropsBySvcId[String(p.crm_service_id)] || null;
    const svcRouteBtn = p.linked_fosc_splice_num && sdp
      ? (sdp.routed
          ? `<button class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:1px 6px"
               onclick="window._svcClearRoute(${p.crm_service_id})">Clear route</button>`
          : `<button class="btn btn-xs btn-outline-warning" style="font-size:11px;padding:1px 6px"
               onclick="window._svcStartRouteDrop(${p.crm_service_id})">Route via poles</button>`)
      : '';

    const svcLinkHtml = p.linked_fosc_splice_num
      ? `<div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
           <span style="color:${STATUS_COLORS['under construction']};font-size:12px">&#x1F517; ${esc(p.linked_fosc_splice_num)}</span>
           <button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:1px 6px"
             onclick="window._svcUnlink(${p.crm_service_id})">Unlink</button>
           ${svcRouteBtn}
         </div>`
      : `<div class="mt-2">
           <button class="btn btn-xs btn-outline-warning" style="font-size:11px;padding:1px 6px"
             onclick="event.stopPropagation(); window._svcStartLink(${p.crm_service_id})">&#x1F517; Link to closure&hellip;</button>
         </div>`;

    // Drop length — from drop props (routed path length) if available
    const dropLen = dp?.lenM ?? ldp?.lenM ?? sdp?.lenM ?? null;
    const dropLenHtml = dropLen != null
      ? `<small class="text-muted">Drop: ${dropLen >= 1000 ? (dropLen / 1000).toFixed(2) + ' km' : Math.round(dropLen) + ' m'}${(dp?.routed || ldp?.routed || sdp?.routed) ? ' (routed)' : ''}</small><br>`
      : '';

    return `<div style="font-size:13px">
      <strong>${esc(p.site_name)}</strong> ${statusBadge}<br>
      ${p.model ? `<small class="text-muted">${esc(p.model)}</small><br>` : ''}
      ${p.mac   ? `<small>MAC: <code>${esc(p.mac)}</code></small><br>` : ''}
      ${p.ip    ? `<small>IP: <code>${esc(p.ip)}</code></small><br>` : ''}
      ${p.uisp_device_id ? `<small>Signal: ${signalHtml}</small><br>` : ''}
      ${dropLenHtml}
      ${p.service_name ? `<small>Plan: ${esc(p.service_name)}${(p.dl_speed || p.ul_speed) ? ` (${speedHtml})` : ''}</small><br>` : ''}
      ${p.status === 'prepared' ? '<small class="text-muted">No ONU in UISP yet &mdash; not installed</small><br>' : ''}
      ${p.address ? `<small class="text-muted">${esc(p.address)}</small>` : ''}
      ${p.uisp_device_id ? linkHtml : p.crm_service_id ? svcLinkHtml : p.status === 'lead' && p.crm_client_id ? leadLinkHtml : ''}
      ${p.crm_client_id && !p.uisp_device_id && !p.crm_service_id ? `<div class="mt-2">
        <button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:1px 6px"
          title="Changes this client's location in UISP"
          onclick="event.stopPropagation(); window._uispClientMove(${p.crm_client_id})">&#x1F4CD; Move in UISP&hellip;</button></div>` : ''}
    </div>`;
  }

  // Move a UISP client's location. This writes to UISP CRM (live billing data), so the
  // pin, the edit bar and a confirmation all say so.
  let uispMoving = null;
  window._uispClientMove = function (crmClientId) {
    const p = leadPropsById[String(crmClientId)];
    if (!p) return;
    let marker = null;
    onuGroup.eachLayer(m => { if (m._onuProps && m._onuProps.crm_client_id === p.crm_client_id && !m._onuProps.uisp_device_id && !m._onuProps.crm_service_id) marker = m; });
    if (!marker) return;
    if (uispMoving) uispMoving.cancel();
    map.closePopup();
    const start = marker.getLatLng();
    const pin = L.marker(start, {
      draggable: true, zIndexOffset: 3000, autoPan: true,
      icon: L.divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 30],
        html: '<div style="font-size:30px;line-height:30px;filter:drop-shadow(0 0 3px #dc3545)">&#x1F4CD;</div>' }),
    }).addTo(map);
    pin.bindTooltip('UISP location: drag me', { permanent: true, direction: 'top', offset: [0, -28] }).openTooltip();
    const ghost = L.circleMarker(start, { radius: 6, color: '#dc3545', weight: 2, dashArray: '3 3', fill: false, interactive: false }).addTo(map);
    const finish = () => { map.removeLayer(pin); map.removeLayer(ghost); hideEditBar(); uispMoving = null; };
    uispMoving = { cancel: finish };
    showEditBar(`<span class="badge bg-danger me-1">UISP DATA</span> Moving <b>${esc(p.site_name)}</b>${p.address ? ' (' + esc(p.address) + ')' : ''}. ` +
      `Saving <b>changes this client's location in UISP</b>, not just on this map. Drag the pin, then save.`, async () => {
      const ll = pin.getLatLng();
      const metres = Math.round(map.distance(start, ll));
      if (metres < 1) { toast('The pin has not moved', 'warning'); return; }
      if (!confirm(`Update UISP?\n\n${p.site_name}\nMove by ${metres} m to ${ll.lat.toFixed(6)}, ${ll.lng.toFixed(6)}\n\nThis changes the client's GPS location in UISP.`)) return;
      try {
        const resp = await apiPost('/api/uisp_client_gps.php', { crm_client_id: p.crm_client_id, lat: ll.lat, lon: ll.lng });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + resp.status));
        marker.setLatLng(ll);
        finish();
        toast('UISP updated: ' + esc(p.site_name) + ' moved ' + metres + ' m', 'success');
      } catch (e) {
        toast('UISP was NOT updated: ' + e.message, 'danger');
      }
    }, finish, 'Save to UISP');
  };

  // Expose link/unlink handlers on window so popup inline onclick can reach them
  window._onuStartLink = function (deviceId) {
    const p = onuPropsById[deviceId] || { uisp_device_id: deviceId };
    map.closePopup();
    toast('Click the closure to link <strong>' + esc(p.site_name || deviceId) + '</strong> to…', 'secondary');
    map.getContainer().style.cursor = 'crosshair';

    // Leaflet stops DOM propagation for marker clicks, so map.click only fires
    // for background clicks (near-misses and cancels).
    // foscGroup.click handles exact marker hits via Leaflet's internal propagation.

    function onFoscClick(e) {
      const layer = e.layer;
      if (!layer._foscId) return;
      cleanup();
      saveLinkOnu(p.uisp_device_id, layer._foscId, p.site_name, layer._spliceNum);
    }

    function onMapClick(e) {
      // map.click fires for background clicks — do proximity check for near-misses
      const clickPt = map.latLngToContainerPoint(e.latlng);
      let nearest = null, nearestDist = 30; // px tolerance
      foscGroup.eachLayer(layer => {
        if (!layer._foscId) return;
        const pt = map.latLngToContainerPoint(layer.getLatLng());
        const dist = Math.hypot(pt.x - clickPt.x, pt.y - clickPt.y);
        if (dist < nearestDist) { nearestDist = dist; nearest = layer; }
      });
      cleanup();
      if (nearest) {
        saveLinkOnu(p.uisp_device_id, nearest._foscId, p.site_name, nearest._spliceNum);
      } else {
        toast('Link cancelled', 'secondary');
      }
    }

    function cleanup() {
      foscGroup.off('click', onFoscClick);
      map.off('click', onMapClick);
      map.getContainer().style.cursor = '';
    }

    foscGroup.on('click', onFoscClick);
    // Delay attaching the cancel/proximity handler so the popup-button click
    // doesn't immediately cancel link mode.
    setTimeout(() => map.on('click', onMapClick), 200);
  };

  window._onuUnlink = function (deviceId) {
    map.closePopup();
    apiDelete(API.assoc + '?uisp_device_id=' + encodeURIComponent(deviceId))
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          toast('ONT unlinked', 'success');
          onuLoaded = false;
          loadOnuMarkers();
          loadDropSpans();
        } else {
          toast('Unlink failed: ' + d.error, 'danger');
        }
      })
      .catch(e => toast('Unlink error: ' + e.message, 'danger'));
  };

  async function saveLinkOnu(deviceId, foscId, siteName, spliceNum) {
    try {
      const resp = await apiPost(API.assoc, { uisp_device_id: deviceId, fosc_id: foscId });
      const data = await resp.json();
      if (data.ok) {
        const label = spliceNum ? ` → ${esc(spliceNum)}` : '';
        toast('Linked <strong>' + esc(siteName || deviceId) + '</strong>' + label, 'success');
        onuLoaded = false;
        loadOnuMarkers();
        loadDropSpans();
      } else {
        toast('Link failed: ' + data.error, 'danger');
      }
    } catch (e) {
      toast('Link error: ' + e.message, 'danger');
    }
  }

  // ── Lead (UCRM) link/unlink — mirrors ONU flow but uses crm_client_id ────────

  window._leadStartLink = function (crmClientId) {
    const p = leadPropsById[String(crmClientId)] || { crm_client_id: crmClientId };
    map.closePopup();
    toast('Click the closure to link <strong>' + esc(p.site_name || ('Lead #' + crmClientId)) + '</strong> to…', 'secondary');
    map.getContainer().style.cursor = 'crosshair';

    function onFoscClick(e) {
      const layer = e.layer;
      if (!layer._foscId) return;
      cleanup();
      saveLinkLead(crmClientId, layer._foscId, p.site_name, layer._spliceNum);
    }

    function onMapClick(e) {
      const clickPt = map.latLngToContainerPoint(e.latlng);
      let nearest = null, nearestDist = 30;
      foscGroup.eachLayer(layer => {
        if (!layer._foscId) return;
        const pt = map.latLngToContainerPoint(layer.getLatLng());
        const dist = Math.hypot(pt.x - clickPt.x, pt.y - clickPt.y);
        if (dist < nearestDist) { nearestDist = dist; nearest = layer; }
      });
      cleanup();
      if (nearest) {
        saveLinkLead(crmClientId, nearest._foscId, p.site_name, nearest._spliceNum);
      } else {
        toast('Link cancelled', 'secondary');
      }
    }

    function cleanup() {
      foscGroup.off('click', onFoscClick);
      map.off('click', onMapClick);
      map.getContainer().style.cursor = '';
    }

    foscGroup.on('click', onFoscClick);
    setTimeout(() => map.on('click', onMapClick), 200);
  };

  window._leadUnlink = function (crmClientId) {
    map.closePopup();
    apiDelete(API.leadAssoc + '?crm_client_id=' + encodeURIComponent(crmClientId))
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          toast('Lead unlinked', 'success');
          onuLoaded = false;
          loadOnuMarkers();
          loadDropSpans();
        } else {
          toast('Unlink failed: ' + d.error, 'danger');
        }
      })
      .catch(e => toast('Unlink error: ' + e.message, 'danger'));
  };

  async function saveLinkLead(crmClientId, foscId, siteName, spliceNum) {
    try {
      const resp = await apiPost(API.leadAssoc, { crm_client_id: crmClientId, fosc_id: foscId });
      const data = await resp.json();
      if (data.ok) {
        const label = spliceNum ? ` → ${esc(spliceNum)}` : '';
        toast('Linked <strong>' + esc(siteName || ('Lead #' + crmClientId)) + '</strong>' + label, 'success');
        onuLoaded = false;
        loadOnuMarkers();
        loadDropSpans();
      } else {
        toast('Link failed: ' + data.error, 'danger');
      }
    } catch (e) {
      toast('Link error: ' + e.message, 'danger');
    }
  }

  // ── Prepared service link/unlink — keyed on crm_service_id ──────────────────
  // A client can hold several pending installs, so the link lives on the service.

  window._svcStartLink = function (crmServiceId) {
    const p = svcPropsById[String(crmServiceId)] || { crm_service_id: crmServiceId };
    map.closePopup();
    toast('Click the closure that will feed <strong>' + esc(p.site_name || ('Service #' + crmServiceId)) + '</strong>…', 'secondary');
    map.getContainer().style.cursor = 'crosshair';

    function onFoscClick(e) {
      const layer = e.layer;
      if (!layer._foscId) return;
      cleanup();
      saveLinkService(crmServiceId, p.svc_crm_client_id, layer._foscId, p.site_name, layer._spliceNum);
    }

    function onMapClick(e) {
      const clickPt = map.latLngToContainerPoint(e.latlng);
      let nearest = null, nearestDist = 30;
      foscGroup.eachLayer(layer => {
        if (!layer._foscId) return;
        const pt = map.latLngToContainerPoint(layer.getLatLng());
        const dist = Math.hypot(pt.x - clickPt.x, pt.y - clickPt.y);
        if (dist < nearestDist) { nearestDist = dist; nearest = layer; }
      });
      cleanup();
      if (nearest) {
        saveLinkService(crmServiceId, p.svc_crm_client_id, nearest._foscId, p.site_name, nearest._spliceNum);
      } else {
        toast('Link cancelled', 'secondary');
      }
    }

    function cleanup() {
      foscGroup.off('click', onFoscClick);
      map.off('click', onMapClick);
      map.getContainer().style.cursor = '';
    }

    foscGroup.on('click', onFoscClick);
    setTimeout(() => map.on('click', onMapClick), 200);
  };

  window._svcUnlink = function (crmServiceId) {
    map.closePopup();
    apiDelete(API.svcAssoc + '?crm_service_id=' + encodeURIComponent(crmServiceId))
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          toast('Service unlinked', 'success');
          onuLoaded = false;
          loadOnuMarkers();
          loadDropSpans();
        } else {
          toast('Unlink failed: ' + d.error, 'danger');
        }
      })
      .catch(e => toast('Unlink error: ' + e.message, 'danger'));
  };

  async function saveLinkService(crmServiceId, crmClientId, foscId, siteName, spliceNum) {
    try {
      const resp = await apiPost(API.svcAssoc, {
        crm_service_id: crmServiceId,
        crm_client_id:  crmClientId,
        fosc_id:        foscId,
      });
      const data = await resp.json();
      if (data.ok) {
        const label = spliceNum ? ` → ${esc(spliceNum)}` : '';
        toast('Linked <strong>' + esc(siteName || ('Service #' + crmServiceId)) + '</strong>' + label, 'success');
        onuLoaded = false;
        loadOnuMarkers();
        loadDropSpans();
      } else {
        toast('Link failed: ' + data.error, 'danger');
      }
    } catch (e) {
      toast('Link error: ' + e.message, 'danger');
    }
  }

  function fmtSpeed(bps) {
    if (bps == null) return '—';
    if (bps >= 1e9) return (bps / 1e9).toFixed(1) + ' Gbps';
    return Math.round(bps / 1e6) + ' Mbps';
  }

  // ── PCA bearing helpers ──────────────────────────────────────────────────────

  // Returns [bearingA, bearingB] (two opposite compass bearings, degrees) from
  // the principal axis of the 5 nearest poles to the given closure location.
  function computePoleAxis(closureLat, closureLon) {
    const strandedFids = new Set(strandData.flatMap(s => [s.from_pole_fid, s.to_pole_fid]));
    const nearest5 = poleData
      .filter(p => strandedFids.has(p.fid))
      .map(p => ({ ...p, d: (p.lat - closureLat) ** 2 + (p.lon - closureLon) ** 2 }))
      .sort((a, b) => a.d - b.d)
      .slice(0, 5);
    if (nearest5.length < 2) return null;

    let sL2 = 0, sA2 = 0, sLA = 0;
    for (const p of nearest5) {
      const dA = p.lat - closureLat;
      const dL = p.lon - closureLon;
      sL2 += dL * dL;
      sA2 += dA * dA;
      sLA += dA * dL;
    }
    const angle = 0.5 * Math.atan2(2 * sLA, sL2 - sA2);
    const b = (90 - angle * 180 / Math.PI + 360) % 360;
    return [b, (b + 180) % 360];
  }

  // Convert a bearing in degrees to a compass label like "NE (42°)".
  function bearingLabel(b) {
    const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    const idx  = Math.round(((b % 360) + 360) % 360 / 45) % 8;
    return `${dirs[idx]} (${Math.round(b)}\u00B0)`;
  }

  // Returns poles in poleData within 500 m and ±45° of bearing from closure,
  // ── Pole filter ─────────────────────────────────────────────────────────────

  function togglePoleFilterPanel() {
    if (poleFilterOpen) {
      closePoleFilterPanel();
    } else {
      poleFilterOpen = true;
      const panel = document.getElementById('poleFilterPanel');
      if (panel) panel.style.display = '';
      document.getElementById('btnFilterPoles')?.classList.add('active');
      document.getElementById('pfWorkOrder')?.focus();
    }
  }

  function closePoleFilterPanel() {
    poleFilterOpen = false;
    const panel = document.getElementById('poleFilterPanel');
    if (panel) panel.style.display = 'none';
    // Keep filter active if values are set — just close the panel
    const activeCount = Object.values(poleFilterValues).filter(Boolean).length;
    if (!activeCount) document.getElementById('btnFilterPoles')?.classList.remove('active');
  }

  // A filter is a working state, not a preference: reloading the page mid-job and
  // silently getting the whole network back is worse than useless, because the
  // map looks correct either way. Persist it and put it back on the next load.
  function savePoleFilterState() {
    try {
      const st = {
        values:        poleFilterValues,
        hasWO:         poleFilterHasWO,
        hasBellPermit: poleFilterHasBellPermit,
        addBack:       addBackChecked(),
      };
      const empty = !Object.keys(st.values).length && !st.hasWO && !st.hasBellPermit;
      if (empty) localStorage.removeItem(MAP_POLE_FILTER_KEY);
      else       localStorage.setItem(MAP_POLE_FILTER_KEY, JSON.stringify(st));
    } catch {}
  }

  // Writes the saved filter back into the panel inputs only. Applying it is left
  // to the existing post-render `if (poleFilterActive()) applyPoleFilter()` hooks —
  // poleFilterActive() reads the live inputs, so filling them in is enough, and
  // markers do not exist yet at the point this runs.
  function restorePoleFilterState() {
    let st = null;
    try { st = JSON.parse(localStorage.getItem(MAP_POLE_FILTER_KEY)); } catch {}
    if (!st || typeof st !== 'object') return;

    const values = st.values && typeof st.values === 'object' ? st.values : {};
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      const v = values[inp.dataset.field];
      if (v) inp.value = v;
    });
    const woChk = document.getElementById('pfHasWO');
    if (woChk) woChk.checked = !!st.hasWO;
    const bpChk = document.getElementById('pfHasBellPermit');
    if (bpChk) bpChk.checked = !!st.hasBellPermit;
    const ab = Array.isArray(st.addBack) ? st.addBack : [];
    document.querySelectorAll('#pfAddBack input[data-addback]').forEach(i => { i.checked = ab.includes(i.dataset.addback); });

    poleFilterValues        = values;
    poleFilterHasWO         = !!st.hasWO;
    poleFilterHasBellPermit = !!st.hasBellPermit;

    if (poleFilterActive()) {
      document.getElementById('btnFilterPoles')?.classList.add('active');
      updateActiveFilterChip();
    }
  }

  // True if any pole filter is active — checks both the cached state AND the
  // live DOM inputs, so a re-apply after a marker rebuild (zoom/pan) never
  // gets skipped just because the cache was momentarily empty.
  function poleFilterActive() {
    if (Object.values(poleFilterValues).some(Boolean) || poleFilterHasWO || poleFilterHasBellPermit) return true;
    const anyInput = Array.from(document.querySelectorAll('#poleFilterPanel input[data-field]'))
      .some(inp => inp.value.trim() !== '');
    const anyChk = !!document.getElementById('pfHasWO')?.checked
                || !!document.getElementById('pfHasBellPermit')?.checked;
    return anyInput || anyChk;
  }

  // Show/hide a vector or marker layer (and its permanent label) for the filter.
  function setLayerHidden(layer, hidden) {
    const el = layer.getElement && layer.getElement();
    if (el) el.style.display = hidden ? 'none' : '';
    const tt = layer.getTooltip && layer.getTooltip();
    const ttEl = tt && (tt.getElement ? tt.getElement() : tt._container);
    if (ttEl) ttEl.style.display = hidden ? 'none' : '';
  }

  function applyPoleFilter() {
    // Read current input values
    poleFilterValues = {};
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      const v = inp.value.trim();
      if (v) poleFilterValues[inp.dataset.field] = v;
    });
    poleFilterHasWO         = document.getElementById('pfHasWO')?.checked         || false;
    poleFilterHasBellPermit = document.getElementById('pfHasBellPermit')?.checked || false;

    // Work-order filter: one permit or a comma-separated list (any match), e.g. "2026-10-ForestryCentre,
    // 2026-10-ForestryCentreAerial" prints the shared route plus option A. A term that is a complete known
    // permit number matches that permit exactly (so the shared permit does not also pull in
    // 2026-10-ForestryCentreAerial); anything else is a substring, as before.
    // Every permit on file, not just those on the poles in view: with the shared permit's poles off screen
    // it would otherwise match as a substring and pull in 2026-10-ForestryCentreAerial too.
    const knownWO = new Set(allPermitsLower);
    document.querySelectorAll('#pfWorkOrderList option').forEach(o => knownWO.add((o.value || '').toLowerCase()));
    const woFilterMatches = (wos, filterStr) => {
      const terms = String(filterStr).split(',').map(t => t.trim().toLowerCase()).filter(Boolean);
      if (!terms.length) return true;
      return terms.some(t => wos.some(w => {
        w = String(w).toLowerCase();
        return w === t || (!knownWO.has(t) && w.includes(t));
      }));
    };
    const filters = Object.entries(poleFilterValues);
    let shown = 0, hidden = 0;

    // Track per-pole visibility so anchors can mirror it
    const hiddenFids = new Set();
    const fadedFids  = new Set();

    poleGroup.eachLayer(function (marker) {
      const p = marker._poleProps;
      if (!p) return;
      const match = filters.every(function ([k, v]) {
        const vl = v.toLowerCase();
        // The list can arrive empty while the single field is set — an empty
        // array is truthy, so check length rather than existence.
        if (k === 'work_order') {
          const arr = (p.work_orders && p.work_orders.length) ? p.work_orders : (p.work_order ? [p.work_order] : []);
          return woFilterMatches(arr, vl);
        }
        if (k === 'bell_permit_num') {
          const arr = (p.bell_permit_nums && p.bell_permit_nums.length) ? p.bell_permit_nums : (p.bell_permit_num ? [p.bell_permit_num] : []);
          return arr.some(bp => bp.toLowerCase().includes(vl));
        }
        return String(p[k] ?? '').toLowerCase().includes(vl);
      });
      const hasWO   = (p.work_orders     && p.work_orders.length     > 0) || !!p.work_order;
      const hasBP   = (p.bell_permit_nums && p.bell_permit_nums.length > 0) || !!p.bell_permit_num;
      const faded = match && ((poleFilterHasWO && !hasWO) || (poleFilterHasBellPermit && !hasBP));
      const el = marker.getElement();
      if (el) {
        el.style.display = match ? '' : 'none';
        if (match) el.style.opacity = faded ? '0.35' : '';
      }
      const tt = marker.getTooltip();
      const ttEl = tt && (tt.getElement ? tt.getElement() : tt._container);
      if (ttEl) {
        ttEl.style.display = match ? '' : 'none';
        ttEl.style.opacity = (match && faded) ? '0.35' : '';
      }
      if (!match) hiddenFids.add(p.fid);
      else if (faded) fadedFids.add(p.fid);
      match ? shown++ : hidden++;
    });

    // Apply same visibility to anchors linked to filtered poles
    anchorGroup.eachLayer(function (marker) {
      const ap = marker._tinAnchor && marker._tinAnchor.props;
      if (!ap) return;
      const el = marker.getElement();
      if (!el) return;
      if (hiddenFids.has(ap.pole_fid)) {
        el.style.display = 'none';
      } else {
        el.style.display = '';
        el.style.opacity = fadedFids.has(ap.pole_fid) ? '0.35' : '';
      }
    });

    // With a WO / Bell-permit filter active, show ONLY the matching linear and
    // point infrastructure (conduits, strands, spans, closures) — hide the rest.
    const woFilter = poleFilterValues['work_order']      ? poleFilterValues['work_order'].toLowerCase()      : null;
    const bpFilter = poleFilterValues['bell_permit_num'] ? poleFilterValues['bell_permit_num'].toLowerCase() : null;
    const woHas = (val) => {
      const parts = val ? String(val).split(',').map(s => s.trim().toLowerCase()) : [];
      return !woFilter || woFilterMatches(parts, woFilter);
    };
    const conduitHidden = (line) => {
      const cp = line._tinConduit;
      const bpVals = cp.bell_permit_num ? cp.bell_permit_num.split(',').map(s => s.trim().toLowerCase()) : [];
      const bpMatch = !bpFilter || bpVals.some(v => v.includes(bpFilter));
      return !(woHas(cp.fpm_wor) && bpMatch);
    };
    if (woFilter || bpFilter) {
      conduitGroup.eachLayer(function (line) {
        if (line._tinConduit) setLayerHidden(line, conduitHidden(line));
        else if (line._tinTerm) setLayerHidden(line, !woHas(line._tinTerm.fpm_wor));
      });
      // strandGroup holds the strand stripes AND the span/conduit casings drawn
      // beneath them — each tagged (_tinStrand or _tinConduit) so they hide with
      // their owning feature. Strands carry no Bell-permit association — WO only.
      strandGroup.eachLayer(function (line) {
        if (line._tinStrand) {
          const sp = line._tinStrand;
          const wos = (sp.work_orders && sp.work_orders.length) ? sp.work_orders.join(',') : (sp.work_order || '');
          setLayerHidden(line, !woHas(wos));
        } else if (line._tinConduit) {
          setLayerHidden(line, conduitHidden(line));
        }
      });
      spanGroup.eachLayer(function (line) {
        if (line._tinSpan) {
          const sp  = line._tinSpan;
          const wos = (sp.work_orders && sp.work_orders.length) ? sp.work_orders.join(',') : (sp.fpm_wor || '');
          setLayerHidden(line, !woHas(wos));
        }
      });
      // Fibre-count labels (144F …) for both spans and conduits — WO only.
      spanLabelGroup.eachLayer(function (marker) {
        if (marker._tinLabelWor !== undefined) setLayerHidden(marker, !woHas(marker._tinLabelWor));
      });
      // FlexTIN pole-to-pole distance labels ("45 m") hide with their span too —
      // left unfiltered they papered the whole map over a filtered-down job.
      flextinDistGroup.eachLayer(function (marker) {
        if (marker._tinLabelWor !== undefined) setLayerHidden(marker, !woHas(marker._tinLabelWor));
      });
      routeDistGroup.eachLayer(function (marker) {
        if (marker._tinLabelWor !== undefined) setLayerHidden(marker, !woHas(marker._tinLabelWor));
      });
      // Building anchors carry their own permit tag.
      buildingAnchorGroup.eachLayer(function (marker) {
        if (marker._banchorProps) setLayerHidden(marker, !woHas(marker._banchorProps.fpm_wor));
      });
      const popsKept = new Set();
      foscGroup.eachLayer(function (marker) {
        const fp = marker._tinFosc;
        if (!fp) return;
        const wos = (fp.work_orders && fp.work_orders.length) ? fp.work_orders.join(',') : (fp.fpm_wor || '');
        const hide = !woHas(wos);
        setLayerHidden(marker, hide);
        if (!hide && fp.pop_id) popsKept.add(Number(fp.pop_id));
      });
      // A POP has no permit of its own: it stays only while a closure that belongs to it matches the filter.
      popGroup.eachLayer(function (marker) {
        if (marker._tinPop) setLayerHidden(marker, !popsKept.has(Number(marker._tinPop.pop_id)));
      });
      // Vaults / handholes / pullboxes and underground guards (WO tag only).
      vaultGroup.eachLayer(function (marker) {
        if (marker._tinVault) setLayerHidden(marker, !woHas(marker._tinVault.props.fpm_wor));
      });
      handholeGroup.eachLayer(function (marker) {
        if (marker._tinVault) setLayerHidden(marker, !woHas(marker._tinVault.props.fpm_wor));
      });
      uguardGroup.eachLayer(function (marker) {
        if (marker._tinUguard) setLayerHidden(marker, !woHas(marker._tinUguard.props.fpm_wor));
      });
      // A note with a permit follows that permit's filter. One without (older notes) is kept only
      // when within 50 m of a pole that matches the filter — otherwise another job's notes sit on a
      // filtered map.
      const keepPts = [];
      poleGroup.eachLayer(function (m) {
        if (m._poleProps && !hiddenFids.has(m._poleProps.fid)) keepPts.push(m.getLatLng());
      });
      noteGroup.eachLayer(function (marker) {
        if (marker._tinNoteWor) { setLayerHidden(marker, !woHas(marker._tinNoteWor)); return; }
        const ll = marker.getLatLng();
        setLayerHidden(marker, !keepPts.some(q => map.distance(ll, q) <= 50));
      });
      // WiFi APs, their aim lines and cones follow their work order
      wifiGroup.eachLayer(function (l) { if (l._tinWifiWor !== undefined) setLayerHidden(l, !woHas(l._tinWifiWor)); });
      applyAddBack(addBackChecked());
    } else {
      applyAddBack(null);
      wifiGroup.eachLayer(function (l) { if (l._tinWifiWor !== undefined) setLayerHidden(l, false); });
      noteGroup.eachLayer(function (marker) { setLayerHidden(marker, false); });
      conduitGroup.eachLayer(function (line) { line.setStyle({ opacity: 0.95 }); setLayerHidden(line, false); });
      strandGroup.eachLayer(function (line) { if (line._tinStrand || line._tinConduit) setLayerHidden(line, false); });
      spanGroup.eachLayer(function (line) { if (line._tinSpan) setLayerHidden(line, false); });
      spanLabelGroup.eachLayer(function (marker) { if (marker._tinLabelWor !== undefined) setLayerHidden(marker, false); });
      flextinDistGroup.eachLayer(function (marker) { if (marker._tinLabelWor !== undefined) setLayerHidden(marker, false); });
      routeDistGroup.eachLayer(function (marker) { if (marker._tinLabelWor !== undefined) setLayerHidden(marker, false); });
      buildingAnchorGroup.eachLayer(function (marker) { if (marker._banchorProps) setLayerHidden(marker, false); });
      foscGroup.eachLayer(function (marker) { if (marker._tinFosc) setLayerHidden(marker, false); });
      popGroup.eachLayer(function (marker) { if (marker._tinPop) setLayerHidden(marker, false); });
      vaultGroup.eachLayer(function (marker) { if (marker._tinVault) setLayerHidden(marker, false); });
      handholeGroup.eachLayer(function (marker) { if (marker._tinVault) setLayerHidden(marker, false); });
      uguardGroup.eachLayer(function (marker) { if (marker._tinUguard) setLayerHidden(marker, false); });
    }

    // Dimensions follow the work-order filter too; they are redrawn per zoom, so the test lives in drawDimension.
    dimFilterWor = woFilter; dimFilterMatch = woFilterMatches; dimFilterHiddenFids = hiddenFids;
    redrawDimensions();
    redrawMarks();   // sheath marks follow their span's permits

    // Update badge
    const count = filters.length + (poleFilterHasWO ? 1 : 0) + (poleFilterHasBellPermit ? 1 : 0);
    const badge = document.getElementById('poleFilterBadge');
    if (badge) {
      badge.textContent = count;
      badge.style.display = count ? 'inline-flex' : 'none';
    }

    // Status line
    const status = document.getElementById('poleFilterStatus');
    if (status) {
      if (filters.length) {
        status.textContent = shown + ' shown, ' + hidden + ' hidden';
      } else if (poleFilterHasWO && poleFilterHasBellPermit) {
        status.textContent = 'Fading poles without Tinuum Permit or Bell Permit';
      } else if (poleFilterHasWO) {
        status.textContent = 'Fading poles without Tinuum Permit';
      } else if (poleFilterHasBellPermit) {
        status.textContent = 'Fading poles without Bell Permit';
      } else {
        status.textContent = '';
      }
    }

    // Both branches above re-show every closure, so the FlexTIN taps get hidden
    // again here — after the work-order filter has had its say, not before.
    applyFlextinClosureFilter();

    // Keep button highlighted while filter is active
    if (count) {
      document.getElementById('btnFilterPoles')?.classList.add('active');
    }
    updateActiveFilterChip();
    savePoleFilterState();
  }

  // ── Active-filter indicator ──────────────────────────────────────────────────
  // The filter controls live in the toolbar flyout, which is shut most of the
  // time — so a filter could be hiding half the network with nothing on screen to
  // say so. This chip is always visible while any filter is on, names what is
  // being filtered, and offers a one-click Clear.

  const POLE_FILTER_LABELS = {
    work_order:      'Tinuum Permit',
    bell_permit_num: 'Bell Permit',
    uid:             'Pole UID',
    owner:           'Owner',
    type:            'Type',
    nbp_num:         'NBP #',
    telco_num:       'Bell #',
    tags_num:        'Tag #',
  };

  function updateActiveFilterChip() {
    const chip = document.getElementById('mapFilterChip');
    if (!chip) return;

    const parts = Object.entries(poleFilterValues)
      .map(([k, v]) => (POLE_FILTER_LABELS[k] || k) + ' = “' + v + '”');
    if (poleFilterHasWO)         parts.push('fading poles with no Tinuum Permit');
    if (poleFilterHasBellPermit) parts.push('fading poles with no Bell Permit');
    const roadHidden = (typeof rcComboHidden !== 'undefined' && rcComboHidden) ? rcComboHidden.size : 0;
    if (roadHidden) parts.push(roadHidden + ' road service type' + (roadHidden === 1 ? '' : 's') + ' hidden');

    const dot = document.getElementById('railDotLayers');
    if (!parts.length) {
      chip.style.display = 'none';
      if (dot) dot.style.display = 'none';
      return;
    }
    // esc() because these carry user-typed filter values.
    document.getElementById('mapFilterChipText').innerHTML =
      '<strong>Filtered view — ' + parts.length + ' filter' + (parts.length === 1 ? '' : 's') +
      ' active</strong> · ' + parts.map(esc).join(' · ');
    chip.style.display = 'flex';
    if (dot) dot.style.display = '';
  }

  function clearAllMapFilters() {
    clearPoleFilter();
    if (typeof rcComboHidden !== 'undefined' && rcComboHidden && rcComboHidden.size) {
      document.querySelectorAll('.rc-type-chk').forEach(function (chk) { chk.checked = true; });
      if (typeof applyRoadTypeFilter === 'function') applyRoadTypeFilter();
    }
    updateActiveFilterChip();
  }

  function clearPoleFilter() {
    poleFilterValues = {};
    poleFilterHasWO         = false;
    poleFilterHasBellPermit = false;
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      inp.value = '';
    });
    const woChk = document.getElementById('pfHasWO');
    if (woChk) woChk.checked = false;
    const bpChk = document.getElementById('pfHasBellPermit');
    if (bpChk) bpChk.checked = false;
    document.querySelectorAll('#pfAddBack input[data-addback]').forEach(i => { i.checked = false; });
    applyAddBack(null);
    // Restore all pole marker visibility and opacity
    poleGroup.eachLayer(function (marker) {
      const el = marker.getElement();
      if (el) { el.style.display = ''; el.style.opacity = ''; }
      const tt = marker.getTooltip();
      const ttEl = tt && (tt.getElement ? tt.getElement() : tt._container);
      if (ttEl) { ttEl.style.display = ''; ttEl.style.opacity = ''; }
    });
    // Restore anchor visibility
    anchorGroup.eachLayer(function (marker) {
      const el = marker.getElement();
      if (el) { el.style.display = ''; el.style.opacity = ''; }
    });
    // Restore conduit opacity and un-hide conduits / strands / spans / labels / closures
    conduitGroup.eachLayer(function (line) { line.setStyle({ opacity: 0.95 }); setLayerHidden(line, false); });
    strandGroup.eachLayer(function (line) { if (line._tinStrand || line._tinConduit) setLayerHidden(line, false); });
    spanGroup.eachLayer(function (line) { if (line._tinSpan) setLayerHidden(line, false); });
    spanLabelGroup.eachLayer(function (marker) { if (marker._tinLabelWor !== undefined) setLayerHidden(marker, false); });
    foscGroup.eachLayer(function (marker) { if (marker._tinFosc) setLayerHidden(marker, false); });
    vaultGroup.eachLayer(function (marker) { if (marker._tinVault) setLayerHidden(marker, false); });
    handholeGroup.eachLayer(function (marker) { if (marker._tinVault) setLayerHidden(marker, false); });
    uguardGroup.eachLayer(function (marker) { if (marker._tinUguard) setLayerHidden(marker, false); });
    noteGroup.eachLayer(function (marker) { setLayerHidden(marker, false); });
    const badge = document.getElementById('poleFilterBadge');
    if (badge) { badge.textContent = ''; badge.style.display = 'none'; }
    const status = document.getElementById('poleFilterStatus');
    if (status) status.textContent = '';
    document.getElementById('btnFilterPoles')?.classList.remove('active');
    updateActiveFilterChip();
    savePoleFilterState();
  }

  // ── Measurement tool ────────────────────────────────────────────────────────

  function fmtDist(m) {
    return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : Math.round(m) + ' m';
  }

  function measureLabelMarker(latlng, text, isTotal) {
    const icon = L.divIcon({
      className: '',
      html: `<span class="measure-label${isTotal ? ' measure-label-total' : ''}">${text}</span>`,
      iconAnchor: [0, 0],
    });
    return L.marker(latlng, { icon, interactive: false, keyboard: false });
  }

  function updateMeasurePolyline() {
    if (measurePolyline) { measurePolyline.remove(); measurePolyline = null; }
    if (measurePoints.length < 2) return;
    measurePolyline = L.polyline(measurePoints, {
      color: '#e63946', weight: 3, dashArray: '6 4', interactive: false,
    }).addTo(map);
  }

  function updateMeasureLabels() {
    measureLabels.forEach(m => m.remove());
    measureLabels = [];
    if (measurePoints.length < 2) return;

    let total = 0;
    for (let i = 1; i < measurePoints.length; i++) {
      const seg = measurePoints[i - 1].distanceTo(measurePoints[i]);
      total += seg;
      // Segment label at midpoint
      const mid = L.latLng(
        (measurePoints[i - 1].lat + measurePoints[i].lat) / 2,
        (measurePoints[i - 1].lng + measurePoints[i].lng) / 2,
      );
      const lbl = measureLabelMarker(mid, fmtDist(seg), false);
      lbl.addTo(map);
      measureLabels.push(lbl);
    }
    // Total at last point
    const tot = measureLabelMarker(measurePoints[measurePoints.length - 1], '∑ ' + fmtDist(total), true);
    tot.addTo(map);
    measureLabels.push(tot);
  }

  function onMeasureClick(e) {
    if (e.originalEvent && e.originalEvent.detail === 2) return; // ignore double-click
    measurePoints.push(e.latlng);
    updateMeasurePolyline();
    updateMeasureLabels();
  }

  function onMeasureMove(e) {
    if (measureCursor) { measureCursor.remove(); measureCursor = null; }
    if (measurePoints.length === 0) return;
    const last = measurePoints[measurePoints.length - 1];
    const dist = last.distanceTo(e.latlng);
    measureCursor = L.polyline([last, e.latlng], {
      color: '#e63946', weight: 2, dashArray: '4 4', opacity: 0.6, interactive: false,
    }).addTo(map);
    // Show running distance on cursor polyline tooltip
    measureCursor.bindTooltip(fmtDist(dist), { sticky: true, className: 'measure-cursor-tip', offset: [10, 0] }).openTooltip(e.latlng);
  }

  function onMeasureDblClick(e) {
    L.DomEvent.stopPropagation(e);
    // Remove rubber-band line, leave final polyline + labels, exit mode
    if (measureCursor) { measureCursor.remove(); measureCursor = null; }
    map.off('click', onMeasureClick);
    map.off('mousemove', onMeasureMove);
    map.off('dblclick', onMeasureDblClick);
    measureActive = false;
    map.getContainer().style.cursor = '';
    document.getElementById('btnMeasure')?.classList.remove('tgl-off');
    document.getElementById('btnMeasure')?.classList.add('measure-done');
    if (measurePoints.length >= 2) {
      let tot = 0;
      for (let i = 1; i < measurePoints.length; i++) tot += measurePoints[i - 1].distanceTo(measurePoints[i]);
      showEditBar(`Measured <b>${fmtDim(tot)}</b>`, keepMeasureAsDimension, hideEditBar, 'Keep as dimension');
    }
  }

  function clearMeasure() {
    if (measurePolyline) { measurePolyline.remove(); measurePolyline = null; }
    if (measureCursor)   { measureCursor.remove();   measureCursor   = null; }
    measureLabels.forEach(m => m.remove());
    measureLabels  = [];
    measurePoints  = [];
    measureActive  = false;
    map.off('click',     onMeasureClick);
    map.off('mousemove', onMeasureMove);
    map.off('dblclick',  onMeasureDblClick);
    map.getContainer().style.cursor = '';
    const btn = document.getElementById('btnMeasure');
    if (btn) btn.classList.remove('measure-done', 'active');
  }

  function toggleMeasure() {
    if (measureActive || document.getElementById('btnMeasure')?.classList.contains('measure-done')) {
      clearMeasure();
      document.getElementById('btnMeasure')?.classList.remove('tgl-off');
      return;
    }
    measureActive = true;
    measurePoints = [];
    document.getElementById('btnMeasure')?.classList.remove('tgl-off');
    document.getElementById('btnMeasure')?.classList.add('active');
    map.getContainer().style.cursor = 'crosshair';
    map.on('click',     onMeasureClick);
    map.on('mousemove', onMeasureMove);
    map.on('dblclick',  onMeasureDblClick);
  }

  // ── Dimensions ──────────────────────────────────────────────────────────────
  // Saved distances kept on the map (and the plan sheet) until deleted. Straight: two
  // points, drawn architectural-style off the measured line (witness lines, end ticks, text
  // along the line). Route: a path traced through assets, drawn along the path. Straight
  // dimensions whose ends are poles follow the pole (the server recomputes the geometry).
  // The tool is separate from the one-off measure tool, which can hand its result over.

  let dimFeatures = [];      // features as loaded, so a zoom can redraw them
  let dimTool = null;        // { mode:'straight'|'route', pts:[{ll,pole}], path:[ll], layer, snapRing }
  const DIM_INK = '#1f2937';

  function fmtDim(m) {
    return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : m.toFixed(1) + ' m';
  }

  function toggleWifi() {
    wifiVisible = !wifiVisible;
    document.getElementById('btnToggleWifi')?.classList.toggle('tgl-off', !wifiVisible);
    if (wifiVisible) wifiGroup.addTo(map); else map.removeLayer(wifiGroup);
    saveLayerState();
  }

  function toggleEasements() {
    easementsVisible = !easementsVisible;
    document.getElementById('btnToggleEasements')?.classList.toggle('tgl-off', !easementsVisible);
    if (easementsVisible) easementGroup.addTo(map); else map.removeLayer(easementGroup);
    saveLayerState();
  }

  function toggleDims() {
    dimsVisible = !dimsVisible;
    document.getElementById('btnToggleDims')?.classList.toggle('tgl-off', !dimsVisible);
    if (dimsVisible) dimGroup.addTo(map); else map.removeLayer(dimGroup);
    saveLayerState();
  }


  // ── Sheath marks ────────────────────────────────────────────────────────────
  // Meter marks read off the cable sheath (app.sheath_length) with the cable's serial, from the contractor
  // as-builts.  Marks at the same spot (a splice's @pole and @EOC, a slack loop's two sides) share one label.
  // Nothing is drawn until the map is zoomed in close; the serial is added from one zoom closer.
  let markFeatures = [];
  const MARK_ZOOM = 17, MARK_SERIAL_ZOOM = 18;

  function toggleMarks() {
    marksVisible = !marksVisible;
    document.getElementById('btnToggleMarks')?.classList.toggle('tgl-off', !marksVisible);
    if (marksVisible) markGroup.addTo(map); else map.removeLayer(markGroup);
    saveLayerState();
  }

  function redrawMarks() {
    if (!markGroup) return;
    markGroup.clearLayers();
    const z = map.getZoom();
    if (z < MARK_ZOOM || !markFeatures.length) return;
    const groups = new Map();
    markFeatures.forEach(f => {
      // A mark has the permits of its span; with a permit filter on, only matching ones stay.
      if (dimFilterWor && !dimFilterMatch(f.properties.work_orders || [], dimFilterWor)) return;
      const [lon, lat] = f.geometry.coordinates;
      const k = lat.toFixed(5) + ',' + lon.toFixed(5);          // ~1 m
      if (!groups.has(k)) groups.set(k, { lat, lon, items: [] });
      groups.get(k).items.push(f.properties);
    });
    const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    groups.forEach(g => {
      g.items.sort((a, b) => b.measure_m - a.measure_m);
      const serials = [...new Set(g.items.map(i => i.cable_serial).filter(Boolean))];
      const lines = g.items.map(i => `<div>${Number(i.measure_m).toLocaleString('en-CA', { maximumFractionDigits: 1 })} m${i.notes ? ` <i>${esc(i.notes)}</i>` : ''}</div>`).join('');
      const head = (z >= MARK_SERIAL_ZOOM && serials.length) ? `<div class="mark-serial">${serials.map(esc).join(' / ')}</div>` : '';
      const icon = L.divIcon({
        className: '',
        iconSize: [0, 0],
        html: `<span class="mark-dot"></span><span class="mark-label">${head}${lines}</span>`,
      });
      const m = L.marker([g.lat, g.lon], { icon, keyboard: false, zIndexOffset: 500 });
      m.bindPopup(() => {
        const rows = g.items.map(i => `<tr><td style="text-align:right;padding-right:8px"><b>${Number(i.measure_m).toLocaleString('en-CA', { maximumFractionDigits: 1 })} m</b></td><td>${esc(i.notes)}</td><td style="padding-left:8px;color:#6b7280">${(i.work_orders || []).length ? esc(i.work_orders.join(', ')) + ' · ' : ''}span ${i.cable_span_id ?? ''}${i.fibre_count ? ` · ${i.fibre_count}F` : ''}</td></tr>`).join('');
        return `<b>Sheath marks</b>${serials.length ? `<div>Cable ID: <b>${serials.map(esc).join(' / ')}</b></div>` : ''}<table style="margin-top:4px;font-size:12px">${rows}</table>`;
      }, { minWidth: 200 });
      markGroup.addLayer(m);
    });
  }

  function renderDimension(feature) {
    dimFeatures.push(feature);
    drawDimension(feature);
  }

  // Set by applyPoleFilter.  A tagged dimension follows its work order; an untagged one stays only
  // while an end pole survives the filter.
  let dimFilterWor = null;
  let dimFilterMatch = () => true;
  let dimFilterHiddenFids = new Set();
  function dimHiddenByFilter(p) {
    if (!dimFilterWor) return false;
    if (p.fpm_wor) {
      const parts = String(p.fpm_wor).split(',').map(x => x.trim().toLowerCase());
      return !dimFilterMatch(parts, dimFilterWor);
    }
    const fids = [p.a_pole_fid, p.b_pole_fid].filter(Boolean);
    return !fids.some(f => !dimFilterHiddenFids.has(f));
  }

  function redrawDimensions() {
    if (!dimGroup) return;
    dimGroup.clearLayers();
    dimFeatures.forEach(drawDimension);
  }

  function metersPerPx() {
    return 156543.03392 * Math.cos(map.getCenter().lat * Math.PI / 180) / Math.pow(2, map.getZoom());
  }

  function dimLabelMarker(ll, angleDeg, text, onClick) {
    let a = angleDeg;
    if (a > 90 || a < -90) a += 180;                       // never upside down
    const icon = L.divIcon({
      className: '',
      iconSize: [0, 0],
      html: `<span class="dim-label" style="transform:translate(-50%,-50%) rotate(${a.toFixed(1)}deg) translateY(-9px)">${text}</span>`,
    });
    const m = L.marker(ll, { icon, keyboard: false, zIndexOffset: 400 });
    if (onClick) m.on('click', onClick);
    return m;
  }

  function drawDimension(feature) {
    const p = feature.properties;
    const coords = feature.geometry.coordinates.map(c => L.latLng(c[1], c[0]));
    if (coords.length < 2 || dimHiddenByFilter(p)) return;
    const text = esc(p.label || fmtDim(p.length_m));
    const open = (ll) => L.popup({ minWidth: 220 }).setLatLng(ll).setContent(buildDimPopup(p)).openOn(map);
    const line = { color: DIM_INK, weight: 1.5, interactive: false };
    const halo = { color: '#fff', weight: 4, opacity: 0.75, interactive: false };
    const add = (l) => dimGroup.addLayer(l);
    // an invisible, wider line over the dimension so a click anywhere on it opens its popup (edit, delete)
    const hit = (pts) => L.polyline(pts, { color: '#000', weight: 14, opacity: 0, interactive: true }).on('click', (ev) => { L.DomEvent.stopPropagation(ev); open(ev.latlng); });
    const px = (ll) => map.latLngToLayerPoint(ll);
    const ll = (pt) => map.layerPointToLatLng(pt);
    const tick = (pt, dx, dy) => {                          // 45° slash across the line at pt
      const L2 = Math.hypot(dx, dy) || 1, ux = dx / L2, uy = dy / L2;
      const vx = (ux - uy) * 0.7071, vy = (ux + uy) * 0.7071;
      add(L.polyline([ll(L.point(pt.x - vx * 6, pt.y - vy * 6)), ll(L.point(pt.x + vx * 6, pt.y + vy * 6))], { color: DIM_INK, weight: 2, interactive: false }));
    };

    if (p.kind === 'route') {
      // Offset the whole path sideways (mitred at the bends) so it never sits on the asset.
      const P = coords.map(px), n = P.length, off = (p.offset_m || 0) / metersPerPx();
      const segN = [];
      for (let i = 1; i < n; i++) {
        const sx = P[i].x - P[i - 1].x, sy = P[i].y - P[i - 1].y, sl = Math.hypot(sx, sy) || 1;
        segN.push([-sy / sl, sx / sl]);
      }
      const Q = P.map((pt, i) => {
        const a = segN[Math.max(0, i - 1)], b = segN[Math.min(segN.length - 1, i)];
        let vx = a[0] + b[0], vy = a[1] + b[1];
        const vl = Math.hypot(vx, vy) || 1; vx /= vl; vy /= vl;
        const dot = Math.max(0.5, vx * b[0] + vy * b[1]);
        return L.point(pt.x + vx * off / dot, pt.y + vy * off / dot);
      });
      if (Math.abs(off) > 3) {
        [[P[0], Q[0]], [P[n - 1], Q[n - 1]]].forEach(([s0, e0]) => {
          const ex = (e0.x - s0.x) / Math.abs(off) * 5, ey = (e0.y - s0.y) / Math.abs(off) * 5;
          add(L.polyline([ll(L.point(s0.x + (e0.x - s0.x) * 0.12, s0.y + (e0.y - s0.y) * 0.12)), ll(L.point(e0.x + ex, e0.y + ey))], { color: DIM_INK, weight: 0.8, opacity: 0.8, interactive: false }));
        });
      }
      const Ql = Q.map(ll);
      add(L.polyline(Ql, halo));
      add(L.polyline(Ql, line));
      add(hit(Ql));
      tick(Q[0], Q[1].x - Q[0].x, Q[1].y - Q[0].y);
      tick(Q[n - 1], Q[n - 1].x - Q[n - 2].x, Q[n - 1].y - Q[n - 2].y);
      let total = 0; const segs = [];
      for (let i = 1; i < n; i++) { const d = Math.hypot(Q[i].x - Q[i - 1].x, Q[i].y - Q[i - 1].y); segs.push(d); total += d; }
      let acc = 0, i = 0;
      while (i < segs.length - 1 && acc + segs[i] < total / 2) { acc += segs[i]; i++; }
      const t = segs[i] ? (total / 2 - acc) / segs[i] : 0;
      const mid = ll(L.point(Q[i].x + (Q[i + 1].x - Q[i].x) * t, Q[i].y + (Q[i + 1].y - Q[i].y) * t));
      add(dimLabelMarker(mid, Math.atan2(Q[i + 1].y - Q[i].y, Q[i + 1].x - Q[i].x) * 180 / Math.PI, text, () => open(mid)));
      return;
    }

    const A = px(coords[0]), B = px(coords[coords.length - 1]);
    const dx = B.x - A.x, dy = B.y - A.y, len = Math.hypot(dx, dy) || 1;
    const off = (p.offset_m || 0) / metersPerPx();
    const nx = -dy / len * off, ny = dx / len * off;
    const A2 = L.point(A.x + nx, A.y + ny), B2 = L.point(B.x + nx, B.y + ny);
    if (Math.abs(off) > 3) {                                // witness lines, running a little past the dimension line
      const ex = Math.sign(off) * nx / (Math.abs(off) || 1) * 5, ey = Math.sign(off) * ny / (Math.abs(off) || 1) * 5;
      [[A, A2], [B, B2]].forEach(([s, e]) => add(L.polyline([ll(L.point(s.x + (e.x - s.x) * 0.12, s.y + (e.y - s.y) * 0.12)), ll(L.point(e.x + ex, e.y + ey))], { color: DIM_INK, weight: 0.8, opacity: 0.8, interactive: false })));
    }
    add(L.polyline([ll(A2), ll(B2)], halo));
    add(L.polyline([ll(A2), ll(B2)], line));
    add(hit([ll(A2), ll(B2)]));
    tick(A2, dx, dy); tick(B2, dx, dy);
    const mid = ll(L.point((A2.x + B2.x) / 2, (A2.y + B2.y) / 2));
    add(dimLabelMarker(mid, Math.atan2(dy, dx) * 180 / Math.PI, text, () => open(mid)));
  }

  function buildDimPopup(p) {
    const id = p.id;
    return `<div class="dim-popup">
      <div class="fw-semibold mb-1">${esc(fmtDim(p.length_m))}${p.kind === 'route' ? ' <small class="text-muted">along route</small>' : ''}</div>
      <label class="small d-block">Label <small class="text-muted">(replaces the value)</small><input id="dimLabel-${id}" class="form-control form-control-sm" value="${esc(p.label)}" placeholder="e.g. 6.0 m min"></label>
      <label class="small d-block mt-1">Permit<input id="dimPermit-${id}" class="form-control form-control-sm" value="${esc(p.permit_ref)}" placeholder="e.g. NB26-M-060"></label>
      <label class="small d-block mt-1">Work order<input id="dimWor-${id}" class="form-control form-control-sm" value="${esc(p.fpm_wor)}"></label>
      <label class="small d-block mt-1">Offset from line (m, minus = other side)<input id="dimOff-${id}" type="number" step="0.5" class="form-control form-control-sm" value="${p.offset_m}"></label>
      ${actRow([actBtn('save', `onclick="window._dimSave(${id})"`, 'Save')], actBtn('del', `onclick="window._dimDelete(${id})"`, 'Delete', 'Delete this dimension'))}
    </div>`;
  }

  window._dimSave = async function (id) {
    const f = dimFeatures.find(x => x.properties.id === id);
    if (!f) return;
    const v = (k) => document.getElementById(k + '-' + id)?.value ?? '';
    const body = { label: v('dimLabel'), permit_ref: v('dimPermit'), fpm_wor: v('dimWor'), notes: f.properties.notes,
                   offset_m: Number(v('dimOff') || 0) };
    try {
      const r = await (await apiPut(API_DIMENSION + '?id=' + id, body)).json();
      if (!r.ok) throw new Error(r.error || 'save failed');
      Object.assign(f.properties, { label: body.label.trim(), permit_ref: body.permit_ref.trim(), fpm_wor: body.fpm_wor.trim(), offset_m: body.offset_m });
      map.closePopup(); redrawDimensions(); toast('Dimension saved', 'success');
    } catch (err) { toast('Could not save: ' + err.message, 'danger'); }
  };

  window._dimDelete = async function (id) {
    if (!confirm('Delete this dimension?')) return;
    try {
      const r = await (await fetch(API_DIMENSION + '?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': cfg.csrf || '' } })).json();
      if (!r.ok) throw new Error(r.error || 'delete failed');
      dimFeatures = dimFeatures.filter(x => x.properties.id !== id);
      map.closePopup(); redrawDimensions(); toast('Dimension deleted', 'success');
    } catch (err) { toast('Could not delete: ' + err.message, 'danger'); }
  };

  const API_DIMENSION = '/api/dimension.php';

  // Save points as a dimension. `pts` = [{ll, pole}], `path` = full traced path (route kind).
  async function saveDimension(kind, pts, path) {
    const lls = kind === 'route' ? path : [pts[0].ll, pts[pts.length - 1].ll];
    const body = {
      kind, coords: lls.map(l => [l.lng, l.lat]),
      a_pole_fid: pts[0].pole || null, b_pole_fid: pts[pts.length - 1].pole || null,
      // Start it clear of the asset line: about 30 px off at the zoom it was drawn.
      offset_m: Math.round(30 * metersPerPx() * 2) / 2,
    };
    const r = await (await apiPost(API_DIMENSION, body)).json();
    if (!r.ok) throw new Error(r.error || 'save failed');
    toast('Dimension saved', 'success');
    if (!dimsVisible) toggleDims();
    loadFeatures(true);
  }

  // ── Snapping: a point asset (pole first), else a point on the nearest line ────
  function dimSnapAt(ev) {
    const pt = ev.containerPoint, tol = 14;
    let best = null, bestD = tol, isPole = false;
    const scan = (g, pole) => {
      if (!g || !map.hasLayer(g)) return;
      g.eachLayer(l => {
        if (!l.getLatLng || l.getLatLngs) return;
        if (l.options && l.options.interactive === false && !l._poleFid) return;
        const d = pt.distanceTo(map.latLngToContainerPoint(l.getLatLng()));
        if (d < bestD || (pole && !isPole && d < tol)) { best = l; bestD = d; isPole = pole; }
      });
    };
    scan(foscGroup, false); scan(anchorGroup, false); scan(vaultGroup, false); scan(handholeGroup, false);
    scan(buildingAnchorGroup, false); scan(popGroup, false);
    bestD = tol; scan(poleGroup, true);                      // a pole beats anything else in reach
    if (best && isPole) return { ll: best.getLatLng(), pole: best._poleFid || null, snapped: true };
    if (best) return { ll: best.getLatLng(), pole: null, snapped: true };
    return { ll: ev.latlng, pole: null, snapped: false };
  }

  // ── Route tracing: shortest path through the strand and conduit lines ─────────
  function dimRoute(from, to) {
    const lat0 = (from.lat + to.lat) / 2, kx = 111320 * Math.cos(lat0 * Math.PI / 180), ky = 110574;
    const X = (ll) => ({ x: ll.lng * kx, y: ll.lat * ky });
    const key = (q) => Math.round(q.x * 4) + ',' + Math.round(q.y * 4);      // 25 cm
    const lo = L.latLngBounds([from, to]).pad(0.5);
    const pad = 0.005;
    const box = L.latLngBounds([lo.getSouth() - pad, lo.getWest() - pad], [lo.getNorth() + pad, lo.getEast() + pad]);
    const nodes = new Map(), adj = new Map(), segs = [];
    const node = (ll) => { const q = X(ll), k = key(q); if (!nodes.has(k)) { nodes.set(k, { k, q, ll }); adj.set(k, []); } return nodes.get(k); };
    const edge = (a, b, w) => { adj.get(a.k).push([b.k, w]); adj.get(b.k).push([a.k, w]); };
    [strandGroup, conduitGroup].forEach(g => {
      if (!g || !map.hasLayer(g)) return;
      g.eachLayer(l => {
        if (!l.getLatLngs || (l.options && l.options.interactive === false)) return;
        const rings = l.getLatLngs(), flat = Array.isArray(rings[0]) ? rings[0] : rings;
        for (let i = 1; i < flat.length; i++) {
          if (!box.contains(flat[i - 1]) && !box.contains(flat[i])) continue;
          const a = node(flat[i - 1]), b = node(flat[i]);
          if (a.k === b.k) continue;
          const w = Math.hypot(a.q.x - b.q.x, a.q.y - b.q.y);
          edge(a, b, w); segs.push([a, b, w]);
        }
      });
    });
    // Attach an end point to the nearest segment (splitting it there); null if nothing within 20 m.
    const attach = (ll) => {
      const q = X(ll); let best = null, bd = 20;
      segs.forEach(([a, b, w]) => {
        const dx = b.q.x - a.q.x, dy = b.q.y - a.q.y, l2 = dx * dx + dy * dy;
        const t = l2 ? Math.max(0, Math.min(1, ((q.x - a.q.x) * dx + (q.y - a.q.y) * dy) / l2)) : 0;
        const d = Math.hypot(q.x - (a.q.x + t * dx), q.y - (a.q.y + t * dy));
        if (d < bd) { bd = d; best = { a, b, w, t }; }
      });
      if (!best) return null;
      const { a, b, w, t } = best;
      if (t <= 0.001) return a;
      if (t >= 0.999) return b;
      const pll = L.latLng(a.ll.lat + (b.ll.lat - a.ll.lat) * t, a.ll.lng + (b.ll.lng - a.ll.lng) * t);
      const n = node(pll);
      edge(a, n, w * t); edge(n, b, w * (1 - t));
      return n;
    };
    const s = attach(from), e = attach(to);
    if (!s || !e) return null;
    const dist = new Map([[s.k, 0]]), prev = new Map(), done = new Set();
    const queue = [[0, s.k]];
    while (queue.length) {
      queue.sort((x, y) => x[0] - y[0]);
      const [d, k] = queue.shift();
      if (done.has(k)) continue;
      done.add(k);
      if (k === e.k) break;
      (adj.get(k) || []).forEach(([nk, w]) => {
        const nd = d + w;
        if (nd < (dist.has(nk) ? dist.get(nk) : Infinity)) { dist.set(nk, nd); prev.set(nk, k); queue.push([nd, nk]); }
      });
    }
    if (!done.has(e.k)) return null;
    const path = [];
    for (let k = e.k; k !== undefined; k = prev.get(k)) path.unshift(nodes.get(k).ll);
    return path;
  }

  // ── The tool ─────────────────────────────────────────────────────────────────
  function dimToolBarMsg() {
    const t = dimTool;
    const seg = (m, label) => `<button class="btn btn-sm ${t.mode === m ? 'btn-dark' : 'btn-outline-dark'} dim-mode" data-mode="${m}">${label}</button>`;
    const n = t.pts.length;
    return `<b>Dimension</b> ` + seg('straight', 'Straight') + ' ' + seg('route', 'Along route') + ' &nbsp;' +
      (n === 0 ? 'Click the first asset or point' :
       t.mode === 'route' ? `${n} point${n > 1 ? 's' : ''}, click to add; Enter or Save to finish` :
       'Click the second asset or point');
  }

  function dimToolRefresh() {
    const t = dimTool;
    if (t.layer) { t.layer.remove(); t.layer = null; }
    const pts = t.mode === 'route' ? t.path : t.pts.map(x => x.ll);
    if (pts.length >= 2) t.layer = L.polyline(pts, { color: DIM_INK, weight: 2, dashArray: '5 4', interactive: false }).addTo(map);
    const canSave = t.mode === 'route' && t.pts.length >= 2;
    showEditBar(dimToolBarMsg(), canSave ? dimToolFinish : null, stopDimTool, 'Save dimension');
    document.querySelectorAll('.dim-mode').forEach(b => b.addEventListener('click', () => {
      t.mode = b.dataset.mode; t.pts = []; t.path = []; dimToolRefresh();
    }));
  }

  async function dimToolFinish() {
    const t = dimTool;
    if (!t || t.pts.length < 2) return;
    try { await saveDimension(t.mode, t.pts, t.path); stopDimTool(); }
    catch (err) { toast('Could not save: ' + err.message, 'danger'); }
  }

  // A capture-phase listener on the map container, so a click on a pole or closure picks
  // it as an end instead of opening its popup. Controls, popups and the edit bar are left alone.
  function onDimClick(domEv) {
    if (domEv.target.closest && domEv.target.closest('.leaflet-control, .leaflet-popup, #mapEditBar')) return;
    domEv.stopPropagation(); domEv.preventDefault();
    const t = dimTool, s = dimSnapAt({ containerPoint: map.mouseEventToContainerPoint(domEv), latlng: map.mouseEventToLatLng(domEv) });
    if (t.pts.length && t.pts[t.pts.length - 1].ll.equals(s.ll)) return;
    t.pts.push(s);
    if (t.mode === 'route') {
      if (t.pts.length === 1) t.path = [s.ll];
      else {
        const prev = t.pts[t.pts.length - 2].ll;
        const seg = dimRoute(prev, s.ll);
        if (!seg) toast('No strand or conduit path joins those points; used a straight line', 'warning');
        t.path = t.path.concat((seg || [prev, s.ll]).slice(1));
      }
      dimToolRefresh();
    } else if (t.pts.length === 2) {
      dimToolFinish();
    } else {
      dimToolRefresh();
    }
  }

  function onDimMove(e) {
    const t = dimTool, s = dimSnapAt(e);
    if (t.snapRing) { t.snapRing.remove(); t.snapRing = null; }
    if (s.snapped) t.snapRing = L.circleMarker(s.ll, { radius: 9, color: '#0d6efd', weight: 2, fill: false, interactive: false }).addTo(map);
    if (t.rubber) { t.rubber.remove(); t.rubber = null; }
    if (t.pts.length && t.mode === 'straight') {
      const a = t.pts[0].ll;
      t.rubber = L.polyline([a, s.ll], { color: DIM_INK, weight: 1.5, dashArray: '3 4', opacity: 0.7, interactive: false }).addTo(map);
      t.rubber.bindTooltip(fmtDim(a.distanceTo(s.ll)), { permanent: true, className: 'measure-cursor-tip', offset: [10, 0] }).openTooltip(s.ll);
    }
  }

  function onDimKey(e) {
    if (e.key === 'Enter' && dimTool && dimTool.mode === 'route') { e.preventDefault(); dimToolFinish(); }
  }

  function stopDimTool() {
    if (!dimTool) return;
    ['layer', 'snapRing', 'rubber'].forEach(k => { if (dimTool[k]) dimTool[k].remove(); });
    dimTool = null;
    map.getContainer().removeEventListener('click', onDimClick, true);
    map.off('mousemove', onDimMove);
    document.removeEventListener('keydown', onDimKey);
    map.getContainer().style.cursor = '';
    document.getElementById('btnDimension')?.classList.remove('active');
    hideEditBar();
  }

  function toggleDimTool() {
    if (dimTool) { stopDimTool(); return; }
    if (measureActive) clearMeasure();
    if (!dimsVisible) toggleDims();
    dimTool = { mode: 'straight', pts: [], path: [], layer: null, snapRing: null, rubber: null };
    document.getElementById('btnDimension')?.classList.add('active');
    map.getContainer().style.cursor = 'crosshair';
    map.getContainer().addEventListener('click', onDimClick, true);
    map.on('mousemove', onDimMove);
    document.addEventListener('keydown', onDimKey);
    dimToolRefresh();
  }

  // Keep a finished measurement as a dimension (from the measure tool).
  async function keepMeasureAsDimension() {
    if (measurePoints.length < 2) return;
    const pts = measurePoints.map(ll => ({ ll, pole: null }));
    try {
      await saveDimension(measurePoints.length === 2 ? 'straight' : 'route', pts, measurePoints.slice());
      clearMeasure(); hideEditBar();
    } catch (err) { toast('Could not save: ' + err.message, 'danger'); }
  }

  // ── Coordinate picker tool ──────────────────────────────────────────────────
  // Click the map to copy "lat, lon, zoom" (6-dp) to the clipboard and show a
  // popup with the decimal coordinates + current zoom. Used to capture a plan
  // centre point. Esc (or clicking the button again) stops.

  let pickCoordActive = false;

  function onPickCoord(e) {
    const lat = e.latlng.lat, lng = e.latlng.lng, z = map.getZoom();
    const txt = lat.toFixed(6) + ', ' + lng.toFixed(6) + ', ' + z;
    let copied = false;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt);
        copied = true;
      }
    } catch (_) { /* clipboard may be blocked; popup still shows the values */ }
    L.popup({ className: 'coord-pick-popup', autoClose: false })
      .setLatLng(e.latlng)
      .setContent(
        '<div style="font-family:monospace;font-size:.8rem;line-height:1.6">' +
        '<div><b>Lat:</b> ' + lat.toFixed(6) + '</div>' +
        '<div><b>Lon:</b> ' + lng.toFixed(6) + '</div>' +
        '<div><b>Zoom:</b> ' + z + '</div>' +
        '<div style="color:#198754;margin-top:3px">' +
        (copied ? '✓ copied to clipboard' : 'select &amp; copy: ' + txt) + '</div>' +
        '</div>'
      )
      .openOn(map);
  }

  function togglePickCoord() {
    if (pickCoordActive) { stopPickCoord(); return; }
    pickCoordActive = true;
    document.getElementById('btnPickCoord')?.classList.add('measure-done', 'active');
    map.getContainer().style.cursor = 'crosshair';
    map.on('click', onPickCoord);
  }

  function stopPickCoord() {
    pickCoordActive = false;
    map.off('click', onPickCoord);
    map.getContainer().style.cursor = '';
    map.closePopup();
    document.getElementById('btnPickCoord')?.classList.remove('measure-done', 'active');
  }

  // ── Plan view: layer selection, overlay emphasis, and loader ─────────────────
  // Maps plan layer keys → their Leaflet feature groups.
  function planLayerGroups() {
    return {
      poles:    [poleGroup],
      spans:    [spanGroup, spanLabelGroup],
      strands:  [strandGroup],
      conduits: [conduitGroup],
      closures: [foscGroup],
      anchors:  [anchorGroup],
      buildingAnchors: [buildingAnchorGroup],
      drops:    [dropGroup, onuGroup],
      vaults:   [vaultGroup, handholeGroup],
      dimensions: [dimGroup],
    };
  }

  // Show only the requested layer keys; hide all other optional overlays.
  function setPlanLayers(keys) {
    const want = new Set(keys || []);
    const groups = planLayerGroups();
    Object.keys(groups).forEach(function (key) {
      groups[key].forEach(function (g) {
        if (!g) return;
        if (want.has(key)) g.addTo(map);
        else map.removeLayer(g);
      });
    });
  }

  // Thicken overlay polylines so the network "pops" against the greyed basemap.
  // Tracks original weights for restore.
  let _planEmphasisLayers = null;
  function applyPlanEmphasis(on) {
    if (on) {
      if (_planEmphasisLayers) return; // already on
      _planEmphasisLayers = [];
      [spanGroup, strandGroup, conduitGroup, dropGroup].forEach(function (grp) {
        if (!grp) return;
        grp.eachLayer(function (lyr) {
          if (typeof lyr.setStyle !== 'function' || !lyr.options) return;
          // Skip the span/conduit casings. Their width ENCODES fibre count
          // (spanWeight()), so scaling it makes a 96 ct cable print as 144 ct —
          // and it would swallow the overlash lashing ticks, whose length is
          // fixed at render time against the unscaled casing. Casings are the
          // only non-interactive polylines here apart from the ticks, which are
          // decoration and should still be emphasised.
          if (lyr.options.interactive === false && !lyr._tinDecor) return;
          const base = lyr.options.weight || 3;
          _planEmphasisLayers.push([lyr, base]);
          lyr.setStyle({ weight: Math.max(base + 1, Math.round(base * 1.4)) });
        });
      });
    } else {
      if (!_planEmphasisLayers) return;
      _planEmphasisLayers.forEach(function (pair) {
        try { pair[0].setStyle({ weight: pair[1] }); } catch (_) {}
      });
      _planEmphasisLayers = null;
    }
  }
  window.applyPlanEmphasis = applyPlanEmphasis; // used by fpmPlanPrint() in map.php

  // Enter the on-screen plan view for a stored plan (?plan=N) or ad-hoc params.
  // Shows the greyed + emphasized map at the plan's view; the framed title block
  // is rendered into the PDF by fpmPlanPrint() (map.php), which reads
  // window._planTitleData populated here.
  function enterPlanView(plan, titleblock) {
    document.body.classList.add('map-plan-view');
    if (Array.isArray(plan.layers) && plan.layers.length) setPlanLayers(plan.layers);
    // Basemap, fade, print resolution and the work-order filter the plan was
    // framed with — without them the record reopens as this browser's own view
    // of that area, which is what made saved plans look nothing like the sheet.
    applyPlanRenderMeta(plan);
    // Within the detail band a sheet can be rendered at (see clampDetailZoom).
    map.setView([plan.center_lat, plan.center_lon], clampDetailZoom(plan.zoom));
    map.invalidateSize();
    window._planTitleData = { plan: plan, titleblock: titleblock || {} };
    const bar = document.getElementById('planViewBar');
    if (bar) bar.style.display = 'flex';
    // Apply line emphasis once tiles/overlays settle.
    setTimeout(function () { applyPlanEmphasis(true); }, 400);
  }

  function loadPlanFromUrl() {
    if (window._mapPlanId) {
      fetch('/api/map_plan.php?id=' + encodeURIComponent(window._mapPlanId))
        .then(r => r.json())
        .then(d => { if (d.ok && d.plan) enterPlanView(d.plan, d.titleblock); })
        .catch(() => {});
    } else if (window._mapPlanAdhoc) {
      enterPlanView(window._mapPlanAdhoc, window._mapPlanAdhoc.titleblock || {});
    }
  }

  // ── Plan framing mode ────────────────────────────────────────────────────────
  // The Plan button used to print the viewport blind. Printing actually expands
  // the map to window size (100vw × 100vh — see _expandCanvasAndPreload in
  // map.php), so the sheet always covered more ground than the canvas showed, and
  // the title block hid the bottom of it. Framing mode draws that true sheet
  // rectangle, shades the parts the printed furniture will cover, and lets you
  // drag the area you actually want.

  let planFraming      = false;
  // A sheet framed further out than DENSITY_ZOOM prints real assets, not the
  // density hexagons the screen would show at that zoom outside framing. The
  // lock holds the detail regime while the sheet is being captured — on screen
  // for the print dialog, and in the renderer for the whole export.
  let planDetailLock   = false;
  function setPlanDetailLock(on) {
    on = !!on;
    if (planDetailLock === on) return;
    planDetailLock = on;
    return applyZoomRegime(true, on);      // resolves once the assets are drawn (print waits on it)
  }
  let planFrameLayers  = null;   // { mask, outline, title, north }
  let planFrameDragOff = null;   // teardown for the drag handlers

  // The sheet has its OWN centre and zoom, independent of what the map is
  // currently showing. That separation is the whole trick: the sheet is a
  // window-sized pixel region, which is always bigger than the map canvas, so at
  // the sheet's own zoom its outline could never fit on screen. Holding it
  // separately lets the map sit further out and show the whole sheet as a
  // rectangle — the same way a CAD layout view previews a page.
  // A sheet is one page. It is RENDERED at its detail zoom (clampDetailZoom) —
  // the icons, labels and everything else zoom-dependent drawn as they are on
  // screen at that zoom — and then FITTED to the paper: a box dragged wider than
  // the paper holds at that zoom is rendered over its whole area and shrunk to
  // the page (a CSS zoom on the canvas at print time; see _expandCanvasAndPreload
  // in map.php). `fit` is that shrink: paper pixels per canvas pixel, 1 when the
  // area fits the paper as-is. The bigger the area, the smaller the lettering —
  // that is the trade the user makes by dragging a bigger box.
  let planSheet = null;          // { center: L.LatLng, zoom: int, box: {w,h} px at zoom | null }
  let planFramePrevView = null;  // where the map was before framing pulled it back
  const PLAN_MIN_FIT = 0.1;      // A0 at 10 % is ~9 km of ground at z19 — enough

  // Paper pixels per canvas pixel for the current paper and orientation: the
  // largest shrink that gets the box onto the page, never an enlargement.
  function planFit() {
    if (!planSheet || !planSheet.box) return 1;
    const px = window.FPM_SHEET.pxSize();
    return Math.max(PLAN_MIN_FIT, Math.min(1, px.w / planSheet.box.w, px.h / planSheet.box.h));
  }

  // The canvas the sheet is rendered on, in pixels at the sheet zoom: the paper,
  // scaled up by the inverse of the fit. Paper aspect, so the page is filled.
  function planCanvasPx() {
    const px = window.FPM_SHEET.pxSize(), f = planFit();
    return { w: px.w / f, h: px.h / f };
  }

  // Sheet corners, projected at the SHEET's zoom (not the display zoom) and then
  // unprojected — exactly how Leaflet itself converts pixels to coordinates.
  function planSheetBounds() {
    if (!planSheet) return null;
    const px = planCanvasPx();
    const p  = map.project(planSheet.center, planSheet.zoom);
    return L.latLngBounds(
      map.unproject(p.subtract([px.w / 2, px.h / 2]), planSheet.zoom),
      map.unproject(p.add([px.w / 2, px.h / 2]), planSheet.zoom)
    );
  }

  // The dragged box in pixels at the sheet zoom, and the orientation that fits
  // it with the least shrink.
  function planBoxForBounds(bounds) {
    const z = planSheet ? planSheet.zoom : PLAN_DEFAULT_DETAIL_ZOOM;
    const nw = map.project(bounds.getNorthWest(), z), se = map.project(bounds.getSouthEast(), z);
    const box = { w: Math.abs(se.x - nw.x), h: Math.abs(se.y - nw.y) };
    const S = window.FPM_SHEET, mm2px = 96 / 25.4;
    const fitFor = land => Math.min(1,
      (land ? S.pageLongMm : S.pageShortMm) * mm2px / box.w,
      (land ? S.pageShortMm : S.pageLongMm) * mm2px / box.h);
    return { box, landscape: fitFor(true) >= fitFor(false) };
  }

  // Printed furniture, converted from its CSS millimetres to map bounds: the
  // frame is inset 5 mm, the title block is a full-width strip at its bottom, the
  // north arrow a disc 6 mm inside its top-right corner (see #engPlanFrame).
  function planFurnitureBounds() {
    const S    = window.FPM_SHEET;
    const px   = planCanvasPx();
    const land = S.landscape();
    const pageW = land ? S.pageLongMm  : S.pageShortMm;
    const pageH = land ? S.pageShortMm : S.pageLongMm;
    const p  = map.project(planSheet.center, planSheet.zoom);
    const x0 = p.x - px.w / 2, y0 = p.y - px.h / 2;
    const sx = px.w / pageW,   sy = px.h / pageH;   // canvas pixels per paper mm
    const rect = (mmX, mmY, mmW, mmH) => L.latLngBounds(
      map.unproject(L.point(x0 + mmX * sx, y0 + mmY * sy), planSheet.zoom),
      map.unproject(L.point(x0 + (mmX + mmW) * sx, y0 + (mmY + mmH) * sy), planSheet.zoom)
    );
    const i = S.frameInsetMm, pad = 6;
    return {
      title: rect(i, pageH - i - S.titleBlockMm, pageW - 2 * i, S.titleBlockMm),
      north: rect(pageW - i - pad - S.northArrowMm, i + pad, S.northArrowMm, S.northArrowMm),
    };
  }

  // Pull the display back until the whole sheet is comfortably visible.
  function fitMapToSheet() {
    const b = planSheetBounds();
    if (b) map.fitBounds(b, { padding: [50, 70], animate: false });
  }

  function planBoundsRing(b) {
    return [b.getNorthWest(), b.getNorthEast(), b.getSouthEast(), b.getSouthWest()];
  }

  // The dim outside the sheet, as four plain bands (above / below / left / right)
  // rather than one polygon with a hole punched in it.
  //
  // A hole is the obvious way to do this and it is what broke when zooming: the
  // renderer clips each ring to the viewport independently, so as soon as you zoom
  // inside the sheet the hole ring falls outside the clip area and is dropped —
  // leaving the outer ring to fill the screen and black out the whole map. Four
  // rectangles clip correctly on their own and simply fall out of view when you
  // zoom in, which is exactly the wanted behaviour. It also avoids a world-sized
  // ring at ±90°, a Mercator singularity that projects to a nonsense coordinate.
  function planMaskBands(sheet) {
    const clampLat = v => Math.max(-85, Math.min(85, v));
    const outer = map.getBounds().pad(0.6);
    outer.extend(sheet.getNorthWest());
    outer.extend(sheet.getSouthEast());
    const o = outer.pad(0.3);

    const oN = clampLat(o.getNorth()), oS = clampLat(o.getSouth());
    const oW = Math.max(-180, o.getWest()), oE = Math.min(180, o.getEast());
    const sN = sheet.getNorth(), sS = sheet.getSouth();
    const sW = sheet.getWest(),  sE = sheet.getEast();

    const band = (n, s, w, e) =>
      (n > s && e > w) ? [planBoundsRing(L.latLngBounds([s, w], [n, e]))] : null;

    return [
      band(oN, sN, oW, oE),   // above
      band(sS, oS, oW, oE),   // below
      band(sN, sS, oW, sW),   // left
      band(sN, sS, sE, oE),   // right
    ].filter(Boolean);
  }

  function drawPlanFrame() {
    if (!planFraming || !planSheet) return;
    const sheet = planSheetBounds();
    const furn  = planFurnitureBounds();

    if (!planFrameLayers) {
      // Everything outside the sheet is dimmed by one polygon with a hole in it
      // (Leaflet fills with evenodd, so the inner ring reads as a cut-out).
      // 'tin-plan-chrome' keeps the B&W Plan basemap's mono repaint off this
      // editor furniture (see body.map-mono in map.php). It repaints every
      // overlay path white-filled and black-stroked, which turned the dim into an
      // opaque mat that hid the out-of-bounds map entirely.
      const mask = L.polygon(planMaskBands(sheet), {
        stroke: false, fillColor: '#0f172a', fillOpacity: 0.35, interactive: false,
        className: 'tin-plan-chrome',
      }).addTo(map);
      const outline = L.rectangle(sheet, {
        color: '#0d6efd', weight: 2, opacity: 0.95, dashArray: '6,4',
        fill: false, interactive: false, className: 'tin-plan-chrome',
      }).addTo(map);
      const zoneStyle = { color: '#dc2626', weight: 1, opacity: 0.6,
                          fillColor: '#dc2626', fillOpacity: 0.18, interactive: false,
                          className: 'tin-plan-chrome' };
      planFrameLayers = {
        mask, outline,
        title: L.rectangle(furn.title, zoneStyle).addTo(map),
        north: L.rectangle(furn.north, zoneStyle).addTo(map),
      };
      // Say what the shaded boxes are — unlabelled they just read as stray squares.
      const zoneLabel = { permanent: true, direction: 'center', className: 'plan-zone-label' };
      planFrameLayers.title.bindTooltip('title block prints over this', zoneLabel);
      planFrameLayers.north.bindTooltip('north arrow', zoneLabel);
    } else {
      planFrameLayers.mask.setLatLngs(planMaskBands(sheet));
      planFrameLayers.outline.setBounds(sheet);
      planFrameLayers.title.setBounds(furn.title);
      planFrameLayers.north.setBounds(furn.north);
    }
    updatePlanFrameStats(sheet);
  }

  function updatePlanFrameStats() {
    const el = document.getElementById('planFrameStats');
    if (!el || !planSheet) return;
    // Scale comes from the SHEET's zoom, not the display zoom — the display is
    // pulled back so the outline is visible and would otherwise report a lie.
    const fit = planFit();
    const sc = window.fpmPlanScale(planSheet.center.lat, planSheet.zoom, fit);
    const px = planCanvasPx();
    const wM = Math.round(px.w * sc.mPerPx), hM = Math.round(px.h * sc.mPerPx);
    const dim = v => v >= 1000 ? (v / 1000).toFixed(2) + ' km' : v + ' m';
    el.textContent = window.FPM_SHEET.paper() + '   ·   1 : ' + sc.scaleN.toLocaleString() +
                     '   ·   ' + dim(wM) + ' × ' + dim(hM) +
                     (fit < 1 ? '   ·   fitted at ' + Math.round(fit * 100) + '%' : '');
    const zt = document.getElementById('planFrameZoomText');
    if (zt) zt.textContent = 'z' + planSheet.zoom;
    const zi = document.getElementById('planFrameZoomIn'), zo = document.getElementById('planFrameZoomOut');
    if (zi) zi.disabled = planSheet.zoom >= PLAN_MAX_DETAIL_ZOOM;
    if (zo) zo.disabled = planSheet.zoom <= PLAN_MIN_DETAIL_ZOOM;
    const ob = document.getElementById('planFrameOrient');
    if (ob) ob.textContent = window.FPM_SHEET.landscape() ? '↔ Landscape' : '↕ Portrait';
    const sel = document.getElementById('planFramePaper');
    if (sel && sel.value !== window.FPM_SHEET.paper()) sel.value = window.FPM_SHEET.paper();
  }

  // The sheet's zoom is its detail level: icon sizes, which labels exist (pole
  // numbers only from z17), note leaders and the density regime are all
  // zoom-dependent. Icons and lettering are a fixed pixel size, so the deeper
  // the zoom the finer they are against the ground — and a big box fitted to
  // the page shrinks them again. z20 is the look the crew signed off on
  // (measured from a screenshot: 58 m between poles at 515 px); z17 when a
  // large box would otherwise fit the lettering down to nothing. Above z20 the
  // canvas for a fitted sheet passes Chrome's 32k px layer limit.
  const PLAN_MIN_DETAIL_ZOOM = 17, PLAN_MAX_DETAIL_ZOOM = 20, PLAN_DEFAULT_DETAIL_ZOOM = 20;
  const PLAN_DETAIL_KEY = 'fpm_plan_detail_zoom';
  // Basemap for print: 'legible' or 'sharp' (see _planBasemapRes in map.php).
  const PLAN_BASEMAP_KEY = 'fpm_plan_basemap';
  function planBasemapRes() {
    try { return localStorage.getItem(PLAN_BASEMAP_KEY) === 'sharp' ? 'sharp' : 'legible'; } catch (_) { return 'legible'; }
  }
  function syncPlanBasemapBtn() {
    const b = document.getElementById('planFrameBasemap');
    if (b) b.textContent = 'Basemap: ' + (planBasemapRes() === 'sharp' ? 'Sharp' : 'Legible');
  }
  function setPlanBasemapRes(res) {
    try { localStorage.setItem(PLAN_BASEMAP_KEY, res === 'sharp' ? 'sharp' : 'legible'); } catch (_) {}
    syncPlanBasemapBtn();
  }
  function togglePlanBasemapRes() {
    setPlanBasemapRes(planBasemapRes() === 'sharp' ? 'legible' : 'sharp');
  }
  function planLastDetailZoom() {
    try { return clampDetailZoom(parseInt(localStorage.getItem(PLAN_DETAIL_KEY), 10)); } catch (_) { return PLAN_DEFAULT_DETAIL_ZOOM; }
  }
  const clampDetailZoom = z => Math.max(PLAN_MIN_DETAIL_ZOOM, Math.min(PLAN_MAX_DETAIL_ZOOM, Math.round(z) || PLAN_DEFAULT_DETAIL_ZOOM));
  // Step the sheet's zoom, keeping its ground area: the box is in pixels at the
  // sheet zoom, so it scales by 2 per notch.
  function setPlanSheetZoom(z) {
    if (!planFraming || !planSheet) return;
    z = clampDetailZoom(z);
    if (z === planSheet.zoom) return;
    if (planSheet.box) {
      const k = Math.pow(2, z - planSheet.zoom);
      planSheet.box = { w: planSheet.box.w * k, h: planSheet.box.h * k };
    }
    planSheet.zoom = z;
    try { localStorage.setItem(PLAN_DETAIL_KEY, String(z)); } catch (_) {}
    fitMapToSheet();
    drawPlanFrame();
  }
  function setPlanPaper(name) {
    if (!window.fpmSetPrintPaper || !window.fpmSetPrintPaper(name)) return;
    if (!planFraming || !planSheet) return;
    fitMapToSheet();
    drawPlanFrame();
  }

  // Drag a box → fit it to the sheet; click → re-centre. Mirrors the road-coverage
  // box-select drag (startRoadCovBoxSelect) rather than reinventing it.
  function attachPlanFrameDrag() {
    const container = map.getContainer();
    let start = null, startPx = null, rect = null;

    function onDown(ev) {
      if (ev.button !== 0) return;
      ev.preventDefault();
      start   = map.mouseEventToLatLng(ev);
      startPx = L.point(ev.clientX, ev.clientY);
      rect = L.rectangle([start, start], {
        color: '#0d6efd', weight: 1.5, opacity: 0.9,
        fillColor: '#0d6efd', fillOpacity: 0.10, interactive: false,
      }).addTo(map);
      container.addEventListener('mousemove', onMove);
      container.addEventListener('mouseup', onUp);
    }
    function onMove(ev) {
      if (rect) rect.setBounds(L.latLngBounds(start, map.mouseEventToLatLng(ev)));
    }
    function onUp(ev) {
      container.removeEventListener('mousemove', onMove);
      container.removeEventListener('mouseup', onUp);
      const moved = startPx ? startPx.distanceTo(L.point(ev.clientX, ev.clientY)) : 0;
      const bounds = rect ? rect.getBounds() : null;
      if (rect) { map.removeLayer(rect); rect = null; }

      if (moved < 5 || !bounds || !bounds.isValid()) {
        planSheet.center = map.mouseEventToLatLng(ev);   // a click moves the sheet
        drawPlanFrame();
      } else {
        // The box becomes the sheet's area, rendered at full detail and fitted
        // to the paper (see planSheet). It used to zoom the sheet out until the
        // box fitted, which quietly dropped the drawing below the pole-number
        // threshold. The orientation follows the box's shape.
        const g = planBoxForBounds(bounds);
        if (window.fpmSetPrintOrientation) window.fpmSetPrintOrientation(g.landscape);
        planSheet = { center: bounds.getCenter(), zoom: planSheet.zoom, box: g.box };
        fitMapToSheet();
        drawPlanFrame();
      }
    }

    container.addEventListener('mousedown', onDown);
    planFrameDragOff = function () {
      container.removeEventListener('mousedown', onDown);
      container.removeEventListener('mousemove', onMove);
      container.removeEventListener('mouseup', onUp);
      if (rect) { map.removeLayer(rect); rect = null; }
    };
  }

  function startPlanFraming() {
    if (planFraming) { exitPlanFraming(); return; }
    cancelActiveEdit();
    setToolbarPanel(null);
    planFraming = true;
    document.body.classList.add('map-framing');
    // Seed the sheet from what you were looking at, then pull the display back so
    // the whole sheet outline is on screen (it is bigger than the canvas, so at
    // the sheet's own zoom you would see none of it).
    planSheet = { center: map.getCenter(), zoom: planLastDetailZoom(), box: null };
    planFramePrevView = { center: map.getCenter(), zoom: map.getZoom() };
    map.dragging.disable();          // drag draws the box; click moves the sheet
    attachPlanFrameDrag();
    // Only after the view settles. The frame is fixed geography, so Leaflet's own
    // pane transform carries it correctly through a zoom animation; rewriting the
    // geometry on every animation frame fought that transform and made the overlay
    // jump and smear. The redraw is just to re-cut the viewport-tracking mask.
    map.on('moveend zoomend', drawPlanFrame);
    fitMapToSheet();
    applyZoomRegime(true);           // reload assets for the wider view
    drawPlanFrame();
  }

  // restoreView=false when the caller is about to set its own view (printing).
  function exitPlanFraming(restoreView = true) {
    if (!planFraming) return;
    planFraming = false;
    document.body.classList.remove('map-framing');
    map.off('moveend zoomend', drawPlanFrame);
    if (planFrameDragOff) { planFrameDragOff(); planFrameDragOff = null; }
    if (planFrameLayers) {
      Object.keys(planFrameLayers).forEach(k => map.removeLayer(planFrameLayers[k]));
      planFrameLayers = null;
    }
    map.dragging.enable();
    const list = document.getElementById('planFrameSavedList');
    if (list) list.style.display = 'none';
    if (restoreView && planFramePrevView) {
      map.setView(planFramePrevView.center, planFramePrevView.zoom, { animate: false });
    }
    planFramePrevView = null;
  }

  // ── Saving a framing as a reusable plan ──────────────────────────────────────
  // app.map_plan already models a plan as centre + zoom + orientation, so a
  // framing is saved as-is. entity_id must be non-empty, so map-authored plans
  // share one bucket ('map') and list together.
  const ADHOC_PLAN_BUCKET = { entity_type: 'adhoc', entity_id: 'map' };

  // A plan framed here usually belongs to a job, not to the map's own list, so
  // the save modal offers the work orders as targets —
  // the same buckets their Site Plans cards read. Filled once, on first open.
  const planTargetSel = () => document.getElementById('planSaveTarget');
  function planSaveTarget() {
    const v = (planTargetSel()?.value || 'adhoc|map').split('|');
    return { entity_type: v[0] || 'adhoc', entity_id: v.slice(1).join('|') || 'map' };
  }
  function planSaveTargetLabel() {
    const sel = planTargetSel();
    return sel && sel.selectedIndex > 0 ? sel.options[sel.selectedIndex].text : '';
  }
  let planTargetsLoaded = false;
  async function loadPlanSaveTargets() {
    const sel = planTargetSel();
    if (!sel || planTargetsLoaded) return;
    planTargetsLoaded = true;
    const add = (group, value, text) => {
      const o = document.createElement('option');
      o.value = value; o.textContent = text;
      group.appendChild(o);
    };
    try {
      const d = await (await fetch('/api/fpm_permits_list.php')).json();
      const permits = (d.permits || d.rows || []).map(x => (typeof x === 'string' ? x : x.permit_num)).filter(Boolean);
      const gone = new Set(d.abandoned || []);
      if (permits.length) {
        const g = document.createElement('optgroup');
        g.label = ((window.ORG && window.ORG.woLabel) || 'Work Order') + 's';
        permits.forEach(pn => add(g, 'fpm_permit|' + pn, pn + (gone.has(pn) ? ' (abandoned)' : '')));
        sel.appendChild(g);
      }
    } catch (_) {}
  }

  // Current layer visibility expressed in map_plan's key vocabulary, so reopening
  // the plan restores the same picture via setPlanLayers().
  function currentPlanLayerKeys() {
    const on = {
      poles: polesVisible, spans: spansVisible, strands: strandsVisible,
      conduits: conduitsVisible, closures: closuresVisible, anchors: anchorsVisible,
      buildingAnchors: buildingAnchorsVisible, drops: onuVisible,
      vaults: vaultsVisible || handholesVisible,
    };
    return Object.keys(on).filter(k => on[k]);
  }

  // Everything about the drawing that map_plan's own columns don't model, so
  // reopening the plan reproduces the sheet rather than just its area: the
  // paper, how far it is shrunk, which basemap at what fade and print
  // resolution, and the filter deciding which assets are on it.
  function currentPlanRenderMeta() {
    // What is on the map, not what is stored: plan view applies a plan's own
    // basemap without recording it as this browser's choice.
    let basemap = window.fpmActiveBasemap ? window.fpmActiveBasemap() : null;
    if (!basemap) { try { basemap = localStorage.getItem(MAP_BASEMAP_KEY); } catch (_) {} }
    return {
      paper:       window.FPM_SHEET.paper(),
      fit:         Number(planFit().toFixed(4)),
      basemap:     basemap || null,
      fade:        (window.fpmBasemapFade ? Number(window.fpmBasemapFade().toFixed(2)) : null),
      basemap_res: planBasemapRes(),
      filter:      mapFilterState(),
    };
  }

  async function savePlanFraming() {
    const input = document.getElementById('planSaveTitle');
    const title = (input?.value || '').trim();
    if (!title) { showErr(document.getElementById('planSaveError'), 'Give the plan a title.'); return; }
    const c = planSheet ? planSheet.center : map.getCenter();
    const target = planSaveTarget();
    try {
      const resp = await apiPost('/api/map_plan.php', Object.assign({}, target, {
        title,
        center_lat:  c.lat,
        center_lon:  c.lng,
        zoom:        planSheet ? planSheet.zoom : map.getZoom(),
        orientation: window.FPM_SHEET.landscape() ? 'landscape' : 'portrait',
        layers:      currentPlanLayerKeys(),
        meta:        currentPlanRenderMeta(),
      }));
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('planSaveModal'))?.hide();
        toast(target.entity_type === 'adhoc' ? 'Plan saved'
              : 'Plan saved to ' + (planSaveTargetLabel() || 'record'), 'success');
        if (target.entity_type === 'adhoc') loadSavedPlans(true);
      } else {
        showErr(document.getElementById('planSaveError'), data.error || 'Save failed');
      }
    } catch (e) {
      showErr(document.getElementById('planSaveError'), 'Error: ' + e.message);
    }
  }

  async function loadSavedPlans(keepOpen) {
    const list = document.getElementById('planFrameSavedList');
    if (!list) return;
    if (!keepOpen && list.style.display !== 'none') { list.style.display = 'none'; return; }
    list.style.display = '';
    list.innerHTML = '<div class="text-muted small">Loading…</div>';
    try {
      const qs = '?entity_type=' + ADHOC_PLAN_BUCKET.entity_type +
                 '&entity_id='   + ADHOC_PLAN_BUCKET.entity_id;
      const data = await (await fetch('/api/map_plan.php' + qs)).json();
      const plans = (data.ok && data.plans) ? data.plans : [];
      if (!plans.length) { list.innerHTML = '<div class="text-muted small">No saved plans yet.</div>'; return; }
      list.innerHTML = plans.map(p =>
        `<div class="plan-saved-row" data-plan="${p.plan_id}">` +
          `<span class="psr-title">${esc(p.title)}</span>` +
          `<span class="psr-meta">${planMetaFit(p).paper || ''} ${p.orientation === 'portrait' ? '↕' : '↔'} z${p.zoom}${planMetaFit(p).fit < 1 ? ' · ' + Math.round(planMetaFit(p).fit * 100) + '%' : ''}</span>` +
          `<button class="btn btn-sm btn-outline-secondary py-0" data-act="goto" title="Frame this area here">Go to</button>` +
          `<a class="btn btn-sm btn-outline-primary py-0" href="/index.php?page=map&plan=${p.plan_id}" target="_blank" title="Open the framed plan view">Open</a>` +
          `<button class="btn btn-sm btn-outline-danger py-0" data-act="del" title="Delete plan">🗑</button>` +
        `</div>`).join('');
      list.querySelectorAll('.plan-saved-row').forEach(row => {
        const p = plans.find(x => String(x.plan_id) === row.dataset.plan);
        row.querySelector('[data-act="goto"]')?.addEventListener('click', function () {
          if (window.FPM_SHEET.landscape() !== (p.orientation !== 'portrait')) togglePlanOrientation();
          const g = applyPlanRenderMeta(p);
          planSheet = { center: L.latLng(p.center_lat, p.center_lon), zoom: clampDetailZoom(p.zoom), box: planBoxForFit(g.fit) };
          fitMapToSheet();
          drawPlanFrame();
        });
        row.querySelector('[data-act="del"]')?.addEventListener('click', async function () {
          if (!confirm('Delete plan "' + p.title + '"?')) return;
          const d = await (await apiDelete('/api/map_plan.php?id=' + p.plan_id)).json();
          d.ok ? (toast('Plan deleted', 'success'), loadSavedPlans(true))
               : toast('Delete failed: ' + (d.error || 'unknown'), 'danger');
        });
      });
    } catch (e) {
      list.innerHTML = '<div class="text-danger small">Failed to load: ' + esc(e.message) + '</div>';
    }
  }

  // The render spec a plan was saved with (see currentPlanRenderMeta). Older
  // plans carry only paper/fit, or nothing at all, so every part is optional and
  // anything missing is simply left as the user has it.
  function applyPlanRenderMeta(p, opts) {
    const m = (p && p.meta && typeof p.meta === 'object' && !Array.isArray(p.meta)) ? p.meta : {};
    const g = planMetaFit(p);
    if (g.paper && window.fpmSetPrintPaper) window.fpmSetPrintPaper(g.paper);
    if (m.basemap || m.fade != null) window.fpmApplyBasemap?.(m.basemap, m.fade);
    if (m.basemap_res && window.fpmSetPlanBasemapRes) {
      window.fpmSetPlanBasemapRes(m.basemap_res);
      setPlanBasemapRes(m.basemap_res);
    }
    // The filter decides which assets are drawn at all, so a plan that was
    // framed on one work order must not reopen showing the whole network.
    if (m.filter && (!opts || opts.filter !== false)) applyMapFilterState(m.filter);
    return g;
  }

  // Paper and fit of a saved plan, from its meta; fit 1 when absent.
  function planMetaFit(p) {
    const m = (p && p.meta && typeof p.meta === 'object') ? p.meta : {};
    const f = parseFloat(m.fit);
    const paper = (window.FPM_SHEET && window.FPM_SHEET.papers[m.paper]) ? m.paper : null;
    return { fit: (isFinite(f) && f > 0 && f < 1) ? Math.max(PLAN_MIN_FIT, f) : 1, paper };
  }
  // A box that reproduces a stored fit on the current paper (null at 1: the
  // sheet is then just the paper).
  function planBoxForFit(fit) {
    if (!(fit < 1)) return null;
    const px = window.FPM_SHEET.pxSize();
    return { w: px.w / fit, h: px.h / fit };
  }

  function togglePlanOrientation() {
    if (window.togglePrintOrient) window.togglePrintOrient();
    drawPlanFrame();
  }

  window.fpmStartPlanFraming = startPlanFraming;

  // The plan PDF is rendered in a separate headless browser, which has none of
  // this browser's localStorage — so a work-order filter that narrows the map on
  // screen would silently render the whole network. These two carry the filter
  // over: the button sends the state with the request, the renderer applies it.
  function mapFilterState() {
    return {
      values:        Object.assign({}, poleFilterValues),
      hasWO:         !!poleFilterHasWO,
      hasBellPermit: !!poleFilterHasBellPermit,
      roadHidden:    Array.from(rcComboHidden),
      layers:        layerState(),
      addBack:       addBackChecked(),
    };
  }
  function applyMapFilterState(st) {
    if (!st || typeof st !== 'object') return false;
    const values = st.values && typeof st.values === 'object' ? st.values : {};
    // Through the panel inputs, the same way restorePoleFilterState() does, so
    // applyPoleFilter() reads exactly what a user would have typed.
    document.querySelectorAll('#poleFilterPanel input[data-field]').forEach(function (inp) {
      const v = values[inp.dataset.field];
      inp.value = typeof v === 'string' ? v : '';
    });
    const woChk = document.getElementById('pfHasWO');
    if (woChk) woChk.checked = !!st.hasWO;
    const bpChk = document.getElementById('pfHasBellPermit');
    if (bpChk) bpChk.checked = !!st.hasBellPermit;
    const ab = Array.isArray(st.addBack) ? st.addBack : [];
    document.querySelectorAll('#pfAddBack input[data-addback]').forEach(i => { i.checked = ab.includes(i.dataset.addback); });
    applyPoleFilter();

    rcComboHidden.clear();
    (Array.isArray(st.roadHidden) ? st.roadHidden : []).forEach(k => { if (typeof k === 'string') rcComboHidden.add(k); });
    applyRoadTypeFilter();
    return true;
  }
  window.fpmMapFilterState      = mapFilterState;
  window.fpmApplyMapFilterState = applyMapFilterState;
  window.fpmSetPlanDetailLock   = setPlanDetailLock;

  // Called by _preparePlanSheet (map.php) once the canvas is pinned to the paper.
  // The map was loaded for a screen-sized view; an A0 at z19 is far bigger, so
  // poles past the old viewport are not fetched yet and pole numbers are bound
  // only for the old view (refreshPoleLabels binds what is on screen). The
  // pan/zoom handler catches up 350 ms after the resize, but the PDF export only
  // waits for tiles — and took the sheet with no labels and a bare edge. This
  // does that catch-up now, unconditionally, and resolves when the sheet is
  // complete so the export can wait on it.
  async function settleSheet() {
    clearTimeout(_viewportReloadTimer);
    await loadFeatures(true);        // full sheet bounds; renderAll rebinds labels
    refreshPoleLabels();
    refreshZoomGeometry();
    // Past the fade-out of the labels renderAll replaced, so the sweep sees only
    // what will print.
    await new Promise(r => setTimeout(r, 250));
    clearTimeout(_declutterTimer);
    applyLabelDeclutter();
    return true;
  }
  window.fpmSettleSheet = settleSheet;


  // ── Conduit rendering ────────────────────────────────────────────────────────

  // ── Conduit terminations ─────────────────────────────────────────────────────
  // Where a conduit starts or stops when it is not at a vault or handhole (building entry, pole riser,
  // closure, POP). A plain small marker, hardly wider than the conduit; unverified ones are hollow amber
  // so they stand out until someone gives them a kind.
  const TERM_KINDS = { building: 'Building entry', pole: 'Pole riser', closure: 'Closure', pop: 'POP', unverified: 'Unverified' };

  function renderTermination(feature) {
    if (!feature.geometry) return;
    const p = feature.properties;
    const [lon, lat] = feature.geometry.coordinates;
    const unverified = p.kind === 'unverified';
    // Above the conduit and cable casings, which are thicker than the marker and would hide it.
    if (!map.getPane('termPane')) { map.createPane('termPane'); map.getPane('termPane').style.zIndex = 455; }
    const m = L.circleMarker([lat, lon], {
      pane: 'termPane', radius: 4, weight: 1.5, color: unverified ? '#d97706' : '#1f2937',
      fillColor: unverified ? '#fff7ed' : '#ffffff', fillOpacity: 1, opacity: 0.95,
    });
    m._tinTerm = p;
    m.bindTooltip((TERM_KINDS[p.kind] || p.kind) + (p.label ? ' · ' + p.label : ''), { direction: 'top' });
    m.bindPopup(() => buildTerminationPopup(p), { minWidth: 220 });
    m.on('popupopen', function () {
      const id = p.termination_id;
      document.getElementById('term-save-' + id)?.addEventListener('click', async function () {
        const body = {
          kind: document.getElementById('term-kind-' + id).value,
          label: document.getElementById('term-label-' + id).value,
          owner: p.owner, fpm_wor: p.fpm_wor, notes: p.notes,
        };
        try {
          const d = await (await apiPut(API.termination + '?id=' + id, body)).json();
          if (!d.ok) throw new Error(d.error || 'save failed');
          m.closePopup(); toast('Termination saved', 'success'); loadFeatures(true);
        } catch (e) { toast(e.message, 'danger'); }
      });
      document.getElementById('term-del-' + id)?.addEventListener('click', async function () {
        if (!confirm('Delete this termination?')) return;
        try {
          const d = await (await apiDelete(API.termination + '?id=' + id)).json();
          if (!d.ok) throw new Error(d.error || 'delete failed');
          m.closePopup(); toast('Termination deleted', 'success'); loadFeatures(true);
        } catch (e) { toast(e.message, 'danger'); }
      });
    });
    conduitGroup.addLayer(m);
  }

  function buildTerminationPopup(p) {
    const id = p.termination_id;
    const opts = Object.entries(TERM_KINDS).map(([k, v]) => `<option value="${k}"${p.kind === k ? ' selected' : ''}>${v}</option>`).join('');
    return `<div>
      <div class="fw-semibold mb-1">Conduit termination</div>
      <div class="small text-muted mb-1">${p.conduits ? 'Conduit ' + esc(p.conduits) + ' ends here' : 'No conduit ends here'}${p.fpm_wor ? ' &middot; WO ' + esc(p.fpm_wor) : ''}</div>
      <select class="form-select form-select-sm mb-1" id="term-kind-${id}">${opts}</select>
      <input class="form-control form-control-sm mb-1" id="term-label-${id}" value="${esc(p.label || '')}" placeholder="Label (building, pole #…)" maxlength="80">
      ${actRow([actBtn('edit', `id="term-save-${id}"`, 'Save')], actBtn('del', `id="term-del-${id}"`, 'Delete', 'Delete termination'))}
    </div>`;
  }

  function renderConduit(feature, bounds) {
    if (!feature.geometry) return;
    const p      = feature.properties;
    const coords = feature.geometry.coordinates.map(c => [c[1], c[0]]);
    // Bright orange so conduit reads clearly against the grey basemap / plan
    // view (the status brown blended in). Status is still shown via dash + popup.
    const color  = p.status === 'retired' ? '#c2410c' : '#fb8500';

    // Conduit is always drawn where it is laid — it has no relationship to the pole
    // line, so it is never hidden or collapsed onto a strand.

    // Occupied duct: orange casing with a black cable core sized by fibre count,
    // so orange shows down both edges — the duct reads as "cable inside" rather
    // than as an empty run. Drawn first so the click target stays on top.
    const cableF = p.cable_fibre_count || 0;
    let decorLayers = [];
    if (cableF) {
      const coreW = spanWeight(cableF);
      // Tag the duct casing + core with _tinConduit so the pole/WO filter hides them
      // with their conduit (they live in strandGroup, which the filter otherwise
      // only scans for _tinStrand).
      const ductCasing = L.polyline(coords, { color, weight: coreW + 6, opacity: 0.95, interactive: false, className: 'tin-casing' });
      const ductCore   = L.polyline(coords, { color: '#111827', weight: coreW, opacity: 1, interactive: false, className: 'tin-core' });
      ductCasing._tinConduit = p; ductCasing._tinDecor = true;
      ductCore._tinConduit   = p; ductCore._tinDecor   = true;
      // Remembered so vertex editing can hide them while the route is being
      // dragged (they sit at the old coords and would visually detach).
      ductCasing._tinDecorOpacity = 0.95;
      ductCore._tinDecorOpacity   = 1;
      decorLayers = [ductCasing, ductCore];
      strandGroup.addLayer(ductCasing);
      strandGroup.addLayer(ductCore);
    }

    const line = L.polyline(coords, {
      color,
      weight:    cableF ? spanWeight(cableF) + 6 : 5,
      opacity:   cableF ? 0 : 0.95,
      dashArray: null,
    });

    line._tinConduit      = p;
    line._tinDecorLayers  = decorLayers;
    line.bindPopup(() => buildConduitPopup(p), { minWidth: 190 });
    registerLineFeat('conduit', p.conduit_id, line);
    enableRouteChooser(line, 'conduit', p.conduit_id);

    // Hover reveals the vertex handles; clicking the line body still opens the
    // popup / route chooser — only Geoman's own handles take a drag.
    line.on('mouseover', function () { armConduitVertices(line, p); });

    line.on('popupopen', function () {
      document.getElementById('edit-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          openConduitModalForEdit(p);
        });
      document.getElementById('dim-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          const rings = line.getLatLngs(), flat = Array.isArray(rings[0]) ? rings[0] : rings;
          saveDimension(flat.length === 2 ? 'straight' : 'route', [{ ll: flat[0], pole: null }, { ll: flat[flat.length - 1], pole: null }], flat.slice())
            .catch(err => toast('Could not save: ' + err.message, 'danger'));
        });
      document.getElementById('del-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () {
          line.closePopup();
          deleteConduit(p.conduit_id, line);
        });
      document.getElementById('move-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () { line.closePopup(); startMoveConduit(line, p); });
      document.getElementById('split-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () { line.closePopup(); startSplitConduit(p); });
      document.getElementById('join-conduit-' + p.conduit_id)
        ?.addEventListener('click', function () { line.closePopup(); startJoinConduit(p); });
    });

    conduitGroup.addLayer(line);
    // Aerial conduit: microduct lashed to the strand. Same orange as buried conduit, with lashing ticks
    // across it (blue ticks are Bell overlash), so it reads as "on the strand" and never as buried.
    if (p.installation === 'aerial') {
      const halfH = cableF ? Math.max(6, spanWeight(cableF) / 2 + 5) : 6;
      const ticks = map.getZoom() >= LASH_TICK_ZOOM ? lashTicks(coords, halfH, 14, 0.625) : [];
      const tickLayer = L.polyline(ticks, { color: '#c2410c', weight: 1.6, opacity: 0.95, interactive: false });
      tickLayer._tinConduit = p; tickLayer._tinDecor = true; tickLayer._baseOpacity = 0.95;
      tickLayer._tinZoomGeom = { coords, halfH };
      _lashTickLayers.push(tickLayer);
      conduitGroup.addLayer(tickLayer);
    }
    // An occupied duct gets the same fibre-count label a span does, so you can
    // see what's inside it without opening the popup.
    renderSpanLabels(coords, p.cable_fibre_count, p.fpm_wor, p.cable_name);
    renderConduitLengthLabel(coords, p);
    coords.forEach(c => bounds.push(c));
  }

  // A measured conduit length is a fact the drawn geometry cannot carry: the duct
  // to FRD1122 is drawn 21.5 m and is 27 m in the ground, because the route bends
  // where nobody surveyed it. Where that number has been recorded, put it on the
  // map — otherwise it sits in the popup and whoever is reading the drawing has no
  // reason to open it, which is why it ends up re-recorded as a map note instead.
  //
  // Only the MEASURED length gets a label. The drawn length is already implicit in
  // the line, and labelling both would put two numbers on one duct with nothing on
  // screen to say which is which.
  //
  // Lives in spanLabelGroup, which already carries this conduit's fibre-count
  // label: same zoom gate, same work-order filter, same clear on re-render. It
  // sits below the centreline, the fibre count above it.
  function renderConduitLengthLabel(coords, p) {
    if (p.known_length_m == null) return;

    // A quarter of the way along, below the centreline. The fibre count sits at the
    // midpoint above it, and both are rotated to the cable — with the length at the
    // midpoint too, the two rotated boxes overlapped and the declutter pass dropped
    // this one every time.
    const g = pointAlongGeom(coords, 0.25);
    if (!g) return;
    const angle = spanLabelAngle(g);
    if (angle === null) return;

    const tol  = p.known_length_tol_m ? ' \u00b1' + trimNum(p.known_length_tol_m) : '';
    const text = trimNum(p.known_length_m) + tol + ' m';

    const marker = L.marker(spanLabelAnchor(g, -1), {
      icon: L.divIcon({
        html: `<div class="conduit-len-label" style="transform:translate(-50%,-50%) rotate(${angle}deg)">${text}</div>`,
        className: '',
        iconSize:   [0, 0],
        iconAnchor: [0, 0],
      }),
      interactive: false,
      keyboard:    false,
    });
    marker._tinLabelWor  = p.fpm_wor || '';   // hides with its conduit under the WO filter
    marker._tinZoomGeom  = g;                    // the anchor is in px, so it is re-derived on zoom
    marker._tinLabelSide = -1;
    _spanLabelMarkers.push(marker);
    spanLabelGroup.addLayer(marker);
  }

  // A duct's drawn line is a plan, not a tape measure: into a building the real
  // pull runs deeper, bends and rises, so it is routinely longer than the map says.
  // Show the measured figure when someone recorded one — with its ± where given —
  // and label the drawn one as drawn so the two are never mistaken for each other.
  function conduitLengthLine(p) {
    const drawn = p.geom_length_m != null ? `${Math.round(p.geom_length_m)}\u202fm drawn` : '';
    if (p.known_length_m == null) {
      return drawn ? `<br><small class="text-muted">Length: ${drawn}</small>` : '';
    }
    const tol   = p.known_length_tol_m ? ` ±\u202f${trimNum(p.known_length_tol_m)}\u202fm` : '';
    const known = `<strong>${trimNum(p.known_length_m)}\u202fm</strong>${tol} measured`;
    return `<br><small class="text-muted">Length: ${known}` +
           (drawn ? ` <span style="opacity:.7">(${drawn})</span>` : '') + `</small>`;
  }

  // 27.00 → "27", 27.50 → "27.5" — these are paced or taped figures, and trailing
  // zeroes imply a precision they do not have.
  function trimNum(v) {
    const n = Number(v);
    return Number.isFinite(n) ? String(parseFloat(n.toFixed(2))) : String(v);
  }

  // Delete confirmation that also says what the asset's timeline holds: its notes and photos go with it
  // and cannot be brought back from the map. Falls back to the plain question if the count can't be read.
  async function confirmDeleteAsset(type, id, question) {
    let n = null;
    try {
      const d = await (await fetch(`api/timeline.php?summary=1&parent_type=${type}&parent_id=${id}`)).json();
      if (d.ok) n = d;
    } catch (_) {}
    if (!n || !n.entries) return confirm(question);
    const parts = [n.entries + (n.entries === 1 ? ' note' : ' notes')];
    if (n.photos) parts.push(n.photos + (n.photos === 1 ? ' photo' : ' photos'));
    return confirm('\u26A0 WARNING\n\nThis has ' + parts.join(' and ') + ' in its timeline. Deleting it permanently deletes them too, and the photos cannot be recovered.\n\n' + question);
  }

  // ── Notes and photos on a conduit or vault (app.timeline_entry, parent_type conduit / vault) ──
  const tlBox = (type, id) => `<div class="tl-box" data-pt="${type}" data-pid="${id}"></div>`;

  // Full-size photo over the page; arrows / keys step through the asset's photos, Esc or a click outside closes.
  function tlLightbox(items, idx) {
    const ov = document.createElement('div');
    ov.className = 'tl-lb';
    const show = () => {
      const it = items[idx];
      ov.innerHTML = `<button type="button" class="tl-lb-x" title="Close (Esc)">&times;</button>
        ${items.length > 1 ? '<button type="button" class="tl-lb-nav tl-lb-prev" title="Previous">&#8249;</button><button type="button" class="tl-lb-nav tl-lb-next" title="Next">&#8250;</button>' : ''}
        <img src="media.php?id=${it.id}" alt="">
        <div class="tl-lb-cap">${esc(it.cap)}${items.length > 1 ? ` &middot; ${idx + 1} / ${items.length}` : ''}</div>`;
    };
    const step = d => { idx = (idx + d + items.length) % items.length; show(); };
    const close = () => { ov.remove(); document.removeEventListener('keydown', onKey, true); };
    const onKey = e => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); }
      else if (e.key === 'ArrowLeft' && items.length > 1) step(-1);
      else if (e.key === 'ArrowRight' && items.length > 1) step(1);
    };
    ov.addEventListener('click', e => {
      if (e.target.closest('.tl-lb-prev')) step(-1);
      else if (e.target.closest('.tl-lb-next')) step(1);
      else if (e.target.tagName !== 'IMG') close();
    });
    document.addEventListener('keydown', onKey, true);
    show();
    document.body.appendChild(ov);
  }

  async function tlLoad(box) {
    const pt = box.dataset.pt, pid = box.dataset.pid;
    box.innerHTML = '<small class="text-muted">Loading notes…</small>';
    let entries = [];
    try {
      const d = await (await fetch(`api/timeline.php?parent_type=${pt}&parent_id=${pid}`)).json();
      if (!d.ok) throw new Error(d.error || 'Failed');
      entries = d.entries;
    } catch (err) { box.innerHTML = `<small class="text-danger">Notes: ${esc(err.message)}</small>`; return; }

    const shown = box.dataset.all ? entries : entries.slice(0, 3);
    const rows = shown.map(en => `<div class="tl-entry" data-eid="${en.id}">
        <div class="tl-when">${esc(en.at)}<button type="button" class="tl-del" title="Delete this note" data-eid="${en.id}">&times;</button></div>
        ${en.text ? `<div class="tl-text">${esc(en.text)}</div>` : ''}
        ${en.photos.length ? `<div class="tl-pics">${en.photos.map(m => `<a href="media.php?id=${m}" data-pid="${m}" data-cap="${esc(en.at + (en.text ? ' — ' + en.text.slice(0, 120) : ''))}"><img loading="lazy" src="media.php?id=${m}&w=160" alt=""></a>`).join('')}</div>` : ''}
      </div>`).join('');
    const more = entries.length > shown.length ? `<a href="#" class="tl-more">Show all ${entries.length}</a>` : '';
    box.innerHTML = `<div class="tl-head">Timeline</div>${rows || '<small class="text-muted">No notes or photos yet.</small>'}${more}
      <form class="tl-form"><textarea name="body_text" rows="2" placeholder="Note…"></textarea>
        <div class="tl-formrow"><input type="file" name="images[]" accept="image/*" multiple>
        <button type="submit" class="btn btn-sm btn-primary">Add</button></div><small class="tl-msg"></small></form>`;

    const pics = [...box.querySelectorAll('.tl-pics a')];
    const items = pics.map(a => ({ id: a.dataset.pid, cap: a.dataset.cap }));
    pics.forEach((a, i) => a.addEventListener('click', ev => { ev.preventDefault(); tlLightbox(items, i); }));
    box.querySelector('.tl-more')?.addEventListener('click', ev => { ev.preventDefault(); box.dataset.all = '1'; tlLoad(box); });
    box.querySelectorAll('.tl-del').forEach(b => b.addEventListener('click', async () => {
      if (!confirm('Delete this note and its photos?')) return;
      await fetch(`api/timeline.php?id=${b.dataset.eid}`, { method: 'DELETE', headers: { 'X-CSRF-Token': cfg.csrf || '' } });
      tlLoad(box);
    }));
    const form = box.querySelector('.tl-form');
    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      const msg = form.querySelector('.tl-msg'), btn = form.querySelector('button');
      const fd = new FormData(form); fd.append('parent_type', pt); fd.append('parent_id', pid);
      btn.disabled = true; msg.textContent = 'Saving…';
      try {
        const d = await (await fetch('api/timeline.php', { method: 'POST', headers: { 'X-CSRF-Token': cfg.csrf || '' }, body: fd })).json();
        if (!d.ok) throw new Error(d.error || 'Failed');
        tlLoad(box);
      } catch (err) { msg.textContent = err.message; msg.className = 'tl-msg text-danger'; btn.disabled = false; }
    });
  }

  function buildConduitPopup(p) {
    const nameStr  = p.name    ? `<strong>${esc(p.name)}</strong><br>` : '<strong>Conduit</strong><br>';
    const typeStr  = p.microduct_type_name ? `<small class="text-muted">${esc(p.microduct_type_name)}${p.has_tracer ? ' <span title="Tracer wire">〰️</span>' : ''}</small><br>` : '';
    const statStr  = `<small class="text-muted">Status: ${esc(p.status)}${p.installation === 'aerial' ? ' &middot; <strong>Aerial, lashed to the strand</strong>' : ''}</small>`;
    const ownerLabels = { bell: 'Bell', nbpower: 'NB Power', rogers: 'Rogers', private: 'Private' };
    const ownerStr = p.owner ? `<br><small class="text-muted">Owner: ${esc(ownerLabels[p.owner] || p.owner)}</small>` : '';
    const spareStr = p.is_spare
      ? '<br><small class="text-muted">Contents: <strong>spare / empty</strong></small>'
      : (p.cable_fibre_count ? `<br><small class="text-muted">Contents: ${esc(p.cable_fibre_count)}F cable</small>` : '');
    const woStr    = p.fpm_wor      ? `<br><small class="text-muted">Tinuum# ${esc(p.fpm_wor)}</small>`           : '';
    const bpStr    = p.bell_permit_num ? `<br><small class="text-muted">Bell Permit: ${esc(p.bell_permit_num)}</small>` : '';
    const noteStr  = p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';
    const lenStr   = conduitLengthLine(p);
    return `<div>
      ${nameStr}${typeStr}${statStr}${ownerStr}${spareStr}${lenStr}${woStr}${bpStr}${noteStr}
      ${tlBox('conduit', p.conduit_id)}
      ${actRow([
        actBtn('edit', `id="edit-conduit-${p.conduit_id}"`, 'Edit'),
        actBtn('move', `id="move-conduit-${p.conduit_id}"`, 'Move', 'Move the whole run: drag it to a new place; a cable that follows it moves too'),
        actBtn('split', `id="split-conduit-${p.conduit_id}"`, 'Split', 'Cut this run in two where you click'),
        actBtn('join', `id="join-conduit-${p.conduit_id}"`, 'Join', 'Join this run to another conduit that meets it end to end'),
        actBtn('dim', `id="dim-conduit-${p.conduit_id}"`, 'Dimension', "Save this conduit's length as a dimension on the plan"),
      ], actBtn('del', `id="del-conduit-${p.conduit_id}"`, 'Delete', 'Delete conduit'))}
    </div>`;
  }

  // ── Vault / Handhole rendering ───────────────────────────────────────────────

  // Name label — falls back to the owner's own reference when unnamed.
  // The database ID with a short type prefix: HH24 (handhole), V26 (vault), PB3 (pullbox), MH7 (manhole).
  // The label shows the name; this reference stands in for an unnamed one and is kept in the popup.
  const VAULT_PREFIX = { vault: 'V', handhole: 'HH', pullbox: 'PB', manhole: 'MH' };
  const vaultRef = (p) => (VAULT_PREFIX[p.type] || 'V') + p.vault_id;

  function bindVaultLabel(marker, p) {
    const own = (p.name || '').trim() || (p.owner_ref || '').trim();
    // The name when there is one (MH2), the database reference only as the fallback (MH28), so a named
    // manhole is not labelled "MH28 · MH2".
    const text = own || vaultRef(p);
    marker.bindTooltip(text, {
      permanent:  true,
      direction:  'right',
      // Owner-coloured to match the pole-arm palette in map.icons.js. A vault with
      // no owner is Tinuum's own — that's what the edit form's blank option means.
      className:  'fosc-label vault-label' +
                  (p.owner === 'bell'    ? ' vault-label--bell'    : '') +
                  (p.owner === 'nbpower' ? ' vault-label--nbpower' : '') +
                  (p.owner === 'rogers'  ? ' vault-label--rogers'  : '') +
                  (p.owner === 'private' ? ' vault-label--private' : '') +
                  (!p.owner              ? ' vault-label--own'  : ''),
      offset:     [6, 0],
    });
  }

  function renderVaultFeature(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;
    const marker = L.marker([lat, lon], {
      icon: vaultIcon(p.status, poleScale()),
      zIndexOffset: 200,   // above the closure icon's tall invisible box, which otherwise covers a vault beside it
    });
    marker._tinVault = { status: p.status, props: p };
    marker.bindPopup(() => buildVaultPopup(p), { minWidth: 170 });
    bindVaultLabel(marker, p);
    marker.on('popupopen', function () {
      document.getElementById('edit-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openVaultModalForEdit(p);
        });
      document.getElementById('move-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          startMoveVault(marker, p);
        });
      document.getElementById('del-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          deleteVault(p.vault_id, marker);
        });
    });
    vaultGroup.addLayer(marker);
  }

  // Drag a vault or handhole. Conduit ends on it follow; a conduit passing the new spot is split there.
  function startMoveVault(marker, p) {
    cancelActiveEdit();
    marker.dragging.enable();
    const zWas = marker.options.zIndexOffset || 0;
    marker.setZIndexOffset(100000);   // sit above any neighbour so the drag grabs this one
    map.getContainer().style.cursor = 'grab';
    const cancel = function () { marker.dragging.disable(); marker.setZIndexOffset(zWas); map.getContainer().style.cursor = ''; };
    activeEdit = { type: 'vault', layer: marker, cancel };
    showEditBar(
      '&#x2B1C; Moving <strong>' + esc((p.name || p.type || 'vault')) + '</strong> — drag to new position',
      null,
      function () { cancel(); hideEditBar(); activeEdit = null; loadFeatures(true); }
    );
    marker.once('dragend', async function () {
      const ll = marker.getLatLng();
      cancel(); hideEditBar(); activeEdit = null;
      toast('Saving…', 'secondary');
      try {
        const data = await (await apiPut(API.vault + '?id=' + p.vault_id, {
          name: p.name || '', type: p.type, owner: p.owner || '', owner_ref: p.owner_ref || '',
          status: p.status, notes: p.notes || '', fpm_wor: p.fpm_wor || '',
          geojson: { type: 'Point', coordinates: [ll.lng, ll.lat] },
        })).json();
        if (!data.ok) throw new Error(data.error || 'save failed');
        toast('Moved' + conduitSummary(data.conduits), 'success');
      } catch (e) { toast('Move failed: ' + e.message, 'danger'); }
      finally { loadFeatures(true); }
    });
  }

  function conduitSummary(c) {
    if (!c) return '';
    const bits = [];
    if (c.moved)   bits.push(c.moved + ' conduit end' + (c.moved > 1 ? 's' : '') + ' moved');
    if (c.snapped) bits.push(c.snapped + ' conduit end' + (c.snapped > 1 ? 's' : '') + ' snapped on');
    if (c.split)   bits.push(c.split + ' conduit' + (c.split > 1 ? 's' : '') + ' split here');
    return bits.length ? ': ' + bits.join(', ') : '';
  }

  function renderHandholeFeature(feature) {
    if (!feature.geometry) return;
    const [lon, lat] = feature.geometry.coordinates;
    if (lon == null || lat == null || !isFinite(lon) || !isFinite(lat)) return;
    const p = feature.properties;
    const iconFn = p.type === 'pullbox' ? pullboxIcon : handholeIcon;
    const marker = L.marker([lat, lon], {
      icon: iconFn(p.status, poleScale()),
    });
    marker._tinVault = { status: p.status, props: p };
    marker.bindPopup(() => buildVaultPopup(p), { minWidth: 170 });
    bindVaultLabel(marker, p);
    marker.on('popupopen', function () {
      document.getElementById('edit-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          openVaultModalForEdit(p);
        });
      document.getElementById('move-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          startMoveVault(marker, p);
        });
      document.getElementById('del-vault-' + p.vault_id)
        ?.addEventListener('click', function () {
          marker.closePopup();
          deleteVault(p.vault_id, marker);
        });
    });
    handholeGroup.addLayer(marker);
  }

  function buildVaultPopup(p) {
    const ownerLabels = { bell: 'Bell', nbpower: 'NB Power', rogers: 'Rogers', private: 'Private' };
    const typeLabel = p.type.charAt(0).toUpperCase() + p.type.slice(1);
    const nameStr   = p.name
      ? `<strong>${typeLabel} ${esc(p.name)}</strong> <span class="text-muted">(id ${esc(vaultRef(p))})</span>`
      : `<strong>${typeLabel} ${esc(vaultRef(p))}</strong>`;
    const statStr   = `<span class="badge bg-secondary ms-1">${esc(p.status)}</span>`;
    const ownerStr  = p.owner ? `<br><small class="text-muted"><strong>${esc(ownerLabels[p.owner] || p.owner)}</strong> ${esc(typeLabel)}${p.owner_ref ? ' #' + esc(p.owner_ref) : ''}</small>`
                              : (p.owner_ref ? `<br><small class="text-muted"><strong>#</strong> ${esc(p.owner_ref)}</small>` : '');
    const woStr     = p.fpm_wor ? `<br><small class="text-muted"><strong>WO:</strong> ${esc(p.fpm_wor)}</small>` : '';
    const modelStr  = p.model_label ? `<br><small class="text-muted"><strong>Model:</strong> ${esc(p.model_label)}</small>` : '';
    const noteStr   = p.notes ? `<br><small class="text-muted">${esc(p.notes)}</small>` : '';
    return `<div>
      ${nameStr}${statStr}${ownerStr}${modelStr}${woStr}${noteStr}
      ${tlBox('vault', p.vault_id)}
      ${actRow([
        actBtn('edit', `id="edit-vault-${p.vault_id}"`, 'Edit'),
        actBtn('move', `id="move-vault-${p.vault_id}"`, 'Move', 'Drag to a new position; conduit ends on it move with it'),
      ], actBtn('del', `id="del-vault-${p.vault_id}"`, 'Delete', 'Delete'))}
    </div>`;
  }


  // ── Conduit: move a whole run, split it, join two ───────────────────────────
  // One click on the map picks a point (or the asset under it). A capture-phase listener on the map
  // container gets the click before the line or marker under the pointer opens its own popup.
  let mapPick = null;
  function pickOnMap(message, onPick) {
    cancelActiveEdit();
    const el = map.getContainer();
    const handler = (ev) => {
      if (ev.target.closest && ev.target.closest('.leaflet-control, .leaflet-popup, #mapEditBar')) return;
      ev.stopPropagation(); ev.preventDefault();
      const done = stopPick();
      onPick({ latlng: map.mouseEventToLatLng(ev), point: map.mouseEventToContainerPoint(ev) });
    };
    const stopPick = () => { el.removeEventListener('click', handler, true); el.style.cursor = ''; mapPick = null; hideEditBar(); };
    el.addEventListener('click', handler, true);
    el.style.cursor = 'crosshair';
    mapPick = stopPick;
    showEditBar(message, null, stopPick);
  }

  function nearestConduitAt(pt, tol, skipId) {
    let best = null, bestD = tol;
    conduitGroup.eachLayer(l => {
      const c = l._tinConduit;
      if (!c || c.conduit_id === skipId || !l.getLatLngs || (l.options && l.options.interactive === false)) return;
      const rings = l.getLatLngs(), flat = Array.isArray(rings[0]) ? rings[0] : rings;
      let prev = null;
      for (const ll of flat) {
        const q = map.latLngToContainerPoint(ll);
        if (prev) {
          const dx = q.x - prev.x, dy = q.y - prev.y, l2 = dx * dx + dy * dy;
          const t = l2 ? Math.max(0, Math.min(1, ((pt.x - prev.x) * dx + (pt.y - prev.y) * dy) / l2)) : 0;
          const d = Math.hypot(pt.x - (prev.x + t * dx), pt.y - (prev.y + t * dy));
          if (d < bestD) { bestD = d; best = c; }
        }
        prev = q;
      }
    });
    return best;
  }

  // Route a cable span through conduits: click each conduit in the order the cable runs through them
  // (click one again to take it out), then Save. The cable's line becomes the conduit lines themselves.
  function startViaConduits(sp) {
    cancelActiveEdit();
    const picked = [], marks = {};
    const el = map.getContainer();
    const label = () => picked.length
      ? 'Cable ' + sp.cable_span_id + ' through conduit' + (picked.length > 1 ? 's ' : ' ') + picked.join(' &rarr; ') + '. Click another conduit, or Save.'
      : 'Click the conduits cable ' + sp.cable_span_id + ' runs through, in order (' + esc(sp.from_splice_num || '') + ' to ' + esc(sp.to_splice_num || '') + '), then Save';
    const handler = (ev) => {
      if (ev.target.closest && ev.target.closest('.leaflet-control, .leaflet-popup, #mapEditBar')) return;
      ev.stopPropagation(); ev.preventDefault();
      const c = nearestConduitAt(map.mouseEventToContainerPoint(ev), 14, -1);
      if (!c) { toast('Click on a conduit', 'warning'); return; }
      const i = picked.indexOf(c.conduit_id);
      if (i >= 0) { picked.splice(i, 1); map.removeLayer(marks[c.conduit_id]); delete marks[c.conduit_id]; }
      else {
        picked.push(c.conduit_id);
        let line = null;
        conduitGroup.eachLayer(l => { if (l._tinConduit && l._tinConduit.conduit_id === c.conduit_id && l.getLatLngs && !l._tinDecor) line = l; });
        if (line) marks[c.conduit_id] = L.polyline(line.getLatLngs(), { color: '#0f766e', weight: 7, opacity: 0.55, interactive: false }).addTo(map);
      }
      showEditBar(label(), save, cancel, 'Save route');
    };
    const cleanup = () => { el.removeEventListener('click', handler, true); el.style.cursor = ''; Object.values(marks).forEach(m => map.removeLayer(m)); mapPick = null; hideEditBar(); activeEdit = null; };
    const cancel = () => cleanup();
    const save = async () => {
      if (!picked.length) { toast('Click at least one conduit first', 'warning'); return; }
      try {
        const d = await (await apiPost('/api/span_via_conduits.php', { cable_span_id: sp.cable_span_id, conduit_ids: picked })).json();
        if (!d.ok) throw new Error(d.error || 'failed');
        cleanup(); toast('Cable routed through ' + picked.length + ' conduit' + (picked.length > 1 ? 's' : '') + ' (' + d.length_m + ' m)', 'success'); loadFeatures(true);
      } catch (e) { toast(e.message, 'danger'); }
    };
    el.addEventListener('click', handler, true);
    el.style.cursor = 'crosshair';
    mapPick = cleanup;
    showEditBar(label(), save, cancel, 'Save route');
  }

  async function conduitAction(action, body, doneMsg) {
    try {
      const data = await (await apiPost(API.conduit + '?action=' + action, body)).json();
      if (!data.ok) throw new Error(data.error || 'failed');
      toast(doneMsg, 'success');
      loadFeatures(true);
    } catch (e) { toast(e.message, 'danger'); }
  }

  function startSplitConduit(p) {
    pickOnMap('&#x2702; Click the conduit where it should be cut', (pick) => {
      conduitAction('split', { id: p.conduit_id, lng: pick.latlng.lng, lat: pick.latlng.lat }, 'Conduit split in two');
    });
  }

  function startJoinConduit(p) {
    pickOnMap('Click the conduit to join to this one (they must meet end to end)', (pick) => {
      const other = nearestConduitAt(pick.point, 14, p.conduit_id);
      if (!other) { toast('No other conduit there', 'warning'); return; }
      if (!confirm('Join conduit ' + other.conduit_id + (other.name ? ' (' + other.name + ')' : '') + ' into ' + p.conduit_id + (p.name ? ' (' + p.name + ')' : '') +
                   '?\n\nThey become one conduit with the details of the first.')) return;
      conduitAction('join', { a: p.conduit_id, b: other.conduit_id }, 'Conduits joined');
    });
  }

  function startMoveConduit(line, p) {
    cancelActiveEdit();
    const rings = line.getLatLngs(), orig = (Array.isArray(rings[0]) ? rings[0] : rings).map(ll => L.latLng(ll.lat, ll.lng));
    const mid = orig[Math.floor(orig.length / 2)];
    const ghost = L.polyline(orig, { color: '#0f766e', weight: 4, dashArray: '8 6', interactive: false }).addTo(map);
    const handle = L.marker(mid, { draggable: true, zIndexOffset: 1000, icon: L.divIcon({ className: '',
      html: '<div style="width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;background:#0f766e;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5);color:#fff;display:flex;align-items:center;justify-content:center">' +
            '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round">' + ACT_ICONS.move + '</svg></div>', iconSize: [0, 0] }) }).addTo(map);
    let shifted = orig;
    handle.on('drag', () => {
      const d = handle.getLatLng(), dlat = d.lat - mid.lat, dlng = d.lng - mid.lng;
      shifted = orig.map(ll => L.latLng(ll.lat + dlat, ll.lng + dlng));
      ghost.setLatLngs(shifted);
    });
    const cleanup = () => { map.removeLayer(ghost); map.removeLayer(handle); hideEditBar(); activeEdit = null; };
    activeEdit = { type: 'conduit-move', layer: handle, cancel: () => { map.removeLayer(ghost); map.removeLayer(handle); } };
    showEditBar('Moving conduit <strong>' + esc(p.name || p.conduit_id) + '</strong>: drag the round handle, then save. A cable that follows this conduit moves with it.',
      async () => {
        try {
          const data = await (await apiPut(API.conduit + '?id=' + p.conduit_id, { geojson: { type: 'LineString', coordinates: shifted.map(ll => [ll.lng, ll.lat]) } })).json();
          if (!data.ok) throw new Error(data.error || 'save failed');
          cleanup(); toast('Conduit moved', 'success'); loadFeatures(true);
        } catch (e) { toast(e.message, 'danger'); }
      },
      () => { cleanup(); },
      'Save position');
  }

  // ── Draw: conduit run ────────────────────────────────────────────────────────

  function startDrawConduit() {
    cancelActiveEdit();
    pendingDrawMode = 'conduit';
    conduitSnapOn();
    map.pm.enableDraw('Line', { snappable: false });
    showEditBar(
      '╌ Click points to draw a conduit run &mdash; double-click to finish &middot; a click near a handhole/vault snaps to it',
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  // Conduit ends belong on handholes/vaults, not closures. While drawing, markers stop eating clicks and a click
  // within 25 px of a handhole/vault is moved onto it (our listener runs before geoman's, sharing the event).
  let conduitSnapHandler = null;
  function conduitSnapOn() {
    conduitSnapOff();
    if (!document.getElementById('conduitSnapCss')) {
      const st = document.createElement('style'); st.id = 'conduitSnapCss';
      st.textContent = '.draw-conduit .leaflet-marker-icon{pointer-events:none!important}';
      document.head.appendChild(st);
    }
    map.getContainer().classList.add('draw-conduit');
    conduitSnapHandler = function (e) {
      if (pendingDrawMode !== 'conduit' || !e.latlng) return;
      const pt = map.latLngToContainerPoint(e.latlng);
      let best = null, bd = 25;
      [vaultGroup, handholeGroup].forEach(g => g && g.eachLayer(m => {
        if (!m._tinVault || !map.hasLayer(m) || !m._icon || m._icon.style.display === 'none') return;
        const d = pt.distanceTo(map.latLngToContainerPoint(m.getLatLng()));
        if (d < bd) { bd = d; best = m; }
      }));
      if (best) e.latlng = best.getLatLng();
    };
    map.on('click mousemove', conduitSnapHandler);
    map.once('pm:drawend', conduitSnapOff);
  }
  function conduitSnapOff() {
    if (conduitSnapHandler) { map.off('click mousemove', conduitSnapHandler); conduitSnapHandler = null; }
    map.getContainer().classList.remove('draw-conduit');
  }

  // ── Draw: vault / handhole marker ────────────────────────────────────────────

  function startPlaceVault(type) {
    cancelActiveEdit();
    pendingVaultType = type;
    pendingDrawMode  = 'vault';
    const label = type === 'handhole' ? 'handhole' : type === 'pullbox' ? 'pullbox' : 'vault';
    map.pm.enableDraw('Marker', { snappable: false });
    showEditBar(
      `□ Click on the map to place the new ${label}`,
      null,
      function () { map.pm.disableDraw(); pendingDrawMode = 'fosc'; hideEditBar(); }
    );
  }

  // ── Conduit modal helpers ────────────────────────────────────────────────────

  function openConduitModalForEdit(p) {
    editingConduitId = p.conduit_id;
    document.getElementById('conduitName').value          = p.name       || '';
    document.getElementById('conduitStatus').value        = p.status     || 'plan';
    document.getElementById('conduitOwner').value         = p.owner      || '';
    document.getElementById('conduitMicroductType').value = p.microduct_type_id ? String(p.microduct_type_id) : '';
    { const ins = document.getElementById('conduitInstallation'); if (ins) ins.value = p.installation === 'aerial' ? 'aerial' : 'buried'; }
    document.getElementById('conduitNotes').value         = p.notes      || '';
    // Blank, not 0 — an empty box is how "never measured" is expressed.
    const knownEl = document.getElementById('conduitKnownLength');
    const tolEl   = document.getElementById('conduitKnownTol');
    if (knownEl) knownEl.value = p.known_length_m     != null ? p.known_length_m     : '';
    if (tolEl)   tolEl.value   = p.known_length_tol_m != null ? p.known_length_tol_m : '';
    document.getElementById('conduitFpmWor').value     = p.fpm_wor      || '';
    document.getElementById('conduitBellPermit').value    = p.bell_permit_num || '';
    document.getElementById('conduitIsSpare').checked     = !!p.is_spare;
    document.getElementById('conduitModalLabel').textContent = 'Edit Conduit';
    showErr(document.getElementById('conduitModalError'), '');
    new bootstrap.Modal(document.getElementById('newConduitModal')).show();
  }

  async function saveConduit() {
    const errEl     = document.getElementById('conduitModalError');
    const name             = (document.getElementById('conduitName')?.value           || '').trim();
    const status           = (document.getElementById('conduitStatus')?.value         || 'plan').trim();
    const owner            = (document.getElementById('conduitOwner')?.value          || '').trim();
    const microductTypeId  = parseInt(document.getElementById('conduitMicroductType')?.value || '0', 10) || null;
    const notes            = (document.getElementById('conduitNotes')?.value          || '').trim();
    const fpmWor        = (document.getElementById('conduitFpmWor')?.value      || '').trim();
    const bellPermitNum    = (document.getElementById('conduitBellPermit')?.value     || '').trim();
    const isSpare          = !!document.getElementById('conduitIsSpare')?.checked;
    const installation     = document.getElementById('conduitInstallation')?.value === 'aerial' ? 'aerial' : 'buried';
    const knownLengthM     = (document.getElementById('conduitKnownLength')?.value || '').trim();
    const knownTolM        = (document.getElementById('conduitKnownTol')?.value    || '').trim();

    showErr(errEl, '');
    if (!editingConduitId && !pendingConduitGeojson) {
      showErr(errEl, 'No conduit route drawn.'); return;
    }

    const payload = { name, status, owner, microduct_type_id: microductTypeId, notes, fpm_wor: fpmWor, bell_permit_num: bellPermitNum, is_spare: isSpare, installation,
                      known_length_m: knownLengthM, known_length_tol_m: knownTolM };
    if (pendingConduitGeojson) payload.geojson = pendingConduitGeojson;

    try {
      const resp = editingConduitId
        ? await apiPut(API.conduit + '?id=' + editingConduitId, payload)
        : await apiPost(API.conduit, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newConduitModal')).hide();
        toast(editingConduitId ? 'Conduit updated' : 'Conduit created', 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function resetConduitModal() {
    pendingConduitGeojson = null;
    editingConduitId      = 0;
    ['conduitName', 'conduitNotes', 'conduitFpmWor', 'conduitBellPermit', 'conduitKnownLength', 'conduitKnownTol'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const sp = document.getElementById('conduitIsSpare');        if (sp) sp.checked = false;
    const st = document.getElementById('conduitStatus');       if (st) st.value = 'plan';
    const ow = document.getElementById('conduitOwner');        if (ow) ow.value = '';
    const mt = document.getElementById('conduitMicroductType'); if (mt) mt.value = '';
    const ins = document.getElementById('conduitInstallation'); if (ins) ins.value = 'buried';
    const lb = document.getElementById('conduitModalLabel');   if (lb) lb.textContent = 'New Conduit';
    showErr(document.getElementById('conduitModalError'), '');
  }

  async function deleteConduit(conduitId, line) {
    if (!(await confirmDeleteAsset('conduit', conduitId, 'Delete this conduit run? This cannot be undone.'))) return;
    try {
      const resp = await apiDelete(API.conduit + '?id=' + conduitId);
      const data = await resp.json();
      if (data.ok) {
        conduitGroup.removeLayer(line);
        toast('Conduit deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) { toast('Error: ' + e.message, 'danger'); }
  }

  // ── Vault modal helpers ──────────────────────────────────────────────────────

  function openVaultModalForEdit(p) {
    editingVaultId = p.vault_id;
    pendingVaultLatLng = null; // editing: no new latlng
    document.getElementById('vaultTypeSelect').value = p.type   || 'vault';
    document.getElementById('vaultTypeInput').value  = p.type   || 'vault';
    document.getElementById('vaultOwner').value      = p.owner     || '';
    document.getElementById('vaultOwnerRef').value   = p.owner_ref || '';
    document.getElementById('vaultName').value       = p.name   || '';
    document.getElementById('vaultStatus').value     = p.status || 'plan';
    document.getElementById('vaultFpmWor').value  = p.fpm_wor || '';
    document.getElementById('vaultNotes').value      = p.notes  || '';
    setVaultModel(p);
    const typeLabel = (p.type || 'vault');
    document.getElementById('vaultModalLabel').textContent =
      'Edit ' + typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1);
    showErr(document.getElementById('vaultModalError'), '');
    new bootstrap.Modal(document.getElementById('newVaultModal')).show();
  }

  // Model picker: a BOM Vault item, or "other" with free text. Required (*) only when the owner is Tinuum.
  function setVaultModel(p) {
    const sel = document.getElementById('vaultModel'), oth = document.getElementById('vaultModelOther');
    if (!sel) return;
    sel.value = p && p.model_id ? String(p.model_id) : (p && p.model_other ? 'other' : '');
    oth.value = (p && p.model_other) || '';
    syncVaultModel();
  }
  function syncVaultModel() {
    const sel = document.getElementById('vaultModel'), oth = document.getElementById('vaultModelOther');
    if (!sel) return;
    oth.style.display = sel.value === 'other' ? '' : 'none';
    document.getElementById('vaultModelReq').style.display = document.getElementById('vaultOwner').value === '' ? '' : 'none';
  }
  document.getElementById('vaultModel')?.addEventListener('change', syncVaultModel);
  document.getElementById('vaultOwner')?.addEventListener('change', syncVaultModel);

  async function saveVault() {
    const errEl  = document.getElementById('vaultModalError');
    const type   = (document.getElementById('vaultTypeSelect')?.value || 'vault').trim();
    const owner    = (document.getElementById('vaultOwner')?.value    || '').trim();
    const ownerRef = (document.getElementById('vaultOwnerRef')?.value || '').trim();
    const name   = (document.getElementById('vaultName')?.value       || '').trim();
    const status = (document.getElementById('vaultStatus')?.value     || 'plan').trim();
    const fpmWor = (document.getElementById('vaultFpmWor')?.value || '').trim();
    const notes  = (document.getElementById('vaultNotes')?.value      || '').trim();

    showErr(errEl, '');
    if (!editingVaultId && !pendingVaultLatLng) {
      showErr(errEl, 'No location placed.'); return;
    }

    const modelSel = document.getElementById('vaultModel')?.value || '';
    const modelOther = (document.getElementById('vaultModelOther')?.value || '').trim();
    if (owner === '' && (modelSel === '' || (modelSel === 'other' && !modelOther))) {
      showErr(errEl, 'Pick the model (required for ' + OWN_NAME + '-owned).'); return;
    }
    const payload = { type, owner, owner_ref: ownerRef, name, status, fpm_wor: fpmWor, notes,
      model_id: modelSel && modelSel !== 'other' ? Number(modelSel) : null,
      model_other: modelSel === 'other' ? modelOther : '' };
    if (pendingVaultLatLng) {
      payload.geojson = { type: 'Point', coordinates: [pendingVaultLatLng.lng, pendingVaultLatLng.lat] };
    }

    try {
      const resp = editingVaultId
        ? await apiPut(API.vault + '?id=' + editingVaultId, payload)
        : await apiPost(API.vault, payload);
      const data = await resp.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('newVaultModal')).hide();
        toast((editingVaultId ? 'Updated' : 'Created') + conduitSummary(data.conduits), 'success');
        loadFeatures(true);
      } else {
        showErr(errEl, data.error || 'Unknown error');
      }
    } catch (e) { showErr(errEl, 'Error: ' + e.message); }
  }

  function resetVaultModal() {
    pendingVaultLatLng = null;
    editingVaultId     = 0;
    ['vaultName', 'vaultNotes', 'vaultFpmWor', 'vaultOwnerRef'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const ow = document.getElementById('vaultOwner');      if (ow) ow.value = '';
    const ts = document.getElementById('vaultTypeSelect'); if (ts) ts.value = 'vault';
    const ti = document.getElementById('vaultTypeInput');  if (ti) ti.value = 'vault';
    const st = document.getElementById('vaultStatus');     if (st) st.value = 'plan';
    setVaultModel(null);
    const lb = document.getElementById('vaultModalLabel'); if (lb) lb.textContent = 'New Vault';
    showErr(document.getElementById('vaultModalError'), '');
  }

  async function deleteVault(vaultId, marker) {
    if (!(await confirmDeleteAsset('vault', vaultId, 'Delete this entry? This cannot be undone.'))) return;
    try {
      const resp = await apiDelete(API.vault + '?id=' + vaultId);
      const data = await resp.json();
      if (data.ok) {
        vaultGroup.removeLayer(marker);
        handholeGroup.removeLayer(marker);
        toast('Deleted', 'success');
      } else {
        toast('Delete failed: ' + (data.error || 'unknown error'), 'danger');
      }
    } catch (e) { toast('Error: ' + e.message, 'danger'); }
  }

  // sorted by distance, with model-B slice applied.
  function polesInBearingDir(closureLat, closureLon, bearing, model) {
    const strandedFids = new Set(strandData.flatMap(s => [s.from_pole_fid, s.to_pole_fid]));
    const cosLat = Math.cos(closureLat * Math.PI / 180);
    const sorted = poleData
      .filter(p => strandedFids.has(p.fid))
      .map(p => {
        const dLat  = p.lat - closureLat;
        const dLon  = p.lon - closureLon;
        const distM = Math.sqrt((dLat * 111000) ** 2 + (dLon * 111000 * cosLat) ** 2);
        const az    = ((Math.atan2(dLon * cosLat, dLat) * 180 / Math.PI) + 360) % 360;
        const angDiff = Math.min(
          ((az - bearing) % 360 + 360) % 360,
          ((bearing - az) % 360 + 360) % 360
        );
        return { ...p, distM, angDiff };
      })
      .filter(p => p.distM > 0.1 && p.distM <= 5000 && p.angDiff <= 45)
      .sort((a, b) => a.distM - b.distM);
    return model === 'B' ? sorted.slice(1) : sorted;
  }


})();
