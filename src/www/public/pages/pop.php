<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/pop.php
// CRUD for app.pop (Points of Presence / central offices).
// A POP groups several closures that live together inside a building, and is the
// home for "active" gear (OLT/PON, SFP, DWDM) that feeds those closures.

/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;

/**
 * Parse + validate optional lat/lon form fields.
 * Returns [lat, lon] as floats, or [null, null] when both are blank.
 * Throws if only one is given or values are out of range.
 */
function pop_parse_latlon(string $latRaw, string $lonRaw): array
{
    $latRaw = trim($latRaw);
    $lonRaw = trim($lonRaw);
    if ($latRaw === '' && $lonRaw === '') {
        return [null, null];
    }
    if ($latRaw === '' || $lonRaw === '') {
        throw new RuntimeException('Provide both latitude and longitude, or leave both blank.');
    }
    if (!is_numeric($latRaw) || !is_numeric($lonRaw)) {
        throw new RuntimeException('Latitude and longitude must be numbers.');
    }
    $lat = (float)$latRaw;
    $lon = (float)$lonRaw;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new RuntimeException('Latitude/longitude out of range.');
    }
    return [$lat, $lon];
}

/** SQL fragment for the location column given parsed lat/lon (binds :lat/:lon when set). */
function pop_location_sql(?float $lat, ?float $lon): string
{
    return $lat === null
        ? 'NULL'
        : 'ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)';
}

// --- POST actions ---
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=pop');
        exit;
    }

    try {
        if ($action === 'create') {
            $name  = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            [$lat, $lon] = pop_parse_latlon($_POST['lat'] ?? '', $_POST['lon'] ?? '');
            if ($name === '') {
                throw new RuntimeException('POP name is required.');
            }
            $stmt = $pdo->prepare("
                INSERT INTO app.pop (name, notes, location)
                VALUES (:name, :notes, " . pop_location_sql($lat, $lon) . ")
            ");
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':notes', $notes !== '' ? $notes : null,
                             $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($lat !== null) { $stmt->bindValue(':lat', $lat); $stmt->bindValue(':lon', $lon); }
            $stmt->execute();
            flash_set('POP created.');

        } elseif ($action === 'update') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            [$lat, $lon] = pop_parse_latlon($_POST['lat'] ?? '', $_POST['lon'] ?? '');
            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Invalid input.');
            }
            $stmt = $pdo->prepare("
                UPDATE app.pop
                   SET name = :name, notes = :notes,
                       location = " . pop_location_sql($lat, $lon) . ",
                       updated_at = now()
                 WHERE pop_id = :id
            ");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':notes', $notes !== '' ? $notes : null,
                             $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($lat !== null) { $stmt->bindValue(':lat', $lat); $stmt->bindValue(':lon', $lon); }
            $stmt->execute();
            flash_set('POP updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Bad id.');
            }
            // Refuse to delete a non-empty POP: deleting would CASCADE-delete its
            // homed active devices and unassign its member closures. Force cleanup first.
            $chk = $pdo->prepare("
                SELECT
                  (SELECT count(*) FROM app.fosc        WHERE pop_id = :id) AS members,
                  (SELECT count(*) FROM app.fosc_device WHERE pop_id = :id) AS devices
            ");
            $chk->execute([':id' => $id]);
            $cnt = $chk->fetch();
            if ((int)$cnt['members'] > 0 || (int)$cnt['devices'] > 0) {
                throw new RuntimeException(
                    'Cannot delete: this POP still has '
                    . (int)$cnt['members'] . ' closure(s) and '
                    . (int)$cnt['devices'] . ' device(s). Reassign them first.');
            }
            $stmt = $pdo->prepare("DELETE FROM app.pop WHERE pop_id = :id");
            $stmt->execute([':id' => $id]);
            flash_set('POP deleted.');
        }
    } catch (Throwable $e) {
        flash_set('Error: ' . $e->getMessage(), false);
    }

    header('Location: index.php?page=pop');
    exit;
}

