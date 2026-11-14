<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// phpHelpers/pole_route.helper.php
// Geographic ordering of a permit's poles into a sensible "route", plus route
// length. Extracted from fpm_permits.php so ssa_tariff.php (projections) and
// the Tinuum Permits page share one implementation.
//
// A "pole" is an associative array with at least 'lat', 'lon' (floats/strings)
// and optionally 'street'. Ordering is: group by street name, order each group
// by nearest-neighbour starting from its northernmost pole, then order the
// street groups north → south by their highest-latitude pole.

if (!function_exists('pole_route_nn_order')) {
    /** Nearest-neighbour order a single group, starting from the northernmost pole. */
    function pole_route_nn_order(array $group): array
    {
        if (count($group) <= 1) return $group;
        $startIdx = 0; $maxLat = -INF;
        foreach ($group as $i => $p) {
            if ((float)$p['lat'] > $maxLat) { $maxLat = (float)$p['lat']; $startIdx = $i; }
        }
        $pool    = array_values($group);
        $ordered = [$pool[$startIdx]];
        array_splice($pool, $startIdx, 1);
        while ($pool) {
            $lastLat = (float)end($ordered)['lat'];
            $lastLon = (float)end($ordered)['lon'];
            $bestDist = PHP_FLOAT_MAX; $bestKey = 0;
            foreach ($pool as $i => $p) {
                $d = ($p['lat'] - $lastLat) ** 2 + ($p['lon'] - $lastLon) ** 2;
                if ($d < $bestDist) { $bestDist = $d; $bestKey = $i; }
            }
            $ordered[] = $pool[$bestKey];
            array_splice($pool, $bestKey, 1);
        }
        return $ordered;
    }
}

if (!function_exists('pole_route_order')) {
    /** Order a permit's poles into a route: street-grouped NN, groups north → south. */
    function pole_route_order(array $poles): array
    {
        if (count($poles) <= 1) return $poles;

        // Group by street name (prevents adjacent roads from interleaving).
        $byStreet = [];
        foreach ($poles as $p) {
            $byStreet[$p['street'] ?? ''][] = $p;
        }

        // Order each street group internally by NN, track group's max lat.
        $groups = [];
        foreach ($byStreet as $group) {
            $ordered = pole_route_nn_order($group);
            $maxLat  = max(array_map(fn($p) => (float)$p['lat'], $ordered));
            $groups[] = ['maxLat' => $maxLat, 'poles' => $ordered];
        }

        // Order street groups north → south.
        usort($groups, fn($a, $b) => $b['maxLat'] <=> $a['maxLat']);

        return array_merge(...array_column($groups, 'poles'));
    }
}

if (!function_exists('pole_haversine_m')) {
    /** Great-circle distance in metres between two [lat, lon] points. */
    function pole_haversine_m(array $a, array $b): float
    {
        $R = 6371000.0; // Earth radius, metres
        $dLat = deg2rad($b[0] - $a[0]);
        $dLon = deg2rad($b[1] - $a[1]);
        $h = sin($dLat / 2) ** 2
           + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}

if (!function_exists('pole_route_length_m')) {
    /**
     * Estimated route/strand length in metres for a set of poles, as the total
     * weight of their minimum spanning tree (Prim's, great-circle edge weights).
     *
     * MST is used rather than an ordered-path sum because it is order-independent
     * and never zig-zags: it traces each linear run of poles and joins separate
     * runs (e.g. different streets) with the single shortest connector, so a
     * branching or multi-street permit measures ≈ its true built length instead
     * of accumulating long jumps between arbitrarily-ordered groups.
     *
     * Poles without a finite lat/lon are ignored. O(n²) — fine for permit sizes.
     */
    function pole_route_length_m(array $poles): float
    {
        $pts = [];
        foreach ($poles as $p) {
            $lat = isset($p['lat']) ? (float)$p['lat'] : null;
            $lon = isset($p['lon']) ? (float)$p['lon'] : null;
            if ($lat !== null && $lon !== null && is_finite($lat) && is_finite($lon)) {
                $pts[] = [$lat, $lon];
            }
        }
        $n = count($pts);
        if ($n < 2) return 0.0;

        // Prim's MST from node 0.
        $inTree  = array_fill(0, $n, false);
        $minEdge = array_fill(0, $n, INF);
        $minEdge[0] = 0.0;
        $total = 0.0;
        for ($iter = 0; $iter < $n; $iter++) {
            $u = -1; $best = INF;
            for ($i = 0; $i < $n; $i++) {
                if (!$inTree[$i] && $minEdge[$i] < $best) { $best = $minEdge[$i]; $u = $i; }
            }
            if ($u === -1) break;
            $inTree[$u] = true;
            $total += $minEdge[$u];
            for ($v = 0; $v < $n; $v++) {
                if (!$inTree[$v]) {
                    $d = pole_haversine_m($pts[$u], $pts[$v]);
                    if ($d < $minEdge[$v]) $minEdge[$v] = $d;
                }
            }
        }
        return $total;
    }
}
