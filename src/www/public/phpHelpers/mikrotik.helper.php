<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/mikrotik.helper.php
// RouterOS v7 REST API helper (https://<host>/rest/)

/**
 * Returns all configured MikroTik routers from environment variables.
 * Reads MIKROTIK_RT1_HOST … MIKROTIK_RT9_HOST (stops at first missing host).
 */
function mikrotik_routers(): array
{
    $routers = [];
    for ($i = 1; $i <= 9; $i++) {
        $host = getenv("MIKROTIK_RT{$i}_HOST") ?: '';
        if ($host === '') break;
        $routers[] = [
            'id'   => "rt{$i}",
            'name' => getenv("MIKROTIK_RT{$i}_NAME") ?: "Router {$i}",
            'host' => $host,
            'port' => getenv("MIKROTIK_RT{$i}_PORT") ?: '',
            'user' => getenv("MIKROTIK_RT{$i}_USER") ?: '',
            'pass' => getenv("MIKROTIK_RT{$i}_PASS") ?: '',
        ];
    }
    return $routers;
}

/**
 * Build a safe RouterOS REST base URL from host/port values.
 * Supports:
 * - IPv4 or DNS host, with or without :port
 * - raw IPv6 host (auto-bracketed)
 * - bracketed IPv6, with or without :port
 * - optional full https:// URL in MIKROTIK_RT*_HOST
 */
function mikrotik_rest_base_url(array $router): string
{
    $rawHost = trim((string)($router['host'] ?? ''));
    $port    = trim((string)($router['port'] ?? ''));
    $host    = $rawHost;

    // Accept "https://host[:port]" values as input and split host/port safely.
    if (preg_match('~^https?://~i', $host)) {
        $parts = parse_url($host);
        if (is_array($parts)) {
            $host = (string)($parts['host'] ?? $host);
            if ($port === '' && isset($parts['port'])) {
                $port = (string)$parts['port'];
            }
        }
    }

    // If host is plain "name:port" or "ipv4:port", split out port unless an explicit one is set.
    if ($port === '' && !str_starts_with($host, '[') && substr_count($host, ':') === 1) {
        [$candidateHost, $candidatePort] = explode(':', $host, 2);
        if ($candidateHost !== '' && ctype_digit($candidatePort)) {
            $host = $candidateHost;
            $port = $candidatePort;
        }
    }

    // Raw IPv6 host without brackets -> bracket it for URL safety.
    if (!str_starts_with($host, '[') && substr_count($host, ':') >= 2) {
        $host = "[{$host}]";
    }

    $hasInlinePort = (bool)preg_match('/^\[[^\]]+\]:\d+$/', $host)
        || (bool)preg_match('/^[^:]+:\d+$/', $host);

    if ($port !== '' && !$hasInlinePort) {
        $host .= ':' . $port;
    }

    return 'https://' . $host . '/rest/';
}

/**
 * HTTP GET against the RouterOS v7 REST API.
 * IPv6 host addresses are automatically wrapped in brackets.
 * Returns decoded JSON array on success, or ['error' => '...'] on failure.
 */
function mikrotik_get(string $host, string $user, string $pass, string $path, string $port = '', int $timeout = 8): array
{
    $url = mikrotik_rest_base_url(['host' => $host, 'port' => $port]) . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_FORBID_REUSE   => true,
    ]);

    $raw   = curl_exec($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return ['error' => "cURL error ({$errno}): {$error}"];
    }
    if ($http === 401) {
        return ['error' => 'Authentication failed (401) — check user/pass'];
    }
    if ($http === 403) {
        return ['error' => 'Forbidden (403) — check user group policies (need read,rest-api)'];
    }
    if ($http === 404) {
        return [];          // Endpoint not present on this router (e.g. no BGP) — return empty
    }
    if ($http >= 400) {
        return ['error' => "HTTP {$http} from router"];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['error' => 'Invalid JSON: ' . substr($raw, 0, 120)];
    }

    // RouterOS wraps some errors in { "detail": "..." }
    if (is_array($decoded) && isset($decoded['detail']) && count($decoded) === 1) {
        return ['error' => $decoded['detail']];
    }

    return $decoded;
}

/**
 * HTTP POST a RouterOS command against the REST API (e.g. interface/ethernet/monitor).
 * Body is JSON-encoded from $params array.
 * Returns decoded JSON array on success, or ['error' => '...'] on failure.
 */
function mikrotik_post(string $host, string $user, string $pass, string $path, array $params = [], string $port = '', int $timeout = 10): array
{
    $url  = mikrotik_rest_base_url(['host' => $host, 'port' => $port]) . ltrim($path, '/');
    $body = json_encode($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_FORBID_REUSE   => true,
    ]);

    $raw   = curl_exec($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || ($errno !== 0 && !($http === 0 && $raw === ''))) {
        return ['error' => "cURL error ({$errno}): {$error}"];
    }
    if ($http === 0 && $raw === '') {
        return ['error' => 'Connection failed (no response)'];
    }
    if ($http === 401) {
        return ['error' => 'Authentication failed (401)'];
    }
    if ($http === 403) {
        return ['error' => 'Forbidden (403) — check API permissions'];
    }
    if ($http >= 400) {
        return ['error' => "HTTP {$http} from router"];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['error' => 'Invalid JSON: ' . substr($raw, 0, 120)];
    }
    if (is_array($decoded) && isset($decoded['detail']) && count($decoded) === 1) {
        return ['error' => $decoded['detail']];
    }

    return $decoded;
}

