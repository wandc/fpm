<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// GET    /api/circuit.php                       — all circuits with their fibres
// POST   /api/circuit.php                       — create {name, kind, customer_name?, crm_client_id?, speed_mbps?, status?, protected_by_link?, notes?, fibres?:[{span, fibre, role?}]}
// PUT    /api/circuit.php?id=N                  — edit the circuit's own fields
// DELETE /api/circuit.php?id=N                  — delete a circuit (its fibre rows go with it)
// POST   /api/circuit.php?id=N&action=add_fibre — {span, fibre, role?}
// DELETE /api/circuit.php?fibre_id=N            — take one fibre off its circuit

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

function circ_text($v): ?string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : $v;
}

function circ_fields(array $b): array
{
    $kind   = $b['kind'] ?? 'p2p';
    $status = $b['status'] ?? 'active';
    if (!in_array($kind, ['p2p', 'dwdm_channel', 'pon_feeder', 'transport', 'other'], true)) api_err('Bad kind');
    if (!in_array($status, ['planned', 'active', 'retired'], true)) api_err('Bad status');
    $name = circ_text($b['name'] ?? null);
    if ($name === null) api_err('Name required');
    $speed = circ_text($b['speed_mbps'] ?? null);
    $crm   = circ_text($b['crm_client_id'] ?? null);
    return [
        ':name'   => $name,
        ':kind'   => $kind,
        ':cust'   => circ_text($b['customer_name'] ?? null),
        ':crm'    => $crm !== null ? (int)$crm : null,
        ':speed'  => $speed !== null ? (int)$speed : null,
        ':status' => $status,
        ':prot'   => circ_text($b['protected_by_link'] ?? null),
        ':notes'  => circ_text($b['notes'] ?? null),
    ];
}

function circ_add_fibre(PDO $pdo, int $circuitId, $span, $fibre, $role): void
{
    $role = $role === 'protect' ? 'protect' : 'working';
    $pdo->prepare('INSERT INTO app.circuit_fibre (circuit_id, cable_span_id, fibre_num, role) VALUES (?, ?, ?, ?)
                   ON CONFLICT (circuit_id, cable_span_id, fibre_num) DO UPDATE SET role = EXCLUDED.role')
        ->execute([$circuitId, (int)$span, (int)$fibre, $role]);
}

try {
    if ($method === 'GET') {
        $rows = $pdo->query("
            SELECT c.*, l.name AS protected_by_name
              FROM app.circuit c LEFT JOIN app.lnd_link l ON l.link_id = c.protected_by_link
             ORDER BY c.status = 'retired', c.name")->fetchAll(PDO::FETCH_ASSOC);
        $fib = $pdo->query("
            SELECT cf.id, cf.circuit_id, cf.cable_span_id, cf.fibre_num, cf.role,
                   cb.name AS cable_name, f1.splice_num AS from_label, f2.splice_num AS to_label
              FROM app.circuit_fibre cf
              JOIN app.cable_span s ON s.cable_span_id = cf.cable_span_id
              JOIN app.cable cb ON cb.cable_id = s.cable_id
              JOIN app.fosc f1 ON f1.fosc_id = s.from_fosc_id
              JOIN app.fosc f2 ON f2.fosc_id = s.to_fosc_id
             ORDER BY cf.circuit_id, cf.role DESC, cf.cable_span_id, cf.fibre_num")->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($fib as $f) $by[(int)$f['circuit_id']][] = $f;
        foreach ($rows as &$r) $r['fibres'] = $by[(int)$r['circuit_id']] ?? [];
        unset($r);
        $links = $pdo->query('SELECT link_id, name FROM app.lnd_link ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
        api_ok(['circuits' => $rows, 'links' => $links]);
    }

    api_require_csrf();
    $b = in_array($method, ['POST', 'PUT'], true) ? api_json_body() : [];

    if ($method === 'POST' && ($_GET['action'] ?? '') === 'add_fibre') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0 || empty($b['span']) || empty($b['fibre'])) api_err('id, span and fibre required');
        circ_add_fibre($pdo, $id, $b['span'], $b['fibre'], $b['role'] ?? 'working');
        api_ok(['circuit_id' => $id]);
    }

    if ($method === 'POST') {
        $f = circ_fields($b);
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO app.circuit (name, kind, customer_name, crm_client_id, speed_mbps, status, protected_by_link, notes)
                             VALUES (:name, :kind, :cust, :crm, :speed, :status, :prot, :notes) RETURNING circuit_id');
        $st->execute($f);
        $id = (int)$st->fetchColumn();
        foreach ($b['fibres'] ?? [] as $x) circ_add_fibre($pdo, $id, $x['span'] ?? 0, $x['fibre'] ?? 0, $x['role'] ?? 'working');
        $pdo->commit();
        api_ok(['circuit_id' => $id]);
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) api_err('id required');
        $f = circ_fields($b);
        $f[':id'] = $id;
        $st = $pdo->prepare('UPDATE app.circuit SET name = :name, kind = :kind, customer_name = :cust, crm_client_id = :crm,
                                    speed_mbps = :speed, status = :status, protected_by_link = :prot, notes = :notes
                              WHERE circuit_id = :id');
        $st->execute($f);
        if ($st->rowCount() === 0) api_err('Circuit not found', 404);
        api_ok(['circuit_id' => $id]);
    }

    if ($method === 'DELETE') {
        if (!empty($_GET['fibre_id'])) {
            $st = $pdo->prepare('DELETE FROM app.circuit_fibre WHERE id = ?');
            $st->execute([(int)$_GET['fibre_id']]);
            if ($st->rowCount() === 0) api_err('Fibre row not found', 404);
            api_ok(['deleted' => true]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) api_err('id required');
        $st = $pdo->prepare('DELETE FROM app.circuit WHERE circuit_id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) api_err('Circuit not found', 404);
        api_ok(['deleted' => true]);
    }

    api_err('Method not allowed', 405);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    [$msg, $status] = api_db_error_message($e);
    api_err($msg, $status);
}
