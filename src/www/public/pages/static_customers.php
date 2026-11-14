<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/static_customers.php — Authoritative static customer IP assignment records
/** @var \FPM\App\Template $PAGE */

require_once __DIR__ . '/../phpHelpers/uisp.helper.php';

global $pdo, $CSRF;

// ── Helpers ───────────────────────────────────────────────────────────────────

// VLAN a customer should be on, from their plan (tinuum.ca/services.php) and whether they hold a static IP.
//   233 Customer Static IP | 211 Customer Dynamic/Public IP | 222 Customer CGNAT
// Fibre 250 is shared (NAT) IPv4, so CGNAT; Biz Gig / Biz 2.5 include a static IPv4. Unknown plans return null.
function sc_expected_vlan(string $plan, bool $hasStatic): ?int
{
    if ($hasStatic) return 233;
    return match (strtolower(trim($plan))) {
        'fibre 250'                          => 222,
        'fibre 500', 'gig 1.0', 'gig 2.5', 'biz 500' => 211,
        'biz gig', 'biz 2.5'                 => 233,
        default                              => null,
    };
}

function sc_normalize_mac(string $mac): string
{
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    if (strlen($hex) !== 12) return strtoupper($mac); // let validation catch it
    return implode(':', str_split($hex, 2));
}

