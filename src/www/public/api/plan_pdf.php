<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/plan_pdf.php — download a plan sheet as a PDF rendered server side.
//
//   GET ?lat=&lon=&zoom=[&orientation=landscape|portrait][&paper=Letter|…|A0]
//        [&fit=0.1..1][&fade=0.15..1][&basemap_res=legible|sharp][&plan=][&basemap=][&filter=]
//        → application/pdf
//
// Why this exists rather than just printing the page: Chrome's print dialog has
// a per-destination Scale setting that a page can neither read nor set, and it
// sticks between sessions. At anything but 100 the sheet prints with the map in
// one corner and the title block still claiming a scale it no longer has — on a
// drawing someone may put a ruler to. Rendering headlessly at a fixed 1:1 takes
// that setting out of the loop.

require_once __DIR__ . '/_common.php';

/** @var array $_apiIdentity */

if (api_method() !== 'GET') api_err('GET only', 405);

$lat  = filter_input(INPUT_GET, 'lat',  FILTER_VALIDATE_FLOAT);
$lon  = filter_input(INPUT_GET, 'lon',  FILTER_VALIDATE_FLOAT);
$zoom = filter_input(INPUT_GET, 'zoom', FILTER_VALIDATE_INT);

if ($lat === false || $lat === null || $lat < -85.0 || $lat > 85.0) {
    api_err('lat must be a number between -85 and 85', 400);
}
if ($lon === false || $lon === null || $lon < -180.0 || $lon > 180.0) {
    api_err('lon must be a number between -180 and 180', 400);
}
// 23 is the map's maxZoom (see L.map in map.helper.js).
if ($zoom === false || $zoom === null || $zoom < 1 || $zoom > 23) {
    api_err('zoom must be an integer between 1 and 23', 400);
}

$orientation = (string)($_GET['orientation'] ?? 'landscape');
if (!in_array($orientation, ['landscape', 'portrait'], true)) {
    api_err('orientation must be landscape or portrait', 400);
}

// Mirrors PAPER_SIZES in map.php; the renderer holds the millimetres.
$PAPERS = ['Letter', 'Tabloid', 'A3', 'A2', 'A1', 'A0'];
$paper  = (string)($_GET['paper'] ?? 'A0');
if (!in_array($paper, $PAPERS, true)) {
    api_err('unknown paper size', 400);
}

// Plan fit: the sheet is rendered at `zoom` over 1/fit times the paper's area
// and shrunk to the page (see planSheet in map.helper.js). 1 = the paper as-is.
$fit = filter_input(INPUT_GET, 'fit', FILTER_VALIDATE_FLOAT);
if ($fit === false || ($fit !== null && ($fit < 0.1 || $fit > 1))) api_err('fit must be between 0.1 and 1', 400);
$fit = $fit === null ? 1.0 : (float)$fit;

// The basemap's Fade slider (opacity), so a satellite the user has dimmed on
// screen prints dimmed too. The renderer has none of their localStorage.
$fade = filter_input(INPUT_GET, 'fade', FILTER_VALIDATE_FLOAT);
if ($fade === false || ($fade !== null && ($fade < 0.15 || $fade > 1))) api_err('fade must be between 0.15 and 1', 400);
$fade = $fade === null ? 1.0 : (float)$fade;

// Basemap for print: 'legible' (largest lettering that clears 150 dpi) or
// 'sharp' (one tile level deeper, embedded at up to ~600 dpi).
$basemapRes = (string)($_GET['basemap_res'] ?? 'legible');
if (!in_array($basemapRes, ['legible', 'sharp'], true)) api_err('basemap_res must be legible or sharp', 400);

// A saved plan (app.map_plan) whose title block the sheet should carry. The
// renderer opens the map with ?plan=N, the same page the Open button shows, and
// map_plan.php enforces who may see it.
$planId = filter_input(INPUT_GET, 'plan', FILTER_VALIDATE_INT);
if ($planId === false || ($planId !== null && $planId < 1)) api_err('plan must be a positive integer', 400);

// Only the basemaps the layers control actually offers; anything else would just
// silently render the default.
$BASEMAPS = ['Street', 'Street (Grey)', 'Satellite (ESRI)', 'Satellite (Google)', 'Satellite (Google) Grey', 'B&W Plan'];
$basemap  = (string)($_GET['basemap'] ?? '');
if ($basemap === 'Grey') $basemap = 'Street (Grey)';   // renamed
if ($basemap !== '' && !in_array($basemap, $BASEMAPS, true)) {
    api_err('unknown basemap', 400);
}

