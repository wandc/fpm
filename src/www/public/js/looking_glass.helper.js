/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2025-2026 Tinuum Networks Inc. */
// looking_glass.helper.js — BGP Looking Glass frontend
'use strict';

// ── RIPE RIS collector metadata (24 global vantage points) ───────────────────
const RRC_META = {
    '0':  { name: 'RRC00', location: 'Amsterdam, NL' },
    '1':  { name: 'RRC01', location: 'London, UK' },
    '3':  { name: 'RRC03', location: 'Amsterdam, NL' },
    '4':  { name: 'RRC04', location: 'Geneva, CH' },
    '5':  { name: 'RRC05', location: 'Vienna, AT' },
    '6':  { name: 'RRC06', location: 'Tokyo, JP' },
    '7':  { name: 'RRC07', location: 'Stockholm, SE' },
    '10': { name: 'RRC10', location: 'Milan, IT' },
    '11': { name: 'RRC11', location: 'New York, US' },
    '12': { name: 'RRC12', location: 'Frankfurt, DE' },
    '13': { name: 'RRC13', location: 'Moscow, RU' },
    '14': { name: 'RRC14', location: 'Palo Alto, US' },
    '15': { name: 'RRC15', location: 'São Paulo, BR' },
    '16': { name: 'RRC16', location: 'Miami, US' },
    '17': { name: 'RRC17', location: 'Nairobi, KE' },
    '18': { name: 'RRC18', location: 'Barcelona, ES' },
    '19': { name: 'RRC19', location: 'Johannesburg, ZA' },
    '20': { name: 'RRC20', location: 'Zurich, CH' },
    '21': { name: 'RRC21', location: 'Paris, FR' },
    '22': { name: 'RRC22', location: 'Bucharest, RO' },
    '23': { name: 'RRC23', location: 'Singapore, SG' },
    '24': { name: 'RRC24', location: 'Montreal, CA' },
    '25': { name: 'RRC25', location: 'Amsterdam, NL' },
    '26': { name: 'RRC26', location: 'Dubai, AE' },
};
const RRC_IDS = Object.keys(RRC_META);

// Set of RRC IDs that are online (populated by fetchRrcInfo)
let onlineRrcIds = new Set(RRC_IDS); // optimistic default — updated on first run

// ── Global path analytics (reset per query run) ───────────────────────────────
// Keyed by family: 'v4' | 'v6'
// Each: { directUpstreams: Map<asn,count>, oneHopAway: Map<asn,count>, transit: Map<asn,count> }
function newFamilyTally() {
    return { directUpstreams: new Map(), oneHopAway: new Map(), transit: new Map() };
}
let pathTally = { v4: newFamilyTally(), v6: newFamilyTally() };
let queryAsn = ''; // bare number, excluded from tally

// ── Known ASN badges (logo + short label shown instead of bare number) ────────
const ASN_BADGE = {
    '3257':  { label: 'GTT',     domain: 'gtt.net' },
    '6939':  { label: 'HE',      domain: 'he.net' },
};
// Your own ASN: label and domain come from the installation's settings (app/org.php) via data attributes.
function homeBadge(asn) {
    const el = document.getElementById('lg-app');
    if (!el || !el.dataset.homeLabel) return undefined;
    if (String(el.dataset.homeAsn || '').replace(/^AS/i, '') !== String(asn)) return undefined;
    return { label: el.dataset.homeLabel, domain: (el.dataset.homeDomain || '').replace(/^www\./, '') };
}

// ── ASN name cache ────────────────────────────────────────────────────────────
// Populated lazily as paths are seen; keyed by ASN string e.g. "3257"
const asnNameCache = new Map();

async function lookupAsnNames(asns) {
    const unknown = asns.filter(a => !asnNameCache.has(a));
    if (unknown.length === 0) return;
    // Fetch in parallel, ignore failures
    await Promise.allSettled(unknown.map(async asn => {
        try {
            const url = `/api/looking_glass.php?action=asn_name&resource=${encodeURIComponent('AS' + asn)}`;
            const res = await fetch(url);
            const json = await res.json();
            const name = json.ripe?.data?.holder ?? null;
            if (name) asnNameCache.set(asn, name);
        } catch (_) { /* ignore */ }
    }));
}

function inc(map, key) { map.set(key, (map.get(key) || 0) + 1); }

