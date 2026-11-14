<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// tools/sync_geonb.php — Mirrors GeoNB province-wide layers into our database.
// Called every loop by the cron Docker service; it does real work at most once
// a night (01:00–05:59 Moncton) per dataset.
//
//   php tools/sync_geonb.php                        # the nightly check
//   php tools/sync_geonb.php --now                  # the nightly check, right now
//   php tools/sync_geonb.php --force                # full load of everything now
//   php tools/sync_geonb.php --force parcel         # full load of one dataset
//
// Two ways a dataset stays current:
//   full        — re-pull everything when GeoNB's count/newest-edit signature
//                 changes (civic addresses: ~2 min).
//   incremental — pull only rows edited since our newest, nightly; plus a full
//                 reload weekly, since an incremental can't see deletions
//                 (parcels: a full pull is ~6 min / 800 MB).
//
// A full load goes into an unlogged stage table and is only swapped in if the
// row count matches what GeoNB said it has. A bad night leaves yesterday's data
// in place, never an empty layer.

define('IS_CLI_POLLER', true);
require_once __DIR__ . '/../www/app/config.php'; // $pdo

const GEONB_REST = 'https://geonb.snb.ca/arcgis/rest/services';
const GEONB_PAGE = 2000;           // the services' usual maxRecordCount; a dataset can set 'page'
const GEONB_FULL_EVERY_DAYS = 7;   // a dataset can set 'full_every_days'

$DATASETS = [
    'civic_address' => [
        'layer' => GEONB_REST . '/GeoNB_DPS_Civic_Address/MapServer/0',
        'table' => 'app.nb_civic_address',
        'key'   => 'civ_id',
        'cols'  => 'civ_id, civic_num, num_suffix, street, st_type, st_dir, community, county, pid,
                    structure, stru_name, add_type, descript, address, modified, geom',
        'ph'    => '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,ST_SetSRID(ST_MakePoint(?,?),4326))',
        'row'   => 'geonb_civic_row',
        'newest'=> ['MODIFIED', 'CREATED'],
    ],
    'parcel' => [
        'layer' => GEONB_REST . '/GeoNB_SNB_Parcels/MapServer/0',
        'table' => 'app.nb_parcel',
        'key'   => 'objectid',
        'cols'  => 'objectid, pid, feature, county, property_map, last_update, area_m2, geom',
        'ph'    => '(?,?,?,?,?,?,?,ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?),4326)))',
        'row'   => 'geonb_parcel_row',
        'newest'=> ['LAST_UPDATE'],
        // Rows edited on or after our newest date; the day itself is re-pulled
        // because edits later that same day share its date.
        'incremental' => ['field' => 'LAST_UPDATE', 'ours' => 'max(last_update)::text',
                          'match' => 'pid'],
    ],
    // Wetlands: ~1 GB for a full pull and rarely edited. The layer has no
    // edit-date field, so the nightly check is count + highest OBJECTID only,
    // backed by a weekly full reload (the default) to catch in-place edits.
    'wetland' => [
        'layer' => GEONB_REST . '/GeoNB_ENV_Wetlands/MapServer/2',
        'table' => 'app.nb_wetland',
        'key'   => 'objectid',
        'cols'  => 'objectid, wc, wetland_class, hectares, geom',
        'ph'    => '(?,?,?,?,ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?),4326)))',
        'row'   => 'geonb_wetland_row',
        'newest'=> [],
        'page'  => 1000,
    ],
    // National Railway Network tracks (~1.4k lines): only styles the Parcels layer.
    'railway' => [
        'layer' => GEONB_REST . '/GeoNB_SNB_NRWN/MapServer/5',
        'table' => 'app.nb_railway',
        'key'   => 'objectid',
        'cols'  => 'objectid, track_name, owner, operator, status, geom',
        'ph'    => '(?,?,?,?,?,ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?),4326)))',
        'row'   => 'geonb_railway_row',
        'newest'=> [],
    ],
    'wetland_psw' => [
        'layer' => GEONB_REST . '/GeoNB_ENV_Wetlands/MapServer/1',
        'table' => 'app.nb_wetland_psw',
        'key'   => 'objectid',
        'cols'  => 'objectid, wc, wetland_class, hectares, geom',
        'ph'    => '(?,?,?,?,ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?),4326)))',
        'row'   => 'geonb_wetland_row',
        'newest'=> [],
        'page'  => 1000,
    ],
];

