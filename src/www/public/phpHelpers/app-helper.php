<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/phpHelpers/app-helper.php
// Shared helpers: CSRF, flash messages, escaping, POST check.

$sessionLifetimeSeconds = 14 * 24 * 60 * 60; // 2 weeks
ini_set('session.gc_maxlifetime', (string)$sessionLifetimeSeconds);
ini_set('session.cookie_lifetime', (string)$sessionLifetimeSeconds);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => $sessionLifetimeSeconds,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$CSRF = $_SESSION['csrf'];

/**
 * HTML-escape.
 */
if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Set flash message for next request.
 */
if (!function_exists('flash_set')) {
    function flash_set(string $msg, bool $ok = true): void
    {
        $_SESSION['flash'] = ['msg' => $msg, 'ok' => $ok];
    }
}

/**
 * Get and clear flash message.
 */
if (!function_exists('flash_get')) {
    function flash_get(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }
}

/**
 * Is this a POST request?
 */
if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
