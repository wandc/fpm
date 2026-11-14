<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/wifi_ap.php         the model list {models:[{model,label,pattern,beam_deg,range_m}]}
// POST   /api/wifi_ap.php         add an AP {name, model, status, fpm_wor, lat, lon, azimuth_deg?, beam_deg?, range_m?, unifi_mac?, notes?}
// PUT    /api/wifi_ap.php?id=N    edit any of those (azimuth_deg null = omnidirectional / unset; lat+lon moves it)
// DELETE /api/wifi_ap.php?id=N    remove it

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

$method = api_method();

function wa_text($v): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
/** Optional number in [lo, hi]; empty is null. */
function wa_num($v, float $lo, float $hi, string $what): ?float
{
    $s = trim((string)($v ?? ''));
    if ($s === '') return null;
    if (!is_numeric($s) || (float)$s < $lo || (float)$s > $hi) api_err("$what must be a number from $lo to $hi", 400);
    return (float)$s;
}
/** Work order: must be a known permit; empty is none. */
function wa_wor(PDO $pdo, $v): ?string
{
    $v = wa_text($v);
    if ($v === null) return null;
    $st = $pdo->prepare('SELECT 1 FROM app.fpm_permit WHERE permit_num = ?');
    $st->execute([$v]);
    if (!$st->fetchColumn()) api_err('Unknown work order', 400);
    return $v;
}
function wa_model(PDO $pdo, $m): string
{
    $m = trim((string)$m);
    $st = $pdo->prepare('SELECT 1 FROM app.wifi_ap_model WHERE model = ?');
    $st->execute([$m]);
    if (!$st->fetchColumn()) api_err('Unknown model', 400);
    return $m;
}

if ($method === 'GET') {
    $rows = $pdo->query('SELECT model, label, pattern, beam_deg, range_m FROM app.wifi_ap_model ORDER BY label')->fetchAll();
    api_ok(['models' => $rows]);
}

if ($method === 'POST') {
    api_require_csrf();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = wa_text($b['name'] ?? null);
    if ($name === null) api_err('A name is required', 400);
    $status = (string)($b['status'] ?? 'plan');
    if (!in_array($status, ['plan', 'active'], true)) api_err('Invalid status', 400);
    $lat = wa_num($b['lat'] ?? null, -90, 90, 'Latitude');
    $lon = wa_num($b['lon'] ?? null, -180, 180, 'Longitude');
    if ($lat === null || $lon === null) api_err('Position required', 400);
    $st = $pdo->prepare("INSERT INTO app.wifi_ap (name, model, status, fpm_wor, geom, azimuth_deg, beam_deg, range_m, unifi_mac, notes)
                         VALUES (:name, :model, :status, :wor, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), :az, :beam, :rng, :mac, :notes) RETURNING id");
    $st->execute([':name' => $name, ':model' => wa_model($pdo, $b['model'] ?? ''), ':status' => $status, ':wor' => array_key_exists('fpm_wor', $b) ? wa_wor($pdo, $b['fpm_wor']) : wa_wor($pdo, org('default_wifi_wor')), ':lon' => $lon, ':lat' => $lat,
                  ':az' => wa_num($b['azimuth_deg'] ?? null, 0, 359.999, 'Direction'), ':beam' => wa_num($b['beam_deg'] ?? null, 1, 360, 'Beam width'),
                  ':rng' => wa_num($b['range_m'] ?? null, 1, 2000, 'Range'), ':mac' => wa_text($b['unifi_mac'] ?? null), ':notes' => wa_text($b['notes'] ?? null)]);
    api_ok(['id' => (int)$st->fetchColumn()]);
}

if ($method === 'PUT') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sets = []; $p = [':id' => $id];
    if (array_key_exists('name', $b)) {
        $n = wa_text($b['name']); if ($n === null) api_err('A name is required', 400);
        $sets[] = 'name = :name'; $p[':name'] = $n;
    }
    if (array_key_exists('model', $b)) { $sets[] = 'model = :model'; $p[':model'] = wa_model($pdo, $b['model']); }
    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], ['plan', 'active'], true)) api_err('Invalid status', 400);
        $sets[] = 'status = :status'; $p[':status'] = $b['status'];
    }
    foreach (['unifi_mac', 'notes'] as $k) if (array_key_exists($k, $b)) { $sets[] = "$k = :$k"; $p[":$k"] = wa_text($b[$k]); }
    if (array_key_exists('fpm_wor', $b)) { $sets[] = 'fpm_wor = :wor'; $p[':wor'] = wa_wor($pdo, $b['fpm_wor']); }
    if (array_key_exists('azimuth_deg', $b)) { $sets[] = 'azimuth_deg = :az';  $p[':az']   = wa_num($b['azimuth_deg'], 0, 359.999, 'Direction'); }
    if (array_key_exists('beam_deg', $b))    { $sets[] = 'beam_deg = :beam';   $p[':beam'] = wa_num($b['beam_deg'], 1, 360, 'Beam width'); }
    if (array_key_exists('range_m', $b))     { $sets[] = 'range_m = :rng';     $p[':rng']  = wa_num($b['range_m'], 1, 2000, 'Range'); }
    if (isset($b['lat'], $b['lon'])) {
        $sets[] = 'geom = ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)';
        $p[':lat'] = wa_num($b['lat'], -90, 90, 'Latitude'); $p[':lon'] = wa_num($b['lon'], -180, 180, 'Longitude');
    }
    if (!$sets) api_err('Nothing to update', 400);
    $st = $pdo->prepare('UPDATE app.wifi_ap SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $st->execute($p);
    if ($st->rowCount() === 0) api_err('AP not found', 404);
    api_ok(['id' => $id]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) api_err('id required', 400);
    $st = $pdo->prepare('DELETE FROM app.wifi_ap WHERE id = :id');
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) api_err('AP not found', 404);
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
