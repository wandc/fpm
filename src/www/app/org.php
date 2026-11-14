<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
/*
 * app/org.php — who runs this installation: name, contact details, host, logo.
 *
 * Defaults here are neutral. Put your own values in app/org.local.php (same keys, returns an array); that file
 * is yours and is never overwritten by a new snapshot. Read a value with org('support_email').
 */

if (!function_exists('org')) {
    function org(string $key): string
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = [
                'name'             => 'Your Company',          // short name on screens and sheets
                'long_name'        => 'Your Company',          // trade name in page titles and headers
                'legal_name'       => 'Your Company Inc.',     // printed in sheet footers and e-mail signatures
                'officer_name'     => '',                      // signs tenders and proposals
                'officer_title'    => '',
                'cert_hosts'       => '',                      // certificate expiry watch: host:port=Label, comma separated
                'address'          => '',                      // mailing address for printed sheets
                'region'           => '',                      // town and province for sheet footers
                'contact_email'    => '',                      // a person's address for shipping sheets
                'app_domain'       => 'localhost',             // host name of this app without a port
                'wo_label'         => 'Work Order',            // what a work order / permit is called on screen
                'http_agent'       => 'FPM/1.0',               // User-Agent for requests to public data services
                'mcp_name'         => 'fpm-plant',             // name the read-only MCP server gives itself
                'app_title'        => 'Fibre Plant Manager',   // home-screen title on phones
                'support_email'    => '',
                'phone'            => '',
                'web'              => '',                      // host name only, e.g. example.com
                'app_host'         => 'localhost:8080',        // used when a request has no Host header
                'own_name'         => 'Own network',           // how your own network is named as a pole owner and coverage provider
                'own_mark'         => '/images/own-mark.svg',  // small mark shown for your own poles
                'logo'             => '/images/logo.svg',
                'favicon_dir'      => '',                      // folder with a full favicon set; empty = just /images/favicon.svg
                'default_wifi_wor' => '',                      // work order new WiFi APs are put on (empty = none)
                'home_asn_label'   => '',                      // label shown for your own ASN in the looking glass
            ];
            $local = __DIR__ . '/org.local.php';
            if (is_file($local)) {
                $over = require $local;
                if (is_array($over)) $cfg = array_merge($cfg, $over);
            }
        }
        return (string)($cfg[$key] ?? '');
    }
}
