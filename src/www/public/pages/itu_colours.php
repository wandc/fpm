<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/itu_colours.php — 144-fiber ITU colour chart (12 tubes × 12 fibers)
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('ITU Colour Chart');

// Standard TIA-598-C / ITU-T fiber colour sequence
$COLOURS = [
    1  => ['name' => 'Blue',   'hex' => '#1565C0', 'dark' => false],
    2  => ['name' => 'Orange', 'hex' => '#E65100', 'dark' => false],
    3  => ['name' => 'Green',  'hex' => '#2E7D32', 'dark' => false],
    4  => ['name' => 'Brown',  'hex' => '#4E342E', 'dark' => false],
    5  => ['name' => 'Slate',  'hex' => '#546E7A', 'dark' => false],
    6  => ['name' => 'White',  'hex' => '#FFFFFF',  'dark' => true ],
    7  => ['name' => 'Red',    'hex' => '#C62828', 'dark' => false],
    8  => ['name' => 'Black',  'hex' => '#212121', 'dark' => false],
    9  => ['name' => 'Yellow', 'hex' => '#F9A825', 'dark' => true ],
    10 => ['name' => 'Violet', 'hex' => '#6A1B9A', 'dark' => false],
    11 => ['name' => 'Rose',   'hex' => '#E91E8C', 'dark' => false],
    12 => ['name' => 'Aqua',   'hex' => '#00838F', 'dark' => false],
];

// Chinese fibre-optic cable type codes (GB/T 7424 naming), transcribed from
// Zion Communication's naming-rules reference — see the credit rendered below
// the table.  Kept as data rather than markup so a row can be corrected without
// touching layout.  Each entry is [model, description, application] and the
// application is what gets emphasised, because that is the column anyone
// actually scans when identifying a reel.
$CABLE_MODELS = [
    ['GYXTY',      'Central loose tube, metal strength member, PE sheath. Used for',        'aerial installation'],
    ['GYXTW',      'Central tube, steel wire reinforcement, steel-PE bonded sheath. Used in', 'overhead and duct'],
    ['GYTA',       'Loose tube stranding, fully filled, Al-PE bonded sheath. Suitable for',  'aerial or duct installation'],
    ['GYTS',       'Same as GYTA but with steel-PE bonded sheath, offering',                 'better crush resistance'],
    ['GYTA53',     'GYTA + corrugated steel armor + PE jacket. Ideal for',                   'direct buried applications'],
    ['GYTY53',     'Similar to GYTA53 but with PE sheath. Used for',                         'underground installations'],
    ['GYFTY',      'Non-metallic cable, safe from electric interference. Used in',           'high-voltage induction areas'],
    ['GYFTY03/05', 'Lightweight, unarmored, non-metallic. Suitable for',                     'overhead or duct in sensitive environments'],
    ['GYFTCY',     'Metal-free, self-supporting design. Ideal for',                          'tower suspension'],
    ['GYTC8Y',     'Figure-8 self-supporting cable. Common for',                             'pole-to-pole aerial deployment'],
    ['GYDTA / GYDTY / GYDTY53', 'Ribbon fiber cables, varying armor levels, for',            'FTTx access networks'],
    ['GYTZX',      'Flame-retardant, steel-flame retardant sheath. Suitable for',            'fire-prone or indoor/outdoor transitions'],
    ['CYTA04 / GYTY54', 'Anti-ant, nylon outer sheath, armored or unarmored. Ideal for',     'underground installations in tropical areas'],
    ['GJFJV',      'Indoor tight-buffered fiber. Used in',                                   'pigtails, patch cords, or equipment termination'],
    ['GJFDBZY',    'Flame-retardant tight-buffered fiber. Ideal for',                        'indoor jumper/pigtail applications'],
];

const CABLE_MODELS_SOURCE_URL = 'https://www.zion-communication.com/China-Optical-Fiber-Cable-Naming-Rules-Fiber-Cable-Code-System-GYTS-GYTA-GYXTW-id41274896.html';

