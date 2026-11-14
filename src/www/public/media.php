<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// media.php?id=<timeline_media_id>
// Thin HTTP wrapper around phpHelpers/media.helper.php

require __DIR__ . '/../app/config.php';
require __DIR__ . '/phpHelpers/media.helper.php';  // adjust path if needed

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$media = media_fetch($pdo, $id);

if (!$media) {
    http_response_code(404);
    exit('Not found');
}

// ?w=N asks for a small JPEG (map popups); the original is untouched.
$thumbW = isset($_GET['w']) ? max(60, min(800, (int)$_GET['w'])) : 0;

// Caching headers
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . ($thumbW ? substr($media['etag'], 0, -1) . "-w$thumbW\"" : $media['etag']));

// Conditional request support (If-None-Match)
$clientEtags = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
$sentEtag = $thumbW ? substr($media['etag'], 0, -1) . "-w$thumbW\"" : $media['etag'];
if (media_etag_matches($clientEtags, $sentEtag)) {
    http_response_code(304);
    exit;
}

if ($thumbW) {
    $raw = $media['is_stream'] ? stream_get_contents($media['blob']) : $media['blob'];
    $img = @imagecreatefromstring($raw);
    if ($img !== false) {
        if (function_exists('exif_read_data') && $media['mime'] === 'image/jpeg') {
            $ex  = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
            $rot = [3 => 180, 6 => -90, 8 => 90][$ex['Orientation'] ?? 1] ?? 0;
            if ($rot) $img = imagerotate($img, $rot, 0);
        }
        $w = imagesx($img);
        if ($w > $thumbW) $img = imagescale($img, $thumbW);
        header('Content-Type: image/jpeg');
        imagejpeg($img, null, 78);
        exit;
    }
}

// Content headers
header('Content-Type: ' . $media['mime']);
if ($media['length'] !== null) {
    header('Content-Length: ' . $media['length']);
}

// Stream or echo the blob
if ($media['is_stream']) {
    // PDO returned a stream; let PHP chunk it out.
    fpassthru($media['blob']);
} else {
    echo $media['blob'];
}
