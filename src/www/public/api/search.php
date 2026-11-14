<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET /api/search.php?q=<term>
// Global autocomplete search. Returns up to 12 results per category:
//   closures  — by splice_num (prefix-first, then contains); links to the map
//   poles     — by uid, fpm_wor, nbp#, telco#, tags#
//   span_tags — by name

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

if (api_method() !== 'GET') api_err('Method not allowed', 405);

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || strlen($q) < 2) {
    api_ok(['closures' => [], 'poles' => [], 'span_tags' => []]);
}

$prefix  = $q . '%';
$anywhere = '%' . $q . '%';

// ── Closures ─────────────────────────────────────────────────────────────────
// Prefix match first (score=1), then contains (score=2). Dedup by fosc_id.
$stFosc = $pdo->prepare("
    SELECT DISTINCT ON (fosc_id)
           fosc_id,
           splice_num,
           status,
           COALESCE(attrs->>'street_address', '') AS address
      FROM app.fosc
     WHERE splice_num ILIKE :anywhere
     ORDER BY fosc_id,
              CASE WHEN splice_num ILIKE :prefix THEN 1 ELSE 2 END,
              splice_num
     LIMIT 12
");
$stFosc->execute([':anywhere' => $anywhere, ':prefix' => $prefix]);
$fosc_rows = $stFosc->fetchAll(PDO::FETCH_ASSOC);

$closures = [];
foreach ($fosc_rows as $r) {
    $sub = $r['address'] !== '' ? $r['address'] : $r['status'];
    $closures[] = [
        'label'    => $r['splice_num'],
        'sublabel' => $sub,
        'status'   => $r['status'],
        // Land on the map with the closure's popup open, the way poles do —
        // map.php reads ?fosc_id and runDetailStartup() pans and opens it.
        'url'      => '/index.php?page=map&fosc_id=' . (int)$r['fosc_id'],
    ];
}

// ── Poles ─────────────────────────────────────────────────────────────────────
$stPole = $pdo->prepare('
    SELECT fid, uid, "fpm_wor", "nbp#", "telco#", "tags#", owner,
           ST_Y(geom::geometry) AS lat,
           ST_X(geom::geometry) AS lon
      FROM app.pole
     WHERE uid          ILIKE :q
        OR "fpm_wor" ILIKE :q
        OR "nbp#"       ILIKE :q
        OR "telco#"     ILIKE :q
        OR "tags#"      ILIKE :q
     ORDER BY uid
     LIMIT 12
');
$stPole->execute([':q' => $anywhere]);
$pole_rows = $stPole->fetchAll(PDO::FETCH_ASSOC);

$poles = [];
foreach ($pole_rows as $r) {
    // Build a readable label: prefer uid, then work order
    $label = $r['uid'] !== '' && $r['uid'] !== null ? $r['uid'] : ('Pole #' . $r['fid']);
    // Sublabel: show what matched (work order, NBP, telco, tags)
    $bits = [];
    if ($r['fpm_wor'] && stripos($r['fpm_wor'], $q) !== false) $bits[] = 'WO: ' . $r['fpm_wor'];
    if ($r['nbp#']       && stripos($r['nbp#'],       $q) !== false) $bits[] = 'NBP: ' . $r['nbp#'];
    if ($r['telco#']     && stripos($r['telco#'],     $q) !== false) $bits[] = 'Telco: ' . $r['telco#'];
    if ($r['tags#']      && stripos($r['tags#'],      $q) !== false) $bits[] = 'Tags: ' . $r['tags#'];
    if ($r['owner'])  $bits[] = $r['owner'];
    $sub = implode(' · ', $bits);

    $poles[] = [
        'label'    => $label,
        'sublabel' => $sub,
        'lat'      => $r['lat'] !== null ? (float)$r['lat'] : null,
        'lon'      => $r['lon'] !== null ? (float)$r['lon'] : null,
        'fid'      => (int)$r['fid'],
        'url'      => '/index.php?page=map&pole_fid=' . (int)$r['fid'],
    ];
}

// ── Span tags ─────────────────────────────────────────────────────────────────
$stTag = $pdo->prepare("
    SELECT span_tag_id, name, notes
      FROM app.span_tag
     WHERE name ILIKE :q
     ORDER BY name
     LIMIT 12
");
$stTag->execute([':q' => $anywhere]);
$tag_rows = $stTag->fetchAll(PDO::FETCH_ASSOC);

$span_tags = [];
foreach ($tag_rows as $r) {
    $span_tags[] = [
        'label'    => $r['name'],
        'sublabel' => $r['notes'] ?? '',
        'url'      => '/index.php?page=span_tags&tag_id=' . (int)$r['span_tag_id'],
    ];
}

api_ok([
    'closures'  => $closures,
    'poles'     => $poles,
    'span_tags' => $span_tags,
]);