function geonb_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] geonb: ' . $msg . PHP_EOL;
}

function geonb_get(string $url, array $params): array
{
    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => org('http_agent') . ' (nightly GeoNB mirror)',
        CURLOPT_ENCODING       => '',
    ]);
    for ($try = 1; ; $try++) {
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($code === 200 && is_array($data) && !isset($data['error'])) break;
        if ($try >= 3) {
            $why = $data['error']['message'] ?? curl_error($ch) ?: "HTTP $code";
            curl_close($ch);
            throw new RuntimeException("GeoNB request failed: $why");
        }
        sleep(5 * $try);
    }
    curl_close($ch);
    return $data;
}

// count | newest edit field(s) | highest OBJECTID. Any add, delete or edit
// moves at least one of these.
function geonb_signature(array $ds): array
{
    $stats = [['statisticType' => 'count', 'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'n'],
              ['statisticType' => 'max',   'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'o']];
    foreach ($ds['newest'] as $i => $f) {
        $stats[] = ['statisticType' => 'max', 'onStatisticField' => $f, 'outStatisticFieldName' => "m$i"];
    }
    $a = geonb_get($ds['layer'] . '/query', ['where' => '1=1', 'outStatistics' => json_encode($stats), 'f' => 'json'])
        ['features'][0]['attributes'] ?? [];
    ksort($a);
    return [(int)($a['n'] ?? 0), implode('|', $a)];
}

function geonb_count(array $ds, string $where): int
{
    return (int)(geonb_get($ds['layer'] . '/query', ['where' => $where, 'returnCountOnly' => 'true', 'f' => 'json'])['count'] ?? -1);
}

function geonb_ms(?int $ms): ?string
{
    return $ms === null ? null : gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
}

// One GeoNB civic address feature → our row, or null to skip it.
function geonb_civic_row(array $f): ?array
{
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? null;
    $id = trim((string)($p['CIV_ID'] ?? ''), '{}');
    if (!$c || $id === '') return null;

    $street = trim(ucwords(strtolower((string)($p['STREET'] ?? '')), " -'"));
    $parts  = array_filter([
        trim(($p['CIVIC_NUM'] ?? '') . ($p['NUM_SUFFIX'] ?? '')),
        $street,
        // 'ROUTE 480' already says what it is; don't make it 'Route 480 Highway'
        preg_match('/^(route|highway)\b/i', $street) ? '' : ($p['ST_TYPE_E'] ?? ''),
        $p['ST_DIR_E'] ?? '',
    ]);
    $address = implode(' ', $parts);
    if (!empty($p['COMMUNITY'])) $address .= ', ' . $p['COMMUNITY'];

    return [
        $id,
        $p['CIVIC_NUM'] ?? null,
        $p['NUM_SUFFIX'] ?? null,
        $p['STREET'] ?? null,
        $p['ST_TYPE_E'] ?? null,
        $p['ST_DIR_E'] ?? null,
        $p['COMMUNITY'] ?? null,
        $p['COUNTY'] ?? null,
        // SNB PIDs are 8 digits; the civic layer drops the leading zeros
        ctype_digit((string)($p['PID'] ?? '')) ? str_pad($p['PID'], 8, '0', STR_PAD_LEFT) : ($p['PID'] ?? null),
        $p['STRUCT_E'] ?? null,
        $p['STRU_NAME'] ?? null,
        $p['ADD_TYPE_E'] ?? null,
        $p['DESCRIPT'] ?? null,
        $address,
        geonb_ms($p['MODIFIED'] ?? null),
        $c[0], $c[1],
    ];
}

// One SNB parcel feature → our row, or null to skip it.
function geonb_parcel_row(array $f): ?array
{
    $p = $f['properties'] ?? [];
    if (empty($f['geometry']) || empty($p['PID']) || !isset($p['OBJECTID'])) return null;
    $d = $p['LAST_UPDATE'] ?? null;
    return [
        (int)$p['OBJECTID'],
        $p['PID'],
        $p['FEATURE'] ?? null,
        $p['COUNTY'] ?? null,
        $p['PROPERTY_MAP'] ?? null,
        ($d && $d > '1900-01-01') ? $d : null,    // 1900-01-01 is SNB's "unknown"
        $p['Shape_Area'] ?? null,
        json_encode($f['geometry']),
    ];
}

// One wetland polygon (either layer) → our row.
function geonb_wetland_row(array $f): ?array
{
    $p = $f['properties'] ?? [];
    if (empty($f['geometry']) || !isset($p['OBJECTID'])) return null;
    return [(int)$p['OBJECTID'], $p['WC'] ?? null, $p['WETLAND_CLASS'] ?? null,
            $p['Hectares'] ?? null, json_encode($f['geometry'])];
}

// One railway track segment → our row.
function geonb_railway_row(array $f): ?array
{
    $p = $f['properties'] ?? [];
    if (empty($f['geometry']) || !isset($p['OBJECTID'])) return null;
    $name = static fn($v) => ($v === null || $v === 'None' || $v === '') ? null : $v;
    return [(int)$p['OBJECTID'], $name($p['TRACKNAME'] ?? null), $name($p['OWNERENA'] ?? null),
            $name($p['OPERATOENA'] ?? null), $name($p['STATUS'] ?? null), json_encode($f['geometry'])];
}

// Pull every feature matching $where into the dataset's stage table.
// Returns how many features GeoNB sent.
function geonb_fetch_to_stage(PDO $pdo, array $ds, string $where): int
{
    $stage = $ds['table'] . '_stage';
    $pdo->exec("TRUNCATE $stage");

    // Page by OBJECTID rather than offset, so an edit on GeoNB's side mid-pull
    // can't shift a page and drop or double rows.
    $last = 0; $n = 0;
    do {
        $page = geonb_get($ds['layer'] . '/query', [
            'where'             => "($where) AND OBJECTID > $last",
            'outFields'         => '*',
            'outSR'             => 4326,
            'orderByFields'     => 'OBJECTID',
            'resultRecordCount' => $ds['page'] ?? GEONB_PAGE,
            'f'                 => 'geojson',
        ]);
        $feats = $page['features'] ?? [];
        $vals  = []; $rows = 0;
        foreach ($feats as $f) {
            $last = max($last, (int)($f['properties']['OBJECTID'] ?? $f['id'] ?? 0));
            if ($r = ($ds['row'])($f)) { array_push($vals, ...$r); $rows++; }
        }
        if ($rows) {
            $pdo->prepare("INSERT INTO $stage ({$ds['cols']}) VALUES " . implode(',', array_fill(0, $rows, $ds['ph'])))
                ->execute($vals);
        }
        $n += count($feats);
    } while (count($feats) > 0 && !empty($page['exceededTransferLimit'] ?? $page['properties']['exceededTransferLimit'] ?? false));
    return $n;
}

function geonb_full_load(PDO $pdo, string $name, array $ds, int $expect): int
{
    $n = geonb_fetch_to_stage($pdo, $ds, '1=1');
    if ($n !== $expect) {
        throw new RuntimeException("$name: fetched $n features but GeoNB reports $expect; keeping the previous load");
    }
    $stage = $ds['table'] . '_stage';
    // Readers keep seeing the old rows until this commits.
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM {$ds['table']}");
    $kept = $pdo->exec("INSERT INTO {$ds['table']} SELECT DISTINCT ON ({$ds['key']}) * FROM $stage");
    $pdo->commit();
    $pdo->exec("TRUNCATE $stage");
    $pdo->exec("VACUUM ANALYZE {$ds['table']}");
    return $kept;
}

// Replace just the rows GeoNB edited since our newest. A parcel redrawn under
// a new OBJECTID is still caught by its PID, so the old shape doesn't linger.
function geonb_incremental_load(PDO $pdo, string $name, array $ds): int
{
    $inc   = $ds['incremental'];
    $since = $pdo->query("SELECT {$inc['ours']} FROM {$ds['table']}")->fetchColumn();
    if (!$since) throw new RuntimeException("$name: no rows yet; needs a full load");
    $where  = "{$inc['field']} >= '" . preg_replace('/[^0-9-]/', '', $since) . "'";
    $expect = geonb_count($ds, $where);
    $n      = geonb_fetch_to_stage($pdo, $ds, $where);
    if ($n !== $expect) {
        throw new RuntimeException("$name: fetched $n of $expect changed features; keeping the previous data");
    }
    $stage = $ds['table'] . '_stage';
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM {$ds['table']} t USING $stage s
                WHERE t.{$ds['key']} = s.{$ds['key']} OR t.{$inc['match']} = s.{$inc['match']}");
    $kept = $pdo->exec("INSERT INTO {$ds['table']} SELECT DISTINCT ON ({$ds['key']}) * FROM $stage");
    $pdo->commit();
    $pdo->exec("TRUNCATE $stage");
    return $kept;
}

// ── Main ──────────────────────────────────────────────────────────────────────

$args  = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$now   = $force || in_array('--now', $args, true);
$only  = array_values(array_diff($args, ['--force', '--now']));
$hour  = (int)date('G');

foreach ($DATASETS as $name => $ds) {
    if ($only && !in_array($name, $only, true)) continue;

    $st = $pdo->prepare("SELECT *, checked_at::date = CURRENT_DATE AS checked_today,
                                coalesce(loaded_at < now() - make_interval(days => ?), true) AS stale
                         FROM app.geonb_sync WHERE dataset = ?");
    $st->execute([$ds['full_every_days'] ?? GEONB_FULL_EVERY_DAYS, $name]);
    $sync = $st->fetch() ?: ['signature' => null, 'checked_today' => false, 'stale' => true];

    if (!$now && ($hour < 1 || $hour > 5 || $sync['checked_today'])) continue;

    $pdo->prepare("INSERT INTO app.geonb_sync (dataset, checked_at) VALUES (?, now())
                   ON CONFLICT (dataset) DO UPDATE SET checked_at = now()")->execute([$name]);
    try {
        [$count, $sig] = geonb_signature($ds);
        if (!$force && !$sync['stale'] && $sig === $sync['signature']) {
            geonb_log("$name unchanged ($count rows)");
            continue;
        }
        $t0 = microtime(true);
        if (!$force && !$sync['stale'] && isset($ds['incremental'])) {
            $kept = geonb_incremental_load($pdo, $name, $ds);
            $total = (int)$pdo->query("SELECT count(*) FROM {$ds['table']}")->fetchColumn();
            $pdo->prepare("UPDATE app.geonb_sync SET signature = ?, row_count = ?, updated_at = now(),
                                  last_error = NULL WHERE dataset = ?")
                ->execute([$sig, $total, $name]);
            geonb_log("$name updated $kept changed rows in " . round(microtime(true) - $t0, 1) . "s ($total total)");
            continue;
        }
        geonb_log("$name full load of $count rows" . ($force ? ' (forced)' : ''));
        $kept = geonb_full_load($pdo, $name, $ds, $count);
        $secs = round(microtime(true) - $t0, 1);
        $pdo->prepare("UPDATE app.geonb_sync SET signature = ?, row_count = ?, loaded_at = now(), updated_at = now(),
                              load_seconds = ?, last_error = NULL WHERE dataset = ?")
            ->execute([$sig, $kept, $secs, $name]);
        geonb_log("$name loaded $kept rows in {$secs}s");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE app.geonb_sync SET last_error = ? WHERE dataset = ?")
            ->execute([substr($e->getMessage(), 0, 1000), $name]);
        geonb_log("$name FAILED: " . $e->getMessage());
    }
}
