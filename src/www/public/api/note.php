<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/note.php        — create map note
// PUT    /api/note.php?id=N   — update note (body, icon, or geometry)
// DELETE /api/note.php?id=N   — delete note

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

$validIcons = ['note', 'tree_trim', 'construction'];

// ── CREATE ────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    api_require_csrf();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $icon    = trim($body['icon']    ?? 'note');
    $text    = trim($body['body']    ?? '');
    $geojson = $body['geojson']       ?? null;
    $wor     = trim((string)($body['fpm_wor'] ?? '')) ?: null;

    if (!in_array($icon, $validIcons))                              api_err('Invalid icon', 400);
    if (!$geojson || ($geojson['type'] ?? '') !== 'Point')          api_err('geojson Point required', 400);

    $st = $pdo->prepare("
        INSERT INTO app.map_note (icon, body, fpm_wor, geom)
        VALUES (:icon, :body, :wor, ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326))
        RETURNING id
    ");
    $st->execute([
        ':icon'    => $icon,
        ':body'    => $text,
        ':wor'     => $wor,
        ':geojson' => json_encode($geojson),
    ]);
    api_ok(['note_id' => (int)$st->fetchColumn()]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    api_require_csrf();
    $id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $wor = trim((string)($_GET['wor'] ?? ''));
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];

    // Done / reopen. ?id=N flips one note; ?wor=<work order>[&icon=tree_trim] flips every open (or done) note of
    // that work order, so finishing a job's tree trimming is one click.
    if (array_key_exists('done', $body) && !array_key_exists('icon', $body)) {
        $done = $body['done'] ? true : false;
        if ($id) {
            $st = $pdo->prepare("UPDATE app.map_note SET done_at = CASE WHEN :done THEN now() END, updated_at = now() WHERE id = :id");
            $st->execute([':done' => $done ? 'true' : 'false', ':id' => $id]);
        } elseif ($wor !== '') {
            $icon = trim((string)($_GET['icon'] ?? ''));
            if ($icon !== '' && !in_array($icon, $validIcons)) api_err('Invalid icon', 400);
            $st = $pdo->prepare("UPDATE app.map_note SET done_at = CASE WHEN :done THEN now() END, updated_at = now()
                                  WHERE fpm_wor = :wor AND (:icon = '' OR icon = :icon2)
                                    AND (done_at IS NULL) = :done2");
            $st->execute([':done' => $done ? 'true' : 'false', ':wor' => $wor, ':icon' => $icon, ':icon2' => $icon, ':done2' => $done ? 'true' : 'false']);
        } else {
            api_err('id or wor required', 400);
        }
        if ($st->rowCount() === 0 && $id) api_err('Note not found', 404);
        api_ok(['changed' => $st->rowCount()]);
    }

    if (!$id) api_err('id required', 400);

    $icon    = trim($body['icon'] ?? 'note');
    $text    = trim($body['body'] ?? '');
    $geojson = $body['geojson'] ?? null;
    $wor     = trim((string)($body['fpm_wor'] ?? '')) ?: null;

    if (!in_array($icon, $validIcons)) api_err('Invalid icon', 400);

    if ($geojson) {
        if (($geojson['type'] ?? '') !== 'Point') api_err('geojson must be Point', 400);
        $st = $pdo->prepare("
            UPDATE app.map_note
               SET icon       = :icon,
                   body       = :body,
                   fpm_wor = :wor,
                   geom       = ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326),
                   updated_at = now()
             WHERE id = :id
        ");
        $st->execute([':icon' => $icon, ':body' => $text, ':wor' => $wor, ':geojson' => json_encode($geojson), ':id' => $id]);
    } else {
        $st = $pdo->prepare("
            UPDATE app.map_note
               SET icon       = :icon,
                   body       = :body,
                   fpm_wor = :wor,
                   updated_at = now()
             WHERE id = :id
        ");
        $st->execute([':icon' => $icon, ':body' => $text, ':wor' => $wor, ':id' => $id]);
    }
    if ($st->rowCount() === 0) api_err('Note not found', 404);
    api_ok(['note_id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $st = $pdo->prepare("DELETE FROM app.map_note WHERE id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('Note not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
