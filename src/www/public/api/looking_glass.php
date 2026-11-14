<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/looking_glass.php — proxy to RIPE Stat API for BGP looking glass

require_once __DIR__ . '/_common.php';

if (api_method() !== 'GET') {
    api_err('GET only', 405);
}

$action   = trim($_GET['action']   ?? '');
$resource = trim($_GET['resource'] ?? '');

$baseUrl = 'https://stat.ripe.net/data';

// rrc_info needs no resource validation — it's a global endpoint
if ($action === 'rrc_info') {
    $url = "{$baseUrl}/rrc-info/data.json";
} elseif ($action === 'asn_name') {
    // resource must be a bare ASN number or AS-prefixed
    if (!preg_match('/^(AS)?\d+$/i', $resource)) {
        api_err('Invalid resource — use AS12345 or 12345');
    }
    $url = "{$baseUrl}/as-overview/data.json?resource=" . urlencode($resource);
} else {
    if ($resource === '') {
        api_err('resource is required');
    }
    // Validate resource: ASN (AS12345), IPv4 prefix (1.2.3.0/24), or IPv6 prefix (2001:db8::/32)
    if (!preg_match('/^(AS\d+|\d{1,3}(\.\d{1,3}){3}\/\d{1,2}|[0-9a-fA-F:]+\/\d{1,3})$/i', $resource)) {
        api_err('Invalid resource — use AS12345, 1.2.3.0/24, or 2001:db8::/32');
    }

    $url = match ($action) {
        'announced_prefixes' => "{$baseUrl}/announced-prefixes/data.json?resource=" . urlencode($resource),
        'bgp_state'          => "{$baseUrl}/bgp-state/data.json?resource=" . urlencode($resource),
        'routing_status'     => "{$baseUrl}/routing-status/data.json?resource=" . urlencode($resource),
        default              => null,
    };

    if ($url === null) {
        api_err('Unknown action — use announced_prefixes, bgp_state, routing_status, rrc_info, or asn_name');
    }
}

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 20,
        'header'  => "User-Agent: " . org('http_agent') . "\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$body = @file_get_contents($url, false, $ctx);
if ($body === false) {
    api_err('Could not reach RIPE Stat API — check server connectivity', 502);
}

$data = json_decode($body, true);
if (!is_array($data)) {
    api_err('Unexpected response from RIPE Stat API', 502);
}

// Surface any RIPE-level errors
$status = $data['status'] ?? '';
if ($status === 'error') {
    $msg = $data['messages'][0][1] ?? 'RIPE Stat returned an error';
    api_err($msg, 502);
}

api_ok(['ripe' => $data]);
