<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/layout.php
/** @var string $pageId */
/** @var string $title */
/** @var string $content */
/** @var array  $breadcrumbs */
/** @var array  $navRail */
/** @var string $layoutType */
/** @var string $cssHelperPath */
/** @var bool   $cssHelperExists */
/** @var string $jsHelperPath */
/** @var bool   $jsHelperExists */
/** @var ?string $inlineCss */
/** @var ?string $inlineJs */
?>
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$identity = function_exists('fpm_current_identity') ? fpm_current_identity() : [];
$authDisplay = trim((string)($identity['display_name'] ?? $identity['username'] ?? 'User'));
if ($authDisplay === '') {
    $authDisplay = 'User';
}
$authEmail = trim((string)($identity['email'] ?? ''));
$authProvider = trim((string)($identity['provider'] ?? ''));
$isAuthenticated = (bool)($identity['is_authenticated'] ?? false);
$csrfToken = (string)($_SESSION['csrf'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>

  <?php $favDir = rtrim(org('favicon_dir'), '/'); if ($favDir !== ''): ?>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($favDir) ?>/favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($favDir) ?>/favicon.svg" />
  <link rel="shortcut icon" href="<?= htmlspecialchars($favDir) ?>/favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($favDir) ?>/apple-touch-icon.png" />
  <?php else: ?>
  <link rel="icon" type="image/svg+xml" href="/images/favicon.svg" />
  <?php endif; ?>
  <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(org('app_title')) ?>" />
  <?php if ($favDir !== ''): ?><link rel="manifest" href="<?= htmlspecialchars($favDir) ?>/site.webmanifest" /><?php endif; ?>
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../public/css/app.css') ?: '0' ?>">
  <!-- Bootstrap via CDN or local, your choice -->
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <?php if (!empty($externalCss)): ?>
        <?php foreach ($externalCss as $href): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

  <?php if ($cssHelperExists): ?>
    <link rel="stylesheet" href="<?= $cssHelperPath ?>">
  <?php endif; ?>

  <style>
    .sms-bell-link { color: rgba(0,0,0,.75); border-radius: 6px; transition: background .15s; }
    .sms-bell-link:hover { background: rgba(0,0,0,.1); color: #000; }
    .sms-bell-link svg { pointer-events: none; }
    .ann-bell-link { color: rgba(0,0,0,.75); border-radius: 6px; transition: background .15s; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; }
    .ann-bell-link:hover { background: rgba(0,0,0,.1); color: #000; }
    #ann-panel-body .ann-item + .ann-item { border-top: 1px solid #dee2e6; margin-top: .75rem; padding-top: .75rem; }
  </style>
  <?php if (!empty($inlineCss)): ?>
    <style><?= $inlineCss ?></style>
  <?php endif; ?>
  <script>window.ORG = <?= json_encode(['name' => org('name'), 'legalName' => org('legal_name'), 'supportEmail' => org('support_email'), 'phone' => org('phone'), 'web' => org('web'), 'defaultWifiWor' => org('default_wifi_wor'), 'woLabel' => org('wo_label'), 'ownName' => org('own_name'), 'ownMark' => org('own_mark'), 'logo' => org('logo'), 'longName' => org('long_name')], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
</head>
<body>

<?php if ($layoutType !== 'login'): ?>
  <!-- Main layout with nav + sidebar -->
  <nav class="navbar navbar-dark" style="background-color:#ffb04f">
    <!-- Mobile: hamburger opens offcanvas -->
    <button class="navbar-toggler border-0 d-md-none ms-2" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav"
            aria-label="Open menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Logo: favicon on mobile, full logo on desktop -->
    <a class="navbar-brand p-0 ms-2 ms-md-3" href="/index.php?page=home">
      <img src="<?= htmlspecialchars($favDir !== '' ? $favDir . '/favicon-96x96.png' : '/images/favicon.svg') ?>" alt="<?= htmlspecialchars(org('name')) ?>" height="28" class="d-md-none" style="display:block">
      <img src="<?= htmlspecialchars(org('logo')) ?>" alt="<?= htmlspecialchars(org('name')) ?>" height="32" class="d-none d-md-block" style="display:block">
    </a>

    <!-- Global search -->
    <div class="navbar-search ms-2 ms-md-4 me-auto position-relative" id="globalSearchWrap">
      <input type="text" id="globalSearch" class="form-control form-control-sm navbar-search-input"
             placeholder="Search closures, poles, span tags…"
             autocomplete="off" spellcheck="false" aria-label="Global search" aria-autocomplete="list"
             title="Search (Ctrl+K)">
      <svg class="navbar-search-icon" viewBox="0 0 24 24" aria-hidden="true"><?= \FPM\App\Ui::railIconSvg('search') ?></svg>
      <div id="globalSearchDrop" class="navbar-search-drop" role="listbox" aria-label="Search results"></div>
    </div>

    <div class="me-2 me-md-3 d-flex align-items-center gap-2">

      <div class="dropdown">
        <button
          class="btn btn-sm btn-outline-dark user-menu-toggle d-flex align-items-center gap-2"
          type="button"
          data-bs-toggle="dropdown"
          aria-expanded="false"
          title="<?= htmlspecialchars($authDisplay, ENT_QUOTES, 'UTF-8') ?>"
        >
          <span class="user-menu-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
              <path d="M8 1a2.5 2.5 0 0 1 2.45 3.01 5.5 5.5 0 0 1 1.26.73l1.12-.45 1.06 1.84-.94.78c.06.35.1.7.1 1.06 0 .36-.04.71-.1 1.06l.94.78-1.06 1.84-1.12-.45a5.5 5.5 0 0 1-1.26.73A2.5 2.5 0 1 1 8 1Zm0 1.5a1 1 0 1 0 .001 2.001A1 1 0 0 0 8 2.5ZM8 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/>
            </svg>
          </span>
          <span class="user-menu-name d-none d-md-inline"><?= htmlspecialchars($authDisplay, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($authProvider !== ''): ?>
            <span class="user-menu-provider d-none d-md-inline"><?= htmlspecialchars($authProvider, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li class="px-3 py-2 user-menu-meta">
            <div class="small text-muted"><?= $isAuthenticated ? 'Signed in as' : 'Not signed in' ?></div>
            <div class="fw-semibold user-menu-meta-value">
              <?= htmlspecialchars($authDisplay !== '' ? $authDisplay : 'User', ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if ($authEmail !== ''): ?>
              <div class="small text-muted user-menu-meta-value"><?= htmlspecialchars($authEmail, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($authProvider !== ''): ?>
              <div class="small text-muted">OAuth provider: <?= htmlspecialchars($authProvider, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item" href="/index.php?page=user_settings">User settings</a>
          </li>
          <li><hr class="dropdown-divider"></li>
          <?php if ($isAuthenticated): ?>
            <li>
              <form method="post" action="/index.php?page=home" class="m-0">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="dropdown-item text-danger user-menu-logout">Log out</button>
              </form>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Mobile offcanvas nav drawer -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel"
       style="max-width:280px; background:#f8f9fa">
    <div class="offcanvas-header" style="background-color:#ffb04f">
      <a href="/index.php?page=home">
        <img src="<?= htmlspecialchars(org('logo')) ?>" alt="<?= htmlspecialchars(org('name')) ?>" height="28">
      </a>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
      <div class="list-group list-group-flush">
        <?php foreach ($navRail as $i => $entry): ?>
          <?php if ($entry['type'] === 'link'): ?>
            <a href="<?= htmlspecialchars($entry['url'], ENT_QUOTES, 'UTF-8') ?>"
               class="list-group-item list-group-item-action<?= $entry['active'] ? ' active' : '' ?>">
              <?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php else: ?>
            <button type="button"
                    class="list-group-item list-group-item-action nav-drawer-group<?= $entry['active'] ? '' : ' collapsed' ?>"
                    data-bs-toggle="collapse" data-bs-target="#drawerGrp<?= (int)$i ?>"
                    aria-expanded="<?= $entry['active'] ? 'true' : 'false' ?>">
              <span><?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="nav-drawer-count"><?= count($entry['items']) ?></span>
            </button>
            <div class="collapse<?= $entry['active'] ? ' show' : '' ?>" id="drawerGrp<?= (int)$i ?>">
              <?php foreach ($entry['items'] as $item): ?>
                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="list-group-item list-group-item-action sidebar-item-grouped<?= $item['active'] ? ' active' : '' ?>">
                  <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="app-shell">
    <aside class="nav-rail d-none d-md-flex" id="navRail" aria-label="Sections">


      <?php foreach ($navRail as $i => $entry): ?>
        <?php if ($entry['type'] === 'link'): ?>
          <a href="<?= htmlspecialchars($entry['url'], ENT_QUOTES, 'UTF-8') ?>"
             class="nav-rail-btn<?= $entry['active'] ? ' is-active' : '' ?>"
             <?= $entry['active'] ? 'aria-current="page"' : '' ?>>
            <svg class="nav-rail-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $entry['icon'] ?></svg>
            <span class="nav-rail-tip"><?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </a>
        <?php else: ?>
          <div class="nav-rail-group<?= $entry['active'] ? ' is-active' : '' ?>" data-rail-group>
            <button type="button" class="nav-rail-btn" aria-haspopup="true" aria-expanded="false"
                    aria-controls="railFlyout<?= (int)$i ?>"
                    aria-label="<?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?>">
              <svg class="nav-rail-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $entry['icon'] ?></svg>
            </button>
            <div class="nav-rail-flyout" id="railFlyout<?= (int)$i ?>">
              <div class="nav-rail-flyout-title"><?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php foreach ($entry['items'] as $item): ?>
                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="nav-rail-flyout-item<?= $item['active'] ? ' is-active' : '' ?>"
                   <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                  <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

    </aside>

    <main class="app-main p-3">
        <?php if ($breadcrumbs): ?>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <?php foreach ($breadcrumbs as $crumb): ?>
                <?php if ($crumb['url']): ?>
                  <li class="breadcrumb-item">
                    <a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                  </li>
                <?php else: ?>
                  <li class="breadcrumb-item active" aria-current="page">
                    <?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </nav>
        <?php endif; ?>

        <?= $content ?>
      </main>
  </div>

<?php else: ?>
  <!-- Special login layout: no menu, no breadcrumbs -->
  <div class="container py-5">
    <div class="text-center mb-4">
      <a href="/index.php?page=home">
        <img src="<?= htmlspecialchars(org('logo')) ?>" alt="<?= htmlspecialchars(org('name')) ?>" height="56" style="display:inline-block">
      </a>
    </div>
    <?= $content ?>
  </div>
<?php endif; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/app.js"></script>

<?php if (!empty($externalJs)): ?>
        <?php foreach ($externalJs as $src): ?>
            <?php
            // Same cache-buster the page helper gets below. Without it an edit to a
            // shared module (js/icons/*.js, the permit picker) keeps serving the
            // browser's cached copy while the helper beside it updates — the change
            // looks half-applied rather than absent, which is far worse to debug.
            // Only local paths that exist on disk; CDN URLs are left alone.
            $jsHref = $src;
            if ($src !== '' && $src[0] === '/') {
                $jsFile = $publicRoot . $src;
                if (is_file($jsFile)) $jsHref = $src . '?v=' . filemtime($jsFile);
            }
            ?>
            <script src="<?= htmlspecialchars($jsHref, ENT_QUOTES) ?>"></script>
        <?php endforeach; ?>
<?php endif; ?>


<?php if (!empty($inlineJs)): ?>
  <script><?= $inlineJs ?></script>
<?php endif; ?>

<?php if ($jsHelperExists): ?>
  <script src="<?= $jsHelperPath ?>?v=<?= filemtime($jsHelperFile) ?>"></script>
<?php endif; ?>


<script>
// Flat page list for the search box: label, URL and the group it lives under.
// Built from the same nav config that draws the rail, so a page added to
// nav.php is searchable with no further work.
window.FPM_PAGES = <?php
  $flat = [];
  foreach ($navRail as $entry) {
      if ($entry['type'] === 'link') {
          $flat[] = ['label' => $entry['label'], 'url' => $entry['url'], 'group' => ''];
          continue;
      }
      foreach ($entry['items'] as $item) {
          $flat[] = ['label' => $item['label'], 'url' => $item['url'], 'group' => $entry['label']];
      }
  }
  echo json_encode($flat, JSON_UNESCAPED_SLASHES);
?>;
</script>
<script>
(function () {
  var input = document.getElementById('globalSearch');
  var drop  = document.getElementById('globalSearchDrop');
  if (!input || !drop) return;

  var timer = null;
  var active = -1; // keyboard-selected row index
  var rows   = []; // [{url,el}]
  var pageIndex = window.FPM_PAGES || [];

  function debounce(fn, ms) {
    clearTimeout(timer);
    timer = setTimeout(fn, ms);
  }

  function closeDrop() {
    drop.innerHTML = '';
    drop.style.display = 'none';
    active = -1;
    rows   = [];
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function statusColor(s) {
    return {active:'#198754',plan:'#E0A800','under construction':'#f97316',retired:'#6c757d'}[s] || '#888';
  }

  function buildGroup(title, items) {
    if (!items || !items.length) return '';
    var html = '<div class="search-group-header">' + esc(title) + '</div>';
    items.forEach(function(item) {
      var dot = item.status
        ? '<span class="search-status-dot" style="background:' + statusColor(item.status) + '"></span>'
        : '';
      html += '<a href="' + esc(item.url) + '" class="search-result-row" role="option">'
            + dot
            + '<span class="search-label">' + esc(item.label) + '</span>'
            + (item.sublabel ? '<span class="search-sublabel">' + esc(item.sublabel) + '</span>' : '')
            + '</a>';
    });
    return html;
  }

  function matchPages(q) {
    var t = q.toLowerCase();
    if (!t) return [];
    var hits = [];
    pageIndex.forEach(function (p) {
      var l = p.label.toLowerCase();
      var i = l.indexOf(t);
      var g = (p.group || '').toLowerCase().indexOf(t);
      if (i < 0 && g < 0) return;
      // label prefix beats a mid-label hit, which beats a group-name-only hit
      hits.push({ rank: i === 0 ? 0 : (i > 0 ? 1 : 2), p: p });
    });
    hits.sort(function (a, b) {
      return a.rank - b.rank || a.p.label.localeCompare(b.p.label);
    });
    return hits.slice(0, 6).map(function (h) {
      return { label: h.p.label, sublabel: h.p.group, url: h.p.url };
    });
  }

  function render(data) {
    var html = buildGroup('Pages',     matchPages(input.value.trim()))
             + buildGroup('Closures',  data.closures)
             + buildGroup('Poles',     data.poles)
             + buildGroup('Span Tags', data.span_tags);

    if (!html) {
      html = '<div class="search-empty">No results</div>';
    }

    drop.innerHTML = html;
    drop.style.display = 'block';

    // collect navigable rows
    rows = Array.from(drop.querySelectorAll('.search-result-row')).map(function(el) {
      return { url: el.getAttribute('href'), el: el };
    });
    active = -1;
  }

  function setActive(idx) {
    rows.forEach(function(r) { r.el.classList.remove('active'); });
    active = idx;
    if (active >= 0 && active < rows.length) {
      rows[active].el.classList.add('active');
      rows[active].el.scrollIntoView({ block: 'nearest' });
    }
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    if (!q) { closeDrop(); return; }
    render({});                       // pages answer instantly, no round trip
    if (q.length < 2) return;         // closures/poles/tags need two characters
    debounce(function () {
      fetch('/api/search.php?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.ok) render(d); })
        .catch(function() {});
    }, 180);
  });

  input.addEventListener('keydown', function (e) {
    if (!rows.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive(Math.min(active + 1, rows.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive(Math.max(active - 1, 0));
    } else if (e.key === 'Enter') {
      if (active >= 0) { e.preventDefault(); window.location.href = rows[active].url; }
    } else if (e.key === 'Escape') {
      closeDrop();
      input.blur();
    }
  });

  document.addEventListener('click', function (e) {
    if (!document.getElementById('globalSearchWrap').contains(e.target)) {
      closeDrop();
    }
  });

  input.addEventListener('focus', function () {
    if (input.value.trim().length >= 1 && rows.length) {
      drop.style.display = 'block';
    }
  });
})();
</script>

<script>
// ── Bootstrap UI state persistence via localStorage ───────────────────────────
// Handles: collapse, tabs/pills, and any element with data-bs-persist="display"
(function () {
  var P = 'bs_';

  // ── Collapse ──
  document.addEventListener('shown.bs.collapse', function (e) {
    if (e.target.id) localStorage.setItem(P + 'c_' + e.target.id, '1');
  });
  document.addEventListener('hidden.bs.collapse', function (e) {
    if (e.target.id) localStorage.removeItem(P + 'c_' + e.target.id);
  });

  // ── Tab / pill ──
  document.addEventListener('shown.bs.tab', function (e) {
    if (!e.target.id) return;
    var nav = e.target.closest('[role="tablist"][id]');
    if (nav) localStorage.setItem(P + 't_' + nav.id, e.target.id);
  });

  // ── Restore on DOMContentLoaded ──
  document.addEventListener('DOMContentLoaded', function () {
    // Collapses: add .show and fix aria on toggle buttons
    document.querySelectorAll('.collapse[id]').forEach(function (el) {
      if (localStorage.getItem(P + 'c_' + el.id) !== '1') return;
      el.classList.add('show');
      document.querySelectorAll(
        '[data-bs-target="#' + el.id + '"],[href="#' + el.id + '"]'
      ).forEach(function (btn) {
        btn.classList.remove('collapsed');
        btn.setAttribute('aria-expanded', 'true');
      });
    });

    // Tabs: re-show the saved active tab in each named tablist
    document.querySelectorAll('[role="tablist"][id]').forEach(function (nav) {
      var savedId = localStorage.getItem(P + 't_' + nav.id);
      if (!savedId) return;
      var tab = document.getElementById(savedId);
      if (tab && window.bootstrap && bootstrap.Tab) {
        new bootstrap.Tab(tab).show();
      }
    });
  });
})();
</script>

<script>
// ── iOS modal keyboard fix ───────────────────────────────────────────────────
// CSS gives .modal-dialog height:dvh so it fits the visible area on open.
// When the keyboard appears dvh lags, so we use the VisualViewport API to
// set an explicit px height on .modal-dialog in real time.
// .modal-body (not position:fixed) is the scroll container — iOS scrolls it
// natively without background bleed-through.
(function () {
  if (!window.visualViewport) return;
  var raf = null;
  var MARGIN = 56; // 1.75rem top + 1.75rem bottom × 16px

  function syncDialogHeight() {
    raf = null;
    var dialog = document.querySelector('.modal.show .modal-dialog');
    if (dialog) {
      dialog.style.height = Math.max(200, window.visualViewport.height - MARGIN) + 'px';
    }
  }

  function scheduleSync() {
    if (!raf) raf = requestAnimationFrame(syncDialogHeight);
  }

  window.visualViewport.addEventListener('resize', scheduleSync);

  document.addEventListener('shown.bs.modal', scheduleSync);

  document.addEventListener('hide.bs.modal', function (e) {
    var dialog = e.target.querySelector('.modal-dialog');
    if (dialog) dialog.style.height = '';
  });
}());
</script>
</body>
</html>