function formatPath(pathStr) {
    if (!pathStr) return '—';
    return pathStr.split(' ').map(asn => {
        const badge = ASN_BADGE[asn] || homeBadge(asn);
        const name  = asnNameCache.get(asn);
        const cls   = name ? ' lg-asn-known' : '';
        if (badge) {
            const favicon = `https://www.google.com/s2/favicons?domain=${encodeURIComponent(badge.domain)}&sz=16`;
            return `<span class="lg-asn lg-asn-badge${cls}" data-asn="${escHtml(asn)}">`
                 + `<img src="${favicon}" width="12" height="12" alt="" class="lg-asn-favicon">`
                 + `${escHtml(badge.label)}</span>`;
        }
        return `<span class="lg-asn${cls}" data-asn="${escHtml(asn)}">${escHtml(asn)}</span>`;
    }).join(' ');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setStatus(msg) {
    document.getElementById('lg-status').textContent = msg;
}

async function apiFetch(action, resource = '') {
    const url = `/api/looking_glass.php?action=${encodeURIComponent(action)}&resource=${encodeURIComponent(resource)}`;
    const res = await fetch(url);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    return json.ripe;
}

async function fetchRrcInfo() {
    try {
        const data = await apiFetch('rrc_info', '');
        const activeIds = new Set();
        for (const rrc of (data.data?.rrcs || [])) {
            const id = String(rrc.id);
            if ((rrc.deactivated_on ?? '') === '' && (rrc.peers?.length ?? 0) > 0) {
                activeIds.add(id);
            }
        }
        if (activeIds.size > 0) onlineRrcIds = activeIds;
    } catch (_) { /* keep optimistic default */ }
}

// ── Prefix card builders ──────────────────────────────────────────────────────

function buildPrefixCard(prefix) {
    const card = document.createElement('div');
    card.className = 'lg-prefix-card';
    card.id = `lg-card-${prefix.replace(/[^a-zA-Z0-9]/g, '_')}`;

    const header = document.createElement('div');
    header.className = 'lg-prefix-header';
    header.innerHTML = `
        <span class="fw-bold">${escHtml(prefix)}</span>
        <span class="ms-2"><span class="lg-loading-spinner"></span></span>
        <span class="ms-auto" id="lg-vis-${escId(prefix)}"></span>`;
    card.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'lg-rrc-grid';
    grid.id = `lg-grid-${escId(prefix)}`;

    // Pre-populate with loading / offline tiles
    for (const rrcId of RRC_IDS) {
        const meta = RRC_META[rrcId];
        const tile = document.createElement('div');
        tile.id = `lg-tile-${escId(prefix)}-${rrcId}`;
        if (onlineRrcIds.has(rrcId)) {
            tile.className = 'lg-rrc-tile lg-rrc-loading';
            tile.innerHTML = `
                <div class="lg-rrc-name">${escHtml(meta.name)}</div>
                <div class="lg-rrc-loc">${escHtml(meta.location)}</div>
                <div class="lg-aspath" style="opacity:.4">querying…</div>`;
        } else {
            tile.className = 'lg-rrc-tile lg-rrc-offline';
            tile.innerHTML = `
                <div class="lg-rrc-name">${escHtml(meta.name)}</div>
                <div class="lg-rrc-loc">${escHtml(meta.location)}</div>
                <div class="lg-aspath">collector offline</div>`;
        }
        grid.appendChild(tile);
    }
    card.appendChild(grid);

    const summary = document.createElement('div');
    summary.className = 'lg-summary-row';
    summary.id = `lg-sum-${escId(prefix)}`;
    summary.textContent = 'Fetching BGP state…';
    card.appendChild(summary);

    return card;
}

function updatePrefixCard(prefix, bgpState) {
    // bgpState is a flat array of {source_id: "00-1.2.3.4", path: [asn,...], ...}
    // Group by RRC numeric id (the zero-padded prefix before the dash)
    const rrcPaths = {}; // rrcId (string) → array of path strings
    for (const entry of bgpState) {
        const rrcPadded = (entry.source_id || '').split('-')[0];
        if (!rrcPadded) continue;
        const rrcNum = String(parseInt(rrcPadded, 10)); // strip leading zero: "00" → "0"
        const pathStr = (entry.path || []).join(' ');
        if (!rrcPaths[rrcNum]) rrcPaths[rrcNum] = [];
        if (pathStr) rrcPaths[rrcNum].push(pathStr);
    }

    let seenCount = 0;
    let onlineCount = 0;
    const seenPaths = [];
    const allPathAsns = new Set();

    for (const rrcId of RRC_IDS) {
        const tile = document.getElementById(`lg-tile-${escId(prefix)}-${rrcId}`);
        if (!tile) continue;

        const meta = RRC_META[rrcId] || { name: `RRC${rrcId}`, location: '' };

        if (!onlineRrcIds.has(rrcId)) {
            // Already rendered as offline in buildPrefixCard; leave it
            continue;
        }
        onlineCount++;

        const paths = rrcPaths[rrcId] || [];
        const seen  = paths.length > 0;

        if (seen) seenCount++;

        // Best AS path: pick shortest
        let bestPath = '';
        for (const p of paths) {
            if (p && (!bestPath || p.split(' ').length < bestPath.split(' ').length)) {
                bestPath = p;
            }
        }

        tile.className = `lg-rrc-tile ${seen ? 'lg-rrc-seen' : 'lg-rrc-unseen'}`;
        const peerCount = paths.length;
        tile.innerHTML = `
            <div class="lg-rrc-name">${escHtml(meta.name)}</div>
            <div class="lg-rrc-loc">${escHtml(meta.location)}${peerCount > 0 ? ` · ${peerCount} peer${peerCount !== 1 ? 's' : ''}` : ''}</div>
            <div class="lg-aspath" id="lg-path-${escId(prefix)}-${rrcId}">${seen ? formatPath(bestPath || '—') : 'not seen'}</div>`;

        if (seen && bestPath) {
            seenPaths.push(bestPath);
            bestPath.split(' ').forEach(a => allPathAsns.add(a));
        }

        // Tally path analytics per address family
        const family = prefix.includes(':') ? 'v6' : 'v4';
        const tally = pathTally[family];
        for (const p of paths) {
            const hops = p.split(' ').filter(h => h && h !== queryAsn);
            if (hops.length === 0) continue;
            const origin = hops[hops.length - 1]; // our ASN already stripped, so origin = last hop = direct upstream
            // direct upstream = ASN immediately before ours (last in stripped path)
            inc(tally.directUpstreams, origin);
            // one-hop-away = ASN before the direct upstream
            if (hops.length >= 2) {
                inc(tally.oneHopAway, hops[hops.length - 2]);
            }
            // all transit (everything except origin in stripped path)
            for (let i = 0; i < hops.length - 1; i++) {
                inc(tally.transit, hops[i]);
            }
        }
    }

    // Visibility badge (only counts online collectors)
    const denominator = onlineCount || RRC_IDS.length;
    const pct = Math.round((seenCount / denominator) * 100);
    const badgeClass = pct >= 80 ? 'lg-vis-ok' : pct >= 40 ? 'lg-vis-warn' : 'lg-vis-none';
    const visBadge = document.getElementById(`lg-vis-${escId(prefix)}`);
    if (visBadge) {
        visBadge.innerHTML = `<span class="lg-vis-badge ${badgeClass}">${seenCount}/${denominator} online collectors · ${pct}%</span>`;
    }

    // Remove spinner from header
    const card = document.getElementById(`lg-card-${prefix.replace(/[^a-zA-Z0-9]/g, '_')}`);
    if (card) {
        const spinner = card.querySelector('.lg-loading-spinner');
        if (spinner) spinner.remove();
    }

    // Summary row: unique origin ASNs
    const originAsns = [...new Set(seenPaths.map(p => p.split(' ').pop()))].filter(Boolean);
    const sumEl = document.getElementById(`lg-sum-${escId(prefix)}`);
    if (sumEl) {
        sumEl.innerHTML = `
            <span>Visible from <strong>${seenCount}</strong> of <strong>${denominator}</strong> online RIPE RIS collectors</span>
            ${originAsns.length ? `<span>Origin AS: <strong class="font-monospace">${escHtml(originAsns.join(', '))}</strong></span>` : ''}`;
    }

    // Background ASN name lookup — update path cells once names arrive
    const asnList = [...allPathAsns].filter(Boolean);
    if (asnList.length > 0) {
        lookupAsnNames(asnList).then(() => {
            // Re-render path cells for this prefix with names
            for (const rrcId of RRC_IDS) {
                if (!onlineRrcIds.has(rrcId)) continue;
                const paths = rrcPaths[rrcId] || [];
                if (paths.length === 0) continue;
                let bestPath = '';
                for (const p of paths) {
                    if (p && (!bestPath || p.split(' ').length < bestPath.split(' ').length)) bestPath = p;
                }
                const pathEl = document.getElementById(`lg-path-${escId(prefix)}-${rrcId}`);
                if (pathEl && bestPath) pathEl.innerHTML = formatPath(bestPath);
            }
        });
    }
}

// ── Peering analysis panel ────────────────────────────────────────────────────

function mapTop(map, n) {
    return [...map.entries()].sort((a, b) => b[1] - a[1]).slice(0, n);
}

function tallyTable(entries, color, note) {
    if (entries.length === 0) return '<p class="text-muted" style="font-size:.8rem;padding:.25rem .5rem;">No data</p>';
    const max = entries[0][1];
    return `<table class="table table-sm table-borderless mb-0" style="font-size:.82rem;">
        <thead class="text-muted"><tr><th>ASN</th><th>Name</th><th class="text-end">Paths</th><th style="width:100px;padding-left:.75rem;">Frequency</th></tr></thead>
        <tbody>${entries.map(([asn, count]) => {
            const name = asnNameCache.get(asn) || '—';
            const pct  = Math.round((count / max) * 100);
            return `<tr>
                <td class="font-monospace text-nowrap">AS${escHtml(asn)}</td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(name)}">${escHtml(name)}</td>
                <td class="text-end font-monospace">${count}</td>
                <td style="padding-left:.75rem;"><div style="background:${color};height:8px;border-radius:4px;width:${pct}%"></div></td>
            </tr>`;
        }).join('')}</tbody>
    </table>${note ? `<p class="text-muted mb-0" style="font-size:.75rem;padding:.25rem .75rem;">${note}</p>` : ''}`;
}