// --- List POPs + their members and homed devices ---
$pops = $pdo->query("
  SELECT pop_id, name, notes,
         ST_Y(location) AS lat,
         ST_X(location) AS lon
    FROM app.pop
ORDER BY name ASC
")->fetchAll();

$memberRows = $pdo->query("
  SELECT pop_id, fosc_id, splice_num, enclosure_rating
    FROM app.fosc
   WHERE pop_id IS NOT NULL
ORDER BY splice_num ASC
")->fetchAll();

$deviceRows = $pdo->query("
  SELECT pop_id, fosc_device_id, device_type, name
    FROM app.fosc_device
   WHERE pop_id IS NOT NULL
ORDER BY name ASC
")->fetchAll();

$membersByPop = [];
foreach ($memberRows as $m) { $membersByPop[(int)$m['pop_id']][] = $m; }
$devicesByPop = [];
foreach ($deviceRows as $d) { $devicesByPop[(int)$d['pop_id']][] = $d; }

$flash = flash_get();
$PAGE->setTitle('Points of Presence');

$PAGE->setContentRenderer(function () use ($pops, $membersByPop, $devicesByPop, $CSRF, $flash) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Points of Presence</h3>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreatePop">
        New POP
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
            <th>Name</th>
            <th>Member closures</th>
            <th>POP devices</th>
            <th>Notes</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pops as $p): ?>
          <?php
            $pid     = (int)$p['pop_id'];
            $members = $membersByPop[$pid] ?? [];
            $devices = $devicesByPop[$pid] ?? [];
          ?>
          <tr>
            <td><?= $pid ?></td>
            <td>
              <a class="text-decoration-none fw-bold"
                 href="index.php?page=pop_detail&pop_id=<?= $pid ?>"><?= h($p['name']) ?></a>
            </td>
            <td>
              <?php if ($members): ?>
                <?php foreach ($members as $m): ?>
                  <a class="badge bg-light text-dark border text-decoration-none me-1"
                     href="index.php?page=closure&fosc_id=<?= (int)$m['fosc_id'] ?>"
                     title="<?= h($m['enclosure_rating']) ?>-rated">
                    <?= h($m['splice_num']) ?>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($devices): ?>
                <?php foreach ($devices as $d): ?>
                  <a class="badge bg-info-subtle text-dark border text-decoration-none me-1"
                     href="index.php?page=device" title="<?= h($d['device_type']) ?>">
                    <?= h($d['name']) ?>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= nl2br(h($p['notes'] ?? '')) ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#modalEditPop<?= $pid ?>">
                Edit
              </button>
              <form class="d-inline" method="post"
                    onsubmit="return confirm('Delete this POP?');">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="modalEditPop<?= $pid ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog"><div class="modal-content">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Edit POP #<?= $pid ?></h5>
                  <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name *</label>
                    <input class="form-control" name="name" value="<?= h($p['name']) ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?= h($p['notes'] ?? '') ?></textarea>
                  </div>
                  <div class="row g-2 mb-2">
                    <div class="col">
                      <label class="form-label">Latitude</label>
                      <input class="form-control" name="lat" inputmode="decimal"
                             value="<?= $p['lat'] !== null ? h(rtrim(rtrim(number_format((float)$p['lat'], 6, '.', ''), '0'), '.')) : '' ?>"
                             placeholder="e.g. 45.1234">
                    </div>
                    <div class="col">
                      <label class="form-label">Longitude</label>
                      <input class="form-control" name="lon" inputmode="decimal"
                             value="<?= $p['lon'] !== null ? h(rtrim(rtrim(number_format((float)$p['lon'], 6, '.', ''), '0'), '.')) : '' ?>"
                             placeholder="e.g. -81.5678">
                    </div>
                  </div>
                  <div class="form-text">
                    Lat/lon place the office-building marker on the map (or drag it there).
                    Clear both to remove it. Assign closures from each closure's Edit dialog.
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
        <?php if (!$pops): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No POPs yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="modalCreatePop" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title">New POP</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Name *</label>
              <input class="form-control" name="name" placeholder="e.g. Colpoys CO" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>
            <div class="row g-2">
              <div class="col">
                <label class="form-label">Latitude</label>
                <input class="form-control" name="lat" inputmode="decimal" placeholder="e.g. 45.1234">
              </div>
              <div class="col">
                <label class="form-label">Longitude</label>
                <input class="form-control" name="lon" inputmode="decimal" placeholder="e.g. -81.5678">
              </div>
              <div class="form-text">
                Optional — sets the office-building marker on the map. Use the map's
                <strong>Coords</strong> tool to copy a point, or drag the marker later.
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
    <?php
    return ob_get_clean();
});
