<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET  /api/nb_addresses.php?bbox=W,S,E,N
// POST /api/nb_addresses.php {civ_id, ignore: true|false}   hide / unhide one address
// POST /api/nb_addresses.php {civ_id, move: [lat, lon]}      correct its position
// POST /api/nb_addresses.php {civ_id, move: null}            back to GeoNB's position
// POST /api/nb_addresses.php {add: {lat, lon, civic_num?, num_suffix?, street?, st_type?, community?, structure?, note?}}
//                                                            add an address GeoNB is missing
// POST /api/nb_addresses.php {add_id, move: [lat, lon]} | {add_id, delete: true}   edit one of ours
// GET  /api/nb_addresses.php?near=lat,lon                    street/community/PID to pre-fill the add form
// GeoNB civic addresses in the viewport, for the map's "Civic #s" layer.
// Served from app.nb_civic_address (migration 0237), which tools/sync_geonb.php
// refreshes nightly from GeoNB. The client only asks at zoom >= 14; the row cap
// is a backstop for a wide screen over a dense town.
//
// Rows are compact arrays to keep the payload small:
//   [lat, lon, label, address, pid, structure, id, moved, added]
// (id is GeoNB's civ_id, or our add_id when added = true)
//
// Ignored addresses (app.nb_civic_address_ignore, 0240) are left out, and moved
// ones (app.nb_civic_address_move, 0241) are at our position. Both are keyed by
// GeoNB's civ_id in their own tables, so the nightly reload never clears them.

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

function civic_in_nb(float $lat, float $lon): void
{
    if ($lat < 44 || $lat > 48.5 || $lon < -69.5 || $lon > -63.5) api_err('Position is outside New Brunswick', 400);
}

// Street, type and community of the nearest GeoNB address, and the parcel under the point.
function civic_near(PDO $pdo, float $lat, float $lon): array
{
    $st = $pdo->prepare("
        WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) AS g)
        SELECT a.street, a.st_type, a.community, a.county,
               round(ST_Distance(a.geom::geography, p.g::geography)) AS dist_m,
               (SELECT pid FROM app.nb_parcel WHERE ST_Intersects(geom, p.g) ORDER BY area_m2 NULLS LAST LIMIT 1) AS pid
        FROM app.nb_civic_address a, p
        ORDER BY a.geom <-> p.g LIMIT 1");
    $st->execute([':lat' => $lat, ':lon' => $lon]);
    return $st->fetch() ?: [];
}

if (api_method() === 'GET' && isset($_GET['near'])) {
    [$lat, $lon] = array_map('floatval', explode(',', (string)$_GET['near']) + [0, 0]);
    civic_in_nb($lat, $lon);
    api_ok(['near' => civic_near($pdo, $lat, $lon)]);
}

