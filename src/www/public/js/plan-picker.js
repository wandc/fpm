/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
/**
 * plan-picker.js — reusable "Site Plans" card for permits and work orders /
 * Tinuum permits. Lists map plans linked to an entity and lets you add/edit/
 * delete them, then open each as a framed map plan (map.php?plan=N).
 *
 *   initPlanPicker(container, entityType, entityId, { canAutoframe })
 *
 * entityType: 'bell_permit' | 'fpm_permit'
 * entityId:   bell_permit.id / fpm_permit.permit_num (string)
 * canAutoframe: show a "Centre on record" button (permits, which have geometry)
 *
 * CSRF token is read from window._csrf at call time (it is assigned after this
 * script loads — see project note on CSRF load order).
 */
(function () {
  const LAYERS = [
    ['poles', 'Poles'], ['spans', 'Cable spans'], ['strands', 'Strands'],
    ['conduits', 'Conduits'], ['closures', 'Closures'], ['anchors', 'Anchors'],
    ['drops', 'Drops / ONUs'], ['vaults', 'Vaults / handholes'],
  ];
  const DEFAULT_LAYERS = ['poles', 'spans', 'strands', 'conduits', 'closures'];

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  async function api(method, qs, body) {
    const opts = { method, headers: {} };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = window._csrf; opts.body = JSON.stringify(body); }
    if (method === 'DELETE') opts.headers['X-CSRF-Token'] = window._csrf;
    const r = await fetch('/api/map_plan.php' + (qs || ''), opts);
    return r.json();
  }

  window.initPlanPicker = function (container, entityType, entityId, opts) {
    opts = opts || {};
    const root = typeof container === 'string' ? document.getElementById(container) : container;
    if (!root) return;
    entityId = String(entityId);

    root.innerHTML =
      '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<h6 class="text-uppercase text-muted mb-0" style="font-size:.72rem;letter-spacing:.06em">Site Plans</h6>' +
        '<button type="button" class="btn btn-sm btn-outline-success" data-pp="add">+ Plan</button>' +
      '</div>' +
      '<div data-pp="list"><p class="text-muted small mb-2">Loading…</p></div>' +
      '<div data-pp="form" style="display:none"></div>';

    const listEl = root.querySelector('[data-pp="list"]');
    const formEl = root.querySelector('[data-pp="form"]');
    root.querySelector('[data-pp="add"]').addEventListener('click', () => showForm(null));

    function load() {
      api('GET', '?entity_type=' + encodeURIComponent(entityType) + '&entity_id=' + encodeURIComponent(entityId))
        .then(d => renderList(d.ok ? (d.plans || []) : []))
        .catch(() => renderList([]));
    }

    function renderList(plans) {
      if (!plans.length) { listEl.innerHTML = '<p class="text-muted small mb-2">No plans yet.</p>'; return; }
      listEl.innerHTML = '<div class="list-group list-group-flush mb-2">' + plans.map(p =>
        '<div class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">' +
          '<div class="overflow-hidden">' +
            '<div class="small fw-semibold text-truncate">' + esc(p.title) + '</div>' +
            '<div class="text-muted" style="font-size:.7rem">' +
              p.center_lat.toFixed(5) + ', ' + p.center_lon.toFixed(5) + ' · z' + p.zoom + ' · ' +
              (p.layers || []).length + ' layer' + ((p.layers || []).length === 1 ? '' : 's') +
            '</div>' +
          '</div>' +
          '<div class="d-flex gap-1 flex-shrink-0">' +
            '<a class="btn btn-sm btn-outline-secondary py-0" target="_blank" href="/index.php?page=map&plan=' + p.plan_id + '">Open</a>' +
            '<button type="button" class="btn btn-sm btn-outline-primary py-0" data-edit="' + p.plan_id + '">Edit</button>' +
            '<button type="button" class="btn btn-sm btn-outline-danger py-0" data-del="' + p.plan_id + '">✕</button>' +
          '</div>' +
        '</div>'
      ).join('') + '</div>';

      listEl.querySelectorAll('[data-edit]').forEach(b =>
        b.addEventListener('click', () => {
          const p = plans.find(x => String(x.plan_id) === b.dataset.edit);
          if (p) showForm(p);
        }));
      listEl.querySelectorAll('[data-del]').forEach(b =>
        b.addEventListener('click', async () => {
          if (!confirm('Delete this plan?')) return;
          await api('DELETE', '?id=' + b.dataset.del);
          load();
        }));
    }

    function showForm(plan) {
      const p = plan || { title: 'Site Plan', center_lat: '', center_lon: '', zoom: 16, orientation: 'landscape', layers: DEFAULT_LAYERS.slice(), meta: {} };
      const m = p.meta || {};
      const checks = LAYERS.map(([k, lbl]) =>
        '<label class="me-2 small"><input type="checkbox" value="' + k + '" data-pp-layer ' +
        ((p.layers || []).includes(k) ? 'checked' : '') + '> ' + esc(lbl) + '</label>'
      ).join('');
      formEl.innerHTML =
        '<div class="border rounded p-2 mb-2">' +
          '<div class="mb-2"><label class="form-label small mb-1">Title</label>' +
            '<input class="form-control form-control-sm" data-pp-f="title" value="' + esc(p.title) + '"></div>' +
          '<div class="mb-2"><label class="form-label small mb-1">Centre — paste "lat, lon[, zoom]" from the map 📍 Coords tool</label>' +
            '<div class="input-group input-group-sm">' +
              '<input class="form-control" data-pp-f="center" placeholder="45.123456, -65.123456, 16" ' +
                'value="' + (p.center_lat !== '' ? p.center_lat + ', ' + p.center_lon : '') + '">' +
              (opts.canAutoframe ? '<button type="button" class="btn btn-outline-secondary" data-pp="autoframe">Centre on record</button>' : '') +
            '</div></div>' +
          '<div class="row g-2 mb-2">' +
            '<div class="col"><label class="form-label small mb-1">Zoom</label>' +
              '<input type="number" min="1" max="23" class="form-control form-control-sm" data-pp-f="zoom" value="' + (p.zoom || 16) + '"></div>' +
            '<div class="col"><label class="form-label small mb-1">Orientation</label>' +
              '<select class="form-select form-select-sm" data-pp-f="orientation">' +
                '<option value="landscape"' + (p.orientation === 'landscape' ? ' selected' : '') + '>Landscape</option>' +
                '<option value="portrait"' + (p.orientation === 'portrait' ? ' selected' : '') + '>Portrait</option>' +
              '</select></div>' +
          '</div>' +
          '<div class="mb-2"><label class="form-label small mb-1">Layers</label><div>' + checks + '</div></div>' +
          '<div class="row g-2 mb-2">' +
            '<div class="col"><label class="form-label small mb-1">Drawing title (optional)</label>' +
              '<input class="form-control form-control-sm" data-pp-m="drawing_title" value="' + esc(m.drawing_title || '') + '"></div>' +
            '<div class="col"><label class="form-label small mb-1">Revision</label>' +
              '<input class="form-control form-control-sm" data-pp-m="revision" value="' + esc(m.revision || '') + '"></div>' +
          '</div>' +
          '<div class="mb-2"><label class="form-label small mb-1">Location / notes (optional)</label>' +
            '<input class="form-control form-control-sm" data-pp-m="location" value="' + esc(m.location || '') + '"></div>' +
          '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-sm btn-success" data-pp="save">Save plan</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-pp="cancel">Cancel</button>' +
            '<span class="small text-danger align-self-center" data-pp="err"></span>' +
          '</div>' +
        '</div>';
      formEl.style.display = '';

      formEl.querySelector('[data-pp="cancel"]').addEventListener('click', () => { formEl.style.display = 'none'; });
      const af = formEl.querySelector('[data-pp="autoframe"]');
      if (af) af.addEventListener('click', doAutoframe);
      formEl.querySelector('[data-pp="save"]').addEventListener('click', () => doSave(plan ? plan.plan_id : 0));
    }

    function parseCentre() {
      const v = (formEl.querySelector('[data-pp-f="center"]').value || '').split(',').map(s => parseFloat(s.trim()));
      const out = { lat: v[0], lon: v[1] };
      if (v.length >= 3 && !isNaN(v[2])) formEl.querySelector('[data-pp-f="zoom"]').value = Math.round(v[2]);
      return out;
    }

    async function doAutoframe() {
      const d = await api('GET', '?autoframe=1&entity_type=' + encodeURIComponent(entityType) + '&entity_id=' + encodeURIComponent(entityId));
      if (d.ok && d.frame) {
        formEl.querySelector('[data-pp-f="center"]').value = d.frame.lat + ', ' + d.frame.lon;
        formEl.querySelector('[data-pp-f="zoom"]').value = d.frame.zoom;
      } else {
        formEl.querySelector('[data-pp="err"]').textContent = 'No geometry found for this record.';
      }
    }

    async function doSave(id) {
      const errEl = formEl.querySelector('[data-pp="err"]');
      errEl.textContent = '';
      const c = parseCentre();
      if (isNaN(c.lat) || isNaN(c.lon)) { errEl.textContent = 'Enter a valid centre (lat, lon).'; return; }
      const layers = [...formEl.querySelectorAll('[data-pp-layer]:checked')].map(x => x.value);
      const meta = {};
      formEl.querySelectorAll('[data-pp-m]').forEach(x => { if (x.value.trim()) meta[x.dataset.ppM] = x.value.trim(); });
      const payload = {
        id: id || undefined,
        entity_type: entityType, entity_id: entityId,
        title: formEl.querySelector('[data-pp-f="title"]').value.trim() || 'Site Plan',
        center_lat: c.lat, center_lon: c.lon,
        zoom: parseInt(formEl.querySelector('[data-pp-f="zoom"]').value, 10) || 16,
        orientation: formEl.querySelector('[data-pp-f="orientation"]').value,
        layers, meta,
      };
      const d = await api('POST', '', payload);
      if (d.ok) { formEl.style.display = 'none'; load(); }
      else errEl.textContent = d.error || 'Save failed';
    }

    load();
  };

  // Auto-init any <div data-plan-picker data-entity-type=… data-entity-id=… [data-autoframe]>
  function autoInit() {
    document.querySelectorAll('[data-plan-picker]').forEach(function (el) {
      if (el.dataset.ppInit) return;
      el.dataset.ppInit = '1';
      window.initPlanPicker(el, el.dataset.entityType, el.dataset.entityId, {
        canAutoframe: el.hasAttribute('data-autoframe'),
      });
    });
  }
  // Exposed so a page that builds its pickers dynamically can re-scan after a render. Idempotent — the
  // data-pp-init guard above skips containers that are already wired.
  window.initPlanPickers = autoInit;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
  else autoInit();
})();
