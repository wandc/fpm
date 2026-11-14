<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/lg_router.php — Real BGP data from our border router via MikroTik REST API

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/mikrotik.helper.php';

if (api_method() !== 'GET') {
    api_err('GET only', 405);
}

$action = trim($_GET['action'] ?? '');

$routers = mikrotik_routers();
if (empty($routers)) {
    api_err('No routers configured', 503);
}

/**
 * Validate a ping/traceroute target: IPv4, IPv6, or a safe hostname/FQDN.
 * Returns true if acceptable, false otherwise.
 */
function valid_network_target(string $t): bool
{
    if ($t === '') return false;
    if (filter_var($t, FILTER_VALIDATE_IP)) return true;
    // Hostname / FQDN: labels of a-z0-9 and hyphens, separated by dots, ≤253 chars
    return strlen($t) <= 253 && (bool)preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $t);
}

/**
 * Return the BGP border router — the one with the full routing table.
 * Uses MIKROTIK_BGP_ROUTER env var (id like "rt2" or name like "MCTN-RT1").
 * Never does BGP session scanning — too slow and unreliable.
 * Falls back to first configured router if env var is unset or unmatched.
 */
function find_border_router(array $routers): ?array
{
    if (empty($routers)) return null;
    $hint = strtolower(trim((string)getenv('MIKROTIK_BGP_ROUTER')));
    if ($hint !== '') {
        foreach ($routers as $r) {
            if (strtolower($r['id']) === $hint || strtolower($r['name']) === $hint) {
                return $r;
            }
        }
        // Hint set but no match — log clearly rather than silently using wrong router
        error_log("lg_router: MIKROTIK_BGP_ROUTER={$hint} did not match any configured router; falling back to first");
    }
    return $routers[0];
}

// ── BGP Sessions ─────────────────────────────────────────────────────────────
if ($action === 'bgp_sessions') {
    $results = [];
    $errors  = [];

    foreach ($routers as $router) {
        // BGP session query can be slow on busy border routers — use 20s timeout
        $sessions = mikrotik_get($router['host'], $router['user'], $router['pass'], 'routing/bgp/session', $router['port'], 20);
        if (isset($sessions['error'])) {
            $errors[] = $router['name'] . ': ' . $sessions['error'];
            continue;
        }

        // iBGP: local.role === 'ibgp', OR same AS on both sides
        // eBGP: local.role starts with 'ebgp' (ebgp-customer, ebgp-peer, ebgp-rs-client, etc.)
        $external = [];
        foreach ($sessions as $s) {
            $role   = $s['local.role'] ?? '';
            $isIbgp = str_starts_with($role, 'ibgp')
                   || ($s['remote.as'] ?? '') === ($s['local.as'] ?? '');
            if ($isIbgp) continue;
            $external[] = [
                'name'          => $s['name'] ?? '',
                'remote_as'     => $s['remote.as'] ?? '',
                'remote_addr'   => $s['remote.address'] ?? '',
                'afi'           => $s['remote.afi'] ?? $s['local.afi'] ?? '',
                'established'   => ($s['established'] ?? '') === 'true',
                'prefix_count'  => (int)($s['prefix-count'] ?? 0),
                'uptime'        => $s['uptime'] ?? '',
                'last_started'  => $s['last-started'] ?? '',
            ];
        }

        if (!empty($external)) {
            $results[] = [
                'router'   => $router['name'],
                'router_id'=> $router['id'],
                'sessions' => $external,
            ];
        }
    }

    if (empty($results)) {
        $detail = empty($errors) ? 'No router returned external sessions.' : implode('; ', $errors);
        api_err('No external BGP sessions found — ' . $detail, 503);
    }

    api_ok(['routers' => $results, 'errors' => $errors]);
}

