<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/looking_glass.php — BGP Looking Glass (RIPE RIS vantage points)
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('BGP Looking Glass');

$defaultAsn = getenv('APP_BGP_AS') ?: '';

$PAGE->setInlineCssRenderer(function () { return <<<CSS
/* Looking Glass page styles */
.lg-query-bar        { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:1.25rem 1.5rem; }
.lg-prefix-card      { border:1px solid #dee2e6; border-radius:8px; margin-bottom:1.25rem; overflow:hidden; }
.lg-prefix-header    { background:#212529; color:#fff; padding:.6rem 1rem; font-family:monospace; font-size:.95rem; display:flex; align-items:center; gap:.75rem; }
.lg-vis-badge        { font-size:.75rem; padding:.25em .6em; border-radius:20px; font-weight:600; }
.lg-vis-ok           { background:#198754; color:#fff; }
.lg-vis-warn         { background:#fd7e14; color:#fff; }
.lg-vis-none         { background:#dc3545; color:#fff; }
.lg-rrc-grid         { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.5rem; padding:1rem; }
.lg-rrc-tile         { border-radius:6px; padding:.5rem .75rem; font-size:.8rem; cursor:default; border:1px solid transparent; transition:box-shadow .15s; }
.lg-rrc-tile:hover   { box-shadow:0 2px 8px rgba(0,0,0,.12); }
.lg-rrc-seen         { background:#d1e7dd; border-color:#a3cfbb; color:#0a3622; }
.lg-rrc-unseen       { background:#f8d7da; border-color:#f1aeb5; color:#58151c; }
.lg-rrc-loading      { background:#e9ecef; border-color:#dee2e6; color:#6c757d; }
.lg-rrc-offline      { background:#f8f9fa; border-color:#dee2e6; color:#adb5bd; opacity:.55; }
.lg-rrc-name         { font-weight:700; font-family:monospace; }
.lg-rrc-loc          { color:inherit; opacity:.75; font-size:.72rem; }
.lg-aspath           { font-family:monospace; font-size:.78rem; margin-top:.2rem; word-break:break-all; opacity:.85; }
.lg-asn              { border-bottom:1px dotted #adb5bd; cursor:help; }
.lg-asn-known        { border-bottom:1px dotted currentColor; }
.lg-asn-loading      { opacity:.6; }
.lg-asn-badge        { display:inline-flex; align-items:center; gap:2px; background:rgba(0,0,0,.07); border-radius:3px; padding:0 3px; border-bottom:none !important; }
.lg-asn-favicon      { display:inline-block; vertical-align:middle; border-radius:2px; }
.lg-asn-tip          { position:absolute; z-index:9999; background:#212529; color:#fff; font-size:.75rem; font-family:monospace; white-space:nowrap; padding:.25rem .55rem; border-radius:4px; pointer-events:none; transform:translate(-50%,-100%); box-shadow:0 2px 6px rgba(0,0,0,.35); }
.lg-loading-spinner  { display:inline-block; width:1rem; height:1rem; border:2px solid #dee2e6; border-top-color:#0d6efd; border-radius:50%; animation:lg-spin .6s linear infinite; }
@keyframes lg-spin   { to { transform:rotate(360deg); } }
.lg-summary-row      { padding:.5rem 1rem .75rem; border-top:1px solid #dee2e6; font-size:.82rem; color:#6c757d; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.lg-empty            { padding:3rem; text-align:center; color:#6c757d; }
.lg-transit-panel    { border:1px solid #dee2e6; border-radius:8px; overflow:hidden; }
.lg-transit-header   { background:#212529; color:#fff; padding:.5rem 1rem; font-size:.85rem; font-weight:600; }
.lg-transit-body     { padding:.5rem; }
.lg-peer-family      { margin-bottom:.75rem; }
.lg-peer-family-label{ font-weight:700; font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:#6c757d; padding:.25rem .25rem .4rem; }
.lg-peer-cols        { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:.75rem; }
.lg-peer-col         { border:1px solid #dee2e6; border-radius:6px; overflow:hidden; }
.lg-peer-col-head    { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:.35rem .75rem; font-size:.78rem; font-weight:600; color:#495057; }
/* Router BGP Sessions */
.lg-rt-panel        { border:1px solid #dee2e6; border-radius:8px; overflow:hidden; margin-bottom:1rem; }
.lg-rt-header       { background:#0a3622; color:#fff; padding:.5rem 1rem; font-size:.85rem; font-weight:600; display:flex; align-items:center; gap:.75rem; }
.lg-rt-badge        { font-size:.72rem; padding:.15em .5em; border-radius:10px; font-weight:600; }
.lg-rt-up           { background:#198754; color:#fff; }
.lg-rt-down         { background:#dc3545; color:#fff; }
.lg-rt-router-name  { font-size:.75rem; opacity:.75; margin-left:auto; }
.lg-rt-note         { background:#fff3cd; border:1px solid #ffecb5; border-radius:6px; padding:.5rem .75rem; font-size:.8rem; color:#856404; margin-bottom:.75rem; }
/* Outbound path lookup */
.lg-ob-card          { border:1px solid #dee2e6; border-radius:8px; margin-bottom:1rem; overflow:hidden; }
.lg-ob-header        { background:#343a40; color:#fff; padding:.5rem 1rem; font-family:monospace; font-size:.9rem; display:flex; align-items:center; gap:.75rem; }
.lg-ob-body          { padding:.75rem 1rem; }
.lg-ob-path          { font-family:monospace; font-size:.85rem; display:flex; align-items:center; flex-wrap:wrap; gap:.25rem .1rem; line-height:1.8; }
.lg-ob-hop           { background:#e9ecef; border-radius:4px; padding:.1rem .4rem; white-space:nowrap; border:1px solid #dee2e6; }
.lg-ob-hop-us        { background:#cfe2ff; border-color:#9ec5fe; color:#084298; font-weight:600; }
.lg-ob-hop-origin    { background:#d1e7dd; border-color:#a3cfbb; color:#0a3622; font-weight:600; }
.lg-ob-arrow         { color:#adb5bd; padding:0 .1rem; }
.lg-ob-via           { font-size:.75rem; color:#6c757d; margin-top:.25rem; }
.lg-ob-nofound       { color:#856404; background:#fff3cd; border:1px solid #ffecb5; border-radius:4px; padding:.4rem .75rem; font-size:.82rem; }
/* Ping result strip */
.lg-ping-result      { padding:.4rem 1rem; font-size:.82rem; border-top:1px solid rgba(0,0,0,.08); }
.lg-ping-ok          { color:#0a3622; background:#d1e7dd; }
.lg-ping-fail        { color:#58151c; background:#f8d7da; }
.lg-ping-pending     { color:#6c757d; }
/* Traceroute panel */
.lg-tr-panel         { border-top:1px solid rgba(0,0,0,.08); padding:.5rem 1rem .75rem; }
.lg-tr-table         { width:100%; font-size:.8rem; font-family:monospace; border-collapse:collapse; }
.lg-tr-table td      { padding:.15rem .4rem; vertical-align:top; }
.lg-tr-hop-num       { color:#adb5bd; width:2rem; text-align:right; }
.lg-tr-hop-addr      { font-weight:600; }
.lg-tr-hop-timeout   { color:#adb5bd; font-style:italic; }
.lg-tr-hop-rtt       { color:#6c757d; white-space:nowrap; }
.lg-tr-hop-dest      { color:#0a3622; font-weight:700; }
CSS; });

$PAGE->setContentRenderer(function () use ($defaultAsn) {
    // Pass home ASN to JS as a data attribute
    ob_start(); ?>
<div class="container-fluid py-3" id="lg-app"
     data-home-asn="<?= htmlspecialchars($defaultAsn) ?>"
     data-home-label="<?= htmlspecialchars(org('home_asn_label')) ?>" data-home-domain="<?= htmlspecialchars(org('web')) ?>">

  <!-- Query bar -->
  <div class="lg-query-bar mb-4">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-auto">
        <label class="form-label mb-1 fw-semibold" for="lg-asn">AS Number</label>
        <input id="lg-asn" type="text" class="form-control form-control-sm font-monospace"
               placeholder="e.g. AS64500" value="<?= htmlspecialchars($defaultAsn) ?>" style="min-width:160px;">
      </div>
      <div class="col-12 col-md-auto">
        <button id="lg-run" class="btn btn-primary btn-sm px-4">
          Query Looking Glass
        </button>
      </div>
      <?php if ($defaultAsn): ?>
      <div class="col-12 col-md-auto">
        <button id="lg-run-home" class="btn btn-outline-secondary btn-sm"
                data-home-asn="<?= htmlspecialchars($defaultAsn) ?>"
     data-home-label="<?= htmlspecialchars(org('home_asn_label')) ?>" data-home-domain="<?= htmlspecialchars(org('web')) ?>">
          Our AS (<?= htmlspecialchars($defaultAsn) ?>)
        </button>
      </div>
      <?php endif; ?>
      <div class="col-12 col-md-auto text-muted" style="font-size:.8rem; padding-top:.25rem;">
        Queries <strong>24 RIPE RIS route collectors</strong> worldwide via the RIPE Stat API.
      </div>
    </div>
  </div>

  <!-- Status line -->
  <div id="lg-status" class="text-muted mb-3" style="font-size:.85rem; min-height:1.4rem;"></div>

  <!-- Transit summary (populated after all prefixes queried) -->
  <div id="lg-transit-summary"></div>

  <!-- Results area -->
  <div id="lg-results">
    <div class="lg-empty text-muted">
      Enter an AS number and click <strong>Query Looking Glass</strong> to see global BGP visibility.
    </div>
  </div>

  <hr class="my-4">

  <!-- Router BGP Sessions (real live data from border router) -->
  <div class="d-flex align-items-baseline gap-2 mb-2">
    <h6 class="mb-0">Live BGP Sessions</h6>
    <span class="text-muted" style="font-size:.8rem;">— real-time peer status from <?= htmlspecialchars(org('name')) ?>'s border router</span>
    <button id="lg-rt-run" class="btn btn-sm btn-outline-success ms-auto">Load Sessions</button>
  </div>
  <div id="lg-rt-status" class="text-muted mb-2" style="font-size:.85rem; min-height:1.2rem;"></div>
  <div id="lg-rt-results" class="mb-3"></div>

  <hr class="my-4">

  <!-- BGP Route Lookup -->
  <div class="lg-query-bar mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-12">
        <span class="fw-semibold">BGP Route Lookup</span>
        <span class="text-muted ms-1" style="font-size:.85rem;">— enter any IP or prefix to see its AS path</span>
      </div>
      <div class="col-12 col-md-auto">
        <label class="form-label mb-1 fw-semibold" for="lg-ob-asn">IP address or prefix</label>
        <input id="lg-ob-asn" type="text" class="form-control form-control-sm font-monospace"
               placeholder="e.g. 137.221.64.1 or 8.8.8.0/24" style="min-width:260px;">
      </div>
      <div class="col-12 col-md-auto">
        <button id="lg-ob-run" class="btn btn-secondary btn-sm px-4">Look Up</button>
      </div>
    </div>
  </div>

  <div id="lg-ob-status" class="text-muted mb-3" style="font-size:.85rem; min-height:1.4rem;"></div>
  <div id="lg-ob-results"></div>

  <hr class="my-4">

  <!-- Ping & Traceroute -->
  <div class="lg-query-bar mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-12">
        <span class="fw-semibold">Ping &amp; Traceroute</span>
        <span class="text-muted ms-1" style="font-size:.85rem;">— run from <?= htmlspecialchars(org('name')) ?>'s border router</span>
      </div>
      <div class="col-12 col-md-auto">
        <label class="form-label mb-1 fw-semibold" for="lg-net-host">Host or IP address</label>
        <input id="lg-net-host" type="text" class="form-control form-control-sm font-monospace"
               placeholder="e.g. 8.8.8.8 or 2001:4860:4860::8888" style="min-width:300px;">
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button id="lg-ping-run" class="btn btn-outline-secondary btn-sm px-3">Ping</button>
        <button id="lg-tr-run" class="btn btn-outline-secondary btn-sm px-3">Traceroute</button>
      </div>
    </div>
  </div>

  <div id="lg-net-ping-result"></div>
  <div id="lg-net-tr-result"></div>

</div>
<?php return ob_get_clean();
}); ?>
