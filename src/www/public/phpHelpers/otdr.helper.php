<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/otdr.helper.php
// Bellcore GR-196-CORE SOR (Standard OTDR Record) binary parser.
//
// Public API:
//   otdr_parse_sor(string $raw): array   — parse a full .sor file
//
// The returned array shape (all keys always present, null when unavailable):
//   'version'        => int                 e.g. 200 (= v2.00)
//   'tested_at'      => string|null         'Y-m-d H:i:s' UTC
//   'wavelength_nm'  => int|null            e.g. 1550
//   'unit'           => string|null         'km' or 'm'
//   'supplier'       => string|null
//   'model'          => string|null
//   'cable_id'       => string|null
//   'fiber_id'       => string|null
//   'dx_m'           => float|null          metres per sample
//   'fiber_length_m' => float|null          distance to end-of-fiber event
//   'total_loss_db'  => float|null          estimated one-way loss
//   'events'         => array               see below
//   'trace'          => array               [[dist_m, db], ...]  (all points)
//
// Event shape:
//   ['num'=>int, 'distance_m'=>float, 'loss_db'=>float|null,
//    'reflectance_db'=>float|null, 'type'=>string, 'comment'=>string]

if (!function_exists('otdr_parse_sor')) {

// ─────────────────────────────────────────────────────────────────────────────
// Public entry point
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_sor(string $raw): array
{
    $out = [
        'version'        => null,
        'tested_at'      => null,
        'wavelength_nm'  => null,
        'unit'           => null,
        'supplier'       => null,
        'model'          => null,
        'cable_id'       => null,
        'fiber_id'       => null,
        'launch_cable_m' => null,
        'dx_m'           => null,
        'fiber_length_m' => null,
        'total_loss_db'  => null,
        'events'         => [],
        'trace'          => [],
    ];

    try {
        $blocks = otdr_parse_map($raw);
        if (empty($blocks)) {
            return $out;
        }

        $out['version'] = $blocks['_map_version'] ?? null;

        // Collect raw block data strings keyed by block name
        $raw_blocks = [];
        foreach ($blocks as $name => $info) {
            if ($name[0] === '_') continue;
            $raw_blocks[$name] = substr($raw, $info['data_offset'], $info['data_len']);
        }

        // FxdParams first (provides RI and dx_m for subsequent parsers)
        $ri   = 1.468;   // default SMF refractive index
        $dx_m = null;
        if (isset($raw_blocks['FxdParams'])) {
            $fxd = otdr_parse_fxd_params($raw_blocks['FxdParams']);
            $out['tested_at']     = $fxd['tested_at'];
            $out['wavelength_nm'] = $fxd['wavelength_nm'];
            $out['unit']          = $fxd['unit'];
            $out['dx_m']          = $fxd['dx_m'];
            $dx_m = $fxd['dx_m'];
            if (($fxd['_ri_raw'] ?? 0) > 0) {
                $ri = $fxd['_ri_raw'] / 1e5;
            }
        }

        // GenParams
        if (isset($raw_blocks['GenParams'])) {
            $gen = otdr_parse_gen_params($raw_blocks['GenParams']);
            $out['cable_id']       = $gen['cable_id'];
            $out['fiber_id']       = $gen['fiber_id'];
            $out['launch_cable_m'] = $gen['launch_cable_m'];
        }

        // SupParams
        if (isset($raw_blocks['SupParams'])) {
            $sup = otdr_parse_sup_params($raw_blocks['SupParams']);
            $out['supplier'] = $sup['supplier'];
            $out['model']    = $sup['model'];
        }

        // KeyEvents (needs RI for distance conversion)
        if (isset($raw_blocks['KeyEvents'])) {
            $out['events'] = otdr_parse_key_events($raw_blocks['KeyEvents'], $ri);
        }

        // Determine fiber length from end-of-fiber event
        foreach ($out['events'] as $ev) {
            $t = $ev['type'];
            if (strlen($t) >= 2 && ($t[0] === '0' || $t[0] === '1') && $t[1] === 'E') {
                $out['fiber_length_m'] = $ev['distance_m'];
                break;
            }
        }

        // DataPts (needs dx_m)
        if (isset($raw_blocks['DataPts']) && $dx_m !== null) {
            $out['trace'] = otdr_parse_data_pts($raw_blocks['DataPts'], $dx_m);
        }

        // Estimate total loss: sum finite event losses (splice losses)
        // Plus distributed loss from trace slope if we have events
        $total_loss = 0.0;
        $start_m = null;
        foreach ($out['events'] as $ev) {
            if ($ev['type'] === '0S') {
                $start_m = $ev['distance_m'];
            }
            if ($ev['loss_db'] !== null) {
                $total_loss += $ev['loss_db'];
            }
        }
        // Estimate distributed loss from attenuation coefficient in comments
        // The comments on non-start/end events often contain dB/km coefficients
        // Use the average of non-zero coefficients × section lengths
        $attn_km = null;
        foreach ($out['events'] as $ev) {
            if (!empty($ev['comment']) && is_numeric($ev['comment'])) {
                $v = (float)$ev['comment'];
                if ($v > 0 && $v < 5) {   // sanity: 0–5 dB/km
                    $attn_km = $v;
                    break;
                }
            }
        }
        if ($attn_km !== null && $out['fiber_length_m'] !== null) {
            $total_loss += $attn_km * ($out['fiber_length_m'] / 1000.0);
        }
        // Cap at 60 dB — larger values indicate a broken/unreachable fibre
        $out['total_loss_db'] = ($total_loss > 0 && $total_loss <= 60.0)
            ? round($total_loss, 3)
            : null;

    } catch (\Throwable $e) {
        // Return what we have so far
    }

    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Map block parser — returns block metadata indexed by name
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_map(string $raw): array
{
    $pos = 0;

    // Map block header: "Map\0" + uint16 version + uint32 total_size
    $map_name = otdr_read_str($raw, $pos);
    if ($map_name !== 'Map') {
        return [];
    }
    $version    = otdr_u16($raw, $pos);
    $map_size   = otdr_u32($raw, $pos);      // total Map block bytes (incl. header)
    $num_blocks = otdr_u16($raw, $pos);      // includes Map itself

    // Parse inventory entries (num_blocks − 1, excluding Map self-entry)
    $inventory = [];
    for ($i = 0; $i < $num_blocks - 1; $i++) {
        $bname  = otdr_read_str($raw, $pos);
        $bver   = otdr_u16($raw, $pos);       // block version (unused)
        $bsize  = otdr_u32($raw, $pos);       // total block bytes (name\0 + data)
        $inventory[$bname] = $bsize;
    }

    // Walk actual data blocks starting at map_size
    $bpos   = $map_size;
    $blocks = ['_map_version' => $version];

    foreach ($inventory as $bname => $inv_size) {
        $block_start   = $bpos;
        $actual_name   = otdr_read_str($raw, $bpos);
        $name_len      = strlen($actual_name) + 1;
        $data_len      = $inv_size - $name_len;
        $data_offset   = $bpos;               // $bpos now points past the null

        $blocks[$actual_name] = [
            'data_offset' => $data_offset,
            'data_len'    => $data_len,
        ];

        $bpos = $block_start + $inv_size;
    }

    return $blocks;
}

// ─────────────────────────────────────────────────────────────────────────────
// FxdParams block parser
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_fxd_params(string $data): array
{
    // Constants
    $C = 2.998e8;  // speed of light in vacuum (m/s)

    $p = 0;
    $ts     = otdr_u32($data, $p);                  // Unix timestamp
    $unit   = rtrim(substr($data, $p, 2), "\0");    $p += 2;
    $wlen   = otdr_u16($data, $p);                  // wavelength × 0.1 nm
    $p += 12;  // skip acquisition_offset (int32×2), num_pulses (u16), pulse_width (u16)
    $dx_raw = otdr_u32($data, $p);                  // sample spacing in picoseconds
    $npts   = otdr_u32($data, $p);                  // number of data points
    $ri_raw = otdr_u32($data, $p);                  // IOR × 100000

    $ri   = ($ri_raw > 0) ? ($ri_raw / 1e5) : 1.468;
    // Dwei-Tech SOR files store sample spacing in 20 fs units (not 1 ps).
    // Empirically derived: correct formula uses 5e13 denominator.
    $dx_m = ($dx_raw > 0 && $ri > 0)
        ? ($dx_raw * $C / (2.0 * $ri * 5e13))
        : null;

    // Stash raw values for other parsers via static — simpler than threading params
    $GLOBALS['_otdr_ri_raw']  = $ri_raw;
    $GLOBALS['_otdr_dx_raw']  = $dx_raw;
    $GLOBALS['_otdr_ri']      = $ri;

    return [
        'tested_at'      => ($ts > 0) ? gmdate('Y-m-d H:i:s', $ts) : null,
        'wavelength_nm'  => ($wlen > 0) ? (int)round($wlen * 0.1) : null,
        'unit'           => ($unit !== '') ? $unit : null,
        'dx_m'           => $dx_m,
        'npts'           => $npts,
        '_ri_raw'        => $ri_raw,
        '_dx_raw'        => $dx_raw,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// GenParams block parser
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_gen_params(string $data): array
{
    $C = 2.998e8;

    $p        = 2;   // skip language code (2 bytes)
    $cable_id = otdr_read_str($data, $p);
    $fiber_id = otdr_read_str($data, $p);

    // Skip fiber_type (u16) + wavelength (u16) + orig_loc + term_loc + cable_code
    $p += 4;
    otdr_read_str($data, $p); // orig_loc
    otdr_read_str($data, $p); // term_loc
    otdr_read_str($data, $p); // cable_code

    // Dwei-Tech stores 2-byte marker (0x4342) then u32 launch-cable offset in 200 ps units
    $launch_m = null;
    if ($p + 6 <= strlen($data)) {
        $p += 2; // skip 0x4342 marker
        $ri  = ($GLOBALS['_otdr_ri'] ?? 1.468);
        $raw = otdr_u32($data, $p);
        if ($raw > 0) {
            $launch_m = round($raw * $C / (2.0 * $ri * 5e9), 1);
        }
    }

    return [
        'cable_id'        => ($cable_id !== '') ? $cable_id : null,
        'fiber_id'        => ($fiber_id !== '') ? $fiber_id : null,
        'launch_cable_m'  => $launch_m,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// SupParams block parser
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_sup_params(string $data): array
{
    $p        = 0;
    $supplier = otdr_read_str($data, $p);
    $model    = otdr_read_str($data, $p);

    return [
        'supplier' => ($supplier !== '') ? $supplier : null,
        'model'    => ($model    !== '') ? $model    : null,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// KeyEvents block parser
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_key_events(string $data, float $ri = 1.468): array
{
    $C   = 2.998e8;
    $p   = 0;
    $nev = otdr_u16($data, $p);

    $events = [];
    for ($i = 0; $i < $nev; $i++) {
        $ev_start = $p;

        $evnum = otdr_u16($data, $p);         // [0:2] event number
        $dist  = otdr_u32($data, $p);         // [2:6] propagation distance in ns
        $loss  = otdr_i32($data, $p);         // [6:10] loss × 0.001 dB
        $refl  = otdr_i32($data, $p);         // [10:14] reflectance × 0.001 dB
        $type  = substr($data, $p, 2);         // [14:15] type prefix e.g. "0S", "1E"
        $p     = $ev_start + 42;               // jump to end of 42-byte record

        // Comment (null-terminated) follows the 42-byte record
        $null    = strpos($data, "\0", $p);
        $comment = ($null !== false) ? substr($data, $p, $null - $p) : '';
        $p       = ($null !== false) ? ($null + 1) : strlen($data);

        // Convert raw distance to metres: Dwei-Tech stores dist in 200 ps units (not 1 ns).
        // Correct formula uses 5e9 denominator.
        $dist_m  = $dist * $C / (2.0 * $ri * 5e9);

        // Loss: cap at 100 dB — larger values are firmware sentinel markers, not real losses.
        // Reflectance: 0 raw = "not measured"; only store non-zero values within range.
        $loss_db = (abs($loss) < 100_000) ? round($loss * 0.001, 3) : null;
        $refl_db = ($refl !== 0 && abs($refl) < 9_999_999) ? round($refl * 0.001, 3) : null;

        $events[] = [
            'num'            => $evnum,
            'distance_m'     => round($dist_m, 2),
            'loss_db'        => $loss_db,
            'reflectance_db' => $refl_db,
            'type'           => $type,
            'comment'        => $comment,
        ];
    }

    return $events;
}

// ─────────────────────────────────────────────────────────────────────────────
// DataPts block parser
// ─────────────────────────────────────────────────────────────────────────────

function otdr_parse_data_pts(string $data, float $dx_m): array
{
    // Header: 4 (num_pts) + 2 (flag) + 4 (num_pts) + 2 (unknown) = 12 bytes
    if (strlen($data) < 12) return [];

    $p      = 0;
    $npts   = otdr_u32($data, $p);   // num_pts from block header
    $p     += 8;                     // skip flag(u16) + num_pts(u32) + unknown(u16)

    if ($npts < 1 || $npts > 100_000) {
        return [];
    }

    $expected = 12 + $npts * 2;
    if (strlen($data) < $expected) {
        $npts = (int)((strlen($data) - 12) / 2);
    }

    $trace = [];
    for ($i = 0; $i < $npts; $i++) {
        $raw_val  = otdr_u16($data, $p);
        $dist_m   = round($i * $dx_m, 4);
        $db       = round($raw_val * 0.001, 4);
        $trace[]  = [$dist_m, $db];
    }

    return $trace;
}

// ─────────────────────────────────────────────────────────────────────────────
// Binary read helpers
// ─────────────────────────────────────────────────────────────────────────────

function otdr_read_str(string $raw, int &$pos): string
{
    $end = strpos($raw, "\0", $pos);
    if ($end === false) {
        $s   = substr($raw, $pos);
        $pos = strlen($raw);
        return $s;
    }
    $s   = substr($raw, $pos, $end - $pos);
    $pos = $end + 1;
    return $s;
}

function otdr_u16(string $raw, int &$pos): int
{
    $v   = unpack('v', substr($raw, $pos, 2))[1];
    $pos += 2;
    return $v;
}

function otdr_u32(string $raw, int &$pos): int
{
    $v   = unpack('V', substr($raw, $pos, 4))[1];
    $pos += 4;
    return $v;
}

function otdr_i32(string $raw, int &$pos): int
{
    $v   = unpack('l', substr($raw, $pos, 4))[1];
    $pos += 4;
    return $v;
}

} // end function_exists guard