// ── Route lookup ──────────────────────────────────────────────────────────────
elseif ($action === 'route_lookup') {
    $resource = trim($_GET['resource'] ?? '');
    if ($resource === '') api_err('resource required');

    // If bare IP (no /), resolve to BGP prefix via RIPE Stat prefix-overview
    if (!str_contains($resource, '/')) {
        $ripeUrl = 'https://stat.ripe.net/data/prefix-overview/data.json?resource=' . urlencode($resource);
        $ctx = stream_context_create(['http' => ['timeout' => 10,
            'header' => "User-Agent: Fibre-LG/1.0\r\nAccept: application/json\r\n",
            'ignore_errors' => true], 'ssl' => ['verify_peer' => true]]);
        $ripeBody = @file_get_contents($ripeUrl, false, $ctx);
        $ripeData = $ripeBody ? json_decode($ripeBody, true) : null;
        $resolved = $ripeData['data']['resource'] ?? null;
        if (!$resolved) api_err("Could not resolve {$resource} to a BGP prefix");
        $resource = $resolved;
    }

    $borderRouter = find_border_router($routers);
    if (!$borderRouter) api_err('No border router available', 503);

    // POST to routing/route/print with .query filter — works fast even on 1M-route tables
    $baseUrl = mikrotik_rest_base_url($borderRouter);
    $postBody = json_encode([
        '.query'    => ["dst-address={$resource}", 'routing-table=main', '#&'],
        '.proplist' => ['dst-address', 'gateway', 'immediate-gw', 'active', 'distance',
                        'bgp.as-path', 'bgp.local-pref', 'bgp.med', 'bgp.origin', 'belongs-to'],
    ]);

    $ch = curl_init($baseUrl . 'routing/route/print');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_USERPWD        => $borderRouter['user'] . ':' . $borderRouter['pass'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Connection: close'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_FORBID_REUSE   => true,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Retry once on "Session closed" — RouterOS sometimes closes sessions after a timeout
    if ($http === 400 && str_contains((string)$raw, 'Session closed')) {
        $ch2 = curl_init($baseUrl . 'routing/route/print');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_USERPWD        => $borderRouter['user'] . ':' . $borderRouter['pass'],
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Connection: close'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FRESH_CONNECT  => true,
            CURLOPT_FORBID_REUSE   => true,
        ]);
        $raw  = curl_exec($ch2);
        $http = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch2);
        curl_close($ch2);
    }

    if ($err) api_err("[{$borderRouter['name']}] Router query failed: {$err}", 502);
    if ($http >= 400) {
        $detail = json_decode($raw, true)['detail'] ?? substr($raw, 0, 300);
        api_err("[{$borderRouter['name']}] Router HTTP {$http}: {$detail}", 502);
    }

    $routes = json_decode($raw, true) ?? [];

    api_ok([
        'prefix' => $resource,
        'router' => $borderRouter['name'],
        'routes' => $routes,
    ]);
}

// ── Ping (run directly on app server — same network) ───────────────────
elseif ($action === 'ping') {
    $target = trim($_GET['target'] ?? '');
    if (!valid_network_target($target)) {
        api_err('Invalid target — use an IP address or hostname');
    }

    // -c 4 packets, -W 2s wait per reply, -n no reverse DNS
    $cmd  = 'ping -c 4 -W 2 ' . escapeshellarg($target) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $exitCode);
    $output = implode("\n", $lines);

    // Parse summary line: "4 packets transmitted, 4 received, 0% packet loss, time 3003ms"
    $sent = $received = $loss = $avgRtt = '';
    if (preg_match('/(\d+) packets transmitted, (\d+) received, ([\d.]+)% packet loss/', $output, $m)) {
        [$sent, $received, $loss] = [$m[1], $m[2], $m[3] . '%'];
    }
    // rtt min/avg/max/mdev = 0.5/0.6/0.7/0.05 ms
    if (preg_match('/rtt[^=]+=\s*[\d.]+\/([\d.]+)\/[\d.]+/', $output, $m)) {
        $avgRtt = $m[1] . 'ms';
    }

    api_ok([
        'target'   => $target,
        'router'   => org('name') . ' app server',
        'result'   => [[
            'host'         => $target,
            'sent'         => $sent,
            'received'     => $received,
            'packet-loss'  => $loss,
            'avg-rtt'      => $avgRtt,
            'status'       => $exitCode === 0 ? '' : 'timeout',
        ]],
    ]);
}

// ── Traceroute (run directly on app server — same network) ─────────────
elseif ($action === 'traceroute') {
    $target = trim($_GET['target'] ?? '');
    if (!valid_network_target($target)) {
        api_err('Invalid target — use an IP address or hostname');
    }

    // -n no DNS, -m 30 max hops, -w 1 wait 1s per probe, -q 1 one probe per hop
    $cmd  = 'traceroute -n -m 30 -w 1 -q 1 ' . escapeshellarg($target) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines);

    // Parse lines: "  1  192.168.1.1  0.5 ms" or "  2  * * *"
    $hops = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m)) continue;
        $num    = (int)$m[1];
        $rest   = trim($m[2]);
        if (str_starts_with($rest, '*')) {
            $hops[] = ['address' => '', 'last' => '', 'status' => 'timeout'];
        } else {
            // e.g. "192.168.1.1  0.5 ms"
            preg_match('/^(\S+)\s+([\d.]+)\s+ms/', $rest, $p);
            $hops[] = ['address' => $p[1] ?? $rest, 'last' => isset($p[2]) ? $p[2] . 'ms' : ''];
        }
    }

    api_ok(['target' => $target, 'router' => org('name') . ' app server', 'hops' => $hops]);
}

else {
    api_err('Unknown action — use bgp_sessions, route_lookup, ping, or traceroute');
}