function sc_validate(array $p): void
{
    if (trim($p['name'] ?? '') === '') throw new RuntimeException('Name is required.');

    $mac = $p['mac_address'] ?? '';
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
        throw new RuntimeException('MAC address is invalid (expected AA:BB:CC:DD:EE:FF).');
    }
    if (!filter_var($p['static_ipv4'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('Static IPv4 address is invalid.');
    }
    $pfx = trim($p['ipv6_prefix'] ?? '');
    if (!preg_match('/^(.+)\/(\d+)$/', $pfx, $m) || (int)$m[2] !== 56) {
        throw new RuntimeException('IPv6 prefix must be a /56 (e.g. 2602:f994:23:100::/56).');
    }
    if (inet_pton($m[1]) === false) {
        throw new RuntimeException('IPv6 prefix address part is invalid.');
    }
    $vlan = (int)($p['vlan'] ?? 233);
    if ($vlan < 1 || $vlan > 4094) throw new RuntimeException('VLAN must be 1–4094.');
}

/**
 * Cross-check a single static customer against DHCP/UISP caches.
 * Returns ['status' => 'ok'|'mismatch'|'unknown', 'detail' => array].
 */
function sc_run_check(PDO $pdo, array $sc): array
{
    $macNorm = strtolower(str_replace(':', '', $sc['mac_address']));
    $detail  = [];
    $found   = false;

    // ── DHCP lease cache ──────────────────────────────────────────────────────
    try {
        $rows = $pdo->query("SELECT router_name, leases FROM app.dhcp_lease_cache")->fetchAll();
        foreach ($rows as $row) {
            $leases = json_decode($row['leases'], true) ?: [];
            foreach ($leases as $lease) {
                if (($lease['mac_norm'] ?? '') !== $macNorm) continue;
                $found = true;

                // IPv4 check
                $cacheIp = $lease['address'] ?? '';
                if ($cacheIp !== (string)$sc['static_ipv4']) {
                    $detail[] = [
                        'field'  => 'static_ipv4',
                        'stored' => (string)$sc['static_ipv4'],
                        'cache'  => $cacheIp,
                        'source' => 'dhcp/' . $row['router_name'],
                    ];
                }

                // IPv6 check
                $cacheV6pfx = $lease['ipv6_prefix']     ?? '';
                $cacheV6len = (int)($lease['ipv6_prefix_len'] ?? 0);
                if ($cacheV6pfx !== '' || $cacheV6len > 0) {
                    preg_match('/^(.+)\/(\d+)$/', (string)$sc['ipv6_prefix'], $sm);
                    $storedPfx = strtolower(rtrim($sm[1] ?? '', ':') . '::');
                    $storedLen = (int)($sm[2] ?? 56);
                    $cacheNorm = strtolower(rtrim($cacheV6pfx, ':') . '::');
                    if ($cacheNorm !== $storedPfx || $cacheV6len !== $storedLen) {
                        $detail[] = [
                            'field'  => 'ipv6_prefix',
                            'stored' => (string)$sc['ipv6_prefix'],
                            'cache'  => $cacheV6pfx . '/' . $cacheV6len,
                            'source' => 'dhcp/' . $row['router_name'],
                        ];
                    }
                }
                break 2;
            }
        }
    } catch (PDOException $e) {
        // cache may be empty — treat as unknown
    }

    if (!$found) {
        // ── UISP cache ────────────────────────────────────────────────────────
        try {
            $devRow = $pdo->query("SELECT data FROM app.uisp_cache WHERE endpoint = 'devices'")->fetch();
            if ($devRow) {
                foreach (json_decode($devRow['data'], true) ?: [] as $dev) {
                    $devMac = strtolower(str_replace(':', '', $dev['identification']['mac'] ?? ''));
                    if ($devMac !== $macNorm) continue;
                    $found = true;
                    $siteName = $dev['identification']['site']['name'] ?? '';
                    if ($siteName !== '' && strcasecmp($siteName, $sc['name']) !== 0) {
                        $detail[] = [
                            'field'  => 'name',
                            'stored' => $sc['name'],
                            'cache'  => $siteName,
                            'source' => 'uisp',
                            'note'   => 'UISP site name differs (informational)',
                        ];
                    }
                    break;
                }
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    if (!$found) {
        return ['status' => 'unknown', 'detail' => []];
    }
    return ['status' => empty($detail) ? 'ok' : 'mismatch', 'detail' => $detail];
}

// ── POST handling (PRG pattern) ───────────────────────────────────────────────
if (is_post()) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        flash_set('Bad CSRF token.', false);
        header('Location: index.php?page=static_customers');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $mac = sc_normalize_mac($_POST['mac_address'] ?? '');
            $p   = [
                'name'         => trim($_POST['name']         ?? ''),
                'mac_address'  => $mac,
                'duid'         => trim($_POST['duid']         ?? '0x00030001AABBCCDDEEFF'),
                'iaid'         => trim($_POST['iaid']         ?? '1'),
                'static_ipv4'  => trim($_POST['static_ipv4']  ?? ''),
                'ipv6_prefix'  => trim($_POST['ipv6_prefix']  ?? ''),
                'vlan'         => (int)($_POST['vlan']        ?? 233),
                'notes'        => trim($_POST['notes']        ?? ''),
                'uisp_site_id' => trim($_POST['uisp_site_id'] ?? '') ?: null,
            ];
            sc_validate($p);
            $pdo->prepare("
                INSERT INTO app.static_customer
                    (name, mac_address, duid, iaid, static_ipv4, ipv6_prefix, vlan, notes, uisp_site_id)
                VALUES
                    (:name, :mac, :duid, :iaid, :ipv4::inet, :ipv6::cidr, :vlan, :notes, :uisp)
            ")->execute([
                ':name'  => $p['name'],
                ':mac'   => $p['mac_address'],
                ':duid'  => $p['duid'],
                ':iaid'  => $p['iaid'],
                ':ipv4'  => $p['static_ipv4'],
                ':ipv6'  => $p['ipv6_prefix'],
                ':vlan'  => $p['vlan'],
                ':notes' => $p['notes'],
                ':uisp'  => $p['uisp_site_id'],
            ]);
            flash_set('Customer added.');

        } elseif ($action === 'edit') {
            $id  = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid ID.');
            $mac = sc_normalize_mac($_POST['mac_address'] ?? '');
            $p   = [
                'name'         => trim($_POST['name']         ?? ''),
                'mac_address'  => $mac,
                'duid'         => trim($_POST['duid']         ?? ''),
                'iaid'         => trim($_POST['iaid']         ?? ''),
                'static_ipv4'  => trim($_POST['static_ipv4']  ?? ''),
                'ipv6_prefix'  => trim($_POST['ipv6_prefix']  ?? ''),
                'vlan'         => (int)($_POST['vlan']        ?? 233),
                'notes'        => trim($_POST['notes']        ?? ''),
                'uisp_site_id' => trim($_POST['uisp_site_id'] ?? '') ?: null,
            ];
            sc_validate($p);
            $pdo->prepare("
                UPDATE app.static_customer
                   SET name = :name, mac_address = :mac, duid = :duid, iaid = :iaid,
                       static_ipv4 = :ipv4::inet, ipv6_prefix = :ipv6::cidr,
                       vlan = :vlan, notes = :notes, uisp_site_id = :uisp,
                       check_status = 'unchecked', check_detail = '{}', last_checked_at = NULL,
                       updated_at = now()
                 WHERE static_customer_id = :id
            ")->execute([
                ':name'  => $p['name'],
                ':mac'   => $p['mac_address'],
                ':duid'  => $p['duid'],
                ':iaid'  => $p['iaid'],
                ':ipv4'  => $p['static_ipv4'],
                ':ipv6'  => $p['ipv6_prefix'],
                ':vlan'  => $p['vlan'],
                ':notes' => $p['notes'],
                ':uisp'  => $p['uisp_site_id'],
                ':id'    => $id,
            ]);
            flash_set('Customer updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid ID.');
            $pdo->prepare("DELETE FROM app.static_customer WHERE static_customer_id = :id")
                ->execute([':id' => $id]);
            flash_set('Customer deleted.');

        } elseif ($action === 'check_one') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid ID.');
            $st = $pdo->prepare("SELECT * FROM app.static_customer WHERE static_customer_id = :id");
            $st->execute([':id' => $id]);
            $sc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sc) throw new RuntimeException('Customer not found.');

            $result = sc_run_check($pdo, $sc);
            $pdo->prepare("
                UPDATE app.static_customer
                   SET check_status = :status, check_detail = :detail,
                       last_checked_at = now(), updated_at = now()
                 WHERE static_customer_id = :id
            ")->execute([
                ':status' => $result['status'],
                ':detail' => json_encode($result['detail']),
                ':id'     => $id,
            ]);
            $label = ['ok' => 'OK', 'mismatch' => 'Mismatch found', 'unknown' => 'Not found in caches'];
            flash_set('Check complete: ' . ($label[$result['status']] ?? $result['status']) . '.',
                      $result['status'] !== 'mismatch');

        } elseif ($action === 'sync_uisp') {
            uisp_fetch_cached('sites?type=endpoint', 0, $pdo);
            uisp_fetch_cached('devices', 0, $pdo);
            flash_set('UISP customer list refreshed.');

        } elseif ($action === 'check_all') {
            $allSc = $pdo->query("SELECT * FROM app.static_customer ORDER BY name")
                         ->fetchAll(PDO::FETCH_ASSOC);
            $counts = ['ok' => 0, 'mismatch' => 0, 'unknown' => 0];
            $st = $pdo->prepare("
                UPDATE app.static_customer
                   SET check_status = :status, check_detail = :detail,
                       last_checked_at = now(), updated_at = now()
                 WHERE static_customer_id = :id
            ");
            foreach ($allSc as $sc) {
                $result = sc_run_check($pdo, $sc);
                $st->execute([
                    ':status' => $result['status'],
                    ':detail' => json_encode($result['detail']),
                    ':id'     => $sc['static_customer_id'],
                ]);
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            }
            $msg = 'Check complete — ' . count($allSc) . ' customers: '
                 . $counts['ok'] . ' OK, '
                 . $counts['mismatch'] . ' mismatch, '
                 . $counts['unknown'] . ' unknown.';
            flash_set($msg, $counts['mismatch'] === 0);
        }

    } catch (Exception $e) {
        flash_set($e->getMessage(), false);
    }

    header('Location: index.php?page=static_customers');
    exit;
}

