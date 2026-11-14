<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// PUT    /api/parcel_owner.php   {pid, owner, as_of?, source?, notes?}   set the owner of a parcel (replaces the one on file)
// DELETE /api/parcel_owner.php?pid=NNNNNNNN                               remove it
// Read through api/nb_parcels.php (the PID popup).

require_once __DIR__ . '/_common.php';
/** @var PDO $pdo */

function po_text($v): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
function po_pid($v): string
{
    $p = preg_replace('/\D/', '', (string)$v);
    if (!preg_match('/^\d{8}$/', $p) || (int)$p < 10) api_err('A real 8-digit PID is required', 400);
    return $p;
}

$method = api_method();

if ($method === 'PUT') {
    api_require_csrf();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid = po_pid($b['pid'] ?? '');
    $owner = po_text($b['owner'] ?? null);
    if ($owner === null) api_err('An owner name is required', 400);
    $asOf = po_text($b['as_of'] ?? null);
    if ($asOf !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_err('Date must be YYYY-MM-DD', 400);
    $st = $pdo->prepare("INSERT INTO app.parcel_owner (pid, owner, as_of, source, notes) VALUES (:pid, :o, :d, :s, :n)
                         ON CONFLICT (pid) DO UPDATE SET owner = EXCLUDED.owner, as_of = EXCLUDED.as_of,
                           source = EXCLUDED.source, notes = EXCLUDED.notes");
    $st->execute([':pid' => $pid, ':o' => $owner, ':d' => $asOf, ':s' => po_text($b['source'] ?? null), ':n' => po_text($b['notes'] ?? null)]);
    api_ok(['pid' => $pid]);
}

if ($method === 'DELETE') {
    api_require_csrf();
    $pid = po_pid($_GET['pid'] ?? '');
    $pdo->prepare("DELETE FROM app.parcel_owner WHERE pid = ?")->execute([$pid]);
    api_ok(['pid' => $pid]);
}

api_err('Method not allowed', 405);
