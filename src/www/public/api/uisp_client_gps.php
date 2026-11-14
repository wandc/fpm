<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/uisp_client_gps.php
// POST { crm_client_id, lat, lon } — move a client's GPS point in UISP CRM (live data).
// Uses UCRM_API_KEY_WRITE; the read-only key cannot do this. Every move is logged in
// app.uisp_client_gps_log with the old and new point.

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

if (api_method() !== 'POST') api_err('POST only', 405);
api_require_csrf();
$b = api_json_body();

$id  = (int)($b['crm_client_id'] ?? 0);
$lat = $b['lat'] ?? null;
$lon = $b['lon'] ?? null;
if ($id <= 0) api_err('crm_client_id is required');
if (!is_numeric($lat) || !is_numeric($lon)) api_err('lat and lon are required');
$lat = round((float)$lat, 7);
$lon = round((float)$lon, 7);
// New Brunswick and a margin: catches a swapped or mistyped point before it reaches UISP.
if ($lat < 44.0 || $lat > 49.0 || $lon < -69.5 || $lon > -63.0) api_err('Point is outside New Brunswick', 400);

$base = rtrim(getenv('UISP_API_URL') ?: '', '/') . '/crm/api/v1.0/clients/' . $id;
$wKey = getenv('UCRM_API_KEY_WRITE') ?: '';
$rKey = getenv('UCRM_API_KEY') ?: '';
if ($wKey === '') api_err('UISP write key is not configured', 500);

function uisp_client_call(string $method, string $url, string $key, ?array $body = null): array
{
    $ch = curl_init($url);
    $h  = ["x-auth-app-key: {$key}", 'Accept: application/json'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$raw, true)];
}

[$code, $client] = uisp_client_call('GET', $base, $rKey ?: $wKey);
if ($code === 404) api_err('Client not found in UISP', 404);
if ($code !== 200 || !is_array($client)) api_err('Could not read the client from UISP (HTTP ' . $code . ')', 502);

[$code, $resp] = uisp_client_call('PATCH', $base, $wKey, ['addressGpsLat' => $lat, 'addressGpsLon' => $lon]);
if ($code !== 200) {
    error_log('uisp_client_gps PATCH client ' . $id . ' HTTP ' . $code . ' ' . json_encode($resp));
    api_err('UISP refused the change (HTTP ' . $code . '): ' . (string)($resp['message'] ?? 'unknown'), 502);
}

$name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: ($client['companyName'] ?? '');
$who  = fpm_current_identity();
$pdo->prepare('INSERT INTO app.uisp_client_gps_log (crm_client_id, client_name, old_lat, old_lon, new_lat, new_lon, actor)
               VALUES (?,?,?,?,?,?,?)')
    ->execute([$id, $name, $client['addressGpsLat'] ?? null, $client['addressGpsLon'] ?? null, $lat, $lon,
               ($who['email'] ?: $who['username']) ?: null]);

// The map reads clients through a 5-minute cache; drop it so the move shows straight away.
$pdo->exec("DELETE FROM app.uisp_cache WHERE endpoint LIKE 'ucrm/clients%'");

api_ok(['crm_client_id' => $id, 'lat' => $lat, 'lon' => $lon]);