function familySection(label, tally) {
    const upstreams   = mapTop(tally.directUpstreams, 10);
    const candidates  = mapTop(tally.oneHopAway, 15).filter(([asn]) => !tally.directUpstreams.has(asn));
    const allTransit  = mapTop(tally.transit, 20);

    return `
    <div class="lg-peer-family">
        <div class="lg-peer-family-label">${label}</div>
        <div class="lg-peer-cols">
            <div class="lg-peer-col">
                <div class="lg-peer-col-head">Your direct upstreams</div>
                ${tallyTable(upstreams, '#198754', 'ASNs that hand routes directly to you.')}
            </div>
            <div class="lg-peer-col">
                <div class="lg-peer-col-head">Prime peering candidates</div>
                ${tallyTable(candidates, '#0d6efd',
                    'One hop upstream of your providers. High count = many networks reach you <em>through</em> these ASNs — a direct peer would shorten those paths.')}
            </div>
            <div class="lg-peer-col">
                <div class="lg-peer-col-head">All transit seen</div>
                ${tallyTable(allTransit, '#6c757d', 'Every ASN appearing in any path, ranked by frequency.')}
            </div>
        </div>
    </div>`;
}

async function renderTransitSummary() {
    const el = document.getElementById('lg-transit-summary');
    if (!el) return;

    const allAsns = [...new Set([
        ...pathTally.v4.directUpstreams.keys(), ...pathTally.v4.oneHopAway.keys(), ...pathTally.v4.transit.keys(),
        ...pathTally.v6.directUpstreams.keys(), ...pathTally.v6.oneHopAway.keys(), ...pathTally.v6.transit.keys(),
    ])];
    if (allAsns.length === 0) { el.innerHTML = ''; return; }

    await lookupAsnNames(allAsns);

    const hasV4 = pathTally.v4.directUpstreams.size > 0;
    const hasV6 = pathTally.v6.directUpstreams.size > 0;

    el.innerHTML = `
        <div class="lg-transit-panel mt-3">
            <div class="lg-transit-header">BGP Path Analysis &amp; Peering Opportunities</div>
            <div class="lg-transit-body p-2">
                ${hasV4 ? familySection('IPv4', pathTally.v4) : ''}
                ${hasV6 ? familySection('IPv6', pathTally.v6) : ''}
            </div>
        </div>`;
}

