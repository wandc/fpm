<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/pop_detail.php
// POP detail page (modeled on the closure page): Overview + Connection Diagram +
// Timeline. The diagram reuses the closure splice-diagram renderer, but the wires
// are patch cables (join_kind='connector') aggregated across the POP's closures.

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/pop.helper.php';

$popId = isset($_GET['pop_id']) ? (int)$_GET['pop_id'] : 0;
$pop   = $popId > 0 ? fetch_pop($pdo, $popId) : null;

if (!$pop) {
    $PAGE->setTitle('POP not found');
    $PAGE->setContentRenderer(function () use ($popId) {
        return '<div class="alert alert-danger m-3">POP not found (pop_id=' . (int)$popId . '). '
             . '<a href="index.php?page=pop">Back to POPs</a></div>';
    });
    return;
}

// ── POST actions: add a POP-homed device, or add a patch cable ──────────────────
if (is_post()) {
    $popDetailRedirect = 'index.php?page=pop_detail&pop_id=' . $popId . '&tab=diagram';
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: ' . $popDetailRedirect);
        exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'pop_add_device') {
            $deviceType = trim((string)($_POST['device_type'] ?? ''));
            $name       = trim((string)($_POST['name'] ?? ''));
            if ($deviceType === '' || $name === '') {
                throw new RuntimeException('Device type and name are required.');
            }
            $attrsRaw = trim((string)($_POST['attrs'] ?? ''));
            if ($attrsRaw === '') {
                $attrsParam = '{}';
            } else {
                $decoded = json_decode($attrsRaw, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Attrs must be valid JSON (e.g. {"outs":1}).');
                }
                $attrsParam = json_encode($decoded);
            }
            $parentId   = (int)($_POST['parent_device_id'] ?? 0) ?: null;
            $slotRaw    = trim((string)($_POST['parent_slot'] ?? ''));
            $parentSlot = ($parentId && $slotRaw !== '') ? (int)$slotRaw : null;

            $st = $pdo->prepare("
                INSERT INTO app.fosc_device (pop_id, device_type, name, attrs, parent_device_id, parent_slot)
                VALUES (:pid, :dtype, :name, :attrs::jsonb, :parent, :slot)
            ");
            $st->bindValue(':pid',   $popId, PDO::PARAM_INT);
            $st->bindValue(':dtype', $deviceType, PDO::PARAM_STR);
            $st->bindValue(':name',  $name, PDO::PARAM_STR);
            $st->bindValue(':attrs', $attrsParam, PDO::PARAM_STR);
            $st->bindValue(':parent', $parentId,   $parentId   !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':slot',   $parentSlot, $parentSlot !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->execute();
            flash_set('Device added.');

        } elseif ($action === 'pop_add_patch') {
            $epData = fetch_pop_patch_endpoints($pdo, $popId);
            $byId   = [];
            foreach ($epData['endpoints'] as $e) { $byId[(int)$e['id']] = $e; }

            $aId = (int)($_POST['a_device_id'] ?? 0);
            $bId = (int)($_POST['b_device_id'] ?? 0);
            $aPort = (int)($_POST['a_port'] ?? 0);
            $bPort = (int)($_POST['b_port'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));

            if (!isset($byId[$aId]) || !isset($byId[$bId])) {
                throw new RuntimeException('Pick two endpoints that belong to this POP.');
            }
            if ($aPort < 0 || $bPort < 0) {
                throw new RuntimeException('Port/fibre numbers must be ≥ 0.');
            }
            if ($aId === $bId && $aPort === $bPort) {
                throw new RuntimeException('The two endpoints must differ.');
            }
            // A port / fibre can carry only ONE patch cable — reject if either end is already used.
            foreach ([[$aId, $aPort, $byId[$aId]['label']], [$bId, $bPort, $byId[$bId]['label']]] as [$d, $p, $lbl]) {
                if (pop_port_in_use($pdo, $d, $p)) {
                    throw new RuntimeException('"' . $lbl . '" port ' . $p . ' is already patched — remove that patch first.');
                }
            }
            // splice_join.fosc_id: prefer a panel endpoint's closure, else any member closure.
            $foscId = $byId[$aId]['fosc_id'] ?? $byId[$bId]['fosc_id'] ?? ($epData['members'][0] ?? null);
            if ($foscId === null) {
                throw new RuntimeException('Assign a member closure to this POP first (a patch must be recorded at a closure).');
            }
            $st = $pdo->prepare("
                INSERT INTO app.splice_join
                  (fosc_id, a_target, a_span_id, a_end, a_range,
                            b_target, b_span_id, b_end, b_range,
                   join_kind, notes, is_plan)
                VALUES
                  (:fid, 'fosc_device', :asid, 'device', int4range(:alo, :ahi),
                         'fosc_device', :bsid, 'device', int4range(:blo, :bhi),
                   'connector', :notes, false)
            ");
            $st->execute([
                ':fid'  => $foscId,
                ':asid' => $aId, ':alo' => $aPort, ':ahi' => $aPort + 1,
                ':bsid' => $bId, ':blo' => $bPort, ':bhi' => $bPort + 1,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
            flash_set('Patch cable added.');

        } elseif ($action === 'pop_edit_device') {
            // Edit a block's device (a panel at a member closure, or a POP-homed device): name + note.
            $devId = (int)($_POST['device_id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $note  = trim((string)($_POST['note'] ?? ''));
            if ($devId <= 0)   throw new RuntimeException('Bad device.');
            if ($name === '')  throw new RuntimeException('Name is required.');
            $st = $pdo->prepare("
                UPDATE app.fosc_device
                   SET name = :name, notes = :note
                 WHERE fosc_device_id = :id
                   AND (fosc_id IN (SELECT fosc_id FROM app.fosc WHERE pop_id = :pid1)
                        OR pop_id = :pid2)
            ");
            $st->bindValue(':name', $name, PDO::PARAM_STR);
            $st->bindValue(':note', $note !== '' ? $note : null, $note !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':id', $devId, PDO::PARAM_INT);
            $st->bindValue(':pid1', $popId, PDO::PARAM_INT);
            $st->bindValue(':pid2', $popId, PDO::PARAM_INT);
            $st->execute();
            flash_set('Saved.');

        } elseif ($action === 'pop_delete_patch') {
            // Remove a patch cable (connector join) recorded at a member closure of this POP.
            $sjId = (int)($_POST['splice_join_id'] ?? 0);
            if ($sjId <= 0) throw new RuntimeException('Bad patch id.');
            $st = $pdo->prepare("
                DELETE FROM app.splice_join
                 WHERE splice_join_id = :id
                   AND join_kind = 'connector'
                   AND fosc_id IN (SELECT fosc_id FROM app.fosc WHERE pop_id = :pid)
            ");
            $st->execute([':id' => $sjId, ':pid' => $popId]);
            flash_set($st->rowCount() ? 'Patch cable removed.' : 'Patch not found in this POP.', (bool)$st->rowCount());

        } elseif ($action === 'pop_delete_device') {
            // Delete an SFP module / interface port (a POP-homed device) + any patch on it.
            $devId = (int)($_POST['device_id'] ?? 0);
            if ($devId <= 0) throw new RuntimeException('Bad device.');
            $chk = $pdo->prepare("SELECT 1 FROM app.fosc_device WHERE fosc_device_id = :id AND pop_id = :pid");
            $chk->execute([':id' => $devId, ':pid' => $popId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Port not found in this POP.');
            // splice_join endpoints are polymorphic (no FK) — clear its patches first.
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM app.splice_join
                            WHERE (a_target='fosc_device' AND a_span_id = :id1)
                               OR (b_target='fosc_device' AND b_span_id = :id2)")
                ->execute([':id1' => $devId, ':id2' => $devId]);
            $pdo->prepare("DELETE FROM app.fosc_device WHERE fosc_device_id = :id")->execute([':id' => $devId]);
            $pdo->commit();
            flash_set('Port removed.');

        } elseif ($action === 'pop_set_fibre_note') {
            // Per-fibre note on a member-closure patch panel, stored in attrs.port_notes[fibre].
            // Blank clears the override → the auto trace note (far-end closure + distance) shows.
            $devId = (int)($_POST['device_id'] ?? 0);
            $fibre = (int)($_POST['fibre'] ?? 0);
            $note  = trim((string)($_POST['note'] ?? ''));
            if ($devId <= 0 || $fibre <= 0) throw new RuntimeException('Bad fibre.');
            $chk = $pdo->prepare("
                SELECT 1 FROM app.fosc_device fd
                  JOIN app.fosc f ON f.fosc_id = fd.fosc_id
                 WHERE fd.fosc_device_id = :id AND fd.device_type = 'patch_panel' AND f.pop_id = :pid
            ");
            $chk->execute([':id' => $devId, ':pid' => $popId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Panel not found in this POP.');
            if ($note === '') {
                $pdo->prepare("
                    UPDATE app.fosc_device
                       SET attrs = COALESCE(attrs, '{}'::jsonb)
                                   || jsonb_build_object('port_notes',
                                        COALESCE(attrs->'port_notes', '{}'::jsonb) - :f::text)
                     WHERE fosc_device_id = :id
                ")->execute([':f' => (string)$fibre, ':id' => $devId]);
                flash_set('Fibre note cleared (using auto).');
            } else {
                $pdo->prepare("
                    UPDATE app.fosc_device
                       SET attrs = COALESCE(attrs, '{}'::jsonb)
                                   || jsonb_build_object('port_notes',
                                        COALESCE(attrs->'port_notes', '{}'::jsonb)
                                        || jsonb_build_object(:f::text, :note::text))
                     WHERE fosc_device_id = :id
                ")->execute([':f' => (string)$fibre, ':note' => $note, ':id' => $devId]);
                flash_set('Fibre note saved.');
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('Error: ' . $e->getMessage(), false);
    }
    header('Location: ' . $popDetailRedirect);
    exit;
}

// ── View model ────────────────────────────────────────────────────────────────
$members = fetch_pop_members($pdo, $popId);
$devices = fetch_pop_devices($pdo, $popId);

$payload  = fetch_pop_patch_diagram($pdo, $popId);
// Render the diagram whenever there are blocks to show (panels/devices), even before any
// patches exist — every terminated-fibre end / port is a click-to-draw target.
$hasPatch = !empty($payload['closures']) || !empty($payload['devices']);
$jFlags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$dgJson   = json_encode($payload, $jFlags);

$tlRows  = timeline_fetch_entries_for_parent($pdo, 'pop', (string)$popId);
$tlCount = count($tlRows);

$patchEndpoints = fetch_pop_patch_endpoints($pdo, $popId)['endpoints'];
$flash = flash_get();

$PAGE->setTitle('POP ' . $pop['name']);
$popDiagJsVer = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/pop_patch_diagram.js') ?: '';
$PAGE->addExternalCss('/css/splice_diagram.css');     // .sj-hl / .sj-row hover styles (reused)
$PAGE->addExternalJs('/js/pop_patch_diagram.js?v=' . $popDiagJsVer); // _renderPopPatchDiagram (cache-busted)
$PAGE->addExternalJs('/js/splice_diagram.helper.js'); // initSpliceDiagramHover

$PAGE->setContentRenderer(function () use (
    $pop, $popId, $members, $devices, $hasPatch, $dgJson, $tlRows, $tlCount, $pdo, $CSRF,
    $patchEndpoints, $flash
) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h3 class="mb-0">
        🏢 <?= h($pop['name']) ?>
        <span class="badge bg-secondary align-middle">POP</span>
      </h3>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=pop">All POPs</a>
        <?php if ($pop['lat'] !== null): ?>
          <a class="btn btn-outline-primary btn-sm" href="index.php?page=map">View on map</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pop-overview"
                type="button" role="tab">Overview</button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pop-diagram"
                type="button" role="tab">Connection Diagram</button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pop-timeline"
                type="button" role="tab">
          Timeline<?php if ($tlCount): ?> <span class="badge bg-secondary"><?= (int)$tlCount ?></span><?php endif; ?>
        </button>
      </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom">

      <!-- ── Overview ──────────────────────────────────────────────────────── -->
      <div class="tab-pane fade show active p-3" id="tab-pop-overview" role="tabpanel">
        <div class="row g-3">
          <div class="col-md-6">
            <dl class="row mb-0 small">
              <dt class="col-4 text-muted">Name</dt>
              <dd class="col-8"><?= h($pop['name']) ?></dd>
              <dt class="col-4 text-muted">Location</dt>
              <dd class="col-8">
                <?php if ($pop['lat'] !== null): ?>
                  <code><?= h(number_format((float)$pop['lat'], 6)) ?>, <?= h(number_format((float)$pop['lon'], 6)) ?></code>
                <?php else: ?>
                  <span class="text-muted">Not placed on map</span>
                <?php endif; ?>
              </dd>
              <dt class="col-4 text-muted">Notes</dt>
              <dd class="col-8"><?= $pop['notes'] ? nl2br(h($pop['notes'])) : '<span class="text-muted">—</span>' ?></dd>
            </dl>
          </div>
          <div class="col-md-6">
            <div class="mb-2">
              <div class="text-muted small fw-semibold text-uppercase ls-1">Member closures (<?= count($members) ?>)</div>
              <?php if ($members): ?>
                <?php foreach ($members as $m): ?>
                  <a class="badge bg-light text-dark border text-decoration-none me-1 mb-1"
                     href="index.php?page=closure&fosc_id=<?= (int)$m['fosc_id'] ?>"
                     title="<?= h($m['enclosure_rating']) ?>-rated, <?= h($m['status']) ?>">
                    <?= h($m['splice_num']) ?>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted small">None assigned.</span>
              <?php endif; ?>
            </div>
            <div>
              <div class="text-muted small fw-semibold text-uppercase ls-1">POP devices (<?= count($devices) ?>)</div>
              <?php if ($devices): ?>
                <?php foreach ($devices as $d): ?>
                  <span class="badge bg-info-subtle text-dark border me-1 mb-1"
                        title="<?= h($d['device_type']) ?>"><?= h($d['name']) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted small">None homed at this POP.</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Connection Diagram (patch cables) ─────────────────────────────── -->
      <div class="tab-pane fade p-3" id="tab-pop-diagram" role="tabpanel">
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
          <h6 class="mb-0 text-muted fw-semibold small text-uppercase ls-1">Patch-Cable Connection Diagram</h6>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalPopAddPatch">+ Patch cable</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalPopAddDevice">+ Device</button>
          </div>
        </div>
        <?php if ($flash): ?>
          <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?> py-2"><?= h($flash['msg']) ?></div>
        <?php endif; ?>
        <p class="text-muted small mb-2">
          Terminated-fibre closures (left) and active devices (right). <strong>Click a port, then
          another</strong> to draw a patch cable between them.
        </p>
        <?php if ($hasPatch): ?>
          <div id="popDiagram" class="border rounded bg-white p-2" style="overflow-x:auto"></div>
        <?php else: ?>
          <div id="popDiagram" class="border rounded bg-white p-3 text-muted small">
            Nothing to show yet. Terminate a cable onto a panel at a member closure, or add a POP
            device with <strong>+ Device</strong>, then patch ports together.
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Timeline ──────────────────────────────────────────────────────── -->
      <div class="tab-pane fade p-3" id="tab-pop-timeline" role="tabpanel">
        <div class="mb-3">
          <a class="btn btn-outline-success btn-sm"
             href="index.php?page=timeline_entry&parent_type=pop&parent_id=<?= (int)$popId ?>">
            + Add Timeline Entry
          </a>
        </div>
        <?php
          if ($tlCount === 0) {
              echo '<div class="alert alert-secondary">No timeline entries yet.</div>';
          } else {
              timeline_render_block($pdo, $tlRows);
          }
        ?>
      </div>

    </div><!-- /tab-content -->

    <!-- ── Add Patch Cable modal ─────────────────────────────────────────────── -->
    <div class="modal fade" id="modalPopAddPatch" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="pop_add_patch">
          <div class="modal-header">
            <h5 class="modal-title">Add Patch Cable</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php if (!$patchEndpoints): ?>
              <p class="text-muted mb-0">No patchable endpoints yet — add a patch panel (terminate a
                cable at a member closure) or a POP device first.</p>
            <?php else: ?>
              <?php
                $epOptions = function () use ($patchEndpoints) {
                    foreach ($patchEndpoints as $e) {
                        echo '<option value="' . (int)$e['id'] . '" data-maxport="' . (int)$e['maxport'] . '">'
                           . h($e['label']) . ($e['maxport'] ? ' · ' . (int)$e['maxport'] . 'p' : '') . '</option>';
                    }
                };
              ?>
              <div class="mb-3">
                <label class="form-label">End A</label>
                <div class="input-group">
                  <select class="form-select" name="a_device_id" required><?php $epOptions(); ?></select>
                  <input type="number" class="form-control" name="a_port" min="0" value="1"
                         style="max-width:90px" placeholder="port" required>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">End B</label>
                <div class="input-group">
                  <select class="form-select" name="b_device_id" required><?php $epOptions(); ?></select>
                  <input type="number" class="form-control" name="b_port" min="0" value="1"
                         style="max-width:90px" placeholder="port" required>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Notes</label>
                <input class="form-control" name="notes" placeholder="optional">
              </div>
              <div class="form-text">Port = fibre number for a panel, or port number for a device/SFP.</div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <?php if ($patchEndpoints): ?><button class="btn btn-success" type="submit">Add Patch Cable</button><?php endif; ?>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>

    <!-- ── Add Device modal (POP-homed) ──────────────────────────────────────── -->
    <div class="modal fade" id="modalPopAddDevice" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="pop_add_device">
          <div class="modal-header">
            <h5 class="modal-title">Add Device to <?= h($pop['name']) ?></h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Device Type *</label>
                <select class="form-select" id="popDevPreset" onchange="popApplyPreset(this)" required>
                  <option value="">— choose —</option>
                  <optgroup label="Splitter (PLC) — loss = 10·log₁₀(N)">
                    <option data-type="splitter" data-attrs='{"outs":2}'>1×2 Splitter</option>
                    <option data-type="splitter" data-attrs='{"outs":4}'>1×4 Splitter</option>
                    <option data-type="splitter" data-attrs='{"outs":8}'>1×8 Splitter</option>
                    <option data-type="splitter" data-attrs='{"outs":16}'>1×16 Splitter</option>
                    <option data-type="splitter" data-attrs='{"outs":32}'>1×32 Splitter</option>
                  </optgroup>
                  <optgroup label="DWDM / OADM">
                    <option data-type="dwdm" data-attrs='{"outs":4}'>1×4 DWDM MUX/DEMUX</option>
                    <option data-type="dwdm" data-attrs='{"outs":8}'>1×8 DWDM MUX/DEMUX</option>
                    <option data-type="dwdm" data-attrs='{"outs":16}'>1×16 DWDM MUX/DEMUX</option>
                    <option data-type="oadm" data-attrs='{}'>OADM</option>
                  </optgroup>
                  <optgroup label="Active / termination">
                    <option data-type="router"  data-attrs='{"outs":12}'>Router (SFP chassis)</option>
                    <option data-type="olt_pon" data-attrs='{"outs":1}'>OLT GPON port</option>
                    <option data-type="sfp"     data-attrs='{"outs":1}'>SFP</option>
                    <option data-type="patch_panel" data-attrs='{"ports":12,"connector":"LC/UPC"}'>Patch panel</option>
                  </optgroup>
                </select>
                <input type="hidden" name="device_type" id="popDevType">
              </div>
              <div class="col-md-6">
                <label class="form-label">Name *</label>
                <input class="form-control" name="name" required>
              </div>
              <div class="col-md-8">
                <label class="form-label">Plugs into (parent device)</label>
                <select class="form-select" name="parent_device_id">
                  <option value="">— none (standalone) —</option>
                  <?php foreach ($devices as $d): ?>
                    <option value="<?= (int)$d['fosc_device_id'] ?>">
                      <?= h($d['name']) ?> (<?= h($d['device_type']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Slot</label>
                <input type="number" class="form-control" name="parent_slot" min="0" placeholder="slot">
              </div>
              <div class="col-12">
                <label class="form-label">Attrs (JSON)</label>
                <input class="form-control" name="attrs" placeholder='{"outs":1}'>
                <div class="form-text">e.g. <code>{"outs":14}</code> for a router/OLT, <code>{"outs":1}</code> for an SFP.</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-success" type="submit">Add Device</button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>

    <!-- Edit-block modal: a block's device name + note (terminations and devices). -->
    <div class="modal fade" id="modalPopEdit" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="pop_edit_device">
          <input type="hidden" name="device_id" id="popEditDeviceId">
          <div class="modal-header">
            <h5 class="modal-title" id="popEditTitle">Edit</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" id="popEditName" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Note</label>
              <textarea class="form-control" name="note" id="popEditNote" rows="3"
                        placeholder="Note for this block"></textarea>
            </div>
            <div class="mb-1" id="popEditPortsWrap" style="display:none">
              <label class="form-label">Interface ports / modules</label>
              <div id="popEditPorts" class="border rounded p-1" style="max-height:240px;overflow:auto"></div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-success" type="submit">Save</button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>
    <form id="popDeleteDeviceForm" method="post" class="d-none">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="pop_delete_device">
      <input type="hidden" name="device_id">
    </form>
    <script>
    // Click a block body in the diagram → edit its device (name + note) and manage its ports.
    window._popEditBlock = function (devId, name, note, label, rows) {
      document.getElementById('popEditDeviceId').value = devId;
      document.getElementById('popEditName').value = name || '';
      document.getElementById('popEditNote').value = note || '';
      document.getElementById('popEditTitle').textContent = 'Edit — ' + label;
      // List the device's SFP modules (rowKey starts with 'c') with a delete button each.
      var ports = (rows || []).filter(function (r) { return String(r.key).charAt(0) === 'c'; });
      var wrap = document.getElementById('popEditPortsWrap');
      var box  = document.getElementById('popEditPorts');
      box.innerHTML = '';
      wrap.style.display = ports.length ? '' : 'none';
      ports.forEach(function (r) {
        var childId = String(r.key).slice(1);
        var row = document.createElement('div');
        row.className = 'd-flex justify-content-between align-items-center px-2 py-1 border-bottom';
        var span = document.createElement('span'); span.className = 'small'; span.textContent = r.label;
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-danger py-0 px-2'; btn.textContent = 'Delete';
        btn.addEventListener('click', function () {
          if (!window.confirm('Delete port "' + r.label + '"? Removes the SFP/module and any patch on it.')) return;
          var f = document.getElementById('popDeleteDeviceForm');
          f.device_id.value = childId; f.submit();
        });
        row.appendChild(span); row.appendChild(btn); box.appendChild(row);
      });
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPopEdit')).show();
    };
    </script>

    <!-- Hidden forms for click-to-draw patching + click-to-delete (reuse the POST handlers). -->
    <form id="popQuickPatchForm" method="post" class="d-none">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="pop_add_patch">
      <input type="hidden" name="a_device_id"><input type="hidden" name="a_port">
      <input type="hidden" name="b_device_id"><input type="hidden" name="b_port">
    </form>
    <form id="popDeletePatchForm" method="post" class="d-none">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="pop_delete_patch">
      <input type="hidden" name="splice_join_id">
    </form>
    <form id="popFibreNoteForm" method="post" class="d-none">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="pop_set_fibre_note">
      <input type="hidden" name="device_id"><input type="hidden" name="fibre"><input type="hidden" name="note">
    </form>
    <script>
    // Called by the diagram when two ports are clicked → create the patch via the existing handler.
    window._popCreatePatch = function (aId, aPort, bId, bPort) {
      var f = document.getElementById('popQuickPatchForm');
      f.a_device_id.value = aId; f.a_port.value = aPort;
      f.b_device_id.value = bId; f.b_port.value = bPort;
      f.submit();
    };
    // Called when a wire is clicked → confirm, then delete the patch cable.
    window._popDeletePatch = function (sjId) {
      if (!sjId) return;
      if (!window.confirm('Delete this patch cable?')) return;
      var f = document.getElementById('popDeletePatchForm');
      f.splice_join_id.value = sjId;
      f.submit();
    };
    // Called when a fibre's note is clicked → edit. Seeds with the manual override if set,
    // otherwise the auto trace note (so it can be tweaked). Blank clears back to auto.
    window._popSetFibreNote = function (devId, fibre, override, auto, label) {
      var seed = override || auto || '';
      var v = window.prompt('Note for ' + (label || ('F' + fibre)) + '  (blank = use auto):', seed);
      if (v === null) return;                       // cancelled
      var f = document.getElementById('popFibreNoteForm');
      f.device_id.value = devId; f.fibre.value = fibre; f.note.value = v.trim();
      f.submit();
    };
    </script>

    <?php if ($hasPatch): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      _renderPopPatchDiagram('popDiagram', <?= $dgJson ?>);
      initSpliceDiagramHover(['popDiagram']);
    });
    </script>
    <?php endif; ?>
    <script>
    // Land back on the Connection Diagram tab after an add (redirect carries ?tab=diagram).
    document.addEventListener('DOMContentLoaded', function () {
      if (new URLSearchParams(location.search).get('tab') === 'diagram') {
        var btn = document.querySelector('[data-bs-target="#tab-pop-diagram"]');
        if (btn) btn.click();
      }
    });

    // Device-type preset → sets the hidden device_type + prefilled attrs JSON.
    function popApplyPreset(sel) {
      var o = sel.options[sel.selectedIndex];
      document.getElementById('popDevType').value = o.getAttribute('data-type') || '';
      var attrs = o.getAttribute('data-attrs');
      if (attrs) document.querySelector('#modalPopAddDevice [name=attrs]').value = attrs;
    }
    window.popApplyPreset = popApplyPreset;
    function popSelectPreset(type) {   // choose the first preset option matching a device_type
      var sel = document.getElementById('popDevPreset');
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].getAttribute('data-type') === type) { sel.selectedIndex = i; break; }
      }
      popApplyPreset(sel);
    }

    // Clicking a device block's "+ SFP" opens the Add-Device modal pre-filled as an SFP
    // plugged into that device. The pop_add_device handler already persists parent/slot.
    var _popDevModalTitleDefault = 'Add Device to <?= h($pop['name']) ?>';
    window._popOnAddSfp = function (devId, label) {
      var modalEl = document.getElementById('modalPopAddDevice');
      var f = modalEl.querySelector('form');
      popSelectPreset('sfp');
      f.querySelector('[name=parent_device_id]').value = String(devId);
      f.querySelector('[name=parent_slot]').value = '';
      f.querySelector('[name=name]').value = '';
      modalEl.querySelector('.modal-title').textContent = 'Add SFP to ' + label;
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };
    // Reset the title to the default once closed, so the plain "+ Device" button reads right.
    document.getElementById('modalPopAddDevice')
      ?.addEventListener('hidden.bs.modal', function () {
        this.querySelector('.modal-title').textContent = _popDevModalTitleDefault;
      });
    </script>
    <?php
    return ob_get_clean();
});
