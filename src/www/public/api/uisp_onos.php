<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/uisp_onos.php — GET only
// Returns a GeoJSON FeatureCollection of customer ONU locations with signal data
// and ONT→closure association state for each device.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

if (api_method() !== 'GET') {
    api_err('Method not allowed', 405);
}

header('Cache-Control: max-age=60, private');

$devices = uisp_fetch_cached('devices', 120, $pdo);
$sites   = uisp_fetch_cached('sites?type=endpoint', 3600, $pdo);

if (isset($devices['error'])) {
    echo json_encode([
        'type'     => 'FeatureCollection',
        'features' => [],
        'error'    => $devices['error'],
    ]);
    exit;
}
if (isset($sites['error'])) {
    echo json_encode([
        'type'     => 'FeatureCollection',
        'features' => [],
        'error'    => $sites['error'],
    ]);
    exit;
}

// Index endpoint sites by id for O(1) lookup
$siteById = [];
foreach ($sites as $site) {
    $sid = $site['id'] ?? null;
    if ($sid) $siteById[$sid] = $site;
}

// Load all lead associations indexed by crm_client_id for O(1) lookup
$leadAssocByCrmId = [];
$stLead = $pdo->query(
    'SELECT la.crm_client_id, la.fosc_id, f.splice_num
       FROM app.lead_association la
       JOIN app.fosc f ON f.fosc_id = la.fosc_id'
);
foreach ($stLead->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $leadAssocByCrmId[(int)$row['crm_client_id']] = $row;
}

// Load all ONT associations indexed by uisp_device_id for O(1) lookup
$assocByDeviceId = [];
$st = $pdo->query(
    'SELECT oa.uisp_device_id::text, oa.fosc_id, f.splice_num AS fosc_splice_num
       FROM app.ont_association oa
       JOIN app.fosc f ON f.fosc_id = oa.fosc_id'
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $assocByDeviceId[$row['uisp_device_id']] = $row;
}

// Load UCRM clients that have GPS: leads are yellow "plan" houses, other clients get their own colour.
$ucrmClients = ucrm_fetch_cached('clients?limit=500', 300, $pdo);
$leadFeatures = [];
$clientById   = [];   // crm client id → client record, reused by the prepared-service pass
if (!isset($ucrmClients['error']) && is_array($ucrmClients)) {
    foreach ($ucrmClients as $client) {
        $clientById[(int)$client['id']] = $client;
        $isLead = (bool)($client['isLead'] ?? false);
        if (!$isLead && (($client['isArchived'] ?? false) || !($client['isActive'] ?? true))) continue;
        $lat = $client['addressGpsLat'] ?? null;
        $lon = $client['addressGpsLon'] ?? null;
        if ($lat === null || $lon === null) continue;
        $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
                ?: ($client['companyName'] ?? '');
        $crmId     = (int)$client['id'];
        $leadAssoc = $leadAssocByCrmId[$crmId] ?? null;
        $leadFeatures[] = [
            'type'     => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [(float)$lon, (float)$lat]],
            'properties' => [
                'uisp_device_id'         => null,
                'crm_client_id'          => $crmId,
                'site_name'              => $name,
                'mac'                    => '',
                'model'                  => '',
                'ip'                     => null,
                'signal'                 => null,
                'bias_current'           => null,
                'voltage'                => null,
                'temperature'            => null,
                'uptime'                 => null,
                'status'                 => $isLead ? 'lead' : 'client',
                'address'                => $client['fullAddress'] ?? null,
                'service_name'           => null,
                'dl_speed'               => null,
                'ul_speed'               => null,
                'linked_fosc_id'         => $leadAssoc ? (int)$leadAssoc['fosc_id'] : null,
                'linked_fosc_splice_num' => $leadAssoc ? $leadAssoc['splice_num']   : null,
            ],
        ];
    }
}

