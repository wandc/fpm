<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/template.php
declare(strict_types=1);

namespace FPM\App;

class Template
{
    private string $pageId;
    private string $title = 'Fibre Plant Manager';

    /** @var \Closure|null */
    private ?\Closure $contentRenderer = null;

    /** @var \Closure|null */
    private ?\Closure $inlineCssRenderer = null;

    /** @var \Closure|null */
    private ?\Closure $inlineJsRenderer = null;

     /** @var string[] */
    private array $externalCss = [];

    /** @var string[] */
    private array $externalJs  = [];


    private string $publicRoot;

    public function __construct(string $pageId)
    {
        $this->pageId    = $pageId;
        $this->publicRoot = dirname(__DIR__) . '/public';
    }

    // --- configuration from the page ---

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setContentRenderer(\Closure $fn): void
    {
        $this->contentRenderer = $fn;
    }

    public function setInlineCssRenderer(?\Closure $fn): void
    {
        $this->inlineCssRenderer = $fn;
    }

    public function setInlineJsRenderer(?\Closure $fn): void
    {
        $this->inlineJsRenderer = $fn;
    }

    // --- external asset registration ---

    public function addExternalCss(string $href): void
    {
        $this->externalCss[] = $href;
    }

    public function addExternalJs(string $src): void
    {
        $this->externalJs[] = $src;
    }

    // --- used by layout ---

    public function getPageId(): string
    {
        return $this->pageId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    // --- main render entry point ---

    public function render(): void
    {
        $content   = $this->renderContent();
        $inlineCss = $this->inlineCssRenderer ? ($this->inlineCssRenderer)() : null;
        $inlineJs  = $this->inlineJsRenderer  ? ($this->inlineJsRenderer)()  : null;

        $pageId     = $this->pageId;
        $title      = $this->title;

        $breadcrumbs = Ui::getBreadcrumbs($pageId);
        $currentRole = (string)(($_SESSION['auth_user']['role'] ?? $_SESSION['user']['role'] ?? 'user'));
        $navRail     = Ui::getNavRail($pageId, $currentRole);
        $layoutType  = Ui::getLayoutForPage($pageId);

        // helper CSS/JS paths
        $cssHelperPath = "/css/{$pageId}.helper.css";
        $jsHelperPath  = "/js/{$pageId}.helper.js";

        $cssHelperFile = $this->publicRoot . $cssHelperPath;
        $jsHelperFile  = $this->publicRoot . $jsHelperPath;

        $cssHelperExists = is_file($cssHelperFile);
        $jsHelperExists  = is_file($jsHelperFile);

        // external asset URLs (for layout.php)
        $externalCss = $this->externalCss;
        $externalJs  = $this->externalJs;
        $publicRoot  = $this->publicRoot;   // layout.php stamps local assets with filemtime

        // expose variables to layout
        include __DIR__ . '/layout.php';
    }

    private function renderContent(): string
    {
        if ($this->contentRenderer) {
            $fn = $this->contentRenderer;
            return $fn();
        }
        return '<p>No content defined for this page.</p>';
    }
}