/**
 * How many REST requests we are willing to have open to one router at once.
 *
 * RouterOS answers each request on its own www-ssl session, and we deliberately
 * never reuse connections (see CURLOPT_FRESH_CONNECT below), so a dashboard load
 * asks for one session per endpoint. Firing all nine at once is enough to exhaust
 * the pool on a busy router: MCTN-RT1 drops five of nine and then stays degraded
 * for a while, which surfaced as a red "unreachable" card for a router that was up
 * the whole time and polling at 88% from cron. Both routers advertise
 * max-sessions=100, so the ceiling being hit is practical, not the configured one.
 *
 * Three at a time costs a few hundred ms on a page that already waits on the
 * network, and it keeps the slowest router inside its budget.
 */
const MIKROTIK_MAX_PARALLEL = 3;

/**
 * Fetch all dashboard sections for a single router, a few at a time via curl_multi.
 */
function mikrotik_fetch_router(array $router): array
{
    $host = (string)$router['host'];
    $user = (string)$router['user'];
    $pass = (string)$router['pass'];

    // If password is empty, skip fetch entirely
    if ($pass === '') {
        return [
            'meta'   => $router,
            'error'  => 'No password configured — set MIKROTIK_RT' . substr($router['id'], 2) . '_PASS in .env',
        ];
    }

    $baseUrl = mikrotik_rest_base_url($router);

    $endpoints = [
        // 'identity' => 'system/identity' was fetched every poll and read by nothing;
        // router display names come from MIKROTIK_RT*_NAME. It cost a session per
        // poll per router, which is the exact resource this router runs out of.
        'resource'    => 'system/resource',
        'interfaces'  => 'interface',
        'addresses'   => 'ip/address',
        'ip6_addr'    => 'ipv6/address',
        'ppp_active'  => 'ppp/active',
        'bgp_sess'    => 'routing/bgp/session',
        'dhcp_leases' => 'ip/dhcp-server/lease',
        // 'ipv6_routes' is deliberately NOT here — see the conditional fetch below.
    ];

    $result = ['meta' => $router];

    // First pass in small batches, then one retry for anything the router dropped
    // on the floor. A refused connection here is a transient session-pool miss, not
    // a router that is down, and retrying it one at a time almost always succeeds —
    // without the retry a single dropped 'resource' call reads as "unreachable".
    $pending = $endpoints;
    for ($attempt = 1; $attempt <= 2 && $pending; $attempt++) {
        $failed = [];
        foreach (array_chunk($pending, $attempt === 1 ? MIKROTIK_MAX_PARALLEL : 1, true) as $batch) {
            foreach (mikrotik_fetch_batch($baseUrl, $user, $pass, $batch) as $key => $value) {
                $result[$key] = $value;
                // Only connection-level failures are worth a second go. An auth or
                // HTTP error is a settled answer and retrying just costs a session.
                if (is_array($value) && isset($value['error'])
                    && str_starts_with((string)$value['error'], 'Connection failed')) {
                    $failed[$key] = $endpoints[$key];
                }
            }
        }
        $pending = $failed;
    }

    // The IPv6 route table has exactly one consumer: mapping DHCPv6-PD prefixes onto
    // this router's DHCP leases. That makes it worth asking for only on a router that
    // actually returned leases. On a border router it is the full BGP table, and
    // MCTN-RT1 cannot serialise it over REST at all — 45 s and still "Session closed",
    // even filtered — while having no subscribers for the map to enrich. Asking
    // anyway cost a session and half a minute on every single poll, and that session
    // pressure is what pushed 'resource' into failing and painted the card red.
    //
    // .proplist trims the reply to the three fields the poller reads; the dynamic
    // filter drops the static table. The PHP side re-checks both regardless.
    $leases = $result['dhcp_leases'] ?? [];
    $result['ipv6_routes'] = [];
    if (is_array($leases) && !isset($leases['error']) && $leases !== []) {
        $batch = mikrotik_fetch_batch($baseUrl, $user, $pass,
            ['ipv6_routes' => 'ipv6/route?.proplist=dst-address,gateway,dynamic&dynamic=true']);
        $result['ipv6_routes'] = $batch['ipv6_routes'];
    }

    // Fall back to legacy BGP peer path if session path was empty/errored
    if (empty($result['bgp_sess']) || isset($result['bgp_sess']['error'])) {
        $fallback = mikrotik_get($host, $user, $pass, 'routing/bgp/peer', (string)($router['port'] ?? ''));
        if (!empty($fallback) && !isset($fallback['error'])) {
            $result['bgp_sess'] = $fallback;
        }
    }

    return $result;
}

