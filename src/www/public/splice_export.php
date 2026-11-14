<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// public/splice_export.php — machine-readable snapshot of the splice plant, for
// external review (typically handed to an AI agent and asked "what looks wrong?").
// Mirrors tender_export.php: auth-gated GET, no CSRF (CSRF here is mutation-only).
//
//   (no args)                 → whole plant, pretty JSON, inline
//   ?download=1               → same, as a file
//   ?fosc=N&hops=3            → that closure plus everything within N span hops
//   ?wor=TIN-1234             → closures and spans on one Tinuum permit
//   ?compact=1                → drop notes, coordinates and timestamps
//   ?plan=0                   → as-built only, skip planned splices

require __DIR__ . '/../app/config.php';                       // $pdo
require __DIR__ . '/phpHelpers/app-helper.php';               // session
require __DIR__ . '/../app/user_identity.php';                // fpm_current_identity()
require __DIR__ . '/phpHelpers/splice_export.helper.php';     // splice_export_array()

$identity = fpm_current_identity();
if (!($identity['is_authenticated'] ?? false)) {
    http_response_code(401);
    exit('Not authenticated');
}

$foscId = (int)($_GET['fosc'] ?? 0);
$wor    = trim((string)($_GET['wor'] ?? ''));

$opts = [
    'fosc_id'      => $foscId > 0 ? $foscId : 0,
    'hops'         => (int)($_GET['hops'] ?? 3),
    'wor'          => $wor,
    'compact'      => ($_GET['compact'] ?? '') === '1',
    'include_plan' => ($_GET['plan'] ?? '1') !== '0',
];

$data = splice_export_array($pdo, $opts);

if (!$data['closures']) {
    http_response_code(404);
    exit('Nothing in scope');
}

// Name the file after what it holds, so a folder of these stays readable.
if ($foscId > 0) {
    $label = $data['closures'][0]['splice_num'] ?? ('fosc' . $foscId);
    foreach ($data['closures'] as $c) {          // the requested closure, not just the lowest id
        if ((int)$c['fosc_id'] === $foscId) { $label = $c['splice_num']; break; }
    }
    $slug = 'closure_' . $label . '_h' . $opts['hops'];
} elseif ($wor !== '') {
    $slug = 'wo_' . $wor;
} else {
    $slug = 'plant';
}
$slug  = preg_replace('/[^A-Za-z0-9_-]+/', '_', $slug);
$fname = 'fpm_splice_' . $slug . '_' . date('Y-m-d') . '.json';

header('X-Content-Type-Options: nosniff');
if (($_GET['download'] ?? '') === '1') {
    header('Content-Disposition: attachment; filename="' . $fname . '"');
}
header('Content-Type: application/json; charset=utf-8');

// Pretty by default so the file reads in a browser tab. compact=1 is the mode meant
// to be pasted into a model, where indentation is just tokens, so it is minified.
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (!$opts['compact']) $flags |= JSON_PRETTY_PRINT;
echo json_encode($data, $flags);
