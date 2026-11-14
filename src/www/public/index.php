<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/index.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/config.php';


use FPM\App\Template;


// Shared app helper (CSRF, flash, etc.)
require __DIR__ . '/phpHelpers/app-helper.php';

// Determine page ID early so we can gate access.
$pageId = $_GET['page'] ?? 'home';
$pageId = strtolower($pageId);
$pageId = preg_replace('/[^a-z0-9_]/', '', $pageId);
if ($pageId === '') {
    $pageId = 'home';
}

// Enforce authenticated access for all app pages by default.
$publicPages = ['login', 'oauth2_start', 'oauth2_callback'];
if (!in_array($pageId, $publicPages, true)) {
    fpm_require_authenticated();
}

// Handle global POST actions
if (is_post() && (($_POST['action'] ?? '') === 'logout')) {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        flash_set('Bad CSRF token.', false);
        header('Location: /index.php?page=home');
        exit;
    }

    $authSessionKeys = [
        'auth_user',
        'user',
        'oauth_user',
        'google_user',
        'oauth',
        'claims',
        'profile',
        'userinfo',
        'id_token_claims',
        'username',
        'user_name',
        'login',
        'user_login',
        'display_name',
        'name',
        'email',
        'user_email',
        'provider',
        'auth_provider',
        'provider_subject',
        'oauth_sub',
        'sub',
        'app_user_id',
        'user_id',
        'uid',
        'oauth2_state',
    ];

    foreach ($authSessionKeys as $key) {
        unset($_SESSION[$key]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    flash_set('You have been logged out.');
    header('Location: /index.php?page=home');
    exit;
}

// Load PHP helper for this page if it exists
$helperFile = __DIR__ . '/phpHelpers/' . $pageId . '.helper.php';
if (is_file($helperFile)) {
    require $helperFile;
}

// Create Template instance and expose as $PAGE
$PAGE = new Template($pageId);

// Load the page content file
$contentFile = __DIR__ . '/pages/' . $pageId . '.php';
if (is_file($contentFile)) {
    require $contentFile;
} else {
    // Simple 404 fallback
    $PAGE->setTitle('Not Found');
    $PAGE->setContentRenderer(function () use ($pageId) {
        $id = htmlspecialchars($pageId, ENT_QUOTES, 'UTF-8');
        return "<h1>404</h1><p>Page '{$id}' not found.</p>";
    });
}

// Render final page
$PAGE->render();