// The map filter the user has on screen (work order, Bell permit, hidden road
// types…), as window.fpmMapFilterState() serialises it. The renderer's
// browser has none of the user's localStorage, so without this a filtered view
// would come back as a sheet of the whole network. Shape-checked only — the
// values are free text the map page already treats as user input.
$filter = null;
if (isset($_GET['filter']) && $_GET['filter'] !== '') {
    if (strlen((string)$_GET['filter']) > 4096) api_err('filter too long', 400);
    $filter = json_decode((string)$_GET['filter'], true);
    if (!is_array($filter)) api_err('filter must be a JSON object', 400);
    $values = $filter['values'] ?? [];
    if (!is_array($values)) api_err('filter.values must be an object', 400);
    foreach ($values as $k => $v) {
        if (!is_string($k) || !is_string($v)) api_err('filter.values must map strings to strings', 400);
    }
    $road = $filter['roadHidden'] ?? [];
    if (!is_array($road)) api_err('filter.roadHidden must be an array', 400);
    foreach ($road as $v) {
        if (!is_string($v)) api_err('filter.roadHidden must be strings', 400);
    }
    // Layer toggles + label mode: flat map of name → bool, as saveLayerState()
    // writes it. The renderer seeds its browser's localStorage with it before
    // the map page loads, so the map comes up with those layers.
    $layers = $filter['layers'] ?? null;
    if ($layers !== null) {
        if (!is_array($layers) || count($layers) > 64) api_err('filter.layers must be a small object', 400);
        foreach ($layers as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z]{1,32}$/', $k) || !is_bool($v)) {
                api_err('filter.layers must map names to booleans', 400);
            }
        }
        $layers = (object)$layers;
    }
    // Layers ticked under "Add back" in the filter panel (hidden by a permit filter otherwise).
    $addBackIn = $filter['addBack'] ?? [];
    if (!is_array($addBackIn)) api_err('filter.addBack must be an array', 400);
    $addBack = [];
    foreach ($addBackIn as $v) {
        if (!is_string($v) || !in_array($v, ['customers', 'overbuild', 'easements', 'survey', 'alden'], true)) {
            api_err('filter.addBack has an unknown layer', 400);
        }
        $addBack[] = $v;
    }
    $filter = [
        'addBack'       => array_values(array_unique($addBack)),
        'values'        => (object)$values,
        'hasWO'         => !empty($filter['hasWO']),
        'hasBellPermit' => !empty($filter['hasBellPermit']),
        'roadHidden'    => array_values($road),
        'layers'        => $layers,
    ];
}

$spec = [
    'lat'       => (float)$lat,
    'lon'       => (float)$lon,
    'zoom'      => (int)$zoom,
    'landscape' => $orientation === 'landscape',
    'paper'     => $paper,
    'fit'       => $fit,
    'fade'      => $fade,
    'basemapRes' => $basemapRes,
];
if ($basemap !== '') $spec['basemap'] = $basemap;
if ($planId !== null) $spec['plan'] = (int)$planId;
if ($filter !== null) $spec['filter'] = $filter;

// The renderer loads the map page as this user, so it needs their session. Hand
// over the cookie rather than a second credential — it is the same trust
// boundary, and the sheet must show exactly what this user is allowed to see.
if (!empty($_COOKIE[session_name()])) {
    $spec['cookie'] = ['name' => session_name(), 'value' => (string)$_COOKIE[session_name()]];
}

// PHP holds an exclusive lock on the session file for the life of the request.
// The renderer is about to request a page as this same user, which would block
// on that lock until this request times out — so let it go now. Nothing below
// writes to $_SESSION.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$endpoint = getenv('PLAN_PDF_URL') ?: 'http://127.0.0.1:8091/render';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($spec, JSON_THROW_ON_ERROR),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    // A cold render is a browser launch, a map load and a tile wait; a fitted
    // sheet at 25 % is sixteen papers' worth of tiles and features. Generous,
    // but bounded so a wedged renderer cannot pin an Apache worker forever.
    CURLOPT_TIMEOUT        => (int)(120 + 240 / ($fit * $fit)),
]);
$response = curl_exec($ch);

if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    error_log('plan_pdf: renderer unreachable: ' . $err);
    api_err('The PDF renderer is not reachable. Is the playwright service running?', 502);
}

$status     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawHeaders = substr($response, 0, $headerSize);
$body       = substr($response, $headerSize);

if ($status !== 200) {
    $detail = json_decode($body, true);
    error_log('plan_pdf: renderer returned ' . $status . ': ' . substr($body, 0, 500));
    api_err('PDF render failed: ' . (string)($detail['error'] ?? 'unknown error'), 502);
}
if (strncmp($body, '%PDF', 4) !== 0) {
    error_log('plan_pdf: renderer returned a non-PDF body');
    api_err('PDF render failed: the renderer did not return a PDF', 502);
}

if (preg_match('/^X-Render-Warnings:\s*(.+)$/mi', $rawHeaders, $m)) {
    error_log('plan_pdf: render warnings: ' . trim($m[1]));
}

$name = 'plan-' . number_format((float)$lat, 5, '.', '') . '_'
      . number_format((float)$lon, 5, '.', '') . '-z' . (int)$zoom
      . '-' . $paper . ($fit < 1 ? '-' . (int)round($fit * 100) . 'pct' : '') . '.pdf';

// _common.php declared JSON; this response is not.
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
echo $body;
