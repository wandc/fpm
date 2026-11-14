<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/timeline.php?parent_type=conduit|vault|fosc|pop&parent_id=N  — entries, newest first, with photo ids
// GET    /api/timeline.php?summary=1&parent_type=conduit|vault|fosc|pop&parent_id=N — {entries, photos} counts
// POST   /api/timeline.php  (multipart: parent_type, parent_id, body_text, images[]) — add an entry
// DELETE /api/timeline.php?id=N — delete an entry and its photos (conduit, vault, closure and POP entries)

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/upload.helper.php';

/** @var PDO $pdo */

const TL_PARENTS = ['conduit' => 'conduit_id', 'vault' => 'vault_id', 'fosc' => 'fosc_id', 'pop' => 'pop_id'];

function tl_parent(string $type, string $id): void
{
    if (!isset(TL_PARENTS[$type]) || !ctype_digit($id)) api_err('Bad parent', 400);
    global $pdo;
    $st = $pdo->prepare('SELECT 1 FROM app.' . $type . ' WHERE ' . TL_PARENTS[$type] . ' = :id');
    $st->execute([':id' => (int)$id]);
    if (!$st->fetchColumn()) api_err('Not found', 404);
}

$method = api_method();

// ?summary=1: how many notes and photos an asset has, for the delete warning (also closures and POPs).
if ($method === 'GET' && isset($_GET['summary'])) {
    $type = (string)($_GET['parent_type'] ?? '');
    $pid  = (string)($_GET['parent_id'] ?? '');
    if (!in_array($type, ['conduit', 'vault', 'fosc', 'pop'], true) || !ctype_digit($pid)) api_err('Bad parent', 400);
    $st = $pdo->prepare("
        SELECT count(DISTINCT e.timeline_entry_id) AS entries, count(m.timeline_media_id) AS photos
          FROM app.timeline_entry e
          LEFT JOIN app.timeline_media m ON m.timeline_entry_id = e.timeline_entry_id
         WHERE e.parent_type = :pt AND e.parent_id = :pid
    ");
    $st->execute([':pt' => $type, ':pid' => $pid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    api_ok(['entries' => (int)$r['entries'], 'photos' => (int)$r['photos']]);
}

if ($method === 'GET') {
    $type = (string)($_GET['parent_type'] ?? '');
    $pid  = (string)($_GET['parent_id'] ?? '');
    tl_parent($type, $pid);
    $st = $pdo->prepare("
        SELECT e.timeline_entry_id AS id, e.body_text AS text,
               to_char(e.created_at AT TIME ZONE 'America/Moncton', 'YYYY-MM-DD HH24:MI') AS at,
               COALESCE((SELECT json_agg(m.timeline_media_id ORDER BY COALESCE(m.exif_created_at, m.created_at), m.timeline_media_id)
                           FROM app.timeline_media m WHERE m.timeline_entry_id = e.timeline_entry_id), '[]'::json) AS photos
          FROM app.timeline_entry e
         WHERE e.parent_type = :pt AND e.parent_id = :pid
         ORDER BY e.created_at DESC, e.timeline_entry_id DESC
    ");
    $st->execute([':pt' => $type, ':pid' => $pid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['photos'] = json_decode($r['photos'], true); }
    api_ok(['entries' => $rows]);
}

if ($method === 'POST') {
    api_require_csrf();
    $type = (string)($_POST['parent_type'] ?? '');
    $pid  = (string)($_POST['parent_id'] ?? '');
    tl_parent($type, $pid);
    $body = trim((string)($_POST['body_text'] ?? ''));
    $meta = ['uploads' => [], 'errors' => [], 'entry_taken_at' => null, 'entry_lat' => null, 'entry_lon' => null];
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
        $meta = upload_process_files($_FILES['images']);
    }
    if ($meta['errors']) api_err(implode(' ', $meta['errors']), 400);
    if ($body === '' && !$meta['uploads']) api_err('Add a note or a photo', 400);

    $res = upload_save_timeline_entry($pdo, [
        'id' => 0, 'parent_type' => $type, 'parent_id' => $pid, 'body_text' => $body,
        'exif_time' => $meta['entry_taken_at'], 'exif_lat' => $meta['entry_lat'], 'exif_lon' => $meta['entry_lon'],
        'uploads' => $meta['uploads'],
    ]);
    if ($res['errors']) api_err(implode(' ', $res['errors']), 500);
    api_ok(['entry_id' => $res['entry_id']]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM app.timeline_entry WHERE timeline_entry_id = :id AND parent_type IN ('conduit','vault','fosc','pop')");
    $st->execute([':id' => $id]);
    if (!$st->rowCount()) api_err('Not found', 404);
    api_ok();
}

api_err('Method not allowed', 405);
