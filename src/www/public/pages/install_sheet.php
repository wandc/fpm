<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/install_sheet.php — printable aerial install sheet for a Tinuum work order.
// One row per pole in feed order (feed end first): span and running length, attachment,
// Bell make-ready notes (tree trimming, down guys, dead-ends), anchors existing vs new,
// closures and slack loops, and a done box per job. Same sheet for strand and fibre.

use FPM\App\Template;

if (!isset($_SESSION)) session_start();

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

require_once __DIR__ . '/../phpHelpers/install_sheet.helper.php';

/** @var PDO $pdo */
$permitList = $pdo->query("SELECT permit_num FROM app.fpm_permit ORDER BY permit_num")->fetchAll(PDO::FETCH_COLUMN);
require_once __DIR__ . '/../phpHelpers/abandoned.helper.php';
$abandonedWos = abandoned_work_orders($pdo);   // still listed, labelled
$selPermit  = isset($_GET['permit']) ? (string)$_GET['permit'] : '';
$startFid   = isset($_GET['start']) && ctype_digit((string)$_GET['start']) ? (int)$_GET['start'] : null;
$model = ($selPermit !== '' && in_array($selPermit, $permitList, true))
         ? install_sheet_build($pdo, $selPermit, $startFid) : null;

function is_owner_mark(string $owner): string
{
    $o = strtolower($owner);
    if (str_starts_with($o, 'bell'))     return '<img class="is-own" src="/images/owners/bell.ico" alt="Bell" title="Bell pole">';
    if (str_starts_with($o, 'nb power')) return '<img class="is-own" src="/images/owners/nbpower.png" alt="NB Power" title="NB Power pole">';
    if ($o === strtolower(org('own_name')))  return '<img class="is-own" src="' . h(org('own_mark')) . '" alt="' . h(org('own_name')) . '" title="' . h(org('own_name')) . ' pole">';
    if ($o === 'private')                return '<span class="is-own-unk" title="Private pole">&#127359;&#65039;</span>';
    if ($owner === '' || $owner === 'Unknown') return '<span class="is-own-unk" title="Owner unknown">?</span>';
    return '<span class="is-own-unk" title="' . h($owner) . '">' . h(mb_substr($owner, 0, 1)) . '</span>';
}