// ── Fetch: static customer records (used for forms / edit / check / delete) ───
$customers = $pdo->query("
    SELECT static_customer_id, name, mac_address, duid, iaid,
           static_ipv4::text AS static_ipv4,
           ipv6_prefix::text AS ipv6_prefix,
           vlan, notes, uisp_site_id,
           check_status, check_detail, last_checked_at,
           credentials_generated_at,
           created_at, updated_at
    FROM app.static_customer
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Index by uisp_site_id; orphans (no UISP link) kept separately
$scBySiteId = [];
$scOrphans  = [];
foreach ($customers as $sc) {
    if ($sc['uisp_site_id']) $scBySiteId[$sc['uisp_site_id']] = $sc;
    else                      $scOrphans[] = $sc;
}

// ── Build unified customer list from UISP + DHCP ──────────────────────────────
$allRows   = [];  // one row per customer (all types)
$uispSites = [];  // UISP sites without a static record (for add picker)

try {
    // 1. UISP endpoint sites (auto-refresh if cache older than 5 min)
    $sitesData = uisp_fetch_cached('sites?type=endpoint', 300, $pdo);

    // 2. ONU devices → site_id to mac_norm map
    $siteToMac = [];
    $devData = uisp_fetch_cached('devices', 300, $pdo);
    foreach ($devData as $dev) {
        if (($dev['identification']['type'] ?? '') !== 'onu') continue;
        $sid     = $dev['identification']['site']['id'] ?? '';
        $macNorm = strtolower(str_replace(':', '', $dev['identification']['mac'] ?? ''));
        if ($sid && $macNorm) $siteToMac[$sid] = $macNorm;
    }

    // 3. DHCP leases → remote_id to lease map
    $leaseByRemoteId = [];
    foreach ($pdo->query("SELECT leases FROM app.dhcp_lease_cache")->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode($json, true) ?: [] as $lease) {
            $rid = $lease['remote_id'] ?? '';
            if ($rid !== '') $leaseByRemoteId[$rid] = $lease;
        }
    }

    // 4. Assemble unified rows — one per UISP site
    foreach ($sitesData as $site) {
        $siteId     = $site['id'] ?? '';
        $clientName = $site['ucrm']['client']['name'] ?? '';
        if (!$siteId || !$clientName) continue;

        $fullAddr   = $site['description']['address'] ?? '';
        $streetAddr = $fullAddr !== '' ? explode(',', $fullAddr)[0] : '';
        $plan       = $site['ucrm']['service']['name'] ?? '';

        $macNorm    = $siteToMac[$siteId] ?? null;
        $lease      = $macNorm ? ($leaseByRemoteId[$macNorm] ?? null) : null;
        $sc         = $scBySiteId[$siteId] ?? null;
        // UISP flips a site to "active" some time after the CRM service does, so a site
        // whose service is already active (status 1) is not inactive just because the
        // site flag has not caught up.  A new customer must be listed to get credentials.
        $serviceActive = (int)($site['ucrm']['service']['status'] ?? 0) === 1;
        $isInactive = (($site['identification']['status'] ?? '') === 'inactive' && !$serviceActive)
                   || ($site['identification']['suspended'] ?? false) === true;

        if ($isInactive) {
            $type = 'inactive';
        } elseif ($sc) {
            $type = 'static';
        } elseif ($lease) {
            $type = str_contains(strtolower($lease['server'] ?? ''), 'cgnat') ? 'cgnat' : 'dynamic';
        } else {
            $type = 'unknown';
        }

        $currentIp  = $lease['address'] ?? ($sc ? ($sc['static_ipv4'] ?? '') : '');
        $ipv6Prefix = '';
        if ($lease && isset($lease['ipv6_prefix'])) {
            $ipv6Prefix = $lease['ipv6_prefix'] . '/' . ($lease['ipv6_prefix_len'] ?? 56);
        } elseif ($sc) {
            $ipv6Prefix = $sc['ipv6_prefix'] ?? '';
        }

        $allRows[] = compact('type', 'clientName', 'streetAddr', 'plan',
                             'currentIp', 'ipv6Prefix', 'siteId', 'sc', 'lease');

        if (!$sc && !$isInactive) {
            $uispSites[] = [
                'site_id'     => $siteId,
                'client_name' => $clientName,
                'street_addr' => $streetAddr,
                'label'       => $clientName . ($streetAddr !== '' ? ' — ' . $streetAddr : ''),
            ];
        }
    }

    // 5. Orphan static customers not in UISP
    foreach ($scOrphans as $sc) {
        $type = 'static'; $clientName = $sc['name']; $streetAddr = '';
        $plan = ''; $currentIp = $sc['static_ipv4'] ?? ''; $ipv6Prefix = $sc['ipv6_prefix'] ?? '';
        $siteId = null; $lease = null;
        $allRows[] = compact('type', 'clientName', 'streetAddr', 'plan',
                             'currentIp', 'ipv6Prefix', 'siteId', 'sc', 'lease');
    }

    // Sort: static → cgnat → dynamic → unknown → inactive, then by name
    $typeOrder = ['static' => 0, 'cgnat' => 1, 'dynamic' => 2, 'unknown' => 3, 'inactive' => 4];
    usort($allRows, fn($a, $b) =>
        ($typeOrder[$a['type']] ?? 9) <=> ($typeOrder[$b['type']] ?? 9)
        ?: strcmp($a['clientName'], $b['clientName'])
    );
    usort($uispSites, fn($a, $b) => strcmp($a['client_name'], $b['client_name']));

} catch (PDOException $e) {
    // Fallback: static-only view
    foreach ($customers as $sc) {
        $type = 'static'; $clientName = $sc['name']; $streetAddr = '';
        $plan = ''; $currentIp = $sc['static_ipv4'] ?? ''; $ipv6Prefix = $sc['ipv6_prefix'] ?? '';
        $siteId = $sc['uisp_site_id']; $lease = null;
        $allRows[] = compact('type', 'clientName', 'streetAddr', 'plan',
                             'currentIp', 'ipv6Prefix', 'siteId', 'sc', 'lease');
    }
}

