/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
/* fiber-range-picker.js — fibre / port range entry for the splice modals.
 *
 * Replaces the old "Fiber lo" + "Fiber hi" number pair. Typing is the fast path
 * (`1:12` is a whole buffer tube), and the ITU/TIA-598 colour grid underneath shows
 * what that selection actually is — the same colours the splicer sees in the tray —
 * while blocking fibres that are already spliced at this closure.
 *
 * The hidden lo/hi inputs keep their original names, so nothing on the server side
 * changed: the form still posts a_fiber_lo / a_fiber_hi etc.
 *
 * Markup contract (see render_fiber_range_picker() in closure.helper.php):
 *   <div data-fibre-picker data-mode="fibre|port"
 *        data-lo="#eAFiberLo" data-hi="#eAFiberHi" data-source="#eAEndpoint">
 *     <input class="fp-text"> <div class="fp-grid"></div> <div class="fp-summary"></div>
 *   </div>
 * Sizing/availability comes from window.FIBRE_PICKER_DATA, keyed by the value of the
 * source <select> (endpoint key for spans, device id for device ports). It carries both
 * layers - the built one at the top, the planned one under `plan` - because a fibre is
 * only in conflict with joins on its own layer. The picker follows the form's Layer
 * radio, so planning over fibre the plan has already claimed is blocked in the grid
 * rather than at the INSERT.
 */