if (api_method() === 'POST') {
    api_require_csrf();
    $b   = api_json_body();
    $who = fpm_current_identity();
    $by  = $who['email'] ?: ($who['display_name'] ?? '');

    // ── Our own added addresses ───────────────────────────────────────────────
    if (isset($b['add'])) {
        $a   = (array)$b['add'];
        $lat = (float)($a['lat'] ?? 0); $lon = (float)($a['lon'] ?? 0);
        civic_in_nb($lat, $lon);
        $near   = civic_near($pdo, $lat, $lon);
        $num    = isset($a['civic_num']) && $a['civic_num'] !== '' ? (int)$a['civic_num'] : null;
        $sfx    = strtoupper(trim((string)($a['num_suffix'] ?? ''))) ?: null;
        $street = strtoupper(trim((string)($a['street'] ?? ''))) ?: ($near['street'] ?? null);
        $type   = trim((string)($a['st_type'] ?? '')) ?: ($street === ($near['street'] ?? null) ? ($near['st_type'] ?? null) : null);
        $comm   = trim((string)($a['community'] ?? '')) ?: ($near['community'] ?? null);
        if (!$street) api_err('Street is required', 400);
        $pretty = trim(ucwords(strtolower($street), " -'"));
        $address = trim(($num !== null ? $num . ($sfx ?? '') : '(no #)') . ' ' . $pretty
                 . (preg_match('/^(route|highway)\b/i', $pretty) ? '' : ' ' . ($type ?? ''))) . ($comm ? ", $comm" : '');
        $st = $pdo->prepare("INSERT INTO app.nb_civic_address_add
                (civic_num, num_suffix, street, st_type, community, county, pid, structure, address, note, geom, added_by)
                VALUES (?,?,?,?,?,?,?,?,?,?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?) RETURNING add_id");
        $st->execute([$num, $sfx, $street, $type, $comm, $near['county'] ?? null, $near['pid'] ?? null,
                      trim((string)($a['structure'] ?? '')) ?: 'Residence', $address,
                      trim((string)($a['note'] ?? '')) ?: null, $lon, $lat, $by]);
        api_ok(['add_id' => $st->fetchColumn(), 'address' => $address, 'pid' => $near['pid'] ?? null]);
    }
    if (isset($b['add_id'])) {
        $id = strtolower(trim((string)$b['add_id']));
        if (!preg_match(UUID_RE, $id)) api_err('Invalid add_id', 400);
        if (!empty($b['delete'])) {
            $pdo->prepare("DELETE FROM app.nb_civic_address_add WHERE add_id = ?::uuid")->execute([$id]);
            api_ok(['deleted' => true]);
        }
        if (is_array($b['move'] ?? null)) {
            [$lat, $lon] = array_map('floatval', $b['move'] + [0, 0]);
            civic_in_nb($lat, $lon);
            $pdo->prepare("UPDATE app.nb_civic_address_add
                           SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326),
                               pid  = (SELECT pid FROM app.nb_parcel WHERE ST_Intersects(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
                                       ORDER BY area_m2 NULLS LAST LIMIT 1)
                           WHERE add_id = ?::uuid")->execute([$lon, $lat, $lon, $lat, $id]);
            api_ok(['moved' => true]);
        }
        api_err('Nothing to do', 400);
    }

    // ── GeoNB addresses: move / ignore ────────────────────────────────────────
    $civ = strtolower(trim((string)($b['civ_id'] ?? '')));
    if (!preg_match(UUID_RE, $civ)) api_err('Invalid civ_id', 400);
    if (array_key_exists('move', $b)) {
        if ($b['move'] === null) {
            $pdo->prepare("DELETE FROM app.nb_civic_address_move WHERE civ_id = ?::uuid")->execute([$civ]);
            api_ok(['civ_id' => $civ, 'moved' => false]);
        }
        [$lat, $lon] = array_map('floatval', (array)$b['move'] + [0, 0]);
        civic_in_nb($lat, $lon);
        $pdo->prepare("INSERT INTO app.nb_civic_address_move (civ_id, geom, orig_geom, address, moved_by)
                       SELECT ?::uuid, ST_SetSRID(ST_MakePoint(?, ?), 4326), a.geom, a.address, ?
                       FROM app.nb_civic_address a WHERE a.civ_id = ?::uuid
                       ON CONFLICT (civ_id) DO UPDATE SET geom = EXCLUDED.geom, moved_by = EXCLUDED.moved_by, moved_at = now()")
            ->execute([$civ, $lon, $lat, $by, $civ]);
        api_ok(['civ_id' => $civ, 'moved' => true]);
    }
    if (!empty($b['ignore'])) {
        $pdo->prepare("INSERT INTO app.nb_civic_address_ignore (civ_id, address, ignored_by)
                       SELECT ?::uuid, (SELECT address FROM app.nb_civic_address WHERE civ_id = ?::uuid), ?
                       ON CONFLICT (civ_id) DO NOTHING")
            ->execute([$civ, $civ, $by]);
    } else {
        $pdo->prepare("DELETE FROM app.nb_civic_address_ignore WHERE civ_id = ?::uuid")->execute([$civ]);
    }
    api_ok(['civ_id' => $civ, 'ignored' => !empty($b['ignore'])]);
}
if (api_method() !== 'GET') api_err('Method not allowed', 405);

$parts = array_map('floatval', explode(',', (string)($_GET['bbox'] ?? '')));
if (count($parts) !== 4 || count(array_filter($parts, 'is_finite')) !== 4) api_err('bbox=W,S,E,N required', 400);
[$w, $s, $e, $n] = $parts;
if (($e - $w) * ($n - $s) > 0.05) api_err('Area too large; zoom in', 400);

$LIMIT = 6000;
// Candidates from both indexes (GeoNB's point in view, or our moved point in
// view), then our position wins. Same result as app.v_nb_civic_address, but
// each half can use its spatial index.
$st = $pdo->prepare("
    WITH env AS (SELECT ST_MakeEnvelope(:w, :s, :e, :n, 4326) AS g),
    ids AS (
        SELECT civ_id FROM app.nb_civic_address, env WHERE geom && env.g
        UNION
        SELECT civ_id FROM app.nb_civic_address_move, env WHERE geom && env.g
    )
    SELECT ST_Y(coalesce(m.geom, a.geom)) AS lat, ST_X(coalesce(m.geom, a.geom)) AS lon,
           coalesce(a.civic_num::text, '') || coalesce(a.num_suffix, '') AS label,
           a.address, a.pid, a.structure, a.civ_id, m.civ_id IS NOT NULL AS moved, false AS added
    FROM ids
    JOIN app.nb_civic_address a USING (civ_id)
    LEFT JOIN app.nb_civic_address_move m USING (civ_id)
    WHERE NOT EXISTS (SELECT 1 FROM app.nb_civic_address_ignore i WHERE i.civ_id = a.civ_id)
      AND coalesce(m.geom, a.geom) && (SELECT g FROM env)
    UNION ALL
    SELECT ST_Y(d.geom), ST_X(d.geom), coalesce(d.civic_num::text || coalesce(d.num_suffix, ''), '?'),
           d.address, d.pid, d.structure, d.add_id, false, true
    FROM app.nb_civic_address_add d, env WHERE d.geom && env.g
    LIMIT $LIMIT + 1
");
$st->execute([':w' => $w, ':s' => $s, ':e' => $e, ':n' => $n]);
$rows = $st->fetchAll(PDO::FETCH_NUM);

$truncated = count($rows) > $LIMIT;
if ($truncated) array_pop($rows);
foreach ($rows as &$r) { $r[0] = round((float)$r[0], 6); $r[1] = round((float)$r[1], 6); $r[7] = (bool)$r[7]; $r[8] = (bool)$r[8]; }
unset($r);

$sync = $pdo->query("SELECT loaded_at FROM app.geonb_sync WHERE dataset = 'civic_address'")->fetchColumn();

header('Cache-Control: max-age=300, private');
api_ok(['rows' => $rows, 'truncated' => $truncated, 'loaded_at' => $sync ?: null]);