$flash = flash_get();

// ── Template ──────────────────────────────────────────────────────────────────
$PAGE->setTitle('Customers');
$PAGE->addExternalJs('/js/qr-code-styling.js');   // self-hosted qr-code-styling 1.9.2

$PAGE->setContentRenderer(function () use ($customers, $allRows, $CSRF, $flash, $uispSites) {
    ob_start();
?>
<style>
@page { size: letter portrait; margin: 0.5in; }
@media print {
  .navbar, aside, nav[aria-label="breadcrumb"], .sc-noprint, .alert { display:none !important; }
  body { margin:0; background:#fff; }
  .container-fluid, .col, [class*="col-"], main.app-main { width:100% !important; max-width:none !important; flex:0 0 100% !important; padding:0 !important; margin:0 !important; }
  #customersTable { font-size:11px; }
  #customersTable th:last-child, #customersTable td:last-child { display:none; }
  #customersTable tr { break-inside:avoid; }
  #customersTable thead { display:table-header-group; }
  .sc-printmeta { display:block !important; }
}
.sc-printmeta { display:none; font-size:12px; color:#555; margin-bottom:6px; }
</style>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Customers</h4>
  <div class="d-flex gap-2 sc-noprint">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">&#128424; Print</button>
    <form method="post" action="index.php?page=static_customers" class="d-inline">
      <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="sync_uisp">
      <button class="btn btn-outline-secondary btn-sm">↻ Sync UISP</button>
    </form>
    <form method="post" action="index.php?page=static_customers" class="d-inline">
      <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="check_all">
      <button class="btn btn-outline-secondary btn-sm"
              onclick="return confirm('Run UISP/DHCP cache check for all static customers?')">
        Check Static vs Cache
      </button>
    </form>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
      + Add Static Customer
    </button>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php
// Count by type for filter badges
$typeCounts = ['static' => 0, 'cgnat' => 0, 'dynamic' => 0, 'unknown' => 0, 'inactive' => 0];
foreach ($allRows as $r) $typeCounts[$r['type']] = ($typeCounts[$r['type']] ?? 0) + 1;
?>

<div class="sc-printmeta">Tinuum Networks &middot; printed <?= h(date('Y-m-d')) ?> &middot; shows the rows of the selected filter</div>
<!-- Type filter -->
<div class="d-flex gap-2 flex-wrap mb-3 sc-noprint">
  <button class="btn btn-sm btn-secondary active" onclick="scFilter('active', this)">
    Active <span class="badge bg-light text-dark ms-1"><?= count($allRows) - $typeCounts['inactive'] ?></span>
  </button>
  <button class="btn btn-sm btn-outline-success" onclick="scFilter('static', this)">
    Static <span class="badge bg-success ms-1"><?= $typeCounts['static'] ?></span>
  </button>
  <button class="btn btn-sm btn-outline-warning" onclick="scFilter('cgnat', this)">
    CGNAT <span class="badge bg-warning text-dark ms-1"><?= $typeCounts['cgnat'] ?></span>
  </button>
  <button class="btn btn-sm btn-outline-primary" onclick="scFilter('dynamic', this)">
    Dynamic <span class="badge bg-primary ms-1"><?= $typeCounts['dynamic'] ?></span>
  </button>
  <?php if ($typeCounts['unknown'] > 0): ?>
  <button class="btn btn-sm btn-outline-secondary" onclick="scFilter('unknown', this)">
    Unknown <span class="badge bg-secondary ms-1"><?= $typeCounts['unknown'] ?></span>
  </button>
  <?php endif; ?>
  <?php if ($typeCounts['inactive'] > 0): ?>
  <button class="btn btn-sm btn-outline-dark" onclick="scFilter('inactive', this)">
    Inactive <span class="badge bg-dark ms-1"><?= $typeCounts['inactive'] ?></span>
  </button>
  <?php endif; ?>
</div>

<?php if (empty($allRows)): ?>
  <p class="text-muted">No customers found.</p>
<?php else: ?>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0" id="customersTable">
    <thead class="table-light">
      <tr>
        <th>Customer</th>
        <th>Plan</th>
        <th>Type</th>
        <th>IP Address</th>
        <th>IPv6 /56</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($allRows as $row):
  $sc   = $row['sc'];
  $type = $row['type'];
  $typeBadge = match($type) {
      'static'   => '<span class="badge bg-success">Static</span>',
      'cgnat'    => '<span class="badge bg-warning text-dark">CGNAT</span>',
      'dynamic'  => '<span class="badge bg-primary">Dynamic</span>',
      'inactive' => '<span class="badge bg-dark">Inactive</span>',
      default    => '<span class="badge bg-secondary">Unknown</span>',
  };
  $checkBadge = '';
  if ($sc) {
      $detail = json_decode($sc['check_detail'] ?? '{}', true) ?: [];
      $checkBadge = match($sc['check_status']) {
          'ok'       => ' <span class="badge bg-success-subtle text-success border border-success-subtle">✓</span>',
          'mismatch' => ' <span class="badge bg-warning text-dark">!</span>',
          'unknown'  => ' <span class="badge bg-secondary-subtle text-secondary border">?</span>',
          default    => '',
      };
  }
?>
      <tr data-type="<?= h($type) ?>"<?= $type === 'inactive' ? ' style="display:none;opacity:.55"' : '' ?>>
        <td>
          <div class="fw-semibold"<?= $type === 'inactive' ? ' style="text-decoration:line-through"' : '' ?>><?= h($row['clientName']) ?></div>
          <?php if ($row['streetAddr'] !== ''): ?>
            <div class="text-muted small"><?= h($row['streetAddr']) ?></div>
          <?php endif; ?>
          <?php if ($sc && $sc['notes'] !== ''): ?>
            <div class="text-muted" style="font-size:.72rem"><?= h($sc['notes']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($row['plan'] !== ''): ?>
            <span class="badge bg-info text-dark"><?= h($row['plan']) ?></span>
            <?php $ev = sc_expected_vlan($row['plan'], $type === 'static');
                  if ($ev !== null):
                      $evName = [211 => 'Customer Dynamic/Public IP', 222 => 'Customer CGNAT', 233 => 'Customer Static IP'][$ev];
                      $curVlan = ['static' => 233, 'cgnat' => 222, 'dynamic' => 211][$type] ?? null;
                      $off = $curVlan !== null && $curVlan !== $ev; ?>
              <span class="badge <?= $off ? 'bg-warning text-dark' : 'bg-secondary' ?>" title="Should be on VLAN <?= $ev ?> (<?= h($evName) ?>)<?= $off ? ' — currently on VLAN ' . $curVlan : '' ?>">V<?= $ev ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $typeBadge ?><?= $checkBadge ?></td>
        <td>
          <?php if ($row['currentIp'] !== ''): ?>
            <code class="small"><?= h($row['currentIp']) ?></code>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($row['ipv6Prefix'] !== ''): ?>
            <code class="small"><?= h($row['ipv6Prefix']) ?></code>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
<?php if ($sc): ?>
            <button class="btn btn-outline-secondary btn-sm"
                    onclick="scOpenEdit(<?= h(json_encode($sc)) ?>)">Edit</button>
            <form method="post" action="index.php?page=static_customers" class="d-inline">
              <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="check_one">
              <input type="hidden" name="id"     value="<?= $sc['static_customer_id'] ?>">
              <button class="btn btn-outline-info btn-sm" title="Check vs cache">✓</button>
            </form>
            <form method="post" action="index.php?page=static_customers" class="d-inline"
                  onsubmit="return confirm('Delete <?= h(addslashes($sc['name'])) ?>?')">
              <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= $sc['static_customer_id'] ?>">
              <button class="btn btn-outline-danger btn-sm">✕</button>
            </form>
<?php endif; ?>
          </div>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<!-- ── Add Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="index.php?page=static_customers">
        <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title">Add Static Customer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= sc_form_fields('add', $uispSites) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Add Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="index.php?page=static_customers">
        <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id"     id="editId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Static Customer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= sc_form_fields('edit') ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>


<script>

function scFilter(type, btn) {
  document.querySelectorAll('#customersTable tbody tr').forEach(function (tr) {
    var show = type === 'all'
      || (type === 'active' && tr.dataset.type !== 'inactive')
      || tr.dataset.type === type;
    tr.style.display = show ? '' : 'none';
    if (show && tr.dataset.type === 'inactive') tr.style.opacity = '0.55';
  });
  document.querySelectorAll('.d-flex.gap-2.flex-wrap button').forEach(function (b) {
    b.classList.remove('active', 'btn-secondary', 'btn-success', 'btn-warning', 'btn-primary', 'btn-dark');
    b.classList.add(b.dataset.bs || b.className.match(/btn-outline-(\w+)/)?.[1] === undefined ? 'btn-outline-secondary' : '');
  });
  // simpler: just toggle active on the clicked button
  document.querySelectorAll('.d-flex.gap-2.flex-wrap.mb-3 button').forEach(function (b) {
    b.classList.remove('active');
    // restore outline style for inactive
    ['btn-secondary','btn-success','btn-warning','btn-primary','btn-dark'].forEach(function(c){b.classList.remove(c);});
    var m = b.className.match(/btn-outline-(\w+)/);
    if (m) b.classList.add('btn-outline-' + m[1]);
  });
  btn.classList.add('active');
  var m = btn.className.match(/btn-outline-(\w+)/);
  if (m) { btn.classList.remove('btn-outline-' + m[1]); btn.classList.add('btn-' + m[1]); }
  else { btn.classList.add('btn-secondary'); }
}


function scUispPickerChange(sel) {
  var opt = sel.options[sel.selectedIndex];
  document.getElementById('addUispSiteId').value = opt.value;
  var nameField = document.getElementById('addName');
  if (opt.value && nameField.value === '') {
    nameField.value = opt.dataset.name || '';
  } else if (!opt.value) {
    nameField.value = '';
  }
}

function scOpenEdit(sc) {
  document.getElementById('editId').value         = sc.static_customer_id;
  document.getElementById('editName').value       = sc.name         || '';
  document.getElementById('editMac').value        = sc.mac_address  || '';
  document.getElementById('editDuid').value       = sc.duid         || '';
  document.getElementById('editIaid').value       = sc.iaid         || '';
  document.getElementById('editIpv4').value       = sc.static_ipv4  || '';
  document.getElementById('editIpv6').value       = sc.ipv6_prefix  || '';
  document.getElementById('editVlan').value       = sc.vlan         || '233';
  document.getElementById('editNotes').value      = sc.notes        || '';
  document.getElementById('editUispSiteId').value = sc.uisp_site_id || '';
  new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
    new bootstrap.Popover(el);
  });

  // Reset add-customer modal fields on open
  var addModal = document.getElementById('addCustomerModal');
  if (addModal) {
    addModal.addEventListener('show.bs.modal', function () {
      var picker = document.getElementById('addUispPicker');
      if (picker) picker.value = '';
      var siteId = document.getElementById('addUispSiteId');
      if (siteId) siteId.value = '';
      var nameEl = document.getElementById('addName');
      if (nameEl) nameEl.value = '';
    });
  }
});
</script>

<?php
    return ob_get_clean();
});