// ── Main query flow ───────────────────────────────────────────────────────────

async function runLookingGlass(asn) {
    const resultsEl = document.getElementById('lg-results');
    resultsEl.innerHTML = '';
    // Reset path analytics for this run
    pathTally = { v4: newFamilyTally(), v6: newFamilyTally() };
    queryAsn = asn.replace(/^AS/i, ''); // bare number for comparison
    const transitEl = document.getElementById('lg-transit-summary');
    if (transitEl) transitEl.innerHTML = '';

    setStatus('Checking collector status…');

    // Fetch RRC online/offline status first
    await fetchRrcInfo();

    setStatus('Fetching announced prefixes…');

    let prefixes;
    try {
        const data = await apiFetch('announced_prefixes', asn);
        prefixes = (data.data?.prefixes || []).map(p => p.prefix).filter(Boolean);
    } catch (e) {
        setStatus('');
        resultsEl.innerHTML = `<div class="alert alert-danger">Failed to fetch announced prefixes: ${escHtml(e.message)}</div>`;
        return;
    }

    if (prefixes.length === 0) {
        setStatus('');
        resultsEl.innerHTML = `<div class="alert alert-warning">No prefixes announced by ${escHtml(asn)}. Make sure the AS number is correct and actively announcing routes.</div>`;
        return;
    }

    const onlineCount = RRC_IDS.filter(id => onlineRrcIds.has(id)).length;
    setStatus(`Found ${prefixes.length} announced prefix${prefixes.length !== 1 ? 'es' : ''}. Querying BGP state from ${onlineCount} online collectors…`);

    // Sort prefixes: IPv4 first, then IPv6
    prefixes.sort((a, b) => {
        const aIs6 = a.includes(':');
        const bIs6 = b.includes(':');
        if (aIs6 !== bIs6) return aIs6 ? 1 : -1;
        return a.localeCompare(b);
    });

    // Build all cards first so they appear immediately
    for (const prefix of prefixes) {
        resultsEl.appendChild(buildPrefixCard(prefix));
    }

    // Fetch BGP state for each prefix (sequentially to avoid hammering the API)
    let done = 0;
    for (const prefix of prefixes) {
        try {
            const data = await apiFetch('bgp_state', prefix);
            const bgpState = data.data?.bgp_state || [];
            updatePrefixCard(prefix, bgpState);
        } catch (e) {
            const sumEl = document.getElementById(`lg-sum-${escId(prefix)}`);
            if (sumEl) sumEl.textContent = `Error: ${e.message}`;
        }
        done++;
        setStatus(`Queried ${done} of ${prefixes.length} prefix${prefixes.length !== 1 ? 'es' : ''}…`);
    }

    setStatus(`Done — ${prefixes.length} prefix${prefixes.length !== 1 ? 'es' : ''} queried from ${onlineCount} online RIPE RIS collectors.`);
    await renderTransitSummary();
}

