<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/nav_rail.php
//
// Icon-rail metadata for the left navigation.  nav.php remains the single
// source of truth for pages, labels, URLs and grouping; this file only says
// which glyph stands for each group and for each ungrouped ("pinned") page.
//
// Icons are inline SVG bodies drawn on a 24x24 grid and stroked with
// currentColor, so they inherit the rail's hover/active colour and cost no
// extra download.  Add a group to nav.php and it appears here as soon as it
// gets a key in 'groups'; a group with no icon falls back to its initial.

return [

    // group label (must match the 'group' value in nav.php) => icon key
    'groups' => [
        'Network'      => 'network',
        'Customers'    => 'customers',
        'Fibre Plant'  => 'plant',
        'Construction' => 'construction',
        'Information'  => 'information',
        'Catalog'      => 'catalog',
        'Regulatory'   => 'regulatory',
        'Admin'        => 'admin',
    ],

    // page id => icon key, for the ungrouped pages pinned to the top of the rail
    'pages' => [
        'map'         => 'map',
        'closure'     => 'closure',
        'fibre_trace' => 'trace',
    ],

    'icons' => [
        'search' => '<circle cx="10.5" cy="10.5" r="6"/><path d="M15 15l4.6 4.6"/>',

        'map' => '<path d="M12 21.3s7-6.5 7-11.3a7 7 0 1 0-14 0c0 4.8 7 11.3 7 11.3z"/>'
                 . '<circle cx="12" cy="10" r="2.6"/>',

        'closure' => '<rect x="6" y="7.5" width="12" height="9" rx="2.5"/>'
                     . '<path d="M2.5 12H6M18 12h3.5"/><path d="M10 10.4v3.2M14 10.4v3.2"/>',

        'trace' => '<circle cx="5" cy="18" r="2"/><circle cx="19" cy="6" r="2"/>'
                   . '<path d="M7 18h5a5 5 0 0 0 5-5V8"/>',

        'network' => '<path d="M4.6 8.4a10 10 0 0 0 0 7.2M19.4 8.4a10 10 0 0 1 0 7.2"/>'
                     . '<path d="M8.2 10.1a5 5 0 0 0 0 3.8M15.8 10.1a5 5 0 0 1 0 3.8"/>'
                     . '<circle cx="12" cy="12" r="1.5"/>',

        'plant' => '<circle cx="12" cy="12" r="8.2"/><circle cx="12" cy="12" r="3"/>'
                   . '<path d="M12 3.8v2.3M20.2 12h-2.3M12 20.2v-2.3M3.8 12h2.3"/>',

        'construction' => '<path d="M3.5 17.6h17"/><path d="M6 17.6v-3.3a6 6 0 0 1 12 0v3.3"/>'
                          . '<path d="M10 8.9V5.6h4v3.3"/>',

        'information' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11.3v5.2"/>'
                         . '<circle cx="12" cy="7.9" r="1" fill="currentColor" stroke="none"/>',

        'catalog' => '<rect x="4" y="4" width="7" height="7" rx="1.5"/>'
                     . '<rect x="13" y="4" width="7" height="7" rx="1.5"/>'
                     . '<rect x="4" y="13" width="7" height="7" rx="1.5"/>'
                     . '<rect x="13" y="13" width="7" height="7" rx="1.5"/>',

        'regulatory' => '<path d="M12 4.4v15.2M7.5 19.6h9M4.6 8.3h14.8"/>'
                        . '<path d="M4.6 8.3 2.1 14h5l-2.5-5.7zM19.4 8.3 16.9 14h5l-2.5-5.7z"/>',

        'customers' => '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/>'
                       . '<circle cx="17" cy="9.5" r="2.4"/><path d="M16 14.2a4.6 4.6 0 0 1 5 4.3"/>',

        'admin' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 19.6a6.5 6.5 0 0 1 13 0"/>',
    ],
];
