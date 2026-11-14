<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/device_splice.php
// Manage splices between FOSC devices (splitters, MSTs, etc.) and cable spans.
//
// Uses EXISTING app.splice_join table, with one side = fosc_device
// and the other side = cable_span.
//
// Route: index.php?page=device_splice&fosc_id=123

/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;

if (!function_exists('is_post')) {
    function is_post(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ---------- Input: fosc_id ----------

$foscId = isset($_GET['fosc_id']) ? (int)$_GET['fosc_id'] : 0;

$pickerRows = $pdo->query("
    SELECT
      f.fosc_id,
      f.splice_num,
      f.status,
      (SELECT COUNT(*) FROM app.fosc_device d WHERE d.fosc_id = f.fosc_id) AS device_count,
      (SELECT COUNT(*) FROM app.v_span_endpoints se WHERE se.fosc_id = f.fosc_id) AS endpoint_count
    FROM app.fosc f
    ORDER BY f.splice_num ASC, f.fosc_id ASC
")->fetchAll() ?: [];

$renderFoscPicker = function (array $rows, ?string $error = null): string {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Device Splice</h3>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?page=closure">Closures</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php else: ?>
      <div class="alert alert-info">Choose a closure to manage device splices.</div>
    <?php endif; ?>

    <form method="get" class="card card-body mb-3">
      <input type="hidden" name="page" value="device_splice">
      <div class="row g-2 align-items-end">
        <div class="col-md-8">
          <label class="form-label">Closure</label>
          <select class="form-select" name="fosc_id" required>
            <option value="">Select…</option>
            <?php foreach ($rows as $r): ?>
              <option value="<?= (int)$r['fosc_id'] ?>">
                <?= h($r['splice_num']) ?> (id <?= (int)$r['fosc_id'] ?>) — <?= h($r['status']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <button class="btn btn-primary w-100" type="submit">Open Device Splices</button>
        </div>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Splice #</th>
            <th>Status</th>
            <th class="text-end">Devices</th>
            <th class="text-end">Span Ends</th>
            <th class="text-end">Open</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><code><?= h($r['splice_num']) ?></code></td>
              <td><?= h($r['status']) ?></td>
              <td class="text-end"><?= (int)$r['device_count'] ?></td>
              <td class="text-end"><?= (int)$r['endpoint_count'] ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="index.php?page=device_splice&fosc_id=<?= (int)$r['fosc_id'] ?>">
                  Open
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="5" class="text-muted">No closures found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
};

if ($foscId <= 0) {
    $PAGE->setTitle('Device Splice');
    $PAGE->setContentRenderer(function () use ($pickerRows, $renderFoscPicker) {
        return $renderFoscPicker($pickerRows);
    });
    return;
}

// Get FOSC label/status
$stFosc = $pdo->prepare("
    SELECT fosc_id, splice_num, status
      FROM app.fosc
     WHERE fosc_id = :id
");
$stFosc->execute([':id' => $foscId]);
$fosc = $stFosc->fetch();
if (!$fosc) {
    $PAGE->setTitle('Device Splice');
    $PAGE->setContentRenderer(function () use ($pickerRows, $renderFoscPicker, $foscId) {
        return $renderFoscPicker($pickerRows, "FOSC not found (fosc_id={$foscId}).");
    });
    return;
}

// ---------- Helper for span endpoint label ----------

function device_span_label(array $ep): string {
    $len = $ep['length_m'] !== null
        ? number_format((float)$ep['length_m'], 0) . ' m'
        : 'len ?';
    $fc  = $ep['fibre_count'] ?? null;
    $fcTxt = $fc ? "{$fc}F" : '?F';

    return 'Span ' . (int)$ep['cable_span_id']
         . ' ' . $ep['span_end'] . ' at ' . $ep['here_label']
         . ' → ' . $ep['far_label']
         . " ({$len}, {$fcTxt} {$ep['cable_name']})";
}

// ---------- Select options ----------

// Devices living in this FOSC
$stDev = $pdo->prepare("
    SELECT fd.fosc_device_id,
           fd.device_type,
           fd.name,
           fd.attrs
      FROM app.fosc_device fd
     WHERE fd.fosc_id = :fid
     ORDER BY fd.device_type, fd.name, fd.fosc_device_id
");
$stDev->execute([':fid' => $foscId]);
$devices = $stDev->fetchAll();

// Span endpoints at this FOSC (very similar to splices.php)
$stSpan = $pdo->prepare("
    SELECT
        se.cable_span_id,
        se.\"end\"::text AS span_end,
        se.fosc_id,
        fh.splice_num AS here_label,
        CASE
          WHEN se.\"end\" = 'from' THEN ft.splice_num
          ELSE ff.splice_num
        END AS far_label,
        cs.length_m,
        c.name  AS cable_name,
        c.fibre_count
      FROM app.v_span_endpoints se
      JOIN app.cable_span cs ON cs.cable_span_id = se.cable_span_id
      JOIN app.cable      c  ON c.cable_id       = cs.cable_id
      JOIN app.fosc       ff ON ff.fosc_id       = cs.from_fosc_id
      JOIN app.fosc       ft ON ft.fosc_id       = cs.to_fosc_id
      JOIN app.fosc       fh ON fh.fosc_id       = se.fosc_id
     WHERE se.fosc_id = :fid
  ORDER BY cs.cable_span_id, se.\"end\"
");
$stSpan->execute([':fid' => $foscId]);
$spanEndpoints = $stSpan->fetchAll();

// ---------- POST actions: create / delete ----------

if (is_post()) {
    $action = $_POST['action'] ?? '';

    // CSRF first
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=device_splice&fosc_id=' . $foscId);
        exit;
    }

    try {
        if ($action === 'create') {
            $deviceId    = (int)($_POST['fosc_device_id'] ?? 0);
            $spanId      = (int)($_POST['cable_span_id'] ?? 0);
            $spanEnd     = $_POST['span_end'] ?? '';
            $fibLo       = (int)($_POST['fibre_lo'] ?? 0);
            $fibHi       = (int)($_POST['fibre_hi'] ?? 0);
            $devLo       = (int)($_POST['dev_lo'] ?? 0);
            $devHi       = (int)($_POST['dev_hi'] ?? 0);
            $joinKind    = trim((string)($_POST['join_kind'] ?? 'single'));
            $isPlan      = !empty($_POST['is_plan']);
            $isPlan = !empty($_POST['is_plan']) ? 1 : 0;
            $notes       = trim((string)($_POST['notes'] ?? ''));

          if ($deviceId <= 0 || $spanId <= 0 || $spanEnd === '') {
    throw new RuntimeException('Device, span and end are required.');
}

// allow single-fibre/port ranges: hi may equal lo
if ($fibLo <= 0 || $fibHi < $fibLo) {
    throw new RuntimeException('Cable fibre range must be [lo, hi] with hi >= lo.');
}

if ($devLo <= 0 || $devHi < $devLo) {
    throw new RuntimeException('Device fibre/port range must be [lo, hi] with hi >= lo.');
}
          
            // Sanity: ensure the span really hits this FOSC
            $chk = $pdo->prepare("
                SELECT 1
                  FROM app.cable_span cs
                 WHERE cs.cable_span_id = :sid
                   AND (:fid = cs.from_fosc_id OR :fid = cs.to_fosc_id)
            ");
            $chk->execute([':sid' => $spanId, ':fid' => $foscId]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException('Selected span does not terminate on this closure.');
            }

            $cableRange = sprintf('[%d,%d]', $fibLo, $fibHi);
            $devRange   = sprintf('[%d,%d]', $devLo, $devHi);

            // Canonical orientation: A side is the DEVICE, B side is the CABLE
            $ins = $pdo->prepare("
                INSERT INTO app.splice_join (
                    fosc_id,
                    a_target, a_span_id, a_end, a_range,
                    b_target, b_span_id, b_end, b_range,
                    notes, is_plan, join_kind
                )
                VALUES (
                    :fid,
                    'fosc_device', :did, 'from', :drange,
                    'cable_span', :sid, :send, :crange,
                    :notes, :is_plan, :join_kind
                )
            ");

            $ins->execute([
                ':fid'       => $foscId,
                ':did'       => $deviceId,
                ':drange'    => $devRange,
                ':sid'       => $spanId,
                ':send'      => $spanEnd,
                ':crange'    => $cableRange,
                ':notes'     => ($notes !== '' ? $notes : null),
                ':is_plan'   => $isPlan,
                ':join_kind' => $joinKind,
            ]);

            flash_set('Device splice created.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Bad id.');
            }
            $del = $pdo->prepare("
                UPDATE app.splice_join SET retired_at = now()
                 WHERE splice_join_id = :id AND retired_at IS NULL
            ");
            $del->execute([':id' => $id]);
            flash_set('Device splice deleted.');
        }
    } catch (Throwable $e) {
        flash_set('Error: ' . $e->getMessage(), false);
    }

    header('Location: index.php?page=device_splice&fosc_id=' . $foscId);
    exit;
}

// ---------- Existing device splices (splice_join rows involving fosc_device) ----------

$stExisting = $pdo->prepare("
    WITH norm AS (
      SELECT
        sj.splice_join_id,
        sj.fosc_id,
        sj.is_plan,
        sj.join_kind,
        sj.notes,
        -- put DEVICE always on 'dev_' columns, CABLE always on 'cab_' columns
        CASE WHEN sj.a_target = 'fosc_device' THEN sj.a_span_id ELSE sj.b_span_id END AS dev_id,
        CASE WHEN sj.a_target = 'fosc_device' THEN sj.a_range   ELSE sj.b_range   END AS dev_range,
        CASE WHEN sj.a_target = 'cable_span'  THEN sj.a_span_id ELSE sj.b_span_id END AS cab_span_id,
        CASE WHEN sj.a_target = 'cable_span'  THEN sj.a_end     ELSE sj.b_end     END AS cab_end,
        CASE WHEN sj.a_target = 'cable_span'  THEN sj.a_range   ELSE sj.b_range   END AS cab_range
      FROM app.splice_join sj
      WHERE sj.fosc_id = :fid
        AND ('fosc_device' = sj.a_target OR 'fosc_device' = sj.b_target)
        AND sj.retired_at IS NULL
    )
    SELECT
      n.splice_join_id,
      n.is_plan,
      n.join_kind,
      n.notes,
      -- inclusive display bounds; the stored ranges are half-open
      lower(n.dev_range) || '-' || (upper(n.dev_range) - 1) as dev_range,
      lower(n.cab_range) || '-' || (upper(n.cab_range) - 1) as cab_range,
      n.cab_end,
      fd.name           AS device_name,
      fd.device_type,
      cs.cable_span_id,
      c.name            AS cable_name,
      c.fibre_count,
      f1.splice_num     AS from_label,
      f2.splice_num     AS to_label,
      n.dev_id
    FROM norm n
    JOIN app.fosc_device fd ON fd.fosc_device_id = n.dev_id
    JOIN app.cable_span  cs ON cs.cable_span_id  = n.cab_span_id
    JOIN app.cable       c  ON c.cable_id        = cs.cable_id
    JOIN app.fosc        f1 ON f1.fosc_id        = cs.from_fosc_id
    JOIN app.fosc        f2 ON f2.fosc_id        = cs.to_fosc_id
    ORDER BY n.is_plan, n.splice_join_id
");
$stExisting->execute([':fid' => $foscId]);
$deviceSplice = $stExisting->fetchAll();

$flash = flash_get();

// ---------- Render via Template ----------

$PAGE->setTitle('Device Splices for ' . $fosc['splice_num']);

$PAGE->setContentRenderer(function () use (
    $fosc,
    $devices,
    $spanEndpoints,
    $deviceSplice,
    $CSRF,
    $flash,
    $foscId
) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">
        Device Splices — <code><?= h($fosc['splice_num']) ?></code>
        <small class="text-muted">(<?= h($fosc['status']) ?>)</small>
      </h3>
      <a class="btn btn-outline-secondary btn-sm"
         href="index.php?page=closure&fosc_id=<?= (int)$foscId ?>">
        Back to Closure
      </a>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= !empty($flash['ok']) ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-header">New Device Splice</div>
      <div class="card-body">
        <?php if (empty($devices)): ?>
          <div class="alert alert-warning mb-0">
            No devices configured on this closure yet. Add devices first on the Devices page.
          </div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="create">

            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Device *</label>
                <select class="form-select" name="fosc_device_id" required>
                  <option value="">Select…</option>
                  <?php foreach ($devices as $d): ?>
                    <option value="<?= (int)$d['fosc_device_id'] ?>">
                      <?= h(ucfirst($d['device_type'])) ?> — <?= h($d['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-5">
                <label class="form-label">Cable endpoint *</label>
                <div class="input-group">
                  <select class="form-select" name="cable_span_id" required>
                    <option value="">Span…</option>
                    <?php foreach ($spanEndpoints as $ep): ?>
                      <option value="<?= (int)$ep['cable_span_id'] ?>">
                        <?= h(device_span_label($ep)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <select class="form-select" name="span_end" required>
                    <option value="">End…</option>
                    <option value="from">from</option>
                    <option value="to">to</option>
                  </select>
                </div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Plan or actual</label>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="is_plan" id="dsIsPlan">
                  <label class="form-check-label" for="dsIsPlan">
                    Planned splice (not yet built)
                  </label>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Cable fibres [lo, hi]</label>
                <div class="input-group">
                  <span class="input-group-text">[</span>
                  <input class="form-control" type="number" min="1" name="fibre_lo" required>
                  <span class="input-group-text">,</span>
                  <input class="form-control" type="number" min="1" name="fibre_hi" required>
                  <span class="input-group-text">]</span>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Device fibres/ports [lo, hi]</label>
                <div class="input-group">
                  <span class="input-group-text">[</span>
                  <input class="form-control" type="number" min="1" name="dev_lo" required>
                  <span class="input-group-text">,</span>
                  <input class="form-control" type="number" min="1" name="dev_hi" required>
                  <span class="input-group-text">]</span>
                </div>
                <div class="form-text">
                  For a single port, use e.g. [1,1].
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Join kind</label>
                <select class="form-select" name="join_kind">
                  <option value="single">Single</option>
                  <option value="ribbon">Ribbon</option>
                  <option value="pass_through">Pass-through</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"
                          placeholder="e.g. PLC S1 out1 to 4F drop"></textarea>
              </div>
            </div>

            <div class="mt-3">
              <button class="btn btn-success" type="submit">Create Device Splice</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Existing Device Splices</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Device</th>
                <th>Cable / Span</th>
                <th>End</th>
                <th>Dev fibres</th>
                <th>Cable fibres</th>
                <th>Kind</th>
                <th>Plan?</th>
                <th>Notes</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($deviceSplice)): ?>
              <tr>
                <td colspan="10" class="text-center text-muted py-3">
                  No device splices recorded yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($deviceSplice as $s): ?>
                <tr>
                  <td><?= (int)$s['splice_join_id'] ?></td>
                  <td>
                    <div><strong><?= h($s['device_name']) ?></strong></div>
                    <div class="small text-muted"><?= h($s['device_type']) ?></div>
                  </td>
                  <td>
                    <div><strong><?= h($s['cable_name']) ?></strong> (<?= (int)$s['fibre_count'] ?>F)</div>
                    <div class="small text-muted">
                      Span <?= (int)$s['cable_span_id'] ?>:
                      <?= h($s['from_label']) ?> → <?= h($s['to_label']) ?>
                    </div>
                  </td>
                  <td><?= h($s['cab_end']) ?></td>
                  <td><code><?= h($s['dev_range']) ?></code></td>
                  <td><code><?= h($s['cab_range']) ?></code></td>
                  <td><?= h($s['join_kind']) ?></td>
                  <td><?= $s['is_plan'] ? 'Plan' : 'Actual' ?></td>
                  <td><?= h($s['notes'] ?? '') ?></td>
                  <td class="text-end">
                    <form method="post"
                          class="d-inline"
                          onsubmit="return confirm('Delete this device splice?');">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$s['splice_join_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">
                        Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
