<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// pages/circuits.php — circuits that ride fibres: DWDM channels, point-to-point customers, PON feeders.
// The fibre hover on the closure connection diagram lists the circuits on a fibre's route, so a cut
// names what goes down. Fibres are cable span + fibre number; any fibre on the same spliced route matches.

use FPM\App\Template;

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$PAGE->setTitle('Circuits');
$PAGE->setContentRenderer(function () {
    ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Circuits</h4>
    <div class="text-muted small">What rides a fibre. Register a circuit on one fibre; the closure diagram finds it anywhere on that fibre's spliced route.</div>
  </div>
  <button class="btn btn-success btn-sm" id="cxNew">+ Add circuit</button>
</div>
<div id="cxMsg"></div>
<div class="table-responsive">
  <table class="table table-sm align-middle" id="cxTable">
    <thead class="table-light"><tr>
      <th>Circuit</th><th>Kind</th><th>Customer</th><th class="text-end">Mbps</th><th>Status</th><th>Protected by</th><th>Fibres</th><th></th>
    </tr></thead>
    <tbody></tbody>
  </table>
</div>

<div class="modal fade" id="cxModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form id="cxForm">
    <div class="modal-header"><h5 class="modal-title" id="cxTitle">Circuit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" id="cxId">
      <div class="mb-2"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" id="cxName" required placeholder="e.g. Acme Corp P2P 1G, COLP DWDM ch 21"></div>
      <div class="row g-2 mb-2">
        <div class="col"><label class="form-label small mb-0">Kind</label>
          <select class="form-select form-select-sm" id="cxKind">
            <option value="p2p">Point-to-point customer</option><option value="dwdm_channel">DWDM channel</option>
            <option value="pon_feeder">PON feeder</option><option value="transport">Transport</option><option value="other">Other</option>
          </select></div>
        <div class="col"><label class="form-label small mb-0">Status</label>
          <select class="form-select form-select-sm" id="cxStatus"><option value="active">Active</option><option value="planned">Planned</option><option value="retired">Retired</option></select></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col"><label class="form-label small mb-0">Customer</label><input class="form-control form-control-sm" id="cxCust"></div>
        <div class="col-4"><label class="form-label small mb-0">UCRM client id</label><input class="form-control form-control-sm" id="cxCrm" inputmode="numeric"></div>
        <div class="col-3"><label class="form-label small mb-0">Mbps</label><input class="form-control form-control-sm" id="cxSpeed" inputmode="numeric"></div>
      </div>
      <div class="mb-2"><label class="form-label small mb-0">Protected by (backup link)</label>
        <select class="form-select form-select-sm" id="cxProt"><option value="">None, a cut is an outage</option></select></div>
      <div class="mb-2"><label class="form-label small mb-0">Notes</label><textarea class="form-control form-control-sm" id="cxNotes" rows="2"></textarea></div>
      <div id="cxFibreBox" class="border rounded p-2 bg-light">
        <div class="small fw-semibold mb-1">Fibres</div>
        <div id="cxFibres" class="small mb-2"></div>
        <div class="d-flex gap-2 align-items-end flex-wrap">
          <div><label class="form-label small mb-0">Cable span id</label><input class="form-control form-control-sm" id="cxSpan" style="width:110px" inputmode="numeric"></div>
          <div><label class="form-label small mb-0">Fibre</label><input class="form-control form-control-sm" id="cxFib" style="width:80px" inputmode="numeric"></div>
          <div><label class="form-label small mb-0">Path</label><select class="form-select form-select-sm" id="cxRole"><option value="working">Working</option><option value="protect">Protect</option></select></div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="cxAddFibre">Add fibre</button>
        </div>
        <div class="text-muted" style="font-size:11px;margin-top:4px">Easier from the closure page: click a fibre in the connection diagram to assign it. Save the circuit first to add fibres here.</div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button><button class="btn btn-primary btn-sm">Save</button></div>
  </form>
</div></div></div>

<script>
(function () {
  var API = 'api/circuit.php', data = {circuits: [], links: []}, modal;
  var KIND = {p2p: 'P2P customer', dwdm_channel: 'DWDM channel', pon_feeder: 'PON feeder', transport: 'Transport', other: 'Other'};
  function $(id) { return document.getElementById(id); }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function msg(t, ok) { $('cxMsg').innerHTML = t ? '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2">' + esc(t) + '</div>' : ''; }
  function call(method, url, body) {
    return fetch(url, {method: method, headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window._csrf}, body: body ? JSON.stringify(body) : undefined})
      .then(function (r) { return r.json(); }).then(function (d) { if (!d.ok) throw new Error(d.error || 'Failed'); return d; });
  }
  function load() {
    return fetch(API).then(function (r) { return r.json(); }).then(function (d) {
      data = d;
      $('cxProt').innerHTML = '<option value="">None, a cut is an outage</option>' + d.links.map(function (l) { return '<option value="' + esc(l.link_id) + '">' + esc(l.name) + '</option>'; }).join('');
      var rows = d.circuits.map(function (c) {
        return '<tr' + (c.status === 'retired' ? ' class="text-muted"' : '') + '><td><strong>' + esc(c.name) + '</strong></td><td>' + esc(KIND[c.kind] || c.kind) + '</td><td>' + esc(c.customer_name) + '</td>'
          + '<td class="text-end">' + esc(c.speed_mbps) + '</td><td>' + esc(c.status) + '</td>'
          + '<td>' + (c.protected_by_name ? esc(c.protected_by_name) : '<span class="text-danger">unprotected</span>') + '</td>'
          + '<td class="small">' + (c.fibres.length ? c.fibres.map(function (f) { return esc(f.from_label) + '→' + esc(f.to_label) + ' F' + f.fibre_num + (f.role === 'protect' ? ' (protect)' : ''); }).join('<br>') : '<span class="text-muted">none yet</span>') + '</td>'
          + '<td class="text-end"><button class="btn btn-outline-secondary btn-sm py-0" data-edit="' + c.circuit_id + '">Edit</button> <button class="btn btn-outline-danger btn-sm py-0" data-del="' + c.circuit_id + '">Delete</button></td></tr>';
      });
      document.querySelector('#cxTable tbody').innerHTML = rows.join('') || '<tr><td colspan="8" class="text-muted">No circuits yet.</td></tr>';
    });
  }
  function fibreList(c) {
    $('cxFibres').innerHTML = c && c.fibres.length ? c.fibres.map(function (f) {
      return '<div>' + esc(f.cable_name) + ' span ' + f.cable_span_id + ' (' + esc(f.from_label) + '→' + esc(f.to_label) + ') F' + f.fibre_num + (f.role === 'protect' ? ' (protect)' : '')
        + ' <a href="#" data-rmf="' + f.id + '" class="text-danger ms-1">remove</a></div>';
    }).join('') : '<span class="text-muted">none yet</span>';
  }
  function open(c) {
    $('cxId').value = c ? c.circuit_id : '';
    $('cxTitle').textContent = c ? 'Edit circuit' : 'Add circuit';
    $('cxName').value = c ? c.name : ''; $('cxKind').value = c ? c.kind : 'p2p'; $('cxStatus').value = c ? c.status : 'active';
    $('cxCust').value = c ? (c.customer_name || '') : ''; $('cxCrm').value = c ? (c.crm_client_id || '') : ''; $('cxSpeed').value = c ? (c.speed_mbps || '') : '';
    $('cxProt').value = c ? (c.protected_by_link || '') : ''; $('cxNotes').value = c ? (c.notes || '') : '';
    $('cxFibreBox').style.display = c ? '' : 'none'; fibreList(c);
    modal = modal || new bootstrap.Modal($('cxModal')); modal.show();
  }
  function form() {
    return {name: $('cxName').value, kind: $('cxKind').value, status: $('cxStatus').value, customer_name: $('cxCust').value, crm_client_id: $('cxCrm').value,
            speed_mbps: $('cxSpeed').value, protected_by_link: $('cxProt').value, notes: $('cxNotes').value};
  }
  function byId(id) { return data.circuits.filter(function (c) { return String(c.circuit_id) === String(id); })[0]; }
  $('cxNew').onclick = function () { open(null); };
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.dataset && t.dataset.edit) open(byId(t.dataset.edit));
    if (t.dataset && t.dataset.del) {
      var c = byId(t.dataset.del);
      if (confirm('Delete circuit "' + c.name + '"?')) call('DELETE', API + '?id=' + c.circuit_id).then(function () { msg('Deleted.', true); return load(); }).catch(function (x) { msg(x.message); });
    }
    if (t.dataset && t.dataset.rmf) {
      e.preventDefault();
      call('DELETE', API + '?fibre_id=' + t.dataset.rmf).then(load).then(function () { fibreList(byId($('cxId').value)); }).catch(function (x) { alert(x.message); });
    }
  });
  $('cxAddFibre').onclick = function () {
    var id = $('cxId').value;
    call('POST', API + '?id=' + id + '&action=add_fibre', {span: $('cxSpan').value, fibre: $('cxFib').value, role: $('cxRole').value})
      .then(load).then(function () { fibreList(byId(id)); $('cxFib').value = ''; }).catch(function (x) { alert(x.message); });
  };
  $('cxForm').onsubmit = function (e) {
    e.preventDefault();
    var id = $('cxId').value;
    call(id ? 'PUT' : 'POST', API + (id ? '?id=' + id : ''), form()).then(function (d) {
      return load().then(function () {
        if (!id) { open(byId(d.circuit_id)); msg('Circuit added. Add its fibres below.', true); }
        else { modal.hide(); msg('Saved.', true); }
      });
    }).catch(function (x) { alert(x.message); });
  };
  load();
})();
</script>
<?php
    return ob_get_clean();
});
