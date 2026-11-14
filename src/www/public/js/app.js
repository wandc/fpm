/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
document.addEventListener('DOMContentLoaded', function () {
    // Restore accordion open/closed state from localStorage (key: "acc:<id>")
    document.querySelectorAll('.accordion-collapse[id]').forEach(function (el) {
        const stored = localStorage.getItem('acc:' + el.id);
        if (stored === null) return; // no stored preference — let PHP default stand
        const btn = document.querySelector('[data-bs-target="#' + CSS.escape(el.id) + '"]');
        if (stored === '1') {
            el.classList.add('show');
            if (btn) btn.classList.remove('collapsed');
        } else {
            el.classList.remove('show');
            if (btn) btn.classList.add('collapsed');
        }
    });

    // Persist show/hide events for any accordion on the page
    document.addEventListener('show.bs.collapse', function (e) {
        if (e.target.id) localStorage.setItem('acc:' + e.target.id, '1');
    });
    document.addEventListener('hide.bs.collapse', function (e) {
        if (e.target.id) localStorage.setItem('acc:' + e.target.id, '0');
    });
});

// ── Icon-rail navigation ─────────────────────────────────────────────────────
// Hover and keyboard focus open a group's flyout in CSS. This adds the click
// path, which is what touch devices and deliberate clicks need, plus Escape
// and click-away to close.
document.addEventListener('DOMContentLoaded', function () {
    var rail = document.getElementById('navRail');
    if (!rail) return;

    var groups = Array.prototype.slice.call(rail.querySelectorAll('[data-rail-group]'));

    function closeAll(except) {
        groups.forEach(function (g) {
            if (g === except) return;
            g.classList.remove('is-open');
            var btn = g.querySelector('.nav-rail-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    groups.forEach(function (g) {
        var btn = g.querySelector('.nav-rail-btn');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var open = g.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            closeAll(g);
        });
    });

    document.addEventListener('click', function (e) {
        if (!rail.contains(e.target)) closeAll(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
    });

    // Ctrl+K / Cmd+K, and the rail's magnifier, both jump to the global search
    var search = document.getElementById('globalSearch');
    var railSearch = document.getElementById('navRailSearch');

    if (railSearch && search) {
        railSearch.addEventListener('click', function () {
            search.focus();
            search.select();
        });
    }

    if (search) {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                closeAll(null);
                search.focus();
                search.select();
            }
        });
    }
});
