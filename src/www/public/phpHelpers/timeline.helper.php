<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/phpHelpers/timeline-helper.php
// Shared timeline helper for Tinuum webapp (NOT a standalone page).


/**
 * Internal: build a URL for pagination/search keeping existing query params.
 *
 * @param array       $params   Params to override/append.
 * @param array       $base     Base query params to always include.
 * @param string|null $baseUrl  Optional base URL; defaults to current path.
 */
function timeline_build_url(array $params, array $base = [], ?string $baseUrl = null): string
{
    $baseUrl = $baseUrl ?? strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
    $query   = http_build_query(array_merge($base, $params));
    return $baseUrl . ($query !== '' ? ('?' . $query) : '');
}



    /**
     * Generic fetch: get all timeline entries for a given parent_type + parent_id.
     *
     * Example usages:
     *   - timeline_fetch_entries_for_parent($pdo, 'fosc', '116')
     *   - timeline_fetch_entries_for_parent($pdo, 'job',  'JOB-1234')
     */
    function timeline_fetch_entries_for_parent(PDO $pdo, string $parentType, $parentId): array
    {
        $st = $pdo->prepare("
            SELECT *
              FROM app.timeline_entry
             WHERE parent_type = :pt
               AND parent_id   = :pid
             ORDER BY created_at DESC, timeline_entry_id DESC
        ");
        $st->execute([
            ':pt'  => $parentType,
            ':pid' => (string)$parentId,   // always store/compare as string
        ]);
        return $st->fetchAll() ?: [];
    }




/**
 * Render timeline block as HTML string.
 *
 * Options:
 *  - q, page, per_page : forwarded to fetch
 *  - csrf              : CSRF token for delete forms (optional)
 *  - redirect          : redirect URL after delete (optional)
 *  - base_url          : base URL for pagination links (default: current URL path)
 *  - base_params       : extra query params to keep in pager/search links (array)
 *  - show_header       : bool, show big "Timeline" header (default true)
 *  - show_search       : bool, show search form (default true)
 */
function timeline_render_block(PDO $pdo, array $rows): void
{
    // bring CSRF into scope from index.php
    global $CSRF;

    if (!$rows) {
        echo '<p class="text-muted mb-0">No timeline entries yet.</p>';
        return;
    }

    // ── Pre-fetch all media for all entries in one query ─────────────────────
    $entryIds = array_column($rows, 'timeline_entry_id');
    $mediaByEntry = [];
    if ($entryIds) {
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        try {
            $st = $pdo->prepare("
                SELECT timeline_media_id, timeline_entry_id, file_name, width, height,
                       to_char(created_at, 'YYYY-MM-DD HH24:MI') AS uploaded_label,
                       -- A photo with no EXIF time is stored with its upload time (in UTC), so that value is not a taken time.
                       CASE WHEN exif_created_at IS NULL OR exif_created_at = date_trunc('second', created_at AT TIME ZONE 'UTC') THEN NULL
                            ELSE to_char(exif_created_at, 'YYYY-MM-DD HH24:MI') END AS taken_label
                  FROM app.timeline_media
                 WHERE timeline_entry_id IN ($placeholders)
              ORDER BY timeline_entry_id, exif_created_at NULLS LAST, created_at
            ");
            $st->execute($entryIds);
            foreach ($st->fetchAll() as $m) {
                $mediaByEntry[(int)$m['timeline_entry_id']][] = $m;
            }
        } catch (\Throwable $e) {
            // fail soft
        }
    }

    // ── Build flat photos array for lightbox ──────────────────────────────────
    $allPhotos = [];   // [{src, caption}]
    foreach ($rows as $row) {
        $eid    = (int)$row['timeline_entry_id'];
        $date   = $row['created_at'] ?? '';
        $body   = trim($row['body_text'] ?? '');
        $cap    = $date . ($body !== '' ? ' — ' . mb_substr($body, 0, 100) : '');
        foreach ($mediaByEntry[$eid] ?? [] as $m) {
            $when = $m['taken_label'] !== null ? 'Taken ' . $m['taken_label'] : 'Uploaded ' . $m['uploaded_label'];
            $allPhotos[] = [
                'src'     => '/media.php?id=' . (int)$m['timeline_media_id'],
                'caption' => $when . ($body !== '' ? ' — ' . mb_substr($body, 0, 100) : ''),
            ];
        }
    }

    // ── Render entries ────────────────────────────────────────────────────────
    $photoIdx = 0;
    foreach ($rows as $row) {
        $entryId   = (int)$row['timeline_entry_id'];
        $createdAt = htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bodyEsc   = htmlspecialchars($row['body_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mediaRows = $mediaByEntry[$entryId] ?? [];
        ?>
        <div class="border rounded p-2 mb-2 small">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <div class="text-muted">
              <span class="me-2">#<?= $entryId ?></span>
              <?php if ($createdAt !== ''): ?>
                <span><?= $createdAt ?></span>
              <?php endif; ?>
            </div>
            <div>
              <a class="btn btn-sm btn-outline-secondary"
                 href="index.php?page=timeline_entry&amp;id=<?= $entryId ?>">
                Edit
              </a>
              <form method="post"
                    action="index.php?page=timeline_entry&amp;id=<?= $entryId ?>"
                    class="d-inline"
                    onsubmit="return confirm('Delete this entry and all its images?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $entryId ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </div>
          </div>

          <?php if ($bodyEsc !== ''): ?>
            <div><?= nl2br($bodyEsc) ?></div>
          <?php endif; ?>

          <?php if ($mediaRows): ?>
            <div class="mt-2 d-flex flex-wrap gap-2">
              <?php foreach ($mediaRows as $m):
                $mid    = (int)$m['timeline_media_id'];
                $src    = '/media.php?id=' . $mid;
                $srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $fname  = htmlspecialchars($m['file_name'] ?? ('media-' . $mid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $idx    = $photoIdx++;
              ?>
                <div class="text-center" style="max-width:160px">
                <button type="button"
                        onclick="tlOpen(<?= $idx ?>)"
                        class="p-0 border-0 bg-transparent d-inline-block"
                        title="<?= $fname ?>">
                  <img src="<?= $srcEsc ?>"
                       alt="<?= $fname ?>"
                       class="img-thumbnail"
                       style="max-width:160px;max-height:160px;object-fit:cover;cursor:zoom-in;">
                </button>
                <div class="text-muted" style="font-size:11px">
                  <?= $m['taken_label'] !== null ? 'Taken ' . htmlspecialchars($m['taken_label']) : 'Uploaded ' . htmlspecialchars($m['uploaded_label']) ?>
                </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    // ── Lightbox (rendered once per block) ────────────────────────────────────
    if (!$allPhotos) return;
    $photosJson = json_encode($allPhotos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <!-- Timeline Lightbox -->
    <div id="tlLightbox"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.93);z-index:9999;
                align-items:center;justify-content:center;flex-direction:column;"
         onclick="if(event.target===this)tlClose()">

      <!-- Close -->
      <button onclick="tlClose()" type="button"
              style="position:absolute;top:.75rem;right:1rem;background:none;border:none;
                     color:#fff;font-size:1.8rem;line-height:1;cursor:pointer;opacity:.8;"
              title="Close (Esc)">&#x2715;</button>

      <!-- Counter -->
      <div id="tlCounter"
           style="position:absolute;top:.85rem;left:50%;transform:translateX(-50%);
                  color:#aaa;font-size:.8rem;font-family:system-ui,sans-serif;pointer-events:none;"></div>

      <!-- Prev -->
      <button onclick="tlStep(-1)" type="button" id="tlPrev"
              style="position:absolute;left:.5rem;top:50%;transform:translateY(-50%);
                     background:rgba(255,255,255,.12);border:none;color:#fff;font-size:2rem;
                     padding:.25rem .75rem;border-radius:4px;cursor:pointer;line-height:1;">&#x2039;</button>

      <!-- Image -->
      <img id="tlImg" src="" alt=""
           style="max-width:92vw;max-height:80vh;object-fit:contain;display:block;border-radius:4px;">

      <!-- Caption -->
      <div id="tlCaption"
           style="color:#ccc;font-size:.8rem;font-family:system-ui,sans-serif;
                  max-width:80vw;text-align:center;margin-top:.6rem;padding:0 2rem;line-height:1.4;"></div>

      <!-- Next -->
      <button onclick="tlStep(1)" type="button" id="tlNext"
              style="position:absolute;right:.5rem;top:50%;transform:translateY(-50%);
                     background:rgba(255,255,255,.12);border:none;color:#fff;font-size:2rem;
                     padding:.25rem .75rem;border-radius:4px;cursor:pointer;line-height:1;">&#x203a;</button>
    </div>

    <script>
    (function() {
      var _photos = <?= $photosJson ?>;
      var _cur    = 0;
      var _lb     = document.getElementById('tlLightbox');
      var _img    = document.getElementById('tlImg');
      var _cap    = document.getElementById('tlCaption');
      var _ctr    = document.getElementById('tlCounter');
      var _prev   = document.getElementById('tlPrev');
      var _next   = document.getElementById('tlNext');

      function _show(idx) {
        _cur = (idx + _photos.length) % _photos.length;
        var p = _photos[_cur];
        _img.src     = p.src;
        _img.alt     = p.caption;
        _cap.textContent = p.caption;
        _ctr.textContent = (_cur + 1) + ' / ' + _photos.length;
        _prev.style.visibility = _photos.length > 1 ? '' : 'hidden';
        _next.style.visibility = _photos.length > 1 ? '' : 'hidden';
      }

      window.tlOpen = function(idx) {
        _show(idx);
        _lb.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      };
      window.tlClose = function() {
        _lb.style.display = 'none';
        document.body.style.overflow = '';
        _img.src = '';
      };
      window.tlStep = function(dir) { _show(_cur + dir); };

      document.addEventListener('keydown', function(e) {
        if (_lb.style.display === 'none') return;
        if (e.key === 'Escape')     tlClose();
        if (e.key === 'ArrowLeft')  tlStep(-1);
        if (e.key === 'ArrowRight') tlStep(1);
      });
    })();
    </script>
    <?php
}