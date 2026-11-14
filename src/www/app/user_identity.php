<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/user_identity.php

if (!function_exists('fpm_decode_jwt_payload')) {
    function fpm_decode_jwt_payload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }

        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $payload = strtr($payload, '-_', '+/');

        $json = base64_decode($payload, true);
        if ($json === false || $json === '') {
            return [];
        }

        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }
}

if (!function_exists('fpm_current_identity')) {
    function fpm_current_identity(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $id = [
            'username'         => '',
            'display_name'     => '',
            'email'            => '',
            'provider'         => '',
            'provider_subject' => '',
            'app_user_id'      => 0,
            'role'             => 'user',
            'oauth_state'      => '',
            'source'           => [],
        ];

        // Dev login bypass: Check for DEV_LOGIN_ENABLED environment variable
        $devLoginEnabled = strtolower(trim((string)getenv('DEV_LOGIN_ENABLED'))) === 'true';
        $devLoginUser = trim((string)getenv('DEV_LOGIN_USER'));
        $devLoginEmail = trim((string)getenv('DEV_LOGIN_EMAIL'));
        
        $devLoginSecret = trim((string)getenv('DEV_LOGIN_SECRET'));
        if ($devLoginEnabled && $devLoginSecret !== '' && $devLoginUser !== '' && $devLoginEmail !== '') {
            // Check if dev login is requested via query parameter — must match secret token
            $devLoginRequest = (string)($_GET['dev_login'] ?? '');
            if ($devLoginSecret !== '' && hash_equals($devLoginSecret, $devLoginRequest)) {
                // Set up dev user session
                $_SESSION['auth_user'] = [
                    'username'     => $devLoginUser,
                    'display_name' => $devLoginUser,
                    'email'        => $devLoginEmail,
                    'provider'     => 'dev',
                    'provider_subject' => 'dev-' . $devLoginUser,
                    'app_user_id'  => 0,
                    'role'         => 'admin',
                ];
                $_SESSION['user'] = $_SESSION['auth_user'];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                // Redirect to remove the dev_login parameter from URL
                $returnTo = (string)($_GET['return_to'] ?? '/index.php?page=home');
                if ($returnTo === '' || $returnTo[0] !== '/') {
                    $returnTo = '/index.php?page=home';
                }
                header('Location: ' . $returnTo, true, 302);
                exit;
            }
        }


        $authUser = $_SESSION['auth_user'] ?? null;
        if (is_array($authUser) && $authUser) {
            $id['username']         = (string)($authUser['username'] ?? $id['username']);
            $id['display_name']     = (string)($authUser['display_name'] ?? $id['display_name']);
            $id['email']            = (string)($authUser['email'] ?? $id['email']);
            $id['provider']         = (string)($authUser['provider'] ?? $id['provider']);
            $id['provider_subject'] = (string)($authUser['provider_subject'] ?? $id['provider_subject']);
            $id['app_user_id']      = (int)($authUser['app_user_id'] ?? $id['app_user_id']);
            $id['role']             = (string)($authUser['role'] ?? 'user');
            $id['source'][] = 'session:auth_user';
        }

        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && $sessionUser) {
            $id['username']         = $id['username'] !== '' ? $id['username'] : (string)($sessionUser['username'] ?? $sessionUser['user'] ?? '');
            $id['display_name']     = $id['display_name'] !== '' ? $id['display_name'] : (string)($sessionUser['display_name'] ?? $sessionUser['name'] ?? '');
            $id['email']            = $id['email'] !== '' ? $id['email'] : (string)($sessionUser['email'] ?? '');
            $id['provider']         = $id['provider'] !== '' ? $id['provider'] : (string)($sessionUser['provider'] ?? '');
            $id['provider_subject'] = $id['provider_subject'] !== '' ? $id['provider_subject'] : (string)($sessionUser['provider_subject'] ?? $sessionUser['sub'] ?? '');
            $id['app_user_id']      = $id['app_user_id'] > 0 ? $id['app_user_id'] : (int)($sessionUser['app_user_id'] ?? $sessionUser['id'] ?? 0);
            $id['source'][] = 'session:user';
        }

        $scalarMap = [
            'username'         => ['username', 'user_name', 'login', 'user_login'],
            'display_name'     => ['display_name', 'name'],
            'email'            => ['email', 'user_email'],
            'provider'         => ['provider', 'auth_provider'],
            'provider_subject' => ['provider_subject', 'oauth_sub', 'sub'],
            'app_user_id'      => ['app_user_id', 'user_id', 'uid'],
        ];

        foreach ($scalarMap as $target => $keys) {
            foreach ($keys as $k) {
                if (!isset($_SESSION[$k]) || $_SESSION[$k] === '') {
                    continue;
                }

                if ($target === 'app_user_id') {
                    if ($id[$target] <= 0) {
                        $id[$target] = (int)$_SESSION[$k];
                        $id['source'][] = 'session:' . $k;
                    }
                } else {
                    if ($id[$target] === '') {
                        $id[$target] = (string)$_SESSION[$k];
                        $id['source'][] = 'session:' . $k;
                    }
                }
                break;
            }
        }

        $sessionContainers = ['oauth_user', 'google_user', 'oauth', 'claims', 'profile', 'userinfo', 'id_token_claims'];
        foreach ($sessionContainers as $containerKey) {
            $container = $_SESSION[$containerKey] ?? null;
            if (!is_array($container) || !$container) {
                continue;
            }

            if ($id['username'] === '') {
                $id['username'] = (string)($container['preferred_username'] ?? $container['username'] ?? $container['login'] ?? '');
                if ($id['username'] !== '') {
                    $id['source'][] = 'session:' . $containerKey . '.username';
                }
            }
            if ($id['display_name'] === '') {
                $id['display_name'] = (string)($container['display_name'] ?? $container['name'] ?? '');
                if ($id['display_name'] !== '') {
                    $id['source'][] = 'session:' . $containerKey . '.display_name';
                }
            }
            if ($id['email'] === '') {
                $id['email'] = (string)($container['email'] ?? '');
                if ($id['email'] !== '') {
                    $id['source'][] = 'session:' . $containerKey . '.email';
                }
            }
            if ($id['provider'] === '') {
                $id['provider'] = (string)($container['provider'] ?? '');
                if ($id['provider'] !== '') {
                    $id['source'][] = 'session:' . $containerKey . '.provider';
                }
            }
            if ($id['provider_subject'] === '') {
                $id['provider_subject'] = (string)($container['provider_subject'] ?? $container['sub'] ?? '');
                if ($id['provider_subject'] !== '') {
                    $id['source'][] = 'session:' . $containerKey . '.sub';
                }
            }
        }

        $headers = [
            'username' => [
                'REMOTE_USER',
                'REDIRECT_REMOTE_USER',
                'OIDC_CLAIM_preferred_username',
                'REDIRECT_OIDC_CLAIM_preferred_username',
                'HTTP_X_AUTH_REQUEST_USER',
                'HTTP_X_AUTH_REQUEST_PREFERRED_USERNAME',
                'HTTP_X_FORWARDED_USER',
                'HTTP_X_FORWARDED_PREFERRED_USERNAME',
                'HTTP_X_USER',
                'HTTP_X_EMAIL',
            ],
            'display_name' => [
                'OIDC_CLAIM_name',
                'REDIRECT_OIDC_CLAIM_name',
                'HTTP_X_AUTH_REQUEST_NAME',
                'HTTP_X_FORWARDED_NAME',
            ],
            'email'    => [
                'OIDC_CLAIM_email',
                'REDIRECT_OIDC_CLAIM_email',
                'HTTP_X_AUTH_REQUEST_EMAIL',
                'HTTP_X_FORWARDED_EMAIL',
                'HTTP_X_USER_EMAIL',
                'HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL',
            ],
            'provider_subject' => [
                'OIDC_CLAIM_sub',
                'REDIRECT_OIDC_CLAIM_sub',
                'HTTP_X_AUTH_REQUEST_SUB',
                'HTTP_X_FORWARDED_SUB',
            ],
            'provider' => ['HTTP_X_AUTH_PROVIDER', 'OIDC_CLAIM_iss', 'REDIRECT_OIDC_CLAIM_iss'],
            'app_user_id' => ['HTTP_X_USER_ID', 'HTTP_X_AUTH_USER_ID'],
        ];

        foreach ($headers as $target => $keys) {
            if ($target === 'app_user_id' && $id[$target] > 0) {
                continue;
            }
            if ($target !== 'app_user_id' && $id[$target] !== '') {
                continue;
            }
            foreach ($keys as $k) {
                $v = isset($_SERVER[$k]) ? trim((string)$_SERVER[$k]) : '';
                if ($v === '') {
                    continue;
                }
                if ($target === 'app_user_id') {
                    $id[$target] = (int)$v;
                } else {
                    $id[$target] = $v;
                }
                $id['source'][] = 'server:' . $k;
                break;
            }
        }

        $envMap = [
            'username' => ['OAUTH2_PROXY_USER', 'AUTHENTICATED_USER', 'OIDC_CLAIM_preferred_username'],
            'display_name' => ['OIDC_CLAIM_name'],
            'email' => ['OAUTH2_PROXY_EMAIL', 'AUTHENTICATED_EMAIL', 'OIDC_CLAIM_email'],
            'provider_subject' => ['OIDC_CLAIM_sub'],
            'provider' => ['AUTH_PROVIDER', 'OIDC_CLAIM_iss'],
        ];

        foreach ($envMap as $target => $envKeys) {
            if ($id[$target] !== '') {
                continue;
            }
            foreach ($envKeys as $k) {
                $v = trim((string)getenv($k));
                if ($v === '') {
                    continue;
                }
                $id[$target] = $v;
                $id['source'][] = 'env:' . $k;
                break;
            }
        }

        if (
            ($id['email'] === '' || $id['username'] === '' || $id['provider_subject'] === '')
            && isset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'])
        ) {
            $claims = fpm_decode_jwt_payload((string)$_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']);
            if ($claims) {
                if ($id['email'] === '' && !empty($claims['email'])) {
                    $id['email'] = (string)$claims['email'];
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION.email';
                }
                if ($id['username'] === '' && !empty($claims['preferred_username'])) {
                    $id['username'] = (string)$claims['preferred_username'];
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION.preferred_username';
                }
                if ($id['username'] === '' && !empty($claims['name'])) {
                    $id['username'] = (string)$claims['name'];
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION.name';
                }
                if ($id['display_name'] === '' && !empty($claims['name'])) {
                    $id['display_name'] = (string)$claims['name'];
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION.display_name';
                }
                if ($id['provider_subject'] === '' && !empty($claims['sub'])) {
                    $id['provider_subject'] = (string)$claims['sub'];
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION.sub';
                }
                if ($id['provider'] === '') {
                    $id['provider'] = 'cf_access';
                    $id['source'][] = 'server:HTTP_CF_ACCESS_JWT_ASSERTION';
                }
            }
        }

        if ($id['provider'] !== '' && str_contains(strtolower($id['provider']), 'google')) {
            $id['provider'] = 'google';
        }
        if ($id['provider'] === '' && ($id['provider_subject'] !== '' || str_contains(strtolower(implode(' ', $id['source'])), 'oidc'))) {
            $id['provider'] = 'oidc';
        }

        if ($id['display_name'] === '') {
            $id['display_name'] = $id['username'];
        }
        if ($id['display_name'] === '' && $id['email'] !== '') {
            $id['display_name'] = (string)preg_replace('/@.*/', '', $id['email']);
        }

        $id['oauth_state'] = (string)($_SESSION['oauth2_state'] ?? '');
        $id['is_authenticated'] = (
            $id['username'] !== '' ||
            $id['email'] !== '' ||
            $id['app_user_id'] > 0
        );

        // Tie this connection's writes to the logged-in person (see app/change_tracking.php).
        static $bound = false;
        global $pdo;
        if (!$bound && $id['is_authenticated'] && isset($pdo) && $pdo instanceof PDO && function_exists('fpm_bind_actor')) {
            $bound = true;
            try { fpm_bind_actor($pdo, $id); } catch (Throwable $e) { error_log('change tracking bind failed: ' . $e->getMessage()); }
        }

        return $id;
    }
}

if (!function_exists('fpm_require_authenticated')) {
    function fpm_require_authenticated(): void
    {
        $identity = fpm_current_identity();
        if ((bool)($identity['is_authenticated'] ?? false)) {
            return;
        }

        $returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/index.php?page=home');
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/index.php?page=home';
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsHtml = ($accept === '') || str_contains($accept, 'text/html') || str_contains($accept, '*/*');

        if ($method === 'GET' && $wantsHtml && !headers_sent()) {
            $loginUrl = '/index.php?page=login&return_to=' . rawurlencode($returnTo);
            header('Location: ' . $loginUrl, true, 302);
            exit;
        }

        if (!headers_sent()) {
            http_response_code(401);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'ok' => false,
            'error' => 'authentication_required',
            'login' => '/index.php?page=login',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