(function () {
  'use strict';

  // TIA-598-C / ITU-T 12-fibre sequence — mirrors fiber_color_name() in
  // phpHelpers/fiber_colors.php. Exported so nothing else has to re-declare it.
  var FIBER_COLORS = [
    ['Blue', '#0072CE'], ['Orange', '#F58220'], ['Green', '#009A44'], ['Brown', '#7C4A21'],
    ['Slate', '#6E7E8C'], ['White', '#FFFFFF'], ['Red', '#DA291C'], ['Black', '#000000'],
    ['Yellow', '#FFD100'], ['Violet', '#582C83'], ['Rose', '#E4007C'], ['Aqua', '#00B5E2']
  ];

  function fiberColor(n) {
    if (n <= 0) return ['Unknown', '#CCCCCC'];
    return FIBER_COLORS[(n - 1) % 12];
  }

  // WCAG relative luminance, same rule as _luma() in fiber_colors.php, so White and
  // Yellow fibres get black text instead of vanishing.
  function readableFg(hex) {
    var h = hex.replace('#', '');
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    var l = 0.2126 * f(parseInt(h.slice(0, 2), 16)) +
            0.7152 * f(parseInt(h.slice(2, 4), 16)) +
            0.0722 * f(parseInt(h.slice(4, 6), 16));
    return l > 0.5 ? '#000' : '#fff';
  }

  /**
   * Parse what a splicer would type into [lo, hi].
   *   1:12  1-12  1–12  1 12  1..12 → range      7 → single
   *   25:   25-                     → 25 to the end of the cable
   *   IN                            → port 0
   * Returns {lo, hi} or {error}. Commas are handled a level up by parseRuns — a
   * splice_join row still holds ONE range per side, so several runs become several
   * rows rather than one row that quietly lies about what is spliced.
   */
  function parseRange(raw, max, mode) {
    var s = String(raw || '').trim().toLowerCase();
    if (s === '') return { empty: true };

    if (mode === 'port' && (s === 'in' || s === 'input')) return { lo: 0, hi: 0 };

    var m = s.match(/^(\d*)\s*(?::|-|–|—|\.\.|\s)\s*(\d*)$/);
    if (m) {
      // "1:" means "1 to the end". Until an endpoint is chosen there is no end, so
      // treat it as still being typed rather than as a mistake.
      if (m[2] === '' && !max) return { pending: true };
      var lo = m[1] === '' ? (mode === 'port' ? 0 : 1) : parseInt(m[1], 10);
      var hi = m[2] === '' ? max : parseInt(m[2], 10);
      if (isNaN(lo) || isNaN(hi)) return { error: 'Enter a range like 1:12.' };
      if (lo > hi) { var t = lo; lo = hi; hi = t; }
      return clamp(lo, hi, max, mode);
    }
    if (/^\d+$/.test(s)) {
      var n = parseInt(s, 10);
      return clamp(n, n, max, mode);
    }
    return { error: 'Enter a fibre like 7, or a range like 1:12.' };
  }

  function clamp(lo, hi, max, mode) {
    var min = mode === 'port' ? 0 : 1;
    if (lo < min) return { error: (mode === 'port' ? 'Ports start at 0 (IN).' : 'Fibres start at 1.') };
    if (max && hi > max) {
      return { error: (mode === 'port' ? 'This device has ports up to ' + max + '.'
                                       : 'This cable only has ' + max + ' fibres.') };
    }
    return { lo: lo, hi: hi };
  }

  function fmtRange(lo, hi) { return lo === hi ? String(lo) : lo + ':' + hi; }

  /**
   * Sort, then fuse runs that overlap or merely touch: picking 1:6 and then 7:12
   * is one continuous splice, and writing it as two rows would be noise in the tray.
   */
  function normalizeRuns(runs) {
    var out = runs.slice().sort(function (a, b) { return a.lo - b.lo; });
    var merged = [];
    out.forEach(function (r) {
      var last = merged[merged.length - 1];
      if (last && r.lo <= last.hi + 1) last.hi = Math.max(last.hi, r.hi);
      else merged.push({ lo: r.lo, hi: r.hi });
    });
    return merged;
  }

  function runsCount(runs) {
    return runs.reduce(function (n, r) { return n + (r.hi - r.lo + 1); }, 0);
  }

  function fmtRuns(runs) {
    return runs.map(function (r) { return fmtRange(r.lo, r.hi); }).join(',');
  }

  /**
   * A whole typed selection: one run, or several separated by commas when the
   * picker allows it. Returns {runs} or a passthrough of parseRange's error/empty.
   */
  function parseRuns(raw, max, mode, multi) {
    var s = String(raw || '').trim();
    if (s === '') return { empty: true };

    var parts = s.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
    if (parts.length > 1 && !multi) {
      return { error: 'One continuous run only — add a second splice for ' + parts.slice(1).join(', ') + '.' };
    }

    var runs = [];
    for (var i = 0; i < parts.length; i++) {
      var res = parseRange(parts[i], max, mode);
      // A trailing comma is mid-typing, not a mistake.
      if (res.empty || res.pending) {
        if (i === parts.length - 1) return runs.length ? { runs: normalizeRuns(runs) } : { pending: true };
        continue;
      }
      if (res.error) return res;
      runs.push({ lo: res.lo, hi: res.hi });
    }
    if (!runs.length) return { pending: true };
    return { runs: normalizeRuns(runs) };
  }

  // ── One picker instance ────────────────────────────────────────────────────
  function Picker(root) {
    this.root    = root;
    this.mode    = root.getAttribute('data-mode') || 'fibre';
    this.multi   = root.getAttribute('data-multi') === '1';
    this.loEl    = document.querySelector(root.getAttribute('data-lo'));
    this.hiEl    = document.querySelector(root.getAttribute('data-hi'));
    this.runsEl  = root.querySelector('.fp-runs');
    this.srcEl   = root.getAttribute('data-source') ? document.querySelector(root.getAttribute('data-source')) : null;
    this.textEl  = root.querySelector('.fp-text');
    this.gridEl  = root.querySelector('.fp-grid');
    this.sumEl   = root.querySelector('.fp-summary');
    this.info    = null;      // {total, used[], usedBy{}, labels[]}
    this.runs    = [];        // [{lo, hi}, …] — disjoint, ascending
    this.drag    = null;      // {from, additive} while sweeping

    var self = this;
    this.textEl.addEventListener('input',  function () { self.onType(); });
    this.textEl.addEventListener('keydown', function (e) {
      // Enter inside the picker commits the text rather than submitting the form —
      // submitting a half-typed range was one way the old fields surprised people.
      if (e.key === 'Enter') { e.preventDefault(); self.onType(); }
    });
    if (this.srcEl) this.srcEl.addEventListener('change', function () { self.reload(); });

    // Availability is per layer, so switching Actual/Plan re-reads it.
    var form = this.root.closest ? this.root.closest('form') : null;
    (form || document).querySelectorAll('input[name="is_plan"]').forEach(function (r) {
      r.addEventListener('change', function () { self.reload(); });
    });

    this.gridEl.addEventListener('mousedown', function (e) { self.onCellDown(e); });
    this.gridEl.addEventListener('mouseover', function (e) { self.onCellOver(e); });
    document.addEventListener('mouseup', function () { self.drag = null; });

    this.reload();
  }

  /** The layer this picker is currently entering a splice on. */
  Picker.prototype.isPlanLayer = function () {
    var form = this.root.closest ? this.root.closest('form') : null;
    var sel  = (form || document).querySelector('input[name="is_plan"]:checked');
    return !!sel && sel.value === '1';
  };

  /** "spliced" on the built layer, "planned" on the other - not the same claim. */
  Picker.prototype.usedWord = function () {
    return this.isPlanLayer() ? 'planned' : 'spliced';
  };

  Picker.prototype.data = function () {
    var store = window.FIBRE_PICKER_DATA || {};
    if (this.isPlanLayer() && store.plan) store = store.plan;
    var key   = this.srcEl ? this.srcEl.value : '';
    if (!key) return null;
    return (this.mode === 'port' ? store.devices : store.endpoints)[key] || null;
  };

  /** Rebuild for the currently selected endpoint/device, keeping a still-valid pick. */
  Picker.prototype.reload = function () {
    this.info = this.data();
    var max = this.max();
    if (this.runs.length && this.info && this.runs[this.runs.length - 1].hi > max) this.setSel([]);
    this.renderGrid();
    // Whatever is in the box was checked against the layer/endpoint at the time it was
    // typed - re-check it now, so "already spliced" from the Actual layer doesn't
    // linger after switching to Plan (and a plan conflict shows up after switching back).
    if (this.textEl.value.trim() !== '') { this.onType(); return; }
    this.err('');
    this.render();
  };

  Picker.prototype.max = function () {
    if (!this.info) return 0;
    return this.mode === 'port' ? (this.info.ports || 0) : (this.info.total || 0);
  };

  Picker.prototype.isUsed = function (n) {
    if (!this.info || !this.info.used || this.info.used.indexOf(n) === -1) return false;
    // When editing a splice, the fibres it already holds are "used" by itself — the
    // whole point of the edit may be to widen or shift that range, so they stay free.
    var mine = this.root.getAttribute('data-exclude-id');
    if (mine && this.info.usedId && String(this.info.usedId[n]) === String(mine)) return false;
    return true;
  };

  Picker.prototype.onType = function () {
    var res = parseRuns(this.textEl.value, this.max(), this.mode, this.multi);
    // Every branch passes fromText:true — the box belongs to the typist. Clearing it
    // mid-keystroke is what made "1:" look like the field was resetting itself.
    if (res.empty)   { this.setSel([], true); this.err(''); return; }
    if (res.pending) { this.setSel([], true); this.err(''); return; }
    if (res.error)   { this.setSel([], true); this.err(res.error); return; }
    var blocked = [], self = this;
    res.runs.forEach(function (r) {
      for (var n = r.lo; n <= r.hi; n++) if (self.isUsed(n)) blocked.push(n);
    });
    if (blocked.length) {
      this.setSel([], true);
      this.err((this.mode === 'port' ? 'Port ' : 'Fibre ') + blocked.join(', ') + ' already ' + this.usedWord() + ' here.');
      return;
    }
    this.err('');
    this.setSel(res.runs, true);
  };

  /**
   * @param {Array} runs    the whole selection; [] clears it
   * @param {boolean} fromText leave the text box alone when the user is typing in it
   */
  Picker.prototype.setSel = function (runs, fromText) {
    this.runs = normalizeRuns(runs || []);
    var first = this.runs[0] || null;
    // lo/hi keep carrying the first run so single-run consumers are untouched; the
    // full selection rides in the runs input, which only the add-splice form has.
    if (this.loEl)   this.loEl.value   = first ? first.lo : '';
    if (this.hiEl)   this.hiEl.value   = first ? first.hi : '';
    if (this.runsEl) this.runsEl.value = this.runs.length ? fmtRuns(this.runs) : '';
    if (!fromText) this.textEl.value = this.runs.length ? fmtRuns(this.runs) : '';
    this.paint();
    this.render();
  };

  Picker.prototype.isPicked = function (n) {
    return this.runs.some(function (r) { return n >= r.lo && n <= r.hi; });
  };

  Picker.prototype.err = function (msg) {
    this.textEl.classList.toggle('is-invalid', !!msg);
    this.root.querySelector('.fp-error').textContent = msg || '';
  };

  // ── Grid ───────────────────────────────────────────────────────────────────
  Picker.prototype.renderGrid = function () {
    var g = this.gridEl;
    g.innerHTML = '';
    var max = this.max();
    if (!max) {
      // No endpoint yet, so nothing to size a grid from. The typed range still shows
      // its colours in the summary strip below, which is the one place they live.
      g.classList.add('fp-grid--empty');
      g.textContent = this.mode === 'port' ? 'Choose a device to see its ports.'
                                           : 'Choose an endpoint to see its fibres.';
      return;
    }
    g.classList.remove('fp-grid--empty');

    if (this.mode === 'port') {
      // Ports are not fibres — no ITU colour, but IN is spelled out so the 0 that
      // used to look like a broken field reads as what it is.
      for (var p = 0; p <= max; p++) {
        g.appendChild(this.cell(p, p === 0 ? 'IN' : 'OUT ' + p, '', 'fp-cell fp-cell--port'));
      }
      return;
    }

    var tubes = Math.ceil(max / 12);
    for (var t = 0; t < tubes; t++) {
      var row = document.createElement('div');
      row.className = 'fp-row';
      var lbl = document.createElement('span');
      lbl.className = 'fp-tube';
      lbl.textContent = 'T' + (t + 1);
      lbl.title = 'Buffer tube ' + (t + 1) + ' — ' + fiberColor(t + 1)[0];
      lbl.style.borderLeftColor = fiberColor(t + 1)[1];
      row.appendChild(lbl);
      for (var i = 1; i <= 12; i++) {
        var n = t * 12 + i;
        if (n > max) break;
        var c = fiberColor(n);
        var cell = this.cell(n, String(n), 'Fiber ' + n + ' • ' + c[0], 'fp-cell');
        cell.style.background = c[1];
        cell.style.color = readableFg(c[1]);
        row.appendChild(cell);
      }
      g.appendChild(row);
    }
    this.paint();
  };

  Picker.prototype.cell = function (n, text, title, cls) {
    var el = document.createElement('button');
    el.type = 'button';
    el.className = cls;
    el.dataset.n = n;
    el.textContent = text;
    if (this.isUsed(n)) {
      el.classList.add('fp-used');
      el.disabled = true;
      var by = this.info.usedBy && this.info.usedBy[n];
      el.title = (title ? title + ' — ' : '') + 'already ' + this.usedWord() + (by ? ' to ' + by : '');
    } else if (title) {
      el.title = title;
    }
    return el;
  };

  Picker.prototype.paint = function () {
    var self = this;
    this.gridEl.querySelectorAll('.fp-cell').forEach(function (el) {
      el.classList.toggle('fp-pick', self.isPicked(+el.dataset.n));
    });
  };

  Picker.prototype.onCellDown = function (e) {
    var el = e.target.closest('.fp-cell');
    if (!el || el.disabled) return;
    e.preventDefault();
    var n = +el.dataset.n;
    var additive = this.multi && (e.ctrlKey || e.metaKey);

    // Ctrl/⌘-click on an already-picked cell removes that run — the same modifier
    // takes it back out, so a mis-click costs one click rather than a restart.
    if (additive && this.isPicked(n)) {
      this.err('');
      this.setSel(this.runs.filter(function (r) { return n < r.lo || n > r.hi; }));
      return;
    }

    // Shift extends the last run in place; the run being redrawn is the base's tail.
    if (e.shiftKey && this.runs.length) {
      var last = this.runs[this.runs.length - 1];
      this.commit(Math.min(last.lo, n), Math.max(last.hi, n), this.runs.slice(0, -1));
      return;
    }

    // Everything the sweep must preserve is frozen at mousedown, so each mouseover
    // redraws one run against a fixed base instead of piling runs on top of itself.
    this.drag = { from: n, base: additive ? this.runs.slice() : [] };
    this.commit(n, n, this.drag.base);
  };

  Picker.prototype.onCellOver = function (e) {
    if (!this.drag) return;
    var el = e.target.closest('.fp-cell');
    if (!el) return;
    var n = +el.dataset.n;
    this.commit(Math.min(this.drag.from, n), Math.max(this.drag.from, n), this.drag.base);
  };

  /**
   * Apply a grid-made run on top of `base`, refusing to swallow an already-spliced
   * fibre. `base` is the set of runs to keep — [] for a plain click that replaces
   * the selection, the frozen snapshot for a ctrl-sweep.
   */
  Picker.prototype.commit = function (lo, hi, base) {
    var label = this.mode === 'port' ? 'port ' : 'fibre ';
    for (var n = lo; n <= hi; n++) {
      if (this.isUsed(n)) { this.err('That range crosses ' + label + n + ', already ' + this.usedWord() + ' here.'); return; }
    }
    this.err('');
    this.setSel((base || []).concat([{ lo: lo, hi: hi }]));
  };

  /**
   * The picked fibres as ITU colour chips. This is what makes a range readable at a
   * glance — and it works even when no endpoint is chosen yet, where there is no grid
   * to light up but the colours of "1:12" are still perfectly well defined.
   */
  function chipStrip(runs, maxChips) {
    var out = document.createElement('div');
    out.className = 'fp-chips';
    var budget = maxChips || 48, total = runsCount(runs), shown = 0;
    for (var i = 0; i < runs.length && shown < budget; i++) {
      // A gap between runs gets its own separator, so 1,3 never reads as 1–3.
      if (i > 0) {
        var gap = document.createElement('span');
        gap.className = 'fp-chip-gap';
        gap.textContent = '·';
        gap.title = 'separate run — its own splice';
        out.appendChild(gap);
      }
      for (var n = runs[i].lo; n <= runs[i].hi && shown < budget; n++, shown++) {
        var c = fiberColor(n);
        var chip = document.createElement('span');
        chip.className = 'fp-chip';
        chip.textContent = n;
        chip.style.background = c[1];
        chip.style.color = readableFg(c[1]);
        chip.title = 'Fiber ' + n + ' • ' + c[0];
        out.appendChild(chip);
      }
    }
    if (total > shown) {
      var more = document.createElement('span');
      more.className = 'fp-chip fp-chip--more';
      more.textContent = '+' + (total - shown);
      more.title = 'and ' + (total - shown) + ' more, through fibre ' + runs[runs.length - 1].hi;
      out.appendChild(more);
    }
    return out;
  }

  // ── Summary line ───────────────────────────────────────────────────────────
  Picker.prototype.render = function () {
    if (!this.sumEl) return;
    if (!this.runs.length) {
      var max = this.max();
      this.sumEl.innerHTML = max
        ? '<span class="text-muted">' + (this.mode === 'port'
            ? 'Ports 0–' + max + ' (0 = IN)'
            : max + ' fibres · ' + (this.info.used || []).length + ' already ' + this.usedWord()) + '</span>'
        : '';
      return;
    }
    var runs = this.runs, count = runsCount(runs), nRuns = runs.length;
    var lo = runs[0].lo, hi = runs[nRuns - 1].hi;
    // Several runs become several splice rows, and saying so here is the only warning
    // before the insert — the alternative was people expecting one row and getting three.
    var rowNote = nRuns > 1
      ? ' · <span class="fp-runs-note">' + nRuns + ' runs → ' + nRuns + ' splices</span>'
      : '';

    if (this.mode === 'port') {
      var portStr = nRuns === 1 && lo === 0 && hi === 0 ? 'IN' : 'OUT ' + fmtRuns(runs);
      this.sumEl.innerHTML = '<strong>' + portStr + '</strong> <span class="text-muted">· ' +
                             count + ' port' + (count > 1 ? 's' : '') + rowNote + '</span>';
      return;
    }

    var tubeLo = Math.floor((lo - 1) / 12) + 1, tubeHi = Math.floor((hi - 1) / 12) + 1;
    var tubeStr = tubeLo === tubeHi
      ? 'tube ' + tubeLo + ' (' + fiberColor(tubeLo)[0] + ')'
      : 'tubes ' + tubeLo + '–' + tubeHi;
    var head = nRuns === 1
      ? 'F' + lo + (count > 1 ? '–F' + hi : '')
      : 'F' + fmtRuns(runs);

    this.sumEl.innerHTML =
      '<strong>' + head + '</strong> ' +
      '<span class="text-muted">· ' + count + ' fibre' + (count > 1 ? 's' : '') +
      ' · ' + tubeStr + rowNote + '</span>';
    // Colour names live in each chip's tooltip now — the strip says it faster.
    this.sumEl.appendChild(chipStrip(runs, 48));
  };

  // ── Wiring ─────────────────────────────────────────────────────────────────
  var pickers = [];

  function initAll() {
    document.querySelectorAll('[data-fibre-picker]').forEach(function (el) {
      if (el._fp) return;
      el._fp = new Picker(el);
      pickers.push(el._fp);
    });
  }

  window.FibrePicker = {
    colors: FIBER_COLORS,
    color:  fiberColor,
    parse:  parseRange,
    init:   initAll,
    /** Re-read hidden lo/hi after code set them (the Edit modal fills them directly). */
    sync: function (rootSel) {
      document.querySelectorAll(rootSel || '[data-fibre-picker]').forEach(function (el) {
        var p = el._fp; if (!p) return;
        p.reload();
        var lo = p.loEl && p.loEl.value !== '' ? parseInt(p.loEl.value, 10) : null;
        var hi = p.hiEl && p.hiEl.value !== '' ? parseInt(p.hiEl.value, 10) : null;
        p.err('');
        // Editing works from the single lo/hi of one existing row — an edit never
        // arrives as several runs, because a row cannot hold several.
        if (lo === null || isNaN(lo)) { p.setSel([]); }
        else p.setSel([{ lo: lo, hi: (hi === null || isNaN(hi)) ? lo : hi }]);
      });
    },
    /** Clear everything — used when a modal closes so nothing leaks into the next one. */
    clear: function (rootSel) {
      document.querySelectorAll(rootSel || '[data-fibre-picker]').forEach(function (el) {
        var p = el._fp; if (!p) return;
        p.err('');
        p.setSel([]);
      });
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
  else initAll();
})();
