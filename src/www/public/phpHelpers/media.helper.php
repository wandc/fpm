<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/media.helper.php
// Streaming media for timeline (and other) use in Tinuum webapp.





// phpHelpers/media.helper.php
// Shared helper for serving timeline_media blobs.
//
// Responsibilities:
//   - Fetch a timeline_media row by id
//   - Normalize blob (handle BYTEA hex vs stream)
//   - Build stable ETag
//
// This helper is intentionally framework-agnostic: it does NOT
// send headers, set status codes, or exit(). Callers are
// responsible for HTTP details.



if (!function_exists('media_fetch')) {

    /**
     * Fetch a media row and normalize the blob.
     *
     * Returns:
     *   null if not found or id invalid
     *   array{
     *     id: int,
     *     mime: string,
     *     etag: string,
     *     ts: string|float|null,
     *     blob: resource|string,
     *     is_stream: bool,
     *     length: ?int
     *   }
     */
    function media_fetch(PDO $pdo, int $id): ?array {
        if ($id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT blob, mime_type, extract(epoch from created_at) AS ts
              FROM app.timeline_media
             WHERE timeline_media_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $mime = $row['mime_type'] ?: 'application/octet-stream';
        $ts   = $row['ts'] ?? null;

        // Same ETag logic as old media.php
        $etag = '"' . sha1($id . '|' . $ts . '|' . $mime) . '"';

        $blob     = $row['blob'];
        $isStream = is_resource($blob);

        // Decode Postgres BYTEA hex ("\x89504e...") if we got a string
        if (!$isStream && is_string($blob) && strncmp($blob, "\\x", 2) === 0) {
            $blob = hex2bin(substr($blob, 2));
        }

        $length = $isStream ? null : strlen($blob);

        return [
            'id'        => $id,
            'mime'      => $mime,
            'etag'      => $etag,
            'ts'        => $ts,
            'blob'      => $blob,
            'is_stream' => $isStream,
            'length'    => $length,
        ];
    }

    /**
     * Check if a client-supplied If-None-Match header matches our ETag.
     * Accepts comma-separated lists per RFC.
     */
    function media_etag_matches(?string $clientHeader, string $ourEtag): bool {
        if ($clientHeader === null) return false;
        foreach (explode(',', $clientHeader) as $tag) {
            if (trim($tag) === $ourEtag) {
                return true;
            }
        }
        return false;
    }
}


if (!function_exists('timeline_stream_media')) {

    /**
     * Stream a single media blob from app.timeline_media by id.
     *
     * This function:
     *  - validates id
     *  - looks up blob + mime_type + created_at
     *  - sends cache headers + ETag
     *  - handles 304 If-None-Match
     *  - streams either a PDO LOB or a BYTEA hex string
     *
     * It sends headers and exits on completion.
     */
    function timeline_stream_media(PDO $pdo, int $id): void
    {
        if ($id <= 0) {
            http_response_code(400);
            echo 'Bad media id';
            exit;
        }

        $stmt = $pdo->prepare("
          SELECT blob, mime_type, extract(epoch from created_at) AS ts
            FROM app.timeline_media
           WHERE timeline_media_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }

        $mime = $row['mime_type'] ?: 'application/octet-stream';
        $etag = '"' . sha1($id . '|' . $row['ts'] . '|' . $mime) . '"';

        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);

        // ETag handling
        $clientTag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientTag !== '' && trim($clientTag) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . $mime);

        $blob = $row['blob'];

        // If PDO returned a stream (LOB), pass it through without loading into RAM
        if (is_resource($blob)) {
            // Size unknown; omit Content-Length to allow chunked encoding
            fpassthru($blob);
            exit;
        }

        // If BYTEA is hex encoded: "\x89504e..." → decode to raw bytes
        if (is_string($blob) && strncmp($blob, "\\x", 2) === 0) {
            $blob = hex2bin(substr($blob, 2));
        }

        if (is_string($blob)) {
            header('Content-Length: ' . strlen($blob));
            echo $blob;
        } else {
            // Fallback: unknown type
            http_response_code(500);
            echo 'Bad media blob';
        }

        exit;
    }
}
