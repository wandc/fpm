<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/ui.php
declare(strict_types=1);

namespace FPM\App;

class Ui
{
    private static ?array $navConfig = null;
    private static ?array $railMeta   = null;

    public static function getNavConfig(): array
    {
        if (self::$navConfig === null) {
            $cfg = require __DIR__ . '/nav.php';
            // A page whose file is not installed has no menu entry. The public edition of this software leaves out the
            // Tinuum-only pages (tenders, regulatory, Bell permits, texting...), and this is what keeps its menu clean.
            foreach (array_keys($cfg) as $id) {
                if (!is_file(__DIR__ . '/../public/pages/' . $id . '.php')) unset($cfg[$id]);
            }
            self::$navConfig = $cfg;
        }
        return self::$navConfig;
    }

    public static function getLayoutForPage(string $pageId): string
    {
        $config = self::getNavConfig();
        return $config[$pageId]['layout'] ?? 'main';
    }

    public static function getSideMenu(string $currentPageId, string $role = 'user'): array
    {
        $config = self::getNavConfig();
        $items = [];
        $lastGroup = false; // false = sentinel (no group emitted yet)

        foreach ($config as $id => $page) {
            if (!($page['menu'] ?? false)) {
                continue;
            }
            if (($page['admin_only'] ?? false) && $role !== 'admin') {
                continue;
            }

            $group = $page['group'] ?? null;

            // Emit a heading row when the group changes (null group = no heading)
            if ($group !== $lastGroup) {
                if ($group !== null) {
                    $items[] = [
                        'type'  => 'heading',
                        'label' => $group,
                    ];
                }
                $lastGroup = $group;
            }

            $items[] = [
                'type'   => 'link',
                'id'     => $id,
                'label'  => $page['label'],
                'url'    => $page['url'],
                'active' => ($id === $currentPageId),
                'group'  => $group,
            ];
        }

        return $items;
    }

    /**
     * Icon-rail navigation: the ungrouped pages first as pinned buttons, then
     * one button per group with its pages nested underneath.  Order follows
     * nav.php, which stays the single source of truth for what exists; only
     * the glyphs come from nav_rail.php.
     *
     * Returns a list of
     *   ['type' => 'link',  'id', 'label', 'url', 'icon', 'active']
     *   ['type' => 'group', 'label', 'icon', 'active', 'items' => [ ...links ]]
     */
    public static function getNavRail(string $currentPageId, string $role = 'user'): array
    {
        $config = self::getNavConfig();
        $meta   = self::getRailMeta();

        $pinned = [];
        $groups = [];   // group label => entry, kept in nav.php order

        foreach ($config as $id => $page) {
            if (!($page['menu'] ?? false)) {
                continue;
            }
            if (($page['admin_only'] ?? false) && $role !== 'admin') {
                continue;
            }

            $active = ($id === $currentPageId);
            $link   = [
                'type'   => 'link',
                'id'     => $id,
                'label'  => $page['label'],
                'url'    => $page['url'],
                'active' => $active,
            ];

            $group = $page['group'] ?? null;

            if ($group === null) {
                $link['icon'] = self::railIcon($meta['pages'][$id] ?? null, $page['label']);
                $pinned[]     = $link;
                continue;
            }

            if (!isset($groups[$group])) {
                $groups[$group] = [
                    'type'   => 'group',
                    'label'  => $group,
                    'icon'   => self::railIcon($meta['groups'][$group] ?? null, $group),
                    'active' => false,
                    'items'  => [],
                ];
            }

            $groups[$group]['items'][] = $link;
            if ($active) {
                $groups[$group]['active'] = true;
            }
        }

        return array_merge($pinned, array_values($groups));
    }

    /** Inline SVG body for one rail glyph, by key from nav_rail.php. */
    public static function railIconSvg(string $key): string
    {
        $meta = self::getRailMeta();
        return $meta['icons'][$key] ?? '';
    }

    private static function railIcon(?string $key, string $fallbackLabel): string
    {
        $meta = self::getRailMeta();
        if ($key !== null && isset($meta['icons'][$key])) {
            return $meta['icons'][$key];
        }

        // No glyph defined yet — draw the initial, so a group added to nav.php
        // still gets a usable rail button instead of an empty square.
        $initial = strtoupper(substr(trim($fallbackLabel), 0, 1));
        return '<text x="12" y="16" text-anchor="middle" font-size="11" font-weight="700"'
             . ' fill="currentColor" stroke="none">'
             . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8')
             . '</text>';
    }

    private static function getRailMeta(): array
    {
        if (self::$railMeta === null) {
            self::$railMeta = require __DIR__ . '/nav_rail.php';
        }
        return self::$railMeta;
    }

    public static function getBreadcrumbs(string $pageId): array
    {
        $config = self::getNavConfig();
        if (!isset($config[$pageId])) {
            return [];
        }

        $crumbs = [];
        $current = $pageId;

        // climb the parent chain
        while ($current !== null && isset($config[$current])) {
            $page = $config[$current];
            $crumbs[] = [
                'label' => $page['label'],
                'url'   => ($current === $pageId) ? null : $page['url'],
            ];
            $current = $page['parent'] ?? null;
        }

        return array_reverse($crumbs);
    }
}