/**
 * Run one batch of endpoints through curl_multi and decode each response.
 * Returns key => decoded body, or key => ['error' => ...].
 */
function mikrotik_fetch_batch(string $baseUrl, string $user, string $pass, array $endpoints): array
{
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($endpoints as $key => $path) {
        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $key === 'bgp_sess' ? 20 : 8,
            CURLOPT_USERPWD        => "{$user}:{$pass}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FRESH_CONNECT  => true,   // never reuse cached connections (avoids "session closed" on long-lived poller)
            CURLOPT_FORBID_REUSE   => true,   // close connection after each request
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh);
    } while ($running > 0 && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $key => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($raw === false || $raw === '' && $http === 0 || $err !== '') {
            // curl_multi silently eats connection errors: curl_error() returns ''
            // even on CURLE_COULDNT_CONNECT.  Treat HTTP 0 with empty body as failure.
            $out[$key] = ['error' => $err ?: 'Connection failed (unreachable or timeout)'];
        } elseif ($http === 401) {
            $out[$key] = ['error' => 'Authentication failed (401)'];
        } elseif ($http === 403) {
            $out[$key] = ['error' => 'Forbidden (403) — need read,rest-api policies'];
        } elseif ($http === 404) {
            $out[$key] = [];     // Endpoint not available on this router
        } elseif ($http >= 400) {
            $out[$key] = ['error' => "HTTP {$http}"];
        } else {
            $decoded = json_decode($raw, true);
            if ($decoded === null) {
                $out[$key] = ['error' => 'Invalid JSON: ' . substr($raw, 0, 100)];
            } elseif (is_array($decoded) && isset($decoded['detail']) && count($decoded) === 1) {
                $out[$key] = ['error' => $decoded['detail']];
            } else {
                $out[$key] = $decoded;
            }
        }
    }

    curl_multi_close($mh);
    return $out;
}

/**
 * Format a byte count as a human-readable string (B / KB / MB / GB / TB).
 */
function mikrotik_fmt_bytes(int|string $bytes): string
{
    $b = (float)$bytes;
    if ($b >= 1e12) return round($b / 1e12, 2) . ' TB';
    if ($b >= 1e9)  return round($b / 1e9,  2) . ' GB';
    if ($b >= 1e6)  return round($b / 1e6,  2) . ' MB';
    if ($b >= 1e3)  return round($b / 1e3,  1) . ' KB';
    return $b . ' B';
}

/**
 * Format uptime seconds as a human-readable string.
 * e.g. 90061 → "1d 1h 1m"
 */
function mikrotik_fmt_uptime(int $seconds): string
{
    if ($seconds <= 0) return '0s';
    $w = intdiv($seconds, 604800); $seconds %= 604800;
    $d = intdiv($seconds, 86400);  $seconds %= 86400;
    $h = intdiv($seconds, 3600);   $seconds %= 3600;
    $m = intdiv($seconds, 60);     $s = $seconds % 60;
    $parts = [];
    if ($w) $parts[] = "{$w}w";
    if ($d) $parts[] = "{$d}d";
    if ($h) $parts[] = "{$h}h";
    if ($m) $parts[] = "{$m}m";
    if ($s && !$w && !$d) $parts[] = "{$s}s";   // only show seconds for short uptimes
    return implode(' ', $parts) ?: '0s';
}

/**
 * RouterOS returns "true"/"false" strings in REST API — normalise to bool.
 */
function mikrotik_bool(mixed $val): bool
{
    return $val === true || $val === 'true' || $val === '1';
}

/**
 * Build a quick address lookup: interface_name => [ip1, ip2, ...]
 */
function mikrotik_addr_map(array $addresses): array
{
    $map = [];
    foreach ($addresses as $a) {
        $iface = $a['interface'] ?? '';
        if ($iface !== '' && isset($a['address'])) {
            $map[$iface][] = $a['address'];
        }
    }
    return $map;
}

/**
 * Returns all devices that should be polled via SNMP.
 * Combines MikroTik routers (from MIKROTIK_RT*_HOST) with any additional
 * SNMP-only devices (SNMP_DEVICE1_HOST … SNMP_DEVICE9_HOST).
 *
 * Each entry: ['id', 'name', 'host']
 */
function snmp_all_devices(): array
{
    $devices = [];

    // MikroTik routers (already have REST credentials; just need host for SNMP)
    foreach (mikrotik_routers() as $r) {
        $devices[] = ['id' => $r['id'], 'name' => $r['name'], 'host' => $r['host']];
    }

    // Extra SNMP devices (e.g. OLTs, switches)
    for ($i = 1; $i <= 9; $i++) {
        $host = getenv("SNMP_DEVICE{$i}_HOST") ?: '';
        if ($host === '') break;
        $devices[] = [
            'id'   => getenv("SNMP_DEVICE{$i}_ID")   ?: "device{$i}",
            'name' => getenv("SNMP_DEVICE{$i}_NAME")  ?: "Device {$i}",
            'host' => $host,
        ];
    }

    return $devices;
}