// ── Escape helpers ────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function escId(str) {
    return String(str).replace(/[^a-zA-Z0-9]/g, '_');
}

// ── Router BGP Sessions ───────────────────────────────────────────────────────

async function runRouterSessions() {
    const statusEl = document.getElementById('lg-rt-status');
    const resultsEl = document.getElementById('lg-rt-results');
    statusEl.textContent = 'Querying border router…';
    resultsEl.innerHTML = '';

    let data;
    try {
        const res = await fetch('/api/lg_router.php?action=bgp_sessions');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        data = json.routers;
    } catch (e) {
        statusEl.textContent = '';
        resultsEl.innerHTML = `<div class="alert alert-danger">Failed to load BGP sessions: ${escHtml(e.message)}</div>`;
        return;
    }

    // Collect all remote ASNs for name lookup
    const allAsns = [...new Set(data.flatMap(r => r.sessions.map(s => s.remote_as)))].filter(Boolean);
    await lookupAsnNames(allAsns);

    statusEl.textContent = '';

    // Group sessions by type label
    function peerLabel(s) {
        const name = asnNameCache.get(s.remote_as) || '';
        if (name) {
            // Shorten common names
            const short = name.replace(/ - .+/, '').replace(/,.*/, '').split(' ').slice(0, 3).join(' ');
            return `${short} (AS${s.remote_as})`;
        }
        return `AS${s.remote_as}`;
    }

    const html = data.map(router => {
        const established = router.sessions.filter(s => s.established).length;
        const total = router.sessions.length;

        const rows = router.sessions.map(s => {
            const upBadge = s.established
                ? '<span class="lg-rt-badge lg-rt-up">UP</span>'
                : '<span class="lg-rt-badge lg-rt-down">DOWN</span>';
            const pfx = s.prefix_count.toLocaleString();
            const afi = s.afi === 'ipv6' ? 'IPv6' : 'IPv4';
            const name = asnNameCache.get(s.remote_as) || '—';
            return `<tr>
                <td>${upBadge}</td>
                <td class="font-monospace" style="font-size:.8rem;">${escHtml(s.name)}</td>
                <td class="font-monospace">AS${escHtml(s.remote_as)}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(name)}">${escHtml(name.replace(/ - .+/, ''))}</td>
                <td><span class="badge bg-secondary" style="font-size:.7rem;">${afi}</span></td>
                <td class="text-end font-monospace">${pfx}</td>
                <td class="text-muted" style="font-size:.75rem;">${escHtml(s.uptime)}</td>
            </tr>`;
        }).join('');

        return `<div class="lg-rt-panel">
            <div class="lg-rt-header">
                <span>${established}/${total} peers up</span>
                <span class="lg-rt-badge ${established === total ? 'lg-rt-up' : 'lg-rt-down'}">${established === total ? 'All UP' : 'Degraded'}</span>
                <span class="lg-rt-router-name">${escHtml(router.router)}</span>
            </div>
            <div class="lg-rt-note">
                <strong>Live data from router.</strong>
                "Prefixes" = routes received from this peer.
                Individual route lookup isn't available via REST — the router has 1M+ BGP routes and
                the REST API times out serializing the full table. Use the CLI:
                <code>/routing/route/print where dst-address=X.X.X.X/Y</code>
            </div>
            <div style="overflow-x:auto;">
                <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                    <thead class="table-light text-muted">
                        <tr><th>Status</th><th>Session</th><th>ASN</th><th>Peer Name</th><th>Family</th><th class="text-end">Prefixes Rcvd</th><th>Uptime</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`;
    }).join('');

    resultsEl.innerHTML = html;
}

// ── BGP Route Lookup (real router table) ──────────────────────────────────────

/**
 * Return the first usable host of a prefix:
 *   IPv4 CIDR  → last octet replaced with 1  (e.g. 137.221.64.0/24 → 137.221.64.1)
 *   IPv6 CIDR  → ::1 appended to network     (e.g. 2001:db8::/32   → 2001:db8::1)
 * Returns [ip, label] or null if unparseable.
 */
function prefixFirstHost(prefix) {
    const net = prefix.split('/')[0];
    // IPv4
    const v4parts = net.split('.');
    if (v4parts.length === 4) {
        v4parts[3] = '1';
        const ip = v4parts.join('.');
        return { ip, label: '.1' };
    }
    // IPv6
    if (net.includes(':')) {
        let ip;
        if (net.endsWith('::'))       ip = net + '1';          // 2001:db8:: → 2001:db8::1
        else if (net.endsWith(':0'))  ip = net.slice(0, -1) + '1'; // :0 → :1
        else                          ip = net + (net.endsWith(':') ? '1' : '::1');
        return { ip, label: '::1' };
    }
    return null;
}

