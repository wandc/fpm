<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// api/document_serve.php — stream a stored document to the browser
// ?id=N           → download (Content-Disposition: attachment)
// ?id=N&inline=1  → open in browser (Content-Disposition: inline)

require __DIR__ . '/../../app/config.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$stmt = $pdo->prepare("
    SELECT title, filename, mime_type, file_size,
           encode(file_data, 'hex') AS file_hex
    FROM app.document
    WHERE document_id = :id
");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Not found');
}

$inline = (($_GET['inline'] ?? '') === '1');
$disp   = $inline ? 'inline' : 'attachment';
$safe   = str_replace('"', '', $doc['filename']);

header('Content-Type: '        . $doc['mime_type']);
header('Content-Disposition: ' . $disp . '; filename="' . $safe . '"');
if ($doc['file_size']) {
    header('Content-Length: ' . $doc['file_size']);
}
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

echo hex2bin($doc['file_hex']);