$PAGE->setContentRenderer(function () use ($COLOURS, $CABLE_MODELS) {
    ob_start();
?>
<style>
/* ── Screen styles ─────────────────────────────────────────────────────────── */
.itu-wrap { max-width: 960px; }

.itu-table {
  border-collapse: collapse;
  width: 100%;
  font-size: .78rem;
  table-layout: fixed;
}
.itu-table th,
.itu-table td {
  border: 1px solid #b0b0b0;
  padding: 0;
  text-align: center;
  vertical-align: middle;
}
.itu-row-white td {
  border-left: 2px solid #999 !important;
  border-right: 2px solid #999 !important;
}

/* ── Cable model reference ──────────────────────────────────────────────── */
.cable-models { margin-top: 2.5rem; }
.cable-models-table { font-size: .85rem; }
.cable-models-table th { font-weight: 600; }
.cable-model-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-weight: 600;
  white-space: nowrap;
}

/* Force background colours to print */
.itu-col-hdr,
.itu-row-hdr,
.itu-cell,
.itu-fiber-dot {
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

/* Column header cells (fiber position 1-12) */
.itu-col-hdr {
  height: 52px;
  width: 66px;
  font-size: .7rem;
  font-weight: 600;
  color: #fff;
  letter-spacing: .02em;
}
.itu-col-hdr span { display: block; font-size: .65rem; font-weight: 400; opacity: .85; margin-top: 2px; }

/* Row header cells (tube 1-12) */
.itu-row-hdr {
  width: 72px;
  height: 44px;
  font-size: .7rem;
  font-weight: 600;
  color: #fff;
  letter-spacing: .02em;
  white-space: nowrap;
}
.itu-row-hdr .tube-no { display: block; font-size: .62rem; font-weight: 400; opacity: .8; }

/* Data cells */
.itu-cell {
  height: 56px;
  position: relative;
  cursor: default;
}
.itu-cell-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  padding: 4px;
}
.itu-fiber-dot {
  width: 38px; height: 38px;
  border-radius: 50%;
  border: 1px solid rgba(0,0,0,.2);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .72rem;
  font-weight: 700;
  line-height: 1;
}
/* Corner cell */
.itu-corner {
  background: #f8f9fa;
  font-size: .65rem;
  color: #6c757d;
  font-weight: 600;
  text-align: center;
  padding: 4px;
}


/* ── Print window styles (injected into popup) ─────────────────────────────── */
/* (screen-only — actual print is done via a separate popup window) */
</style>

<div id="itu-print-root">
<div class="itu-wrap mx-auto">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2 no-print">
    <h4 class="mb-0">144-Fiber ITU Colour Chart</h4>
    <button class="btn btn-outline-secondary btn-sm" onclick="ituPrint()">
      🖨 Print
    </button>
  </div>

  <p class="text-muted small mb-3 no-print">
    TIA-598-C / ITU-T standard colour coding. 12 buffer tubes × 12 fibers = 144 fibers.
    Fiber number = (tube − 1) × 12 + position.
  </p>

  <!-- Print heading (hidden on screen) -->
  <div class="text-center mb-3" style="display:none" id="itu-print-title">
    <strong style="font-size:1rem">144-Fiber ITU Colour Code — TIA-598-C</strong><br>
    <span style="font-size:.8rem;color:#555">12 Buffer Tubes × 12 Fibers</span>
  </div>

  <div class="table-responsive">
    <table class="itu-table">
      <thead>
        <tr>
          <!-- Corner -->
          <th class="itu-corner">Tube ↓<br>Fiber →</th>
          <!-- Column headers: fiber position 1–12 -->
          <?php for ($f = 1; $f <= 12; $f++):
            $c = $COLOURS[$f];
            $txt = $c['dark'] ? '#333' : '#fff';
          ?>
          <th class="itu-col-hdr" style="background:<?= $c['hex'] ?>;color:<?= $txt ?>">
            <?= h($c['name']) ?><span>F<?= $f ?></span>
          </th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php for ($t = 1; $t <= 12; $t++):
          $tc = $COLOURS[$t];
          $tubeTxt = $tc['dark'] ? '#333' : '#fff';
          $firstFiber = ($t - 1) * 12 + 1;
          $lastFiber  = $t * 12;
        ?>
        <tr<?= $tc['name'] === 'White' ? ' class="itu-row-white"' : '' ?>>
          <!-- Row header: tube colour -->
          <th class="itu-row-hdr" style="background:<?= $tc['hex'] ?>;color:<?= $tubeTxt ?>">
            <?= h($tc['name']) ?>
            <span class="tube-no">Tube <?= $t ?> (<?= $firstFiber ?>–<?= $lastFiber ?>)</span>
          </th>
          <?php for ($f = 1; $f <= 12; $f++):
            $fc     = $COLOURS[$f];
            $fiberNo = ($t - 1) * 12 + $f;
            // Cell background: light tint of tube color, fiber dot shows fiber color
            // We'll use a very light version of tube color as bg, with full fiber dot
            $tcRgb = sscanf($tc['hex'], '#%02x%02x%02x');
            $bgR = (int)round($tcRgb[0] * 0.12 + 255 * 0.88);
            $bgG = (int)round($tcRgb[1] * 0.12 + 255 * 0.88);
            $bgB = (int)round($tcRgb[2] * 0.12 + 255 * 0.88);
            $cellBg = sprintf('#%02x%02x%02x', $bgR, $bgG, $bgB);
            $numColor = '#1a1a1a';
          ?>
          <td class="itu-cell" style="background:<?= $cellBg ?>;border-left:3px solid <?= $tc['hex'] ?>">
            <div class="itu-cell-inner">
              <span class="itu-fiber-dot" style="background:<?= $fc['hex'] ?>;color:<?= $fc['dark'] ? '#333' : '#fff' ?>;<?= $fc['name'] === 'White' ? 'border:2px solid #888;' : 'border-color:rgba(0,0,0,.15);' ?>"><?= $fiberNo ?></span>
            </div>
          </td>
          <?php endfor; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>


  <!-- ── Common cable models ────────────────────────────────────────────────
       Deliberately after the colour chart: ituPrint() copies the first table in
       .itu-wrap, so the printed chart stays exactly what it was. -->
  <div class="cable-models no-print">
    <h5 class="mb-1">Common Cable Models and Their Applications</h5>
    <p class="text-muted small mb-3">
      Chinese cable type codes (GB/T 7424) as seen on imported reels and datasheets.
      Read the code left to right: <code>GY</code> outdoor communication cable,
      then structure, then sheath and armour.
    </p>

    <div class="table-responsive">
      <table class="table table-sm cable-models-table mb-2">
        <thead>
          <tr><th style="width:190px">Model</th><th>Description &amp; applications</th></tr>
        </thead>
        <tbody>
        <?php foreach ($CABLE_MODELS as [$model, $description, $application]): ?>
          <tr>
            <td class="cable-model-code"><?= h($model) ?></td>
            <td><?= h($description) ?> <strong><?= h($application) ?></strong>.</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="text-muted small mb-0">
      Source:
      <a href="<?= h(CABLE_MODELS_SOURCE_URL) ?>" target="_blank" rel="noopener noreferrer">
        China Optical Fiber Cable Naming Rules — Zion Communication</a>.
      Reproduced for reference; verify against the manufacturer's datasheet before ordering.
    </p>
  </div>

</div><!-- /itu-wrap -->
</div><!-- /itu-print-root -->

<script>
function ituPrint() {
  var w = window.open('', '_blank', 'width=1100,height=700');
  w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">');
  w.document.write('<title>144-Fiber ITU Colour Chart</title>');
  w.document.write('<style>');
  w.document.write(
    'body{margin:10mm;font-family:sans-serif;}' +
    '.itu-table{border-collapse:collapse;width:100%;font-size:.62rem;table-layout:fixed;}' +
    '.itu-table th,.itu-table td{border:1px solid #b0b0b0;padding:0;text-align:center;vertical-align:middle;}' +
    '.itu-row-white td{border-left:2px solid #999 !important;border-right:2px solid #999 !important;}' +
    '.itu-col-hdr{height:40px;font-size:.58rem;font-weight:600;letter-spacing:.02em;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    '.itu-col-hdr span{display:block;font-size:.55rem;font-weight:400;opacity:.85;margin-top:2px;}' +
    '.itu-row-hdr{height:36px;font-size:.58rem;font-weight:600;letter-spacing:.02em;white-space:nowrap;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    '.itu-row-hdr .tube-no{display:block;font-size:.52rem;font-weight:400;opacity:.8;}' +
    '.itu-cell{height:46px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    '.itu-cell-inner{display:flex;align-items:center;justify-content:center;height:100%;padding:4px;}' +
    '.itu-fiber-dot{width:30px;height:30px;border-radius:50%;border:1px solid rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    '.itu-corner{background:#f8f9fa;font-size:.58rem;color:#6c757d;font-weight:600;text-align:center;padding:4px;}' +
    '@page{size:landscape;margin:10mm;}'
  );
  w.document.write('</style></head><body>');
  w.document.write('<div style="text-align:center;margin-bottom:6mm"><strong style="font-size:1rem">144-Fiber ITU Colour Code — TIA-598-C</strong><br><span style="font-size:.8rem;color:#555">12 Buffer Tubes × 12 Fibers</span></div>');
  w.document.write(document.querySelector('.itu-wrap table').outerHTML);
  w.document.write('</body></html>');
  w.document.close();
  w.focus();
  w.addEventListener('afterprint', function() { w.close(); });
  w.print();
}
</script>

<?php
    return ob_get_clean();
});
