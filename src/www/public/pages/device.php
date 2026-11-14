<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/device.php
// CRUD for app.fosc_device (non-geometry fields)
// Geometry (location) is edited in QGIS; this page manages type/name/attrs/notes.

/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;

// --- helper: is_post / flash_* should already exist globally ---
// using the same ones as cable_spans.php

// --- Reference data ---

$foscRows = $pdo->query("
  SELECT fosc_id, splice_num
    FROM app.fosc
ORDER BY splice_num ASC, fosc_id ASC
")->fetchAll();

$popRows = $pdo->query("
  SELECT pop_id, name
    FROM app.pop
ORDER BY name ASC
")->fetchAll();

// Optional: common device types for a datalist
$DEVICE_TYPES = ['router', 'olt_pon', 'sfp', 'dwdm', 'splitter', 'oadm', 'fdc', 'patch_panel', 'amp', 'mux', 'demux'];

// Active gear typically lives in a POP; passive gear in a closure. Used only as a
// default hint for the home-type toggle — the operator can override.
$ACTIVE_DEVICE_TYPES = ['olt_pon', 'sfp', 'dwdm', 'amp', 'mux', 'demux'];

/**
 * Resolve the device "home" from POST into [fosc_id|null, pop_id|null], enforcing
 * exactly one. Mirrors the DB chk_device_home constraint.
 */
function device_home_from_post(array $post): array {
    $home = ($post['home_type'] ?? 'fosc') === 'pop' ? 'pop' : 'fosc';
    if ($home === 'pop') {
        $pid = (int)($post['pop_id'] ?? 0);
        if ($pid <= 0) throw new RuntimeException('Select a POP.');
        return [null, $pid];
    }
    $fid = (int)($post['fosc_id'] ?? 0);
    if ($fid <= 0) throw new RuntimeException('Select a closure.');
    return [$fid, null];
}

/**
 * Resolve the optional parent device (an SFP module plugs into a router/OLT slot) from POST.
 * Returns [parent_device_id|null, parent_slot|null]. Blank parent ⇒ standalone device.
 */
function device_parent_from_post(array $post, int $selfId = 0): array {
    $pid = (int)($post['parent_device_id'] ?? 0);
    if ($pid <= 0) return [null, null];
    if ($selfId > 0 && $pid === $selfId) {
        throw new RuntimeException('A device cannot plug into itself.');
    }
    $slotRaw = trim((string)($post['parent_slot'] ?? ''));
    $slot    = $slotRaw === '' ? null : (int)$slotRaw;
    return [$pid, $slot];
}

// Closures (field enclosures) may only home passive optical gear; POPs may home anything.
// Mirrors the DB constraint chk_closure_device_passive (migration 0116).
$CLOSURE_DEVICE_TYPES = ['splitter', 'oadm', 'patch_panel'];

function device_assert_home_type(?int $fosc_id, string $device_type): void {
    if ($fosc_id !== null && !in_array($device_type, ['splitter', 'oadm', 'patch_panel'], true)) {
        throw new RuntimeException(
            'Closures may only hold passive gear — splitter, OADM, or patch panel. '
            . 'Active gear (router/OLT/SFP) and DWDM/MPT belong in a POP.');
    }
}

// --- POST actions ---

if (is_post()) {
    $action = $_POST['action'] ?? '';

    // CSRF
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=device');
        exit;
    }

    try {
        if ($action === 'create') {
            [$fosc_id, $pop_id] = device_home_from_post($_POST);
            $device_type = trim($_POST['device_type'] ?? '');
            $name        = trim($_POST['name'] ?? '');
            $attrs_raw   = trim($_POST['attrs'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($device_type === '' || $name === '') {
                throw new RuntimeException('Device type and name are required.');
            }
            device_assert_home_type($fosc_id, $device_type);

            // attrs must be valid JSON object/array or empty
           $attrs_raw   = trim($_POST['attrs'] ?? '');

// Always send valid JSON; default to {} if blank
if ($attrs_raw === '') {
    $attrsParam = '{}';
} else {
    $decoded = json_decode($attrs_raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Attrs must be valid JSON (e.g. {"outs":[1,2,3]}).');
    }
    $attrsParam = json_encode($decoded);
}

[$parent_id, $parent_slot] = device_parent_from_post($_POST);

$stmt = $pdo->prepare("
    INSERT INTO app.fosc_device (fosc_id, pop_id, device_type, name, attrs, notes, parent_device_id, parent_slot)
    VALUES (:fid, :pid, :dtype, :name, :attrs, :notes, :parent, :slot)
");

$stmt->bindValue(':fid', $fosc_id, $fosc_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':pid', $pop_id,  $pop_id  !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':dtype', $device_type, PDO::PARAM_STR);
$stmt->bindValue(':name',  $name,        PDO::PARAM_STR);
$stmt->bindValue(':attrs', $attrsParam,  PDO::PARAM_STR);
$stmt->bindValue(
    ':notes',
    $notes !== '' ? $notes : null,
    $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
);
$stmt->bindValue(':parent', $parent_id,   $parent_id   !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':slot',   $parent_slot, $parent_slot !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);

            $stmt->execute();
            flash_set('Device created.');

        } elseif ($action === 'update') {
            $id          = (int)($_POST['id'] ?? 0);
            [$fosc_id, $pop_id] = device_home_from_post($_POST);
            $device_type = trim($_POST['device_type'] ?? '');
            $name        = trim($_POST['name'] ?? '');
            $attrs_raw   = trim($_POST['attrs'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($id <= 0 || $device_type === '' || $name === '') {
                throw new RuntimeException('Invalid input.');
            }
            device_assert_home_type($fosc_id, $device_type);

         $attrs_raw   = trim($_POST['attrs'] ?? '');

// Always valid JSON; default to {}
if ($attrs_raw === '') {
    $attrsParam = '{}';
} else {
    $decoded = json_decode($attrs_raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Attrs must be valid JSON (e.g. {"outs":[1,2,3]}).');
    }
    $attrsParam = json_encode($decoded);
}

[$parent_id, $parent_slot] = device_parent_from_post($_POST, $id);

$stmt = $pdo->prepare("
    UPDATE app.fosc_device
       SET fosc_id     = :fid,
           pop_id      = :pid,
           device_type = :dtype,
           name        = :name,
           attrs       = :attrs,
           notes       = :notes,
           parent_device_id = :parent,
           parent_slot      = :slot
     WHERE fosc_device_id = :id
");

$stmt->bindValue(':id',    $id,          PDO::PARAM_INT);
$stmt->bindValue(':fid', $fosc_id, $fosc_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':pid', $pop_id,  $pop_id  !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':dtype', $device_type, PDO::PARAM_STR);
$stmt->bindValue(':name',  $name,        PDO::PARAM_STR);
$stmt->bindValue(':attrs', $attrsParam,  PDO::PARAM_STR);
$stmt->bindValue(
    ':notes',
    $notes !== '' ? $notes : null,
    $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
);
$stmt->bindValue(':parent', $parent_id,   $parent_id   !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
$stmt->bindValue(':slot',   $parent_slot, $parent_slot !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->execute();
            flash_set('Device updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Bad id.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM app.fosc_device
                 WHERE fosc_device_id = :id
            ");
            $stmt->execute([':id' => $id]);

            flash_set('Device deleted.');
        }
    } catch (Throwable $e) {
        flash_set('Error: ' . $e->getMessage(), false);
    }

    header('Location: index.php?page=device');
    exit;
}

// --- List device ---

$rows = $pdo->query("
  SELECT
    d.fosc_device_id,
    d.fosc_id,
    d.pop_id,
    f.splice_num AS fosc_label,
    p.name       AS pop_label,
    d.device_type,
    d.name,
    d.attrs,
    d.notes,
    d.parent_device_id,
    d.parent_slot,
    pd.name      AS parent_name
  FROM app.fosc_device d
  LEFT JOIN app.fosc f ON f.fosc_id = d.fosc_id
  LEFT JOIN app.pop  p ON p.pop_id  = d.pop_id
  LEFT JOIN app.fosc_device pd ON pd.fosc_device_id = d.parent_device_id
  ORDER BY COALESCE(f.splice_num, p.name) ASC, d.fosc_device_id ASC
")->fetchAll();

$flash = flash_get();

$PAGE->setTitle('Devices in Closures');

$PAGE->setContentRenderer(function () use ($rows, $foscRows, $popRows, $CSRF, $flash, $DEVICE_TYPES, $ACTIVE_DEVICE_TYPES, $CLOSURE_DEVICE_TYPES) {
    ob_start();
    ?>

    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Devices in Closures</h3>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreateDevice">
        New Device
      </button>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Home</th>
            <th>Type</th>
            <th>Name</th>
            <th>Plugs into</th>
            <th>Attrs (JSON)</th>
            <th>Notes</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $attrsRaw = $r['attrs'];
            if ($attrsRaw === null || $attrsRaw === '') {
                $attrsShort = '';
            } else {
                $s = is_string($attrsRaw) ? $attrsRaw : json_encode($attrsRaw);
                $attrsShort = (strlen($s) > 80) ? substr($s, 0, 80) . '…' : $s;
            }
          ?>
          <tr>
            <td><?= (int)$r['fosc_device_id'] ?></td>
            <td>
              <?php if (!empty($r['pop_id'])): ?>
                <a class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none"
                   href="index.php?page=pop">POP: <?= h($r['pop_label']) ?></a>
              <?php else: ?>
                <a class="text-decoration-none"
                   href="index.php?page=closure&fosc_id=<?= (int)$r['fosc_id'] ?>">
                  <code><?= h($r['fosc_label']) ?></code>
                </a>
              <?php endif; ?>
            </td>
            <td><?= h($r['device_type']) ?></td>
            <td><?= h($r['name']) ?></td>
            <td>
              <?php if (!empty($r['parent_device_id'])): ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                  <?= h($r['parent_name'] ?? ('#' . (int)$r['parent_device_id'])) ?><?php
                    if ($r['parent_slot'] !== null) echo ' · s' . (int)$r['parent_slot']; ?>
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><code><?= h($attrsShort) ?></code></td>
            <td><?= nl2br(h($r['notes'] ?? '')) ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal"
                      data-bs-target="#modalEditDevice<?= (int)$r['fosc_device_id'] ?>">
                Edit
              </button>
              <form class="d-inline" method="post"
                    onsubmit="return confirm('Delete this device?');">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['fosc_device_id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="modalEditDevice<?= (int)$r['fosc_device_id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg"><div class="modal-content">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['fosc_device_id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">
                    Edit Device #<?= (int)$r['fosc_device_id'] ?>
                  </h5>
                  <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="row g-3">
                    <?php $eid = (int)$r['fosc_device_id']; $isPop = !empty($r['pop_id']); ?>
                    <div class="col-md-6">
                      <label class="form-label d-block">Home *</label>
                      <div class="btn-group btn-group-sm mb-2" role="group">
                        <input type="radio" class="btn-check" name="home_type" value="fosc"
                               id="homeFosc<?= $eid ?>" <?= $isPop ? '' : 'checked' ?>
                               onchange="deviceHomeToggle(<?= $eid ?>)">
                        <label class="btn btn-outline-secondary" for="homeFosc<?= $eid ?>">Closure</label>
                        <input type="radio" class="btn-check" name="home_type" value="pop"
                               id="homePop<?= $eid ?>" <?= $isPop ? 'checked' : '' ?>
                               onchange="deviceHomeToggle(<?= $eid ?>)">
                        <label class="btn btn-outline-secondary" for="homePop<?= $eid ?>">POP</label>
                      </div>
                      <select class="form-select" name="fosc_id" id="foscSel<?= $eid ?>"
                              <?= $isPop ? 'disabled style="display:none"' : '' ?>>
                        <option value="">Select closure…</option>
                        <?php foreach ($foscRows as $f): ?>
                          <option value="<?= (int)$f['fosc_id'] ?>"
                            <?= ((int)$f['fosc_id'] === (int)$r['fosc_id']) ? 'selected' : '' ?>>
                            <?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <select class="form-select" name="pop_id" id="popSel<?= $eid ?>"
                              <?= $isPop ? '' : 'disabled style="display:none"' ?>>
                        <option value="">Select POP…</option>
                        <?php foreach ($popRows as $p): ?>
                          <option value="<?= (int)$p['pop_id'] ?>"
                            <?= ((int)$p['pop_id'] === (int)$r['pop_id']) ? 'selected' : '' ?>>
                            <?= h($p['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Device Type *</label>
                      <input class="form-control"
                             name="device_type"
                             id="dtype<?= $eid ?>"
                             list="<?= $isPop ? 'deviceTypeList' : 'closureDeviceTypeList' ?>"
                             value="<?= h($r['device_type']) ?>"
                             required>
                      <div class="form-text">Closures: splitter, OADM or patch panel only. POPs: any.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Name *</label>
                      <input class="form-control"
                             name="name"
                             value="<?= h($r['name']) ?>"
                             required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Plugs into (parent device)</label>
                      <div class="input-group">
                        <select class="form-select" name="parent_device_id">
                          <option value="">— none (standalone) —</option>
                          <?php foreach ($rows as $opt):
                            if ((int)$opt['fosc_device_id'] === (int)$r['fosc_device_id']) continue; ?>
                            <option value="<?= (int)$opt['fosc_device_id'] ?>"
                              <?= ((int)$opt['fosc_device_id'] === (int)$r['parent_device_id']) ? 'selected' : '' ?>>
                              <?= h($opt['name']) ?> (<?= h($opt['device_type']) ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <input type="number" class="form-control" name="parent_slot" min="0"
                               style="max-width:90px" placeholder="slot"
                               value="<?= $r['parent_slot'] !== null ? (int)$r['parent_slot'] : '' ?>">
                      </div>
                      <div class="form-text">For an SFP / module installed in a router or OLT port.</div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Attrs (JSON)</label>
                      <textarea class="form-control"
                                name="attrs"
                                rows="3"
                                placeholder='{"outs":[1,2,3,4]}'><?= h(
                                  is_string($r['attrs'])
                                    ? $r['attrs']
                                    : ($r['attrs'] !== null ? json_encode($r['attrs'], JSON_PRETTY_PRINT) : '')
                                ) ?></textarea>
                      <div class="form-text">
                        Leave blank for NULL. Otherwise must be valid JSON.
                      </div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Notes</label>
                      <textarea class="form-control" name="notes" rows="2"><?= h($r['notes'] ?? '') ?></textarea>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-primary" type="submit">Save</button>
                  <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div></div>
          </div>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="modalCreateDevice" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title">New Device</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label d-block">Home *</label>
                <div class="btn-group btn-group-sm mb-2" role="group">
                  <input type="radio" class="btn-check" name="home_type" value="fosc"
                         id="homeFosc0" checked onchange="deviceHomeToggle(0)">
                  <label class="btn btn-outline-secondary" for="homeFosc0">Closure</label>
                  <input type="radio" class="btn-check" name="home_type" value="pop"
                         id="homePop0" onchange="deviceHomeToggle(0)">
                  <label class="btn btn-outline-secondary" for="homePop0">POP</label>
                </div>
                <select class="form-select" name="fosc_id" id="foscSel0">
                  <option value="">Select closure…</option>
                  <?php foreach ($foscRows as $f): ?>
                    <option value="<?= (int)$f['fosc_id'] ?>">
                      <?= h($f['splice_num']) ?> (<?= (int)$f['fosc_id'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select" name="pop_id" id="popSel0" disabled style="display:none">
                  <option value="">Select POP…</option>
                  <?php foreach ($popRows as $p): ?>
                    <option value="<?= (int)$p['pop_id'] ?>"><?= h($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Active gear (OLT/SFP/DWDM) usually lives in a POP; passive gear in a closure.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Device Type *</label>
                <input class="form-control"
                       name="device_type"
                       id="dtype0"
                       list="closureDeviceTypeList"
                       required>
                <div class="form-text">Closures: splitter, OADM or patch panel only. POPs: any.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Name *</label>
                <input class="form-control" name="name" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Plugs into (parent device)</label>
                <div class="input-group">
                  <select class="form-select" name="parent_device_id">
                    <option value="">— none (standalone) —</option>
                    <?php foreach ($rows as $opt): ?>
                      <option value="<?= (int)$opt['fosc_device_id'] ?>">
                        <?= h($opt['name']) ?> (<?= h($opt['device_type']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <input type="number" class="form-control" name="parent_slot" min="0"
                         style="max-width:90px" placeholder="slot">
                </div>
                <div class="form-text">For an SFP / module installed in a router or OLT port.</div>
              </div>
              <div class="col-12">
                <label class="form-label">Attrs (JSON)</label>
                <textarea class="form-control"
                          name="attrs"
                          rows="3"
                          placeholder='{"outs":[1,2,3,4]}'></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-success" type="submit">Create</button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div></div>
    </div>

    <!-- Datalists for device types: full set (POPs) vs passive-only (closures). -->
    <datalist id="deviceTypeList">
      <?php foreach ($DEVICE_TYPES as $t): ?>
        <option value="<?= h($t) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <datalist id="closureDeviceTypeList">
      <?php foreach ($CLOSURE_DEVICE_TYPES as $t): ?>
        <option value="<?= h($t) ?>"></option>
      <?php endforeach; ?>
    </datalist>

    <script>
    // Toggle a device's home between a closure select and a POP select.
    // The inactive <select> is disabled so it is not submitted (chk_device_home
    // requires exactly one of fosc_id / pop_id). Also swap the device-type suggestions:
    // closures allow only passive gear (splitter/OADM/patch panel); POPs allow anything.
    function deviceHomeToggle(id) {
      var fosc = document.getElementById('foscSel' + id);
      var pop  = document.getElementById('popSel'  + id);
      var usePop = document.getElementById('homePop' + id).checked;
      if (!fosc || !pop) return;
      fosc.disabled = usePop;  fosc.style.display = usePop ? 'none' : '';
      pop.disabled  = !usePop; pop.style.display  = usePop ? '' : 'none';
      var dt = document.getElementById('dtype' + id);
      if (dt) dt.setAttribute('list', usePop ? 'deviceTypeList' : 'closureDeviceTypeList');
    }
    </script>

    <?php
    return ob_get_clean();
});
