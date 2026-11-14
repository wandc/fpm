/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
/**
 * stage-picker.js — reusable Tinuum construction stage control.
 *
 * The construction stage lives on the Tinuum work order and is written from
 * three places (BOM calculator, Tinuum Permits, Bell Permits) so nothing has to
 * be typed twice. This module renders the same control in all of them.
 *
 * Markup — the page supplies the current value, the module does the rest:
 *
 *   <span data-stage-picker
 *         data-permit="2026-1-Lewis"
 *         data-stage="splicing"
 *         data-effective="2026-06-12"
 *         data-compact></span>
 *
 * data-compact renders just the <select> (for dense lists such as the Bell
 * permit page); the full form adds an effective-date box, a note and Save.
 *
 * Any element with [data-stage-badge="<permit>"] on the page is refreshed after
 * a successful save, so a header badge stays in step with the picker below it.
 *
 * CSRF token is read from window._csrf at call time — it is assigned after this
 * script loads (see project note on CSRF load order).
 */
(function () {
  const STAGES = [
    ['not_started',   'Not started',           'secondary'],
    ['make_ready',    'Make-Ready complete',    'warning text-dark'],
    ['fibre_install', 'Fibre Install complete', 'info'],
    ['splicing',      'Splicing complete',      'primary'],
    ['commissioning', 'Commissioning complete', 'primary'],
    ['complete',      'Construction complete', 'success'],
  ];

  const LABEL = {}, CLASS = {};
  STAGES.forEach(function (s) { LABEL[s[0]] = s[1]; CLASS[s[0]] = s[2]; });

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function today() {
    const d = new Date();
    return d.getFullYear() + '-' +
           String(d.getMonth() + 1).padStart(2, '0') + '-' +
           String(d.getDate()).padStart(2, '0');
  }

  async function save(permit, stage, effective, note) {
    const r = await fetch('/api/fpm_permit_stage.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window._csrf },
      body:    JSON.stringify({
        permit_num: permit, stage: stage, effective_date: effective, note: note || '',
      }),
    });
    return r.json();
  }

  // Keep every badge and "since" date for this work order in step, wherever they
  // sit on the page — the BOM sidebar and the Tinuum accordion header both show
  // them away from the picker that was just used.
  function refreshBadges(permit, stage, effective) {
    document.querySelectorAll('[data-stage-badge]').forEach(function (el) {
      if (el.dataset.stageBadge !== permit) return;
      el.className   = 'badge bg-' + CLASS[stage] + ' stage-badge';
      el.textContent = LABEL[stage];
      el.dataset.stageBadge = permit;
    });
    if (!effective) return;
    document.querySelectorAll('[data-stage-date]').forEach(function (el) {
      if (el.dataset.stageDate !== permit) return;
      el.textContent = 'since ' + effective;
      el.title       = 'entered this stage on ' + effective;
    });
  }

  function render(root) {
    const permit  = root.dataset.permit;
    const current = root.dataset.stage || 'not_started';
    const eff     = root.dataset.effective || '';
    const compact = root.hasAttribute('data-compact');

    const opts = STAGES.map(function (s) {
      return '<option value="' + s[0] + '"' + (s[0] === current ? ' selected' : '') + '>' +
             esc(s[1]) + '</option>';
    }).join('');

    if (compact) {
      root.innerHTML =
        '<select class="form-select form-select-sm d-inline-block stage-sel" style="width:auto">' +
          opts +
        '</select>' +
        '<span class="stage-msg small ms-2"></span>';
    } else {
      root.innerHTML =
        '<div class="row g-2 align-items-end">' +
          '<div class="col-sm-4">' +
            '<label class="form-label small fw-semibold mb-0">Construction stage</label>' +
            '<select class="form-select form-select-sm stage-sel">' + opts + '</select>' +
          '</div>' +
          '<div class="col-sm-3">' +
            '<label class="form-label small fw-semibold mb-0">Effective date</label>' +
            '<input type="date" class="form-control form-control-sm stage-eff" value="' +
              esc(eff || today()) + '">' +
          '</div>' +
          '<div class="col-sm-3">' +
            '<label class="form-label small fw-semibold mb-0">Note</label>' +
            '<input type="text" class="form-control form-control-sm stage-note" placeholder="optional">' +
          '</div>' +
          '<div class="col-sm-2">' +
            '<button type="button" class="btn btn-primary btn-sm w-100 stage-save">Record</button>' +
          '</div>' +
        '</div>' +
        '<div class="small text-muted mt-1 stage-msg"></div>';
    }

    const sel  = root.querySelector('.stage-sel');
    const msg  = root.querySelector('.stage-msg');
    const effI = root.querySelector('.stage-eff');
    const noteI= root.querySelector('.stage-note');
    let orig   = current;

    async function commit() {
      const stage = sel.value;
      const date  = effI ? effI.value : today();
      const note  = noteI ? noteI.value : '';

      msg.className   = 'stage-msg small text-muted' + (compact ? ' ms-2' : ' mt-1');
      msg.textContent = 'Saving…';
      try {
        const data = await save(permit, stage, date, note);
        if (!data.ok) {
          msg.className   = 'stage-msg small text-danger' + (compact ? ' ms-2' : ' mt-1');
          msg.textContent = data.error || 'Save failed';
          sel.value = orig;                    // revert, like the Bell inline edit
          return;
        }
        orig = stage;
        root.dataset.stage = stage;
        refreshBadges(permit, stage, data.effective_date);
        if (noteI) noteI.value = '';
        msg.className   = 'stage-msg small text-success' + (compact ? ' ms-2' : ' mt-1');
        msg.textContent = 'Saved — ' + LABEL[stage] + ' as of ' + data.effective_date;
      } catch (e) {
        msg.className   = 'stage-msg small text-danger' + (compact ? ' ms-2' : ' mt-1');
        msg.textContent = 'Save failed: ' + e.message;
        sel.value = orig;
      }
    }

    // Compact mode saves on change; the full form waits for Record so a
    // backdated entry can have its date and note set first.
    if (compact) {
      sel.addEventListener('change', commit);
    } else {
      root.querySelector('.stage-save').addEventListener('click', commit);
    }
  }

  function initAll(scope) {
    (scope || document).querySelectorAll('[data-stage-picker]').forEach(function (el) {
      if (el.dataset.stageInit) return;
      el.dataset.stageInit = '1';
      render(el);
    });
  }

  // Deleting a history entry is handled here, delegated from the document, so any
  // page that renders a .stage-del-btn gets it without repeating the fetch call.
  // Delegation also survives history tables inside a <details> that opens later.
  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('.stage-del-btn');
    if (!btn) return;
    ev.preventDefault();

    if (!confirm('Remove this stage entry?\n\nAny year-end dated after it will be re-valued.')) return;
    btn.disabled = true;
    try {
      const r = await fetch('/api/fpm_permit_stage.php', {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window._csrf },
        body:    JSON.stringify({ id: parseInt(btn.dataset.stageId, 10) }),
      });
      const data = await r.json();
      if (!data.ok) { btn.disabled = false; alert(data.error || 'Could not delete.'); return; }

      const row = btn.closest('tr');
      const tbody = row ? row.parentNode : null;
      if (row) row.remove();

      // The current stage may now be a different row, and the badge alongside it
      // would be stale. Reloading is the honest option — the whole page's costing
      // (BOM category markers, earned totals) depends on the stage.
      if (btn.dataset.stageReload !== '0') { location.reload(); return; }

      if (tbody && !tbody.querySelector('tr')) {
        const det = tbody.closest('details');
        if (det) det.remove();
      }
    } catch (e) {
      btn.disabled = false;
      alert('Delete failed: ' + e.message);
    }
  });

  window.initStagePickers = initAll;
  document.addEventListener('DOMContentLoaded', function () { initAll(document); });
})();
