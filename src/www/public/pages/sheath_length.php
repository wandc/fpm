<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/sheath_length.php
// Simple sheath-length capture page for Tinuum webapp.

/** @var \FPM\App\Template $PAGE */
global $pdo, $CSRF;

// -------- POST handling (save / delete) --------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF check
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
        header('Location: index.php?page=sheath_length');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash'] = ['msg' => 'Invalid id for delete.', 'ok' => false];
            header('Location: index.php?page=sheath_length');
            exit;
        }
        try {
            $st = $pdo->prepare('DELETE FROM app.sheath_length WHERE sheath_length_id = :id');
            $st->execute([':id' => $id]);
            $_SESSION['flash'] = ['msg' => "Deleted measurement #{$id}.", 'ok' => true];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => 'Delete failed: '.$e->getMessage(), 'ok' => false];
        }
        header('Location: index.php?page=sheath_length');
        exit;
    }

    if ($action === 'save') {
        $length   = trim((string)($_POST['length_m'] ?? ''));
        $latRaw   = trim((string)($_POST['lat'] ?? ''));
        $lonRaw   = trim((string)($_POST['lon'] ?? ''));
        $note     = trim((string)($_POST['note'] ?? ''));
        $spanRaw  = trim((string)($_POST['cable_span_id'] ?? ''));

        $errors = [];

        // length: integer meters
        if ($length === '' || !is_numeric($length) || (int)$length <= 0) {
            $errors[] = 'Length must be a positive integer (meters).';
        } else {
            $length = (int)$length;
        }

        // coords must both be present & numeric
        if ($latRaw === '' || $lonRaw === '' || !is_numeric($latRaw) || !is_numeric($lonRaw)) {
            $errors[] = 'Latitude and longitude are required and must be numeric.';
        } else {
            $lat = (float)$latRaw;
            $lon = (float)$lonRaw;
        }

        // optional cable_span_id
        $cableSpanId = null;
        if ($spanRaw !== '' && is_numeric($spanRaw)) {
            $cableSpanId = (int)$spanRaw;
            if ($cableSpanId <= 0) {
                $cableSpanId = null;
            }
        }

        if (!empty($errors)) {
            $_SESSION['flash'] = [
                'msg' => 'Save failed: ' . implode(' ', $errors),
                'ok'  => false,
            ];
            header('Location: index.php?page=sheath_length');
            exit;
        }

        try {
            $sql = "
                INSERT INTO app.sheath_length
                    (cable_span_id, measure_m, geom, notes)
                VALUES
                    (:span_id,
                     :measure_m,
                     ST_SetSRID(ST_MakePoint(:lon, :lat), 4326),
                     NULLIF(:notes, '')
                    )
            ";
            $st = $pdo->prepare($sql);
            $st->bindValue(':span_id', $cableSpanId, $cableSpanId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':measure_m', $length, PDO::PARAM_INT);
            $st->bindValue(':lon', $lon);
            $st->bindValue(':lat', $lat);
            $st->bindValue(':notes', $note === '' ? null : $note, $note === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->execute();

            $_SESSION['flash'] = [
                'msg' => 'Sheath length measurement saved.',
                'ok'  => true,
            ];
        } catch (Throwable $e) {
            $_SESSION['flash'] = [
                'msg' => 'DB error: '.$e->getMessage(),
                'ok'  => false,
            ];
        }

        header('Location: index.php?page=sheath_length');
        exit;
    }
}

// -------- Fetch flash + recent rows --------

$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) unset($_SESSION['flash']);

