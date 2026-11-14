<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/signal_discrepancy.php — ONU Signal Discrepancy Report
//
// Compares actual ONU RX signal (from UISP cache) against the estimated signal
// computed from the fiber loss model (OLT TX - path loss - 2 × connector loss).
// Highlights closures where actual and estimated diverge significantly.

/** @var \FPM\App\Template $PAGE */

require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

$PAGE->setTitle('Signal Discrepancy');

$PAGE->setContentRenderer(function () {

    global $pdo;

    // ── 1. Load loss parameters ───────────────────────────────────────────────
    $oltTxDbm      = 3.25;
    $connectorLoss = 0.35;
    foreach ($pdo->query("SELECT param_key, loss_db::float FROM app.loss_param")->fetchAll() as $p) {
        if ($p['param_key'] === 'olt_class_b_plus_tx_dbm') $oltTxDbm      = (float)$p['loss_db'];
        if ($p['param_key'] === 'connector_sc_upc')         $connectorLoss = (float)$p['loss_db'];
    }

    // ── 2. Load per-fosc path loss + a representative trace span ─────────────
    // One row per ont_association; LEFT JOIN cable_span picks any connected span
    // for the "Open in Fibre Trace" link (DISTINCT ON keeps just one span per fosc).
    $foscRows = $pdo->query("
        SELECT oa.uisp_device_id::text          AS uisp_device_id,
               oa.fosc_id,
               COALESCE(ts.parent_closure_label,
                        f.splice_num)           AS fosc_label,
               COALESCE(ts.parent_fosc_id,
                        oa.fosc_id)             AS fosc_link_id,
               ts.fdc_span_id                  AS trace_span_id,
               ts.path_loss_db
          FROM app.ont_association oa
          JOIN app.fosc f ON f.fosc_id = oa.fosc_id
         CROSS JOIN LATERAL app.fosc_trace_stats(oa.fosc_id) ts
         ORDER BY oa.fosc_id
    ")->fetchAll();

    // Build a map keyed by uisp_device_id for fast lookup
    $foscByDevice = [];
    foreach ($foscRows as $r) {
        $foscByDevice[$r['uisp_device_id']] = $r;
    }

    // ── 3. Load UISP devices from cache ──────────────────────────────────────
    $uispDevices = uisp_fetch_cached('devices', 120, $pdo);
    $uispError   = null;
    if (isset($uispDevices['error'])) {
        $uispError = $uispDevices['error'];
    }

    // ── 4. Build rows: associated ONUs with actual + estimated signal ─────────
    $associated   = [];
    $unassociated = [];

    if (!$uispError) {
        foreach ($uispDevices as $dev) {
            if (($dev['identification']['type'] ?? '') !== 'onu') continue;
            $devId  = $dev['identification']['id'] ?? null;
            if (!$devId) continue;
            $ov     = $dev['overview'] ?? [];
            $actual = isset($ov['signal']) ? (int)$ov['signal'] : null;
            $name   = $dev['identification']['site']['name'] ?? $dev['identification']['name'] ?? '—';
            $status = $ov['status'] ?? 'unknown';

            if (isset($foscByDevice[$devId])) {
                $fr        = $foscByDevice[$devId];
                $pathLoss  = $fr['path_loss_db'] !== null ? (float)$fr['path_loss_db'] : null;
                $estimated = $pathLoss !== null
                    ? round($oltTxDbm - $pathLoss - 2 * $connectorLoss, 1)
                    : null;
                $delta = ($actual !== null && $estimated !== null)
                    ? round($actual - $estimated, 1)
                    : null;
                $associated[] = [
                    'name'          => $name,
                    'status'        => $status,
                    'fosc_id'       => (int)$fr['fosc_id'],
                    'fosc_label'    => $fr['fosc_label'],
                    'fosc_link_id'  => (int)$fr['fosc_link_id'],
                    'trace_span_id' => $fr['trace_span_id'],
                    'actual'        => $actual,
                    'estimated'     => $estimated,
                    'delta'         => $delta,
                ];
            } else {
                $unassociated[] = [
                    'name'   => $name,
                    'status' => $status,
                    'actual' => $actual,
                ];
            }
        }

        // Sort associated rows by |delta| descending (unknowns last)
        usort($associated, function ($a, $b) {
            $da = $a['delta'] !== null ? abs($a['delta']) : -1;
            $db = $b['delta'] !== null ? abs($b['delta']) : -1;
            return $db <=> $da;
        });

        // Sort unassociated by signal (weakest first)
        usort($unassociated, fn($a, $b) =>
            ($a['actual'] ?? 0) <=> ($b['actual'] ?? 0)
        );
    }

    ob_start();
    ?>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2 class="mb-0">Signal Discrepancy</h2>
                <p class="text-muted small mb-0">
                    Actual ONU RX signal vs. estimated from fiber loss model &nbsp;&mdash;&nbsp;
                    sorted by largest discrepancy first
                </p>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="text-muted small">Show delta &ge;</span>
                <div class="btn-group btn-group-sm" id="deltaFilter" role="group" aria-label="Delta filter">
                    <button type="button" class="btn btn-outline-secondary active" data-min="0">All</button>
                    <button type="button" class="btn btn-outline-warning"  data-min="2">2 dB</button>
                    <button type="button" class="btn btn-outline-danger"   data-min="5">5 dB</button>
                </div>
            </div>
        </div>

        <?php if ($uispError): ?>
            <div class="alert alert-danger"><strong>UISP API error:</strong> <?= h($uispError) ?></div>
        <?php else: ?>

        <!-- ── Associated ONUs ─────────────────────────────────────────── -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <h5 class="mb-0">Associated ONUs
                    <span class="badge bg-secondary" id="assocCount"><?= count($associated) ?></span>
                </h5>
                <small class="text-muted ms-2">
                    Delta = actual &minus; estimated &nbsp;
                    <span class="text-success fw-bold">&gt; 0</span> better than model
                    &nbsp;<span class="text-muted">|&delta;| &lt; 2 dB</span>
                    &nbsp;<span class="text-warning fw-bold">&minus;2 to &minus;5 dB</span>
                    &nbsp;<span class="text-danger fw-bold">&lt; &minus;5 dB</span>
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0" id="assocTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer / Site</th>
                            <th>Closure</th>
                            <th>Actual (dBm)</th>
                            <th>Estimated (dBm)</th>
                            <th>Delta (dB)</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($associated as $row):
                        $absDelta = $row['delta'] !== null ? abs($row['delta']) : null;
                        $deltaClass = match(true) {
                            $row['delta'] === null  => 'text-muted',
                            $row['delta'] > 0       => 'text-success',
                            $absDelta >= 5.0        => 'text-danger fw-bold',
                            $absDelta >= 2.0        => 'text-warning fw-bold',
                            default                 => 'text-muted',
                        };
                        $statusBadge = match($row['status']) {
                            'active'       => 'success',
                            'disconnected' => 'danger',
                            default        => 'secondary',
                        };
                        $traceUrl = $row['trace_span_id']
                            ? '/index.php?page=fibre_trace&span_id=' . (int)$row['trace_span_id'] . '&fibre=1&type=light'
                            : null;
                    ?>
                        <tr data-delta="<?= $absDelta !== null ? number_format($absDelta, 1) : '' ?>">
                            <td><strong><?= h($row['name']) ?></strong></td>
                            <td><a href="/index.php?page=closure&fosc_id=<?= (int)$row['fosc_link_id'] ?>"><code><?= h($row['fosc_label']) ?></code></a></td>
                            <td class="<?= gpon_signal_class($row['actual']) ?> fw-bold">
                                <?= $row['actual'] !== null ? $row['actual'] . ' dBm' : '—' ?>
                            </td>
                            <td class="<?= gpon_signal_class($row['estimated']) ?>">
                                <?php if ($row['estimated'] !== null): ?>
                                    ~<?= number_format($row['estimated'], 1) ?> dBm
                                <?php else: ?>
                                    <span class="text-muted">Not mapped</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= $deltaClass ?>">
                                <?php if ($row['delta'] !== null): ?>
                                    <?= ($row['delta'] >= 0 ? '+' : '') . number_format($row['delta'], 1) ?> dB
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $statusBadge ?>"><?= h($row['status']) ?></span></td>
                            <td>
                                <?php if ($traceUrl): ?>
                                    <a href="<?= h($traceUrl) ?>" class="btn btn-outline-secondary btn-sm text-nowrap">
                                        Trace &rarr;
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <?php if ($unassociated): ?>
        <!-- ── Unassociated ONUs ───────────────────────────────────────── -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Unassociated ONUs
                    <span class="badge bg-secondary"><?= count($unassociated) ?></span>
                    <small class="text-muted ms-2 fw-normal">
                        Signal data available but no fiber closure mapped — associate via Fibre Trace
                    </small>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer / Site</th>
                            <th>Actual (dBm)</th>
                            <th>Estimated (dBm)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unassociated as $row):
                        $statusBadge = match($row['status']) {
                            'active'       => 'success',
                            'disconnected' => 'danger',
                            default        => 'secondary',
                        };
                    ?>
                        <tr>
                            <td><strong><?= h($row['name']) ?></strong></td>
                            <td class="<?= gpon_signal_class($row['actual']) ?> fw-bold">
                                <?= $row['actual'] !== null ? $row['actual'] . ' dBm' : '—' ?>
                            </td>
                            <td><span class="text-muted">—</span></td>
                            <td><span class="badge bg-<?= $statusBadge ?>"><?= h($row['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // !$uispError ?>
    </div>

    <script>
    (function () {
        var btns  = document.querySelectorAll('#deltaFilter button');
        var rows  = document.querySelectorAll('#assocTable tbody tr');
        var count = document.getElementById('assocCount');

        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                var min    = parseFloat(btn.dataset.min) || 0;
                var visible = 0;

                rows.forEach(function (row) {
                    var delta = parseFloat(row.dataset.delta);
                    var show  = isNaN(delta) ? (min === 0) : (delta >= min);
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                if (count) count.textContent = visible;
            });
        });
    })();
    </script>
    <?php

    return ob_get_clean();
});