async function runTraceroute(target, routerName, containerEl) {
    containerEl.style.display = '';
    containerEl.innerHTML = `<div class="lg-tr-panel"><span class="text-muted" style="font-size:.82rem;">Tracing route to ${escHtml(target)} from ${escHtml(routerName)}…</span></div>`;

    let data;
    try {
        const res = await fetch(`/api/lg_router.php?action=traceroute&target=${encodeURIComponent(target)}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Traceroute failed');
        data = json;
    } catch (e) {
        containerEl.innerHTML = `<div class="lg-tr-panel"><span class="text-danger" style="font-size:.82rem;">Traceroute error: ${escHtml(e.message)}</span></div>`;
        return;
    }

    const hops = Array.isArray(data.hops) ? data.hops : [];
    if (hops.length === 0) {
        containerEl.innerHTML = `<div class="lg-tr-panel text-muted" style="font-size:.82rem;">No traceroute data returned.</div>`;
        return;
    }

    // RouterOS returns running results — stop at first time we reach the target
    // (avoids duplicate passes that appear when count > 1 or the tool loops)
    let hopNum = 0;
    const rows = [];
    for (const h of hops) {
        hopNum++;
        const addr    = h['address'] ?? h['host'] ?? '';
        const timeout = (h['status'] === 'timeout') || addr === '' || addr === '0.0.0.0';
        const rtt     = h['last'] || h['avg'] || h['avg-rtt'] || '';
        const isFinal = addr === target;
        if (timeout) {
            rows.push(`<tr><td class="lg-tr-hop-num">${hopNum}</td>
                <td class="lg-tr-hop-timeout" colspan="2">* * *  (no response)</td></tr>`);
        } else {
            rows.push(`<tr>
                <td class="lg-tr-hop-num">${hopNum}</td>
                <td class="lg-tr-hop-addr ${isFinal ? 'lg-tr-hop-dest' : ''}">${escHtml(addr)}</td>
                <td class="lg-tr-hop-rtt">${escHtml(rtt)}</td>
            </tr>`);
            if (isFinal) break; // reached destination — stop
        }
    }

    containerEl.innerHTML = `
        <div class="lg-tr-panel">
            <div style="font-size:.75rem;color:#6c757d;margin-bottom:.35rem;">
                Traceroute to <strong>${escHtml(target)}</strong> from <strong>${escHtml(routerName)}</strong> — ${rows.length} hop${rows.length !== 1 ? 's' : ''}
            </div>
            <table class="lg-tr-table"><tbody>${rows.join('')}</tbody></table>
        </div>`;
}

async function runPing(target, routerName, containerEl) {
    containerEl.className = 'lg-ping-result lg-ping-pending';
    containerEl.textContent = `Pinging ${target} from ${routerName}…`;

    let data;
    try {
        const res = await fetch(`/api/lg_router.php?action=ping&target=${encodeURIComponent(target)}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Ping failed');
        data = json;
    } catch (e) {
        containerEl.className = 'lg-ping-result lg-ping-fail';
        containerEl.textContent = `Ping error: ${e.message}`;
        return;
    }

    // RouterOS /tool/ping result: array of objects with host, sent, received, packet-loss, avg-rtt
    const result = Array.isArray(data.result) ? data.result[data.result.length - 1] : data.result;
    const sent     = result?.sent     ?? result?.['sent']     ?? '?';
    const received = result?.received ?? result?.['received'] ?? '?';
    const loss     = result?.['packet-loss'] ?? '';
    const avgRtt   = result?.['avg-rtt']     ?? '';
    const ok       = received !== '0' && received !== 0;

    containerEl.className = `lg-ping-result ${ok ? 'lg-ping-ok' : 'lg-ping-fail'}`;
    const rttPart  = avgRtt  ? ` · avg RTT ${avgRtt}`  : '';
    const lossPart = loss    ? ` · ${loss} loss`        : '';
    containerEl.textContent = ok
        ? `✓ ${target} — ${received}/${sent} packets received${rttPart}${lossPart}`
        : `✗ ${target} — unreachable (${sent} sent, ${received} received${lossPart})`;
}

function setObStatus(msg) {
    const el = document.getElementById('lg-ob-status');
    if (el) el.textContent = msg;
}

function renderHop(asn, active) {
    const name = asnNameCache.get(asn);
    const short = name ? name.replace(/ - .+/, '').split(',')[0] : null;
    const label = short ? `AS${escHtml(asn)} · ${escHtml(short)}` : `AS${escHtml(asn)}`;
    const title = name ? ` title="${escHtml(name)}"` : '';
    const cls = active ? 'lg-ob-hop-us' : 'lg-ob-hop';
    return `<span class="${cls}"${title}>${label}</span>`;
}

async function runRouteLookup(resource) {
    const resultsEl = document.getElementById('lg-ob-results');
    resultsEl.innerHTML = '';
    setObStatus(`Looking up ${resource} in router BGP table…`);

    let data;
    try {
        const res = await fetch(`/api/lg_router.php?action=route_lookup&resource=${encodeURIComponent(resource)}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        data = json;
    } catch (e) {
        setObStatus('');
        resultsEl.innerHTML = `<div class="alert alert-danger">${escHtml(e.message)}</div>`;
        return;
    }

    setObStatus('');
    const { prefix, router, routes } = data;

    if (!routes || routes.length === 0) {
        resultsEl.innerHTML = `<div class="alert alert-warning">No route found for <strong>${escHtml(prefix)}</strong> in the BGP table on ${escHtml(router)}.</div>`;
        return;
    }

    // Collect all ASNs for name lookup
    const allAsns = [...new Set(routes.flatMap(r => (r['bgp.as-path'] || '').split(',').filter(Boolean)))];
    await lookupAsnNames(allAsns);

    const activeRoute = routes.find(r => r.active === 'true') || routes[0];

    const rows = routes.map(r => {
        const asPath = (r['bgp.as-path'] || '').split(',').filter(Boolean);
        const isActive = r.active === 'true';
        const hops = asPath.map(asn => renderHop(asn, isActive)).join('<span class="lg-ob-arrow">→</span>');
        const peer = (r['belongs-to'] || '').replace(/^bgp-(IP|IPv6)-/, '');
        const localPref = r['bgp.local-pref'] ? `LP ${r['bgp.local-pref']}` : '';
        const med = r['bgp.med'] && r['bgp.med'] !== '0' ? `MED ${r['bgp.med']}` : '';
        return `<tr class="${isActive ? 'table-success' : ''}">
            <td>${isActive ? '<span class="badge bg-success">ACTIVE</span>' : '<span class="badge bg-secondary">alt</span>'}</td>
            <td><div class="lg-ob-path">${hops || '<em class="text-muted">direct</em>'}</div></td>
            <td class="font-monospace text-muted" style="font-size:.75rem;white-space:nowrap;">${escHtml(peer)}</td>
            <td class="text-muted" style="font-size:.75rem;white-space:nowrap;">${[localPref, med].filter(Boolean).join(' · ')}</td>
        </tr>`;
    }).join('');

    const firstHost = prefixFirstHost(prefix);
    const toolBtns = firstHost ? `
        <button class="lg-ping-btn btn btn-sm btn-outline-light ms-2" style="font-size:.75rem;padding:.1rem .5rem;" data-target="${escHtml(firstHost.ip)}">Ping ${escHtml(firstHost.label)}</button>
        <button class="lg-tr-btn btn btn-sm btn-outline-light ms-1" style="font-size:.75rem;padding:.1rem .5rem;" data-target="${escHtml(firstHost.ip)}">Traceroute</button>
    ` : '';

    resultsEl.innerHTML = `
        <div class="lg-ob-card">
            <div class="lg-ob-header">
                <span class="fw-bold font-monospace">${escHtml(prefix)}</span>
                ${toolBtns}
                <span class="ms-auto" style="font-size:.75rem;opacity:.7;">from ${escHtml(router)} BGP table · ${routes.length} path${routes.length !== 1 ? 's' : ''}</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="table table-sm mb-0" style="font-size:.82rem;">
                    <thead class="table-light text-muted"><tr><th>Status</th><th>AS Path</th><th>Via peer</th><th>Attrs</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div class="lg-ping-result" style="display:none;"></div>
            <div class="lg-tr-result" style="display:none;"></div>
        </div>`;

    const pingBtn = resultsEl.querySelector('.lg-ping-btn');
    if (pingBtn) {
        pingBtn.addEventListener('click', () => {
            const tgt = pingBtn.dataset.target;
            const pingResultEl = resultsEl.querySelector('.lg-ping-result');
            pingResultEl.style.display = '';
            pingBtn.disabled = true;
            pingBtn.textContent = 'Pinging…';
            runPing(tgt, router, pingResultEl).finally(() => {
                pingBtn.disabled = false;
                pingBtn.textContent = 'Ping .1';
            });
        });
    }

    const trBtn = resultsEl.querySelector('.lg-tr-btn');
    if (trBtn) {
        trBtn.addEventListener('click', () => {
            const tgt = trBtn.dataset.target;
            const trResultEl = resultsEl.querySelector('.lg-tr-result');
            trBtn.disabled = true;
            trBtn.textContent = 'Tracing…';
            runTraceroute(tgt, router, trResultEl).finally(() => {
                trBtn.disabled = false;
                trBtn.textContent = 'Traceroute';
            });
        });
    }
}

// ── Wire up ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const runBtn = document.getElementById('lg-run');
    const asnInput = document.getElementById('lg-asn');

    function doRun() {
        const raw = asnInput.value.trim().toUpperCase();
        if (!raw) {
            asnInput.focus();
            return;
        }
        // Normalise: prepend AS if user typed a bare number
        const asn = /^\d+$/.test(raw) ? `AS${raw}` : raw;
        asnInput.value = asn;
        runBtn.disabled = true;
        runBtn.textContent = 'Querying…';
        runLookingGlass(asn).finally(() => {
            runBtn.disabled = false;
            runBtn.textContent = 'Query Looking Glass';
        });
    }

    runBtn.addEventListener('click', doRun);
    asnInput.addEventListener('keydown', e => { if (e.key === 'Enter') doRun(); });

    const homeBtn = document.getElementById('lg-run-home');
    if (homeBtn) {
        homeBtn.addEventListener('click', () => {
            asnInput.value = homeBtn.dataset.homeAsn;
            doRun();
        });
    }

    // Router BGP sessions
    const rtRunBtn = document.getElementById('lg-rt-run');
    if (rtRunBtn) {
        rtRunBtn.addEventListener('click', () => {
            rtRunBtn.disabled = true;
            rtRunBtn.textContent = 'Loading…';
            runRouterSessions().finally(() => {
                rtRunBtn.disabled = false;
                rtRunBtn.textContent = 'Refresh';
            });
        });
    }

    // BGP route lookup
    const obRunBtn = document.getElementById('lg-ob-run');
    const obAsnInput = document.getElementById('lg-ob-asn');
    if (obRunBtn && obAsnInput) {
        function doObRun() {
            const resource = obAsnInput.value.trim();
            if (!resource) {
                obAsnInput.focus();
                return;
            }
            obRunBtn.disabled = true;
            obRunBtn.textContent = 'Looking up…';
            runRouteLookup(resource).finally(() => {
                obRunBtn.disabled = false;
                obRunBtn.textContent = 'Look Up';
            });
        }

        obRunBtn.addEventListener('click', doObRun);
        obAsnInput.addEventListener('keydown', e => { if (e.key === 'Enter') doObRun(); });
    }

    // Standalone ping & traceroute
    const netHostInput = document.getElementById('lg-net-host');
    const netPingBtn   = document.getElementById('lg-ping-run');
    const netTrBtn     = document.getElementById('lg-tr-run');

    if (netHostInput && netPingBtn) {
        function doNetPing() {
            const host = netHostInput.value.trim();
            if (!host) { netHostInput.focus(); return; }
            const resultEl = document.getElementById('lg-net-ping-result');
            resultEl.innerHTML = '';
            // wrap in a card-style container reusing ping strip classes
            const strip = document.createElement('div');
            strip.className = 'lg-ping-result lg-ping-pending mb-2';
            resultEl.appendChild(strip);
            netPingBtn.disabled = true;
            netPingBtn.textContent = 'Pinging…';
            runPing(host, 'border router', strip).finally(() => {
                netPingBtn.disabled = false;
                netPingBtn.textContent = 'Ping';
            });
        }
        netPingBtn.addEventListener('click', doNetPing);
        netHostInput.addEventListener('keydown', e => { if (e.key === 'Enter') doNetPing(); });
    }

    if (netHostInput && netTrBtn) {
        function doNetTr() {
            const host = netHostInput.value.trim();
            if (!host) { netHostInput.focus(); return; }
            const resultEl = document.getElementById('lg-net-tr-result');
            netTrBtn.disabled = true;
            netTrBtn.textContent = 'Tracing…';
            runTraceroute(host, 'border router', resultEl).finally(() => {
                netTrBtn.disabled = false;
                netTrBtn.textContent = 'Traceroute';
            });
        }
        netTrBtn.addEventListener('click', doNetTr);
        netHostInput.addEventListener('keydown', e => { if (e.key === 'Enter') doNetTr(); });
    }

    // ── ASN hover tooltip ─────────────────────────────────────────────────────
    // Delegated: works for any [data-asn] span rendered anywhere on the page.
    let asnTipEl = null;
    document.addEventListener('mouseover', async function (e) {
        const span = e.target.closest('[data-asn]');
        if (!span) return;
        const asn = span.dataset.asn;
        if (!asn) return;

        // Fetch name if not cached yet
        if (!asnNameCache.has(asn)) {
            span.classList.add('lg-asn-loading');
            await lookupAsnNames([asn]);
            span.classList.remove('lg-asn-loading');
            if (asnNameCache.has(asn)) span.classList.add('lg-asn-known');
        }
        const name = asnNameCache.get(asn);

        // Remove any existing tip
        if (asnTipEl) { asnTipEl.remove(); asnTipEl = null; }

        const label = name ? `AS${asn} — ${name}` : `AS${asn} (unknown)`;
        const tip = document.createElement('div');
        tip.className = 'lg-asn-tip';
        tip.textContent = label;
        document.body.appendChild(tip);
        asnTipEl = tip;

        // Position above the span
        const rect = span.getBoundingClientRect();
        tip.style.left = (rect.left + window.scrollX + rect.width / 2) + 'px';
        tip.style.top  = (rect.top  + window.scrollY - 6) + 'px';

        span.addEventListener('mouseleave', function () {
            if (asnTipEl) { asnTipEl.remove(); asnTipEl = null; }
        }, { once: true });
    });
});
