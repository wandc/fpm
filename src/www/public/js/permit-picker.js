/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
/**
 * Permit autocomplete — the single implementation for every permit-number input.
 *
 * Attach by marking an input and calling PermitPicker.init(csrf):
 *
 *   data-permit-picker           Tinuum permit list (default)
 *   data-permit-picker="bell"    Bell permit list (read-only — Bell permits are
 *                                created on the Bell Permits page, not inline)
 *   data-permit-multi            chip mode: picking fires a `permit:selected`
 *                                CustomEvent (detail = permit) and clears the input
 *   data-permit-comma            the field holds a comma-separated list: match on
 *                                the segment being typed and replace only that one
 *
 * Suggestions filter as you type (substring, case-insensitive). For the Tinuum
 * list an unmatched value offers a "Create" row, which registers the permit in
 * app.fpm_permit so it autocompletes everywhere else from then on.
 */
(function () {
  const SOURCES = {
    tinuum: { url: '/api/fpm_permits_list.php', key: 'permits', canCreate: true,
              emptyLabel: 'No permits on file' },
    bell:   { url: '/api/bell_permits_list.php',   key: 'permits', canCreate: false,
              emptyLabel: 'No Bell permits on file' },
  };

  const _lists   = {};   // kind → string[]
  const _loading = {};   // kind → Promise
  let   _csrf    = null; // string | () => string

  function csrf() {
    const v = typeof _csrf === 'function' ? _csrf() : _csrf;
    return v || window._csrf || '';
  }

  function kindOf(inp) {
    const v = (inp.getAttribute('data-permit-picker') || '').trim().toLowerCase();
    return SOURCES[v] ? v : 'tinuum';
  }

  function load(kind) {
    if (_lists[kind])   return Promise.resolve(_lists[kind]);
    if (_loading[kind]) return _loading[kind];
    _loading[kind] = fetch(SOURCES[kind].url)
      .then(r => r.json())
      .then(data => {
        _lists[kind] = data[SOURCES[kind].key] || [];
        return _lists[kind];
      })
      .catch(() => [])          // offline / error: empty list, retried on next open
      .finally(() => { delete _loading[kind]; });
    return _loading[kind];
  }

  // ── comma-list helpers (data-permit-comma) ─────────────────────────────────
  const commaParts   = v => v.split(',');
  const commaQuery   = v => (commaParts(v).pop() || '').trim();
  const commaChosen  = v => commaParts(v).slice(0, -1).map(s => s.trim()).filter(Boolean);
  function commaReplaceLast(v, permit) {
    const parts = commaParts(v);
    parts[parts.length - 1] = ' ' + permit;
    return parts.join(',').replace(/^\s+/, '');
  }

  function attach(inp) {
    const kind    = kindOf(inp);
    const src     = SOURCES[kind];
    const isMulti = inp.hasAttribute('data-permit-multi');
    const isComma = inp.hasAttribute('data-permit-comma');

    const drop = document.createElement('div');
    Object.assign(drop.style, {
      position: 'fixed', zIndex: '1090', background: '#fff',
      border: '1px solid #ced4da', borderRadius: '6px',
      boxShadow: '0 6px 24px rgba(0,0,0,.18)',
      maxHeight: '240px', overflowY: 'auto',
      minWidth: '200px', display: 'none', padding: '4px 0',
    });
    document.body.appendChild(drop);

    let isOpen = false;

    function reposition() {
      const r = inp.getBoundingClientRect();
      drop.style.top   = (r.bottom + 2) + 'px';
      drop.style.left  = r.left + 'px';
      drop.style.width = Math.max(r.width, 220) + 'px';
    }

    function makeRow(label, onClick, style) {
      const d = document.createElement('div');
      d.style.cssText = 'padding:6px 12px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.875rem';
      if (style === 'create') {
        d.style.color = '#0d6efd'; d.style.fontWeight = '500';
        d.style.borderTop = '1px solid #dee2e6'; d.style.marginTop = '3px';
      }
      if (style === 'empty') { d.style.color = '#6c757d'; d.style.cursor = 'default'; }
      d.textContent = label;
      if (style !== 'empty') {
        d.addEventListener('mouseenter', () => d.style.background = '#eef2ff');
        d.addEventListener('mouseleave', () => d.style.background = '');
      }
      d.addEventListener('mousedown', e => { e.preventDefault(); onClick && onClick(); });
      return d;
    }

    function select(permit) {
      if (isMulti) {
        inp.value = '';
        inp.dispatchEvent(new CustomEvent('permit:selected', { detail: permit, bubbles: true }));
        render();
        return;
      }
      inp.value = isComma ? commaReplaceLast(inp.value, permit) : permit;
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      close();
    }

    function render() {
      drop.innerHTML = '';
      const all    = _lists[kind] || [];
      const typed  = isComma ? commaQuery(inp.value) : inp.value.trim();
      const q      = typed.toLowerCase();
      // Already-listed permits are not offered again (comma fields only — chip
      // fields don't know their own chips, the page owns that list).
      const taken   = isComma ? commaChosen(inp.value).map(s => s.toLowerCase()) : [];
      const matches = all.filter(p => (!q || p.toLowerCase().includes(q))
                                   && !taken.includes(p.toLowerCase()));

      if (!matches.length) {
        drop.appendChild(makeRow(q ? 'No match' : src.emptyLabel, null, 'empty'));
      }
      matches.forEach(p => drop.appendChild(makeRow(p, () => select(p))));

      if (src.canCreate && typed && !all.some(p => p.toLowerCase() === q)) {
        drop.appendChild(makeRow('＋ Create "' + typed + '"', async () => {
          if (!isMulti) close();
          try {
            const r = await fetch(src.url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
              body: JSON.stringify({ permit_num: typed }),
            });
            const j = await r.json();
            if (j.ok && !(_lists[kind] || []).includes(typed)) {
              _lists[kind] = [...(_lists[kind] || []), typed].sort((a, b) => a.localeCompare(b));
            }
          } catch (_) { /* the value is still saved with the feature */ }
          select(typed);
        }, 'create'));
      }
    }

    function open() {
      if (inp.disabled || inp.readOnly) return;
      reposition();
      drop.style.display = '';
      isOpen = true;
      render();
      // First open (or a previously failed fetch) pulls the list, then re-renders.
      if (!_lists[kind]) load(kind).then(() => { if (isOpen) render(); });
    }

    function close() {
      drop.style.display = 'none';
      isOpen = false;
    }

    inp.addEventListener('focus', open);
    inp.addEventListener('click', () => { if (!isOpen) open(); });
    inp.addEventListener('input', () => { if (isOpen) render(); else open(); });
    inp.addEventListener('blur', () => setTimeout(close, 160));
    inp.addEventListener('keydown', e => {
      if (e.key === 'Escape') { close(); return; }
      if (!isOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { open(); return; }
      if (!isOpen) return;
      const rows = [...drop.children].filter(el => el.style.cursor !== 'default');
      const activeIdx = rows.findIndex(el => el.dataset.active === '1');
      const highlight = idx => rows.forEach((el, i) => {
        const on = i === idx;
        el.dataset.active = on ? '1' : '';
        el.style.background = on ? '#eef2ff' : '';
        if (on) el.scrollIntoView({ block: 'nearest' });
      });
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(Math.min(activeIdx + 1, rows.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(Math.max(activeIdx - 1, 0));
      } else if (e.key === 'Enter') {
        const active = rows.find(el => el.dataset.active === '1');
        if (active) { e.preventDefault(); active.dispatchEvent(new MouseEvent('mousedown')); }
      }
    });
  }

  window.PermitPicker = {
    /**
     * Attach to every [data-permit-picker] input on the page (idempotent — safe to
     * call again after adding inputs to the DOM).
     * @param {string|function} csrfToken token, or a getter read at fetch time
     */
    init(csrfToken) {
      if (csrfToken) _csrf = csrfToken;
      const kinds = new Set();
      document.querySelectorAll('[data-permit-picker]').forEach(inp => {
        kinds.add(kindOf(inp));
        if (inp.dataset.ppInit) return;
        inp.dataset.ppInit = '1';
        attach(inp);
      });
      // Warm the lists so the first keystroke already has suggestions.
      kinds.forEach(load);
    },

    /** Drop the cached list so the next open refetches (after creating a permit). */
    refresh(kind) {
      if (kind) delete _lists[kind];
      else Object.keys(SOURCES).forEach(k => delete _lists[k]);
    },

    /** Current cached list for a kind (default 'tinuum'). */
    list(kind) { return (_lists[kind || 'tinuum'] || []).slice(); },
  };

  // Back-compat alias for pages that called the older standalone helper.
  window.initPermitPickerStandalone = function (csrfToken) {
    window.PermitPicker.init(csrfToken);
  };
})();
