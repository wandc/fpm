<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/splices.php – Manage splices at a given FOSC

use FPM\App\Template;

if (!isset($PAGE) || !($PAGE instanceof Template)) {
    throw new RuntimeException('Template not initialised');
}

// Session + CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// Helpers used by the diagrams
require_once $_SERVER['DOCUMENT_ROOT']. '/phpHelpers/fiber_colors.php';   // adjust path if needed
require_once $_SERVER['DOCUMENT_ROOT']. '/phpHelpers/splice_chart.helper.php';            // providesender_layout(), etc.

$PAGE->addExternalCss('/css/splice_chart.helper.css');
$PAGE->addExternalJs('/js/splice_chart.helper.js?v=3');

/* -------------------------------------------------------------------------
 * Small data helpers
 * ---------------------------------------------------------------------- */

function fetch_fosc_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("
        SELECT f.fosc_id, f.splice_num, f.location,
               topology.ST_X(f.location) AS lon, topology.ST_Y(f.location) AS lat
        FROM app.fosc f
        WHERE f.fosc_id = :fid
    ");
    $st->execute([':fid' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function fetch_fosc_id_by_splice(PDO $pdo, string $splice): ?int {
    $st = $pdo->prepare("SELECT fosc_id FROM app.fosc WHERE splice_num = UPPER(:s) LIMIT 1");
    $st->execute([':s' => $splice]);
    $row = $st->fetch();
    return $row ? (int)$row['fosc_id'] : null;
}

function fetch_span_endpoints_for_fosc(PDO $pdo, int $foscId): array {
    
       $sql = <<<SQL_QUERY
SELECT
        (se.cable_span_id || '-' || se."end") AS endpoint_key,
        se.cable_span_id,
        se."end"::text AS ep_end,
        se.fosc_id,
        fh.splice_num AS fosc_label,
        CASE
          WHEN se."end" = 'from' THEN ft.splice_num
          ELSE ff.splice_num
        END AS far_fosc_label,
        cs.length_m,
        NULL AS incoming_bearing_deg,
        'cable_span'::text AS target
      FROM app.v_span_endpoints se
      JOIN app.cable_span cs ON cs.cable_span_id = se.cable_span_id
      JOIN app.fosc ff ON ff.fosc_id = cs.from_fosc_id
      JOIN app.fosc ft ON ft.fosc_id = cs.to_fosc_id
      JOIN app.fosc fh ON fh.fosc_id = se.fosc_id
      WHERE se.fosc_id = :fid
      ORDER BY se.cable_span_id, se."end"
SQL_QUERY;
    
    
    $st = $pdo->prepare($sql);
    $st->execute([':fid' => $foscId]);
    return $st->fetchAll() ?: [];
}





/* -------------------------------------------------------------------------
 * Splice chart + plan helpers
 * ---------------------------------------------------------------------- */

// render_fosc_splice_chart() is defined in splice_chart.helper.php

function generate_splicing_plan(PDO $pdo, int $foscId, bool $isPlan = false): string {
    $sql = "
 SELECT
    j.splice_join_id,
    j.a_target,
    j.a_span_id,
    j.a_end,

    -- A side: display-friendly [lo,hi] from stored range
    lower(j.a_range)     AS a_lo,
    upper(j.a_range) - 1 AS a_hi,   -- inclusive; the stored range is half-open

    j.b_target,
    j.b_span_id,
    j.b_end,

    -- B side: same thing
    lower(j.b_range)     AS b_lo,
    upper(j.b_range) - 1 AS b_hi,

    da.name        AS a_dev_name,
    da.device_type AS a_dev_type,
    (da.attrs->>'outs')::int AS a_dev_outs,
    db.name        AS b_dev_name,
    db.device_type AS b_dev_type,
    (db.attrs->>'outs')::int AS b_dev_outs

  FROM app.splice_join j
  LEFT JOIN app.fosc_device da
    ON (j.a_target = 'fosc_device' AND da.fosc_device_id = j.a_span_id)
  LEFT JOIN app.fosc_device db
    ON (j.b_target = 'fosc_device' AND db.fosc_device_id = j.b_span_id)
  WHERE j.fosc_id = :fid
    AND j.is_plan = :isPlan
    AND j.retired_at IS NULL
  ORDER BY
    j.a_target,
    j.a_span_id,
    lower(j.a_range),
    j.splice_join_id
";
 
    $st = $pdo->prepare($sql);
    $st->bindValue(':fid', $foscId, PDO::PARAM_INT);
    $st->bindValue(':isPlan', (bool)$isPlan, PDO::PARAM_BOOL);
    $st->execute();

    $rows = $st->fetchAll() ?: [];
    if (!$rows) return $isPlan ? "No planned splices." : "No actual splices.";

    $labelEp = function(array $row, string $side): string {
        $tgt = $row[$side.'_target'];
        $id  = (int)$row[$side.'_span_id'];
        if ($tgt === 'cable_span') return "Span {$id}";
        $name = $row[$side.'_dev_name'] ?: ("Device {$id}");
        if (($row[$side.'_dev_type'] ?? '') === 'splitter') {
            $outs = (int)($row[$side.'_dev_outs'] ?? 0);
            if ($outs > 0) $name .= " — 1×{$outs}";
        }
        return $name;
    };
    $fmtRange = function(string $target, int $lo, int $hi): string {
        $isCable = ($target === 'cable_span');
        if ($lo === $hi) return $isCable ? "{$lo}F" : "port {$lo}";
        return $isCable ? "{$lo}–{$hi}F" : "ports {$lo}–{$hi}";
    };

    $lines = [];
    foreach ($rows as $r) {
        $lines[] = $labelEp($r, 'a') . ' ' . $fmtRange($r['a_target'], (int)$r['a_lo'], (int)$r['a_hi'])
                 . ' → ' . $labelEp($r, 'b') . ' ' . $fmtRange($r['b_target'], (int)$r['b_lo'], (int)$r['b_hi']);
    }
    return implode("\n", $lines);
}

function fetch_splices_at_fosc(PDO $pdo, int $foscId): array {
    $sql = "
      SELECT
        j.splice_join_id,
        -- inclusive bounds (the stored range is half-open, so hi is upper()-1)
        lower(j.a_range)     AS a_fiber_lo,
        upper(j.a_range) - 1 AS a_fiber_hi,
        lower(j.b_range)     AS b_fiber_lo,
        upper(j.b_range) - 1 AS b_fiber_hi,
        j.notes,
        j.a_target, j.a_span_id, j.a_end,
        j.b_target, j.b_span_id, j.b_end,
        CASE WHEN j.a_target = 'cable_span' THEN cA.name ELSE dA.name END AS a_label,
        CASE WHEN j.a_target = 'cable_span' THEN sA.length_m ELSE NULL END AS a_length_m,
        CASE WHEN j.a_target = 'cable_span' THEN fA_far.splice_num ELSE NULL END AS a_far_fosc_label,
        CASE WHEN j.a_target = 'cable_span' THEN NULL END AS a_dir,
        CASE WHEN j.a_target = 'fosc_device' AND dA.device_type='splitter'
             THEN (dA.attrs->>'outs')::int ELSE NULL END AS a_splitter_outs,
        CASE WHEN j.b_target = 'cable_span' THEN cB.name ELSE dB.name END AS b_label,
        CASE WHEN j.b_target = 'cable_span' THEN sB.length_m ELSE NULL END AS b_length_m,
        CASE WHEN j.b_target = 'cable_span' THEN fB_far.splice_num ELSE NULL END AS b_far_fosc_label,
        CASE WHEN j.b_target = 'cable_span' THEN NULL END AS b_dir,
        CASE WHEN j.b_target = 'fosc_device' AND dB.device_type='splitter'
             THEN (dB.attrs->>'outs')::int ELSE NULL END AS b_splitter_outs
      FROM app.splice_join j
      LEFT JOIN app.cable_span   sA ON (j.a_target='cable_span'   AND sA.cable_span_id  = j.a_span_id)
      LEFT JOIN app.cable        cA ON (j.a_target='cable_span'   AND cA.cable_id       = sA.cable_id)
      LEFT JOIN app.fosc_device  dA ON (j.a_target='fosc_device'  AND dA.fosc_device_id = j.a_span_id)
      LEFT JOIN app.fosc      fA_from ON (j.a_target='cable_span' AND fA_from.fosc_id   = sA.from_fosc_id)
      LEFT JOIN app.fosc        fA_to ON (j.a_target='cable_span' AND fA_to.fosc_id     = sA.to_fosc_id)
      LEFT JOIN LATERAL (
        SELECT CASE WHEN j.a_end = 'from' THEN fA_to.fosc_id ELSE fA_from.fosc_id END AS far_id
      ) a_far ON true
      LEFT JOIN app.fosc      fA_far ON (j.a_target='cable_span' AND fA_far.fosc_id = a_far.far_id)
      LEFT JOIN app.cable_span   sB ON (j.b_target='cable_span'   AND sB.cable_span_id  = j.b_span_id)
      LEFT JOIN app.cable        cB ON (j.b_target='cable_span'   AND cB.cable_id       = sB.cable_id)
      LEFT JOIN app.fosc_device  dB ON (j.b_target='fosc_device'  AND dB.fosc_device_id = j.b_span_id)
      LEFT JOIN app.fosc      fB_from ON (j.b_target='cable_span' AND fB_from.fosc_id   = sB.from_fosc_id)
      LEFT JOIN app.fosc        fB_to ON (j.b_target='cable_span' AND fB_to.fosc_id     = sB.to_fosc_id)
      LEFT JOIN LATERAL (
        SELECT CASE WHEN j.b_end = 'from' THEN fB_to.fosc_id ELSE fB_from.fosc_id END AS far_id
      ) b_far ON true
      LEFT JOIN app.fosc      fB_far ON (j.b_target='cable_span' AND fB_far.fosc_id = b_far.far_id)
      WHERE j.fosc_id = :fid
        AND j.retired_at IS NULL
      ORDER BY j.splice_join_id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':fid' => $foscId]);
    return $st->fetchAll() ?: [];
}
function parse_endpoint_key(?string $ek): ?array {
    if ($ek === null || $ek === '' || strpos($ek, '-') === false) return null;
    // device form: dev:{id}-device
    if (substr($ek, 0, 4) === 'dev:') {
        $ek = substr($ek, 4);
        [$id, $end] = explode('-', $ek, 2);
        $id = (int)$id;
        $end = ($end === 'device') ? 'device' : null;
        if ($id <= 0 || !$end) return null;
        return ['target' => 'fosc_device', 'span_id' => $id, 'end' => $end];
    }
    // cable form: {span_id}-{from|to}
    [$sid, $end] = explode('-', $ek, 2);
    $sid = (int)$sid;
    $end = ($end === 'from' || $end === 'to') ? $end : null;
    if ($sid <= 0 || !$end) return null;
    return ['target' => 'cable_span', 'span_id' => $sid, 'end' => $end];
}


/**
 * Render <option> tags for span endpoints.
 *
 * @param array       $spanEndpoints rows from endpoint query
 * @param string|null $selectedKey   currently-selected endpoint_key (if any)
 */
function render_span_endpoint_options(array $spanEndpoints, ?string $selectedKey = null): string
{
    ob_start();
    foreach ($spanEndpoints as $ep) {
        $value = (string)($ep['endpoint_key'] ?? '');
        $label =  " \u{27A9}". bearing_to_compass($ep['incoming_bearing_deg']). 
                ' Span ' . (int)$ep['cable_span_id']
               . ' from ' . h($ep['far_fosc_label'])
               . ' (' . number_format((float)($ep['length_m'] ?? 0), 0) . ' m)';

        $selectedAttr = ($selectedKey !== null && $selectedKey === $value) ? ' selected' : '';

        ?>
        <option value="<?= h($value) ?>"<?= $selectedAttr ?>>
          <?= h($label) ?>
        </option>
        <?php
    }
    return trim(ob_get_clean());
}

/**
 * Convert a bearing in degrees (0–360) to a compass code.
 *
 * @param float|null $deg  Bearing in degrees (can be any real, will be normalized).
 * @param int        $mode 8 for N/NE/E/… (8-way), 4 for N/E/S/W (4-way).
 *
 * @return string|null Compass code or null if $deg is null.
 */
function bearing_to_compass(?float $deg, int $mode = 4): ?string
{
    if ($deg === null) {
        return null;
    }

    // Normalize to [0, 360)
    $deg = fmod($deg, 360.0);
    if ($deg < 0) {
        $deg += 360.0;
    }

    if ($mode === 4) {
        // 4-way: N, E, S, W (90° sectors centered on the cardinal directions)
        // N: [315, 360) & [0, 45)
        // E: [45, 135)
        // S: [135, 225)
        // W: [225, 315)
        $dirs4 = ['N', 'E', 'S', 'W'];
        $index = (int) floor(($deg + 45.0) / 90.0) % 4;
        return $dirs4[$index];
    }

    // Default: 8-way: N, NE, E, SE, S, SW, W, NW (45° sectors)
    $dirs8 = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    $index = (int) floor(($deg + 22.5) / 45.0) % 8;
    return $dirs8[$index];
}

/* -------------------------------------------------------------------------
 * POST: add splice
 * ---------------------------------------------------------------------- */

if (($_POST['action'] ?? '') === 'add_splice') {
    $errors = [];
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) $errors[] = 'Bad CSRF token.';


    // Splice type
    $joinKind = $_POST['join_kind'] ?? 'single';

    // whitelist – keep in sync with app.splice_join_kind enum
    $validKinds = ['single', 'ribbon', 'pass_through'];
    if (!in_array($joinKind, $validKinds, true)) {
    $joinKind = 'single';
    }

    $foscId = (int)($_POST['fosc_id'] ?? 0);
    $isPlan = ($_POST['is_plan'] ?? '0') === '1';

    $a = parse_endpoint_key($_POST['a_endpoint'] ?? '') ?: [];
    $b = parse_endpoint_key($_POST['b_endpoint'] ?? '') ?: [];
    $a_lo = (int)($_POST['a_fiber_lo'] ?? 0);
    $a_hi = (int)($_POST['a_fiber_hi'] ?? 0);
    $b_lo = (int)($_POST['b_fiber_lo'] ?? 0);
    $b_hi = (int)($_POST['b_fiber_hi'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($foscId <= 0) $errors[] = 'Missing FOSC.';
    if (!$a) $errors[] = 'Select A endpoint.';
    if (!$b) $errors[] = 'Select B endpoint.';
    //if (!$errors && ($a['target'] ?? '') === ($b['target'] ?? '') && $a['span_id'] === $b['span_id'] && $a['end'] === $b['end']) {
    //    $errors[] = 'A and B endpoints must differ.';
    //}
    if (((($a['target'] ?? 'cable_span') === 'cable_span') && ($a_lo <= 0 || $a_hi <= 0)) || $a_lo > $a_hi) {
        $errors[] = 'A port/fiber range invalid.';
    }
    if ((($b['target'] ?? 'cable_span') === 'cable_span' && ($b_lo <= 0 || $b_hi <= 0)) || $b_lo > $b_hi) {
        $errors[] = 'B port/fiber range invalid.';
    }

    if ($errors) {
        $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
        header('Location: /index.php?page=splices&fosc_id=' . $foscId);
        exit;
    }

    try {

        $sqlIns="
  INSERT INTO app.splice_join (
    fosc_id,
    is_plan,
    a_target, a_span_id, a_end, a_range,
    b_target, b_span_id, b_end, b_range,
    notes,
    join_kind
  )
  VALUES (
    :fosc,
    :isPlan,
    :atarget, :asid, :aend, int4range(:alo, :ahi, '[)'),
    :btarget, :bsid, :bend, int4range(:blo, :bhi, '[)'),
    :notes,
    :join_kind
  )
  RETURNING splice_join_id";
$stIns = $pdo->prepare($sqlIns);

$stIns->bindValue(':fosc',    $foscId,       PDO::PARAM_INT);
$stIns->bindValue(':isPlan',  (bool)$isPlan, PDO::PARAM_BOOL);

$stIns->bindValue(':atarget', $a['target']);
$stIns->bindValue(':asid',    $a['span_id'], PDO::PARAM_INT);
$stIns->bindValue(':aend',    $a['end']);
$stIns->bindValue(':alo',     $a_lo,         PDO::PARAM_INT);
$stIns->bindValue(':ahi',     $a_hi,         PDO::PARAM_INT);

$stIns->bindValue(':btarget', $b['target']);
$stIns->bindValue(':bsid',    $b['span_id'], PDO::PARAM_INT);
$stIns->bindValue(':bend',    $b['end']);
$stIns->bindValue(':blo',     $b_lo,         PDO::PARAM_INT);
$stIns->bindValue(':bhi',     $b_hi,         PDO::PARAM_INT);

$stIns->bindValue(':notes',   ($notes !== '' ? $notes : null));
$stIns->bindValue(':join_kind', $joinKind);  // <-- use $stIns here

$stIns->execute();

        $_SESSION['flash'] = ['msg' => 'Splice added (#' . $stIns->fetchColumn() . ').', 'ok' => true];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['msg' => 'Insert failed: ' . $e->getMessage(), 'ok' => false];
    }

    header('Location: /index.php?page=splices&fosc_id=' . $foscId);
    exit;
}
// --- Delete splice_join row ---
elseif (($_POST['action'] ?? '') === 'delete_join') {
    // We keep the same CSRF style and flash messages as elsewhere
    $foscId = isset($_POST['fosc_id'])
        ? (int)$_POST['fosc_id']
        : (int)($_GET['fosc_id'] ?? 0);

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = [
            'msg' => 'Bad CSRF token.',
            'ok'  => false,
        ];
        header('Location: index.php?page=splices&fosc_id=' . $foscId);
        exit;
    }

    $joinId = (int)($_POST['join_id'] ?? 0);
    if ($joinId <= 0) {
        $_SESSION['flash'] = [
            'msg' => 'Invalid splice join id.',
            'ok'  => false,
        ];
        header('Location: index.php?page=splices&fosc_id=' . $foscId);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("
            UPDATE app.splice_join SET retired_at = now()
             WHERE splice_join_id = :id AND retired_at IS NULL
        ");
        $st->execute([':id' => $joinId]);
        $pdo->commit();

        $_SESSION['flash'] = [
            'msg' => "Deleted splice join #{$joinId}.",
            'ok'  => true,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = [
            'msg' => 'Delete failed: ' . $e->getMessage(),
            'ok'  => false,
        ];
    }

    header('Location: index.php?page=splices&fosc_id=' . $foscId);
    exit;
}

/* -------------------------------------------------------------------------
 * GET + page data
 * ---------------------------------------------------------------------- */

$foscId    = isset($_GET['fosc_id']) ? (int)$_GET['fosc_id'] : 0;
$spliceNum = trim($_GET['splice_num'] ?? '');

if ($foscId === 0 && $spliceNum !== '') {
    $maybe = fetch_fosc_id_by_splice($pdo, $spliceNum);
    if ($maybe) {
        header('Location: /index.php?page=splices&fosc_id=' . $maybe);
        exit;
    }
}


/* splice chart??? */

// Build chart data once so both content + JS can see it
$actualChart = null;
$planChart   = null;
if ($foscId) {
    // actual splices
    $actualChart = render_fosc_splice_chart(
        $pdo,
        $foscId,
        false,                        // $isPlan = false
        'fosc-' . $foscId . '-actual' // namespace
    );

    // planned splices (if you want a plan chart)
    $planChart = render_fosc_splice_chart(
        $pdo,
        $foscId,
        true,                         // $isPlan = true
        'fosc-' . $foscId . '-plan'
    );
}
if ($foscId) {
    $PAGE->setInlineJsRenderer(function () use ($actualChart, $planChart) {
        $bits = [];

        if (!empty($actualChart['js'])) {
            $bits[] = $actualChart['js'];
        }
        if (!empty($planChart['js'])) {
            $bits[] = $planChart['js'];
        }

        return implode("\n\n", $bits);
    });
}



$fosc          = $foscId ? fetch_fosc_by_id($pdo, $foscId) : null;
$spanEndpoints = $foscId ? fetch_span_endpoints_for_fosc($pdo, $foscId) : [];
$splicesHere   = $foscId ? fetch_splices_at_fosc($pdo, $foscId) : [];

$pageTitle = $fosc ? ('Splices @ ' . $fosc['splice_num']) : 'Splices';
$PAGE->setTitle($pageTitle);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Normalise for closure capture
$viewFosc          = $fosc;
$viewSpanEndpoints = $spanEndpoints;
$viewSplicesHere   = $splicesHere;
$viewFlash         = $flash;
$viewSpliceNum     = $spliceNum;
$viewFoscId        = $foscId;

$PAGE->setContentRenderer(function () use (
    $pdo,
    $pageTitle,
    $viewFoscId,
    $viewFosc,
    $viewSpanEndpoints,
    $viewSplicesHere,
    $viewFlash,
    $viewSpliceNum,
    $CSRF,
    $actualChart,
    $planChart
) {
    $fosc          = $viewFosc;
    $spanEndpoints = $viewSpanEndpoints;
    $splicesHere   = $viewSplicesHere;
    $flash         = $viewFlash;
    $spliceNum     = $viewSpliceNum;
    $foscId        = $viewFoscId;
    $joinKind =null;
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0"><?= h($pageTitle) ?></h3>
      <div class="d-flex gap-2">
        <?php if ($fosc): ?>
          <a class="btn btn-outline-secondary btn-sm" href="/index.php?page=closure&fosc_id=<?= (int)$foscId ?>">Back to Closure</a>
        <?php else: ?>
          <a class="btn btn-outline-secondary btn-sm" href="/index.php?page=closure">Closures</a>
        <?php endif; ?>
        <div class="btn-group">
          <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">Export plant</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header">Whole plant, with integrity checks</h6></li>
            <li><a class="dropdown-item" target="_blank" href="/splice_export.php">View JSON</a></li>
            <li><a class="dropdown-item" href="/splice_export.php?download=1">Download JSON</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/splice_export.php?compact=1&download=1">Download compact (for AI review)</a></li>
          </ul>
        </div>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <form class="row g-3 mb-4" method="get">
      <input type="hidden" name="page" value="splices">
      <div class="col-md-6">
        <label class="form-label">Go to closure by Splice #</label>
        <div class="input-group">
          <input class="form-control" type="text" name="splice_num" value="<?= h($spliceNum) ?>" placeholder="ABC1234">
          <button class="btn btn-outline-primary" type="submit">Open</button>
        </div>
      </div>
    </form>

    <?php if (!$foscId): ?>
      <div class="alert alert-info">Select a closure to view/add splices.</div>
    <?php else: ?>

      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-light">
          <strong>Add Splice at <?= h($fosc['splice_num']) ?></strong>
        </div>
        <div class="card-body">
          <?php if (!$spanEndpoints): ?>
            <div class="alert alert-warning mb-0">No cable span endpoints at this closure yet. Create cable spans first.</div>
          <?php else: ?>
            <form method="post" class="row g-3">
              <input type="hidden" name="action" value="add_splice">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">

              <div class="mb-3">
                <label class="form-label">Layer</label><br>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="is_plan" id="layerActual" value="0" checked>
                  <label class="form-check-label" for="layerActual">Actual</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="is_plan" id="layerPlan" value="1">
                  <label class="form-check-label" for="layerPlan">Plan</label>
                </div>
              </div>

              <div class="col-md-6">
  <label class="form-label">A endpoint</label>
  <select class="form-select" name="a_endpoint" required>
    <option value="">Select…</option>
    <?= render_span_endpoint_options(
          $spanEndpoints,
          $selectedAEndpoint ?? null   // optional: preserve selection
        ) ?>
  </select>
</div>
              <div class="col-md-6">
                <label class="form-label">A fibers (lo–hi)</label>
                <div class="input-group">
                  <input class="form-control" type="number" name="a_fiber_lo" min="1" step="1" required>
                  <span class="input-group-text">to</span>
                  <input class="form-control" type="number" name="a_fiber_hi" min="1" step="1" required>
                </div>
              </div>

           <div class="col-md-6">
  <label class="form-label">B endpoint</label>
  <select class="form-select" name="b_endpoint" required>
    <option value="">Select…</option>
    <?= render_span_endpoint_options(
          $spanEndpoints,
          $selectedBEndpoint ?? null
        ) ?>
  </select>
</div>
              <div class="col-md-6">
                <label class="form-label">B fibers (lo–hi)</label>
                <div class="input-group">
                  <input class="form-control" type="number" name="b_fiber_lo" min="1" step="1" required>
                  <span class="input-group-text">to</span>
                  <input class="form-control" type="number" name="b_fiber_hi" min="1" step="1" required>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Notes (optional)</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
              </div>
             <div class="col-md-4">
  <label class="form-label">Splice Type</label>
  <select name="join_kind" class="form-select">
    <option value="single" <?= $joinKind === 'single' ? 'selected' : '' ?>>
      Single fusion
    </option>
    <option value="ribbon" <?= $joinKind === 'ribbon' ? 'selected' : '' ?>>
      Ribbon
    </option>
    <option value="pass_through" <?= $joinKind === 'pass_through' ? 'selected' : '' ?>>
      Pass-through (midspan)
    </option>
  </select>
  <div class="form-text">
    Single = normal fiber-by-fiber splice, Ribbon = ribbon mass fusion,
    Pass-through = midspan (no cut, just continuity).
  </div>
</div>

              <div class="col-12">
                <button class="btn btn-primary" type="submit">Add Splice</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <h4 class="mb-3">Splices at this Closure</h4>

<?php if ($foscId): ?>
      <div class="card mb-3">
        <div class="card-header">Splice Chart (actual)</div>
        <div class="card-body">
          <?php if (!empty($actualChart['html'])): ?>
            <?= $actualChart['html'] ?>
          <?php else: ?>
            <p class="text-muted mb-0">No actual splices to display.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($foscId): ?>
      <div class="card mb-3">
        <div class="card-header">Splice Plan</div>
        <div class="card-body">
          <?php if (!empty($planChart['html'])): ?>
            <?= $planChart['html'] ?>
          <?php else: ?>
            <p class="text-muted mb-0">No planned splices to display.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
      
      <?php
        echo '<h5>Splicing Plan</h5><pre>' . h(generate_splicing_plan($pdo, $foscId, true)) . '</pre>';
        echo '<h5>As-Built</h5><pre>' . h(generate_splicing_plan($pdo, $foscId, false)) . '</pre>';

        // Actual (as-built)

        render_fosc_splice_chart($pdo, $foscId, false, null, 'Actual');

        // Plan (proposed)
        render_fosc_splice_chart($pdo, $foscId, true,  null, 'Plan');   
   
        $splicesHere = fetch_splices_at_fosc($pdo, $foscId);
      ?>

      <?php if (!$splicesHere): ?>
        <div class="alert alert-secondary mt-3">No splices recorded here.</div>
      <?php else: ?>


  

        <div class="d-flex flex-column gap-3 mt-3">
          <?php foreach ($splicesHere as $sj): ?>

<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center py-2 px-3">
    <div>
      <div class="fw-semibold">
        Join #<?= (int)$sj['splice_join_id'] ?> – Fibers <?= h($sj['a_fiber_lo']) ?>–<?= h($sj['a_fiber_hi']) ?>
        <?php if ($sj['a_fiber_lo'] != $sj['a_fiber_hi']): ?>
          (<?= (int)$sj['a_fiber_hi'] - (int)$sj['a_fiber_lo'] + 1 ?> fibres)
        <?php endif; ?>
      </div>
      <div class="small text-muted">
        From span #<?= (int)$sj['a_span_id'] ?> to span #<?= (int)$sj['b_span_id'] ?>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2">
      <?php if (!empty($sj['notes'])): ?>
        <div class="small text-muted"><?= h($sj['notes']) ?></div>
      <?php endif; ?>

      <form method="post"
            action="index.php?page=splices&fosc_id=<?= (int)$foscId ?>"
            class="mb-0"
            onsubmit="return confirm('Delete this splice join?');">
        <input type="hidden" name="action" value="delete_join">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
        <input type="hidden" name="join_id" value="<?= (int)$sj['splice_join_id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">
          Delete
        </button>
      </form>
    </div>
  </div>
  <!-- existing card body stays the same -->
            
            <div class="card shadow-sm">
              <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div>
                  <strong>Splice #<?= (int)$sj['splice_join_id'] ?></strong>
                  <span class="text-muted ms-2">at <?= h($fosc['splice_num']) ?></span>
                </div>
                <?php if (!empty($sj['notes'])): ?>
                  <span class="text-muted small"><?= nl2br(h($sj['notes'])) ?></span>
                <?php endif; ?>
              </div>

              <div class="card-body">
                <div class="row g-4 align-items-start">

                  
                  

                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    <?php
    return ob_get_clean();
});