$features = [];
$siteIdsWithDevice = [];   // NMS site ids that already contribute an ONU marker
foreach ($devices as $dev) {
    if (($dev['identification']['type'] ?? '') !== 'onu') continue;

    $id      = $dev['identification'] ?? [];
    $ov      = $dev['overview'] ?? [];
    $devId   = $id['id'] ?? null;   // UISP device UUID
    $siteId  = $id['site']['id'] ?? null;
    $siteRec = $siteId ? ($siteById[$siteId] ?? null) : null;

    // Prefer site-level NMS GPS (what UISP's own map uses) over device-level GPS
    $siteLoc = $siteRec ? ($siteRec['description']['location'] ?? null) : null;
    $lat = ($siteLoc['latitude']  ?? null) ?? ($dev['location']['latitude']  ?? null);
    $lon = ($siteLoc['longitude'] ?? null) ?? ($dev['location']['longitude'] ?? null);
    if ($lat === null || $lon === null) continue;
    $ucrm    = $siteRec['ucrm']        ?? null;
    $qos     = $siteRec['qos']         ?? null;
    $addr    = $siteRec['description']['address'] ?? null;

    $assoc = $devId ? ($assocByDeviceId[$devId] ?? null) : null;
    if ($siteId) $siteIdsWithDevice[$siteId] = true;

    $features[] = [
        'type'     => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$lon, (float)$lat]],
        'properties' => [
            'uisp_device_id'      => $devId,
            'site_name'           => $id['site']['name'] ?? $id['name'] ?? '',
            'mac'                 => $id['mac']       ?? '',
            'model'               => $id['modelName'] ?? '',
            'ip'                  => $dev['ipAddress'] ?? null,
            'signal'              => isset($ov['signal'])       ? (int)$ov['signal']       : null,
            'bias_current'        => isset($ov['biasCurrent'])  ? (int)$ov['biasCurrent']  : null,
            'voltage'             => isset($ov['voltage'])      ? (float)$ov['voltage']    : null,
            'temperature'         => isset($ov['temperature'])  ? (int)$ov['temperature']  : null,
            'uptime'              => isset($ov['uptime'])        ? (int)$ov['uptime']       : null,
            'status'              => $ov['status'] ?? 'unknown',
            'address'             => $addr,
            'service_name'        => $ucrm['service']['name'] ?? null,
            'dl_speed'            => $qos['downloadSpeed'] ?? null,
            'ul_speed'            => $qos['uploadSpeed']   ?? null,
            'linked_fosc_id'      => $assoc ? (int)$assoc['fosc_id'] : null,
            'linked_fosc_splice_num' => $assoc ? $assoc['fosc_splice_num'] : null,
        ],
    ];
}

// Prepared CRM services — sold but not yet provisioned in NMS, so they own no ONU
// device and nothing above plots them. Without these a pending install is simply
// invisible on the map, even though the customer is already on the books.
$preparedFeatures = [];
$preparedSvcs = ucrm_fetch_cached('clients/services?statuses[]=0&limit=500', 300, $pdo);
if (!isset($preparedSvcs['error']) && is_array($preparedSvcs)) {
    // Prepared service → closure links, indexed by crm_service_id
    $svcAssocById = [];
    $stSvc = $pdo->query(
        'SELECT sa.crm_service_id, sa.fosc_id, f.splice_num
           FROM app.service_association sa
           JOIN app.fosc f ON f.fosc_id = sa.fosc_id'
    );
    foreach ($stSvc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $svcAssocById[(int)$row['crm_service_id']] = $row;
    }

    foreach ($preparedSvcs as $svc) {
        $lat = $svc['addressGpsLat'] ?? null;
        $lon = $svc['addressGpsLon'] ?? null;
        if ($lat === null || $lon === null) continue;

        // Already drawn as a real ONU: the service points at an NMS site that has a device.
        $svcSiteId = $svc['unmsClientSiteId'] ?? null;
        if ($svcSiteId !== null && isset($siteIdsWithDevice[$svcSiteId])) continue;

        $crmId  = (int)($svc['clientId'] ?? 0);
        $client = $clientById[$crmId] ?? null;
        // Leads already get a marker from the client GPS above; don't double-plot them.
        if ($client !== null && ($client['isLead'] ?? false)) continue;

        $clientName = $client !== null
            ? (trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
               ?: ($client['companyName'] ?? ''))
            : '';

        $svcId    = (int)($svc['id'] ?? 0);
        $svcAssoc = $svcAssocById[$svcId] ?? null;

        $preparedFeatures[] = [
            'type'     => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [(float)$lon, (float)$lat]],
            'properties' => [
                // crm_client_id stays null on purpose: it drives the lead link/unlink
                // buttons, and app.lead_association keys on the client — which would
                // collide when one client holds several prepared services.
                'uisp_device_id'         => null,
                'crm_client_id'          => null,
                'crm_service_id'         => $svcId,
                'svc_crm_client_id'      => $crmId,
                'client_name'            => $clientName,
                'site_name'              => $clientName ?: ($svc['name'] ?? 'Prepared service'),
                'mac'                    => '',
                'model'                  => '',
                'ip'                     => null,
                'signal'                 => null,
                'bias_current'           => null,
                'voltage'                => null,
                'temperature'            => null,
                'uptime'                 => null,
                'status'                 => 'prepared',
                'address'                => $svc['fullAddress'] ?: null,
                'service_name'           => $svc['name'] ?? null,
                'dl_speed'               => null,
                'ul_speed'               => null,
                'linked_fosc_id'         => $svcAssoc ? (int)$svcAssoc['fosc_id'] : null,
                'linked_fosc_splice_num' => $svcAssoc ? $svcAssoc['splice_num']   : null,
            ],
        ];
    }
}

echo json_encode([
    'type'     => 'FeatureCollection',
    'features' => array_merge($features, $leadFeatures, $preparedFeatures),
]);
