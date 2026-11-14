<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/uisp.php
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('UISP Network Status');

$PAGE->setContentRenderer(function () {

    // Fetch data from UISP API (DB-cached)
    global $pdo, $CSRF;
    $uispDevices = uisp_fetch_cached('devices', 120, $pdo);
    $uispSites   = uisp_fetch_cached('sites?type=endpoint', 3600, $pdo);
    $uispError   = null;

    // ── Device health (populated by tools/poller_uisp_events.php) ────────────
    // SOLO events only. Anything two or more devices did at the same moment is a
    // common event (app.uisp_common_event) with a cause upstream of both, so it's
    // excluded — what survives points at the device itself.
    // Keep in step with poller_uisp_events.php (FLAG_THRESHOLD).
    $FLAG_THRESHOLD = 2;

    $healthRows = $pdo->prepare("
        SELECT * FROM app.v_uisp_device_health
        WHERE score_events >= :threshold
        ORDER BY score_events DESC, last_event_at DESC
    ");
    $healthRows->execute([':threshold' => $FLAG_THRESHOLD]);
    $health = $healthRows->fetchAll();

    // Recent event history for the flagged devices, for the expandable detail rows.
    // Carries the common-event kind so each line can say why it wasn't counted.
    $historyByDevice = [];
    if ($health) {
        $hist = $pdo->prepare("
            SELECT e.device_id, e.occurred_at, e.kind, e.common_event_id,
                   c.kind AS common_kind, c.device_count AS common_devices
            FROM app.uisp_device_event e
            LEFT JOIN app.uisp_common_event c USING (common_event_id)
            WHERE e.device_id = ANY(:ids::uuid[])
              AND e.kind IN ('disconnect', 'restart', 'pon_down', 'connect', 'pon_up')
              AND e.occurred_at > now() - INTERVAL '30 days'
            ORDER BY e.occurred_at DESC
        ");
        $hist->execute([':ids' => '{' . implode(',', array_column($health, 'device_id')) . '}']);
        foreach ($hist->fetchAll() as $e) {
            $historyByDevice[$e['device_id']][] = $e;
        }
    }

    // Signal levels, so you can tell a bad router (signal fine) from a bad drop.
    $signalByDevice = [];
    if (!isset($uispDevices['error'])) {
        foreach ($uispDevices as $d) {
            $did = $d['identification']['id'] ?? null;
            if ($did) $signalByDevice[$did] = $d['overview']['signal'] ?? null;
        }
    }

    // Correlated events — shown separately, never charged to any one device.
    $commonEvents = $pdo->query("
        SELECT * FROM app.uisp_common_event ORDER BY started_at DESC LIMIT 20
    ")->fetchAll();

    // How each common-event kind is described wherever it appears.
    $commonMeta = [
        'outage'        => ['Power outage',  'bg-info text-dark',      'Many devices dropped together — utility power or upstream.'],
        'nms_reconnect' => ['UISP restart',  'bg-secondary',           'Devices re-registered with UISP. Nobody lost service.'],
        'local'         => ['Shared event',  'bg-primary',             'A few devices moved together — shared splitter, pole, or transformer.'],
    ];

    $onuDevices   = [];
    $infraDevices = [];
    $sitesByName  = [];

    if (isset($uispDevices['error'])) {
        $uispError = $uispDevices['error'];
    } elseif (isset($uispSites['error'])) {
        $uispError = $uispSites['error'];
    } else {
        foreach ($uispSites as $site) {
            $sid = $site['id'] ?? ($site['identification']['id'] ?? null);
            if ($sid) $sitesByName[$sid] = $site;
        }
        foreach ($uispDevices as $dev) {
            $type = $dev['identification']['type'] ?? '';
            if ($type === 'onu') {
                $onuDevices[] = $dev;
            } else {
                $infraDevices[] = $dev;
            }
        }
        // Sort ONUs by signal (weakest first — potential problem devices at top)
        usort($onuDevices, fn($a, $b) =>
            ($a['overview']['signal'] ?? 0) <=> ($b['overview']['signal'] ?? 0)
        );
    }

    ob_start();
    ?>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2 class="mb-0">UISP Network Status</h2>
                <p class="text-muted small mb-0">Data cached at: <?= (new DateTimeImmutable())->format('Y-m-d H:i:s') ?></p>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="btnUispRefresh">&#8635; Refresh from UISP</button>
        </div>
        <script>
        document.getElementById('btnUispRefresh').addEventListener('click', async function () {
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Refreshing\u2026';
            try {
                const r = await fetch('/api/uisp_refresh.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': '<?= h($CSRF) ?>' },
                });
                const d = await r.json();
                if (d.ok) {
                    window.location.reload();
                } else {
                    alert('Refresh failed: ' + (d.error || 'unknown error'));
                    btn.disabled = false;
                    btn.textContent = '\u21BB Refresh from UISP';
                }
            } catch (e) {
                alert('Refresh error: ' + e.message);
                btn.disabled = false;
                btn.textContent = '\u21BB Refresh from UISP';
            }
        });
        </script>

        <style>
        /* UISP ONU table — hide detail columns unless expanded */
        #onuTable .onu-detail-col { display: none; }
        #onuTable.onu-cols-expanded .onu-detail-col { display: table-cell; }
        /* Sticky first column */
        #onuTable th:first-child,
        #onuTable td:first-child {
            position: sticky;
            left: 0;
            background: inherit;
            z-index: 1;
        }
        #onuTable thead th:first-child { background: #212529; }
        </style>
        <script>
        (function () {
            var LS_KEY = 'fpm_onu_cols_expanded';
            var btn   = document.getElementById('onuColToggle');
            var table = document.getElementById('onuTable');
            if (!btn || !table) return;
            function apply(expanded) {
                table.classList.toggle('onu-cols-expanded', expanded);
                btn.textContent = expanded ? '⚙ Fewer columns' : '⚙ More columns';
            }
            apply(localStorage.getItem(LS_KEY) === '1');
            btn.addEventListener('click', function () {
                var exp = !table.classList.contains('onu-cols-expanded');
                localStorage.setItem(LS_KEY, exp ? '1' : '0');
                apply(exp);
            });
        })();
        </script>

        <!-- ── Device Health ───────────────────────────────────── -->
        <?php
            $kindLabel = [
                'disconnect' => ['Disconnected', 'bg-danger'],
                'restart'    => ['Restarted',    'bg-warning text-dark'],
                'pon_down'   => ['PON down',     'bg-danger'],
                'connect'    => ['Reconnected',  'bg-success'],
                'pon_up'     => ['PON up',       'bg-success'],
            ];
        ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h5 class="mb-0">
                    Device Health
                    <span class="badge <?= $health ? 'bg-danger' : 'bg-success' ?>"><?= count($health) ?></span>
                </h5>
                <small class="text-muted me-auto">
                    Devices that dropped or self-restarted <strong>alone</strong> &mdash;
                    <?= $FLAG_THRESHOLD ?>+ events in 30 days. Anything 2+ devices did together is
                    a common event and doesn't count against either of them.
                </small>
                <?php if ($commonEvents): ?>
                <button class="btn btn-outline-secondary btn-sm text-nowrap" type="button"
                        data-bs-toggle="collapse" data-bs-target="#commonEvents">
                    ⚡ Common events (<?= count($commonEvents) ?>)
                </button>
                <?php endif; ?>
            </div>

            <?php if ($commonEvents): ?>
            <div class="collapse" id="commonEvents">
                <div class="card-body border-bottom bg-body-tertiary py-2">
                    <div class="small text-muted mb-2">
                        Events shared by 2+ devices, correlated by time across the whole fleet.
                        The cause sits upstream of any one device, so none of these are scored.
                    </div>
                    <?php foreach ($commonEvents as $m):
                        [$cLbl, $cCls, $cHint] = $commonMeta[$m['kind']] ?? [$m['kind'], 'bg-secondary', ''];
                    ?>
                        <div class="d-flex gap-2 small py-1 border-top align-items-center">
                            <span class="text-nowrap" style="min-width:9rem">
                                <?= h((new DateTime($m['started_at']))->format('M j, H:i')) ?>
                            </span>
                            <span class="badge <?= $cCls ?>" style="min-width:7rem" title="<?= h($cHint) ?>">
                                <?= h($cLbl) ?>
                            </span>
                            <span class="badge bg-dark"><?= (int)$m['device_count'] ?> devices</span>
                            <span class="text-muted text-truncate">
                                <?= h(implode(', ', array_slice(
                                        explode(',', trim($m['sites'], '{}')), 0, 5))) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-body p-0">
                <?php if (!$health): ?>
                    <div class="text-muted text-center py-4">
                        No devices flagging. Every recent drop was part of a multi-device power outage.
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer / Site</th>
                            <th>Model</th>
                            <th>Signal (dBm)</th>
                            <th class="text-end">Events (30d)</th>
                            <th class="text-end">Drops</th>
                            <th class="text-end">Restarts</th>
                            <th class="text-end">PON</th>
                            <th>Last event</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($health as $i => $r):
                        $sig    = $signalByDevice[$r['device_id']] ?? null;
                        $rowId  = 'devhist' . $i;
                        $events = (int)$r['score_events'];
                        // GPON banding (as in the ONU table) only means anything for an ONU —
                        // an airMAX/Wave radio sits happily around −60 dBm.
                        $sigCls = 'text-muted';
                        if ($sig !== null && $r['device_type'] === 'onu') {
                            $sigCls = ($sig > -6.5 || $sig < -29.5) ? 'text-danger fw-bold'
                                    : (($sig > -8.0 || $sig < -28.0) ? 'text-warning fw-bold' : 'text-success');
                        }
                    ?>
                        <tr>
                            <td>
                                <strong><?= h($r['site_name'] !== '' ? $r['site_name'] : $r['device_name']) ?></strong>
                                <?php if ($events >= 10): ?>
                                    <span class="badge bg-danger ms-1">severe</span>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:.75rem"><?= h((string)$r['device_mac']) ?></div>
                            </td>
                            <td class="small"><?= h((string)$r['device_model']) ?></td>
                            <td class="<?= $sigCls ?>"><?= $sig === null ? '—' : h((string)$sig) ?></td>
                            <td class="text-end fw-bold"><?= $events ?></td>
                            <td class="text-end"><?= (int)$r['disconnects'] ?></td>
                            <td class="text-end"><?= (int)$r['restarts'] ?></td>
                            <td class="text-end"><?= (int)$r['pon_downs'] ?: '—' ?></td>
                            <td class="small text-nowrap">
                                <?= h((new DateTime($r['last_event_at']))->format('M j, H:i')) ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-secondary btn-sm py-0" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#<?= $rowId ?>">
                                    History
                                </button>
                            </td>
                        </tr>
                        <tr class="p-0">
                            <td colspan="9" class="p-0 border-0">
                                <div class="collapse" id="<?= $rowId ?>">
                                    <div class="p-3 bg-body-tertiary small">
                                        <?php foreach (($historyByDevice[$r['device_id']] ?? []) as $e):
                                            [$lbl, $cls] = $kindLabel[$e['kind']] ?? [$e['kind'], 'bg-secondary'];
                                            $common = $e['common_event_id'] !== null;
                                            [$cLbl, $cCls] = $common
                                                ? ($commonMeta[$e['common_kind']] ?? [$e['common_kind'], 'bg-secondary'])
                                                : [null, null];
                                        ?>
                                            <div class="d-flex gap-2 py-1 border-top align-items-center <?= $common ? 'opacity-50' : '' ?>">
                                                <span class="text-nowrap text-muted" style="min-width:9rem">
                                                    <?= h((new DateTime($e['occurred_at']))->format('M j, H:i:s')) ?>
                                                </span>
                                                <span class="badge <?= $cls ?>" style="min-width:6.5rem"><?= h($lbl) ?></span>
                                                <?php if ($common): ?>
                                                    <span class="badge <?= $cCls ?>">
                                                        <?= h($cLbl) ?> &middot; <?= (int)$e['common_devices'] ?> devices
                                                    </span>
                                                    <span class="text-muted" style="font-size:.7rem">not counted</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="text-muted mt-2" style="font-size:.75rem">
                                            Greyed-out lines happened to other devices at the same moment, so the cause is
                                            upstream of this one and they don't count toward its score.
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($uispError): ?>
            <div class="alert alert-danger"><strong>UISP API error:</strong> <?= h($uispError) ?></div>
        <?php else: ?>

        <!-- ── Customer ONUs ───────────────────────────────────── -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h5 class="mb-0">Customer ONUs <span class="badge bg-secondary"><?= count($onuDevices) ?></span></h5>
                <small class="text-muted me-auto">
                    Sorted by signal level (weakest first) &mdash; GPON range −8 to −28 dBm &nbsp;
                    <span class="text-danger fw-bold">&gt;−6.5</span> (too strong)
                    <span class="text-warning fw-bold ms-1">−6.5 to −8</span>
                    <span class="text-success fw-bold ms-1">−8 to −28</span> (normal)
                    <span class="text-warning fw-bold ms-1">−28 to −29.5</span>
                    <span class="text-danger fw-bold ms-1">&lt;−29.5</span> (too weak)
                </small>
                <button class="btn btn-outline-secondary btn-sm text-nowrap" id="onuColToggle" type="button">
                    ⚙ More columns
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0" id="onuTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer / Site</th>
                            <th>ONU MAC</th>
                            <th>Model</th>
                            <th>MikroTik IP</th>
                            <th>Signal (dBm)</th>
                            <th class="onu-detail-col">Bias (mA)</th>
                            <th class="onu-detail-col">Voltage (V)</th>
                            <th class="onu-detail-col">Temp (°C)</th>
                            <th class="onu-detail-col">CPU %</th>
                            <th class="onu-detail-col">RAM %</th>
                            <th>Uptime</th>
                            <th>Status</th>
                            <th>Service Plan</th>
                            <th>Speed (↓/↑)</th>
                            <th>Address</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($onuDevices as $dev):
                        $ov      = $dev['overview'] ?? [];
                        $id      = $dev['identification'] ?? [];
                        $siteId  = $id['site']['id'] ?? null;
                        $siteRec = $siteId ? ($sitesByName[$siteId] ?? null) : null;
                        $ucrm    = $siteRec['ucrm'] ?? null;
                        $qos     = $siteRec['qos'] ?? null;
                        $addr    = $siteRec['description']['address'] ?? '—';
                        $signal  = isset($ov['signal']) ? (int)$ov['signal'] : null;
                        $statusVal = $ov['status'] ?? 'unknown';
                        $statusBadge = match($statusVal) {
                            'active'       => 'success',
                            'disconnected' => 'danger',
                            default        => 'secondary',
                        };
                        // ONU MAC normalised for data attribute (used by JS for DHCP cross-ref)
                        $onuMacNorm = strtolower(str_replace(':', '', $id['mac'] ?? ''));
                    ?>
                        <tr data-onu-mac="<?= h($onuMacNorm) ?>">
                            <td><strong><?= h($id['site']['name'] ?? $id['name'] ?? '—') ?></strong></td>
                            <td><code><?= h($id['mac'] ?? '—') ?></code></td>
                            <td><?= h($id['modelName'] ?? '—') ?></td>
                            <td class="dhcp-ip text-muted small"><span class="spinner-border spinner-border-sm" role="status"></span></td>
                            <td class="<?= gpon_signal_class($signal) ?> fw-bold">
                                <?= $signal !== null ? $signal . ' dBm' : '—' ?>
                            </td>
                            <td class="onu-detail-col"><?= isset($ov['biasCurrent']) ? h($ov['biasCurrent']) : '—' ?></td>
                            <td class="onu-detail-col"><?= isset($ov['voltage']) ? h($ov['voltage']) : '—' ?></td>
                            <td class="onu-detail-col"><?= isset($ov['temperature']) ? h($ov['temperature']) : '—' ?></td>
                            <td class="onu-detail-col"><?= isset($ov['cpu']) ? h($ov['cpu']) . '%' : '—' ?></td>
                            <td class="onu-detail-col"><?= isset($ov['ram']) ? h($ov['ram']) . '%' : '—' ?></td>
                            <td><?= uisp_format_uptime(isset($ov['uptime']) ? (int)$ov['uptime'] : null) ?></td>
                            <td><span class="badge bg-<?= $statusBadge ?>"><?= h($statusVal) ?></span></td>
                            <td><?= h($ucrm['service']['name'] ?? '—') ?></td>
                            <td class="text-nowrap">
                                <?= uisp_format_speed($qos['downloadSpeed'] ?? null) ?> /
                                <?= uisp_format_speed($qos['uploadSpeed'] ?? null) ?>
                            </td>
                            <td class="text-muted small"><?= h($addr) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <script>
        // Cross-reference ONU MACs with MikroTik DHCP leases
        // Matching: ONU MAC (normalised, no colons) == lease.agent-remote-id
        (function () {
            async function enrichOnuIps() {
                let data;
                try {
                    const resp = await fetch('/api/dhcp_leases.php');
                    data = await resp.json();
                } catch (e) {
                    document.querySelectorAll('#onuTable td.dhcp-ip').forEach(td => {
                        td.textContent = '—';
                    });
                    return;
                }

                const byRemoteId = data.by_remote_id || {};
                const byMac      = data.by_mac      || {};

                // Age of the DHCP cache — colour-code staleness
                const fetchedAt = data.fetched_at ? new Date(data.fetched_at) : null;
                const ageMs  = fetchedAt ? (Date.now() - fetchedAt.getTime()) : Infinity;
                const ageMins = Math.floor(ageMs / 60000);
                const ageSecs = Math.floor((ageMs % 60000) / 1000);
                const ageStr  = ageMs === Infinity ? 'unknown age'
                              : ageMins > 0 ? `${ageMins}m ago` : `${ageSecs}s ago`;
                const ageClass = ageMs > 600000 ? 'text-danger'     // > 10 min
                               : ageMs > 180000 ? 'text-warning'    // > 3 min
                               : 'text-muted';

                document.querySelectorAll('#onuTable tr[data-onu-mac]').forEach(row => {
                    const mac = row.dataset.onuMac;
                    const td  = row.querySelector('td.dhcp-ip');
                    if (!td) return;

                    // Try remote-id match first (Option 82), then direct MAC match
                    const lease = byRemoteId[mac] || byMac[mac] || null;

                    if (!lease || !lease.address) {
                        td.innerHTML = `<span class="text-muted">—</span>`
                            + `<br><small class="${ageClass}">${ageStr}</small>`;
                        return;
                    }

                    const matchType = byRemoteId[mac] ? 'Opt82' : 'MAC';
                    const host = lease.hostname ? ` <small class="text-muted">${esc(lease.hostname)}</small>` : '';
                    const status = lease.status === 'bound' ? '' : ` <span class="badge bg-warning text-dark">${esc(lease.status)}</span>`;
                    const ci = decodeCircuitId(lease.circuit_id, lease.address, lease.server);
                    const portBadge = ci && ci.port ? ` <span class="badge bg-secondary" title="GPON port">P${esc(ci.port)}</span>` : '';
                    const vlanBadge = ci && ci.c_vlan ? ` <span class="badge bg-info text-dark" title="Customer VLAN">V${esc(ci.c_vlan)}</span>` : '';
                    const ipv6 = (lease.ipv6_prefix && lease.ipv6_prefix_len)
                        ? `<br><code class="text-info">${esc(lease.ipv6_prefix)}/${esc(lease.ipv6_prefix_len)}</code>`
                        : '';
                    td.innerHTML = `<code>${esc(lease.address)}</code>${host}${status}${ipv6}`
                        + `<br><small class="${ageClass}">${matchType} · ${esc(lease.router)} · ${ageStr}</small>${portBadge}${vlanBadge}`;
                });
            }

            function esc(s) {
                return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            // Decode Ubiquiti OLT Option 82 circuit-id:
            // Format: <olt_mac>/<gpon_port>/<onu_mac>[/<s_vlan>:<c_vlan>]
            // VLAN fallback priority when circuit_id omits the VLAN segment:
            //   1. circuit_id 4th segment  (authoritative when OLT includes it)
            //   2. DHCP server name        gpon-{VLAN}-dhcp (MikroTik server name)
            //   3. CGNAT IP third octet    100.64.{VLAN}.host
            function decodeCircuitId(raw, address, server) {
                const parts = raw ? raw.split('/') : [];
                const hasCircuit = parts.length >= 3;
                const gponPort = hasCircuit ? parts[1] : null;
                const vlanPart = hasCircuit ? (parts[3] || '') : '';
                const [sVlan, cVlan] = vlanPart ? vlanPart.split(':') : [];

                let derivedVlan = cVlan || '';
                if (!derivedVlan && server) {
                    const ms = server.match(/gpon-(\d+)-/);
                    if (ms) derivedVlan = ms[1];
                }
                if (!derivedVlan && address) {
                    const m = address.match(/^100\.64\.(\d+)\./);
                    if (m) derivedVlan = m[1];
                }

                if (!hasCircuit && !derivedVlan) return null;
                return { port: gponPort, s_vlan: sVlan || '', c_vlan: derivedVlan };
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enrichOnuIps);
            } else {
                enrichOnuIps();
            }
        })();
        </script>

        <!-- ── Infrastructure Devices ─────────────────────────── -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Infrastructure Devices <span class="badge bg-secondary"><?= count($infraDevices) ?></span></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Model</th>
                            <th>MAC Address</th>
                            <th>IP Address</th>
                            <th>CPU %</th>
                            <th>RAM %</th>
                            <th>Uptime</th>
                            <th>Status</th>
                            <th>Site</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($infraDevices as $dev):
                        $ov  = $dev['overview'] ?? [];
                        $id  = $dev['identification'] ?? [];
                        $statusVal = $ov['status'] ?? 'unknown';
                        $statusBadge = match($statusVal) {
                            'active'       => 'success',
                            'disconnected' => 'danger',
                            default        => 'secondary',
                        };
                    ?>
                        <tr>
                            <td><strong><?= h($id['name'] ?? '—') ?></strong></td>
                            <td><span class="badge bg-info text-dark"><?= h($id['type'] ?? '—') ?></span></td>
                            <td><?= h($id['modelName'] ?? '—') ?></td>
                            <td><code><?= h($id['mac'] ?? '—') ?></code></td>
                            <td><code><?= h($dev['ipAddress'] ?? '—') ?></code></td>
                            <td><?= isset($ov['cpu']) ? h($ov['cpu']) . '%' : '—' ?></td>
                            <td><?= isset($ov['ram']) ? h($ov['ram']) . '%' : '—' ?></td>
                            <td><?= uisp_format_uptime(isset($ov['uptime']) ? (int)$ov['uptime'] : null) ?></td>
                            <td><span class="badge bg-<?= $statusBadge ?>"><?= h($statusVal) ?></span></td>
                            <td><?= h($id['site']['name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>


        <?php endif; // no uispError ?>
    </div>
    <?php
    return ob_get_clean();
});
