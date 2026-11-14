<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/otdr.php — OTDR SOR file library
/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;

$PAGE->setTitle('OTDR Results');
$PAGE->addExternalJs('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js');

// Load all closures for the link form
$closures = $pdo->query("
    SELECT fosc_id, splice_num FROM app.fosc ORDER BY splice_num
")->fetchAll(PDO::FETCH_ASSOC);

// Load all cable spans for the link form
$spans = $pdo->query("
    SELECT cs.cable_span_id,
           fa.splice_num || ' ↔ ' || fb.splice_num AS label
    FROM app.cable_span cs
    JOIN app.fosc fa ON fa.fosc_id = cs.from_fosc_id
    JOIN app.fosc fb ON fb.fosc_id = cs.to_fosc_id
    ORDER BY fa.splice_num, fb.splice_num
")->fetchAll(PDO::FETCH_ASSOC);

$PAGE->setContentRenderer(function () use ($CSRF, $closures, $spans) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">OTDR Results</h3>
      <button class="btn btn-success" id="btnUpload">Upload .sor Files</button>
    </div>

    <!-- Hidden file input -->
    <input type="file" id="sorFileInput" accept=".sor" multiple style="display:none">

    <!-- Upload progress -->
    <div id="uploadStatus" class="mb-3" style="display:none"></div>

    <!-- Results table -->
    <div class="table-responsive" id="resultsTableWrap">
      <table class="table table-hover align-middle" id="resultsTable">
        <thead>
          <tr>
            <th></th><!-- expand toggle -->
            <th>Filename</th>
            <th>Tested</th>
            <th>λ (nm)</th>
            <th>Length</th>
            <th>Loss</th>
            <th>Supplier / Model</th>
            <th>Linked To</th>
            <th></th><!-- actions -->
          </tr>
        </thead>
        <tbody id="resultsBody">
          <tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Expand panel template (hidden, cloned by JS) -->
    <template id="expandTpl">
      <tr class="expand-row">
        <td colspan="9" class="p-0">
          <div class="border-top border-bottom bg-light p-3">
            <div class="row g-3">
              <div class="col-lg-8">
                <canvas class="otdr-chart" height="180"></canvas>
              </div>
              <div class="col-lg-4">
                <h6>Events</h6>
                <div class="events-wrap" style="max-height:220px;overflow-y:auto">
                  <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>Dist (m)</th><th>Net (m)</th><th>Loss (dB)</th><th>Refl (dB)</th><th>Type</th></tr></thead>
                    <tbody class="events-body"></tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class="mt-3 link-form-wrap">
              <h6>Link to Closure</h6>
              <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                  <label class="form-label form-label-sm mb-1">Closure</label>
                  <select class="form-select form-select-sm sel-fosc">
                    <option value="">— select closure —</option>
                    <?php foreach ($closures as $c): ?>
                      <option value="<?= (int)$c['fosc_id'] ?>"><?= h($c['splice_num']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label form-label-sm mb-1">Cable Span (optional)</label>
                  <select class="form-select form-select-sm sel-span">
                    <option value="">— none —</option>
                    <?php foreach ($spans as $s): ?>
                      <option value="<?= (int)$s['cable_span_id'] ?>"><?= h($s['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-2">
                  <label class="form-label form-label-sm mb-1">Fiber #</label>
                  <input type="number" class="form-control form-control-sm inp-fiber" min="1" max="9999" placeholder="e.g. 12">
                </div>
                <div class="col-sm-2">
                  <button class="btn btn-sm btn-primary btn-link-result w-100">Link</button>
                </div>
              </div>
            </div>
          </div>
        </td>
      </tr>
    </template>

    <script>
    (function () {
      const CSRF = <?= json_encode($CSRF) ?>;
      let expandedId = null;
      let expandChart = null;

      // ── Upload ────────────────────────────────────────────────────────────────

      document.getElementById('btnUpload').addEventListener('click', () => {
        document.getElementById('sorFileInput').click();
      });

      document.getElementById('sorFileInput').addEventListener('change', async function () {
        const files = this.files;
        if (!files.length) return;

        const status = document.getElementById('uploadStatus');
        status.style.display = '';
        status.innerHTML = '<div class="alert alert-info">Uploading ' + files.length + ' file(s)…</div>';

        const fd = new FormData();
        for (const f of files) fd.append('files[]', f);

        try {
          const resp = await fetch('/api/otdr_upload.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: fd,
          });
          const data = await resp.json();
          if (data.ok) {
            status.innerHTML = '<div class="alert alert-success">Uploaded ' + data.ids.length + ' file(s) successfully.</div>';
            loadResults();
          } else {
            status.innerHTML = '<div class="alert alert-danger">' + (data.error || 'Upload failed') + '</div>';
          }
          if (data.warnings?.length) {
            status.innerHTML += '<div class="alert alert-warning">' + data.warnings.join('<br>') + '</div>';
          }
        } catch (e) {
          status.innerHTML = '<div class="alert alert-danger">Network error: ' + e.message + '</div>';
        }

        this.value = '';
        setTimeout(() => { status.style.display = 'none'; }, 5000);
      });

      // ── Load Results ──────────────────────────────────────────────────────────

      async function loadResults() {
        const resp = await fetch('/api/otdr_result.php');
        const data = await resp.json();
        if (!data.ok) return;
        renderTable(data.results);
      }

      function renderTable(results) {
        const tbody = document.getElementById('resultsBody');
        // Close any open expand
        expandedId = null;
        if (expandChart) { expandChart.destroy(); expandChart = null; }
        tbody.innerHTML = '';

        if (!results.length) {
          tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No OTDR results yet. Upload .sor files to get started.</td></tr>';
          return;
        }

        for (const r of results) {
          const tr = document.createElement('tr');
          tr.dataset.id = r.id;
          tr.style.cursor = 'pointer';

          const linked = r.fosc_splice_num
            ? `<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">${esc(r.fosc_splice_num)}${r.fiber_num ? ' F' + r.fiber_num : ''}</span>`
            : '<span class="text-muted small">— Unlinked</span>';

          const launch    = parseFloat(r.parsed_meta?.launch_cable_m ?? 0) || 0;
          const netLen    = r.fiber_length_m != null ? parseFloat(r.fiber_length_m) - launch : null;
          const lengthStr = netLen != null ? (netLen / 1000).toFixed(3) + ' km' + (launch > 0 ? ' <span class="text-muted small">(+' + Math.round(launch) + 'm launch)</span>' : '') : '—';
          const lossStr   = r.total_loss_db  != null ? parseFloat(r.total_loss_db).toFixed(2)  + ' dB'  : '—';
          const testedStr = r.tested_at ? r.tested_at.replace('T', ' ').substring(0, 16) : '—';

          tr.innerHTML = `
            <td class="text-muted" style="width:1.5rem"><i class="toggle-icon">▶</i></td>
            <td><strong>${esc(r.filename)}</strong></td>
            <td class="text-nowrap small">${esc(testedStr)}</td>
            <td>${r.wavelength_nm ?? '—'}</td>
            <td class="text-nowrap">${lengthStr}</td>
            <td class="text-nowrap">${lossStr}</td>
            <td class="small">${r.supplier ? esc(r.supplier) + (r.model ? ' / ' + esc(r.model) : '') : '—'}</td>
            <td>${linked}</td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="/api/otdr_result.php?id=${r.id}&download=1">⬇</a>
              <button class="btn btn-sm btn-outline-danger btn-delete" data-id="${r.id}">✕</button>
            </td>
          `;

          tr.querySelector('.btn-delete').addEventListener('click', (e) => {
            e.stopPropagation();
            deleteResult(r.id);
          });

          tr.addEventListener('click', (e) => {
            if (e.target.closest('.btn-delete, a')) return;
            toggleExpand(r.id, tr);
          });

          tbody.appendChild(tr);
        }
      }

      // ── Expand Row ────────────────────────────────────────────────────────────

      async function toggleExpand(id, tr) {
        // Close existing expand
        const existing = document.querySelector('.expand-row');
        if (existing) {
          existing.remove();
          if (expandChart) { expandChart.destroy(); expandChart = null; }
          const prevTr = document.querySelector(`tr[data-id="${expandedId}"] .toggle-icon`);
          if (prevTr) prevTr.textContent = '▶';
        }
        if (expandedId === id) { expandedId = null; return; }
        expandedId = id;
        tr.querySelector('.toggle-icon').textContent = '▼';

        // Fetch full data
        const resp = await fetch('/api/otdr_result.php?id=' + id);
        const data = await resp.json();
        if (!data.ok) return;
        const r = data.result;

        const tpl = document.getElementById('expandTpl').content.cloneNode(true);
        const expandTr = tpl.querySelector('tr');

        // Link form pre-fill
        if (r.fosc_id) {
          expandTr.querySelector('.sel-fosc').value    = r.fosc_id;
          expandTr.querySelector('.sel-span').value    = r.cable_span_id ?? '';
          expandTr.querySelector('.inp-fiber').value   = r.fiber_num ?? '';
        }

        // Link / Unlink button
        const linkBtn = expandTr.querySelector('.btn-link-result');
        if (r.fosc_id) {
          const unlinkBtn = document.createElement('button');
          unlinkBtn.className = 'btn btn-sm btn-outline-warning ms-2';
          unlinkBtn.textContent = 'Unlink';
          unlinkBtn.addEventListener('click', () => linkResult(id, null, true));
          linkBtn.parentElement.appendChild(unlinkBtn);
        }
        linkBtn.addEventListener('click', () => {
          const foscId = parseInt(expandTr.querySelector('.sel-fosc').value) || null;
          const spanId = parseInt(expandTr.querySelector('.sel-span').value) || null;
          const fiber  = parseInt(expandTr.querySelector('.inp-fiber').value) || null;
          linkResult(id, foscId, false, spanId, fiber);
        });

        // Launch cable info
        const launchM = parseFloat(r.parsed_meta?.launch_cable_m ?? 0) || 0;
        if (launchM > 0) {
          const h6 = expandTr.querySelector('.events-wrap').previousElementSibling;
          h6.innerHTML = `Events <span class="badge text-bg-secondary ms-1">Launch cable: ${Math.round(launchM)} m</span>`;
        }

        // Events table
        const evTbody = expandTr.querySelector('.events-body');
        for (const ev of (r.events ?? [])) {
          const net = launchM > 0 && ev.distance_m != null ? Math.round(ev.distance_m - launchM) : null;
          const netStr = net != null ? (net < 0 ? '<span class="text-muted">' + net + '</span>' : net) : '—';
          const row = document.createElement('tr');
          row.innerHTML = `<td>${ev.num}</td><td>${ev.distance_m?.toFixed(0) ?? '—'}</td><td>${netStr}</td><td>${ev.loss_db != null ? ev.loss_db.toFixed(3) : '—'}</td><td>${ev.reflectance_db != null ? ev.reflectance_db.toFixed(3) : '—'}</td><td>${eventTypeBadge(ev.type ?? '')}</td>`;
          evTbody.appendChild(row);
        }

        tr.insertAdjacentElement('afterend', expandTr);

        // Chart
        const pts = r.trace_pts ?? [];
        if (pts.length) {
          const canvas = expandTr.querySelector('canvas.otdr-chart');
          const ctx = canvas.getContext('2d');

          // Strip launch cable: only show points at or beyond the launch offset
          const visible = launchM > 0 ? pts.filter(p => p[0] >= launchM) : pts;

          // Downsample to ~2000 pts for display
          const step = Math.max(1, Math.floor(visible.length / 2000));
          const ds = visible.filter((_, i) => i % step === 0);

          // Event annotation styles (color + short label for chart)
          const EVENT_CHART_STYLE = {
            '0S': { color: '#6c757d', short: 'Launch'    },
            '0F': { color: '#198754', short: 'Splice'     },
            '1F': { color: '#fd7e14', short: 'Connector'  },
            '0E': { color: '#212529', short: 'End'        },
            '1E': { color: '#212529', short: 'End'        },
            '0A': { color: '#0d6efd', short: 'Added'      },
            '1A': { color: '#0d6efd', short: 'Added'      },
          };
          const chartEvents = (r.events ?? []).filter(ev => ev.distance_m != null);

          // Toggle button injected above canvas
          let showEventLabels = true;
          const toggleBtn = document.createElement('button');
          toggleBtn.className = 'btn btn-outline-secondary mb-1';
          toggleBtn.style.cssText = 'font-size:.72rem;padding:.15rem .5rem';
          toggleBtn.textContent = 'Events ✓';
          toggleBtn.addEventListener('click', () => {
            showEventLabels = !showEventLabels;
            toggleBtn.textContent = showEventLabels ? 'Events ✓' : 'Events';
            expandChart.update();
          });
          canvas.parentElement.insertBefore(toggleBtn, canvas);

          // Inline Chart.js plugin — draws vertical lines + rotated labels at event positions
          const evAnnotationPlugin = {
            id: 'otdrEventLines',
            afterDatasetsDraw(chart) {
              if (!showEventLabels || !chartEvents.length) return;
              const c = chart.ctx;
              const xScale = chart.scales.x;
              const yScale = chart.scales.y;
              c.save();
              for (const ev of chartEvents) {
                const netDist = ev.distance_m - launchM;
                const style = EVENT_CHART_STYLE[ev.type] ?? { color: '#999', short: ev.type ?? '?' };
                const xPx = xScale.getPixelForValue(netDist);
                if (xPx < xScale.left || xPx > xScale.right) continue;
                // Vertical dashed line
                c.beginPath();
                c.setLineDash([4, 3]);
                c.strokeStyle = style.color;
                c.lineWidth = 1;
                c.globalAlpha = 0.65;
                c.moveTo(xPx, yScale.top);
                c.lineTo(xPx, yScale.bottom);
                c.stroke();
                // Rotated label
                c.setLineDash([]);
                c.globalAlpha = 1;
                c.save();
                c.translate(xPx + 3, yScale.top + 6);
                c.rotate(Math.PI / 2);
                c.font = 'bold 9px sans-serif';
                c.fillStyle = style.color;
                c.fillText(style.short, 0, 0);
                c.restore();
              }
              c.restore();
            }
          };

          expandChart = new Chart(ctx, {
            type: 'line',
            plugins: [evAnnotationPlugin],
            data: {
              datasets: [{
                data: ds.map(p => ({ x: p[0] - launchM, y: Math.abs(p[1]) })),
                borderColor: '#0d6efd',
                borderWidth: 1,
                pointRadius: 0,
                tension: 0,
                parsing: false,
              }]
            },
            options: {
              responsive: true,
              animation: false,
              plugins: { legend: { display: false } },
              scales: {
                x: {
                  type: 'linear',
                  title: { display: true, text: launchM > 0 ? 'Net Distance (m)' : 'Distance (m)' },
                  ticks: { maxTicksLimit: 10 },
                },
                y: {
                  title: { display: true, text: 'Level (dB)' },
                  reverse: true,
                }
              }
            }
          });
        } else {
          expandTr.querySelector('.col-lg-8').innerHTML = '<p class="text-muted">No trace data.</p>';
        }
      }

      // ── Link / Unlink ─────────────────────────────────────────────────────────

      async function linkResult(id, foscId, unlink, spanId = null, fiberNum = null) {
        if (!unlink && !foscId) { alert('Select a closure first.'); return; }

        const body = unlink
          ? { id, unlink: true }
          : { id, fosc_id: foscId, cable_span_id: spanId, fiber_num: fiberNum };

        const resp = await fetch('/api/otdr_result.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify(body),
        });
        const data = await resp.json();
        if (data.ok) {
          loadResults();
        } else {
          alert('Error: ' + (data.error || 'unknown'));
        }
      }

      // ── Delete ────────────────────────────────────────────────────────────────

      async function deleteResult(id) {
        if (!confirm('Delete this OTDR result?')) return;
        const resp = await fetch('/api/otdr_result.php?id=' + id, {
          method: 'DELETE',
          headers: { 'X-CSRF-Token': CSRF },
        });
        const data = await resp.json();
        if (data.ok) {
          loadResults();
        } else {
          alert('Error: ' + (data.error || 'unknown'));
        }
      }

      // ── Helpers ───────────────────────────────────────────────────────────────

      function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      const EVENT_TYPE_MAP = {
        '0S': { label: '▶\u202fLaunch',       cls: 'text-bg-secondary' },
        '0F': { label: '〰\u202fFusion splice', cls: 'text-bg-success'   },
        '1F': { label: '⬡\u202fConnector',     cls: 'text-bg-warning'   },
        '0E': { label: '■\u202fEnd of fiber',  cls: 'text-bg-dark'      },
        '1E': { label: '■\u202fEnd of fiber',  cls: 'text-bg-dark'      },
        '0A': { label: '+\u202fAdded',          cls: 'text-bg-info'      },
        '1A': { label: '+\u202fAdded',          cls: 'text-bg-info'      },
      };

      function eventTypeBadge(type) {
        const t = EVENT_TYPE_MAP[type];
        if (!t) return `<code class="text-muted">${esc(type)}</code>`;
        return `<span class="badge ${t.cls}" title="${esc(type)}" style="font-size:.75em">${t.label}</span>`;
      }

      // ── Init ──────────────────────────────────────────────────────────────────

      loadResults();
    })();
    </script>
    <?php
    return ob_get_clean();
});
