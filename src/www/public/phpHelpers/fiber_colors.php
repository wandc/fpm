<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// includes/fiber_colors.php
// Utilities to colorize fiber numbers by TIA/EIA 12-fiber sequence
declare(strict_types=1);

// TIA/EIA 12-color sequence (name, hex)
const FIBER_COLORS_12 = [
  1  => ['Blue',   '#0072CE'],
  2  => ['Orange', '#F58220'],
  3  => ['Green',  '#009A44'],
  4  => ['Brown',  '#7C4A21'],
  5  => ['Slate',  '#6E7E8C'],
  6  => ['White',  '#FFFFFF'],
  7  => ['Red',    '#DA291C'],
  8  => ['Black',  '#000000'],
  9  => ['Yellow', '#FFD100'],
  10 => ['Violet', '#582C83'],
  11 => ['Rose',   '#E4007C'],
  12 => ['Aqua',   '#00B5E2'],
];

/**
 * Return [name, hex] for a 1-based fiber number (wraps every 12).
 */
function fiber_color_name(int $n): array {
  if ($n <= 0) return ['Unknown', '#CCCCCC'];
  $idx = (($n - 1) % 12) + 1;
  return FIBER_COLORS_12[$idx] ?? ['Unknown', '#CCCCCC'];
}

/**
 * Simple luminance calc to pick readable text color for a given background.
 */
function _luma(string $hex): float {
  $hex = ltrim($hex, '#');
  if (strlen($hex) === 3) { // short form rgb
    $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  }
  $r = hexdec(substr($hex, 0, 2)) / 255;
  $g = hexdec(substr($hex, 2, 2)) / 255;
  $b = hexdec(substr($hex, 4, 2)) / 255;
  // WCAG relative luminance
  foreach (['r'=>&$r,'g'=>&$g,'b'=>&$b] as &$c) {
    $c = ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
  }
  return 0.2126*$r + 0.7152*$g + 0.0722*$b;
}

/**
 * Render a single “chip” for a fiber number.
 */
function render_fiber_chip(int $n): string {
  [$name, $hex] = fiber_color_name($n);
  $fg = (_luma($hex) > 0.5) ? '#000000' : '#FFFFFF';
  $title = "Fiber $n • $name";
  return '<span class="fiber-chip"'
       .' style="background:'.$hex.';color:'.$fg.';"'
       .' title="'.htmlspecialchars($title, ENT_QUOTES).'">'
       . (string)$n
       .'</span>';
}

/**
 * Render chips for a range. If the range is large, show a compact badge instead.
 * @return string HTML
 */
function render_range_chips(int $lo, int $hi, int $maxChips = 48): string {
  if ($hi < $lo) { [$lo,$hi] = [$hi,$lo]; }
  $count = $hi - $lo + 1;

  if ($count <= 0) return '';

  if ($count > $maxChips) {
    $label = $lo.'–'.$hi.' ('.$count.')';
    return '<span class="badge rounded-pill text-bg-secondary">'.$label.'</span>';
  }

  $out = [];
  for ($i = $lo; $i <= $hi; $i++) {
    $out[] = render_fiber_chip($i);
  }
  // IMPORTANT: return a string, not an array (prevents “Array to string conversion”)
  return implode('', $out);
}

/**
 * Optional: small CSS you can include once in your layout.
 * Call fiber_colors_emit_css() once per page (idempotent).
 */
function fiber_colors_emit_css(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  echo <<<CSS
<style>
.fiber-chip{
  display:inline-block;
  min-width: 1.6em;
  padding: 0.15rem 0.35rem;
  margin: 0 0.15rem 0.15rem 0;
  text-align:center;
  font-weight:600;
  font-size:.85rem;
  border-radius:.5rem;
  box-shadow: 0 1px 2px rgba(0,0,0,.15);
}
</style>
CSS;
}