$render_css = function (): string {
    return '
.is-sheet { background:#fbfaf6; color:#16202b; padding:1rem 1.1rem 1.4rem; border:1px solid #c9ccc9; }
.is-sheet h1 { font-size:1.5rem; font-weight:700; margin:0; }
.is-head { display:flex; flex-wrap:wrap; gap:.6rem 2rem; justify-content:space-between; align-items:flex-end; border-bottom:3px solid #16202b; padding-bottom:.6rem; margin-bottom:.8rem; }
.is-sub { color:#5d6874; font-size:.85rem; }
.is-ids { display:grid; grid-template-columns:auto auto; gap:1px 12px; font-size:.78rem; }
.is-ids dt { color:#5d6874; text-transform:uppercase; letter-spacing:.06em; font-size:.65rem; align-self:center; margin:0; font-weight:400; }
.is-ids dd { margin:0; font-family:ui-monospace,monospace; font-weight:600; text-align:right; }
.is-fill { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:.6rem 1.2rem; margin-bottom:.8rem; }
.is-fill label { display:flex; flex-direction:column; font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6874; }
.is-fill input { font:500 .9rem ui-monospace,monospace; color:#16202b; background:transparent; border:0; border-bottom:1.5px solid #16202b; padding:3px 0; border-radius:0; min-width:0; }
.is-key { display:flex; flex-wrap:wrap; gap:.4rem 1rem; align-items:center; font-size:.75rem; color:#5d6874; margin-bottom:.8rem; }
.is-own { width:26px; height:26px; background:#fff; border:1.5px solid #16202b; padding:2px; display:inline-block; vertical-align:middle; }
.is-own-unk { display:inline-flex; width:26px; height:26px; align-items:center; justify-content:center; border:1.5px solid #16202b; font:700 .8rem ui-monospace,monospace; background:#eef0ec; }
.is-tag { display:inline-block; font:600 .66rem ui-monospace,monospace; letter-spacing:.03em; padding:1px 5px; border:1.5px solid currentColor; white-space:nowrap; }
.is-tag.tt  { color:#b4470a; background:#fdeede; }
.is-tag.de  { color:#16202b; background:#eef0ec; }
.is-tag.guy { color:#16202b; }
.is-tag.new { color:#fff; background:#0b5c4a; border-color:#0b5c4a; }
.is-tag.ex  { color:#16202b; background:#fbfaf6; border-style:dashed; }
.is-tag.clo { color:#fff; background:#16202b; border-color:#16202b; }
.is-tag.slk { color:#16202b; background:#eef0ec; border-style:double; border-width:3px; }
.is-table { width:100%; border-collapse:collapse; min-width:860px; }
.is-table th { text-align:left; font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:#5d6874; font-weight:600; padding:5px 7px; border-bottom:2px solid #16202b; }
.is-table td { padding:7px; vertical-align:top; border-bottom:1px solid #c9ccc9; }
.is-table tr { break-inside:avoid; }
.is-table tr.is-tt td:first-child { box-shadow:inset 4px 0 0 #b4470a; }
.is-seq { font:700 1.15rem ui-monospace,monospace; width:46px; }
.is-pole .num { font:600 .9rem ui-monospace,monospace; }
.is-pole .st { font-size:.72rem; color:#5d6874; }
.is-pole { display:grid; grid-template-columns:auto 1fr; gap:0 8px; align-items:center; }
.is-pole .own { grid-row:1 / span 2; }
.is-span { font:500 .85rem ui-monospace,monospace; white-space:nowrap; }
.is-span small { display:block; font-size:.66rem; color:#5d6874; }
.is-att b { font-size:.95rem; display:block; }
.is-anc { display:flex; flex-direction:column; gap:4px; min-width:150px; }
.is-anc .a { display:flex; flex-direction:column; gap:2px; font-size:.72rem; }
.is-anc .a.n { border-left:5px solid #0b5c4a; padding-left:6px; }
.is-anc .a.e { border-left:5px dashed #16202b; padding-left:6px; }
.is-anc .dir { font:600 .78rem ui-monospace,monospace; }
.is-none { color:#5d6874; font-size:.75rem; }
.is-tags { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:3px; }
.is-note { font-size:.74rem; color:#3b4652; }
.is-fosc { margin-top:5px; font-size:.78rem; display:flex; flex-wrap:wrap; gap:3px 8px; align-items:center; }
.is-fosc b { font-family:ui-monospace,monospace; }
.is-blank { display:inline-block; min-width:56px; border-bottom:1.5px solid #16202b; height:1em; }
.is-boxes { display:flex; gap:8px; }
.is-boxes span { display:flex; flex-direction:column; align-items:center; font-size:.6rem; text-transform:uppercase; letter-spacing:.05em; color:#5d6874; gap:2px; }
.is-boxes i { width:20px; height:20px; border:2px solid #16202b; display:block; }
.is-totals { display:flex; flex-wrap:wrap; gap:.4rem 1.6rem; border-top:3px solid #16202b; padding-top:.5rem; margin-top:.6rem; }
.is-totals div { display:flex; flex-direction:column; }
.is-totals b { font:600 1.1rem ui-monospace,monospace; }
.is-totals span { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6874; }
.is-notes { border:1px solid #c9ccc9; min-height:80px; padding:5px 8px; font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6874; margin-top:.8rem; }
.is-sign { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem 1.6rem; margin-top:.4rem; }
.is-sign div { border-bottom:1.5px solid #16202b; padding-top:26px; font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6874; }
.is-warn { font-size:.78rem; color:#92400e; background:#fffbeb; border:1px solid #fde68a; padding:.3rem .5rem; margin-bottom:.5rem; }
@page { size:letter portrait; margin:0.4in; }
@media print {
    .navbar, aside, nav[aria-label="breadcrumb"], .is-toolbar, .is-noprint { display:none !important; }
    body { margin:0; background:#fff; }
    .container-fluid, .col, [class*="col-"], main.app-main { width:100% !important; max-width:none !important; flex:0 0 100% !important; padding:0 !important; margin:0 !important; }
    .is-sheet { border:0; padding:0; font-size:11px; }
    .is-table { min-width:0; table-layout:fixed; }
    .is-table th:nth-child(1) { width:30px; } .is-table th:nth-child(2) { width:152px; } .is-table th:nth-child(3) { width:66px; }
    .is-table th:nth-child(4) { width:52px; } .is-table th:nth-child(5) { width:112px; } .is-table th:nth-child(7) { width:58px; }
    .is-table td:nth-child(6) { overflow-wrap:anywhere; }
    .is-table td { padding:4px 4px; }
    .is-pole .num { font-size:11px; overflow-wrap:normal; } .is-pole .st { font-size:9px; }
    .is-span { font-size:11px; white-space:normal; } .is-span small { white-space:normal !important; }
    .is-att b { font-size:11px; }
    .is-anc .is-tag { font-size:8px; white-space:normal; } .is-anc .dir { font-size:10px; }
    .is-seq { font-size:13px; width:auto; white-space:nowrap; }
    .is-anc { min-width:0; }
    .is-own, .is-own-unk { width:20px; height:20px; }
    .is-boxes { flex-wrap:wrap; gap:4px; }
    .is-boxes i { width:15px; height:15px; }
    .is-note { font-size:9px; }
    .is-key { font-size:9px; }
    .is-head { flex-wrap:nowrap; }
    .is-table thead { display:table-header-group; }
    .is-table-wrap { overflow:visible !important; }
}
';
};

$render_body = function () use ($permitList, $selPermit, $model, $startFid, $abandonedWos) {
    ob_start(); ?>
    <div class="container-fluid my-3" style="max-width:1180px">
      <div class="is-toolbar d-flex align-items-center gap-2 mb-3 py-2 flex-wrap">
        <h1 class="h5 mb-0 me-2">Install Sheet</h1>
        <form method="get" class="d-flex align-items-center gap-2 is-noprint flex-wrap">
          <input type="hidden" name="page" value="install_sheet">
          <select name="permit" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Choose a work order…</option>
            <?php foreach ($permitList as $pn): ?>
              <option value="<?= h($pn) ?>" <?= $pn === $selPermit ? 'selected' : '' ?>><?= h($pn) ?><?= isset($abandonedWos[$pn]) ? ' (abandoned)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($model && count($model['starts']) > 1): ?>
            <label class="small text-muted">Feed pole
              <select name="start" class="form-select form-select-sm d-inline-block" style="width:auto" onchange="this.form.submit()">
                <?php foreach ($model['starts'] as $fid => $lbl): ?>
                  <option value="<?= (int)$fid ?>" <?= $fid === $model['start_fid'] ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
        </form>
        <?php if ($model && $model['stops']): ?>
          <button type="button" class="btn btn-sm btn-primary is-noprint" onclick="window.print()">Print / Save PDF</button>
          <span class="small text-muted is-noprint">Letter, portrait. If the print dialog opens in landscape (Safari ignores the page setting), switch Orientation to Portrait.</span>
        <?php endif; ?>
      </div>

      <?php if (!$model): ?>
        <div class="alert alert-secondary">Choose a work order to build its install sheet. The sheet follows the messenger strand tagged to that work order, from the feed end.</div>
      <?php else:
        $stops = $model['stops'];
        $tt = $guys = $dend = $newA = $exA = $clo = $slk = 0;
        foreach ($stops as $s) {
            $tt += $s['tt'] ? 1 : 0; $guys += $s['guy'] ? 1 : 0; $dend += $s['dend'] ? 1 : 0;
            foreach ($s['anchors'] as $a) { if ($a['new']) $newA++; else $exA++; }
            foreach ($s['fosc'] as $f) { if ($f['kind'] === 'slack') $slk++; else $clo++; }
        }
        $poles = count(array_filter($stops, fn($s) => $s['kind'] === 'pole'));
        $runM = $stops ? end($stops)['run_m'] : 0;
        foreach ($model['elsewhere'] as $f) { if ($f['kind'] === 'slack') $slk++; else $clo++; }
      ?>
        <div class="is-sheet">
          <?php foreach ($model['warnings'] as $w): ?><div class="is-warn">⚠ <?= h($w) ?></div><?php endforeach; ?>
          <div class="is-head">
            <div>
              <h1>Aerial install sheet · <?= h($model['permit']) ?></h1>
              <div class="is-sub">Pole by pole from the feed end. Work from top to bottom. Poles are shown by NB Power number.</div>
            </div>
            <dl class="is-ids">
              <dt>Work order</dt><dd><?= h($model['permit']) ?></dd>
              <?php if ($model['bell_permits']): ?><dt>Bell permit</dt><dd><?= h(implode(', ', $model['bell_permits'])) ?></dd><?php endif; ?>
              <dt>Poles</dt><dd><?= (int)$poles ?></dd>
              <dt>Printed</dt><dd><?= h(date('Y-m-d')) ?></dd>
            </dl>
          </div>

          <div class="is-fill">
            <label for="isCrew">Crew<input id="isCrew" type="text" placeholder="Lead / truck"></label>
            <label for="isDate">Install date<input id="isDate" type="text" placeholder="YYYY-MM-DD"></label>
            <label for="isSize">Strand / cable size and type<input id="isSize" type="text"></label>
            <label for="isReel">Reel no.<input id="isReel" type="text"></label>
          </div>

          <div class="is-key">
            <b>Key</b>
            <span><span class="is-tag tt">TT</span> tree trimming first</span>
            <span><span class="is-tag de">D.END</span> dead-end here</span>
            <span><span class="is-tag guy">D.GUY + GUARD</span> down guy and guard</span>
            <span><span class="is-tag clo">CLOSURE</span> splice closure</span>
            <span><span class="is-tag slk">SLACK LOOP</span> coil spare, write the length</span>
            <span><span class="is-tag new">NEW</span> anchor to place</span>
            <span><span class="is-tag ex">EXISTING</span> anchor in the ground</span>
            <span><img class="is-own" src="/images/owners/bell.ico" alt=""> Bell pole</span>
            <span><img class="is-own" src="/images/owners/nbpower.png" alt=""> NB Power pole</span>
            <span><b>BFS</b> bottom field side</span>
            <span><b>TFS</b> top field side</span>
          </div>

          <?php if (!$stops): ?>
            <div class="alert alert-secondary mb-0">Nothing to show for this work order.</div>
          <?php else: ?>
          <div class="is-table-wrap" style="overflow-x:auto">
            <table class="is-table">
              <thead><tr><th>#</th><th>Pole</th><th>Span in</th><th>Attach</th><th>Anchors</th><th>Work at this pole</th><th>Done</th></tr></thead>
              <tbody>
              <?php foreach ($stops as $i => $s):
                  $isPole = $s['kind'] === 'pole'; ?>
                <tr class="<?= $s['tt'] ? 'is-tt' : '' ?>">
                  <td class="is-seq"><?= $i + 1 ?></td>
                  <td><div class="is-pole">
                    <span class="own"><?= $isPole ? is_owner_mark($s['owner']) : '' ?></span>
                    <span class="num"><?= h($s['label']) ?></span>
                    <span class="st"><?= h($s['street']) ?></span>
                  </div></td>
                  <td class="is-span">
                    <?php if ($i === 0): ?>Start<small>0 m run</small>
                    <?php else: ?><?= number_format($s['span_m']) ?> m<small>from #<?= $i ?> · <?= number_format($s['run_m']) ?> m run</small><?php endif; ?>
                  </td>
                  <td class="is-att"><b><?= h($isPole ? ($s['attach'] ?: '—') : 'Anchor') ?></b></td>
                  <td><div class="is-anc">
                    <?php foreach ($s['anchors'] as $a): ?>
                      <div class="a <?= $a['new'] ? 'n' : 'e' ?>">
                        <span class="is-tag <?= $a['new'] ? 'new' : 'ex' ?>"><?= $a['new'] ? 'NEW ANCHOR' : 'EXISTING ANCHOR' ?></span>
                        <?php if ($a['dir'] !== ''): ?><span class="dir">Pulls away <?= h($a['dir']) ?></span><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                    <?php if (!$s['anchors']): ?><span class="is-none">None</span><?php endif; ?>
                  </div></td>
                  <td>
                    <div class="is-tags">
                      <?php if ($s['tt']): ?><span class="is-tag tt">TT</span><?php endif; ?>
                      <?php if ($s['dend']): ?><span class="is-tag de">D.END</span><?php endif; ?>
                      <?php if ($s['guy']): ?><span class="is-tag guy">D.GUY + GUARD</span><?php endif; ?>
                      <?php foreach ($s['fosc'] as $f): ?>
                        <span class="is-tag <?= $f['kind'] === 'slack' ? 'slk' : 'clo' ?>"><?= $f['kind'] === 'slack' ? 'SLACK LOOP' : 'CLOSURE' ?></span>
                      <?php endforeach; ?>
                    </div>
                    <?php if ($s['note'] !== ''): ?><div class="is-note"><?= h($s['note']) ?></div><?php endif; ?>
                    <?php foreach ($s['fosc'] as $f): ?>
                      <div class="is-fosc"><b><?= h($f['splice_num']) ?></b>
                        <span><?= $f['kind'] === 'slack' ? 'Slack loop' : 'Closure' ?>, about <?= number_format($f['dist_m']) ?> m from this pole.</span>
                        <?php if ($f['kind'] === 'slack'): ?>
                          <span>Length left:</span><?php if ($f['slack_m'] !== null): ?><b><?= h(rtrim(rtrim((string)$f['slack_m'], '0'), '.')) ?> m planned</b><?php else: ?><span class="is-blank"></span><?php endif; ?><span>m</span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </td>
                  <td><div class="is-boxes">
                    <?php if ($s['tt']): ?><span><i></i>Trim</span><?php endif; ?>
                    <?php if ($s['guy']): ?><span><i></i>Guy</span><?php endif; ?>
                    <?php foreach ($s['fosc'] as $f): ?><span><i></i><?= $f['kind'] === 'slack' ? 'Slack' : 'Closure' ?></span><?php endforeach; ?>
                    <span><i></i>Install</span>
                  </div></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($model['elsewhere']): ?>
            <div class="mt-3">
              <b class="small text-uppercase">Closures and slack loops not at a pole on this run</b>
              <?php foreach ($model['elsewhere'] as $f): ?>
                <div class="is-fosc"><span class="is-tag <?= $f['kind'] === 'slack' ? 'slk' : 'clo' ?>"><?= $f['kind'] === 'slack' ? 'SLACK LOOP' : 'CLOSURE' ?></span>
                  <b><?= h($f['splice_num']) ?></b>
                  <span>About <?= number_format($f['dist_m']) ?> m from <?= h($f['near']) ?>. Confirm the spot with the lead.</span>
                  <?php if ($f['kind'] === 'slack'): ?><span>Length left:</span><span class="is-blank"></span><span>m</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="is-totals">
            <div><b><?= (int)$poles ?></b><span>Poles</span></div>
            <div><b><?= number_format($runM) ?> m</b><span>Run, straight line</span></div>
            <div><b><?= (int)$tt ?></b><span>Poles to trim</span></div>
            <div><b><?= (int)$guys ?></b><span>Down guys + guards</span></div>
            <div><b><?= (int)$exA ?></b><span>Existing anchors</span></div>
            <div><b><?= (int)$newA ?></b><span>New anchors</span></div>
            <div><b><?= (int)$clo ?></b><span>Closures</span></div>
            <div><b><?= (int)$slk ?></b><span>Slack loops</span></div>
            <div><b><?= (int)$dend ?></b><span>Dead-ends</span></div>
          </div>
          <div class="is-notes">Crew notes / changes from plan</div>
          <div class="is-sign"><div>Crew lead</div><div>Date</div><div>Checked by (Tinuum)</div></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
};

if (isset($PAGE) && $PAGE instanceof Template) {
    $PAGE->setTitle('Install Sheet');
    $PAGE->setInlineCssRenderer($render_css);
    $PAGE->setContentRenderer($render_body);
} else {
    ?><!doctype html><html><head><meta charset="utf-8">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <style><?= $render_css() ?></style></head><body><?= $render_body() ?></body></html><?php
}
