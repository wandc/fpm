<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST   /api/vault.php        — create vault/handhole/pullbox
// PUT    /api/vault.php?id=N   — update vault
// DELETE /api/vault.php?id=N   — delete vault

require_once __DIR__ . '/_common.php';

/** @var PDO $pdo */

$method = api_method();

/**
 * Model of the vault: a BOM material id (category Vault) or free text. Returns [model_id, model_other];
 * one we own (owner blank) must have one of the two.
 */
function vault_model(PDO $pdo, array $body, string $owner, bool $required): array
{
    $id    = isset($body['model_id']) && $body['model_id'] !== '' ? (int)$body['model_id'] : null;
    $other = trim((string)($body['model_other'] ?? ''));
    if ($id !== null) {
        $c = $pdo->prepare("SELECT 1 FROM app.bom_material WHERE id = ? AND category = 'Vault'");
        $c->execute([$id]);
        if (!$c->fetchColumn()) api_err('Unknown model', 400);
        $other = '';
    }
    if ($required && $owner === '' && $id === null && $other === '') api_err('Pick the model (required for ' . org('own_name') . '-owned)', 400);
    return [$id, $other !== '' ? $other : null];
}

// ── CREATE ────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    api_require_csrf();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = trim($body['name']   ?? '');
    $type    = trim($body['type']   ?? 'vault');
    $owner   = trim($body['owner']  ?? '');
    $ownerRef = trim($body['owner_ref'] ?? '');
    $status  = trim($body['status'] ?? 'plan');
    $notes   = trim($body['notes']  ?? '');
    $fpmWor = trim($body['fpm_wor'] ?? '');
    $geojson = $body['geojson']      ?? null;

    $validTypes    = ['vault', 'handhole', 'pullbox', 'manhole'];
    $validStatuses = ['plan', 'active', 'retired'];
    $validOwners   = ['', 'bell', 'nbpower', 'rogers', 'private'];
    if (!in_array($type, $validTypes))     api_err('Invalid type', 400);
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if (!in_array($owner, $validOwners))    api_err('Invalid owner', 400);
    if (!$geojson || ($geojson['type'] ?? '') !== 'Point') api_err('geojson Point required', 400);

    [$modelId, $modelOther] = vault_model($pdo, $body, $owner, true);

    $pdo->beginTransaction();
    $st = $pdo->prepare("
        INSERT INTO app.vault (name, type, owner, owner_ref, status, notes, fpm_wor, model_id, model_other, geom)
        VALUES (:name, :type::app.vault_type, :owner, :owner_ref, :status::app.vault_status, :notes, :fpm_wor, :model_id, :model_other,
                ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326))
        RETURNING vault_id
    ");
    $st->execute([
        ':name'    => $name,
        ':type'    => $type,
        ':owner'   => $owner !== '' ? $owner : null,
        ':owner_ref' => $ownerRef !== '' ? $ownerRef : null,
        ':status'  => $status,
        ':notes'   => $notes,
        ':fpm_wor' => $fpmWor !== '' ? $fpmWor : null,
        ':model_id' => $modelId,
        ':model_other' => $modelOther,
        ':geojson' => json_encode($geojson),
    ]);
    $id = (int)$st->fetchColumn();
    // Conduits start and stop at a vault: split one passing through, snap one ending nearby.
    $att = $pdo->prepare("SELECT app.vault_attach_conduits(:id, NULL)");
    $att->execute([':id' => $id]);
    $pdo->commit();
    api_ok(['vault_id' => $id, 'conduits' => json_decode((string)$att->fetchColumn(), true)]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = trim($body['name']   ?? '');
    $type    = trim($body['type']   ?? 'vault');
    $owner   = trim($body['owner']  ?? '');
    $ownerRef = trim($body['owner_ref'] ?? '');
    $status  = trim($body['status'] ?? 'plan');
    $notes   = trim($body['notes']  ?? '');
    $fpmWor = trim($body['fpm_wor'] ?? '');

    $validTypes    = ['vault', 'handhole', 'pullbox', 'manhole'];
    $validStatuses = ['plan', 'active', 'retired'];
    $validOwners   = ['', 'bell', 'nbpower', 'rogers', 'private'];
    if (!in_array($type, $validTypes))     api_err('Invalid type', 400);
    if (!in_array($status, $validStatuses)) api_err('Invalid status', 400);
    if (!in_array($owner, $validOwners))    api_err('Invalid owner', 400);

    $geojson = $body['geojson'] ?? null;
    if ($geojson && ($geojson['type'] ?? '') !== 'Point') api_err('geojson must be Point', 400);
    $setModel = array_key_exists('model_id', $body) || array_key_exists('model_other', $body);
    if ($setModel) [$modelId, $modelOther] = vault_model($pdo, $body, $owner, true);

    $pdo->beginTransaction();
    $old = null;
    if ($geojson) {
        $o = $pdo->prepare("SELECT ST_AsEWKT(geom) FROM app.vault WHERE vault_id = :id FOR UPDATE");
        $o->execute([':id' => $id]);
        $old = $o->fetchColumn() ?: null;
    }
    $st = $pdo->prepare("
        UPDATE app.vault
           SET name   = :name,
               type   = :type::app.vault_type,
               owner  = :owner,
               owner_ref = :owner_ref,
               status = :status::app.vault_status,
               notes  = :notes,
               fpm_wor = :fpm_wor" . ($setModel ? ",
               model_id = :model_id, model_other = :model_other" : "") . ($geojson ? ",
               geom = ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)" : "") . "
         WHERE vault_id = :id
    ");
    $st->execute([
        ':name'   => $name,
        ':type'   => $type,
        ':owner'  => $owner !== '' ? $owner : null,
        ':owner_ref' => $ownerRef !== '' ? $ownerRef : null,
        ':status' => $status,
        ':notes'  => $notes,
        ':fpm_wor' => $fpmWor !== '' ? $fpmWor : null,
        ':id'     => $id,
    ] + ($setModel ? [':model_id' => $modelId, ':model_other' => $modelOther] : [])
      + ($geojson ? [':geojson' => json_encode($geojson)] : []));
    if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Vault not found', 404); }
    $conduits = null;
    if ($geojson) {
        // Conduit ends that were on the vault go with it; any conduit now at it is split or snapped.
        $att = $pdo->prepare("SELECT app.vault_attach_conduits(:id, ST_GeomFromEWKT(:old))");
        $att->execute([':id' => $id, ':old' => $old]);
        $conduits = json_decode((string)$att->fetchColumn(), true);
    }
    $pdo->commit();
    api_ok(['vault_id' => $id, 'conduits' => $conduits]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    api_require_csrf();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) api_err('id required', 400);

    $use = $pdo->prepare('SELECT conduit_id FROM app.conduit WHERE start_vault_id = ? OR end_vault_id = ? ORDER BY 1');
    $use->execute([$id, $id]);
    $ids = $use->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) api_err('Conduit ' . implode(', ', $ids) . ' ends at this vault. A conduit end has to be on a vault, handhole or termination; move or delete the conduit first.', 400);
    $pdo->beginTransaction();
    $st = $pdo->prepare("DELETE FROM app.vault WHERE vault_id = :id");
    $st->execute([':id' => $id]);
    if ($st->rowCount() === 0) { $pdo->rollBack(); api_err('Vault not found', 404); }
    api_delete_timeline($pdo, 'vault', $id);
    $pdo->commit();
    api_ok(['deleted' => true]);
}

api_err('Method not allowed', 405);