// ── Reusable form fields ───────────────────────────────────────────────────────
function sc_form_fields(string $prefix, array $uispSites = []): string
{
    $p      = $prefix === 'edit' ? 'edit' : 'add';
    $isAdd  = ($prefix === 'add');
    ob_start();
?>
<div class="row g-3">
<?php if ($isAdd): ?>
  <div class="col-12">
    <label class="form-label fw-semibold">UISP Customer <span class="text-danger">*</span></label>
<?php if (empty($uispSites)): ?>
    <div class="alert alert-warning mb-0 py-2">
      No unassigned UISP customers found. All active UISP customers may already be added,
      or the UISP cache may need a refresh.
    </div>
    <?php /* disable submit if no sites */ ?>
    <input type="hidden" name="uisp_site_id" id="addUispSiteId" value="">
<?php else: ?>
    <select id="addUispPicker" class="form-select" required
            onchange="scUispPickerChange(this)">
      <option value="">— select a customer —</option>
      <?php foreach ($uispSites as $s): ?>
        <option value="<?= h($s['site_id']) ?>"
                data-name="<?= h($s['street_addr'] ?: $s['client_name']) ?>">
          <?= h($s['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="uisp_site_id" id="addUispSiteId" value="">
<?php endif; ?>
  </div>
  <div class="col-sm-8">
    <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span>
      <span class="text-muted fw-normal small">(auto-filled from address — edit if needed)</span></label>
    <input type="text" name="name" id="addName" class="form-control"
           required placeholder="e.g. 459 Grub Road">
  </div>
<?php else: ?>
  <div class="col-sm-8">
    <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
    <input type="text" name="name" id="<?= $p ?>Name" class="form-control"
           required placeholder="e.g. Acme Corp">
  </div>
<?php endif; ?>
  <div class="col-sm-4">
    <label class="form-label fw-semibold">VLAN</label>
    <input type="number" name="vlan" id="<?= $p ?>Vlan" class="form-control"
           value="233" min="1" max="4094" required>
  </div>

  <div class="col-sm-6">
    <label class="form-label fw-semibold">WAN MAC Address <span class="text-danger">*</span></label>
    <input type="text" name="mac_address" id="<?= $p ?>Mac" class="form-control font-monospace"
           required placeholder="AA:BB:CC:DD:EE:FF"
           pattern="[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}">
    <div class="form-text">Used for IPv4 static DHCP lease matching.</div>
  </div>
  <div class="col-sm-6">
    <label class="form-label fw-semibold">Static IPv4 <span class="text-danger">*</span></label>
    <input type="text" name="static_ipv4" id="<?= $p ?>Ipv4" class="form-control font-monospace"
           required placeholder="23.170.184.162">
  </div>

  <div class="col-sm-8">
    <label class="form-label fw-semibold">IPv6 /56 Prefix <span class="text-danger">*</span></label>
    <input type="text" name="ipv6_prefix" id="<?= $p ?>Ipv6" class="form-control font-monospace"
           required placeholder="2602:f994:23:100::/56">
    <div class="form-text">Delegated /56 prefix for this customer's LAN.</div>
  </div>
  <div class="col-sm-4">
    <label class="form-label fw-semibold">IAID</label>
    <input type="text" name="iaid" id="<?= $p ?>Iaid" class="form-control font-monospace"
           value="1" required>
  </div>

  <div class="col-12">
    <label class="form-label fw-semibold">Customer DUID</label>
    <input type="text" name="duid" id="<?= $p ?>Duid" class="form-control font-monospace"
           value="0x00030001AABBCCDDEEFF" required>
    <div class="form-text">DHCPv6 DUID for IPv6 PD binding. Update once known from router request.</div>
  </div>

<?php if (!$isAdd): ?>
  <div class="col-sm-6">
    <label class="form-label fw-semibold">UISP Site ID <span class="text-muted fw-normal">(optional)</span></label>
    <input type="text" name="uisp_site_id" id="editUispSiteId" class="form-control font-monospace"
           placeholder="UUID from UISP">
  </div>
<?php endif; ?>
  <div class="col-sm-6">
    <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal">(optional)</span></label>
    <input type="text" name="notes" id="<?= $p ?>Notes" class="form-control"
           placeholder="Any notes about this assignment">
  </div>
</div>
<?php
    return ob_get_clean();
}

function sc_detail_html(array $detail): string
{
    if (empty($detail)) return '';
    $lines = [];
    foreach ($detail as $d) {
        $lines[] = '<div class="mb-1"><strong>' . h($d['field']) . '</strong>'
                 . ' (<em>' . h($d['source'] ?? '') . '</em>):<br>'
                 . 'Stored: <code>' . h($d['stored'] ?? '') . '</code><br>'
                 . 'Cache:&nbsp; <code>' . h($d['cache'] ?? '') . '</code>'
                 . (isset($d['note']) ? '<br><small class="text-muted">' . h($d['note']) . '</small>' : '')
                 . '</div>';
    }
    return implode('', $lines);
}