// --- pretty “ribbon” renderer that wraps every N fibers (default 12) ---
  /**
   * Render a horizontal ribbon of fiber chips from $lo..$hi, wrapping every $wrap fibers.
   * Example: render_fiber_ribbon(1, 48) -> 4 packs of 12 with pack labels.
   */
  function render_fiber_ribbon(int $lo, int $hi, int $wrap = 12, bool $showPackLabels = true): string {
    if ($hi < $lo) { [$lo,$hi] = [$hi,$lo]; }
    if ($wrap < 1) $wrap = 12;

    $html = '<div class="fiber-ribbon">';
    $packStart = $lo - (($lo - 1) % $wrap); // align pack label to multiples of $wrap

    for ($n = $lo; $n <= $hi; $n++) {
      // New pack row when starting a multiple of $wrap OR when it's the very first chip
      if ((($n - 1) % $wrap) === 0) {
        if ($n !== $lo) $html .= '</div>'; // close previous pack row
        $label = $showPackLabels ? ('<div class="fiber-pack-label">'.(int)$packStart.'–'.(int)($packStart+$wrap-1).'</div>') : '';
        $html .= '<div class="fiber-pack">'.$label;
        $packStart = $n + ($wrap - (($n - 1) % $wrap)) - $wrap; // next label baseline
      }
      $html .= render_fiber_chip($n);
    }
    $html .= '</div></div>'; // close last pack + ribbon
    return $html;
  }

  function fiber_colors_tube_palette(): array {
    // Use high-contrast colors for web display; feel free to tweak hex values
    return [
      0 => ['name'=>'Blue',   'hex'=>'#1976d2'],
      1 => ['name'=>'Orange', 'hex'=>'#f57c00'],
      2 => ['name'=>'Green',  'hex'=>'#388e3c'],
      3 => ['name'=>'Brown',  'hex'=>'#6d4c41'],
      4 => ['name'=>'Slate',  'hex'=>'#607d8b'],
      5 => ['name'=>'White',  'hex'=>'#ffffff', 'fg'=>'#111'],
      6 => ['name'=>'Red',    'hex'=>'#d32f2f'],
      7 => ['name'=>'Black',  'hex'=>'#000000', 'fg'=>'#fff'],
      8 => ['name'=>'Yellow', 'hex'=>'#fbc02d', 'fg'=>'#111'],
      9 => ['name'=>'Violet', 'hex'=>'#7b1fa2'],
      10=> ['name'=>'Rose',   'hex'=>'#e91e63'],
      11=> ['name'=>'Aqua',   'hex'=>'#00acc1'],
    ];
  }

/**
 * Render buffer tubes that intersect a fiber range.
 * - $lo..$hi : fiber numbers (1-based, inclusive)
 * - $tube_size : fibers per tube (default 12)
 * - $total_fibers : optional total fiber count for the cable (lets us show full set)
 *   If null, only tubes intersecting the range are shown.
 */
function render_buffer_tubes(int $lo, int $hi, int $tube_size = 12, ?int $total_fibers = null): string {
    if ($lo <= 0 || $hi < $lo) return '';
    $palette = fiber_colors_tube_palette();

    // Which tubes are covered by the range?
    $startTube = intdiv($lo-1, $tube_size);
    $endTube   = intdiv($hi-1, $tube_size);

    // If total fibers known, show all tubes up to that; else only intersecting tubes
    $maxTube = ($total_fibers && $total_fibers > 0)
      ? max($endTube, intdiv($total_fibers-1, $tube_size))
      : $endTube;

    $minTube = ($total_fibers && $total_fibers > 0) ? 0 : $startTube;

    $out = '<div class="fiber-tubes">';
    for ($t = $minTube; $t <= $maxTube; $t++) {
      $pal = $palette[$t % 12];
      $tubeLo = $t * $tube_size + 1;
      $tubeHi = ($t+1) * $tube_size;
      // intersection with selected range
      $iLo = max($tubeLo, $lo);
      $iHi = min($tubeHi, $hi);
      $intersects = $iLo <= $iHi;

      $bg = $pal['hex'];
      $fg = $pal['fg'] ?? '#fff';

      $classes = 'fiber-tube small';
      if (!$intersects && $total_fibers) $classes .= ' dim';

      $out .= '<div class="'.$classes.'" style="background:'.$bg.'; color:'.$fg.'">';
      $out .= '<span class="swatch" style="background:'.$bg.'"></span>';
      $out .= '<span class="label">'.htmlspecialchars($pal['name']).'</span>';
      $out .= '<span class="range">('.$tubeLo.'–'.$tubeHi.')</span>';
      if ($intersects) {
        $out .= '<span class="range">• '.$iLo.'–'.$iHi.'</span>';
      }
      $out .= '</div>';
    }
    $out .= '</div>';
    return $out;
  }


/**
 * Optional: draw subtle tube separators above an existing fiber ribbon.
 * Returns a thin overlay with ticks at every tube boundary.
 */
function render_tube_separators(int $lo, int $hi, int $tube_size = 12): string {
    if ($lo <= 0 || $hi < $lo) return '';
    $count = $hi - $lo + 1;
    $ticks = [];
    // percentage positions inside the ribbon
    for ($n = $lo; $n <= $hi; $n++) {
      if ((($n-1) % $tube_size) === 0 && $n !== $lo) {
        $pos = ($n - $lo) / $count * 100.0;
        $ticks[] = $pos;
      }
    }
    $html = '<div style="position:relative;height:0;margin-top:2px">';
    foreach ($ticks as $p) {
      $html .= '<div style="position:absolute;left:'.$p.'%;top:0;width:0;height:8px;border-left:1px dashed rgba(0,0,0,.25)"></div>';
    }
    $html .= '</div>';
    return $html;
  }
