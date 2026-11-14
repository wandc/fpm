<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/phpHelpers/uisp.helper.php

/**
 * Fetch data from the UISP NMS API.
 * Returns decoded JSON array/object on success, or ['error' => '...'] on failure.
 */
function uisp_fetch(string $endpoint): array
{
    $baseUrl = rtrim(getenv('UISP_API_URL') ?: '', '/');
    $apiKey  = getenv('UISP_API_KEY') ?: '';
    $url     = $baseUrl . '/nms/api/v2.1/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ["x-auth-token: {$apiKey}", 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return ['error' => "UISP API request failed: {$error} ({$url})"];
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['error' => 'Invalid JSON from UISP API: ' . substr($raw, 0, 200)];
    }
    return $decoded;
}

/**
 * Fetch from UISP API with DB-based TTL cache (app.uisp_cache).
 * $ttl seconds before a fresh fetch is made. On API failure, serves stale cache.
 * Pass $pdo to enable caching; without it, data is fetched live every call.
 */
function uisp_fetch_cached(string $endpoint, int $ttl = 120, ?PDO $pdo = null): array
{
    if ($pdo !== null) {
        // ── Check DB cache ────────────────────────────────────────────────────
        $st = $pdo->prepare(
            'SELECT data, fetched_at FROM app.uisp_cache WHERE endpoint = ?'
        );
        $st->execute([$endpoint]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row !== false && (time() - strtotime($row['fetched_at'])) < $ttl) {
            $data = json_decode($row['data'], true);
            if ($data !== null) return $data;
        }

        // ── Cache miss or stale — fetch fresh ─────────────────────────────────
        $fresh = uisp_fetch($endpoint);

        if (!isset($fresh['error'])) {
            $pdo->prepare(
                'INSERT INTO app.uisp_cache (endpoint, data, fetched_at)
                 VALUES (?, ?::jsonb, now())
                 ON CONFLICT (endpoint) DO UPDATE
                   SET data = EXCLUDED.data, fetched_at = EXCLUDED.fetched_at'
            )->execute([$endpoint, json_encode($fresh)]);
            return $fresh;
        }

        // ── API down — return stale data if available ─────────────────────────
        if ($row !== false) {
            $data = json_decode($row['data'], true);
            if ($data !== null) return $data;
        }

        return $fresh; // ['error' => '...'] — nothing we can do
    }

    // ── No $pdo: fetch live (no caching) ─────────────────────────────────────
    return uisp_fetch($endpoint);
}

/**
 * Fetch from the UCRM (CRM) API with DB-based TTL cache.
 * Uses x-auth-app-key (UCRM_API_KEY env var) against /crm/api/v1.0/<endpoint>.
 * Cache key in app.uisp_cache is "ucrm/<endpoint>".
 */
function ucrm_fetch_cached(string $endpoint, int $ttl = 300, ?PDO $pdo = null): array
{
    $baseUrl  = rtrim(getenv('UISP_API_URL') ?: '', '/');
    $apiKey   = getenv('UCRM_API_KEY') ?: '';
    $cacheKey = 'ucrm/' . $endpoint;

    if ($pdo !== null) {
        $st = $pdo->prepare('SELECT data, fetched_at FROM app.uisp_cache WHERE endpoint = ?');
        $st->execute([$cacheKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && (time() - strtotime($row['fetched_at'])) < $ttl) {
            $data = json_decode($row['data'], true);
            if ($data !== null) return $data;
        }
    }

    $url = $baseUrl . '/crm/api/v1.0/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ["x-auth-app-key: {$apiKey}", 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return isset($row) && $row ? (json_decode($row['data'], true) ?: []) : ['error' => 'UCRM request failed'];
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null || isset($decoded['code'])) {
        return isset($row) && $row ? (json_decode($row['data'], true) ?: []) : ['error' => 'UCRM error'];
    }

    if ($pdo !== null) {
        $pdo->prepare(
            'INSERT INTO app.uisp_cache (endpoint, data, fetched_at)
             VALUES (?, ?::jsonb, now())
             ON CONFLICT (endpoint) DO UPDATE
               SET data = EXCLUDED.data, fetched_at = EXCLUDED.fetched_at'
        )->execute([$cacheKey, json_encode($decoded)]);
    }

    return $decoded;
}

/**
 * Format uptime seconds as "Xd Xh Xm".
 */
function uisp_format_uptime(?int $seconds): string
{
    if ($seconds === null) return '—';
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($d) $parts[] = "{$d}d";
    if ($h) $parts[] = "{$h}h";
    $parts[] = "{$m}m";
    return implode(' ', $parts);
}

/**
 * Format bits/s as "X Mbps" or "X Gbps".
 */
function uisp_format_speed(?int $bps): string
{
    if ($bps === null) return '—';
    if ($bps >= 1_000_000_000) return round($bps / 1_000_000_000, 1) . ' Gbps';
    return round($bps / 1_000_000) . ' Mbps';
}

/**
 * GPON receive signal quality — canonical threshold function.
 * Normal range: -8 dBm (max) to -28 dBm (min).
 *   > -6.5 dBm          : danger  — above GPON maximum (overloaded)
 *   -8.0 to -6.5 dBm    : warning — upper edge
 *   -28.0 to -8.0 dBm   : success — normal operating range
 *   -29.5 to -28.0 dBm  : warning — lower edge
 *   < -29.5 dBm         : danger  — below GPON minimum
 *
 * Returns ['class' => Bootstrap text class, 'color' => hex colour string].
 */
function gpon_signal_info(?float $dbm): array
{
    if ($dbm === null)   return ['class' => 'text-muted',   'color' => '#6c757d'];
    if ($dbm > -6.5)    return ['class' => 'text-danger',  'color' => '#dc3545']; // above GPON max
    if ($dbm > -8.0)    return ['class' => 'text-warning', 'color' => '#E0A800']; // upper edge
    if ($dbm >= -28.0)  return ['class' => 'text-success', 'color' => '#198754']; // normal range
    if ($dbm >= -29.5)  return ['class' => 'text-warning', 'color' => '#E0A800']; // lower edge
    return                     ['class' => 'text-danger',  'color' => '#dc3545']; // below GPON min
}

/** Convenience wrapper — returns Bootstrap text class only. */
function gpon_signal_class(?float $dbm): string
{
    return gpon_signal_info($dbm)['class'];
}