$recent = [];
try {
    $st = $pdo->query("
        SELECT
            sheath_length_id,
            cable_span_id,
            measure_m,
            ST_X(geom) AS lon,
            ST_Y(geom) AS lat,
            notes,
            updated_at,
            created_at
        FROM app.sheath_length
        ORDER BY updated_at DESC, created_at DESC
        LIMIT 20
    ");
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('sheath_length recent query failed: '.$e->getMessage());
    $recent = [];
}

// -------- Page title + content --------

$PAGE->setTitle('Sheath Length Measurements');

$PAGE->setContentRenderer(function () use ($flash, $recent, $CSRF) {
    ob_start();
    ?>

    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">Sheath Length Measurements</h3>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= !empty($flash['ok']) ? 'alert-success' : 'alert-danger' ?>">
        <?= h($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-5 mb-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title">New Measurement</h5>
            <form method="post">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

              <div class="mb-3">
                <label class="form-label">Length (m)</label>
                <input type="number"
                       class="form-control"
                       name="length_m"
                       step="1"
                       min="1"
                       required>
                <div class="form-text">Integer meters only.</div>
              </div>

              <div class="mb-3">
                <label class="form-label">Cable Span ID (optional)</label>
                <input type="number"
                       class="form-control"
                       name="cable_span_id"
                       step="1"
                       min="1"
                >
              </div>

              <div class="row g-2 mb-2">
                <div class="col-6">
                  <label class="form-label">Latitude</label>
                  <input type="text"
                         class="form-control"
                         name="lat"
                         id="sheath-lat"
                         placeholder="46.09"
                         required>
                </div>
                <div class="col-6">
                  <label class="form-label">Longitude</label>
                  <input type="text"
                         class="form-control"
                         name="lon"
                         id="sheath-lon"
                         placeholder="-64.78"
                         required>
                </div>
              </div>

              <div class="mb-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-use-location">
                  Use device location
                </button>
                <small class="text-muted ms-2 d-inline-block" id="loc-status"></small>
              </div>

              <div class="mb-3">
                <label class="form-label">Notes (optional)</label>
                <textarea class="form-control"
                          name="note"
                          rows="2"
                          placeholder="Anything special about this measurement?"></textarea>
              </div>

              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Measurement</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title">Recent Measurements</h5>
            <?php if (empty($recent)): ?>
              <p class="text-muted mb-0">No measurements recorded yet.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Len (m)</th>
                      <th>Coords</th>
                      <th>Notes</th>
                      <th>When</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($recent as $row): ?>
                    <?php
                      $id   = (int)$row['sheath_length_id'];
                      $lenI = $row['measure_m'] !== null ? (int)round($row['measure_m']) : null;

                      $lat  = $row['lat'] ?? null;
                      $lon  = $row['lon'] ?? null;
                      $coord = ($lat !== null && $lon !== null)
                          ? sprintf('%.5f, %.5f', $lat, $lon)
                          : '';

                      $tsRaw = $row['updated_at'] ?? $row['created_at'] ?? null;
                      $when  = '';
                      if ($tsRaw) {
                          try {
                              $dt   = new \DateTimeImmutable($tsRaw);
                              $when = $dt->format('Y-m-d H:i');
                          } catch (\Throwable $e) {
                              $when = (string)$tsRaw;
                          }
                      }
                    ?>
                    <tr>
                      <td><?= h($id) ?></td>
                      <td><?= $lenI !== null ? h($lenI) : '' ?></td>
                      <td><code><?= h($coord) ?></code></td>
                      <td><?= h($row['notes'] ?? '') ?></td>
                      <td><?= h($when) ?></td>
                      <td class="text-end">
                        <form method="post"
                              onsubmit="return confirm('Delete this measurement?');"
                              class="d-inline">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit">
                            Delete
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const btn   = document.getElementById('btn-use-location');
      const latEl = document.getElementById('sheath-lat');
      const lonEl = document.getElementById('sheath-lon');
      const statusEl = document.getElementById('loc-status');

      if (!btn) return;

      btn.addEventListener('click', function() {
        if (!navigator.geolocation) {
          statusEl.textContent = 'Geolocation not supported.';
          return;
        }
        statusEl.textContent = 'Getting location…';
        navigator.geolocation.getCurrentPosition(
          function(pos) {
            const lat = pos.coords.latitude.toFixed(6);
            const lon = pos.coords.longitude.toFixed(6);
            latEl.value = lat;
            lonEl.value = lon;
            statusEl.textContent = 'Location filled.';
          },
          function(err) {
            statusEl.textContent = 'Location failed: ' + err.message;
          },
          {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 30000
          }
        );
      });
    });
    </script>

    <?php
    return ob_get_clean();
});
